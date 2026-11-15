<?php
use PHPUnit\Framework\TestCase;
use WPLC\Update\LocalPackageStorage;
use WPLC\Update\PackageStream;

final class LocalPackageStorageTest extends TestCase {
	/** @var string[] */
	private $files = array();

	protected function tearDown(): void {
		foreach ( $this->files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		$this->files = array();
	}

	private function temp_file( string $content ): string {
		$path = tempnam( sys_get_temp_dir(), 'wplc' );
		file_put_contents( $path, $content );
		$this->files[] = $path;

		return $path;
	}

	private function real_zip(): string {
		$path = tempnam( sys_get_temp_dir(), 'wplc' );
		$this->files[] = $path;
		$zip = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString( 'plugin/plugin.php', '<?php // test' );
		$zip->close();

		return $path;
	}

	public function test_real_zip_is_accepted(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive is not available.' );
		}
		$path = $this->real_zip();
		$this->assertSame( '', LocalPackageStorage::validate_zip_file( $path, 'Plugin.ZIP', filesize( $path ), 1000000 ) );
	}

	public function test_wrong_extension_is_rejected(): void {
		$path = $this->temp_file( "PK\x03\x04data" );
		$this->assertSame( 'not_a_zip', LocalPackageStorage::validate_zip_file( $path, 'plugin.zip.php', filesize( $path ), 1000000 ) );
		$this->assertSame( 'not_a_zip', LocalPackageStorage::validate_zip_file( $path, 'plugin', filesize( $path ), 1000000 ) );
	}

	public function test_wrong_magic_bytes_are_rejected(): void {
		$php = $this->temp_file( '<?php echo "not a zip";' );
		$this->assertSame( 'not_a_zip', LocalPackageStorage::validate_zip_file( $php, 'plugin.zip', filesize( $php ), 1000000 ) );

		// End-of-central-directory signature only: an empty archive.
		$empty = $this->temp_file( "PK\x05\x06" . str_repeat( "\0", 18 ) );
		$this->assertSame( 'not_a_zip', LocalPackageStorage::validate_zip_file( $empty, 'plugin.zip', filesize( $empty ), 1000000 ) );
	}

