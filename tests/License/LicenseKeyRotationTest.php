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
}
