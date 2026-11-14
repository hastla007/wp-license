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
		add_action( 'woocommerce_order_fully_refunded', array( self::class, 'handle_full_refund' ), 20, 1 );
		add_action( 'woocommerce_order_partially_refunded', array( self::class, 'handle_partial_renewal_refund' ), 20, 2 );
		add_action( 'woocommerce_order_status_refunded', array( self::class, 'handle_full_refund' ), 20, 1 );
		add_action( 'wplc_daily_lifecycle', array( self::class, 'expire_due_licenses' ) );
		// Public extension point; fired by chargeback_for_order() for Stripe disputes.
		add_action( 'wplc/chargeback', array( self::class, 'suspend_for_chargeback' ), 10, 2 );
		// Not before `init`: wp_schedule_event() runs the `cron_schedules` filter, and
		// callbacks of other plugins translate their schedule names there.
		if ( did_action( 'init' ) ) {
			self::schedule_daily_event();
		} else {
			add_action( 'init', array( self::class, 'schedule_daily_event' ) );
		}
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
		if ( ! $refund || (int) $refund->get_parent_id() !== $order_id ) {
			return;
		}

		// The hook can fire more than once for one refund; each refund revokes its units only once.
		if ( $refund->get_meta( '_wplc_refund_processed', true ) ) {
			return;
		}
		$refund->update_meta_data( '_wplc_refund_processed', gmdate( 'Y-m-d H:i:s' ) );
		$refund->save();

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
	 * Grace period after the paid period during which a renewing license keeps its rights.
	 */
	public const GRACE_DAYS = 14;

	/**
	 * Whether a license currently grants premium rights.
	 *
	 * Active licenses are entitled until paid_until. A license whose automatic
	 * renewal is pending or failing (past_due) stays entitled for GRACE_DAYS.
	 *
	 * @param array<string,mixed> $license License row.
	 * @param int|null            $now     Unix timestamp, defaults to now.
	 * @return bool
	 */
	public static function has_access( array $license, ?int $now = null ): bool {
		$now    = null === $now ? time() : $now;
		$status = (string) ( $license['status'] ?? '' );
		if ( ! in_array( $status, array( 'active', 'past_due' ), true ) ) {
			return false;
		}
		if ( empty( $license['paid_until'] ) ) {
			return 'active' === $status;
		}
		$paid_until = strtotime( (string) $license['paid_until'] . ' UTC' );
		if ( false === $paid_until ) {
			return false;
		}
		if ( $now < $paid_until ) {
			return true;
		}
		$renewing = 'past_due' === $status
			|| ( ! empty( $license['renewal_enabled'] ) && empty( $license['cancel_at_period_end'] ) );
		return $renewing && $now < $paid_until + self::GRACE_DAYS * 86400;
	}

	/**
	 * UTC datetime that entitlement end dates are compared against.
	 *
	 * Entitlements end with the paid period; during the renewal grace period
	 * they must stay valid, so the comparison point moves back before paid_until.
	 *
	 * @param array<string,mixed> $license License row.
	 * @param int|null            $now     Unix timestamp, defaults to now.
	 * @return string
	 */
	public static function entitlement_cutoff( array $license, ?int $now = null ): string {
		$now        = null === $now ? time() : $now;
		$paid_until = empty( $license['paid_until'] ) ? false : strtotime( (string) $license['paid_until'] . ' UTC' );
		if ( false !== $paid_until && $now >= $paid_until && self::has_access( $license, $now ) ) {
			return gmdate( 'Y-m-d H:i:s', $paid_until - 1 );
		}
		return gmdate( 'Y-m-d H:i:s', $now );
	}

	/**
	 * Revoke every license issued from an order that was refunded in full.
	 *
	 * WooCommerce creates amount-only refunds (no line items) when the order
	 * status is set to "refunded" or the gateway reports a full refund.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public static function handle_full_refund( int $order_id ): void {
		global $wpdb;

		if ( ! $order_id ) {
			return;
		}
		self::handle_renewal_order_refund( $order_id );
		$tables = Schema::table_names();
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$tables['licenses']} WHERE source_order_id = %d AND status IN ('active','past_due','suspended')",
				$order_id
			)
		);
		foreach ( $ids as $license_id ) {
			self::transition( (int) $license_id, 'revoked', 'Order refunded in full', 'order_refund' );
		}
	}

	/**
	 * Record a partial refund of a renewal order (T-50).
	 *
	 * A partial refund is a goodwill or price correction: the customer keeps the renewed period, so the license is not
	 * changed. The refund is written to the audit history and the order notes, and the admin can shorten the term
	 * with "Change term" when the refund was meant to end the period early. A refund that brings the order to zero
	 * is a full refund and rolls the renewal back (handle_full_refund).
	 *
	 * @param int $order_id  Order ID.
	 * @param int $refund_id Refund ID.
	 * @return void
	 */
	public static function handle_partial_renewal_refund( int $order_id, int $refund_id ): void {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		if ( ! $order || ! is_callable( array( $order, 'get_meta' ) ) ) {
			return;
		}
		$license_id = absint( $order->get_meta( '_wplc_renewal_for_license', true ) );
		$renewal_id = absint( $order->get_meta( '_wplc_renewal_id', true ) );
		$refund     = wc_get_order( $refund_id );
		if ( ! $license_id || ! $renewal_id || ! $refund || (int) $refund->get_parent_id() !== $order_id ) {
			return;
		}
		// The hook may fire more than once for one refund.
		if ( $refund->get_meta( '_wplc_partial_renewal_refund_recorded', true ) ) {
			return;
		}
		$refund->update_meta_data( '_wplc_partial_renewal_refund_recorded', gmdate( 'Y-m-d H:i:s' ) );
		$refund->save();

		$amount   = (string) $refund->get_amount();
		$currency = (string) $order->get_currency();
		AuditLog::record(
			'renewal_partially_refunded',
			'license',
			$license_id,
			'Renewal order #' . $order_id . ' was partially refunded (' . $amount . ' ' . $currency . '); the license term is unchanged',
			null,
			array( 'renewal_id' => $renewal_id, 'refund_id' => $refund_id, 'amount' => $amount, 'currency' => $currency )
		);
		$order->add_order_note(
			sprintf(
				/* translators: %d: license ID */
				__( 'Partial refund: the renewed license #%d keeps its term. Use "Change term" on the license if the refund should shorten it.', 'wp-license' ),
				$license_id
			)
		);
	}

	/**
	 * Undo a renewal whose WooCommerce order was refunded in full.
	 *
	 * The license is not revoked: its paid period is rolled back to the start
	 * of the refunded renewal and automatic renewal is switched off, so it
	 * expires through the normal rules. Safe under duplicate refund hooks,
	 * because the renewal row is claimed with a conditional update.
	 *
	 * @param int $order_id Refunded order ID.
	 * @return void
	 */
	private static function handle_renewal_order_refund( int $order_id ): void {
		global $wpdb;

		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		if ( ! $order || ! is_callable( array( $order, 'get_meta' ) ) ) {
			return;
		}
		$license_id = absint( $order->get_meta( '_wplc_renewal_for_license', true ) );
		$renewal_id = absint( $order->get_meta( '_wplc_renewal_id', true ) );
		if ( ! $license_id || ! $renewal_id ) {
			return;
		}

		$tables = Schema::table_names();
		$renewal = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$tables['renewals']} WHERE id = %d AND license_id = %d AND renewal_order_id = %d LIMIT 1",
				$renewal_id,
				$license_id,
				$order_id
			),
			ARRAY_A
		);
		$license = self::get_license( $license_id );
		if ( ! is_array( $renewal ) || ! $license || 'paid' !== $renewal['status'] ) {
			return;
		}

		$now = gmdate( 'Y-m-d H:i:s' );
		$claimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$tables['renewals']} SET status = %s, retry_at = NULL, updated_at = %s WHERE id = %d AND status = %s",
				'refunded',
				$now,
				$renewal_id,
				'paid'
			)
		);
		if ( 1 !== (int) $claimed ) {
			return;
		}

		// Only the latest paid renewal defines paid_until; an older one must not shorten later paid periods.
		$rolled_back = 0;
		if ( (string) $license['paid_until'] === (string) $renewal['period_end'] ) {
			$rolled_back = (int) $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$tables['licenses']} SET paid_until = %s, next_payment_at = NULL, renewal_enabled = 0, updated_at = %s WHERE id = %d AND paid_until = %s",
					(string) $renewal['period_start'],
					$now,
					$license_id,
					(string) $renewal['period_end']
				)
			);
		}
		if ( $rolled_back ) {
			$wpdb->update(
				$tables['entitlements'],
				array( 'ends_at' => (string) $renewal['period_start'] ),
				array( 'license_id' => $license_id ),
				array( '%s' ),
				array( '%d' )
			);
		} else {
			$wpdb->update(
				$tables['licenses'],
				array( 'renewal_enabled' => 0, 'next_payment_at' => null, 'updated_at' => $now ),
				array( 'id' => $license_id ),
				array( '%d', '%s', '%s' ),
				array( '%d' )
			);
		}
		// Automatic renewal is off now, so no open period may be charged.
		self::close_open_renewals( $license_id, $now );

		AuditLog::record(
			'renewal_refunded',
			'license',
			$license_id,
			'Renewal order #' . $order_id . ' was refunded in full',
			array( 'paid_until' => $license['paid_until'], 'renewal_enabled' => (int) $license['renewal_enabled'], 'renewal_id' => $renewal_id ),
			array( 'paid_until' => $rolled_back ? $renewal['period_start'] : $license['paid_until'], 'renewal_enabled' => 0, 'rolled_back' => (bool) $rolled_back )
		);
	}

	/**
	 * Suspend the licenses related to a disputed (charged back) order.
	 *
	 * Covers the license a renewal order extended and licenses issued from the
	 * order itself. Fires the public `wplc/chargeback` action per license; the
	 * suspension is done by this class listening on that action.
	 *
	 * @param int    $order_id Disputed WooCommerce order ID.
	 * @param string $reason Gateway summary.
	 * @return int Number of related licenses.
	 */
	public static function chargeback_for_order( int $order_id, string $reason = '' ): int {
		$license_ids = self::license_ids_for_order( $order_id );
		foreach ( $license_ids as $license_id ) {
			/**
			 * Fires when a payment for a license was disputed.
			 *
			 * @param int    $license_id License ID.
			 * @param string $reason Gateway summary.
			 */
			do_action( 'wplc/chargeback', $license_id, $reason );
		}

		return count( $license_ids );
	}

	/**
	 * Licenses a WooCommerce order paid for (first purchase or renewal).
	 *
	 * @param int $order_id Order ID.
	 * @return int[]
	 */
	public static function license_ids_for_order( int $order_id ): array {
		global $wpdb;

		if ( ! $order_id ) {
			return array();
		}
		$tables = Schema::table_names();
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$tables['licenses']} WHERE source_order_id = %d", $order_id ) ) );
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		$renewed = $order && is_callable( array( $order, 'get_meta' ) ) ? absint( $order->get_meta( '_wplc_renewal_for_license', true ) ) : 0;
		if ( $renewed && self::get_license( $renewed ) ) {
			$ids[] = $renewed;
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Set a new paid-through date without a payment (goodwill, support cases).
	 *
	 * Reactivates an expired or past-due license. Revoked and suspended
	 * licenses must be handled through admin_set_status() first.
	 *
	 * @param int    $license_id License ID.
	 * @param string $paid_until_utc New paid-through date, 'Y-m-d H:i:s' UTC, in the future.
	 * @param string $reason Required administrator reason.
	 * @param int    $actor_user_id Administrator user ID.
	 * @return bool
	 */
	public static function admin_extend( int $license_id, string $paid_until_utc, string $reason, int $actor_user_id ): bool {
		global $wpdb;

		if ( '' === trim( $reason ) || ! self::valid_utc_datetime( $paid_until_utc ) || $paid_until_utc <= gmdate( 'Y-m-d H:i:s' ) ) {
			return false;
		}
		$license = self::get_license( $license_id );
		if ( ! $license || ! LicenseStateMachine::allows( (string) $license['status'], 'active', 'admin_extend' ) ) {
			return false;
		}

		$tables = Schema::table_names();
		$now = gmdate( 'Y-m-d H:i:s' );
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$tables['licenses']} SET status = %s, status_reason = %s, paid_until = %s, next_payment_at = IF( renewal_enabled = 1, %s, NULL ), updated_at = %s WHERE id = %d AND status = %s",
				'active',
				'',
				$paid_until_utc,
				$paid_until_utc,
				$now,
				$license_id,
				(string) $license['status']
			)
		);
		if ( false === $updated || 0 === $updated ) {
			return false;
		}

		$wpdb->update(
			$tables['entitlements'],
			array( 'status' => 'active', 'ends_at' => $paid_until_utc ),
			array( 'license_id' => $license_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		// Open renewals belong to the old period; the scheduler creates the row for the new one.
		self::close_open_renewals( $license_id, $now );

		AuditLog::record(
			'admin_extend',
			'license',
			$license_id,
			$reason,
			array( 'status' => $license['status'], 'paid_until' => $license['paid_until'] ),
			array( 'status' => 'active', 'paid_until' => $paid_until_utc ),
			$actor_user_id
		);
		self::fire_status_changed( $license_id, (string) $license['status'], 'active', 'admin_extend' );

		return true;
	}

	/**
	 * Announce a successful status transition or renewal.
	 *
	 * @param int    $license_id License ID.
	 * @param string $old_status Previous status.
	 * @param string $new_status New status (equal to the old one for a plain renewal).
	 * @param string $action Lifecycle action, e.g. renewal_succeeded, license_expired, admin_extend.
	 * @return void
	 */
	private static function fire_status_changed( int $license_id, string $old_status, string $new_status, string $action ): void {
		/**
		 * Fires after every successful license status transition and renewal.
		 *
		 * @param int    $license_id License ID.
		 * @param string $old_status Previous status.
		 * @param string $new_status New status.
		 * @param string $action Lifecycle action.
		 */
		do_action( 'wplc/license_status_changed', $license_id, $old_status, $new_status, $action );
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
				'status_reason' => substr( sanitize_text_field( $reason ), 0, 255 ),
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $license_id, 'status' => (string) $license['status'] ),
			array( '%d', '%s', '%s' ),
			array( '%d', '%s' )
		);

		if ( false === $updated || 0 === $updated ) {
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
	 * Perform an audited administrator status change.
	 *
	 * @param int $license_id License ID.
	 * @param string $status Target status.
	 * @param string $reason Required administrator reason.
	 * @param int $actor_user_id Administrator user ID.
	 * @return bool
	 */
	public static function admin_set_status( int $license_id, string $status, string $reason, int $actor_user_id ): bool {
		if ( '' === trim( $reason ) || ! in_array( $status, array( 'active', 'suspended', 'revoked' ), true ) ) {
			return false;
		}

		$license = self::get_license( $license_id );
		if ( ! $license || ! LicenseStateMachine::allows( (string) $license['status'], $status, 'admin_status_change' ) ) {
			return false;
		}
		if ( 'active' === $status && ! empty( $license['paid_until'] ) && (string) $license['paid_until'] <= gmdate( 'Y-m-d H:i:s' ) ) {
			return false;
		}

		$changed = self::transition( $license_id, $status, $reason, 'admin_status_change', $actor_user_id );
		if ( $changed && 'active' === $status ) {
			global $wpdb;
			$tables = Schema::table_names();
			$wpdb->update(
				$tables['entitlements'],
				array( 'status' => 'active', 'ends_at' => $license['paid_until'] ),
				array( 'license_id' => $license_id, 'status' => 'suspended' ),
				array( '%s', '%s' ),
				array( '%d', '%s' )
			);
		}

		return $changed;
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
		self::apply_renewal( $license_id, $paid_until, $order_id, false, 0 );
	}

	/**
	 * Apply a customer-initiated (manual) renewal payment.
	 *
	 * Unlike an automatic charge this may reactivate an expired license while
	 * the payment falls inside the manual renewal window, and it keeps a
	 * scheduled cancellation of automatic renewal in place.
	 *
	 * @param int    $license_id License ID.
	 * @param string $paid_until New paid-through date in UTC.
	 * @param int    $order_id Renewal order ID.
	 * @param int    $paid_at Unix timestamp of the payment, zero for now.
	 * @return bool True when the license was extended.
	 */
	public static function mark_renewed_manually( int $license_id, string $paid_until, int $order_id, int $paid_at = 0 ): bool {
		return self::apply_renewal( $license_id, $paid_until, $order_id, true, $paid_at ? $paid_at : time() );
	}

	/**
	 * Extend a license after a verified renewal payment.
	 *
	 * @param int    $license_id License ID.
	 * @param string $paid_until New paid-through date in UTC.
	 * @param int    $order_id Renewal order ID.
	 * @param bool   $manual Customer-initiated renewal.
	 * @param int    $paid_at Unix timestamp of a manual payment.
	 * @return bool
	 */
	private static function apply_renewal( int $license_id, string $paid_until, int $order_id, bool $manual, int $paid_at ): bool {
		global $wpdb;

		$license = self::get_license( $license_id );
		if ( ! $license || ! self::valid_utc_datetime( $paid_until ) ) {
			return false;
		}
		$old_status = (string) $license['status'];
		$old_paid_until = empty( $license['paid_until'] ) ? 0 : (int) strtotime( (string) $license['paid_until'] . ' UTC' );
		$allowed = $manual
			? LicenseStateMachine::can_apply_manual_renewal( $old_status, $old_paid_until, $paid_at )
			: LicenseStateMachine::can_apply_renewal_payment( $old_status );
		if ( ! $allowed ) {
			return false;
		}

		$tables = Schema::table_names();
		// A manual renewal must not silently re-enable an automatic renewal the customer cancelled.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$tables['licenses']} SET status = %s, status_reason = %s, paid_until = %s, next_payment_at = IF( renewal_enabled = 1, %s, NULL ), cancel_at_period_end = IF( %d = 1, cancel_at_period_end, 0 ), updated_at = %s WHERE id = %d AND status = %s",
				'active',
				'',
				$paid_until,
				$paid_until,
				$manual ? 1 : 0,
				gmdate( 'Y-m-d H:i:s' ),
				$license_id,
				$old_status
			)
		);
		if ( false === $updated || 0 === $updated ) {
			return false;
		}

		$entitlements_updated = $wpdb->update(
			$tables['entitlements'],
			array( 'status' => 'active', 'ends_at' => $paid_until ),
			array( 'license_id' => $license_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		AuditLog::record(
			'renewal_succeeded',
			'license',
			$license_id,
			( $manual ? 'Manually renewed' : 'Renewed' ) . ' from WooCommerce order #' . absint( $order_id ),
			array( 'status' => $old_status, 'paid_until' => $license['paid_until'] ),
			array( 'status' => 'active', 'paid_until' => $paid_until, 'entitlements_updated' => false !== $entitlements_updated )
		);
		self::fire_status_changed( $license_id, $old_status, 'active', 'renewal_succeeded' );

		return true;
	}

	/**
	 * Expire a past-due license after its grace period has ended.
	 *
	 * @param int $license_id License ID.
	 * @return void
	 */
	public static function expire_after_grace( int $license_id ): void {
		// The grace-end job is scheduled when a renewal fails and is not withdrawn when the
		// customer pays afterwards: never expire a license that (again) grants access.
		$license = self::get_license( $license_id );
		if ( ! $license || self::has_access( $license ) ) {
			return;
		}

		self::transition(
			$license_id,
			'expired',
			'Automatic renewal grace period ended',
			'license_expired'
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
				"SELECT id FROM {$tables['licenses']} WHERE status = %s AND paid_until IS NOT NULL AND ( ( ( renewal_enabled = 0 OR cancel_at_period_end = 1 ) AND paid_until <= %s ) OR paid_until <= %s ) ORDER BY id ASC LIMIT 250",
				'active',
				gmdate( 'Y-m-d H:i:s' ),
				gmdate( 'Y-m-d H:i:s', time() - ( self::GRACE_DAYS + 1 ) * DAY_IN_SECONDS )
			)
		);

		foreach ( $ids as $license_id ) {
			self::transition( (int) $license_id, 'expired', 'Paid period ended', 'license_expired' );
		}

		// Safety net for past-due licenses whose grace period is over but whose grace-end job
		// never ran or was never scheduled (e.g. the customer cancelled while a retry was pending).
		$past_due_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$tables['licenses']} WHERE status = %s AND paid_until IS NOT NULL AND paid_until <= %s ORDER BY id ASC LIMIT 200",
				'past_due',
				gmdate( 'Y-m-d H:i:s', time() - self::GRACE_DAYS * DAY_IN_SECONDS )
			)
		);
		foreach ( $past_due_ids as $license_id ) {
			self::expire_after_grace( (int) $license_id );
		}
	}

	/**
	 * Stop every open renewal of a license.
	 *
	 * Scheduled and failed rows are cancelled. A row whose last payment result
	 * is unknown may already have been charged: it is not re-checked any more
	 * either, but goes to review so the payment is checked by hand.
	 *
	 * @param int    $license_id License ID.
	 * @param string $now Current UTC datetime.
	 * @return void
	 */
	private static function close_open_renewals( int $license_id, string $now ): void {
		global $wpdb;

		$tables = Schema::table_names();
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$tables['renewals']} SET status = %s, retry_at = NULL, updated_at = %s WHERE license_id = %d AND status IN ('scheduled','failed','manual_action_required')",
				'cancelled',
				$now,
				$license_id
			)
		);
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$tables['renewals']} SET status = %s, retry_at = NULL, updated_at = %s WHERE license_id = %d AND status = %s",
				'review_required',
				$now,
				$license_id,
				'reconciling'
			)
		);
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
	private static function transition( int $license_id, string $new_status, string $reason, string $action, int $actor_user_id = 0 ): bool {
		global $wpdb;

		$license = self::get_license( $license_id );
		if ( ! $license || ! LicenseStateMachine::allows( (string) $license['status'], $new_status, $action ) ) {
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
			array( 'id' => $license_id, 'status' => (string) $license['status'] ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);

		if ( false === $updated || 0 === $updated ) {
			return false;
		}

		if ( in_array( $new_status, array( 'revoked', 'expired' ), true ) ) {
			// A revoked or expired license must never be charged again.
			$wpdb->update(
				$tables['licenses'],
				array( 'renewal_enabled' => 0, 'next_payment_at' => null ),
				array( 'id' => $license_id ),
				array( '%d', '%s' ),
				array( '%d' )
			);
			self::close_open_renewals( $license_id, $now );
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
			array( 'status' => $new_status, 'paid_until' => $license['paid_until'] ),
			$actor_user_id
		);
		self::fire_status_changed( $license_id, (string) $license['status'], $new_status, $action );

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

	/**
	 * Withdraw a scheduled cancellation while the license still runs.
	 *
	 * Ownership must be checked by the caller. Only active and past-due
	 * licenses qualify; an active license must not have passed its paid period.
	 *
	 * @param int $license_id License ID.
	 * @param int $actor_user_id User who withdraws the cancellation.
	 * @return bool
	 */
	public static function resume_renewal( int $license_id, int $actor_user_id ): bool {
		global $wpdb;

		$license = self::get_license( $license_id );
		if (
			! $license
			|| empty( $license['cancel_at_period_end'] )
			|| ! in_array( (string) $license['status'], array( 'active', 'past_due' ), true )
		) {
			return false;
		}
		if ( 'active' === $license['status'] && ! empty( $license['paid_until'] ) && (string) $license['paid_until'] <= gmdate( 'Y-m-d H:i:s' ) ) {
			return false;
		}

		$tables  = Schema::table_names();
		$updated = $wpdb->update(
			$tables['licenses'],
			array(
				'cancel_at_period_end' => 0,
				'status_reason' => '',
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $license_id, 'status' => (string) $license['status'], 'cancel_at_period_end' => 1 ),
			array( '%d', '%s', '%s' ),
			array( '%d', '%s', '%d' )
		);
		if ( 1 !== $updated ) {
			return false;
		}

		AuditLog::record(
			'cancellation_withdrawn',
			'license',
			$license_id,
			'Cancellation withdrawn',
			array( 'status' => $license['status'], 'cancel_at_period_end' => 1 ),
			array( 'status' => $license['status'], 'cancel_at_period_end' => 0 ),
			$actor_user_id
		);

		if ( 'active' === $license['status'] && ! empty( $license['renewal_enabled'] ) && class_exists( '\\WPLC\\Billing\\RenewalScheduler' ) ) {
			// Idempotent: makes sure the upcoming automatic renewal is queued again.
			\WPLC\Billing\RenewalScheduler::schedule_next( $license_id );
		}

		return true;
	}
}
