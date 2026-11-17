<?php
/**
 * Runs listener calls only after the current state change is committed.
 *
 * @package WPLC
 */

namespace WPLC\Events;

use WPLC\License\LicenseLifecycle;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The "after commit" promise of the license events (contract, see docs/LICENSE-EVENTS.md).
 *
 * A call handed to run() is made right away when the request holds no
 * per-license lock, no activation lock of this plugin (hold()) and no
 * transaction opened with begin(). Otherwise it is queued and made once the
 * last of them ends: commit() and the release of the last lock run the queue.
 * rollback() drops the calls queued inside the transaction. Whatever is still
 * queued at `shutdown` runs then, except calls of a transaction that was never
 * committed (MySQL rolls it back when the connection closes).
 */
final class AfterCommit {

	/**
	 * Whether a transaction opened with begin() is running.
	 *
	 * @var bool
	 */
	private static $transaction = false;

	/**
	 * Locks of this plugin held by the request outside the license lock (activation locks).
	 *
	 * @var int
	 */
	private static $holds = 0;

	/**
	 * Queued calls: callback and whether it was queued inside a transaction.
	 *
	 * @var array<int,array{0:callable,1:bool}>
	 */
	private static $queue = array();

	/**
	 * Register the shutdown fallback.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'shutdown', array( self::class, 'on_shutdown' ), 1 );
	}

	/**
	 * Open a transaction (WooCommerce's transaction helper, where available).
	 *
	 * MySQL does not nest transactions; callers never open a second one.
	 *
	 * @return void
	 */
	public static function begin(): void {
		self::relax_mariadb_snapshot_isolation();
		if ( function_exists( 'wc_transaction_query' ) ) {
			wc_transaction_query( 'start' );
		}
		self::$transaction = true;
	}

	/**
	 * On MariaDB 11.6.2 and later, switch `innodb_snapshot_isolation` off for this connection before
	 * a transaction. The plugin serialises concurrent changes with named locks and conditional
	 * updates; with snapshot isolation on, a row another request changed after this transaction's
	 * first read would make the next UPDATE fail with error 1020 and roll back the whole renewal.
	 * MySQL is not affected. Runs once per request; a failure is ignored (older or managed servers).
	 *
	 * @return void
	 */
	public static function relax_mariadb_snapshot_isolation(): void {
		global $wpdb;
		static $done = false;

		if ( $done || ! is_object( $wpdb ) || ! \WPLC\Compatibility\Requirements::mariadb_snapshot_isolation_applies() ) {
			return;
		}
		$done        = true;
		$suppressed  = $wpdb->suppress_errors( true );
		$wpdb->query( 'SET SESSION innodb_snapshot_isolation = OFF' );
		$wpdb->suppress_errors( $suppressed );
	}

	/**
	 * Commit the transaction and run the calls that waited for it.
	 *
	 * @return void
	 */
	public static function commit(): void {
		if ( function_exists( 'wc_transaction_query' ) ) {
			wc_transaction_query( 'commit' );
		}
		self::$transaction = false;
		self::flush();
	}

	/**
	 * Roll the transaction back and drop the calls queued inside it.
	 *
	 * @return void
	 */
	public static function rollback(): void {
		if ( function_exists( 'wc_transaction_query' ) ) {
			wc_transaction_query( 'rollback' );
		}
		self::drop_transactional();
		self::flush();
	}

	/**
	 * Note that the request took a lock of this plugin (activation lock).
	 *
	 * @return void
	 */
	public static function hold(): void {
		++self::$holds;
	}

	/**
	 * Note that the request released a lock taken after hold(); runs the queue when nothing blocks it.
	 *
	 * @return void
	 */
	public static function release(): void {
		self::$holds = max( 0, self::$holds - 1 );
		self::flush();
	}

	/**
	 * Whether a call would be queued now.
	 *
	 * @return bool
	 */
	public static function blocked(): bool {
		return self::$transaction || self::$holds > 0 || LicenseLifecycle::holds_any_license_lock();
	}

	/**
	 * Make the call now, or once the lock is free and the transaction is committed.
	 *
	 * @param callable $callback Call without arguments.
	 * @return void
	 */
	public static function run( callable $callback ): void {
		if ( self::blocked() ) {
			self::$queue[] = array( $callback, self::$transaction );
			return;
		}
		$callback();
	}

	/**
	 * Run the queued calls in order while nothing blocks them.
	 *
	 * A call is removed before it runs, so a listener that throws never runs twice.
	 *
	 * @return void
	 */
	public static function flush(): void {
		while ( self::$queue && ! self::blocked() ) {
			$item = array_shift( self::$queue );
			( $item[0] )();
		}
	}

	/**
	 * Run what is left at the end of the request.
	 *
	 * @return void
	 */
	public static function on_shutdown(): void {
		if ( self::$transaction ) {
			self::drop_transactional();
		}
		self::$holds = 0;
		self::flush();
	}

	/**
	 * Number of queued calls (diagnostics and tests).
	 *
	 * @return int
	 */
	public static function pending(): int {
		return count( self::$queue );
	}

	/**
	 * Forget all state (tests).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$transaction = false;
		self::$holds       = 0;
		self::$queue       = array();
	}

	/**
	 * End the transaction state and drop the calls queued inside it.
	 *
	 * @return void
	 */
	private static function drop_transactional(): void {
		self::$transaction = false;
		self::$queue       = array_values(
			array_filter(
				self::$queue,
				static function ( array $item ): bool {
					return ! $item[1];
				}
			)
		);
	}
}
