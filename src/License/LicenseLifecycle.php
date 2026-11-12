<?php
/**
 * License state transitions driven by WooCommerce events and scheduled expiry.
 *
 * @package WPLC
 */

namespace WPLC\License;

use WPLC\Infrastructure\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies cancellations, refunds, chargebacks, payment retries and expiry.
 */
final class LicenseLifecycle {

	/**
	 * Register event and cron handlers.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'woocommerce_order_refunded', array( self::class, 'handle_refund' ), 20, 2 );
		add_action( 'wplc_daily_lifecycle', array( self::class, 'expire_due_licenses' ) );
		add_action( 'wplc/renewal_payment_failed', array( self::class, 'mark_past_due' ), 10, 2 );
		add_action( 'wplc/renewal_payment_succeeded', array( self::class, 'mark_renewed' ), 10, 3 );
		add_action( 'wplc/chargeback', array( self::class, 'suspend_for_chargeback' ), 10, 2 );
		self::schedule_daily_event();
	}

	/**
	 * Schedule a daily expiry sweep.
	 *
	 * @return void
	 */
	public static function schedule_daily_event(): void {
		if ( ! wp_next_scheduled( 'wplc_daily_lifecycle' ) ) {
			wp_schedule_event( time() + 300, 'daily', 'wplc_daily_lifecycle' );
		}
	}

	/**
	 * Mark refunded order units revoked. Quantity refunds revoke only the matching
	 * number of licenses; a monetary partial refund without unit quantity does not.
	 *
	 * @param int $order_id Order ID.
	 * @param int $refund_id Refund ID.
	 * @return void
	 */
	public static function handle_refund( int $order_id, int $refund_id ): void {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$refund = wc_get_order( $refund_id );
		if ( ! $refund ) {
			return;
		}

		foreach ( $refund->get_items( 'line_item' ) as $refund_item ) {
			$source_item_id = absint( $refund_item->get_meta( '_refunded_item_id', true ) );
			$quantity       = absint( $refund_item->get_quantity() );
			if ( ! $source_item_id || ! $quantity ) {
				continue;
			}

			$license_ids = self::licenses_for_refunded_units( $source_item_id, $quantity );
			foreach ( $license_ids as $license_id ) {
				self::transition(
					$license_id,
					'revoked',
					'Refunded order unit',
					'order_refund'
				);
			}
		}
	}

	/**
	 * Schedule cancellation for the end of the paid period.
	 *
	 * @param int    $license_id License ID.
	 * @param string $reason Cancellation reason.
	 * @param int    $actor_user_id User ID or zero for system action.
	 * @return bool
	 */
	public static function schedule_cancellation( int $license_id, string $reason = '', int $actor_user_id = 0 ): bool {
		global $wpdb;

		$license = self::get_license( $license_id );
		if ( ! $license || ! in_array( $license['status'], array( 'active', 'past_due' ), true ) ) {
			return false;
		}

		$tables = Schema::table_names();
		$updated = $wpdb->update(
			$tables['licenses'],
			array(
				'cancel_at_period_end' => 1,
				'status_reason' => sanitize_textarea_field( $reason ),
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $license_id ),
			array( '%d', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return false;
		}

		AuditLog::record(
			'cancellation_scheduled',
			'license',
			$license_id,
			$reason,
			array( 'status' => $license['status'], 'cancel_at_period_end' => (int) $license['cancel_at_period_end'] ),
			array( 'status' => $license['status'], 'cancel_at_period_end' => 1 ),
			$actor_user_id
		);

		return true;
	}

	/**
	 * Mark a license past due after a renewal attempt fails.
	 *
	 * @param int    $license_id License ID.
	 * @param string $reason Failure summary.
	 * @return void
	 */
	public static function mark_past_due( int $license_id, string $reason = '' ): void {
		self::transition( $license_id, 'past_due', $reason, 'renewal_failed' );
	}

	/**
	 * Restore a renewed license and update its paid-through date.
	 *
	 * @param int    $license_id License ID.
	 * @param string $paid_until New paid-through date in UTC.
	 * @param int    $order_id Renewal order ID.
	 * @return void
	 */
	public static function mark_renewed( int $license_id, string $paid_until, int $order_id = 0 ): void {
		global $wpdb;

		$license = self::get_license( $license_id );
		if ( ! $license || ! self::valid_utc_datetime( $paid_until ) ) {
			return;
		}

		$tables = Schema::table_names();
		$wpdb->update(
			$tables['licenses'],
			array(
				'status' => 'active',
				'status_reason' => '',
				'paid_until' => $paid_until,
				'next_payment_at' => $paid_until,
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $license_id ),
			array( '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		AuditLog::record(
			'renewal_succeeded',
			'license',
			$license_id,
			'Renewed from WooCommerce order #' . absint( $order_id ),
			array( 'status' => $license['status'], 'paid_until' => $license['paid_until'] ),
			array( 'status' => 'active', 'paid_until' => $paid_until )
		);
	}

	/**
	 * Suspend a license for manual chargeback review.
	 *
	 * @param int    $license_id License ID.
	 * @param string $reason Gateway or administrator summary.
	 * @return void
	 */
	public static function suspend_for_chargeback( int $license_id, string $reason = '' ): void {
		self::transition( $license_id, 'suspended', $reason, 'chargeback' );
	}

	/**
	 * Expire active licenses whose paid period has ended.
	 *
	 * @return void
	 */
	public static function expire_due_licenses(): void {
		global $wpdb;

		$tables = Schema::table_names();
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$tables['licenses']} WHERE status = %s AND paid_until IS NOT NULL AND paid_until <= %s ORDER BY id ASC LIMIT 250",
				'active',
				gmdate( 'Y-m-d H:i:s' )
			)
		);

