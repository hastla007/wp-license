<?php
/**
 * Renewal processing and retry state machine.
 *
 * @package WPLC
 */

namespace WPLC\Billing;

use WPLC\Gateway\PaymentReference;
use WPLC\Gateway\Registry;
use WPLC\Gateway\StripeEventHandler;
use WPLC\Gateway\StripeRecurringAdapter;
use WPLC\Infrastructure\Database\Schema;
use WPLC\License\AuditLog;
use WPLC\License\AuditReason;
use WPLC\License\LicenseLifecycle;
use WPLC\License\LicenseStateMachine;
use WPLC\License\PlanChange;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Executes one due attempt and schedules the agreed retry sequence.
 */
final class RenewalProcessor {

	/**
	 * Error code of an attempt whose outcome is not known (timeout, crash).
	 * Such an attempt is never counted as a decline: the renewal waits in
	 * STATUS_RECONCILING and is only re-checked with the same gateway
	 * idempotency key (see mark_result_unknown()).
	 */
	public const RESULT_UNKNOWN = 'renewal_result_unknown';

	/** Renewal status while the result of an attempt is unknown and being checked. */
	public const STATUS_RECONCILING = 'reconciling';

	/**
	 * Seconds after the first request of an attempt during which it may be sent
	 * again with the same idempotency key. Stripe keeps keys for 24 hours; after
	 * that the same key would be a new charge, so this stays well below.
	 */
	public const RESULT_UNKNOWN_WINDOW = 43200;

	/** Seconds between two reconciliation checks of an attempt with unknown result. */
	public const RESULT_UNKNOWN_CHECK_INTERVAL = 3600;

	/** Order meta: `{attempt_count}|{unix time}` of the first request of the attempt whose result is unknown. */
	public const META_RESULT_UNKNOWN_SINCE = '_wplc_result_unknown_since';

	/** Order meta: user ID of the administrator who set an automatic renewal order to paid without a transaction ID (P-60). */
	public const META_MARKED_PAID_BY = '_wplc_marked_paid_by';

	/** Seconds after which a renewal left in `processing` counts as stuck. */
	public const STUCK_AFTER_SECONDS = 900;

	/**
	 * Renewal status of a payment that was taken but could not be applied to
	 * the license, or whose outcome stayed unknown. Never charged again
	 * automatically; an administrator decides on the "Renewals to review"
	 * screen (RenewalReview, T-154, T-137).
	 */
	public const STATUS_REVIEW = 'review_required';

	/**
	 * Former name of STATUS_REVIEW on one development line. Schema step 21
	 * moves such rows to STATUS_REVIEW; the label stays for old audit entries.
	 */
	public const LEGACY_STATUS_REVIEW = 'payment_review';

	/** Seconds until a renewal blocked by a shop-side problem is tried again. */
	public const BLOCKED_RETRY_SECONDS = 21600;

	/**
	 * Default retry days after the period start (filter `wplc_renewal_retry_days`, see retry_days()).
	 */
	public const DEFAULT_RETRY_DAYS = array( 1, 3, 7 );

	/** Seconds one daily sweep may run. */
	public const SWEEP_TIME_BUDGET = 20;

	/**
	 * Errors that are the shop's problem, not a failed payment of the customer (A-15).
	 *
	 * @var string[]
	 */
	private const SHOP_SIDE_ERRORS = array(
		'woocommerce_unavailable',
		'renewal_gateway_unavailable',
		'renewal_product_missing',
		'renewal_amount_invalid',
		'renewal_amount_mismatch',
		'renewal_order_conflict',
		'renewal_order_ambiguous',
		'renewal_order_link_failed',
		'renewal_order_lookup_failed',
		'renewal_charge_not_allowed',
		'renewal_configuration_error',
	);

	/**
	 * Renewal locks held by this request, to avoid nested lock handling.
	 *
	 * @var array<int,bool>
	 */
	private static $held_locks = array();

	/**
	 * Register Action Scheduler and WP-Cron handlers.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		RenewalOrder::register_hooks();
		add_action( 'wplc_process_renewal', array( self::class, 'process' ) );
		add_action( 'wplc/renewal_grace_ended', array( self::class, 'expire_after_grace' ) );
		add_action( 'wplc/stripe_webhook_received', array( self::class, 'queue_stripe_event' ), 10, 1 );
		add_action( 'wplc_process_stripe_webhook', array( self::class, 'process_stripe_event' ) );
		// Every way WooCommerce reports a paid order; reconciliation is idempotent.
		add_action( 'woocommerce_payment_complete', array( self::class, 'handle_paid_order' ) );
		add_action( 'woocommerce_order_status_processing', array( self::class, 'handle_paid_order' ) );
		add_action( 'woocommerce_order_status_completed', array( self::class, 'handle_paid_order' ) );
		add_action( 'wplc_reconcile_paid_renewal_order', array( self::class, 'reconcile_paid_renewal_order' ) );
		// An administrator resolves an unknown payment result by setting the renewal order to failed.
		add_action( 'woocommerce_order_status_failed', array( self::class, 'handle_failed_order' ) );
		add_action( 'wplc_resolve_failed_renewal_order', array( self::class, 'resolve_failed_renewal_order' ) );
		// Every daily task is isolated (B-34): an exception in one does not stop the others.
		add_action( 'wplc_daily_lifecycle', array( self::class, 'reconcile_stuck_renewals' ) );
		add_action( 'wplc_daily_lifecycle', array( self::class, 'requeue_overdue_renewals' ) );
		add_action( 'wplc_daily_lifecycle', array( self::class, 'repair_missing_schedules' ) );
		add_action( 'wplc_daily_lifecycle', array( self::class, 'reconcile_unbooked_paid_orders' ) );
		add_action( 'wplc_daily_lifecycle', array( self::class, 'requeue_pending_stripe_events' ) );
		add_action( 'wplc_daily_lifecycle', array( self::class, 'cleanup_stale_renewals' ) );
		add_action( 'wplc_reconcile_stuck_renewal', array( self::class, 'reconcile_stuck_renewal' ) );
		add_action( 'wplc/license_status_changed', array( self::class, 'on_license_status_changed' ), 10, 4 );
	}

	/**
	 * Whether charging and job-driven changes are paused on this site (B-01).
	 *
	 * @return bool
	 */
	public static function billing_paused(): bool {
		return LicenseLifecycle::billing_paused();
	}

	/**
	 * Run a scheduled renewal.
	 *
	 * @param int $renewal_id Renewal row ID.
	 * @return void
	 */
	public static function process( int $renewal_id ): void {
		if ( self::billing_paused() ) {
			// Nothing is charged and nothing changes; the daily sweep queues the renewal again once billing runs.
			return;
		}
		if ( ! self::lock( $renewal_id ) ) {
			self::retry_after_lock_failure( $renewal_id );
			return;
		}
		delete_transient( 'wplc_renewal_lock_fail_' . $renewal_id );
		try {
			self::process_locked( $renewal_id );
		} finally {
			self::unlock( $renewal_id );
		}
	}

	/**
	 * Queue the renewal again after its lock could not be taken (B-165).
	 *
	 * The wait grows with every consecutive failure, and from the fifth one the
	 * action `wplc/renewal_lock_unavailable` fires, so a database that refuses
	 * GET_LOCK does not loop silently every 30 seconds.
	 *
	 * @param int $renewal_id Renewal ID.
	 * @return void
	 */
	private static function retry_after_lock_failure( int $renewal_id ): void {
		$key = 'wplc_renewal_lock_fail_' . $renewal_id;
		$failures = (int) get_transient( $key ) + 1;
		set_transient( $key, $failures, DAY_IN_SECONDS );
		if ( $failures >= 5 ) {
			/**
			 * Fires when the lock of a renewal could repeatedly not be taken.
			 *
			 * @param int $renewal_id Renewal ID.
			 * @param int $failures Consecutive failures.
			 */
			do_action( 'wplc/renewal_lock_unavailable', $renewal_id, $failures );
		}
		RenewalScheduler::enqueue( $renewal_id, time() + min( HOUR_IN_SECONDS, 30 * ( 2 ** min( 7, $failures - 1 ) ) ) );
	}

	/**
	 * Execute the renewal while holding the per-renewal lock.
	 *
	 * @param int $renewal_id Renewal row ID.
	 * @return void
	 */
	private static function process_locked( int $renewal_id ): void {
		global $wpdb;

		$tables = Schema::table_names();
		$renewal = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM %i WHERE id = %d LIMIT 1", $tables['renewals'], $renewal_id ),
			ARRAY_A
		);
		if ( ! is_array( $renewal ) || ! in_array( $renewal['status'], array( 'scheduled', 'failed', 'processing', self::STATUS_RECONCILING ), true ) ) {
			return;
		}
		if ( 'processing' === $renewal['status'] ) {
			// The lock is free, so the attempt that set this status died. Its outcome is resolved
			// by the stuck check, never by charging again from here.
			self::schedule_stuck_check( $renewal_id );
			return;
		}

		$now = time();
		$start = self::utc_ts( $renewal['period_start'] ?? null );
		$grace_end = self::grace_end_ts( $renewal );
		$retry_at = empty( $renewal['retry_at'] ) ? 0 : (int) strtotime( (string) $renewal['retry_at'] . ' UTC' );
		$grace_over = $grace_end && $now >= $grace_end;

