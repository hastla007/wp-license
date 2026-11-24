<?php
use PHPUnit\Framework\TestCase;
use WPLC\Security\SecretCrypto;

final class SecretCryptoTest extends TestCase {
	private const UUID = '0b3c9f5e-6a51-4d1e-9a7c-2f6d8e4b1a00';

	/**
	 * A v1 envelope as versions before UEB-12 wrote it (bound to the key ID only).
	 */
	private static function v1_envelope( string $plaintext, string $key_id, string $key ): string {
		$nonce      = random_bytes( 12 );
		$tag        = '';
		$ciphertext = openssl_encrypt( $plaintext, 'aes-256-gcm', hash( 'sha256', $key, true ), OPENSSL_RAW_DATA, $nonce, $tag, 'wplc-secret-v1:' . $key_id, 16 );

		return 'v1:' . $key_id . ':' . base64_encode( $nonce . $tag . $ciphertext );
	}

	public function test_encrypts_and_decrypts_a_versioned_current_key_envelope(): void {
		$encrypted = SecretCrypto::encrypt( 'installation secret', SecretCrypto::PURPOSE_INSTALLATION, self::UUID );

		$this->assertIsString( $encrypted );
		$this->assertStringStartsWith( 'v2:test-2026:', $encrypted );
		$this->assertSame( 'installation secret', SecretCrypto::decrypt( $encrypted, SecretCrypto::PURPOSE_INSTALLATION, self::UUID ) );
		$this->assertTrue( SecretCrypto::is_envelope( $encrypted ) );
		$this->assertFalse( SecretCrypto::is_legacy( $encrypted ) );
		$this->assertSame( 'v2:test-2026:', SecretCrypto::current_prefix() );
	}

	public function test_a_v2_envelope_is_bound_to_its_purpose_and_its_row(): void {
		$encrypted = SecretCrypto::encrypt( 'installation secret', SecretCrypto::PURPOSE_INSTALLATION, self::UUID );
		$this->assertIsString( $encrypted );

		// Copied into another row, or read as another kind of secret: no longer decrypts.
		$this->assertFalse( SecretCrypto::decrypt( $encrypted, SecretCrypto::PURPOSE_INSTALLATION, 'ffffffff-6a51-4d1e-9a7c-2f6d8e4b1a00' ) );
		$this->assertFalse( SecretCrypto::decrypt( $encrypted, SecretCrypto::PURPOSE_WEBHOOK, self::UUID ) );
		$this->assertFalse( SecretCrypto::decrypt( $encrypted, SecretCrypto::PURPOSE_INSTALLATION, '' ) );
		// Fields are length-prefixed: shifting characters between purpose and context changes the data.
		$this->assertFalse( SecretCrypto::decrypt( $encrypted, SecretCrypto::PURPOSE_INSTALLATION . ':' . substr( self::UUID, 0, 4 ), substr( self::UUID, 4 ) ) );
		// A v2 envelope relabelled as v1 does not fall back to the legacy path.
		$this->assertFalse( SecretCrypto::decrypt( 'v1:' . substr( $encrypted, 3 ), SecretCrypto::PURPOSE_INSTALLATION, self::UUID ) );
		// No purpose, no envelope.
		$this->assertFalse( SecretCrypto::encrypt( 'installation secret', '', self::UUID ) );
	}

	public function test_a_legacy_v1_envelope_still_decrypts_and_is_marked_for_rotation(): void {
		$current  = self::v1_envelope( 'old installation secret', 'test-2026', 'test-current-encryption-key' );
		$previous = self::v1_envelope( 'previous installation secret', 'test-2025', 'test-previous-encryption-key' );

		// Legacy fallback: purpose and context are not part of a v1 envelope.
		$this->assertSame( 'old installation secret', SecretCrypto::decrypt( $current, SecretCrypto::PURPOSE_INSTALLATION, self::UUID ) );
		$this->assertSame( 'previous installation secret', SecretCrypto::decrypt( $previous, SecretCrypto::PURPOSE_INSTALLATION, self::UUID ) );
		$this->assertTrue( SecretCrypto::needs_rotation( $current ), 'A v1 envelope with the current key still moves to v2.' );
		$this->assertTrue( SecretCrypto::needs_rotation( $previous ) );
		$this->assertTrue( SecretCrypto::is_legacy( $current ) );
		$this->assertTrue( SecretCrypto::is_envelope( $current ) );

		// The upgrade: decrypt in the old format, encrypt in the new one, same secret.
		$upgraded = SecretCrypto::encrypt( (string) SecretCrypto::decrypt( $current, SecretCrypto::PURPOSE_INSTALLATION, self::UUID ), SecretCrypto::PURPOSE_INSTALLATION, self::UUID );
		$this->assertIsString( $upgraded );
		$this->assertFalse( SecretCrypto::needs_rotation( $upgraded ) );
		$this->assertSame( 'old installation secret', SecretCrypto::decrypt( $upgraded, SecretCrypto::PURPOSE_INSTALLATION, self::UUID ) );
	}

	public function test_rejects_unknown_key_ids_and_modified_ciphertext(): void {
		$encrypted = SecretCrypto::encrypt( 'installation secret', SecretCrypto::PURPOSE_INSTALLATION, self::UUID );
		$this->assertIsString( $encrypted );
		$unknown_key = str_replace( 'v2:test-2026:', 'v2:missing-key:', $encrypted );
		$this->assertFalse( SecretCrypto::decrypt( $unknown_key, SecretCrypto::PURPOSE_INSTALLATION, self::UUID ) );

		$last     = substr( $encrypted, -1 );
		$modified = substr( $encrypted, 0, -1 ) . ( 'A' === $last ? 'B' : 'A' );
		$this->assertFalse( SecretCrypto::decrypt( $modified, SecretCrypto::PURPOSE_INSTALLATION, self::UUID ) );
	}

	public function test_current_key_envelopes_do_not_need_rotation_and_previous_key_envelopes_do(): void {
		$current = SecretCrypto::encrypt( 'current installation secret', SecretCrypto::PURPOSE_INSTALLATION, self::UUID );
		$this->assertIsString( $current );
		$this->assertFalse( SecretCrypto::needs_rotation( $current ) );

		$this->assertTrue( SecretCrypto::needs_rotation( 'v2:test-2025:' . substr( $current, strlen( 'v2:test-2026:' ) ) ) );
	}

	public function test_legacy_envelopes_are_marked_for_rotation(): void {
		$this->assertTrue( SecretCrypto::needs_rotation( base64_encode( str_repeat( 'x', 40 ) ) ) );
		$this->assertTrue( SecretCrypto::is_legacy( base64_encode( str_repeat( 'x', 40 ) ) ) );
		$this->assertFalse( SecretCrypto::is_envelope( base64_encode( str_repeat( 'x', 40 ) ) ) );
	}
}