		foreach ( $ids as $license_id ) {
			self::transition( (int) $license_id, 'expired', 'Paid period ended', 'license_expired' );
		}
	}

	/**
	 * Apply a status transition and log it.
	 *
	 * @param int    $license_id License ID.
	 * @param string $new_status New status.
	 * @param string $reason Reason.
	 * @param string $action Audit action.
	 * @return bool
	 */
	private static function transition( int $license_id, string $new_status, string $reason, string $action ): bool {
		global $wpdb;

		$allowed = array( 'active', 'past_due', 'suspended', 'revoked', 'expired' );
		if ( ! in_array( $new_status, $allowed, true ) ) {
			return false;
		}

		$license = self::get_license( $license_id );
		if ( ! $license || $license['status'] === $new_status ) {
			return false;
		}

		$tables = Schema::table_names();
		$now = gmdate( 'Y-m-d H:i:s' );
		$updated = $wpdb->update(
			$tables['licenses'],
			array(
				'status' => $new_status,
				'status_reason' => sanitize_textarea_field( $reason ),
				'revoked_at' => 'revoked' === $new_status ? $now : null,
				'updated_at' => $now,
			),
			array( 'id' => $license_id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return false;
		}

		if ( in_array( $new_status, array( 'revoked', 'suspended' ), true ) ) {
			$wpdb->update(
				$tables['entitlements'],
				array( 'status' => $new_status ),
				array( 'license_id' => $license_id, 'status' => 'active' ),
				array( '%s' ),
				array( '%d', '%s' )
			);
		}

		AuditLog::record(
			$action,
			'license',
			$license_id,
			$reason,
			array( 'status' => $license['status'], 'paid_until' => $license['paid_until'] ),
			array( 'status' => $new_status, 'paid_until' => $license['paid_until'] )
		);

		return true;
	}

	/**
	 * Find licenses tied to refunded quantity units.
	 *
	 * @param int $order_item_id Order item ID.
	 * @param int $quantity Refunded quantity.
	 * @return int[]
	 */
	private static function licenses_for_refunded_units( int $order_item_id, int $quantity ): array {
		global $wpdb;

		$tables = Schema::table_names();
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$tables['licenses']} WHERE source_order_item_id = %d AND status IN ('active','past_due','suspended') ORDER BY unit_index DESC LIMIT %d",
				$order_item_id,
				$quantity
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Fetch one license row.
	 *
	 * @param int $license_id License ID.
	 * @return array<string,mixed>|null
	 */
	private static function get_license( int $license_id ): ?array {
		global $wpdb;

		$tables = Schema::table_names();
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$tables['licenses']} WHERE id = %d LIMIT 1", $license_id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Validate a MySQL UTC datetime string.
	 *
	 * @param string $value Datetime.
	 * @return bool
	 */
	private static function valid_utc_datetime( string $value ): bool {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
		return $date instanceof \DateTimeImmutable && $date->format( 'Y-m-d H:i:s' ) === $value;
	}
}
