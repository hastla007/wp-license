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
		const VERSION = '2.1.1';

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
		 * Seconds the stored `fetched_at` may lie ahead of the local clock before the snapshot counts as tampered.
		 */
		const CLOCK_SKEW = 300;

		/**
		 * Seconds the local clock may run past the signed `cache_expires_at` before the snapshot is `stale` whatever
		 * the stored clock fields say (since 2.1.0). Filter `{prefix}_license_offline_tolerance`.
		 */
		const OFFLINE_TOLERANCE = 86400;

		/**
		 * Upper bound of the filtered tolerance: seven days.
		 */
		const MAX_OFFLINE_TOLERANCE = 604800;

		/**
		 * Seconds between two background checks scheduled because the clock went back.
		 */
		const RECHECK_LOCK = 900;

		/**
		 * Sites handled per get_sites() query when uninstalling on a multisite.
		 */
		const UNINSTALL_BATCH = 100;

		/**
		 * Seconds before the end of the signed offline window from which Site Health recommends a look (since 2.1.1).
		 */
		const HEALTH_LOW_WINDOW = 259200;

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
		 * Transport client, created on first use (see build_client()).
		 *
		 * @var LicenseClient|null
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
		 * State of this request that the database did not take (see write()); null when the stored one counts.
		 *
		 * @var array<string,mixed>|null
		 */
		private $unsaved;

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
		 *     @type string          $settings_url    Full admin address of the add-on's own screen that calls render_settings().
		 *                                            Needed with `settings_parent => false` (redirect after the form, notice links).
		 *     @type string          $capability      Capability that may manage the license.
		 *     @type string          $account_url     Customer account of the shop (renewal link in notices).
		 *     @type string[]        $fallback_urls   Further server addresses.
		 *     @type string          $rest_base       REST base when the server does not use `/wp-json`.
		 *     @type OptionStore     $store           Storage (tests).
		 *     @type callable        $transport       HTTP transport (tests); also used for update downloads.
		 *     @type callable        $clock           Clock (tests).
		 *     @type callable|false  $signature_verifier Ed25519 verification (tests, see LicenseClient).
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
					'settings_url'    => '',
					'public_keys'     => array(),
				),
				$config,
				array(
					'product_key' => $product_key,
					'network'     => $network,
				)
			);
		}

		/**
		 * Build the transport client on first use.
		 *
		 * The filters `{prefix}_license_server_url` and `{prefix}_license_public_keys`
		 * are applied here, not in the constructor, so that a filter added after the
		 * add-on file was included (another plugin, the theme) still counts as long
		 * as nothing asked for the license before.
		 *
		 * @return LicenseClient
		 */
		private function build_client(): LicenseClient {
			$config      = $this->config;
			$prefix      = $this->prefix;
			$network     = (bool) $config['network'];
			$product_key = (string) $config['product_key'];
			$server_url  = (string) ( $config['server_url'] ?? '' );
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

			$public_keys = (array) $this->config['public_keys'];
			if ( '' !== $prefix && function_exists( 'apply_filters' ) && self::is_dev_build( $prefix ) ) {
				/**
				 * Filters the pinned public keys of the license server (development builds only).
				 *
				 * Honoured only when the constant `{PREFIX}_LICENSE_DEV` (the option
				 * prefix in upper case, e.g. `VOUCHER_CAPTAIN_PRO_LICENSE_DEV`) is
				 * defined and true. In a release build the filter is ignored, so no
				 * other plugin can pin a key of its own and sign rights for this add-on.
				 *
				 * @param string[] $public_keys Base64 keys from the add-on's configuration.
				 */
				$public_keys = (array) apply_filters( $prefix . '_license_public_keys', $public_keys ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- prefixed with the add-on's own prefix.
			}

			$allow_local = self::is_dev_build( $prefix );
			if ( '' !== $prefix && function_exists( 'apply_filters' ) ) {
				/**
				 * Filters whether the license server may have a local or private address (since 2.1.0).
				 *
				 * Requests to the license server go through wp_safe_remote_request(), which refuses
				 * hosts that resolve to a loopback or private IP address and ports other than 80, 443
				 * and 8080. For a development or test server on such an address, return true: only the
				 * host and port of the configured server addresses are then let through, nothing else.
				 * Default: true in a development build (`{PREFIX}_LICENSE_DEV`), false otherwise.
				 *
				 * @param bool $allow Whether the server host may resolve to a local or private address.
				 */
				$allow_local = (bool) apply_filters( $prefix . '_license_allow_local_server', $allow_local ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- prefixed with the add-on's own prefix.
			}

			return new LicenseClient(
				$server_url,
				$product_key,
				(string) $this->config['version'],
				$prefix . '_license',
				array(
					// An empty list would switch the server signature off; the facade does not allow that.
					'public_keys'        => empty( $public_keys ) ? array( '' ) : $public_keys,
					'text_domain'        => (string) $this->config['text_domain'],
					'network'            => $network,
					'fallback_urls'      => $config['fallback_urls'] ?? array(),
					'rest_base'          => $config['rest_base'] ?? '',
					'store'              => $config['store'] ?? null,
					'transport'          => $config['transport'] ?? null,
					'clock'              => $config['clock'] ?? null,
					'signature_verifier' => $config['signature_verifier'] ?? null,
					'allow_local_server' => $allow_local,
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
			if ( is_admin() || wp_doing_cron() ) {
				// Self-healing of the daily event; not needed on every front-end request.
				add_action( 'init', array( $this, 'ensure_schedule' ) );
			}
			add_action( 'admin_init', array( $this, 'catch_up' ) );
			add_action( 'admin_init', array( $this, 'add_privacy_text' ) );
			add_filter( 'debug_information', array( $this, 'debug_information' ) );
			add_filter( 'site_status_tests', array( $this, 'site_status_tests' ) );
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
		 * Whether the add-on runs as a development build: the constant `{PREFIX}_LICENSE_DEV` is defined and true.
		 *
		 * Only then is the filter `{prefix}_license_public_keys` applied. WP_DEBUG does not count: it is
		 * switched on on many live sites.
		 *
		 * @param string $prefix Option prefix of the add-on.
		 * @return bool
		 */
		public static function is_dev_build( string $prefix ): bool {
			$constant = strtoupper( $prefix ) . '_LICENSE_DEV';

			return '' !== $prefix && defined( $constant ) && true === (bool) constant( $constant );
		}

		/**
		 * Transport client of this add-on.
		 *
		 * @return LicenseClient
		 */
		public function client(): LicenseClient {
			if ( null === $this->client ) {
				$this->client = $this->build_client();
			}

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
		 * @param string $key Entitlement key, e.g. `pass.waiting_room`.
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

			if ( ! $this->client()->is_configured() ) {
				$status->state = LicenseStatus::STATE_MISCONFIGURED;
				return $status;
			}
			if ( ! $this->client()->is_activated() ) {
				$status->state = 'deactivated' === ( $state['lock'] ?? '' ) ? LicenseStatus::STATE_DEACTIVATED : LicenseStatus::STATE_UNLICENSED;
				return $status;
			}
			$status->installation_id = $this->client()->installation_id();
			$status->environment     = $this->client()->environment();
			if ( ! $this->client()->site_matches() ) {
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

			$server_now = $this->server_now( $state, $data );
			$expires    = self::utc( $status->cache_expires_at );
			$local_now  = $this->client()->now();
			if ( PHP_INT_MAX === $server_now && (int) ( $state['fetched_at'] ?? 0 ) > $local_now + self::CLOCK_SKEW ) {
				// The clock was turned back (NTP correction) or the option was edited: ask the server
				// in the background right away instead of waiting for the next daily check.
				$this->schedule_recheck();
			}
			if ( 0 === $expires || $server_now >= $expires ) {
				// The signed offline window is used up; only a new signed answer restores the rights.
				$status->state = LicenseStatus::STATE_STALE;
				return $status;
			}
			if ( $local_now > $expires + $this->offline_tolerance() ) {
				// Absolute limit (since 2.1.0): the local clock is past the signed deadline. Resetting the unsigned
				// clock fields of the option does not change this; only a new signed answer (or a clock turned back) does.
				$status->state  = LicenseStatus::STATE_STALE;
				$status->reason = LicenseStatus::REASON_CLOCK_AHEAD;
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

		/**
		 * Seconds the local clock may run past the signed `cache_expires_at` (since 2.1.0).
		 *
		 * Default OFFLINE_TOLERANCE (one day), filter `{prefix}_license_offline_tolerance`,
		 * limited to 0 … MAX_OFFLINE_TOLERANCE (seven days).
		 *
		 * @return int
		 */
		public function offline_tolerance(): int {
			$tolerance = self::OFFLINE_TOLERANCE;
			if ( '' !== $this->prefix && function_exists( 'apply_filters' ) ) {
				/**
				 * Filters how long the local clock may run past the signed end of the offline window.
				 *
				 * A stored snapshot is `stale` (reason `clock_ahead`) as soon as the local time is later
				 * than its signed `cache_expires_at` plus this tolerance, whatever the unsigned clock
				 * fields next to it say. A site whose clock is ahead by more than the offline window plus
				 * the tolerance therefore loses the premium features after every check. Values outside
				 * 0 … 604800 (seven days) are limited to that range.
				 *
				 * @since 2.1.0
				 *
				 * @param int $tolerance Seconds. Default 86400.
				 */
				$tolerance = (int) apply_filters( $this->prefix . '_license_offline_tolerance', $tolerance ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- prefixed with the add-on's own prefix.
			}

			return max( 0, min( self::MAX_OFFLINE_TOLERANCE, $tolerance ) );
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
			$data   = $this->client()->activate( $license_key, $environment, $staging_parent_installation_id );

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

			return $this->after_activation( $this->client()->move( $license_key ), $before );
		}

		/**
		 * Release the activation of this site (or only forget copied credentials on a copy).
		 *
		 * The stored rights are dropped whenever the server released the activation, also when the
		 * credentials could not be removed (`wplc_credentials_not_removed`, returned as the error).
		 * A success is returned only when credentials and stored state are both gone.
		 *
		 * @return array<string,mixed>|\WP_Error Result of LicenseClient::deactivate().
		 */
		public function deactivate() {
			$before = $this->status()->state;
			$result = $this->client()->deactivate();
			$gone   = ! is_wp_error( $result )
				|| ( 'wplc_credentials_not_removed' === $result->get_error_code() && ! empty( ( (array) $result->get_error_data() )['released'] ) );
			if ( $gone ) {
				$removed = $this->drop_state();
				$this->updater()->flush();
				$this->ensure_schedule();
				$this->changed( $before );
				if ( ! $removed && ! is_wp_error( $result ) ) {
					$result = $this->error( 'wplc_credentials_not_removed', array( 'released' => true ) );
				}
			}

			return $result;
		}

		/**
		 * Delete the credentials and the cached rights of this site without asking the server.
		 *
		 * For an explicit admin action only: deactivate() keeps the credentials on an
		 * unsigned refusal, which leaves this as the way out when the server really
		 * no longer knows the installation.
		 *
		 * @since 2.0.3 Returns whether the data is gone (was void).
		 *
		 * @return bool Whether credentials and stored state are both removed.
		 */
		public function forget_locally(): bool {
			$before      = $this->status()->state;
			$credentials = $this->client()->forget();
			$state       = $this->drop_state();
			$this->updater()->flush();
			$this->ensure_schedule();
			$this->changed( $before );

			return $credentials && $state;
		}

		/**
		 * Ask the server for the current status ("Check now", daily check).
		 *
		 * @param bool $force False respects the back-off after failed attempts.
		 * @return LicenseStatus|\WP_Error The error of the request; the stored status is kept or changed according to error_class().
		 */
		public function refresh( bool $force = true ) {
			if ( ! $this->client()->is_activated() ) {
				return $this->error( 'wplc_not_activated' );
			}
			$state = $this->state();
			$now   = $this->client()->now();
			if ( ! $force && $now < (int) ( $state['next_attempt'] ?? 0 ) && $now >= (int) ( $state['last_attempt'] ?? 0 ) ) {
				return $this->error( 'wplc_backoff', array( 'next_attempt' => (int) $state['next_attempt'] ), 'wplc_connection_failed' );
			}
			$before = $this->status()->state;
			$result = $this->client()->check_entitlements();
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
			if ( $this->client()->is_activated() && $this->client()->site_matches() ) {
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
			if ( $this->client()->is_activated() && ! $scheduled ) {
				// A random offset spreads the checks of all customer sites over the day.
				wp_schedule_event( time() + wp_rand( 300, DAY_IN_SECONDS ), 'daily', $this->cron_hook() );
			} elseif ( $scheduled && ! $this->client()->is_activated() ) {
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
		 * Schedule a one-off background check now, at most once per RECHECK_LOCK seconds.
		 *
		 * Used when the stored snapshot cannot be dated because the clock went
		 * back: the premium features are off until a new signed answer arrives,
		 * which this brings within minutes instead of up to a day. It is no way
		 * around the rule: only a signed answer restores the rights.
		 *
		 * @return void
		 */
		private function schedule_recheck(): void {
			if ( ! function_exists( 'wp_schedule_single_event' ) || ! function_exists( 'get_transient' ) || ! $this->client()->site_matches() ) {
				return;
			}
			$lock = $this->prefix . '_license_recheck';
			if ( false !== get_transient( $lock ) ) {
				return;
			}
			set_transient( $lock, 1, self::RECHECK_LOCK );
			wp_schedule_single_event( time(), $this->cron_hook() );
		}

		/**
		 * Catch up in the admin when WP-Cron did not run or the last attempt failed.
		 *
		 * @return void
		 */
		public function catch_up(): void {
			if ( wp_doing_ajax() || ! current_user_can( (string) $this->config['capability'] ) || ! $this->client()->is_activated() || ! $this->client()->site_matches() ) {
				return;
			}
			$state = $this->state();
			$now   = $this->client()->now();
			$last  = (int) ( $state['last_attempt'] ?? 0 );
			$due   = empty( $state['error'] )
				? $now - $last > self::CATCH_UP_AFTER
				: $now >= (int) ( $state['next_attempt'] ?? 0 );
			if ( $due || $last > $now || (int) ( $state['fetched_at'] ?? 0 ) > $now + self::CLOCK_SKEW ) {
				$this->refresh( false );
			}
		}

		/**
		 * Remove everything this add-on stored and release the activation. For `uninstall.php`.
		 *
		 * The server is asked with a short timeout; whatever it answers, the
		 * local data is removed. If the server cannot be reached the site slot
		 * stays in use until the customer releases it in the account. On a
		 * multisite (per-site activations) every site is cleaned, in batches of
		 * UNINSTALL_BATCH; the server is only asked during the first 20 seconds.
		 *
		 * @return void
		 */
		public function uninstall(): void {
			$started = time();
			if ( function_exists( 'is_multisite' ) && is_multisite() && ! $this->config['network'] ) {
				$offset = 0;
				do {
					$sites = get_sites(
						array(
							'fields'                 => 'ids',
							'number'                 => self::UNINSTALL_BATCH,
							'offset'                 => $offset,
							'orderby'                => 'id',
							'order'                  => 'ASC',
							'no_found_rows'          => true,
							'update_site_meta_cache' => false,
						)
					);
					foreach ( $sites as $site_id ) {
						switch_to_blog( (int) $site_id );
						$this->uninstall_site( $started );
						restore_current_blog();
					}
					$offset += self::UNINSTALL_BATCH;
				} while ( count( $sites ) === self::UNINSTALL_BATCH );
				// The update data is kept once for the network (see Updater).
				$this->updater()->flush();
			} else {
				$this->uninstall_site( $started );
			}
			$this->snapshot = null;
		}

		/**
		 * Uninstall step for the current site.
		 *
		 * @param int $started Unix time the uninstall started.
		 * @return void
		 */
		private function uninstall_site( int $started ): void {
			if ( $this->client()->is_activated() && time() - $started < 20 ) {
				$this->client()->deactivate();
			}
			$this->client()->forget();
			$this->client()->store()->delete( $this->state_option() );
			$this->client()->store()->delete( $this->prefix . '_update_meta' );
			wp_clear_scheduled_hook( $this->cron_hook() );
		}

		// ------------------------------------------------------------------
		// Admin screen and notices.
		// ------------------------------------------------------------------

		/**
		 * Address of the license screen.
		 *
		 * The `settings_url` setting when the add-on gives one (its own screen or
		 * tab that calls render_settings()); otherwise the screen this class adds.
		 * With `settings_parent => false` and no `settings_url` there is no known
		 * screen: the result is an empty string (notices then carry no link and
		 * the form returns to the page it was sent from).
		 *
		 * @return string
		 */
		public function settings_url(): string {
			$url = (string) ( $this->config['settings_url'] ?? '' );
			if ( '' === $url && false !== $this->config['settings_parent'] && '' !== $this->config['settings_parent'] ) {
				$parent = (string) $this->config['settings_parent'];
				$parent = false === strpos( $parent, '.php' ) ? 'admin.php' : $parent;
				$url    = $parent . ( false === strpos( $parent, '?' ) ? '?' : '&' ) . 'page=' . $this->page_slug();
				$url    = $this->config['network'] ? network_admin_url( $url ) : admin_url( $url );
			}

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
			if ( '' !== $status->reason ) {
				$this->row( $this->text( 'ui.reason' ), $this->text( 'reason.' . $status->reason ) );
			}
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
				$this->row( $this->text( 'ui.last_error' ), $this->client()->messages()->error( $status->last_error ) . ' (' . $status->last_error . ', ' . $this->date( gmdate( 'Y-m-d H:i:s', $status->last_error_at ) ) . ')' );
			}
			echo '</tbody></table>';

			if ( LicenseStatus::STATE_MISCONFIGURED === $status->state ) {
				echo '</div>';
				return;
			}

			// admin-post.php exists only in wp-admin/, also in network mode (there is no wp-admin/network/admin-post.php).
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
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
				$old = $this->client()->activated_site_url();
				$new = $this->client()->store()->site_url();
				echo '<p>' . esc_html( $this->client()->messages()->error( 'wplc_site_changed' ) ) . '</p>';
				echo '<table class="form-table" role="presentation"><tbody>';
				$this->row( $this->text( 'ui.activated_address' ), $old );
				$this->row( $this->text( 'ui.current_address' ), $new );
				echo '</tbody></table>';
				echo '<p>' . wp_kses( $label . $key_input, $allowed ) . '</p><p>';
				$this->button( 'activate', $this->text( 'ui.activate_copy' ), true );
				$this->button( 'deactivate', $this->text( 'ui.forget' ) );
				echo '</p>';
				// Transferring takes the license away from the address it was activated for: only after an explicit confirmation.
				echo '<p class="description">' . esc_html( sprintf( $this->text( 'ui.move_warning' ), $old, $new ) );
				if ( 'production' !== LicenseClient::detect_environment() ) {
					echo ' ' . esc_html( $this->text( 'ui.move_not_live' ) );
				}
				echo '</p><p><label><input type="checkbox" name="wplc_confirm_move" value="1"> ' . esc_html( sprintf( $this->text( 'ui.move_confirm' ), $old ) ) . '</label></p><p>';
				$this->button( 'move', $this->text( 'ui.move' ) );
				echo '</p>';
			} elseif ( '' !== $status->installation_id ) {
				echo '<p>';
				$this->button( 'check', $this->text( 'ui.check_now' ), true );
				if ( $status->needs_reactivation ) {
					$this->button( 'forget', $this->text( 'ui.forget_local' ) );
				}
				// Releasing gives up the site slot and switches the features off at once: only after an explicit confirmation.
				echo '</p><p><label><input type="checkbox" name="wplc_confirm_deactivate" value="1"> ' . esc_html( $this->text( 'ui.deactivate_confirm' ) ) . '</label></p><p>';
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
			// Each action that gives something up has its own confirmation field: `move`, and since 2.1.0 `deactivate`.
			$confirm = 'deactivate' === $do ? 'wplc_confirm_deactivate' : 'wplc_confirm_move';
			$result  = $this->run_form_action( $do, $key, ! empty( $_POST[ $confirm ] ) );

			set_transient( $this->flash_key(), $result, 60 );
			$target = $this->settings_url();
			if ( '' === $target ) {
				// No license screen of this class: back to the add-on's own page that sent the form.
				$target = (string) wp_get_referer();
			}
			if ( '' === $target ) {
				$target = $this->config['network'] ? network_admin_url() : admin_url();
			}
			wp_safe_redirect( $target );
			exit;
		}

		/**
		 * Execute one action of the license form.
		 *
		 * Since 2.1.0 `deactivate` needs `$confirmed = true` as well, like `move`, whenever it would
		 * release the activation on the server (activated site under its activated address).
		 * Without it nothing is sent and an error message is returned. On a copy of the site
		 * (`site_changed`) `deactivate` only removes the copied data and needs no confirmation.
		 * Code that calls this without the form confirms explicitly: `run_form_action( 'deactivate', '', true )`.
		 *
		 * @param string $action      `activate`, `move`, `deactivate`, `forget` or `check`.
		 * @param string $license_key Key from the form (`activate`, `move`).
		 * @param bool   $confirmed   For `move`: the administrator confirmed that the site moved (the old address loses the license).
		 *                            For `deactivate` (since 2.1.0): the administrator confirmed that this site gives up its license.
		 * @return array{type:string,text:string} Message for the next page view.
		 */
		public function run_form_action( string $action, string $license_key = '', bool $confirmed = false ): array {
			$saved = 'ui.saved.checked';
			if ( 'activate' === $action ) {
				$result = $this->activate( $license_key );
				$saved  = 'ui.saved.activated';
			} elseif ( 'move' === $action ) {
				if ( ! $confirmed ) {
					return array(
						'type' => 'error',
						'text' => $this->text( 'ui.move_unconfirmed' ),
					);
				}
				$result = $this->move( $license_key );
				$saved  = 'ui.saved.moved';
			} elseif ( 'deactivate' === $action ) {
				if ( ! $confirmed && $this->client()->is_activated() && $this->client()->site_matches() ) {
					return array(
						'type' => 'error',
						'text' => $this->text( 'ui.deactivate_unconfirmed' ),
					);
				}
				$result = $this->deactivate();
				$saved  = is_array( $result ) && 'removed_locally' === ( $result['status'] ?? '' ) && in_array( $result['reason'] ?? '', array( 'wplc_site_changed', 'site_mismatch' ), true ) ? 'ui.saved.deactivated_locally' : 'ui.saved.deactivated';
			} elseif ( 'forget' === $action ) {
				$result = $this->forget_locally() ? array() : $this->error( 'wplc_credentials_not_removed' );
				$saved  = 'ui.saved.forgotten';
			} else {
				$result = $this->refresh( true );
			}
			if ( is_wp_error( $result ) ) {
				return array(
					'type' => 'error',
					'text' => $this->error_text( $result ) . ' (' . $result->get_error_code() . ')',
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
			$url = $on_page ? '' : $this->settings_url();
			if ( '' !== $url ) {
				printf( ' <a href="%s">%s</a>', esc_url( $url ), esc_html( $this->text( 'notice.open_settings' ) ) );
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
					} elseif ( '' !== $status->last_error && $this->client()->now() - $status->last_checked > 3 * DAY_IN_SECONDS ) {
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
					$key  = LicenseStatus::REASON_CLOCK_AHEAD === $status->reason ? 'notice.stale_clock_ahead' : 'notice.' . $status->state;
					$text = sprintf( $this->text( $key ), $name );
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
		 * Translated text of an error of the library, for display.
		 *
		 * Errors created before `init` carry their code as message, because no translation may be
		 * loaded that early (Messages::ready()); this looks the text up when it is shown. Any other
		 * message is returned as it is. Add-ons that print errors of the library use this instead
		 * of `get_error_message()`.
		 *
		 * @since 2.1.0
		 *
		 * @param \WP_Error $error Error of LicenseManager, LicenseClient or Updater.
		 * @return string
		 */
		public function error_text( \WP_Error $error ): string {
			$code    = (string) $error->get_error_code();
			$message = (string) $error->get_error_message();
			if ( '' !== $message && $message !== $code ) {
				return $message;
			}

			return $this->client()->messages()->error( 'wplc_backoff' === $code ? 'wplc_connection_failed' : $code );
		}

		/**
		 * Section of this add-on under Tools → Site Health → Info (filter `debug_information`).
		 *
		 * State with its reason, last successful check, last error with its time, end of the offline
		 * window, environment, a shortened installation ID, server host, how signatures are checked,
		 * fingerprints of the pinned keys and the library version. Never the license key (it is not
		 * stored), the installation secret or the snapshot.
		 *
		 * @since 2.1.0
		 *
		 * @param mixed $info Sections of Site Health (an array; other plugins may break the filter value).
		 * @return array<string,mixed>
		 */
		public function debug_information( $info ) {
			$info   = is_array( $info ) ? $info : array();
			$status = $this->status();
			$client = $this->client();
			$none   = $this->text( 'debug.none' );
			$utc    = static function ( int $time ): string {
				return gmdate( 'Y-m-d H:i:s', $time ) . ' UTC';
			};

			$state_text = $this->text( 'state.' . $status->state );
			if ( '' !== $status->reason ) {
				$state_text .= ' – ' . $this->text( 'reason.' . $status->reason );
			}
			$error_text  = $none;
			$error_debug = 'none';
			if ( '' !== $status->last_error ) {
				$error_text  = $client->messages()->error( $status->last_error ) . ' (' . $status->last_error . ', ' . $this->date( gmdate( 'Y-m-d H:i:s', $status->last_error_at ) ) . ')';
				$error_debug = $status->last_error . ', ' . $utc( $status->last_error_at );
			}
			$signature = 'none';
			if ( $client->can_verify_server_signature() ) {
				$signature = $client->uses_native_ed25519() ? 'native' : 'compat';
			}
			$fingerprints = array();
			foreach ( (array) $this->config['public_keys'] as $key ) {
				$binary = is_string( $key ) ? base64_decode( trim( $key ), true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decodes a public key.
				if ( is_string( $binary ) && 32 === strlen( $binary ) ) {
					$fingerprints[] = substr( hash( 'sha256', $binary ), 0, 16 );
				}
			}
			// Shortened: enough for support to find the activation, not the full identifier.
			$installation = '' === $status->installation_id ? '' : substr( $status->installation_id, 0, 8 ) . '…';
			$host         = $client->server_host();

			$fields = array(
				'state'         => array(
					'label' => $this->text( 'ui.status' ),
					'value' => $state_text,
					'debug' => $status->state . ( '' === $status->reason ? '' : ' (' . $status->reason . ')' ),
				),
				'last_checked'  => array(
					'label' => $this->text( 'ui.last_checked' ),
					'value' => 0 === $status->last_checked ? $this->text( 'ui.never' ) : $this->date( gmdate( 'Y-m-d H:i:s', $status->last_checked ) ),
					'debug' => 0 === $status->last_checked ? 'never' : $utc( $status->last_checked ),
				),
				'last_error'    => array(
					'label' => $this->text( 'ui.last_error' ),
					'value' => $error_text,
					'debug' => $error_debug,
				),
				'offline_until' => array(
					'label' => $this->text( 'debug.offline_until' ),
					'value' => '' === $status->cache_expires_at ? $none : $this->date( $status->cache_expires_at ),
					'debug' => '' === $status->cache_expires_at ? 'none' : $status->cache_expires_at . ' UTC',
				),
				'environment'   => array(
					'label' => $this->text( 'ui.environment' ),
					'value' => '' === $status->environment ? $none : $this->text( 'ui.environment.' . $status->environment ),
					'debug' => '' === $status->environment ? 'none' : $status->environment,
				),
				'installation'  => array(
					'label' => $this->text( 'debug.installation' ),
					'value' => '' === $installation ? $none : $installation,
					'debug' => '' === $installation ? 'none' : $installation,
				),
				'server'        => array(
					'label' => $this->text( 'debug.server' ),
					'value' => '' === $host ? $none : $host,
					'debug' => '' === $host ? 'none' : $host,
				),
				'signature'     => array(
					'label' => $this->text( 'debug.signature' ),
					'value' => $this->text( 'debug.signature.' . $signature ),
					'debug' => $signature,
				),
				'keys'          => array(
					'label' => $this->text( 'debug.keys' ),
					'value' => empty( $fingerprints ) ? $none : implode( ', ', $fingerprints ),
					'debug' => empty( $fingerprints ) ? 'none' : implode( ', ', $fingerprints ),
				),
				'version'       => array(
					'label' => $this->text( 'debug.version' ),
					'value' => self::VERSION,
				),
			);

			$info[ $this->prefix . '_license' ] = array(
				'label'  => sprintf( '%s – %s', (string) $this->config['name'], $this->text( 'ui.title' ) ),
				'fields' => $fields,
			);

			return $info;
		}

		/**
		 * Add the direct test "License check" of this add-on to Tools → Site Health → Status (filter `site_status_tests`).
		 *
		 * Only while the add-on is configured and this site holds an activation (or a signed answer released it);
		 * without one there is no check that could be overdue, the notice asks for the key.
		 *
		 * @since 2.1.1
		 *
		 * @param mixed $tests Tests of Site Health, keys `direct` and `async` (an array; other plugins may break the filter value).
		 * @return array<string,mixed>
		 */
		public function site_status_tests( $tests ) {
			$tests  = is_array( $tests ) ? $tests : array();
			$client = $this->client();
			if ( ! $client->is_configured() ) {
				return $tests;
			}
			$state = $this->state();
			if ( ! $client->is_activated() && 'deactivated' !== ( $state['lock'] ?? '' ) ) {
				return $tests;
			}
			$tests['direct'] = isset( $tests['direct'] ) && is_array( $tests['direct'] ) ? $tests['direct'] : array();
			$tests['direct'][ $this->prefix . '_license_check' ] = array(
				'label' => sprintf( '%s – %s', (string) $this->config['name'], $this->text( 'ui.title' ) ),
				'test'  => array( $this, 'site_health_test' ),
			);

			return $tests;
		}

		/**
		 * Result of the Site Health test "License check": is the daily check of the license working?
		 *
		 * - `good`: the last successful check is recent (within CATCH_UP_AFTER) and did not fail since.
		 * - `recommended`: the last check failed, the check is overdue, the server asks for the key again,
		 *   the site address changed, or less than HEALTH_LOW_WINDOW of the signed offline window is left.
		 * - `critical`: the snapshot is `stale` (offline window used up, clock ahead, not verifiable) or a
		 *   signed answer released the activation.
		 *
		 * Shows dates, the error text and the server host; never the key, the secret or the snapshot.
		 *
		 * @since 2.1.1
		 *
		 * @return array{label:string,status:string,badge:array{label:string,color:string},description:string,actions:string,test:string}
		 */
		public function site_health_test(): array {
			$status  = $this->status();
			$name    = (string) $this->config['name'];
			$now     = $this->client()->now();
			$expires = self::utc( $status->cache_expires_at );
			$until   = 0 === $expires ? $this->text( 'debug.none' ) : $this->date( $status->cache_expires_at );
			$checked = 0 === $status->last_checked ? $this->text( 'ui.never' ) : $this->date( gmdate( 'Y-m-d H:i:s', $status->last_checked ) );
			$failed  = '' !== $status->last_error && $status->last_error_at >= $status->last_checked;

			if ( in_array( $status->state, array( LicenseStatus::STATE_STALE, LicenseStatus::STATE_DEACTIVATED ), true ) ) {
				$result      = 'critical';
				$label       = sprintf( $this->text( LicenseStatus::STATE_STALE === $status->state ? 'health.stale' : 'health.locked' ), $name );
				$notice      = LicenseStatus::REASON_CLOCK_AHEAD === $status->reason ? 'notice.stale_clock_ahead' : 'notice.' . $status->state;
				$description = sprintf( $this->text( $notice ), $name );
			} elseif ( LicenseStatus::STATE_SITE_CHANGED === $status->state ) {
				$result      = 'recommended';
				$label       = sprintf( $this->text( 'health.site_changed' ), $name );
				$description = sprintf( $this->text( 'notice.site_changed' ), $name );
			} elseif ( $status->needs_reactivation ) {
				$result      = 'recommended';
				$label       = sprintf( $this->text( 'health.failing' ), $name );
				$description = sprintf( $this->text( 'notice.reactivate' ), $name );
			} elseif ( $failed ) {
				$result      = 'recommended';
				$label       = sprintf( $this->text( 'health.failing' ), $name );
				$description = sprintf( $this->text( 'health.failing_text' ), $this->client()->messages()->error( $status->last_error ), $checked, $until, $this->client()->server_host() );
			} elseif ( 0 !== $expires && $expires - $now < self::HEALTH_LOW_WINDOW ) {
				$result      = 'recommended';
				$label       = sprintf( $this->text( 'health.running_low' ), $name );
				$description = sprintf( $this->text( 'health.overdue_text' ), $checked, $until, $this->client()->server_host() );
			} elseif ( $now - $status->last_checked > self::CATCH_UP_AFTER ) {
				$result      = 'recommended';
				$label       = sprintf( $this->text( 'health.overdue' ), $name );
				$description = sprintf( $this->text( 'health.overdue_text' ), $checked, $until, $this->client()->server_host() );
			} else {
				$result      = 'good';
				$label       = sprintf( $this->text( 'health.good' ), $name );
				$description = sprintf( $this->text( 'health.good_text' ), $checked, $until );
			}

			$actions = '';
			$url     = $this->settings_url();
			if ( '' !== $url ) {
				$actions = sprintf( '<p><a href="%s">%s</a></p>', esc_url( $url ), esc_html( $this->text( 'notice.open_settings' ) ) );
			}
			$colors = array(
				'good'        => 'blue',
				'recommended' => 'orange',
				'critical'    => 'red',
			);

			return array(
				'label'       => $label,
				'status'      => $result,
				'badge'       => array(
					'label' => $this->text( 'ui.title' ),
					'color' => $colors[ $result ],
				),
				'description' => '<p>' . esc_html( $description ) . '</p>',
				'actions'     => $actions,
				'test'        => $this->prefix . '_license_check',
			);
		}

		/**
		 * Suggested paragraph for the privacy policy of the customer site.
		 *
		 * @return string
		 */
		public function privacy_text(): string {
			return sprintf( $this->text( 'privacy.text' ), (string) $this->config['name'], $this->client()->server_host() );
		}

		/**
		 * Offer the privacy paragraph under Settings → Privacy → policy guide.
		 *
		 * @return void
		 */
		public function add_privacy_text(): void {
			if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
				wp_add_privacy_policy_content( (string) $this->config['name'] . ' – ' . $this->text( 'privacy.title' ), wp_kses_post( wpautop( $this->privacy_text(), false ) ) );
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
			$now   = $this->client()->now();

			$state['last_attempt'] = $now;
			$state['clock_high']   = max( $now, (int) ( $state['clock_high'] ?? 0 ) );
			unset( $state['verified_at'], $state['server_time'] ); // Unsigned fields of client 2.0.0; never read again.

			if ( ! is_wp_error( $result ) ) {
				$server_time = self::utc( (string) ( $result['server_time'] ?? '' ) );
				if ( 0 === $server_time || empty( $this->client()->last_envelope() ) ) {
					$result = $this->error( 'wplc_response_invalid' );
				} elseif ( $server_time < (int) ( $state['server_time_max'] ?? 0 ) - 300 ) {
					// An answer older than one already seen is a replay, whatever it says.
					$result = $this->error( 'wplc_response_stale' );
				}
			}

			if ( ! is_wp_error( $result ) ) {
				$state['server_time_max']    = max( $server_time, (int) ( $state['server_time_max'] ?? 0 ) );
				$state['fetched_at']         = $now;
				$state['clock_high']         = $now; // A signed answer anchors the time again.
				$state['last_success']       = $now;
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
					$state['envelope'] = $this->client()->last_envelope();
				}
				if ( ! $this->write( $state ) ) {
					return $this->not_saved();
				}

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
				if ( ! $this->write( $state ) ) {
					return $this->not_saved();
				}

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
			// HMAC and Ed25519 are checked on every read. Nothing stored next to the snapshot can switch the
			// server signature off: without ext-sodium WordPress's sodium_compat verifies (its result is
			// remembered under a key bound to the signature, see LicenseClient::verified_snapshot_data()),
			// and a PHP with neither never accepts the snapshot. No texts are built here (early is_entitled()).
			$data = $this->client()->verified_snapshot_data( $state['envelope'] );
			if ( ! is_array( $data ) ) {
				return $this->snapshot;
			}
			$this->snapshot = $data;

			return $this->snapshot;
		}

		/**
		 * Time of the license server right now, derived from the signed `server_time` of the verified snapshot.
		 *
		 * The base is only the signed `server_time` inside the verified payload.
		 * The local clock measures the time since the snapshot was stored
		 * (`fetched_at`), so a wrong time zone or a constant offset does not
		 * shift the deadline. Since 2.1.0 status() adds an absolute limit on
		 * top: the local time must not be later than `cache_expires_at` plus
		 * offline_tolerance(), whatever the unsigned fields below say. The unsigned stored fields can only make the result later,
		 * never earlier: `clock_high` (highest local time seen, so turning the
		 * clock back does not extend the offline window) and `server_time_max`
		 * (highest signed server time seen). A `fetched_at` more than CLOCK_SKEW
		 * seconds in the future means the option was edited (or the clock was
		 * turned back before the last answer): the snapshot then counts as
		 * expired until a new signed answer arrives (catch_up() asks at once).
		 *
		 * @param array<string,mixed> $state Stored state.
		 * @param array<string,mixed> $data  Verified data of the snapshot.
		 * @return int Unix time; PHP_INT_MAX when the snapshot cannot be dated.
		 */
		private function server_now( array $state, array $data ): int {
			$signed  = self::utc( (string) ( $data['server_time'] ?? '' ) );
			$now     = $this->client()->now();
			$fetched = (int) ( $state['fetched_at'] ?? 0 );
			if ( 0 === $signed || $fetched <= 0 || $fetched > $now + self::CLOCK_SKEW ) {
				return PHP_INT_MAX;
			}
			$local = max( $now, (int) ( $state['clock_high'] ?? 0 ) );

			return max( $signed + max( 0, $local - $fetched ), (int) ( $state['server_time_max'] ?? 0 ) );
		}

		/**
		 * Stored state of this add-on.
		 *
		 * @return array<string,mixed>
		 */
		private function state(): array {
			if ( null !== $this->unsaved ) {
				return $this->unsaved;
			}
			$state = $this->client()->store()->get( $this->state_option(), array() );

			return is_array( $state ) ? $state : array();
		}

		/**
		 * Write the state and forget the memoized snapshot.
		 *
		 * A state the database did not take must not leave the older one in charge when that one
		 * grants more (R-26, audit item 9): a signed answer that withdraws the access or a right
		 * would otherwise stay without effect until the offline window of the older answer ends.
		 * In that case the stored state is deleted (the add-on then reports "stale", without
		 * rights, until an answer can be stored). An older state that grants the same is kept, so
		 * a database hiccup does not switch a paid add-on off. For the rest of the request the new
		 * state is used from memory either way. A database that accepts neither the write nor the
		 * delete keeps the old state; the error says so and the daily check repeats the request.
		 *
		 * @param array<string,mixed> $state State.
		 * @return bool Whether the state was stored (read back by OptionStore::set()).
		 */
		private function write( array $state ): bool {
			$this->unsaved  = null;
			$this->snapshot = null;
			if ( $this->client()->store()->set( $this->state_option(), $state ) ) {
				return true;
			}

			$stored         = $this->status();
			$this->unsaved  = $state;
			$this->snapshot = null;
			$current        = $this->status();
			if ( $stored->access && ( ! $current->access || $stored->entitlements !== $current->entitlements ) ) {
				$this->client()->store()->delete( $this->state_option() );
			}

			return false;
		}

		/**
		 * Remove the stored state with its snapshot; when the option cannot be deleted, overwrite it with an empty state.
		 *
		 * @return bool Whether no snapshot is stored any more.
		 */
		private function drop_state(): bool {
			$removed = $this->client()->store()->delete( $this->state_option() ) || $this->write( array() );

			$this->snapshot = null;

			return $removed;
		}

		/**
		 * A signed answer could not be stored (see write() for what happened to the older state).
		 *
		 * @return \WP_Error `wplc_state_not_saved`.
		 */
		private function not_saved(): \WP_Error {
			return $this->error( 'wplc_state_not_saved' );
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
			return $this->client()->messages()->get( $code );
		}

		/**
		 * Error of the manager; before `init` its message is the code (no text is built, see Messages::ready()).
		 *
		 * @param string              $code      Error code.
		 * @param array<string,mixed> $data      Error data.
		 * @param string              $text_code Code whose text is used, when it differs.
		 * @return \WP_Error
		 */
		private function error( string $code, array $data = array(), string $text_code = '' ): \WP_Error {
			return new \WP_Error( $code, $this->client()->messages()->error_message( '' === $text_code ? $code : $text_code ), $data );
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
