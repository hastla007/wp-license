<?php
use PHPUnit\Framework\TestCase;
use WPLC\Api\V1\Response;
use WPLC\Client\LicenseClient;

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, $options = 0, $depth = 512 ) {
		return json_encode( $value, $options, $depth );
	}
}

final class ResponseSignatureTest extends TestCase {
	private const SECRET = 'test-installation-secret-0123456789abcdef';
	private const NONCE = '0123456789abcdef0123456789abcdef';

	/** @return array<string,mixed> */
	private static function data(): array {
		return array(
			'activation_id' => 7,
			'license_status' => 'active',
			'site_limit' => 3,
			'entitlements' => array( 'feature.export' => true ),
		);
	}

	public function test_envelope_matches_fixed_vectors_and_keeps_the_legacy_fields(): void {
		$envelope = Response::envelope( self::data(), self::SECRET, self::NONCE );

		$this->assertSame( array( 'data', 'signature', 'payload', 'payload_signature' ), array_keys( $envelope ) );
		$this->assertSame( self::data(), $envelope['data'] );
		$this->assertSame( 'e479e0263b0d94762d86a0a2ed347ea8d2552ef0786ddc60f8d389f9b18542da', $envelope['signature'] );
		$this->assertSame(
			'{"data":{"activation_id":7,"license_status":"active","site_limit":3,"entitlements":{"feature.export":true}},"nonce":"0123456789abcdef0123456789abcdef"}',
			$envelope['payload']
		);
		$this->assertSame( '0c2d2070b327f0013c71659e1df3984135c26866fcc91771373f05eaa5bf5c1e', $envelope['payload_signature'] );
	}

	public function test_client_accepts_a_server_envelope_and_returns_the_signed_data(): void {
		$envelope = Response::envelope( self::data(), self::SECRET, self::NONCE );

		$this->assertSame( self::data(), LicenseClient::verified_data( $envelope, self::SECRET, self::NONCE ) );
		$this->assertSame( self::data(), LicenseClient::verified_data( $envelope, self::SECRET, self::NONCE, true ) );
	}

	public function test_client_verifies_the_raw_payload_bytes_not_a_re_encoding(): void {
		// Unescaped slashes, a float with a trailing zero, and an escaped
		// non-ASCII character do not survive decode + encode unchanged.
		$payload = '{"data":{"package_url":"https://shop.example/wp-json/x","n":1.0,"u":"ä"},"nonce":"abc"}';
		$envelope = array(
			'data' => array( 'ignored' => true ),
			'signature' => 'not-checked-when-a-payload-is-present',
			'payload' => $payload,
			'payload_signature' => '42d1bee744d3d35c30fbdd94e0c0cff9a892c212edff521e00c839de8e2d549c',
		);

		$data = LicenseClient::verified_data( $envelope, self::SECRET, 'abc' );

		$this->assertIsArray( $data );
		$this->assertSame( 'https://shop.example/wp-json/x', $data['package_url'] );
		$this->assertSame( 'ä', $data['u'] );
		$this->assertArrayNotHasKey( 'ignored', $data );
	}

	public function test_client_rejects_a_response_signed_for_another_request(): void {
		$envelope = Response::envelope( self::data(), self::SECRET, self::NONCE );

		$this->assertSame( 'wplc_nonce_mismatch', LicenseClient::verified_data( $envelope, self::SECRET, 'ffffffffffffffffffffffffffffffff' ) );
	}

	public function test_client_rejects_modified_payload_signature_or_secret(): void {
		$envelope = Response::envelope( self::data(), self::SECRET, self::NONCE );

		$tampered = $envelope;
		$tampered['payload'] = str_replace( '"site_limit":3', '"site_limit":99', $envelope['payload'] );
		$this->assertSame( 'wplc_signature_invalid', LicenseClient::verified_data( $tampered, self::SECRET, self::NONCE ) );

		$tampered = $envelope;
		$tampered['payload_signature'] = str_repeat( '0', 64 );
		$this->assertSame( 'wplc_signature_invalid', LicenseClient::verified_data( $tampered, self::SECRET, self::NONCE ) );

		$this->assertSame( 'wplc_signature_invalid', LicenseClient::verified_data( $envelope, 'another-secret', self::NONCE ) );
		$this->assertSame( 'wplc_signature_invalid', LicenseClient::verified_data( $envelope, '', self::NONCE ) );
	}

	public function test_modified_data_field_cannot_override_the_signed_payload(): void {
		$envelope = Response::envelope( self::data(), self::SECRET, self::NONCE );
		$envelope['data']['site_limit'] = 99;

		$data = LicenseClient::verified_data( $envelope, self::SECRET, self::NONCE );

		$this->assertIsArray( $data );
		$this->assertSame( 3, $data['site_limit'] );
	}

	public function test_a_signed_payload_without_data_and_nonce_is_invalid(): void {
		$payload = '{"activation_id":7}';
		$envelope = array(
			'data' => array(),
			'signature' => '',
			'payload' => $payload,
			'payload_signature' => hash_hmac( 'sha256', $payload, self::SECRET ),
		);

		$this->assertSame( 'wplc_response_invalid', LicenseClient::verified_data( $envelope, self::SECRET ) );
	}

	public function test_legacy_envelope_is_accepted_only_when_no_payload_is_present_or_required(): void {
		$envelope = Response::envelope( self::data(), self::SECRET, self::NONCE );
		$legacy = array( 'data' => $envelope['data'], 'signature' => $envelope['signature'] );

		$this->assertSame( self::data(), LicenseClient::verified_data( $legacy, self::SECRET, self::NONCE ) );
		$this->assertSame( 'wplc_signature_invalid', LicenseClient::verified_data( $legacy, self::SECRET, self::NONCE, true ) );

		$legacy['data']['site_limit'] = 99;
		$this->assertSame( 'wplc_signature_invalid', LicenseClient::verified_data( $legacy, self::SECRET, self::NONCE ) );

		// Stripping only the payload signature must not fall back to the legacy check.
		$stripped = $envelope;
		unset( $stripped['payload_signature'] );
		$this->assertSame( 'wplc_signature_invalid', LicenseClient::verified_data( $stripped, self::SECRET, self::NONCE ) );
	}
}
