<?php
use PHPUnit\Framework\TestCase;
use WPLC\Compatibility\Requirements;

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

final class RequirementsTest extends TestCase {

	public function test_version_at_least_compares_numerically(): void {
		$this->assertTrue( Requirements::version_at_least( '8.9.0', '8.9.0' ) );
		$this->assertTrue( Requirements::version_at_least( '10.0.1', '8.9.0' ) );
		$this->assertTrue( Requirements::version_at_least( '8.10.0', '8.9.0' ) );
		$this->assertFalse( Requirements::version_at_least( '8.8.5', '8.9.0' ) );
		$this->assertFalse( Requirements::version_at_least( '7.9.0', '8.9.0' ) );
	}

	public function test_prerelease_suffix_of_running_version_is_ignored(): void {
		$this->assertTrue( Requirements::version_at_least( '9.9.0-rc.1', '9.9.0' ) );
		$this->assertTrue( Requirements::version_at_least( '9.9.0-dev', '9.9.0' ) );
		$this->assertTrue( Requirements::version_at_least( '11.2.2+build5', '11.2.2' ) );
		$this->assertFalse( Requirements::version_at_least( '9.8.5-rc.1', '9.9.0' ) );
	}

	public function test_unknown_version_never_satisfies_a_minimum(): void {
		$this->assertFalse( Requirements::version_at_least( '', '8.9.0' ) );
		$this->assertFalse( Requirements::version_at_least( 'trunk', '8.9.0' ) );
	}

	public function test_declared_minimum_matches_plugin_header(): void {
		$header = (string) file_get_contents( dirname( __DIR__, 2 ) . '/wp-license.php' );
		$this->assertSame( 1, preg_match( '/^ \* WC requires at least: ([0-9.]+)$/m', $header, $match ) );
		$this->assertSame( 0, version_compare( $match[1] . '.0', Requirements::MIN_WC ) );
	}

	public function test_unique_argument_is_only_passed_when_it_compares_args(): void {
		$this->assertSame( 'native', Requirements::unique_mode( '4.0.0' ) );
		$this->assertSame( 'native', Requirements::unique_mode( '4.1.0' ) );
		// 3.6.0 to 3.9.x accept $unique but compare hook and group only.
		$this->assertSame( 'emulated', Requirements::unique_mode( '3.9.3' ) );
		$this->assertSame( 'emulated', Requirements::unique_mode( '3.6.0' ) );
		$this->assertSame( 'emulated', Requirements::unique_mode( '3.5.4' ) );
		$this->assertSame( 'emulated', Requirements::unique_mode( '' ) );
	}

	public function test_stripe_gateway_absent(): void {
		$status = Requirements::evaluate_stripe( '', array( 'WC_Stripe_API' ) );
		$this->assertFalse( $status['ok'] );
		$this->assertNotSame( '', $status['reason'] );
	}

	public function test_stripe_gateway_below_verified_minimum(): void {
		$status = Requirements::evaluate_stripe( '9.5.2', array() );
		$this->assertFalse( $status['ok'] );
		$this->assertStringContainsString( '9.5.2', $status['reason'] );
		$this->assertStringContainsString( Requirements::STRIPE_MIN_VERSION, $status['reason'] );
	}

	public function test_stripe_gateway_with_missing_internals(): void {
		$status = Requirements::evaluate_stripe( '11.2.0', array( 'WC_Stripe_API::request' ) );
		$this->assertFalse( $status['ok'] );
		$this->assertStringContainsString( 'WC_Stripe_API::request', $status['reason'] );
	}

	public function test_stripe_gateway_in_verified_range(): void {
		foreach ( array( '9.6.0', '10.4.1', '11.2.0' ) as $version ) {
			$this->assertSame(
				array(
					'ok'     => true,
					'reason' => '',
				),
				Requirements::evaluate_stripe( $version, array() ),
				$version
			);
		}
	}

	public function test_newer_stripe_gateway_stays_enabled_with_a_hint(): void {
		$status = Requirements::evaluate_stripe( '11.3.0', array() );
		$this->assertTrue( $status['ok'] );
		$this->assertStringContainsString( '11.3.0', $status['reason'] );
	}
}
