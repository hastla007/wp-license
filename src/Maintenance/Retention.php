<?php
/**
 * Scheduled data retention.
 *
 * @package WPLC
 */

namespace WPLC\Maintenance;

use WPLC\Infrastructure\Database\Schema;
use WPLC\License\ActivationManager;
use WPLC\License\AuditLog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deletes records that are older than their retention period.
 *
 * Every rule works in bounded batches and repeats until nothing is left or
 * the time budget of the run is used up, so a backlog of any size is removed
 * without one long-running query.
 */
final class Retention {

	/**
	 * Default retention periods in days. Zero disables a rule.
	 *
	 * - audit_log:                 audit entries by creation date.
	 * - event_inbox:               processed gateway events by processing date.
	 * - event_inbox_open:          longest age of events that were never resolved
	 *                              (`manual_review`, `pending_reconciliation`, `received`), by receipt date.
	 * - activations:               deactivated installations of ended licenses.
	 * - inactive_activations:      deactivated installations of licenses that are still in force.
	 * - ended_license_activations: days after the paid period of an expired or cancelled license
	 *                              at which its still active installations are deactivated
	 *                              (a revoked license loses them at once).
	 * - reminder_log:              notification log rows.
	 * - anonymize_licenses:        days after the end of a license at which the owner, the payment
	 *                              token reference and the installations are removed. Off by default.
	 * - consents:                  evidence of the consent to automatic renewal, by consent date, once
	 *                              its license has ended or no longer exists. Ten years by default:
	 *                              the evidence belongs to charges that are kept as long as invoices.
	 */
	public const DEFAULT_DAYS = array(
		'audit_log' => 730,
		'event_inbox' => 180,
		'event_inbox_open' => 730,
		'activations' => 365,
		'inactive_activations' => 365,
		'ended_license_activations' => 30,
		'reminder_log' => 730,
		'anonymize_licenses' => 0,
		'consents' => 3650,
	);

	/**
	 * Rows deleted per query.
	 */
	public const BATCH_SIZE = 1000;

	/**
	 * Rows per query for rules that work row by row (locks, audit entries).
	 */
	private const SMALL_BATCH_SIZE = 200;

	/**
	 * Default seconds one daily run may take (filter `wplc_retention_time_budget`).
	 */
	public const DEFAULT_TIME_BUDGET = 20;

	/**
	 * Seconds the hourly sweep of short-lived rows may take.
	 */
	private const SWEEP_TIME_BUDGET = 10;

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
	 * Inbox outcomes that still need work. They are kept beyond `event_inbox` and only fall under `event_inbox_open`.
	 *
	 * @var string[]
	 */
	private const OPEN_INBOX_OUTCOMES = array( 'pending_reconciliation', 'manual_review', 'received' );

	/**
	 * Register the jobs.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'wplc_daily_lifecycle', array( self::class, 'run_scheduled' ), 30, 0 );
		// Before the per-package cleanups of the same hook, which delete at most 1000 rows per hour.
		add_action( 'wplc_cleanup_security_rows', array( self::class, 'sweep_scheduled' ), 5, 0 );
		add_action( 'wplc/license_status_changed', array( self::class, 'on_license_status_changed' ), 30, 3 );
	}

	/**
	 * Daily job.
	 *
	 * @return void
	 */
	public static function run_scheduled(): void {
		self::run();
	}

	/**
	 * Hourly job.
	 *
	 * @return void
	 */
	public static function sweep_scheduled(): void {
		self::sweep_short_lived_rows();
	}

