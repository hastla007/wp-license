<?php
use PHPUnit\Framework\TestCase;
use WPLC\Security\ResponseSigner;

final class ResponseSignerTest extends TestCase {
	private const PAYLOAD_VECTOR = '7JE5lIc5ZSbbYP1HFGvM1+wM5pnjhI/yZTaZUlmhgd+JcmXTzNi8hWvj7wTqDEjBfaDYlMyON3tB4A1ey3reBg==';

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

	/** B-67: a configured value must be a real Ed25519 key, not just 32 or 64 bytes. */
	public function test_a_malformed_configured_key_is_rejected_instead_of_used(): void {
		list( $secret ) = $this->keys();

		// 64 random bytes: the public half does not belong to the seed half.
		$this->assertNull( ResponseSigner::secret_key_from( base64_encode( str_repeat( "\x07", 64 ) ) ) );
		// A real secret key with a flipped bit in the public half.
		$broken     = $secret;
		$broken[63] = chr( ord( $broken[63] ) ^ 1 );
		$this->assertNull( ResponseSigner::secret_key_from( base64_encode( $broken ) ) );
		// Not base64, hex instead of base64, wrong lengths, empty.
		$this->assertNull( ResponseSigner::secret_key_from( 'not base64 !!' ) );
		$this->assertNull( ResponseSigner::secret_key_from( bin2hex( str_repeat( "\x01", 32 ) ) ) );
		$this->assertNull( ResponseSigner::secret_key_from( base64_encode( str_repeat( "\x01", 31 ) ) ) );
		$this->assertNull( ResponseSigner::secret_key_from( base64_encode( str_repeat( "\x01", 33 ) ) ) );
		$this->assertNull( ResponseSigner::secret_key_from( '' ) );
		// Surrounding whitespace from wp-config.php is tolerated.
		$this->assertSame( $secret, ResponseSigner::secret_key_from( "  " . base64_encode( str_repeat( "\x01", 32 ) ) . "\n" ) );
		$this->assertSame( $secret, ResponseSigner::secret_key_from( base64_encode( $secret ) ) );
	}

	/** A-30: the payload signature covers the nonce, so it cannot be replayed for another request. */
	public function test_the_payload_signature_binds_the_request_nonce(): void {
		list( $secret, $public ) = $this->keys();
		$uuid      = 'a1b2c3d4-0000-4000-8000-000000000001';
		$payload   = '{"data":{"license_status":"active"},"nonce":"nonce-of-request-0001"}';
		$signature = ResponseSigner::sign_with( ResponseSigner::payload_message( $uuid, $payload ), $secret );

		$this->assertTrue( ResponseSigner::verify( ResponseSigner::payload_message( strtoupper( $uuid ), $payload ), $signature, $public ) );
		$this->assertFalse( ResponseSigner::verify( ResponseSigner::payload_message( $uuid, str_replace( '0001', '0002', $payload ) ), $signature, $public ), 'another nonce' );
		$this->assertFalse( ResponseSigner::verify( ResponseSigner::payload_message( 'a1b2c3d4-0000-4000-8000-000000000002', $payload ), $signature, $public ), 'another installation' );
		// The two contexts are separated: a payload signature is not a data signature and vice versa.
		$this->assertFalse( ResponseSigner::verify( ResponseSigner::message( $uuid, $payload ), $signature, $public ) );
		$this->assertSame( "wplc-response-v2\n" . $uuid . "\n" . $payload, ResponseSigner::payload_message( $uuid, $payload ) );
	}

	/** Documented test vector for payload_signature_ed25519 (docs/API-V1.md). */
	public function test_documented_payload_vector(): void {
		list( $secret, $public ) = $this->keys();
		$payload = '{"data":{"license_status":"active"},"nonce":"0123456789abcdef"}';
		$message = ResponseSigner::payload_message( 'a1b2c3d4-0000-4000-8000-000000000001', $payload );

		$this->assertSame( self::PAYLOAD_VECTOR, ResponseSigner::sign_with( $message, $secret ) );
		$this->assertTrue( ResponseSigner::verify( $message, self::PAYLOAD_VECTOR, $public ) );
	}

	/** Documented test vector (docs/API-V1.md): seed 32 × 0x01, installation and data below. */
	public function test_documented_vector(): void {
		list( $secret, $public ) = $this->keys();
		$message = ResponseSigner::message( 'a1b2c3d4-0000-4000-8000-000000000001', '{"license_status":"active"}' );
		$this->assertSame( 'iojj3XQJ8ZX9UtstPLpdcspnCb8dlBIb83SIAbQPb1w=', $public );
		$this->assertSame( '4qfhfiKz3F2K45FnfKU7bEeAoreDXyf6tuAtlPXlCSvVRxzcoqVFOubr0bb+TtmU65fpRAc2KRVZ4KdooQMBDA==', ResponseSigner::sign_with( $message, $secret ) );
	}
}
