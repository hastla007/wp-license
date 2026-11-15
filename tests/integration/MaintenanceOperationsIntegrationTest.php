<?php
/**
 * Daily run split, gap cursor, retention of API counters, admin overhead and WP-CLI guards
 * (audit of 15 Nov 2026: P-29, P-30, P-34, P-51, P-52, P-55).
 *
 * @package WPLC
 */

use WPLC\Admin\Diagnostics;
use WPLC\Admin\KeyConfigurationNotice;
use WPLC\Api\V1\Authenticator;
use WPLC\Billing\PlanUpgrade;
use WPLC\CLI\ActivationCommand;
use WPLC\CLI\SigningKeyCommand;
use WPLC\Maintenance\DailyRunner;
use WPLC\Maintenance\GapReconciler;
use WPLC\Maintenance\Retention;
use WPLC\Maintenance\SiteGuard;
use WPLC\Security\ResponseSigner;

if ( ! class_exists( 'WPLC_Test_CLI_Stop' ) ) {
	/**
	 * Thrown by the WP_CLI stand-in where WP-CLI would end the command.
	 */
	final class WPLC_Test_CLI_Stop extends RuntimeException {
	}
}

if ( ! class_exists( 'WP_CLI' ) ) {
	/**
	 * Minimal stand-in for WP-CLI: records output, stops on error, halt and an unconfirmed confirm().
	 */
	final class WP_CLI {
		/** @var string[] */
		public static $output = array();

		public static function error( $message ) {
			throw new WPLC_Test_CLI_Stop( 'error: ' . $message );
		}

		public static function confirm( $question, $assoc_args = array() ) {
			self::$output[] = 'confirm: ' . $question;
			if ( empty( $assoc_args['yes'] ) ) {
				throw new WPLC_Test_CLI_Stop( 'declined: ' . $question );
			}
		}

		public static function halt( $code ) {
			throw new WPLC_Test_CLI_Stop( 'halt: ' . $code );
		}

		public static function success( $message ) {
			self::$output[] = 'success: ' . $message;
		}

		public static function warning( $message ) {
			self::$output[] = 'warning: ' . $message;
		}

		public static function log( $message ) {
			self::$output[] = 'log: ' . $message;
		}

		public static function line( $message = '' ) {
			self::$output[] = 'line: ' . $message;
		}
	}
}

final class MaintenanceOperationsIntegrationTest extends WPLC_Integration_TestCase {

	/** @var int */
	public static $probe_runs = 0;

	public static function probe_job(): void {
		++self::$probe_runs;
	}

	public static function failing_job(): void {
		throw new RuntimeException( 'probe failure' );
	}

