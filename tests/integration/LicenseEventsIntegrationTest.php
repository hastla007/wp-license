<?php
/**
 * WFC-LIC-04, WFC-LIC-05, WFC-LIC-07: the versioned license events and the read-only facade
 * against a real database.
 *
 * Every event fires exactly once with the documented public payload, after the license lock
 * (and the activation lock) is released, and never for a refused or rolled-back change. The
 * existing actions keep their arguments. The facade only reads and never discloses a key or
 * an email address.
 *
 * @package WPLC
 */

use WPLC\Events\AfterCommit;
use WPLC\Events\LicenseEvents;
use WPLC\Events\LicenseReader;
use WPLC\License\ActivationManager;
use WPLC\License\AuditReason;
use WPLC\License\LicenseIssuer;
use WPLC\License\LicenseKey;
use WPLC\License\LicenseLifecycle;
use WPLC\License\PlanChange;
use WPLC\Update\ReleaseRegistry;

final class LicenseEventsIntegrationTest extends WPLC_Integration_TestCase {

	/**
	 * Envelopes received through `wplc/event`, each with the lock state seen by the listener.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private $events = array();

	/**
	 * Calls of the existing actions (name and arguments).
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private $legacy = array();

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
		$this->events         = array();
		$this->legacy         = array();

		add_action(
			'wplc/event',
			function ( $envelope ) {
				global $wpdb;
				$license_lock    = '0';
				$activation_lock = '0';
				$license_id      = $this->license_id_of( (string) ( $envelope['license'] ?? '' ) );
				if ( $license_id > 0 ) {
					$license_lock    = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s) IS NOT NULL', LicenseLifecycle::lock_name( $license_id ) ) );
					$activation_lock = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s) IS NOT NULL', ActivationManager::lock_name( $license_id ) ) );
				}
				$this->events[] = array(
					'envelope' => $envelope,
					'locked'   => '1' === $license_lock || '1' === $activation_lock,
				);
			}
		);
		foreach ( array( 'wplc/license_status_changed' => 4, 'wplc/license_plan_changed' => 1, 'wplc/license_issued_followup' => 2, 'wplc/license_issued' => 3 ) as $hook => $args ) {
			add_action(
				$hook,
				function ( ...$values ) use ( $hook ) {
					$this->legacy[] = array_merge( array( $hook ), $values );
				},
				10,
				$args
			);
		}
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

	private function license_id_of( string $reference ): int {
		global $wpdb;
		if ( '' === $reference ) {
			return 0;
		}
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE key_prefix = %s ORDER BY id DESC LIMIT 1', $this->tables()['licenses'], $reference ) );
	}

	/**
	 * Envelopes of one event name.
	 *
	 * @param string $event Event name.
	 * @return array<int,array<string,mixed>>
	 */
	private function of( string $event ): array {
		return array_values(
			array_map(
				static function ( $entry ) {
					return $entry['envelope'];
				},
				array_filter(
					$this->events,
					static function ( $entry ) use ( $event ) {
						return $event === $entry['envelope']['event'];
					}
				)
			)
		);
	}

	private function seed( array $overrides = array(), array $entitlements = array( 'sites.live_limit' => 1, 'feature.export' => true ) ): array {
		return $this->seed_license( array_merge( array( 'customer_id' => $this->customer, 'product_key' => 'test-product' ), $overrides ), $entitlements );
	}

	/**
	 * Common checks: public fields only, released locks, no key, no email.
	 *
	 * @param array<string,mixed> $envelope Envelope.
	 * @param array<string,mixed> $seeded   Seeded license (id, key, uuid).
	 */
	private function assert_public_license_event( array $envelope, array $seeded ): void {
		$this->assertSame( LicenseKey::prefix( $seeded['uuid'] ), $envelope['license'] );
		$this->assertSame( $this->customer, $envelope['customer_user_id'] );
		$this->assertSame( WPLC_EVENT_CONTRACT_VERSION, $envelope['contract'] );
		$this->assertSame( 1, $envelope['version'] );
		$this->assertArrayNotHasKey( 'license_id', $envelope );
		$this->assertArrayNotHasKey( 'id', $envelope );
		$json = (string) wp_json_encode( $envelope );
		foreach ( array( $seeded['key'], $seeded['uuid'], $this->customer_email, LicenseKey::hash( $seeded['key'] ) ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $json );
		}
		foreach ( $envelope as $value ) {
			$this->assertTrue( null === $value || is_scalar( $value ) );
		}
	}

