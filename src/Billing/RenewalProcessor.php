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
use WPLC\License\LicenseLifecycle;
use WPLC\License\LicenseStateMachine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Executes one due attempt and schedules the agreed retry sequence.
 */
final class RenewalProcessor {

	/**
	 * Error code of an attempt whose outcome is not known (timeout, crash).
	 * Such an attempt is repeated with the same gateway idempotency key.
	 */
	public const RESULT_UNKNOWN = 'renewal_result_unknown';

	/** Seconds after which a renewal left in `processing` counts as stuck. */
	public const STUCK_AFTER_SECONDS = 900;

	/**
	 * Renewal status of a payment that was taken but could not be applied to
	 * the license, or whose outcome stayed unknown. Never charged again
	 * automatically; needs a decision by the shop.
	 */
	public const STATUS_REVIEW = 'payment_review';

	/**
	 * Seconds an attempt with unknown outcome is repeated with the same gateway
	 * idempotency key. Stripe forgets keys after 24 hours; a later repeat could
	 * charge a second time (A-114).
	 */
	public const UNKNOWN_RESULT_WINDOW = 72000;

	/** Seconds until a renewal blocked by a shop-side problem is tried again. */
	public const BLOCKED_RETRY_SECONDS = 21600;

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
		if ( ! is_array( $renewal ) || ! in_array( $renewal['status'], array( 'scheduled', 'failed', 'processing' ), true ) ) {
			return;
		}
		if ( 'processing' === $renewal['status'] ) {
			// The lock is free, so the attempt that set this status died. Its outcome is resolved
			// by the stuck check, never by charging again from here.
			self::schedule_stuck_check( $renewal_id );
			return;
		}

		$now = time();
		$start = strtotime( (string) $renewal['period_start'] . ' UTC' );
		$grace_end = strtotime( (string) $renewal['grace_ends_at'] . ' UTC' );
		$retry_at = empty( $renewal['retry_at'] ) ? 0 : (int) strtotime( (string) $renewal['retry_at'] . ' UTC' );
		$grace_over = $grace_end && $now >= $grace_end;