	public function set_up(): void {
		parent::set_up();
		delete_option( SiteGuard::OPTION );
		delete_option( SiteGuard::KEEP_OPTION );
		delete_option( SiteGuard::PAUSED_SINCE_OPTION );
		delete_option( Diagnostics::LAST_RUN_OPTION );
		delete_option( DailyRunner::FAILURES_OPTION );
		SiteGuard::remember();
		self::$probe_runs = 0;
		WP_CLI::$output   = array();
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( DailyRunner::JOB_HOOK );
		}
	}

	public function tear_down(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( DailyRunner::JOB_HOOK );
		}
		ResponseSigner::reset();
		parent::tear_down();
	}

	/**
	 * Job keys of the pending daily-job actions, in queue order.
	 *
	 * @return string[]
	 */
	private function queued_job_keys(): array {
		$actions = as_get_scheduled_actions(
			array(
				'hook'     => DailyRunner::JOB_HOOK,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			)
		);
		ksort( $actions );
		$keys = array();
		foreach ( $actions as $action ) {
			$args   = $action->get_args();
			$keys[] = (string) $args[0];
		}

		return $keys;
	}

	// P-30: with Action Scheduler every job of the daily run is its own queued action.
	public function test_daily_run_queues_one_action_per_job_and_jobs_run_isolated(): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			$this->markTestSkipped( 'Action Scheduler is not loaded.' );
		}
		// The registration mechanism stays: add_action() on the daily hook.
		add_action( DailyRunner::HOOK, array( self::class, 'probe_job' ), 15 );
		add_action( DailyRunner::HOOK, array( self::class, 'failing_job' ), 16 );
		add_filter( 'wplc_daily_run_split', '__return_true' );

		do_action( DailyRunner::HOOK );

		$this->assertSame( 0, self::$probe_runs, 'The job is queued, not run in the cron request.' );
		$this->assertGreaterThan( 0, Diagnostics::last_run( Diagnostics::JOB_DAILY_STARTED ), 'The run itself started.' );
		$this->assertSame( 0, Diagnostics::last_run( Diagnostics::JOB_DAILY ), 'The closing heartbeat waits for the queued jobs.' );

		$probe   = DailyRunner::job_name( array( self::class, 'probe_job' ) );
		$failing = DailyRunner::job_name( array( self::class, 'failing_job' ) );
		$keys    = $this->queued_job_keys();
		$this->assertContains( $probe, $keys );
		$this->assertContains( $failing, $keys );
		$this->assertContains( 'license_lifecycle_expire_due_licenses', $keys );
		$this->assertContains( 'retention_run_scheduled', $keys );
		$this->assertLessThan( array_search( 'retention_run_scheduled', $keys, true ), array_search( 'gap_reconciler_run_scheduled', $keys, true ), 'Priority order is kept.' );
		$this->assertSame( DailyRunner::FINISH_JOB, end( $keys ), 'The closing heartbeat comes last.' );
		$this->assertSame( 'diagnostics_emit_alerts', $keys[ count( $keys ) - 2 ], 'The health alerts run after the jobs.' );
		$this->assertSame( count( $keys ), count( array_unique( $keys ) ) );

		// The queued action runs in a new request, where the jobs are registered again.
		add_action( DailyRunner::HOOK, array( self::class, 'probe_job' ), 15 );
		add_action( DailyRunner::HOOK, array( self::class, 'failing_job' ), 16 );
		$this->assertArrayHasKey( $probe, DailyRunner::daily_jobs() );

		// A second firing on the same day does not queue duplicates.
		do_action( DailyRunner::HOOK );
		add_action( DailyRunner::HOOK, array( self::class, 'probe_job' ), 15 );
		add_action( DailyRunner::HOOK, array( self::class, 'failing_job' ), 16 );
		$this->assertSame( $keys, $this->queued_job_keys() );

		// Each action runs its job only; a failing job is contained and recorded.
		DailyRunner::run_daily_job( $failing );
		$this->assertArrayHasKey( $failing, DailyRunner::failures() );
		DailyRunner::run_daily_job( $probe );
		$this->assertSame( 1, self::$probe_runs );
		$this->assertGreaterThan( 0, Diagnostics::last_run( $probe ) );
		DailyRunner::run_daily_job( 'unknown_job' );
		DailyRunner::run_daily_job( DailyRunner::FINISH_JOB );
		$this->assertGreaterThan( 0, Diagnostics::last_run( Diagnostics::JOB_DAILY ) );
	}

	// P-30: fired outside WP-Cron (or without Action Scheduler) the jobs still run inline.
	public function test_daily_run_outside_cron_runs_the_jobs_inline(): void {
		add_action( DailyRunner::HOOK, array( self::class, 'probe_job' ), 15 );

		do_action( DailyRunner::HOOK );

		$this->assertSame( 1, self::$probe_runs );
		$this->assertGreaterThan( 0, Diagnostics::last_run( Diagnostics::JOB_DAILY ) );
		if ( function_exists( 'as_get_scheduled_actions' ) ) {
			$this->assertSame( array(), $this->queued_job_keys() );
		}
	}

	// P-29: the paid-order pass keeps a cursor; plan upgrade orders are no missing licenses (QA #4).
	public function test_gap_reconciliation_keeps_a_cursor_and_skips_plan_upgrade_orders(): void {
		$product = $this->create_license_product();
		$order   = $this->create_paid_order( array( array( $product, 1 ) ), self::factory()->user->create( array( 'role' => 'customer' ) ) );

		$summary = GapReconciler::run();

		$this->assertTrue( $summary['complete'] );
		$cursor = get_option( GapReconciler::CURSOR_OPTION );
		$this->assertSame( 0, $cursor['below'] );
		$this->assertGreaterThanOrEqual( $order->get_id(), $cursor['top'] );

		$upgrade = $this->create_order( array( array( $product, 1 ) ) );
		$this->assertSame( 1, GapReconciler::missing_license_units( $upgrade ), 'Fixture: a mapped product line.' );
		$upgrade->update_meta_data( PlanUpgrade::META_LICENSE, 123 );
		$upgrade->save();
		$this->assertSame( 0, GapReconciler::missing_license_units( wc_get_order( $upgrade->get_id() ) ) );

		$created_via = $this->create_order( array( array( $product, 1 ) ) );
		$created_via->set_created_via( 'wplc_plan_upgrade' );
		$created_via->save();
		$this->assertSame( 0, GapReconciler::missing_license_units( wc_get_order( $created_via->get_id() ) ) );
	}

	// P-51: yesterday's API error counters survive the hourly sweep; older buckets go.
	public function test_rate_limit_sweep_keeps_yesterdays_counters(): void {
		global $wpdb;

		$yesterday = gmdate( 'Y-m-d 00:00:00', time() - DAY_IN_SECONDS );
		$old       = gmdate( 'Y-m-d 00:00:00', time() - 3 * DAY_IN_SECONDS );
		Authenticator::increment_bucket( hash( 'sha256', 'p51-yesterday' ), $yesterday );
		Authenticator::increment_bucket( hash( 'sha256', 'p51-old' ), $old );

		Retention::sweep_short_lived_rows();

		$tables = $this->tables();
		$count  = static function ( string $bucket ) use ( $wpdb, $tables ): int {
			return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE bucket_start = %s', $tables['request_limits'], $bucket ) );
		};
		$this->assertSame( 1, $count( $yesterday ) );
		$this->assertSame( 0, $count( $old ) );
	}

	// P-52: the inbox note is synced at most once a day per secret state and never on admin-post.php.
	public function test_inbox_note_sync_is_throttled(): void {
		global $pagenow;

		if ( ! class_exists( '\\Automattic\\WooCommerce\\Admin\\Notes\\Notes' ) ) {
			$this->markTestSkipped( 'WooCommerce admin notes are not loaded.' );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		delete_transient( KeyConfigurationNotice::NOTE_SYNC_TRANSIENT );
		$previous = $pagenow;

		$pagenow = 'admin-post.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		KeyConfigurationNotice::sync_inbox_note();
		$this->assertFalse( get_transient( KeyConfigurationNotice::NOTE_SYNC_TRANSIENT ) );

		$pagenow = 'admin.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		KeyConfigurationNotice::sync_inbox_note();
		$state = KeyConfigurationNotice::has_problem() ? 'problem' : 'ok';
		$this->assertSame( $state, get_transient( KeyConfigurationNotice::NOTE_SYNC_TRANSIENT ) );

		$queries = get_num_queries();
		KeyConfigurationNotice::sync_inbox_note();
		$this->assertSame( $queries, get_num_queries(), 'An unchanged state reads no notes.' );
		$pagenow = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	// P-34: release --license needs a confirmation, IDs and --license do not mix, --environment is checked.
	public function test_activation_release_validates_and_confirms(): void {
		$license = $this->seed_license();
		$live    = $this->seed_activation( $license['id'] );
		$dev     = $this->seed_activation( $license['id'], array( 'environment' => 'development' ) );
		$other   = $this->seed_license();
		$foreign = $this->seed_activation( $other['id'] );

		$mixed = ActivationCommand::release_plan( array( (string) $foreign['id'] ), array( 'license' => (string) $license['id'] ) );
		$this->assertWPError( $mixed );
		$this->assertSame( 'mixed', $mixed->get_error_code() );
		$this->assertSame( 'environment', ActivationCommand::release_plan( array(), array( 'license' => (string) $license['id'], 'environment' => 'prod' ) )->get_error_code() );
		$this->assertSame( 'environment_without_license', ActivationCommand::release_plan( array( (string) $live['id'] ), array( 'environment' => 'staging' ) )->get_error_code() );
		$this->assertSame( 'invalid_id', ActivationCommand::release_plan( array( 'abc' ), array() )->get_error_code() );
		$this->assertSame( array( $dev['id'] ), ActivationCommand::release_plan( array(), array( 'license' => (string) $license['id'], 'environment' => 'development' ) )['ids'] );

		$command = new ActivationCommand();
		try {
			$command->release( array(), array( 'license' => (string) $license['id'] ) );
			$this->fail( 'The release must ask first.' );
		} catch ( WPLC_Test_CLI_Stop $stop ) {
			$this->assertStringStartsWith( 'declined: ', $stop->getMessage() );
		}
		$this->assertSame( 'active', $this->activation( $live['id'] )['status'] );

		$command->release( array(), array( 'license' => (string) $license['id'], 'yes' => true ) );
		$this->assertSame( 'deactivated', $this->activation( $live['id'] )['status'] );
		$this->assertSame( 'deactivated', $this->activation( $dev['id'] )['status'] );
		$this->assertSame( 'active', $this->activation( $foreign['id'] )['status'] );
		$this->assertStringStartsWith( 'success: ', (string) end( WP_CLI::$output ) );
	}

	// P-34: --rotate needs a prepared key or --force, and a confirmation in both cases.
	public function test_signing_key_rotation_requires_a_prepared_key_or_force(): void {
		if ( ! ResponseSigner::available() || ResponseSigner::uses_constant() ) {
			$this->markTestSkipped( 'The signing key is not stored by the plugin here.' );
		}
		delete_option( ResponseSigner::NEXT_OPTION );
		ResponseSigner::reset();
		$current = ResponseSigner::status()['key_id'];
		$this->assertNotSame( '', $current );
		$command = new SigningKeyCommand();
		$attempt = static function ( array $assoc_args ) use ( $command ): string {
			try {
				$command( array(), $assoc_args );
			} catch ( WPLC_Test_CLI_Stop $stop ) {
				return $stop->getMessage();
			}
			return 'completed';
		};

		$this->assertStringContainsString( '--prepare', $attempt( array( 'rotate' => true ) ) );
		$this->assertStringContainsString( '--prepare', $attempt( array( 'rotate' => true, 'yes' => true ) ), '--yes alone does not replace a key no client pins.' );
		$this->assertStringStartsWith( 'declined: ', $attempt( array( 'rotate' => true, 'force' => true ) ) );
		ResponseSigner::reset();
		$this->assertSame( $current, ResponseSigner::status()['key_id'], 'Nothing was rotated.' );

		$next = ResponseSigner::prepare_next();
		$this->assertIsString( $next );
		$next_id = ResponseSigner::key_id_of( ResponseSigner::public_key_of( $next ) );
		$this->assertStringStartsWith( 'declined: ', $attempt( array( 'rotate' => true ) ) );
		ResponseSigner::reset();
		$this->assertSame( $current, ResponseSigner::status()['key_id'] );

		$this->assertSame( 'completed', $attempt( array( 'rotate' => true, 'yes' => true ) ) );
		ResponseSigner::reset();
		$this->assertSame( $next_id, ResponseSigner::status()['key_id'], 'The prepared key is in use.' );
	}
}
