<?php
/**
 * Customer notification wiring.
 *
 * @package WPLC
 */

namespace WPLC\Notifications;

use WPLC\Compatibility\Requirements;
use WPLC\Infrastructure\Database\Schema;
use WPLC\License\LicenseKey;
use WPLC\License\LicenseLifecycle;
use WPLC\License\LicenseStateMachine;

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
		'cancelled' => 'CancellationConfirmedEmail',
		'suspended' => 'LicenseSuspendedEmail',
		'revoked' => 'LicenseRevokedEmail',
	);

	/**
	 * Delays in seconds before a refused event email is tried again.
	 *
	 * @var int[]
	 */
	private const RETRY_DELAYS = array( 900, 7200, 43200 );

	/**
	 * Action Scheduler hook that retries a refused event email.
	 */
	public const RETRY_HOOK = 'wplc_retry_license_email';

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
	 * Option holding the position a continued reminder run resumes after:
	 * array( 'day' => UTC date of the run, 'id' => last handled license ID ).
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
		add_action( 'wplc/cancellation_scheduled', array( self::class, 'on_cancellation_scheduled' ), 20, 2 );
		add_action( self::RETRY_HOOK, array( self::class, 'retry_email' ), 10, 5 );
		// Fired by the admin package; these always go out, whatever was sent before.
		add_action( 'wplc/license_key_reissued', array( self::class, 'on_key_resend' ), 20, 3 );
		add_action( 'wplc/license_key_resend_requested', array( self::class, 'on_key_resend' ), 20, 3 );
		add_action( 'woocommerce_email_after_order_table', array( self::class, 'render_order_renewal_terms' ), 20, 3 );
		add_filter( 'woocommerce_prepare_email_for_preview', array( self::class, 'prepare_preview' ) );
		PurchaseLocale::register_hooks();
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
	 * Send the license email again: after a key was re-issued, or when support
	 * asks for it. Not bound to the send-once marker of the first email.
	 *
	 * @param mixed $license_id License ID.
	 * @param mixed $key        Plaintext license key (never logged or stored).
	 * @param mixed $actor      Acting user; unused.
	 * @return void
	 */
	public static function on_key_resend( $license_id = 0, $key = '', $actor = 0 ): void {
		unset( $actor );
		try {
			$license = self::get_license( (int) $license_id );
			if ( ! $license || empty( $license['customer_id'] ) ) {
				return;
			}
			$context = array(
				'license_key' => is_string( $key ) ? $key : '',
				'order_id' => (int) ( $license['source_order_id'] ?? 0 ),
			);
			if ( ! self::deliver( 'issued', $license, $context ) ) {
				self::schedule_retry( 'issued', (int) $license['id'], '', array(), 0 );
			}
		} catch ( \Throwable $error ) {
			self::log( 'error', 'License key email failed: ' . $error->getMessage(), (int) $license_id );
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
			self::send_once( $email_key, self::marker( $email_key ), $license, $period, array() );
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
		if ( in_array( $new_status, array( 'suspended', 'revoked' ), true ) && $new_status !== $old_status ) {
			return $new_status;
		}
		return '';
	}

	/**
	 * Marker under which an event email is recorded in the reminder log.
	 *
	 * A license can be suspended, reactivated and suspended again within one
	 * paid period, so these two markers carry the day: one email per day.
	 *
	 * @param string $email_key Short email key.
	 * @return string
	 */
	private static function marker( string $email_key ): string {
		return in_array( $email_key, array( 'suspended', 'revoked' ), true ) ? $email_key . '_' . gmdate( 'Ymd' ) : $email_key;
	}

	/**
	 * Confirm a cancellation of the automatic renewal by email (text form).
	 *
	 * Fired by the customer account and the public cancellation page through
	 * `do_action( 'wplc/cancellation_scheduled', $license_id, $cancelled_at )`.
	 * Sent at most once per license and request.
	 *
	 * @param mixed $license_id   License ID.
	 * @param mixed $cancelled_at Unix timestamp at which the cancellation was received; now when empty.
	 * @return void
	 */
	public static function on_cancellation_scheduled( $license_id = 0, $cancelled_at = 0 ): void {
		static $confirmed = array();

		try {
			$license_id = (int) $license_id;
			$license    = self::get_license( $license_id );
			if ( ! $license || empty( $license['cancel_at_period_end'] ) || isset( $confirmed[ $license_id ] ) ) {
				return;
			}
			$confirmed[ $license_id ] = true;
			$context                  = array( 'cancelled_at' => (int) $cancelled_at > 0 ? (int) $cancelled_at : time() );
			if ( ! self::deliver( 'cancelled', $license, $context ) ) {
				self::schedule_retry( 'cancelled', $license_id, '', $context, 0 );
			}
		} catch ( \Throwable $error ) {
			self::log( 'error', 'Cancellation confirmation email failed: ' . $error->getMessage(), (int) $license_id );
		}
	}

	/**
	 * Whether the advance notice of the upcoming automatic charge was sent
	 * (accepted by the mailer) for the current period of a license.
	 *
	 * For the renewal processor, which may refuse to charge without it.
	 *
	 * @param array<string,mixed> $license License row.
	 * @return bool
	 */
	public static function renewal_notice_sent( array $license ): bool {
		if ( empty( $license['id'] ) || empty( $license['paid_until'] ) ) {
			return false;
		}
		return ReminderLog::was_sent( (int) $license['id'], ReminderSchedule::TYPE_RENEWAL_NOTICE, (string) $license['paid_until'] );
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
		if ( ! ReminderLog::maybe_install() ) {
			self::log( 'error', 'Reminder log table is not available; no reminders were sent.' );
			return;
		}

		$tables   = Schema::table_names();
		$now      = time();
		$today    = gmdate( 'Y-m-d', $now );
		$cursor   = self::read_cursor( get_option( self::CURSOR_OPTION, 0 ), $today );
		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
		$finished = false;
		$over     = false;

		for ( $batch = 0; $batch < self::MAX_BATCHES && ! $over; ++$batch ) {
			// Two extra days: lead days are counted in site-timezone calendar days.
			$licenses = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$tables['licenses']} WHERE status = %s AND customer_id > 0 AND paid_until IS NOT NULL AND paid_until > %s AND paid_until <= %s AND id > %d ORDER BY id ASC LIMIT %d",
					'active',
					gmdate( 'Y-m-d H:i:s', $now ),
					gmdate( 'Y-m-d H:i:s', $now + ( $window + 2 ) * 86400 ),
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
				// Checked before every email: one slow mail server must not take the cron run down.
				if ( self::budget_used( $now, time() ) ) {
					$over = true;
					break;
				}
				$cursor = (int) $license['id'];
				$due    = ReminderSchedule::due_reminder( $license, $now, $lead_days, $notice_days, $timezone );
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

			if ( ! $over && count( $licenses ) < self::BATCH_SIZE ) {
				$finished = true;
				break;
			}
		}

		if ( $finished ) {
			delete_option( self::CURSOR_OPTION );
			return;
		}

		update_option(
			self::CURSOR_OPTION,
			array(
				'day' => $today,
				'id' => $cursor,
			),
			false
		);
		if ( 0 === Requirements::schedule_single_action( time() + 5 * 60, self::CONTINUE_HOOK ) && ! function_exists( 'as_schedule_single_action' ) && ! wp_next_scheduled( self::CONTINUE_HOOK ) ) {
			// Without Action Scheduler (never the case with WooCommerce active) WP-Cron continues the run.
			wp_schedule_single_event( time() + 5 * 60, self::CONTINUE_HOOK );
		}
	}

	/**
	 * License ID a continued run resumes after.
	 *
	 * A cursor of an earlier day is discarded: it belongs to a run that never
	 * finished, and resuming after it would skip the licenses before it today.
	 *
	 * @param mixed  $stored Stored option value.
	 * @param string $today  UTC date of the current run, 'Y-m-d'.
	 * @return int Zero to start from the beginning.
	 */
	public static function read_cursor( $stored, string $today ): int {
		if ( ! is_array( $stored ) || ! isset( $stored['day'], $stored['id'] ) || (string) $stored['day'] !== $today ) {
			return 0;
		}
		return max( 0, (int) $stored['id'] );
	}

	/**
	 * Whether a reminder run has used up its time budget.
	 *
	 * @param int $started Unix timestamp at which the run started.
	 * @param int $now     Current Unix timestamp.
	 * @return bool
	 */
	public static function budget_used( int $started, int $now ): bool {
		/**
		 * Filter the seconds a reminder run may take before it continues in a later run.
		 *
		 * @param int $seconds Time budget.
		 */
		$budget = (int) apply_filters( 'wplc_reminder_time_budget', self::TIME_BUDGET );

		return $now - $started >= max( 1, $budget );
	}

	/**
	 * Claim a notification and send it when this call won the claim.
	 *
	 * The claim only becomes a permanent "sent" entry after the mailer accepted
	 * the message. A refused message releases the claim: reminders are picked up
	 * by the next daily run, event emails are retried by a scheduled action.
	 *
	 * @param string              $email_key  Short email key.
	 * @param string              $marker     Marker stored in the reminder log.
	 * @param array<string,mixed> $license    License row.
	 * @param string              $period_end Period the notification belongs to (UTC datetime).
	 * @param array<string,mixed> $context    Extra data for the email.
	 * @param bool                $strict     True: never send when the claim cannot be stored (reminders).
	 * @param int                 $attempt    Number of retries already made for an event email.
	 * @return bool True when the email was handed to the mailer.
	 */
	private static function send_once( string $email_key, string $marker, array $license, string $period_end, array $context, bool $strict = false, int $attempt = 0 ): bool {
		$license_id = (int) ( $license['id'] ?? 0 );
		if ( $license_id <= 0 || empty( $license['customer_id'] ) ) {
			return false;
		}

		$email = self::email( $email_key );
		if ( null === $email ) {
			return false;
		}
		if ( ! $email->is_enabled() ) {
			self::log( 'info', 'The "' . $marker . '" email is switched off under WooCommerce > Settings > Emails; nothing was sent.', $license_id );
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

		$sent = false;
		try {
			$sent = (bool) $email->trigger( $license, $context );
		} catch ( \Throwable $error ) {
			self::log( 'error', 'The "' . $marker . '" email could not be built: ' . $error->getMessage(), $license_id );
		}

		if ( ReminderLog::CLAIMED === $claim ) {
			// Only an accepted message counts as sent; a refused one is tried again.
			if ( $sent ) {
				ReminderLog::confirm( $license_id, $marker, $period_end );
			} else {
				ReminderLog::release( $license_id, $marker, $period_end );
			}
		}
		if ( ! $sent ) {
			self::log( 'warning', 'The "' . $marker . '" email was not accepted by the mailer.', $license_id );
			if ( ! $strict ) {
				self::schedule_retry( $email_key, $license_id, $period_end, $context, $attempt );
			}
		}
		return $sent;
	}

	/**
	 * Send an email without duplicate protection.
	 *
	 * @param string              $email_key Short email key.
	 * @param array<string,mixed> $license   License row.
	 * @param array<string,mixed> $context   Extra data for the email.
	 * @return bool True when the email was handed to the mailer, or is switched off.
	 */
	private static function deliver( string $email_key, array $license, array $context ): bool {
		$license_id = (int) ( $license['id'] ?? 0 );
		$email      = self::email( $email_key );
		if ( null === $email || $license_id <= 0 ) {
			return true;
		}
		if ( ! $email->is_enabled() ) {
			self::log( 'info', 'The "' . $email_key . '" email is switched off under WooCommerce > Settings > Emails; nothing was sent.', $license_id );
			return true;
		}

		$sent = false;
		try {
			$sent = (bool) $email->trigger( $license, $context );
		} catch ( \Throwable $error ) {
			self::log( 'error', 'The "' . $email_key . '" email could not be built: ' . $error->getMessage(), $license_id );
		}
		if ( ! $sent ) {
			self::log( 'warning', 'The "' . $email_key . '" email was not accepted by the mailer.', $license_id );
		}
		return $sent;
	}

	/**
	 * Queue another attempt for an event email the mailer refused.
	 *
	 * The plaintext license key is never stored with the action; the retry
	 * derives it again.
	 *
	 * @param string              $email_key  Short email key.
	 * @param int                 $license_id License ID.
	 * @param string              $period_end Period of the notification, empty for emails without duplicate protection.
	 * @param array<string,mixed> $context    Extra data for the email.
	 * @param int                 $attempt    Retries already made.
	 * @return void
	 */
	private static function schedule_retry( string $email_key, int $license_id, string $period_end, array $context, int $attempt ): void {
		if ( ! isset( self::RETRY_DELAYS[ $attempt ] ) ) {
			self::log( 'error', 'The "' . $email_key . '" email was refused ' . ( $attempt + 1 ) . ' times and is given up.', $license_id );
			return;
		}
		$safe = array();
		foreach ( $context as $name => $value ) {
			if ( 'license_key' !== $name && ( is_int( $value ) || is_bool( $value ) ) ) {
				$safe[ (string) $name ] = (int) $value;
			}
		}
		Requirements::schedule_single_action(
			time() + self::RETRY_DELAYS[ $attempt ],
			self::RETRY_HOOK,
			array( $email_key, $license_id, $period_end, $attempt + 1, $safe )
		);
	}

	/**
	 * Scheduled action: try an event email again.
	 *
	 * Nothing is sent when the license has moved on and the email no longer applies.
	 *
	 * @param mixed $email_key  Short email key.
	 * @param mixed $license_id License ID.
	 * @param mixed $period_end Period of the notification, empty for emails without duplicate protection.
	 * @param mixed $attempt    Number of this retry, starting at 1.
	 * @param mixed $context    Extra data for the email.
	 * @return void
	 */
	public static function retry_email( $email_key = '', $license_id = 0, $period_end = '', $attempt = 1, $context = array() ): void {
		try {
			$email_key = (string) $email_key;
			$attempt   = max( 1, (int) $attempt );
			$context   = is_array( $context ) ? $context : array();
			$license   = self::get_license( (int) $license_id );
			if ( ! $license || ! isset( self::EMAILS[ $email_key ] ) || ! self::still_applies( $email_key, $license, (string) $period_end ) ) {
				return;
			}

			if ( 'issued' === $email_key ) {
				$key_id                 = (string) ( $license['license_key_secret_id'] ?? 'legacy' );
				$verified               = LicenseKey::matches_hash( (string) $license['license_uuid'], $key_id, (string) $license['license_key_hash'] );
				$context['license_key'] = $verified ? LicenseKey::for_uuid( (string) $license['license_uuid'], $key_id ) : '';
				$context['order_id']    = (int) ( $license['source_order_id'] ?? 0 );
			}

			// Without a period the email has no send-once marker (cancellation confirmation, key resend).
			if ( '' === (string) $period_end ) {
				if ( ! self::deliver( $email_key, $license, $context ) ) {
					self::schedule_retry( $email_key, (int) $license['id'], '', $context, $attempt );
				}
				return;
			}
			self::send_once( $email_key, self::marker( $email_key ), $license, (string) $period_end, $context, false, $attempt );
		} catch ( \Throwable $error ) {
			self::log( 'error', 'Retry of a license email failed: ' . $error->getMessage(), (int) $license_id );
		}
	}

	/**
	 * Whether a delayed event email still describes the license.
	 *
	 * @param string              $email_key  Short email key.
	 * @param array<string,mixed> $license    Current license row.
	 * @param string              $period_end Period the email was first triggered for.
	 * @return bool
	 */
	public static function still_applies( string $email_key, array $license, string $period_end ): bool {
		$status = (string) ( $license['status'] ?? '' );
		switch ( $email_key ) {
			case 'issued':
				return in_array( $status, array( 'active', 'past_due' ), true );
			case 'payment_failed':
				return 'past_due' === $status;
			case 'expired':
			case 'suspended':
			case 'revoked':
				return $email_key === $status;
			case 'renewed':
				return 'active' === $status && (string) ( $license['paid_until'] ?? '' ) === $period_end;
			case 'cancelled':
				return ! empty( $license['cancel_at_period_end'] ) && in_array( $status, array( 'active', 'past_due' ), true );
		}
		return false;
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
		if ( $due_at <= 0 ) {
			$due_at = RenewalTerms::charge_timestamp( $license );
		}

		// The customer is charged the amount including tax, so that is the amount the emails name.
		$gross = RenewalTerms::gross( $license );

		$account_url = function_exists( 'wc_get_account_endpoint_url' )
			? (string) wc_get_account_endpoint_url( 'wplc-licenses' )
			: (string) home_url( '/' );

		// "Renew now" exists in the account from this moment on; earlier emails must not promise it.
		$renew_from = false === $paid_until ? 0 : (int) $paid_until - LicenseStateMachine::MANUAL_RENEWAL_WINDOW_DAYS * 86400;

		return array(
			'product' => self::product_name( (string) ( $license['product_key'] ?? '' ) ),
			'plan' => self::plan_label( (string) ( $license['plan_key'] ?? '' ), $license ),
			'key_prefix' => (string) ( $license['key_prefix'] ?? '' ),
			'valid_until' => false === $paid_until ? __( 'No expiry', 'wp-license' ) : self::format_date( (int) $paid_until ),
			'days_left' => isset( $context['days_left'] ) ? (string) max( 0, (int) $context['days_left'] ) : '',
			'amount' => RenewalTerms::amount_text( $gross ),
			'amount_html' => RenewalTerms::price_html( $gross ),
			'amount_includes_tax' => ! empty( $gross['includes_tax'] ) ? '1' : '',
			'currency' => (string) $gross['currency'],
			'interval' => RenewalTerms::interval_label( (string) ( $license['billing_interval'] ?? 'year' ) ),
			'charge_date' => $due_at > 0 ? self::format_date( $due_at ) : '',
			'grace_until' => false === $paid_until ? '' : self::format_date( (int) $paid_until + LicenseLifecycle::GRACE_DAYS * 86400 ),
			'account_url' => $account_url,
			'pay_url' => self::pay_url( $license ),
			'can_renew_now' => $renew_from > 0 && time() >= $renew_from ? '1' : '',
			'renew_from' => $renew_from > 0 ? self::format_date( $renew_from ) : '',
			'cancelled_at' => ! empty( $context['cancelled_at'] ) ? self::format_date( (int) $context['cancelled_at'], true ) : '',
			'status' => (string) ( $license['status'] ?? '' ),
			'support_email' => self::support_email(),
		);
	}

	/**
	 * Sample values for the email preview of WooCommerce.
	 *
	 * @param string $status License status the sample describes.
	 * @return array<string,string>
	 */
	public static function sample_view( string $status = 'active' ): array {
		$now   = time();
		$price = function_exists( 'wc_price' ) ? (string) wc_price( 49 ) : '49.00';

		return array(
			'product' => __( 'Sample product', 'wp-license' ),
			'plan' => __( 'Sample plan', 'wp-license' ),
			'key_prefix' => 'WPLC-1A2B3C4D',
			'valid_until' => self::format_date( $now + 30 * 86400 ),
			'days_left' => '7',
			'amount' => Placeholders::plain( $price ),
			'amount_html' => $price,
			'amount_includes_tax' => '',
			'currency' => function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '',
			'interval' => RenewalTerms::interval_label( 'year' ),
			'charge_date' => self::format_date( $now + 30 * 86400 ),
			'grace_until' => self::format_date( $now + ( 30 + LicenseLifecycle::GRACE_DAYS ) * 86400 ),
			'account_url' => function_exists( 'wc_get_account_endpoint_url' ) ? (string) wc_get_account_endpoint_url( 'wplc-licenses' ) : (string) home_url( '/' ),
			'pay_url' => '',
			'can_renew_now' => '1',
			'renew_from' => self::format_date( $now ),
			'cancelled_at' => self::format_date( $now, true ),
			'status' => $status,
			'support_email' => self::support_email(),
		);
	}

	/**
	 * Fill a license email with sample data for the WooCommerce email preview.
	 *
	 * @param mixed $email Email object prepared by WooCommerce.
	 * @return mixed
	 */
	public static function prepare_preview( $email ) {
		try {
			if ( $email instanceof Emails\LicenseEmail ) {
				$email->set_preview_data();
			}
		} catch ( \Throwable $error ) {
			self::log( 'warning', 'Could not prepare the email preview: ' . $error->getMessage() );
		}
		return $email;
	}

	/**
	 * Print the terms of the automatic renewal below the order table of the
	 * customer's order emails.
	 *
	 * @param mixed $order         Order.
	 * @param mixed $sent_to_admin Whether the email goes to the admin.
	 * @param mixed $plain_text    Whether the email is plain text.
	 * @return void
	 */
	public static function render_order_renewal_terms( $order, $sent_to_admin = false, $plain_text = false ): void {
		try {
			if ( $sent_to_admin || ! $order instanceof \WC_Order || ! function_exists( 'wc_get_template' ) ) {
				return;
			}
			$license_ids = LicenseLifecycle::license_ids_for_order( (int) $order->get_id() );
			$printed     = false;
			foreach ( $license_ids as $license_id ) {
				$license = self::get_license( (int) $license_id );
				$rows    = $license && in_array( (string) $license['status'], array( 'active', 'past_due' ), true ) ? RenewalTerms::rows( $license ) : array();
				if ( ! $rows ) {
					continue;
				}
				$printed = true;
				wc_get_template(
					( $plain_text ? 'emails/plain/' : 'emails/' ) . 'wplc-renewal-terms.php',
					array(
						'product' => self::product_name( (string) $license['product_key'] ),
						'key_prefix' => (string) $license['key_prefix'],
						'renewal_terms' => $rows,
						'cancel_text' => RenewalTerms::cancel_text(),
					),
					'wp-license/',
					WPLC_PATH . 'templates/'
				);
			}
			if ( ! $printed && ! $license_ids && $order->get_meta( '_wplc_recurring_consent_at', true ) && ! $order->get_meta( '_wplc_renewal_for_license', true ) ) {
				// The license is created with the payment; its email lists date, amount and interval.
				$text = __( 'You chose automatic renewal for your license. The email with your license lists the renewal date, the amount including tax and the interval.', 'wp-license' ) . ' ' . RenewalTerms::cancel_text();
				echo $plain_text ? "\n" . esc_html( $text ) . "\n" : '<p>' . esc_html( $text ) . '</p>';
			}
		} catch ( \Throwable $error ) {
			self::log( 'warning', 'Could not print the renewal terms in an order email: ' . $error->getMessage() );
		}
	}

	/**
	 * Three steps from the purchase to the working add-on.
	 *
	 * @param array<string,mixed> $license License row, for the filter.
	 * @return string[]
	 */
	public static function install_steps( array $license = array() ): array {
		$steps = array(
			__( 'Download the plugin ZIP file in your account under "Licenses".', 'wp-license' ),
			__( 'In the WordPress dashboard of your site, go to Plugins > Add New > Upload Plugin, choose the ZIP file, then install and activate it.', 'wp-license' ),
			__( 'Open the license settings of the plugin, enter your license key and activate the license for the site.', 'wp-license' ),
		);

		/**
		 * Filter the installation steps shown in the account and in the license email.
		 *
		 * @param string[]            $steps   Plain-text steps.
		 * @param array<string,mixed> $license License row, may be empty.
		 */
		$filtered = apply_filters( 'wplc_install_steps', $steps, $license );

		return is_array( $filtered ) ? array_values( array_filter( array_map( 'strval', $filtered ), 'strlen' ) ) : $steps;
	}

	/**
	 * Documentation link of a product, when the shop provides one.
	 *
	 * @param string $product_key Product key.
	 * @return string URL or an empty string.
	 */
	public static function docs_url( string $product_key ): string {
		/**
		 * Filter the documentation URL shown with the installation steps.
		 *
		 * @param string $url         URL, empty for none.
		 * @param string $product_key Product key.
		 */
		$url = apply_filters( 'wplc_product_docs_url', '', $product_key );

		return is_string( $url ) ? esc_url_raw( $url ) : '';
	}

	/**
	 * Customer-facing name of a plan.
	 *
	 * The catalog has no display name for plans, so the internal key is made
	 * readable ("single-site" becomes "Single Site") and can be filtered.
	 *
	 * @param string              $plan_key Plan key.
	 * @param array<string,mixed> $license  License row.
	 * @return string
	 */
	public static function plan_label( string $plan_key, array $license = array() ): string {
		$label = ucwords( trim( (string) preg_replace( '/[-_.]+/', ' ', $plan_key ) ) );

		/**
		 * Filter the plan name shown to customers.
		 *
		 * @param string              $label    Readable plan name.
		 * @param string              $plan_key Internal plan key.
		 * @param array<string,mixed> $license  License row.
		 */
		$filtered = apply_filters( 'wplc_plan_label', $label, $plan_key, $license );

		return is_string( $filtered ) && '' !== $filtered ? $filtered : $label;
	}

	/**
	 * Address customers can reply to: the sender address of the shop emails.
	 *
	 * @return string
	 */
	public static function support_email(): string {
		$address = (string) get_option( 'woocommerce_email_from_address', '' );
		if ( ! is_email( $address ) ) {
			$address = (string) get_option( 'admin_email', '' );
		}

		/**
		 * Filter the support contact named in license emails.
		 *
		 * @param string $address Email address.
		 */
		$filtered = apply_filters( 'wplc_support_email', $address );

		return is_string( $filtered ) && is_email( $filtered ) ? $filtered : '';
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
	public static function product_name( string $product_key ): string {
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
	 * Format a timestamp in the site's date format and timezone.
	 *
	 * @param int  $timestamp Unix timestamp.
	 * @param bool $with_time Whether to append the time and the timezone.
	 * @return string
	 */
	public static function format_date( int $timestamp, bool $with_time = false ): string {
		$format = (string) get_option( 'date_format', 'Y-m-d' );
		$format = '' === $format ? 'Y-m-d' : $format;
		if ( $with_time ) {
			$time    = (string) get_option( 'time_format', 'H:i' );
			$format .= ' ' . ( '' === $time ? 'H:i' : $time ) . ' (T)';
		}
		$date = function_exists( 'wp_date' ) ? wp_date( $format, $timestamp ) : false;
		if ( is_string( $date ) && '' !== $date ) {
			return $date;
		}

		return $with_time ? gmdate( 'Y-m-d H:i', $timestamp ) . ' (UTC)' : gmdate( 'Y-m-d', $timestamp );
	}

	/**
	 * Fetch one license row.
	 *
	 * @param int $license_id License ID.
	 * @return array<string,mixed>|null
	 */
	public static function get_license( int $license_id ): ?array {
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
			// Mailer errors can quote the recipient; addresses do not belong in a log file.
			$message = (string) preg_replace( '/[^\s<>"\'(),;:]+@[^\s<>"\'(),;:]+/', '[email]', $message );
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
