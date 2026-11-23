<?php
/**
 * Plugin settings page and option access.
 *
 * @package WPLC
 */

namespace WPLC\Admin;

use WPLC\Compatibility\Requirements;
use WPLC\Infrastructure\Database\Schema;
use WPLC\License\AuditLog;
use WPLC\License\LicenseLifecycle;
use WPLC\Settings\Registry;
use WPLC\Settings\Texts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce → License settings. Secrets stay in wp-config.php constants.
 *
 * What a setting is (key, type, default, limits, group, capability) stands in
 * Settings\Registry; this class is the page, the form and the change log.
 */
final class Settings {

	/**
	 * Option name and settings group.
	 */
	public const OPTION = Registry::OPTION;

	/**
	 * Package hosts the last sanitize() call of this request stored or kept (null before the first call).
	 *
	 * @var string[]|null
	 */
	private static $accepted_hosts = null;

	/**
	 * Admin page slug.
	 */
	public const PAGE = 'wplc-settings';

	/**
	 * Register the page, the option and the runtime filters fed by it.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ), 20 );
		add_action( 'admin_init', array( self::class, 'register_settings' ) );
		add_filter( 'option_page_capability_' . self::OPTION, array( self::class, 'capability' ) );
		add_filter( 'wplc_update_package_hosts', array( self::class, 'filter_package_hosts' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
		add_action( 'add_option_' . self::OPTION, array( self::class, 'audit_added' ), 10, 2 );
		add_action( 'update_option_' . self::OPTION, array( self::class, 'audit_updated' ), 10, 2 );
		if ( defined( 'WPLC_FILE' ) ) {
			add_filter( 'plugin_action_links_' . plugin_basename( WPLC_FILE ), array( self::class, 'plugin_action_links' ) );
		}
	}

	/**
	 * "Getting started" link in the plugin list: the entry point after activation (A-121).
	 *
	 * @param mixed $links Action links.
	 * @return mixed
	 */
	public static function plugin_action_links( $links ) {
		if ( ! is_array( $links ) || ! current_user_can( self::capability() ) ) {
			return $links;
		}
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Getting started', 'wp-license' ) . '</a>' );

		return $links;
	}

	/**
	 * Audit the first save of the option.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Stored value.
	 * @return void
	 */
	public static function audit_added( $option, $value ): void {
		self::audit_change( self::defaults(), $value );
	}

	/**
	 * Audit a change of the option. WordPress fires this only when the value changed.
	 *
	 * @param mixed $old_value Previous value.
	 * @param mixed $value     New value.
	 * @return void
	 */
	public static function audit_updated( $old_value, $value ): void {
		self::audit_change( $old_value, $value );
	}

	/**
	 * Write one `settings_updated` audit entry (object type `settings`, ID 0).
	 *
	 * @param mixed $old_value Previous value.
	 * @param mixed $value     New value.
	 * @return void
	 */
	private static function audit_change( $old_value, $value ): void {
		$diff = self::settings_diff( is_array( $old_value ) ? $old_value : array(), is_array( $value ) ? $value : array() );
		if ( ! $diff['changed'] ) {
			return;
		}
		AuditLog::record(
			'settings_updated',
			'settings',
			0,
			'Changed: ' . implode( ', ', $diff['changed'] ),
			$diff['before'],
			$diff['after'],
			function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0
		);
	}

	/**
	 * Compare two settings arrays for the audit log.
	 *
	 * Missing keys count as their default. Old and new value of every changed
	 * setting are recorded; free text is shortened (Registry::PREVIEW_LENGTH) and a
	 * setting flagged as secret appears by name only.
	 *
	 * @param array<string,mixed> $old Previous settings.
	 * @param array<string,mixed> $new New settings.
	 * @return array{changed:string[],before:array<string,mixed>,after:array<string,mixed>}
	 */
	public static function settings_diff( array $old, array $new ): array {
		$defaults = self::defaults();
		$old      = array_merge( $defaults, $old );
		$new      = array_merge( $defaults, $new );
		$keys     = array_unique( array_merge( array_keys( $old ), array_keys( $new ) ) );
		sort( $keys );

		$changed = array();
		$before  = array();
		$after   = array();
		foreach ( $keys as $key ) {
			$key       = (string) $key;
			$old_value = $old[ $key ] ?? null;
			$new_value = $new[ $key ] ?? null;
			if ( $old_value === $new_value ) {
				continue;
			}
			$changed[] = $key;
			$definition = Registry::definition( $key );
			if ( null === $definition || $definition['secret'] ) {
				continue;
			}
			$as_text        = in_array( $definition['type'], array( 'string', 'text' ), true );
			$before[ $key ] = $as_text ? Registry::display( $key, $old_value ) : $old_value;
			$after[ $key ]  = $as_text ? Registry::display( $key, $new_value ) : $new_value;
		}

		return array(
			'changed' => $changed,
			'before'  => $before,
			'after'   => $after,
		);
	}

	/**
	 * Default values of all stored settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return Registry::defaults();
	}

	/**
	 * Read one setting.
	 *
	 * @param string $key     Setting key (see Settings\Definitions).
	 * @param mixed  $default Value when the setting is not stored; null uses the built-in default.
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		return Registry::get( $key, $default );
	}

	/**
	 * Reminder lead times as integers, longest first.
	 *
	 * @return int[]
	 */
	public static function reminder_days(): array {
		return array_map( 'intval', explode( ',', self::sanitize_reminder_days( (string) self::get( 'reminder_days' ) ) ) );
	}

	/**
	 * Capability required to view and save the settings.
	 *
	 * @return string
	 */
	public static function capability(): string {
		return Capabilities::cap( 'manage_settings' );
	}

	/**
	 * Whether the current user may change the trusted package hosts.
	 *
	 * The hosts decide from where update packages reach customer sites, so by
	 * default only administrators may change them (B-49).
	 *
	 * @return bool
	 */
	public static function can_manage_package_hosts(): bool {
		return Capabilities::can( 'manage_package_hosts' );
	}

	/**
	 * Fingerprint of a host list; the form sends back the one it was rendered with.
	 *
	 * @param mixed $hosts Stored host list.
	 * @return string
	 */
	public static function hosts_fingerprint( $hosts ): string {
		$hosts = is_array( $hosts ) ? array_values( array_filter( $hosts, 'is_string' ) ) : array();

		return substr( hash( 'sha256', implode( "\n", $hosts ) ), 0, 20 );
	}

