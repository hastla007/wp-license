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
		$declared = 2 === substr_count( $match[1], '.' ) ? $match[1] : $match[1] . '.0';
		$this->assertSame( 0, version_compare( $declared, Requirements::MIN_WC ) );
		$this->assertSame( 1, preg_match( '/^ \* Requires at least: ([0-9.]+)$/m', $header, $wp ) );
		$this->assertSame( Requirements::MIN_WP, $wp[1] );
	}

	/**
	 * Owner decision of 17 Nov 2026: WordPress 7.0 and WooCommerce 11.2 for every Captain plugin; 11.2.2 because 11.2.0 carried data of an earlier Store API checkout into a later one in the same PHP process.
	 */
	public function test_minimums_follow_the_owner_decision_of_17_nov_2026(): void {
		$this->assertSame( '7.0', Requirements::MIN_WP );
		$this->assertSame( '11.2.2', Requirements::MIN_WC );
	}

	public function test_wordpress_version_check(): void {
		$had      = array_key_exists( 'wp_version', $GLOBALS );
		$previous = $had ? $GLOBALS['wp_version'] : null;
		try {
			$GLOBALS['wp_version'] = '6.9.1';
			$this->assertFalse( Requirements::wordpress_is_supported() );
			$GLOBALS['wp_version'] = '7.0';
			$this->assertTrue( Requirements::wordpress_is_supported() );
			$GLOBALS['wp_version'] = '7.1.4';
			$this->assertTrue( Requirements::wordpress_is_supported() );
			$GLOBALS['wp_version'] = '7.0-RC1-61234';
			$this->assertTrue( Requirements::wordpress_is_supported() );
			unset( $GLOBALS['wp_version'] );
			$this->assertTrue( Requirements::wordpress_is_supported(), 'Outside WordPress the version is unknown and nothing is reported.' );
		} finally {
			if ( $had ) {
				$GLOBALS['wp_version'] = $previous;
			} else {
				unset( $GLOBALS['wp_version'] );
			}
		}
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
		$wc = (string) preg_replace( '/\.0$/', '', Requirements::MIN_WC );
		$this->assertStringContainsString( "Requires at least: {$wp[1]}\n", $readme );
		$this->assertStringContainsString( "Requires PHP: {$php[1]}\n", $readme );
		foreach ( array( "WordPress {$wp[1]} or later", "PHP {$php[1]} or later", "WooCommerce {$wc} or later" ) as $line ) {
			$this->assertStringContainsString( '* ' . $line, $readme );
			$this->assertStringContainsString( '- ' . $line, $guide );
		}

		// The integration matrix runs the declared minimums together.
		$wc_cell = 2 === substr_count( $wc, '.' ) ? preg_quote( $wc, '/' ) : preg_quote( $wc, '/' ) . '\.[0-9]+';
		$cell    = '/\{ wp: "' . preg_quote( $wp[1], '/' ) . '", wc: "' . $wc_cell . '", php: "' . preg_quote( $php[1], '/' ) . '"/';
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

	/**
	 * MINVER-1: every place that states the PHP minimum names Requirements::MIN_PHP.
	 */
	public function test_minver1_php_minimum_agrees_across_files(): void {
		$root = dirname( __DIR__, 2 );
		$read = static function ( string $file ) use ( $root ): string {
			return (string) file_get_contents( $root . '/' . $file );
		};
		$min  = Requirements::MIN_PHP;

		$this->assertSame( '8.5', $min, 'Owner decision of 16 Nov 2026: PHP 8.5.' );
		$this->assertSame( array( '9.7', '12.3' ), array( Requirements::MIN_MYSQL, Requirements::MIN_MARIADB ) );

		$main = $read( 'wp-license.php' );
		$this->assertStringContainsString( " * Requires PHP: {$min}\n", $main );
		$this->assertStringContainsString( "if ( version_compare( PHP_VERSION, '{$min}', '<' )", $main, 'The guard in wp-license.php uses the minimum.' );
		$this->assertStringContainsString( "'{$min}',\n\t\t\t\tPHP_VERSION", $main, 'The notice in wp-license.php names the minimum.' );
		$this->assertStringContainsString( "Requires PHP: {$min}\n", $read( 'readme.txt' ) );

		$composer = json_decode( $read( 'composer.json' ), true );
		$this->assertSame( ">={$min}", $composer['require']['php'] ?? null );
		$this->assertSame( $min, $composer['config']['platform']['php'] ?? null, 'Dependencies are resolved for the minimum.' );

		foreach ( array( 'phpcs.xml.dist', 'phpcs.blocking.xml' ) as $file ) {
			$this->assertStringContainsString( '<config name="testVersion" value="' . $min . '-"/>', $read( $file ), $file );
		}
		list( $major, $minor ) = array_map( 'intval', explode( '.', $min ) );
		$this->assertStringContainsString( 'phpVersion: ' . ( $major * 10000 + $minor * 100 ), $read( 'phpstan.neon.dist' ) );

		// CI: unit tests start at the minimum; every integration cell runs it, except the regular
		// cell on the newer PHP 8.6 (UEB-07, owner decision of 24 Nov 2026).
		$ci = $read( '.github/workflows/phpunit.yml' );
		$this->assertStringContainsString( "php: [\"{$min}\", \"8.6\"]", $ci );
		$this->assertGreaterThan( 0, (int) preg_match_all( '/^          - \{ wp: [^\n]*\}$/m', $ci, $cells ) );
		foreach ( $cells[0] as $cell ) {
			if ( false === strpos( $cell, 'php: "8.6"' ) ) {
				$this->assertStringContainsString( "php: \"{$min}\"", $cell );
			}
		}
		$this->assertStringContainsString( "'mariadb:" . Requirements::MIN_MARIADB . "'", $ci );
		$this->assertStringContainsString( "'mysql:" . Requirements::MIN_MYSQL . "'", $ci );
		foreach ( array( 'release.yml', 'package.yml', 'quality.yml', 'e2e.yml' ) as $workflow ) {
			$text = $read( '.github/workflows/' . $workflow );
			$this->assertSame( 0, preg_match( '/php-version: "(?!' . preg_quote( $min, '/' ) . '")/', $text ), $workflow . ' runs another PHP version than the minimum.' );
		}
	}

	public function test_minver1_php_check_and_test_opt_out(): void {
		$this->assertTrue( Requirements::php_meets_minimum( '8.5.0' ) );
		$this->assertTrue( Requirements::php_meets_minimum( '8.6.0-dev' ) );
		$this->assertFalse( Requirements::php_meets_minimum( '8.4.15' ) );
		$this->assertFalse( Requirements::php_meets_minimum( '7.4.33' ) );
		// tests/bootstrap.php defines the test-only constant, so the suite also runs on an older PHP.
		$this->assertTrue( Requirements::php_minimum_ignored_for_tests() );
		$this->assertTrue( Requirements::php_is_supported() );
	}

	/**
	 * @dataProvider db_server_strings
	 */
	public function test_minver1_database_server_is_recognised( string $info, string $name, string $version, bool $ok ): void {
		$status = Requirements::database_status( $info );
		$this->assertTrue( $status['known'] );
		$this->assertSame( array( $name, $version, $ok ), array( $status['name'], $status['version'], $status['ok'] ) );
		$this->assertSame( $ok, '' === $status['message'] );
		if ( ! $ok ) {
			$this->assertStringContainsString( "{$name} {$version}", $status['message'] );
		}
	}

	/**
	 * @return array<string,array{0:string,1:string,2:string,3:bool}>
	 */
	public function db_server_strings(): array {
		return array(
			'MySQL 9.7'                   => array( '9.7.0', 'MySQL', '9.7.0', true ),
			'MySQL 9.8 innovation'        => array( '9.8.1', 'MySQL', '9.8.1', true ),
			'MySQL 8.4 LTS'               => array( '8.4.3', 'MySQL', '8.4.3', false ),
			'MySQL 8.0 with suffix'       => array( '8.0.40-0ubuntu0.24.04.1', 'MySQL', '8.0.40', false ),
			'MariaDB 12.3'                => array( '12.3.1-MariaDB-ubu2404', 'MariaDB', '12.3.1', true ),
			'MariaDB 12.3 old prefix'     => array( '5.5.5-12.3.2-MariaDB-log', 'MariaDB', '12.3.2', true ),
			'MariaDB 11.8 LTS'            => array( '11.8.3-MariaDB', 'MariaDB', '11.8.3', false ),
			'MariaDB 10.11 old prefix'    => array( '5.5.5-10.11.6-MariaDB-0+deb12u1', 'MariaDB', '10.11.6', false ),
			'MariaDB 10.6 lower case'     => array( '10.6.18-mariadb', 'MariaDB', '10.6.18', false ),
		);
	}

	public function test_minver1_unknown_database_server_is_not_a_warning(): void {
		$status = Requirements::database_status( 'unknown' );
		$this->assertSame( array( false, true, '' ), array( $status['known'], $status['ok'], $status['message'] ) );
		$this->assertNull( Requirements::parse_db_server( '' ) );
	}

	public function test_mariadb_snapshot_isolation_is_recognised_from_the_server_version(): void {
		$this->assertTrue( Requirements::mariadb_snapshot_isolation_applies( '12.3.3-MariaDB-ubu2404' ) );
		$this->assertTrue( Requirements::mariadb_snapshot_isolation_applies( '5.5.5-11.6.2-MariaDB' ) );
		$this->assertFalse( Requirements::mariadb_snapshot_isolation_applies( '11.6.1-MariaDB' ) );
		$this->assertFalse( Requirements::mariadb_snapshot_isolation_applies( '10.11.6-MariaDB' ) );
		$this->assertFalse( Requirements::mariadb_snapshot_isolation_applies( '9.7.0' ), 'MySQL keeps the old behaviour.' );
		$this->assertFalse( Requirements::mariadb_snapshot_isolation_applies( 'unknown' ) );
	}
}
