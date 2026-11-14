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
			array_values( array_diff( array_keys( $envelope['properties'] ), array( 'signature_ed25519', 'payload_signature_ed25519', 'key_id' ) ) ),
			array_keys( \WPLC\Api\V1\Response::envelope( array( 'status' => 'ok' ), 'contract-test-secret', 'contract-test-nonce' ) ),
			'The server envelope and the published schema must list the same fields.'
		);
		$this->assertArrayHasKey( 'InstallationHmac', $contract['components']['securitySchemes'] );
		$this->assertArrayHasKey( 'StripeSignature', $contract['components']['securitySchemes'] );
	}

	/** B-72: the published error codes are exactly the codes the server knows, each with one status. */
	public function test_contract_lists_the_error_codes_and_status_values_of_the_server(): void {
		$contract = json_decode(
			(string) file_get_contents( dirname( __DIR__, 2 ) . '/docs/openapi-v1.json' ),
			true,
			512,
			JSON_THROW_ON_ERROR
		);
		$schemas  = $contract['components']['schemas'];

		$this->assertSame( array_keys( \WPLC\Api\V1\ApiLog::CODES ), $schemas['ErrorCode']['enum'] );
		$this->assertSame( array( 'active', 'past_due', 'expired', 'suspended', 'revoked', 'pending', 'deactivated' ), $schemas['LicenseStatus']['enum'] );
		$this->assertSame( array( 'active', 'deactivated' ), $schemas['ActivationStatus']['enum'] );
		$this->assertSame( \WPLC\Api\V1\ActivationService::ENVIRONMENTS, $schemas['Environment']['enum'] );
		// B-75: the secret pattern of the contract is the one the server validates with.
		$this->assertSame(
			'/' . str_replace( '/', '\\/', $schemas['ActivationRequest']['properties']['installation_secret']['pattern'] ) . '/',
			\WPLC\Api\V1\ActivationService::SECRET_PATTERN
		);

		// The Markdown reference documents every code.
		$markdown = (string) file_get_contents( dirname( __DIR__, 2 ) . '/docs/API-V1.md' );
		foreach ( \WPLC\Api\V1\ApiLog::CODES as $code => $status ) {
			$this->assertMatchesRegularExpression( '/\| `' . preg_quote( $code, '/' ) . '` \| ' . $status . ' \|/', $markdown, 'docs/API-V1.md must list ' . $code . ' with status ' . $status );
		}
	}

	/** T-145: every signed route declares the answers shared by all authenticated requests. */
	public function test_signed_routes_declare_replay_rate_limit_and_unavailable_responses(): void {
		$contract = json_decode(
			(string) file_get_contents( dirname( __DIR__, 2 ) . '/docs/openapi-v1.json' ),
			true,
			512,
			JSON_THROW_ON_ERROR
		);
		foreach ( array( array( '/activations/{activation_id}', 'delete' ), array( '/entitlements/check', 'post' ), array( '/updates/check', 'post' ) ) as $operation ) {
			$responses = $contract['paths'][ $operation[0] ][ $operation[1] ]['responses'];
			foreach ( array( '401', '409', '429', '503' ) as $status ) {
				$this->assertSame( '#/components/responses/ApiError', $responses[ $status ]['$ref'] ?? '', $operation[0] . ' must declare ' . $status );
			}
		}
	}
}
