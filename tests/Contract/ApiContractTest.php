<?php
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, $options = 0, $depth = 512 ) {
		return json_encode( $value, $options, $depth );
	}
}

final class ApiContractTest extends TestCase {
	/** The published API contract must remain valid JSON and declare every public v1 route. */
	public function test_openapi_contract_declares_all_v1_routes_and_methods(): void {
		$path = dirname( __DIR__, 2 ) . '/docs/openapi-v1.json';
		$json = file_get_contents( $path );

		$this->assertIsString( $json );
		$contract = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );

		$this->assertSame( '3.0.3', $contract['openapi'] );
		$this->assertSame( '1.0.0', $contract['info']['version'] );
		$this->assertSame(
			array(
				'/activations' => array( 'post' ),
				'/activations/{activation_id}' => array( 'delete' ),
				'/entitlements/check' => array( 'post' ),
				'/updates/check' => array( 'post' ),
				'/updates/download/{token}' => array( 'get' ),
				'/webhooks/stripe' => array( 'post' ),
				'/packages/{token}' => array( 'get' ),
			),
			array_map( 'array_keys', $contract['paths'] )
		);
	}

	public function test_contract_defines_signed_envelopes_and_activation_requirements(): void {
		$contract = json_decode(
			(string) file_get_contents( dirname( __DIR__, 2 ) . '/docs/openapi-v1.json' ),
			true,
			512,
			JSON_THROW_ON_ERROR
		);

		$envelope = $contract['components']['schemas']['SignedEnvelope'];
		// data/signature are the original v1 fields and must stay for bundled older clients.
		$this->assertSame( array( 'data', 'signature', 'payload', 'payload_signature' ), $envelope['required'] );
		$this->assertSame( 'object', $envelope['properties']['data']['type'] );
		$this->assertSame( 'string', $envelope['properties']['payload']['type'] );
		$this->assertSame( '^[a-f0-9]{64}$', $envelope['properties']['signature']['pattern'] );
		$this->assertSame( '^[a-f0-9]{64}$', $envelope['properties']['payload_signature']['pattern'] );
		$this->assertSame(
			array( 'license_key', 'product_key', 'installation_id', 'installation_secret', 'site_url' ),
			$contract['components']['schemas']['ActivationRequest']['required']
		);
		$this->assertSame(
			// The Ed25519 fields are optional: they need a signing key and an installation.
			array_values( array_diff( array_keys( $envelope['properties'] ), array( 'signature_ed25519', 'key_id' ) ) ),
			array_keys( \WPLC\Api\V1\Response::envelope( array( 'status' => 'ok' ), 'contract-test-secret', 'contract-test-nonce' ) ),
			'The server envelope and the published schema must list the same fields.'
		);
		$this->assertArrayHasKey( 'InstallationHmac', $contract['components']['securitySchemes'] );
		$this->assertArrayHasKey( 'StripeSignature', $contract['components']['securitySchemes'] );
	}

	/**
	 * Responses each operation can return according to src/Api/V1 (T-145).
	 *
	 * Signed routes share Authenticator::authenticate(): 401 (headers, timestamp,
	 * installation, signature), 409 request_replayed, 429 rate_limited and 503
	 * replay_store_unavailable / rate_limit_store_unavailable.
	 */
	public function test_contract_declares_every_status_the_routes_return(): void {
		$contract = json_decode(
			(string) file_get_contents( dirname( __DIR__, 2 ) . '/docs/openapi-v1.json' ),
			true,
			512,
			JSON_THROW_ON_ERROR
		);

		$expected = array(
			// 400 invalid input, 401 invalid_key, 403 product_mismatch/expired, 409 conflicts and site_limit,
			// 429 rate_limited, 500 activation_failed/server_configuration, 503 retry_later and limiter store.
			'post /activations' => array( 200, 400, 401, 403, 409, 429, 500, 503 ),
			// 403 activation_mismatch, 409 also deactivation_failed, 503 also retry_later (license lock).
			'delete /activations/{activation_id}' => array( 200, 401, 403, 409, 429, 503 ),
			// 404 license_not_found.
			'post /entitlements/check' => array( 200, 401, 404, 409, 429, 503 ),
			// 400 missing product_key/current_version, 403 license_inactive, 503 also package configuration/grant.
			'post /updates/check' => array( 200, 400, 401, 403, 409, 429, 503 ),
			'post /webhooks/stripe' => array( 200, 400, 409, 500, 503 ),
		);

		foreach ( $expected as $operation => $statuses ) {
			list( $method, $path ) = explode( ' ', $operation, 2 );
			$declared = array_map( 'intval', array_keys( $contract['paths'][ $path ][ $method ]['responses'] ) );
			$this->assertSame( $statuses, $declared, $operation );
		}
	}
}
