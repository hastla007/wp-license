<?php
/**
 * One order and one charge for several automatic renewals of a customer (B-191).
 *
 * @package WPLC
 */

namespace WPLC\Billing;

use WPLC\Gateway\PaymentReference;
use WPLC\Gateway\RecurringGatewayAdapter;
use WPLC\Gateway\Registry;
use WPLC\Infrastructure\Database\Schema;
use WPLC\License\AuditLog;
use WPLC\License\AuditReason;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Combined renewal: switched off by default (setting "Combine automatic
 * renewals", filter `wplc_combined_renewals`).
 *
 * When the first attempt of an automatic renewal is due, the other automatic
 * renewals of the same customer that are due on the same UTC day, with the
 * same payment method, currency, precision and billing interval and no
 * pending plan change, are charged together: one WooCommerce order with one
 * line per license, one charge. Each license keeps its own renewal row, all
 * linked to the order; the first renewal (the leader) carries the order's
 * renewal metas and its idempotency key, so the gateway adapter, Stripe events
 * and admin actions reach the order through it.
 *
 * Rules that keep money safe:
 * - The leader holds the lock of every member and claims each member under
 *   its license lock (P-02) before the one charge is sent. A member whose lock
 *   is busy, or that may not be charged, is left out.
 * - Success books every license; a license changed during the charge goes to
 *   review on its own. A payment whose result is unknown puts every renewal in
 *   `reconciling`; only the leader re-checks, with the same request. A
 *   definite decline counts the attempt on every renewal (retries, grace,
 *   emails as usual) and dissolves the order: the retries run per license.
 *   A shop-side problem (nothing was charged) dissolves the order as well.
 * - A member's own job never charges while it is linked to the order: it
 *   follows the state of the order and the leader (follow()).
 * - Manual renewal stays per license: a member that is renewed by hand leaves
 *   the combined order first (RenewalOrder::get_or_create()).
 */
final class CombinedRenewal {

	/** Default of the most licenses in one combined order (setting combined_renewal_max_lines, SET-LIC-41). */
	public const MAX_LINES = 20;

	/** Option of the settings checkbox. */
	public const OPTION = 'wplc_combined_renewals';

	/**
	 * Most licenses in one combined order: the setting "Combined renewal: most licenses per order".
	 *
	 * @return int
	 */
	public static function max_lines(): int {
		return \WPLC\Settings\Registry::int( 'combined_renewal_max_lines' );
	}

	/**
	 * Whether automatic renewals are combined.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		$setting = (bool) \WPLC\Admin\Settings::get( 'combined_renewals' );
		/**
		 * Filters whether due automatic renewals of one customer are combined into one order and one charge (B-191).
		 *
		 * @param bool $enabled Default: the setting "Combine automatic renewals" (off).
		 */
		return (bool) apply_filters( 'wplc_combined_renewals', $setting );
	}

	/**
	 * The combined order a renewal row is linked to, if any.
	 *
	 * @param array<string,mixed> $renewal Renewal row.
	 * @return \WC_Order|null
	 */
	public static function linked_order( array $renewal ) {
		$order = RenewalProcessor::renewal_order( $renewal );

		return $order && RenewalOrder::is_combined( $order ) ? $order : null;
	}

	/**
	 * Unlink a renewal from a combined order; close the order once no renewal links it.
	 *
	 * Only for an order that was not paid. A paid order stays as it is.
	 *
	 * @param int $renewal_id Renewal ID.
	 * @param int $order_id Combined order ID.
	 * @return void
	 */
	public static function release( int $renewal_id, int $order_id ): void {
		global $wpdb;

		$tables = Schema::table_names();
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET renewal_order_id = NULL, updated_at = %s WHERE id = %d AND renewal_order_id = %d', $tables['renewals'], gmdate( 'Y-m-d H:i:s' ), $renewal_id, $order_id ) );
		$still = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE renewal_order_id = %d', $tables['renewals'], $order_id ) );
		if ( 0 === $still ) {
			RenewalOrder::close_unpaid( $order_id, __( 'WP License Captain: the licenses of this combined renewal order are renewed one by one now; the order is closed.', 'wp-license' ) );
		}
	}

	/**
	 * Charge the first attempt of a due renewal together with the other due renewals of the customer.
	 *
	 * The caller holds the leader's renewal lock and has checked the leader
	 * like a single renewal (license, retry time, advance notice, adapter,
	 * token).
	 *
	 * @param array<string,mixed>     $renewal Leader renewal row (status scheduled, first attempt, no order).
	 * @param array<string,mixed>     $license Leader license row.
	 * @param RecurringGatewayAdapter $adapter Gateway adapter of the license.
	 * @param int                     $token_id Resolved payment token.
	 * @return bool True when the renewal was handled here; false to renew it on its own.
	 */
	public static function charge_group( array $renewal, array $license, RecurringGatewayAdapter $adapter, int $token_id ): bool {
		if ( '' !== (string) ( $license['pending_plan_key'] ?? '' ) ) {
			return false;
		}
		$members = array();
		try {
			foreach ( self::candidates( $renewal, $license ) as $candidate_id ) {
				if ( count( $members ) >= self::max_lines() - 1 ) {
					break;
				}
				$member = self::lock_member( $candidate_id, $license, $adapter, $token_id );
				if ( null !== $member ) {
					$members[] = $member;
				}
			}
			if ( ! $members ) {
				return false;
			}
			$group = array_merge( array( array( 'renewal' => $renewal, 'license' => $license ) ), $members );

			$order = RenewalOrder::create_combined( $group );
			if ( is_wp_error( $order ) ) {
				// Each license is renewed on its own and reports its problem itself.
				return false;
			}
			foreach ( $group as $index => $member ) {
				if ( ! RenewalOrder::link( (int) $member['renewal']['id'], (int) $order->get_id() ) ) {
					foreach ( $group as $linked ) {
						self::release( (int) $linked['renewal']['id'], (int) $order->get_id() );
					}
					return false;
				}
				$group[ $index ]['renewal']['renewal_order_id'] = (int) $order->get_id();
			}

			$attempt_time = time();
			$fields       = array( 'last_attempt_at' => gmdate( 'Y-m-d H:i:s', $attempt_time ) );
			$claimed      = array();
			foreach ( $group as $index => $member ) {
				$claim = RenewalProcessor::claim_for_charge( $member['renewal'], $fields );
				if ( '' !== $claim['problem'] ) {
					self::abandon( $group, $claimed, (int) $order->get_id() );
					// The next run builds the group again without the renewal that changed.
					RenewalScheduler::enqueue( (int) $renewal['id'], time() + MINUTE_IN_SECONDS );
					return true;
				}
				$claimed[]                  = (int) $member['renewal']['id'];
				$group[ $index ]['license'] = (array) $claim['license'];
				$group[ $index ]['renewal'] = array_merge( $member['renewal'], $fields );
			}
			foreach ( $group as $member ) {
				RenewalProcessor::schedule_stuck_check( (int) $member['renewal']['id'] );
			}
			$order->add_order_note(
				sprintf(
					/* translators: %s: comma-separated license IDs. */
					__( 'WP License Captain: combined renewal of licenses %s; one charge for all of them.', 'wp-license' ),
					implode( ', ', array_map( static function ( $member ) {
						return '#' . (int) $member['license']['id'];
					}, $group ) )
				)
			);
			AuditLog::record( 'renewal_combined', 'renewal', (int) $renewal['id'], 'renewal_combined', null, array( 'order_id' => (int) $order->get_id(), 'renewals' => array_map( 'intval', array_keys( RenewalOrder::links( $order ) ) ) ) );

			self::charge( $group, $order, $adapter, $token_id, $attempt_time, false );

			return true;
		} finally {
			foreach ( $members as $member ) {
				RenewalProcessor::unlock( (int) $member['renewal']['id'] );
			}
		}
	}

	/**
	 * Re-check a combined order whose payment result is unknown, with the same request.
	 *
	 * The caller holds the leader's lock; the leader is in `reconciling`.
	 *
	 * @param array<string,mixed> $renewal Leader renewal row.
	 * @param \WC_Order           $order Combined order.
	 * @return void
	 */
	public static function recheck( array $renewal, $order ): void {
		global $wpdb;

		$tables = Schema::table_names();
		$links  = RenewalOrder::links( $order );
		$locked = array();
		try {
			foreach ( array_keys( $links ) as $renewal_id ) {
				if ( (int) $renewal_id === (int) $renewal['id'] ) {
					continue;
				}
				if ( ! RenewalProcessor::lock( (int) $renewal_id, 0 ) ) {
					RenewalScheduler::enqueue( (int) $renewal['id'], time() + MINUTE_IN_SECONDS );
					return;
				}
				$locked[] = (int) $renewal_id;
			}
			$group = array();
			foreach ( array_keys( $links ) as $renewal_id ) {
				$row = (int) $renewal_id === (int) $renewal['id'] ? $renewal : $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', $tables['renewals'], (int) $renewal_id ), ARRAY_A );
				if ( ! is_array( $row ) || (int) $row['renewal_order_id'] !== (int) $order->get_id() || ! in_array( (string) $row['status'], array( RenewalProcessor::STATUS_RECONCILING, 'processing' ), true ) ) {
					continue;
				}
				if ( 'processing' === (string) $row['status'] ) {
					// A member whose own stuck check did not run yet waits for the same result.
					$row['status'] = RenewalProcessor::STATUS_RECONCILING;
					RenewalProcessor::update_renewal( (int) $row['id'], array( 'status' => RenewalProcessor::STATUS_RECONCILING ) );
				}
				$license = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', $tables['licenses'], (int) $row['license_id'] ), ARRAY_A );
				$group[] = array( 'renewal' => $row, 'license' => is_array( $license ) ? $license : null );
			}

			if ( $order->is_paid() ) {
				foreach ( $group as $member ) {
					if ( null !== $member['license'] ) {
						RenewalProcessor::book_paid_order( $member['renewal'], $member['license'], $order, 'renewal_paid', AuditReason::RENEWAL_ALREADY_PAID );
					}
				}
				return;
			}
			// Re-sending is the charge if the first request never arrived: every license must still want it.
			foreach ( $group as $member ) {
				$problem = null === $member['license'] ? 'license_missing' : RenewalProcessor::reconciliation_problem( $member['renewal'], $member['license'], time() );
				if ( '' === $problem && null !== $member['license'] ) {
					$problem = RenewalProcessor::charge_blocker( $member['renewal'], $member['license'] );
				}
				if ( '' !== $problem ) {
					self::review_all( $group, $problem );
					return;
				}
			}
			if ( ! RenewalOrder::net_matches( $order ) ) {
				self::review_all( $group, 'renewal_amount_mismatch' );
				return;
			}
			$leader  = $group[0]['license'] ?? null;
			$adapter = is_array( $leader ) ? Registry::get( (string) $leader['payment_gateway_id'] ) : null;
			$token   = $adapter ? PaymentReference::resolve_token( $adapter, $leader ) : 0;
			if ( ! $adapter || is_wp_error( $token ) || (int) $group[0]['renewal']['id'] !== (int) $renewal['id'] ) {
				self::review_all( $group, ! $adapter ? 'renewal_gateway_unavailable' : 'renewal_payment_method_missing' );
				return;
			}
			foreach ( $group as $index => $member ) {
				$claim = RenewalProcessor::claim_for_charge( $member['renewal'], array() );
				if ( '' !== $claim['problem'] ) {
					self::review_all( $group, $claim['problem'] );
					return;
				}
				$group[ $index ]['license'] = (array) $claim['license'];
			}
			foreach ( $group as $member ) {
				RenewalProcessor::schedule_stuck_check( (int) $member['renewal']['id'] );
			}
			self::charge( $group, $order, $adapter, (int) $token, time(), true );
		} finally {
			foreach ( $locked as $renewal_id ) {
				RenewalProcessor::unlock( $renewal_id );
			}
		}
	}

	/**
	 * The job of a member renewal while it is linked to a combined order: follow the order.
	 *
	 * The caller holds the member's lock. A member never charges the order.
	 *
	 * @param array<string,mixed> $renewal Member renewal row.
	 * @param \WC_Order           $order Combined order.
	 * @return array<string,mixed>|null Null when handled; the fresh row when the member renews on its own now.
	 */
	public static function follow( array $renewal, $order ): ?array {
		global $wpdb;

		$tables     = Schema::table_names();
		$renewal_id = (int) $renewal['id'];
		if ( $order->is_paid() ) {
			if ( ! in_array( (string) $renewal['status'], array( 'paid', 'refunded' ), true ) ) {
				RenewalProcessor::reconcile_paid_locked( $order, $renewal_id, (int) $renewal['license_id'] );
			}
			return null;
		}
		$leader_id = absint( $order->get_meta( RenewalOrder::META_RENEWAL, true ) );
		$leader    = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', $tables['renewals'], $leader_id ), ARRAY_A );
		$waiting   = in_array( (string) $renewal['status'], array( 'processing', RenewalProcessor::STATUS_RECONCILING ), true );
		if ( ! $waiting ) {
			// Not waiting for a charge of this order (any more): renew on its own.
			self::release( $renewal_id, (int) $order->get_id() );
			$fresh = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', $tables['renewals'], $renewal_id ), ARRAY_A );
			return is_array( $fresh ) ? $fresh : null;
		}
		$leader_status = is_array( $leader ) ? (string) $leader['status'] : '';
		$same_order    = is_array( $leader ) && (int) $leader['renewal_order_id'] === (int) $order->get_id();
		if ( 'processing' === $leader_status && $same_order ) {
			RenewalScheduler::enqueue( $renewal_id, time() + RenewalProcessor::STUCK_AFTER_SECONDS );
			return null;
		}
		if ( RenewalProcessor::STATUS_RECONCILING === $leader_status && $same_order ) {
			if ( 'processing' === (string) $renewal['status'] ) {
				// The run that charged the order died: wait for the same result as the leader.
				RenewalProcessor::mark_result_unknown(
					$renewal,
					new \WP_Error( RenewalProcessor::RESULT_UNKNOWN, __( 'The renewal attempt did not finish and its result is unknown.', 'wp-license' ) ),
					RenewalProcessor::utc_ts( $renewal['updated_at'] ?? null )
				);
				return null;
			}
			$next = max( time() + MINUTE_IN_SECONDS, RenewalProcessor::utc_ts( $leader['retry_at'] ?? null ) + MINUTE_IN_SECONDS );
			RenewalScheduler::enqueue( $renewal_id, min( $next, time() + RenewalProcessor::RESULT_UNKNOWN_CHECK_INTERVAL ) );
			return null;
		}
		$declined = is_array( $leader ) && in_array( $leader_status, array( 'failed', 'manual_action_required' ), true ) && (int) $leader['attempt_count'] > (int) $renewal['attempt_count'];
		if ( $declined ) {
			// The result of the combined charge was resolved as a decline (event, administrator).
			$license = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', $tables['licenses'], (int) $renewal['license_id'] ), ARRAY_A );
			if ( is_array( $license ) ) {
				$data = 'manual_action_required' === $leader_status ? array( 'retryable' => false ) : null;
				RenewalProcessor::fail_attempt( $renewal, $license, new \WP_Error( '' !== (string) $leader['last_error'] ? (string) $leader['last_error'] : 'renewal_payment_not_taken', __( 'The combined renewal payment was declined.', 'wp-license' ), $data ) );
			}
			self::release( $renewal_id, (int) $order->get_id() );
			return null;
		}
		// The leader is in review or was closed while this result is unknown: an administrator decides.
		RenewalProcessor::flag_for_review( $renewal, 'combined_order_in_review' );

		return null;
	}

	/**
	 * Book every renewal of a paid combined order (paid-order hooks, Stripe events, review).
	 *
	 * @param \WC_Order $order Paid combined order.
	 * @return void
	 */
	public static function reconcile_paid( $order ): void {
		foreach ( RenewalOrder::links( $order ) as $renewal_id => $license_id ) {
			if ( ! RenewalProcessor::lock( (int) $renewal_id ) ) {
				RenewalProcessor::queue_paid_order_reconciliation( (int) $order->get_id() );
				continue;
			}
			try {
				RenewalProcessor::reconcile_paid_locked( $order, (int) $renewal_id, (int) $license_id );
			} finally {
				RenewalProcessor::unlock( (int) $renewal_id );
			}
		}
	}

	/**
	 * Other renewals of the customer that may join the leader's charge.
	 *
	 * @param array<string,mixed> $renewal Leader renewal row.
	 * @param array<string,mixed> $license Leader license row.
	 * @return int[] Renewal IDs.
	 */
	private static function candidates( array $renewal, array $license ): array {
		global $wpdb;

		$tables = Schema::table_names();
		$day    = substr( (string) $renewal['period_start'], 0, 10 );
		if ( 10 !== strlen( $day ) ) {
			return array();
		}
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from Schema::table_names(), values prepared.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT r.id FROM {$tables['renewals']} r JOIN {$tables['licenses']} l ON l.id = r.license_id
				WHERE r.id <> %d AND r.status = %s AND r.attempt_count = 0 AND ( r.renewal_order_id IS NULL OR r.renewal_order_id = 0 )
				AND r.period_start = l.paid_until AND r.period_start >= %s AND r.period_start < %s
				AND l.customer_id = %d AND l.renewal_enabled = 1 AND l.cancel_at_period_end = 0 AND l.status IN (%s, %s)
				AND l.payment_gateway_id = %s AND l.payment_token_id = %d AND l.renewal_currency = %s AND l.renewal_currency_decimals = %d
				AND l.billing_interval = %s AND l.pending_plan_key = '' AND l.renewal_amount_minor > 0
				ORDER BY r.period_start ASC, r.id ASC LIMIT %d",
				(int) $renewal['id'],
				'scheduled',
				$day . ' 00:00:00',
				gmdate( 'Y-m-d', (int) strtotime( $day . ' 00:00:00 UTC' ) + DAY_IN_SECONDS ) . ' 00:00:00',
				(int) $license['customer_id'],
				'active',
				'past_due',
				(string) $license['payment_gateway_id'],
				(int) $license['payment_token_id'],
				(string) $license['renewal_currency'],
				(int) $license['renewal_currency_decimals'],
				(string) $license['billing_interval'],
				self::max_lines() * 2
			)
		);
		// phpcs:enable

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Lock a candidate and check it like a renewal of its own; null when it cannot join.
	 *
	 * @param int                     $renewal_id Candidate renewal.
	 * @param array<string,mixed>     $leader_license Leader license row.
	 * @param RecurringGatewayAdapter $adapter Gateway adapter.
	 * @param int                     $token_id Leader's resolved token.
	 * @return array{renewal:array<string,mixed>,license:array<string,mixed>}|null The candidate stays locked when returned.
	 */
	private static function lock_member( int $renewal_id, array $leader_license, RecurringGatewayAdapter $adapter, int $token_id ): ?array {
		global $wpdb;

		if ( ! RenewalProcessor::lock( $renewal_id, 0 ) ) {
			return null;
		}
		$tables  = Schema::table_names();
		$renewal = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', $tables['renewals'], $renewal_id ), ARRAY_A );
		$license = is_array( $renewal ) ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', $tables['licenses'], (int) $renewal['license_id'] ), ARRAY_A ) : null;
		$ok      = is_array( $renewal ) && is_array( $license )
			&& 'scheduled' === (string) $renewal['status']
			&& 0 === (int) $renewal['attempt_count']
			&& empty( $renewal['renewal_order_id'] )
			&& (int) $license['customer_id'] === (int) $leader_license['customer_id']
			&& strtoupper( (string) $license['renewal_currency'] ) === strtoupper( (string) $leader_license['renewal_currency'] )
			&& (string) $license['billing_interval'] === (string) $leader_license['billing_interval']
			&& '' === RenewalProcessor::charge_blocker( $renewal, $license );
		if ( $ok ) {
			/** This filter is documented in src/Billing/RenewalProcessor.php */
			$ok = true === apply_filters( 'wplc_renewal_charge_allowed', true, $renewal, $license );
		}
		if ( $ok ) {
			$token = PaymentReference::resolve_token( $adapter, $license );
			$ok    = ! is_wp_error( $token ) && (int) $token === $token_id;
		}
		if ( ! $ok ) {
			RenewalProcessor::unlock( $renewal_id );
			return null;
		}

		return array( 'renewal' => $renewal, 'license' => $license );
	}

	/**
	 * Undo a group whose claim failed before anything was charged.
	 *
	 * @param array<int,array<string,mixed>> $group Group members.
	 * @param int[]                          $claimed Renewals already moved to processing.
	 * @param int                            $order_id Combined order.
	 * @return void
	 */
	private static function abandon( array $group, array $claimed, int $order_id ): void {
		global $wpdb;

		$tables = Schema::table_names();
		foreach ( $claimed as $renewal_id ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET status = %s, updated_at = %s WHERE id = %d AND status = %s', $tables['renewals'], 'scheduled', gmdate( 'Y-m-d H:i:s' ), $renewal_id, 'processing' ) );
		}
		foreach ( $group as $member ) {
			self::release( (int) $member['renewal']['id'], $order_id );
		}
		foreach ( $group as $member ) {
			if ( ! in_array( (int) $member['renewal']['id'], $claimed, true ) ) {
				continue;
			}
			RenewalScheduler::enqueue( (int) $member['renewal']['id'], time() + 2 * MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Send the one charge of a claimed group and apply its outcome to every renewal.
	 *
	 * @param array<int,array<string,mixed>> $group Claimed members, leader first; renewal rows as read before the claim.
	 * @param \WC_Order                      $order Combined order.
	 * @param RecurringGatewayAdapter        $adapter Gateway adapter.
	 * @param int                            $token_id Payment token.
	 * @param int                            $request_at Unix time of the attempt.
	 * @param bool                           $reconciling Whether this re-checks an unknown result.
	 * @return void
	 */
	private static function charge( array $group, $order, RecurringGatewayAdapter $adapter, int $token_id, int $request_at, bool $reconciling ): void {
		$leader      = $group[0]['renewal'];
		$attempt_key = RenewalScheduler::attempt_idempotency_key( (string) $leader['idempotency_key'], (int) $leader['attempt_count'] );
		$result      = $adapter->charge( $order, $token_id, $attempt_key );

		$fresh          = wc_get_order( (int) $order->get_id() );
		$order          = $fresh instanceof \WC_Order ? $fresh : $order;
		$paid_elsewhere = $order->is_paid();

		if ( is_wp_error( $result ) || empty( $result['success'] ) ) {
			$error = is_wp_error( $result ) ? $result : new \WP_Error( 'renewal_payment_failed', __( 'The payment gateway did not confirm the renewal.', 'wp-license' ) );
			if ( $paid_elsewhere ) {
				foreach ( $group as $member ) {
					RenewalProcessor::book_paid_order( $member['renewal'], $member['license'], $order, 'renewal_paid', AuditReason::RENEWAL_ALREADY_PAID );
				}
				return;
			}
			$data = $error->get_error_data();
			$code = (string) $error->get_error_code();
			if ( ! $reconciling && in_array( $code, RenewalProcessor::SHOP_SIDE_ERRORS, true ) ) {
				// Nothing was charged: every license renews on its own again (the next run may combine them anew).
				$reason = is_array( $data ) && ! empty( $data['reason'] ) ? (string) $data['reason'] : $code;
				if ( 'renewal_payment_method_missing' === $reason ) {
					PaymentReference::report_unresolved( $group[0]['license'], (int) $leader['id'], $error, (string) ( $leader['last_error'] ?? '' ) !== $reason );
				}
				foreach ( $group as $member ) {
					RenewalProcessor::update_renewal( (int) $member['renewal']['id'], array( 'status' => 'scheduled' ) );
					self::release( (int) $member['renewal']['id'], (int) $order->get_id() );
					$member['renewal']['renewal_order_id'] = 0;
					RenewalProcessor::defer_attempt( $member['renewal'], $reason, RenewalProcessor::BLOCKED_RETRY_SECONDS );
				}
				return;
			}
			$wait     = is_array( $data ) ? absint( $data['wait'] ?? 0 ) : 0;
			$definite = RenewalProcessor::RESULT_UNKNOWN !== $code && ! $wait && ! in_array( $code, RenewalProcessor::SHOP_SIDE_ERRORS, true );
			if ( ! $definite ) {
				if ( $reconciling && in_array( $code, RenewalProcessor::SHOP_SIDE_ERRORS, true ) ) {
					self::review_all( $group, $code );
					return;
				}
				// The payment may have been taken (or is still being processed): every renewal waits for it.
				foreach ( $group as $member ) {
					RenewalProcessor::mark_result_unknown( $member['renewal'], $error, $request_at );
				}
				return;
			}
			$order->update_status( 'failed', $code );
			foreach ( $group as $member ) {
				RenewalProcessor::fail_attempt( $member['renewal'], $member['license'], $error, true, $request_at );
			}
			// The retries run per license, each with its own order.
			foreach ( $group as $member ) {
				self::release( (int) $member['renewal']['id'], (int) $order->get_id() );
			}
			return;
		}

		$transaction_id = sanitize_text_field( (string) ( $result['transaction_id'] ?? '' ) );
		foreach ( $group as $member ) {
			RenewalProcessor::update_renewal( (int) $member['renewal']['id'], array( 'gateway_attempt_id' => substr( $transaction_id, 0, 190 ) ) );
		}
		if ( $paid_elsewhere ) {
			$order->update_meta_data( '_wplc_duplicate_transaction_id', $transaction_id );
			$order->save();
			$problem = sprintf(
				/* translators: 1: transaction ID of the automatic charge, 2: transaction ID on the order. */
				__( 'The automatic charge %1$s succeeded although the order was already paid (%2$s). One of the two payments must be refunded. Refund the second payment %1$s in the payment gateway; a refund of this order in WooCommerce refunds %2$s and keeps the renewal, because the other payment still pays the period.', 'wp-license' ),
				$transaction_id,
				(string) $order->get_transaction_id()
			);
			$order->add_order_note( 'WP License Captain: ' . $problem );
			AuditLog::record( 'renewal_double_payment', 'renewal', (int) $leader['id'], $problem, null, array( 'order_id' => (int) $order->get_id() ) );
			/** This action is documented in src/Billing/RenewalOrder.php */
			do_action( 'wplc/renewal_needs_review', (int) $order->get_id(), (int) $leader['id'], $problem );
			foreach ( $group as $member ) {
				RenewalProcessor::book_paid_order( $member['renewal'], $member['license'], $order, 'renewal_paid', AuditReason::RENEWAL_ALREADY_PAID );
			}
			return;
		}
		$order->payment_complete( $transaction_id );
		// Members first: the combined renewal email goes out with the last booking (Notifications).
		foreach ( array_reverse( $group ) as $member ) {
			if ( RenewalProcessor::withdrawn_during_charge( (int) $member['license']['id'] ) ) {
				RenewalProcessor::flag_withdrawn_during_charge( $member['renewal'], $order );
				continue;
			}
			RenewalProcessor::finish_paid( $member['renewal'], $member['license'], $order, 'renewal_paid', AuditReason::RENEWAL_CONFIRMED_BY_ADAPTER );
		}
	}

	/**
	 * Send every renewal of the group to review with one reason (unknown result that cannot be re-checked).
	 *
	 * @param array<int,array<string,mixed>> $group Group members.
	 * @param string                         $reason Reason code.
	 * @return void
	 */
	private static function review_all( array $group, string $reason ): void {
		foreach ( $group as $member ) {
			RenewalProcessor::flag_for_review( $member['renewal'], $reason );
		}
	}
}
