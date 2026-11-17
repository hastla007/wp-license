<?php
/**
 * WFC-LIC-04, WFC-LIC-05, WFC-LIC-07: the license actions of the hook contract and the
 * read-only facade against a real database (suite spec sections 4.5, 6 and 11).
 *
 * Every action fires exactly once with scalar IDs, after the license lock (and the activation
 * lock) is released, and never for a refused or rolled-back change. No argument and no facade
 * result contains the license key, its hash, its prefix or an email address.
 *
 * @package WPLC
 */

use WPLC\Events\AfterCommit;
use WPLC\Events\LicenseReader;
use WPLC\License\ActivationManager;
use WPLC\License\AuditReason;
use WPLC\License\LicenseIssuer;
use WPLC\License\LicenseKey;
use WPLC\License\LicenseLifecycle;
use WPLC\License\PlanChange;
use WPLC\Update\ReleaseRegistry;

final class LicenseEventsIntegrationTest extends WPLC_Integration_TestCase {

	/** Contract actions and their number of arguments. */
	private const ACTIONS = array(
		'wplc/license_issued_followup' => 2,
		'wplc/license_status_changed'  => 4,
		'wplc/license_plan_changed'    => 1,
		'wplc/activation_created'      => 3,
		'wplc/activation_removed'      => 4,
		'wplc/site_limit_reached'      => 3,
		'wplc/release_published'       => 4,
		'wplc/release_withdrawn'       => 4,
	);

	/**
	 * Calls of the contract actions: name, arguments, and whether a lock was held.
	 *
	 * @var array<int,array{0:string,1:array<int,mixed>,2:bool}>
	 */
	private $calls = array();

	/**
	 * Calls of `wplc/license_issued` (which passes the key).
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private $issued = array();

	/** @var int */
	private $customer = 0;

	/** @var string */
	private $customer_email = '';

	/** @var string[] */
	private $files = array();

	public function set_up(): void {
		parent::set_up();
		AfterCommit::reset();
		$this->customer_email = 'events-' . wp_generate_password( 6, false ) . '@example.test';
		$this->customer       = self::factory()->user->create( array( 'user_email' => $this->customer_email ) );
		$this->calls          = array();
		$this->issued         = array();

		foreach ( self::ACTIONS as $hook => $count ) {
			add_action(
				$hook,
				function ( ...$args ) use ( $hook ) {
					$this->calls[] = array( $hook, $args, $this->lock_held( $hook, $args ) );
				},
				10,
				$count
			);
		}
		add_action(
			'wplc/license_issued',
			function ( ...$args ) {
				$this->issued[] = $args;
			},
			10,
			3
		);
	}

	public function tear_down(): void {
		AfterCommit::reset();
		foreach ( $this->files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		parent::tear_down();
	}

	private function lock_held( string $hook, array $args ): bool {
		global $wpdb;
		if ( 0 === strpos( $hook, 'wplc/release_' ) ) {
			return false;
		}
		$license_id = (int) $args[0];
		$license    = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s) IS NOT NULL', LicenseLifecycle::lock_name( $license_id ) ) );
		$activation = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s) IS NOT NULL', ActivationManager::lock_name( $license_id ) ) );

