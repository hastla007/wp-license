<?php
use PHPUnit\Framework\TestCase;
use WPLC\Api\V1\Response;
use WPLC\Client\LicenseClient;
use WPLC\Security\ResponseSigner;

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, $options = 0, $depth = 512 ) {
		return json_encode( $value, $options, $depth );
	}
}

/**
 * T-134: the client checks the server's Ed25519 signature against pinned keys.
 */
final class ServerSignatureClientTest extends TestCase {
	private const SECRET       = 'test-installation-secret-0123456789abcdef';
	private const NONCE        = '0123456789abcdef0123456789abcdef';
	private const INSTALLATION = 'a1b2c3d4-0000-4000-8000-000000000001';

	/** @return array<string,mixed> */
	private static function data(): array {
		return array(
			'license_status' => 'active',
			'package_url'    => 'https://shop.example/wp-json/wplc/v1/x',
			'entitlements'   => array( 'feature.export' => true ),
			'label'          => 'Größe',
		);
	}

	/** @return array{0:string,1:string} Binary secret key, base64 public key. */
	private static function keys( string $byte = "\x01" ): array {
		$secret = ResponseSigner::secret_key_from( base64_encode( str_repeat( $byte, 32 ) ) );
		return array( $secret, ResponseSigner::public_key_of( $secret ) );
	}

	/**
	 * Envelope as the server builds it (Response::envelope + ResponseSigner::envelope_fields).
	 *
	 * @return array<string,mixed>
	 */
	private static function envelope( string $secret_key, string $installation = self::INSTALLATION, ?array $data = null ): array {
		$data     = $data ?? self::data();
		$envelope = Response::envelope( $data, self::SECRET, self::NONCE );
		$json     = wp_json_encode( $data, JSON_UNESCAPED_SLASHES );
		$envelope['signature_ed25519'] = ResponseSigner::sign_with( ResponseSigner::message( $installation, $json ), $secret_key );
		$envelope['key_id']            = ResponseSigner::key_id_of( ResponseSigner::public_key_of( $secret_key ) );
		return $envelope;
	}

	public function test_a_server_signed_envelope_verifies_with_the_pinned_key(): void {
		list( $secret, $public ) = self::keys();

		$this->assertSame( self::data(), LicenseClient::verified_server_data( self::envelope( $secret ), self::INSTALLATION, array( $public ) ) );
		$this->assertSame( self::data(), LicenseClient::verified_server_data( self::envelope( $secret ), strtoupper( self::INSTALLATION ), array( $public ) ), 'UUID case does not matter' );
	}

	public function test_during_a_rotation_old_and_new_key_are_accepted(): void {
		list( $old_secret, $old_public ) = self::keys( "\x01" );
		list( $new_secret, $new_public ) = self::keys( "\x02" );
		$pinned = array( $new_public, $old_public );

		$this->assertIsArray( LicenseClient::verified_server_data( self::envelope( $old_secret ), self::INSTALLATION, $pinned ) );
		$this->assertIsArray( LicenseClient::verified_server_data( self::envelope( $new_secret ), self::INSTALLATION, $pinned ) );
	}

	public function test_another_key_another_installation_or_a_missing_signature_is_rejected(): void {
		list( $secret, $public ) = self::keys( "\x01" );
		list( $forger )          = self::keys( "\x03" );

		$this->assertSame( 'wplc_server_signature_invalid', LicenseClient::verified_server_data( self::envelope( $forger ), self::INSTALLATION, array( $public ) ), 'own server of an attacker' );
		$this->assertSame( 'wplc_server_signature_invalid', LicenseClient::verified_server_data( self::envelope( $secret, 'a1b2c3d4-0000-4000-8000-000000000002' ), self::INSTALLATION, array( $public ) ), 'answer of another installation' );

		$unsigned = Response::envelope( self::data(), self::SECRET, self::NONCE );
		$this->assertSame( 'wplc_server_signature_invalid', LicenseClient::verified_server_data( $unsigned, self::INSTALLATION, array( $public ) ) );
		$this->assertSame( 'wplc_server_signature_invalid', LicenseClient::verified_server_data( self::envelope( $secret ), self::INSTALLATION, array() ), 'no pinned key' );
		$this->assertSame( 'wplc_server_signature_invalid', LicenseClient::verified_server_data( self::envelope( $secret ), self::INSTALLATION, array( 'garbage', base64_encode( 'short' ) ) ) );
	}

	public function test_a_forged_payload_with_a_valid_hmac_is_rejected(): void {
		// Somebody who read the installation secret from the options table can
		// produce a valid HMAC, but not the server's signature.
		list( $secret, $public ) = self::keys();
		$envelope = self::envelope( $secret );
		$envelope['payload'] = str_replace( '"feature.export":true', '"feature.export":true,"feature.all":true', $envelope['payload'] );
		$envelope['payload_signature'] = hash_hmac( 'sha256', $envelope['payload'], self::SECRET );

		$this->assertIsArray( LicenseClient::verified_data( $envelope, self::SECRET, self::NONCE ), 'HMAC alone accepts it' );
		$this->assertSame( 'wplc_server_signature_invalid', LicenseClient::verified_server_data( $envelope, self::INSTALLATION, array( $public ) ) );
	}

	public function test_data_must_be_exactly_the_signed_part_of_the_payload(): void {
		list( $secret, $public ) = self::keys();
		$envelope = self::envelope( $secret );

		// Extra member after the nonce: the suffix no longer matches.
		$tampered            = $envelope;
		$tampered['payload'] = substr( $envelope['payload'], 0, -1 ) . ',"x":1}';
		$this->assertSame( 'wplc_server_signature_invalid', LicenseClient::verified_server_data( $tampered, self::INSTALLATION, array( $public ) ) );

		// Duplicate "data" key: json_decode keeps the last one, which is not the signed part.
		$tampered            = $envelope;
		$json                = wp_json_encode( self::data(), JSON_UNESCAPED_SLASHES );
		$tampered['payload'] = '{"data":' . $json . ',"data":{"license_status":"active","entitlements":{"feature.all":true}},"nonce":"' . self::NONCE . '"}';
		$this->assertSame( 'wplc_server_signature_invalid', LicenseClient::verified_server_data( $tampered, self::INSTALLATION, array( $public ) ) );

		// The modified legacy `data` field plays no role.
		$tampered                       = $envelope;
		$tampered['data']['site_limit'] = 99;
		$this->assertSame( self::data(), LicenseClient::verified_server_data( $tampered, self::INSTALLATION, array( $public ) ) );
	}

	public function test_an_empty_data_object_round_trips(): void {
		list( $secret, $public ) = self::keys();

		$this->assertSame( array(), LicenseClient::verified_server_data( self::envelope( $secret, self::INSTALLATION, array() ), self::INSTALLATION, array( $public ) ) );
	}
}