	/**
	 * Compute which rules run and the UTC cutoff of each.
	 *
	 * Pure: no WordPress functions. Unknown keys are ignored, missing keys use
	 * the default, and zero, negative or non-numeric values disable a rule.
	 *
	 * @param mixed $days Retention days keyed by rule, usually the filtered default map.
	 * @param int   $now  Unix timestamp.
	 * @return array<string,string> Rule => 'Y-m-d H:i:s' cutoff; rows older than it are processed.
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
	 * Run every enabled retention rule until it is done or the time budget is used up.
	 *
	 * @return array<string,int> Processed rows per rule (for tests and logs).
	 */
	public static function run(): array {
		try {
			/**
			 * Filter the retention periods in days.
			 *
			 * @param array<string,int> $days See Retention::DEFAULT_DAYS for the keys. Zero disables a rule.
			 */
			$days    = apply_filters( 'wplc_retention_days', self::DEFAULT_DAYS );
			$cutoffs = self::cutoffs( $days, time() );
			/**
			 * Filter the seconds one retention run may take.
			 *
			 * @param int $seconds Default 20.
			 */
			$budget = max( 1, min( 300, (int) apply_filters( 'wplc_retention_time_budget', self::DEFAULT_TIME_BUDGET ) ) );
		} catch ( \Throwable $error ) {
			self::log( 'error', 'Retention configuration failed: ' . $error->getMessage() );
			return array();
		}

		$deadline  = microtime( true ) + $budget;
		$processed = array();
		$exhausted = false;
		foreach ( $cutoffs as $rule => $cutoff ) {
			if ( microtime( true ) >= $deadline ) {
				$exhausted = true;
				break;
			}
			try {
				$batch_size = in_array( $rule, array( 'ended_license_activations', 'anonymize_licenses' ), true ) ? self::SMALL_BATCH_SIZE : self::BATCH_SIZE;
				$total      = 0;
				do {
					$count  = self::process_batch( $rule, $cutoff );
					$total += $count;
					$more   = $count >= $batch_size;
					if ( $more && microtime( true ) >= $deadline ) {
						$exhausted = true;
						break;
					}
				} while ( $more );
				if ( $total > 0 ) {
					$processed[ $rule ] = $total;
				}
			} catch ( \Throwable $error ) {
				self::log( 'error', 'Retention rule "' . $rule . '" failed: ' . $error->getMessage() );
			}
		}

		if ( $processed ) {
			$parts = array();
			foreach ( $processed as $rule => $count ) {
				$parts[] = $rule . '=' . $count;
			}
			self::log( 'info', 'Retention processed rows: ' . implode( ', ', $parts ) . ( $exhausted ? ' (time budget reached, continues with the next run)' : '' ) );
		}

		return $processed;
	}

