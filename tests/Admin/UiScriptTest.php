<?php
/**
 * A-124, PK-01: runs the JavaScript tests of the admin scripts when Node.js is available.
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

	public function test_product_editor_offers_only_the_group_the_catalog_maps_the_key_to(): void {
		$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
		if ( '' === $node ) {
			$this->markTestSkipped( 'Node.js is not installed.' );
		}
		$script = dirname( __DIR__ ) . '/js/wplc-product-editor.test.js';
		$output = array();
		$status = 0;
		exec( escapeshellarg( $node ) . ' --test ' . escapeshellarg( $script ) . ' 2>&1', $output, $status );

		$this->assertSame( 0, $status, implode( "\n", $output ) );
		$this->assertStringContainsString( '# pass 4', implode( "\n", $output ) );
	}
}