	private function assert_no_event_under_a_lock(): void {
		foreach ( $this->events as $entry ) {
			$this->assertFalse( $entry['locked'], $entry['envelope']['event'] . ' fired while a lock was held.' );
		}
	}

	// ------------------------------------------------------------------
	// license.license.status_changed (WFC-LIC-04, WFC-LIC-07)
	// ------------------------------------------------------------------

	public function test_status_change_fires_one_event_after_the_lock_and_keeps_the_existing_action(): void {
		$seeded = $this->seed();

		$this->assertTrue( LicenseLifecycle::admin_set_status( $seeded['id'], 'suspended', 'Events check', 1 ) );

		$fired = $this->of( LicenseEvents::LICENSE_STATUS_CHANGED );
		$this->assertCount( 1, $fired );
		$this->assert_public_license_event( $fired[0], $seeded );
		$this->assertSame( array( 'suspended', 'active', 'test-product', 'single' ), array( $fired[0]['status'], $fired[0]['previous_status'], $fired[0]['product_key'], $fired[0]['plan_key'] ) );
		$this->assertNotSame( '', $fired[0]['action'] );
		$this->assert_no_event_under_a_lock();
		// Backward compatibility: the existing action, once, with its four arguments.
		$this->assertSame( array( array( 'wplc/license_status_changed', $seeded['id'], 'active', 'suspended', $fired[0]['action'] ) ), $this->legacy );
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

		$this->assertSame( array(), $this->events );
		$this->assertSame( array(), $this->legacy );
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
		$this->assertSame( array(), $this->events, 'A rolled-back renewal fires no event.' );
		$this->assertSame( array(), $this->legacy );
		$this->assertSame( 0, AfterCommit::pending(), 'Nothing waits for a later flush either.' );

		// Restore the row the test database did not roll back (WooCommerce transactions are off in tests).
		$wpdb->update( $this->tables()['licenses'], array( 'paid_until' => $before['paid_until'] ), array( 'id' => $seeded['id'] ) );
		$this->assertTrue( LicenseLifecycle::mark_renewed_manually( $seeded['id'], $next, 0 ) );
		$fired = $this->of( LicenseEvents::LICENSE_STATUS_CHANGED );
		$this->assertCount( 1, $fired );
		$this->assertSame( array( 'active', 'renewal_succeeded' ), array( $fired[0]['status'], $fired[0]['action'] ) );
		$this->assert_no_event_under_a_lock();
	}

	public function test_events_wait_for_an_open_transaction_and_are_dropped_by_its_rollback(): void {
		$seeded = $this->seed();

		AfterCommit::begin();
		$this->assertTrue( LicenseLifecycle::admin_set_status( $seeded['id'], 'suspended', 'Inside a transaction', 1 ) );
		$this->assertSame( array(), $this->events, 'No event before the commit.' );
		$this->assertSame( array(), $this->legacy, 'The existing action waits as well.' );
		AfterCommit::commit();
		$this->assertCount( 1, $this->of( LicenseEvents::LICENSE_STATUS_CHANGED ) );
		$this->assertCount( 1, $this->legacy );

		AfterCommit::begin();
		$this->assertTrue( LicenseLifecycle::admin_set_status( $seeded['id'], 'active', 'Rolled back', 1 ) );
		AfterCommit::rollback();
		AfterCommit::on_shutdown();
		$this->assertCount( 1, $this->of( LicenseEvents::LICENSE_STATUS_CHANGED ), 'The rolled-back change fires nothing.' );
		$this->assertCount( 1, $this->legacy );
	}

