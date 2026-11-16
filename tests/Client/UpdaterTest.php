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

	public function test_p21_per_site_activations_on_a_network_use_one_source_for_updates(): void {
		$GLOBALS['wplc_test_sites'] = array( 1, 2, 3, 4, 5 );
		$store                      = new WPLC_Test_Blog_Store();
		$network                    = function () use ( $store ): LicenseManager {
			return $this->kit->manager( array( 'update_uri' => 'https://license.example/test-addon', 'store' => $store ) );
		};
		$offer = function (): void {
			$this->kit->server->queue[] = $this->kit->server->signed( array( 'update_available' => true, 'version' => '1.1.0', 'product_key' => 'test-addon', 'license_active' => true ) );
		};
		$asked_by = function (): string {
			$last = end( $this->kit->server->requests );
			return (string) $last['headers']['x-wplc-installation-id'];
		};

		// The add-on is licensed on site 5 only; the main site (1) is not activated.
		switch_to_blog( 5 );
		$this->assertInstanceOf( \WPLC\Client\LicenseStatus::class, $network()->activate( self::KEY ) );
		$site5 = $network()->client()->installation_id();
		restore_current_blog();
		$this->assertSame( 1, get_current_blog_id() );
		$this->assertFalse( $network()->client()->is_activated() );

		// Network admin (main site): the update is found with the credentials of site 5 and cached for the network.
		$offer();
		$this->assertSame( '1.1.0', $network()->updater()->metadata()['version'] ?? null );
		$this->assertSame( $site5, $asked_by() );
		$this->assertSame( 5, $network()->updater()->source_site() );
		$this->assertArrayHasKey( 'test_addon_update_meta', $GLOBALS['wplc_test_site_options'] );

		// The cron of any other site sees the same answer (no flip-flop, no extra request).
		$requests = count( $this->kit->server->requests );
		foreach ( array( 2, 5, 3 ) as $site ) {
			switch_to_blog( $site );
			$this->assertSame( '1.1.0', $network()->updater()->metadata()['version'] ?? null, 'site ' . $site );
			restore_current_blog();
		}
		$this->assertCount( $requests, $this->kit->server->requests );

		// The download from the network admin uses site 5 as well.
		$this->kit->server->queue[] = $this->kit->server->signed( array( 'update_available' => true, 'version' => '1.1.0', 'product_key' => 'test-addon', 'license_active' => true, 'package' => 'https://storage.example/p.zip', 'sha256' => hash( 'sha256', self::ZIP ) ) );
		$this->kit->server->file( 'https://storage.example/p.zip', self::ZIP );
		$updater       = $network()->updater();
		$file          = $updater->filter_pre_download( false, $updater->placeholder() );
		$this->files[] = $file;
		$this->assertIsString( $file );
		$this->assertSame( $site5, $asked_by() );
		$this->assertSame( 1, get_current_blog_id(), 'The site is switched back.' );

		// Once the main site is activated it is the source: stable, whatever site checks.
		$this->assertInstanceOf( \WPLC\Client\LicenseStatus::class, $network()->activate( self::KEY ) );
		$main = $network()->client()->installation_id();
		$this->assertArrayNotHasKey( 'test_addon_update_meta', $GLOBALS['wplc_test_site_options'], 'The activation flushed the network cache.' );
		switch_to_blog( 3 );
		$offer();
		$this->assertSame( '1.1.0', $network()->updater()->metadata()['version'] ?? null );
		restore_current_blog();
		$this->assertSame( $main, $asked_by() );

		// No site activated: no request at all, the answer "not activated" is cached for the network.
		$GLOBALS['wplc_test_sites'] = array( 2, 3 );
		$GLOBALS['wplc_test_main_site'] = 2;
		$network()->updater()->flush();
		$requests = count( $this->kit->server->requests );
		$this->assertSame( array( 'error' => 'wplc_not_activated' ), $network()->updater()->metadata() );
		$this->assertCount( $requests, $this->kit->server->requests );
		$this->assertSame( 'wplc_not_activated', $network()->updater()->filter_pre_download( false, $updater->placeholder() )->get_error_data() );
		unset( $GLOBALS['wplc_test_sites'], $GLOBALS['wplc_test_main_site'], $GLOBALS['wplc_test_site_options'], $GLOBALS['wplc_test_blog'], $GLOBALS['wplc_test_blog_stack'] );
	}

	public function test_p48_the_cache_is_flushed_only_after_an_update_of_this_add_on(): void {
		$updater = $this->manager->updater();
		$this->kit->store->data['test_addon_update_meta'] = array( 'data' => array(), 'expires' => 1, 'version' => '1.0.0' );
		$basename = $updater->basename();

		$updater->after_upgrade( null, array( 'type' => 'theme', 'themes' => array( 'x' ) ) );
		$updater->after_upgrade( null, array( 'type' => 'plugin', 'plugins' => array( 'other/other.php' ) ) );
		$updater->after_upgrade( null, array( 'type' => 'translation' ) );
		$this->assertArrayHasKey( 'test_addon_update_meta', $this->kit->store->data );

		$updater->after_upgrade( null, array( 'type' => 'plugin', 'plugins' => array( 'other/other.php', $basename ) ) );
		$this->assertArrayNotHasKey( 'test_addon_update_meta', $this->kit->store->data );
	}

	/**
	 * Add-on audit 16 Nov 2026, item 2 (P11, captain-pro docs/AUDIT-LIZENZ-PRS.md): a failed check no longer
	 * replaces the last valid offer; it is kept until its own expiry and the error is remembered next to it.
	 */
	public function test_audit16_a_failed_check_keeps_the_last_offer_until_it_expires(): void {
		$updater         = $this->manager->updater();
		$server          = $this->kit->server;
		$server->queue[] = static function ( array $request ) use ( $server ) {
			return WPLC_Test_Server::json( 200, $server->envelope( $request, array( 'update_available' => true, 'version' => '1.1.0', 'current_version' => '1.0.0', 'product_key' => 'test-addon', 'license_active' => true, 'package_url' => '', 'package' => '' ) ) );
		};
		$this->assertSame( '1.1.0', $updater->metadata( true )['version'] );

		// WordPress checks again two hours later (cron or "Check again"); the server cannot be reached.
		$this->kit->advance( 2 * HOUR_IN_SECONDS );
		$server->queue[] = new WP_Error( 'http_request_failed', 'down' );
		$meta            = $updater->metadata( true );
		$this->assertArrayNotHasKey( 'error', $meta, 'The offer is still there.' );
		$this->assertSame( '1.1.0', $meta['version'] );
		$this->assertTrue( $meta['update_available'] );
		$this->assertSame( 'wplc_connection_failed', $meta['last_error'] );
		$this->assertArrayNotHasKey( 'package_url', $meta, 'Never a link in the cache.' );

		// The failure is cached for an hour only, then the check is repeated.
		$requests = count( $server->requests );
		$this->kit->advance( 30 * MINUTE_IN_SECONDS );
		$this->assertSame( '1.1.0', $updater->metadata()['version'] );
		$this->assertCount( $requests, $server->requests );
		$this->kit->advance( 31 * MINUTE_IN_SECONDS );
		$server->queue[] = WPLC_Test_Server::error( 503, 'retry_later' );
		$this->assertSame( 'retry_later', $updater->metadata()['last_error'] );
		$this->assertCount( $requests + 1, $server->requests );

		// Twelve hours after the successful check the offer has expired: the error replaces it.
		$this->kit->advance( 9 * HOUR_IN_SECONDS );
		$server->queue[] = new WP_Error( 'http_request_failed', 'down' );
		$this->assertSame( array( 'error' => 'wplc_connection_failed' ), $updater->metadata() );

		// A signed refusal ends the activation: no offer is kept.
		$server->queue[] = static function ( array $request ) use ( $server ) {
			return WPLC_Test_Server::json( 200, $server->envelope( $request, array( 'update_available' => true, 'version' => '1.2.0', 'current_version' => '1.0.0', 'product_key' => 'test-addon', 'license_active' => true ) ) );
		};
		$this->assertSame( '1.2.0', $updater->metadata( true )['version'] );
		$server->queue[] = static function ( array $request ) use ( $server ) {
			$data = array( 'activation_id' => 41, 'installation_id' => '', 'activation_status' => 'deactivated', 'server_time' => gmdate( 'Y-m-d H:i:s', $server->now ) );
			return WPLC_Test_Server::error( 403, 'activation_deactivated', array( 'signed' => $server->envelope( $request, $data ) ) );
		};
		$this->assertSame( array( 'error' => 'activation_deactivated' ), $updater->metadata( true ) );
	}

	public function test_audit3_allowed_package_addresses(): void {
		foreach ( array( 'https://storage.example/pkg.zip', 'HTTPS://Storage.example/x', 'http://localhost/pkg.zip', 'http://127.0.0.1:8080/pkg.zip', 'http://[::1]/pkg.zip', 'http://addon.localhost/pkg.zip' ) as $url ) {
			$this->assertTrue( Updater::is_allowed_package_url( $url ), $url );
		}
		foreach ( array( 'http://storage.example/pkg.zip', 'ftp://storage.example/pkg.zip', 'https://user:pass@storage.example/pkg.zip', '//storage.example/pkg.zip', 'file:///etc/passwd', '' ) as $url ) {
			$this->assertFalse( Updater::is_allowed_package_url( $url ), $url );
		}
	}

	public function test_p48_update_uri_reads_the_plugin_file_at_most_once_per_request(): void {
		$GLOBALS['wplc_test_file_reads']   = 0;
		$GLOBALS['wplc_test_file_headers'] = array( 'Update URI' => 'https://license.example/test-addon' );
		try {
			// Configured: no file access at all.
			$configured = $this->kit->manager( array( 'update_uri' => 'https://license.example/test-addon', 'plugin_file' => '/plugins/test-addon/test-addon.php' ) );
			$this->assertSame( 'license.example', $configured->updater()->update_host() );
			$configured->updater()->placeholder();
			$this->assertSame( 0, $GLOBALS['wplc_test_file_reads'] );

			// Not configured: the header is read once and then kept in memory.
			$updater = $this->kit->manager( array( 'plugin_file' => '/plugins/test-addon/test-addon.php' ) )->updater();
			$this->assertSame( 'https://license.example/test-addon', $updater->update_uri() );
			$this->assertSame( 'license.example', $updater->update_host() );
			$updater->placeholder();
			$this->assertSame( 1, $GLOBALS['wplc_test_file_reads'] );
		} finally {
			unset( $GLOBALS['wplc_test_file_reads'], $GLOBALS['wplc_test_file_headers'] );
		}
	}
}
