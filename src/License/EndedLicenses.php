<?php
/**
 * Which licenses count as ended for the privacy eraser and for retention.
 *
 * @package WPLC
 */

namespace WPLC\License;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One rule for "the service period of this license has ended".
 *
 * Expired and revoked licenses have ended. A suspended license can become active again
 * (a dispute is settled, an order is paid after all), so by default it has not. The shop
 * decides whether a license that stayed suspended for a long time counts as ended:
 * setting "Suspended licenses count as ended after … days" (0 = never, the default),
 * filter `wplc_suspended_license_ends_after_days`.
 *
 * The time of the suspension is `licenses.suspended_at` (schema 27): set when a license
 * becomes suspended, so a license that was reactivated and suspended again counts from the
 * second suspension. A license that is active again is never affected, whatever the column holds.
 */
final class EndedLicenses {

	/**
	 * States that always count as ended.
	 */
	public const STATES = array( 'expired', 'revoked' );

	/**
	 * Largest accepted number of days (ten years).
	 */
	public const MAX_DAYS = 3650;

	/**
	 * Days after which a suspended license counts as ended; 0 = never.
	 *
	 * @return int
	 */
	public static function suspended_days(): int {
		$days = class_exists( '\\WPLC\\Admin\\Settings' ) ? (int) \WPLC\Admin\Settings::get( 'suspended_ends_after_days' ) : 0;
		/**
		 * Filters the days after which a suspended license counts as ended (0 = never). Wins over the setting.
		 *
		 * Used by the privacy eraser and by retention.
		 *
		 * @since 0.2.0
		 *
		 * @param int $days Days of uninterrupted suspension; 0 = a suspended license never counts as ended.
		 */
		$days = (int) apply_filters( 'wplc_suspended_license_ends_after_days', $days );

		return max( 0, min( self::MAX_DAYS, $days ) );
	}

	/**
	 * SQL condition "this suspended license counts as ended"; never true while the rule is off.
	 *
	 * Built from constants and a computed date only, so it can be put into a query as it is.
	 *
	 * @param string   $alias Table alias of the licenses table, without the dot; empty for none.
	 * @param int|null $now   Current time (tests); null for now.
	 * @return string
	 */
	public static function suspended_condition( string $alias = '', ?int $now = null ): string {
		$days = self::suspended_days();
		if ( $days < 1 ) {
			return '( 1 = 0 )';
		}
		$column = '' === $alias ? '' : preg_replace( '/[^a-z_]/', '', $alias ) . '.';
		$cutoff = gmdate( 'Y-m-d H:i:s', ( null === $now ? time() : $now ) - $days * DAY_IN_SECONDS );

		return "( {$column}status = 'suspended' AND {$column}suspended_at IS NOT NULL AND {$column}suspended_at <= '{$cutoff}' )";
	}

	/**
	 * SQL condition "this license has ended".
	 *
	 * @param string   $alias Table alias of the licenses table, without the dot; empty for none.
	 * @param int|null $now   Current time (tests); null for now.
	 * @return string
	 */
	public static function condition( string $alias = '', ?int $now = null ): string {
		$column = '' === $alias ? '' : preg_replace( '/[^a-z_]/', '', $alias ) . '.';

		return "( {$column}status IN ('" . implode( "','", self::STATES ) . "') OR " . self::suspended_condition( $alias, $now ) . ' )';
	}

	/**
	 * Whether a license row counts as ended.
	 *
	 * @param array<string,mixed> $license License row (status, suspended_at).
	 * @param int|null            $now     Current time (tests); null for now.
	 * @return bool
	 */
	public static function has_ended( array $license, ?int $now = null ): bool {
		$status = (string) ( $license['status'] ?? '' );
		if ( in_array( $status, self::STATES, true ) ) {
			return true;
		}
		$days = self::suspended_days();
		if ( 'suspended' !== $status || $days < 1 || empty( $license['suspended_at'] ) ) {
			return false;
		}
		$since = strtotime( (string) $license['suspended_at'] . ' UTC' );

		return false !== $since && $since <= ( null === $now ? time() : $now ) - $days * DAY_IN_SECONDS;
	}
}