	public function test_a_throwing_listener_does_not_disturb_the_license_operation(): void {
		$seeded = $this->seed();
		$failed = array();
		add_action(
			'wplc/event',
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
		$named = 0;
		add_action(
			'wplc/event/' . LicenseEvents::LICENSE_STATUS_CHANGED,
			static function () use ( &$named ) {
				++$named;
			}
		);

		$this->assertTrue( LicenseLifecycle::admin_set_status( $seeded['id'], 'suspended', 'Receiver fails', 1 ) );

		$this->assertSame( 'suspended', $this->license( $seeded['id'] )['status'] );
		$this->assertSame( array( 'license_event:' . LicenseEvents::LICENSE_STATUS_CHANGED ), $failed );
		$this->assertSame( 1, $named, 'The event still reaches the listeners of its own name.' );
		$this->assertCount( 1, $this->legacy, 'The existing action is unaffected.' );
	}

	// ------------------------------------------------------------------
	// license.license.issued
	// ------------------------------------------------------------------

	public function test_issued_event_comes_from_the_follow_up_without_the_key_and_keeps_its_id_on_a_repeat(): void {
		$product = $this->create_license_product();
		$order   = $this->create_paid_order( array( array( $product, 1 ) ), $this->customer );
		$license = $this->licenses_for_order( $order->get_id() )[0];
		$seeded  = array(
			'id'   => (int) $license['id'],
			'uuid' => (string) $license['license_uuid'],
			'key'  => LicenseKey::for_uuid( (string) $license['license_uuid'], (string) $license['license_key_secret_id'] ),
		);
		$this->assertSame( array(), $this->of( LicenseEvents::LICENSE_ISSUED ), 'The key-carrying action is never turned into an event.' );
		$this->assertSame( 'wplc/license_issued', $this->legacy[0][0], 'The existing action still passes the key.' );
		$this->assertSame( $seeded['key'], $this->legacy[0][2] );

		LicenseIssuer::run_followup( $seeded['id'] );
		LicenseIssuer::run_followup( $seeded['id'] );

		$fired = $this->of( LicenseEvents::LICENSE_ISSUED );
		$this->assertCount( 2, $fired, 'One event per follow-up run.' );
		$this->assert_public_license_event( $fired[0], $seeded );
		$this->assertSame( $fired[0]['event_id'], $fired[1]['event_id'], 'A repeated follow-up carries the same event_id.' );
		$this->assertSame( array( $order->get_id(), 'active' ), array( $fired[0]['order_id'], $fired[0]['status'] ) );
		$followups = array_values(
			array_filter(
				$this->legacy,
				static function ( $call ) {
					return 'wplc/license_issued_followup' === $call[0];
				}
			)
		);
		$this->assertSame( array( 'wplc/license_issued_followup', $seeded['id'], $order->get_id() ), $followups[0] );
	}

	public function test_a_license_issued_by_hand_fires_the_issued_event_with_order_zero(): void {
		$product = $this->create_license_product();
		$plan    = \WPLC\Catalog\Catalog::mapping_for_product( $product );
		$this->assertIsArray( $plan );
		$id = LicenseIssuer::issue_manual( $this->customer, $plan, gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ), 1, 'Partner license' );
		$this->assertGreaterThan( 0, $id );

		$fired = $this->of( LicenseEvents::LICENSE_ISSUED );
		$this->assertCount( 1, $fired );
		$this->assertSame( 0, $fired[0]['order_id'] );
		$this->assertSame( LicenseKey::prefix( (string) $this->license( $id )['license_uuid'] ), $fired[0]['license'] );
	}

	// ------------------------------------------------------------------
	// license.license.plan_changed
	// ------------------------------------------------------------------

