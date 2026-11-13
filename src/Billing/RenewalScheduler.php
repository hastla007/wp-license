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

		$start_timestamp = strtotime( (string) $license['paid_until'] . ' UTC' );
		if ( ! $start_timestamp ) {
			return 0;
		}

		$renewal_id = self::find_or_create_row( $license, 'scheduled' );
		if ( ! $renewal_id ) {
			return 0;
		}
		// A row first created by an unpaid manual renewal becomes an automatic one.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$tables['renewals']} SET status = %s, retry_at = period_start, updated_at = %s WHERE id = %d AND status = %s",
				'scheduled',
				gmdate( 'Y-m-d H:i:s' ),
				$renewal_id,
				'manual'
			)
		);
		self::enqueue( $renewal_id, $start_timestamp );

		return $renewal_id;
	}

	/**
	 * Stable key of the renewal row for one license period.
	 *
	 * Automatic and manual renewals of the same period share this key, so a
	 * period can only ever be paid once.
	 *
	 * @param int    $license_id License ID.
	 * @param string $period_start Period start ('Y-m-d H:i:s' UTC), the license's paid_until.
	 * @return string
	 */
	public static function idempotency_key( int $license_id, string $period_start ): string {
		return hash( 'sha256', 'wplc:' . $license_id . ':' . $period_start );
	}

	/**
	 * Gateway idempotency key for one charge attempt of a renewal.
	 *
	 * Stripe replays the stored response for a known key, so one key for all
	 * attempts would return the first decline forever. The attempt counter only
	 * advances after a definite failure was recorded, therefore:
	 * - a retry after a recorded failure sends a new key and is a new request;
	 * - a re-run of the same attempt (crash or timeout before the result was
	 *   recorded) sends the same key and cannot charge the customer twice.
	 *
	 * @param string $renewal_key Renewal row idempotency key.
	 * @param int    $attempt_count Definite failures recorded so far.
	 * @return string
	 */
	public static function attempt_idempotency_key( string $renewal_key, int $attempt_count ): string {
		return $renewal_key . ':' . max( 0, $attempt_count );
	}

	/**
	 * Find or create the renewal row for the period that starts at paid_until.
	 *
	 * @param array<string,mixed> $license License row.
	 * @param string              $initial_status Status of a new row: 'scheduled' (automatic) or 'manual'.
	 * @return int Renewal ID, or zero on failure.
	 */
	public static function find_or_create_row( array $license, string $initial_status = 'scheduled' ): int {
		global $wpdb;

		$license_id = (int) ( $license['id'] ?? 0 );
		$period_start = (string) ( $license['paid_until'] ?? '' );
		$start_timestamp = '' === $period_start ? false : strtotime( $period_start . ' UTC' );
		if ( ! $license_id || ! $start_timestamp ) {
			return 0;
		}

		$tables = Schema::table_names();
		$idempotency_key = self::idempotency_key( $license_id, $period_start );
		$existing = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$tables['renewals']} WHERE idempotency_key = %s LIMIT 1", $idempotency_key )
		);
		if ( $existing ) {
			return $existing;
		}

		$automatic = 'manual' !== $initial_status;
		$now = gmdate( 'Y-m-d H:i:s' );
		$inserted = $wpdb->insert(
			$tables['renewals'],
			array(
				'license_id' => $license_id,
				'period_start' => $period_start,
				'period_end' => gmdate( 'Y-m-d H:i:s', self::period_end( $start_timestamp, (string) $license['billing_interval'] ) ),
				'idempotency_key' => $idempotency_key,
				'status' => $automatic ? 'scheduled' : 'manual',
				'retry_at' => $automatic ? $period_start : null,
				'grace_ends_at' => gmdate( 'Y-m-d H:i:s', $start_timestamp + \WPLC\License\LicenseLifecycle::GRACE_DAYS * DAY_IN_SECONDS ),
				'attempt_count' => 0,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		if ( false === $inserted || ! $wpdb->insert_id ) {
			// A concurrent request may have inserted the same unique key.
			return (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$tables['renewals']} WHERE idempotency_key = %s LIMIT 1", $idempotency_key )
			);
		}

		return (int) $wpdb->insert_id;
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
	 * Single source of the period math for automatic and manual renewals.
	 *
	 * @param int $timestamp UTC timestamp.
	 * @param string $interval Billing interval ('month'; anything else is a year).
	 * @return int
	 */
	public static function period_end( int $timestamp, string $interval ): int {
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
