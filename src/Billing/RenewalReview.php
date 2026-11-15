<?php
/**
 * Renewals that need an administrator: open, list and resolve review cases.
 *
 * @package WPLC
 */

namespace WPLC\Billing;

use WPLC\Infrastructure\Database\Schema;
use WPLC\License\AuditLog;
use WPLC\License\AuditReason;
use WPLC\License\LicenseLifecycle;
use WPLC\License\PlanChange;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A review case is a renewal row in status `review_required` (T-154, T-137):
 *
 * - the result of an automatic payment stayed unknown (T-136),
 * - an automatic payment went through while the license changed (T-22),
 * - a paid manual renewal could not be applied, e.g. a late bank transfer.
 *
 * The row carries the reason (`review_reason`) and the start of the case
 * (`review_at`). An administrator books the renewal, records the payment as
 * not taken (unknown result only) or closes the case without booking
 * (status `closed`). Every action runs under the per-renewal lock and claims
 * the row with a conditional update, so a double submission changes nothing.
 */
final class RenewalReview {

	/** Renewal status of a review case that was closed without booking. */
	public const STATUS_CLOSED = 'closed';

	/** Order meta set when a case was closed: `paid` or `unpaid`, the order's state at that time. */
	public const META_CLOSED = '_wplc_review_closed';

	/** Automatic renewal whose payment result is unknown; the order is not paid. */
	public const KIND_RESULT_UNKNOWN = 'result_unknown';

	/** Automatic renewal whose order is paid but whose license was not extended. */
	public const KIND_PAID_NOT_BOOKED = 'paid_not_booked';

	/** Paid manual renewal that could not be applied (late payment, changed period, price). */
	public const KIND_MANUAL_PAYMENT = 'manual_payment';

	/** No usable order (missing, or a manual order that is not paid): only closing is possible. */
	public const KIND_NO_ORDER = 'no_order';

	/** The order was refunded or cancelled (P-05): only closing is possible. */
	public const KIND_ORDER_CLOSED = 'order_closed';

	/**
	 * Order statuses that end a case: the money went back, or the order was given up (P-05).
	 *
	 * @var string[]
	 */
	public const CLOSED_ORDER_STATUSES = array( 'refunded', 'cancelled' );

	public const ACTION_BOOK     = 'book';
	public const ACTION_NOT_PAID = 'not_paid';
	public const ACTION_CLOSE    = 'close';

	/** Upper bound of rows shown on the review screen. */
	public const LIST_LIMIT = 200;

	/**
	 * Kind of a case from its order. Pure.
	 *
	 * @param bool $has_order Whether a matching renewal order exists.
	 * @param bool $manual Whether it is a customer-initiated (manual) renewal order.
	 * @param bool $paid Whether the order is paid.
	 * @param bool $closed Whether the order was refunded or cancelled (P-05).
	 * @return string One of the KIND_* constants.
	 */
	public static function kind( bool $has_order, bool $manual, bool $paid, bool $closed = false ): string {
		if ( ! $has_order ) {
			return self::KIND_NO_ORDER;
		}
		if ( $closed ) {
			// A refunded or cancelled order is neither booked nor charged again.
			return self::KIND_ORDER_CLOSED;
		}
		if ( $manual ) {
			return $paid ? self::KIND_MANUAL_PAYMENT : self::KIND_NO_ORDER;
		}

		return $paid ? self::KIND_PAID_NOT_BOOKED : self::KIND_RESULT_UNKNOWN;
	}

	/**
	 * Actions offered for a kind of case. Pure.
	 *
	 * @param string $kind KIND_* constant.
	 * @return string[] ACTION_* constants.
	 */
	public static function actions( string $kind ): array {
		switch ( $kind ) {
			case self::KIND_RESULT_UNKNOWN:
				return array( self::ACTION_BOOK, self::ACTION_NOT_PAID, self::ACTION_CLOSE );
			case self::KIND_PAID_NOT_BOOKED:
			case self::KIND_MANUAL_PAYMENT:
				return array( self::ACTION_BOOK, self::ACTION_CLOSE );
			default:
				return array( self::ACTION_CLOSE );
		}
	}

