<?php
/**
 * Pure reminder selection rules.
 *
 * @package WPLC
 */

namespace WPLC\Notifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decides which reminder, if any, a license is due for at a given moment.
 *
 * All calculations are done in UTC and on whole calendar days, so the result
 * does not depend on the hour at which the daily job happens to run.
 */
final class ReminderSchedule {

	/**
	 * Default lead days for expiry reminders.
	 */
	public const DEFAULT_LEAD_DAYS = array( 30, 7 );

	/**
	 * Default lead days for the automatic renewal notice.
	 */
	public const DEFAULT_NOTICE_DAYS = 7;

	/**
	 * Reminder type for licenses that end without automatic renewal.
	 */
	public const TYPE_EXPIRY = 'expiry_reminder';

	/**
	 * Reminder type for licenses that will be charged automatically.
	 */
	public const TYPE_RENEWAL_NOTICE = 'renewal_notice';

	/**
	 * Normalize lead days from a comma list or an array.
	 *
	 * @param mixed $value Comma separated string or list of numbers.
	 * @return int[] Unique lead days between 1 and 365, largest first.
	 */
	public static function parse_lead_days( $value ): array {
		if ( is_string( $value ) ) {
			$value = explode( ',', $value );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}

		$days = array();
		foreach ( $value as $entry ) {
			if ( ! is_scalar( $entry ) || ! preg_match( '/^\s*(\d{1,3})\s*$/', (string) $entry, $matches ) ) {
				continue;
			}
			$day = (int) $matches[1];
			if ( $day >= 1 && $day <= 365 ) {
				$days[ $day ] = $day;
			}
		}

		rsort( $days, SORT_NUMERIC );
		return array_values( $days );
	}

	/**
	 * Whether the license renews automatically at the end of the period.
	 *
	 * @param array<string,mixed> $license License row.
	 * @return bool
	 */
	public static function renews_automatically( array $license ): bool {
		return ! empty( $license['renewal_enabled'] ) && empty( $license['cancel_at_period_end'] );
	}

	/**
	 * Whole UTC calendar days from the day of $now to the day of $target.
	 *
	 * @param int $target Unix timestamp (non-negative).
	 * @param int $now    Unix timestamp (non-negative).
	 * @return int Negative when the target day is in the past.
	 */
	public static function days_until( int $target, int $now ): int {
		return intdiv( max( 0, $target ), 86400 ) - intdiv( max( 0, $now ), 86400 );
	}

	/**
	 * Approximate length of one billing period in days.
	 *
	 * @param string $interval Billing interval.
	 * @return int
	 */
	public static function term_days( string $interval ): int {
		return 'month' === $interval ? 30 : 365;
	}

	/**
	 * Return the reminder a license is due for, or null.
	 *
	 * Rules:
	 * - only active licenses with a paid_until in the future;
	 * - automatic renewal (and no scheduled cancellation): one renewal notice
	 *   within $notice_days before the charge date;
	 * - otherwise: the most urgent expiry lead day that has been reached. A missed
	 *   cron day therefore does not lose a reminder, and a license that is first
	 *   seen 5 days before expiry gets the 7-day reminder only;
	 * - lead days longer than half the billing period are ignored, so a monthly
	 *   license does not receive a "30 days left" email right after purchase.
	 *
	 * @param array<string,mixed> $license     License row.
	 * @param int                 $now         Unix timestamp.
	 * @param int[]               $lead_days   Expiry lead days.
	 * @param int                 $notice_days Renewal notice lead days.
	 * @return array<string,mixed>|null type, lead, marker, period_end, days_left, due_at.
	 */
	public static function due_reminder( array $license, int $now, array $lead_days, int $notice_days ): ?array {
		if ( 'active' !== (string) ( $license['status'] ?? '' ) || empty( $license['paid_until'] ) ) {
			return null;
		}
		$period_end = (string) $license['paid_until'];
		$paid_until = strtotime( $period_end . ' UTC' );
		if ( false === $paid_until || $paid_until <= $now ) {
			return null;
		}

		$term = self::term_days( (string) ( $license['billing_interval'] ?? 'year' ) );

		if ( self::renews_automatically( $license ) ) {
			$charge_at = empty( $license['next_payment_at'] ) ? false : strtotime( (string) $license['next_payment_at'] . ' UTC' );
			if ( false === $charge_at ) {
				$charge_at = $paid_until;
			}
			$days = self::days_until( $charge_at, $now );
			if ( $notice_days < 1 || $charge_at <= $now || $days > $notice_days || $notice_days * 2 > $term ) {
				return null;
			}
			return array(
				'type' => self::TYPE_RENEWAL_NOTICE,
				'lead' => $notice_days,
				'marker' => self::TYPE_RENEWAL_NOTICE,
				'period_end' => $period_end,
				'days_left' => $days,
				'due_at' => $charge_at,
			);
		}

		$days  = self::days_until( $paid_until, $now );
		$match = 0;
		foreach ( self::parse_lead_days( $lead_days ) as $lead ) {
			if ( $lead * 2 > $term ) {
				continue;
			}
			if ( $days <= $lead ) {
				// Sorted largest first: keep overwriting to end with the smallest reached lead.
				$match = $lead;
			}
		}
		if ( 0 === $match ) {
			return null;
		}

		return array(
			'type' => self::TYPE_EXPIRY,
			'lead' => $match,
			'marker' => 'expiry_' . $match,
			'period_end' => $period_end,
			'days_left' => $days,
			'due_at' => $paid_until,
		);
	}

	/**
	 * Largest number of days before paid_until at which any reminder can be due.
	 *
	 * @param int[] $lead_days   Expiry lead days.
	 * @param int   $notice_days Renewal notice lead days.
	 * @return int
	 */
	public static function max_window_days( array $lead_days, int $notice_days ): int {
		$days = self::parse_lead_days( $lead_days );
		return max( $days ? (int) $days[0] : 0, max( 0, $notice_days ) );
	}
}
