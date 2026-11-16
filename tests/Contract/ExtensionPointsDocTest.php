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

	/** R-3.4 / R-3.9 of the suite spec: every action names the version since which it exists and whether it fires after the commit. */
	public function test_every_action_has_a_since_version_and_an_after_commit_entry(): void {
		$doc = (string) file_get_contents( dirname( __DIR__, 2 ) . '/docs/EXTENSION-POINTS.md' );
		$this->assertSame( 1, preg_match( '/## Actions\n\n\| Hook \| Where \| Since \| After commit \| Summary \|\n\| --- [^\n]*\n((?:\|[^\n]*\n)+)/', $doc, $table ) );
		$rows = array();
		foreach ( explode( "\n", trim( $table[1] ) ) as $row ) {
			$this->assertSame( 1, preg_match( '/^\| `([^`]+)` \| `[^`]+` \| (\d+\.\d+\.\d+) \| (yes|–) \| /u', $row, $match ), 'Row without since/after-commit: ' . $row );
			$rows[ $match[1] ] = $match[3];
		}
		$this->assertGreaterThan( 40, count( $rows ) );
		foreach ( array( 'wplc/license_issued_followup', 'wplc/license_status_changed', 'wplc/license_plan_changed', 'wplc/activation_created', 'wplc/activation_removed', 'wplc/site_limit_reached', 'wplc/release_published', 'wplc/release_withdrawn' ) as $action ) {
			$this->assertSame( 'yes', $rows[ $action ] ?? null, $action . ' is promised after commit.' );
		}
	}
}
