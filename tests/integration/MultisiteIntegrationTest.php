<?php
/**
 * T-61: behavior on a multisite network (docs/MULTISITE.md).
 *
 * Runs only when the suite is started as multisite (WP_MULTISITE=1). Sites are
 * created with permanent tables because the schema check reads
 * information_schema, which does not list the temporary tables the WordPress
 * test case uses otherwise. Every site is deleted again in tear_down(), which
 * also exercises the wpmu_drop_tables filter.
 *
 * @package WPLC
 */

use WPLC\Api\V1\Routes;
use WPLC\Billing\RenewalProcessor;
use WPLC\Infrastructure\Database\Schema;
use WPLC\Infrastructure\Database\SiteCleanupHelper;
use WPLC\License\ActivationManager;
use WPLC\License\LicenseLifecycle;
use WPLC\Plugin;

final class MultisiteIntegrationTest extends WPLC_Integration_TestCase {

	/**
	 * Recurring events the plugin schedules on every site.
	 */
	private const RECURRING_HOOKS = array( 'wplc_daily_lifecycle', 'wplc_cleanup_security_rows' );

	/**
	 * Sites created by the current test.
	 *
	 * @var int[]
	 */
	private $created_sites = array();

	public function set_up(): void {
		parent::set_up();
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only; run the integration suite with WP_MULTISITE=1.' );
		}
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	public function tear_down(): void {
		if ( is_multisite() ) {
			while ( ms_is_switched() ) {
				restore_current_blog();
			}
			// Dropping tables commits the open transaction. Undo this test's writes since
			// its last DDL statement first, so they cannot leak into later tests (cron
			// events, rate-limit counters of the main site).
			$GLOBALS['wpdb']->query( 'ROLLBACK' );
			wp_cache_flush();
			foreach ( $this->created_sites as $site_id ) {
				if ( get_site( $site_id ) ) {
					wp_delete_site( $site_id );
				}
			}
			// wp_delete_site() removes the wp_blogs row after the tables were dropped; keep
			// that row deletion too, or later tests iterate over sites without tables.
			$GLOBALS['wpdb']->query( 'COMMIT' );
		}
		$this->created_sites = array();
		parent::tear_down();
	}

	/**
	 * Create a site the way the network admin does (no plugin code runs for it).
	 *
	 * @return int Site ID.
	 */
	private function create_site(): int {
		$site_id = wp_insert_site(
			array(
				'domain' => 'example.org',
				'path'   => '/wplc-ms-' . strtolower( wp_generate_password( 8, false ) ) . '/',
				'title'  => 'WPLC multisite test',
			)
		);
		$this->assertIsInt( $site_id, is_wp_error( $site_id ) ? $site_id->get_error_message() : '' );
		$this->created_sites[] = $site_id;

		return $site_id;
	}

	/**
	 * Tables of the given names that exist in the database.
	 *
	 * @param string[] $tables Table names.
	 * @return string[]
	 */
	private function existing_tables( array $tables ): array {
		global $wpdb;

		$existing = array();
		foreach ( $tables as $table ) {
			$count = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s', $table )
			);
			if ( 1 === $count ) {
				$existing[] = $table;
			}
		}

