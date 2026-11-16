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

	/**
	 * A-91: version and minimum statements agree across header, readme.txt, README.md and the CI matrix.
	 */
	public function test_a91_declared_versions_agree_across_files(): void {
		$root   = dirname( __DIR__, 2 );
		$header = (string) file_get_contents( $root . '/wp-license.php' );
		$readme = (string) file_get_contents( $root . '/readme.txt' );
		$guide  = (string) file_get_contents( $root . '/README.md' );
		$ci     = (string) file_get_contents( $root . '/.github/workflows/phpunit.yml' );
		$schema = (string) file_get_contents( $root . '/src/Infrastructure/Database/Schema.php' );

		$this->assertSame( 1, preg_match( '/^ \* Version: ([0-9.]+)$/m', $header, $version ) );
		$this->assertSame( 1, preg_match( "/define\\( 'WPLC_VERSION', '([0-9.]+)' \\)/", $header, $constant ) );
		$this->assertSame( $version[1], $constant[1] );
		$this->assertStringContainsString( "Stable tag: {$version[1]}\n", $readme );
		$this->assertStringContainsString( "= {$version[1]} =", $readme, 'readme.txt changelog has an entry for the current version.' );

		$this->assertSame( 1, preg_match( '/^ \* Requires at least: ([0-9.]+)$/m', $header, $wp ) );
		$this->assertSame( 1, preg_match( '/^ \* Requires PHP: ([0-9.]+)$/m', $header, $php ) );
		$wc = implode( '.', array_slice( explode( '.', Requirements::MIN_WC ), 0, 2 ) );
		$this->assertStringContainsString( "Requires at least: {$wp[1]}\n", $readme );
		$this->assertStringContainsString( "Requires PHP: {$php[1]}\n", $readme );
		foreach ( array( "WordPress {$wp[1]} or later", "PHP {$php[1]} or later", "WooCommerce {$wc} or later" ) as $line ) {
			$this->assertStringContainsString( '* ' . $line, $readme );
			$this->assertStringContainsString( '- ' . $line, $guide );
		}

		// The integration matrix runs the declared minimums together.
		$cell = '/\{ wp: "' . preg_quote( $wp[1], '/' ) . '", wc: "' . preg_quote( $wc, '/' ) . '\.[0-9]+", php: "' . preg_quote( $php[1], '/' ) . '"/';
		$this->assertSame( 1, preg_match( $cell, $ci ), 'CI has no matrix cell for the declared minimums.' );

		$this->assertSame( 1, preg_match( "/const VERSION = '([0-9]+)';/", $schema, $schema_version ) );
		$this->assertStringContainsString( "Database schema {$schema_version[1]}.", $readme, 'readme.txt changelog names the current schema number.' );
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
