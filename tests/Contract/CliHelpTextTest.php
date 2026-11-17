<?php
/**
 * WP-CLI help comes from docblocks; the vendor reads it, so it must not cite internal task IDs or unshipped docs.
 *
 * @package WPLC
 */

namespace WPLC\Tests\Contract;

use PHPUnit\Framework\TestCase;

final class CliHelpTextTest extends TestCase {

	/**
	 * Docblocks WP-CLI turns into help text: the one of each command class and of each public method.
	 *
	 * @return array<string,string> "file:line" => docblock.
	 */
	private static function help_docblocks(): array {
		$found = array();
		foreach ( glob( dirname( __DIR__, 2 ) . '/src/CLI/*.php' ) as $file ) {
			$code = (string) file_get_contents( $file );
			if ( ! preg_match_all( '#(/\*\*(?:(?!\*/).)*\*/)\s*(?:final\s+)?(?:class\s|public\s+(?:static\s+)?function\s)#s', $code, $matches, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			foreach ( $matches[1] as $match ) {
				$line = substr_count( substr( $code, 0, $match[1] ), "\n" ) + 1;
				$found[ basename( $file ) . ':' . $line ] = $match[0];
			}
		}
		return $found;
	}

	/**
	 * Whether a text cites an internal task or decision ID, or a doc that is not shipped.
	 */
	private static function problem( string $text ): ?string {
		if ( preg_match( '/\b(?:T|A|B|P|R|PK|AL|INH|PROD|FR-LIC|WFC-LIC)-\d+\b/', $text, $m ) ) {
			return 'internal ID ' . $m[0];
		}
		if ( preg_match( '#docs/(?!manual/|catalog-file\.schema\.json)[A-Za-z0-9_./-]+#', $text, $m ) ) {
			return 'unshipped doc ' . $m[0];
		}
		return null;
	}

	public function test_the_check_itself_catches_ids_and_unshipped_docs(): void {
		$this->assertNotNull( self::problem( 'Reset the sites (B-172).' ) );
		$this->assertNotNull( self::problem( 'See docs/ENTITLEMENTS.md.' ) );
		$this->assertNull( self::problem( 'See docs/manual/10-wp-cli.md and docs/catalog-file.schema.json.' ) );
		$this->assertNull( self::problem( 'Uses SHA-256 and Ed25519.' ) );
	}

	public function test_wp_cli_help_cites_no_internal_ids_or_unshipped_docs(): void {
		$blocks = self::help_docblocks();
		$this->assertGreaterThan( 10, count( $blocks ), 'The scan found the command docblocks.' );
		$problems = array();
		foreach ( $blocks as $where => $block ) {
			$problem = self::problem( $block );
			if ( null !== $problem ) {
				$problems[] = $where . ': ' . $problem;
			}
		}
		$this->assertSame( array(), $problems );
	}
}