	/**
	 * Delete expired replay-protection, rate-limit, download-grant and stream rows.
	 *
	 * Runs hourly. These tables grow with every API request; each query deletes
	 * a bounded batch through an index on the expiry column and is repeated
	 * until the table is clean or the time budget is used up.
	 *
	 * @return array<string,int> Deleted rows per table key.
	 */
	public static function sweep_short_lived_rows(): array {
		global $wpdb;

		$tables = Schema::table_names();
		$now    = time();
		/** This filter is documented in src/Api/V1/UpdateService.php */
		$grant_days = max( 1, min( 3650, absint( apply_filters( 'wplc_download_grant_retention_days', 7 ) ) ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- only table names are interpolated.
		$queries = array(
			'request_nonces'  => $wpdb->prepare( "DELETE FROM {$tables['request_nonces']} WHERE expires_at <= %s LIMIT %d", gmdate( 'Y-m-d H:i:s', $now ), self::BATCH_SIZE ),
			'request_limits'  => $wpdb->prepare( "DELETE FROM {$tables['request_limits']} WHERE bucket_start < %s LIMIT %d", gmdate( 'Y-m-d H:i:s', $now - DAY_IN_SECONDS ), self::BATCH_SIZE ),
			'download_tokens' => $wpdb->prepare( "DELETE FROM {$tables['download_tokens']} WHERE expires_at < %s LIMIT %d", gmdate( 'Y-m-d H:i:s', $now - $grant_days * DAY_IN_SECONDS ), self::BATCH_SIZE ),
			'package_streams' => $wpdb->prepare( "DELETE FROM {$tables['package_streams']} WHERE expires_at < %s LIMIT %d", gmdate( 'Y-m-d H:i:s', $now - DAY_IN_SECONDS ), self::BATCH_SIZE ),
		);
		// phpcs:enable

		$deadline = microtime( true ) + self::SWEEP_TIME_BUDGET;
		$deleted  = array();
		foreach ( $queries as $key => $query ) {
			$total = 0;
			do {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
				$count  = self::affected( $wpdb->query( $query ), $key );
				$total += $count;
			} while ( $count >= self::BATCH_SIZE && microtime( true ) < $deadline );
			if ( $total > 0 ) {
				$deleted[ $key ] = $total;
			}
			if ( microtime( true ) >= $deadline ) {
				break;
			}
		}

		return $deleted;
	}

	/**
	 * A revoked license loses its active installations immediately.
	 *
	 * Expired and cancelled licenses keep them until `ended_license_activations`
	 * days have passed, because a late renewal payment reactivates the license
	 * and the customer's sites should keep working without a new activation.
	 *
	 * @param mixed $license_id License ID.
	 * @param mixed $old_status Previous status.
	 * @param mixed $new_status New status.
	 * @return void
	 */
	public static function on_license_status_changed( $license_id = 0, $old_status = '', $new_status = '' ): void {
		if ( 'revoked' !== $new_status || (int) $license_id < 1 ) {
			return;
		}
		try {
			self::deactivate_installations_of( (int) $license_id, 'License revoked' );
		} catch ( \Throwable $error ) {
			self::log( 'error', 'Installations of a revoked license could not be deactivated: ' . $error->getMessage() );
		}
	}

	/**
	 * Deactivate every active installation of one license.
	 *
	 * @param int    $license_id License ID.
	 * @param string $reason     Audit reason.
	 * @return int Deactivated installations (staging children are deactivated with their parent).
	 */
	public static function deactivate_installations_of( int $license_id, string $reason ): int {
		global $wpdb;

		$tables = Schema::table_names();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- only the table name is interpolated.
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$tables['activations']} WHERE license_id = %d AND status = %s ORDER BY staging_parent_id IS NOT NULL, id ASC LIMIT %d", $license_id, 'active', self::SMALL_BATCH_SIZE ) );
		$count = 0;
		foreach ( (array) $ids as $activation_id ) {
			if ( ActivationManager::deactivate( (int) $activation_id, $reason, 0, 'site_deactivated_license_ended' ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Process one bounded batch for one rule.
	 *
	 * @param string $rule   Rule key.
	 * @param string $cutoff UTC datetime.
	 * @return int Processed rows.
	 */
	private static function process_batch( string $rule, string $cutoff ): int {
		global $wpdb;

		$tables = Schema::table_names();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- only table names are interpolated.
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

			case 'event_inbox_open':
				// Unresolved events are kept much longer, but not forever: the payload contains customer data.
				$result = $wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$tables['event_inbox']} WHERE received_at < %s AND outcome IN (%s,%s,%s) ORDER BY id ASC LIMIT %d",
						$cutoff,
						self::OPEN_INBOX_OUTCOMES[0],
						self::OPEN_INBOX_OUTCOMES[1],
						self::OPEN_INBOX_OUTCOMES[2],
						self::BATCH_SIZE
					)
				);
				return self::affected( $result, $rule );

			case 'activations':
				return self::delete_activations( $tables, $cutoff, true );

			case 'inactive_activations':
				return self::delete_activations( $tables, $cutoff, false );

			case 'ended_license_activations':
				return self::deactivate_ended_license_activations( $tables, $cutoff );

			case 'anonymize_licenses':
				return self::anonymize_licenses( $tables, $cutoff );

			case 'consents':
				$result = $wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$tables['consents']} WHERE id IN ( SELECT id FROM ( SELECT c.id FROM {$tables['consents']} c LEFT JOIN {$tables['licenses']} l ON l.id = c.license_id WHERE c.consent_at < %s AND ( l.id IS NULL OR l.status IN (%s,%s,%s) ) ORDER BY c.id ASC LIMIT %d ) batch )",
						$cutoff,
						self::TERMINAL_STATES[0],
						self::TERMINAL_STATES[1],
						self::TERMINAL_STATES[2],
						self::BATCH_SIZE
					)
				);
				return self::affected( $result, $rule );