	public function test_plan_change_fires_one_event_and_a_refused_change_none(): void {
		$solo     = $this->create_license_product( array( 'plan_key' => 'solo', 'entitlements' => array( 'feature.export' => true ) ), '49.00' );
		$business = $this->create_license_product( array( 'plan_key' => 'business', 'live_site_limit' => 5, 'entitlements' => array( 'feature.export' => true ) ), '149.00' );
		$order    = $this->create_paid_order( array( array( $solo, 1 ) ), $this->customer );
		$license  = $this->licenses_for_order( $order->get_id() )[0];
		$this->events = array();
		$this->legacy = array();

		$this->assertSame( 'plan_change_unavailable', PlanChange::admin_change_now( (int) $license['id'], 999999, 'No such plan', 1 ) );
		$this->assertSame( array(), $this->events );

		$this->assertSame( 'plan_changed', PlanChange::admin_change_now( (int) $license['id'], $business->get_id(), 'Customer asked', 1 ) );
		$fired = $this->of( LicenseEvents::LICENSE_PLAN_CHANGED );
		$this->assertCount( 1, $fired );
		$this->assertSame( array( 'business', 'test-product' ), array( $fired[0]['plan_key'], $fired[0]['product_key'] ) );
		$this->assert_no_event_under_a_lock();
		$this->assertContains( array( 'wplc/license_plan_changed', (int) $license['id'] ), $this->legacy );
	}

	// ------------------------------------------------------------------
	// license.site.*
	// ------------------------------------------------------------------

	public function test_site_activation_and_limit_events_fire_after_the_activation_lock(): void {
		$seeded = $this->seed();

		$first = $this->activate_via_api( $seeded['key'] );
		$this->assertContains( $first['response']->get_status(), array( 200, 201 ) );
		$refused = $this->activate_via_api( $seeded['key'] );
		$this->assertApiError( 409, 'site_limit', $refused['response'] );

		$activated = $this->of( LicenseEvents::SITE_ACTIVATED );
		$this->assertCount( 1, $activated, 'Only the stored activation counts.' );
		$this->assert_public_license_event( $activated[0], $seeded );
		$this->assertSame( 'live', $activated[0]['environment'] );
		$limit = $this->of( LicenseEvents::SITE_LIMIT_REACHED );
		$this->assertCount( 1, $limit );
		$this->assertSame( array( 'live', 1 ), array( $limit[0]['environment'], $limit[0]['limit'] ) );
		$json = (string) wp_json_encode( $this->events );
		$this->assertStringNotContainsString( $first['secret'], $json );
		$this->assertStringNotContainsString( $first['uuid'], $json );
		$this->assertStringNotContainsString( 'example.com', $json, 'The site address is not part of the event.' );
		$this->assert_no_event_under_a_lock();

		// Repeating the activation of a known installation is no new activation.
		$this->activate_via_api( $seeded['key'], array( 'installation_id' => $first['uuid'], 'installation_secret' => $first['secret'], 'site_url' => 'https://repeat.example.com' ) );
		$this->assertCount( 1, $this->of( LicenseEvents::SITE_ACTIVATED ) );
	}

