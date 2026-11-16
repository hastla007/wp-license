<?php
/**
 * PROD-10: the sold plugin ZIP (bin/build-zip.sh) ships the vendor manual docs/manual/ and the catalog
 * file schema it links to, and no internal document (to-do list, plans, reviews, launch evidence,
 * release readiness, ...). The rules live in bin/check-zip-contents.php, which the build runs; the last
 * test runs the real build in a copy of the repository.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/bin/check-zip-contents.php';

final class ReleasePackageTest extends TestCase {

	/** @var string[] Directories to remove after the test. */
	private $temp = array();

	protected function tearDown(): void {
		foreach ( $this->temp as $dir ) {
			exec( 'rm -rf ' . escapeshellarg( $dir ) );
		}
		$this->temp = array();
	}

	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	/** @return string[] File names of the manual chapters in the repository. */
	private static function chapters(): array {
		return wplc_zip_manual_chapters( self::root() . '/docs/manual' );
	}

	/** @return string[] Internal documents: every Markdown file directly under docs/. */
	private static function internal_documents(): array {
		return array_map( 'basename', (array) glob( self::root() . '/docs/*.md' ) );
	}

	/** A valid entry list as the build produces it. */
	private static function valid_entries(): array {
		$entries = array(
			'wp-license/',
			'wp-license/wp-license.php',
			'wp-license/uninstall.php',
			'wp-license/readme.txt',
			'wp-license/vendor/autoload.php',
			'wp-license/src/',
			'wp-license/src/Plugin.php',
			'wp-license/mu-plugins/wplc-site-cleanup.php',
			'wp-license/catalogs/acme-reports-pro.json',
			'wp-license/languages/wp-license-de_DE.mo',
			'wp-license/docs/',
			'wp-license/docs/catalog-file.schema.json',
			'wp-license/docs/manual/',
		);
		foreach ( self::chapters() as $chapter ) {
			$entries[] = 'wp-license/docs/manual/' . $chapter;
		}

		return $entries;
	}

	private static function options(): array {
		return array(
			'manual'           => self::chapters(),
			'require_mo'       => true,
			'require_catalogs' => true,
		);
	}

	public function test_the_repository_has_a_manual_and_internal_documents_to_keep_out(): void {
		$this->assertContains( 'README.md', self::chapters() );
		$this->assertGreaterThanOrEqual( 13, count( self::chapters() ) );
		foreach ( array( 'TODO.md', 'LAUNCH-EVIDENCE.md', 'RELEASE-READINESS.md' ) as $internal ) {
			$this->assertContains( $internal, self::internal_documents() );
		}
	}

	public function test_a_package_with_the_manual_and_without_internal_documents_passes(): void {
		$this->assertSame( array(), wplc_zip_contents_problems( self::valid_entries(), self::options() ) );
	}

	public function test_every_internal_document_is_refused(): void {
		foreach ( self::internal_documents() as $document ) {
			foreach ( array( 'wp-license/docs/' . $document, 'wp-license/docs/manual/' . $document ) as $entry ) {
				$problems = wplc_zip_contents_problems( array_merge( self::valid_entries(), array( $entry ) ), self::options() );
				$this->assertCount( 1, $problems, $entry );
				$this->assertStringContainsString( $entry, $problems[0] );
			}
		}
		foreach ( array( 'wp-license/TODO.md', 'wp-license/src/AUDIT-2026.md', 'wp-license/docs/REVIEW-wave5.md', 'wp-license/docs/openapi-v1.json', 'wp-license/docs/manual/notes/draft.md' ) as $entry ) {
			$this->assertNotSame( array(), wplc_zip_contents_problems( array_merge( self::valid_entries(), array( $entry ) ), self::options() ), $entry );
		}
	}

	public function test_a_missing_chapter_and_the_other_rules_are_reported(): void {
		$chapters = self::chapters();
		$missing  = array_values( array_diff( self::valid_entries(), array( 'wp-license/docs/manual/' . end( $chapters ) ) ) );
		$this->assertSame( array( 'The ZIP is missing wp-license/docs/manual/' . end( $chapters ) . '.' ), wplc_zip_contents_problems( $missing, self::options() ) );

		$without_schema = array_values( array_diff( self::valid_entries(), array( 'wp-license/docs/catalog-file.schema.json' ) ) );
		$this->assertCount( 1, wplc_zip_contents_problems( $without_schema, self::options() ) );

		foreach ( array( 'wp-license/tests/bootstrap.php', 'wp-license/bin/build-zip.sh', 'wp-license/composer.json', 'wp-license/CHANGELOG.md', 'wp-license/README.md', 'wp-license/.distignore', 'other/file.php' ) as $entry ) {
			$this->assertCount( 1, wplc_zip_contents_problems( array_merge( self::valid_entries(), array( $entry ) ), self::options() ), $entry );
		}

		$no_mo = array_values( preg_grep( '#\.mo$#', self::valid_entries(), PREG_GREP_INVERT ) );
		$this->assertCount( 1, wplc_zip_contents_problems( $no_mo, self::options() ) );
		$this->assertSame( array(), wplc_zip_contents_problems( $no_mo, array( 'manual' => self::chapters() ) ) );
	}

	public function test_the_build_ships_the_manual_and_no_internal_document(): void {
		foreach ( array( 'composer', 'zip', 'unzip', 'sha256sum' ) as $tool ) {
			exec( 'command -v ' . escapeshellarg( $tool ) . ' 2>/dev/null', $unused, $status );
			if ( 0 !== $status ) {
				$this->markTestSkipped( $tool . ' is not installed.' );
			}
		}
		$copy = sys_get_temp_dir() . '/wplc-build-test-' . bin2hex( random_bytes( 6 ) );
		mkdir( $copy );
		$this->temp[] = $copy;
		// The whole docs/ folder is copied on purpose: the build has to pick the manual out of it.
		foreach ( array( 'wp-license.php', 'uninstall.php', 'readme.txt', 'composer.json', 'src', 'mu-plugins', 'catalogs', 'languages', 'assets', 'templates', 'docs', 'bin', 'packaging' ) as $path ) {
			if ( file_exists( self::root() . '/' . $path ) ) {
				exec( 'cp -R ' . escapeshellarg( self::root() . '/' . $path ) . ' ' . escapeshellarg( $copy . '/' ), $unused, $status );
				$this->assertSame( 0, $status, $path );
			}
		}

		exec( 'bash ' . escapeshellarg( $copy . '/bin/build-zip.sh' ) . ' 2>&1', $output, $status );
		$this->assertSame( 0, $status, implode( "\n", $output ) );
		$zips = glob( $copy . '/dist/wp-license-captain-*.zip' );
		$this->assertCount( 1, $zips );

		$archive = new ZipArchive();
		$this->assertTrue( $archive->open( $zips[0] ) );
		$entries = array();
		for ( $i = 0; $i < $archive->numFiles; $i++ ) {
			$entries[] = (string) $archive->getNameIndex( $i );
		}
		foreach ( self::chapters() as $chapter ) {
			$this->assertSame(
				file_get_contents( self::root() . '/docs/manual/' . $chapter ),
				$archive->getFromName( 'wp-license/docs/manual/' . $chapter ),
				'The manual chapter ' . $chapter . ' ships unchanged.'
			);
		}
		$this->assertSame( file_get_contents( self::root() . '/docs/catalog-file.schema.json' ), $archive->getFromName( 'wp-license/docs/catalog-file.schema.json' ) );
		$archive->close();

		$documents = preg_grep( '#^wp-license/docs/.#', $entries );
		$expected  = array_merge( array( 'wp-license/docs/catalog-file.schema.json', 'wp-license/docs/manual/' ), preg_filter( '/^/', 'wp-license/docs/manual/', self::chapters() ) );
		sort( $documents );
		sort( $expected );
		$this->assertSame( $expected, $documents, 'Below docs/ only the manual and the schema.' );
		foreach ( self::internal_documents() as $internal ) {
			$this->assertSame( array(), preg_grep( '#(^|/)' . preg_quote( $internal, '#' ) . '$#i', $entries ), $internal . ' must not ship.' );
		}
		$this->assertSame( array(), wplc_zip_contents_problems( $entries, self::options() ) );
	}
}
