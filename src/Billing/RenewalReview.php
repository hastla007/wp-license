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
	 * @return string One of the KIND_* constants.
	 */
	public static function kind( bool $has_order, bool $manual, bool $paid ): string {
		if ( ! $has_order ) {
			return self::KIND_NO_ORDER;
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
		$order = self::order_for( $renewal, $license );

		return self::kind( null !== $order, $order ? RenewalOrder::is_manual( $order ) : false, $order ? $order->is_paid() : false );
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
			$kind    = self::kind( null !== $order, $order ? RenewalOrder::is_manual( $order ) : false, $order ? $order->is_paid() : false );
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
		if ( ! $order->is_paid() ) {
			// The administrator confirmed the payment in the gateway. The paid-order hooks find the renewal paid.
			if ( $order->needs_payment() ) {
				$order->payment_complete();
			} else {
				$order->update_status( 'processing', __( 'WP License Captain: payment confirmed during the renewal review.', 'wp-license' ) );
			}
		}
		RenewalScheduler::schedule_next( $license_id );

		return 'review_booked';
	}
}
