<?php
/**
 * Update detection and verified download (B7, B10).
 *
 * @package Vendor\Product\Premium
 */

use PHPUnit\Framework\TestCase;
use Vendor\Product\Premium\Client\LicenseClient;
use Vendor\Product\Premium\Tests\Support\FakeServer;
use Vendor\Product\Premium\Tests\Support\Fixtures;
use Vendor\Product\Premium\Update\Updater;

final class UpdaterTest extends TestCase {
	private const BASENAME = 'premium-addon-starter/premium-addon-starter.php';

	/** @var FakeServer */
	private $server;
	/** @var array<string,mixed> */
	private $update;

	protected function setUp(): void {
		wp_stub_reset();
		Fixtures::store_credentials();
		$this->server = new FakeServer();
		$this->server->install();
		// Same metadata as the server-generated fixture.
		$this->update         = Fixtures::json( 'update-available.json' )['data'];
		$this->server->update = $this->update;
		$this->server->downloads[ (string) $this->update['package_url'] ] = (string) file_get_contents( Fixtures::path( 'package.zip' ) );
	}

	private function updater( string $version = '1.0.0', int $max_bytes = 1048576, ?LicenseClient $client = null, int $now = 0 ): Updater {
		$now = $now ?: Fixtures::time( 60 );
		return new Updater(
			$client ?? Fixtures::client( null, $version ),
			self::BASENAME,
			'premium-addon-starter',
			'starter-product',
			$version,
			Fixtures::UPDATE,
			'https://shop.example',
			array( 'shop.example' ),
			$max_bytes,
			static function () use ( $now ): int {
				return $now;
			}
		);
	}

	public function test_the_signed_fixture_update_answer_verifies(): void {
		$keys     = Fixtures::json( 'keys.json' );
		$envelope = Fixtures::json( 'update-available.json' );
		$nonce    = (string) json_decode( (string) $envelope['payload'], true )['nonce'];
		$data     = LicenseClient::verified_server_data( $envelope, (string) $keys['installation_id'], array( (string) $keys['server_public_key'] ), $nonce );
		$this->assertSame( '9.9.9', $data['version'] );
		$this->assertSame( hash_file( 'sha256', Fixtures::path( 'package.zip' ) ), $data['sha256'] );

		// P-14 (client 2.0.2): the nonce of the request is required, and the legacy signature without nonce alone is not enough.
		$this->assertSame( 'wplc_nonce_mismatch', LicenseClient::verified_server_data( $envelope, (string) $keys['installation_id'], array( (string) $keys['server_public_key'] ) ) );
		unset( $envelope['payload_signature_ed25519'] );
		$this->assertSame( 'wplc_server_signature_invalid', LicenseClient::verified_server_data( $envelope, (string) $keys['installation_id'], array( (string) $keys['server_public_key'] ), $nonce ) );
	}

	public function test_detection_runs_without_a_logged_in_user_and_fills_the_transient(): void {
		$GLOBALS['wp_stub']['can'] = false;
		$updater   = $this->updater();
		$transient = $updater->filter_update_transient( (object) array( 'response' => array(), 'checked' => array() ) );

		$this->assertContains( 'POST /wplc/v1/updates/check', $this->server->seen );
		$item = $transient->response[ self::BASENAME ];
		$this->assertSame( '9.9.9', $item->new_version );
		$this->assertSame( $updater->placeholder(), $item->package, 'P-26: a placeholder, never a one-time download URL.' );
		$this->assertSame( '7.4', $item->requires_php );
		$this->assertSame( 9, count( (array) $item ) );
	}

	public function test_p26_the_periodic_check_asks_without_download_and_keeps_no_url(): void {
		$updater = $this->updater();
		$updater->check();

		$this->assertCount( 1, $this->server->update_requests );
		$this->assertFalse( $this->server->update_requests[0]['download'], 'No download grant on the server for a periodic check.' );
		$timeout = end( $GLOBALS['wp_stub']['requests'] )['args']['timeout'];
		$this->assertSame( 5, $timeout, 'The short default timeout of a web request, not the 15 s of a download.' );
		$this->assertSame( '', $updater->stored()['update']['package_url'] );
		$this->assertSame( '9.9.9', $updater->stored()['update']['version'] );

		// The placeholder from the transient is handled by pre_download(), which asks with download: true.
		$path = $updater->pre_download( false, $updater->placeholder(), null, array() );
		$this->assertIsString( $path );
		$this->assertTrue( end( $this->server->update_requests )['download'] );
		unlink( $path );
	}

	public function test_a_recent_stored_check_is_reused(): void {
		$updater = $this->updater();
		$updater->check();
		$this->server->seen = array();
		$updater->filter_update_transient( new stdClass() );
		$this->assertSame( array(), $this->server->seen );
	}

