<?php
use PHPUnit\Framework\TestCase;
use WPLC\License\LicenseKey;

final class LicenseKeyRotationTest extends TestCase {
	public function test_keys_are_derived_with_their_persistable_key_id(): void {
		$uuid = '12345678-1234-4234-8234-123456789abc';

		$this->assertSame( 'test-2026', LicenseKey::current_key_id() );
		$this->assertNotSame( LicenseKey::for_uuid( $uuid, 'test-2026' ), LicenseKey::for_uuid( $uuid, 'test-2025' ) );
		$this->assertSame( LicenseKey::for_uuid( $uuid, 'test-2025' ), LicenseKey::for_uuid( $uuid, 'test-2025' ) );
		$this->assertSame( '', LicenseKey::for_uuid( $uuid, 'missing-key' ) );
	}

	public function test_key_id_recovery_verifies_against_the_stored_license_hash(): void {
		$uuid = '12345678-1234-4234-8234-123456789abc';
		$previous_key = LicenseKey::for_uuid( $uuid, 'test-2025' );

		$this->assertTrue( LicenseKey::matches_hash( $uuid, 'test-2025', LicenseKey::hash( $previous_key ) ) );
		$this->assertFalse( LicenseKey::matches_hash( $uuid, 'test-2026', LicenseKey::hash( $previous_key ) ) );
		$this->assertFalse( LicenseKey::matches_hash( $uuid, 'missing-key', LicenseKey::hash( $previous_key ) ) );
	}
}
