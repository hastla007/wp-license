<?php
/**
 * Pure rules of the staging guard, the gap flag and the job runner (B-01, B-14, B-34).
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\Maintenance\DailyRunner;
use WPLC\Maintenance\GapGuard;
use WPLC\Maintenance\SiteGuard;

final class SiteGuardTest extends TestCase {

	// B-01: the fingerprint is host and path, database name and table prefix.
	public function test_home_is_reduced_to_host_and_path(): void {
		$this->assertSame( 'shop.example', SiteGuard::normalize_home( 'https://shop.example' ) );
		$this->assertSame( 'shop.example', SiteGuard::normalize_home( 'http://www.Shop.Example:8443/' ) );
		$this->assertSame( 'shop.example/store', SiteGuard::normalize_home( 'https://shop.example/store/' ) );
		$this->assertSame( 'shop.example/store', SiteGuard::normalize_home( 'https://shop.example/store/?x=1#y' ) );
		$this->assertSame( 'staging.shop.example', SiteGuard::normalize_home( 'https://staging.shop.example' ) );
		$this->assertSame( '', SiteGuard::normalize_home( '' ) );
	}

	public function test_fingerprint_changes_with_host_path_database_or_prefix(): void {
		$live = SiteGuard::fingerprint( 'https://shop.example', 'shop', 'wp_' );

		$this->assertSame( $live['hash'], SiteGuard::fingerprint( 'http://www.shop.example/', 'shop', 'wp_' )['hash'], 'Scheme and www. are the same shop.' );
		$this->assertNotSame( $live['hash'], SiteGuard::fingerprint( 'https://staging.shop.example', 'shop', 'wp_' )['hash'] );
		$this->assertNotSame( $live['hash'], SiteGuard::fingerprint( 'https://shop.example/staging', 'shop', 'wp_' )['hash'] );
		$this->assertNotSame( $live['hash'], SiteGuard::fingerprint( 'https://shop.example', 'shop_staging', 'wp_' )['hash'] );
		$this->assertNotSame( $live['hash'], SiteGuard::fingerprint( 'https://shop.example', 'shop', 'stg_' )['hash'] );
		$this->assertSame( array( 'home', 'db', 'prefix', 'hash' ), array_keys( $live ) );
	}

	// B-01: constant, fingerprint and environment type pause billing; the first match is the reason.
	public function test_pause_decision(): void {
		$this->assertSame( '', SiteGuard::decide( null, false, 'a', 'a', 'production' ) );
		$this->assertSame( '', SiteGuard::decide( null, false, '', 'a', 'production' ), 'Nothing is stored before the first run.' );
		$this->assertSame( 'fingerprint', SiteGuard::decide( null, false, 'a', 'b', 'production' ) );
		$this->assertSame( 'environment', SiteGuard::decide( null, false, 'a', 'a', 'staging' ) );
		$this->assertSame( 'environment', SiteGuard::decide( null, false, 'a', 'a', 'local' ) );
		$this->assertSame( 'constant', SiteGuard::decide( true, false, 'a', 'a', 'production' ) );
		$this->assertSame( 'constant', SiteGuard::decide( true, true, 'a', 'b', 'staging' ) );
		$this->assertSame( 'schema_downgrade', SiteGuard::decide( null, true, 'a', 'a', 'production' ) );
	}

	public function test_constant_false_only_releases_the_environment_brake(): void {
		$this->assertSame( '', SiteGuard::decide( false, false, 'a', 'a', 'staging' ) );
		$this->assertSame( 'fingerprint', SiteGuard::decide( false, false, 'a', 'b', 'staging' ), 'A copy stays paused until an administrator confirms it.' );
		$this->assertSame( 'schema_downgrade', SiteGuard::decide( false, true, 'a', 'a', 'production' ) );
	}

	// B-14: the hold after a gap lasts as long as the gap, at least a day, at most the configured maximum.
	public function test_gap_hold_scales_with_the_length_of_the_gap(): void {
		$day = 86400;
		$to  = 1800000000;

		$this->assertSame( $to + $day, GapGuard::hold_until( $to - 7200, $to, 15 * $day ) );
		$this->assertSame( $to + 5 * $day, GapGuard::hold_until( $to - 5 * $day, $to, 15 * $day ) );
		$this->assertSame( $to + 15 * $day, GapGuard::hold_until( $to - 40 * $day, $to, 15 * $day ) );
		$this->assertSame( $to, GapGuard::hold_until( $to - 40 * $day, $to, 0 ) );
	}

	// B-34/B-35: job names for logs and heartbeats.
	public function test_job_names(): void {
		$this->assertSame( 'license_lifecycle_expire_due_licenses', DailyRunner::job_name( array( 'WPLC\\License\\LicenseLifecycle', 'expire_due_licenses' ) ) );
		$this->assertSame( 'retention_run', DailyRunner::job_name( 'WPLC\\Maintenance\\Retention::run' ) );
		$this->assertSame( 'package_stream_cleanup', DailyRunner::job_name( array( 'WPLC\\Update\\PackageStream', 'cleanup' ) ) );
		$this->assertSame( 'my_function', DailyRunner::job_name( 'my_function' ) );
		$this->assertSame( 'closure', DailyRunner::job_name( static function () {} ) );
	}
}
