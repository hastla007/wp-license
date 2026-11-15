<?php
/**
 * A-124: runs the JavaScript test of the copy button when Node.js is available.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;

final class UiScriptTest extends TestCase {

	public function test_copy_button_announces_success_and_failure(): void {
		$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
		if ( '' === $node ) {
			$this->markTestSkipped( 'Node.js is not installed.' );
		}
		$script = dirname( __DIR__ ) . '/js/wplc-ui.test.js';
		$output = array();
		$status = 0;
		exec( escapeshellarg( $node ) . ' --test ' . escapeshellarg( $script ) . ' 2>&1', $output, $status );

		$this->assertSame( 0, $status, implode( "\n", $output ) );
		$this->assertStringContainsString( '# pass 3', implode( "\n", $output ) );
	}
}
