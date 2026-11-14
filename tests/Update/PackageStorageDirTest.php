<?php
use PHPUnit\Framework\TestCase;
use WPLC\Update\LocalPackageStorage;

final class PackageStorageDirTest extends TestCase {
	private const ROOTS = array( '/var/www/html/', '/var/www/html/wp-content', '/var/www/html/wp-content/uploads' );

	public function test_valid_directory_outside_web_root(): void {
		$this->assertSame( '', LocalPackageStorage::validate_custom_dir( '/var/lib/wplc-packages', self::ROOTS ) );
		$this->assertSame( '', LocalPackageStorage::validate_custom_dir( '/var/www/private/wplc/', self::ROOTS ) );
		$this->assertSame( '', LocalPackageStorage::validate_custom_dir( 'C:\\wplc\\packages', self::ROOTS ) );
		// A sibling with a common prefix is not inside the web root.
		$this->assertSame( '', LocalPackageStorage::validate_custom_dir( '/var/www/html-private', self::ROOTS ) );
	}

	public function test_relative_paths_are_rejected(): void {
		$this->assertSame( 'relative', LocalPackageStorage::validate_custom_dir( 'wplc-packages', self::ROOTS ) );
		$this->assertSame( 'relative', LocalPackageStorage::validate_custom_dir( '../private', self::ROOTS ) );
		$this->assertSame( 'relative', LocalPackageStorage::validate_custom_dir( '   ', self::ROOTS ) );
	}

	public function test_traversal_and_root_are_rejected(): void {
		$this->assertSame( 'traversal', LocalPackageStorage::validate_custom_dir( '/var/lib/../www/html/x', self::ROOTS ) );
		$this->assertSame( 'traversal', LocalPackageStorage::validate_custom_dir( '/var/lib/./wplc', self::ROOTS ) );
		$this->assertSame( 'root', LocalPackageStorage::validate_custom_dir( '/', self::ROOTS ) );
		$this->assertSame( 'root', LocalPackageStorage::validate_custom_dir( 'C:\\', self::ROOTS ) );
	}

	public function test_web_reachable_directories_are_rejected(): void {
		$this->assertSame( 'web_reachable', LocalPackageStorage::validate_custom_dir( '/var/www/html', self::ROOTS ) );
		$this->assertSame( 'web_reachable', LocalPackageStorage::validate_custom_dir( '/var/www/html/private', self::ROOTS ) );
		$this->assertSame( 'web_reachable', LocalPackageStorage::validate_custom_dir( '/var/www/html/wp-content/uploads/wplc', self::ROOTS ) );
		$this->assertSame( 'web_reachable', LocalPackageStorage::validate_custom_dir( '/var//www/html//wp-content/', self::ROOTS ) );
	}

	public function test_symlink_into_web_root_is_rejected(): void {
		if ( ! function_exists( 'symlink' ) ) {
			$this->markTestSkipped( 'symlink() is not available.' );
		}
		$base = sys_get_temp_dir() . '/wplc-dir-' . bin2hex( random_bytes( 4 ) );
		mkdir( $base . '/webroot/uploads', 0777, true );
		$link = $base . '/outside-link';
		if ( ! @symlink( $base . '/webroot/uploads', $link ) ) {
			rmdir( $base . '/webroot/uploads' );
			rmdir( $base . '/webroot' );
			rmdir( $base );
			$this->markTestSkipped( 'Cannot create symlinks here.' );
		}
		try {
			$this->assertSame( 'web_reachable', LocalPackageStorage::validate_custom_dir( $link, array( realpath( $base . '/webroot' ) ) ) );
		} finally {
			unlink( $link );
			rmdir( $base . '/webroot/uploads' );
			rmdir( $base . '/webroot' );
			rmdir( $base );
		}
	}

	public function test_resolve_dir_prefers_valid_constant(): void {
		$default = '/var/www/html/wp-content/uploads/wplc-packages/';

		$this->assertSame(
			array(
				'dir' => '/var/www/html/wp-content/uploads/wplc-packages',
				'source' => 'default',
				'constant_error' => '',
			),
			LocalPackageStorage::resolve_dir( null, $default, self::ROOTS )
		);
		$this->assertSame(
			array(
				'dir' => '/srv/wplc-packages',
				'source' => 'constant',
				'constant_error' => '',
			),
			LocalPackageStorage::resolve_dir( ' /srv/wplc-packages/ ', $default, self::ROOTS )
		);
	}

	public function test_resolve_dir_ignores_invalid_constant_and_reports_it(): void {
		$default = '/var/www/html/wp-content/uploads/wplc-packages';
		$resolved = LocalPackageStorage::resolve_dir( '/var/www/html/wp-content/packages', $default, self::ROOTS );
		$this->assertSame( $default, $resolved['dir'] );
		$this->assertSame( 'default', $resolved['source'] );
		$this->assertSame( 'web_reachable', $resolved['constant_error'] );

		$this->assertSame( 'relative', LocalPackageStorage::resolve_dir( '', $default, self::ROOTS )['constant_error'] );
	}

	public function test_is_inside_any(): void {
		$this->assertTrue( LocalPackageStorage::is_inside_any( '/a/b/c', array( '/a/b' ) ) );
		$this->assertTrue( LocalPackageStorage::is_inside_any( '/a/b', array( '/a/b/' ) ) );
		$this->assertTrue( LocalPackageStorage::is_inside_any( 'C:\\site\\wp\\x', array( 'C:/site/wp' ) ) );
		$this->assertFalse( LocalPackageStorage::is_inside_any( '/a/bc', array( '/a/b' ) ) );
		$this->assertFalse( LocalPackageStorage::is_inside_any( '/a', array( '/a/b', '' ) ) );
	}

	public function test_public_url_for_dir(): void {
		$map = array(
			'/var/www/html/wp-content/uploads' => 'https://shop.example/wp-content/uploads',
			'/var/www/html/wp-content' => 'https://shop.example/wp-content',
			'/var/www/html/' => 'https://shop.example/',
		);
		$this->assertSame( 'https://shop.example/wp-content/uploads/wplc-packages', LocalPackageStorage::public_url_for_dir( '/var/www/html/wp-content/uploads/wplc-packages', $map ) );
		$this->assertSame( 'https://shop.example/wp-content/uploads/sites/2/wplc-packages', LocalPackageStorage::public_url_for_dir( '/var/www/html/wp-content/uploads/sites/2/wplc-packages/', $map ) );
		$this->assertSame( 'https://shop.example/private%20dir', LocalPackageStorage::public_url_for_dir( '/var/www/html/private dir', $map ) );
		$this->assertNull( LocalPackageStorage::public_url_for_dir( '/var/lib/wplc-packages', $map ) );
	}
}
