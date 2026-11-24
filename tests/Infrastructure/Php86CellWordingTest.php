<?php
/**
 * P6-M6 / UEB-07: PHP 8.6 is released; since the owner decision of 24 Nov 2026 its CI cells are regular.
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
	}

	public function test_php_8_6_cells_are_regular(): void {
		$root = dirname( __DIR__, 2 );
		$read = static function ( string $file ) use ( $root ): string {
			return (string) file_get_contents( $root . '/' . $file );
		};
		$workflow = $read( '.github/workflows/phpunit.yml' );
		// Both 8.6 cells run as ordinary matrix cells: a failure fails the run and wordpress-integration.
		$this->assertStringContainsString( 'php: ["8.5", "8.6"]', $workflow );
		$this->assertStringContainsString( '- { wp: "latest", wc: "11.3.0", php: "8.6", db: "mysql", hpos: "1" }', $workflow );
		$this->assertStringNotContainsString( 'continue-on-error', $workflow );
		$this->assertStringNotContainsString( 'experimental', $workflow );
		foreach ( array( 'docs/COMPATIBILITY.md', 'docs/QUALITY-GATES.md', 'README.md' ) as $file ) {
			$this->assertStringNotContainsString( 'experimental', $read( $file ), $file );
		}
		$this->assertStringContainsString( '`unit (8.6)`', $read( 'docs/QUALITY-GATES.md' ) );
	}
}
