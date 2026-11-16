<?php
/**
 * WFC-LIC-04 / WFC-LIC-07: shape of every license event, no secrets, and the "after commit" queue.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\Events\AfterCommit;
use WPLC\Events\LicenseEvents;
use WPLC\License\AuditReason;
use WPLC\License\LicenseKey;

require_once dirname( __DIR__ ) . '/Client/client-test-kit.php';

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return strtolower( preg_replace( '/[^a-z0-9_\\-]/', '', strtolower( (string) $key ) ) );
	}
}
if ( ! function_exists( 'has_action' ) ) {
	// Listeners exist only while a test of this file switches them on.
	function has_action( $hook ) {
		return ! empty( $GLOBALS['wplc_test_event_listeners'] );
	}
}

/**
 * Stand-in for the one SELECT LicenseEvents makes. Returns more columns than
 * asked for, as `SELECT *` would, to prove that only public fields leave.
 */
final class WPLC_License_Events_Test_Db {
	/** @var string */
	public $prefix = 'wp_';
	/** @var array<int,string> */
	public $queries = array();
	/** @var array<string,mixed>|null */
	public $row;

	public function prepare( $query, ...$args ) {
		return $query . ' -- ' . json_encode( $args );
	}

	public function get_row( $query, $output = null ) {
		$this->queries[] = $query;
		return $this->row;
	}
}

final class LicenseEventsTest extends TestCase {

	private const UUID = '1a2b3c4d-5e6f-4a1b-9c2d-0123456789ab';

	/** Fields every event carries. */
	private const COMMON = array( 'event_id', 'event', 'version', 'contract', 'occurred_at' );

	/** Fields of an event about one license. */
	private const LICENSE = array( 'license', 'product_key', 'plan_key', 'status', 'customer_user_id' );

	/** Documented extra fields per event. */
	private const EXTRA = array(
		LicenseEvents::LICENSE_ISSUED         => array( 'order_id' ),
		LicenseEvents::LICENSE_STATUS_CHANGED => array( 'previous_status', 'action' ),
		LicenseEvents::LICENSE_PLAN_CHANGED   => array(),
		LicenseEvents::SITE_ACTIVATED         => array( 'environment' ),
		LicenseEvents::SITE_DEACTIVATED       => array( 'environment', 'initiator' ),
		LicenseEvents::SITE_LIMIT_REACHED     => array( 'environment', 'limit' ),
	);

	/** @var mixed */
	private $previous_db;

	protected function setUp(): void {
		$this->previous_db                     = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']                       = new WPLC_License_Events_Test_Db();
		$GLOBALS['wpdb']->row                  = array(
			'id' => 42,
			'license_uuid' => self::UUID,
			'license_key_hash' => 'hash-that-must-not-leave',
			'license_key_secret_id' => 'test-2026',
			'customer_id' => 7,
			'billing_email' => 'customer@example.com',
			'installation_secret_encrypted' => 'enc-secret-that-must-not-leave',
			'payment_token_id' => 99,
			'product_key' => 'acme-reports-pro',
			'plan_key' => 'business',
			'status' => 'active',
		);
		$GLOBALS['wplc_test_actions']          = array();
		$GLOBALS['wplc_test_event_listeners']  = true;
		AfterCommit::reset();
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb']                      = $this->previous_db;
		$GLOBALS['wplc_test_actions']         = array();
		$GLOBALS['wplc_test_event_listeners'] = false;
		AfterCommit::reset();
	}

