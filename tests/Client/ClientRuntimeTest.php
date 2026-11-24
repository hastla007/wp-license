<?php
/**
 * Client 2.1.x behaviour that depends on WordPress functions the other client tests do not define:
 * no texts before `init`, wp_safe_remote_request() with the local-server exception, the confirmation
 * field of the license form, the Site Health test "License check" (2.1.1).
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
			function wp_create_nonce( $action ) { return "n"; }
			function wp_referer_field() { echo "<input type=\"hidden\" name=\"_wp_http_referer\" value=\"/wp-admin/\">"; }
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

	/**
	 * SET-LIC-33, -34, -35 (2.4.0): beta box, choice of the environment, brand values on the license screen.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_set_lic33_to_35_the_license_screen_shows_beta_box_site_type_and_brand(): void {
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
			function wp_create_nonce( $action ) { return "n"; }
			function wp_referer_field() { echo "<input type=\"hidden\" name=\"_wp_http_referer\" value=\"/wp-admin/\">"; }
			function wp_kses( $html, $allowed ) { return $html; }
			function wp_get_environment_type() { return $GLOBALS["wplc_test_environment"] ?? "production"; }'
		);
		// phpcs:enable
		$kit    = self::kit();
		$render = static function ( LicenseManager $manager ): string {
			ob_start();
			$manager->render_settings();
			return (string) ob_get_clean();
		};

		// As with 2.3.0: no box, no support line, the placeholder WPLC-…, "Type of this site" collapsed on a live site.
		$html = $render( $kit->manager( array( 'settings_parent' => false ) ) );
		$this->assertStringContainsString( 'placeholder="WPLC-…"', $html );
		$this->assertStringContainsString( '<details><summary>Type of this site</summary>', $html );
		$this->assertStringContainsString( '<option value="">Detected automatically: Live site</option>', $html );
		$this->assertStringContainsString( '<option value="staging">Staging site</option>', $html );
		$this->assertStringContainsString( 'name="wplc_staging_parent"', $html );
		$this->assertStringNotContainsString( 'wplc_beta', $html );
		$this->assertStringNotContainsString( 'Help with the license', $html );

		// Detected staging: the choice is open.
		$GLOBALS['wplc_test_environment'] = 'staging';
		$this->assertStringContainsString( '<details open><summary>Type of this site</summary>', $render( $kit->manager( array( 'settings_parent' => false ) ) ) );
		$this->assertStringContainsString( 'Detected automatically: Staging site', $render( $kit->manager( array( 'settings_parent' => false ) ) ) );
		$GLOBALS['wplc_test_environment'] = 'production';

		// Brand and channel choice switched on by the build.
		$config  = array(
			'settings_parent'      => false,
			'allow_channel_choice' => true,
			'brand'                => array( 'support_email' => 'help@vendor.example', 'support_url' => 'https://vendor.example/support', 'key_placeholder' => 'VEND-…' ),
		);
		$manager = $kit->manager( $config );
		$html    = $render( $manager );
		$this->assertStringContainsString( 'placeholder="VEND-…"', $html );
		$this->assertStringContainsString( 'Help with the license: <a href="mailto:help@vendor.example">help@vendor.example</a> · <a href="https://vendor.example/support" target="_blank" rel="noopener noreferrer">https://vendor.example/support</a>', $html );
		$this->assertStringNotContainsString( 'wplc_beta', $html, 'No box before the activation.' );

		$manager->activate( self::KEY );
		$html = $render( $manager );
		$this->assertStringContainsString( '<input type="checkbox" name="wplc_beta" value="1"> Receive beta versions', $html );
		$this->assertStringContainsString( 'value="channel"', $html );
		$this->assertStringContainsString( '<th scope="row">Updates</th><td>Stable versions</td>', $html );
		$manager->choose_beta( true );
		$html = $render( $manager );
		$this->assertStringContainsString( '<input type="checkbox" name="wplc_beta" value="1" checked="checked"> Receive beta versions', $html );
		$this->assertStringContainsString( '<th scope="row">Updates</th><td>Beta and stable versions</td>', $html );

		// On a copy the live site's ID comes from the copied data: no field for it.
		$kit->store->site = 'https://staging.shop.example';
		$html             = $render( $kit->manager( $config ) );
		$this->assertStringContainsString( 'name="wplc_environment"', $html );
		$this->assertStringNotContainsString( 'name="wplc_staging_parent"', $html );
		$this->assertStringNotContainsString( 'wplc_beta', $html );
	}

	/**
	 * Forms of a rendered license screen: per form the names of its hidden fields, the values of its
	 * submit buttons in order, and whether it holds the key field.
	 *
	 * @param string $html  Output of render_settings().
	 * @param string $field Name of the key field.
	 * @return array<int,array{hidden:string[],buttons:string[],key:bool}>
	 */
	private static function forms( string $html, string $field ): array {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8"><body>' . $html . '</body>' );
		libxml_clear_errors();
		$forms = array();
		foreach ( $dom->getElementsByTagName( 'form' ) as $form ) {
			$entry = array(
				'hidden'  => array(),
				'buttons' => array(),
				'key'     => false,
			);
			foreach ( $form->getElementsByTagName( 'input' ) as $input ) {
				if ( 'hidden' === $input->getAttribute( 'type' ) ) {
					$entry['hidden'][] = $input->getAttribute( 'name' );
				}
				$entry['key'] = $entry['key'] || $field === $input->getAttribute( 'name' );
			}
			foreach ( $form->getElementsByTagName( 'button' ) as $button ) {
				if ( 'submit' === $button->getAttribute( 'type' ) ) {
					$entry['buttons'][] = $button->getAttribute( 'value' );
				}
			}
			$forms[] = $entry;
		}

		return $forms;
	}

	/**
	 * CP-P5-08 (2.4.1, captain-pro): Enter in the key field activates, in every state of the screen.
	 *
	 * A browser sends the first submit button of the form on Enter in a text field (implicit
	 * submission). On an activated site the key field therefore has its own form; "Save", "Check now"
	 * and "Remove license" stay in the first one and work by keyboard as before.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_cp_p5_08_enter_in_the_key_field_sends_activate(): void {
		// phpcs:disable
		eval(
			'function current_user_can( $capability ) { return true; }
			function delete_transient( $name ) { return true; }
			function get_current_user_id() { return 1; }
			function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
			function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
			function esc_url( $url ) { return (string) $url; }
			function admin_url( $path = "" ) { return "https://shop.example/wp-admin/" . $path; }
			function wp_nonce_field( $action ) { echo "<input type=\"hidden\" id=\"_wpnonce\" name=\"_wpnonce\" value=\"n\"><input type=\"hidden\" name=\"_wp_http_referer\" value=\"/wp-admin/\">"; }
			function wp_create_nonce( $action ) { return "n"; }
			function wp_referer_field() { echo "<input type=\"hidden\" name=\"_wp_http_referer\" value=\"/wp-admin/\">"; }
			function wp_kses( $html, $allowed ) { return $html; }'
		);
		// phpcs:enable
		$kit    = self::kit();
		$field  = 'test_addon_license_key';
		$hidden = array( '_wpnonce', '_wp_http_referer', 'action' );
		$render = static function ( LicenseManager $manager ): string {
			ob_start();
			$manager->render_settings();
			return (string) ob_get_clean();
		};
		$config = array(
			'settings_parent'      => false,
			'allow_channel_choice' => true,
		);

		// Not activated: one form, Activate is its only button.
		$forms = self::forms( $render( $kit->manager( $config ) ), $field );
		$this->assertCount( 1, $forms );
		$this->assertTrue( $forms[0]['key'] );
		$this->assertSame( array( 'activate' ), $forms[0]['buttons'] );

		// Activated, with the beta box: the buttons above the key field are in a form of their own.
		$manager = $kit->manager( $config );
		$manager->activate( self::KEY );
		$html  = $render( $manager );
		$forms = self::forms( $html, $field );
		$this->assertCount( 2, $forms );
		$this->assertFalse( $forms[0]['key'] );
		$this->assertSame( array( 'channel', 'check', 'deactivate' ), $forms[0]['buttons'], 'Save, Check now and Remove license work as before.' );
		$this->assertTrue( $forms[1]['key'] );
		$this->assertSame( array( 'activate' ), $forms[1]['buttons'], 'Enter in the key field sends Activate.' );
		foreach ( $forms as $form ) {
			$this->assertSame( $hidden, $form['hidden'], 'Each form carries the nonce, the referer and the action.' );
		}
		$this->assertSame( 1, substr_count( $html, 'id="_wpnonce"' ), 'No duplicate id.' );
		$this->assertLessThan( strpos( $html, 'name="wplc_environment"' ), strrpos( $html, '<form' ), 'The type of the site goes with the activation.' );
		$this->assertLessThan( strrpos( $html, '<form' ), strpos( $html, 'name="wplc_confirm_deactivate"' ), 'The confirmation goes with Remove license.' );

		// A copy of the site: the key field comes first, "Activate for this address" is the first button.
		$kit->store->site = 'https://staging.shop.example';
		$forms            = self::forms( $render( $kit->manager( $config ) ), $field );
		$this->assertCount( 1, $forms );
		$this->assertTrue( $forms[0]['key'] );
		$this->assertSame( array( 'activate', 'deactivate', 'move' ), $forms[0]['buttons'] );
	}

	/**
	 * CP-P6-09 (2.4.1, captain-pro): the catch-up in the admin does not wait for the server.
	 *
	 * A due check is scheduled as a one-off WP-Cron event of the add-on and WP-Cron is started once
	 * per request for all add-ons; the event runs the check. Six add-ons and an unreachable server
	 * cost up to 30 seconds per admin page view before.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_cp_p6_09_catch_up_schedules_the_check_and_starts_wp_cron_once(): void {
		// phpcs:disable
		eval(
			'function current_user_can( $capability ) { return true; }
			function wp_doing_ajax() { return false; }
			function wp_doing_cron() { return false; }
			function sanitize_key( $key ) { return strtolower( (string) $key ); }
			function wp_unslash( $value ) { return $value; }
			function spawn_cron( $gmt_time = 0 ) { $GLOBALS["wplc_test_spawned"] = ( $GLOBALS["wplc_test_spawned"] ?? 0 ) + 1; return true; }'
		);
		// phpcs:enable
		// Two add-ons, each with its own store and server (in the real case: its own copy of the library).
		$kit   = self::kit();
		$other = new WPLC_Client_Test_Kit();
		$first = $kit->manager();
		// The second add-on has its own prefix and license screen.
		$second = $other->manager( array( 'option_prefix' => 'second_addon' ) );
		$this->assertInstanceOf( LicenseStatus::class, $first->activate( self::KEY ) );
		$this->assertInstanceOf( LicenseStatus::class, $second->activate( self::KEY ) );
		$requests = count( $kit->server->requests ) + count( $other->server->requests );
		$asked    = static function () use ( $kit, $other ): int {
			return count( $kit->server->requests ) + count( $other->server->requests );
		};

		// Right after the activation nothing is due.
		$first->catch_up();
		$second->catch_up();
		$this->assertArrayNotHasKey( 'wplc_test_single_events', $GLOBALS );
		$this->assertArrayNotHasKey( 'wplc_test_spawned', $GLOBALS );

		// WP-Cron did not run for two days: both add-ons schedule their check, nobody asks the server.
		$kit->advance( 2 * DAY_IN_SECONDS );
		$other->advance( 2 * DAY_IN_SECONDS );
		$first->catch_up();
		$second->catch_up();
		$this->assertSame( $requests, $asked(), 'No request in the admin page view.' );
		$this->assertSame( array( 'test_addon_license_check', 'second_addon_license_check' ), array_column( $GLOBALS['wplc_test_single_events'], 0 ) );
		$this->assertSame( 1, $GLOBALS['wplc_test_spawned'], 'WP-Cron is started once for all add-ons.' );
		$this->assertTrue( $GLOBALS[ LicenseManager::CRON_SPAWNED_GLOBAL ] );

		// The next page views within RECHECK_LOCK schedule nothing more.
		$first->catch_up();
		$this->assertCount( 2, $GLOBALS['wplc_test_single_events'] );

		// The event runs and checks; afterwards nothing is due.
		$first->run_scheduled_check();
		$this->assertSame( $requests + 1, $asked() );
		$this->assertSame( $kit->now, $first->status()->last_checked );

		// On the add-on's own license screen the check is still made at once.
		$_GET['page'] = 'second-addon-license';
		$second->catch_up();
		unset( $_GET['page'] );
		$this->assertSame( $requests + 2, $asked() );
		$this->assertCount( 2, $GLOBALS['wplc_test_single_events'] );
		$this->assertSame( 1, $GLOBALS['wplc_test_spawned'] );
	}

	/**
	 * AL-4 (2.1.1): the Site Health test "License check" says whether the daily check works.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_al4_site_health_test_reports_an_overdue_or_failing_license_check(): void {
		// phpcs:disable
		eval(
			'function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
			function esc_url( $url ) { return (string) $url; }
			function admin_url( $path = "" ) { return "https://shop.example/wp-admin/" . $path; }'
		);
		// phpcs:enable
		$kit   = self::kit();
		$other = array(
			'direct' => array( 'php_version' => array( 'label' => 'PHP' ) ),
			'async'  => array(),
		);

		// Not activated or not configured: no check that could be overdue, no test.
		$this->assertSame( $other, $kit->manager()->site_status_tests( $other ) );
		$this->assertSame( $other, $kit->manager( array( 'public_keys' => array() ) )->site_status_tests( $other ) );

		$manager = $kit->manager();
		$this->assertInstanceOf( LicenseStatus::class, $manager->activate( self::KEY ) );
		$tests = $manager->site_status_tests( $other );
		$this->assertArrayHasKey( 'php_version', $tests['direct'], 'Other tests stay.' );
		$this->assertSame( 'Test Add-on – License', $tests['direct']['test_addon_license_check']['label'] );
		$this->assertSame( array( $manager, 'site_health_test' ), $tests['direct']['test_addon_license_check']['test'] );

		// Fresh signed answer: good.
		$result = $manager->site_health_test();
		$this->assertSame( 'good', $result['status'] );
		$this->assertSame( 'test_addon_license_check', $result['test'] );
		$this->assertSame( array( 'label' => 'License', 'color' => 'blue' ), $result['badge'] );
		$this->assertSame( 'Test Add-on: the license check works', $result['label'] );
		$this->assertStringContainsString( gmdate( 'Y-m-d H:i', $kit->now + 14 * DAY_IN_SECONDS ), $result['description'] );
		$this->assertStringContainsString( 'https://shop.example/wp-admin/options-general.php?page=', $result['actions'] );
		$credentials = $kit->credentials();
		$json        = (string) json_encode( $result );
		foreach ( array( $credentials['installation_secret'], $credentials['installation_id'], $credentials['license_key_hash'], self::KEY ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $json );
		}

		// The last check failed: recommended, with the error and the server host.
		$kit->advance( 3600 );
		$kit->server->queue[] = new WP_Error( 'http_request_failed', 'down' );
		$manager->refresh();
		$result = $manager->site_health_test();
		$this->assertSame( 'recommended', $result['status'] );
		$this->assertSame( 'orange', $result['badge']['color'] );
		$this->assertSame( 'Test Add-on: the last license check failed', $result['label'] );
		$this->assertStringContainsString( 'could not be reached', $result['description'] );
		$this->assertStringContainsString( 'license.example', $result['description'] );

		// A later successful check makes it good again; two days without a check: overdue.
		$kit->advance( 3600 );
		$this->assertSame( LicenseStatus::STATE_ACTIVE, $manager->refresh()->state );
		$this->assertSame( 'good', $manager->site_health_test()['status'] );
		$kit->advance( 2 * DAY_IN_SECONDS );
		$result = $manager->site_health_test();
		$this->assertSame( 'recommended', $result['status'] );
		$this->assertSame( 'Test Add-on: the license check is overdue', $result['label'] );

		// Less than three days of the offline window left.
		$kit->advance( 10 * DAY_IN_SECONDS );
		$result = $manager->site_health_test();
		$this->assertSame( 'recommended', $result['status'] );
		$this->assertSame( 'Test Add-on: the offline period of the license ends soon', $result['label'] );

		// Offline window used up: critical.
		$kit->advance( 2 * DAY_IN_SECONDS );
		$this->assertSame( LicenseStatus::STATE_STALE, $manager->status()->state );
		$result = $manager->site_health_test();
		$this->assertSame( 'critical', $result['status'] );
		$this->assertSame( 'red', $result['badge']['color'] );
		$this->assertSame( 'Test Add-on: the license could not be verified', $result['label'] );
		$this->assertStringContainsString( 'could not be verified for too long', $result['description'] );

		// A signed answer released the activation: still listed, critical.
		$server               = $kit->server;
		$kit->server->queue[] = static function ( array $request ) use ( $server ) {
			$data = array(
				'activation_id'     => 41,
				'installation_id'   => '',
				'activation_status' => 'deactivated',
				'license_status'    => 'deactivated',
				'access'            => false,
				'entitlements'      => array(),
				'server_time'       => gmdate( 'Y-m-d H:i:s', $server->now ),
			);
			return WPLC_Test_Server::error( 403, 'activation_deactivated', array( 'signed' => $server->envelope( $request, $data ) ) );
		};
		$manager->refresh();
		$this->assertSame( LicenseStatus::STATE_DEACTIVATED, $manager->status()->state );
		$this->assertArrayHasKey( 'test_addon_license_check', $manager->site_status_tests( $other )['direct'] );
		$result = $manager->site_health_test();
		$this->assertSame( 'critical', $result['status'] );
		$this->assertSame( 'Test Add-on: the license of this site was released', $result['label'] );
	}
}
