<?php
/**
 * WFC-LIC-04 / WFC-LIC-07: the license actions of the hook contract (scalar IDs only, no key,
 * no site address, no email), the contract constant and the "after commit" queue.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\Events\AfterCommit;
use WPLC\Events\LicenseEvents;
use WPLC\License\AuditReason;

require_once dirname( __DIR__ ) . '/Client/client-test-kit.php';

final class LicenseEventsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['wplc_test_actions'] = array();
		AfterCommit::reset();
	}

	protected function tearDown(): void {
		$GLOBALS['wplc_test_actions'] = array();
		AfterCommit::reset();
	}

	/**
	 * Recorded calls of the contract actions (name, then arguments).
	 *
	 * @return array<int,array<int,mixed>>
	 */
	private function fired(): array {
		return array_values(
			array_filter(
				$GLOBALS['wplc_test_actions'],
				static function ( $call ) {
					return in_array( $call[0], array( 'wplc/activation_created', 'wplc/activation_removed', 'wplc/site_limit_reached', 'wplc/release_published', 'wplc/release_withdrawn' ), true );
				}
			)
		);
	}

	private function release(): array {
		return array(
			'id' => 3,
			'product_key' => 'acme-reports-pro',
			'version' => '2.1.0',
			'channel' => 'stable',
			'package_url' => 'https://downloads.example.com/secret.zip?token=abc',
			'sha256' => str_repeat( 'a', 64 ),
			'release_notes' => 'Contact support@example.com',
		);
	}

	public function test_contract_constant_follows_the_version_constant_prefix(): void {
		$plugin = (string) file_get_contents( dirname( __DIR__, 2 ) . '/wp-license.php' );
		$this->assertSame( 1, preg_match( "/define\\( 'WPLC_VERSION',/", $plugin ) );
		$this->assertSame( 1, preg_match( "/define\\( 'WPLC_CONTRACT_VERSION', '([^']+)' \\);/", $plugin, $match ), 'R-3.1: <PREFIX>_CONTRACT_VERSION with the prefix of WPLC_VERSION.' );
		$this->assertSame( LicenseEvents::CONTRACT_VERSION, $match[1] );
		$this->assertSame( '1.0.0', $match[1] );
		$this->assertStringNotContainsString( 'WPLC_EVENT_CONTRACT_VERSION', $plugin );
	}

	public function test_every_new_action_passes_scalar_ids_only(): void {
		LicenseEvents::activation_created( 42, 7, 'production' );
		LicenseEvents::activation_removed( 42, 8, 'staging', 'site_deactivated', AuditReason::DEACTIVATED_BY_OWNER, 5 );
		LicenseEvents::site_limit_reached( 42, 'production', 5 );
		LicenseEvents::release_published( $this->release() );
		LicenseEvents::release_withdrawn( $this->release() );

		$this->assertSame(
			array(
				array( 'wplc/activation_created', 42, 7, 'live' ),
				array( 'wplc/activation_removed', 42, 8, 'staging', 'customer' ),
				array( 'wplc/site_limit_reached', 42, 'live', 5 ),
				array( 'wplc/release_published', 3, 'acme-reports-pro', '2.1.0', 'stable' ),
				array( 'wplc/release_withdrawn', 3, 'acme-reports-pro', '2.1.0', 'stable' ),
			),
			$this->fired()
		);
		foreach ( $this->fired() as $call ) {
			foreach ( array_slice( $call, 1 ) as $argument ) {
				$this->assertTrue( is_int( $argument ) || is_string( $argument ), 'Scalar arguments only.' );
			}
		}
		$json = (string) json_encode( $this->fired() );
		foreach ( array( '@', 'token=abc', 'https://', str_repeat( 'a', 64 ), 'WPLC-' ) as $forbidden ) {
			$this->assertStringNotContainsString( $forbidden, $json );
		}
	}

	public function test_actions_raised_in_a_transaction_fire_after_the_commit_once(): void {
		AfterCommit::begin();
		LicenseEvents::activation_created( 42, 7, 'staging' );
		LicenseEvents::release_published( $this->release() );
		$this->assertSame( array(), $this->fired(), 'Nothing fires before the commit.' );
		$this->assertSame( 2, AfterCommit::pending() );

		AfterCommit::commit();
		$this->assertSame( array( 'wplc/activation_created', 'wplc/release_published' ), array_column( $this->fired(), 0 ) );
		AfterCommit::flush();
		$this->assertCount( 2, $this->fired(), 'Each action fires once.' );
	}

	public function test_actions_raised_in_a_rolled_back_transaction_never_fire(): void {
		AfterCommit::hold();
		LicenseEvents::activation_created( 42, 7, 'production' ); // Queued under the lock, not in the transaction.
		AfterCommit::release();
		$this->assertCount( 1, $this->fired() );

		AfterCommit::begin();
		LicenseEvents::site_limit_reached( 42, 'production', 1 );
		AfterCommit::rollback();
		AfterCommit::on_shutdown();

		$this->assertSame( array( 'wplc/activation_created' ), array_column( $this->fired(), 0 ) );
		$this->assertSame( 0, AfterCommit::pending() );
	}

	public function test_actions_raised_under_an_activation_lock_fire_after_its_release(): void {
		AfterCommit::hold();
		LicenseEvents::activation_removed( 42, 7, 'production', 'admin_site_released', 'Moved hosting', 1 );
		LicenseEvents::activation_removed( 42, 8, 'staging', 'admin_site_released', 'Moved hosting', 1 );
		$this->assertSame( array(), $this->fired() );

		AfterCommit::release();
		$this->assertSame( array( array( 'wplc/activation_removed', 42, 7, 'live', 'support' ), array( 'wplc/activation_removed', 42, 8, 'staging', 'support' ) ), $this->fired() );
		$this->assertStringNotContainsString( 'Moved hosting', (string) json_encode( $this->fired() ), 'The reason text is not passed on.' );
	}

	public function test_a_transaction_left_open_at_shutdown_drops_its_actions_only(): void {
		AfterCommit::hold();
		LicenseEvents::activation_created( 42, 7, 'production' );
		AfterCommit::begin();
		LicenseEvents::release_withdrawn( $this->release() );
		AfterCommit::on_shutdown();

		$this->assertSame( array( 'wplc/activation_created' ), array_column( $this->fired(), 0 ) );
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
		$this->assertSame( array( 'live', 'staging', 'development', 'live' ), array_map( array( LicenseEvents::class, 'environment' ), array( 'production', 'staging', 'development', 'odd' ) ) );
	}

	public function test_no_envelope_and_no_event_id_machinery_is_left(): void {
		$methods = array_map(
			static function ( ReflectionMethod $method ) {
				return $method->getName();
			},
			( new ReflectionClass( LicenseEvents::class ) )->getMethods( ReflectionMethod::IS_PUBLIC )
		);
		sort( $methods );
		$this->assertSame( array( 'activation_created', 'activation_removed', 'contract_version', 'environment', 'initiator', 'release_published', 'release_withdrawn', 'site_limit_reached' ), $methods );
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Events/LicenseEvents.php' );
		$this->assertStringNotContainsString( "'wplc/event'", $source );
		$this->assertStringNotContainsString( 'event_id', $source );
	}
}
