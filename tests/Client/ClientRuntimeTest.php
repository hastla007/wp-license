<?php
/**
 * Client 2.1.0 behaviour that depends on WordPress functions the other client tests do not define:
 * no texts before `init`, wp_safe_remote_request() with the local-server exception, the confirmation
 * field of the license form.
 *
 * Each test runs in its own process because it defines the WordPress functions it needs before the
 * test kit is loaded (the kit only defines what is still missing).
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\Client\LicenseManager;
use WPLC\Client\LicenseStatus;
use WPLC\Client\Messages;

final class ClientRuntimeTest extends TestCase {

	private const KEY = 'WPLC-AAAA-BBBB-CCCC-DDDD';

	/**
	 * Load the test kit after the stubs of a test.
	 *
	 * @return WPLC_Client_Test_Kit
	 */
	private static function kit(): WPLC_Client_Test_Kit {
		require_once __DIR__ . '/client-test-kit.php';

		return new WPLC_Client_Test_Kit();
	}

	/**
	 * add_filter() and remove_filter() on the table the kit's apply_filters() reads.
	 */
	private static function filter_stubs(): void {
		// phpcs:disable
		eval(
			'function add_filter( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS["wplc_test_filters"][ $hook ][] = $callback; return true; }
			function remove_filter( $hook, $callback, $priority = 10 ) {
				foreach ( $GLOBALS["wplc_test_filters"][ $hook ] ?? array() as $index => $known ) {
					if ( $known === $callback ) { unset( $GLOBALS["wplc_test_filters"][ $hook ][ $index ] ); return true; }
				}
				return false;
			}'
		);
		// phpcs:enable
	}

	/**
	 * Item 5 of 16 Nov 2026 (captain-pro): nothing of the library loads a translation before `init`.
	 *
	 * WordPress 6.7 reports "Translation loading ... was triggered too early" when an add-on asks for its
	 * license on plugins_loaded. Errors created that early carry their code; the text is built when shown.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_status_and_errors_before_init_call_no_translation_function(): void {
		// phpcs:disable
		eval(
			'function did_action( $hook ) { return "init" === $hook ? (int) ( $GLOBALS["wplc_test_init"] ?? 0 ) : 0; }
			function __( $text, $domain = "default" ) { $GLOBALS["wplc_test_translations"][] = $text; return $text; }
			function translate( $text, $domain = "default" ) { $GLOBALS["wplc_test_translations"][] = $text; return $text; }'
		);
		// phpcs:enable
		$kit = self::kit();
		$GLOBALS['wplc_test_init'] = 1;
		$this->assertInstanceOf( LicenseStatus::class, $kit->manager( array( 'text_domain' => 'test-addon' ) )->activate( self::KEY ) );
		$credentials = $kit->store->data['test_addon_license'];

		// The next request, on plugins_loaded.
		$GLOBALS['wplc_test_init']         = 0;
		$GLOBALS['wplc_test_translations'] = array();
		$this->assertFalse( Messages::ready() );

		// A site without activation (the case found in captain-pro).
		unset( $kit->store->data['test_addon_license'] );
		$manager = $kit->manager( array( 'text_domain' => 'test-addon' ) );
		$this->assertSame( LicenseStatus::STATE_UNLICENSED, $manager->status()->state );
		$this->assertFalse( $manager->is_entitled( 'feature.flag' ) );
		$error = $manager->refresh();
		$this->assertSame( 'wplc_not_activated', $error->get_error_code() );
		$this->assertSame( 'wplc_not_activated', $error->get_error_message(), 'The code stands in for the text.' );
		$this->assertSame( 'wplc_not_activated', $manager->client()->deactivate()->get_error_message() );
		$this->assertSame( 'wplc_not_activated', $manager->client()->check_update()->get_error_message() );
		$manager->run_scheduled_check();

		// An activated site, a copy of it, a failed check, a clock that is ahead.
		$kit->store->data['test_addon_license'] = $credentials;
		$manager                                = $kit->manager( array( 'text_domain' => 'test-addon' ) );
		$this->assertTrue( $manager->is_entitled( 'feature.flag' ) );
		$kit->server->queue = array( new WP_Error( 'http_request_failed', 'down' ) );
		$this->assertSame( 'wplc_connection_failed', $manager->refresh()->get_error_message() );
		$this->assertSame( 'wplc_backoff', $manager->refresh( false )->get_error_code() );
		$kit->now += 16 * DAY_IN_SECONDS;
		$this->assertSame( LicenseStatus::STATE_STALE, $manager->status()->state );
		$kit->now      -= 16 * DAY_IN_SECONDS;
		$kit->store->site = 'https://copy.example';
		$this->assertSame( LicenseStatus::STATE_SITE_CHANGED, $manager->status()->state );
		$this->assertSame( 'wplc_site_changed', $manager->refresh()->get_error_message() );

		$this->assertSame( array(), $GLOBALS['wplc_test_translations'], 'No translation function was called before init.' );

		// Shown later, after init: translated then.
		$GLOBALS['wplc_test_init'] = 1;
		$this->assertSame( 'The address of this site differs from the address the license was activated for. This looks like a copy of the site. Activate the license for this address.', $manager->error_text( $manager->refresh() ) );
		$this->assertNotSame( array(), $GLOBALS['wplc_test_translations'] );
		$this->assertStringStartsWith( 'This site has not been activated', $manager->error_text( $error ) );
	}

	/**
	 * AL-6: the API is called with wp_safe_remote_request(); a local server needs an explicit exception for its host.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_api_requests_use_wp_safe_remote_request_and_free_only_the_server_host(): void {
		self::filter_stubs();
		// phpcs:disable
		eval(
			'function wp_remote_request( $url, $args = array() ) { throw new RuntimeException( "wp_remote_request() must not be used" ); }
			function wp_safe_remote_request( $url, $args = array() ) {
				$host = (string) parse_url( $url, PHP_URL_HOST );
				$GLOBALS["wplc_test_http"][] = array(
					"url"      => $url,
					"external" => array(
						$host     => apply_filters( "http_request_host_is_external", false, $host, $url ),
						"evil.test" => apply_filters( "http_request_host_is_external", false, "evil.test", "https://evil.test/" ),
					),
					"ports"    => array(
						$host     => apply_filters( "http_allowed_safe_ports", array( 80, 443, 8080 ), $host, $url ),
						"evil.test" => apply_filters( "http_allowed_safe_ports", array( 80, 443, 8080 ), "evil.test", "https://evil.test/" ),
					),
				);
				return call_user_func( $GLOBALS["wplc_test_transport"], $url, $args );
			}'
		);
		// phpcs:enable
		$kit                            = self::kit();
		$GLOBALS['wplc_test_transport'] = $kit->server->transport();

		// Default: nothing is let through; WordPress decides about the address as for any other safe request.
		$manager = $kit->manager( array( 'transport' => null ) );
		$this->assertInstanceOf( LicenseStatus::class, $manager->activate( self::KEY ) );
		$this->assertSame( LicenseStatus::STATE_ACTIVE, $manager->refresh()->state );
		$this->assertCount( 2, $GLOBALS['wplc_test_http'] );
		$this->assertSame( 'https://license.example/wp-json/wplc/v1/entitlements/check', $GLOBALS['wplc_test_http'][1]['url'] );
		$this->assertFalse( $GLOBALS['wplc_test_http'][1]['external']['license.example'] );

		// The documented exception: only the configured server host and its port, only during the request.
		$GLOBALS['wplc_test_http']                                          = array();
		$GLOBALS['wplc_test_filters']['test_addon_license_allow_local_server'][] = static function () {
			return true;
		};
		$kit->server->files = array();
		$local              = $kit->manager(
			array(
				'transport'  => null,
				'server_url' => 'https://License.test:8443',
			)
		);
		$GLOBALS['wplc_test_transport'] = static function ( string $url, array $args ) use ( $kit ) {
			return call_user_func( $kit->server->transport(), str_replace( 'https://license.test:8443', 'https://license.example', $url ), $args );
		};
		$this->assertSame( LicenseStatus::STATE_ACTIVE, $local->refresh()->state );
		$call = $GLOBALS['wplc_test_http'][0];
		$this->assertSame( 'https://License.test:8443/wp-json/wplc/v1/entitlements/check', $call['url'] );
		$this->assertTrue( $call['external']['License.test'] );
		$this->assertFalse( $call['external']['evil.test'], 'No other host is freed.' );
		$this->assertContains( 8443, $call['ports']['License.test'] );
		$this->assertNotContains( 8443, $call['ports']['evil.test'] );
		$this->assertSame( array(), array_values( $GLOBALS['wplc_test_filters']['http_request_host_is_external'] ), 'The exception ends with the request.' );
		$this->assertSame( array(), array_values( $GLOBALS['wplc_test_filters']['http_allowed_safe_ports'] ) );

		// A development build allows it without the filter.
		unset( $GLOBALS['wplc_test_filters']['test_addon_license_allow_local_server'] );
		$GLOBALS['wplc_test_http'] = array();
		$this->assertSame( LicenseStatus::STATE_ACTIVE, $kit->manager( array( 'transport' => null, 'server_url' => 'https://license.test:8443' ) )->refresh()->state );
		$this->assertFalse( $GLOBALS['wplc_test_http'][0]['external']['license.test'] );
		define( 'TEST_ADDON_LICENSE_DEV', true );
		$GLOBALS['wplc_test_http'] = array();
		$this->assertSame( LicenseStatus::STATE_ACTIVE, $kit->manager( array( 'transport' => null, 'server_url' => 'https://license.test:8443' ) )->refresh()->state );
		$this->assertTrue( $GLOBALS['wplc_test_http'][0]['external']['license.test'] );
	}

	/**
	 * AL-5: the license screen asks for a confirmation before "Remove license from this site".
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_license_screen_has_a_confirmation_field_for_the_release(): void {
		// phpcs:disable
		eval(
			'function current_user_can( $capability ) { return true; }
			function delete_transient( $name ) { return true; }
			function get_current_user_id() { return 1; }
			function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
			function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
			function esc_url( $url ) { return (string) $url; }
			function admin_url( $path = "" ) { return "https://shop.example/wp-admin/" . $path; }
			function wp_nonce_field( $action ) { echo "<input type=\"hidden\" name=\"_wpnonce\" value=\"n\">"; }
			function wp_kses( $html, $allowed ) { return $html; }'
		);
		// phpcs:enable
		$kit     = self::kit();
		$manager = $kit->manager( array( 'settings_parent' => false ) );
		$manager->activate( self::KEY );

		ob_start();
		$manager->render_settings();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<input type="checkbox" name="wplc_confirm_deactivate" value="1">', $html );
		$this->assertStringContainsString( 'I confirm that this site gives up its license.', $html );
		$this->assertLessThan( strpos( $html, 'value="deactivate"' ), strpos( $html, 'wplc_confirm_deactivate' ), 'The field comes before the button.' );
		$this->assertStringNotContainsString( 'wplc_confirm_move', $html );
		$this->assertStringNotContainsString( $kit->credentials()['installation_secret'], $html );

		// The same form fields as before for everything else.
		$this->assertSame( 'error', $manager->run_form_action( 'deactivate' )['type'] );
		$this->assertTrue( $manager->client()->is_activated() );
	}
}
