<?php
use PHPUnit\\Framework\\TestCase;
use WPLC\\Api\\V1\\Authenticator;

final class ApiRequestSignatureTest extends TestCase {
	public function test_signature_matches_the_documented_canonical_request_vector(): void {
		$signature = Authenticator::signature_for(
			'test-installation-secret',
			'post',
			'/wplc/v1/entitlements/check',
			'1730000000',
			'nonce-1234567890123456',
			'{"license_key":"abc"}'
		);

		$this->assertSame( '3f3d484ee2676e4ee252b06136976c01d1936ee288ca89886cc00f4946b45454', $signature );
		$this->assertSame( 64, strlen( $signature ) );
	}

	public function test_signature_binds_method_route_and_raw_body(): void {
		$baseline = Authenticator::signature_for( 'secret', 'POST', '/wplc/v1/entitlements/check', '1', 'nonce-1234567890123456', '{}' );

		$this->assertNotSame( $baseline, Authenticator::signature_for( 'secret', 'GET', '/wplc/v1/entitlements/check', '1', 'nonce-1234567890123456', '{}' ) );
		$this->assertNotSame( $baseline, Authenticator::signature_for( 'secret', 'POST', '/wplc/v1/updates/check', '1', 'nonce-1234567890123456', '{}' ) );
		$this->assertNotSame( $baseline, Authenticator::signature_for( 'secret', 'POST', '/wplc/v1/entitlements/check', '1', 'nonce-1234567890123456', '{ }' ) );
	}
}