	public function test_site_deactivation_events_name_the_initiator_and_include_the_staging_copy(): void {
		$seeded  = $this->seed( array(), array( 'sites.live_limit' => 5 ) );
		$api     = $this->activate_via_api( $seeded['key'] );
		$api_id  = (int) $api['response']->get_data()['data']['activation_id'];
		$live    = $this->seed_activation( $seeded['id'] );
		$staging = $this->seed_activation( $seeded['id'], array( 'environment' => 'staging', 'staging_parent_id' => $live['id'] ) );
		$own     = $this->seed_activation( $seeded['id'] );
		$this->events = array();

		$response = $this->dispatch( $this->api_request( 'DELETE', '/wplc/v1/activations/' . $api_id, array(), $api ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( ActivationManager::deactivate( $live['id'], 'Moved hosting', 1, 'admin_site_released' ) );
		$this->assertTrue( ActivationManager::deactivate( $own['id'], AuditReason::DEACTIVATED_BY_OWNER, $this->customer ) );
		$this->assertFalse( ActivationManager::deactivate( $own['id'], AuditReason::DEACTIVATED_BY_OWNER, $this->customer ), 'Already released: no second event.' );

		$fired = $this->of( LicenseEvents::SITE_DEACTIVATED );
		$this->assertSame(
			array( array( 'api', 'live' ), array( 'support', 'live' ), array( 'support', 'staging' ), array( 'customer', 'live' ) ),
			array_map(
				static function ( $e ) {
					return array( $e['initiator'], $e['environment'] );
				},
				$fired
			)
		);
		foreach ( $fired as $envelope ) {
			$this->assert_public_license_event( $envelope, $seeded );
		}
		$this->assertStringNotContainsString( $staging['secret'], (string) wp_json_encode( $fired ) );
		$this->assert_no_event_under_a_lock();
	}

	// ------------------------------------------------------------------
	// license.release.*
	// ------------------------------------------------------------------

	public function test_release_publish_and_withdraw_fire_once_and_a_refused_publish_none(): void {
		$dir = sys_get_temp_dir() . '/wplc-ev-' . bin2hex( random_bytes( 6 ) );
		add_filter(
			'wplc_package_storage_dir',
			static function () use ( $dir ) {
				return $dir;
			}
		);
		global $wpdb;
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

		$this->assertSame( array( LicenseEvents::RELEASE_PUBLISHED, LicenseEvents::RELEASE_WITHDRAWN ), array_column( array_column( $this->events, 'envelope' ), 'event' ) );
		foreach ( array_column( $this->events, 'envelope' ) as $envelope ) {
			$this->assertSame( array( 'event_id', 'event', 'version', 'contract', 'occurred_at', 'product_key', 'release_version', 'channel' ), array_keys( $envelope ) );
			$this->assertSame( array( 'test-product', '3.1.0', 'stable' ), array( $envelope['product_key'], $envelope['release_version'], $envelope['channel'] ) );
		}
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

	public function test_the_facade_reads_by_the_public_reference_of_an_event(): void {
		$seeded = $this->seed( array(), array( 'sites.live_limit' => 5, 'feature.export' => true, 'feature.white_label' => false ) );
		$this->assertTrue( LicenseLifecycle::admin_set_status( $seeded['id'], 'suspended', 'Facade check', 1 ) );
		$reference = $this->of( LicenseEvents::LICENSE_STATUS_CHANGED )[0]['license'];
		$license   = $this->license( $seeded['id'] );

		$summary = LicenseReader::get( $reference );
		$this->assertSame(
			array( 'license', 'product_key', 'plan_key', 'status', 'access', 'expires_at', 'access_until', 'customer_user_id' ),
			array_keys( $summary )
		);
		$this->assertSame( array( $reference, 'suspended', false, $this->customer ), array( $summary['license'], $summary['status'], $summary['access'], $summary['customer_user_id'] ) );
		$this->assertSame( gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $license['paid_until'] . ' UTC' ) ), LicenseReader::expires_at( $reference ) );
		$this->assertSame( array(), LicenseReader::entitlements( $reference ), 'A suspended license grants nothing.' );

		$this->assertTrue( LicenseLifecycle::admin_set_status( $seeded['id'], 'active', 'Back', 1 ) );
		$this->assertSame( 'active', LicenseReader::status( strtolower( $reference ) ), 'The reference is case-insensitive.' );
		$this->assertSame( array( 'product_key' => 'test-product', 'plan_key' => 'single' ), LicenseReader::plan( $reference ) );
		$this->assertSame( array( 'feature.export' => true, 'feature.white_label' => false, 'sites.live_limit' => 5 ), LicenseReader::entitlements( $reference ) );
		$this->assertTrue( LicenseReader::is_entitled( $reference, 'feature.export' ) );
		$this->assertFalse( LicenseReader::is_entitled( $reference, 'feature.white_label' ) );
		$this->assertTrue( LicenseReader::get( $reference )['access'] );

		$this->assertNull( LicenseReader::get( 'WPLC-00000000' ) );
		$this->assertNull( LicenseReader::get( $seeded['key'] ), 'A license key is not a reference.' );
		$this->assertNull( LicenseReader::status( "WPLC-' OR 1=1 --" ) );
		$this->assertSame( array(), LicenseReader::entitlements( 'nonsense' ) );
		$this->assertNull( LicenseReader::get( $reference, $this->customer + 1000 ), 'Narrowed to another owner: not found.' );
	}

	public function test_the_facade_lists_the_licenses_of_a_user_and_refuses_an_ambiguous_reference(): void {
		$first  = $this->seed();
		$second = $this->seed( array( 'product_key' => 'other-product', 'status' => 'expired' ) );
		$this->seed( array( 'customer_id' => $this->customer + 1000 ) );

		$all = LicenseReader::for_user( $this->customer );
		$this->assertSame( array( LicenseKey::prefix( $second['uuid'] ), LicenseKey::prefix( $first['uuid'] ) ), array_column( $all, 'license' ), 'Newest first, only this user.' );
		$this->assertSame( array( LicenseKey::prefix( $first['uuid'] ) ), array_column( LicenseReader::for_user( $this->customer, 'test-product' ), 'license' ) );
		$this->assertSame( array(), LicenseReader::for_user( 0 ) );

		// Two licenses whose UUIDs share the first eight hex digits have the same reference.
		$uuid  = substr( $first['uuid'], 0, 8 ) . '-0000-4000-8000-000000000001';
		$this->seed_license( array( 'license_uuid' => $uuid, 'key_prefix' => LicenseKey::prefix( $uuid ), 'customer_id' => $this->customer + 1000 ) );
		$this->assertSame( LicenseKey::prefix( $first['uuid'] ), LicenseKey::prefix( $uuid ) );
		$this->assertNull( LicenseReader::get( LicenseKey::prefix( $first['uuid'] ) ), 'Ambiguous without the owner.' );
		$this->assertSame( $this->customer, LicenseReader::get( LicenseKey::prefix( $first['uuid'] ), $this->customer )['customer_user_id'] );
	}

	public function test_the_facade_issues_only_select_queries_and_discloses_no_key_or_email(): void {
		global $wpdb;
		$seeded = $this->seed();
		$this->seed_activation( $seeded['id'] );
		$reference = LicenseKey::prefix( $seeded['uuid'] );
		$queries   = array();
		$recorder  = static function ( $query ) use ( &$queries ) {
			$queries[] = $query;
			return $query;
		};
		$audit_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->tables()['audit_log']}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema.

		add_filter( 'query', $recorder );
		$results = array(
			LicenseReader::get( $reference ),
			LicenseReader::status( $reference ),
			LicenseReader::plan( $reference ),
			LicenseReader::expires_at( $reference ),
			LicenseReader::entitlements( $reference ),
			LicenseReader::is_entitled( $reference, 'feature.export' ),
			LicenseReader::for_user( $this->customer ),
			LicenseReader::for_user( $this->customer, 'test-product' ),
			LicenseReader::get( 'WPLC-FFFFFFFF' ),
		);
		remove_filter( 'query', $recorder );

		$this->assertNotEmpty( $queries );
		foreach ( $queries as $query ) {
			$this->assertMatchesRegularExpression( '/^\s*SELECT\s/i', $query, 'Only reading queries: ' . $query );
			$this->assertDoesNotMatchRegularExpression( '/\b(INSERT|UPDATE|DELETE|REPLACE|GET_LOCK|ALTER|CREATE|DROP|TRUNCATE)\b/i', $query );
		}
		$this->assertSame( $audit_before, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->tables()['audit_log']}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema.

		$json = (string) wp_json_encode( $results );
		foreach ( array( $seeded['key'], $seeded['uuid'], LicenseKey::hash( $seeded['key'] ), $this->customer_email, '@' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $json );
		}
		$this->assertSame( 'active', $results[1] );
		$this->assertTrue( $results[5] );

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