	/**
	 * Whether a license in this status may be extended by booking a reviewed renewal. Pure.
	 *
	 * Active and past-due licenses are extended, an expired one is reactivated
	 * (the transitions of the administrator's "Change term"). A suspended or
	 * revoked license is never extended implicitly: reactivate it first, or
	 * close the case and refund.
	 *
	 * @param string $status License status.
	 * @return bool
	 */
	public static function license_allows_booking( string $status ): bool {
		return \WPLC\License\LicenseStateMachine::allows( $status, 'active', 'admin_extend' );
	}

	/**
	 * New paid-through date when a reviewed renewal is booked. Pure.
	 *
	 * - An automatic renewal (unknown result, or paid while the license changed)
	 *   whose period still starts at the license's paid_until is booked as if the
	 *   charge had succeeded: the license runs to the end of that period, without
	 *   a gap (the customer kept access during the grace period).
	 * - Everything else (a late manual payment, a period that changed since, or
	 *   a period whose end has already passed) is booked like a manual renewal
	 *   made now: one period from max(paid_until, now). Never retroactively.
	 *
	 * @param string              $kind KIND_* constant.
	 * @param array<string,mixed> $renewal Renewal row (period_start, period_end).
	 * @param array<string,mixed> $license License row (paid_until, billing_interval).
	 * @param int                 $now Unix time.
	 * @return string 'Y-m-d H:i:s' UTC.
	 */
	public static function booking_paid_until( string $kind, array $renewal, array $license, int $now ): string {
		$paid_until = (string) ( $license['paid_until'] ?? '' );
		if (
			in_array( $kind, array( self::KIND_RESULT_UNKNOWN, self::KIND_PAID_NOT_BOOKED ), true )
			&& '' !== $paid_until
			&& (string) ( $renewal['period_start'] ?? '' ) === $paid_until
		) {
			$end = strtotime( (string) ( $renewal['period_end'] ?? '' ) . ' UTC' );
			if ( $end && $end > $now ) {
				return gmdate( 'Y-m-d H:i:s', $end );
			}
		}

		return ManualRenewal::next_paid_until( $paid_until, (string) ( $license['billing_interval'] ?? 'year' ), $now );
	}

	/**
	 * Open (or keep open) a review case on a renewal row.
	 *
	 * The reason and start time of a case that is already open are kept unless
	 * $replace_reason is set. Stops all automatic work on the row (retry_at).
	 * Emits the `review_required_renewals` health alert for a new case.
	 *
	 * @param int      $renewal_id Renewal ID.
	 * @param string   $reason Reason code (AuditReason) or stored reason.
	 * @param int|null $order_id Renewal order to link, null to keep the link.
	 * @param bool     $replace_reason Overwrite the reason of an already open case.
	 * @return bool True when the case was newly opened.
	 */
	public static function open( int $renewal_id, string $reason, ?int $order_id = null, bool $replace_reason = false ): bool {
		global $wpdb;

		$tables   = Schema::table_names();
		$previous = (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$tables['renewals']} WHERE id = %d", $renewal_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names(), values prepared.
		if ( '' === $previous ) {
			return false;
		}
		$is_open = RenewalProcessor::STATUS_REVIEW === $previous;
		$now     = gmdate( 'Y-m-d H:i:s' );
		$data    = array(
			'status'     => RenewalProcessor::STATUS_REVIEW,
			'retry_at'   => null,
			'updated_at' => $now,
		);
		$formats = array( '%s', '%s', '%s' );
		if ( ! $is_open || $replace_reason ) {
			$data['review_reason'] = substr( $reason, 0, 255 );
			$formats[]             = '%s';
		}
		if ( ! $is_open ) {
			$data['review_at'] = $now;
			$formats[]         = '%s';
		}
		if ( null !== $order_id ) {
			$data['renewal_order_id'] = $order_id;
			$formats[]                = '%d';
		}
		$wpdb->update( $tables['renewals'], $data, array( 'id' => $renewal_id ), $formats, array( '%d' ) );
		if ( ! $is_open ) {
			\WPLC\Admin\Diagnostics::emit_alert( 'review_required_renewals', self::open_count() );
		}

		return ! $is_open;
	}

