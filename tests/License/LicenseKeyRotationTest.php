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

	/** T-86: a wrong stored key ID must not show the customer a different (invalid) key. */
	public function test_displayed_key_requires_a_matching_stored_verifier(): void {
		$uuid = '12345678-1234-4234-8234-123456789abc';
		$hash = LicenseKey::hash( LicenseKey::for_uuid( $uuid, 'test-2025' ) );

		$this->assertSame( LicenseKey::for_uuid( $uuid, 'test-2025' ), LicenseKey::verified_for_license( $uuid, 'test-2025', $hash ) );
		$this->assertSame( '', LicenseKey::verified_for_license( $uuid, 'test-2026', $hash ), 'wrong but configured key ID' );
		$this->assertSame( '', LicenseKey::verified_for_license( $uuid, 'missing-key', $hash ), 'key ID not in the ring' );
		$this->assertSame( '', LicenseKey::verified_for_license( $uuid, 'test-2025', '' ), 'no verifier' );
	}

	public function test_configured_key_ids(): void {
		$this->assertTrue( LicenseKey::is_configured( 'test-2026' ) );
		$this->assertTrue( LicenseKey::is_configured( 'test-2025' ) );
		$this->assertFalse( LicenseKey::is_configured( 'test-2024' ) );
		$this->assertFalse( LicenseKey::is_configured( '' ) );
	}
}
