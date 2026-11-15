<?php
/**
 * The vendored client and the fixtures match the license server (runs where the server code is available).
 *
 * @package Vendor\Product\Premium
 */

use PHPUnit\Framework\TestCase;
use Vendor\Product\Premium\Tests\Support\FakeServer;
use Vendor\Product\Premium\Tests\Support\Fixtures;

final class ServerSyncTest extends TestCase {
	private function server_dir(): string {
		$dir = premium_addon_starter_server_dir();
		if ( '' === $dir ) {
			$this->markTestSkipped( 'License server code not available (set WPLC_SERVER_DIR to run this).' );
		}
		return $dir;
	}

	public function test_vendored_client_equals_the_server_copy_rewritten(): void {
		$dir = $this->server_dir();
		require_once dirname( __DIR__, 2 ) . '/bin/vendor-client.php';
		foreach ( PREMIUM_ADDON_STARTER_CLIENT_FILES as $file ) {
			$expected = premium_addon_starter_vendor_client( (string) file_get_contents( $dir . '/src/Client/' . $file ), PREMIUM_ADDON_STARTER_CLIENT_NAMESPACE, PREMIUM_ADDON_STARTER_CLIENT_TEXT_DOMAIN, $file );
			$this->assertSame( $expected, file_get_contents( dirname( __DIR__, 2 ) . '/src/Client/' . $file ), $file . ': run php bin/vendor-client.php' );
		}
	}

	public function test_committed_fixtures_equal_what_the_server_code_produces(): void {
		$dir = $this->server_dir();
		require_once dirname( __DIR__ ) . '/bin/generate-fixtures.php';
		premium_addon_starter_load_server( $dir );
		foreach ( premium_addon_starter_build_fixtures() as $name => $value ) {
			$this->assertSame( premium_addon_starter_encode_fixture( $name, $value ), file_get_contents( Fixtures::path( $name ) ), $name . ' is outdated: run php tests/bin/generate-fixtures.php' );
		}
	}

	public function test_the_fake_server_builds_envelopes_exactly_like_the_real_one(): void {
		$dir = $this->server_dir();
		require_once dirname( __DIR__ ) . '/bin/generate-fixtures.php';
		premium_addon_starter_load_server( $dir );
		$fake   = new FakeServer();
		$keys   = $fake->keys;
		$data   = $fake->entitlement_data( (string) $keys['installation_id'] );
		$real   = \WPLC\Api\V1\Response::envelope( $data, (string) $keys['installation_secret'], 'n-123' );
		$json   = (string) wp_json_encode( $data, JSON_UNESCAPED_SLASHES );
		$signer = '\\WPLC\\Security\\ResponseSigner';
		$real['signature_ed25519'] = $signer::sign_with( $signer::message( (string) $keys['installation_id'], $json ), $fake->secret_key() );
		$real['payload_signature_ed25519'] = $signer::sign_with( $signer::payload_message( (string) $keys['installation_id'], (string) $real['payload'] ), $fake->secret_key() );
		$real['key_id']            = $signer::key_id_of( $signer::public_key_of( $fake->secret_key() ) );
		$this->assertSame( $real, $fake->envelope( $data, (string) $keys['installation_secret'], 'n-123', (string) $keys['installation_id'] ) );
	}
}
