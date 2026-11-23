<?php
/**
 * Health alerts by email.
 *
 * @package WPLC
 */

namespace WPLC\Admin;

use WPLC\Settings\Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends the alerts of the diagnostics page (action `wplc/health_alert`) to the addresses
 * of the setting "Send health alerts to" (SET-LIC-22, T-91). Without recipients nothing
 * is sent. All alerts of one request go into one email, and at most one email leaves per
 * day (UTC); the diagnostics page always shows the current state.
 */
final class HealthAlertMail {

	/**
	 * Option with the UTC date of the last email.
	 */
	public const SENT_OPTION = 'wplc_health_alert_mail_date';

	/**
	 * Alerts collected in this request.
	 *
	 * @var array<string,array{value:int,threshold:int,url:string}>
	 */
	private static $alerts = array();

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'wplc/health_alert', array( self::class, 'collect' ), 10, 4 );
		add_action( 'shutdown', array( self::class, 'send_collected' ) );
	}

	/**
	 * The recipients: the setting, then the filter.
	 *
	 * @return string[]
	 */
	public static function recipients(): array {
		$setting = array_filter( array_map( 'trim', explode( ',', Registry::string( 'health_alert_recipients' ) ) ) );
		/**
		 * Filters the recipients of health alert emails. Wins over the setting.
		 *
		 * @since 0.2.0
		 *
		 * @param string[] $recipients Email addresses of the setting "Send health alerts to"; empty = no email.
		 */
		$filtered = (array) apply_filters( 'wplc_health_alert_recipients', array_values( $setting ) );

		return array_values( array_unique( array_filter( array_map( 'strval', $filtered ), 'is_email' ) ) );
	}

	/**
	 * Remember an alert of this request.
	 *
	 * @param mixed $metric    Metric key.
	 * @param mixed $value     Current value.
	 * @param mixed $threshold Threshold.
	 * @param mixed $url       Admin screen that handles the cause.
	 * @return void
	 */
	public static function collect( $metric, $value = 0, $threshold = 0, $url = '' ): void {
		self::$alerts[ sanitize_key( (string) $metric ) ] = array(
			'value'     => (int) $value,
			'threshold' => (int) $threshold,
			'url'       => is_string( $url ) ? $url : '',
		);
	}

	/**
	 * At the end of the request: send what was collected.
	 *
	 * @return void
	 */
	public static function send_collected(): void {
		try {
			self::send();
		} catch ( \Throwable $error ) {
			// An alert that cannot be mailed must not break the request; the diagnostics page shows the state.
			unset( $error );
		}
	}

	/**
	 * Send a test message to the recipients, also when an email already left today
	 * (the button on the settings page and `wp wplc settings test-alert`).
	 *
	 * @return string `sent`, `no_recipient` or `failed`.
	 */
	public static function send_test(): string {
		if ( ! self::recipients() ) {
			return 'no_recipient';
		}
		self::collect( 'test_message', 1, 1, '' );

		return self::send( true ) ? 'sent' : 'failed';
	}

	/**
	 * Send the collected alerts, unless an email already left today.
	 *
	 * @param bool $force Send although an email left today (test message).
	 * @return bool Whether an email was handed to WordPress.
	 */
	public static function send( $force = false ): bool {
		$alerts       = self::$alerts;
		self::$alerts = array();
		$recipients   = self::recipients();
		$today        = gmdate( 'Y-m-d' );
		if ( ! $alerts || ! $recipients || ( true !== $force && get_option( self::SENT_OPTION ) === $today ) ) {
			return false;
		}

		$site  = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$lines = array(
			/* translators: %s: name of the site. */
			sprintf( __( 'The license server on "%s" reports figures that need attention:', 'wp-license' ), $site ),
			'',
		);
		foreach ( $alerts as $metric => $alert ) {
			/* translators: 1: key of a figure, 2: current value, 3: alert threshold. */
			$lines[] = sprintf( __( '- %1$s: %2$s (alert from %3$s)', 'wp-license' ), $metric, number_format_i18n( $alert['value'] ), number_format_i18n( $alert['threshold'] ) );
			if ( '' !== $alert['url'] ) {
				$lines[] = '  ' . $alert['url'];
			}
		}
		$lines[] = '';
		$lines[] = __( 'Details: WooCommerce → License diagnostics. At most one of these emails is sent per day; the page always shows the current state.', 'wp-license' );
		$lines[] = admin_url( 'admin.php?page=' . Diagnostics::PAGE );

		/* translators: %s: name of the site. */
		$sent = (bool) wp_mail( $recipients, sprintf( __( '[%s] License server: health alert', 'wp-license' ), $site ), implode( "\n", $lines ) );
		if ( $sent && true !== $force ) {
			update_option( self::SENT_OPTION, $today, false );
		}

		return $sent;
	}
}