		$license = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM %i WHERE id = %d LIMIT 1", $tables['licenses'], (int) $renewal['license_id'] ),
			ARRAY_A
		);
		// The previous attempt may have charged the customer. It is only ever re-sent with the
		// same idempotency key, inside the window, and only while nothing else changed.
		$reconciling = self::is_waiting_for_result( $renewal );
		if ( ! is_array( $license ) ) {
			if ( $reconciling ) {
				self::flag_for_review( $renewal, 'license_missing' );
				return;
			}
			self::close_renewal( $renewal, 'cancelled', 'license_missing' );
			return;
		}

		if ( $reconciling ) {
			$problem = self::reconciliation_problem( $renewal, $license, $now );
			if ( '' !== $problem ) {
				self::flag_for_review( $renewal, $problem );
				return;
			}
		}

		$blocker = self::charge_blocker( $renewal, $license );
		if ( '' !== $blocker ) {
			self::close_renewal( $renewal, 'cancelled', $blocker );
			return;
		}

		// B-188: a duplicate job must not bring the next attempt forward.
		if ( ! $grace_over && in_array( $renewal['status'], array( 'failed', self::STATUS_RECONCILING ), true ) && ! $retry_at ) {
			return;
		}
		if ( ! $grace_over && $retry_at > $now + 5 ) {
			RenewalScheduler::enqueue( $renewal_id, $retry_at );
			return;
		}
		if ( $start && $now < $start ) {
			RenewalScheduler::enqueue( $renewal_id, $start );
			return;
		}

		$late_attempt = false;
		if ( $grace_over ) {
			// A-18 / B-14: a renewal that was never attempted (queue backlog, plugin inactive, shop-side
			// block) gets its attempt instead of expiring unattempted, for as long as the customer could
			// still renew manually.
			$late_attempt = 0 === (int) $renewal['attempt_count']
				&& $start
				&& $now < $start + LicenseStateMachine::MANUAL_RENEWAL_WINDOW_DAYS * DAY_IN_SECONDS;
			if ( ! $late_attempt ) {
				self::close_renewal( $renewal, 'expired', 'grace_ended' );
				self::expire_license( (int) $license['id'] );
				return;
			}
		}

		/**
		 * Filters whether a due renewal may be charged now (B-41).
		 *
		 * Return false (or a WP_Error) to postpone, e.g. while the advance notice
		 * of the charge has not been sent. A postponed renewal is not counted as
		 * a failed payment and is tried again later.
		 *
		 * @param bool|\WP_Error      $allowed Default true.
		 * @param array<string,mixed> $renewal Renewal row.
		 * @param array<string,mixed> $license License row.
		 */
		$allowed = apply_filters( 'wplc_renewal_charge_allowed', true, $renewal, $license );
		if ( true !== $allowed ) {
			self::defer_attempt( $renewal, is_wp_error( $allowed ) ? (string) $allowed->get_error_code() : 'renewal_charge_not_allowed', self::BLOCKED_RETRY_SECONDS );
			return;
		}

		$adapter = Registry::get( (string) $license['payment_gateway_id'] );
		if ( ! $adapter ) {
			// A-15: the gateway integration is gone (plugin deactivated). Not a declined card.
			self::defer_attempt( $renewal, 'renewal_gateway_unavailable', self::BLOCKED_RETRY_SECONDS );
			return;
		}
		// B-57, B-78: by token ID or by the gateway's payment method reference, in the mode of the purchase.
		// A missing payment method is the shop's problem to solve with the customer, not a declined card.
		$token_id = PaymentReference::resolve_token( $adapter, $license );
		if ( is_wp_error( $token_id ) ) {
			$reason = PaymentReference::reason( $token_id );
			PaymentReference::report_unresolved( $license, $renewal_id, $token_id, (string) ( $renewal['last_error'] ?? '' ) !== $reason );
			self::defer_attempt( $renewal, $reason, self::BLOCKED_RETRY_SECONDS );
			return;
		}

		$order = RenewalOrder::get_or_create( $renewal, $license, false );
		if ( is_wp_error( $order ) ) {
			if ( 'renewal_order_in_use' === $order->get_error_code() ) {
				// B-184: the customer is paying this order; look again after the quiet period.
				self::defer_attempt( $renewal, 'renewal_order_in_use', RenewalOrder::CUSTOMER_PAYMENT_QUIET_SECONDS, false );
				return;
			}
			self::defer_attempt( $renewal, (string) $order->get_error_code(), self::BLOCKED_RETRY_SECONDS );
			return;
		}

		// Keep the in-memory row in sync so failure handling never unlinks the order.
		$renewal['renewal_order_id'] = (int) $order->get_id();
		if ( $order->is_paid() ) {
			// Paid by the customer or by an earlier attempt whose result was lost: book it, never charge again.
			self::book_paid_order( $renewal, $license, $order, 'renewal_paid', AuditReason::RENEWAL_ALREADY_PAID );
			return;
		}
		if ( ! $order->needs_payment() ) {
			// A-14: e.g. on-hold, the customer chose an offline payment for this period. Waiting for a
			// bank transfer is not a failed payment: no attempt is counted and no dunning mail is sent.
			self::defer_attempt( $renewal, 'renewal_awaiting_customer_payment', DAY_IN_SECONDS, false );
			return;
		}
		if ( ! RenewalOrder::net_matches( $order ) ) {
			// B-182: both paths verify the same stored amount; an unexplained difference is not charged.
			self::defer_attempt( $renewal, 'renewal_amount_mismatch', self::BLOCKED_RETRY_SECONDS );
			return;
		}

		// One timestamp for the stored attempt time and the retry calculation (B-11).
		$attempt_time = time();
		$started      = gmdate( 'Y-m-d H:i:s', $attempt_time );
		$fields = array();
		if ( ! $reconciling || empty( $renewal['last_attempt_at'] ) ) {
			// A repeat of an attempt with unknown outcome keeps the time of its first try (A-114).
			$fields['last_attempt_at'] = $started;
		}
		if ( $late_attempt && ! $reconciling ) {
			// P-43: after the grace period the customer had no access; like a manual renewal (B-185)
			// the new period runs from the charge, not from the old due date. The period start, the
			// compare-and-set value on the license, stays.
			$late_end = ManualRenewal::next_paid_until( (string) $renewal['period_start'], (string) $license['billing_interval'], $attempt_time, RenewalScheduler::anchor_day( $license ), true );
			if ( self::utc_ts( $late_end ) > self::utc_ts( $renewal['period_end'] ?? null ) ) {
				$fields['period_end'] = $late_end;
			}
		}
		// P-02: the order was built without the license lock; a cancellation, revocation or switch-off
		// may have happened meanwhile. Re-check under the license lock and claim the row conditionally.
		$claim = self::claim_for_charge( $renewal, $fields );
		if ( '' !== $claim['problem'] ) {
			self::handle_lost_claim( $renewal, $claim['problem'], $claim['license'], $reconciling );
			return;
		}
		$license = (array) $claim['license'];
		$renewal = array_merge( $renewal, $fields );
		self::schedule_stuck_check( $renewal_id );
		// The key changes only after a definite failure was recorded (see attempt_idempotency_key()).
		// A reconciliation check therefore sends the key of the attempt whose result is unknown.
		$attempt_key = RenewalScheduler::attempt_idempotency_key( (string) $renewal['idempotency_key'], (int) $renewal['attempt_count'] );
		$request_at = $attempt_time;
		$result = $adapter->charge( $order, $token_id, $attempt_key );

		// B-184: the charge took time; the customer may have paid the same order in the meantime.
		$fresh = wc_get_order( (int) $order->get_id() );
		$order = $fresh instanceof \WC_Order ? $fresh : $order;
		$paid_elsewhere = $order->is_paid();

		if ( is_wp_error( $result ) || empty( $result['success'] ) ) {
			$error = is_wp_error( $result )
				? $result
				: new \WP_Error( 'renewal_payment_failed', __( 'The payment gateway did not confirm the renewal.', 'wp-license' ) );
			if ( $paid_elsewhere ) {
				self::book_paid_order( $renewal, $license, $order, 'renewal_paid', __( 'The customer paid the renewal order while the automatic charge failed', 'wp-license' ) );
				return;
			}
			$error_data = $error->get_error_data();
			$wait = is_array( $error_data ) ? absint( $error_data['wait'] ?? 0 ) : 0;
			if ( $wait && $reconciling && self::RESULT_UNKNOWN !== $error->get_error_code() ) {
				// The attempt whose result is unknown is still being processed at the gateway.
				self::mark_result_unknown( $renewal, $error, $request_at );
				return;
			}
			if ( $wait && self::RESULT_UNKNOWN !== $error->get_error_code() ) {
				// B-59, B-63: nothing failed, the adapter asks to look again later (the customer is paying
				// the order, the payment is still being processed, the gateway limits requests).
				self::update_renewal( $renewal_id, array( 'status' => $renewal['status'] ) );
				self::defer_attempt( $renewal, (string) $error->get_error_code(), $wait, false );
				return;
			}
			if ( in_array( $error->get_error_code(), self::SHOP_SIDE_ERRORS, true ) ) {
				self::update_renewal( $renewal_id, array( 'status' => $renewal['status'] ) );
				// A-16: the adapter names the cause in `reason`; the renewal row keeps it.
				$reason = is_array( $error_data ) && ! empty( $error_data['reason'] ) ? (string) $error_data['reason'] : (string) $error->get_error_code();
				if ( ! $reconciling && 'renewal_payment_method_missing' === $reason ) {
					// P-16: the gateway no longer has the card (e.g. removed at Stripe): same as a token that is gone here.
					PaymentReference::report_unresolved( $license, $renewal_id, $error, (string) ( $renewal['last_error'] ?? '' ) !== $reason );
				}
				self::defer_attempt( $renewal, $reason, self::BLOCKED_RETRY_SECONDS );
				return;
			}
			$definite = self::RESULT_UNKNOWN !== $error->get_error_code();
			if ( $definite ) {
				$order->update_status( 'failed', $error->get_error_code() );
			}
			self::fail_attempt( $renewal, $license, $error, $definite, $request_at );
			return;
		}

		$transaction_id = sanitize_text_field( (string) ( $result['transaction_id'] ?? '' ) );
		self::update_renewal( $renewal_id, array( 'gateway_attempt_id' => substr( $transaction_id, 0, 190 ) ) );
		if ( $paid_elsewhere ) {
			// Two payments for one period. The first one stays on the order; ours is reported, not hidden.
			$order->update_meta_data( '_wplc_duplicate_transaction_id', $transaction_id );
			$order->save();
			$problem = sprintf(
				/* translators: 1: transaction ID of the automatic charge, 2: transaction ID on the order. */
				__( 'The automatic charge %1$s succeeded although the order was already paid (%2$s). One of the two payments must be refunded. Refund the second payment %1$s in the payment gateway; a refund of this order in WooCommerce refunds %2$s and keeps the renewal, because the other payment still pays the period.', 'wp-license' ),
				$transaction_id,
				(string) $order->get_transaction_id()
			);
			$order->add_order_note( 'WP License Captain: ' . $problem );
			AuditLog::record( 'renewal_double_payment', 'renewal', $renewal_id, $problem, null, array( 'order_id' => (int) $order->get_id() ) );
			/** This action is documented in src/Billing/RenewalOrder.php */
			do_action( 'wplc/renewal_needs_review', (int) $order->get_id(), $renewal_id, $problem );
			self::book_paid_order( $renewal, $license, $order, 'renewal_paid', AuditReason::RENEWAL_ALREADY_PAID );
			return;
		}
		$order->payment_complete( $transaction_id );
		if ( self::withdrawn_during_charge( (int) $license['id'] ) ) {
			// P-02: the customer cancelled (or automatic renewal was switched off) while the charge was
			// in flight. The money is taken, but the renewal is not booked silently: an administrator
			// refunds the payment or books the renewal on the review screen.
			self::flag_withdrawn_during_charge( $renewal, $order );
			return;
		}
		self::finish_paid( $renewal, $license, $order, 'renewal_paid', AuditReason::RENEWAL_CONFIRMED_BY_ADAPTER );
	}

	/**
	 * Why a renewal must not be charged for this license now; empty when it may (P-02).
	 *
	 * Pure. The code is stored as the reason of the closed renewal.
	 *
	 * @param array<string,mixed> $renewal Renewal row.
	 * @param array<string,mixed> $license License row.
	 * @return string
	 */
	public static function charge_blocker( array $renewal, array $license ): string {
		$status = (string) ( $license['status'] ?? '' );
		if ( ! in_array( $status, array( 'active', 'past_due' ), true ) ) {
			return 'license_' . preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $status ) );
		}
		if ( empty( $license['renewal_enabled'] ) ) {
			return 'renewal_disabled';
		}
		if ( ! empty( $license['cancel_at_period_end'] ) ) {
			return 'customer_cancelled';
		}
		if ( (string) ( $renewal['period_start'] ?? '' ) !== (string) ( $license['paid_until'] ?? '' ) ) {
			// B-50: the term was moved (administrator, manual renewal). This row belongs to a period
			// that no longer exists and must never be charged.
			return 'period_changed';
		}

		return '';
	}

	/**
	 * Re-read license and renewal under the license lock and move the renewal to `processing` (P-02).
	 *
	 * Lock order: the caller holds the renewal lock; the license lock is taken
	 * second, as everywhere. Cancellation, revocation and switch-off write the
	 * license under the same lock, so either they come first and nothing is
	 * charged, or the row is `processing` and they leave it to the processor.
	 * The update only applies while the row still has the status it was read
	 * with, so a renewal cancelled meanwhile is never set back to `processing`.
	 *
	 * @param array<string,mixed> $renewal Renewal row as read at the start of the attempt.
	 * @param array<string,mixed> $fields Extra columns to write (last_attempt_at, period_end).
	 * @return array{problem:string,license:array<string,mixed>|null} Empty problem when claimed.
	 */
	private static function claim_for_charge( array $renewal, array $fields ): array {
		$result = LicenseLifecycle::run_locked(
			(int) $renewal['license_id'],
			static function () use ( $renewal, $fields ) {
				global $wpdb;

				$tables  = Schema::table_names();
				$license = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', $tables['licenses'], (int) $renewal['license_id'] ), ARRAY_A );
				$license = is_array( $license ) ? $license : null;
				$status  = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE id = %d LIMIT 1', $tables['renewals'], (int) $renewal['id'] ) );
				if ( $status !== (string) $renewal['status'] ) {
					return array( 'problem' => 'renewal_changed', 'license' => $license );
				}
				if ( null === $license ) {
					return array( 'problem' => 'license_missing', 'license' => null );
				}
				$blocker = self::charge_blocker( $renewal, $license );
				if ( '' !== $blocker ) {
					return array( 'problem' => $blocker, 'license' => $license );
				}
				$data    = array( 'status' => 'processing', 'retry_at' => null, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) );
				$formats = array( '%s', '%s', '%s' );
				foreach ( array( 'last_attempt_at', 'period_end' ) as $column ) {
					if ( isset( $fields[ $column ] ) ) {
						$data[ $column ] = (string) $fields[ $column ];
						$formats[]       = '%s';
					}
				}
				$claimed = $wpdb->update( $tables['renewals'], $data, array( 'id' => (int) $renewal['id'], 'status' => (string) $renewal['status'] ), $formats, array( '%d', '%s' ) );

				return array( 'problem' => 1 === (int) $claimed ? '' : 'renewal_changed', 'license' => $license );
			}
		);

		return is_array( $result ) ? $result : array( 'problem' => 'license_busy', 'license' => null );
	}

	/**
	 * Act on a renewal that could not be claimed for its charge (P-02). Nothing was charged.
	 *
	 * @param array<string,mixed>      $renewal Renewal row as read at the start of the attempt.
	 * @param string                   $problem Result of claim_for_charge().
	 * @param array<string,mixed>|null $license Fresh license row, if any.
	 * @param bool                     $reconciling Whether the renewal waits for an unknown result.
	 * @return void
	 */
	private static function handle_lost_claim( array $renewal, string $problem, ?array $license, bool $reconciling ): void {
		global $wpdb;

		$renewal_id = (int) $renewal['id'];
		if ( 'license_busy' === $problem ) {
			// Another request changes the license right now; look again shortly. Not a failed payment.
			RenewalScheduler::enqueue( $renewal_id, time() + MINUTE_IN_SECONDS );
			return;
		}
		if ( 'renewal_changed' === $problem ) {
			// Cancelled or closed by another path while the order was built. That path read the row
			// before the order was linked, so the order may still be open.
			$tables = Schema::table_names();
			$status = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE id = %d LIMIT 1', $tables['renewals'], $renewal_id ) );
			if ( in_array( $status, array( 'cancelled', 'expired' ), true ) && ! empty( $renewal['renewal_order_id'] ) ) {
				RenewalOrder::close_unpaid( (int) $renewal['renewal_order_id'], __( 'WP License Captain: this renewal is no longer due; the order is closed.', 'wp-license' ) );
			}
			return;
		}
		if ( $reconciling ) {
			$reason = null !== $license ? self::reconciliation_problem( $renewal, $license, time() ) : '';
			self::flag_for_review( $renewal, '' !== $reason ? $reason : $problem );
			return;
		}
		self::close_renewal( $renewal, 'cancelled', $problem );
	}

	/**
	 * Whether automatic renewal of the license was cancelled or switched off (P-02).
	 *
	 * Called after a successful charge of a renewal that was claimed while
	 * neither was set, so true means it happened while the charge was in flight.
	 *
	 * @param int $license_id License ID.
	 * @return bool
	 */
	private static function withdrawn_during_charge( int $license_id ): bool {
		global $wpdb;

		$tables  = Schema::table_names();
		$license = $wpdb->get_row( $wpdb->prepare( 'SELECT status, renewal_enabled, cancel_at_period_end FROM %i WHERE id = %d LIMIT 1', $tables['licenses'], $license_id ), ARRAY_A );

		return is_array( $license )
			&& in_array( (string) $license['status'], array( 'active', 'past_due' ), true )
			&& ( ! empty( $license['cancel_at_period_end'] ) || empty( $license['renewal_enabled'] ) );
	}

	/**
	 * Send a paid renewal whose automatic renewal was withdrawn during the charge to review (P-02).
	 *
	 * @param array<string,mixed> $renewal Renewal row.
	 * @param \WC_Order           $order Paid renewal order.
	 * @return void
	 */
	private static function flag_withdrawn_during_charge( array $renewal, $order ): void {
		$renewal_id = (int) $renewal['id'];
		RenewalReview::open( $renewal_id, AuditReason::RENEWAL_WITHDRAWN_DURING_CHARGE, (int) $order->get_id(), true );
		RenewalOrder::flag_for_review( $order, $renewal_id, AuditReason::RENEWAL_WITHDRAWN_DURING_CHARGE, 'renewal_paid_not_applied' );
		AuditLog::record(
			'renewal_review_required',
			'renewal',
			$renewal_id,
			AuditReason::RENEWAL_WITHDRAWN_DURING_CHARGE,
			array( 'status' => 'processing' ),
			array( 'status' => self::STATUS_REVIEW, 'order_id' => (int) $order->get_id() )
		);
	}

	/**
	 * Whether an open review case may only be resolved by an administrator.
	 *
	 * Pure. Such a case is never booked by a paid-order hook or a Stripe event:
	 * the payment is known, the decision (refund or book) is the shop's.
	 *
	 * @param array<string,mixed> $renewal Renewal row.
	 * @return bool
	 */
	public static function review_needs_decision( array $renewal ): bool {
		if ( self::STATUS_REVIEW !== (string) ( $renewal['status'] ?? '' ) ) {
			return false;
		}
		$stored = (string) ( $renewal['review_reason'] ?? '' );
		$parsed = AuditReason::parse( $stored );
		$code   = is_array( $parsed ) ? (string) $parsed['code'] : $stored;

		return in_array( $code, array( AuditReason::RENEWAL_WITHDRAWN_DURING_CHARGE, AuditReason::MARKED_PAID_WITHOUT_PAYMENT, AuditReason::UNBOOKED_STRIPE_PAYMENT ), true );
	}

	/**
	 * End a renewal that will not be charged: record why and close its unpaid order.
	 *
	 * @param array<string,mixed> $renewal Renewal row.
	 * @param string              $status 'cancelled' or 'expired'.
	 * @param string              $code Reason code (A-73).
	 * @return void
	 */
	private static function close_renewal( array $renewal, string $status, string $code ): void {
		$renewal_id = (int) $renewal['id'];
		self::update_renewal( $renewal_id, array( 'status' => $status, 'retry_at' => null, 'last_error' => $code ) );
		if ( ! empty( $renewal['renewal_order_id'] ) ) {
			RenewalOrder::close_unpaid( (int) $renewal['renewal_order_id'], __( 'WP License Captain: this renewal is no longer due; the order is closed.', 'wp-license' ) );
		}
		AuditLog::record(
			'renewal_' . $status,
			'renewal',
			$renewal_id,
			$code,
			array( 'status' => $renewal['status'] ),
			array( 'status' => $status, 'reason' => $code )
		);
	}

	/**
	 * Postpone a renewal without counting a failed payment (A-14, A-15, B-41, B-184).
	 *
	 * The license is not set past due and the customer gets no mail. Shop-side
	 * problems fire `wplc/renewal_blocked` for monitoring. A renewal waiting for
	 * an unknown payment result goes to review instead (flag_for_review()).
	 *
	 * @param array<string,mixed> $renewal Renewal row.
	 * @param string              $code Reason code.
	 * @param int                 $seconds Seconds until the next look.
	 * @param bool                $alert Whether the shop has to act.
	 * @return void
	 */
	private static function defer_attempt( array $renewal, string $code, int $seconds, bool $alert = true ): void {
		if ( self::is_waiting_for_result( $renewal ) ) {
			// During reconciliation such a problem says nothing about the earlier attempt, which may
			// have charged the customer: it goes to review instead of waiting silently.
			self::flag_for_review( $renewal, $code );
			return;
		}
		$renewal_id = (int) $renewal['id'];
		$code = substr( sanitize_key( $code ), 0, 100 );
		$next = time() + max( 60, $seconds );
		self::update_renewal(
			$renewal_id,
			array(
				'status' => 'failed' === $renewal['status'] ? 'failed' : 'scheduled',
				'retry_at' => gmdate( 'Y-m-d H:i:s', $next ),
				'last_error' => $code,
			)
		);
		RenewalScheduler::enqueue( $renewal_id, $next );
		if ( (string) ( $renewal['last_error'] ?? '' ) !== $code ) {
			// Once per cause, not on every repeat.
			AuditLog::record(
				$alert ? 'renewal_blocked' : 'renewal_waiting',
				'renewal',
				$renewal_id,
				$code,
				array( 'status' => $renewal['status'] ),
				array( 'retry_at' => gmdate( 'Y-m-d H:i:s', $next ) )
			);
		}
		if ( $alert ) {
			/**
			 * Fires when a renewal could not be attempted because of a shop-side problem.
			 *
			 * @param int    $renewal_id Renewal ID.
			 * @param string $code Reason code.
			 * @param int    $license_id License ID.
			 */
			do_action( 'wplc/renewal_blocked', $renewal_id, $code, (int) $renewal['license_id'] );
		}
	}

	/**
	 * Book an order that is already paid, by the rules of its kind.
	 *
	 * @param array<string,mixed> $renewal Renewal row.
	 * @param array<string,mixed> $license License row.
	 * @param \WC_Order           $order Paid renewal order.
	 * @param string              $audit_action Audit action for an automatic renewal.
	 * @param string              $reason Audit reason for an automatic renewal.
	 * @return void
	 */
	private static function book_paid_order( array $renewal, array $license, $order, string $audit_action, string $reason ): void {
		if ( RenewalOrder::is_manual( $order ) ) {
			ManualRenewal::complete_locked( $order, $renewal, $license );
			return;
		}
		self::finish_paid( $renewal, $license, $order, $audit_action, $reason );
	}

	/**
	 * Book a paid automatic renewal: license period, renewal row, next period.
	 *
	 * Order of the steps (B-32, A-13): the license is extended first, with a
	 * compare-and-set on its paid_until (B-50). Only then the renewal becomes
	 * `paid`. A run that dies in between leaves the renewal open with a paid
	 * order; the next reconciliation finds the license already extended and
	 * only finishes the bookkeeping. A payment that cannot be applied (license
	 * suspended, term moved) is never reported as a success: the renewal goes
	 * to STATUS_REVIEW and the order is flagged.
	 *
	 * The next period is scheduled before any listener runs (B-31).
	 *
	 * @param array<string,mixed> $renewal Renewal row.
	 * @param array<string,mixed> $license License row.
	 * @param \WC_Order           $order Paid renewal order.
	 * @param string              $audit_action Audit action.
	 * @param string              $reason Audit reason.
	 * @param int                 $actor_user_id User who confirmed the payment, zero for the system.
	 * @return bool True when the renewal is booked.
	 */
	private static function finish_paid( array $renewal, array $license, $order, string $audit_action, string $reason, int $actor_user_id = 0 ): bool {
		global $wpdb;

		$renewal_id = (int) $renewal['id'];
		$license_id = (int) $license['id'];
		$order_id = (int) $order->get_id();
		$tables = Schema::table_names();
		// Never trust the row read before the charge: the term may have been changed meanwhile.
		$current = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE id = %d LIMIT 1", $tables['licenses'], $license_id ), ARRAY_A );
		$current = is_array( $current ) ? $current : $license;

		$old_status = false;
		$already_applied = (string) $current['paid_until'] === (string) $renewal['period_end']
			&& (string) $renewal['period_end'] !== (string) $renewal['period_start']
			&& 'active' === $current['status'];
		if ( $already_applied ) {
			LicenseLifecycle::sync_entitlements( $license_id );
		} else {
			$old_status = LicenseLifecycle::apply_automatic_renewal( $license_id, (string) $renewal['period_start'], (string) $renewal['period_end'], $order_id );
			if ( false === $old_status ) {
				$problem = (string) $current['paid_until'] !== (string) $renewal['period_start']
					? __( 'The license term was changed while the renewal was being paid; the payment was not applied', 'wp-license' )
					: sprintf(
						/* translators: %s: license status. */
						__( 'The license could not be extended because its status changed to "%s" while the payment was running; the payment was not applied. Refund the payment, or reactivate the license and extend its term by hand', 'wp-license' ),
						(string) $current['status']
					);
				self::flag_paid_but_not_booked( $renewal, $current, $order, $problem );
				return false;
			}
		}

		self::update_renewal( $renewal_id, array( 'status' => 'paid', 'retry_at' => null, 'renewal_order_id' => $order_id, 'last_error' => '' ) );
		// A plan change scheduled for this renewal (T-110) takes effect now; idempotent on a repeat.
		PlanChange::apply_pending_after_renewal( $license_id, $order );
		try {
			RenewalScheduler::schedule_next( $license_id );
		} catch ( \Throwable $error ) {
			// repair_missing_schedules() creates the period on the next daily run.
			LicenseLifecycle::log_error( 'schedule_next after renewal ' . $renewal_id . ': ' . $error->getMessage() );
		}
		AuditLog::record(
			$audit_action,
			'renewal',
			$renewal_id,
			$reason,
			array( 'status' => $renewal['status'] ),
			array( 'status' => 'paid', 'order_id' => $order_id ),
			$actor_user_id
		);
		$order->add_order_note(
			sprintf(
				/* translators: 1: license ID, 2: new paid-through date (UTC). */
				__( 'WP License Captain renewed license #%1$d until %2$s (UTC).', 'wp-license' ),
				$license_id,
				(string) $renewal['period_end']
			)
		);
		if ( false !== $old_status ) {
			LicenseLifecycle::announce_renewal( $license_id, (string) $old_status );
		}

		return true;
	}

	/**
	 * Days after the period start on which the charge is repeated.
	 *
	 * @return int[]
	 */
	public static function retry_days(): array {
		/**
		 * Filters the retry schedule of failed renewal charges (A-135).
		 *
		 * @param int[] $days Days after the period start, ascending. Default 1, 3 and 7.
		 */
		$days = (array) apply_filters( 'wplc_renewal_retry_days', self::DEFAULT_RETRY_DAYS );
		$days = array_values( array_filter( array_map( 'absint', $days ) ) );
		sort( $days );

		return $days;
	}

	/**
	 * Time of the retry after the given number of definite failures (B-11).
	 *
	 * Pure. The retry is due on its day after the period start, but never
	 * sooner after the failed attempt than the gap between two retry days. After
	 * a backlog (plugin inactive, queue stopped, restore) the attempts therefore
	 * keep their distance instead of being used up within minutes.
	 *
	 * @param int   $attempt Definite failures so far, including the current one (>= 1).
	 * @param int   $period_start Unix timestamp of the period start.
	 * @param int   $now Unix timestamp of the failed attempt.
	 * @param int[] $days Retry days after the period start.
	 * @return int Unix timestamp, zero when no retry is left.
	 */
	public static function next_retry_at( int $attempt, int $period_start, int $now, array $days = self::DEFAULT_RETRY_DAYS ): int {
		if ( $attempt < 1 || ! isset( $days[ $attempt - 1 ] ) || ! $period_start ) {
			return 0;
		}
		$previous_day = $attempt >= 2 ? (int) $days[ $attempt - 2 ] : 0;
		$gap_days = max( 1, (int) $days[ $attempt - 1 ] - $previous_day );

		return max( $period_start + (int) $days[ $attempt - 1 ] * 86400, $now + $gap_days * 86400 );
	}

	/**
	 * Mark a renewal attempt failed, schedule the retries or end grace.
	 *
	 * Only a definite failure counts as an attempt, makes the license past_due
	 * (which sends the "payment failed" email) and lets the next attempt use a
	 * new idempotency key. An unknown outcome (timeout, crash) is handed to
	 * mark_result_unknown() and changes neither the counter nor the license
	 * (T-136, A-114).
	 *
	 * B-62: an error whose data contains `retryable => false` (lost or stolen
	 * card, do-not-retry advice) stops the retries; the customer has to pay.
	 *
	 * @param array<string,mixed> $renewal Renewal row.
	 * @param array<string,mixed> $license License row.
	 * @param \WP_Error $error Failure.
	 * @param bool $definite False when the payment outcome is unknown.
	 * @param int  $request_at Unix time the gateway request of this attempt was sent; zero for now.
	 * @return void
	 */
	private static function fail_attempt( array $renewal, array $license, \WP_Error $error, bool $definite = true, int $request_at = 0 ): void {
		$renewal_id = (int) $renewal['id'];
		$code = (string) $error->get_error_code();
		$stored_code = substr( sanitize_key( $code ), 0, 100 );
		$attempt = (int) $renewal['attempt_count'] + ( $definite ? 1 : 0 );
		$grace_end = self::grace_end_ts( $renewal );
		$data = $error->get_error_data();
		$stop_retries = 'renewal_manual_action_required' === $code || ( is_array( $data ) && array_key_exists( 'retryable', $data ) && false === $data['retryable'] );

		if ( $definite && $stop_retries ) {
			self::update_renewal(
				$renewal_id,
				array(
					'status' => 'manual_action_required',
					'retry_at' => null,
					'attempt_count' => $attempt,
					'last_error' => $stored_code,
				)
			);
			LicenseLifecycle::mark_past_due( (int) $license['id'], $code );
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( absint( $renewal['renewal_order_id'] ) ) : false;
			if ( $order ) {
				$order->add_order_note(
					'renewal_manual_action_required' === $code
						? __( 'This renewal requires customer authentication. Automatic retries have been stopped.', 'wp-license' )
						: __( 'The payment method was declined for good. Automatic retries have been stopped; the customer has to pay this order.', 'wp-license' )
				);
			}
			AuditLog::record( 'renewal_manual_action_required', 'renewal', $renewal_id, $code, array( 'status' => (string) $renewal['status'], 'attempt_count' => (int) $renewal['attempt_count'] ), array( 'attempt_count' => $attempt, 'status' => 'manual_action_required' ) );
			if ( $grace_end ) {
				self::schedule_grace_end( (int) $license['id'], $grace_end );
			}
			return;
		}

		if ( ! $definite ) {
			self::mark_result_unknown( $renewal, $error, $request_at ? $request_at : time() );
			return;
		}

		$period_start = (int) strtotime( (string) $renewal['period_start'] . ' UTC' );
		$next = self::next_retry_at( $attempt, $period_start, $request_at ? $request_at : time(), self::retry_days() );
		$retry = $next && $grace_end && $next < $grace_end;
		// B-190: an exhausted renewal waits for the end of grace with a date, not with an open end.
		$wait_until = $retry ? $next : max( time() + 30, (int) $grace_end );
		self::update_renewal(
			$renewal_id,
			array(
				'status' => 'failed',
				'retry_at' => gmdate( 'Y-m-d H:i:s', $wait_until ),
				'attempt_count' => $attempt,
				'last_error' => $stored_code,
			)
		);

		LicenseLifecycle::mark_past_due( (int) $license['id'], $code );
		AuditLog::record(
			'renewal_failed',
			'renewal',
			$renewal_id,
			$code,
			array( 'status' => (string) $renewal['status'], 'attempt_count' => (int) $renewal['attempt_count'] ),
			array( 'status' => 'failed', 'attempt_count' => $attempt, 'error_code' => $code, 'retry_at' => gmdate( 'Y-m-d H:i:s', $wait_until ) )
		);

		if ( $retry ) {
			RenewalScheduler::enqueue( $renewal_id, $next );
			return;
		}

		self::schedule_grace_end( (int) $license['id'], $grace_end ? $grace_end : time() );
	}

	/**
	 * Whether a renewal waits for the result of an attempt whose outcome is unknown.
	 *
	 * Status STATUS_RECONCILING; also a `failed` row whose last error is
	 * RESULT_UNKNOWN (written that way by an earlier development version).
	 *
	 * @param array<string,mixed> $renewal Renewal row.
	 * @return bool
	 */
	private static function is_waiting_for_result( array $renewal ): bool {
		return self::STATUS_RECONCILING === (string) $renewal['status']
			|| ( 'failed' === (string) $renewal['status'] && self::RESULT_UNKNOWN === (string) ( $renewal['last_error'] ?? '' ) );
	}

	/**
	 * Keep a renewal whose payment result is unknown in STATUS_RECONCILING.
	 *
	 * The charge may have gone through, so this is not a decline: the attempt
	 * counter (and with it the idempotency key) stays, the order is not set to
	 * failed, the license keeps its status (no past_due, no "payment failed"
	 * email) and no new key is ever used. A check is queued every hour; it sends
	 * the same key, so the gateway replays the first result or, if the first
	 * request never arrived, makes the one and only charge. A Stripe success
	 * webhook, a Stripe failure webhook of this attempt or a paid order ends the
	 * wait earlier. When the result is still unknown RESULT_UNKNOWN_WINDOW after
	 * the first request, or at the end of the grace period, the renewal goes to
	 * review and nothing is charged again.
	 *
	 * @param array<string,mixed> $renewal Renewal row as it was before the attempt.
	 * @param \WP_Error           $error Gateway or reconciliation error.
	 * @param int                 $request_at Unix time the gateway request of this attempt was sent.
	 * @return void
	 */
	private static function mark_result_unknown( array $renewal, \WP_Error $error, int $request_at ): void {
		$renewal_id = (int) $renewal['id'];
		$attempt = (int) $renewal['attempt_count'];
		$order = self::renewal_order( $renewal );
		if ( ! $order ) {
			self::flag_for_review( $renewal, 'renewal_order_missing' );
			return;
		}
		// A repeat of an attempt with unknown outcome keeps the time of its first try (A-114).
		$first_try = self::is_waiting_for_result( $renewal ) && ! empty( $renewal['last_attempt_at'] ) ? (int) strtotime( (string) $renewal['last_attempt_at'] . ' UTC' ) : 0;
		if ( $first_try > 0 && $first_try < $request_at ) {
			$request_at = $first_try;
		}
		$since = self::unknown_since_from_meta( (string) $order->get_meta( self::META_RESULT_UNKNOWN_SINCE, true ), $attempt );
		$first = 0 === $since;
		if ( $first || $request_at < $since ) {
			$since = $first ? $request_at : min( $since, $request_at );
			$order->update_meta_data( self::META_RESULT_UNKNOWN_SINCE, $attempt . '|' . $since );
			if ( $first ) {
				$order->add_order_note( __( 'The payment gateway did not return a definite result for this renewal payment; the payment may have been taken. The renewal is checked again with the same idempotency key and is not charged a second time. The customer was not told that the payment failed.', 'wp-license' ) );
			}
			$order->save();
		}

		$grace_end = self::grace_end_ts( $renewal );
		$next = self::next_reconciliation_check( time(), $since, $grace_end ? (int) $grace_end : 0 );
		if ( ! $next ) {
			self::flag_for_review( $renewal, 'result_unknown_timeout' );
			return;
		}
		$retry_at = gmdate( 'Y-m-d H:i:s', $next );
		self::update_renewal(
			$renewal_id,
			array(
				'status' => self::STATUS_RECONCILING,
				'retry_at' => $retry_at,
				'attempt_count' => $attempt,
				'last_error' => substr( sanitize_key( (string) $error->get_error_code() ), 0, 100 ),
			)
		);
		if ( $first ) {
			AuditLog::record(
				'renewal_result_unknown',
				'renewal',
				$renewal_id,
				$error->get_error_code(),
				array( 'status' => (string) $renewal['status'], 'attempt_count' => $attempt ),
				array( 'status' => self::STATUS_RECONCILING, 'attempt_count' => $attempt, 'error_code' => $error->get_error_code(), 'retry_at' => $retry_at )
			);
		}
		RenewalScheduler::enqueue( $renewal_id, $next );
	}

	/**
	 * Time of the next reconciliation check, or zero when the wait is over.
	 *
	 * Pure. The wait ends RESULT_UNKNOWN_WINDOW after the first request or at
	 * the end of the grace period, whichever is earlier.
	 *
	 * @param int $now Unix time.
	 * @param int $since Unix time of the first request of the attempt; zero when unknown.
	 * @param int $grace_end Unix time the grace period ends; zero when unknown.
	 * @return int
	 */
	public static function next_reconciliation_check( int $now, int $since, int $grace_end ): int {
		if ( $since <= 0 ) {
			return 0;
		}
		$deadline = $since + self::RESULT_UNKNOWN_WINDOW;
		if ( $grace_end > 0 ) {
			$deadline = min( $deadline, $grace_end );
		}
		if ( $now >= $deadline ) {
			return 0;
		}

		return min( $now + self::RESULT_UNKNOWN_CHECK_INTERVAL, $deadline );
	}

	/**
	 * Unix time stored in META_RESULT_UNKNOWN_SINCE for this attempt, or zero.
	 *
	 * Pure. A value written for another attempt is ignored.
	 *
	 * @param string $meta Stored value `{attempt_count}|{unix time}`.
	 * @param int    $attempt_count Attempt counter of the renewal.
	 * @return int
	 */
	public static function unknown_since_from_meta( string $meta, int $attempt_count ): int {
		if ( ! preg_match( '/^(\d+)\|(\d+)$/', $meta, $parts ) || (int) $parts[1] !== $attempt_count ) {
			return 0;
		}

		return (int) $parts[2];
	}

	/**
	 * Why a renewal waiting for an unknown result must not be re-checked; empty when it may.
	 *
	 * Re-sending is only safe with the same key inside the window, and only
	 * while the customer still wants the renewal: if the first request never
	 * reached the gateway, the re-check is the charge.
	 *
	 * @param array<string,mixed> $renewal Renewal row.
	 * @param array<string,mixed> $license License row.
	 * @param int                 $now Unix time.
	 * @return string
	 */
	private static function reconciliation_problem( array $renewal, array $license, int $now ): string {
		if ( ! in_array( $license['status'], array( 'active', 'past_due' ), true ) ) {
			return 'license_not_renewable';
		}
		if ( ! empty( $license['cancel_at_period_end'] ) || empty( $license['renewal_enabled'] ) ) {
			return 'renewal_cancelled';
		}
		if ( (string) $renewal['period_start'] !== (string) $license['paid_until'] ) {
			return AuditReason::LICENSE_CHANGED_WHILE_UNKNOWN;
		}
		$order = self::renewal_order( $renewal );
		if ( ! $order || ! RenewalOrder::matches( $order, $renewal, $license ) ) {
			return 'renewal_order_missing';
		}
		if ( $order->is_paid() ) {
			// Booked by the normal path.
			return '';
		}
		if ( ! $order->needs_payment() ) {
			return 'renewal_order_closed';
		}
		$since = self::unknown_since_from_meta( (string) $order->get_meta( self::META_RESULT_UNKNOWN_SINCE, true ), (int) $renewal['attempt_count'] );
		if ( 0 === $since && ! empty( $renewal['last_attempt_at'] ) ) {
			$since = (int) strtotime( (string) $renewal['last_attempt_at'] . ' UTC' );
		}
		$grace_end = self::grace_end_ts( $renewal );
		if ( ! self::next_reconciliation_check( $now, $since, $grace_end ? (int) $grace_end : 0 ) ) {
			return 'result_unknown_timeout';
		}

		return '';
	}

	/**
	 * Stop all automatic attempts and ask an administrator to check the payment.
	 *
	 * The license is left as it is. The case appears on the "Renewals to review"
	 * screen; an order note says how to resolve it: mark the order paid (books
	 * the renewal) or failed (counts a decline and resumes the retries with a
	 * new key, see resolve_failed_renewal_order()). Fires
	 * `wplc/renewal_needs_review` for monitoring.
	 *
	 * @param array<string,mixed> $renewal Renewal row.
	 * @param string              $reason Machine-readable reason.
	 * @return void
	 */
	private static function flag_for_review( array $renewal, string $reason ): void {
		$renewal_id = (int) $renewal['id'];
		$opened = RenewalReview::open( $renewal_id, sanitize_key( $reason ) );
		$order = self::renewal_order( $renewal );
		if ( $order ) {
			$order->add_order_note( __( 'The result of the automatic renewal payment is still unknown and automatic attempts have stopped. Check the payment in the payment gateway: if it was taken, set this order to Processing or Completed to book the renewal; if it was not, set it to Failed to resume the automatic retries.', 'wp-license' ) );
		}
		AuditLog::record(
			'renewal_review_required',
			'renewal',
			$renewal_id,
			sanitize_key( $reason ),
			array( 'status' => (string) $renewal['status'] ),
			array( 'status' => self::STATUS_REVIEW, 'order_id' => $order ? (int) $order->get_id() : 0 )
		);
		if ( $opened ) {
			/** This action is documented in src/Billing/RenewalOrder.php */
			do_action( 'wplc/renewal_needs_review', $order ? (int) $order->get_id() : 0, $renewal_id, sanitize_key( $reason ) );
		}
	}

	/**
	 * Send a paid renewal whose license could not be extended to review.
	 *
	 * Happens when the license status or term changed while the gateway was
	 * charging (suspension, refund of the original order, a term moved by an
	 * administrator). The order stays paid, the license keeps its status and
	 * period, and the renewal row is not `paid`. An administrator refunds the
	 * payment or books the renewal on the review screen.
	 *
	 * @param array<string,mixed> $renewal Renewal row as read before the charge.
	 * @param array<string,mixed> $current Current license row.
	 * @param \WC_Order           $order Paid renewal order.
	 * @param string              $problem Readable reason for the order note.
	 * @return void
	 */
	private static function flag_paid_but_not_booked( array $renewal, array $current, $order, string $problem ): void {
		$renewal_id = (int) $renewal['id'];
		// The order is paid now, whatever opened an earlier case: this is the reason to show.
		RenewalReview::open( $renewal_id, AuditReason::LICENSE_CHANGED_DURING_CHARGE, (int) $order->get_id(), true );
		if ( self::STATUS_REVIEW === (string) $renewal['status'] ) {
			// A repeated paid-order hook while the license still cannot be extended: already reported.
			return;
		}
		RenewalOrder::flag_for_review( $order, $renewal_id, $problem, 'renewal_paid_not_applied' );
		AuditLog::record(
			'renewal_review_required',
			'renewal',
			$renewal_id,
			AuditReason::LICENSE_CHANGED_DURING_CHARGE,
			array( 'status' => (string) $renewal['status'] ),
			array( 'status' => self::STATUS_REVIEW, 'license_status' => (string) $current['status'], 'order_id' => (int) $order->get_id() )
		);
	}

	/**
	 * The renewal order linked to a renewal row, if it exists.
	 *
	 * @param array<string,mixed> $renewal Renewal row.
	 * @return \WC_Order|null
	 */
	private static function renewal_order( array $renewal ) {
		$order_id = absint( $renewal['renewal_order_id'] ?? 0 );
		$order = $order_id && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;

		return $order instanceof \WC_Order ? $order : null;
	}

	/**
	 * An administrator set a renewal order to failed: queue the resolution.
	 *
	 * Only renewals waiting for an unknown result are affected. A decline
	 * recorded by process_locked() sets the order to failed while the renewal is
	 * still `processing`, so it is not picked up here.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public static function handle_failed_order( $order_id ): void {
		global $wpdb;

		$order_id = absint( $order_id );
		$order = $order_id && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof \WC_Order || RenewalOrder::is_manual( $order ) ) {
			return;
		}
		$renewal_id = absint( $order->get_meta( RenewalOrder::META_RENEWAL, true ) );
		if ( ! $renewal_id ) {
			return;
		}
		$tables = Schema::table_names();
		$status = (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM %i WHERE id = %d LIMIT 1", $tables['renewals'], $renewal_id ) );
		if ( ! in_array( $status, array( self::STATUS_RECONCILING, self::STATUS_REVIEW ), true ) ) {
			return;
		}
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			\WPLC\Compatibility\Requirements::enqueue_async_action( 'wplc_resolve_failed_renewal_order', array( $order_id ), 'wplc' );
			return;
		}
		if ( ! wp_next_scheduled( 'wplc_resolve_failed_renewal_order', array( $order_id ) ) ) {
			wp_schedule_single_event( time() + 15, 'wplc_resolve_failed_renewal_order', array( $order_id ) );
		}
	}

	/**
	 * Record a renewal order set to failed by an administrator as a definite decline.
	 *
	 * Only for a renewal in STATUS_RECONCILING or STATUS_REVIEW whose order is
	 * still failed. The attempt is counted, the license becomes past_due and the
	 * next retry uses a new idempotency key, as after any decline.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public static function resolve_failed_renewal_order( $order_id ): void {
		global $wpdb;

		$order_id = absint( $order_id );
		$order = $order_id && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof \WC_Order || 'failed' !== $order->get_status() || RenewalOrder::is_manual( $order ) ) {
			return;
		}
		$renewal_id = absint( $order->get_meta( RenewalOrder::META_RENEWAL, true ) );
		if ( ! $renewal_id ) {
			return;
		}
		if ( ! self::lock( $renewal_id ) ) {
			if ( ! wp_next_scheduled( 'wplc_resolve_failed_renewal_order', array( $order_id ) ) ) {
				wp_schedule_single_event( time() + 30, 'wplc_resolve_failed_renewal_order', array( $order_id ) );
			}
			return;
		}
		try {
			$tables = Schema::table_names();
			$renewal = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE id = %d LIMIT 1", $tables['renewals'], $renewal_id ), ARRAY_A );
			$license = is_array( $renewal ) ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE id = %d LIMIT 1", $tables['licenses'], (int) $renewal['license_id'] ), ARRAY_A ) : null;
			if (
				! is_array( $renewal )
				|| ! is_array( $license )
				|| ! in_array( $renewal['status'], array( self::STATUS_RECONCILING, self::STATUS_REVIEW ), true )
				|| (int) $renewal['renewal_order_id'] !== $order_id
				|| ! RenewalOrder::matches( $order, $renewal, $license )
			) {
				return;
			}
			self::resolve_as_declined_locked( $renewal, $license, AuditReason::PAYMENT_NOT_TAKEN );
		} finally {
			self::unlock( $renewal_id );
		}
	}

	/**
	 * Record the attempt of a renewal whose result was unknown as a definite decline.
	 *
	 * The caller holds the per-renewal lock and has checked that the renewal is
	 * in STATUS_RECONCILING or STATUS_REVIEW and its order is not paid.
	 *
	 * @param array<string,mixed> $renewal Renewal row.
	 * @param array<string,mixed> $license License row.
	 * @param string              $reason Stored audit reason.
	 * @param int                 $actor_user_id Administrator, zero for the order-status path.
	 * @return void
	 */
	public static function resolve_as_declined_locked( array $renewal, array $license, string $reason, int $actor_user_id = 0 ): void {
		AuditLog::record(
			'renewal_result_resolved',
			'renewal',
			(int) $renewal['id'],
			$reason,
			array( 'status' => (string) $renewal['status'] ),
			array( 'outcome' => 'failed' ),
			$actor_user_id
		);
		self::fail_attempt( $renewal, $license, new \WP_Error( 'renewal_payment_not_taken', __( 'The renewal order was set to failed after its payment result was checked.', 'wp-license' ) ) );
	}

	/**
	 * Whether a verified failure event of the current attempt resolves an unknown result.
	 *
	 * Only a renewal waiting for the result of exactly this attempt (status
	 * `reconciling`, or `review_required` whose order still records the unknown
	 * result of this attempt) with an unpaid, failable automatic order. The
	 * caller has matched IDs, amount and currency and the attempt counter.
	 *
	 * @param array<string,mixed> $renewal Renewal row.
	 * @param \WC_Order           $order Renewal order.
	 * @param array<string,mixed> $payload Minimized event payload.
	 * @return bool
	 */
	private static function stripe_failure_resolves( array $renewal, $order, array $payload ): bool {
		return in_array( (string) $renewal['status'], array( self::STATUS_RECONCILING, self::STATUS_REVIEW ), true )
			// Stripe moves a PaymentIntent back to requires_payment_method when the payment fails.
			&& 'requires_payment_method' === (string) ( $payload['payment_status'] ?? '' )
			&& ! $order->is_paid()
			&& $order->needs_payment()
			&& ! RenewalOrder::is_manual( $order )
			&& 0 < self::unknown_since_from_meta( (string) $order->get_meta( self::META_RESULT_UNKNOWN_SINCE, true ), (int) $renewal['attempt_count'] );
	}

	/**
	 * Schedule the expiry check for the end of the renewal grace period.
	 *
	 * @param int $license_id License ID.
	 * @param int $timestamp Grace end.
	 * @return void
	 */
	private static function schedule_grace_end( int $license_id, int $timestamp ): void {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			\WPLC\Compatibility\Requirements::schedule_single_action( max( time() + 30, $timestamp ), 'wplc/renewal_grace_ended', array( $license_id ), 'wplc' );
		} else {
			wp_schedule_single_event( max( time() + 30, $timestamp ), 'wplc/renewal_grace_ended', array( $license_id ) );
		}
	}

	/**
	 * Expire a license once its renewal grace period ends.
	 *
	 * @param int $license_id License ID.
	 * @return void
	 */
	public static function expire_after_grace( int $license_id ): void {
		self::expire_license( $license_id );
	}

	/**
	 * Expire a license through the lifecycle rules.
	 *
	 * @param int $license_id License ID.
	 * @return void
	 */
	private static function expire_license( int $license_id ): void {
		LicenseLifecycle::expire_after_grace( $license_id );
	}

	/**
	 * Schedule the period that follows after the term or status was changed.
	 *
	 * Covers a new paid_until set by an administrator and (A-17) a license that
	 * became active again: its renewal was cancelled while it was suspended.
	 *
	 * @param int    $license_id License ID.
	 * @param string $old_status Previous status.
	 * @param string $new_status New status.
	 * @param string $action Lifecycle action.
	 * @return void
	 */
	public static function on_license_status_changed( $license_id, $old_status = '', $new_status = '', $action = '' ): void {
		if ( 'active' === $new_status && in_array( $action, array( 'admin_extend', 'admin_status_change', 'admin_restore' ), true ) ) {
			RenewalScheduler::schedule_next( (int) $license_id );
		}
	}

	/** Queue verified webhook reconciliation after the inbox has committed. */
	public static function queue_stripe_event( int $inbox_id ): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			\WPLC\Compatibility\Requirements::enqueue_async_action( 'wplc_process_stripe_webhook', array( $inbox_id ), 'wplc' );
			return;
		}
		if ( ! wp_next_scheduled( 'wplc_process_stripe_webhook', array( $inbox_id ) ) ) {
			wp_schedule_single_event( time() + 15, 'wplc_process_stripe_webhook', array( $inbox_id ) );
		}
	}

	/**
	 * Single entry point for paid WooCommerce orders.
	 *
	 * Fires for woocommerce_payment_complete and the processing/completed
	 * status changes, possibly several times per order. Manual renewals are
	 * booked immediately so the customer sees the new period on return;
	 * everything else is reconciled asynchronously.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public static function handle_paid_order( $order_id ): void {
		$order_id = absint( $order_id );
		$order = $order_id && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$renewal_id = absint( $order->get_meta( RenewalOrder::META_RENEWAL, true ) );
		if ( ! $renewal_id ) {
			return;
		}
		if ( RenewalOrder::is_manual( $order ) && empty( self::$held_locks[ $renewal_id ] ) ) {
			self::reconcile_paid_renewal_order( $order_id );
			return;
		}
		if (
			empty( self::$held_locks[ $renewal_id ] )
			&& '' === (string) $order->get_transaction_id()
			&& '' === (string) $order->get_meta( self::META_MARKED_PAID_BY, true )
			&& get_current_user_id()
			&& current_user_can( 'manage_woocommerce' )
		) {
			// P-60: a status change by hand, without a payment reference. Remember who made it; the
			// asynchronous booking runs without a user.
			$order->update_meta_data( self::META_MARKED_PAID_BY, (string) get_current_user_id() );
			$order->save();
		}
		self::queue_paid_order_reconciliation( $order_id );
	}

	/** Queue reconciliation of a paid renewal order. */
	public static function queue_paid_order_reconciliation( int $order_id ): void {
		if ( ! $order_id ) {
			return;
		}
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			\WPLC\Compatibility\Requirements::enqueue_async_action( 'wplc_reconcile_paid_renewal_order', array( $order_id ), 'wplc' );
			return;
		}
		if ( ! wp_next_scheduled( 'wplc_reconcile_paid_renewal_order', array( $order_id ) ) ) {
			wp_schedule_single_event( time() + 15, 'wplc_reconcile_paid_renewal_order', array( $order_id ) );
		}
	}

	/** Reconcile a durable Stripe event using renewal, license, order, amount, and currency linkage. */
	public static function process_stripe_event( int $inbox_id ): void {
		global $wpdb;
		$tables = Schema::table_names();
		$event = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM %i WHERE id = %d AND source = %s LIMIT 1", $tables['event_inbox'], $inbox_id, 'stripe' ),
			ARRAY_A
		);
		if ( ! is_array( $event ) || 'pending_reconciliation' !== $event['outcome'] ) {
			return;
		}
		$payload = json_decode( (string) $event['payload_json'], true );
		if ( is_array( $payload ) && 'charge.dispute.created' === $event['event_type'] ) {
			self::process_dispute_event( $inbox_id, $payload );
			return;
		}
		if ( is_array( $payload ) && StripeEventHandler::handles( (string) $event['event_type'] ) ) {
			// B-148: dispute follow-ups never change a license; a fraud warning only switches automatic renewal off (P-58).
			self::finish_stripe_event( $inbox_id, StripeEventHandler::handle( (string) $event['event_type'], $payload ) );
			return;
		}
		if ( ! is_array( $payload ) || empty( $payload['renewal_id'] ) ) {
			self::finish_stripe_event( $inbox_id, 'invalid_payload' );
			return;
		}
		$renewal_id = absint( $payload['renewal_id'] );
		if ( ! self::lock( $renewal_id ) ) {
			if ( ! wp_next_scheduled( 'wplc_process_stripe_webhook', array( $inbox_id ) ) ) {
				wp_schedule_single_event( time() + 30, 'wplc_process_stripe_webhook', array( $inbox_id ) );
			}
			return;
		}
		try {
			self::reconcile_stripe_event_locked( $inbox_id, $event, $payload );
		} finally {
			self::unlock( $renewal_id );
		}
	}

	/**
	 * Suspend the licenses paid by a disputed order.
	 *
	 * @param int                 $inbox_id Inbox row ID.
	 * @param array<string,mixed> $payload Minimized dispute payload.
	 * @return void
	 */
	private static function process_dispute_event( int $inbox_id, array $payload ): void {
		$order_id = absint( $payload['order_id'] ?? 0 );
		$reason = AuditReason::encode(
			AuditReason::STRIPE_DISPUTE,
			array(
				'dispute' => sanitize_text_field( (string) ( $payload['dispute_id'] ?? '' ) ),
				'reason'  => sanitize_key( (string) ( $payload['reason'] ?? 'unknown' ) ),
			)
		);
		$count = LicenseLifecycle::chargeback_for_order( $order_id, $reason );
		AuditLog::record( 'chargeback_received', 'order', $order_id, $reason, null, array( 'licenses' => $count ) );
		self::finish_stripe_event( $inbox_id, $count ? 'processed' : 'ignored' );
	}

	/** Reconcile one event while holding the per-renewal lock. */
	private static function reconcile_stripe_event_locked( int $inbox_id, array $event, array $payload ): void {
		global $wpdb;
		$tables = Schema::table_names();
		$renewal = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM %i WHERE id = %d LIMIT 1", $tables['renewals'], absint( $payload['renewal_id'] ) ),
			ARRAY_A
		);
		$license = is_array( $renewal ) ? $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM %i WHERE id = %d LIMIT 1", $tables['licenses'], (int) $renewal['license_id'] ),
			ARRAY_A
		) : null;
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( absint( $payload['order_id'] ?? 0 ) ) : false;
		if (
			! is_array( $renewal )
			|| ! is_array( $license )
			|| (int) $renewal['license_id'] !== absint( $payload['license_id'] ?? 0 )
			|| (int) $renewal['renewal_order_id'] !== absint( $payload['order_id'] ?? 0 )
			|| 'stripe' !== $license['payment_gateway_id']
			|| ! $order instanceof \WC_Order
			|| 'stripe' !== $order->get_payment_method()
			|| (int) $order->get_meta( '_wplc_renewal_id', true ) !== (int) $renewal['id']
			|| (int) $order->get_meta( '_wplc_renewal_for_license', true ) !== (int) $license['id']
		) {
			self::finish_stripe_event( $inbox_id, 'manual_review' );
			AuditLog::record( 'stripe_webhook_mismatch', 'webhook', $inbox_id, AuditReason::STRIPE_METADATA_MISMATCH );
			// The link is not trusted, so no case is opened on the renewal; the money is still reported.
			self::report_unbooked_payment( $event, $payload, null, $order instanceof \WC_Order ? $order : null, AuditReason::STRIPE_METADATA_MISMATCH );
			return;
		}

		$intent_id = sanitize_text_field( (string) ( $payload['payment_intent_id'] ?? '' ) );
		$paid_by = (string) $order->get_meta( '_stripe_intent_id', true );
		if ( 'payment_intent.succeeded' === $event['event_type'] && $order->is_paid() && 0 === strpos( $paid_by, 'pi_' ) && $paid_by !== $intent_id ) {
			// B-59: the order was paid by another PaymentIntent (the customer on the pay page) and this
			// one succeeded as well. Two payments for one period: never "already reconciled".
			$problem = sprintf(
				/* translators: 1: PaymentIntent of this event, 2: PaymentIntent that paid the order. */
				__( 'Stripe reports a second successful payment %1$s for a renewal order that was paid by %2$s. One of the two payments must be refunded. Refund the second payment %1$s in the payment gateway; a refund of this order in WooCommerce refunds %2$s and keeps the renewal, because the other payment still pays the period.', 'wp-license' ),
				$intent_id,
				$paid_by
			);
			$order->update_meta_data( '_wplc_duplicate_payment_intent_id', $intent_id );
			$order->save();
			$order->add_order_note( 'WP License Captain: ' . $problem );
			self::finish_stripe_event( $inbox_id, 'manual_review' );
			AuditLog::record( 'renewal_double_payment', 'renewal', (int) $renewal['id'], $problem, null, array( 'order_id' => (int) $order->get_id() ) );
			/** This action is documented in src/Billing/RenewalOrder.php */
			do_action( 'wplc/renewal_needs_review', (int) $order->get_id(), (int) $renewal['id'], $problem );
			return;
		}

		// The event is verified and linked to our order: remember its PaymentIntent for disputes and support.
		// A failure of an older attempt must not replace the PaymentIntent of the current one.
		$failure = 'payment_intent.payment_failed' === $event['event_type'];
		$current_attempt = isset( $payload['attempt'] ) && is_int( $payload['attempt'] ) && $payload['attempt'] === (int) $renewal['attempt_count'];
		$stored_intent = (string) $order->get_meta( RenewalOrder::META_PAYMENT_INTENT, true );
		if ( 0 === strpos( $intent_id, 'pi_' ) && $intent_id !== $stored_intent && ( ! $failure || $current_attempt || '' === $stored_intent ) ) {
			$order->update_meta_data( RenewalOrder::META_PAYMENT_INTENT, $intent_id );
			$order->save();
		}

		// A renewal in review may still be booked by a verified success (its result was unknown);
		// a review the administrator closed without booking is never booked by an event.
		if ( in_array( $renewal['status'], array( 'cancelled', 'expired', 'refunded', RenewalReview::STATUS_CLOSED ), true ) || in_array( $license['status'], array( 'revoked', 'suspended' ), true ) ) {
			self::finish_stripe_event( $inbox_id, 'manual_review' );
			AuditLog::record( 'stripe_webhook_manual_review', 'renewal', (int) $renewal['id'], AuditReason::STRIPE_EVENT_FOR_CLOSED_RECORD );
			self::report_unbooked_payment( $event, $payload, $renewal, $order, AuditReason::STRIPE_EVENT_FOR_CLOSED_RECORD );
			return;
		}
		if ( self::review_needs_decision( $renewal ) ) {
			// The case waits for the administrator's decision (refund or book); an event never books it.
			self::finish_stripe_event( $inbox_id, 'manual_review' );
			return;
		}

		if ( ! class_exists( '\\WC_Stripe_Helper' ) ) {
			self::finish_stripe_event( $inbox_id, 'manual_review' );
			self::report_unbooked_payment( $event, $payload, $renewal, $order, 'stripe_gateway_unavailable' );
			return;
		}
		// The charged amount must equal the total of our order, and the order's
		// pre-tax line must still be the net renewal price (integer minor units).
		$expected_amount = \WC_Stripe_Helper::get_stripe_amount( $order->get_total(), $order->get_currency() );
		if (
			! is_int( $expected_amount )
			|| $expected_amount <= 0
			// A failed payment has received nothing: a success must match the order total with the
			// received amount, a failure with the requested one (stored since T-155).
			|| ( 'payment_intent.succeeded' === $event['event_type'] && absint( $payload['amount_received'] ?? 0 ) !== $expected_amount )
			|| ( $failure && isset( $payload['amount'] ) && $expected_amount !== absint( $payload['amount'] ) )
			|| strtolower( (string) $order->get_currency() ) !== strtolower( (string) ( $payload['currency'] ?? '' ) )
			|| strtolower( (string) $license['renewal_currency'] ) !== strtolower( (string) $order->get_currency() )
			|| ! RenewalOrder::net_matches( $order )
		) {
			self::finish_stripe_event( $inbox_id, 'manual_review' );
			AuditLog::record( 'stripe_webhook_amount_mismatch', 'renewal', (int) $renewal['id'], AuditReason::STRIPE_AMOUNT_MISMATCH );
			self::report_unbooked_payment( $event, $payload, $renewal, $order, AuditReason::STRIPE_AMOUNT_MISMATCH );
			return;
		}

		if ( 'paid' === $renewal['status'] ) {
			self::finish_stripe_event( $inbox_id, 'already_reconciled' );
			return;
		}

		if ( 'payment_intent.succeeded' === $event['event_type'] ) {
			if ( 'succeeded' !== (string) ( $payload['payment_status'] ?? '' ) ) {
				self::finish_stripe_event( $inbox_id, 'manual_review' );
				return;
			}
			$booked = self::finish_paid( $renewal, $license, $order, 'renewal_reconciled', AuditReason::STRIPE_SUCCESS_RECONCILED );
			if ( ! $order->is_paid() ) {
				// B-60: the charge is the transaction ID, and the gateway's own metas make refunds work.
				$charge_id = sanitize_text_field( (string) ( $payload['charge_id'] ?? '' ) );
				StripeRecurringAdapter::store_gateway_meta( $order, $intent_id, $charge_id );
				$order->payment_complete( '' !== $charge_id ? $charge_id : $intent_id );
			}
			self::finish_stripe_event( $inbox_id, $booked ? 'processed' : 'manual_review' );
			return;
		}

		if ( $current_attempt && self::stripe_failure_resolves( $renewal, $order, $payload ) ) {
			// Lock order: the caller holds the renewal lock; fail_attempt() takes the license lock.
			self::resolve_as_declined_locked( $renewal, $license, AuditReason::STRIPE_DECLINE_RECONCILED );
			// After the renewal left reconciling/review, so handle_failed_order() queues nothing.
			$order->update_status( 'failed', __( 'WP License Captain: Stripe reported that the renewal payment of this attempt failed; recorded as a decline.', 'wp-license' ) );
			self::finish_stripe_event( $inbox_id, 'decline_reconciled' );
			return;
		}

		// Synchronous adapter results already own retry scheduling. The webhook is an idempotent audit/reconciliation signal.
		self::finish_stripe_event( $inbox_id, 'failure_observed' );
	}

	/**
	 * Make money that Stripe received but that cannot be booked visible (P-19).
	 *
	 * Only for `payment_intent.succeeded`. A payment that is already recorded
	 * on its order (paid or refunded with this PaymentIntent or charge) is a
	 * repeated event and stays silent; every other one gets an order note, an
	 * audit entry, `wplc/renewal_needs_review` (a health alert) and, when the
	 * renewal is linked and not being charged or booked, a review case.
	 *
	 * @param array<string,mixed>      $event Inbox row.
	 * @param array<string,mixed>      $payload Minimized event payload.
	 * @param array<string,mixed>|null $renewal Linked renewal row, null when the link is not trusted.
	 * @param \WC_Order|null           $order Order named by the event, if any.
	 * @param string                   $cause Reason code.
	 * @return void
	 */
	private static function report_unbooked_payment( array $event, array $payload, ?array $renewal, $order, string $cause ): void {
		if ( 'payment_intent.succeeded' !== (string) ( $event['event_type'] ?? '' ) ) {
			return;
		}
		$intent_id = sanitize_text_field( (string) ( $payload['payment_intent_id'] ?? '' ) );
		$charge_id = sanitize_text_field( (string) ( $payload['charge_id'] ?? '' ) );
		if ( $order instanceof \WC_Order ) {
			$recorded = ( $order->is_paid() || 'refunded' === $order->get_status() )
				&& (
					( '' !== $intent_id && (string) $order->get_meta( '_stripe_intent_id', true ) === $intent_id )
					|| in_array( (string) $order->get_transaction_id(), array_filter( array( $intent_id, $charge_id ) ), true )
				);
			if ( $recorded || ( '' !== $intent_id && (string) $order->get_meta( '_wplc_unbooked_payment_reported', true ) === $intent_id ) ) {
				return;
			}
		}
		$reason     = AuditReason::encode( AuditReason::UNBOOKED_STRIPE_PAYMENT, array( 'intent' => '' !== $intent_id ? $intent_id : '-', 'cause' => sanitize_key( $cause ) ) );
		$renewal_id = is_array( $renewal ) ? (int) $renewal['id'] : 0;
		$order_id   = $order instanceof \WC_Order ? (int) $order->get_id() : 0;
		$reviewable = array( 'scheduled', 'failed', 'manual_action_required', 'cancelled', 'expired', self::STATUS_RECONCILING, self::STATUS_REVIEW, RenewalReview::STATUS_CLOSED );
		if ( $renewal_id && in_array( (string) $renewal['status'], $reviewable, true ) ) {
			RenewalReview::open( $renewal_id, $reason, null, true );
		}
		if ( $order instanceof \WC_Order ) {
			$order->update_meta_data( '_wplc_unbooked_payment_reported', $intent_id );
			$order->save();
			$order->add_order_note( 'WP License Captain: ' . AuditReason::label( $reason ) );
		}
		AuditLog::record(
			'stripe_payment_not_booked',
			$renewal_id ? 'renewal' : 'order',
			$renewal_id ? $renewal_id : $order_id,
			$reason,
			null,
			array( 'order_id' => $order_id, 'amount_received' => absint( $payload['amount_received'] ?? 0 ), 'currency' => sanitize_key( (string) ( $payload['currency'] ?? '' ) ) )
		);
		/** This action is documented in src/Billing/RenewalOrder.php */
		do_action( 'wplc/renewal_needs_review', $order_id, $renewal_id, $reason );
	}

	/**
	 * Book a paid renewal order. The only code path that extends a license for
	 * an order paid outside the automatic charge: manual renewals (any gateway)
	 * and failed automatic renewals the customer paid on the pay page.
	 *
	 * Idempotent under duplicate hooks: it runs under the per-renewal lock and
	 * stops when the renewal is already paid.
	 *
	 * A paid order that cannot be booked always gets an order note and an audit
	 * entry (A-12, B-190); the money was accepted, so it must not disappear.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public static function reconcile_paid_renewal_order( int $order_id ): void {
		global $wpdb;
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof \WC_Order || ! $order->is_paid() ) {
			return;
		}
		$renewal_id = absint( $order->get_meta( RenewalOrder::META_RENEWAL, true ) );
		$license_id = absint( $order->get_meta( RenewalOrder::META_LICENSE, true ) );
		if ( ! $renewal_id || ! $license_id ) {
			return;
		}
		if ( ! self::lock( $renewal_id ) ) {
			self::queue_paid_order_reconciliation( $order_id );
			return;
		}
		try {
			$tables = Schema::table_names();
			$renewal = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE id = %d LIMIT 1", $tables['renewals'], $renewal_id ), ARRAY_A );
			$license = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE id = %d LIMIT 1", $tables['licenses'], $license_id ), ARRAY_A );
			if ( is_array( $renewal ) && in_array( $renewal['status'], array( 'paid', 'refunded' ), true ) && (int) $renewal['renewal_order_id'] === $order_id ) {
				return;
			}
			if (
				! is_array( $renewal )
				|| ! is_array( $license )
				|| (int) $renewal['license_id'] !== $license_id
				|| (int) $renewal['renewal_order_id'] !== $order_id
				|| ! RenewalOrder::matches( $order, $renewal, $license )
			) {
				RenewalOrder::flag_for_review( $order, 0, __( 'Paid renewal order metadata did not match its license', 'wp-license' ), 'renewal_recovery_mismatch' );
				return;
			}
			if ( 'paid' === $renewal['status'] ) {
				return;
			}
			$closed = (string) $order->get_meta( RenewalReview::META_CLOSED, true );
			if ( '' !== $closed ) {
				// An administrator closed the review of this order without booking it. Never book it
				// silently; an order that was unpaid then and is paid now goes back to review.
				if ( 'unpaid' === $closed && RenewalReview::STATUS_CLOSED === $renewal['status'] && RenewalReview::open( $renewal_id, AuditReason::PAID_AFTER_REVIEW_CLOSED ) ) {
					AuditLog::record( 'renewal_review_required', 'renewal', $renewal_id, AuditReason::PAID_AFTER_REVIEW_CLOSED, array( 'status' => RenewalReview::STATUS_CLOSED ), array( 'status' => self::STATUS_REVIEW, 'order_id' => $order_id ) );
					$order->add_order_note( __( 'WP License Captain: this renewal order was paid after its review had been closed. The renewal was not booked; it is back in the review list.', 'wp-license' ) );
				}
				return;
			}
			if ( RenewalOrder::is_manual( $order ) ) {
				ManualRenewal::complete_locked( $order, $renewal, $license );
				return;
			}
			if ( self::review_needs_decision( $renewal ) ) {
				// P-02, P-19, P-60: the administrator decides on the review screen; a hook never books it.
				return;
			}
			if ( ! in_array( $renewal['status'], array( 'scheduled', 'failed', 'manual_action_required', 'processing', self::STATUS_RECONCILING, self::STATUS_REVIEW ), true ) ) {
				RenewalOrder::flag_for_review(
					$order,
					$renewal_id,
					sprintf(
						/* translators: %s: renewal status. */
						__( 'The renewal was already closed (%s) when the payment arrived', 'wp-license' ),
						(string) $renewal['status']
					),
					'renewal_recovery_not_allowed'
				);
				return;
			}
			// The order is ours (meta and renewal link) and paid in full, so its total is what was charged.
			// Verify that its pre-tax line still is the net renewal price and that the currency is unchanged.
			if ( ! RenewalOrder::net_matches( $order ) || strtoupper( (string) $license['renewal_currency'] ) !== strtoupper( (string) $order->get_currency() ) || 'yes' === $order->get_meta( RenewalOrder::META_AMOUNT_MISMATCH, true ) ) {
				RenewalOrder::flag_for_review( $order, $renewal_id, __( 'Paid customer recovery order did not match the stored renewal amount', 'wp-license' ), 'renewal_recovery_amount_mismatch' );
				return;
			}
			$marked_by = absint( $order->get_meta( self::META_MARKED_PAID_BY, true ) );
			if ( $marked_by && '' === (string) $order->get_transaction_id() ) {
				// P-60: an administrator set the order to paid by hand, without any payment reference.
				if ( ! in_array( $renewal['status'], array( self::STATUS_RECONCILING, self::STATUS_REVIEW ), true ) ) {
					// Nothing says that money arrived (no charge with unknown result): never booked as
					// "customer paid"; the administrator books it on the review screen if it did.
					RenewalReview::open( $renewal_id, AuditReason::MARKED_PAID_WITHOUT_PAYMENT, $order_id, true );
					RenewalOrder::flag_for_review( $order, $renewal_id, AuditReason::MARKED_PAID_WITHOUT_PAYMENT, 'renewal_review_required' );
					return;
				}
				// The order note of the unknown result asks for exactly this: booked with the administrator as actor.
				self::finish_paid( $renewal, $license, $order, 'renewal_marked_paid_by_admin', AuditReason::encode( AuditReason::MARKED_PAID_BY_ADMIN, array( 'user' => $marked_by ) ), $marked_by );
				return;
			}
			self::finish_paid( $renewal, $license, $order, 'renewal_customer_recovered', AuditReason::CUSTOMER_PAID_RENEWAL );
		} finally {
			self::unlock( $renewal_id );
		}
	}

	/**
	 * Daily sweep for renewals whose job is no longer in the queue.
	 *
	 * A queued job is used up without effect when it runs while the plugin is
	 * inactive, and all jobs are removed on uninstall while the data stays. Without
	 * this sweep such a renewal would never be attempted and the license would
	 * expire although automatic renewal is switched on. Queuing is harmless when a
	 * job is still pending (skipped) and process() is idempotent.
	 *
	 * A-18: every overdue renewal is queued, not only the first hundred.
	 *
	 * @return void
	 */
	public static function requeue_overdue_renewals(): void {
		if ( self::billing_paused() ) {
			return;
		}
		LicenseLifecycle::run_guarded(
			'requeue_overdue_renewals',
			static function () {
				global $wpdb;

				$tables = Schema::table_names();
				$deadline = microtime( true ) + self::SWEEP_TIME_BUDGET;
				$cursor = 0;
				$spread = 0;
				do {
					$ids = (array) $wpdb->get_col(
						$wpdb->prepare(
							"SELECT id FROM %i WHERE id > %d AND status IN (%s, %s, %s) AND retry_at IS NOT NULL AND retry_at <= %s ORDER BY id ASC LIMIT 500",
							$tables['renewals'],
							$cursor,
							'scheduled',
							'failed',
							self::STATUS_RECONCILING,
							gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS )
						)
					);
					foreach ( $ids as $renewal_id ) {
						$cursor = (int) $renewal_id;
						// Spread a large backlog over time instead of one burst of charges.
						RenewalScheduler::enqueue( $cursor, time() + 30 + (int) floor( $spread / 50 ) * MINUTE_IN_SECONDS );
						++$spread;
					}
					$fetched = count( $ids );
				} while ( $fetched >= 500 && microtime( true ) < $deadline );
			}
		);
	}

	/**
	 * Daily repair: renewing licenses that have no renewal for their next period (B-31).
	 *
	 * The row is created when a renewal is booked. When that step was lost (an
	 * exception, a silent database error, a restore) nothing would ever charge
	 * the license again and it would expire unattempted a period later.
	 *
	 * @return void
	 */
	public static function repair_missing_schedules(): void {
		if ( self::billing_paused() ) {
			return;
		}
		LicenseLifecycle::run_guarded(
			'repair_missing_schedules',
			static function () {
				global $wpdb;

				$tables = Schema::table_names();
				$deadline = microtime( true ) + self::SWEEP_TIME_BUDGET;
				$cursor = 0;
				do {
					$ids = (array) $wpdb->get_col(
						$wpdb->prepare(
							"SELECT l.id FROM {$tables['licenses']} l LEFT JOIN {$tables['renewals']} r ON r.license_id = l.id AND r.period_start = l.paid_until
							WHERE l.id > %d AND l.renewal_enabled = 1 AND l.cancel_at_period_end = 0 AND l.status IN (%s, %s) AND l.paid_until IS NOT NULL
							AND ( r.id IS NULL OR r.status = %s )
							ORDER BY l.id ASC LIMIT 200",
							$cursor,
							'active',
							'past_due',
							'cancelled'
						)
					);
					foreach ( $ids as $license_id ) {
						$cursor = (int) $license_id;
						if ( RenewalScheduler::schedule_next( $cursor ) ) {
							AuditLog::record( 'renewal_schedule_repaired', 'license', $cursor, __( 'The renewal of the next period was missing and has been scheduled', 'wp-license' ) );
						}
					}
					$fetched = count( $ids );
				} while ( $fetched >= 200 && microtime( true ) < $deadline );
			}
		);
	}

	/**
	 * Daily sweep: paid renewal orders whose booking job was lost (A-19, B-14).
	 *
	 * The booking of an order paid by the customer hangs on one queued job. An
	 * order paid while the plugin was inactive never got one.
	 *
	 * @return void
	 */
	public static function reconcile_unbooked_paid_orders(): void {
		if ( self::billing_paused() ) {
			return;
		}
		LicenseLifecycle::run_guarded(
			'reconcile_unbooked_paid_orders',
			static function () {
				global $wpdb;

				if ( ! function_exists( 'wc_get_order' ) ) {
					return;
				}
				$tables = Schema::table_names();
				$deadline = microtime( true ) + self::SWEEP_TIME_BUDGET;
				$cursor = 0;
				do {
					$rows = (array) $wpdb->get_results(
						$wpdb->prepare(
							"SELECT id, renewal_order_id FROM %i WHERE id > %d AND renewal_order_id IS NOT NULL AND renewal_order_id > 0 AND status IN (%s, %s, %s, %s, %s) ORDER BY id ASC LIMIT 200",
							$tables['renewals'],
							$cursor,
							'scheduled',
							'failed',
							'manual_action_required',
							'manual',
							self::STATUS_RECONCILING
						),
						ARRAY_A
					);
					foreach ( $rows as $row ) {
						$cursor = (int) $row['id'];
						$order = wc_get_order( (int) $row['renewal_order_id'] );
						if ( $order instanceof \WC_Order && $order->is_paid() && 'yes' !== $order->get_meta( RenewalOrder::META_REVIEW, true ) ) {
							self::reconcile_paid_renewal_order( (int) $order->get_id() );
						}
					}
					$fetched = count( $rows );
				} while ( $fetched >= 200 && microtime( true ) < $deadline );
			}
		);
	}

	/**
	 * Daily sweep: verified Stripe events whose processing job was lost (A-19).
	 *
	 * @return void
	 */
	public static function requeue_pending_stripe_events(): void {
		if ( self::billing_paused() ) {
			return;
		}
		LicenseLifecycle::run_guarded(
			'requeue_pending_stripe_events',
			static function () {
				global $wpdb;

				$tables = Schema::table_names();
				$ids = (array) $wpdb->get_col(
					$wpdb->prepare(
						"SELECT id FROM %i WHERE source = %s AND outcome = %s AND processed_at IS NULL AND received_at <= %s ORDER BY id ASC LIMIT 200",
						$tables['event_inbox'],
						'stripe',
						'pending_reconciliation',
						gmdate( 'Y-m-d H:i:s', time() - self::STUCK_AFTER_SECONDS )
					)
				);
				foreach ( $ids as $inbox_id ) {
					self::queue_stripe_event( (int) $inbox_id );
				}
			}
		);
	}

	/**
	 * Daily clean-up of renewals that can no longer be paid with effect (B-186, B-190).
	 *
	 * - A manual renewal the customer started and never paid is cancelled with
	 *   its order once the manual renewal window of that period has passed.
	 * - An open automatic renewal whose license ended, or whose period is no
	 *   longer the license's current one, is cancelled with its order.
	 *
	 * @return void
	 */
	public static function cleanup_stale_renewals(): void {
		if ( self::billing_paused() ) {
			return;
		}
		LicenseLifecycle::run_guarded(
			'cleanup_stale_renewals',
			static function () {
				global $wpdb;

				$tables = Schema::table_names();
				$window_end = gmdate( 'Y-m-d H:i:s', time() - LicenseStateMachine::MANUAL_RENEWAL_WINDOW_DAYS * DAY_IN_SECONDS );
				$rows = (array) $wpdb->get_results(
					$wpdb->prepare(
						"SELECT r.* FROM {$tables['renewals']} r LEFT JOIN {$tables['licenses']} l ON l.id = r.license_id
						WHERE ( r.status = %s AND r.period_start < %s )
						OR ( r.status IN (%s, %s, %s) AND ( l.id IS NULL OR l.status IN (%s, %s) OR l.paid_until IS NULL OR l.paid_until <> r.period_start ) )
						ORDER BY r.id ASC LIMIT 500",
						'manual',
						$window_end,
						'scheduled',
						'failed',
						'manual_action_required',
						'expired',
						'revoked'
					),
					ARRAY_A
				);
				foreach ( $rows as $row ) {
					$renewal_id = (int) $row['id'];
					if ( ! self::lock( $renewal_id, 0 ) ) {
						continue;
					}
					try {
						$status = (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM %i WHERE id = %d", $tables['renewals'], $renewal_id ) );
						if ( $status === (string) $row['status'] ) {
							self::close_renewal( $row, 'cancelled', 'manual' === $status ? 'renewal_window_ended' : 'renewal_obsolete' );
						}
					} finally {
						self::unlock( $renewal_id );
					}
				}
				self::close_orphaned_renewal_orders();
			}
		);
	}

	/**
	 * Close unpaid renewal orders that no renewal can accept any more (B-190).
	 *
	 * An order created for a renewal is orphaned when its renewal row is gone
	 * (database restore, manual clean-up), when the renewal ended or was paid
	 * through another order, or when the renewal is linked to another order.
	 * Such an order is cancelled with a note (pending, failed); an order on
	 * hold waits for an offline payment and only gets a note, once. Renewals
	 * that wait for a payment result or a review, renewals not linked to any
	 * order yet (the next attempt links it) and orders changed within the last
	 * hour are left alone.
	 *
	 * @return void
	 */
	public static function close_orphaned_renewal_orders(): void {
		global $wpdb;

		if ( ! function_exists( 'wc_get_orders' ) ) {
			return;
		}
		$tables = Schema::table_names();
		$deadline = microtime( true ) + self::SWEEP_TIME_BUDGET;
		$waiting = array( 'processing', self::STATUS_RECONCILING, self::STATUS_REVIEW, self::LEGACY_STATUS_REVIEW );
		$open = array( 'scheduled', 'failed', 'manual_action_required', 'manual' );
		$offset = 0;
		do {
			$ids = wc_get_orders(
				array(
					'type' => 'shop_order',
					'created_via' => RenewalOrder::CREATED_VIA,
					'status' => array( 'pending', 'failed', 'on-hold' ),
					'date_created' => '<' . ( time() - DAY_IN_SECONDS ),
					'orderby' => 'ID',
					'order' => 'ASC',
					'limit' => 100,
					'offset' => $offset,
					'return' => 'ids',
				)
			);
			if ( ! is_array( $ids ) ) {
				return;
			}
			$closed = 0;
			foreach ( $ids as $order_id ) {
				$order = wc_get_order( absint( $order_id ) );
				if ( ! $order instanceof \WC_Order || RenewalOrder::CREATED_VIA !== $order->get_created_via() || $order->is_paid() || $order->get_date_paid() ) {
					continue;
				}
				$modified = $order->get_date_modified();
				if ( $modified && $modified->getTimestamp() > time() - HOUR_IN_SECONDS ) {
					continue;
				}
				$renewal_id = absint( $order->get_meta( RenewalOrder::META_RENEWAL, true ) );
				$renewal = $renewal_id ? $wpdb->get_row( $wpdb->prepare( 'SELECT id, license_id, status, renewal_order_id FROM %i WHERE id = %d LIMIT 1', $tables['renewals'], $renewal_id ), ARRAY_A ) : null;
				if ( is_array( $renewal ) ) {
					$status = (string) $renewal['status'];
					$linked = (int) $renewal['renewal_order_id'];
					if (
						in_array( $status, $waiting, true )
						|| (int) $order->get_meta( RenewalOrder::META_LICENSE, true ) !== (int) $renewal['license_id']
						|| ( in_array( $status, $open, true ) && 0 === $linked )
						// A renewal booked on this very order (e.g. after a review) keeps it: cancelling it
						// would read as a bounced payment and suspend the license.
						|| ( (int) $order->get_id() === $linked && ! in_array( $status, array( 'cancelled', 'expired' ), true ) )
					) {
						continue;
					}
				}
				$cause = ! is_array( $renewal ) ? 'renewal_missing' : ( in_array( (string) $renewal['status'], $open, true ) ? 'renewal_linked_elsewhere' : 'renewal_' . sanitize_key( (string) $renewal['status'] ) );
				if ( 'on-hold' === $order->get_status() ) {
					if ( 'yes' === $order->get_meta( '_wplc_orphan_noted', true ) ) {
						continue;
					}
					$order->update_meta_data( '_wplc_orphan_noted', 'yes' );
					$order->save_meta_data();
					$order->add_order_note( __( 'WP License Captain: no license renewal can accept this order any more (the renewal was closed, paid with another order or no longer exists). Do not accept a payment for it without checking the license.', 'wp-license' ) );
					AuditLog::record( 'renewal_order_orphaned', 'order', (int) $order->get_id(), $cause, null, array( 'renewal_id' => $renewal_id, 'status' => 'on-hold' ) );
					continue;
				}
				if ( RenewalOrder::close_unpaid( (int) $order->get_id(), __( 'WP License Captain: no license renewal can accept this order any more (the renewal was closed, paid with another order or no longer exists); the order is closed.', 'wp-license' ) ) ) {
					AuditLog::record( 'renewal_order_orphaned', 'order', (int) $order->get_id(), $cause, null, array( 'renewal_id' => $renewal_id, 'status' => 'cancelled' ) );
					++$closed;
				}
			}
			// Closed orders leave the result set; the next page starts after the ones that stay.
			$offset += count( $ids ) - $closed;
		} while ( count( $ids ) >= 100 && microtime( true ) < $deadline );
	}

	/**
	 * Daily sweep for renewals left in `processing` by a crash.
	 *
	 * @return void
	 */
	public static function reconcile_stuck_renewals(): void {
		if ( self::billing_paused() ) {
			return;
		}
		LicenseLifecycle::run_guarded(
			'reconcile_stuck_renewals',
			static function () {
				global $wpdb;

				$tables = Schema::table_names();
				$ids = (array) $wpdb->get_col(
					$wpdb->prepare(
						"SELECT id FROM %i WHERE status = %s AND updated_at <= %s ORDER BY id ASC LIMIT 200",
						$tables['renewals'],
						'processing',
						gmdate( 'Y-m-d H:i:s', time() - self::STUCK_AFTER_SECONDS )
					)
				);
				foreach ( $ids as $renewal_id ) {
					try {
						self::reconcile_stuck_renewal( (int) $renewal_id );
					} catch ( \Throwable $error ) {
						LicenseLifecycle::log_error( 'reconcile_stuck_renewal ' . (int) $renewal_id . ': ' . $error->getMessage() );
					}
				}
			}
		);
	}

	/**
	 * Resolve one renewal stuck in `processing`, without charging.
	 *
	 * A paid order finishes the renewal. Otherwise the attempt is handed to
	 * mark_result_unknown(): the license is not touched and later checks send
	 * the same gateway idempotency key, so a charge that did go through before
	 * the crash is replayed instead of taken twice. The window for those checks
	 * counts from the moment the row entered `processing`.
	 *
	 * @param int $renewal_id Renewal ID.
	 * @return void
	 */
	public static function reconcile_stuck_renewal( $renewal_id ): void {
		global $wpdb;

		$renewal_id = absint( $renewal_id );
		// A held lock means the renewal is being processed right now.
		if ( ! $renewal_id || self::billing_paused() || ! self::lock( $renewal_id, 0 ) ) {
			return;
		}
		try {
			$tables = Schema::table_names();
			$renewal = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE id = %d LIMIT 1", $tables['renewals'], $renewal_id ), ARRAY_A );
			if (
				! is_array( $renewal )
				|| 'processing' !== $renewal['status']
				|| (int) strtotime( (string) $renewal['updated_at'] . ' UTC' ) > time() - self::STUCK_AFTER_SECONDS
			) {
				return;
			}
			$license = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE id = %d LIMIT 1", $tables['licenses'], (int) $renewal['license_id'] ), ARRAY_A );
			if ( ! is_array( $license ) ) {
				self::close_renewal( $renewal, 'cancelled', 'license_missing' );
				return;
			}
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( absint( $renewal['renewal_order_id'] ) ) : false;
			if ( $order instanceof \WC_Order && RenewalOrder::matches( $order, $renewal, $license ) && $order->is_paid() ) {
				self::book_paid_order( $renewal, $license, $order, 'renewal_stuck_recovered', AuditReason::STUCK_RENEWAL_PAID );
				return;
			}
			self::fail_attempt(
				$renewal,
				$license,
				new \WP_Error( self::RESULT_UNKNOWN, __( 'The renewal attempt did not finish and its result is unknown.', 'wp-license' ) ),
				false,
				(int) strtotime( (string) $renewal['updated_at'] . ' UTC' )
			);
		} finally {
			self::unlock( $renewal_id );
		}
	}

	/**
	 * Queue the stuck check for a renewal that just entered `processing`.
	 *
	 * @param int $renewal_id Renewal ID.
	 * @return void
	 */
	private static function schedule_stuck_check( int $renewal_id ): void {
		$at = time() + self::STUCK_AFTER_SECONDS + 5 * MINUTE_IN_SECONDS;
		if ( function_exists( 'as_schedule_single_action' ) ) {
			\WPLC\Compatibility\Requirements::schedule_single_action( $at, 'wplc_reconcile_stuck_renewal', array( $renewal_id ), 'wplc' );
		} elseif ( ! wp_next_scheduled( 'wplc_reconcile_stuck_renewal', array( $renewal_id ) ) ) {
			wp_schedule_single_event( $at, 'wplc_reconcile_stuck_renewal', array( $renewal_id ) );
		}
	}

	/**
	 * Close a webhook inbox entry with its outcome.
	 *
	 * @param int    $inbox_id Inbox row ID.
	 * @param string $outcome Outcome key.
	 * @return void
	 */
	private static function finish_stripe_event( int $inbox_id, string $outcome ): void {
		global $wpdb;
		$tables = Schema::table_names();
		$wpdb->update(
			$tables['event_inbox'],
			array( 'outcome' => sanitize_key( $outcome ), 'processed_at' => gmdate( 'Y-m-d H:i:s' ) ),
			array( 'id' => $inbox_id, 'source' => 'stripe', 'outcome' => 'pending_reconciliation' ),
			array( '%s', '%s' ),
			array( '%d', '%s', '%s' )
		);
	}

	/**
	 * Unix time of a stored UTC datetime, zero when empty or invalid (P-44).
	 *
	 * Pure. `strtotime( ' UTC' )` returns the current time, not false, so an
	 * empty column must never be passed to strtotime() with the suffix.
	 *
	 * @param mixed $value Stored 'Y-m-d H:i:s' UTC value, may be null.
	 * @return int
	 */
	public static function utc_ts( $value ): int {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		if ( '' === $value || 0 === strpos( $value, '0000-00-00' ) ) {
			return 0;
		}
		$timestamp = strtotime( $value . ' UTC' );

		return false === $timestamp ? 0 : (int) $timestamp;
	}

	/**
	 * End of the grace period of a renewal (P-44).
	 *
	 * Pure. Rows written by early development versions have no grace_ends_at;
	 * their grace period ends grace_days() after the period start, as in the
	 * diagnostics. Zero when neither is known.
	 *
	 * @param array<string,mixed> $renewal Renewal row.
	 * @return int
	 */
	public static function grace_end_ts( array $renewal ): int {
		$grace_end = self::utc_ts( $renewal['grace_ends_at'] ?? null );
		if ( $grace_end ) {
			return $grace_end;
		}
		$start = self::utc_ts( $renewal['period_start'] ?? null );

		return $start ? $start + LicenseLifecycle::grace_days() * 86400 : 0;
	}

	/**
	 * Name of the per-renewal MySQL advisory lock.
	 *
	 * @param int $renewal_id Renewal ID.
	 * @return string
	 */
	public static function renewal_lock_name( int $renewal_id ): string {
		return 'wplc_renewal_' . substr( hash( 'sha256', \WPLC\Infrastructure\Database\Schema::lock_scope() . '|renewal:' . $renewal_id ), 0, 48 );
	}

	/**
	 * Take the per-renewal lock that serializes charging, reconciliation and manual renewal.
	 *
	 * @param int $renewal_id Renewal ID.
	 * @param int $timeout Seconds to wait.
	 * @return bool
	 */
	public static function lock( int $renewal_id, int $timeout = 5 ): bool {
		global $wpdb;

		// Never nest: releasing an inner lock must not free the outer one.
		if ( ! empty( self::$held_locks[ $renewal_id ] ) ) {
			return false;
		}
		$locked = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::renewal_lock_name( $renewal_id ), max( 0, $timeout ) ) );
		if ( '1' !== (string) $locked ) {
			return false;
		}
		self::$held_locks[ $renewal_id ] = true;

		return true;
	}

	/**
	 * Take the lock of the renewal of the license's current period, if there is one (P-02).
	 *
	 * For callers that change whether a license may be charged (cancellation,
	 * switch-off) before they take the license lock: lock order renewal, then
	 * license. While the processor charges that renewal they wait up to
	 * $timeout seconds; a caller that gets no lock proceeds anyway, and the
	 * processor re-checks the license under the license lock before charging
	 * and sends a payment taken meanwhile to review.
	 *
	 * @param int $license_id License ID.
	 * @param int $timeout Seconds to wait.
	 * @return int Locked renewal ID, zero when no lock was taken. Release it with unlock().
	 */
	public static function lock_current_period( int $license_id, int $timeout ): int {
		global $wpdb;

		$tables     = Schema::table_names();
		$renewal_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT r.id FROM %i r JOIN %i l ON l.id = r.license_id AND r.period_start = l.paid_until WHERE l.id = %d ORDER BY r.id DESC LIMIT 1',
				$tables['renewals'],
				$tables['licenses'],
				$license_id
			)
		);

		return $renewal_id && self::lock( $renewal_id, $timeout ) ? $renewal_id : 0;
	}

	/**
	 * Release the per-renewal lock taken by lock().
	 *
	 * @param int $renewal_id Renewal ID.
	 * @return void
	 */
	public static function unlock( int $renewal_id ): void {
		global $wpdb;

		if ( empty( self::$held_locks[ $renewal_id ] ) ) {
			return;
		}
		unset( self::$held_locks[ $renewal_id ] );
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::renewal_lock_name( $renewal_id ) ) );
	}

	/**
	 * Update fields of a renewal row.
	 *
	 * @param int                 $renewal_id Renewal ID.
	 * @param array<string,mixed> $fields Any of status, retry_at, renewal_order_id, attempt_count,
	 *                                    last_error, last_attempt_at, gateway_attempt_id.
	 * @return void
	 */
	private static function update_renewal( int $renewal_id, array $fields ): void {
		global $wpdb;

		$formats_by_field = array(
			'status' => '%s',
			'retry_at' => '%s',
			'renewal_order_id' => '%d',
			'attempt_count' => '%d',
			'last_error' => '%s',
			'last_attempt_at' => '%s',
			'gateway_attempt_id' => '%s',
		);
		$data = array();
		$formats = array();
		foreach ( $formats_by_field as $field => $format ) {
			if ( array_key_exists( $field, $fields ) ) {
				$data[ $field ] = $fields[ $field ];
				$formats[] = $format;
			}
		}
		$data['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		$formats[] = '%s';

		$tables = Schema::table_names();
		$wpdb->update( $tables['renewals'], $data, array( 'id' => $renewal_id ), $formats, array( '%d' ) );
	}
}