	/**
	 * Whether the value being sanitized comes from the settings form (options.php).
	 *
	 * @return bool
	 */
	private static function is_form_submission(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- options.php verified the nonce before the option is sanitized.
		return isset( $_POST['option_page'] ) && self::OPTION === sanitize_key( wp_unslash( $_POST['option_page'] ) );
	}

	/**
	 * Report a problem on the settings screen.
	 *
	 * @param string $code    Error code.
	 * @param string $message Translated message.
	 * @param string $type    error or warning.
	 * @return void
	 */
	private static function settings_error( string $code, string $message, string $type = 'error' ): void {
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error( self::OPTION, $code, $message, $type );
		}
	}

	/**
	 * Add the WooCommerce submenu page.
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		add_submenu_page(
			Capabilities::menu_parent(),
			__( 'License settings', 'wp-license' ),
			__( 'License settings', 'wp-license' ),
			self::capability(),
			self::PAGE,
			array( self::class, 'render' )
		);
		LicenseAdmin::connect_page( self::PAGE, __( 'License settings', 'wp-license' ) );
	}

	/**
	 * Register the option with the Settings API.
	 *
	 * @return void
	 */
	public static function register_settings(): void {
		register_setting(
			self::OPTION,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);

		// Sections and fields come from the register; a field with a renderer of its own
		// (render_<key>_field) keeps it, every other field uses the generic one.
		$titles = Texts::groups();
		foreach ( Registry::GROUPS as $group ) {
			$keys = Registry::keys_of_group( $group );
			if ( ! $keys ) {
				continue;
			}
			add_settings_section( 'wplc_' . $group, $titles[ $group ] ?? $group, '__return_false', self::PAGE );
			foreach ( $keys as $key ) {
				$own = 'render_' . $key . '_field';
				add_settings_field(
					'wplc_' . $key,
					Texts::label( $key ),
					method_exists( self::class, $own ) ? array( self::class, $own ) : array( self::class, 'render_field' ),
					self::PAGE,
					'wplc_' . $group,
					array(
						'label_for' => 'wplc_' . $key,
						'key'       => $key,
					)
				);
			}
		}
	}

	/**
	 * Sanitize the submitted settings.
	 *
	 * The result is the stored option with the submitted keys applied: a key that
	 * is missing from the input keeps its stored value, so a partial write or an
	 * old form cannot wipe other settings (B-134). The trusted package hosts are
	 * only changed from the form when the user may change them and the form was
	 * rendered with the list that is stored now; an old browser tab can therefore
	 * not bring back a host that was removed in the meantime. Every other key is
	 * checked with the rules of the register; a value that cannot be accepted is
	 * reported on the screen and the stored value stays.
	 *
	 * @param mixed $input Raw option value.
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ): array {
		$input   = is_array( $input ) ? $input : array();
		$current = Registry::all();
		$out     = array();

		foreach ( Registry::definitions() as $key => $definition ) {
			$key = (string) $key;
			if ( 'package_hosts' === $key ) {
				$out[ $key ] = self::sanitize_package_hosts( $input, $current[ $key ] );
				continue;
			}

			// What is stored now, brought into the valid form (an old or hand-edited value never survives a save).
			$kept        = Registry::validate( $key, $current[ $key ] );
			$out[ $key ] = $kept['ok'] ? $kept['value'] : $definition['default'];
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			if ( self::is_form_submission() && ! Capabilities::can( (string) $definition['capability'] ) ) {
				continue;
			}
			$result = Registry::validate( $key, $input[ $key ] );
			if ( $result['ok'] ) {
				$out[ $key ] = $result['value'];
				continue;
			}
			// SET-LIC-01: a value that cannot be accepted is reported and the stored value stays.
			self::settings_error( 'wplc_invalid_' . $key, Texts::problem( $key, $result['code'] ) );
		}

		foreach ( self::combination_warnings( $out ) as $code => $message ) {
			self::settings_error( $code, $message, 'warning' );
		}

		return $out;
	}

	/**
	 * Warnings about values that are each valid but do not fit together. The values are saved all the same.
	 *
	 * @param array<string,mixed> $values Settings in stored form.
	 * @return array<string,string> Code => translated message.
	 */
	public static function combination_warnings( array $values ): array {
		$warnings = array();
		$retries  = array_filter( array_map( 'intval', explode( ',', (string) ( $values['renewal_retry_days'] ?? '' ) ) ) );
		$grace    = (int) ( $values['renewal_grace_days'] ?? 0 );
		if ( $retries && $grace > 0 && $grace <= max( $retries ) ) {
			$warnings['wplc_grace_before_last_retry'] = sprintf(
				/* translators: 1: grace period in days, 2: last retry day. */
				__( 'The grace period (%1$s days) is not longer than the last retry day (%2$s). The last charge attempts would come after the customer has lost access. Make the grace period longer or remove the late retry days.', 'wp-license' ),
				number_format_i18n( $grace ),
				number_format_i18n( max( $retries ) )
			);
		}

		return $warnings;
	}

	/**
	 * The trusted package hosts after a save.
	 *
	 * @param array<string,mixed> $input   Submitted values.
	 * @param mixed               $current Stored list.
	 * @return string[]
	 */
	private static function sanitize_package_hosts( array $input, $current ): array {
		$current_hosts = is_array( $current ) ? array_values( array_filter( $current, 'is_string' ) ) : array();
		$hosts_out     = $current_hosts;
		if ( array_key_exists( 'package_hosts', $input ) ) {
			$raw_hosts = $input['package_hosts'];
			if ( is_array( $raw_hosts ) ) {
				// Already sanitized (WordPress may run the callback twice when the option is first added).
				$raw_hosts = implode( "\n", array_filter( $raw_hosts, 'is_string' ) );
			}
			$hosts = self::parse_hosts( is_string( $raw_hosts ) ? $raw_hosts : '' );
			if ( $hosts['invalid'] ) {
				self::settings_error(
					'wplc_invalid_hosts',
					sprintf(
						/* translators: %d: number of rejected lines. */
						_n(
							'%d package host line was not a valid host name and was ignored. Enter host names only, without https:// or a path.',
							'%d package host lines were not valid host names and were ignored. Enter host names only, without https:// or a path.',
							count( $hosts['invalid'] ),
							'wp-license'
						),
						count( $hosts['invalid'] )
					)
				);
			}

			if ( is_array( $input['package_hosts'] ) && null !== self::$accepted_hosts && $hosts['valid'] === self::$accepted_hosts ) {
				// Second pass of the same save: WordPress sanitizes again when it adds the option
				// for the first time, now with our own result and without the form's fingerprint.
				// Only the list this request already accepted passes; a posted array does not.
				$hosts_out = self::$accepted_hosts;
			} elseif ( $hosts['valid'] !== $current_hosts ) {
				$seen = isset( $input['package_hosts_seen'] ) && is_string( $input['package_hosts_seen'] ) ? $input['package_hosts_seen'] : '';
				if ( ! self::is_form_submission() ) {
					// WP-CLI, tests and other code: no browser tab can be stale.
					$hosts_out = $hosts['valid'];
				} elseif ( ! self::can_manage_package_hosts() ) {
					self::settings_error( 'wplc_hosts_forbidden', __( 'The trusted package hosts were not changed: only an administrator may change them.', 'wp-license' ) );
				} elseif ( ! hash_equals( self::hosts_fingerprint( $current_hosts ), $seen ) ) {
					self::settings_error( 'wplc_hosts_stale', __( 'The trusted package hosts were not changed: someone else changed them after you opened this page. Check the list below and save again.', 'wp-license' ) );
				} else {
					$hosts_out = $hosts['valid'];
				}
			}
		}

		self::$accepted_hosts = $hosts_out;

		return $hosts_out;
	}

	/**
	 * Split a textarea into valid and rejected host names.
	 *
	 * @param string $raw One host per line.
	 * @return array{valid:string[],invalid:string[]}
	 */
	public static function parse_hosts( string $raw ): array {
		return Registry::parse_hosts( $raw );
	}

	/**
	 * Validate a DNS host name (no scheme, port, path, wildcard or IP address).
	 *
	 * @param string $host Lowercase candidate.
	 * @return bool
	 */
	public static function is_valid_hostname( string $host ): bool {
		return Registry::is_valid_hostname( $host );
	}

	/**
	 * Normalize a comma separated list of reminder lead days.
	 *
	 * @param string $raw For example "30, 7".
	 * @return string Unique values between 1 and 365, longest first; the default when nothing valid remains.
	 */
	public static function sanitize_reminder_days( string $raw ): string {
		$result = Registry::validate( 'reminder_days', $raw );

		return $result['ok'] ? (string) $result['value'] : (string) Registry::defaults()['reminder_days'];
	}

	/**
	 * Add the configured hosts to the update package allow-list.
	 *
	 * @param mixed $hosts Hosts from earlier filters.
	 * @return array<int,mixed>
	 */
	public static function filter_package_hosts( $hosts ) {
		$hosts      = is_array( $hosts ) ? $hosts : array();
		$configured = self::get( 'package_hosts' );
		if ( ! is_array( $configured ) ) {
			return $hosts;
		}
		foreach ( $configured as $host ) {
			if ( is_string( $host ) && self::is_valid_hostname( strtolower( $host ) ) && ! in_array( strtolower( $host ), $hosts, true ) ) {
				$hosts[] = strtolower( $host );
			}
		}
		return $hosts;
	}

	/**
	 * Load the copy-button script on the settings page.
	 *
	 * @return void
	 */
	public static function enqueue_assets(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Screen detection only.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::PAGE !== $page ) {
			return;
		}
		// B-124: the version follows the file, so an update is not hidden by browser or CDN caches.
		wp_enqueue_style( 'wplc-admin', plugins_url( 'assets/css/wplc-admin.css', WPLC_FILE ), array(), \WPLC\Account\CustomerPortal::asset_version( 'assets/css/wplc-admin.css' ) );
		wp_enqueue_script( 'wplc-ui', plugins_url( 'assets/js/wplc-ui.js', WPLC_FILE ), array(), \WPLC\Account\CustomerPortal::asset_version( 'assets/js/wplc-ui.js' ), true );
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to change license settings.', 'wp-license' ), '', array( 'response' => 403 ) );
		}

		echo '<div class="wrap wplc-settings"><h1>' . esc_html__( 'License settings', 'wp-license' ) . '</h1>';
		settings_errors( self::OPTION );
		self::render_setup_steps();

		echo '<h2>' . esc_html__( 'Status', 'wp-license' ) . '</h2>';
		echo '<table class="widefat striped" role="presentation"><tbody>';
		foreach ( self::status_rows() as $row ) {
			echo '<tr><th scope="row" style="width:260px">' . esc_html( $row['label'] ) . '</th><td>';
			if ( isset( $row['copy'] ) ) {
				echo '<label class="screen-reader-text" for="wplc-webhook-url">' . esc_html( $row['label'] ) . '</label>';
				echo '<input type="text" readonly class="regular-text code" id="wplc-webhook-url" value="' . esc_attr( $row['copy'] ) . '"> ';
				echo '<button type="button" class="button" data-wplc-copy="wplc-webhook-url" data-wplc-copied="' . esc_attr__( 'Copied', 'wp-license' ) . '" data-wplc-copy-failed="' . esc_attr__( 'Copying failed. Select the address and copy it by hand.', 'wp-license' ) . '">' . esc_html__( 'Copy', 'wp-license' ) . '</button>';
				echo ' <span role="status" aria-live="polite" data-wplc-copy-status="wplc-webhook-url"></span>';
			} else {
				$state = $row['state'] ?? '';
				if ( 'ok' === $state ) {
					echo '<span class="dashicons dashicons-yes-alt" style="color:#00a32a" aria-hidden="true"></span> ';
				} elseif ( 'warning' === $state ) {
					echo '<span class="dashicons dashicons-warning" style="color:#d63638" aria-hidden="true"></span> ';
				}
				echo esc_html( $row['value'] );
			}
			if ( ! empty( $row['help'] ) ) {
				echo '<p class="description">' . esc_html( $row['help'] ) . '</p>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Secrets are never stored in the database. Define them as constants in wp-config.php.', 'wp-license' ) . '</p>';

		$tab = self::current_tab();
		self::render_tabs( $tab );
		echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '">';
		settings_fields( self::OPTION );
		self::render_sections( $tab );
		submit_button();
		echo '</form>';
		/**
		 * Fires below the settings form: tools that work on the settings (country profiles, export, import, reset).
		 *
		 * @since 0.2.0
		 *
		 * @param string $tab Current tab: a group key, or '' for all settings.
		 */
		do_action( 'wplc_settings_page_tools', $tab );
		echo '</div>';
	}

	/**
	 * Keys of the setup steps, in the order a new vendor needs them (A-121, PROD-09).
	 */
	public const SETUP_STEPS = array( 'secrets', 'public_key', 'catalog', 'product', 'release', 'test', 'webhook' );

	/**
	 * What the site already has, one flag per setup step (PROD-09). Reads only; creates no key.
	 *
	 * - secrets: license key secrets and installation encryption keys come from wp-config.php.
	 * - public_key: the response signing key comes from wp-config.php (WPLC_RESPONSE_SIGNING_KEY) and is usable, so
	 *   the public key the add-on pins cannot change with a restored or copied database.
	 * - catalog: at least one catalog file was imported (by upload, WP-CLI or a release package); the copies shipped
	 *   in catalogs/ do not count.
	 * - product: at least one active plan. release: at least one published release. test: at least one license.
	 * - webhook: a Stripe webhook signing secret is configured.
	 *
	 * @return array<string,bool> Step key => done.
	 */
	public static function setup_state(): array {
		global $wpdb;

		$tables  = Schema::table_names();
		$catalog = false;
		foreach ( \WPLC\Catalog\CatalogStore::all() as $product ) {
			if ( \WPLC\Catalog\CatalogStore::SOURCE_BUNDLED !== (string) ( $product['source'] ?? '' ) ) {
				$catalog = true;
				break;
			}
		}

		return array(
			'secrets'    => ! self::uses_fallback( '\\WPLC\\License\\LicenseKey', 'uses_fallback_secret', array( 'WPLC_LICENSE_KEY_SECRETS', 'WPLC_LICENSE_KEY_SECRET' ) )
				&& ! self::uses_fallback( '\\WPLC\\Security\\SecretCrypto', 'uses_fallback_key', array( 'WPLC_INSTALLATION_ENCRYPTION_KEYS', 'WPLC_INSTALLATION_ENCRYPTION_KEY' ) ),
			// Only the constant is read: status() of a stored key would create one on an empty installation.
			'public_key' => \WPLC\Security\ResponseSigner::uses_constant() && 'ok' === \WPLC\Security\ResponseSigner::status()['state'],
			'catalog'    => $catalog,
			'product'    => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE active = 1 LIMIT 1', $tables['plans'] ) ) > 0,
			'release'    => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE status = %s LIMIT 1', $tables['releases'], 'published' ) ) > 0,
			'test'       => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i LIMIT 1', $tables['licenses'] ) ) > 0,
			'webhook'    => \WPLC\Api\V1\StripeWebhook::is_configured(),
		);
	}

	/**
	 * Steps from activation to the first sold license, with the state of this site (A-121, PROD-09).
	 *
	 * @return array<int,array{key:string,title:string,text:string,done:bool,link:string,link_text:string}>
	 */
	public static function setup_steps(): array {
		return self::setup_steps_for( self::setup_state() );
	}

	/**
	 * The setup checklist for a given state. Pure apart from translation; `link` is relative to wp-admin/.
	 *
	 * @param array<string,bool> $state Step key => done (see setup_state()); a missing key counts as not done.
	 * @return array<int,array{key:string,title:string,text:string,done:bool,link:string,link_text:string}>
	 */
	public static function setup_steps_for( array $state ): array {
		$diagnostics = 'admin.php?page=' . Diagnostics::PAGE;
		$steps       = array(
			'secrets'    => array(
				'title'     => __( 'Define the secrets in wp-config.php', 'wp-license' ),
				'text'      => __( 'Set WPLC_LICENSE_KEY_SECRETS (with WPLC_LICENSE_KEY_ID) and WPLC_INSTALLATION_ENCRYPTION_KEYS before the first license is sold. Keys issued without them depend on the WordPress salts and stop working when the salts change.', 'wp-license' ),
				'link'      => $diagnostics,
				'link_text' => __( 'Open diagnostics', 'wp-license' ),
			),
			'public_key' => array(
				'title'     => __( 'Pin the public key in your add-on', 'wp-license' ),
				'text'      => __( 'Put the response signing key into wp-config.php as WPLC_RESPONSE_SIGNING_KEY ("wp wplc signing-key --generate") and give its public key to the license client of your add-on (option public_keys).', 'wp-license' ),
				'link'      => $diagnostics,
				'link_text' => __( 'Show the public key', 'wp-license' ),
			),
			'catalog'    => array(
				'title'     => __( 'Import your catalog file', 'wp-license' ),
				'text'      => __( 'Describe your product and its entitlements in a wplc-catalog.json file (docs/manual/03-catalog-files.md) and import it here, or ship it in the plugin folder of your release package.', 'wp-license' ),
				'link'      => 'admin.php?page=' . CatalogFileAdmin::PAGE,
				'link_text' => __( 'Open catalog files', 'wp-license' ),
			),
			'product'    => array(
				'title'     => __( 'Turn a product into a license product', 'wp-license' ),
				'text'      => __( 'Edit the WooCommerce product, tick "License product", enter the product key of your catalog file (for example acme-pro) and a plan key (for example single), and tick the entitlements of the plan. Keep both keys unchanged once the product is sold.', 'wp-license' ),
				'link'      => 'edit.php?post_type=product',
				'link_text' => __( 'Open products', 'wp-license' ),
			),
			'release'    => array(
				'title'     => __( 'Publish the first release', 'wp-license' ),
				'text'      => __( 'Upload the add-on package and publish it, so that licensed sites can download it and receive updates.', 'wp-license' ),
				'link'      => 'admin.php?page=' . ReleaseAdmin::PAGE,
				'link_text' => __( 'Open license releases', 'wp-license' ),
			),
			'test'       => array(
				'title'     => __( 'Make a test purchase', 'wp-license' ),
				'text'      => __( 'Buy the product with a test customer, check the license under Licenses and activate it in the add-on on a test site. The diagnostics page shows whether the daily background run works.', 'wp-license' ),
				'link'      => 'admin.php?page=' . LicenseAdmin::PAGE,
				'link_text' => __( 'Open licenses', 'wp-license' ),
			),
			'webhook'    => array(
				'title'     => __( 'Connect the Stripe webhook', 'wp-license' ),
				'text'      => __( 'In the Stripe dashboard, add an endpoint with the webhook URL shown under "Status" below and the events listed in the documentation (docs/manual/08-renewals-and-stripe.md), then put its signing secret into WPLC_STRIPE_WEBHOOK_SECRET. Without it, renewals, refunds and disputes reported by Stripe are rejected.', 'wp-license' ),
				'link'      => '',
				'link_text' => '',
			),
		);

		$list = array();
		foreach ( self::SETUP_STEPS as $key ) {
			$list[] = array( 'key' => $key ) + $steps[ $key ] + array( 'done' => ! empty( $state[ $key ] ) );
		}

		return $list;
	}

	/**
	 * "Getting started" checklist; folded away once every step is done (A-121, PROD-09).
	 *
	 * @return void
	 */
	private static function render_setup_steps(): void {
		$steps = self::setup_steps();
		$done  = count( array_filter( array_column( $steps, 'done' ) ) );
		$open  = $done < count( $steps );

		echo '<details class="wplc-setup" data-wplc-setup-done="' . esc_attr( (string) $done ) . '"' . ( $open ? ' open' : '' ) . '><summary><h2 style="display:inline">' . esc_html__( 'Getting started', 'wp-license' ) . '</h2> ';
		/* translators: 1: number of finished setup steps, 2: number of all setup steps. */
		echo '<span>' . esc_html( sprintf( __( '%1$s of %2$s steps done', 'wp-license' ), number_format_i18n( $done ), number_format_i18n( count( $steps ) ) ) ) . '</span></summary>';
		echo '<ol>';
		foreach ( $steps as $step ) {
			echo '<li data-wplc-step="' . esc_attr( $step['key'] ) . '" data-wplc-step-done="' . ( $step['done'] ? '1' : '0' ) . '"><strong>' . esc_html( $step['title'] ) . '</strong> ';
			if ( $step['done'] ) {
				echo '<span class="dashicons dashicons-yes-alt" style="color:#00a32a" aria-hidden="true"></span> <span>' . esc_html__( 'Done', 'wp-license' ) . '</span>';
			} else {
				echo '<span>' . esc_html__( 'To do', 'wp-license' ) . '</span>';
			}
			echo '<br><span class="description">' . esc_html( $step['text'] ) . '</span>';
			if ( '' !== $step['link'] ) {
				echo ' <a href="' . esc_url( admin_url( $step['link'] ) ) . '">' . esc_html( $step['link_text'] ) . '</a>';
			}
			echo '</li>';
		}
		echo '</ol></details>';
	}

	/**
	 * Build the read-only status rows.
	 *
	 * @return array<int,array<string,string>>
	 */
	private static function status_rows(): array {
		$rows = array();

		$schema = (string) get_option( 'wplc_schema_version', '0' );
		$rows[] = array(
			'label' => __( 'Database schema version', 'wp-license' ),
			/* translators: 1: installed schema version, 2: schema version expected by the code. */
			'value' => sprintf( __( '%1$s (expected: %2$s)', 'wp-license' ), $schema, Schema::VERSION ),
			'state' => version_compare( $schema, Schema::VERSION, '>=' ) ? 'ok' : 'warning',
		);

		// Live and test secrets, per site on a network: the webhook class knows all sources.
		$webhook_secret = \WPLC\Api\V1\StripeWebhook::is_configured();
		$rows[]         = array(
			'label' => __( 'Stripe webhook secret', 'wp-license' ),
			'value' => $webhook_secret ? __( 'A webhook signing secret is configured in wp-config.php.', 'wp-license' ) : __( 'No webhook signing secret is configured (WPLC_STRIPE_WEBHOOK_SECRET in wp-config.php). Stripe events are rejected.', 'wp-license' ),
			'state' => $webhook_secret ? 'ok' : 'warning',
		);
		$rows[]         = array(
			'label' => __( 'Stripe webhook URL', 'wp-license' ),
			'copy'  => rest_url( 'wplc/v1/webhooks/stripe' ),
			'help'  => __( 'Enter this URL as the endpoint in the Stripe dashboard.', 'wp-license' ),
		);

		$license_fallback = self::uses_fallback(
			'\\WPLC\\License\\LicenseKey',
			'uses_fallback_secret',
			array( 'WPLC_LICENSE_KEY_SECRETS', 'WPLC_LICENSE_KEY_SECRET' )
		);
		$rows[]           = array(
			'label' => __( 'License key secret', 'wp-license' ),
			'value' => $license_fallback
				? __( 'Not configured. Keys are derived from the WordPress salts; rotating the salts invalidates every issued key. Define WPLC_LICENSE_KEY_SECRETS.', 'wp-license' )
				: __( 'Configured through a constant.', 'wp-license' ),
			'state' => $license_fallback ? 'warning' : 'ok',
		);

		$crypto_fallback = self::uses_fallback(
			'\\WPLC\\Security\\SecretCrypto',
			'uses_fallback_key',
			array( 'WPLC_INSTALLATION_ENCRYPTION_KEYS', 'WPLC_INSTALLATION_ENCRYPTION_KEY' )
		);
		$rows[]          = array(
			'label' => __( 'Installation secret encryption key', 'wp-license' ),
			'value' => $crypto_fallback
				? __( 'Not configured. The WordPress salts are used; define WPLC_INSTALLATION_ENCRYPTION_KEYS.', 'wp-license' )
				: __( 'Configured through a constant.', 'wp-license' ),
			'state' => $crypto_fallback ? 'warning' : 'ok',
		);

		$sample = Diagnostics::key_sample();
		if ( $sample['checked'] > 0 ) {
			$rows[] = array(
				'label' => __( 'License keys verifiable with the current secret', 'wp-license' ),
				/* translators: 1: number of verifiable keys, 2: number of checked keys. */
				'value' => sprintf( _n( '%1$s of the %2$s most recent key', '%1$s of the %2$s most recent keys', $sample['checked'], 'wp-license' ), number_format_i18n( $sample['verified'] ), number_format_i18n( $sample['checked'] ) ),
				'state' => $sample['verified'] === $sample['checked'] ? 'ok' : 'warning',
				'help'  => $sample['verified'] === $sample['checked'] ? '' : __( 'Some customers see "not available" instead of their key. The secret or the salts were changed. Restore the previous secret as an additional entry in WPLC_LICENSE_KEY_SECRETS, or run "wp wplc recover-license-key-ids".', 'wp-license' ),
			);
		}

		// A-52: say why automatic renewal is unavailable.
		$stripe = Requirements::stripe_adapter_status();
		$rows[] = array(
			'label' => __( 'Automatic renewal (Stripe)', 'wp-license' ),
			'value' => $stripe['ok']
				? ( '' !== $stripe['reason'] ? $stripe['reason'] : __( 'Available. Customers can opt in at checkout.', 'wp-license' ) )
				/* translators: %s: reason, a full sentence. */
				: sprintf( __( 'Switched off. %s Customers are not offered automatic renewal and renew manually in their account.', 'wp-license' ), $stripe['reason'] ),
			'state' => $stripe['ok'] ? 'ok' : 'warning',
		);

		$scheduler = function_exists( 'as_schedule_single_action' );
		$rows[]    = array(
			'label' => __( 'Action Scheduler', 'wp-license' ),
			'value' => $scheduler ? __( 'Available', 'wp-license' ) : __( 'Unavailable. Scheduled work falls back to WP-Cron, which is less reliable.', 'wp-license' ),
			'state' => $scheduler ? 'ok' : 'warning',
		);

		$block_checkout   = self::uses_block_checkout();
		$block_supported  = function_exists( 'woocommerce_register_additional_checkout_field' );
		$checkout_message = __( 'Classic checkout', 'wp-license' );
		if ( $block_checkout ) {
			$checkout_message = $block_supported
				? __( 'Block checkout. The option for automatic renewal is offered as an additional checkout field.', 'wp-license' )
				: __( 'The checkout page uses the block checkout, but this WooCommerce version has no additional checkout fields. Licenses bought here never renew automatically.', 'wp-license' );
		}
		$rows[] = array(
			'label' => __( 'Checkout type', 'wp-license' ),
			'value' => $checkout_message,
			'state' => $block_checkout && ! $block_supported ? 'warning' : 'ok',
		);

		$rows[] = array(
			'label' => __( 'Renewal grace period', 'wp-license' ),
			/* translators: %d: number of days. */
			'value' => sprintf( _n( '%d day', '%d days', LicenseLifecycle::grace_days(), 'wp-license' ), LicenseLifecycle::grace_days() ),
		);

		// B-45: ended licenses are detached from the customer ten years after the end of the year (filter wplc_retention_days).
		$anonymize = \WPLC\Maintenance\Retention::configured_days()['anonymize_licenses'];
		$rows[]    = array(
			'label' => __( 'Anonymisation of ended licenses', 'wp-license' ),
			'value' => $anonymize > 0
				/* translators: %s: number of days. */
				? sprintf( _n( '%s day after the end of the year in which the license ended', '%s days after the end of the year in which the license ended', $anonymize, 'wp-license' ), number_format_i18n( $anonymize ) )
				: __( 'Off. Ended licenses stay linked to the customer account until an erasure request or the deletion of the account. Set a period that matches your privacy notice with the filter wplc_retention_days (key anonymize_licenses).', 'wp-license' ),
			'state' => $anonymize > 0 ? 'ok' : '',
		);

		return $rows;
	}

	/**
	 * Whether a secret falls back to the WordPress salts.
	 *
	 * @param string   $class     Class that may report its own state.
	 * @param string   $method    Static reporting method (provided by another work package).
	 * @param string[] $constants Constants that configure the secret.
	 * @return bool
	 */
	private static function uses_fallback( string $class, string $method, array $constants ): bool {
		if ( class_exists( $class ) && method_exists( $class, $method ) ) {
			return (bool) call_user_func( array( $class, $method ) );
		}
		foreach ( $constants as $constant ) {
			if ( defined( $constant ) && ! empty( constant( $constant ) ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether the WooCommerce checkout page contains the checkout block.
	 *
	 * @return bool
	 */
	private static function uses_block_checkout(): bool {
		if ( ! function_exists( 'has_block' ) || ! function_exists( 'wc_get_page_id' ) ) {
			return false;
		}
		$page_id = (int) wc_get_page_id( 'checkout' );

		return $page_id > 0 && has_block( 'woocommerce/checkout', $page_id );
	}

	/**
	 * Render a field from its definition in the register (every setting without a renderer of its own).
	 *
	 * @param mixed $args Arguments of add_settings_field(): `key`.
	 * @return void
	 */
	public static function render_field( $args ): void {
		$key        = is_array( $args ) && isset( $args['key'] ) ? (string) $args['key'] : '';
		$definition = Registry::definition( $key );
		if ( null === $definition ) {
			return;
		}
		$id       = 'wplc_' . $key;
		$name     = self::OPTION . '[' . $key . ']';
		$value    = Registry::get( $key );
		$readonly = ! Capabilities::can( (string) $definition['capability'] );
		// A read-only field has no name: a missing key keeps the stored value.
		$name_attr = $readonly ? '' : ' name="' . esc_attr( $name ) . '"';
		$lock      = $readonly ? ' readonly' : '';
		$help      = ' aria-describedby="' . esc_attr( $id ) . '_help"';

		switch ( $definition['type'] ) {
			case 'bool':
				if ( ! $readonly ) {
					echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="0">';
				}
				echo '<input type="checkbox" id="' . esc_attr( $id ) . '"' . $name_attr . ' value="1"' . checked( ! empty( $value ), true, false ) . ( $readonly ? ' disabled' : '' ) . $help . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
				break;
			case 'int':
				echo '<input type="number" id="' . esc_attr( $id ) . '"' . $name_attr . ' value="' . esc_attr( (string) Registry::int( $key ) ) . '" class="small-text" min="' . esc_attr( (string) (int) ( $definition['min'] ?? 0 ) ) . '" max="' . esc_attr( (string) (int) ( $definition['max'] ?? 0 ) ) . '" step="1"' . $lock . $help . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
				break;
			case 'choice':
				echo '<select id="' . esc_attr( $id ) . '"' . $name_attr . ( $readonly ? ' disabled' : '' ) . $help . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
				$labels = Texts::choices( $key );
				foreach ( (array) ( $definition['choices'] ?? array() ) as $choice ) {
					echo '<option value="' . esc_attr( (string) $choice ) . '"' . selected( (string) $value, (string) $choice, false ) . '>' . esc_html( $labels[ $choice ] ?? (string) $choice ) . '</option>';
				}
				echo '</select>';
				break;
			case 'text':
			case 'string_list':
			case 'hosts':
				$text = is_array( $value ) ? implode( "\n", array_filter( $value, 'is_scalar' ) ) : (string) $value;
				$rows = 'text' === $definition['type'] ? 4 : max( 3, min( 12, substr_count( $text, "\n" ) + 2 ) );
				echo '<textarea id="' . esc_attr( $id ) . '"' . $name_attr . ' rows="' . esc_attr( (string) $rows ) . '" cols="50" class="large-text' . ( 'text' === $definition['type'] ? '' : ' code' ) . '"' . ( isset( $definition['max_length'] ) ? ' maxlength="' . esc_attr( (string) (int) $definition['max_length'] ) . '"' : '' ) . $lock . $help . '>' . esc_textarea( $text ) . '</textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
				break;
			default:
				echo '<input type="text" id="' . esc_attr( $id ) . '"' . $name_attr . ' value="' . esc_attr( is_scalar( $value ) ? (string) $value : '' ) . '" class="regular-text"' . $lock . $help . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
		}

		echo '<p class="description" id="' . esc_attr( $id ) . '_help">' . esc_html( Texts::help( $key ) );
		if ( $definition['legal'] ) {
			echo ' <strong>' . esc_html( Texts::legal_note() ) . '</strong>';
		}
		if ( '' !== $definition['filter'] ) {
			/* translators: %s: name of a PHP filter. */
			echo ' ' . esc_html( sprintf( __( 'Code of the shop can change the value with the filter %s; the filter decides last.', 'wp-license' ), $definition['filter'] ) );
		}
		if ( $readonly ) {
			echo ' ' . esc_html__( 'Only an administrator may change this value.', 'wp-license' );
		}
		echo '</p>';
		self::render_effective_value( $key );
	}

	/**
	 * Say so when code of the shop (a filter) changes the stored value (SET-LIC-32).
	 *
	 * @param string $key Setting key.
	 * @return void
	 */
	private static function render_effective_value( string $key ): void {
		$effective = \WPLC\Settings\Effective::value( $key );
		if ( null === $effective ) {
			return;
		}
		$stored = Registry::display( $key, Registry::get( $key ) );
		$shown  = Registry::display( $key, $effective );
		if ( $stored === $shown ) {
			return;
		}
		/* translators: %s: the value that is in effect. */
		echo '<p class="description wplc-effective"><span class="dashicons dashicons-info" aria-hidden="true"></span> ' . esc_html( sprintf( __( 'In effect: %s (set by code of the shop, not by this field).', 'wp-license' ), '' === $shown ? __( 'empty', 'wp-license' ) : $shown ) ) . '</p>';
	}

	/**
	 * The tab the page shows: a group key, or '' for all groups.
	 *
	 * @return string
	 */
	public static function current_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		return in_array( $tab, Registry::GROUPS, true ) && Registry::keys_of_group( $tab ) ? $tab : '';
	}

	/**
	 * Tabs: all settings, or one group (SET-LIC-32).
	 *
	 * @param string $current Current tab.
	 * @return void
	 */
	private static function render_tabs( string $current ): void {
		$base   = admin_url( 'admin.php?page=' . self::PAGE );
		$titles = Texts::groups();
		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Groups of settings', 'wp-license' ) . '">';
		echo '<a class="nav-tab' . ( '' === $current ? ' nav-tab-active' : '' ) . '" href="' . esc_url( $base ) . '"' . ( '' === $current ? ' aria-current="page"' : '' ) . '>' . esc_html__( 'All settings', 'wp-license' ) . '</a>';
		foreach ( Registry::GROUPS as $group ) {
			if ( ! Registry::keys_of_group( $group ) ) {
				continue;
			}
			echo '<a class="nav-tab' . ( $group === $current ? ' nav-tab-active' : '' ) . '" href="' . esc_url( add_query_arg( 'tab', $group, $base ) ) . '"' . ( $group === $current ? ' aria-current="page"' : '' ) . '>' . esc_html( $titles[ $group ] ?? $group ) . '</a>';
		}
		echo '</nav>';
	}

	/**
	 * Print the sections of the form: all of them, or the one of the current tab.
	 *
	 * A tab posts only its own fields; sanitize() keeps every key that is missing.
	 *
	 * @param string $tab Current tab.
	 * @return void
	 */
	private static function render_sections( string $tab ): void {
		global $wp_settings_sections, $wp_settings_fields;
		if ( '' === $tab || ! isset( $wp_settings_sections[ self::PAGE ]['wplc_' . $tab ] ) ) {
			do_settings_sections( self::PAGE );
			return;
		}
		$section = $wp_settings_sections[ self::PAGE ]['wplc_' . $tab ];
		echo '<h2>' . esc_html( (string) $section['title'] ) . '</h2>';
		if ( isset( $wp_settings_fields[ self::PAGE ]['wplc_' . $tab ] ) ) {
			echo '<table class="form-table" role="presentation">';
			do_settings_fields( self::PAGE, 'wplc_' . $tab );
			echo '</table>';
		}
	}

	/**
	 * Render the package host textarea.
	 *
	 * @return void
	 */
	public static function render_package_hosts_field(): void {
		$hosts = self::get( 'package_hosts' );
		$hosts = is_array( $hosts ) ? array_values( array_filter( $hosts, 'is_string' ) ) : array();
		if ( ! self::can_manage_package_hosts() ) {
			// Not part of the form: the stored list is kept when the field is missing.
			echo '<textarea id="wplc_package_hosts" rows="4" cols="50" class="large-text code" readonly aria-describedby="wplc_package_hosts_help">' . esc_textarea( implode( "\n", $hosts ) ) . '</textarea>';
			echo '<p class="description" id="wplc_package_hosts_help">' . esc_html__( 'Only an administrator may change the trusted package hosts, because update packages for all customer sites are delivered from them.', 'wp-license' ) . '</p>';
			return;
		}
		echo '<input type="hidden" name="' . esc_attr( self::OPTION ) . '[package_hosts_seen]" value="' . esc_attr( self::hosts_fingerprint( $hosts ) ) . '">';
		echo '<textarea id="wplc_package_hosts" name="' . esc_attr( self::OPTION ) . '[package_hosts]" rows="4" cols="50" class="large-text code" aria-describedby="wplc_package_hosts_help">' . esc_textarea( implode( "\n", $hosts ) ) . '</textarea>';
		echo '<p class="description" id="wplc_package_hosts_help">';
		echo esc_html(
			sprintf(
				/* translators: %s: host name of this site. */
				__( 'One host name per line, for example downloads.example.com. Update packages are only delivered from HTTPS URLs on these hosts. This site (%s) is always trusted.', 'wp-license' ),
				(string) wp_parse_url( home_url(), PHP_URL_HOST )
			)
		);
		echo '</p>';
	}

	/**
	 * Render the reminder lead days input.
	 *
	 * @return void
	 */
	public static function render_reminder_days_field(): void {
		echo '<input type="text" id="wplc_reminder_days" name="' . esc_attr( self::OPTION ) . '[reminder_days]" value="' . esc_attr( (string) self::get( 'reminder_days' ) ) . '" class="regular-text" pattern="[0-9 ,]*" aria-describedby="wplc_reminder_days_help">';
		echo '<p class="description" id="wplc_reminder_days_help">' . esc_html__( 'Days before the end of the paid period on which a reminder is sent, separated by commas. Default: 30,7.', 'wp-license' ) . '</p>';
	}

	/**
	 * Render the switch for the catalog import from release packages (PROD-03).
	 *
	 * @return void
	 */
	public static function render_catalog_auto_import_field(): void {
		echo '<input type="hidden" name="' . esc_attr( self::OPTION ) . '[catalog_auto_import]" value="0">';
		echo '<label for="wplc_catalog_auto_import"><input type="checkbox" id="wplc_catalog_auto_import" name="' . esc_attr( self::OPTION ) . '[catalog_auto_import]" value="1"' . checked( (bool) self::get( 'catalog_auto_import' ), true, false ) . ' aria-describedby="wplc_catalog_auto_import_help"> ';
		echo esc_html__( 'Import the catalog file from release packages', 'wp-license' ) . '</label>';
		echo '<p class="description" id="wplc_catalog_auto_import_help">' . esc_html__( 'When a release is created from a ZIP, the file wplc-catalog.json in the plugin folder of the package is checked and imported into the license catalog. The release is created in any case; a file that is invalid or describes another product is not imported and reported. Plans and licenses are never changed.', 'wp-license' ) . '</p>';
	}

	/**
	 * Render the switch for combined automatic renewals (B-191).
	 *
	 * @return void
	 */
	public static function render_combined_renewals_field(): void {
		echo '<input type="hidden" name="' . esc_attr( self::OPTION ) . '[combined_renewals]" value="0">';
		echo '<label for="wplc_combined_renewals"><input type="checkbox" id="wplc_combined_renewals" name="' . esc_attr( self::OPTION ) . '[combined_renewals]" value="1"' . checked( (bool) self::get( 'combined_renewals' ), true, false ) . ' aria-describedby="wplc_combined_renewals_help"> ';
		echo esc_html__( 'Charge the licenses of one customer that renew automatically on the same day with one order and one payment', 'wp-license' ) . '</label>';
		echo '<p class="description" id="wplc_combined_renewals_help">' . esc_html__( 'Only licenses with the same saved card, currency and billing interval are combined. Each license keeps its own renewal; a declined payment is retried per license. Manual renewals are never combined. Off by default.', 'wp-license' ) . '</p>';
	}

	/**
	 * Render the notice period of a renewal price change (INH-05).
	 *
	 * @return void
	 */
	public static function render_price_change_notice_days_field(): void {
		echo '<input type="number" id="wplc_price_change_notice_days" name="' . esc_attr( self::OPTION ) . '[price_change_notice_days]" value="' . esc_attr( (string) (int) self::get( 'price_change_notice_days' ) ) . '" min="' . esc_attr( (string) \WPLC\Billing\PriceChange::MIN_NOTICE_DAYS ) . '" max="' . esc_attr( (string) \WPLC\Billing\PriceChange::MAX_NOTICE_DAYS ) . '" step="1" class="small-text" aria-describedby="wplc_price_change_notice_days_help">';
		echo '<p class="description" id="wplc_price_change_notice_days_help">' . esc_html__( 'Days between the email that announces a higher renewal price for an existing license and the first renewal at that price. Default: 60, at least 30. Have the period and the wording of the email checked by a lawyer.', 'wp-license' ) . '</p>';
	}

	/**
	 * Render the lead time of the automatic renewal notice (B-41).
	 *
	 * @return void
	 */
	public static function render_renewal_notice_days_field(): void {
		echo '<input type="number" id="wplc_renewal_notice_days" name="' . esc_attr( self::OPTION ) . '[renewal_notice_days]" value="' . esc_attr( (string) (int) self::get( 'renewal_notice_days' ) ) . '" class="small-text" min="1" max="60" step="1" aria-describedby="wplc_renewal_notice_days_help">';
		echo '<p class="description" id="wplc_renewal_notice_days_help">' . esc_html__( 'Days before an automatic charge on which the customer is told the date and the amount. Default: 7. A license is only charged after this email was accepted for sending; otherwise the charge is postponed and reported under Diagnostics.', 'wp-license' ) . '</p>';
	}

	/**
	 * Render the largest quantity of a license product in one order line.
	 *
	 * @return void
	 */
	public static function render_max_quantity_per_line_field(): void {
		echo '<input type="number" id="wplc_max_quantity_per_line" name="' . esc_attr( self::OPTION ) . '[max_quantity_per_line]" value="' . esc_attr( (string) (int) self::get( 'max_quantity_per_line' ) ) . '" class="small-text" min="0" max="' . esc_attr( (string) \WPLC\Catalog\QuantityLimit::MAX_SETTING ) . '" step="1" aria-describedby="wplc_max_quantity_per_line_help">';
		echo '<p class="description" id="wplc_max_quantity_per_line_help">' . esc_html__( 'Largest quantity of one license product a customer can put into the cart and order. Every unit becomes a license of its own with its own key. Default: 100. 0 = no limit. An order you create yourself in the admin or through the REST API is not blocked; a larger quantity there is noted on the order and in the audit log.', 'wp-license' ) . '</p>';
	}

	/**
	 * Render the number of days after which a suspended license counts as ended.
	 *
	 * @return void
	 */
	public static function render_suspended_ends_after_days_field(): void {
		echo '<input type="number" id="wplc_suspended_ends_after_days" name="' . esc_attr( self::OPTION ) . '[suspended_ends_after_days]" value="' . esc_attr( (string) (int) self::get( 'suspended_ends_after_days' ) ) . '" class="small-text" min="0" max="' . esc_attr( (string) \WPLC\License\EndedLicenses::MAX_DAYS ) . '" step="1" aria-describedby="wplc_suspended_ends_after_days_help"> ' . esc_html__( 'days', 'wp-license' );
		echo '<p class="description" id="wplc_suspended_ends_after_days_help">' . esc_html__( 'Default: 0 = never; a suspended license then keeps its customer and its data until you revoke it or it is reactivated. With a number of days, a license that stayed suspended without interruption for that long is treated like an expired or revoked one: a personal data erasure request detaches it from the customer and releases its sites, and the retention rules delete its installations and consent records after their periods. This cannot be undone. A license that was reactivated is never affected; a new suspension counts from its own start. Agree the period with your legal counsel.', 'wp-license' ) . '</p>';
	}

	/**
	 * Render the sender note textarea.
	 *
	 * @return void
	 */
	public static function render_sender_note_field(): void {
		echo '<textarea id="wplc_sender_note" name="' . esc_attr( self::OPTION ) . '[sender_note]" rows="3" cols="50" class="large-text" maxlength="500" aria-describedby="wplc_sender_note_help">' . esc_textarea( (string) self::get( 'sender_note' ) ) . '</textarea>';
		echo '<p class="description" id="wplc_sender_note_help">' . esc_html__( 'Optional short text that license emails may show below their content, for example a support contact.', 'wp-license' ) . '</p>';
	}
}
