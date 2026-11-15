<?php
/**
 * Package download of the client's Updater: HTTPS on every hop, size limit, checksum.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\Client\LicenseManager;
use WPLC\Client\Updater;

require_once __DIR__ . '/client-test-kit.php';

final class UpdaterTest extends TestCase {

	private const KEY = 'WPLC-AAAA-BBBB-CCCC-DDDD';

	private const ZIP = "PK\x03\x04 test package of version 1.1.0";

	/** @var WPLC_Client_Test_Kit */
	private $kit;

	/** @var LicenseManager */
	private $manager;

	/** @var string[] Files returned by the updater, removed after each test. */
	private $files = array();

	protected function setUp(): void {
		$this->kit     = new WPLC_Client_Test_Kit();
		$this->manager = $this->kit->manager( array( 'update_uri' => 'https://license.example/test-addon' ) );
		$this->manager->activate( self::KEY );
	}

	protected function tearDown(): void {
		foreach ( $this->files as $file ) {
			if ( is_string( $file ) && is_file( $file ) ) {
				unlink( $file );
			}
		}
		unset( $GLOBALS['wplc_test_filters'] );
	}

	/** Script the signed answer of `POST /updates/check` with a package URL. */
	private function offer( string $url, string $sha256 = '' ): void {
		$this->kit->server->queue[] = $this->kit->server->signed(
			array(
				'update_available' => true,
				'version'          => '1.1.0',
				'product_key'      => 'test-addon',
				'license_active'   => true,
				'package'          => $url,
				'sha256'           => '' === $sha256 ? hash( 'sha256', self::ZIP ) : $sha256,
			)
		);
	}

	/** @return mixed */
	private function download() {
		$updater = $this->manager->updater();
		$result  = $updater->filter_pre_download( false, $updater->placeholder() );
		if ( is_string( $result ) ) {
			$this->files[] = $result;
		}

		return $result;
	}

	public function test_audit3_a_verified_https_package_is_downloaded(): void {
		$this->offer( 'https://license.example/wplc/v1/packages/one-time' );
		$this->kit->server->redirect( 'https://license.example/wplc/v1/packages/one-time', 'https://storage.example/pkg.zip?sig=abc' );
		$this->kit->server->file( 'https://storage.example/pkg.zip?sig=abc', self::ZIP, array( 'content-length' => (string) strlen( self::ZIP ) ) );

		$file = $this->download();

		$this->assertIsString( $file );
		$this->assertSame( self::ZIP, file_get_contents( $file ) );
		$this->assertSame( array( 'https://license.example/wplc/v1/packages/one-time', 'https://storage.example/pkg.zip?sig=abc' ), $this->kit->server->downloads );
	}

	public function test_audit3_plain_http_is_refused_before_and_after_a_redirect(): void {
		$this->offer( 'http://storage.example/pkg.zip' );
		$this->kit->server->file( 'http://storage.example/pkg.zip', self::ZIP );
		$this->assertSame( 'wplc_update_insecure', $this->download()->get_error_code() );
		$this->assertSame( array(), $this->kit->server->downloads, 'Not a single byte over plain HTTP.' );

		$this->offer( 'https://license.example/one-time' );
		$this->kit->server->redirect( 'https://license.example/one-time', 'http://storage.example/pkg.zip' );
		$this->assertSame( 'wplc_update_insecure', $this->download()->get_error_code() );
		$this->assertSame( array( 'https://license.example/one-time' ), $this->kit->server->downloads );
	}

	public function test_audit3_the_size_is_capped_and_filterable(): void {
		$this->assertSame( 104857600, $this->manager->updater()->max_package_bytes(), 'Default 100 MB.' );
		$GLOBALS['wplc_test_filters']['test_addon_license_max_package_bytes'][] = static function () {
			return 10;
		};
		$this->assertSame( 10, $this->manager->updater()->max_package_bytes() );

		// Larger than the limit (WordPress cuts the stream at limit + 1 byte; the fake writes it all).
		$this->offer( 'https://storage.example/big.zip' );
		$this->kit->server->file( 'https://storage.example/big.zip', self::ZIP );
		$before = glob( sys_get_temp_dir() . '/wplc*' );
		$error  = $this->download();
		$this->assertSame( 'wplc_update_too_large', $error->get_error_code() );
		$this->assertStringContainsString( 'larger than', $error->get_error_message() );
		$this->assertSame( $before, glob( sys_get_temp_dir() . '/wplc*' ), 'The partial file is deleted.' );

		// A Content-Length above the limit is refused as well.
		$GLOBALS['wplc_test_filters']['test_addon_license_max_package_bytes'] = array(
			static function () {
				return 1000;
			},
		);
		$this->offer( 'https://storage.example/claims-big.zip' );
		$this->kit->server->file( 'https://storage.example/claims-big.zip', self::ZIP, array( 'content-length' => '5000' ) );
		$this->assertSame( 'wplc_update_too_large', $this->download()->get_error_code() );
	}

	public function test_audit3_redirect_loops_and_checksum_mismatches_end_in_an_error(): void {
		$this->offer( 'https://a.example/loop' );
		$this->kit->server->redirect( 'https://a.example/loop', 'https://a.example/loop' );
		$this->assertSame( 'wplc_update_failed', $this->download()->get_error_code() );
		$this->assertCount( Updater::MAX_REDIRECTS + 1, $this->kit->server->downloads );

		$this->offer( 'https://storage.example/pkg.zip', str_repeat( 'a', 64 ) );
		$this->kit->server->file( 'https://storage.example/pkg.zip', self::ZIP );
		$this->assertSame( 'wplc_update_checksum', $this->download()->get_error_code() );
	}

	public function test_audit3_allowed_package_addresses(): void {
		foreach ( array( 'https://storage.example/pkg.zip', 'HTTPS://Storage.example/x', 'http://localhost/pkg.zip', 'http://127.0.0.1:8080/pkg.zip', 'http://[::1]/pkg.zip', 'http://addon.localhost/pkg.zip' ) as $url ) {
			$this->assertTrue( Updater::is_allowed_package_url( $url ), $url );
		}
		foreach ( array( 'http://storage.example/pkg.zip', 'ftp://storage.example/pkg.zip', 'https://user:pass@storage.example/pkg.zip', '//storage.example/pkg.zip', 'file:///etc/passwd', '' ) as $url ) {
			$this->assertFalse( Updater::is_allowed_package_url( $url ), $url );
		}
	}
}