	/**
	 * Envelopes fired under `wplc/event`.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function fired(): array {
		$envelopes = array();
		foreach ( $GLOBALS['wplc_test_actions'] as $action ) {
			if ( 'wplc/event' === $action[0] ) {
				$envelopes[] = $action[1];
			}
		}
		return $envelopes;
	}

	private function assert_public_envelope( array $envelope, string $event, array $fields ): void {
		$this->assertSame( array_merge( self::COMMON, $fields ), array_keys( $envelope ), $event . ': documented fields in documented order.' );
		$this->assertSame( $event, $envelope['event'] );
		$this->assertSame( 1, $envelope['version'] );
		$this->assertSame( '1.0.0', $envelope['contract'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $envelope['occurred_at'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-[45][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $envelope['event_id'] );
		foreach ( $envelope as $name => $value ) {
			$this->assertTrue( null === $value || is_scalar( $value ), $event . ': ' . $name . ' is scalar.' );
		}
		$json = (string) json_encode( $envelope );
		foreach ( array( 'hash-that-must-not-leave', 'customer@example.com', 'enc-secret', '@', self::UUID, LicenseKey::for_uuid( self::UUID, 'test-2026' ) ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $json, $event . ' leaks ' . $secret );
		}
		if ( isset( $envelope['license'] ) ) {
			$this->assertSame( 'WPLC-1A2B3C4D', $envelope['license'], 'The public reference, never the key or the database ID.' );
			$this->assertSame( 7, $envelope['customer_user_id'] );
		}
	}

	public function test_contract_version_is_the_constant_of_the_plugin_file(): void {
		$plugin = (string) file_get_contents( dirname( __DIR__, 2 ) . '/wp-license.php' );
		$this->assertSame( 1, preg_match( "/define\\( 'WPLC_EVENT_CONTRACT_VERSION', '([^']+)' \\);/", $plugin, $match ) );
		$this->assertSame( LicenseEvents::CONTRACT_VERSION, $match[1] );
		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', $match[1], 'Semantic version.' );
	}

	public function test_every_license_event_fires_once_with_the_documented_public_payload(): void {
		LicenseEvents::on_license_issued( 42, 1001 );
		LicenseEvents::on_status_changed( 42, 'active', 'suspended', 'admin_status_change' );
		LicenseEvents::on_plan_changed( 42 );
		LicenseEvents::site_activated( 42, 'production' );
		LicenseEvents::site_deactivated( 42, 'staging', 'site_deactivated', AuditReason::DEACTIVATED_BY_OWNER, 7 );
		LicenseEvents::site_limit_reached( 42, 'production', 5 );

		$fired = $this->fired();
		$this->assertSame( array_keys( self::EXTRA ), array_column( $fired, 'event' ), 'One event per call, in order.' );
		foreach ( $fired as $envelope ) {
			$this->assert_public_envelope( $envelope, $envelope['event'], array_merge( self::LICENSE, self::EXTRA[ $envelope['event'] ] ) );
		}
		$this->assertSame( 1001, $fired[0]['order_id'] );
		$this->assertSame( array( 'suspended', 'active', 'admin_status_change' ), array( $fired[1]['status'], $fired[1]['previous_status'], $fired[1]['action'] ) );
		$this->assertSame( 'live', $fired[3]['environment'], 'production is published as live.' );
		$this->assertSame( array( 'staging', 'customer' ), array( $fired[4]['environment'], $fired[4]['initiator'] ) );
		$this->assertSame( array( 'live', 5 ), array( $fired[5]['environment'], $fired[5]['limit'] ) );

		// The same envelope also under the event's own name.
		$named = array_values(
			array_filter(
				$GLOBALS['wplc_test_actions'],
				static function ( $action ) {
					return 0 === strpos( $action[0], 'wplc/event/' );
				}
			)
		);
		$this->assertSame( array_map( static function ( $e ) { return 'wplc/event/' . $e; }, array_keys( self::EXTRA ) ), array_column( $named, 0 ) );
		$this->assertSame( $fired[2], $named[2][1] );
	}

	public function test_release_events_carry_product_version_and_channel_only(): void {
		$release = array( 'id' => 3, 'product_key' => 'acme-reports-pro', 'version' => '2.1.0', 'channel' => 'stable', 'package_url' => 'https://example.com/secret.zip?token=abc', 'sha256' => str_repeat( 'a', 64 ) );
		LicenseEvents::release_event( LicenseEvents::RELEASE_PUBLISHED, $release );
		LicenseEvents::release_event( LicenseEvents::RELEASE_WITHDRAWN, $release );

		$fired = $this->fired();
		$this->assertCount( 2, $fired );
		foreach ( array( LicenseEvents::RELEASE_PUBLISHED, LicenseEvents::RELEASE_WITHDRAWN ) as $index => $event ) {
			$this->assert_public_envelope( $fired[ $index ], $event, array( 'product_key', 'release_version', 'channel' ) );
			$this->assertSame( array( 'acme-reports-pro', '2.1.0', 'stable' ), array( $fired[ $index ]['product_key'], $fired[ $index ]['release_version'], $fired[ $index ]['channel'] ) );
			$this->assertStringNotContainsString( 'token=abc', (string) json_encode( $fired[ $index ] ) );
		}
		$this->assertSame( array(), $GLOBALS['wpdb']->queries, 'Release events read nothing.' );
	}

	public function test_every_event_of_the_contract_is_covered_and_versioned(): void {
		$this->assertSame(
			array_merge( array_keys( self::EXTRA ), array( LicenseEvents::RELEASE_PUBLISHED, LicenseEvents::RELEASE_WITHDRAWN ) ),
			array_keys( LicenseEvents::EVENTS )
		);
		foreach ( LicenseEvents::EVENTS as $event => $version ) {
			$this->assertMatchesRegularExpression( '/^license\.(license|site|release)\.[a-z_]+$/', $event, 'Names follow license.<object>.<event>.' );
			$this->assertSame( 1, $version );
		}
	}

	public function test_the_issued_event_keeps_its_id_when_the_follow_up_repeats(): void {
		LicenseEvents::on_license_issued( 42, 1001 );
		LicenseEvents::on_license_issued( 42, 1001 );
		LicenseEvents::on_plan_changed( 42 );
		LicenseEvents::on_plan_changed( 42 );

		$ids = array_column( $this->fired(), 'event_id' );
		$this->assertSame( $ids[0], $ids[1], 'A repeated follow-up is recognisable by its event_id.' );
		$this->assertSame( '5', $ids[0][14], 'Name-based UUID (v5 layout).' );
		$this->assertNotSame( $ids[2], $ids[3], 'Other events get a fresh UUID v4.' );
		$this->assertSame( '4', $ids[2][14] );
	}

	public function test_without_listeners_nothing_is_read_or_fired(): void {
		$GLOBALS['wplc_test_event_listeners'] = false;
		LicenseEvents::on_status_changed( 42, 'active', 'expired', 'license_expired' );
		LicenseEvents::site_activated( 42, 'production' );

		$this->assertSame( array(), $GLOBALS['wpdb']->queries );
		$this->assertSame( array(), $this->fired() );
	}

	public function test_an_unknown_license_fires_nothing(): void {
		$GLOBALS['wpdb']->row = null;
		LicenseEvents::on_plan_changed( 404 );
		LicenseEvents::on_plan_changed( 0 );

		$this->assertSame( array(), $this->fired() );
		$this->assertCount( 1, $GLOBALS['wpdb']->queries, 'License ID zero is not even looked up.' );
		$this->assertStringStartsWith( 'SELECT license_uuid, customer_id, product_key, plan_key, status FROM', $GLOBALS['wpdb']->queries[0], 'Only public columns are read.' );
	}

	public function test_events_raised_in_a_transaction_fire_after_the_commit(): void {
		AfterCommit::begin();
		LicenseEvents::on_plan_changed( 42 );
		LicenseEvents::site_activated( 42, 'staging' );
		$this->assertSame( array(), $this->fired(), 'Nothing fires before the commit.' );
		$this->assertSame( 2, AfterCommit::pending() );

		AfterCommit::commit();
		$this->assertSame( array( LicenseEvents::LICENSE_PLAN_CHANGED, LicenseEvents::SITE_ACTIVATED ), array_column( $this->fired(), 'event' ) );
		AfterCommit::flush();
		$this->assertCount( 2, $this->fired(), 'Each event fires once.' );
	}

	public function test_events_raised_in_a_rolled_back_transaction_never_fire(): void {
		AfterCommit::hold();
		LicenseEvents::site_activated( 42, 'production' ); // Queued under the lock, not in the transaction.
		AfterCommit::release();
		$this->assertCount( 1, $this->fired() );

		AfterCommit::begin();
		LicenseEvents::on_status_changed( 42, 'active', 'active', 'renewal_succeeded' );
		AfterCommit::rollback();
		AfterCommit::on_shutdown();

		$this->assertSame( array( LicenseEvents::SITE_ACTIVATED ), array_column( $this->fired(), 'event' ) );
		$this->assertSame( 0, AfterCommit::pending() );
	}

	public function test_events_raised_under_an_activation_lock_fire_after_its_release(): void {
		AfterCommit::hold();
		LicenseEvents::site_deactivated( 42, 'production', 'admin_site_released', 'Moved hosting', 1 );
		LicenseEvents::site_deactivated( 42, 'staging', 'admin_site_released', 'Moved hosting', 1 );
		$this->assertSame( array(), $this->fired() );

		AfterCommit::release();
		$this->assertSame( array( 'support', 'support' ), array_column( $this->fired(), 'initiator' ) );
		$this->assertSame( array( 'live', 'staging' ), array_column( $this->fired(), 'environment' ) );
	}

	public function test_a_transaction_left_open_at_shutdown_drops_its_events_only(): void {
		AfterCommit::hold();
		LicenseEvents::site_activated( 42, 'production' );
		AfterCommit::begin();
		LicenseEvents::on_plan_changed( 42 );
		AfterCommit::on_shutdown();

		$this->assertSame( array( LicenseEvents::SITE_ACTIVATED ), array_column( $this->fired(), 'event' ) );
	}

	public function test_initiator_and_environment_names(): void {
		$this->assertSame( 'support', LicenseEvents::initiator( 'admin_site_released', 'Reason', 1 ) );
		$this->assertSame( 'system', LicenseEvents::initiator( 'site_deactivated_license_ended', 'License ended', 0 ) );
		$this->assertSame( 'api', LicenseEvents::initiator( 'site_replaced', AuditReason::INSTALLATION_REPLACED, 0 ) );
		$this->assertSame( 'api', LicenseEvents::initiator( 'site_deactivated', AuditReason::DEACTIVATED_BY_INSTALLATION, 0 ) );
		$this->assertSame( 'customer', LicenseEvents::initiator( 'site_deactivated', AuditReason::DEACTIVATED_BY_OWNER, 7 ) );
		$this->assertSame( 'support', LicenseEvents::initiator( 'site_deactivated', 'wp-cli', 1 ) );
		$this->assertSame( 'system', LicenseEvents::initiator( 'site_deactivated', 'Cleanup', 0 ) );
		foreach ( array( 'admin_site_released', 'site_deactivated_license_ended', 'site_replaced', 'site_deactivated' ) as $action ) {
			$this->assertContains( LicenseEvents::initiator( $action, '', 0 ), LicenseEvents::INITIATORS );
		}
		$this->assertSame( array( 'live', 'staging', 'development' ), array_map( array( LicenseEvents::class, 'environment' ), array( 'production', 'staging', 'development' ) ) );
	}

	public function test_non_scalar_fields_are_dropped_from_the_envelope(): void {
		$envelope = LicenseEvents::envelope( LicenseEvents::LICENSE_PLAN_CHANGED, array( 'license' => 'WPLC-1A2B3C4D', 'nested' => array( 'key' => 'x' ), 'object' => new stdClass(), 'event' => 'spoofed' ) );

		$this->assertArrayNotHasKey( 'nested', $envelope );
		$this->assertArrayNotHasKey( 'object', $envelope );
		$this->assertSame( LicenseEvents::LICENSE_PLAN_CHANGED, $envelope['event'], 'Envelope fields cannot be overwritten.' );
	}
}
