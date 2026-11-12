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
	 * Register Action Scheduler and WP-Cron handlers.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'wplc_process_renewal', array( self::class, 'process' ) );
		add_action( 'wplc/renewal_grace_ended', array( self::class, 'expire_after_grace' ) );
	}

	/**
	 * Run a scheduled renewal.
	 *
	 * @param int $renewal_id Renewal row ID.
	 * @return void
	 */
	public static function process( int $renewal_id ): void {
		global $wpdb;
		$lock_name = 'wplc_renewal_' . substr( hash( 'sha256', 'renewal:' . $renewal_id ), 0, 48 );
		$locked = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name ) );
		if ( '1' !== (string) $locked ) {
			return;
		}
		try {
			self::process_locked( $renewal_id );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
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
		if ( ! is_array( $renewal ) || in_array( $renewal['status'], array( 'paid', 'cancelled', 'expired' ), true ) ) {
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

		$order = self::get_or_create_order( $renewal, $license );
		if ( is_wp_error( $order ) ) {
			self::fail_attempt( $renewal, $license, $order );
			return;
		}

		self::set_renewal_status( $renewal_id, 'processing', null );
		$result = $adapter->charge( $order, $token_id, (string) $renewal['idempotency_key'] );
		if ( is_wp_error( $result ) || empty( $result['success'] ) ) {
			$error = is_wp_error( $result )
				? $result
				: new \WP_Error( 'renewal_payment_failed', __( 'The payment gateway did not confirm the renewal.', 'wp-license' ) );
			$order->update_status( 'failed', $error->get_error_code() );
			self::fail_attempt( $renewal, $license, $error );
			return;
		}

		$transaction_id = sanitize_text_field( (string) ( $result['transaction_id'] ?? '' ) );
		$order->payment_complete( $transaction_id );
		self::set_renewal_status( $renewal_id, 'paid', null, (int) $order->get_id() );
		LicenseLifecycle::mark_renewed( (int) $license['id'], (string) $renewal['period_end'], (int) $order->get_id() );
		RenewalScheduler::schedule_next( (int) $license['id'] );
		AuditLog::record(
			'renewal_paid',
			'renewal',
			$renewal_id,
			'Renewal was confirmed by the payment adapter',
			array( 'status' => $renewal['status'] ),
			array( 'status' => 'paid', 'order_id' => (int) $order->get_id() )
		);
	}

	/**
	 * Mark a renewal attempt failed, schedule day 1/3/7 retries or end grace.
	 *
	 * @param array<string,mixed> $renewal Renewal row.
	 * @param array<string,mixed> $license License row.
	 * @param \WP_Error $error Failure.
	 * @return void
	 */
	private static function fail_attempt( array $renewal, array $license, \WP_Error $error ): void {
		$attempt = (int) $renewal['attempt_count'] + 1;
		$delays = array( 1, 3, 7 );
		$grace_end = strtotime( (string) $renewal['grace_ends_at'] . ' UTC' );
		$period_start = strtotime( (string) $renewal['period_start'] . ' UTC' );
		$next = isset( $delays[ $attempt - 1 ] ) && $period_start ? max( time() + 30, $period_start + $delays[ $attempt - 1 ] * DAY_IN_SECONDS ) : 0;
		self::set_renewal_status( (int) $renewal['id'], 'failed', $next ? gmdate( 'Y-m-d H:i:s', $next ) : null, null, $attempt );

		LicenseLifecycle::mark_past_due( (int) $license['id'], $error->get_error_code() );
		AuditLog::record(
			'renewal_failed',
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

		$expire_at = $grace_end ?: time();
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( max( time() + 30, $expire_at ), 'wplc/renewal_grace_ended', array( (int) $license['id'] ), 'wplc', true );
		} else {
			wp_schedule_single_event( max( time() + 30, $expire_at ), 'wplc/renewal_grace_ended', array( (int) $license['id'] ) );
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
	 * Create one renewal order once, then reuse it for retries.
	 *
	 * @param array<string,mixed> $renewal Renewal row.
	 * @param array<string,mixed> $license License row.
	 * @return \WC_Order|\WP_Error
	 */
	private static function expire_license( int $license_id ): void {
		global $wpdb;
		$tables = Schema::table_names();
		$now = gmdate( 'Y-m-d H:i:s' );
		$updated = $wpdb->update( $tables['licenses'], array( 'status' => 'expired', 'status_reason' => 'Automatic renewal grace period ended', 'updated_at' => $now ), array( 'id' => $license_id, 'status' => 'past_due' ), array( '%s', '%s', '%s' ), array( '%d', '%s' ) );
		if ( false === $updated || 0 === $updated ) { return; }
		$wpdb->update( $tables['entitlements'], array( 'status' => 'expired' ), array( 'license_id' => $license_id, 'status' => 'active' ), array( '%s' ), array( '%d', '%s' ) );
		AuditLog::record( 'license_expired', 'license', $license_id, 'Automatic renewal grace period ended', array( 'status' => 'past_due' ), array( 'status' => 'expired' ) );
	}

	private static function get_or_create_order( array $renewal, array $license ) {
		if ( ! function_exists( 'wc_get_order' ) || ! function_exists( 'wc_create_order' ) ) {
			return new \WP_Error( 'woocommerce_unavailable', __( 'WooCommerce order services are unavailable.', 'wp-license' ) );
		}

		if ( ! empty( $renewal['renewal_order_id'] ) ) {
			$existing = wc_get_order( (int) $renewal['renewal_order_id'] );
			if ( $existing ) {
				return $existing;
			}
		}

		$source_order = wc_get_order( (int) $license['source_order_id'] );
		$source_item = $source_order ? $source_order->get_item( (int) $license['source_order_item_id'] ) : false;
		$product = $source_item ? $source_item->get_product() : false;
		if ( ! $product ) {
			return new \WP_Error( 'renewal_product_missing', __( 'The source product is no longer available for renewal.', 'wp-license' ) );
		}

		$order = wc_create_order( array( 'customer_id' => (int) $license['customer_id'] ) );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$order->set_currency( (string) $license['renewal_currency'] );
		$order->set_payment_method( (string) $license['payment_gateway_id'] );
		$order->add_product(
			$product,
			1,
			array(
				'subtotal' => (int) $license['renewal_amount_minor'] / ( 10 ** wc_get_price_decimals() ),
				'total' => (int) $license['renewal_amount_minor'] / ( 10 ** wc_get_price_decimals() ),
			)
		);
		$order->update_meta_data( '_wplc_renewal_for_license', (int) $license['id'] );
		$order->update_meta_data( '_wplc_renewal_id', (int) $renewal['id'] );
		$order->update_meta_data( '_wplc_idempotency_key', (string) $renewal['idempotency_key'] );
		$order->calculate_totals();
		$order->save();

		$tables = Schema::table_names();
		global $wpdb;
		$wpdb->update(
			$tables['renewals'],
			array( 'renewal_order_id' => (int) $order->get_id(), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ),
			array( 'id' => (int) $renewal['id'] ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		return $order;
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
