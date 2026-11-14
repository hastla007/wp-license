<?php
use PHPUnit\Framework\TestCase;
use WPLC\Update\LocalPackageStorage;
use WPLC\Update\PackageStorageCheck;

final class PackageStorageCheckTest extends TestCase {
	/** @var string */
	private $dir;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/wplc-probe-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->dir );
	}

	protected function tearDown(): void {
		foreach ( (array) glob( $this->dir . '/*' ) as $file ) {
			unlink( $file );
		}
		rmdir( $this->dir );
	}

	public function test_classify(): void {
		$this->assertSame( PackageStorageCheck::RESULT_EXPOSED, PackageStorageCheck::classify( 200, 'tok', 'tok' ) );
		$this->assertSame( PackageStorageCheck::RESULT_PROTECTED, PackageStorageCheck::classify( 200, '<html>Login</html>', 'tok' ) );
		$this->assertSame( PackageStorageCheck::RESULT_PROTECTED, PackageStorageCheck::classify( 403, 'tok', 'tok' ) );
		$this->assertSame( PackageStorageCheck::RESULT_PROTECTED, PackageStorageCheck::classify( 404, '', 'tok' ) );
		$this->assertSame( PackageStorageCheck::RESULT_UNKNOWN, PackageStorageCheck::classify( 500, '', 'tok' ) );
		$this->assertSame( PackageStorageCheck::RESULT_UNKNOWN, PackageStorageCheck::classify( 0, '', 'tok' ) );
		$this->assertSame( PackageStorageCheck::RESULT_UNKNOWN, PackageStorageCheck::classify( 302, '', 'tok' ) );
	}

	public function test_probe_reports_exposed_when_server_serves_the_canary(): void {
		$dir = $this->dir;
		$requested = '';
		$fetch = static function ( string $url ) use ( $dir, &$requested ): array {
			$requested = $url;
			$file = $dir . '/' . basename( (string) parse_url( $url, PHP_URL_PATH ) );
			return array(
				'code' => 200,
				'body' => (string) file_get_contents( $file ),
			);
		};

		$this->assertSame( PackageStorageCheck::RESULT_EXPOSED, PackageStorageCheck::run_probe( $dir, 'https://shop.example/wp-content/uploads/wplc-packages/', $fetch ) );
		$this->assertMatchesRegularExpression( '#^https://shop\.example/wp-content/uploads/wplc-packages/wplc_canary_[a-f0-9]{32}\.zip$#', $requested );
		$this->assertFalse( LocalPackageStorage::is_valid_key( basename( $requested ) ), 'a canary can never pass as a package key' );
		$this->assertSame( array(), glob( $dir . '/*' ), 'canary is removed' );
	}

	public function test_probe_reports_protected_on_denied_request(): void {
		$fetch = static function (): array {
			return array(
				'code' => 403,
				'body' => 'Forbidden',
			);
		};
		$this->assertSame( PackageStorageCheck::RESULT_PROTECTED, PackageStorageCheck::run_probe( $this->dir, 'https://shop.example/p', $fetch ) );
		$this->assertSame( array(), glob( $this->dir . '/*' ) );
	}

	public function test_probe_reports_unknown_on_transport_error_and_cleans_up(): void {
		$fetch = static function () {
			return null;
		};
		$this->assertSame( PackageStorageCheck::RESULT_UNKNOWN, PackageStorageCheck::run_probe( $this->dir, 'https://shop.example/p', $fetch ) );
		$this->assertSame( array(), glob( $this->dir . '/*' ) );
	}

	public function test_probe_cleans_up_when_fetch_throws(): void {
		$fetch = static function () {
			throw new RuntimeException( 'boom' );
		};
		try {
			PackageStorageCheck::run_probe( $this->dir, 'https://shop.example/p', $fetch );
			$this->fail( 'exception expected' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( array(), glob( $this->dir . '/*' ) );
		}
	}

	public function test_probe_is_unknown_when_directory_is_not_writable(): void {
		$called = false;
		$fetch = static function () use ( &$called ) {
			$called = true;
			return null;
		};
		$this->assertSame( PackageStorageCheck::RESULT_UNKNOWN, PackageStorageCheck::run_probe( $this->dir . '/missing', 'https://shop.example/p', $fetch ) );
		$this->assertFalse( $called );
	}

	public function test_nginx_rule(): void {
		$this->assertSame( 'location ^~ /wp-content/uploads/wplc-packages/ { deny all; }', PackageStorageCheck::nginx_rule( 'https://shop.example/wp-content/uploads/wplc-packages' ) );
		$this->assertSame( 'location ^~ /blog/wp-content/uploads/sites/2/wplc-packages/ { deny all; }', PackageStorageCheck::nginx_rule( 'https://shop.example/blog/wp-content/uploads/sites/2/wplc-packages/' ) );
		$this->assertSame( '', PackageStorageCheck::nginx_rule( 'https://shop.example' ) );
		$this->assertSame( '', PackageStorageCheck::nginx_rule( 'https://shop.example/a;b{c}/' ) );
	}
}