		return $existing;
	}

	/**
	 * Table names of a site.
	 *
	 * @param int $site_id Site ID.
	 * @return string[]
	 */
	private function site_tables( int $site_id ): array {
		global $wpdb;

		return array_values( Schema::table_names_for_prefix( $wpdb->get_blog_prefix( $site_id ) ) );
	}

	/**
	 * Recurring hooks scheduled on the current site.
	 *
	 * @return string[]
	 */
	private function scheduled_recurring_hooks(): array {
		return array_values(
			array_filter(
				self::RECURRING_HOOKS,
				static function ( string $hook ): bool {
					return false !== wp_next_scheduled( $hook );
				}
			)
		);
	}

	public function test_tables_and_schema_version_are_per_site_and_created_on_the_first_load(): void {
		global $wpdb;

		$site_id     = $this->create_site();
		$site_tables = $this->site_tables( $site_id );
		$main_tables = $this->site_tables( get_main_site_id() );
		$this->assertSame( 0, strpos( $site_tables[0], $wpdb->get_blog_prefix( $site_id ) . 'wplc_' ) );
		$this->assertNotSame( $main_tables, $site_tables );
		$this->assertContains( $wpdb->get_blog_prefix( $site_id ) . 'wplc_reminder_log', $site_tables );
		$this->assertContains( $wpdb->get_blog_prefix( $site_id ) . 'wplc_license_members', $site_tables, 'B-139: the co-user table is a site table.' );

		// Creating a site runs no plugin code: no tables until the plugin loads there.
		$this->assertSame( array(), $this->existing_tables( $site_tables ) );

		switch_to_blog( $site_id );
		$this->assertSame( $site_tables, array_values( Schema::table_names() ) );
		$this->assertFalse( get_option( Schema::VERSION_OPTION ) );
		// What on_plugins_loaded() does on the site's first request.
		$this->assertTrue( Schema::maybe_upgrade() );
		$this->assertSame( Schema::VERSION, (string) get_option( Schema::VERSION_OPTION ) );
		restore_current_blog();

		$this->assertSame( $site_tables, $this->existing_tables( $site_tables ) );
		$this->assertSame( $main_tables, $this->existing_tables( $main_tables ), 'The main site lost tables.' );
		$this->assertSame( Schema::VERSION, (string) get_option( Schema::VERSION_OPTION ) );
	}

	public function test_network_activation_sets_up_the_current_site_and_the_others_on_their_first_load(): void {
		$site_id = $this->create_site();

		// WordPress passes $network_wide = true on a network activation from the network admin.
		Plugin::activate( true );

		$main_tables = $this->site_tables( get_main_site_id() );
		$this->assertSame( $main_tables, $this->existing_tables( $main_tables ) );
		$this->assertSame( array(), $this->existing_tables( $this->site_tables( $site_id ) ), 'Documented: other sites are set up lazily.' );

		switch_to_blog( $site_id );
		$this->assertTrue( Schema::maybe_upgrade() );
		LicenseLifecycle::schedule_daily_event();
		restore_current_blog();

		$this->assertSame( $this->site_tables( $site_id ), $this->existing_tables( $this->site_tables( $site_id ) ) );
	}

	public function test_activation_on_a_single_site_sets_up_only_that_site(): void {
		$site_id = $this->create_site();
		$other   = $this->create_site();

		switch_to_blog( $site_id );
		Plugin::activate( false );
		$this->assertContains( 'wplc_daily_lifecycle', $this->scheduled_recurring_hooks() );
		restore_current_blog();

		$this->assertSame( $this->site_tables( $site_id ), $this->existing_tables( $this->site_tables( $site_id ) ) );
		$this->assertSame( array(), $this->existing_tables( $this->site_tables( $other ) ) );

		switch_to_blog( $other );
		$this->assertSame( array(), $this->scheduled_recurring_hooks(), 'Cron events are per site.' );
		restore_current_blog();
	}

	public function test_a_license_of_one_site_is_unknown_to_the_api_of_another_site(): void {
		global $wpdb;

		$site_id = $this->create_site();
		switch_to_blog( $site_id );
		$this->assertTrue( Schema::maybe_upgrade() );
		$license = $this->seed_license();
		$site_activation = $this->activate_via_api( $license['key'] );
		$this->assertSame( 200, $site_activation['response']->get_status(), (string) wp_json_encode( $site_activation['response']->get_data() ) );

		$status = $this->dispatch( $this->api_request( 'POST', '/wplc/v1/entitlements/check', array(), $site_activation ) );
		$this->assertSame( 200, $status->get_status(), (string) wp_json_encode( $status->get_data() ) );
		$site_tables = $this->tables();
		restore_current_blog();

		$main_tables = $this->tables();
		$this->assertNotSame( $site_tables['licenses'], $main_tables['licenses'] );
		$this->assertSame(
			0,
			(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$main_tables['licenses']} WHERE license_uuid = %s", $license['uuid'] ) )
		);

		// The same key and installation are unknown on the main site.
		$this->assertApiError( 401, 'invalid_key', $this->activate_via_api( $license['key'] )['response'] );
		$main_status = $this->dispatch( $this->api_request( 'POST', '/wplc/v1/entitlements/check', array(), $site_activation ) );
		$this->assertSame( 401, $main_status->get_status(), (string) wp_json_encode( $main_status->get_data() ) );

		// And the REST URLs differ per site.
		$this->assertNotSame( get_rest_url( $site_id, 'wplc/v1/activations' ), get_rest_url( get_main_site_id(), 'wplc/v1/activations' ) );
	}

	/** UEB-12 (2): the move to the v2 envelope runs per site, on that site's tables and with its own state. */
	public function test_the_secret_format_migration_runs_per_site(): void {
		$legacy = static function ( string $plaintext ): string {
			$nonce      = random_bytes( 12 );
			$tag        = '';
			$ciphertext = openssl_encrypt( $plaintext, 'aes-256-gcm', hash( 'sha256', 'integration-only-encryption-secret', true ), OPENSSL_RAW_DATA, $nonce, $tag, 'wplc-secret-v1:integration-2026', 16 );

			return 'v1:integration-2026:' . base64_encode( $nonce . $tag . $ciphertext );
		};
		$seed = function () use ( $legacy ): array {
			$secret = 'legacy-' . wp_generate_password( 24, false );
			$row    = $this->seed_activation( $this->seed_license()['id'], array( 'installation_secret_hash' => hash( 'sha256', $secret ), 'installation_secret_encrypted' => $legacy( $secret ) ) );
			$row['secret'] = $secret;

			return $row;
		};
		delete_option( \WPLC\Security\SecretMigration::STATE_OPTION );
		$main = $seed();

		$site_id = $this->create_site();
		switch_to_blog( $site_id );
		$this->assertTrue( Schema::maybe_upgrade() );
		$sub = $seed();
		$this->assertSame( 'finished', \WPLC\Security\SecretMigration::run( 100 )['status'] );
		$stored = $this->activation( $sub['id'] )['installation_secret_encrypted'];
		$this->assertStringStartsWith( \WPLC\Security\SecretCrypto::current_prefix(), $stored );
		$this->assertSame( $sub['secret'], \WPLC\Security\SecretCrypto::decrypt( $stored, \WPLC\Security\SecretCrypto::PURPOSE_INSTALLATION, $sub['uuid'] ) );
		$this->assertSame( 200, $this->dispatch( $this->api_request( 'POST', '/wplc/v1/entitlements/check', array(), $sub ) )->get_status() );
		restore_current_blog();

		// The main site is untouched and has its own state.
		$this->assertStringStartsWith( 'v1:', $this->activation( $main['id'] )['installation_secret_encrypted'] );
		$this->assertFalse( \WPLC\Security\SecretMigration::state()['done'] );
		$this->assertSame( 'finished', \WPLC\Security\SecretMigration::run( 100 )['status'] );
		$this->assertStringStartsWith( \WPLC\Security\SecretCrypto::current_prefix(), $this->activation( $main['id'] )['installation_secret_encrypted'] );
	}

	public function test_deleting_a_site_drops_its_tables_and_keeps_the_others(): void {
		global $wpdb;

		$site_id = $this->create_site();
		switch_to_blog( $site_id );
		$this->assertTrue( Schema::maybe_upgrade() );
		restore_current_blog();
		$site_tables = $this->site_tables( $site_id );
		$this->assertSame( $site_tables, $this->existing_tables( $site_tables ) );

		$this->assertNotFalse( has_filter( 'wpmu_drop_tables', array( Schema::class, 'filter_wpmu_drop_tables' ) ) );
		$dropped = apply_filters( 'wpmu_drop_tables', $wpdb->tables( 'blog' ), $site_id );
		$this->assertContains( $wpdb->get_blog_prefix( $site_id ) . 'wplc_reminder_log', $dropped );
		$this->assertContains( $wpdb->get_blog_prefix( $site_id ) . 'wplc_license_members', $dropped );

		$this->assertNotWPError( wp_delete_site( $site_id ) );

		$this->assertSame( array(), $this->existing_tables( $site_tables ), 'Orphan tables of the deleted site.' );
		$main_tables = $this->site_tables( get_main_site_id() );
		$this->assertSame( $main_tables, $this->existing_tables( $main_tables ) );
	}

	// P-32: the must-use helper drops the tables when the plugin is not loaded in the deleting request.
	public function test_must_use_helper_drops_the_tables_without_the_plugin_filter(): void {
		global $wpdb;

		require_once dirname( __DIR__, 2 ) . '/mu-plugins/wplc-site-cleanup.php';
		$this->assertNotFalse( has_filter( 'wpmu_drop_tables', 'wplc_mu_site_cleanup_filter' ) );

		$site_id = $this->create_site();
		switch_to_blog( $site_id );
		$this->assertTrue( Schema::maybe_upgrade() );
		restore_current_blog();
		$site_tables = $this->site_tables( $site_id );
		$this->assertSame( $site_tables, $this->existing_tables( $site_tables ) );

		// The main site is never touched, whatever the caller passes.
		$this->assertSame( array( 'x' ), wplc_mu_site_cleanup_filter( array( 'x' ), get_main_site_id() ) );
		$selected = wplc_mu_site_cleanup_filter( array(), $site_id );
		$expected = $site_tables;
		sort( $selected );
		sort( $expected );
		$this->assertSame( $expected, $selected );

		// As in a request in which WordPress did not load the plugin.
		remove_filter( 'wpmu_drop_tables', array( Schema::class, 'filter_wpmu_drop_tables' ), 10 );
		try {
			$this->assertNotWPError( wp_delete_site( $site_id ) );
		} finally {
			add_filter( 'wpmu_drop_tables', array( Schema::class, 'filter_wpmu_drop_tables' ), 10, 2 );
		}

		$this->assertSame( array(), $this->existing_tables( $site_tables ), 'Orphan tables of the deleted site.' );
		$main_tables = $this->site_tables( get_main_site_id() );
		$this->assertSame( $main_tables, $this->existing_tables( $main_tables ) );
	}

	public function test_must_use_helper_install_and_remove_leave_other_files_alone(): void {
		$dir    = get_temp_dir() . 'wplc-mu-' . wp_generate_password( 8, false );
		$target = $dir . '/' . SiteCleanupHelper::FILE_NAME;

		$this->assertSame( '', SiteCleanupHelper::install( $target ), 'Creates the directory and copies the file.' );
		$this->assertSame( SiteCleanupHelper::STATUS_INSTALLED, SiteCleanupHelper::status( $target ) );
		$this->assertSame( '', SiteCleanupHelper::remove( $target ) );
		$this->assertFileDoesNotExist( $target );
		$this->assertSame( '', SiteCleanupHelper::remove( $target ), 'Nothing to remove is not an error.' );

		file_put_contents( $target, "<?php\n// Another must-use plugin.\n" );
		$this->assertNotSame( '', SiteCleanupHelper::install( $target ) );
		$this->assertNotSame( '', SiteCleanupHelper::remove( $target ) );
		$this->assertFileExists( $target );
		unlink( $target );
		rmdir( $dir );
	}

	public function test_lock_names_differ_per_site(): void {
		$site_id = $this->create_site();

		$names = static function (): array {
			return array(
				'scope'      => Schema::lock_scope(),
				'activation' => ActivationManager::lock_name( 1 ),
				'license'    => LicenseLifecycle::lock_name( 1 ),
				'renewal'    => RenewalProcessor::renewal_lock_name( 1 ),
			);
		};

		$main = $names();
		switch_to_blog( $site_id );
		$site = $names();
		restore_current_blog();

		foreach ( $main as $kind => $name ) {
			$this->assertNotSame( $name, $site[ $kind ], 'The ' . $kind . ' lock is shared between sites.' );
			if ( 'scope' !== $kind ) {
				$this->assertLessThanOrEqual( 64, strlen( $site[ $kind ] ) );
			}
		}
		$this->assertSame( $main, $names(), 'restore_current_blog() restores the lock scope.' );
	}

	public function test_deactivation_clears_the_events_of_one_site_or_of_the_whole_network(): void {
		$site_id = $this->create_site();
		$main_id = get_current_blog_id();

		switch_to_blog( $site_id );
		LicenseLifecycle::schedule_daily_event();
		Routes::schedule_cleanup_event();
		$this->assertSame( self::RECURRING_HOOKS, $this->scheduled_recurring_hooks() );

		// Deactivation on one site leaves the other sites alone.
		Plugin::deactivate( false );
		$this->assertSame( array(), $this->scheduled_recurring_hooks() );
		restore_current_blog();
		$this->assertSame( self::RECURRING_HOOKS, $this->scheduled_recurring_hooks() );

		// A network deactivation clears every site, not only the one the request runs on.
		switch_to_blog( $site_id );
		LicenseLifecycle::schedule_daily_event();
		Routes::schedule_cleanup_event();
		restore_current_blog();
		Plugin::deactivate( true );
		$this->assertSame( $main_id, get_current_blog_id() );
		$this->assertSame( array(), $this->scheduled_recurring_hooks() );
		switch_to_blog( $site_id );
		$this->assertSame( array(), $this->scheduled_recurring_hooks(), 'Leftover events on another site of the network.' );
		restore_current_blog();
	}

	public function test_uninstall_clears_scheduled_work_on_every_site_and_keeps_the_tables(): void {
		$site_id = $this->create_site();
		switch_to_blog( $site_id );
		$this->assertTrue( Schema::maybe_upgrade() );
		LicenseLifecycle::schedule_daily_event();
		Routes::schedule_cleanup_event();
		restore_current_blog();

		$action_id = as_schedule_single_action( time() + HOUR_IN_SECONDS, 'wplc_multisite_test_action', array(), 'wplc' );
		$this->assertGreaterThan( 0, $action_id );
		$this->assertSame( self::RECURRING_HOOKS, $this->scheduled_recurring_hooks() );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'wp-license/wp-license.php' );
		}
		require dirname( __DIR__, 2 ) . '/uninstall.php';

		$this->assertSame( array(), $this->scheduled_recurring_hooks() );
		$this->assertFalse( as_has_scheduled_action( 'wplc_multisite_test_action', array(), 'wplc' ) );
		switch_to_blog( $site_id );
		$this->assertSame( array(), $this->scheduled_recurring_hooks(), 'Leftover events on another site of the network.' );
		restore_current_blog();

		foreach ( array( get_main_site_id(), $site_id ) as $id ) {
			$this->assertSame( $this->site_tables( $id ), $this->existing_tables( $this->site_tables( $id ) ), 'Uninstall keeps the business records.' );
		}
	}
}
