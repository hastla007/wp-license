<?php
use PHPUnit\Framework\TestCase;
use WPLC\Security\SecretCrypto;

final class SecretCryptoTest extends TestCase {
	public function test_encrypts_and_decrypts_a_versioned_current_key_envelope(): void {
		$encrypted = SecretCrypto::encrypt( 'installation secret' );

		$this->assertIsString( $encrypted );
		$this->assertStringStartsWith( 'v1:test-2026:', $encrypted );
		$this->assertSame( 'installation secret', SecretCrypto::decrypt( $encrypted ) );
	}

	public function test_decrypts_an_envelope_using_a_retained_previous_key(): void {
		$nonce = random_bytes( 12 );
		$tag = '';
		$ciphertext = openssl_encrypt(
			'previous installation secret',
			'aes-256-gcm',
			hash( 'sha256', 'test-previous-encryption-key', true ),
			OPENSSL_RAW_DATA,
			$nonce,
			$tag,
			'wplc-secret-v1:test-2025',
			16
		);
		$encrypted = 'v1:test-2025:' . base64_encode( $nonce . $tag . $ciphertext );

		$this->assertSame( 'previous installation secret', SecretCrypto::decrypt( $encrypted ) );
	}

	public function test_rejects_unknown_key_ids_and_modified_ciphertext(): void {
		$encrypted = SecretCrypto::encrypt( 'installation secret' );
		$unknown_key = str_replace( 'v1:test-2026:', 'v1:missing-key:', $encrypted );
		$this->assertFalse( SecretCrypto::decrypt( $unknown_key ) );

		$last = substr( $encrypted, -1 );
		$modified = substr( $encrypted, 0, -1 ) . ( 'A' === $last ? 'B' : 'A' );
		$this->assertFalse( SecretCrypto::decrypt( $modified ) );
	}

	public function test_current_key_envelopes_do_not_need_rotation_and_previous_key_envelopes_do(): void {
		$current = SecretCrypto::encrypt( 'current installation secret' );
		$this->assertIsString( $current );
		$this->assertFalse( SecretCrypto::needs_rotation( $current ) );

		$nonce = random_bytes( 12 );
		$tag = '';
		$ciphertext = openssl_encrypt(
			'previous installation secret',
			'aes-256-gcm',
			hash( 'sha256', 'test-previous-encryption-key', true ),
			OPENSSL_RAW_DATA,
			$nonce,
			$tag,
			'wplc-secret-v1:test-2025',
			16
		);
		$previous = 'v1:test-2025:' . base64_encode( $nonce . $tag . $ciphertext );
		$this->assertTrue( SecretCrypto::needs_rotation( $previous ) );
	}

	public function test_legacy_envelopes_are_marked_for_rotation(): void {
		$this->assertTrue( SecretCrypto::needs_rotation( base64_encode( str_repeat( 'x', 40 ) ) ) );
	}
}
