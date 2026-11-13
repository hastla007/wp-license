<?php
use PHPUnit\Framework\TestCase;
use WPLC\License\LicenseKey;

final class LicenseKeyTest extends TestCase {
	public function test_license_key_is_deterministic_and_uses_expected_format(): void {
		$uuid = '12345678-1234-4234-8234-123456789abc';
		$key = LicenseKey::for_uuid( $uuid );

		$this->assertSame( $key, LicenseKey::for_uuid( $uuid ) );
		$this->assertMatchesRegularExpression( '/^WPLC-[0-9A-F-]+-[0-9A-F]{64}$/', $key );
	}

	public function test_hash_normalizes_whitespace_and_case(): void {
		$key = LicenseKey::for_uuid( '12345678-1234-4234-8234-123456789abc' );

		$this->assertSame( LicenseKey::hash( $key ), LicenseKey::hash( '  ' . strtolower( $key ) . '  ' ) );
	}

	public function test_prefix_contains_only_non_secret_uuid_prefix(): void {
		$prefix = LicenseKey::prefix( '12345678-1234-4234-8234-123456789abc' );

		$this->assertSame( 'WPLC-12345678', $prefix );
		$this->assertStringNotContainsString( '123456789ABC', $prefix );
	}
}
