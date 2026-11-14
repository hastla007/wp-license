<?php
/**
 * Customer notification wiring.
 *
 * @package WPLC
 */

namespace WPLC\Notifications;

use WPLC\Billing\Money;
use WPLC\Infrastructure\Database\Schema;
use WPLC\License\LicenseLifecycle;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the license emails with WooCommerce and decides when they are sent.
 *
 * Every send is best-effort: a failing mailer, template or database must never
 * break checkout, a renewal or the daily cron run.
 */
final class Notifications {

	/**
	 * WooCommerce email registry keys mapped to the short class names in Emails/.
	 *
	 * @var array<string,string>
	 */
	private const EMAILS = array(
		'issued' => 'LicenseIssuedEmail',
		'expiry_reminder' => 'ExpiryReminderEmail',
		'renewal_notice' => 'RenewalNoticeEmail',
		'payment_failed' => 'PaymentFailedEmail',
		'expired' => 'LicenseExpiredEmail',
		'renewed' => 'LicenseRenewedEmail',
	);

	/**
	 * Licenses read per query by the reminder job.
	 */
	private const BATCH_SIZE = 200;

	/**
	 * Upper bound of batches per reminder run.
	 */
	private const MAX_BATCHES = 5;

	/**
	 * Seconds after which a reminder run stops and continues later.
	 */
	private const TIME_BUDGET = 20;

	/**
	 * Option holding the license ID a continued reminder run resumes after.
	 */
	private const CURSOR_OPTION = 'wplc_reminder_cursor';

