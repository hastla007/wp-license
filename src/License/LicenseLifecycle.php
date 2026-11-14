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
	 * Grace period after the paid period during which a renewing license keeps its rights.
	 */
	public const GRACE_DAYS = 14;

	/** Seconds one expiry run may take before it hands over to a follow-up run (A-80). */
	public const EXPIRY_TIME_BUDGET = 20;

	/**
	 * Why the last admin_extend() / admin_set_status() / admin_restore() call returned false.
	 *
	 * @var string
	 */
	private static $last_refusal = '';

	/**
	 * Register event and cron handlers.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'woocommerce_order_refunded', array( self::class, 'handle_refund' ), 20, 2 );
		add_action( 'woocommerce_order_fully_refunded', array( self::class, 'handle_full_refund' ), 20, 1 );
		add_action( 'woocommerce_order_status_refunded', array( self::class, 'handle_full_refund' ), 20, 1 );
		// B-36: a paid order that is cancelled or fails afterwards (bounced debit).
		add_action( 'woocommerce_order_status_cancelled', array( self::class, 'handle_order_cancelled' ), 20, 1 );
		add_action( 'woocommerce_order_status_failed', array( self::class, 'handle_order_cancelled' ), 20, 1 );
		// B-60: a paid renewal order the gateway cancelled because the payment was refunded at the provider.
		add_action( 'wplc/renewal_order_voided', array( self::class, 'handle_renewal_order_voided' ), 10, 1 );
		// A-25: a deleted customer account is handled by Maintenance\Orphans::on_user_deleted().
		add_action( 'wplc_daily_lifecycle', array( self::class, 'expire_due_licenses' ) );
		add_action( 'wplc_expire_due_licenses', array( self::class, 'expire_due_licenses' ) );
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
	 * Whether charging, job-driven status changes and customer mails are paused (B-01).
	 *
	 * Delegates to WPLC\Maintenance\SiteGuard::billing_paused() (staging clone, restored
	 * database, WPLC_PAUSE_BILLING), which also applies the filter `wplc_billing_paused`.
	 *
	 * @return bool
	 */
	public static function billing_paused(): bool {
		return \WPLC\Maintenance\SiteGuard::billing_paused();
	}

	/**
	 * Run one background task so that a failure cannot stop the tasks after it (B-34).
	 *
	 * Delegates to WPLC\Maintenance\DailyRunner::run_job(): a Throwable is logged and
	 * kept in DailyRunner::failures(), a finished task writes its heartbeat (B-35)
	 * through Diagnostics::mark_run(). Inside a job that DailyRunner already isolates
	 * (the daily run) the task just runs and the outer job reports the result.
	 *
	 * @param string   $job Job name.
	 * @param callable $task Task.
	 * @return bool True when the task finished without an exception.
	 */
	public static function run_guarded( string $job, callable $task ): bool {
		return \WPLC\Maintenance\DailyRunner::run_job( sanitize_key( $job ), $task );
	}

	/**
	 * Write an error to the WooCommerce log (source `wplc`) or the PHP error log.
	 *
	 * @param string $message Message without personal data.
	 * @return void
	 */
	public static function log_error( string $message ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error( $message, array( 'source' => 'wplc' ) );
			return;
		}
		error_log( 'WPLC: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/**
	 * Machine-readable reason of the last refused administrator action.
	 *
	 * One of '', 'invalid_input', 'not_allowed', 'renewal_in_progress',
	 * 'term_changed', 'status_changed', 'term_ended', 'update_failed'.
	 *
	 * @return string
	 */
	public static function last_refusal(): string {
		return self::$last_refusal;
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

		// B-47: the audit names the person who refunded and the documents.
		$actor = is_callable( array( $refund, 'get_refunded_by' ) ) ? absint( $refund->get_refunded_by() ) : 0;
		$actor = $actor ? $actor : get_current_user_id();
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
					/* translators: 1: refund ID, 2: order ID. */
					sprintf( __( 'Refunded order unit (refund #%1$d of order #%2$d)', 'wp-license' ), $refund_id, $order_id ),
					'order_refund',
					$actor
				);
			}
		}
	}

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
				"SELECT id FROM %i WHERE source_order_id = %d AND status IN ('active','past_due','suspended')",
				$tables['licenses'],
				$order_id
			)
		);
		foreach ( $ids as $license_id ) {
			self::transition(
				(int) $license_id,
				'revoked',
				/* translators: %d: order ID. */
				sprintf( __( 'Order #%d refunded in full', 'wp-license' ), $order_id ),
				'order_refund',
				get_current_user_id()
			);
		}
	}

	/**
	 * A paid renewal order was cancelled by the gateway after the payment was refunded at
	 * the provider (B-60). Same consequence as a full refund of the renewal order: the
	 * period is rolled back and automatic renewal is switched off; the license is not suspended.
	 *
	 * @param mixed $order_id Renewal order ID.
	 * @return void
	 */
	public static function handle_renewal_order_voided( $order_id ): void {
		$order_id = absint( $order_id );
		if ( $order_id ) {
			self::handle_renewal_order_refund( $order_id );
		}
	}

	/**
	 * Undo a renewal whose WooCommerce order was refunded in full.
	 *
	 * The license is not revoked: its paid period is rolled back by the length
	 * of the refunded period and automatic renewal is switched off, so it
	 * expires through the normal rules. Safe under duplicate refund hooks,
	 * because the renewal row is claimed with a conditional update.
	 *
	 * B-183: the roll-back does not depend on the refunded period being the
	 * last one. After a goodwill extension or when an older period is refunded
	 * first, paid_until is reduced by the duration of the refunded period. Only
	 * when paid_until lies before the end of that period (an administrator
	 * shortened the term) nothing is rolled back; the order gets a note.
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
				"SELECT * FROM %i WHERE id = %d AND license_id = %d AND renewal_order_id = %d LIMIT 1",
				$tables['renewals'],
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
				"UPDATE %i SET status = %s, retry_at = NULL, updated_at = %s WHERE id = %d AND status = %s",
				$tables['renewals'],
				'refunded',
				$now,
				$renewal_id,
				'paid'
			)
		);
		if ( 1 !== (int) $claimed ) {
			return;
		}

		$rolled_back = 0;
		$new_paid_until = (string) $license['paid_until'];
		$paid_until = empty( $license['paid_until'] ) ? 0 : (int) strtotime( (string) $license['paid_until'] . ' UTC' );
		$period_start = (int) strtotime( (string) $renewal['period_start'] . ' UTC' );
		$period_end = (int) strtotime( (string) $renewal['period_end'] . ' UTC' );
		if ( $paid_until && $period_start && $period_end > $period_start && $paid_until >= $period_end ) {
			$new_paid_until = (string) $license['paid_until'] === (string) $renewal['period_end']
				? (string) $renewal['period_start']
				: gmdate( 'Y-m-d H:i:s', $paid_until - ( $period_end - $period_start ) );
			$rolled_back = (int) $wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET paid_until = %s, next_payment_at = NULL, renewal_enabled = 0, updated_at = %s WHERE id = %d AND paid_until = %s",
					$tables['licenses'],
					$new_paid_until,
					$now,
					$license_id,
					(string) $license['paid_until']
				)
			);
		}
		if ( $rolled_back ) {
			$wpdb->update(
				$tables['entitlements'],
				array( 'ends_at' => $new_paid_until ),
				array( 'license_id' => $license_id ),
				array( '%s' ),
				array( '%d' )
			);
		} else {
			$new_paid_until = (string) $license['paid_until'];
			$wpdb->update(
				$tables['licenses'],
				array( 'renewal_enabled' => 0, 'next_payment_at' => null, 'updated_at' => $now ),
				array( 'id' => $license_id ),
				array( '%d', '%s', '%s' ),
				array( '%d' )
			);
		}
		// Automatic renewal is off now, so no open period may be charged.
		self::cancel_open_renewals( $license_id, false, 'renewal_refunded', __( 'WP License Captain: automatic renewal was switched off after a refund.', 'wp-license' ) );

		$order->add_order_note(
			$rolled_back
				? sprintf(
					/* translators: 1: license ID, 2: new paid-through date (UTC). */
					__( 'WP License Captain: the refunded renewal was taken back. License #%1$d is now paid until %2$s (UTC); automatic renewal is off.', 'wp-license' ),
					$license_id,
					$new_paid_until
				)
				: sprintf(
					/* translators: %d: license ID. */
					__( 'WP License Captain: the term of license #%d was NOT rolled back for this refund, because the term was changed in the meantime. Automatic renewal is off; adjust the term manually if needed.', 'wp-license' ),
					$license_id
				)
		);
		AuditLog::record(
			'renewal_refunded',
			'license',
			$license_id,
			/* translators: %d: order ID. */
			sprintf( __( 'Renewal order #%d was refunded in full', 'wp-license' ), $order_id ),
			array( 'paid_until' => $license['paid_until'], 'renewal_enabled' => (int) $license['renewal_enabled'], 'renewal_id' => $renewal_id ),
			array( 'paid_until' => $new_paid_until, 'renewal_enabled' => 0, 'rolled_back' => (bool) $rolled_back ),
			get_current_user_id()
		);
	}

	/**
	 * Suspend the licenses of a paid order that was cancelled or failed afterwards (B-36).
	 *
	 * Covers licenses issued from the order and the license a paid renewal
	 * order extended. Suspension is reversible by an administrator; nothing is
	 * revoked. Orders that never produced or renewed a license are ignored, so
	 * a failed charge attempt or a closed unpaid renewal order has no effect.
	 *
	 * @param mixed $order_id Order ID.
	 * @return void
	 */
	public static function handle_order_cancelled( $order_id ): void {
		global $wpdb;

		$order_id = absint( $order_id );
		$order = $order_id && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		if ( ! $order || ! is_callable( array( $order, 'get_meta' ) ) ) {
			return;
		}
		/**
		 * Filters whether licenses are suspended when their paid order is cancelled or fails.
		 *
		 * @param bool      $suspend Default true.
		 * @param \WC_Order $order Order.
		 */
		if ( ! apply_filters( 'wplc_suspend_license_on_order_status', true, $order ) ) {
			return;
		}
		if ( 'yes' === $order->get_meta( '_wplc_renewal_voided', true ) ) {
			// The money went back to the customer: handled like a refund of the renewal
			// (handle_renewal_order_voided()), not like a bounced payment.
			return;
		}

		$tables = Schema::table_names();
		$ids = array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT id FROM %i WHERE source_order_id = %d AND status IN ('active','past_due')",
					$tables['licenses'],
					$order_id
				)
			)
		);
		$renewal_id = absint( $order->get_meta( '_wplc_renewal_id', true ) );
		if ( $renewal_id ) {
			$renewed = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT license_id FROM %i WHERE id = %d AND renewal_order_id = %d AND status = %s LIMIT 1",
					$tables['renewals'],
					$renewal_id,
					$order_id,
					'paid'
				)
			);
			if ( $renewed ) {
				$ids[] = $renewed;
			}
		}
		$reason = sprintf(
			/* translators: 1: order ID, 2: order status. */
			__( 'Order #%1$d was set to "%2$s" after the license was paid; review before reactivating', 'wp-license' ),
			$order_id,
			$order->get_status()
		);
		foreach ( array_unique( $ids ) as $license_id ) {
			if ( self::transition( $license_id, 'suspended', $reason, 'order_cancelled', get_current_user_id() ) ) {
				/**
				 * Fires when a license was suspended and needs a decision by the shop.
				 *
				 * @param int    $license_id License ID.
				 * @param string $reason Reason.
				 */
				do_action( 'wplc/license_needs_review', $license_id, $reason );
			}
		}
	}

	/**
	 * Switch automatic renewal off for good and close open automatic renewals.
	 *
	 * Delegates to WPLC\Maintenance\RenewalSwitch::off(), the single writer of this change
	 * (audit action `renewal_disabled`).
	 *
	 * @param int    $license_id License ID.
	 * @param string $reason Reason for the audit log.
	 * @param int    $actor_user_id Acting user, zero for the system.
	 * @return bool
	 */
	public static function disable_renewal( int $license_id, string $reason, int $actor_user_id = 0 ): bool {
		return \WPLC\Maintenance\RenewalSwitch::off( $license_id, $reason, $actor_user_id );
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
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE source_order_id = %d", $tables['licenses'], $order_id ) ) );
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
	 * Refused (false, see last_refusal()) while an automatic charge for the
	 * license is in flight (B-50): the charge would otherwise be booked on top
	 * of, or instead of, the new term. Open renewals of the old period,
	 * including a manual renewal the customer started, are cancelled together
	 * with their unpaid orders (B-46), so a later payment cannot be accepted
	 * without effect.
	 *
	 * @param int         $license_id License ID.
	 * @param string      $paid_until_utc New paid-through date, 'Y-m-d H:i:s' UTC, in the future.
	 * @param string      $reason Required administrator reason.
	 * @param int         $actor_user_id Administrator user ID.
	 * @param string|null $expected_paid_until paid_until the administrator saw in the form (B-133);
	 *                                         null skips the check, '' stands for "no date".
	 * @return bool
	 */
	public static function admin_extend( int $license_id, string $paid_until_utc, string $reason, int $actor_user_id, ?string $expected_paid_until = null ): bool {
		global $wpdb;

		self::$last_refusal = '';
		if ( '' === trim( $reason ) || ! self::valid_utc_datetime( $paid_until_utc ) || $paid_until_utc <= gmdate( 'Y-m-d H:i:s' ) ) {
			self::$last_refusal = 'invalid_input';
			return false;
		}
		$license = self::get_license( $license_id );
		if ( ! $license || ! LicenseStateMachine::allows( (string) $license['status'], 'active', 'admin_extend' ) ) {
			self::$last_refusal = 'not_allowed';
			return false;
		}
		if ( null !== $expected_paid_until && (string) $license['paid_until'] !== $expected_paid_until ) {
			self::$last_refusal = 'term_changed';
			return false;
		}
		if ( self::renewal_in_progress( $license_id ) ) {
			self::$last_refusal = 'renewal_in_progress';
			return false;
		}

		$tables = Schema::table_names();
		$now = gmdate( 'Y-m-d H:i:s' );
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = %s, status_reason = %s, paid_until = %s, next_payment_at = IF( renewal_enabled = 1 AND cancel_at_period_end = 0, %s, NULL ), updated_at = %s WHERE id = %d AND status = %s AND IFNULL( paid_until, '' ) = %s",
				$tables['licenses'],
				'active',
				'',
				$paid_until_utc,
				$paid_until_utc,
				$now,
				$license_id,
				(string) $license['status'],
				(string) $license['paid_until']
			)
		);
		if ( false === $updated || 0 === $updated ) {
			self::$last_refusal = 'update_failed';
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
		self::cancel_open_renewals(
			$license_id,
			true,
			'term_changed',
			__( 'WP License Captain: an administrator changed the term of the license; this renewal order is no longer needed.', 'wp-license' )
		);

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
	 * Whether an automatic charge for the license is in flight.
	 *
	 * A renewal left in `processing` by a crash is resolved first, so it does
	 * not block the administrator for longer than the stuck timeout.
	 *
	 * @param int $license_id License ID.
	 * @return bool
	 */
	private static function renewal_in_progress( int $license_id ): bool {
		global $wpdb;

		$tables = Schema::table_names();
		$query = $wpdb->prepare( "SELECT id FROM %i WHERE license_id = %d AND status = %s", $tables['renewals'], $license_id, 'processing' );
		$ids = (array) $wpdb->get_col( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
		if ( ! $ids ) {
			return false;
		}
		if ( class_exists( '\\WPLC\\Billing\\RenewalProcessor' ) ) {
			foreach ( $ids as $renewal_id ) {
				\WPLC\Billing\RenewalProcessor::reconcile_stuck_renewal( (int) $renewal_id );
			}
			$ids = (array) $wpdb->get_col( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
		}

		return (bool) $ids;
	}

	/**
	 * Cancel the open renewals of a license and close their unpaid orders.
	 *
	 * Renewals in `processing` are never touched: their charge is in flight and
	 * is resolved by the processor.
	 *
	 * @param int    $license_id License ID.
	 * @param bool   $include_manual Also cancel renewals the customer started manually.
	 * @param string $code Reason code stored on the renewal.
	 * @param string $order_note Note for the closed orders.
	 * @return int Number of cancelled renewals.
	 */
	public static function cancel_open_renewals( int $license_id, bool $include_manual, string $code, string $order_note ): int {
		global $wpdb;

		$tables = Schema::table_names();
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, status, renewal_order_id FROM %i WHERE license_id = %d AND status IN ('scheduled','failed','manual_action_required','manual')",
				$tables['renewals'],
				$license_id
			),
			ARRAY_A
		);
		$cancelled = 0;
		foreach ( $rows as $row ) {
			if ( 'manual' === $row['status'] && ! $include_manual ) {
				continue;
			}
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET status = %s, retry_at = NULL, last_error = %s, updated_at = %s WHERE id = %d AND status = %s",
					$tables['renewals'],
					'cancelled',
					substr( sanitize_key( $code ), 0, 100 ),
					gmdate( 'Y-m-d H:i:s' ),
					(int) $row['id'],
					(string) $row['status']
				)
			);
			if ( 1 !== (int) $updated ) {
				continue;
			}
			++$cancelled;
			if ( ! empty( $row['renewal_order_id'] ) && class_exists( '\\WPLC\\Billing\\RenewalOrder' ) ) {
				\WPLC\Billing\RenewalOrder::close_unpaid( (int) $row['renewal_order_id'], $order_note );
			}
		}

		return $cancelled;
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
	 * The flag is set under the lock of the current period's renewal when that
	 * lock is free, so a cancellation never interleaves with the start of a
	 * charge. When a charge is already in flight the flag is set regardless: a
	 * payment never clears it (B-02), so no further period is charged.
	 *
	 * @param int    $license_id License ID.
	 * @param string $reason Cancellation reason.
	 * @param int    $actor_user_id User ID or zero for system action.
	 * @param int    $received_at Unix time at which the cancellation was received; zero for now.
	 * @return bool
	 */
	public static function schedule_cancellation( int $license_id, string $reason = '', int $actor_user_id = 0, int $received_at = 0 ): bool {
		global $wpdb;

		$license = self::get_license( $license_id );
		if ( ! $license || ! in_array( $license['status'], array( 'active', 'past_due' ), true ) ) {
			return false;
		}

		$tables = Schema::table_names();
		$renewal_id = 0;
		$locked = false;
		if ( ! empty( $license['paid_until'] ) && class_exists( '\\WPLC\\Billing\\RenewalProcessor' ) ) {
			$renewal_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM %i WHERE license_id = %d AND period_start = %s LIMIT 1",
					$tables['renewals'],
					$license_id,
					(string) $license['paid_until']
				)
			);
			$locked = $renewal_id && \WPLC\Billing\RenewalProcessor::lock( $renewal_id, 3 );
		}
		try {
			$updated = $wpdb->update(
				$tables['licenses'],
				array(
					'cancel_at_period_end' => 1,
					'next_payment_at' => null,
					'status_reason' => self::clip( sanitize_text_field( $reason ) ),
					'updated_at' => gmdate( 'Y-m-d H:i:s' ),
				),
				array( 'id' => $license_id, 'status' => (string) $license['status'] ),
				array( '%d', '%s', '%s', '%s' ),
				array( '%d', '%s' )
			);
		} finally {
			if ( $locked ) {
				\WPLC\Billing\RenewalProcessor::unlock( $renewal_id );
			}
		}

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

		/**
		 * Fires after the automatic renewal of a license was cancelled for the end of the paid
		 * period, whoever asked for it (account, public cancellation page, administrator, CLI).
		 * The notification package confirms the cancellation by email.
		 *
		 * @param int $license_id   License ID.
		 * @param int $cancelled_at Unix timestamp at which the cancellation was received.
		 */
		if ( empty( $license['cancel_at_period_end'] ) ) {
			// Only the cancellation itself, not a repeated request for an already cancelled license.
			do_action( 'wplc/cancellation_scheduled', $license_id, $received_at > 0 ? $received_at : time() );
		}

		return true;
	}

	/**
	 * Perform an audited administrator status change.
	 *
	 * A-51: reactivating a suspended license whose term ended while it was
	 * suspended moves it to `expired` (and returns true). From there
	 * admin_extend() can give it a new term; before, such a license could
	 * never leave `suspended`.
	 *
	 * @param int $license_id License ID.
	 * @param string $status Target status.
	 * @param string $reason Required administrator reason.
	 * @param int $actor_user_id Administrator user ID.
	 * @return bool
	 */
	public static function admin_set_status( int $license_id, string $status, string $reason, int $actor_user_id ): bool {
		self::$last_refusal = '';
		if ( '' === trim( $reason ) || ! in_array( $status, array( 'active', 'suspended', 'revoked' ), true ) ) {
			self::$last_refusal = 'invalid_input';
			return false;
		}

		$license = self::get_license( $license_id );
		if ( ! $license || ! LicenseStateMachine::allows( (string) $license['status'], $status, 'admin_status_change' ) ) {
			self::$last_refusal = 'not_allowed';
			return false;
		}
		if ( 'active' === $status && ! empty( $license['paid_until'] ) && (string) $license['paid_until'] <= gmdate( 'Y-m-d H:i:s' ) ) {
			if ( 'suspended' === $license['status'] ) {
				return self::transition( $license_id, 'expired', $reason, 'admin_status_change', $actor_user_id );
			}
			self::$last_refusal = 'term_ended';
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
	 * Bring a revoked or suspended license back (B-47, A-51).
	 *
	 * The one implementation of "restore": the way back from a revocation (for
	 * example an order set to "refunded" by mistake) and from "suspended and the
	 * period has ended". Used by the admin screen through SupportActions::restore().
	 *
	 * The caller must check a capability of its own; undoing a revocation is
	 * stronger than a normal status change. Automatic renewal of a restored
	 * revoked license stays off; a suspended license keeps its renewal setting
	 * and gets the renewal of the new period scheduled.
	 *
	 * Refused (false, see last_refusal()) without a reason or actor, when the
	 * status is not the expected one (`status_changed`, a stale form), while an
	 * automatic charge is in flight (`renewal_in_progress`) and when the term
	 * would end in the past (`term_ended`).
	 *
	 * @param int         $license_id License ID.
	 * @param string      $reason Required reason.
	 * @param int         $actor_user_id Administrator user ID.
	 * @param string|null $paid_until_utc New paid-through date ('Y-m-d H:i:s' UTC); null keeps the stored one.
	 * @param string|null $expected_status Status the administrator saw (`revoked` or `suspended`); null accepts both.
	 * @return bool
	 */
	public static function admin_restore( int $license_id, string $reason, int $actor_user_id, ?string $paid_until_utc = null, ?string $expected_status = null ): bool {
		global $wpdb;

		self::$last_refusal = '';
		$license = self::get_license( $license_id );
		if ( '' === trim( $reason ) || $actor_user_id <= 0 || ( null !== $paid_until_utc && ! self::valid_utc_datetime( $paid_until_utc ) ) ) {
			self::$last_refusal = 'invalid_input';
			return false;
		}
		if ( ! $license ) {
			self::$last_refusal = 'not_allowed';
			return false;
		}
		$old_status = (string) $license['status'];
		if ( null !== $expected_status && $old_status !== $expected_status ) {
			self::$last_refusal = 'status_changed';
			return false;
		}
		if ( ! LicenseStateMachine::allows( $old_status, 'active', 'admin_restore' ) ) {
			self::$last_refusal = 'not_allowed';
			return false;
		}
		$paid_until = null === $paid_until_utc ? (string) $license['paid_until'] : $paid_until_utc;
		if ( '' === $paid_until || $paid_until <= gmdate( 'Y-m-d H:i:s' ) ) {
			self::$last_refusal = 'term_ended';
			return false;
		}
		if ( self::renewal_in_progress( $license_id ) ) {
			self::$last_refusal = 'renewal_in_progress';
			return false;
		}

		$tables = Schema::table_names();
		$now = gmdate( 'Y-m-d H:i:s' );
		// A revocation ends the automatic renewal for good; a suspension only pauses the license.
		$renews = 'suspended' === $old_status && ! empty( $license['renewal_enabled'] ) && empty( $license['cancel_at_period_end'] );
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = %s, status_reason = %s, revoked_at = NULL, paid_until = %s, renewal_enabled = IF( %d = 1, renewal_enabled, 0 ), next_payment_at = IF( %d = 1, %s, NULL ), updated_at = %s WHERE id = %d AND status = %s",
				$tables['licenses'],
				'active',
				'',
				$paid_until,
				'suspended' === $old_status ? 1 : 0,
				$renews ? 1 : 0,
				$paid_until,
				$now,
				$license_id,
				$old_status
			)
		);
		if ( 1 !== (int) $updated ) {
			self::$last_refusal = 'status_changed';
			return false;
		}
		$wpdb->update(
			$tables['entitlements'],
			array( 'status' => 'active', 'ends_at' => $paid_until ),
			array( 'license_id' => $license_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		// Open renewals belong to the old period; the scheduler creates the row for the new one.
		self::cancel_open_renewals(
			$license_id,
			false,
			'license_restored',
			__( 'WP License Captain: an administrator restored the license with a new term; this renewal order is no longer needed.', 'wp-license' )
		);
		AuditLog::record(
			'admin_restore',
			'license',
			$license_id,
			$reason,
			array( 'status' => $old_status, 'paid_until' => $license['paid_until'] ),
			array( 'status' => 'active', 'paid_until' => $paid_until ),
			$actor_user_id
		);
		if ( $renews && class_exists( '\\WPLC\\Billing\\RenewalScheduler' ) ) {
			\WPLC\Billing\RenewalScheduler::schedule_next( $license_id );
		}
		self::fire_status_changed( $license_id, $old_status, 'active', 'admin_restore' );

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
	 * Kept for callers outside the renewal processor. The processor uses
	 * apply_automatic_renewal(), which also checks the period.
	 *
	 * @param int    $license_id License ID.
	 * @param string $paid_until New paid-through date in UTC.
	 * @param int    $order_id Renewal order ID.
	 * @return void
	 */
	public static function mark_renewed( int $license_id, string $paid_until, int $order_id = 0 ): void {
		self::apply_renewal( $license_id, $paid_until, $order_id, false, 0, null, true );
	}

	/**
	 * Extend a license for a paid automatic renewal period.
	 *
	 * B-50: only applies while the license still ends where the renewal period
	 * starts. After an administrator moved the term, or a second booking of the
	 * same payment, nothing is changed and false is returned.
	 *
	 * The `wplc/license_status_changed` event is NOT fired here; the caller
	 * fires it through announce_renewal() after its own bookkeeping (B-31), so
	 * an exception in a listener cannot lose the next period.
	 *
	 * @param int    $license_id License ID.
	 * @param string $period_start Start of the paid period = expected paid_until.
	 * @param string $period_end End of the paid period = new paid_until.
	 * @param int    $order_id Renewal order ID.
	 * @return string|false Previous license status, false when nothing was applied.
	 */
	public static function apply_automatic_renewal( int $license_id, string $period_start, string $period_end, int $order_id ) {
		$license = self::get_license( $license_id );
		if ( ! $license || ! self::apply_renewal( $license_id, $period_end, $order_id, false, 0, $period_start, false ) ) {
			return false;
		}

		return (string) $license['status'];
	}

	/**
	 * Fire the status event of a renewal booked with a deferred announcement.
	 *
	 * @param int    $license_id License ID.
	 * @param string $old_status Status before the renewal.
	 * @return void
	 */
	public static function announce_renewal( int $license_id, string $old_status ): void {
		self::fire_status_changed( $license_id, $old_status, 'active', 'renewal_succeeded' );
	}

	/**
	 * Apply a customer-initiated (manual) renewal payment.
	 *
	 * Unlike an automatic charge this may reactivate an expired license while
	 * the payment falls inside the manual renewal window, and it keeps a
	 * scheduled cancellation of automatic renewal in place.
	 *
	 * @param int         $license_id License ID.
	 * @param string      $paid_until New paid-through date in UTC.
	 * @param int         $order_id Renewal order ID.
	 * @param int         $paid_at Unix timestamp of the payment, zero for now.
	 * @param string|null $expected_paid_until paid_until the renewal was calculated from; null skips the check.
	 * @param bool        $announce False to fire the status event later through announce_renewal().
	 * @return bool True when the license was extended.
	 */
	public static function mark_renewed_manually( int $license_id, string $paid_until, int $order_id, int $paid_at = 0, ?string $expected_paid_until = null, bool $announce = true ): bool {
		return self::apply_renewal( $license_id, $paid_until, $order_id, true, $paid_at ? $paid_at : time(), $expected_paid_until, $announce );
	}

	/**
	 * Extend a license after a verified renewal payment.
	 *
	 * B-02: a scheduled cancellation survives every payment. The paid period is
	 * granted, `cancel_at_period_end` stays set and no next charge is planned.
	 *
	 * B-32: the license row and its entitlements change in one transaction
	 * (where WooCommerce transactions are available), and the update is a
	 * compare-and-set on status and paid_until.
	 *
	 * @param int         $license_id License ID.
	 * @param string      $paid_until New paid-through date in UTC.
	 * @param int         $order_id Renewal order ID.
	 * @param bool        $manual Customer-initiated renewal.
	 * @param int         $paid_at Unix timestamp of a manual payment.
	 * @param string|null $expected_paid_until Required current paid_until, null for any.
	 * @param bool        $announce Fire the status event.
	 * @return bool
	 */
	private static function apply_renewal( int $license_id, string $paid_until, int $order_id, bool $manual, int $paid_at, ?string $expected_paid_until, bool $announce ): bool {
		global $wpdb;

		$license = self::get_license( $license_id );
		if ( ! $license || ! self::valid_utc_datetime( $paid_until ) ) {
			return false;
		}
		if ( null !== $expected_paid_until && (string) $license['paid_until'] !== $expected_paid_until ) {
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
		$transaction = function_exists( 'wc_transaction_query' );
		if ( $transaction ) {
			wc_transaction_query( 'start' );
		}
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = %s, status_reason = %s, paid_until = %s, next_payment_at = IF( renewal_enabled = 1 AND cancel_at_period_end = 0, %s, NULL ), updated_at = %s WHERE id = %d AND status = %s AND IFNULL( paid_until, '' ) = %s",
				$tables['licenses'],
				'active',
				'',
				$paid_until,
				$paid_until,
				gmdate( 'Y-m-d H:i:s' ),
				$license_id,
				$old_status,
				(string) $license['paid_until']
			)
		);
		if ( false === $updated || 0 === $updated ) {
			if ( $transaction ) {
				wc_transaction_query( 'rollback' );
			}
			return false;
		}

		$entitlements_updated = $wpdb->update(
			$tables['entitlements'],
			array( 'status' => 'active', 'ends_at' => $paid_until ),
			array( 'license_id' => $license_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		if ( false === $entitlements_updated ) {
			if ( $transaction ) {
				wc_transaction_query( 'rollback' );
				return false;
			}
			// Without a transaction the license is extended; put the entitlements right on the next run.
			self::log_error( 'Entitlements of license ' . $license_id . ' could not be extended.' );
		}
		if ( $transaction ) {
			wc_transaction_query( 'commit' );
		}

		AuditLog::record(
			'renewal_succeeded',
			'license',
			$license_id,
			sprintf(
				/* translators: %d: order ID. */
				$manual ? __( 'Manually renewed from WooCommerce order #%d', 'wp-license' ) : __( 'Renewed from WooCommerce order #%d', 'wp-license' ),
				absint( $order_id )
			),
			array( 'status' => $old_status, 'paid_until' => $license['paid_until'] ),
			array( 'status' => 'active', 'paid_until' => $paid_until, 'entitlements_updated' => false !== $entitlements_updated )
		);
		if ( $announce ) {
			self::fire_status_changed( $license_id, $old_status, 'active', 'renewal_succeeded' );
		}

		return true;
	}

	/**
	 * Bring the entitlements of a license in line with its paid-through date.
	 *
	 * Idempotent repair for a renewal that was interrupted between the license
	 * and the entitlement update (B-32).
	 *
	 * @param int $license_id License ID.
	 * @return void
	 */
	public static function sync_entitlements( int $license_id ): void {
		global $wpdb;

		$license = self::get_license( $license_id );
		if ( ! $license || empty( $license['paid_until'] ) || ! in_array( (string) $license['status'], array( 'active', 'past_due' ), true ) ) {
			return;
		}
		$tables = Schema::table_names();
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET ends_at = %s WHERE license_id = %d AND status = %s AND ( ends_at IS NULL OR ends_at <> %s )",
				$tables['entitlements'],
				(string) $license['paid_until'],
				$license_id,
				'active',
				(string) $license['paid_until']
			)
		);
	}

	/**
	 * Expire a past-due license after its grace period has ended.
	 *
	 * @param int $license_id License ID.
	 * @return void
	 */
	public static function expire_after_grace( int $license_id ): void {
		if ( self::billing_paused() ) {
			return;
		}
		// The grace-end job is scheduled when a renewal fails and is not withdrawn when the
		// customer pays afterwards: never expire a license that (again) grants access.
		$license = self::get_license( $license_id );
		if ( ! $license || self::has_access( $license ) ) {
			return;
		}
		// B-14: the period ended during a processing gap; the daily run comes back to this
		// license (safety net for past-due licenses) once the hold period is over.
		if ( \WPLC\Maintenance\GapGuard::protects( isset( $license['paid_until'] ) ? (string) $license['paid_until'] : null ) ) {
			return;
		}

		self::transition(
			$license_id,
			'expired',
			__( 'Automatic renewal grace period ended', 'wp-license' ),
			'license_expired',
			0,
			$license
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
	 * A-80: works through all due licenses in batches. When the time budget is
	 * used up a follow-up run is scheduled, so mails of one run cannot time out
	 * the daily event and nothing waits for the next day.
	 *
	 * B-33: every license is read again right before it is expired and the
	 * update is conditional on the paid_until that was read, so a license paid
	 * or extended during the run stays active.
	 *
	 * A-18: a renewing license whose first charge attempt has not been made yet
	 * (queue backlog, plugin was inactive) is left to the renewal processor for
	 * the length of the manual renewal window instead of expiring unattempted.
	 *
	 * @return void
	 */
	public static function expire_due_licenses(): void {
		if ( self::billing_paused() ) {
			return;
		}
		self::run_guarded( 'expire_due_licenses', array( self::class, 'expire_due_licenses_now' ) );
	}

	/**
	 * Body of expire_due_licenses(). Public only for the guarded runner.
	 *
	 * @return void
	 */
	public static function expire_due_licenses_now(): void {
		global $wpdb;

		$tables = Schema::table_names();
		$deadline = microtime( true ) + (int) apply_filters( 'wplc_expiry_time_budget', self::EXPIRY_TIME_BUDGET );
		$now = time();
		$now_utc = gmdate( 'Y-m-d H:i:s', $now );
		$unattempted_after = gmdate( 'Y-m-d H:i:s', $now - LicenseStateMachine::MANUAL_RENEWAL_WINDOW_DAYS * DAY_IN_SECONDS );
		$more = false;

		$cursor = 0;
		do {
			$ids = (array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT l.id FROM {$tables['licenses']} l WHERE l.id > %d AND l.status = %s AND l.paid_until IS NOT NULL
					AND ( ( ( l.renewal_enabled = 0 OR l.cancel_at_period_end = 1 ) AND l.paid_until <= %s ) OR l.paid_until <= %s )
					AND NOT ( l.renewal_enabled = 1 AND l.cancel_at_period_end = 0 AND l.paid_until > %s AND EXISTS (
						SELECT 1 FROM {$tables['renewals']} r WHERE r.license_id = l.id AND r.period_start = l.paid_until AND r.status = %s AND r.attempt_count = 0
					) )
					ORDER BY l.id ASC LIMIT 200",
					$cursor,
					'active',
					$now_utc,
					gmdate( 'Y-m-d H:i:s', $now - ( self::GRACE_DAYS + 1 ) * DAY_IN_SECONDS ),
					$unattempted_after,
					'scheduled'
				)
			);
			foreach ( $ids as $license_id ) {
				$cursor = (int) $license_id;
				$license = self::get_license( $cursor );
				if ( ! $license || 'active' !== $license['status'] || self::has_access( $license ) ) {
					continue;
				}
				// B-14: due while the plugin was off, billing was paused or the daily run stood still.
				// The renewal and order catch-up get the hold period before the license may expire.
				if ( \WPLC\Maintenance\GapGuard::protects( isset( $license['paid_until'] ) ? (string) $license['paid_until'] : null, $now ) ) {
					continue;
				}
				self::transition( $cursor, 'expired', __( 'Paid period ended', 'wp-license' ), 'license_expired', 0, $license );
				if ( microtime( true ) > $deadline ) {
					$more = true;
					break 2;
				}
			}
			$fetched = count( $ids );
		} while ( $fetched >= 200 );

		// Safety net for past-due licenses whose grace period is over but whose grace-end job
		// never ran or was never scheduled (e.g. the customer cancelled while a retry was pending).
		$cursor = 0;
		do {
			if ( $more ) {
				break;
			}
			$ids = (array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT id FROM %i WHERE id > %d AND status = %s AND paid_until IS NOT NULL AND paid_until <= %s ORDER BY id ASC LIMIT 200",
					$tables['licenses'],
					$cursor,
					'past_due',
					gmdate( 'Y-m-d H:i:s', $now - self::GRACE_DAYS * DAY_IN_SECONDS )
				)
			);
			foreach ( $ids as $license_id ) {
				$cursor = (int) $license_id;
				self::expire_after_grace( $cursor );
				if ( microtime( true ) > $deadline ) {
					$more = true;
					break 2;
				}
			}
			$fetched = count( $ids );
		} while ( $fetched >= 200 );

		if ( $more ) {
			if ( function_exists( 'as_schedule_single_action' ) ) {
				\WPLC\Compatibility\Requirements::schedule_single_action( time() + 2 * MINUTE_IN_SECONDS, 'wplc_expire_due_licenses', array(), 'wplc' );
			} elseif ( ! wp_next_scheduled( 'wplc_expire_due_licenses' ) ) {
				wp_schedule_single_event( time() + 2 * MINUTE_IN_SECONDS, 'wplc_expire_due_licenses' );
			}
		}
	}

	/**
	 * Shorten a text to the 255 characters of licenses.status_reason (B-176).
	 *
	 * WordPress refuses to write a longer value, which made the whole status
	 * change fail silently.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function clip( string $text ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 255 ) : substr( $text, 0, 255 );
	}

	/**
	 * Apply a status transition and log it.
	 *
	 * @param int                      $license_id License ID.
	 * @param string                   $new_status New status.
	 * @param string                   $reason Reason.
	 * @param string                   $action Audit action.
	 * @param int                      $actor_user_id Acting user, zero for the system.
	 * @param array<string,mixed>|null $expected Row the decision was made on; the transition then only
	 *                                           applies while its paid_until is unchanged (B-33).
	 * @return bool
	 */
	private static function transition( int $license_id, string $new_status, string $reason, string $action, int $actor_user_id = 0, ?array $expected = null ): bool {
		global $wpdb;

		$license = self::get_license( $license_id );
		if ( ! $license || ! LicenseStateMachine::allows( (string) $license['status'], $new_status, $action ) ) {
			self::$last_refusal = 'not_allowed';
			return false;
		}
		if ( null !== $expected && (string) $expected['paid_until'] !== (string) $license['paid_until'] ) {
			return false;
		}

		$tables = Schema::table_names();
		$now = gmdate( 'Y-m-d H:i:s' );
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = %s, status_reason = %s, revoked_at = IF( %d = 1, %s, NULL ), updated_at = %s WHERE id = %d AND status = %s AND IFNULL( paid_until, '' ) = %s",
				$tables['licenses'],
				$new_status,
				self::clip( sanitize_textarea_field( $reason ) ),
				'revoked' === $new_status ? 1 : 0,
				$now,
				$now,
				$license_id,
				(string) $license['status'],
				(string) $license['paid_until']
			)
		);

		if ( false === $updated || 0 === $updated ) {
			self::$last_refusal = 'update_failed';
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
			// A-12: their unpaid orders are closed too, so a later payment is not accepted without
			// effect. An expired license keeps a manual renewal the customer started: paying it
			// inside the renewal window reactivates the license.
			self::cancel_open_renewals(
				$license_id,
				'revoked' === $new_status,
				'license_' . $new_status,
				__( 'WP License Captain: the license ended; this renewal order is closed.', 'wp-license' )
			);
		}

		if ( 'suspended' === $new_status ) {
			$wpdb->update(
				$tables['entitlements'],
				array( 'status' => 'suspended' ),
				array( 'license_id' => $license_id, 'status' => 'active' ),
				array( '%s' ),
				array( '%d', '%s' )
			);
		}
		if ( 'revoked' === $new_status ) {
			// B-190: also the rows a previous suspension left as `suspended`.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET status = %s WHERE license_id = %d AND status IN ('active','suspended')",
					$tables['entitlements'],
					'revoked',
					$license_id
				)
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
				"SELECT id FROM %i WHERE source_order_item_id = %d AND status IN ('active','past_due','suspended') ORDER BY unit_index DESC LIMIT %d",
				$tables['licenses'],
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
			$wpdb->prepare( "SELECT * FROM %i WHERE id = %d LIMIT 1", $tables['licenses'], $license_id ),
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
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET cancel_at_period_end = 0, status_reason = %s, next_payment_at = IF( renewal_enabled = 1, paid_until, NULL ), updated_at = %s WHERE id = %d AND status = %s AND cancel_at_period_end = 1",
				$tables['licenses'],
				'',
				gmdate( 'Y-m-d H:i:s' ),
				$license_id,
				(string) $license['status']
			)
		);
		if ( 1 !== (int) $updated ) {
			return false;
		}

		AuditLog::record(
			'cancellation_withdrawn',
			'license',
			$license_id,
			__( 'Cancellation withdrawn', 'wp-license' ),
			array( 'status' => $license['status'], 'cancel_at_period_end' => 1 ),
			array( 'status' => $license['status'], 'cancel_at_period_end' => 0 ),
			$actor_user_id
		);

		if ( ! empty( $license['renewal_enabled'] ) && class_exists( '\\WPLC\\Billing\\RenewalScheduler' ) ) {
			// A-17: idempotent; brings back the renewal of the current period, also for a past-due
			// license whose retries were cancelled while the cancellation was pending.
			\WPLC\Billing\RenewalScheduler::schedule_next( $license_id );
		}

		return true;
	}
}