			case 'reminder_log':
				$result = $wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$tables['reminder_log']} WHERE sent_at < %s ORDER BY sent_at ASC LIMIT %d",
						$cutoff,
						self::BATCH_SIZE
					)
				);
				return self::affected( $result, $rule );
		}
		// phpcs:enable

		return 0;
	}

	/**
	 * Delete deactivated installations.
	 *
	 * @param array<string,string> $tables Table names.
	 * @param string               $cutoff UTC datetime.
	 * @param bool                 $ended  True: installations of ended licenses. False: of licenses still in force
	 *                                     (and of licenses that no longer exist).
	 * @return int Deleted rows.
	 */
	private static function delete_activations( array $tables, string $cutoff, bool $ended ): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- table names and one %d per ID.
		$license_condition = $ended ? 'l.status IN (%s,%s,%s)' : '( l.id IS NULL OR l.status NOT IN (%s,%s,%s) )';
		$ids               = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT a.id FROM {$tables['activations']} a LEFT JOIN {$tables['licenses']} l ON l.id = a.license_id WHERE a.status <> %s AND a.deactivated_at IS NOT NULL AND a.deactivated_at < %s AND {$license_condition} AND NOT EXISTS ( SELECT 1 FROM {$tables['activations']} c WHERE c.staging_parent_id = a.id AND c.status = %s ) ORDER BY a.id ASC LIMIT %d",
				'active',
				$cutoff,
				self::TERMINAL_STATES[0],
				self::TERMINAL_STATES[1],
				self::TERMINAL_STATES[2],
				'active',
				self::BATCH_SIZE
			)
		);
		$ids               = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
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
		// phpcs:enable

		return self::affected( $result, $ended ? 'activations' : 'inactive_activations' );
	}

	/**
	 * Deactivate installations that are still active although their license has ended.
	 *
	 * Revoked licenses at once (safety net for the status hook), expired and
	 * cancelled licenses once the paid period ended before the cutoff.
	 *
	 * @param array<string,string> $tables Table names.
	 * @param string               $cutoff UTC datetime.
	 * @return int Handled installations; a full batch means "there may be more".
	 */
	private static function deactivate_ended_license_activations( array $tables, string $cutoff ): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- only table names are interpolated.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT a.id FROM {$tables['activations']} a INNER JOIN {$tables['licenses']} l ON l.id = a.license_id WHERE a.status = %s AND ( l.status = %s OR ( l.status IN (%s,%s) AND l.paid_until IS NOT NULL AND l.paid_until < %s ) ) ORDER BY a.staging_parent_id IS NOT NULL, a.id ASC LIMIT %d",
				'active',
				'revoked',
				'expired',
				'cancelled',
				$cutoff,
				self::SMALL_BATCH_SIZE
			)
		);
		// phpcs:enable
		$done = 0;
		foreach ( (array) $ids as $activation_id ) {
			if ( ActivationManager::deactivate( (int) $activation_id, 'License ended', 0, 'site_deactivated_license_ended' ) ) {
				++$done;
			}
		}

		// Without progress (lock contention) the loop must stop; the next run tries again.
		return $done > 0 && count( (array) $ids ) >= self::SMALL_BATCH_SIZE ? self::SMALL_BATCH_SIZE : $done;
	}

	/**
	 * Remove the personal references of licenses that ended long ago.
	 *
	 * The license row stays as a business record (product, plan, dates, order
	 * link). Removed are the owner, the payment token reference and all
	 * installations with their site addresses. Disabled unless a period is
	 * configured for `anonymize_licenses`.
	 *
	 * @param array<string,string> $tables Table names.
	 * @param string               $cutoff UTC datetime.
	 * @return int Anonymized licenses; a full batch means "there may be more".
	 */
	private static function anonymize_licenses( array $tables, string $cutoff ): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- only table names are interpolated.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT l.id FROM {$tables['licenses']} l WHERE l.status IN (%s,%s,%s) AND COALESCE( l.revoked_at, l.paid_until, l.updated_at ) < %s AND l.updated_at < %s AND ( l.customer_id > 0 OR l.payment_token_id > 0 OR EXISTS ( SELECT 1 FROM {$tables['activations']} a WHERE a.license_id = l.id ) ) ORDER BY l.id ASC LIMIT %d",
				self::TERMINAL_STATES[0],
				self::TERMINAL_STATES[1],
				self::TERMINAL_STATES[2],
				$cutoff,
				$cutoff,
				self::SMALL_BATCH_SIZE
			)
		);

		$done = 0;
		foreach ( (array) $ids as $license_id ) {
			$license_id = (int) $license_id;
			$updated    = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$tables['licenses']} SET customer_id = 0, payment_token_id = 0, renewal_enabled = 0, next_payment_at = NULL WHERE id = %d AND status IN (%s,%s,%s)",
					$license_id,
					self::TERMINAL_STATES[0],
					self::TERMINAL_STATES[1],
					self::TERMINAL_STATES[2]
				)
			);
			if ( false === $updated ) {
				continue;
			}
			\WPLC\Gateway\PaymentReference::clear( $license_id );
			$wpdb->query( $wpdb->prepare( "UPDATE {$tables['consents']} SET customer_id = 0 WHERE license_id = %d AND customer_id > 0", $license_id ) );
			$wpdb->query( $wpdb->prepare( "DELETE n FROM {$tables['request_nonces']} n INNER JOIN {$tables['activations']} a ON a.id = n.activation_id WHERE a.license_id = %d", $license_id ) );
			$removed = $wpdb->query( $wpdb->prepare( "DELETE FROM {$tables['activations']} WHERE license_id = %d", $license_id ) );
			if ( false === $removed ) {
				continue;
			}
			++$done;
			AuditLog::record( 'license_anonymized', 'license', $license_id, 'Retention: owner, payment reference and installations removed after the license ended.', null, array( 'installations_removed' => (int) $removed ) );
		}
		// phpcs:enable

		return $done > 0 && count( (array) $ids ) >= self::SMALL_BATCH_SIZE ? self::SMALL_BATCH_SIZE : $done;
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
