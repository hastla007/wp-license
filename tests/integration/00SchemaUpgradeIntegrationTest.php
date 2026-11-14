<?php
/**
 * Migration mechanism against a real database (B-12, B-13, B-100, B-101, B-103, B-118, A-106).
 *
 * Every test changes permanent tables (DDL commits the test transaction), so
 * none of them creates fixture rows and tear_down() restores a complete schema.
 *
 * @package WPLC
 */

use WPLC\Infrastructure\Database\Schema;
use WPLC\Maintenance\SiteGuard;

final class SchemaUpgradeIntegrationTest extends WP_UnitTestCase {

	/**
	 * Restore a complete, current schema after tests that damage it on purpose.
	 */
	public function tear_down(): void {
		remove_all_filters( 'wplc_schema_can_migrate' );
		remove_filter( 'query', array( $this, 'break_renewals_alter' ) );
		$this->permanent_tables();
		delete_option( Schema::ERROR_OPTION );
		delete_option( Schema::DIAGNOSTICS_ERROR_OPTION );
		update_option( Schema::VERSION_OPTION, Schema::VERSION, true );
		Schema::migrate();
		// The uninstall tests clear the cron events for good (DDL commits the test transaction).
		\WPLC\License\LicenseLifecycle::schedule_daily_event();
		\WPLC\Api\V1\Routes::schedule_cleanup_event();
		\WPLC\Maintenance\SiteGuard::remember();
		// DDL committed parts of this test; commit the restored state too, otherwise the
		// rollback in parent::tear_down() would leave the database without it.
		$GLOBALS['wpdb']->query( 'COMMIT' );
		parent::tear_down();
	}

