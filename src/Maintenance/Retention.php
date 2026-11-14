<?php
/**
 * Scheduled data retention.
 *
 * @package WPLC
 */

namespace WPLC\Maintenance;

use WPLC\Infrastructure\Database\Schema;
use WPLC\Notifications\ReminderLog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deletes records that are older than their retention period, in bounded batches.
 */
final class Retention {

	/**
	 * Default retention periods in days. Zero disables a rule.
	 */
	public const DEFAULT_DAYS = array(
		'audit_log' => 730,
		'event_inbox' => 180,
		'activations' => 365,
		'reminder_log' => 730,
	);

	/**
	 * Rows deleted per run and table.
	 */
	public const BATCH_SIZE = 1000;

	/**
	 * Longest accepted retention period (100 years) to keep date math safe.
	 */
	private const MAX_DAYS = 36500;

	/**
	 * License states after which deactivated installations may be deleted.
	 *
	 * @var string[]
	 */
	private const TERMINAL_STATES = array( 'expired', 'revoked', 'cancelled' );

	/**
	 * Inbox outcomes that still need work and are never deleted.
	 *
	 * @var string[]
	 */
	private const OPEN_INBOX_OUTCOMES = array( 'pending_reconciliation', 'manual_review', 'received' );

	/**
	 * Register the daily job.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'wplc_daily_lifecycle', array( self::class, 'run' ), 30 );
	}

	/**
	 * Compute which rules run and the UTC cutoff of each.
	 *
	 * Pure: no WordPress functions. Unknown keys are ignored, missing keys use
	 * the default, and zero, negative or non-numeric values disable a rule.
	 *
	 * @param mixed $days Retention days keyed by rule, usually the filtered default map.
	 * @param int   $now  Unix timestamp.
	 * @return array<string,string> Rule => 'Y-m-d H:i:s' cutoff; rows older than it are deleted.
	 */
	public static function cutoffs( $days, int $now ): array {
		$days    = is_array( $days ) ? $days : array();
		$cutoffs = array();
		foreach ( self::DEFAULT_DAYS as $rule => $default ) {
			$value = array_key_exists( $rule, $days ) ? $days[ $rule ] : $default;
			if ( ! is_int( $value ) && ! ( is_string( $value ) && preg_match( '/^\d{1,6}$/', $value ) ) ) {
				continue;
			}
			$value = min( self::MAX_DAYS, (int) $value );
			if ( $value < 1 ) {
				continue;
			}
			$cutoffs[ $rule ] = gmdate( 'Y-m-d H:i:s', $now - $value * 86400 );
		}

		return $cutoffs;
	}

	/**
	 * Run every enabled retention rule once.
	 *
	 * @return void
	 */
	public static function run(): void {
		try {
			/**
			 * Filter the retention periods in days.
			 *
			 * @param array<string,int> $days Keys: audit_log, event_inbox, activations, reminder_log. Zero disables a rule.
			 */
			$days    = apply_filters( 'wplc_retention_days', self::DEFAULT_DAYS );
			$cutoffs = self::cutoffs( $days, time() );
		} catch ( \Throwable $error ) {
			self::log( 'error', 'Retention configuration failed: ' . $error->getMessage() );
			return;
		}

		$deleted = array();
		foreach ( $cutoffs as $rule => $cutoff ) {
			try {
				$count = self::delete_batch( $rule, $cutoff );
				if ( $count > 0 ) {
					$deleted[ $rule ] = $count;
				}
			} catch ( \Throwable $error ) {
				self::log( 'error', 'Retention rule "' . $rule . '" failed: ' . $error->getMessage() );
			}
		}

		if ( $deleted ) {
			$parts = array();
			foreach ( $deleted as $rule => $count ) {
				$parts[] = $rule . '=' . $count;
			}
			self::log( 'info', 'Retention deleted rows: ' . implode( ', ', $parts ) );
		}
	}

