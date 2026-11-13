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
}