	/**
	 * Use permanent tables: information_schema does not list temporary ones.
	 */
	private function permanent_tables(): void {
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	private function renewals_status_length(): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = %s AND column_name = %s',
				Schema::table_names()['renewals'],
				'status'
			)
		);
	}

	private function table_exists( string $table ): bool {
		global $wpdb;

		return 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s', $table ) );
	}

	/**
	 * Bring the database to the state of schema 10: narrow renewals.status, no reminder log in the schema.
	 */
	private function downgrade_database_to_schema_10(): void {
		global $wpdb;

		$tables = Schema::table_names();
		$wpdb->query( "ALTER TABLE {$tables['renewals']} MODIFY status varchar(20) NOT NULL DEFAULT 'scheduled'" );
		$wpdb->query( "DROP TABLE IF EXISTS {$tables['reminder_log']}" );
		update_option( Schema::VERSION_OPTION, '10', true );
		delete_option( Schema::VERIFIED_OPTION );
		$this->assertSame( 20, $this->renewals_status_length(), 'Fixture: the column could not be narrowed.' );
	}

	/**
	 * Query filter: turn dbDelta's ALTER of renewals into a statement that fails.
	 *
	 * @param string $query SQL.
	 * @return string
	 */
	public function break_renewals_alter( $query ) {
		if ( preg_match( '/^\s*ALTER TABLE \S*wplc_renewals\b/i', (string) $query ) ) {
			return 'ALTER TABLE wplc_table_that_does_not_exist ADD broken int';
		}
		return $query;
	}

	// B-13 (and T-60/T-100): upgrade of a running schema-10 database.
	public function test_upgrade_from_schema_10_widens_renewals_status_and_adopts_the_reminder_log(): void {
		$this->permanent_tables();
		$this->downgrade_database_to_schema_10();

		$this->assertTrue( Schema::maybe_upgrade() );

		$this->assertSame( 30, $this->renewals_status_length() );
		$this->assertTrue( $this->table_exists( Schema::table_names()['reminder_log'] ) );
		$this->assertSame( Schema::VERSION, (string) get_option( Schema::VERSION_OPTION ) );
		$this->assertSame( array(), Schema::verify() );
		$this->assertSame( \WPLC\Notifications\ReminderLog::VERSION, (string) get_option( 'wplc_reminder_log_version' ), 'Step 18 aligns the option of the former self-installer (layout 2: status column).' );
		$this->assertSame( '2', \WPLC\Notifications\ReminderLog::VERSION );
	}

	// B-12, A-106: a failed ALTER is not booked as done, is reported and is retried after a pause.
	public function test_failed_alter_keeps_the_old_version_and_is_retried_later(): void {
		$this->permanent_tables();
		$this->downgrade_database_to_schema_10();
		add_filter( 'query', array( $this, 'break_renewals_alter' ) );

		$this->assertFalse( Schema::maybe_upgrade() );
		$this->assertSame( '10', (string) get_option( Schema::VERSION_OPTION ), 'The version must not be stored after a failed ALTER.' );
		$this->assertSame( 20, $this->renewals_status_length() );
		$error = get_option( Schema::ERROR_OPTION );
		$this->assertIsArray( $error );
		$this->assertStringContainsString( 'renewals.status: varchar(20), expected varchar(30)', $error['message'] );
		$this->assertStringContainsString( 'Database error:', $error['message'] );
		$this->assertSame( $error['message'], get_option( 'wplc_schema_error' ), 'The diagnostics page reads this option.' );
		$status = Schema::status();
		$this->assertSame( '10', $status['installed'] );
		$this->assertSame( Schema::VERSION, $status['expected'] );
		$this->assertNotEmpty( $status['problems'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		Schema::render_admin_notice();
		$this->assertStringContainsString( 'could not update its database tables', (string) ob_get_clean() );

		// The cause is gone, but the next automatic attempt waits for the retry delay.
		remove_filter( 'query', array( $this, 'break_renewals_alter' ) );
		$this->assertFalse( Schema::maybe_upgrade() );
		$this->assertSame( 20, $this->renewals_status_length() );

		$error['time'] = time() - 301;
		update_option( Schema::ERROR_OPTION, $error, false );
		$this->assertTrue( Schema::maybe_upgrade() );
		$this->assertSame( 30, $this->renewals_status_length() );
		$this->assertSame( Schema::VERSION, (string) get_option( Schema::VERSION_OPTION ) );
		$this->assertNull( get_option( Schema::ERROR_OPTION, null ) );
		$this->assertNull( get_option( 'wplc_schema_error', null ) );
	}

	// B-12: tables missing although the stored version is current are created again.
	public function test_missing_table_is_recreated_although_the_version_is_current(): void {
		global $wpdb;

		$this->permanent_tables();
		$this->assertTrue( Schema::migrate() );
		$table = Schema::table_names()['package_streams'];
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		$this->assertFalse( $this->table_exists( $table ) );
		$this->assertSame( Schema::VERSION, (string) get_option( Schema::VERSION_OPTION ) );

		// Checked recently: no query, no repair.
		$this->assertTrue( Schema::maybe_upgrade() );
		$this->assertFalse( $this->table_exists( $table ) );

		// The daily structure check finds and repairs it.
		update_option( Schema::VERIFIED_OPTION, Schema::VERSION . '|' . ( time() - DAY_IN_SECONDS - 1 ), true );
		$this->assertTrue( Schema::maybe_upgrade() );
		$this->assertTrue( $this->table_exists( $table ) );
	}

	// B-118: a stale cached version is corrected instead of migrating on every request.
	public function test_stale_cached_version_is_corrected_without_running_dbdelta(): void {
		$alloptions                           = wp_load_alloptions();
		$alloptions[ Schema::VERSION_OPTION ] = '9';
		wp_cache_set( 'alloptions', $alloptions, 'options' );
		$this->assertSame( '9', (string) get_option( Schema::VERSION_OPTION ), 'Fixture: the cache is not stale.' );

		$dbdelta_runs = 0;
		$counter      = static function ( $queries ) use ( &$dbdelta_runs ) {
			++$dbdelta_runs;
			return $queries;
		};
		add_filter( 'dbdelta_queries', $counter );
		$result = Schema::maybe_upgrade();
		remove_filter( 'dbdelta_queries', $counter );

		$this->assertTrue( $result );
		$this->assertSame( 0, $dbdelta_runs );
		$this->assertSame( Schema::VERSION, (string) get_option( Schema::VERSION_OPTION ) );
	}

	// B-100: front-end and REST requests neither migrate nor wait for the lock.
	public function test_front_end_request_does_not_migrate(): void {
		$this->permanent_tables();
		$this->downgrade_database_to_schema_10();
		add_filter( 'wplc_schema_can_migrate', '__return_false' );

		$this->assertFalse( Schema::maybe_upgrade() );
		$this->assertSame( 20, $this->renewals_status_length() );
		$this->assertSame( '10', (string) get_option( Schema::VERSION_OPTION ) );

		remove_all_filters( 'wplc_schema_can_migrate' );
		$this->assertTrue( Schema::maybe_upgrade() );
		$this->assertSame( 30, $this->renewals_status_length() );
	}

	// B-101: after a rollback to an older plugin version nothing is migrated and billing stops.
	public function test_downgrade_is_detected_and_the_newer_schema_is_left_alone(): void {
		global $wpdb;

		$this->permanent_tables();
		$tables = Schema::table_names();
		$wpdb->query( "ALTER TABLE {$tables['renewals']} MODIFY status varchar(64) NOT NULL DEFAULT 'scheduled'" );
		update_option( Schema::VERSION_OPTION, '999', true );

		$this->assertTrue( Schema::is_downgraded() );
		$this->assertTrue( Schema::maybe_upgrade() );
		$this->assertTrue( Schema::migrate() );
		$this->assertSame( 64, $this->renewals_status_length(), 'dbDelta must not run with older definitions.' );
		$this->assertSame( '999', (string) get_option( Schema::VERSION_OPTION ) );
		$this->assertTrue( SiteGuard::billing_paused(), 'Charges stop while the code is older than the schema.' );
		$this->assertSame( 'schema_downgrade', SiteGuard::pause_reason() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		Schema::render_admin_notice();
		$this->assertStringContainsString( 'newer plugin version', (string) ob_get_clean() );

		update_option( Schema::VERSION_OPTION, Schema::VERSION, true );
		$this->assertFalse( Schema::is_downgraded() );
		$wpdb->query( "ALTER TABLE {$tables['renewals']} MODIFY status varchar(30) NOT NULL DEFAULT 'scheduled'" );
	}

	// A-117: uninstall removes scheduled work and working state, keeps the business records.
	public function test_uninstall_keeps_data_and_removes_working_state(): void {
		$this->permanent_tables();
		update_option( 'wplc_reminder_cursor', 12, false );
		update_option( 'wplc_gap_window', array( 'from' => 1, 'to' => 2 ), false );
		update_option( 'wplc_privacy_eraser_cursor_77', 5, false );
		if ( ! wp_next_scheduled( 'wplc_daily_lifecycle' ) ) {
			wp_schedule_event( time() + 300, 'daily', 'wplc_daily_lifecycle' );
		}
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'wp-license/wp-license.php' );
		}

		require dirname( __DIR__, 2 ) . '/uninstall.php';

		$this->assertFalse( wp_next_scheduled( 'wplc_daily_lifecycle' ) );
		$this->assertFalse( get_option( 'wplc_reminder_cursor' ) );
		$this->assertFalse( get_option( 'wplc_gap_window' ) );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'wplc_privacy_eraser_cursor_77', 'options' );
		$this->assertFalse( get_option( 'wplc_privacy_eraser_cursor_77' ) );
		$this->assertSame( Schema::VERSION, (string) get_option( Schema::VERSION_OPTION ) );
		foreach ( Schema::table_names() as $table ) {
			$this->assertTrue( $this->table_exists( $table ), $table );
		}
	}

	// A-79: tables dropped by hand while the options stayed - reinstalling creates them again, reminder log included.
	public function test_reactivation_after_manually_dropped_tables_recreates_them(): void {
		global $wpdb;

		$this->permanent_tables();
		foreach ( Schema::table_names() as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}
		$this->assertSame( \WPLC\Notifications\ReminderLog::VERSION, (string) get_option( 'wplc_reminder_log_version', '2' ) );
		$this->assertSame( Schema::VERSION, (string) get_option( Schema::VERSION_OPTION ) );

		\WPLC\Plugin::activate();

		foreach ( Schema::table_names() as $table ) {
			$this->assertTrue( $this->table_exists( $table ), $table );
		}
		$this->assertSame( array(), Schema::verify() );
	}

	// A-117: the opt-in removes tables and options, and a later activation starts from scratch.
	public function test_opt_in_uninstall_removes_tables_and_options(): void {
		$this->permanent_tables();
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'wp-license/wp-license.php' );
		}
		require dirname( __DIR__, 2 ) . '/uninstall.php';

		global $wpdb;
		// Every option a class of the plugin writes (A-117): none may survive the opt-in.
		$options = array(
			\WPLC\Catalog\Catalog::IDS_OPTION,
			\WPLC\Security\ResponseSigner::OPTION,
			\WPLC\Security\ResponseSigner::KEY_ID_OPTION,
			\WPLC\Update\ReleaseRegistry::SLUG_OPTION,
			\WPLC\Admin\Diagnostics::LAST_RUN_OPTION,
			\WPLC\Admin\Settings::OPTION,
			\WPLC\Maintenance\DailyRunner::FAILURES_OPTION,
			\WPLC\Maintenance\DailyRunner::LAST_RUN_OPTION,
			\WPLC\Maintenance\GapGuard::OPTION,
			\WPLC\Maintenance\GapReconciler::HANDLED_OPTION,
			\WPLC\Maintenance\SiteGuard::OPTION,
			\WPLC\Maintenance\SiteGuard::KEEP_OPTION,
			\WPLC\Maintenance\SiteGuard::PAUSED_SINCE_OPTION,
			\WPLC\Account\CustomerPortal::REWRITE_VERSION_OPTION,
			\WPLC\Plugin::DEACTIVATED_OPTION,
			Schema::ERROR_OPTION,
			Schema::DIAGNOSTICS_ERROR_OPTION,
			Schema::VERIFIED_OPTION,
			'wplc_reminder_cursor',
			'wplc_job_heartbeats',
		);
		foreach ( $options as $option ) {
			update_option( $option, 'x', false );
		}
		set_transient( 'wplc_key_sample', array( 'x' ), HOUR_IN_SECONDS );

		wplc_uninstall_site( true );

		foreach ( Schema::table_names() as $table ) {
			$this->assertFalse( $this->table_exists( $table ), $table );
		}
		$left = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'wplc\\_%' OR option_name LIKE '\\_transient\\_wplc\\_%' OR option_name LIKE '\\_transient\\_timeout\\_wplc\\_%'" );
		$this->assertSame( array(), $left, 'Options left behind by the opt-in uninstall.' );
		$this->assertFalse( get_option( Schema::VERSION_OPTION ) );
		$this->assertFalse( get_option( 'wplc_reminder_log_version' ) );

		\WPLC\Plugin::activate();
		$this->assertSame( Schema::VERSION, (string) get_option( Schema::VERSION_OPTION ) );
		$this->assertSame( array(), Schema::verify() );
	}

	// B-103: a deleted subsite takes the reminder log with it.
	public function test_reminder_log_is_dropped_with_a_deleted_site(): void {
		global $wpdb;

		$tables = Schema::filter_wpmu_drop_tables( array( 'wp_5_posts' ), 5 );

		$this->assertContains( $wpdb->get_blog_prefix( 5 ) . 'wplc_reminder_log', $tables );
		$this->assertContains( 'wp_5_posts', $tables );
	}
}
