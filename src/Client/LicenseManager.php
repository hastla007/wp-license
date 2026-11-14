<?php
/**
 * Facade of the license client: everything a premium add-on needs.
 *
 * @package WPLC
 */

namespace WPLC\Client;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\\LicenseManager', false ) ) {

	/**
	 * License state, feature gate, daily check, notices, settings form, updater and uninstall of one add-on.
	 *
	 * Rules (docs/CLIENT-INTEGRATION.md):
	 * - The rights are kept as the signed snapshot of the last answer in a
	 *   non-autoloaded option and are valid until the signed `cache_expires_at`.
	 * - Only a signed answer may take rights away. Errors are sorted by
	 *   error_class(): `lock`, `reactivate` or `keep`.
	 * - All state is per instance; several add-ons can share one copy of the class.
	 */
	final class LicenseManager {

		/**
		 * Version of the client library this copy belongs to.
		 */
		const VERSION = '2.0.0';

		/**
		 * A signed answer ended the activation: rights and credentials are dropped.
		 */
		const CLASS_LOCK = 'lock';

		/**
		 * The server does not know this installation: the cache stays until it expires, the key must be entered again.
		 */
		const CLASS_REACTIVATE = 'reactivate';

		/**
		 * Anything else (transport, firewall, rate limit, clock, unverifiable answer): the cache stays until it expires.
		 */
		const CLASS_KEEP = 'keep';

		/**
		 * Seconds after which a missed daily check is caught up in the admin.
		 */
		const CATCH_UP_AFTER = 129600;

		/**
		 * Settings.
		 *
		 * @var array<string,mixed>
		 */
		private $config;

		/**
		 * Prefix of options, hooks and form fields.
		 *
		 * @var string
		 */
		private $prefix;

		/**
		 * Transport client.
		 *
		 * @var LicenseClient
		 */
		private $client;

		/**
		 * Updater, created on demand.
		 *
		 * @var Updater|null
		 */
		private $updater;

		/**
		 * Memoized result of snapshot() for this request.
		 *
		 * @var array<string,mixed>|null
		 */
		private $snapshot;

		/**
		 * Constructor.
		 *
		 * @param array<string,mixed> $config {
		 *     Settings of the add-on.
		 *
		 *     @type string          $plugin_file     Main file of the add-on (`__FILE__`). Required.
		 *     @type string          $product_key     Product key in the catalog of the license server. Required.
		 *     @type string          $version         Installed version of the add-on. Required.
		 *     @type string          $server_url      HTTPS address of the license server. Required.
		 *     @type string[]        $public_keys     Base64 Ed25519 public keys of the license server. Required.
		 *     @type string          $name            Display name of the add-on.
		 *     @type string          $text_domain     Text domain of the add-on.
		 *     @type string          $option_prefix   Prefix of options and hooks. Default: product key with underscores.
		 *     @type bool            $network         Multisite: one activation for the whole network. Default false.
		 *     @type string          $update_uri      Value of the `Update URI` header. Default: read from the plugin file.
		 *     @type string          $channel         `stable` or `beta`.
		 *     @type string|callable $core_version    Version of the free base plugin (or a callable returning it).
		 *     @type string|false    $settings_parent Admin menu the license screen is added to; false for none.
		 *                                            Default `options-general.php` (`settings.php` in network mode).
		 *     @type string          $capability      Capability that may manage the license.
		 *     @type string          $account_url     Customer account of the shop (renewal link in notices).
		 *     @type string[]        $fallback_urls   Further server addresses.
		 *     @type string          $rest_base       REST base when the server does not use `/wp-json`.
		 *     @type OptionStore     $store           Storage (tests).
		 *     @type callable        $transport       HTTP transport (tests).
		 *     @type callable        $clock           Clock (tests).
		 * }
		 */
		public function __construct( array $config ) {
			$product_key = strtolower( preg_replace( '/[^A-Za-z0-9_-]/', '', (string) ( $config['product_key'] ?? '' ) ) );
			$prefix      = (string) ( $config['option_prefix'] ?? '' );
			$prefix      = strtolower( preg_replace( '/[^A-Za-z0-9_]/', '', '' === $prefix ? str_replace( '-', '_', $product_key ) : $prefix ) );
			$network     = ! empty( $config['network'] ) && function_exists( 'is_multisite' ) && is_multisite();

			$this->prefix = $prefix;
			$this->config = array_merge(
				array(
					'plugin_file'     => '',
					'version'         => '',
					'name'            => $product_key,
					'text_domain'     => '',
					'update_uri'      => '',
					'channel'         => 'stable',
					'core_version'    => '',
					'settings_parent' => $network ? 'settings.php' : 'options-general.php',
					'capability'      => $network ? 'manage_network_options' : 'manage_options',
					'account_url'     => '',
					'public_keys'     => array(),
				),
				$config,
				array(
					'product_key' => $product_key,
					'network'     => $network,
				)
			);

			$server_url = (string) ( $config['server_url'] ?? '' );
			if ( '' !== $prefix && function_exists( 'apply_filters' ) ) {
				/**
				 * Filters the address of the license server of this add-on.
				 *
				 * The hook name starts with the option prefix of the add-on,
				 * e.g. `voucher_captain_pro_license_server_url`.
				 *
				 * @param string $server_url Address from the add-on's configuration.
				 */
				$server_url = (string) apply_filters( $prefix . '_license_server_url', $server_url ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- prefixed with the add-on's own prefix.
			}

			$this->client = new LicenseClient(
				$server_url,
				$product_key,
				(string) $this->config['version'],
				$prefix . '_license',
				array(
					// An empty list would switch the server signature off; the facade does not allow that.
					'public_keys'   => empty( $this->config['public_keys'] ) ? array( '' ) : (array) $this->config['public_keys'],
					'text_domain'   => (string) $this->config['text_domain'],
					'network'       => $network,
					'fallback_urls' => $config['fallback_urls'] ?? array(),
					'rest_base'     => $config['rest_base'] ?? '',
					'store'         => $config['store'] ?? null,
					'transport'     => $config['transport'] ?? null,
					'clock'         => $config['clock'] ?? null,
				)
			);
		}

		// ------------------------------------------------------------------
		// Wiring.
		// ------------------------------------------------------------------

		/**
		 * Register all hooks of the add-on's licensing. Call once, when the add-on is loaded.
		 *
		 * @return void
		 */
		public function register(): void {
			add_action( $this->cron_hook(), array( $this, 'run_scheduled_check' ) );
			add_action( 'init', array( $this, 'ensure_schedule' ) );
			add_action( 'admin_init', array( $this, 'catch_up' ) );
			add_action( 'admin_init', array( $this, 'add_privacy_text' ) );
			add_action( 'admin_post_' . $this->prefix . '_license', array( $this, 'handle_settings_form' ) );
			add_action( $this->config['network'] ? 'network_admin_notices' : 'admin_notices', array( $this, 'render_notice' ) );
			if ( false !== $this->config['settings_parent'] && '' !== $this->config['settings_parent'] ) {
				add_action( $this->config['network'] ? 'network_admin_menu' : 'admin_menu', array( $this, 'add_settings_page' ) );
			}
			if ( '' !== $this->config['plugin_file'] ) {
				register_deactivation_hook( (string) $this->config['plugin_file'], array( $this, 'unschedule' ) );
				$this->updater()->register();
			}
		}

		/**
		 * Transport client of this add-on.
		 *
		 * @return LicenseClient
		 */
		public function client(): LicenseClient {
			return $this->client;
		}

		/**
		 * Updater of this add-on.
		 *
		 * @return Updater
		 */
		public function updater(): Updater {
			if ( null === $this->updater ) {
				$this->updater = new Updater( $this );
			}

			return $this->updater;
		}

		/**
		 * One setting of the add-on.
		 *
		 * @param string $key Setting name.
		 * @return mixed Null when unknown.
		 */
		public function config( string $key ) {
			return $this->config[ $key ] ?? null;
		}

		/**
		 * Prefix of options, hooks and form fields.
		 *
		 * @return string
		 */
		public function prefix(): string {
			return $this->prefix;
		}

		/**
		 * Name of the daily cron hook.
		 *
		 * @return string
		 */
		public function cron_hook(): string {
			return $this->prefix . '_license_check';
		}

		// ------------------------------------------------------------------
		// Feature gate.
		// ------------------------------------------------------------------

		/**
		 * Whether the license grants a feature right now. Fails closed.
		 *
		 * Reads one non-autoloaded option and verifies the stored snapshot; no
		 * request is sent.
		 *
		 * @param string $key Entitlement key, e.g. `voucher.qr_redemption`.
		 * @return bool
		 */
		public function is_entitled( string $key ): bool {
			return $this->status()->is_entitled( $key );
		}

		/**
		 * Current license status from the stored snapshot. No request is sent.
		 *
		 * @return LicenseStatus
		 */
		public function status(): LicenseStatus {
			$status = new LicenseStatus();
			$state  = $this->state();

			$status->last_checked       = (int) ( $state['last_success'] ?? 0 );
			$status->needs_reactivation = ! empty( $state['needs_reactivation'] );
			if ( ! empty( $state['error'] ) && is_array( $state['error'] ) ) {
				$status->last_error    = (string) ( $state['error']['code'] ?? '' );
				$status->last_error_at = (int) ( $state['error']['at'] ?? 0 );
			}

			if ( ! $this->client->is_configured() ) {
				$status->state = LicenseStatus::STATE_MISCONFIGURED;
				return $status;
			}
			if ( ! $this->client->is_activated() ) {
				$status->state = 'deactivated' === ( $state['lock'] ?? '' ) ? LicenseStatus::STATE_DEACTIVATED : LicenseStatus::STATE_UNLICENSED;
				return $status;
			}
			$status->installation_id = $this->client->installation_id();
			$status->environment     = $this->client->environment();
			if ( ! $this->client->site_matches() ) {
				$status->state = LicenseStatus::STATE_SITE_CHANGED;
				return $status;
			}

			$data = $this->snapshot();
			if ( empty( $data ) ) {
				$status->state = LicenseStatus::STATE_STALE;
				return $status;
			}
			$status->license_status   = (string) ( $data['license_status'] ?? '' );
			$status->paid_until       = (string) ( $data['paid_until'] ?? '' );
			$status->access_until     = (string) ( $data['access_until'] ?? '' );
			$status->grace_until      = (string) ( $data['grace_until'] ?? '' );
			$status->cache_expires_at = (string) ( $data['cache_expires_at'] ?? '' );
			$status->environment      = (string) ( $data['environment'] ?? $status->environment );
			$status->site_limit       = (int) ( $data['site_limit'] ?? 0 );
			$status->site_count       = (int) ( $data['site_count'] ?? 0 );

			$server_now = $this->server_now( $state );
			$expires    = self::utc( $status->cache_expires_at );
			if ( 0 === $expires || $server_now >= $expires ) {
				// The signed offline window is used up; only a new signed answer restores the rights.
				$status->state = LicenseStatus::STATE_STALE;
				return $status;
			}
			if ( array_key_exists( 'site_match', $data ) && false === $data['site_match'] ) {
				$status->state = LicenseStatus::STATE_SITE_CHANGED;
				return $status;
			}
			if ( true === ( $data['access'] ?? null ) && 'deactivated' !== ( $data['activation_status'] ?? '' ) ) {
				$paid                 = self::utc( $status->paid_until );
				$status->access       = true;
				$status->entitlements = isset( $data['entitlements'] ) && is_array( $data['entitlements'] ) ? $data['entitlements'] : array();
				$status->state        = ( 'past_due' === $status->license_status || ( 0 !== $paid && $server_now >= $paid ) ) ? LicenseStatus::STATE_GRACE : LicenseStatus::STATE_ACTIVE;
				return $status;
			}
			$map           = array(
				'expired'     => LicenseStatus::STATE_EXPIRED,
				'past_due'    => LicenseStatus::STATE_EXPIRED,
				'suspended'   => LicenseStatus::STATE_SUSPENDED,
				'revoked'     => LicenseStatus::STATE_REVOKED,
				'deactivated' => LicenseStatus::STATE_DEACTIVATED,
			);
			$status->state = $map[ $status->license_status ] ?? LicenseStatus::STATE_INACTIVE;

			return $status;
		}

		// ------------------------------------------------------------------
		// Actions.
		// ------------------------------------------------------------------

		/**
		 * Activate this site with a license key.
		 *
		 * @param string $license_key                    Key entered by the customer.
		 * @param string $environment                    `production`, `staging`, `development`; empty: detect.
		 * @param string $staging_parent_installation_id Installation UUID of the live site of a staging site.
		 * @return LicenseStatus|\WP_Error
		 */
		public function activate( string $license_key, string $environment = '', string $staging_parent_installation_id = '' ) {
			$before = $this->status()->state;
			$data   = $this->client->activate( $license_key, $environment, $staging_parent_installation_id );

			return $this->after_activation( $data, $before );
		}

		/**
		 * Transfer the activation to the current address after the site moved.
		 *
		 * @param string $license_key Key entered by the customer.
		 * @return LicenseStatus|\WP_Error
		 */
		public function move( string $license_key ) {
			$before = $this->status()->state;

			return $this->after_activation( $this->client->move( $license_key ), $before );
		}

		/**
		 * Release the activation of this site (or only forget copied credentials on a copy).
		 *
		 * @return array<string,mixed>|\WP_Error Result of LicenseClient::deactivate().
		 */
		public function deactivate() {
			$before = $this->status()->state;
			$result = $this->client->deactivate();
			if ( ! is_wp_error( $result ) ) {
				$this->client->store()->delete( $this->state_option() );
				$this->snapshot = null;
				$this->updater()->flush();
				$this->ensure_schedule();
				$this->changed( $before );
			}

			return $result;
		}

		/**
		 * Ask the server for the current status ("Check now", daily check).
		 *
		 * @param bool $force False respects the back-off after failed attempts.
		 * @return LicenseStatus|\WP_Error The error of the request; the stored status is kept or changed according to error_class().
		 */
		public function refresh( bool $force = true ) {
			if ( ! $this->client->is_activated() ) {
				return new \WP_Error( 'wplc_not_activated', $this->client->messages()->error( 'wplc_not_activated' ) );
			}
			$state = $this->state();
			$now   = $this->client->now();
			if ( ! $force && $now < (int) ( $state['next_attempt'] ?? 0 ) && $now >= (int) ( $state['last_attempt'] ?? 0 ) ) {
				return new \WP_Error( 'wplc_backoff', $this->client->messages()->error( 'wplc_connection_failed' ), array( 'next_attempt' => (int) $state['next_attempt'] ) );
			}
			$before = $this->status()->state;
			$result = $this->client->check_entitlements();
			$error  = $this->apply( $result );
			$this->changed( $before );

			return null === $error ? $this->status() : $error;
		}

		/**
		 * Sort an error of a signed request into the table "lock / re-activate / keep cache".
		 *
		 * | Class | Errors | Effect |
		 * | --- | --- | --- |
		 * | `lock` | `activation_deactivated` with a verified signed envelope | rights and credentials dropped |
		 * | `reactivate` | `activation_not_found`, `installation_secret_invalid`, `license_not_found` | cache kept until `cache_expires_at`, notice asks for the key |
		 * | `keep` | everything else: timeouts, 5xx, 429, firewall pages, clock errors, unverifiable answers | cache kept until `cache_expires_at`, retried with back-off |
		 *
		 * A signed 200 answer is not an error; it replaces the snapshot, whatever it says.
		 *
		 * @param \WP_Error $error Error of LicenseClient.
		 * @return string One of the CLASS_* constants.
		 */
		public static function error_class( \WP_Error $error ): string {
			if ( LicenseClient::is_signed_refusal( $error ) ) {
				return self::CLASS_LOCK;
			}
			if ( in_array( $error->get_error_code(), array( 'activation_not_found', 'installation_secret_invalid', 'license_not_found' ), true ) ) {
				return self::CLASS_REACTIVATE;
			}

			return self::CLASS_KEEP;
		}

		/**
		 * Daily cron callback.
		 *
		 * @return void
		 */
		public function run_scheduled_check(): void {
			if ( $this->client->is_activated() && $this->client->site_matches() ) {
				$this->refresh( false );
			}
		}

		/**
		 * Schedule the daily check while the site is activated (self-healing).
		 *
		 * @return void
		 */
		public function ensure_schedule(): void {
			if ( $this->config['network'] && function_exists( 'is_main_site' ) && ! is_main_site() ) {
				return;
			}
			$scheduled = wp_next_scheduled( $this->cron_hook() );
			if ( $this->client->is_activated() && ! $scheduled ) {
				// A random offset spreads the checks of all customer sites over the day.
				wp_schedule_event( time() + wp_rand( 300, DAY_IN_SECONDS ), 'daily', $this->cron_hook() );
			} elseif ( $scheduled && ! $this->client->is_activated() ) {
				wp_clear_scheduled_hook( $this->cron_hook() );
			}
		}

		/**
		 * Remove the daily check (plugin deactivation).
		 *
		 * @return void
		 */
		public function unschedule(): void {
			wp_clear_scheduled_hook( $this->cron_hook() );
		}

		/**
		 * Catch up in the admin when WP-Cron did not run or the last attempt failed.
		 *
		 * @return void
		 */
		public function catch_up(): void {
			if ( wp_doing_ajax() || ! current_user_can( (string) $this->config['capability'] ) || ! $this->client->is_activated() || ! $this->client->site_matches() ) {
				return;
			}
			$state = $this->state();
			$now   = $this->client->now();
			$last  = (int) ( $state['last_attempt'] ?? 0 );
			$due   = empty( $state['error'] )
				? $now - $last > self::CATCH_UP_AFTER
				: $now >= (int) ( $state['next_attempt'] ?? 0 );
			if ( $due || $last > $now ) {
				$this->refresh( false );
			}
		}

		/**
		 * Remove everything this add-on stored and release the activation. For `uninstall.php`.
		 *
		 * The server is asked with a short timeout; whatever it answers, the
		 * local data is removed. If the server cannot be reached the site slot
		 * stays in use until the customer releases it in the account.
		 *
		 * @return void
		 */
		public function uninstall(): void {
			$started = time();
			$sites   = array( 0 );
			if ( function_exists( 'is_multisite' ) && is_multisite() && ! $this->config['network'] ) {
				$sites = get_sites(
					array(
						'fields' => 'ids',
						'number' => 500,
					)
				);
			}
			foreach ( $sites as $site_id ) {
				if ( $site_id ) {
					switch_to_blog( (int) $site_id );
				}
				if ( $this->client->is_activated() && time() - $started < 20 ) {
					$this->client->deactivate();
				}
				$this->client->forget();
				$this->client->store()->delete( $this->state_option() );
				$this->client->store()->delete( $this->prefix . '_update_meta' );
				wp_clear_scheduled_hook( $this->cron_hook() );
				if ( $site_id ) {
					restore_current_blog();
				}
			}
			$this->snapshot = null;
		}

		// ------------------------------------------------------------------
		// Admin screen and notices.
		// ------------------------------------------------------------------

		/**
		 * Address of the license screen.
		 *
		 * @return string
		 */
		public function settings_url(): string {
			$parent = false === $this->config['settings_parent'] ? 'options-general.php' : (string) $this->config['settings_parent'];
			$parent = false === strpos( $parent, '.php' ) ? 'admin.php' : $parent;
			$url    = $parent . ( false === strpos( $parent, '?' ) ? '?' : '&' ) . 'page=' . $this->page_slug();
			$url    = $this->config['network'] ? network_admin_url( $url ) : admin_url( $url );

			return (string) apply_filters( $this->prefix . '_license_settings_url', $url ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- prefixed with the add-on's own prefix.
		}

		/**
		 * Add the license screen to the admin menu.
		 *
		 * @return void
		 */
		public function add_settings_page(): void {
			$title = sprintf( '%s – %s', (string) $this->config['name'], $this->text( 'ui.title' ) );
			add_submenu_page( (string) $this->config['settings_parent'], $title, $title, (string) $this->config['capability'], $this->page_slug(), array( $this, 'render_settings' ) );
		}

		/**
		 * Print the license form: key field, activate, deactivate, check now, status.
		 *
		 * Can be used as the callback of the add-on's own settings page or tab.
		 *
		 * @return void
		 */
		public function render_settings(): void {
			if ( ! current_user_can( (string) $this->config['capability'] ) ) {
				return;
			}
			$status = $this->status();
			$flash  = get_transient( $this->flash_key() );
			delete_transient( $this->flash_key() );
			$field = $this->prefix . '_license_key';

			echo '<div class="wrap"><h1>' . esc_html( sprintf( '%s – %s', (string) $this->config['name'], $this->text( 'ui.title' ) ) ) . '</h1>';
			if ( is_array( $flash ) && isset( $flash['type'], $flash['text'] ) ) {
				printf( '<div class="notice notice-%s"><p>%s</p></div>', esc_attr( (string) $flash['type'] ), esc_html( (string) $flash['text'] ) );
			}

			echo '<table class="form-table" role="presentation"><tbody>';
			$this->row( $this->text( 'ui.status' ), $this->text( 'state.' . $status->state ) );
			if ( '' !== $status->license_status ) {
				$this->row( $this->text( 'ui.paid_until' ), '' === $status->paid_until ? $this->text( 'ui.no_end' ) : $this->date( $status->paid_until ) );
				if ( $status->access && '' !== $status->access_until && $status->access_until !== $status->paid_until ) {
					$this->row( $this->text( 'ui.access_until' ), $this->date( $status->access_until ) );
				}
				$this->row( $this->text( 'ui.sites' ), $status->site_count . ' / ' . $status->site_limit );
			}
			if ( '' !== $status->environment && '' !== $status->installation_id ) {
				$this->row( $this->text( 'ui.environment' ), $this->text( 'ui.environment.' . $status->environment ) . ' (' . $status->installation_id . ')' );
			}
			if ( '' !== $status->installation_id ) {
				$this->row( $this->text( 'ui.last_checked' ), 0 === $status->last_checked ? $this->text( 'ui.never' ) : $this->date( gmdate( 'Y-m-d H:i:s', $status->last_checked ) ) );
			}
			if ( '' !== $status->last_error ) {
				$this->row( $this->text( 'ui.last_error' ), $this->client->messages()->error( $status->last_error ) . ' (' . $status->last_error . ', ' . $this->date( gmdate( 'Y-m-d H:i:s', $status->last_error_at ) ) . ')' );
			}
			echo '</tbody></table>';

			if ( LicenseStatus::STATE_MISCONFIGURED === $status->state ) {
				echo '</div>';
				return;
			}

			echo '<form method="post" action="' . esc_url( $this->config['network'] ? network_admin_url( 'admin-post.php' ) : admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( $this->prefix . '_license' );
			echo '<input type="hidden" name="action" value="' . esc_attr( $this->prefix . '_license' ) . '">';
			$key_input = '<input type="text" class="regular-text code" name="' . esc_attr( $field ) . '" id="' . esc_attr( $field ) . '" autocomplete="off" spellcheck="false" placeholder="WPLC-…">';
			$label     = '<label for="' . esc_attr( $field ) . '"><strong>' . esc_html( $this->text( 'ui.license_key' ) ) . '</strong></label><br>';
			$allowed   = array(
				'input'  => array(
					'type'         => true,
					'class'        => true,
					'name'         => true,
					'id'           => true,
					'autocomplete' => true,
					'spellcheck'   => true,
					'placeholder'  => true,
				),
				'label'  => array( 'for' => true ),
				'strong' => array(),
				'br'     => array(),
			);

			if ( LicenseStatus::STATE_SITE_CHANGED === $status->state ) {
				echo '<p>' . esc_html( $this->client->messages()->error( 'wplc_site_changed' ) ) . '</p>';
				echo '<p>' . wp_kses( $label . $key_input, $allowed ) . '</p><p>';
				$this->button( 'activate', $this->text( 'ui.activate_copy' ), true );
				$this->button( 'move', $this->text( 'ui.move' ) );
				$this->button( 'deactivate', $this->text( 'ui.forget' ) );
				echo '</p>';
			} elseif ( '' !== $status->installation_id ) {
				echo '<p>';
				$this->button( 'check', $this->text( 'ui.check_now' ), true );
				$this->button( 'deactivate', $this->text( 'ui.deactivate' ) );
				echo '</p><p class="description">' . esc_html( $this->text( 'ui.key_hidden' ) ) . '</p>';
				echo '<p>' . wp_kses( $label . $key_input, $allowed ) . ' ';
				$this->button( 'activate', $this->text( 'ui.activate' ) );
				echo '</p>';
			} else {
				echo '<p>' . wp_kses( $label . $key_input, $allowed ) . ' ';
				$this->button( 'activate', $this->text( 'ui.activate' ), true );
				echo '</p>';
			}
			echo '</form></div>';
		}

		/**
		 * Handle the license form (admin-post.php).
		 *
		 * @return void
		 */
		public function handle_settings_form(): void {
			if ( ! current_user_can( (string) $this->config['capability'] ) ) {
				wp_die( esc_html( $this->text( 'ui.forbidden' ) ), 403 );
			}
			check_admin_referer( $this->prefix . '_license' );
			$do     = isset( $_POST['wplc_do'] ) ? sanitize_key( wp_unslash( $_POST['wplc_do'] ) ) : '';
			$field  = $this->prefix . '_license_key';
			$key    = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
			$result = $this->run_form_action( $do, $key );

			set_transient( $this->flash_key(), $result, 60 );
			wp_safe_redirect( $this->settings_url() );
			exit;
		}

		/**
		 * Execute one action of the license form.
		 *
		 * @param string $action      `activate`, `move`, `deactivate` or `check`.
		 * @param string $license_key Key from the form.
		 * @return array{type:string,text:string} Message for the next page view.
		 */
		public function run_form_action( string $action, string $license_key = '' ): array {
			$saved = 'ui.saved.checked';
			if ( 'activate' === $action ) {
				$result = $this->activate( $license_key );
				$saved  = 'ui.saved.activated';
			} elseif ( 'move' === $action ) {
				$result = $this->move( $license_key );
				$saved  = 'ui.saved.moved';
			} elseif ( 'deactivate' === $action ) {
				$result = $this->deactivate();
				$saved  = is_array( $result ) && 'removed_locally' === ( $result['status'] ?? '' ) && 'wplc_site_changed' === ( $result['reason'] ?? '' ) ? 'ui.saved.deactivated_locally' : 'ui.saved.deactivated';
			} else {
				$result = $this->refresh( true );
			}
			if ( is_wp_error( $result ) ) {
				return array(
					'type' => 'error',
					'text' => $result->get_error_message() . ' (' . $result->get_error_code() . ')',
				);
			}

			return array(
				'type' => 'success',
				'text' => $this->text( $saved ),
			);
		}

		/**
		 * Print the admin notice that belongs to the current state, if any.
		 *
		 * @return void
		 */
		public function render_notice(): void {
			if ( ! current_user_can( (string) $this->config['capability'] ) ) {
				return;
			}
			$notice = $this->notice();
			if ( null === $notice ) {
				return;
			}
			$screen  = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			$on_page = isset( $_GET['page'] ) && $this->page_slug() === sanitize_key( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only comparison.
			if ( 'info' === $notice['type'] && ! ( $screen && in_array( $screen->base, array( 'plugins', 'plugins-network', 'dashboard' ), true ) ) ) {
				// A missing license is only mentioned where the customer manages plugins.
				return;
			}
			/**
			 * Filters whether the license notice of this add-on is printed.
			 *
			 * @param bool                 $show   Whether to print the notice.
			 * @param array<string,string> $notice Keys `type`, `text`, `state`.
			 */
			if ( ! apply_filters( $this->prefix . '_license_show_notice', true, $notice ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- prefixed with the add-on's own prefix.
				return;
			}
			printf( '<div class="notice notice-%s"><p>%s', esc_attr( $notice['type'] ), esc_html( $notice['text'] ) );
			if ( ! $on_page ) {
				printf( ' <a href="%s">%s</a>', esc_url( $this->settings_url() ), esc_html( $this->text( 'notice.open_settings' ) ) );
			}
			echo '</p></div>';
		}

		/**
		 * Notice for the current state.
		 *
		 * @return array{type:string,text:string,state:string}|null Null when nothing needs to be said.
		 */
		public function notice() {
			$status = $this->status();
			$name   = (string) $this->config['name'];
			$type   = 'warning';
			$text   = '';
			switch ( $status->state ) {
				case LicenseStatus::STATE_ACTIVE:
					if ( $status->needs_reactivation ) {
						$text = sprintf( $this->text( 'notice.reactivate' ), $name );
					} elseif ( '' !== $status->last_error && $this->client->now() - $status->last_checked > 3 * DAY_IN_SECONDS ) {
						$text = sprintf( $this->text( 'notice.offline' ), $name, $this->date( $status->cache_expires_at ) );
					}
					break;
				case LicenseStatus::STATE_GRACE:
					$text = sprintf( $this->text( 'notice.grace' ), $name, $this->date( $status->access_until ) );
					break;
				case LicenseStatus::STATE_UNLICENSED:
					$type = 'info';
					$text = sprintf( $this->text( 'notice.unlicensed' ), $name );
					break;
				case LicenseStatus::STATE_EXPIRED:
				case LicenseStatus::STATE_SUSPENDED:
				case LicenseStatus::STATE_REVOKED:
				case LicenseStatus::STATE_INACTIVE:
				case LicenseStatus::STATE_DEACTIVATED:
				case LicenseStatus::STATE_SITE_CHANGED:
				case LicenseStatus::STATE_STALE:
				case LicenseStatus::STATE_MISCONFIGURED:
					$type = in_array( $status->state, array( LicenseStatus::STATE_SITE_CHANGED, LicenseStatus::STATE_DEACTIVATED ), true ) ? 'warning' : 'error';
					$text = sprintf( $this->text( 'notice.' . $status->state ), $name );
					break;
			}
			if ( '' === $text ) {
				return null;
			}
			if ( '' !== $this->config['account_url'] && in_array( $status->state, array( LicenseStatus::STATE_GRACE, LicenseStatus::STATE_EXPIRED ), true ) ) {
				$text .= ' ' . (string) $this->config['account_url'];
			}

			return array(
				'type'  => $type,
				'text'  => $text,
				'state' => $status->state,
			);
		}

		/**
		 * Suggested paragraph for the privacy policy of the customer site.
		 *
		 * @return string
		 */
		public function privacy_text(): string {
			return sprintf( $this->text( 'privacy.text' ), (string) $this->config['name'], $this->client->server_host() );
		}

		/**
		 * Offer the privacy paragraph under Settings → Privacy → policy guide.
		 *
		 * @return void
		 */
		public function add_privacy_text(): void {
			if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
				wp_add_privacy_policy_content( (string) $this->config['name'], wp_kses_post( wpautop( $this->privacy_text(), false ) ) );
			}
		}

		// ------------------------------------------------------------------
		// Internals.
		// ------------------------------------------------------------------

		/**
		 * Store the result of an activation.
		 *
		 * @param array<string,mixed>|\WP_Error $data   Result of the client.
		 * @param string                        $before State before the request.
		 * @return LicenseStatus|\WP_Error
		 */
		private function after_activation( $data, string $before ) {
			if ( is_wp_error( $data ) ) {
				if ( LicenseClient::is_signed_refusal( $data ) ) {
					$this->apply( $data );
					$this->changed( $before );
				}
				return $data;
			}
			$error = $this->apply( $data );
			$this->updater()->flush();
			$this->ensure_schedule();
			$this->changed( $before );

			return null === $error ? $this->status() : $error;
		}

		/**
		 * Apply the result of a signed request to the stored state.
		 *
		 * @param array<string,mixed>|\WP_Error $result Verified data or an error.
		 * @return \WP_Error|null The error that was recorded, null when a signed answer was stored.
		 */
		private function apply( $result ) {
			$state = $this->state();
			$now   = $this->client->now();

			$state['last_attempt'] = $now;
			$state['clock_high']   = max( $now, (int) ( $state['clock_high'] ?? 0 ) );

			if ( ! is_wp_error( $result ) ) {
				$server_time = self::utc( (string) ( $result['server_time'] ?? '' ) );
				if ( 0 === $server_time || empty( $this->client->last_envelope() ) ) {
					$result = new \WP_Error( 'wplc_response_invalid', $this->client->messages()->error( 'wplc_response_invalid' ) );
				} elseif ( $server_time < (int) ( $state['server_time_max'] ?? 0 ) - 300 ) {
					// An answer older than one already seen is a replay, whatever it says.
					$result = new \WP_Error( 'wplc_response_stale', $this->client->messages()->error( 'wplc_response_stale' ) );
				}
			}

			if ( ! is_wp_error( $result ) ) {
				$state['server_time']        = $server_time;
				$state['server_time_max']    = max( $server_time, (int) ( $state['server_time_max'] ?? 0 ) );
				$state['fetched_at']         = $now;
				$state['last_success']       = $now;
				$state['verified_at']        = $now;
				$state['error']              = null;
				$state['error_streak']       = 0;
				$state['next_attempt']       = 0;
				$state['needs_reactivation'] = false;
				if ( 'deactivated' === ( $result['activation_status'] ?? '' ) ) {
					// Signed: the activation was released. The client already removed the credentials.
					$state['lock'] = 'deactivated';
					unset( $state['envelope'] );
				} else {
					$state['lock']     = '';
					$state['envelope'] = $this->client->last_envelope();
				}
				$this->write( $state );

				return null;
			}

			$class = self::error_class( $result );
			$data  = (array) $result->get_error_data();
			if ( self::CLASS_LOCK === $class ) {
				$verified                 = isset( $data['verified'] ) && is_array( $data['verified'] ) ? $data['verified'] : array();
				$state['lock']            = 'deactivated';
				$state['server_time_max'] = max( self::utc( (string) ( $verified['server_time'] ?? '' ) ), (int) ( $state['server_time_max'] ?? 0 ) );
				$state['last_success']    = $now;
				$state['error']           = null;
				$state['error_streak']    = 0;
				$state['next_attempt']    = 0;
				unset( $state['envelope'] );
				$this->write( $state );

				return $result;
			}

			$streak = (int) ( $state['error_streak'] ?? 0 ) + 1;
			$delay  = min( DAY_IN_SECONDS, 15 * MINUTE_IN_SECONDS * pow( 2, min( $streak, 8 ) - 1 ) );
			if ( isset( $data['retry_after'] ) && is_numeric( $data['retry_after'] ) ) {
				$delay = max( $delay, min( DAY_IN_SECONDS, (int) $data['retry_after'] ) );
			}
			$state['error']        = array(
				'code'   => (string) $result->get_error_code(),
				'status' => (int) ( $data['status'] ?? 0 ),
				'at'     => $now,
			);
			$state['error_streak'] = $streak;
			$state['next_attempt'] = $now + (int) $delay;
			if ( self::CLASS_REACTIVATE === $class ) {
				$state['needs_reactivation'] = true;
			}
			$this->write( $state );

			return $result;
		}

		/**
		 * Fire the status-change actions when the state differs from the one before.
		 *
		 * @param string $before State before.
		 * @return void
		 */
		private function changed( string $before ): void {
			$status = $this->status();
			if ( $status->state === $before ) {
				return;
			}
			/**
			 * Fires when the license state of this add-on changed.
			 *
			 * The hook name starts with the option prefix of the add-on, e.g.
			 * `voucher_captain_pro_license_status_changed`.
			 *
			 * @param string        $state  New state (LicenseStatus::STATE_*).
			 * @param string        $before Previous state.
			 * @param LicenseStatus $status Full status.
			 */
			do_action( $this->prefix . '_license_status_changed', $status->state, $before, $status ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- prefixed with the add-on's own prefix.
		}

		/**
		 * Verified data of the stored snapshot; empty when there is none or it is not valid.
		 *
		 * @return array<string,mixed>
		 */
		private function snapshot(): array {
			if ( null !== $this->snapshot ) {
				return $this->snapshot;
			}
			$state          = $this->state();
			$this->snapshot = array();
			if ( empty( $state['envelope'] ) || ! is_array( $state['envelope'] ) ) {
				return $this->snapshot;
			}
			// The HMAC is checked on every request. Ed25519 too when PHP has the sodium extension; with the
			// slow pure-PHP fallback of WordPress the server signature is re-checked every twelve hours.
			$now      = $this->client->now();
			$verified = (int) ( $state['verified_at'] ?? 0 );
			$skip     = ! extension_loaded( 'sodium' ) && $verified <= $now && $now - $verified < 12 * HOUR_IN_SECONDS;
			$data     = $this->client->verify_snapshot( $state['envelope'], $skip );
			if ( is_wp_error( $data ) ) {
				return $this->snapshot;
			}
			if ( ! $skip && ! extension_loaded( 'sodium' ) ) {
				$state['verified_at'] = $now;
				$this->client->store()->set( $this->state_option(), $state );
			}
			$this->snapshot = $data;

			return $this->snapshot;
		}

		/**
		 * Time of the license server right now, derived from the signed `server_time` of the snapshot.
		 *
		 * Uses the local clock only to measure the time since the snapshot was
		 * stored, so a wrong time zone or a constant offset does not matter. The
		 * highest local time ever seen is kept: turning the clock back does not
		 * extend the offline window.
		 *
		 * @param array<string,mixed> $state Stored state.
		 * @return int Unix time.
		 */
		private function server_now( array $state ): int {
			$local   = max( $this->client->now(), (int) ( $state['clock_high'] ?? 0 ) );
			$elapsed = max( 0, $local - (int) ( $state['fetched_at'] ?? 0 ) );

			return max( (int) ( $state['server_time'] ?? 0 ) + $elapsed, (int) ( $state['server_time_max'] ?? 0 ) );
		}

		/**
		 * Stored state of this add-on.
		 *
		 * @return array<string,mixed>
		 */
		private function state(): array {
			$state = $this->client->store()->get( $this->state_option(), array() );

			return is_array( $state ) ? $state : array();
		}

		/**
		 * Write the state and forget the memoized snapshot.
		 *
		 * @param array<string,mixed> $state State.
		 * @return void
		 */
		private function write( array $state ): void {
			$this->client->store()->set( $this->state_option(), $state );
			$this->snapshot = null;
		}

		/**
		 * Name of the state option.
		 *
		 * @return string
		 */
		private function state_option(): string {
			return $this->prefix . '_license_state';
		}

		/**
		 * Slug of the license screen.
		 *
		 * @return string
		 */
		private function page_slug(): string {
			return str_replace( '_', '-', $this->prefix ) . '-license';
		}

		/**
		 * Name of the transient that carries the message of the last form action.
		 *
		 * @return string
		 */
		private function flash_key(): string {
			return $this->prefix . '_license_flash_' . get_current_user_id();
		}

		/**
		 * Text by code.
		 *
		 * @param string $code Code.
		 * @return string
		 */
		private function text( string $code ): string {
			return $this->client->messages()->get( $code );
		}

		/**
		 * Print one row of the status table.
		 *
		 * @param string $label Label.
		 * @param string $value Value.
		 * @return void
		 */
		private function row( string $label, string $value ): void {
			printf( '<tr><th scope="row">%s</th><td>%s</td></tr>', esc_html( $label ), esc_html( $value ) );
		}

		/**
		 * Print one submit button of the license form.
		 *
		 * @param string $action  Value of the action field.
		 * @param string $label   Label.
		 * @param bool   $primary Whether this is the main action.
		 * @return void
		 */
		private function button( string $action, string $label, bool $primary = false ): void {
			printf( '<button type="submit" class="button%s" name="wplc_do" value="%s">%s</button> ', $primary ? ' button-primary' : '', esc_attr( $action ), esc_html( $label ) );
		}

		/**
		 * Format a UTC date-time of the server in the time zone and format of the site.
		 *
		 * @param string $utc `Y-m-d H:i:s` in UTC.
		 * @return string
		 */
		private function date( string $utc ): string {
			$time = self::utc( $utc );
			if ( 0 === $time ) {
				return $utc;
			}
			if ( function_exists( 'wp_date' ) ) {
				return (string) wp_date( (string) get_option( 'date_format', 'Y-m-d' ) . ' ' . (string) get_option( 'time_format', 'H:i' ), $time );
			}

			return gmdate( 'Y-m-d H:i', $time ) . ' ' . $this->text( 'ui.utc' );
		}

		/**
		 * Unix time of a UTC date-time string of the server; 0 when it cannot be read.
		 *
		 * @param string $value `Y-m-d H:i:s`.
		 * @return int
		 */
		public static function utc( string $value ): int {
			if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) ) {
				return 0;
			}
			$time = strtotime( $value . ' UTC' );

			return false === $time ? 0 : (int) $time;
		}
	}
}