		$license = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM %i WHERE id = %d LIMIT 1", $tables['licenses'], (int) $renewal['license_id'] ),
			ARRAY_A
		);
		if ( ! is_array( $license ) ) {
			self::close_renewal( $renewal, 'cancelled', 'license_missing' );
			return;
		}

		if ( ! in_array( $license['status'], array( 'active', 'past_due' ), true ) ) {
			self::close_renewal( $renewal, 'cancelled', 'license_' . sanitize_key( (string) $license['status'] ) );
			return;
		}

		if ( ! empty( $license['cancel_at_period_end'] ) || empty( $license['renewal_enabled'] ) ) {
			self::close_renewal( $renewal, 'cancelled', empty( $license['renewal_enabled'] ) ? 'renewal_disabled' : 'customer_cancelled' );
			return;
		}

		if ( (string) $renewal['period_start'] !== (string) $license['paid_until'] ) {
			// B-50: the term was moved (administrator, manual renewal). This row belongs to a period
			// that no longer exists and must never be charged.
			self::close_renewal( $renewal, 'cancelled', 'period_changed' );
			return;
		}

		// B-188: a duplicate job must not bring the next attempt forward.
		if ( ! $grace_over && 'failed' === $renewal['status'] && ! $retry_at ) {
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
			self::book_paid_order( $renewal, $license, $order, 'renewal_paid', __( 'Renewal order was already paid', 'wp-license' ) );
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

		$started = gmdate( 'Y-m-d H:i:s' );
		$fields = array( 'status' => 'processing', 'retry_at' => null );
		if ( self::RESULT_UNKNOWN !== (string) ( $renewal['last_error'] ?? '' ) || empty( $renewal['last_attempt_at'] ) ) {
			// A repeat of an attempt with unknown outcome keeps the time of its first try (A-114).
			$fields['last_attempt_at'] = $started;
			$renewal['last_attempt_at'] = $started;
		}
		self::update_renewal( $renewal_id, $fields );
		self::schedule_stuck_check( $renewal_id );
		// The key changes only after a definite failure was recorded (see attempt_idempotency_key()).
		$attempt_key = RenewalScheduler::attempt_idempotency_key( (string) $renewal['idempotency_key'], (int) $renewal['attempt_count'] );
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
				self::defer_attempt( $renewal, is_array( $error_data ) && ! empty( $error_data['reason'] ) ? (string) $error_data['reason'] : (string) $error->get_error_code(), self::BLOCKED_RETRY_SECONDS );
				return;
			}
			$definite = self::RESULT_UNKNOWN !== $error->get_error_code();
			if ( $definite ) {
				$order->update_status( 'failed', $error->get_error_code() );
			}
			self::fail_attempt( $renewal, $license, $error, $definite );
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
				__( 'The automatic charge %1$s succeeded although the order was already paid (%2$s). One of the two payments must be refunded.', 'wp-license' ),
				$transaction_id,
				(string) $order->get_transaction_id()
			);
			$order->add_order_note( 'WP License Captain: ' . $problem );
			AuditLog::record( 'renewal_double_payment', 'renewal', $renewal_id, $problem, null, array( 'order_id' => (int) $order->get_id() ) );
			/** This action is documented in src/Billing/RenewalOrder.php */
			do_action( 'wplc/renewal_needs_review', (int) $order->get_id(), $renewal_id, $problem );
			self::book_paid_order( $renewal, $license, $order, 'renewal_paid', __( 'Renewal order was already paid', 'wp-license' ) );
			return;
		}
		$order->payment_complete( $transaction_id );
		self::finish_paid( $renewal, $license, $order, 'renewal_paid', __( 'Renewal was confirmed by the payment adapter', 'wp-license' ) );
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
	 * problems fire `wplc/renewal_blocked` for monitoring.
	 *
	 * @param array<string,mixed> $renewal Renewal row.
	 * @param string              $code Reason code.
	 * @param int                 $seconds Seconds until the next look.
	 * @param bool                $alert Whether the shop has to act.
	 * @return void
	 */
	private static function defer_attempt( array $renewal, string $code, int $seconds, bool $alert = true ): void {
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
	 * @return bool True when the renewal is booked.
	 */
	private static function finish_paid( array $renewal, array $license, $order, string $audit_action, string $reason ): bool {
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
						__( 'The license status "%s" did not allow a renewal; the payment was not applied', 'wp-license' ),
						(string) $current['status']
					);
				self::update_renewal(
					$renewal_id,
					array(
						'status' => self::STATUS_REVIEW,
						'retry_at' => null,
						'renewal_order_id' => $order_id,
						'last_error' => 'renewal_paid_not_applied',
					)
				);
				RenewalOrder::flag_for_review( $order, $renewal_id, $problem, 'renewal_paid_not_applied' );
				return false;
			}
		}

		self::update_renewal( $renewal_id, array( 'status' => 'paid', 'retry_at' => null, 'renewal_order_id' => $order_id, 'last_error' => '' ) );
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
			array( 'status' => 'paid', 'order_id' => $order_id )
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
		$days = (array) apply_filters( 'wplc_renewal_retry_days', array( 1, 3, 7 ) );
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
	public static function next_retry_at( int $attempt, int $period_start, int $now, array $days = array( 1, 3, 7 ) ): int {
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
	 * Only a definite failure counts as an attempt. When the outcome is unknown
	 * (timeout, crash) the counter stays, so the retry sends the same gateway
	 * idempotency key and the gateway replays the first result instead of
	 * charging again. That retry runs within the hour; after
	 * UNKNOWN_RESULT_WINDOW the renewal is handed to manual review instead,
	 * because the gateway may have forgotten the key by then (A-114).
	 *
	 * T-136: an unknown outcome does not set the license past due and sends no
	 * "payment failed" mail; the charge may have succeeded.
	 *
	 * B-62: an error whose data contains `retryable => false` (lost or stolen
	 * card, do-not-retry advice) stops the retries; the customer has to pay.
	 *
	 * @param array<string,mixed> $renewal Renewal row.
	 * @param array<string,mixed> $license License row.
	 * @param \WP_Error $error Failure.
	 * @param bool $definite False when the payment outcome is unknown.
	 * @return void
	 */
	private static function fail_attempt( array $renewal, array $license, \WP_Error $error, bool $definite = true ): void {
		$renewal_id = (int) $renewal['id'];
		$code = (string) $error->get_error_code();
		$stored_code = substr( sanitize_key( $code ), 0, 100 );
		$attempt = (int) $renewal['attempt_count'] + ( $definite ? 1 : 0 );
		$grace_end = strtotime( (string) $renewal['grace_ends_at'] . ' UTC' );
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
			AuditLog::record( 'renewal_manual_action_required', 'renewal', $renewal_id, $code, array( 'attempt_count' => (int) $renewal['attempt_count'] ), array( 'attempt_count' => $attempt, 'status' => 'manual_action_required' ) );
			if ( $grace_end ) {
				self::schedule_grace_end( (int) $license['id'], $grace_end );
			}
			return;
		}

		if ( ! $definite ) {
			$first_try = empty( $renewal['last_attempt_at'] ) ? time() : (int) strtotime( (string) $renewal['last_attempt_at'] . ' UTC' );
			if ( time() - $first_try > self::UNKNOWN_RESULT_WINDOW ) {
				self::update_renewal( $renewal_id, array( 'status' => self::STATUS_REVIEW, 'retry_at' => null, 'last_error' => $stored_code ) );
				$problem = __( 'The outcome of the renewal charge stayed unknown. Check the payment at the gateway before charging again.', 'wp-license' );
				AuditLog::record( 'renewal_result_unresolved', 'renewal', $renewal_id, $problem, array( 'status' => $renewal['status'] ), array( 'status' => self::STATUS_REVIEW ) );
				/** This action is documented in src/Billing/RenewalOrder.php */
				do_action( 'wplc/renewal_needs_review', absint( $renewal['renewal_order_id'] ), $renewal_id, $problem );
				return;
			}
			$next = time() + HOUR_IN_SECONDS;
			self::update_renewal( $renewal_id, array( 'status' => 'failed', 'retry_at' => gmdate( 'Y-m-d H:i:s', $next ), 'last_error' => $stored_code ) );
			AuditLog::record( 'renewal_result_unknown', 'renewal', $renewal_id, $code, array( 'attempt_count' => $attempt ), array( 'attempt_count' => $attempt, 'error_code' => $code ) );
			RenewalScheduler::enqueue( $renewal_id, $next );
			return;
		}

		$period_start = (int) strtotime( (string) $renewal['period_start'] . ' UTC' );
		$next = self::next_retry_at( $attempt, $period_start, time(), self::retry_days() );
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
			array( 'attempt_count' => (int) $renewal['attempt_count'] ),
			array( 'attempt_count' => $attempt, 'error_code' => $code )
		);

		if ( $retry ) {
			RenewalScheduler::enqueue( $renewal_id, $next );
			return;
		}

		self::schedule_grace_end( (int) $license['id'], $grace_end ? $grace_end : time() );
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
			// B-148: dispute follow-ups and fraud warnings never change a license.
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
		$reason = sprintf(
			/* translators: 1: Stripe dispute ID, 2: dispute reason code. */
			__( 'Stripe dispute %1$s (%2$s)', 'wp-license' ),
			sanitize_text_field( (string) ( $payload['dispute_id'] ?? '' ) ),
			sanitize_key( (string) ( $payload['reason'] ?? 'unknown' ) )
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
			AuditLog::record( 'stripe_webhook_mismatch', 'webhook', $inbox_id, __( 'Stripe event metadata did not match its renewal order', 'wp-license' ) );
			return;
		}

		$intent_id = sanitize_text_field( (string) ( $payload['payment_intent_id'] ?? '' ) );
		$paid_by = (string) $order->get_meta( '_stripe_intent_id', true );
		if ( 'payment_intent.succeeded' === $event['event_type'] && $order->is_paid() && 0 === strpos( $paid_by, 'pi_' ) && $paid_by !== $intent_id ) {
			// B-59: the order was paid by another PaymentIntent (the customer on the pay page) and this
			// one succeeded as well. Two payments for one period: never "already reconciled".
			$problem = sprintf(
				/* translators: 1: PaymentIntent of this event, 2: PaymentIntent that paid the order. */
				__( 'Stripe reports a second successful payment %1$s for a renewal order that was paid by %2$s. One of the two payments must be refunded.', 'wp-license' ),
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
		if ( 0 === strpos( $intent_id, 'pi_' ) && (string) $order->get_meta( RenewalOrder::META_PAYMENT_INTENT, true ) !== $intent_id ) {
			$order->update_meta_data( RenewalOrder::META_PAYMENT_INTENT, $intent_id );
			$order->save();
		}

		if ( in_array( $renewal['status'], array( 'cancelled', 'expired', 'refunded', self::STATUS_REVIEW ), true ) || in_array( $license['status'], array( 'revoked', 'suspended' ), true ) ) {
			self::finish_stripe_event( $inbox_id, 'manual_review' );
			AuditLog::record( 'stripe_webhook_manual_review', 'renewal', (int) $renewal['id'], __( 'Payment event arrived for a cancelled, expired, refunded, revoked or suspended record', 'wp-license' ) );
			return;
		}

		if ( ! class_exists( '\\WC_Stripe_Helper' ) ) {
			self::finish_stripe_event( $inbox_id, 'manual_review' );
			return;
		}
		// The charged amount must equal the total of our order, and the order's
		// pre-tax line must still be the net renewal price (integer minor units).
		$expected_amount = \WC_Stripe_Helper::get_stripe_amount( $order->get_total(), $order->get_currency() );
		if (
			! is_int( $expected_amount )
			|| $expected_amount <= 0
			// A failed payment has received nothing; only a success must match the order total.
			|| ( 'payment_intent.succeeded' === $event['event_type'] && absint( $payload['amount_received'] ?? 0 ) !== $expected_amount )
			|| strtolower( (string) $order->get_currency() ) !== strtolower( (string) ( $payload['currency'] ?? '' ) )
			|| strtolower( (string) $license['renewal_currency'] ) !== strtolower( (string) $order->get_currency() )
			|| ! RenewalOrder::net_matches( $order )
		) {
			self::finish_stripe_event( $inbox_id, 'manual_review' );
			AuditLog::record( 'stripe_webhook_amount_mismatch', 'renewal', (int) $renewal['id'], __( 'Stripe amount or currency did not match the WooCommerce renewal order', 'wp-license' ) );
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
			$booked = self::finish_paid( $renewal, $license, $order, 'renewal_reconciled', __( 'Verified Stripe PaymentIntent success reconciled', 'wp-license' ) );
			if ( ! $order->is_paid() ) {
				// B-60: the charge is the transaction ID, and the gateway's own metas make refunds work.
				$charge_id = sanitize_text_field( (string) ( $payload['charge_id'] ?? '' ) );
				StripeRecurringAdapter::store_gateway_meta( $order, $intent_id, $charge_id );
				$order->payment_complete( '' !== $charge_id ? $charge_id : $intent_id );
			}
			self::finish_stripe_event( $inbox_id, $booked ? 'processed' : 'manual_review' );
			return;
		}

		// Synchronous adapter results already own retry scheduling. The webhook is an idempotent audit/reconciliation signal.
		self::finish_stripe_event( $inbox_id, 'failure_observed' );
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
			if ( RenewalOrder::is_manual( $order ) ) {
				ManualRenewal::complete_locked( $order, $renewal, $license );
				return;
			}
			if ( ! in_array( $renewal['status'], array( 'scheduled', 'failed', 'manual_action_required', 'processing' ), true ) ) {
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
			if ( ! RenewalOrder::net_matches( $order ) || strtoupper( (string) $license['renewal_currency'] ) !== strtoupper( (string) $order->get_currency() ) ) {
				RenewalOrder::flag_for_review( $order, $renewal_id, __( 'Paid customer recovery order did not match the stored renewal amount', 'wp-license' ), 'renewal_recovery_amount_mismatch' );
				return;
			}
			self::finish_paid( $renewal, $license, $order, 'renewal_customer_recovered', __( 'Customer paid the renewal order', 'wp-license' ) );
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
							"SELECT id FROM %i WHERE id > %d AND status IN (%s, %s) AND retry_at IS NOT NULL AND retry_at <= %s ORDER BY id ASC LIMIT 500",
							$tables['renewals'],
							$cursor,
							'scheduled',
							'failed',
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
							"SELECT id, renewal_order_id FROM %i WHERE id > %d AND renewal_order_id IS NOT NULL AND renewal_order_id > 0 AND status IN (%s, %s, %s, %s) ORDER BY id ASC LIMIT 200",
							$tables['renewals'],
							$cursor,
							'scheduled',
							'failed',
							'manual_action_required',
							'manual'
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
			}
		);
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
	 * A paid order finishes the renewal. Otherwise the attempt is handed to the
	 * normal retry path as "outcome unknown": the retry repeats the attempt with
	 * the same gateway idempotency key, so a charge that did go through before
	 * the crash is replayed instead of taken twice.
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
				self::book_paid_order( $renewal, $license, $order, 'renewal_stuck_recovered', __( 'Stuck renewal had a paid order', 'wp-license' ) );
				return;
			}
			self::fail_attempt(
				$renewal,
				$license,
				new \WP_Error( self::RESULT_UNKNOWN, __( 'The renewal attempt did not finish and its result is unknown.', 'wp-license' ) ),
				false
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
