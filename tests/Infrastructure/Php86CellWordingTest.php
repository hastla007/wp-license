<?php
/**
 * P6-M6 / UEB-07: PHP 8.6 is released; its CI cells stay experimental until the owner decides.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;

final class Php86CellWordingTest extends TestCase {

	public function test_workflow_and_compatibility_no_longer_call_php_8_6_a_nightly_build(): void {
		$root     = dirname( __DIR__, 2 );
		$workflow = (string) file_get_contents( $root . '/.github/workflows/phpunit.yml' );
		$compat   = (string) file_get_contents( $root . '/docs/COMPATIBILITY.md' );
		foreach ( array( 'phpunit.yml' => $workflow, 'COMPATIBILITY.md' => $compat ) as $name => $text ) {
			$this->assertStringNotContainsString( 'nightly', $text, $name );
			$this->assertStringContainsString( 'released 19 Nov 2026', $text, $name );
			$this->assertStringContainsString( 'UEB-07', $text, $name );
		}
		// The matrix keeps its meaning: both 8.6 cells are still experimental.
		$this->assertStringContainsString( '- { php: "8.6", experimental: true }', $workflow );
		$this->assertStringContainsString( 'php: "8.6", db: "mysql", hpos: "1", experimental: true }', $workflow );
		$this->assertStringContainsString( 'continue-on-error: ${{ matrix.experimental }}', $workflow );
	}
}
