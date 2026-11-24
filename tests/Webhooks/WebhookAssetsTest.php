<?php
/**
 * P5-I5 / P5-M7: narrow webhooks screen and the copy live region (stylesheets and browser check).
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;

final class WebhookAssetsTest extends TestCase {

	/**
	 * Contents of a file of the plugin.
	 *
	 * @param string $path Path from the plugin root.
	 * @return string
	 */
	private function file( string $path ): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $path );
	}

	public function test_webhook_tables_scroll_inside_themselves_on_narrow_screens(): void {
		$css = $this->file( 'assets/css/wplc-admin.css' );
		$this->assertMatchesRegularExpression( '/@media \(max-width: 782px\) \{\s*(?:[^{]*,\s*)?\.wplc-webhooks table\.widefat,[^{]*\{\s*display: block;[^}]*overflow-x: auto;/s', $css );

		$browser = $this->file( 'tests/browser/acceptance.js' );
		$this->assertStringContainsString( "[ 'admin webhooks', '/wp-admin/admin.php?page=wplc-webhooks'", $browser, 'The browser check visits the screen at 1280 and 375 px.' );
		$this->assertStringContainsString( "add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );", $this->file( 'src/Webhooks/Admin.php' ), 'The screen loads the admin stylesheet.' );
	}

	public function test_the_empty_copy_live_region_stays_rendered(): void {
		$css = $this->file( 'assets/css/wplc-account.css' );
		$this->assertSame( 1, preg_match( '/\.wplc-copy-status:empty\s*\{([^}]*)\}/', $css, $rule ) );
		$this->assertStringNotContainsString( 'display: none', $rule[1], 'display:none removes the live region from the accessibility tree.' );
		$this->assertStringContainsString( 'clip-path: inset(50%)', $rule[1], 'Visually hidden instead.' );
	}
}
