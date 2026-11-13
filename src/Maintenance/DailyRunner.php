<?php
/**
 * Isolation, pause gate and heartbeat for the scheduled jobs.
 *
 * @package WPLC
 */

namespace WPLC\Maintenance;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wraps the callbacks of the plugin's job hooks.
 *
 * `wplc_daily_lifecycle` is one cron event with many callbacks registered by
 * different packages. Without isolation an exception in one callback (for
 * example in a listener of `wplc/health_alert`) stops every callback after it.
 * When the hook fires, each callback is replaced by an {@see IsolatedJob}:
 * - a \Throwable is logged and the next job runs,
 * - the health alerts run last, after the jobs they report on,
 * - a heartbeat is written per job (`Diagnostics::mark_run()` when available),
 * - billing jobs are skipped while {@see SiteGuard::billing_paused()} is true;
 *   cleanup jobs still run.
 *
 * The same pause gate (without swallowing errors, so Action Scheduler still
 * records failures) is put in front of the queued billing jobs and of the
 * customer mail listeners.
 */
final class DailyRunner {

	/**
	 * Daily cron hook.
	 */
	public const HOOK = 'wplc_daily_lifecycle';

	/**
	 * Hourly cleanup hook.
	 */
	public const HOURLY_HOOK = 'wplc_cleanup_security_rows';

	/**
	 * Option with the unix time of the last completed daily run.
	 */
	public const LAST_RUN_OPTION = 'wplc_last_daily_run';

	/**
	 * Option with the last failure per job: job => array{time:int,message:string}.
	 */
	public const FAILURES_OPTION = 'wplc_job_failures';

	/**
	 * Priority the health alerts are moved to.
	 */
	public const ALERT_PRIORITY = 1000;

	/**
	 * A missing daily run is treated as a processing gap after this many seconds.
	 */
	public const MISSED_RUN_SECONDS = 172800;

	/**
	 * Classes whose daily jobs only clean up and therefore run while billing is paused.
	 *
	 * @var string[]
	 */
	private const SAFE_WHILE_PAUSED = array(
		'WPLC\\Maintenance\\Retention',
		'WPLC\\Api\\V1\\Authenticator',
		'WPLC\\Api\\V1\\UpdateService',
		'WPLC\\Update\\PackageStream',
	);

