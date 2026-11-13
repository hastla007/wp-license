<?php
use PHPUnit\Framework\TestCase;

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
				'/signing-keys' => array( 'get' ),
				'/webhooks/stripe' => array( 'post' ),
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

		$this->assertSame( array( 'data', 'signature' ), $contract['components']['schemas']['SignedEnvelope']['required'] );
		$this->assertSame(
			array( 'license_key', 'product_key', 'installation_id', 'installation_secret', 'site_url' ),
			$contract['components']['schemas']['ActivationRequest']['required']
		);
		$this->assertArrayHasKey( 'signatures', $contract['components']['schemas']['SignedEnvelope']['properties'] );
		$this->assertSame( array( 'context', 'current', 'next' ), $contract['components']['schemas']['SigningKeys']['required'] );
		$this->assertArrayHasKey( 'InstallationHmac', $contract['components']['securitySchemes'] );
		$this->assertArrayHasKey( 'StripeSignature', $contract['components']['securitySchemes'] );
	}
}
