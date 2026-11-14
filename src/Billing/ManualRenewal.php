<?php
/**
 * Customer-initiated renewal of an existing license.
 *
 * @package WPLC
 */

namespace WPLC\Billing;

use WPLC\Account\CustomerPortal;
use WPLC\Infrastructure\Database\Schema;
use WPLC\License\AuditLog;
use WPLC\License\LicenseLifecycle;
use WPLC\License\LicenseStateMachine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lets a customer pay a renewal order for the same license and key with any
 * payment method. The renewal row of the period is shared with automatic
 * renewals, so one period can only be paid once.
 */
final class ManualRenewal {

	/**
	 * Register the customer action.
	 *
	 * Paid orders are picked up by RenewalProcessor::handle_paid_order(), the
	 * single entry point that extends a license for a paid renewal order.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'admin_post_wplc_start_renewal', array( self::class, 'handle_start' ) );
		add_action( 'admin_post_nopriv_wplc_start_renewal', array( self::class, 'handle_start' ) );
	}

	/**
	 * Whether the customer may start a manual renewal for this license now.
	 *
	 * @param array<string,mixed> $license_row License row.
	 * @return bool
	 */
	public static function can_renew( array $license_row ): bool {
		global $wpdb;

		if ( ! self::in_window( $license_row, time() ) ) {
			return false;
		}

		// Not while the target period is already paid.
		$tables = Schema::table_names();
		$status = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT status FROM %i WHERE idempotency_key = %s LIMIT 1",
				$tables['renewals'],
				RenewalScheduler::idempotency_key( (int) $license_row['id'], (string) $license_row['paid_until'] )
			)
		);

		return 'paid' !== $status;
	}

	/**
	 * Pure window rule: status active, past_due or expired, and now between
	 * 30 days before paid_until and 30 days after it.
	 *
	 * @param array<string,mixed> $license License row.
	 * @param int                 $now Unix timestamp.
	 * @return bool
	 */
	public static function in_window( array $license, int $now ): bool {
		if ( empty( $license['id'] ) || ! in_array( (string) ( $license['status'] ?? '' ), array( 'active', 'past_due', 'expired' ), true ) ) {
			return false;
		}
		$paid_until = empty( $license['paid_until'] ) ? false : strtotime( (string) $license['paid_until'] . ' UTC' );
		if ( ! $paid_until ) {
			return false;
		}
		$window = LicenseStateMachine::MANUAL_RENEWAL_WINDOW_DAYS * 86400;

		return $now >= $paid_until - $window && $now <= $paid_until + $window;
	}

	/**
	 * New paid-through date of a manual renewal.
	 *
	 * B-185: one rule for every way of paying. While the license has not expired
	 * and the payment arrives before the end of the grace period (paid_until +
	 * LicenseLifecycle::GRACE_DAYS) the new period continues from the old due
	 * date, exactly like an automatic renewal: the customer had access during
	 * those days, so paying late gifts none. A payment for an expired license,
	 * or after the grace period, starts the period at the payment time, because
	 * the gap without access is not billed.
	 *
	 * @param string $paid_until Current paid_until ('Y-m-d H:i:s' UTC), may be empty.
	 * @param string $interval Billing interval.
	 * @param int    $paid_at Unix timestamp of the payment.
	 * @param int    $anchor_day Billing day of month (RenewalScheduler::anchor_day()), zero for none.
	 * @param bool   $expired Whether the license had already expired when it was paid.
	 * @return string 'Y-m-d H:i:s' UTC.
	 */
	public static function next_paid_until( string $paid_until, string $interval, int $paid_at, int $anchor_day = 0, bool $expired = false ): string {
		$current = '' === $paid_until ? 0 : (int) strtotime( $paid_until . ' UTC' );
		$from = $current && ! $expired && $paid_at <= $current + LicenseLifecycle::GRACE_DAYS * 86400 ? $current : max( $current, $paid_at );

		return gmdate( 'Y-m-d H:i:s', RenewalScheduler::period_end( $from, $interval, $anchor_day ) );
	}

	/**
	 * Handle the customer's "renew now" form post.
	 *
	 * @return void
	 */
	public static function handle_start(): void {
		$license_id = isset( $_POST['license_id'] ) ? absint( wp_unslash( $_POST['license_id'] ) ) : 0;
		$account_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/' );
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( $account_url );
			exit;
		}
		check_admin_referer( 'wplc_start_renewal_' . $license_id );

		$order = self::start( $license_id, get_current_user_id() );
		if ( is_wp_error( $order ) ) {
			// admin-post.php has no WooCommerce session, so wc_add_notice() would be lost:
			// the customer portal carries an allow-listed notice code instead.
			$code = (string) $order->get_error_code();
			if ( 'manual_renewal_address_missing' === $code && function_exists( 'wc_get_endpoint_url' ) ) {
				// B-66: without a billing country the tax of the renewal cannot be determined.
				wp_safe_redirect( wc_get_endpoint_url( 'edit-address', 'billing', $account_url ) );
				exit;
			}
			if ( ! in_array( $code, array( 'manual_renewal_unavailable', 'manual_renewal_busy', 'manual_renewal_paid', 'manual_renewal_pending' ), true ) ) {
				$code = 'manual_renewal_unavailable';
			}
			CustomerPortal::redirect_with_notice( $code );
			exit;
		}

		wp_safe_redirect( $order->get_checkout_payment_url() );
		exit;
	}

	/**
	 * Create or reuse the renewal order of the license's next period.
	 *
	 * @param int $license_id License ID.
	 * @param int $customer_id Acting customer.
	 * @return \WC_Order|\WP_Error Order awaiting payment.
	 */
	public static function start( int $license_id, int $customer_id ) {
		global $wpdb;

		$not_available = new \WP_Error( 'manual_renewal_unavailable', __( 'This license cannot be renewed at the moment.', 'wp-license' ) );
		$tables = Schema::table_names();
		$license = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE id = %d LIMIT 1", $tables['licenses'], $license_id ), ARRAY_A );
		// Same answer for a foreign and a missing license.
		if ( ! is_array( $license ) || ! $customer_id || (int) $license['customer_id'] !== $customer_id || ! self::in_window( $license, time() ) ) {
			return $not_available;
		}

		$renewal_id = RenewalScheduler::find_or_create_row( $license, 'manual' );
		if ( ! $renewal_id ) {
			return $not_available;
		}

		if ( ! RenewalProcessor::lock( $renewal_id ) ) {
			return new \WP_Error( 'manual_renewal_busy', __( 'A renewal for this license is being processed. Please try again in a few minutes.', 'wp-license' ) );
		}
		try {
			// Re-read under the lock: an automatic charge may just have finished.
			$license = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE id = %d LIMIT 1", $tables['licenses'], $license_id ), ARRAY_A );
			$renewal = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE id = %d LIMIT 1", $tables['renewals'], $renewal_id ), ARRAY_A );
			if (
				! is_array( $license )
				|| ! is_array( $renewal )
				|| (int) $renewal['license_id'] !== $license_id
				|| (string) $renewal['period_start'] !== (string) $license['paid_until']
				|| ! self::in_window( $license, time() )
			) {
				return $not_available;
			}
			if ( 'paid' === $renewal['status'] ) {
				return new \WP_Error( 'manual_renewal_paid', __( 'This renewal has already been paid.', 'wp-license' ) );
			}
			if ( 'processing' === $renewal['status'] ) {
				return new \WP_Error( 'manual_renewal_busy', __( 'A renewal for this license is being processed. Please try again in a few minutes.', 'wp-license' ) );
			}

			$order = RenewalOrder::get_or_create( $renewal, $license, true );
			if ( is_wp_error( $order ) ) {
				AuditLog::record( 'manual_renewal_order_failed', 'renewal', $renewal_id, $order->get_error_code(), null, null, $customer_id );
				return $order;
			}
			if ( $order->is_paid() ) {
				// Paid but not booked yet (missed hook): book it now instead of asking for money again.
				RenewalProcessor::queue_paid_order_reconciliation( (int) $order->get_id() );
				return new \WP_Error( 'manual_renewal_paid', __( 'Your payment for this renewal has been received and is being processed.', 'wp-license' ) );
			}
			if ( ! $order->needs_payment() ) {
				return new \WP_Error( 'manual_renewal_pending', __( 'A renewal order for this license is already waiting for your payment to arrive.', 'wp-license' ) );
			}
			if ( ! RenewalOrder::has_tax_location( $order ) && function_exists( 'wc_tax_enabled' ) && wc_tax_enabled() ) {
				// B-66: e.g. a license created by hand for a customer who never checked out.
				return new \WP_Error( 'manual_renewal_address_missing', __( 'Please add your billing address before renewing, so that tax can be calculated correctly.', 'wp-license' ) );
			}

			AuditLog::record(
				'manual_renewal_started',
				'renewal',
				$renewal_id,
				__( 'Customer started a manual renewal', 'wp-license' ),
				null,
				array( 'order_id' => (int) $order->get_id(), 'license_id' => $license_id ),
				$customer_id
			);

			return $order;
		} finally {
			RenewalProcessor::unlock( $renewal_id );
		}
	}

	/**
	 * Book a paid manual renewal order. The caller holds the per-renewal lock
	 * and has verified that order, renewal and license belong together and
	 * that the renewal is not paid yet.
	 *
	 * @param \WC_Order           $order Paid renewal order.
	 * @param array<string,mixed> $renewal Renewal row.
	 * @param array<string,mixed> $license License row.
	 * @return bool True when the license was extended.
	 */
	public static function complete_locked( $order, array $renewal, array $license ): bool {
		global $wpdb;

		$renewal_id = (int) $renewal['id'];
		$license_id = (int) $license['id'];
		$order_id = (int) $order->get_id();
		$date_paid = $order->get_date_paid();
		$paid_at = $date_paid ? (int) $date_paid->getTimestamp() : time();
		$paid_until = empty( $license['paid_until'] ) ? 0 : (int) strtotime( (string) $license['paid_until'] . ' UTC' );

		$anchor_day = RenewalScheduler::anchor_day( $license );
		$new_paid_until = self::next_paid_until( (string) $renewal['period_start'], (string) $license['billing_interval'], $paid_at, $anchor_day, 'expired' === $license['status'] );
		$tables = Schema::table_names();
		$now = gmdate( 'Y-m-d H:i:s' );

		// B-32: an earlier run extended the license and stopped before the renewal was marked paid.
		// The license is active by now, so both possible results of that run are compared.
		$from_payment = self::next_paid_until( (string) $renewal['period_start'], (string) $license['billing_interval'], $paid_at, $anchor_day, true );
		$already_applied = (string) $renewal['period_start'] !== (string) $license['paid_until']
			&& in_array( (string) $license['paid_until'], array( $new_paid_until, $from_payment ), true )
			&& 'active' === $license['status'];
		if ( $already_applied ) {
			$new_paid_until = (string) $license['paid_until'];
		}

		$old_status = (string) $license['status'];
		if ( ! $already_applied ) {
			$problem = '';
			if ( (string) $renewal['period_start'] !== (string) $license['paid_until'] ) {
				$problem = __( 'The license period changed after the renewal order was created', 'wp-license' );
			} elseif ( ! LicenseStateMachine::can_apply_manual_renewal( (string) $license['status'], $paid_until, $paid_at ) ) {
				$problem = __( 'The license status or renewal window did not allow a manual renewal', 'wp-license' );
			} elseif ( ! RenewalOrder::net_matches( $order ) ) {
				$problem = __( 'The paid order line did not match the renewal price', 'wp-license' );
			}
			if ( '' !== $problem ) {
				self::reject( $order, $renewal_id, $problem );
				return false;
			}

			// The license first, compare-and-set on its paid_until: a duplicate hook finds the period moved and stops.
			if ( ! LicenseLifecycle::mark_renewed_manually( $license_id, $new_paid_until, $order_id, $paid_at, (string) $renewal['period_start'], false ) ) {
				self::reject( $order, $renewal_id, __( 'The license could not be extended', 'wp-license' ) );
				return false;
			}
		} else {
			LicenseLifecycle::sync_entitlements( $license_id );
		}

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = %s, period_end = %s, renewal_order_id = %d, retry_at = NULL, last_error = %s, updated_at = %s WHERE id = %d AND status <> %s",
				$tables['renewals'],
				'paid',
				$new_paid_until,
				$order_id,
				'',
				$now,
				$renewal_id,
				'paid'
			)
		);

		try {
			RenewalScheduler::schedule_next( $license_id );
		} catch ( \Throwable $error ) {
			// The daily repair run creates the missing period (B-31).
			LicenseLifecycle::log_error( 'schedule_next after manual renewal ' . $renewal_id . ': ' . $error->getMessage() );
		}
		$order->add_order_note(
			sprintf(
				/* translators: 1: license ID, 2: new paid-through date (UTC). */
				__( 'WP License Captain renewed license #%1$d until %2$s (UTC).', 'wp-license' ),
				$license_id,
				$new_paid_until
			)
		);
		AuditLog::record(
			'manual_renewal_paid',
			'renewal',
			$renewal_id,
			__( 'Customer paid a manual renewal order', 'wp-license' ),
			array( 'status' => $renewal['status'], 'period_end' => $renewal['period_end'] ),
			array( 'status' => 'paid', 'period_end' => $new_paid_until, 'order_id' => $order_id )
		);
		if ( ! $already_applied ) {
			LicenseLifecycle::announce_renewal( $license_id, $old_status );
		}

		return true;
	}

	/**
	 * Record a paid order that could not renew its license, for manual review.
	 *
	 * @param \WC_Order $order Order.
	 * @param int       $renewal_id Renewal ID.
	 * @param string    $problem Internal reason.
	 * @return void
	 */
	private static function reject( $order, int $renewal_id, string $problem ): void {
		RenewalOrder::flag_for_review( $order, $renewal_id, $problem, 'manual_renewal_rejected' );
	}
}