	/**
	 * Single-event hook that continues an unfinished reminder run.
	 */
	public const CONTINUE_HOOK = 'wplc_send_reminders';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_filter( 'woocommerce_email_classes', array( self::class, 'register_emails' ) );
		add_filter( 'woocommerce_template_directory', array( self::class, 'template_directory' ), 10, 2 );
		add_action( 'wplc/license_issued', array( self::class, 'on_license_issued' ), 20, 3 );
		add_action( 'wplc/license_status_changed', array( self::class, 'on_status_changed' ), 20, 4 );
		add_action( 'wplc_daily_lifecycle', array( self::class, 'send_due_reminders' ), 20 );
		add_action( self::CONTINUE_HOOK, array( self::class, 'send_due_reminders' ) );
	}

	/**
	 * Registry key used in WooCommerce for one of our emails.
	 *
	 * @param string $email_key Short email key.
	 * @return string
	 */
	public static function registry_key( string $email_key ): string {
		return 'WPLC_Email_' . ucwords( $email_key, '_' );
	}

	/**
	 * Add the license emails to WooCommerce → Settings → Emails.
	 *
	 * The classes extend WC_Email and are therefore only loaded here, after
	 * WooCommerce has loaded its mailer.
	 *
	 * @param mixed $emails Registered email objects.
	 * @return mixed
	 */
	public static function register_emails( $emails ) {
		if ( ! is_array( $emails ) || ! class_exists( '\\WC_Email' ) ) {
			return $emails;
		}

		foreach ( self::EMAILS as $email_key => $class_name ) {
			try {
				$class = __NAMESPACE__ . '\\Emails\\' . $class_name;
				if ( class_exists( $class ) ) {
					$emails[ self::registry_key( $email_key ) ] = new $class();
				}
			} catch ( \Throwable $error ) {
				self::log( 'error', 'Could not register license email "' . $email_key . '": ' . $error->getMessage() );
			}
		}

		return $emails;
	}

	/**
	 * Let themes override the templates in wp-license/emails/ instead of woocommerce/emails/.
	 *
	 * @param mixed $directory Theme directory name.
	 * @param mixed $template  Template path relative to the template base.
	 * @return mixed
	 */
	public static function template_directory( $directory, $template = '' ) {
		if ( is_string( $template ) && preg_match( '#^emails/(plain/)?wplc-#', $template ) ) {
			return 'wp-license';
		}
		return $directory;
	}

	/**
	 * Send the "license issued" email.
	 *
	 * @param mixed $license_id License ID.
	 * @param mixed $key        Plaintext license key (never logged).
	 * @param mixed $order_id   Source order ID.
	 * @return void
	 */
	public static function on_license_issued( $license_id = 0, $key = '', $order_id = 0 ): void {
		try {
			$license = self::get_license( (int) $license_id );
			if ( ! $license ) {
				return;
			}
			$period = ! empty( $license['paid_until'] ) ? (string) $license['paid_until'] : (string) $license['starts_at'];
			self::send_once(
				'issued',
				'issued',
				$license,
				$period,
				array(
					'license_key' => is_string( $key ) ? $key : '',
					'order_id' => (int) $order_id,
				)
			);
		} catch ( \Throwable $error ) {
			self::log( 'error', 'License issued email failed: ' . $error->getMessage(), (int) $license_id );
		}
	}

	/**
	 * Send the email that belongs to a status transition or renewal.
	 *
	 * @param mixed $license_id License ID.
	 * @param mixed $old_status Previous status.
	 * @param mixed $new_status New status.
	 * @param mixed $action     Lifecycle action.
	 * @return void
	 */
	public static function on_status_changed( $license_id = 0, $old_status = '', $new_status = '', $action = '' ): void {
		try {
			$email_key = self::email_for_transition( (string) $old_status, (string) $new_status, (string) $action );
			if ( '' === $email_key ) {
				return;
			}
			$license = self::get_license( (int) $license_id );
			if ( ! $license ) {
				return;
			}
			$period = ! empty( $license['paid_until'] ) ? (string) $license['paid_until'] : (string) $license['starts_at'];
			self::send_once( $email_key, $email_key, $license, $period, array() );
		} catch ( \Throwable $error ) {
			self::log( 'error', 'License status email failed: ' . $error->getMessage(), (int) $license_id );
		}
	}

	/**
	 * Map a lifecycle event to an email key.
	 *
	 * @param string $old_status Previous status.
	 * @param string $new_status New status.
	 * @param string $action     Lifecycle action.
	 * @return string Email key or an empty string when no email is sent.
	 */
	public static function email_for_transition( string $old_status, string $new_status, string $action ): string {
		if ( 'renewal_succeeded' === $action ) {
			return 'active' === $new_status ? 'renewed' : '';
		}
		if ( 'past_due' === $new_status && 'past_due' !== $old_status ) {
			return 'payment_failed';
		}
		if ( 'expired' === $new_status && 'expired' !== $old_status ) {
			return 'expired';
		}
		return '';
	}

	/**
	 * Expiry lead days: defaults, then the settings option, then the filter.
	 *
	 * @return int[]
	 */
	public static function lead_days(): array {
		$days     = ReminderSchedule::DEFAULT_LEAD_DAYS;
		$settings = get_option( 'wplc_settings', array() );
		// The settings page stores the list as "reminder_days".
		$setting_key = is_array( $settings ) && isset( $settings['reminder_days'] ) ? 'reminder_days' : 'reminder_lead_days';
		if ( is_array( $settings ) && isset( $settings[ $setting_key ] ) ) {
			$configured = $settings[ $setting_key ];
			if ( is_array( $configured ) || ( is_scalar( $configured ) && '' !== trim( (string) $configured ) ) ) {
				// "0" (or only invalid numbers) disables expiry reminders.
				$days = ReminderSchedule::parse_lead_days( is_array( $configured ) ? $configured : (string) $configured );
			}
		}

		/**
		 * Filter the days before paid_until on which expiry reminders are sent.
		 *
		 * @param int[] $days Lead days. An empty array disables expiry reminders.
		 */
		return ReminderSchedule::parse_lead_days( apply_filters( 'wplc_reminder_lead_days', $days ) );
	}

	/**
	 * Days before an automatic charge at which the customer is told about it.
	 *
	 * @return int
	 */
	public static function notice_days(): int {
		/**
		 * Filter the lead time of the automatic renewal notice. Zero disables it.
		 *
		 * @param int $days Lead days.
		 */
		return max( 0, min( 365, (int) apply_filters( 'wplc_renewal_notice_lead_days', ReminderSchedule::DEFAULT_NOTICE_DAYS ) ) );
	}

	/**
	 * Daily job: send expiry reminders and renewal notices that are due.
	 *
	 * @return void
	 */
	public static function send_due_reminders(): void {
		try {
			self::run_reminders();
		} catch ( \Throwable $error ) {
			self::log( 'error', 'Reminder run failed: ' . $error->getMessage() );
		}
	}

	/**
	 * Select due licenses in bounded batches and send each reminder once.
	 *
	 * @return void
	 */
	private static function run_reminders(): void {
		global $wpdb;

		if ( ! function_exists( 'WC' ) ) {
			return;
		}
		$lead_days   = self::lead_days();
		$notice_days = self::notice_days();
		$window      = ReminderSchedule::max_window_days( $lead_days, $notice_days );
		if ( $window < 1 ) {
			return;
		}
		if ( ! ReminderLog::is_available() ) {
			self::log( 'error', 'Reminder log table is not available; no reminders were sent.' );
			return;
		}

		$tables   = Schema::table_names();
		$now      = time();
		$cursor   = max( 0, (int) get_option( self::CURSOR_OPTION, 0 ) );
		$finished = false;

		for ( $batch = 0; $batch < self::MAX_BATCHES; ++$batch ) {
			$licenses = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$tables['licenses']} WHERE status = %s AND customer_id > 0 AND paid_until IS NOT NULL AND paid_until > %s AND paid_until <= %s AND id > %d ORDER BY id ASC LIMIT %d",
					'active',
					gmdate( 'Y-m-d H:i:s', $now ),
					gmdate( 'Y-m-d H:i:s', $now + ( $window + 1 ) * 86400 ),
					$cursor,
					self::BATCH_SIZE
				),
				ARRAY_A
			);
			if ( ! is_array( $licenses ) || ! $licenses ) {
				$finished = true;
				break;
			}

			foreach ( $licenses as $license ) {
				$cursor = (int) $license['id'];
				$due    = ReminderSchedule::due_reminder( $license, $now, $lead_days, $notice_days );
				if ( null === $due ) {
					continue;
				}
				try {
					self::send_once(
						(string) $due['type'],
						(string) $due['marker'],
						$license,
						(string) $due['period_end'],
						array(
							'days_left' => (int) $due['days_left'],
							'due_at' => (int) $due['due_at'],
						),
						true
					);
				} catch ( \Throwable $error ) {
					self::log( 'error', 'Reminder email failed: ' . $error->getMessage(), $cursor );
				}
			}

			if ( count( $licenses ) < self::BATCH_SIZE ) {
				$finished = true;
				break;
			}
			if ( time() - $now >= self::TIME_BUDGET ) {
				break;
			}
		}

		if ( $finished ) {
			delete_option( self::CURSOR_OPTION );
			return;
		}

		update_option( self::CURSOR_OPTION, $cursor, false );
		if ( ! wp_next_scheduled( self::CONTINUE_HOOK ) ) {
			wp_schedule_single_event( time() + 5 * 60, self::CONTINUE_HOOK );
		}
	}

	/**
	 * Claim a notification and send it when this call won the claim.
	 *
	 * @param string              $email_key  Short email key.
	 * @param string              $marker     Marker stored in the reminder log.
	 * @param array<string,mixed> $license    License row.
	 * @param string              $period_end Period the notification belongs to (UTC datetime).
	 * @param array<string,mixed> $context    Extra data for the email.
	 * @param bool                $strict     True: never send when the claim cannot be stored.
	 * @return bool True when the email was handed to the mailer.
	 */
	private static function send_once( string $email_key, string $marker, array $license, string $period_end, array $context, bool $strict = false ): bool {
		$license_id = (int) ( $license['id'] ?? 0 );
		if ( $license_id <= 0 || empty( $license['customer_id'] ) ) {
			return false;
		}

		$email = self::email( $email_key );
		if ( null === $email || ! $email->is_enabled() ) {
			return false;
		}

		$claim = ReminderLog::claim( $license_id, $marker, $period_end );
		if ( ReminderLog::DUPLICATE === $claim ) {
			return false;
		}
		if ( ReminderLog::FAILED === $claim ) {
			if ( $strict ) {
				self::log( 'warning', 'Could not record the "' . $marker . '" reminder; it was not sent.', $license_id );
				return false;
			}
			// Event emails are sent anyway: a rare duplicate is better than silence about a failed payment.
			self::log( 'warning', 'Could not record the "' . $marker . '" notification; sending without duplicate protection.', $license_id );
		}

		$sent = (bool) $email->trigger( $license, $context );
		if ( ! $sent ) {
			self::log( 'warning', 'The "' . $marker . '" email was not accepted by the mailer.', $license_id );
		}
		return $sent;
	}

	/**
	 * Fetch one of our email objects from the WooCommerce mailer.
	 *
	 * @param string $email_key Short email key.
	 * @return Emails\LicenseEmail|null
	 */
	private static function email( string $email_key ) {
		if ( ! isset( self::EMAILS[ $email_key ] ) || ! function_exists( 'WC' ) ) {
			return null;
		}
		$mailer = WC()->mailer();
		$emails = is_object( $mailer ) ? $mailer->get_emails() : array();
		$email  = is_array( $emails ) && isset( $emails[ self::registry_key( $email_key ) ] ) ? $emails[ self::registry_key( $email_key ) ] : null;

		return $email instanceof Emails\LicenseEmail ? $email : null;
	}

	/**
	 * Name of the bought product or variation, shown as the plan; never the internal plan key.
	 *
	 * Empty when it adds nothing to the product name, so the email leaves the row out.
	 *
	 * @param array<string,mixed> $license      License row.
	 * @param string              $product_name Display name of the catalog product.
	 * @return string
	 */
	public static function plan_label( array $license, string $product_name ): string {
		$product_id = (int) ( $license['renewal_product_id'] ?? 0 );
		if ( $product_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return '';
		}
		$product = wc_get_product( $product_id );
		if ( ! $product || ! is_callable( array( $product, 'get_name' ) ) ) {
			return '';
		}
		$name = trim( wp_strip_all_tags( (string) $product->get_name() ) );
		return $name === trim( $product_name ) ? '' : $name;
	}

	/**
	 * Build the display values shared by the templates and placeholders.
	 *
	 * Must be called after the customer's locale was switched in, because it
	 * formats dates and amounts.
	 *
	 * @param array<string,mixed> $license License row.
	 * @param array<string,mixed> $context Extra data from the trigger.
	 * @return array<string,string>
	 */
	public static function view( array $license, array $context = array() ): array {
		$paid_until = empty( $license['paid_until'] ) ? false : strtotime( (string) $license['paid_until'] . ' UTC' );
		$due_at     = isset( $context['due_at'] ) ? (int) $context['due_at'] : 0;
		if ( $due_at <= 0 && ! empty( $license['next_payment_at'] ) ) {
			$due_at = (int) strtotime( (string) $license['next_payment_at'] . ' UTC' );
		}
		if ( $due_at <= 0 && false !== $paid_until ) {
			$due_at = (int) $paid_until;
		}

		$amount_html = '';
		$amount      = '';
		$minor       = (int) ( $license['renewal_amount_minor'] ?? 0 );
		$currency    = strtoupper( (string) ( $license['renewal_currency'] ?? '' ) );
		if ( $minor > 0 && function_exists( 'wc_price' ) ) {
			$decimals    = (int) ( $license['renewal_currency_decimals'] ?? 2 );
			$amount_html = (string) wc_price(
				Money::to_decimal( $minor, $decimals ),
				array(
					'currency' => $currency,
					'decimals' => $decimals,
				)
			);
			$amount      = Placeholders::plain( $amount_html );
		}

		$account_url = function_exists( 'wc_get_account_endpoint_url' )
			? (string) wc_get_account_endpoint_url( 'wplc-licenses' )
			: (string) home_url( '/' );

		$product_name = self::product_name( (string) ( $license['product_key'] ?? '' ) );

		return array(
			'product' => $product_name,
			'plan' => self::plan_label( $license, $product_name ),
			'key_prefix' => (string) ( $license['key_prefix'] ?? '' ),
			'valid_until' => false === $paid_until ? __( 'No expiry', 'wp-license' ) : self::format_date( (int) $paid_until ),
			'days_left' => isset( $context['days_left'] ) ? (string) max( 0, (int) $context['days_left'] ) : '',
			'amount' => $amount,
			'amount_html' => $amount_html,
			'currency' => $currency,
			'charge_date' => $due_at > 0 ? self::format_date( $due_at ) : '',
			'grace_until' => false === $paid_until ? '' : self::format_date( (int) $paid_until + LicenseLifecycle::GRACE_DAYS * 86400 ),
			'account_url' => $account_url,
			'pay_url' => self::pay_url( $license ),
		);
	}

	/**
	 * Pay link of an open renewal order of a past-due license, or an empty string.
	 *
	 * Mirrors the lookup in the customer portal, but accepts every gateway.
	 *
	 * @param array<string,mixed> $license License row.
	 * @return string
	 */
	private static function pay_url( array $license ): string {
		global $wpdb;

		if ( 'past_due' !== (string) ( $license['status'] ?? '' ) || ! function_exists( 'wc_get_order' ) ) {
			return '';
		}

		$tables   = Schema::table_names();
		$order_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT renewal_order_id FROM {$tables['renewals']} WHERE license_id = %d AND status IN (%s,%s) AND renewal_order_id IS NOT NULL ORDER BY created_at DESC, id DESC LIMIT 1",
				(int) $license['id'],
				'failed',
				'manual_action_required'
			)
		);
		$order    = $order_id > 0 ? wc_get_order( $order_id ) : false;
		if (
			! $order instanceof \WC_Order
			|| (int) $order->get_customer_id() !== (int) $license['customer_id']
			|| ! $order->needs_payment()
		) {
			return '';
		}

		return (string) $order->get_checkout_payment_url();
	}

	/**
	 * Display name of a licensed product.
	 *
	 * @param string $product_key Product key.
	 * @return string
	 */
	private static function product_name( string $product_key ): string {
		global $wpdb;

		if ( '' === $product_key ) {
			return '';
		}
		$tables = Schema::table_names();
		$name   = $wpdb->get_var(
			$wpdb->prepare( "SELECT display_name FROM {$tables['products']} WHERE product_key = %s LIMIT 1", $product_key )
		);

		return is_string( $name ) && '' !== trim( $name ) ? $name : $product_key;
	}

	/**
	 * Format a timestamp as a date in the site's date format and timezone.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string
	 */
	private static function format_date( int $timestamp ): string {
		$format = (string) get_option( 'date_format', 'Y-m-d' );
		$date   = function_exists( 'wp_date' ) ? wp_date( '' === $format ? 'Y-m-d' : $format, $timestamp ) : false;

		return is_string( $date ) && '' !== $date ? $date : gmdate( 'Y-m-d', $timestamp );
	}

	/**
	 * Fetch one license row.
	 *
	 * @param int $license_id License ID.
	 * @return array<string,mixed>|null
	 */
	private static function get_license( int $license_id ): ?array {
		global $wpdb;

		if ( $license_id <= 0 ) {
			return null;
		}
		$tables = Schema::table_names();
		$row    = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$tables['licenses']} WHERE id = %d LIMIT 1", $license_id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Write to the WooCommerce log. Messages must not contain keys or secrets.
	 *
	 * @param string $level      Log level.
	 * @param string $message    Message.
	 * @param int    $license_id Related license ID, if any.
	 * @return void
	 */
	public static function log( string $level, string $message, int $license_id = 0 ): void {
		try {
			if ( ! function_exists( 'wc_get_logger' ) ) {
				return;
			}
			$context = array( 'source' => 'wp-license' );
			if ( $license_id > 0 ) {
				$context['license_id'] = $license_id;
			}
			wc_get_logger()->log( in_array( $level, array( 'error', 'warning', 'info', 'notice', 'debug' ), true ) ? $level : 'info', $message, $context );
		} catch ( \Throwable $error ) {
			unset( $error );
		}
	}
}
