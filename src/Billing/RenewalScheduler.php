<?php
/**
 * Renewal period scheduling.
 *
 * @package WPLC
 */

namespace WPLC\Billing;

use WPLC\Infrastructure\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates one renewal row and one scheduled action per license period.
 */
final class RenewalScheduler {

	/**
	 * Create and schedule the next period for an eligible license.
	 *
	 * @param int $license_id License ID.
	 * @return int Renewal ID, or zero if not eligible.
	 */
	public static function schedule_next( int $license_id ): int {
		global $wpdb;

		$tables = Schema::table_names();
		$license = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$tables['licenses']} WHERE id = %d LIMIT 1", $license_id ),
			ARRAY_A
		);
		if ( ! is_array( $license ) || empty( $license['renewal_enabled'] ) || empty( $license['paid_until'] ) ) {
			return 0;
		}

		$period_start = (string) $license['paid_until'];
		$start_timestamp = strtotime( $period_start . ' UTC' );
		if ( ! $start_timestamp ) {
			return 0;
		}
		$period_end = gmdate( 'Y-m-d H:i:s', self::period_end( $start_timestamp, (string) $license['billing_interval'] ) );
		$idempotency_key = hash( 'sha256', 'wplc:' . $license_id . ':' . $period_start );

		$existing = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$tables['renewals']} WHERE idempotency_key = %s LIMIT 1", $idempotency_key )
		);
		if ( $existing ) {
			self::enqueue( $existing, $start_timestamp );
			return $existing;
		}

		$now = gmdate( 'Y-m-d H:i:s' );
		$inserted = $wpdb->insert(
			$tables['renewals'],
			array(
				'license_id' => $license_id,
				'period_start' => $period_start,
				'period_end' => $period_end,
				'idempotency_key' => $idempotency_key,
				'status' => 'scheduled',
				'retry_at' => $period_start,
				'grace_ends_at' => gmdate( 'Y-m-d H:i:s', $start_timestamp + 14 * DAY_IN_SECONDS ),
				'attempt_count' => 0,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		if ( false === $inserted || ! $wpdb->insert_id ) {
			return 0;
		}

		$renewal_id = (int) $wpdb->insert_id;
		self::enqueue( $renewal_id, $start_timestamp );

		return $renewal_id;
	}

	/**
	 * Schedule a single action using Action Scheduler when available.
	 *
	 * @param int $renewal_id Renewal ID.
	 * @param int $timestamp UTC timestamp.
	 * @return void
	 */
	public static function enqueue( int $renewal_id, int $timestamp ): void {
		$args = array( $renewal_id );
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( max( time() + 30, $timestamp ), 'wplc_process_renewal', $args, 'wplc', true );
		} elseif ( ! wp_next_scheduled( 'wplc_process_renewal', $args ) ) {
			wp_schedule_single_event( max( time() + 30, $timestamp ), 'wplc_process_renewal', $args );
		}
	}

	/**
	 * Advance a period without end-of-month overflow.
	 *
	 * @param int $timestamp UTC timestamp.
	 * @param string $interval Billing interval.
	 * @return int
	 */
	private static function period_end( int $timestamp, string $interval ): int {
		$date = ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( new \DateTimeZone( 'UTC' ) );
		$day = (int) $date->format( 'j' );
		$months = 'month' === $interval ? 1 : 12;
		$first = $date->modify( 'first day of this month' )->modify( '+' . $months . ' months' );

		return $first->setDate(
			(int) $first->format( 'Y' ),
			(int) $first->format( 'n' ),
			min( $day, (int) $first->format( 't' ) )
		)->setTime(
			(int) $date->format( 'G' ),
			(int) $date->format( 'i' ),
			(int) $date->format( 's' )
		)->getTimestamp();
	}
}