		return '1' === $license || '1' === $activation;
	}

	/**
	 * Arguments of every call of one action.
	 *
	 * @param string $hook Action.
	 * @return array<int,array<int,mixed>>
	 */
	private function of( string $hook ): array {
		$out = array();
		foreach ( $this->calls as $call ) {
			if ( $hook === $call[0] ) {
				$out[] = $call[1];
			}
		}
		return $out;
	}

	private function seed( array $overrides = array(), array $entitlements = array( 'sites.live_limit' => 1, 'feature.export' => true ) ): array {
		return $this->seed_license( array_merge( array( 'customer_id' => $this->customer, 'product_key' => 'test-product' ), $overrides ), $entitlements );
	}

	/**
	 * No argument of any contract action carries a secret or the key prefix, and all are scalar.
	 *
	 * @param array<string,mixed> $seeded Seeded license (id, key, uuid).
	 */
	private function assert_no_secret_in_any_call( array $seeded ): void {
		$this->assertNotEmpty( $this->calls );
		$json = (string) wp_json_encode( array_column( $this->calls, 1 ) );
		foreach ( array( $seeded['key'], $seeded['uuid'], LicenseKey::hash( $seeded['key'] ), LicenseKey::prefix( $seeded['uuid'] ), 'WPLC-', $this->customer_email, '@', 'https://', 'example.com' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $json );
		}
		foreach ( $this->calls as $call ) {
			$this->assertCount( self::ACTIONS[ $call[0] ], $call[1], $call[0] . ': documented number of arguments.' );
			foreach ( $call[1] as $argument ) {
				$this->assertTrue( is_int( $argument ) || is_string( $argument ), $call[0] . ': scalar arguments only.' );
			}
			$this->assertFalse( $call[2], $call[0] . ' fired while a lock was held.' );
		}
	}

	// ------------------------------------------------------------------
	// wplc/license_status_changed (WFC-LIC-07)
	// ------------------------------------------------------------------

	public function test_status_change_fires_once_after_the_lock_with_unchanged_arguments(): void {
		$seeded = $this->seed();

		$this->assertTrue( LicenseLifecycle::admin_set_status( $seeded['id'], 'suspended', 'Events check', 1 ) );

		$this->assertSame( array( array( $seeded['id'], 'active', 'suspended', 'admin_status_change' ) ), $this->of( 'wplc/license_status_changed' ) );
		$this->assertCount( 1, $this->calls );
		$this->assert_no_secret_in_any_call( $seeded );
	}

	public function test_refused_or_blocked_status_changes_fire_nothing(): void {
		$seeded = $this->seed( array( 'status' => 'revoked' ) );
		$this->assertFalse( LicenseLifecycle::admin_set_status( $seeded['id'], 'active', 'Not allowed', 1 ) );

		$other_license = $this->seed();
		$other         = mysqli_init();
		$this->assertTrue( $other->real_connect( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME ), 'A second database connection is needed.' );
		$name = LicenseLifecycle::lock_name( $other_license['id'] );
		$this->assertSame( '1', (string) $other->query( "SELECT GET_LOCK('" . $other->real_escape_string( $name ) . "', 0)" )->fetch_row()[0] );
		add_filter( 'wplc_license_lock_timeout', '__return_zero' );
		$this->assertFalse( LicenseLifecycle::admin_set_status( $other_license['id'], 'suspended', 'Lock busy', 1 ) );
		remove_filter( 'wplc_license_lock_timeout', '__return_zero' );
		$other->query( "SELECT RELEASE_LOCK('" . $other->real_escape_string( $name ) . "')" );
		$other->close();

		$this->assertSame( array(), $this->calls );
		$this->assertSame( 0, AfterCommit::pending() );
	}

	public function test_renewal_fires_after_the_commit_and_a_rolled_back_renewal_fires_nothing(): void {
		global $wpdb;
		$seeded = $this->seed();
		$before = $this->license( $seeded['id'] );
		$next   = gmdate( 'Y-m-d H:i:s', strtotime( $before['paid_until'] . ' UTC' ) + YEAR_IN_SECONDS );

		// The entitlement update fails: the renewal is rolled back and nothing is announced.
		$entitlements = $this->tables()['entitlements'];
		$break        = static function ( $query ) use ( $entitlements ) {
			return 0 === strpos( ltrim( $query ), 'UPDATE `' . $entitlements . '`' ) ? 'UPDATE `' . $entitlements . '` SET no_such_column = 1' : $query;
		};
		add_filter( 'query', $break );
		$suppress = $wpdb->suppress_errors( true );
		$this->assertFalse( LicenseLifecycle::mark_renewed_manually( $seeded['id'], $next, 0 ) );
		$wpdb->suppress_errors( $suppress );
		remove_filter( 'query', $break );
		$this->assertSame( array(), $this->calls, 'A rolled-back renewal fires nothing.' );
		$this->assertSame( 0, AfterCommit::pending(), 'Nothing waits for a later flush either.' );

		// Restore the row the test database did not roll back (WooCommerce transactions are off in tests).
		$wpdb->update( $this->tables()['licenses'], array( 'paid_until' => $before['paid_until'] ), array( 'id' => $seeded['id'] ) );
		$this->assertTrue( LicenseLifecycle::mark_renewed_manually( $seeded['id'], $next, 0 ) );
		$this->assertSame( array( array( $seeded['id'], 'active', 'active', 'renewal_succeeded' ) ), $this->of( 'wplc/license_status_changed' ) );
		$this->assert_no_secret_in_any_call( $seeded );
	}

	public function test_actions_wait_for_an_open_transaction_and_are_dropped_by_its_rollback(): void {
		$seeded = $this->seed();

		AfterCommit::begin();
		$this->assertTrue( LicenseLifecycle::admin_set_status( $seeded['id'], 'suspended', 'Inside a transaction', 1 ) );
		$this->assertSame( array(), $this->calls, 'Nothing before the commit.' );
		AfterCommit::commit();
		$this->assertCount( 1, $this->of( 'wplc/license_status_changed' ) );

		AfterCommit::begin();
		$this->assertTrue( LicenseLifecycle::admin_set_status( $seeded['id'], 'active', 'Rolled back', 1 ) );
		AfterCommit::rollback();
		AfterCommit::on_shutdown();
		$this->assertCount( 1, $this->of( 'wplc/license_status_changed' ), 'The rolled-back change fires nothing.' );
	}

	// ------------------------------------------------------------------
	// wplc/license_issued_followup
	// ------------------------------------------------------------------

	public function test_issued_followup_passes_no_key_and_the_key_action_is_unchanged(): void {
		$product = $this->create_license_product();
		$order   = $this->create_paid_order( array( array( $product, 1 ) ), $this->customer );
		$license = $this->licenses_for_order( $order->get_id() )[0];
		$seeded  = array(
			'id'   => (int) $license['id'],
			'uuid' => (string) $license['license_uuid'],
			'key'  => LicenseKey::for_uuid( (string) $license['license_uuid'], (string) $license['license_key_secret_id'] ),
		);
		$this->assertSame( array( array( $seeded['id'], $seeded['key'], $order->get_id() ) ), $this->issued, 'Backward compatibility: wplc/license_issued still passes the key.' );

		LicenseIssuer::run_followup( $seeded['id'] );

		$this->assertSame( array( array( $seeded['id'], $order->get_id() ) ), $this->of( 'wplc/license_issued_followup' ) );
		$this->assert_no_secret_in_any_call( $seeded );
	}

	public function test_a_license_issued_by_hand_fires_the_followup_with_order_zero(): void {
		$product = $this->create_license_product();
		$plan    = \WPLC\Catalog\Catalog::mapping_for_product( $product );
		$this->assertIsArray( $plan );
		$id = LicenseIssuer::issue_manual( $this->customer, $plan, gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ), 1, 'Partner license' );
		$this->assertGreaterThan( 0, $id );

		$this->assertSame( array( array( $id, 0 ) ), $this->of( 'wplc/license_issued_followup' ) );
	}

	// ------------------------------------------------------------------
	// wplc/license_plan_changed
	// ------------------------------------------------------------------

	public function test_plan_change_fires_once_and_a_refused_change_not_at_all(): void {
		$solo     = $this->create_license_product( array( 'plan_key' => 'solo' ), '49.00' );
		$business = $this->create_license_product( array( 'plan_key' => 'business', 'live_site_limit' => 5 ), '149.00' );
		$order    = $this->create_paid_order( array( array( $solo, 1 ) ), $this->customer );
		$license  = (int) $this->licenses_for_order( $order->get_id() )[0]['id'];
		$this->calls = array();

		$this->assertSame( 'plan_change_unavailable', PlanChange::admin_change_now( $license, 999999, 'No such plan', 1 ) );
		$this->assertSame( array(), $this->calls );

		$this->assertSame( 'plan_changed', PlanChange::admin_change_now( $license, $business->get_id(), 'Customer asked', 1 ) );
		$this->assertSame( array( array( $license ) ), $this->of( 'wplc/license_plan_changed' ) );
		$this->assertFalse( $this->calls[0][2], 'Fired after the locks.' );
	}

	// ------------------------------------------------------------------
	// wplc/activation_created, wplc/site_limit_reached, wplc/activation_removed
	// ------------------------------------------------------------------

	public function test_activation_and_limit_actions_fire_after_the_activation_lock(): void {
		$seeded = $this->seed();

		$first = $this->activate_via_api( $seeded['key'] );
		$this->assertContains( $first['response']->get_status(), array( 200, 201 ) );
		$activation_id = (int) $first['response']->get_data()['data']['activation_id'];
		$refused       = $this->activate_via_api( $seeded['key'] );
		$this->assertApiError( 409, 'site_limit', $refused['response'] );

		$this->assertSame( array( array( $seeded['id'], $activation_id, 'live' ) ), $this->of( 'wplc/activation_created' ), 'Only the stored activation counts.' );
		$this->assertSame( array( array( $seeded['id'], 'live', 1 ) ), $this->of( 'wplc/site_limit_reached' ) );
		$json = (string) wp_json_encode( $this->calls );
		$this->assertStringNotContainsString( $first['secret'], $json );
		$this->assertStringNotContainsString( $first['uuid'], $json );
		$this->assert_no_secret_in_any_call( $seeded );

		// Repeating the activation of a known installation is no new activation.
		$this->activate_via_api( $seeded['key'], array( 'installation_id' => $first['uuid'], 'installation_secret' => $first['secret'], 'site_url' => 'https://repeat.example.com' ) );
		$this->assertCount( 1, $this->of( 'wplc/activation_created' ) );
	}

	public function test_activation_removed_names_the_initiator_and_includes_the_staging_copy(): void {
		$seeded  = $this->seed( array(), array( 'sites.live_limit' => 5 ) );
		$api     = $this->activate_via_api( $seeded['key'] );
		$api_id  = (int) $api['response']->get_data()['data']['activation_id'];
		$live    = $this->seed_activation( $seeded['id'] );
		$staging = $this->seed_activation( $seeded['id'], array( 'environment' => 'staging', 'staging_parent_id' => $live['id'] ) );
		$own     = $this->seed_activation( $seeded['id'] );
		$this->calls = array();

		$response = $this->dispatch( $this->api_request( 'DELETE', '/wplc/v1/activations/' . $api_id, array(), $api ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( ActivationManager::deactivate( $live['id'], 'Moved hosting', 1, 'admin_site_released' ) );
		$this->assertTrue( ActivationManager::deactivate( $own['id'], AuditReason::DEACTIVATED_BY_OWNER, $this->customer ) );
		$this->assertFalse( ActivationManager::deactivate( $own['id'], AuditReason::DEACTIVATED_BY_OWNER, $this->customer ), 'Already released: no second call.' );

		$this->assertSame(
			array(
				array( $seeded['id'], $api_id, 'live', 'api' ),
				array( $seeded['id'], $live['id'], 'live', 'support' ),
				array( $seeded['id'], $staging['id'], 'staging', 'support' ),
				array( $seeded['id'], $own['id'], 'live', 'customer' ),
			),
			$this->of( 'wplc/activation_removed' )
		);
		$this->assertStringNotContainsString( 'Moved hosting', (string) wp_json_encode( $this->calls ), 'The reason text is not passed on.' );
		$this->assertStringNotContainsString( $staging['secret'], (string) wp_json_encode( $this->calls ) );
		$this->assert_no_secret_in_any_call( $seeded );
	}

	public function test_a_throwing_listener_does_not_break_the_activation(): void {
		$seeded = $this->seed();
		$failed = array();
		add_action(
			'wplc/activation_created',
			static function () {
				throw new RuntimeException( 'Receiver is broken' );
			},
			5
		);
		add_action(
			'wplc/job_failed',
			static function ( $job ) use ( &$failed ) {
				$failed[] = $job;
			}
		);

		$site = $this->activate_via_api( $seeded['key'] );

		$this->assertContains( $site['response']->get_status(), array( 200, 201 ), 'The activation answer is not affected.' );
		$this->assertSame( array( 'action:wplc/activation_created' ), $failed );
		$this->assertSame( 0, AfterCommit::pending() );
	}

	// ------------------------------------------------------------------
	// wplc/release_published, wplc/release_withdrawn
	// ------------------------------------------------------------------

	public function test_release_publish_and_withdraw_fire_once_and_a_refused_publish_not_at_all(): void {
		global $wpdb;
		$dir = sys_get_temp_dir() . '/wplc-ev-' . bin2hex( random_bytes( 6 ) );
		add_filter(
			'wplc_package_storage_dir',
			static function () use ( $dir ) {
				return $dir;
			}
		);
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert( $this->tables()['products'], array( 'product_key' => 'test-product', 'display_name' => 'Test Product', 'active' => 1, 'created_at' => $now, 'updated_at' => $now ) );
		$zip           = sys_get_temp_dir() . '/wplc-ev-' . bin2hex( random_bytes( 6 ) ) . '.zip';
		$this->files[] = $zip;
		$archive       = new ZipArchive();
		$archive->open( $zip, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$archive->addFromString( 'test-product/test-product.php', "<?php\n/**\n * Plugin Name: Test Product\n * Version: 3.1.0\n * Requires at least: 6.5\n * Requires PHP: 7.4\n */\n" );
		$archive->close();
		$id = ReleaseRegistry::create_with_package( array( 'product_key' => 'test-product', 'version' => '3.1.0' ), $zip );
		$this->assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : '' );

		$this->assertTrue( ReleaseRegistry::publish( $id, 0, 'Release' ) );
		$this->assertInstanceOf( WP_Error::class, ReleaseRegistry::publish( $id, 0, 'Again' ), 'A published release cannot be published again.' );
		$this->assertTrue( ReleaseRegistry::withdraw( $id, 'Broken', 0 ) );

		$this->assertSame(
			array(
				array( 'wplc/release_published', array( $id, 'test-product', '3.1.0', 'stable' ), false ),
				array( 'wplc/release_withdrawn', array( $id, 'test-product', '3.1.0', 'stable' ), false ),
			),
			$this->calls
		);
		foreach ( (array) glob( $dir . '/{,.}[!.]*', GLOB_BRACE ) as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		if ( is_dir( $dir ) ) {
			rmdir( $dir );
		}
	}

	// ------------------------------------------------------------------
	// WFC-LIC-05: the read-only facade
	// ------------------------------------------------------------------

	public function test_the_facade_reads_by_the_license_id_of_an_action(): void {
		$seeded = $this->seed( array(), array( 'sites.live_limit' => 5, 'feature.export' => true, 'feature.white_label' => false ) );
		$this->assertTrue( LicenseLifecycle::admin_set_status( $seeded['id'], 'suspended', 'Facade check', 1 ) );
		$license_id = (int) $this->of( 'wplc/license_status_changed' )[0][0];
		$license    = $this->license( $seeded['id'] );

		$summary = LicenseReader::get( $license_id );
		$this->assertSame(
			array( 'license_id', 'product_key', 'plan_key', 'status', 'access', 'expires_at', 'access_until', 'customer_user_id' ),
			array_keys( $summary )
		);
		$this->assertSame( array( $seeded['id'], 'suspended', false, $this->customer ), array( $summary['license_id'], $summary['status'], $summary['access'], $summary['customer_user_id'] ) );
		$this->assertSame( gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $license['paid_until'] . ' UTC' ) ), LicenseReader::expires_at( $license_id ) );
		$this->assertSame( array(), LicenseReader::entitlements( $license_id ), 'A suspended license grants nothing.' );

		$this->assertTrue( LicenseLifecycle::admin_set_status( $seeded['id'], 'active', 'Back', 1 ) );
		$this->assertSame( 'active', LicenseReader::status( $license_id ) );
		$this->assertSame( array( 'product_key' => 'test-product', 'plan_key' => 'single' ), LicenseReader::plan( $license_id ) );
		$this->assertSame( array( 'feature.export' => true, 'feature.white_label' => false, 'sites.live_limit' => 5 ), LicenseReader::entitlements( $license_id ) );
		$this->assertTrue( LicenseReader::is_entitled( $license_id, 'feature.export' ) );
		$this->assertFalse( LicenseReader::is_entitled( $license_id, 'feature.white_label' ) );
		$this->assertTrue( LicenseReader::get( $license_id )['access'] );
		$this->assertSame( $seeded['id'], LicenseReader::get( $license_id, $this->customer )['license_id'], 'Narrowed to the owner.' );

		$this->assertNull( LicenseReader::get( 0 ) );
		$this->assertNull( LicenseReader::get( $license_id + 100000 ) );
		$this->assertNull( LicenseReader::get( $license_id, $this->customer + 1000 ), 'Narrowed to another owner: not found.' );
		$this->assertSame( array(), LicenseReader::entitlements( $license_id + 100000 ) );
	}

	public function test_the_facade_lists_the_licenses_of_a_user(): void {
		$first  = $this->seed();
		$second = $this->seed( array( 'product_key' => 'other-product', 'status' => 'expired' ) );
		$this->seed( array( 'customer_id' => $this->customer + 1000 ) );
		$pending = $this->seed( array( 'status' => 'pending' ) );

		$this->assertSame( array( $second['id'], $first['id'] ), array_column( LicenseReader::for_user( $this->customer ), 'license_id' ), 'Newest first, only this user, no pending rows.' );
		$this->assertSame( array( $first['id'] ), array_column( LicenseReader::for_user( $this->customer, 'test-product' ), 'license_id' ) );
		$this->assertSame( array(), LicenseReader::for_user( 0 ) );
		$this->assertNull( LicenseReader::get( $pending['id'] ), 'A row of an aborted issuing request is not a license yet.' );
	}

	public function test_the_facade_issues_only_select_queries_and_discloses_no_key_or_email(): void {
		global $wpdb;
		$seeded = $this->seed();
		$site   = $this->seed_activation( $seeded['id'] );
		$queries  = array();
		$recorder = static function ( $query ) use ( &$queries ) {
			$queries[] = $query;
			return $query;
		};
		$audit_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->tables()['audit_log']}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema.

		add_filter( 'query', $recorder );
		$results = array(
			LicenseReader::get( $seeded['id'] ),
			LicenseReader::get( $seeded['id'], $this->customer ),
			LicenseReader::status( $seeded['id'] ),
			LicenseReader::plan( $seeded['id'] ),
			LicenseReader::expires_at( $seeded['id'] ),
			LicenseReader::entitlements( $seeded['id'] ),
			LicenseReader::is_entitled( $seeded['id'], 'feature.export' ),
			LicenseReader::for_user( $this->customer ),
			LicenseReader::for_user( $this->customer, 'test-product' ),
			LicenseReader::get( 999999999 ),
		);
		remove_filter( 'query', $recorder );

		$this->assertNotEmpty( $queries );
		foreach ( $queries as $query ) {
			$this->assertMatchesRegularExpression( '/^\s*SELECT\s/i', $query, 'Only reading queries: ' . $query );
			$this->assertDoesNotMatchRegularExpression( '/\b(INSERT|UPDATE|DELETE|REPLACE|GET_LOCK|ALTER|CREATE|DROP|TRUNCATE)\b/i', $query );
			$this->assertStringNotContainsString( 'key_prefix', $query, 'The key prefix is never read.' );
			$this->assertStringNotContainsString( 'license_key', $query );
		}
		$this->assertSame( $audit_before, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->tables()['audit_log']}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema.

		$json = (string) wp_json_encode( $results );
		foreach ( array( $seeded['key'], $seeded['uuid'], LicenseKey::hash( $seeded['key'] ), LicenseKey::prefix( $seeded['uuid'] ), 'WPLC-', $this->customer_email, '@', $site['secret'], 'example.test' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $json );
		}
		$this->assertSame( 'active', $results[2] );
		$this->assertTrue( $results[6] );

		$methods = array_map(
			static function ( ReflectionMethod $method ) {
				return $method->getName();
			},
			( new ReflectionClass( LicenseReader::class ) )->getMethods( ReflectionMethod::IS_PUBLIC )
		);
		sort( $methods );
		$this->assertSame( array( 'entitlements', 'expires_at', 'for_user', 'get', 'is_entitled', 'plan', 'status' ), $methods, 'The facade offers reading methods only.' );
	}
}
