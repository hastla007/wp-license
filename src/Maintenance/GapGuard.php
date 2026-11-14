<?php
/**
 * Remembers a period in which the plugin did not process anything.
 *
 * @package WPLC
 */

namespace WPLC\Maintenance;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Flag with timestamps for "the plugin was not running from A to B".
 *
 * While the plugin is deactivated, billing is paused or the daily run does not
 * fire, renewals become due and are not attempted. After such a gap the
 * expiry run would expire those licenses at once, without a single charge
 * attempt. The gap is recorded here; the expiry run asks
 * {@see GapGuard::protects()} (or uses {@see GapGuard::protected_range()} in
 * its query) and leaves those licenses alone until the hold period is over,
 * which gives the renewal catch-up time to charge them.
 *
 * Recorded by: plugin activation after a deactivation, the daily run when the
 * previous run is older than two days, SiteGuard when a billing pause ends and
 * GapReconciler when it finds a paid order without license.
 */
final class GapGuard {

	/**
	 * Option with the last gap: array{from:int,to:int,recorded_at:int,source:string}.
	 */
	public const OPTION = 'wplc_gap_window';

	/**
	 * Gaps shorter than this are ignored (a deactivation for a minute is not a gap).
	 */
	public const MIN_GAP_SECONDS = 3600;

	/**
	 * Longest hold after the end of the gap, in days (filter `wplc_gap_hold_days`).
	 */
	public const DEFAULT_HOLD_DAYS = 15;

	/**
	 * Days before the gap whose due dates are protected as well (filter
	 * `wplc_gap_due_margin_days`): those licenses were inside their retry and
	 * grace period when the gap began.
	 */
	public const DEFAULT_MARGIN_DAYS = 14;

	/**
	 * Record a gap. An overlapping or still active gap is extended.
	 *
	 * @param int    $from   Unix time at which processing stopped.
	 * @param int    $to     Unix time at which processing resumed.
	 * @param string $source Short identifier of the detector.
	 * @return bool True when a gap was stored.
	 */
	public static function record( int $from, int $to, string $source ): bool {
		if ( $from <= 0 || $to - $from < self::MIN_GAP_SECONDS ) {
			return false;
		}

		$existing = self::window( $to );
		if ( null !== $existing ) {
			$from = min( $from, $existing['from'] );
			$to   = max( $to, $existing['to'] );
		}
		update_option(
			self::OPTION,
			array(
				'from'        => $from,
				'to'          => $to,
				'recorded_at' => time(),
				'source'      => sanitize_key( $source ),
			),
			false
		);

		/**
		 * Fires when a processing gap was recorded.
		 *
		 * @param int    $from   Start of the gap (unix time).
		 * @param int    $to     End of the gap (unix time).
		 * @param string $source Detector.
		 */
		do_action( 'wplc/gap_detected', $from, $to, $source );

		return true;
	}

	/**
	 * The gap that still protects licenses, or null.
	 *
	 * @param int|null $now Unix timestamp, default: now.
	 * @return array{from:int,to:int,hold_until:int,due_from:int,source:string}|null
	 */
	public static function window( ?int $now = null ): ?array {
		$now    = null === $now ? time() : $now;
		$stored = get_option( self::OPTION, null );
		if ( ! is_array( $stored ) || empty( $stored['from'] ) || empty( $stored['to'] ) ) {
			return null;
		}
		$from = (int) $stored['from'];
		$to   = (int) $stored['to'];

		/**
		 * Filter the longest hold after a gap in days.
		 *
		 * @param int $days Default 15 (grace period plus one day).
		 */
		$hold_days = max( 0, min( 365, (int) apply_filters( 'wplc_gap_hold_days', self::DEFAULT_HOLD_DAYS ) ) );
		/**
		 * Filter how many days before the gap a due date is still protected.
		 *
		 * @param int $days Default 14 (grace period).
		 */
		$margin_days = max( 0, min( 365, (int) apply_filters( 'wplc_gap_due_margin_days', self::DEFAULT_MARGIN_DAYS ) ) );

		$hold_until = self::hold_until( $from, $to, $hold_days * 86400 );
		if ( $now >= $hold_until ) {
			return null;
		}

		return array(
			'from'       => $from,
			'to'         => $to,
			'hold_until' => $hold_until,
			'due_from'   => $from - $margin_days * 86400,
			'source'     => isset( $stored['source'] ) ? (string) $stored['source'] : '',
		);
	}

	/**
	 * End of the protection: as long as the gap lasted, at least one day, at most the configured hold.
	 *
	 * Pure.
	 *
	 * @param int $from     Start of the gap.
	 * @param int $to       End of the gap.
	 * @param int $max_hold Longest hold in seconds.
	 * @return int Unix timestamp.
	 */
	public static function hold_until( int $from, int $to, int $max_hold ): int {
		return $to + min( $max_hold, max( 86400, $to - $from ) );
	}

	/**
	 * Whether a license with this due date must not be expired yet.
	 *
	 * @param string|null $paid_until_utc End of the paid period, UTC 'Y-m-d H:i:s'.
	 * @param int|null    $now            Unix timestamp, default: now.
	 * @return bool
	 */
	public static function protects( ?string $paid_until_utc, ?int $now = null ): bool {
		$window = self::window( $now );
		if ( null === $window || null === $paid_until_utc || '' === $paid_until_utc ) {
			return false;
		}
		$due = strtotime( $paid_until_utc . ' UTC' );

		return false !== $due && $due >= $window['due_from'] && $due <= $window['to'];
	}

	/**
	 * The protected due dates as UTC datetimes for a SQL condition.
	 *
	 * Usage in the expiry run: `AND NOT ( paid_until BETWEEN %s AND %s )`.
	 *
	 * @param int|null $now Unix timestamp, default: now.
	 * @return array{0:string,1:string}|null Null when no gap is active.
	 */
	public static function protected_range( ?int $now = null ): ?array {
		$window = self::window( $now );
		if ( null === $window ) {
			return null;
		}

		return array( gmdate( 'Y-m-d H:i:s', $window['due_from'] ), gmdate( 'Y-m-d H:i:s', $window['to'] ) );
	}

	/**
	 * Unix time at which the hold ends, 0 when no gap is active (for diagnostics).
	 *
	 * @return int
	 */
	public static function active_until(): int {
		$window = self::window();

		return null === $window ? 0 : $window['hold_until'];
	}

	/**
	 * Remove an expired flag (daily housekeeping).
	 *
	 * @return void
	 */
	public static function cleanup(): void {
		if ( null !== get_option( self::OPTION, null ) && null === self::window() ) {
			delete_option( self::OPTION );
		}
	}
}
