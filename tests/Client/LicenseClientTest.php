<?php
/**
 * Transport client against a fake license server that signs like the real one.
 *
 * Every test names the task it belongs to (docs/TODO.md).
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\Client\LicenseClient;

require_once __DIR__ . '/client-test-kit.php';

final class LicenseClientTest extends TestCase {

	private const KEY = 'WPLC-AAAA-BBBB-CCCC-DDDD';

	/** @var WPLC_Client_Test_Kit */
	private $kit;

	protected function setUp(): void {
		$this->kit = new WPLC_Client_Test_Kit();
	}

	/** @return array<int,string> */
	private function routes(): array {
		return array_map(
			static function ( array $request ): string {
				return $request['method'] . ' ' . $request['route'];
			},
			$this->kit->server->requests
		);
	}

	private function activated(): LicenseClient {
		$client = $this->kit->client();
		$this->assertIsArray( $client->activate( self::KEY ) );
		$this->kit->server->requests = array();

		return $client;
	}

	// ------------------------------------------------------------------
	// T-134, A-30, A-31, B-158, B-159: signature verification.
	// ------------------------------------------------------------------

	public function test_t134_documented_test_vector_of_the_server_signature_verifies(): void {
		// docs/API-V1.md, "Test vectors".
		$payload  = '{"data":{"license_status":"active"},"nonce":"0123456789abcdef"}';
		$envelope = array(
			'payload'                   => $payload,
			'payload_signature'         => hash_hmac( 'sha256', $payload, 'secret' ),
			'payload_signature_ed25519' => '7JE5lIc5ZSbbYP1HFGvM1+wM5pnjhI/yZTaZUlmhgd+JcmXTzNi8hWvj7wTqDEjBfaDYlMyON3tB4A1ey3reBg==',
		);
		$keys     = array( 'iojj3XQJ8ZX9UtstPLpdcspnCb8dlBIb83SIAbQPb1w=' );

		$this->assertSame(
			array( 'license_status' => 'active' ),
			LicenseClient::verified_data( $envelope, 'secret', '0123456789abcdef', $keys, 'A1B2C3D4-0000-4000-8000-000000000001' )
		);
		$this->assertSame( 'wplc_server_signature_invalid', LicenseClient::verified_data( $envelope, 'secret', '0123456789abcdef', $keys, 'a1b2c3d4-0000-4000-8000-000000000002' ), 'The signature is bound to the installation.' );
	}

	public function test_t134_good_tampered_wrong_key_and_missing_signature(): void {
		$server  = $this->kit->server;
		$request = array(
			'headers' => array(
				'x-wplc-installation-id' => 'a1b2c3d4-0000-4000-8000-000000000001',
				'x-wplc-nonce'           => 'nonce-0001',
			),
			'body'    => array(),
		);
		$server->secrets['a1b2c3d4-0000-4000-8000-000000000001'] = 'installation-secret';
		$data     = $server->entitlement();
		$envelope = $server->envelope( $request, $data );
		$verify   = static function ( array $envelope, array $keys ) {
			return LicenseClient::verified_data( $envelope, 'installation-secret', 'nonce-0001', $keys, 'a1b2c3d4-0000-4000-8000-000000000001' );
		};
		$pinned   = array( $server->public_key );
		$other    = new WPLC_Test_Server( str_repeat( "\x09", 32 ) );

		// Good.
		$this->assertSame( 'active', $verify( $envelope, $pinned )['license_status'] );

		// Tampered payload: the HMAC can be recomputed by anyone who knows the installation secret, the server signature cannot.
		$tampered                      = $envelope;
		$tampered['payload']           = str_replace( '"site_limit":1', '"site_limit":99', $envelope['payload'] );
		$tampered['payload_signature'] = hash_hmac( 'sha256', $tampered['payload'], 'installation-secret' );
		$this->assertSame( 'wplc_server_signature_invalid', $verify( $tampered, $pinned ) );
		$tampered['payload_signature'] = $envelope['payload_signature'];
		$this->assertSame( 'wplc_signature_invalid', $verify( $tampered, $pinned ) );

		// Wrong key: signed by another server.
		$this->assertSame( 'wplc_server_signature_invalid', $verify( $envelope, array( $other->public_key ) ) );
		$this->assertSame( 'wplc_server_signature_invalid', $verify( $server->envelope( $request, $data, array( 'secret_key' => $other->secret_key ) ), $pinned ) );

		// Missing: stripping the server signature, or the whole payload, is a downgrade (B-158).
		$this->assertSame( 'wplc_server_signature_invalid', $verify( $server->envelope( $request, $data, array( 'strip' => array( 'payload_signature_ed25519' ) ) ), $pinned ) );
		$this->assertSame( 'wplc_signature_invalid', $verify( $server->envelope( $request, $data, array( 'strip' => array( 'payload', 'payload_signature', 'payload_signature_ed25519' ) ) ), $pinned ), 'The legacy members data + signature are never accepted.' );
		$this->assertSame( 'wplc_signature_invalid', $verify( $server->envelope( $request, $data, array( 'strip' => array( 'payload_signature' ) ) ), $pinned ) );

		// B-159: only the payload counts; a changed top-level `data` changes nothing.
		$changed                       = $envelope;
		$changed['data']['site_limit'] = 99;
		$this->assertSame( 1, $verify( $changed, $pinned )['site_limit'] );

		// A-30: an answer recorded for another request is refused.
		$this->assertSame( 'wplc_nonce_mismatch', LicenseClient::verified_data( $envelope, 'installation-secret', 'nonce-0002', $pinned, 'a1b2c3d4-0000-4000-8000-000000000001' ) );

		// A-31: several pinned keys allow a rotation; a malformed pinned key never switches the check off.
		$this->assertSame( 'active', $verify( $envelope, array( $other->public_key, $server->public_key ) )['license_status'] );
		$this->assertSame( 'wplc_server_signature_invalid', $verify( $envelope, array( 'not-a-key' ) ) );
	}

	public function test_t134_client_rejects_an_unsigned_or_foreign_answer_and_stores_nothing(): void {
		$other  = new WPLC_Test_Server( str_repeat( "\x09", 32 ) );
		$server = $this->kit->server;

		$server->queue[] = $server->signed( $server->entitlement(), array( 'secret_key' => $other->secret_key ) );
		$result          = $this->kit->client()->activate( self::KEY );
		$this->assertSame( 'wplc_server_signature_invalid', $result->get_error_code() );
		$this->assertFalse( $this->kit->client()->is_activated() );

		$server->queue[] = $server->signed( $server->entitlement(), array( 'strip' => array( 'payload_signature_ed25519' ) ) );
		$this->assertSame( 'wplc_server_signature_invalid', $this->kit->client()->activate( self::KEY )->get_error_code() );

		// A client whose pinned key list is unusable is not configured; it does not fall back to "no check".
		$broken = $this->kit->client( array( 'public_keys' => array( 'AAAA' ) ) );
		$this->assertFalse( $broken->is_configured() );
		$this->assertSame( 'wplc_configuration_invalid', $broken->activate( self::KEY )->get_error_code() );
	}

	public function test_b15_server_address_must_be_https_without_credentials(): void {
		foreach ( array( 'http://license.example', 'https://user:pw@license.example', 'https://license.example/?x=1', 'ftp://license.example', '' ) as $url ) {
			$this->assertFalse( $this->kit->client( array(), $url )->is_configured(), $url );
		}
		$this->assertTrue( $this->kit->client( array(), 'https://license.example/' )->is_configured() );
		$this->assertTrue( $this->kit->client( array( 'allow_insecure' => true ), 'http://localhost:8080' )->is_configured() );
		$this->assertSame( 'license.example', $this->kit->client()->server_host() );
	}

	// ------------------------------------------------------------------
	// Activation: B-71, B-72, B-73, B-163, B-170.
	// ------------------------------------------------------------------

	public function test_b73_activation_stores_site_and_environment_and_exposes_the_state(): void {
		$client = $this->kit->client();
		$this->assertFalse( $client->is_activated() );
		$this->assertSame( '', $client->installation_id() );

		$data = $client->activate( ' wplc-aaaa-bbbb-cccc-dddd ' );

		$this->assertIsArray( $data );
		$this->assertSame( array( 'POST /wplc/v1/activations' ), $this->routes() );
		$body = $this->kit->server->requests[0]['body'];
		$this->assertSame( self::KEY, $body['license_key'] );
		$this->assertSame( 'test-addon', $body['product_key'] );
		$this->assertSame( 'https://shop.example', $body['site_url'] );
		$this->assertSame( 'production', $body['environment'] );
		$this->assertSame( '1.0.0', $body['product_version'] );
		$this->assertSame( PHP_VERSION, $body['php_version'] );
		$this->assertArrayNotHasKey( 'staging_parent_installation_id', $body );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $body['installation_id'] );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9._~+\/=-]{32,128}$/', $body['installation_secret'] );

		$stored = $this->kit->credentials();
		$this->assertSame( $body['installation_id'], $stored['installation_id'] );
		$this->assertSame( 'https://shop.example', $stored['site_url'] );
		$this->assertSame( 'production', $stored['environment'] );
		$this->assertSame( hash( 'sha256', self::KEY ), $stored['license_key_hash'] );
		$this->assertArrayNotHasKey( 'signed_payload', $stored, 'B-158: the downgrade flag is gone.' );
		$this->assertStringNotContainsString( self::KEY, (string) json_encode( $this->kit->store->data ), 'The license key is not stored.' );

		$this->assertTrue( $client->is_activated() );
		$this->assertSame( $body['installation_id'], $client->installation_id() );
		$this->assertSame( 'production', $client->environment() );
		$this->assertArrayHasKey( 'payload_signature_ed25519', $client->last_envelope() );
		$this->assertArrayNotHasKey( 'test_addon_license_pending', $this->kit->store->data );
	}

	public function test_working_credentials_are_reused_instead_of_a_second_activation(): void {
		$client = $this->activated();

		$this->assertIsArray( $client->activate( self::KEY ) );

		$this->assertSame( array( 'POST /wplc/v1/entitlements/check' ), $this->routes() );
	}

	public function test_b71_a_failed_precheck_never_creates_a_new_installation(): void {
		$client = $this->activated();
		$before = $this->kit->credentials();
		$server = $this->kit->server;

		foreach ( array( new WP_Error( 'http_request_failed', 'cURL error 28: timed out' ), WPLC_Test_Server::error( 500, 'activation_failed' ), WPLC_Test_Server::error( 429, 'rate_limited' ), WPLC_Test_Server::json( 503, '<html>Checking your browser</html>', array( 'content-type' => 'text/html' ) ) ) as $answer ) {
			$server->requests = array();
			$server->queue    = array( $answer );
			$result           = $client->activate( self::KEY );
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( array( 'POST /wplc/v1/entitlements/check' ), $this->routes(), $result->get_error_code() );
			$this->assertSame( $before, $this->kit->credentials() );
		}
	}

	/**
	 * @dataProvider data_unknown_installation
	 *
	 * @param string $code Error code of the pre-check.
	 */
	public function test_b71_only_an_unknown_installation_is_activated_again( string $code ): void {
		$client = $this->activated();
		$before = $this->kit->credentials();

		$this->kit->server->queue[] = WPLC_Test_Server::error( 401, $code );
		$data                       = $client->activate( self::KEY );

		$this->assertIsArray( $data );
		$this->assertSame( array( 'POST /wplc/v1/entitlements/check', 'POST /wplc/v1/activations' ), $this->routes() );
		$this->assertNotSame( $before['installation_id'], $this->kit->credentials()['installation_id'] );
	}

	/** @return array<string,array{0:string}> */
	public function data_unknown_installation(): array {
		return array(
			'activation_not_found'        => array( 'activation_not_found' ),
			'installation_secret_invalid' => array( 'installation_secret_invalid' ),
		);
	}

	public function test_b71_pending_installation_is_stored_before_the_request_and_reused_after_a_lost_answer(): void {
		$client = $this->kit->client();
		$server = $this->kit->server;
		$store  = $this->kit->store;

		// The answer of the first attempt is lost: the server may have created the activation.
		$server->queue[] = static function () use ( $store ) {
			PHPUnit\Framework\Assert::assertArrayHasKey( 'test_addon_license_pending', $store->data, 'Stored before the request is sent.' );
			return new WP_Error( 'http_request_failed', 'Connection reset' );
		};
		$first           = $client->activate( self::KEY );
		$this->assertSame( 'wplc_connection_failed', $first->get_error_code() );
		$this->assertFalse( $client->is_activated() );
		$pending = $store->data['test_addon_license_pending'];

		// A corrupt answer keeps the pending installation as well.
		$server->queue[] = WPLC_Test_Server::json( 200, '{"oops":true}' );
		$this->assertSame( 'wplc_signature_invalid', $client->activate( self::KEY )->get_error_code() );

		$this->assertIsArray( $client->activate( self::KEY ) );
		$bodies = array_column( $server->requests, 'body' );
		$this->assertCount( 3, $bodies );
		$this->assertSame( array( $pending['installation_id'] ), array_values( array_unique( array_column( $bodies, 'installation_id' ) ) ), 'Every attempt repeats the same installation ID.' );
		$this->assertSame( array( $pending['installation_secret'] ), array_values( array_unique( array_column( $bodies, 'installation_secret' ) ) ) );
		$this->assertSame( $pending['installation_id'], $client->installation_id() );
		$this->assertArrayNotHasKey( 'test_addon_license_pending', $store->data );
	}

	public function test_b71_pending_installation_is_not_reused_for_another_key_and_not_sent_when_it_cannot_be_stored(): void {
		$client                     = $this->kit->client();
		$this->kit->server->queue[] = new WP_Error( 'http_request_failed', 'timeout' );
		$client->activate( self::KEY );
		$this->kit->server->queue[] = new WP_Error( 'http_request_failed', 'timeout' );
		$client->activate( 'WPLC-OTHER-KEY' );
		$ids = array_column( array_column( $this->kit->server->requests, 'body' ), 'installation_id' );
		$this->assertNotSame( $ids[0], $ids[1] );

		$fresh              = new WPLC_Client_Test_Kit();
		$fresh->store->fail = true;
		$this->assertSame( 'wplc_credentials_not_saved', $fresh->client()->activate( self::KEY )->get_error_code() );
		$this->assertSame( array(), $fresh->server->requests, 'No activation is requested when its credentials could not be kept.' );
	}

	public function test_b72_refusals_keep_their_code_and_data_and_show_the_clients_own_text(): void {
		$client = $this->kit->client();
		$cases  = array(
			array( 409, 'site_limit', array( 'site_limit' => 1, 'site_count' => 1 ) ),
			array( 409, 'development_limit', array( 'development_limit' => 5, 'development_count' => 5 ) ),
			array( 409, 'staging_site_invalid', array() ),
			array( 403, 'license_expired', array( 'license_status' => 'expired' ) ),
			array( 403, 'license_suspended', array( 'license_status' => 'suspended' ) ),
			array( 403, 'license_revoked', array( 'license_status' => 'revoked' ) ),
			array( 403, 'license_inactive', array( 'license_status' => 'pending' ) ),
			array( 400, 'invalid_activation', array( 'fields' => array( 'environment' ) ) ),
			array( 401, 'invalid_key', array() ),
		);
		foreach ( $cases as $case ) {
			$this->kit->server->queue = array( WPLC_Test_Server::error( $case[0], $case[1], $case[2] ) );
			$result                   = $client->activate( self::KEY );
			$this->assertSame( $case[1], $result->get_error_code() );
			$data = $result->get_error_data();
			$this->assertSame( $case[0], $data['status'] );
			foreach ( $case[2] as $key => $value ) {
				$this->assertSame( $value, $data[ $key ], $case[1] . '.' . $key );
			}
			// B-23: the English log text of the server is not what the customer reads.
			$this->assertSame( 'Server text for ' . $case[1] . '.', $data['server_message'] );
			$this->assertStringNotContainsString( 'Server text', $result->get_error_message() );
			$this->assertNotSame( '', $result->get_error_message() );
			$this->assertArrayNotHasKey( 'test_addon_license_pending', $this->kit->store->data, 'A definite refusal ends the pending installation.' );
			$this->assertFalse( $client->is_activated() );
		}
	}

	public function test_a_used_up_installation_id_is_replaced_once(): void {
		$client                     = $this->kit->client();
		$this->kit->server->queue[] = WPLC_Test_Server::error( 409, 'activation_deactivated' );

		$this->assertIsArray( $client->activate( self::KEY ) );

		$ids = array_column( array_column( $this->kit->server->requests, 'body' ), 'installation_id' );
		$this->assertCount( 2, $ids );
		$this->assertNotSame( $ids[0], $ids[1] );
		$this->assertSame( $ids[1], $client->installation_id() );
	}

	public function test_b163_another_key_releases_the_old_activation_first(): void {
		$client = $this->activated();
		$old    = $this->kit->credentials();

		$this->assertIsArray( $client->activate( 'WPLC-NEW-KEY' ) );

		$this->assertSame( array( 'DELETE /wplc/v1/activations/41', 'POST /wplc/v1/activations' ), $this->routes() );
		$this->assertSame( $old['installation_id'], $this->kit->server->requests[0]['headers']['x-wplc-installation-id'] );
		$this->assertSame( hash( 'sha256', 'WPLC-NEW-KEY' ), $this->kit->credentials()['license_key_hash'] );
	}

	public function test_a102_data_of_another_product_is_rejected(): void {
		$server          = $this->kit->server;
		$server->queue[] = $server->signed( $server->entitlement( array( 'product_key' => 'other-addon' ) ) );
		$this->assertSame( 'wplc_product_mismatch', $this->kit->client()->activate( self::KEY )->get_error_code() );
		$this->assertFalse( $this->kit->client()->is_activated() );

		$client          = $this->activated();
		$server->queue[] = $server->signed( $server->entitlement( array( 'product_key' => 'other-addon' ) ) );
		$this->assertSame( 'wplc_product_mismatch', $client->check_entitlements()->get_error_code() );
		$this->assertTrue( $client->is_activated() );
	}

	// ------------------------------------------------------------------
	// Signed requests: A-34, A-108, A-109, B-18, B-52, B-70.
	// ------------------------------------------------------------------

	public function test_a34_check_and_delete_send_the_site_address_and_are_signed(): void {
		$client = $this->activated();
		$stored = $this->kit->credentials();

		$this->assertIsArray( $client->check_entitlements() );
		$check = $this->kit->server->requests[0];
		$this->assertSame( 'https://shop.example', $check['body']['site_url'] );
		$this->assertSame( PHP_VERSION, $check['body']['php_version'] );
		$this->assertSame( '1.0.0', $check['body']['product_version'] );
		$canonical = implode( "\n", array( 'POST', '/wplc/v1/entitlements/check', $check['headers']['x-wplc-timestamp'], $check['headers']['x-wplc-nonce'], hash( 'sha256', $check['raw'] ) ) );
		$this->assertSame( hash_hmac( 'sha256', $canonical, $stored['installation_secret'] ), $check['headers']['x-wplc-signature'] );
		$this->assertSame( (string) $this->kit->now, $check['headers']['x-wplc-timestamp'] );

		$result = $client->deactivate();
		$this->assertSame( 'deactivated', $result['status'] );
		$delete = $this->kit->server->requests[1];
		$this->assertSame( 'DELETE', $delete['method'] );
		$this->assertSame( array( 'site_url' => 'https://shop.example' ), $delete['body'] );
		$this->assertFalse( $client->is_activated() );
	}

	public function test_b52_periodic_update_check_asks_for_no_download(): void {
		$client = $this->activated();

		$client->check_update();
		$client->check_update( true, array( 'channel' => 'beta', 'core_version' => '2.1.0', 'ignored' => 'x' ) );

		$periodic = $this->kit->server->requests[0];
		$download = $this->kit->server->requests[1];
		$this->assertFalse( $periodic['body']['download'] );
		$this->assertSame( 'test-addon', $periodic['body']['product_key'] );
		$this->assertSame( '1.0.0', $periodic['body']['current_version'] );
		$this->assertSame( PHP_VERSION, $periodic['body']['php_version'] );
		$this->assertArrayNotHasKey( 'channel', $periodic['body'] );
		$this->assertTrue( $download['body']['download'] );
		$this->assertSame( 'beta', $download['body']['channel'] );
		$this->assertSame( '2.1.0', $download['body']['core_version'] );
		$this->assertArrayNotHasKey( 'ignored', $download['body'] );
		$this->assertSame( 15, $download['timeout'], 'More patience when a package is about to be downloaded.' );
		$this->assertSame( 5, $periodic['timeout'], 'A5/A-108: short timeout outside cron.' );
	}

	public function test_a108_timeout_is_configurable(): void {
		$client = $this->kit->client( array( 'timeout' => 2 ) );
		$client->activate( self::KEY );
		$this->assertSame( 2, $this->kit->server->requests[0]['timeout'] );
	}

	public function test_b18_html_of_a_firewall_has_its_own_error_code(): void {
		$client = $this->activated();
		$cases  = array(
			array( 403, '<!DOCTYPE html><html><title>Attention Required! | Cloudflare</title>', 'text/html; charset=UTF-8', 'wplc_blocked_by_firewall' ),
			array( 200, '<html><body>Please enable JavaScript</body></html>', 'text/html', 'wplc_blocked_by_firewall' ),
			array( 503, "\n <html>maintenance</html>", 'text/plain', 'wplc_blocked_by_firewall' ),
			array( 502, '', 'text/plain', 'wplc_http_error' ),
			array( 200, 'OK', 'text/plain', 'wplc_response_invalid' ),
		);
		foreach ( $cases as $case ) {
			$this->kit->server->queue = array( WPLC_Test_Server::json( $case[0], $case[1], array( 'content-type' => $case[2] ) ) );
			$result                   = $client->check_entitlements();
			$this->assertSame( $case[3], $result->get_error_code(), $case[1] );
			$this->assertSame( $case[0], $result->get_error_data()['status'] );
			$this->assertTrue( $client->is_activated() );
		}
	}

	public function test_b70_signed_refusal_is_verified_and_only_then_ends_the_activation(): void {
		$server = $this->kit->server;
		$signed = static function ( array $options = array() ) use ( $server ) {
			return static function ( array $request ) use ( $server, $options ) {
				$data = array(
					'activation_id'     => 41,
					'installation_id'   => '',
					'activation_status' => 'deactivated',
					'license_status'    => 'deactivated',
					'access'            => false,
					'entitlements'      => array(),
					'server_time'       => gmdate( 'Y-m-d H:i:s', $server->now ),
				);
				return WPLC_Test_Server::error( 403, 'activation_deactivated', array( 'signed' => $server->envelope( $request, $data, $options ) ) );
			};
		};
		$other  = new WPLC_Test_Server( str_repeat( "\x09", 32 ) );

		// Unsigned, signed by someone else, or recorded for another request: nothing is dropped.
		$client = $this->activated();
		foreach ( array( WPLC_Test_Server::error( 403, 'activation_deactivated' ), $signed( array( 'secret_key' => $other->secret_key ) ), $signed( array( 'nonce' => 'recorded-earlier' ) ), WPLC_Test_Server::error( 403, 'activation_deactivated', array( 'wplc_signed' => true, 'verified' => array( 'activation_status' => 'deactivated' ) ) ) ) as $answer ) {
			$server->queue = array( $answer );
			$result        = $client->check_update();
			$this->assertSame( 'activation_deactivated', $result->get_error_code() );
			$this->assertFalse( LicenseClient::is_signed_refusal( $result ) );
			$this->assertFalse( LicenseClient::needs_new_activation( $result ) );
			$this->assertTrue( $client->is_activated() );
		}

		$server->queue = array( $signed() );
		$result        = $client->check_update();
		$this->assertTrue( LicenseClient::is_signed_refusal( $result ) );
		$this->assertSame( 'deactivated', $result->get_error_data()['verified']['activation_status'] );
		$this->assertArrayNotHasKey( 'signed', $result->get_error_data() );
		$this->assertFalse( $client->is_activated(), 'The verified refusal removes the credentials.' );
	}

	public function test_b70_signed_deactivated_status_of_the_entitlement_check_removes_the_credentials(): void {
		$client                     = $this->activated();
		$server                     = $this->kit->server;
		$server->queue[]            = $server->signed( $server->entitlement( array( 'activation_status' => 'deactivated', 'license_status' => 'deactivated', 'access' => false, 'entitlements' => array() ) ) );

		$data = $client->check_entitlements();

		$this->assertSame( 'deactivated', $data['activation_status'] );
		$this->assertFalse( $client->is_activated() );
	}

	public function test_a109_wrong_local_clock_is_corrected_from_the_servers_answer(): void {
		$client          = $this->activated();
		$server          = $this->kit->server;
		$server->now     = $this->kit->now + 7200; // The customer server is two hours behind.
		$server->queue[] = WPLC_Test_Server::error( 401, 'request_expired', array( 'server_time' => $server->now ) );

		$this->assertIsArray( $client->check_entitlements() );

		$this->assertCount( 2, $server->requests );
		$this->assertSame( (string) $this->kit->now, $server->requests[0]['headers']['x-wplc-timestamp'] );
		$this->assertSame( (string) $server->now, $server->requests[1]['headers']['x-wplc-timestamp'] );
		$this->assertNotSame( $server->requests[0]['headers']['x-wplc-nonce'], $server->requests[1]['headers']['x-wplc-nonce'] );

		// The offset is remembered: the next request is right at once.
		$server->requests = array();
		$client->check_entitlements();
		$this->assertSame( (string) $server->now, $server->requests[0]['headers']['x-wplc-timestamp'] );

		// Still refused: reported as it is, no endless loop, no lost credentials.
		$server->requests = array();
		$server->queue    = array( WPLC_Test_Server::error( 401, 'request_expired', array( 'server_time' => $server->now ) ), WPLC_Test_Server::error( 401, 'request_expired', array( 'server_time' => $server->now ) ) );
		$this->assertSame( 'request_expired', $client->check_entitlements()->get_error_code() );
		$this->assertCount( 2, $server->requests );
		$this->assertTrue( $client->is_activated() );
	}

	public function test_a_replayed_nonce_is_retried_once_and_retry_after_is_passed_on(): void {
		$client                     = $this->activated();
		$this->kit->server->queue[] = WPLC_Test_Server::error( 409, 'request_replayed' );
		$this->assertIsArray( $client->check_entitlements() );
		$this->assertCount( 2, $this->kit->server->requests );

		$this->kit->server->queue[] = WPLC_Test_Server::json( 429, array( 'code' => 'rate_limited', 'message' => 'x', 'data' => array( 'status' => 429 ) ), array( 'Retry-After' => '120' ) );
		$result                     = $client->check_entitlements();
		$this->assertSame( 'rate_limited', $result->get_error_code() );
		$this->assertSame( 120, $result->get_error_data()['retry_after'] );
	}

	public function test_b15_fallback_address_is_used_when_the_first_cannot_be_reached(): void {
		$client          = $this->kit->client( array( 'fallback_urls' => array( 'https://license-2.example', 'http://insecure.example' ) ) );
		$server          = $this->kit->server;
		$server->queue[] = new WP_Error( 'http_request_failed', 'Could not resolve host' );

		$this->assertIsArray( $client->activate( self::KEY ) );

		$this->assertSame( array( 'https://license.example/wp-json/wplc/v1/activations', 'https://license-2.example/wp-json/wplc/v1/activations' ), array_column( $server->requests, 'url' ) );

		// An HTTP answer, also an error, is an answer: no second address is asked.
		$server->requests = array();
		$server->queue    = array( WPLC_Test_Server::error( 500, 'activation_failed' ) );
		$client->check_entitlements();
		$this->assertCount( 1, $server->requests );

		// All addresses down.
		$server->requests = array();
		$server->queue    = array( new WP_Error( 'http_request_failed', 'down' ), new WP_Error( 'http_request_failed', 'down too' ) );
		$result           = $client->check_entitlements();
		$this->assertSame( 'wplc_connection_failed', $result->get_error_code() );
		$this->assertSame( 'down too', $result->get_error_data()['transport_message'] );
		$this->assertCount( 2, $server->requests, 'The insecure fallback is ignored.' );
	}

	public function test_a108_rest_base_can_be_replaced(): void {
		$client = $this->kit->client( array( 'rest_base' => 'https://license.example/index.php?rest_route=' ) );
		$client->activate( self::KEY );
		$this->assertSame( 'https://license.example/index.php?rest_route=/wplc/v1/activations', $this->kit->server->requests[0]['url'] );
	}

	// ------------------------------------------------------------------
	// Deactivation: A-108, A-110, B-69.
	// ------------------------------------------------------------------

	public function test_a110_deactivation_outcomes(): void {
		$cases = array(
			'site_mismatch'        => array( WPLC_Test_Server::error( 409, 'site_mismatch' ), 'removed_locally' ),
			'activation_not_found' => array( WPLC_Test_Server::error( 401, 'activation_not_found' ), 'removed_locally' ),
			'retry_later'          => array( WPLC_Test_Server::error( 503, 'retry_later' ), 'retry_later' ),
			'activation_mismatch'  => array( WPLC_Test_Server::error( 403, 'activation_mismatch' ), 'activation_mismatch' ),
			'timeout'              => array( new WP_Error( 'http_request_failed', 'timeout' ), 'wplc_connection_failed' ),
		);
		foreach ( $cases as $name => $case ) {
			$this->kit                  = new WPLC_Client_Test_Kit();
			$client                     = $this->activated();
			$this->kit->server->queue[] = $case[0];
			$result                     = $client->deactivate();
			if ( 'removed_locally' === $case[1] ) {
				$this->assertSame( 'removed_locally', $result['status'], $name );
				$this->assertSame( $name, $result['reason'] );
				$this->assertFalse( $client->is_activated(), $name );
			} else {
				$this->assertSame( $case[1], $result->get_error_code(), $name );
				$this->assertTrue( $client->is_activated(), 'Kept so that the release can be repeated: ' . $name );
			}
		}

		$this->assertSame( 'wplc_not_activated', $this->kit->client( array( 'store' => new WPLC_Test_Store() ) )->deactivate()->get_error_code() );
	}

	public function test_a108_delete_falls_back_to_a_method_override_when_delete_is_blocked(): void {
		foreach ( array( WPLC_Test_Server::json( 405, '<html>Method Not Allowed</html>', array( 'content-type' => 'text/html' ) ), WPLC_Test_Server::json( 501, '' ) ) as $blocked ) {
			$this->kit                  = new WPLC_Client_Test_Kit();
			$client                     = $this->activated();
			$stored                     = $this->kit->credentials();
			$this->kit->server->queue[] = $blocked;

			$result = $client->deactivate();

			$this->assertSame( 'deactivated', $result['status'] );
			$second = $this->kit->server->requests[1];
			$this->assertSame( 'POST', $second['method'] );
			$this->assertSame( 'DELETE', $second['headers']['x-http-method-override'] );
			$canonical = implode( "\n", array( 'DELETE', '/wplc/v1/activations/41', $second['headers']['x-wplc-timestamp'], $second['headers']['x-wplc-nonce'], hash( 'sha256', $second['raw'] ) ) );
			$this->assertSame( hash_hmac( 'sha256', $canonical, $stored['installation_secret'] ), $second['headers']['x-wplc-signature'], 'Signed as the DELETE the server executes.' );
			$this->assertFalse( $client->is_activated() );
		}
	}

	// ------------------------------------------------------------------
	// Copies of a site: A-34, B-69, B-164.
	// ------------------------------------------------------------------

	public function test_b69_a_copy_of_the_site_never_uses_the_copied_credentials(): void {
		$client = $this->activated();
		$live   = $this->kit->credentials();

		// The database was copied to a staging host.
		$this->kit->store->site = 'https://staging.shop.example';

		$this->assertTrue( $client->is_activated(), 'Credentials are present.' );
		$this->assertFalse( $client->site_matches() );
		foreach ( array( $client->check_entitlements(), $client->check_update(), $client->check_update( true ) ) as $result ) {
			$this->assertSame( 'wplc_site_changed', $result->get_error_code() );
			$this->assertSame( 'https://shop.example', $result->get_error_data()['activated_site_url'] );
		}

		$result = $client->deactivate();
		$this->assertSame( array( 'status' => 'removed_locally', 'reason' => 'wplc_site_changed' ), $result );
		$this->assertSame( array(), $this->kit->server->requests, 'B-69: the live activation is never touched from the copy.' );
		$this->assertFalse( $client->is_activated() );

		// Back on the live site nothing happened to its own credentials (they were only removed in the copy).
		$this->assertNotSame( '', $live['installation_id'] );
	}

	public function test_same_site_under_https_www_or_another_case_is_not_a_copy(): void {
		$client = $this->activated();
		foreach ( array( 'http://shop.example', 'https://www.shop.example/', 'https://SHOP.example', 'https://shop.example:443' ) as $same ) {
			$this->kit->store->site = $same;
			$this->assertTrue( $client->site_matches(), $same );
		}
		foreach ( array( 'https://shop.example/blog', 'https://staging.shop.example', 'https://shop.example:8443', 'https://shop.example.evil.test' ) as $other ) {
			$this->kit->store->site = $other;
			$this->assertFalse( $client->site_matches(), $other );
		}
		$this->assertSame( 'shop.example/sub/dir', LicenseClient::site_key( 'HTTPS://WWW.Shop.Example//Sub/Dir/' ) );
	}

	public function test_staging_copy_activates_with_the_live_installation_as_parent(): void {
		$client = $this->activated();
		$live   = $this->kit->credentials();

		$this->kit->store->site = 'https://staging.shop.example';
		$data                   = $client->activate( self::KEY, 'staging' );

		$this->assertIsArray( $data );
		$this->assertSame( array( 'POST /wplc/v1/activations' ), $this->routes(), 'No pre-check and no release with the copied credentials.' );
		$body = $this->kit->server->requests[0]['body'];
		$this->assertSame( 'staging', $body['environment'] );
		$this->assertSame( $live['installation_id'], $body['staging_parent_installation_id'] );
		$this->assertSame( 'https://staging.shop.example', $body['site_url'] );
		$this->assertNotSame( $live['installation_id'], $body['installation_id'] );

		$stored = $this->kit->credentials();
		$this->assertSame( 'staging', $stored['environment'] );
		$this->assertSame( 'https://staging.shop.example', $stored['site_url'] );
		$this->assertTrue( $client->site_matches() );
	}

	public function test_a_copy_activated_as_live_site_gets_its_own_installation(): void {
		$client = $this->activated();
		$live   = $this->kit->credentials();

		$this->kit->store->site = 'https://other-shop.example';
		$this->assertIsArray( $client->activate( self::KEY, 'production' ) );

		$body = $this->kit->server->requests[0]['body'];
		$this->assertSame( array( 'POST /wplc/v1/activations' ), $this->routes() );
		$this->assertArrayNotHasKey( 'staging_parent_installation_id', $body );
		$this->assertNotSame( $live['installation_id'], $body['installation_id'] );
	}

	public function test_b164_a_moved_site_repeats_the_activation_with_its_installation(): void {
		$client = $this->activated();
		$live   = $this->kit->credentials();

		$this->kit->store->site = 'https://new-domain.example';
		$data                   = $client->move( self::KEY );

		$this->assertIsArray( $data );
		$body = $this->kit->server->requests[0]['body'];
		$this->assertSame( $live['installation_id'], $body['installation_id'] );
		$this->assertSame( $live['installation_secret'], $body['installation_secret'] );
		$this->assertSame( 'https://new-domain.example', $body['site_url'] );
		$this->assertSame( 'https://new-domain.example', $this->kit->credentials()['site_url'] );
		$this->assertTrue( $client->site_matches() );

		// The server refuses (a staging site cannot move to an unrelated host): the credentials stay as they were.
		$this->kit->store->site     = 'https://third.example';
		$this->kit->server->queue[] = WPLC_Test_Server::error( 409, 'staging_site_invalid' );
		$this->assertSame( 'staging_site_invalid', $client->move( self::KEY )->get_error_code() );
		$this->assertSame( 'https://new-domain.example', $this->kit->credentials()['site_url'] );
	}
}
