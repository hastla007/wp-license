<?php
/**
 * Renewal processing and retry state machine.
 *
 * @package WPLC
 */

namespace WPLC\Billing;

use WPLC\Gateway\Registry;
use WPLC\Infrastructure\Database\Schema;
use WPLC\License\AuditLog;
use WPLC\License\LicenseLifecycle;

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
		add_action( 'wplc_process_renewal', array( self::class, 'process' ) );
		add_action( 'wplc/renewal_grace_ended', array( self::class, 'expire_after_grace' ) );
		add_action( 'wplc/stripe_webhook_received', array( self::class, 'queue_stripe_event' ), 10, 1 );
		add_action( 'wplc_process_stripe_webhook', array( self::class, 'process_stripe_event' ) );
		// Every way WooCommerce reports a paid order; reconciliation is idempotent.
		add_action( 'woocommerce_payment_complete', array( self::class, 'handle_paid_order' ) );
		add_action( 'woocommerce_order_status_processing', array( self::class, 'handle_paid_order' ) );
		add_action( 'woocommerce_order_status_completed', array( self::class, 'handle_paid_order' ) );
		add_action( 'wplc_reconcile_paid_renewal_order', array( self::class, 'reconcile_paid_renewal_order' ) );
		add_action( 'wplc_daily_lifecycle', array( self::class, 'reconcile_stuck_renewals' ) );
		add_action( 'wplc_reconcile_stuck_renewal', array( self::class, 'reconcile_stuck_renewal' ) );
		add_action( 'wplc/license_status_changed', array( self::class, 'on_license_status_changed' ), 10, 4 );
	}

	/**
	 * Run a scheduled renewal.
	 *
	 * @param int $renewal_id Renewal row ID.
	 * @return void
	 */
	public static function process( int $renewal_id ): void {
		if ( ! self::lock( $renewal_id ) ) {
			RenewalScheduler::enqueue( $renewal_id, time() + 30 );
			return;
		}
		try {
			self::process_locked( $renewal_id );
		} finally {
			self::unlock( $renewal_id );
		}
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
			$wpdb->prepare( "SELECT * FROM {$tables['renewals']} WHERE id = %d LIMIT 1", $renewal_id ),
			ARRAY_A
		);
		if ( ! is_array( $renewal ) || in_array( $renewal['status'], array( 'paid', 'cancelled', 'expired', 'manual_action_required', 'refunded', 'manual' ), true ) ) {
			return;
		}

		$license = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$tables['licenses']} WHERE id = %d LIMIT 1", (int) $renewal['license_id'] ),
			ARRAY_A
		);
		if ( ! is_array( $license ) ) {
			self::set_renewal_status( $renewal_id, 'failed', null );
			return;
		}

		if ( ! in_array( $license['status'], array( 'active', 'past_due' ), true ) ) {
			self::set_renewal_status( $renewal_id, 'cancelled', null );
			return;
		}

		if ( ! empty( $license['cancel_at_period_end'] ) || empty( $license['renewal_enabled'] ) ) {
			self::set_renewal_status( $renewal_id, 'cancelled', null );
			return;
		}

		$now = time();
		$start = strtotime( (string) $renewal['period_start'] . ' UTC' );
		$grace_end = strtotime( (string) $renewal['grace_ends_at'] . ' UTC' );
		if ( $grace_end && $now >= $grace_end ) {
			self::set_renewal_status( $renewal_id, 'expired', null );
			self::expire_license( (int) $license['id'] );
			return;
		}
		if ( $start && $now < $start ) {
			RenewalScheduler::enqueue( $renewal_id, $start );
			return;
		}

		$token_id = absint( $license['payment_token_id'] );
		$customer_id = (int) $license['customer_id'];
		$adapter = Registry::get( (string) $license['payment_gateway_id'] );
		$token = class_exists( 'WC_Payment_Tokens' ) ? \WC_Payment_Tokens::get( $token_id ) : false;
		if (
			! $adapter
			|| ! $token
			|| (int) $token->get_user_id() !== $customer_id
			|| ! $adapter->supports_saved_token( $token_id, $customer_id )
		) {
			self::fail_attempt( $renewal, $license, new \WP_Error( 'renewal_method_unavailable', __( 'A verified automatic renewal payment method is unavailable.', 'wp-license' ) ) );
			return;
		}

		$order = RenewalOrder::get_or_create( $renewal, $license, false );
		if ( is_wp_error( $order ) ) {
			self::fail_attempt( $renewal, $license, $order );
			return;
		}

		// Keep the in-memory row in sync so failure handling never unlinks the order.
		$renewal['renewal_order_id'] = (int) $order->get_id();
		if ( $order->is_paid() ) {
			// Paid by the customer or by an earlier attempt whose result was lost: book it, never charge again.
			self::book_paid_order( $renewal, $license, $order, 'renewal_paid', 'Renewal order was already paid' );
			return;
		}
		if ( ! $order->needs_payment() ) {
			// E.g. on-hold: the customer chose an offline payment for this period.
			self::fail_attempt( $renewal, $license, new \WP_Error( 'renewal_awaiting_customer_payment', __( 'The renewal order is waiting for a payment started by the customer.', 'wp-license' ) ) );
			return;
		}

		self::set_renewal_status( $renewal_id, 'processing', null );
		self::schedule_stuck_check( $renewal_id );
		// The key changes only after a definite failure was recorded (see attempt_idempotency_key()).
		$attempt_key = RenewalScheduler::attempt_idempotency_key( (string) $renewal['idempotency_key'], (int) $renewal['attempt_count'] );
		$result = $adapter->charge( $order, $token_id, $attempt_key );
		if ( is_wp_error( $result ) || empty( $result['success'] ) ) {
			$error = is_wp_error( $result )
				? $result
				: new \WP_Error( 'renewal_payment_failed', __( 'The payment gateway did not confirm the renewal.', 'wp-license' ) );
			$definite = self::RESULT_UNKNOWN !== $error->get_error_code();
			if ( $definite ) {
				$order->update_status( 'failed', $error->get_error_code() );
			}
			self::fail_attempt( $renewal, $license, $error, $definite );
			return;
		}

		$transaction_id = sanitize_text_field( (string) ( $result['transaction_id'] ?? '' ) );
		$order->payment_complete( $transaction_id );
		self::finish_paid( $renewal, $license, $order, 'renewal_paid', 'Renewal was confirmed by the payment adapter' );
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
	 * Book a paid automatic renewal: renewal row, license period, next period.
	 *
	 * @param array<string,mixed> $renewal Renewal row.
	 * @param array<string,mixed> $license License row.
	 * @param \WC_Order           $order Paid renewal order.
	 * @param string              $audit_action Audit action.
	 * @param string              $reason Audit reason.
	 * @return void
	 */
	private static function finish_paid( array $renewal, array $license, $order, string $audit_action, string $reason ): void {
		$renewal_id = (int) $renewal['id'];
		$order_id = (int) $order->get_id();
		self::set_renewal_status( $renewal_id, 'paid', null, $order_id );
		LicenseLifecycle::mark_renewed( (int) $license['id'], (string) $renewal['period_end'], $order_id );
		RenewalScheduler::schedule_next( (int) $license['id'] );
		AuditLog::record(
			$audit_action,
			'renewal',
			$renewal_id,
			$reason,
			array( 'status' => $renewal['status'] ),
			array( 'status' => 'paid', 'order_id' => $order_id )
		);
	}

	/**
	 * Mark a renewal attempt failed, schedule day 1/3/7 retries or end grace.
	 *
	 * Only a definite failure counts as an attempt. When the outcome is unknown
	 * (timeout, crash) the counter stays, so the retry sends the same gateway
	 * idempotency key and the gateway replays the first result instead of
	 * charging again. That retry runs within the hour, well inside the 24 hours
	 * Stripe keeps idempotency keys.
	 *
	 * @param array<string,mixed> $renewal Renewal row.
	 * @param array<string,mixed> $license License row.
	 * @param \WP_Error $error Failure.
	 * @param bool $definite False when the payment outcome is unknown.
	 * @return void
	 */
	private static function fail_attempt( array $renewal, array $license, \WP_Error $error, bool $definite = true ): void {
		$attempt = (int) $renewal['attempt_count'] + ( $definite ? 1 : 0 );
		if ( 'renewal_manual_action_required' === $error->get_error_code() ) {
			self::set_renewal_status( (int) $renewal['id'], 'manual_action_required', null, absint( $renewal['renewal_order_id'] ) ?: null, $attempt );
			LicenseLifecycle::mark_past_due( (int) $license['id'], $error->get_error_code() );
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( absint( $renewal['renewal_order_id'] ) ) : false;
			if ( $order ) {
				$order->add_order_note( __( 'This renewal requires customer authentication. Automatic retries have been stopped.', 'wp-license' ) );
			}
			AuditLog::record( 'renewal_manual_action_required', 'renewal', (int) $renewal['id'], $error->get_error_code(), array( 'attempt_count' => (int) $renewal['attempt_count'] ), array( 'attempt_count' => $attempt, 'status' => 'manual_action_required' ) );
			$grace_end = strtotime( (string) $renewal['grace_ends_at'] . ' UTC' );
			if ( $grace_end ) {
				self::schedule_grace_end( (int) $license['id'], $grace_end );
			}
			return;
		}
		$delays = array( 1, 3, 7 );
		$grace_end = strtotime( (string) $renewal['grace_ends_at'] . ' UTC' );
		$period_start = strtotime( (string) $renewal['period_start'] . ' UTC' );
		if ( $definite ) {
			$next = isset( $delays[ $attempt - 1 ] ) && $period_start ? max( time() + 30, $period_start + $delays[ $attempt - 1 ] * DAY_IN_SECONDS ) : 0;
		} else {
			$next = time() + HOUR_IN_SECONDS;
		}
		self::set_renewal_status( (int) $renewal['id'], 'failed', $next ? gmdate( 'Y-m-d H:i:s', $next ) : null, null, $attempt );

		LicenseLifecycle::mark_past_due( (int) $license['id'], $error->get_error_code() );
		AuditLog::record(
			$definite ? 'renewal_failed' : 'renewal_result_unknown',
			'renewal',
			(int) $renewal['id'],
			$error->get_error_code(),
			array( 'attempt_count' => (int) $renewal['attempt_count'] ),
			array( 'attempt_count' => $attempt, 'error_code' => $error->get_error_code() )
		);

		if ( $next && $grace_end && $next < $grace_end ) {
			RenewalScheduler::enqueue( (int) $renewal['id'], $next );
			return;
		}

		self::schedule_grace_end( (int) $license['id'], $grace_end ?: time() );
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
			as_schedule_single_action( max( time() + 30, $timestamp ), 'wplc/renewal_grace_ended', array( $license_id ), 'wplc', true );
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

	private static function expire_license( int $license_id ): void {
		LicenseLifecycle::expire_after_grace( $license_id );
	}

	/**
	 * After an administrator moved paid_until, schedule the period that now follows.
	 *
	 * @param int    $license_id License ID.
	 * @param string $old_status Previous status.
	 * @param string $new_status New status.
	 * @param string $action Lifecycle action.
	 * @return void
	 */
	public static function on_license_status_changed( $license_id, $old_status = '', $new_status = '', $action = '' ): void {
		if ( 'admin_extend' === $action ) {
			RenewalScheduler::schedule_next( (int) $license_id );
		}
	}

	/** Queue verified webhook reconciliation after the inbox has committed. */
	public static function queue_stripe_event( int $inbox_id ): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'wplc_process_stripe_webhook', array( $inbox_id ), 'wplc', true );
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
			as_enqueue_async_action( 'wplc_reconcile_paid_renewal_order', array( $order_id ), 'wplc', true );
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
			$wpdb->prepare( "SELECT * FROM {$tables['event_inbox']} WHERE id = %d AND source = %s LIMIT 1", $inbox_id, 'stripe' ),
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
			'Stripe dispute %1$s (%2$s)',
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
			$wpdb->prepare( "SELECT * FROM {$tables['renewals']} WHERE id = %d LIMIT 1", absint( $payload['renewal_id'] ) ),
			ARRAY_A
		);
		$license = is_array( $renewal ) ? $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$tables['licenses']} WHERE id = %d LIMIT 1", (int) $renewal['license_id'] ),
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
			AuditLog::record( 'stripe_webhook_mismatch', 'webhook', $inbox_id, 'Stripe event metadata did not match its renewal order' );
			return;
		}

		// The event is verified and linked to our order: remember its PaymentIntent for disputes and support.
		$intent_id = sanitize_text_field( (string) ( $payload['payment_intent_id'] ?? '' ) );
		if ( 0 === strpos( $intent_id, 'pi_' ) && $intent_id !== (string) $order->get_meta( RenewalOrder::META_PAYMENT_INTENT, true ) ) {
			$order->update_meta_data( RenewalOrder::META_PAYMENT_INTENT, $intent_id );
			$order->save();
		}

		if ( in_array( $renewal['status'], array( 'cancelled', 'expired', 'refunded' ), true ) || in_array( $license['status'], array( 'revoked', 'suspended' ), true ) ) {
			self::finish_stripe_event( $inbox_id, 'manual_review' );
			AuditLog::record( 'stripe_webhook_manual_review', 'renewal', (int) $renewal['id'], 'Payment event arrived for a cancelled, expired, refunded, revoked or suspended record' );
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
			|| $expected_amount !== absint( $payload['amount_received'] ?? 0 )
			|| strtolower( (string) $order->get_currency() ) !== strtolower( (string) ( $payload['currency'] ?? '' ) )
			|| strtolower( (string) $license['renewal_currency'] ) !== strtolower( (string) $order->get_currency() )
			|| ! RenewalOrder::net_matches( $order )
		) {
			self::finish_stripe_event( $inbox_id, 'manual_review' );
			AuditLog::record( 'stripe_webhook_amount_mismatch', 'renewal', (int) $renewal['id'], 'Stripe amount or currency did not match the WooCommerce renewal order' );
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
			self::finish_paid( $renewal, $license, $order, 'renewal_reconciled', 'Verified Stripe PaymentIntent success reconciled' );
			if ( ! $order->is_paid() ) {
				$order->payment_complete( $intent_id );
			}
			self::finish_stripe_event( $inbox_id, 'processed' );
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
			$renewal = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['renewals']} WHERE id = %d LIMIT 1", $renewal_id ), ARRAY_A );
			$license = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['licenses']} WHERE id = %d LIMIT 1", $license_id ), ARRAY_A );
			if (
				! is_array( $renewal )
				|| ! is_array( $license )
				|| (int) $renewal['license_id'] !== $license_id
				|| (int) $renewal['renewal_order_id'] !== $order_id
				|| ! RenewalOrder::matches( $order, $renewal, $license )
			) {
				AuditLog::record( 'renewal_recovery_mismatch', 'order', $order_id, 'Paid renewal order metadata did not match its license' );
				return;
			}
			if ( 'paid' === $renewal['status'] ) {
				return;
			}
			if ( RenewalOrder::is_manual( $order ) ) {
				ManualRenewal::complete_locked( $order, $renewal, $license );
				return;
			}
			if ( ! in_array( $renewal['status'], array( 'failed', 'manual_action_required', 'processing' ), true ) ) {
				AuditLog::record( 'renewal_recovery_not_allowed', 'renewal', $renewal_id, 'Renewal status did not allow customer payment recovery' );
				return;
			}
			// The order is ours (meta and renewal link) and paid in full, so its total is what was charged.
			// Verify that its pre-tax line still is the net renewal price and that the currency is unchanged.
			if ( ! RenewalOrder::net_matches( $order ) || strtoupper( (string) $license['renewal_currency'] ) !== strtoupper( (string) $order->get_currency() ) ) {
				AuditLog::record( 'renewal_recovery_amount_mismatch', 'renewal', $renewal_id, 'Paid customer recovery order did not match the stored renewal amount' );
				return;
			}
			self::finish_paid( $renewal, $license, $order, 'renewal_customer_recovered', 'Customer paid the renewal order' );
		} finally {
			self::unlock( $renewal_id );
		}
	}

	/**
	 * Daily sweep for renewals left in `processing` by a crash.
	 *
	 * @return void
	 */
	public static function reconcile_stuck_renewals(): void {
		global $wpdb;

		$tables = Schema::table_names();
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$tables['renewals']} WHERE status = %s AND updated_at <= %s ORDER BY id ASC LIMIT 50",
				'processing',
				gmdate( 'Y-m-d H:i:s', time() - self::STUCK_AFTER_SECONDS )
			)
		);
		foreach ( (array) $ids as $renewal_id ) {
			self::reconcile_stuck_renewal( (int) $renewal_id );
		}
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
		if ( ! $renewal_id || ! self::lock( $renewal_id, 0 ) ) {
			return;
		}
		try {
			$tables = Schema::table_names();
			$renewal = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['renewals']} WHERE id = %d LIMIT 1", $renewal_id ), ARRAY_A );
			if (
				! is_array( $renewal )
				|| 'processing' !== $renewal['status']
				|| (int) strtotime( (string) $renewal['updated_at'] . ' UTC' ) > time() - self::STUCK_AFTER_SECONDS
			) {
				return;
			}
			$license = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['licenses']} WHERE id = %d LIMIT 1", (int) $renewal['license_id'] ), ARRAY_A );
			if ( ! is_array( $license ) ) {
				self::set_renewal_status( $renewal_id, 'failed', null );
				return;
			}
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( absint( $renewal['renewal_order_id'] ) ) : false;
			if ( $order instanceof \WC_Order && RenewalOrder::matches( $order, $renewal, $license ) && $order->is_paid() ) {
				self::book_paid_order( $renewal, $license, $order, 'renewal_stuck_recovered', 'Stuck renewal had a paid order' );
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
			as_schedule_single_action( $at, 'wplc_reconcile_stuck_renewal', array( $renewal_id ), 'wplc', true );
		} elseif ( ! wp_next_scheduled( 'wplc_reconcile_stuck_renewal', array( $renewal_id ) ) ) {
			wp_schedule_single_event( $at, 'wplc_reconcile_stuck_renewal', array( $renewal_id ) );
		}
	}

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
		return 'wplc_renewal_' . substr( hash( 'sha256', 'renewal:' . $renewal_id ), 0, 48 );
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
	 * Update renewal attempt fields.
	 *
	 * @param int $renewal_id Renewal ID.
	 * @param string $status Status.
	 * @param string|null $retry_at Retry time.
	 * @param int|null $order_id Renewal order ID.
	 * @param int|null $attempt_count Attempts.
	 * @return void
	 */
	private static function set_renewal_status( int $renewal_id, string $status, ?string $retry_at, ?int $order_id = null, ?int $attempt_count = null ): void {
		global $wpdb;

		$data = array( 'status' => $status, 'retry_at' => $retry_at, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) );
		$formats = array( '%s', '%s', '%s' );
		if ( null !== $order_id ) {
			$data['renewal_order_id'] = $order_id;
			$formats[] = '%d';
		}
		if ( null !== $attempt_count ) {
			$data['attempt_count'] = $attempt_count;
			$formats[] = '%d';
		}

		$tables = Schema::table_names();
		$wpdb->update( $tables['renewals'], $data, array( 'id' => $renewal_id ), $formats, array( '%d' ) );
	}
}
