<?php
/**
 * Plugin settings page and option access.
 *
 * @package WPLC
 */

namespace WPLC\Admin;

use WPLC\Infrastructure\Database\Schema;
use WPLC\License\LicenseLifecycle;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce → License settings. Secrets stay in wp-config.php constants.
 */
final class Settings {

	/**
	 * Option name and settings group.
	 */
	public const OPTION = 'wplc_settings';

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
	}

	/**
	 * Default values of all stored settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'package_hosts' => array(),
			'reminder_days' => '30,7',
			'sender_note'   => '',
		);
	}

	/**
	 * Read one setting.
	 *
	 * @param string $key     Setting key: package_hosts (string[]), reminder_days (string), sender_note (string).
	 * @param mixed  $default Value when the setting is not stored; null uses the built-in default.
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		$stored = get_option( self::OPTION, array() );
		if ( is_array( $stored ) && array_key_exists( $key, $stored ) ) {
			return $stored[ $key ];
		}
		if ( null !== $default ) {
			return $default;
		}
		$defaults = self::defaults();
		return array_key_exists( $key, $defaults ) ? $defaults[ $key ] : null;
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
		return 'manage_woocommerce';
	}

	/**
	 * Add the WooCommerce submenu page.
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'License settings', 'wp-license' ),
			__( 'License settings', 'wp-license' ),
			self::capability(),
			self::PAGE,
			array( self::class, 'render' )
		);
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

		add_settings_section( 'wplc_updates', __( 'Updates', 'wp-license' ), '__return_false', self::PAGE );
		add_settings_field(
			'wplc_package_hosts',
			__( 'Additional trusted package hosts', 'wp-license' ),
			array( self::class, 'render_package_hosts_field' ),
			self::PAGE,
			'wplc_updates',
			array( 'label_for' => 'wplc_package_hosts' )
		);

		add_settings_section( 'wplc_emails', __( 'Customer emails', 'wp-license' ), '__return_false', self::PAGE );
		add_settings_field(
			'wplc_reminder_days',
			__( 'Reminder lead days', 'wp-license' ),
			array( self::class, 'render_reminder_days_field' ),
			self::PAGE,
			'wplc_emails',
			array( 'label_for' => 'wplc_reminder_days' )
		);
		add_settings_field(
			'wplc_sender_note',
			__( 'Sender note', 'wp-license' ),
			array( self::class, 'render_sender_note_field' ),
			self::PAGE,
			'wplc_emails',
			array( 'label_for' => 'wplc_sender_note' )
		);
	}

	/**
	 * Sanitize the submitted settings.
	 *
	 * @param mixed $input Raw option value.
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();

		$raw_hosts = $input['package_hosts'] ?? '';
		if ( is_array( $raw_hosts ) ) {
			// Already sanitized (WordPress may run the callback twice when the option is first added).
			$raw_hosts = implode( "\n", array_filter( $raw_hosts, 'is_string' ) );
		}
		$hosts = self::parse_hosts( is_string( $raw_hosts ) ? $raw_hosts : '' );
		if ( $hosts['invalid'] && function_exists( 'add_settings_error' ) ) {
			add_settings_error(
				self::OPTION,
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
				),
				'error'
			);
		}

		$raw_days = isset( $input['reminder_days'] ) && is_string( $input['reminder_days'] ) ? $input['reminder_days'] : '';
		$raw_note = isset( $input['sender_note'] ) && is_string( $input['sender_note'] ) ? $input['sender_note'] : '';
		$note     = function_exists( 'sanitize_textarea_field' ) ? sanitize_textarea_field( $raw_note ) : trim( strip_tags( $raw_note ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags

		return array(
			'package_hosts' => $hosts['valid'],
			'reminder_days' => self::sanitize_reminder_days( $raw_days ),
			'sender_note'   => function_exists( 'mb_substr' ) ? mb_substr( $note, 0, 500 ) : substr( $note, 0, 500 ),
		);
	}

	/**
	 * Split a textarea into valid and rejected host names.
	 *
	 * @param string $raw One host per line.
	 * @return array{valid:string[],invalid:string[]}
	 */
	public static function parse_hosts( string $raw ): array {
		$valid   = array();
		$invalid = array();
		$lines   = preg_split( '/[\r\n,]+/', $raw );
		foreach ( is_array( $lines ) ? $lines : array() as $line ) {
			$line = strtolower( trim( $line ) );
			if ( '' === $line ) {
				continue;
			}
			if ( self::is_valid_hostname( $line ) ) {
				$valid[ $line ] = $line;
			} else {
				$invalid[] = $line;
			}
		}

		return array(
			'valid'   => array_slice( array_values( $valid ), 0, 50 ),
			'invalid' => $invalid,
		);
	}

	/**
	 * Validate a DNS host name (no scheme, port, path, wildcard or IP address).
	 *
	 * @param string $host Lowercase candidate.
	 * @return bool
	 */
	public static function is_valid_hostname( string $host ): bool {
		if ( strlen( $host ) > 253 || false === strpos( $host, '.' ) ) {
			return false;
		}
		if ( ! preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $host ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Normalize a comma separated list of reminder lead days.
	 *
	 * @param string $raw For example "30, 7".
	 * @return string Unique values between 1 and 365, longest first; the default when nothing valid remains.
	 */
	public static function sanitize_reminder_days( string $raw ): string {
		$days  = array();
		$parts = preg_split( '/[\s,;]+/', $raw );
		foreach ( is_array( $parts ) ? $parts : array() as $part ) {
			if ( '' !== $part && ctype_digit( $part ) && (int) $part >= 1 && (int) $part <= 365 ) {
				$days[ (int) $part ] = (int) $part;
			}
		}
		if ( ! $days ) {
			return '30,7';
		}
		rsort( $days );

		return implode( ',', array_slice( $days, 0, 6 ) );
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
		wp_enqueue_script( 'wplc-ui', plugins_url( 'assets/js/wplc-ui.js', WPLC_FILE ), array(), WPLC_VERSION, true );
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

		echo '<div class="wrap"><h1>' . esc_html__( 'License settings', 'wp-license' ) . '</h1>';
		settings_errors( self::OPTION );

		echo '<h2>' . esc_html__( 'Status', 'wp-license' ) . '</h2>';
		echo '<table class="widefat striped" role="presentation"><tbody>';
		foreach ( self::status_rows() as $row ) {
			echo '<tr><th scope="row" style="width:260px">' . esc_html( $row['label'] ) . '</th><td>';
			if ( isset( $row['copy'] ) ) {
				echo '<label class="screen-reader-text" for="wplc-webhook-url">' . esc_html( $row['label'] ) . '</label>';
				echo '<input type="text" readonly class="regular-text code" id="wplc-webhook-url" value="' . esc_attr( $row['copy'] ) . '"> ';
				echo '<button type="button" class="button" data-wplc-copy="wplc-webhook-url" data-wplc-copied="' . esc_attr__( 'Copied', 'wp-license' ) . '">' . esc_html__( 'Copy', 'wp-license' ) . '</button>';
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

		echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '">';
		settings_fields( self::OPTION );
		do_settings_sections( self::PAGE );
		submit_button();
		echo '</form></div>';
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

		$webhook_secret = defined( 'WPLC_STRIPE_WEBHOOK_SECRET' ) && is_string( WPLC_STRIPE_WEBHOOK_SECRET ) && '' !== WPLC_STRIPE_WEBHOOK_SECRET;
		$rows[]         = array(
			'label' => __( 'Stripe webhook secret', 'wp-license' ),
			'value' => $webhook_secret ? __( 'Constant WPLC_STRIPE_WEBHOOK_SECRET is set.', 'wp-license' ) : __( 'Constant WPLC_STRIPE_WEBHOOK_SECRET is not set. Stripe events are rejected.', 'wp-license' ),
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

		$scheduler = function_exists( 'as_schedule_single_action' );
		$rows[]    = array(
			'label' => __( 'Action Scheduler', 'wp-license' ),
			'value' => $scheduler ? __( 'Available', 'wp-license' ) : __( 'Unavailable. Renewals cannot be scheduled.', 'wp-license' ),
			'state' => $scheduler ? 'ok' : 'warning',
		);

		$block_checkout = self::uses_block_checkout();
		$rows[]         = array(
			'label' => __( 'Checkout type', 'wp-license' ),
			'value' => $block_checkout
				? __( 'The checkout page uses the block checkout. The consent to automatic renewal only works with the classic checkout; licenses bought here never renew automatically.', 'wp-license' )
				: __( 'Classic checkout', 'wp-license' ),
			'state' => $block_checkout ? 'warning' : 'ok',
		);

		$rows[] = array(
			'label' => __( 'Renewal grace period', 'wp-license' ),
			/* translators: %d: number of days. */
			'value' => sprintf( _n( '%d day', '%d days', LicenseLifecycle::GRACE_DAYS, 'wp-license' ), LicenseLifecycle::GRACE_DAYS ),
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
	 * Render the package host textarea.
	 *
	 * @return void
	 */
	public static function render_package_hosts_field(): void {
		$hosts = self::get( 'package_hosts' );
		$hosts = is_array( $hosts ) ? array_filter( $hosts, 'is_string' ) : array();
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
	 * Render the sender note textarea.
	 *
	 * @return void
	 */
	public static function render_sender_note_field(): void {
		echo '<textarea id="wplc_sender_note" name="' . esc_attr( self::OPTION ) . '[sender_note]" rows="3" cols="50" class="large-text" maxlength="500" aria-describedby="wplc_sender_note_help">' . esc_textarea( (string) self::get( 'sender_note' ) ) . '</textarea>';
		echo '<p class="description" id="wplc_sender_note_help">' . esc_html__( 'Optional short text that license emails may show below their content, for example a support contact.', 'wp-license' ) . '</p>';
	}
}
