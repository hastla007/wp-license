<?php
/**
 * PROD-10: the vendor manual in docs/manual/ ships with the sold plugin, so it must stay vendor-neutral
 * (no repositories or products of the original vendor, no internal task or decision IDs, no internal
 * documents), self-contained (links only to its own chapters and to the catalog file schema that ships
 * next to it) and correct where it can be checked mechanically (the catalog file example is valid).
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\Catalog\CatalogFile;

require_once dirname( __DIR__ ) . '/Admin/wp-function-stubs.php';

final class VendorManualTest extends TestCase {

	/**
	 * Forbidden references, matched case-insensitively. Label => pattern.
	 *
	 * @var array<string,string>
	 */
	private const FORBIDDEN = array(
		'repository owner'          => '/hastla007/i',
		'Captain Pro repository'    => '/captain[- _]?pro/i',
		'Captain products'          => '/\b(voucher|pass|affiliate|workflow|form|review|course)[- _]?captain\b/i',
		'client plugin name'        => '/license captain client/i',
		'customer projects'         => '/boating[- _]?school|learn[- _]?boating/i',
		'task and decision IDs'     => '/\b(T|PROD|INH|R|PK|AL|A|B|P|WFC-LIC)-\d+\b|\bPROD-Q\d+\b|\bE8b?\b/i',
		'internal documents'        => '/\b(TODO|LAUNCH-EVIDENCE|RELEASE-READINESS|PRODUCT-PLAN|IMPLEMENTATION-PLAN|QUALITY-GATES|BROWSER-ACCEPTANCE|STRIPE-TEST-PLAN|CLIENT-REPOS|PRODUCT-COMPATIBILITY)(\.md)?\b/i',
		'audits and evidence'       => '/\baudits\b|\baudit (report|finding)s?\b|\bevidence\b/i',
		'internal decisions'        => '/owner decision|offene entscheidungen/i',
		'repository paths'          => '/\b(tests|tools|examples)\/|docs\/[A-Z-]+\.md/',
	);

	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Files of the manual (and the schema that ships with it), path => content.
	 *
	 * @return array<string,string>
	 */
	private static function files(): array {
		$files = array();
		foreach ( glob( self::root() . '/docs/manual/*.md' ) as $file ) {
			$files[ 'docs/manual/' . basename( $file ) ] = (string) file_get_contents( $file );
		}
		$files['docs/catalog-file.schema.json'] = (string) file_get_contents( self::root() . '/docs/catalog-file.schema.json' );

		return $files;
	}

	public function test_the_manual_has_an_index_and_chapters(): void {
		$files = self::files();
		$this->assertArrayHasKey( 'docs/manual/README.md', $files );
		$this->assertGreaterThanOrEqual( 12, count( $files ) - 2, 'Index plus at least twelve chapters.' );
		$this->assertStringContainsString( 'German edition will follow', $files['docs/manual/README.md'], 'PROD-Q10: English now, German later.' );
	}

	public function test_the_manual_contains_no_forbidden_reference(): void {
		$found = array();
		foreach ( self::files() as $path => $content ) {
			foreach ( explode( "\n", $content ) as $number => $line ) {
				foreach ( self::FORBIDDEN as $label => $pattern ) {
					if ( preg_match( $pattern, $line, $match ) ) {
						$found[] = sprintf( '%s:%d: %s ("%s")', $path, $number + 1, $label, $match[0] );
					}
				}
			}
		}
		$this->assertSame( array(), $found, "The vendor manual must stay vendor-neutral:\n" . implode( "\n", $found ) );
	}

	public function test_the_scan_finds_each_kind_of_forbidden_reference(): void {
		$samples = array(
			'See github.com/HASTLA007/wp-license.',
			'Built in captain-pro.',
			'Like Voucher Captain does.',
			'The License Captain Client plugin.',
			'For LearnBoating.',
			'Fixed in t-150.',
			'See docs/TODO.md.',
			'As the audits showed.',
			'Owner decision of 16 Nov.',
			'tests/Client/ClientPackageTest.php',
		);
		foreach ( $samples as $sample ) {
			$hit = false;
			foreach ( self::FORBIDDEN as $pattern ) {
				$hit = $hit || 1 === preg_match( $pattern, $sample );
			}
			$this->assertTrue( $hit, 'Not found: ' . $sample );
		}
		foreach ( array( 'The audit log of the license.', 'WP License Captain', 'AES-256-GCM, SHA-256 and UTF-8.', 'acme_reports.pdf_export' ) as $allowed ) {
			foreach ( self::FORBIDDEN as $label => $pattern ) {
				$this->assertSame( 0, preg_match( $pattern, $allowed ), $label . ' matches the allowed text: ' . $allowed );
			}
		}
	}

	public function test_every_chapter_is_linked_from_the_index_and_every_link_resolves(): void {
		$files  = self::files();
		$index  = $files['docs/manual/README.md'];
		$broken = array();
		foreach ( array_keys( $files ) as $path ) {
			if ( 0 === strpos( $path, 'docs/manual/' ) && 'docs/manual/README.md' !== $path ) {
				$this->assertStringContainsString( '](' . basename( $path ) . ')', $index, 'The index links ' . $path );
			}
		}
		foreach ( $files as $path => $content ) {
			if ( '.md' !== substr( $path, -3 ) ) {
				continue;
			}
			preg_match_all( '/\]\(([^)\s]+)\)/', $content, $links );
			foreach ( $links[1] as $link ) {
				if ( preg_match( '#^(https?:|mailto:)#', $link ) ) {
					continue;
				}
				$target = (string) strtok( $link, '#' );
				if ( '' === $target ) {
					continue;
				}
				// Only the chapters and the schema ship; a link to any other repository file would break in the ZIP.
				$resolved = self::normalize( dirname( $path ) . '/' . $target );
				if ( ! isset( $files[ $resolved ] ) ) {
					$broken[] = $path . ' -> ' . $link;
				}
			}
		}
		$this->assertSame( array(), $broken, "Links to files that do not ship with the manual:\n" . implode( "\n", $broken ) );
	}

	public function test_the_catalog_file_example_is_valid(): void {
		$chapter = self::files()['docs/manual/03-catalog-files.md'];
		$this->assertSame( 1, preg_match( '/```json\n(\{.*?\n\})\n```/s', $chapter, $match ) );
		$example = CatalogFile::parse( $match[1] );
		$this->assertTrue( $example['ok'], (string) json_encode( $example['errors'] ) );
		$this->assertSame( 'acme-reports-pro', $example['catalog']['product_key'] );
	}

	public function test_the_cli_reference_names_every_command(): void {
		$reference = self::files()['docs/manual/10-wp-cli.md'];
		$commands  = array();
		foreach ( glob( self::root() . '/src/CLI/*.php' ) as $file ) {
			preg_match_all( "/add_command\\(\\s*'(wplc [a-z-]+)'/", (string) file_get_contents( $file ), $found );
			$commands = array_merge( $commands, $found[1] );
		}
		$this->assertNotEmpty( $commands );
		foreach ( $commands as $command ) {
			$this->assertStringContainsString( 'wp ' . $command, $reference, 'Chapter 10 documents `wp ' . $command . '`.' );
		}
	}

	/** Resolve `.` and `..` in a relative path. */
	private static function normalize( string $path ): string {
		$parts = array();
		foreach ( explode( '/', $path ) as $part ) {
			if ( '..' === $part ) {
				array_pop( $parts );
			} elseif ( '' !== $part && '.' !== $part ) {
				$parts[] = $part;
			}
		}

		return implode( '/', $parts );
	}
}