	/**
	 * Number of open review cases.
	 *
	 * @return int
	 */
	public static function open_count(): int {
		global $wpdb;

		$tables = Schema::table_names();

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['renewals']} WHERE status = %s", RenewalProcessor::STATUS_REVIEW ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names(), values prepared.
	}

	/**
	 * Open review cases with their license, oldest first.
	 *
	 * @param int $limit Maximum rows.
	 * @return array<int,array<string,mixed>> Renewal columns plus license_status, customer_id, key_prefix, product_key, paid_until, billing_interval, renewal_amount_minor, renewal_currency_decimals, renewal_currency.
	 */
	public static function open_cases( int $limit = self::LIST_LIMIT ): array {
		global $wpdb;

		$tables = Schema::table_names();
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.*, l.status AS license_status, l.customer_id, l.key_prefix, l.product_key, l.paid_until, l.billing_interval, l.renewal_amount_minor, l.renewal_currency_decimals, l.renewal_currency FROM {$tables['renewals']} r LEFT JOIN {$tables['licenses']} l ON l.id = r.license_id WHERE r.status = %s ORDER BY COALESCE( r.review_at, r.updated_at ) ASC, r.id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from Schema::table_names(), values prepared.
				RenewalProcessor::STATUS_REVIEW,
				max( 1, $limit )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Open review cases of one license.
	 *
	 * @param int $license_id License ID.
	 * @return array<int,array<string,mixed>> Renewal rows.
	 */
	public static function open_cases_for_license( int $license_id ): array {
		global $wpdb;

		$tables = Schema::table_names();
		$rows   = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$tables['renewals']} WHERE license_id = %d AND status = %s ORDER BY id ASC", $license_id, RenewalProcessor::STATUS_REVIEW ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names(), values prepared.
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The renewal order of a case, if it exists and belongs to the renewal and license.
	 *
	 * @param array<string,mixed>      $renewal Renewal row.
	 * @param array<string,mixed>|null $license License row.
	 * @return \WC_Order|null
	 */
	public static function order_for( array $renewal, ?array $license ) {
		$order_id = absint( $renewal['renewal_order_id'] ?? 0 );
		$order    = $order_id && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof \WC_Order || ! is_array( $license ) || ! RenewalOrder::matches( $order, $renewal, $license ) ) {
			return null;
		}

		return $order;
	}

	/**
	 * Kind of a stored case.
	 *
	 * @param array<string,mixed>      $renewal Renewal row.
	 * @param array<string,mixed>|null $license License row.
	 * @return string
	 */
	public static function kind_of( array $renewal, ?array $license ): string {
		return self::kind_for_order( self::order_for( $renewal, $license ) );
	}

	/**
	 * Kind of a case from its order (the result of order_for()).
	 *
	 * @param \WC_Order|null $order Renewal order of the case.
	 * @return string
	 */
	public static function kind_for_order( $order ): string {
		if ( ! $order instanceof \WC_Order ) {
			return self::KIND_NO_ORDER;
		}

		return self::kind( true, RenewalOrder::is_manual( $order ), $order->is_paid(), self::order_closed( $order ) );
	}

	/**
	 * Whether an order was refunded or cancelled (P-05).
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	public static function order_closed( $order ): bool {
		return in_array( (string) $order->get_status(), self::CLOSED_ORDER_STATUSES, true );
	}

	/**
	 * Book the renewal of a case: the payment was taken.
	 *
	 * @param int    $renewal_id Renewal ID.
	 * @param string $note Administrator's reason.
	 * @param int    $actor_user_id Administrator.
	 * @return string Result code (AdminNotices): review_booked or an error code.
	 */
	public static function book( int $renewal_id, string $note, int $actor_user_id ): string {
		return self::with_case(
			$renewal_id,
			$note,
			self::ACTION_BOOK,
			static function ( array $renewal, array $license, $order, string $kind ) use ( $note, $actor_user_id ): string {
				return self::book_locked( $renewal, $license, $order, $kind, $note, $actor_user_id );
			}
		);
	}

	/**
	 * Record the payment of a case with unknown result as not taken (a definite decline).
	 *
	 * Same path as setting the renewal order to Failed: the order becomes
	 * failed, the attempt is counted, the license becomes past_due (with the
	 * usual email) and the next retry uses a new idempotency key.
	 *
	 * @param int    $renewal_id Renewal ID.
	 * @param string $note Administrator's reason.
	 * @param int    $actor_user_id Administrator.
	 * @return string Result code.
	 */
	public static function mark_not_paid( int $renewal_id, string $note, int $actor_user_id ): string {
		return self::with_case(
			$renewal_id,
			$note,
			self::ACTION_NOT_PAID,
			static function ( array $renewal, array $license, $order, string $kind ) use ( $note, $actor_user_id ): string {
				if ( self::order_closed( $order ) ) {
					// P-05: never fail (and charge again) an order that was refunded or cancelled.
					return 'review_order_closed';
				}
				$parsed = AuditReason::parse( (string) $renewal['review_reason'] );
				if ( is_array( $parsed ) && AuditReason::UNBOOKED_STRIPE_PAYMENT === $parsed['code'] ) {
					// P-19: Stripe reported the money as received; a new attempt would charge twice.
					return 'review_action_not_allowed';
				}
				$order->update_status( 'failed', __( 'WP License Captain: marked as not paid after review.', 'wp-license' ) . ' ' . $note );
				RenewalProcessor::resolve_as_declined_locked(
					$renewal,
					$license,
					AuditReason::encode( AuditReason::REVIEW_NOT_PAID, array( 'note' => $note ) ),
					$actor_user_id
				);

				return 'review_marked_not_paid';
			}
		);
	}

	/**
	 * Close a case without extending the license (refunded, or decided otherwise).
	 *
	 * The order is not changed and no refund is made: refunds are done in
	 * WooCommerce. A later payment of an order that was unpaid when the case
	 * was closed reopens the case instead of being booked silently.
	 *
	 * @param int    $renewal_id Renewal ID.
	 * @param string $note Administrator's reason.
	 * @param int    $actor_user_id Administrator.
	 * @return string Result code.
	 */
	public static function close( int $renewal_id, string $note, int $actor_user_id ): string {
		return self::with_case(
			$renewal_id,
			$note,
			self::ACTION_CLOSE,
			static function ( array $renewal, array $license, $order, string $kind ) use ( $note, $actor_user_id ): string {
				global $wpdb;

				$tables  = Schema::table_names();
				$claimed = $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$tables['renewals']} SET status = %s, retry_at = NULL, updated_at = %s WHERE id = %d AND status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names(), values prepared.
						self::STATUS_CLOSED,
						gmdate( 'Y-m-d H:i:s' ),
						(int) $renewal['id'],
						RenewalProcessor::STATUS_REVIEW
					)
				);
				if ( 1 !== (int) $claimed ) {
					return 'review_not_open';
				}
				if ( $order ) {
					$order->update_meta_data( self::META_CLOSED, $order->is_paid() ? 'paid' : 'unpaid' );
				do_action( 'wplc/review_case_changed' );
					$order->save();
					$order->add_order_note(
						sprintf(
							/* translators: %s: reason entered by the administrator. */
							__( 'WP License Captain: the renewal review was closed without extending the license. Reason: %s. Refunds are made in WooCommerce.', 'wp-license' ),
							$note
						)
					);
				}
				AuditLog::record(
					'renewal_review_closed',
					'renewal',
					(int) $renewal['id'],
					AuditReason::encode( AuditReason::REVIEW_CLOSED, array( 'note' => $note ) ),
					array( 'status' => RenewalProcessor::STATUS_REVIEW, 'review_reason' => (string) $renewal['review_reason'] ),
					array( 'status' => self::STATUS_CLOSED, 'kind' => $kind, 'order_id' => $order ? (int) $order->get_id() : 0 ),
					$actor_user_id
				);

				return 'review_closed';
			}
		);
	}

	/**
	 * Close the open case of a renewal order that was refunded or cancelled (P-05).
	 *
	 * Called from the refund and cancellation handlers of the license
	 * lifecycle, before they take the license lock (lock order). The money went
	 * back or the order was given up, so the case must neither be booked nor
	 * charged again; a later payment of the order is never booked silently
	 * (META_CLOSED). Does nothing when the order has no open case.
	 *
	 * @param int    $order_id Renewal order ID.
	 * @param string $reason AuditReason::REVIEW_ORDER_REFUNDED or REVIEW_ORDER_CANCELLED.
	 * @return bool True when a case was closed.
	 */
	public static function close_for_order( int $order_id, string $reason ): bool {
		global $wpdb;

		$order = $order_id && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof \WC_Order ) {
			return false;
		}
		$renewal_id = absint( $order->get_meta( RenewalOrder::META_RENEWAL, true ) );
		if ( ! $renewal_id ) {
			return false;
		}
		// Without waiting: the caller may hold the license lock (an order closed during a status
		// change), which must never wait for a renewal lock. Without the lock (held by a running
		// review action) the conditional update still decides.
		$locked = RenewalProcessor::lock( $renewal_id, 0 );
		try {
			$tables  = Schema::table_names();
			$renewal = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['renewals']} WHERE id = %d LIMIT 1", $renewal_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names(), values prepared.
			if ( ! is_array( $renewal ) || RenewalProcessor::STATUS_REVIEW !== (string) $renewal['status'] || (int) $renewal['renewal_order_id'] !== $order_id ) {
				return false;
			}
			$claimed = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$tables['renewals']} SET status = %s, retry_at = NULL, updated_at = %s WHERE id = %d AND status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names(), values prepared.
					self::STATUS_CLOSED,
					gmdate( 'Y-m-d H:i:s' ),
					$renewal_id,
					RenewalProcessor::STATUS_REVIEW
				)
			);
			if ( 1 !== (int) $claimed ) {
				return false;
			}
			$code = AuditReason::REVIEW_ORDER_CANCELLED === $reason ? 'cancelled' : 'refunded';
			$order->update_meta_data( self::META_CLOSED, $code );
			do_action( 'wplc/review_case_changed' );
			$order->save();
			$order->add_order_note( __( 'WP License Captain: the renewal review was closed because this order was refunded or cancelled. The license was not extended.', 'wp-license' ) );
			AuditLog::record(
				'renewal_review_closed',
				'renewal',
				$renewal_id,
				AuditReason::encode( $reason, array( 'order' => $order_id ) ),
				array( 'status' => RenewalProcessor::STATUS_REVIEW, 'review_reason' => (string) $renewal['review_reason'] ),
				array( 'status' => self::STATUS_CLOSED, 'kind' => self::KIND_ORDER_CLOSED, 'order_id' => $order_id ),
				get_current_user_id()
			);

			return true;
		} finally {
			if ( $locked ) {
				RenewalProcessor::unlock( $renewal_id );
			}
		}
	}

	/**
	 * Run an action on an open case under the per-renewal lock.
	 *
	 * @param int      $renewal_id Renewal ID.
	 * @param string   $note Administrator's reason.
	 * @param string   $action ACTION_* constant.
	 * @param callable $callback Receives renewal, license, order (or null) and kind; returns a result code.
	 * @return string Result code.
	 */
	private static function with_case( int $renewal_id, string $note, string $action, callable $callback ): string {
		global $wpdb;

		if ( '' === trim( $note ) ) {
			return 'review_missing_reason';
		}
		if ( ! $renewal_id || ! RenewalProcessor::lock( $renewal_id ) ) {
			return $renewal_id ? 'review_busy' : 'review_not_open';
		}
		try {
			$tables  = Schema::table_names();
			$renewal = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['renewals']} WHERE id = %d LIMIT 1", $renewal_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names(), values prepared.
			if ( ! is_array( $renewal ) || RenewalProcessor::STATUS_REVIEW !== (string) $renewal['status'] ) {
				return 'review_not_open';
			}
			$license = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['licenses']} WHERE id = %d LIMIT 1", (int) $renewal['license_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names(), values prepared.
			$license = is_array( $license ) ? $license : null;
			$order   = self::order_for( $renewal, $license );
			$kind    = self::kind_for_order( $order );
			if ( ! in_array( $action, self::actions( $kind ), true ) ) {
				return 'review_action_not_allowed';
			}
			if ( null === $license ) {
				// Only closing is possible without a license (KIND_NO_ORDER); keep the callback signature.
				$license = array( 'id' => (int) $renewal['license_id'] );
			}

			return (string) $callback( $renewal, $license, $order, $kind );
		} finally {
			RenewalProcessor::unlock( $renewal_id );
		}
	}

	/**
	 * Body of book() while the per-renewal lock is held.
	 *
	 * @param array<string,mixed> $renewal Renewal row.
	 * @param array<string,mixed> $license License row.
	 * @param \WC_Order           $order Renewal order.
	 * @param string              $kind KIND_* constant.
	 * @param string              $note Administrator's reason.
	 * @param int                 $actor_user_id Administrator.
	 * @return string
	 */
	private static function book_locked( array $renewal, array $license, $order, string $kind, string $note, int $actor_user_id ): string {
		global $wpdb;

		if ( ! self::license_allows_booking( (string) ( $license['status'] ?? '' ) ) ) {
			return 'review_license_status';
		}
		if ( self::order_closed( $order ) ) {
			// P-05: a refunded or cancelled order never renews the license or becomes Processing again.
			return 'review_order_closed';
		}
		$renewal_id     = (int) $renewal['id'];
		$license_id     = (int) $license['id'];
		$order_id       = (int) $order->get_id();
		$new_paid_until = self::booking_paid_until( $kind, $renewal, $license, time() );
		$tables         = Schema::table_names();
		$now            = gmdate( 'Y-m-d H:i:s' );
		// Claim the case first: a second submission finds it paid and stops.
		$claimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$tables['renewals']} SET status = %s, period_end = %s, renewal_order_id = %d, retry_at = NULL, updated_at = %s WHERE id = %d AND status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names(), values prepared.
				'paid',
				$new_paid_until,
				$order_id,
				$now,
				$renewal_id,
				RenewalProcessor::STATUS_REVIEW
			)
		);
		if ( 1 !== (int) $claimed ) {
			return 'review_not_open';
		}
		$booked = LicenseLifecycle::book_reviewed_renewal(
			$license_id,
			$new_paid_until,
			AuditReason::encode( AuditReason::RENEWED_AFTER_REVIEW, array( 'order' => $order_id, 'note' => $note ) ),
			$actor_user_id
		);
		if ( ! $booked ) {
			$wpdb->update(
				$tables['renewals'],
				array( 'status' => RenewalProcessor::STATUS_REVIEW, 'period_end' => (string) $renewal['period_end'], 'updated_at' => $now ),
				array( 'id' => $renewal_id ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);
			return 'review_book_failed';
		}

		AuditLog::record(
			'renewal_review_booked',
			'renewal',
			$renewal_id,
			AuditReason::encode( AuditReason::REVIEW_BOOKED, array( 'note' => $note ) ),
			array( 'status' => RenewalProcessor::STATUS_REVIEW, 'period_end' => (string) $renewal['period_end'], 'review_reason' => (string) $renewal['review_reason'] ),
			array( 'status' => 'paid', 'period_end' => $new_paid_until, 'order_id' => $order_id, 'kind' => $kind ),
			$actor_user_id
		);
		$order->add_order_note(
			sprintf(
				/* translators: 1: license ID, 2: new paid-through date (UTC), 3: reason entered by the administrator. */
				__( 'WP License Captain: renewal booked after review; license #%1$d runs until %2$s (UTC). Reason: %3$s', 'wp-license' ),
				$license_id,
				$new_paid_until,
				$note
			)
		);
		// A plan change scheduled for this renewal (T-110) takes effect now.
		PlanChange::apply_pending_after_renewal( $license_id, $order );
		if ( ! $order->is_paid() ) {
			// The administrator confirmed the payment in the gateway. The paid-order hooks find the renewal paid.
			// P-18: with its gateway reference, so a refund from WooCommerce and a dispute find the payment.
			$reference = self::payment_reference( $order, $renewal );
			if ( '' === $reference ) {
				$order->add_order_note( __( 'WP License Captain: no payment reference was found for this renewal payment. Enter the transaction ID on this order; until then a refund has to be made in the payment gateway, and a dispute is not matched to this order automatically.', 'wp-license' ) );
			}
			if ( $order->needs_payment() ) {
				$order->payment_complete( $reference );
			} else {
				if ( '' !== $reference && '' === (string) $order->get_transaction_id() ) {
					$order->set_transaction_id( $reference );
					$order->save();
				}
				$order->update_status( 'processing', __( 'WP License Captain: payment confirmed during the renewal review.', 'wp-license' ) );
			}
		}
		RenewalScheduler::schedule_next( $license_id );

		return 'review_booked';
	}

	/**
	 * Transaction ID of the payment of a reviewed order that is booked as paid (P-18).
	 *
	 * In this order: the order's own transaction ID, a successful Stripe
	 * PaymentIntent of exactly this renewal (the one stored on the order, or
	 * found by the renewal metadata; its gateway metas are written as after an
	 * automatic charge), the transaction ID the adapter returned for the
	 * attempt. Empty when none is known; the filter
	 * `wplc_review_payment_reference` lets another gateway integration answer.
	 *
	 * @param \WC_Order           $order Renewal order, not paid.
	 * @param array<string,mixed> $renewal Renewal row.
	 * @return string
	 */
	private static function payment_reference( $order, array $renewal ): string {
		$reference = (string) $order->get_transaction_id();
		if ( '' === $reference && 'stripe' === $order->get_payment_method() ) {
			$intent = self::find_stripe_payment( $order, $renewal );
			if ( $intent ) {
				$intent_id = sanitize_text_field( (string) ( $intent->id ?? '' ) );
				$charge_id = \WPLC\Gateway\StripeRecurringAdapter::charge_id( $intent );
				\WPLC\Gateway\StripeRecurringAdapter::store_gateway_meta( $order, $intent_id, $charge_id );
				$reference = '' !== $charge_id ? $charge_id : $intent_id;
			}
		}
		if ( '' === $reference ) {
			$reference = sanitize_text_field( (string) ( $renewal['gateway_attempt_id'] ?? '' ) );
		}

		/**
		 * Filters the transaction ID stored when a reviewed renewal order is booked as paid.
		 *
		 * @param string              $reference Transaction ID, empty when unknown.
		 * @param \WC_Order           $order Renewal order.
		 * @param array<string,mixed> $renewal Renewal row.
		 */
		return sanitize_text_field( (string) apply_filters( 'wplc_review_payment_reference', $reference, $order, $renewal ) );
	}

	/**
	 * The one successful Stripe PaymentIntent of a renewal, if Stripe knows exactly one (P-18).
	 *
	 * Only intents created for this renewal and license on this site, for the
	 * order's amount and currency, count. None or more than one: null.
	 *
	 * @param \WC_Order           $order Renewal order.
	 * @param array<string,mixed> $renewal Renewal row.
	 * @return object|null
	 */
	private static function find_stripe_payment( $order, array $renewal ) {
		if ( ! class_exists( '\\WC_Stripe_API' ) || ! class_exists( '\\WC_Stripe_Helper' ) ) {
			return null;
		}
		$amount   = \WC_Stripe_Helper::get_stripe_amount( $order->get_total(), $order->get_currency() );
		$currency = strtolower( (string) $order->get_currency() );
		$answers  = array();
		$stored   = (string) $order->get_meta( RenewalOrder::META_PAYMENT_INTENT, true );
		if ( 0 === strpos( $stored, 'pi_' ) ) {
			$answers[] = self::stripe_get( 'payment_intents/' . rawurlencode( $stored ) );
		}
		$query  = sprintf( "metadata['wplc_renewal_id']:'%d' AND metadata['wplc_license_id']:'%d'", (int) $renewal['id'], (int) $renewal['license_id'] );
		$found  = self::stripe_get( 'payment_intents/search?limit=20&query=' . rawurlencode( $query ) );
		$found  = is_object( $found ) && is_array( $found->data ?? null ) ? $found->data : array();
		$answers = array_merge( $answers, $found );

		$succeeded = array();
		foreach ( $answers as $intent ) {
			if ( ! is_object( $intent ) || ! empty( $intent->error ) || 'succeeded' !== (string) ( $intent->status ?? '' ) ) {
				continue;
			}
			$metadata = is_object( $intent->metadata ?? null ) ? $intent->metadata : null;
			$site     = $metadata ? (string) ( $metadata->wplc_site ?? '' ) : '';
			$period   = $metadata ? (string) ( $metadata->wplc_period ?? '' ) : '';
			if (
				! $metadata
				|| ( '' !== $period && (string) $renewal['period_start'] !== $period )
				|| absint( $metadata->wplc_renewal_id ?? 0 ) !== (int) $renewal['id']
				|| absint( $metadata->wplc_license_id ?? 0 ) !== (int) $renewal['license_id']
				|| ( '' !== $site && ! hash_equals( \WPLC\Gateway\StripeRecurringAdapter::site_id(), $site ) )
				|| absint( $intent->amount_received ?? ( $intent->amount ?? 0 ) ) !== $amount
				|| strtolower( (string) ( $intent->currency ?? '' ) ) !== $currency
			) {
				continue;
			}
			$succeeded[ (string) ( $intent->id ?? '' ) ] = $intent;
		}

		return 1 === count( $succeeded ) ? reset( $succeeded ) : null;
	}

	/**
	 * GET a Stripe API resource through the gateway; null when there is no usable answer.
	 *
	 * @param string $api Path below the API root.
	 * @return object|null
	 */
	private static function stripe_get( string $api ) {
		try {
			$response = \WC_Stripe_API::retrieve( $api );
		} catch ( \Throwable $exception ) {
			return null;
		}

		return is_object( $response ) && ! is_wp_error( $response ) ? $response : null;
	}
}