	public function test_truncated_zip_is_rejected_when_ziparchive_is_available(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive is not available.' );
		}
		$fake = $this->temp_file( "PK\x03\x04" . str_repeat( 'x', 100 ) );
		$this->assertSame( 'not_a_zip', LocalPackageStorage::validate_zip_file( $fake, 'plugin.zip', filesize( $fake ), 1000000 ) );
	}

	public function test_size_limits(): void {
		$path = $this->temp_file( "PK\x03\x04data" );
		$this->assertSame( 'empty_file', LocalPackageStorage::validate_zip_file( $path, 'plugin.zip', 0, 1000 ) );
		$this->assertSame( 'too_large', LocalPackageStorage::validate_zip_file( $path, 'plugin.zip', 1001, 1000 ) );
		$this->assertSame( 'upload_failed', LocalPackageStorage::validate_zip_file( $path . '-missing', 'plugin.zip', 10, 1000 ) );
	}

	public function test_storage_key_is_unguessable_and_path_free(): void {
		$random = str_repeat( 'ab', 16 );
		$key = LocalPackageStorage::build_key( 'voucher-captain', '1.2.3-beta.1+build/../x', $random );
		$this->assertTrue( LocalPackageStorage::is_valid_key( $key ), $key );
		$this->assertStringStartsWith( 'voucher-captain-1.2.3-beta.1-build-', $key );
		$this->assertStringEndsWith( '-' . $random . '.zip', $key );
		$this->assertStringNotContainsString( '/', $key );
		$this->assertStringNotContainsString( '..', $key );

		$this->assertSame( 'voucher-captain-1.2.3-' . $random . '.zip', LocalPackageStorage::build_key( 'voucher-captain', '1.2.3', $random ) );
	}

	public function test_invalid_storage_keys_are_rejected(): void {
		$random = str_repeat( 'ab', 16 );
		foreach ( array(
			'',
			'../voucher-1.0.0-' . $random . '.zip',
			'dir/voucher-1.0.0-' . $random . '.zip',
			'voucher-1.0.0-' . $random . '.php',
			'voucher-1.0.0-' . substr( $random, 2 ) . '.zip',
			'voucher-1..0-' . $random . '.zip',
			'voucher-1.0.0.zip',
			"voucher-1.0.0-{$random}.zip\n",
		) as $key ) {
			$this->assertFalse( LocalPackageStorage::is_valid_key( $key ), $key );
		}
	}

	public function test_release_placeholder_url_is_parsed_for_all_permalink_modes(): void {
		$this->assertSame( 12, PackageStream::parse_release_url( 'https://shop.example.test/wp-json/wplc/v1/packages/release/12' ) );
		$this->assertSame( 12, PackageStream::parse_release_url( 'https://shop.example.test/index.php?rest_route=/wplc/v1/packages/release/12' ) );
		$this->assertSame( 12, PackageStream::parse_release_url( 'https://shop.example.test/?rest_route=%2Fwplc%2Fv1%2Fpackages%2Frelease%2F12' ) );
		$this->assertSame( 0, PackageStream::parse_release_url( 'https://cdn.example.test/product-1.2.3.zip' ) );
		$this->assertSame( 0, PackageStream::parse_release_url( 'https://shop.example.test/wp-json/wplc/v1/packages/release/0' ) );
		$this->assertSame( 0, PackageStream::parse_release_url( 'https://shop.example.test/wp-json/wplc/v1/packages/release/12/extra' ) );
		$this->assertSame( 0, PackageStream::parse_release_url( 'https://shop.example.test/wp-json/wplc/v1/packages/' . str_repeat( 'a', 64 ) ) );
	}

	public function test_trusted_url_rules_match_the_update_api(): void {
		$hosts = array( 'Shop.Example.test', ' cdn.example.test ', '', 5 );
		$this->assertTrue( PackageStream::url_matches_hosts( 'https://shop.example.test/wp-json/wplc/v1/packages/abc', $hosts ) );
		$this->assertTrue( PackageStream::url_matches_hosts( 'https://CDN.example.test:443/a.zip?sig=1', $hosts ) );
		$this->assertFalse( PackageStream::url_matches_hosts( 'http://shop.example.test/a.zip', $hosts ) );
		$this->assertFalse( PackageStream::url_matches_hosts( 'https://shop.example.test:8443/a.zip', $hosts ) );
		$this->assertFalse( PackageStream::url_matches_hosts( 'https://user:pw@shop.example.test/a.zip', $hosts ) );
		$this->assertFalse( PackageStream::url_matches_hosts( 'https://shop.example.test/a.zip#frag', $hosts ) );
		$this->assertFalse( PackageStream::url_matches_hosts( 'https://evil.example.test/a.zip', $hosts ) );
		$this->assertFalse( PackageStream::url_matches_hosts( 'https://shop.example.test.evil.test/a.zip', $hosts ) );
		$this->assertFalse( PackageStream::url_matches_hosts( '', $hosts ) );
		$this->assertFalse( PackageStream::url_matches_hosts( 'https://shop.example.test/a.zip', array() ) );
	}

	// B-174: keys the catalog accepts produce a valid storage key.
	public function test_storage_key_can_be_built_for_every_catalog_product_key(): void {
		foreach ( array( 'x', '_pro', '-pro', 'voucher-captain', '---' ) as $product_key ) {
			$key = LocalPackageStorage::build_key( $product_key, '1.2.3-beta1', str_repeat( 'a', 32 ) );
			$this->assertTrue( LocalPackageStorage::is_valid_key( $key ), $product_key . ' => ' . $key );
		}
		$this->assertFalse( LocalPackageStorage::is_valid_key( LocalPackageStorage::build_key( 'x', '', str_repeat( 'a', 32 ) ) ), 'An empty version never yields a valid key.' );
	}

	// B-53: the headers WordPress reads from the main plugin file.
	public function test_plugin_headers_are_parsed_like_wordpress_does(): void {
		$headers = LocalPackageStorage::parse_plugin_headers( "<?php\r\n/**\r\n * Plugin Name: Voucher Captain Pro\r\n * Version:  1.4.0 \r\n * Requires at least: 6.5\r\n * Requires PHP: 7.4\r\n */\r\n" );
		$this->assertSame(
			array(
				'name'         => 'Voucher Captain Pro',
				'version'      => '1.4.0',
				'requires_php' => '7.4',
				'requires_wp'  => '6.5',
			),
			$headers
		);
		$single = LocalPackageStorage::parse_plugin_headers( '<?php /* Plugin Name: One Line */ // Version: 9.9.9' );
		$this->assertSame( 'One Line', $single['name'] );
		$none = LocalPackageStorage::parse_plugin_headers( "<?php\n\$version = 'Version: nope';\n" );
		$this->assertSame( '', $none['name'] );
		$this->assertSame( '', LocalPackageStorage::parse_plugin_headers( "<?php\n// Plugin Name: X\n" )['version'] );
	}

	// T-135: what the HTTP probe of the package directory means.
	public function test_probe_answer_is_classified(): void {
		$this->assertSame( 'exposed', LocalPackageStorage::classify_probe( 200, 'wplc-probe-abc', 'wplc-probe-abc' ) );
		$this->assertSame( 'protected', LocalPackageStorage::classify_probe( 403, 'Forbidden', 'wplc-probe-abc' ) );
		$this->assertSame( 'protected', LocalPackageStorage::classify_probe( 404, '', 'wplc-probe-abc' ) );
		// A 200 without the marker is a soft 404 or a login page, not proof of exposure.
		$this->assertSame( 'unknown', LocalPackageStorage::classify_probe( 200, '<html>Not found</html>', 'wplc-probe-abc' ) );
		$this->assertSame( 'unknown', LocalPackageStorage::classify_probe( 500, '', 'wplc-probe-abc' ) );
		$this->assertSame( 'unknown', LocalPackageStorage::classify_probe( 302, '', 'wplc-probe-abc' ) );
	}

	// A-111: resuming an interrupted download.
	public function test_byte_ranges_are_parsed(): void {
		$this->assertNull( PackageStream::parse_range( '', 1000 ) );
		$this->assertSame( array( 200, 999 ), PackageStream::parse_range( 'bytes=200-', 1000 ) );
		$this->assertSame( array( 0, 99 ), PackageStream::parse_range( 'bytes=0-99', 1000 ) );
		$this->assertSame( array( 900, 999 ), PackageStream::parse_range( 'bytes=-100', 1000 ) );
		$this->assertSame( array( 500, 999 ), PackageStream::parse_range( 'bytes=500-5000', 1000 ) );
		// The whole file, several ranges, nonsense: send everything.
		foreach ( array( 'bytes=0-', 'bytes=0-999', 'bytes=0-10,20-30', 'bytes=1000-', 'bytes=50-10', 'bytes=-0', 'bytes=-', 'items=0-1', 'bytes=a-b' ) as $header ) {
			$this->assertNull( PackageStream::parse_range( $header, 1000 ), $header );
		}
	}

	// B-52: Content-MD5 on a full response; B-19/A-111: ranges are announced.
	public function test_response_headers_of_a_package(): void {
		$full = PackageStream::response_headers( 1000, 'voucher-captain-1.1.0.zip', 'XUFAKrxLKna5cZ2REBfFkg==', null );
		$this->assertSame( 200, $full['status'] );
		$this->assertSame( 'application/zip', $full['headers']['Content-Type'] );
		$this->assertSame( '1000', $full['headers']['Content-Length'] );
		$this->assertSame( 'XUFAKrxLKna5cZ2REBfFkg==', $full['headers']['Content-MD5'] );
		$this->assertSame( 'bytes', $full['headers']['Accept-Ranges'] );
		$this->assertSame( 'no-store', $full['headers']['Cache-Control'] );
		$this->assertSame( 'attachment; filename="voucher-captain-1.1.0.zip"', $full['headers']['Content-Disposition'] );

		$part = PackageStream::response_headers( 1000, 'x.zip', 'XUFAKrxLKna5cZ2REBfFkg==', array( 200, 999 ) );
		$this->assertSame( 206, $part['status'] );
		$this->assertSame( 'bytes 200-999/1000', $part['headers']['Content-Range'] );
		$this->assertSame( '800', $part['headers']['Content-Length'] );
		$this->assertArrayNotHasKey( 'Content-MD5', $part['headers'], 'The digest describes the whole file only.' );

		$this->assertArrayNotHasKey( 'Content-MD5', PackageStream::response_headers( 1000, 'x.zip', '', null )['headers'] );
	}

	// B-25: only real offload headers on one line are accepted from the filter.
	public function test_offload_header_is_validated(): void {
		$this->assertSame( 'X-Accel-Redirect: /wplc-protected/a.zip', PackageStream::sanitize_offload_header( 'X-Accel-Redirect: /wplc-protected/a.zip' ) );
		$this->assertSame( 'X-Sendfile: /var/lib/wplc/a.zip', PackageStream::sanitize_offload_header( 'X-Sendfile: /var/lib/wplc/a.zip' ) );
		foreach ( array( '', null, true, array(), 'Location: https://evil.example', "X-Sendfile: /a.zip\r\nSet-Cookie: x=1", 'X-Sendfile:/a.zip', 'X-Accel-Redirect: ' ) as $header ) {
			$this->assertSame( '', PackageStream::sanitize_offload_header( $header ) );
		}
	}
}
