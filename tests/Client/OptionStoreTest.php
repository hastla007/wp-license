<?php
/**
 * P-24: the address that identifies the installation is read without the home_url filters.
 *
 * Each test runs in its own process because it defines the WordPress functions it needs.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\Client\Messages;
use WPLC\Client\OptionStore;

final class OptionStoreTest extends TestCase {

	/**
	 * Stubs of the WordPress functions OptionStore reads; `home_url()` behaves like a multilingual plugin's filter.
	 */
	private static function stubs(): void {
		// phpcs:disable
		eval(
			'function get_option( $name, $default = false ) { return $GLOBALS["wplc_test_options"][ $name ] ?? $default; }
			function is_ssl() { return ! empty( $GLOBALS["wplc_test_ssl"] ); }
			function untrailingslashit( $value ) { return rtrim( $value, "/\\\\" ); }
			function home_url( $path = "" ) { return "https://shop.fr"; }
			function network_home_url( $path = "" ) { return "https://mapped.example/"; }
			function is_multisite() { return ! empty( $GLOBALS["wplc_test_multisite"] ); }
			function get_network() { return (object) array( "domain" => "network.example", "path" => "/sub/" ); }'
		);
		// phpcs:enable
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_p24_per_site_the_stored_home_option_counts_not_the_filtered_home_url(): void {
		self::stubs();
		$GLOBALS['wplc_test_options'] = array( 'home' => 'http://Shop.example/' );
		$store                        = new OptionStore();

		$this->assertSame( 'http://Shop.example', $store->site_url(), 'A language domain filtered into home_url() is not the identity of the site.' );
		$GLOBALS['wplc_test_ssl'] = true;
		$this->assertSame( 'https://Shop.example', $store->site_url(), 'Same scheme rule as home_url(): an HTTPS request reports https.' );

		$GLOBALS['wplc_test_options'] = array( 'home' => 'https://shop.example/blog' );
		$GLOBALS['wplc_test_ssl']     = false;
		$this->assertSame( 'https://shop.example/blog', $store->site_url(), 'https is never downgraded.' );

		$GLOBALS['wplc_test_options'] = array();
		$this->assertSame( 'https://shop.fr', $store->site_url(), 'Without the option (cannot happen in WordPress) home_url() is the fallback.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_p24_network_mode_uses_the_domain_and_path_of_the_network(): void {
		self::stubs();
		$GLOBALS['wplc_test_multisite'] = true;
		$store                          = new OptionStore( true );

		$this->assertTrue( $store->is_network() );
		$this->assertSame( 'http://network.example/sub/', $store->site_url() );
		$GLOBALS['wplc_test_ssl'] = true;
		$this->assertSame( 'https://network.example/sub/', $store->site_url() );
	}

	/**
	 * Add-on audit 16 Nov 2026, item 10 (captain-pro docs/AUDIT-LIZENZ-PRS.md): delete() reads back like set(),
	 * because delete_option() returns false for a missing option as well as for a failure.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_audit16_delete_confirms_that_the_option_is_gone(): void {
		self::stubs();
		// phpcs:disable
		eval( 'function delete_option( $name ) { if ( empty( $GLOBALS["wplc_test_delete_fails"] ) ) { unset( $GLOBALS["wplc_test_options"][ $name ] ); return true; } return false; }' );
		// phpcs:enable
		$GLOBALS['wplc_test_options'] = array( 'addon_license' => array( 'installation_id' => 'x' ) );
		$store                        = new OptionStore();

		$this->assertTrue( $store->delete( 'addon_license' ) );
		$this->assertArrayNotHasKey( 'addon_license', $GLOBALS['wplc_test_options'] );
		$this->assertTrue( $store->delete( 'addon_license' ), 'An option that does not exist is gone.' );

		$GLOBALS['wplc_test_options']      = array( 'addon_license' => array( 'installation_id' => 'x' ) );
		$GLOBALS['wplc_test_delete_fails'] = true;
		$this->assertFalse( $store->delete( 'addon_license' ), 'A value that is still stored is reported.' );
	}

	/**
	 * P-47 (rest of B-193): texts looked up before `init` are not kept for the request.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_p47_texts_built_before_init_are_not_memoized(): void {
		// phpcs:disable
		eval( 'function did_action( $hook ) { return "init" === $hook ? (int) ( $GLOBALS["wplc_test_init"] ?? 0 ) : 0; }' );
		eval( 'function __( $text, $domain = "default" ) { $GLOBALS["wplc_test_translations"] = ( $GLOBALS["wplc_test_translations"] ?? 0 ) + 1; return $text; }' );
		// phpcs:enable
		$messages = new Messages( 'test-addon' );
		$texts    = new ReflectionProperty( Messages::class, 'texts' );

		$this->assertSame( 'License', $messages->get( 'ui.title' ) );
		$this->assertNull( $texts->getValue( $messages ), 'Before init: used once, not kept.' );

		$GLOBALS['wplc_test_init'] = 1;
		$this->assertSame( 'License', $messages->get( 'ui.title' ) );
		$this->assertIsArray( $texts->getValue( $messages ), 'After init the table is kept.' );
		$count = $GLOBALS['wplc_test_translations'];
		$messages->get( 'ui.status' );
		$this->assertSame( $count, $GLOBALS['wplc_test_translations'], 'and not rebuilt.' );
	}

	/**
	 * R-27: delete() confirms by reading back, in both storage modes.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_r27_delete_reports_whether_the_value_is_gone(): void {
		self::stubs();
		// phpcs:disable
		eval(
			'function delete_option( $name ) { if ( empty( $GLOBALS["wplc_test_readonly"] ) ) { unset( $GLOBALS["wplc_test_options"][ $name ] ); return true; } return false; }
			function get_site_option( $name, $default = false ) { return $GLOBALS["wplc_test_site_options"][ $name ] ?? $default; }
			function delete_site_option( $name ) { if ( empty( $GLOBALS["wplc_test_readonly"] ) ) { unset( $GLOBALS["wplc_test_site_options"][ $name ] ); return true; } return false; }'
		);
		// phpcs:enable
		$GLOBALS['wplc_test_options']      = array( 'addon_license' => array( 'installation_id' => 'x' ) );
		$GLOBALS['wplc_test_site_options'] = array( 'addon_license' => array( 'installation_id' => 'y' ) );

		$site                          = new OptionStore();
		$GLOBALS['wplc_test_readonly'] = true;
		$this->assertFalse( $site->delete( 'addon_license' ), 'A database that kept the row is not reported as deleted.' );
		$this->assertTrue( $site->delete( 'addon_missing' ), 'Nothing stored counts as gone (delete_option() returns false here).' );
		$GLOBALS['wplc_test_readonly'] = false;
		$this->assertTrue( $site->delete( 'addon_license' ) );
		$this->assertArrayNotHasKey( 'addon_license', $GLOBALS['wplc_test_options'] );

		$GLOBALS['wplc_test_multisite'] = true;
		$network                        = new OptionStore( true );
		$GLOBALS['wplc_test_readonly']  = true;
		$this->assertFalse( $network->delete( 'addon_license' ) );
		$GLOBALS['wplc_test_readonly'] = false;
		$this->assertTrue( $network->delete( 'addon_license' ) );
		$this->assertArrayNotHasKey( 'addon_license', $GLOBALS['wplc_test_site_options'] );
	}
}