	/**
	 * Hooks whose plugin callbacks only get the pause gate: hook => class prefix of the gated callbacks.
	 *
	 * Queued charge, retry, grace-end and reminder jobs, and the customer mails
	 * that follow a status change or a new license.
	 *
	 * @var array<string,string>
	 */
	private const GATED_HOOKS = array(
		'wplc_process_renewal'        => 'WPLC\\',
		'wplc/renewal_grace_ended'    => 'WPLC\\',
		'wplc_reconcile_stuck_renewal' => 'WPLC\\',
		'wplc_send_reminders'         => 'WPLC\\',
		'wplc/license_status_changed' => 'WPLC\\Notifications\\',
		'wplc/license_issued'         => 'WPLC\\Notifications\\',
		GapReconciler::HOOK           => 'WPLC\\',
	);

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( self::HOOK, array( self::class, 'begin' ), PHP_INT_MIN );
		add_action( self::HOOK, array( self::class, 'finish' ), PHP_INT_MAX );
		add_action( self::HOURLY_HOOK, array( self::class, 'isolate_hourly' ), PHP_INT_MIN );
		foreach ( array_keys( self::GATED_HOOKS ) as $hook ) {
			add_action( $hook, array( self::class, 'gate_current_hook' ), PHP_INT_MIN, 0 );
		}
	}

	/**
	 * First callback of the daily run: isolate the others and do the run's own bookkeeping.
	 *
	 * @return void
	 */
	public static function begin(): void {
		try {
			self::wrap_callbacks( self::HOOK, 'WPLC\\', true, true );
		} catch ( \Throwable $error ) {
			self::record_failure( 'daily_lifecycle_isolation', $error );
		}

		try {
			$now = time();
			SiteGuard::remember();
			SiteGuard::track_pause( $now );

			$last_run = (int) get_option( self::LAST_RUN_OPTION, 0 );
			if ( $last_run > 0 && $now - $last_run >= self::MISSED_RUN_SECONDS ) {
				// Cron or the queue stood still (or a backup was restored): renewals were not attempted.
				GapGuard::record( $last_run, $now, 'daily_run_missed' );
			}
			GapGuard::cleanup();
		} catch ( \Throwable $error ) {
			self::record_failure( 'daily_lifecycle_start', $error );
		}
	}

	/**
	 * Last callback of the daily run: heartbeat of the whole chain.
	 *
	 * @return void
	 */
	public static function finish(): void {
		try {
			update_option( self::LAST_RUN_OPTION, time(), false );
			self::mark_run( 'daily_lifecycle' );
		} catch ( \Throwable $error ) {
			self::record_failure( 'daily_lifecycle_finish', $error );
		}
	}

	/**
	 * Isolate the callbacks of the hourly cleanup.
	 *
	 * @return void
	 */
	public static function isolate_hourly(): void {
		try {
			self::wrap_callbacks( self::HOURLY_HOOK, 'WPLC\\', true, false );
		} catch ( \Throwable $error ) {
			self::record_failure( 'hourly_cleanup_isolation', $error );
		}
	}

	/**
	 * Put the pause gate in front of the plugin's callbacks of the hook that is firing.
	 *
	 * @return void
	 */
	public static function gate_current_hook(): void {
		$hook = (string) current_filter();
		if ( ! isset( self::GATED_HOOKS[ $hook ] ) ) {
			return;
		}
		self::wrap_callbacks( $hook, self::GATED_HOOKS[ $hook ], false, false );
	}

	/**
	 * Unix time of the last completed daily run, 0 when it never ran.
	 *
	 * @return int
	 */
	public static function last_run(): int {
		return (int) get_option( self::LAST_RUN_OPTION, 0 );
	}

	/**
	 * Last failure per job for the diagnostics page.
	 *
	 * @return array<string,array{time:int,message:string}>
	 */
	public static function failures(): array {
		$failures = get_option( self::FAILURES_OPTION, array() );

		return is_array( $failures ) ? $failures : array();
	}

	/**
	 * Whether a job may run now. Billing jobs are skipped while billing is paused.
	 *
	 * @param string $job      Job name.
	 * @param mixed  $callback Original callback.
	 * @return bool
	 */
	public static function may_run( string $job, $callback ): bool {
		if ( ! SiteGuard::billing_paused() ) {
			return true;
		}

		$class = self::callback_class( $callback );
		$runs  = '' === $class || 0 !== strpos( $class, 'WPLC\\' ) || in_array( $class, self::SAFE_WHILE_PAUSED, true );

		/**
		 * Filter whether a job runs although billing is paused.
		 *
		 * By default only cleanup jobs and callbacks of other plugins run.
		 *
		 * @param bool   $runs     Whether the job runs.
		 * @param string $job      Job name, e.g. "license_lifecycle_expire_due_licenses".
		 * @param mixed  $callback Original callback.
		 */
		return (bool) apply_filters( 'wplc_job_runs_while_billing_paused', $runs, $job, $callback );
	}

	/**
	 * Write the heartbeat of a job.
	 *
	 * @param string $job Job name.
	 * @return void
	 */
	public static function mark_run( string $job ): void {
		$target = array( '\\WPLC\\Admin\\Diagnostics', 'mark_run' );
		if ( is_callable( $target ) ) {
			try {
				call_user_func( $target, $job );
			} catch ( \Throwable $error ) {
				unset( $error );
			}
		}
	}

	/**
	 * Log a failed job and keep the reason for the diagnostics page.
	 *
	 * @param string     $job   Job name.
	 * @param \Throwable $error Error.
	 * @return void
	 */
	public static function record_failure( string $job, \Throwable $error ): void {
		$message = get_class( $error ) . ': ' . $error->getMessage() . ' (' . basename( $error->getFile() ) . ':' . $error->getLine() . ')';
		try {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error( 'Job "' . $job . '" failed and was skipped: ' . $message, array( 'source' => 'wp-license' ) );
			}
			$failures         = self::failures();
			$failures[ $job ] = array(
				'time'    => time(),
				'message' => substr( $message, 0, 500 ),
			);
			update_option( self::FAILURES_OPTION, array_slice( $failures, -30, null, true ), false );

			/**
			 * Fires after a scheduled job threw. Errors of listeners are ignored.
			 *
			 * @param string     $job   Job name.
			 * @param \Throwable $error Error.
			 */
			do_action( 'wplc/job_failed', $job, $error );
		} catch ( \Throwable $ignored ) {
			unset( $ignored );
		}
	}

	/**
	 * Job name of a callback: "license_lifecycle_expire_due_licenses" for
	 * `array( LicenseLifecycle::class, 'expire_due_licenses' )`.
	 *
	 * Pure.
	 *
	 * @param mixed $callback Callback.
	 * @return string
	 */
	public static function job_name( $callback ): string {
		if ( is_string( $callback ) && false !== strpos( $callback, '::' ) ) {
			$callback = explode( '::', $callback, 2 );
		}
		if ( is_array( $callback ) && 2 === count( $callback ) ) {
			$class = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
			$short = false !== strrpos( $class, '\\' ) ? substr( $class, strrpos( $class, '\\' ) + 1 ) : $class;
			$snake = strtolower( (string) preg_replace( '/(?<=[a-z0-9])(?=[A-Z])/', '_', $short ) );

			return (string) preg_replace( '/[^a-z0-9_]+/', '_', $snake . '_' . strtolower( (string) $callback[1] ) );
		}
		if ( is_string( $callback ) ) {
			return (string) preg_replace( '/[^a-z0-9_]+/', '_', strtolower( $callback ) );
		}

		return 'closure';
	}

	/**
	 * Class a callback belongs to, '' for functions and closures.
	 *
	 * @param mixed $callback Callback.
	 * @return string
	 */
	private static function callback_class( $callback ): string {
		if ( is_string( $callback ) && false !== strpos( $callback, '::' ) ) {
			$callback = explode( '::', $callback, 2 );
		}
		if ( is_array( $callback ) && 2 === count( $callback ) ) {
			return ltrim( is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0], '\\' );
		}

		return '';
	}

	/**
	 * Replace callbacks of a hook by IsolatedJob wrappers, keeping priority and order.
	 *
	 * Safe while the hook is firing: WP_Hook supports changes to later priorities.
	 *
	 * @param string $hook           Hook name.
	 * @param string $class_prefix   Only callbacks of classes with this prefix are wrapped when
	 *                               $contain_errors is false; with error containment every callback is wrapped.
	 * @param bool   $contain_errors Whether a \Throwable is logged and swallowed.
	 * @param bool   $alerts_last    Move the health alerts behind every other job.
	 * @return void
	 */
	private static function wrap_callbacks( string $hook, string $class_prefix, bool $contain_errors, bool $alerts_last ): void {
		global $wp_filter;

		if ( ! isset( $wp_filter[ $hook ] ) || ! is_object( $wp_filter[ $hook ] ) || ! isset( $wp_filter[ $hook ]->callbacks ) ) {
			return;
		}

		$snapshot = $wp_filter[ $hook ]->callbacks;
		ksort( $snapshot, SORT_NUMERIC );
		foreach ( $snapshot as $priority => $callbacks ) {
			foreach ( (array) $callbacks as $entry ) {
				$callback = isset( $entry['function'] ) ? $entry['function'] : null;
				if ( null === $callback || $callback instanceof IsolatedJob ) {
					continue;
				}
				$class = self::callback_class( $callback );
				if ( self::class === $class ) {
					continue;
				}
				if ( ! $contain_errors && ( '' === $class || 0 !== strpos( $class, $class_prefix ) ) ) {
					continue;
				}
				if ( ! is_callable( $callback ) ) {
					continue;
				}

				$target = $priority;
				if ( $alerts_last && 'WPLC\\Admin\\Diagnostics' === $class && (int) $priority < self::ALERT_PRIORITY ) {
					$target = self::ALERT_PRIORITY;
				}
				$accepted_args = isset( $entry['accepted_args'] ) ? (int) $entry['accepted_args'] : 1;
				remove_action( $hook, $callback, (int) $priority );
				add_action( $hook, new IsolatedJob( $callback, self::job_name( $callback ), $contain_errors ), (int) $target, $accepted_args );
			}
		}
	}
}