	/**
	 * Delete one bounded batch for one rule.
	 *
	 * @param string $rule   Rule key.
	 * @param string $cutoff UTC datetime.
	 * @return int Deleted rows.
	 */
	private static function delete_batch( string $rule, string $cutoff ): int {
		global $wpdb;

		$tables = Schema::table_names();

		switch ( $rule ) {
			case 'audit_log':
				// IDs grow with created_at, so the oldest row tells whether there is anything to do.
				$oldest = $wpdb->get_var( "SELECT created_at FROM {$tables['audit_log']} ORDER BY id ASC LIMIT 1" );
				if ( ! is_string( $oldest ) || $oldest >= $cutoff ) {
					return 0;
				}
				$result = $wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$tables['audit_log']} WHERE created_at < %s ORDER BY id ASC LIMIT %d",
						$cutoff,
						self::BATCH_SIZE
					)
				);
				return self::affected( $result, $rule );

			case 'event_inbox':
				$result = $wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$tables['event_inbox']} WHERE processed_at IS NOT NULL AND processed_at < %s AND outcome NOT IN (%s,%s,%s) ORDER BY processed_at ASC LIMIT %d",
						$cutoff,
						self::OPEN_INBOX_OUTCOMES[0],
						self::OPEN_INBOX_OUTCOMES[1],
						self::OPEN_INBOX_OUTCOMES[2],
						self::BATCH_SIZE
					)
				);
				return self::affected( $result, $rule );

			case 'activations':
				return self::delete_activations( $tables, $cutoff );

			case 'reminder_log':
				if ( ! ReminderLog::is_available() ) {
					return 0;
				}
				$result = $wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$tables['reminder_log']} WHERE sent_at < %s ORDER BY sent_at ASC LIMIT %d",
						$cutoff,
						self::BATCH_SIZE
					)
				);
				return self::affected( $result, $rule );
		}

		return 0;
	}

	/**
	 * Delete deactivated installations of licenses that have ended.
	 *
	 * @param array<string,string> $tables Table names.
	 * @param string               $cutoff UTC datetime.
	 * @return int Deleted rows.
	 */
	private static function delete_activations( array $tables, string $cutoff ): int {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT a.id FROM {$tables['activations']} a INNER JOIN {$tables['licenses']} l ON l.id = a.license_id WHERE a.status <> %s AND a.deactivated_at IS NOT NULL AND a.deactivated_at < %s AND l.status IN (%s,%s,%s) AND NOT EXISTS ( SELECT 1 FROM {$tables['activations']} c WHERE c.staging_parent_id = a.id AND c.status = %s ) ORDER BY a.id ASC LIMIT %d",
				'active',
				$cutoff,
				self::TERMINAL_STATES[0],
				self::TERMINAL_STATES[1],
				self::TERMINAL_STATES[2],
				'active',
				self::BATCH_SIZE
			)
		);
		$ids = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
		if ( ! $ids ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// Replay-protection rows of a deleted installation have no further use.
		$wpdb->query(
			$wpdb->prepare( "DELETE FROM {$tables['request_nonces']} WHERE activation_id IN ({$placeholders})", $ids )
		);

		// The status and date conditions are repeated so a row reactivated in between is kept.
		$result = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$tables['activations']} WHERE id IN ({$placeholders}) AND status <> %s AND deactivated_at IS NOT NULL AND deactivated_at < %s",
				array_merge( $ids, array( 'active', $cutoff ) )
			)
		);

		return self::affected( $result, 'activations' );
	}

	/**
	 * Normalize a query result and log database errors.
	 *
	 * @param mixed  $result Result of $wpdb->query().
	 * @param string $rule   Rule key.
	 * @return int
	 */
	private static function affected( $result, string $rule ): int {
		if ( false === $result ) {
			self::log( 'error', 'Retention rule "' . $rule . '" could not delete rows (database error).' );
			return 0;
		}
		return (int) $result;
	}

	/**
	 * Write to the WooCommerce log.
	 *
	 * @param string $level   Log level.
	 * @param string $message Message without personal data.
	 * @return void
	 */
	private static function log( string $level, string $message ): void {
		try {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->log( $level, $message, array( 'source' => 'wp-license' ) );
			}
		} catch ( \Throwable $error ) {
			unset( $error );
		}
	}
}
