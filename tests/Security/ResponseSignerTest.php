<?php
use PHPUnit\Framework\TestCase;
use WPLC\Security\ResponseSigner;

final class ResponseSignerTest extends TestCase {
	private function keys(): array {
		$secret = ResponseSigner::secret_key_from( base64_encode( str_repeat( "\x01", 32 ) ) );
		return array( $secret, ResponseSigner::public_key_of( $secret ) );
	}

	public function test_a_signed_message_verifies_with_the_public_key(): void {
		list( $secret, $public ) = $this->keys();
		$message   = ResponseSigner::message( 'A1B2C3D4-0000-4000-8000-000000000001', '{"license_status":"active"}' );
		$signature = ResponseSigner::sign_with( $message, $secret );

		$this->assertTrue( ResponseSigner::verify( $message, $signature, $public ) );
		$this->assertSame( 16, strlen( ResponseSigner::key_id_of( $public ) ) );
	}

	public function test_the_signature_binds_data_and_installation(): void {
		list( $secret, $public ) = $this->keys();
		$json      = '{"license_status":"active"}';
		$signature = ResponseSigner::sign_with( ResponseSigner::message( 'a1b2c3d4-0000-4000-8000-000000000001', $json ), $secret );

		$this->assertFalse( ResponseSigner::verify( ResponseSigner::message( 'a1b2c3d4-0000-4000-8000-000000000002', $json ), $signature, $public ), 'another installation' );
		$this->assertFalse( ResponseSigner::verify( ResponseSigner::message( 'a1b2c3d4-0000-4000-8000-000000000001', '{"license_status":"suspended"}' ), $signature, $public ), 'other data' );
		$this->assertSame( ResponseSigner::message( 'A1B2C3D4-0000-4000-8000-000000000001', $json ), ResponseSigner::message( 'a1b2c3d4-0000-4000-8000-000000000001', $json ), 'UUID case does not matter' );
	}

	public function test_another_key_or_garbage_is_rejected(): void {
		list( $secret ) = $this->keys();
		$other   = ResponseSigner::public_key_of( ResponseSigner::secret_key_from( base64_encode( str_repeat( "\x02", 32 ) ) ) );
		$message = ResponseSigner::message( '', '{}' );
		$sig     = ResponseSigner::sign_with( $message, $secret );

		$this->assertFalse( ResponseSigner::verify( $message, $sig, $other ) );
		$this->assertFalse( ResponseSigner::verify( $message, 'not-base64!', $other ) );
		$this->assertFalse( ResponseSigner::verify( $message, $sig, base64_encode( 'short' ) ) );
	}

	public function test_a_seed_and_the_full_secret_key_give_the_same_key(): void {
		list( $secret, $public ) = $this->keys();
		$this->assertSame( $public, ResponseSigner::public_key_of( ResponseSigner::secret_key_from( base64_encode( $secret ) ) ) );
		$this->assertNull( ResponseSigner::secret_key_from( base64_encode( 'wrong length' ) ) );
	}

	/** Documented test vector (docs/API-V1.md): seed 32 × 0x01, installation and data below. */
	public function test_documented_vector(): void {
		list( $secret, $public ) = $this->keys();
		$message = ResponseSigner::message( 'a1b2c3d4-0000-4000-8000-000000000001', '{"license_status":"active"}' );
		$this->assertSame( 'iojj3XQJ8ZX9UtstPLpdcspnCb8dlBIb83SIAbQPb1w=', $public );
		$this->assertSame( '4qfhfiKz3F2K45FnfKU7bEeAoreDXyf6tuAtlPXlCSvVRxzcoqVFOubr0bb+TtmU65fpRAc2KRVZ4KdooQMBDA==', ResponseSigner::sign_with( $message, $secret ) );
	}

	/** Envelope of a value encrypted with the retained previous key test-2025 (tests/bootstrap.php). */
	private function previous_key_envelope( string $plaintext ): string {
		$nonce      = random_bytes( 12 );
		$tag        = '';
		$ciphertext = openssl_encrypt( $plaintext, 'aes-256-gcm', hash( 'sha256', 'test-previous-encryption-key', true ), OPENSSL_RAW_DATA, $nonce, $tag, 'wplc-secret-v1:test-2025', 16 );
		return 'v1:test-2025:' . base64_encode( $nonce . $tag . $ciphertext );
	}

	/** T-86: the stored signing key moves to the current encryption key with the installation secrets. */
	public function test_a_key_stored_with_a_previous_encryption_key_is_reencrypted(): void {
		list( $secret ) = $this->keys();
		$stored = $this->previous_key_envelope( base64_encode( $secret ) );

		$this->assertSame( $secret, ResponseSigner::stored_secret_key( $stored ) );
		$result = ResponseSigner::reencrypted( $stored );
		$this->assertSame( 'rotate', $result['status'] );
		$this->assertStringStartsWith( 'v1:test-2026:', $result['value'] );
		$this->assertSame( $secret, ResponseSigner::stored_secret_key( $result['value'] ), 'same signing key after re-encryption' );
		$this->assertSame( 'current', ResponseSigner::reencrypted( $result['value'] )['status'] );
	}

	public function test_a_plain_stored_key_is_encrypted_and_an_undecryptable_one_is_kept(): void {
		list( $secret ) = $this->keys();
		$plain = ResponseSigner::reencrypted( base64_encode( $secret ) );
		$this->assertSame( 'rotate', $plain['status'] );
		$this->assertSame( $secret, ResponseSigner::stored_secret_key( $plain['value'] ) );

		$removed_key = str_replace( 'v1:test-2025:', 'v1:retired-2024:', $this->previous_key_envelope( base64_encode( $secret ) ) );
		$this->assertNull( ResponseSigner::stored_secret_key( $removed_key ) );
		$this->assertSame( array( 'status' => 'undecryptable', 'value' => $removed_key ), ResponseSigner::reencrypted( $removed_key ) );
		$this->assertSame( array( 'status' => 'empty', 'value' => '' ), ResponseSigner::reencrypted( '' ) );
	}
}
