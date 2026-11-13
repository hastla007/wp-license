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
			array_keys( $envelope['properties'] ),
			array_keys( \WPLC\Api\V1\Response::envelope( array( 'status' => 'ok' ), 'contract-test-secret', 'contract-test-nonce' ) ),
			'The server envelope and the published schema must list the same fields.'
		);
		$this->assertArrayHasKey( 'InstallationHmac', $contract['components']['securitySchemes'] );
		$this->assertArrayHasKey( 'StripeSignature', $contract['components']['securitySchemes'] );
	}
}
