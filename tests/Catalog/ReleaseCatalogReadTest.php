<?php
/**
 * PROD-03: reading the catalog file from a release package, safely.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\Catalog\CatalogFile;
use WPLC\Catalog\ReleaseCatalog;

final class ReleaseCatalogReadTest extends TestCase {

	/**
	 * @var string[]
	 */
	private $files = array();

	protected function tearDown(): void {
		foreach ( $this->files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		parent::tearDown();
	}

	/** @param array<string,string> $entries Name => content. */
	private function zip( array $entries ): string {
		$path = tempnam( sys_get_temp_dir(), 'wplc-rc-' );
		$zip  = new ZipArchive();
		$this->assertTrue( $zip->open( $path, ZipArchive::OVERWRITE ) );
		foreach ( $entries as $name => $content ) {
			$zip->addFromString( $name, $content );
		}
		$zip->close();
		$this->files[] = $path;

		return $path;
	}

	public function test_the_file_is_read_from_the_plugin_folder_only(): void {
		$json = '{"format":1}';
		$this->assertSame( $json, ReleaseCatalog::read( $this->zip( array( 'my-addon/my-addon.php' => '<?php', 'my-addon/wplc-catalog.json' => $json ) ), 'my-addon' ) );

		// A package without the file: nothing.
		$this->assertNull( ReleaseCatalog::read( $this->zip( array( 'my-addon/my-addon.php' => '<?php' ) ), 'my-addon' ) );
		// Anywhere else it is not the catalog file of the add-on.
		foreach ( array( 'wplc-catalog.json', 'my-addon/includes/wplc-catalog.json', 'other/wplc-catalog.json', 'my-addon/WPLC-CATALOG.JSON', 'my-addon/../my-addon/wplc-catalog.json', 'my-addon/wplc-catalog.json/x' ) as $name ) {
			$this->assertNull( ReleaseCatalog::read( $this->zip( array( 'my-addon/my-addon.php' => '<?php', $name => $json ) ), 'my-addon' ), $name );
		}
		$this->assertNull( ReleaseCatalog::read( $this->zip( array( 'my-addon/wplc-catalog.json' => $json ) ), '' ) );
	}

	public function test_a_large_or_inflating_file_is_refused_before_it_is_read(): void {
		// One megabyte of zeros compresses to about a kilobyte: the declared size decides, not the size in the archive.
		$bomb = $this->zip( array( 'my-addon/wplc-catalog.json' => str_repeat( '0', 1048576 ) ) );
		$this->assertLessThan( 10000, filesize( $bomb ) );
		$result = ReleaseCatalog::read( $bomb, 'my-addon' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'file_too_large', $result->get_error_code() );

		$limit = $this->zip( array( 'my-addon/wplc-catalog.json' => str_repeat( ' ', CatalogFile::MAX_BYTES ) ) );
		$this->assertSame( CatalogFile::MAX_BYTES, strlen( (string) ReleaseCatalog::read( $limit, 'my-addon' ) ), 'Exactly the limit is still read.' );
		$over = $this->zip( array( 'my-addon/wplc-catalog.json' => str_repeat( ' ', CatalogFile::MAX_BYTES + 1 ) ) );
		$this->assertSame( 'file_too_large', ReleaseCatalog::read( $over, 'my-addon' )->get_error_code() );
	}

	public function test_a_file_that_is_no_archive_is_reported(): void {
		$path = tempnam( sys_get_temp_dir(), 'wplc-rc-' );
		file_put_contents( $path, 'not a zip' );
		$this->files[] = $path;
		$this->assertSame( 'catalog_unreadable', ReleaseCatalog::read( $path, 'my-addon' )->get_error_code() );
	}

	public function test_nothing_is_written_to_disk(): void {
		$dir    = sys_get_temp_dir();
		$before = count( (array) glob( $dir . '/*' ) );
		$path   = $this->zip( array( 'my-addon/wplc-catalog.json' => '{"format":1}', 'my-addon/big.bin' => str_repeat( 'x', 200000 ) ) );
		$after  = count( (array) glob( $dir . '/*' ) );
		ReleaseCatalog::read( $path, 'my-addon' );
		$this->assertSame( $after, count( (array) glob( $dir . '/*' ) ), 'Reading extracts nothing.' );
		$this->assertSame( $before + 1, $after );
	}
}
