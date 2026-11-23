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

require_once dirname( __DIR__ ) . '/fixtures/interop/captain-pro-LicenseClient.php';

/**
 * Responses of this server are accepted by both clients with pinned keys:
 * the client of this repository and the client library
 * that hastla007/captain-pro ships, both through payload_signature_ed25519 (v2).
 * The legacy signature_ed25519 (v1) is still sent for clients 1.x (P-14).
 */
final class ServerClientInteropTest extends TestCase {
	private const SECRET       = 'test-installation-secret-0123456789abcdef';
	private const NONCE        = '0123456789abcdef0123456789abcdef';
	private const INSTALLATION = 'a1b2c3d4-0000-4000-8000-000000000001';

	/** @var string */
	private $public_key;

	protected function setUp(): void {
		$secret           = ResponseSigner::secret_key_from( base64_encode( str_repeat( "\x05", 32 ) ) );
		$this->public_key = ResponseSigner::public_key_of( $secret );
		$property         = new ReflectionProperty( ResponseSigner::class, 'secret_key' );
		$property->setValue( null, $secret );
	}

	protected function tearDown(): void {
		ResponseSigner::reset();
	}

	/** @return array<string,mixed> */
	private static function data(): array {
		return array(
			'license_status' => 'active',
			'entitlements'   => array( 'voucher.bulk_orders' => true ),
			'package_url'    => 'https://shop.example/wp-json/wplc/v1/updates/download/abc',
			'label'          => 'Größe',
		);
	}

	public function test_the_envelope_carries_both_server_signatures(): void {
		$envelope = Response::envelope( self::data(), self::SECRET, self::NONCE, self::INSTALLATION );

		$this->assertArrayHasKey( 'signature_ed25519', $envelope );
		$this->assertArrayHasKey( 'payload_signature_ed25519', $envelope );
		$this->assertArrayHasKey( 'key_id', $envelope );
		$this->assertTrue( ResponseSigner::verify( ResponseSigner::payload_message( self::INSTALLATION, $envelope['payload'] ), $envelope['payload_signature_ed25519'], $this->public_key ) );
	}

	public function test_the_captain_pro_client_accepts_a_response_of_this_server(): void {
		$envelope = Response::envelope( self::data(), self::SECRET, self::NONCE, self::INSTALLATION );

		$this->assertSame(
			self::data(),
			\Interop\CaptainPro\LicenseClient::verified_data( $envelope, self::SECRET, self::NONCE, array( $this->public_key ), self::INSTALLATION )
		);
	}

	public function test_the_client_of_this_repository_accepts_it_through_the_payload_signature(): void {
		$envelope = Response::envelope( self::data(), self::SECRET, self::NONCE, self::INSTALLATION );

		$this->assertSame( self::data(), LicenseClient::verified_data( $envelope, self::SECRET, self::NONCE, array( $this->public_key ), self::INSTALLATION ) );
		// P-14: the helper without the installation secret verifies the same v2 signature and needs the nonce.
		$this->assertSame( self::data(), LicenseClient::verified_server_data( $envelope, self::INSTALLATION, array( $this->public_key ), self::NONCE ) );
		$this->assertSame( 'wplc_nonce_mismatch', LicenseClient::verified_server_data( $envelope, self::INSTALLATION, array( $this->public_key ) ) );
	}

	public function test_p14_the_legacy_signature_is_still_sent_but_this_client_ignores_it(): void {
		$envelope = Response::envelope( self::data(), self::SECRET, self::NONCE, self::INSTALLATION );

		// Server output unchanged for clients 1.x: the v1 signature over the data JSON still verifies.
		$this->assertTrue( ResponseSigner::verify( ResponseSigner::message( self::INSTALLATION, (string) wp_json_encode( self::data(), JSON_UNESCAPED_SLASHES ) ), $envelope['signature_ed25519'], $this->public_key ) );

		// Without the payload signature this client accepts nothing, whatever the v1 signature says.
		unset( $envelope['payload_signature_ed25519'] );
		$this->assertSame( 'wplc_server_signature_invalid', LicenseClient::verified_server_data( $envelope, self::INSTALLATION, array( $this->public_key ), self::NONCE ) );
		$this->assertSame( 'wplc_server_signature_invalid', LicenseClient::verified_data( $envelope, self::SECRET, self::NONCE, array( $this->public_key ), self::INSTALLATION ) );
	}

	public function test_the_captain_pro_client_rejects_a_forged_payload_and_another_installation(): void {
		$envelope = Response::envelope( self::data(), self::SECRET, self::NONCE, self::INSTALLATION );

		$forged                      = $envelope;
		$forged['payload']           = str_replace( '"voucher.bulk_orders":true', '"voucher.bulk_orders":true,"voucher.white_label":true', $envelope['payload'] );
		$forged['payload_signature'] = hash_hmac( 'sha256', $forged['payload'], self::SECRET );
		$this->assertSame( 'wplc_server_signature_invalid', \Interop\CaptainPro\LicenseClient::verified_data( $forged, self::SECRET, self::NONCE, array( $this->public_key ), self::INSTALLATION ) );

		$this->assertSame( 'wplc_server_signature_invalid', \Interop\CaptainPro\LicenseClient::verified_data( $envelope, self::SECRET, self::NONCE, array( $this->public_key ), 'a1b2c3d4-0000-4000-8000-000000000002' ) );
	}
}
