<?php
/**
 * A-134: every action and filter of the plugin is listed in docs/EXTENSION-POINTS.md.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;

final class ExtensionPointsDocTest extends TestCase {

	public function test_every_hook_in_src_is_documented(): void {
		$root   = dirname( __DIR__, 2 );
		$output = array();
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $root . '/bin/list-hooks.php' ) . ' --names', $output, $code );
		$this->assertSame( 0, $code );
		$this->assertGreaterThan( 50, count( $output ), 'The hook list looks incomplete.' );

		$doc     = (string) file_get_contents( $root . '/docs/EXTENSION-POINTS.md' );
		$missing = array();
		foreach ( array_filter( $output ) as $hook ) {
			if ( false === strpos( $doc, '| `' . $hook . '` |' ) ) {
				$missing[] = $hook;
			}
		}

		$this->assertSame( array(), $missing, 'Run php bin/list-hooks.php and add these hooks to docs/EXTENSION-POINTS.md.' );
	}

	public function test_the_doc_names_no_hook_that_does_not_exist(): void {
		$root   = dirname( __DIR__, 2 );
		$output = array();
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $root . '/bin/list-hooks.php' ) . ' --names', $output, $code );
		preg_match_all( '/^\| `([^`]+)` \|/m', (string) file_get_contents( $root . '/docs/EXTENSION-POINTS.md' ), $matches );

		$this->assertSame( array(), array_values( array_diff( $matches[1], $output ) ) );
	}
}