	public function test_no_newer_version_goes_to_no_update(): void {
		$transient = $this->updater( '9.9.9' )->filter_update_transient( new stdClass() );
		$this->assertArrayNotHasKey( self::BASENAME, (array) ( $transient->response ?? array() ) );
		$this->assertSame( '9.9.9', $transient->no_update[ self::BASENAME ]->new_version );
	}

	public function test_an_inactive_license_removes_a_stored_update(): void {
		$updater = $this->updater();
		$updater->check();
		$this->server->errors['POST /wplc/v1/updates/check'] = array( 403, 'license_inactive' );
		$this->assertInstanceOf( WP_Error::class, $updater->check() );
		$this->assertNull( $updater->stored()['update'] );
	}

	public function test_update_data_for_another_product_or_host_is_refused(): void {
		// Client 2.0 refuses signed data of another product itself.
		$this->server->update = array_merge( $this->update, array( 'product_key' => 'other-product' ) );
		$this->assertSame( 'wplc_product_mismatch', $this->updater()->check()->get_error_code() );
		// The package URL only comes with download: true, right before the download.
		$this->server->update = array_merge( $this->update, array( 'package_url' => 'https://evil.example/x.zip' ) );
		$this->assertSame( 'update_metadata_invalid', $this->updater()->pre_download( false, 'x', null, array( 'plugin' => self::BASENAME ) )->get_error_code() );
		$this->server->update = array_merge( $this->update, array( 'package_url' => 'http://shop.example/wp-json/wplc/v1/updates/download/x' ) );
		$this->assertSame( 'update_metadata_invalid', $this->updater()->pre_download( false, 'x', null, array( 'plugin' => self::BASENAME ) )->get_error_code() );
	}

	public function test_pre_download_fetches_a_fresh_grant_and_returns_the_verified_file(): void {
		$path = $this->updater()->pre_download( false, 'https://shop.example/wp-json/wplc/v1/updates/download/old', null, array( 'plugin' => self::BASENAME ) );
		$this->assertIsString( $path );
		$this->assertSame( hash_file( 'sha256', Fixtures::path( 'package.zip' ) ), hash_file( 'sha256', $path ) );
		$this->assertContains( 'POST /wplc/v1/updates/check', $this->server->seen, 'fresh one-time URL requested right before the download' );
		unlink( $path );
	}

	public function test_checksum_mismatch_is_rejected_and_leaves_no_file(): void {
		$this->server->downloads[ (string) $this->update['package_url'] ] = 'PK tampered package';
		$before = glob( sys_get_temp_dir() . '/starter*' );
		$result = $this->updater()->pre_download( false, (string) $this->update['package_url'], null, array( 'plugin' => self::BASENAME ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'package_checksum_mismatch', $result->get_error_code() );
		$this->assertSame( $before, glob( sys_get_temp_dir() . '/starter*' ), 'temporary file removed' );
	}

	public function test_size_limit_is_enforced(): void {
		$result = $this->updater( '1.0.0', 10 )->download( (string) $this->update['package_url'], (string) $this->update['sha256'] );
		$this->assertSame( 'package_too_large', $result->get_error_code() );
	}

	public function test_redirects_are_followed_only_to_allowed_https_hosts(): void {
		$url      = (string) $this->update['package_url'];
		$internal = 'https://shop.example/wp-json/wplc/v1/packages/stream';
		$bytes    = $this->server->downloads[ $url ];
		unset( $this->server->downloads[ $url ] );
		$this->server->redirects[ $url ]      = '/wp-json/wplc/v1/packages/stream';
		$this->server->downloads[ $internal ] = $bytes;
		$path = $this->updater()->download( $url, (string) $this->update['sha256'] );
		$this->assertIsString( $path );
		unlink( $path );

		$this->server->redirects[ $url ] = 'https://storage.elsewhere.example/p.zip';
		$this->assertSame( 'package_host_not_allowed', $this->updater()->download( $url, (string) $this->update['sha256'] )->get_error_code() );
	}

	public function test_server_error_during_download_aborts(): void {
		$this->server->errors['POST /wplc/v1/updates/check'] = array( 0, 'http_request_failed' );
		$result = $this->updater()->pre_download( false, (string) $this->update['package_url'], null, array( 'plugin' => self::BASENAME ) );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_other_packages_are_left_to_wordpress(): void {
		$this->assertFalse( $this->updater()->pre_download( false, 'https://downloads.wordpress.org/plugin/x.zip', null, array( 'plugin' => 'x/x.php' ) ) );
		$this->assertSame( array(), $this->server->seen );
	}

	public function test_plugins_api_answers_only_for_this_slug(): void {
		$updater = $this->updater();
		$updater->check();
		$info = $updater->plugins_api( false, 'plugin_information', (object) array( 'slug' => 'premium-addon-starter' ) );
		$this->assertSame( '9.9.9', $info->version );
		$this->assertStringContainsString( 'Fixes', $info->sections['changelog'] );
		$this->assertFalse( $updater->plugins_api( false, 'plugin_information', (object) array( 'slug' => 'other' ) ) );
	}
}
