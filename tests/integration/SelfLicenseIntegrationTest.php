<?php
/**
 * E8b: updates of the plugin itself from a license server. Off by default; the license never gates function; no loop.
 *
 * @package WPLC
 */

use WPLC\Infrastructure\SelfLicense;
use WPLC\Infrastructure\SelfUpdateCheck;

final class SelfLicenseIntegrationTest extends WPLC_Integration_TestCase {

	/**
	 * Hosts of the outbound requests of the test.
	 *
	 * @var string[]
	 */
	private $requests = array();

	public function set_up(): void {
		parent::set_up();
		SelfLicense::reset();
		add_filter(
			'pre_http_request',
			function ( $response, $args, $url ) {
				$this->requests[] = (string) wp_parse_url( (string) $url, PHP_URL_HOST );
				return new WP_Error( 'http_request_failed', 'No network in tests.' );
			},
			10,
			3
		);
	}

	public function tear_down(): void {
		remove_all_filters( 'wplc_vendor_license_config' );
		remove_all_filters( 'pre_http_request' );
		remove_all_actions( 'wplc_vendor_license_check' );
		remove_all_actions( 'admin_post_wplc_vendor_license' );
		wp_clear_scheduled_hook( 'wplc_vendor_license_check' );
		SelfLicense::reset();
		parent::tear_down();
	}

	private function configure( string $server_url ): void {
		add_filter(
			'wplc_vendor_license_config',
			static function ( $config ) use ( $server_url ) {
				return array(
					'server_url'  => $server_url,
					'product_key' => 'license-server',
					'public_keys' => array( 'k1' => base64_encode( str_repeat( 'k', 32 ) ) ),
					'account_url' => 'https://vendor.example/my-account/',
				);
			}
		);
		SelfLicense::reset();
	}

	private function ask_wordpress_for_updates(): void {
		apply_filters( 'update_plugins_github.com', false, array(), plugin_basename( WPLC_FILE ), array() );
	}

	public function test_without_a_vendor_endpoint_nothing_new_happens_and_no_request_is_made(): void {
		$this->assertSame( SelfLicense::STATE_OFF, SelfLicense::state() );
		$this->assertNull( SelfLicense::manager() );
		$this->assertFalse( SelfLicense::active() );
		SelfLicense::register_hooks();

		$this->assertFalse( has_action( 'admin_post_wplc_vendor_license' ), 'No license form.' );
		$this->assertFalse( has_action( 'wplc_vendor_license_check' ), 'No daily check.' );
		$this->assertFalse( wp_next_scheduled( 'wplc_vendor_license_check' ) );
		foreach ( array( 'wplc_vendor_license', 'wplc_vendor_license_state', 'wplc_vendor_update_meta' ) as $option ) {
			$this->assertFalse( get_option( $option, false ), $option );
		}
		$this->assertTrue( SelfUpdateCheck::enabled(), 'The release notification of A-42 stays as it was.' );

		$this->ask_wordpress_for_updates();
		$this->assertSame( array(), array_values( array_diff( $this->requests, array( 'api.github.com' ) ) ), 'No request to anything but the release check that existed before.' );
	}

	public function test_a_configured_endpoint_wires_the_client_library_and_replaces_the_github_check(): void {
		$this->configure( 'https://licenses.vendor.example' );
		$this->assertSame( SelfLicense::STATE_ON, SelfLicense::state() );
		$manager = SelfLicense::manager();
		$this->assertInstanceOf( \WPLC\Client\LicenseManager::class, $manager );
		$this->assertSame( 'license-server', $manager->config( 'product_key' ) );
		$this->assertSame( WPLC_VERSION, $manager->config( 'version' ) );
		$this->assertSame( 'wplc_vendor', $manager->prefix() );
		$this->assertSame( 'wplc_vendor_license_check', $manager->cron_hook(), 'Its own options and hooks, apart from the shop data.' );

		SelfLicense::register_hooks();
		$this->assertNotFalse( has_action( 'admin_post_wplc_vendor_license' ) );
		$this->assertNotFalse( has_action( 'wplc_vendor_license_check' ) );
		$this->assertFalse( SelfUpdateCheck::enabled(), 'Updates come from the license server, GitHub is not asked.' );

		$this->requests = array();
		$this->ask_wordpress_for_updates();
		$this->assertNotContains( 'api.github.com', $this->requests );
	}

	/**
	 * The rule of the product plan: the own license gates updates and support, never function.
	 */
	public function test_the_shop_works_fully_without_a_valid_own_license(): void {
		$this->configure( 'https://licenses.vendor.example' );
		SelfLicense::register_hooks();
		$manager = SelfLicense::manager();
		// No license was ever entered and the vendor's server cannot be reached.
		$this->assertSame( \WPLC\Client\LicenseStatus::STATE_UNLICENSED, $manager->status()->state, 'The own license is not valid.' );
		$manager->run_scheduled_check();

		// Selling: a paid order gets its license.
		$customer = self::factory()->user->create();
		$order    = $this->create_paid_order( array( array( $this->create_license_product(), 1 ) ), $customer );
		$licenses = $this->licenses_for_order( $order->get_id() );
		$this->assertCount( 1, $licenses );
		$this->assertSame( 'active', $licenses[0]['status'] );

		// Serving customers: their site activates and gets its entitlements.
		$key      = \WPLC\License\LicenseKey::for_uuid( (string) $licenses[0]['license_uuid'], (string) $licenses[0]['license_key_secret_id'] );
		$response = $this->activate_via_api( $key );
		$this->assertContains( $response['response']->get_status(), array( 200, 201 ), wp_json_encode( $response['response']->get_data() ) );

		// Renewing by hand is offered as always.
		global $wpdb;
		$wpdb->update( $this->tables()['licenses'], array( 'paid_until' => gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS ) ), array( 'id' => (int) $licenses[0]['id'] ) );
		$this->assertInstanceOf( WC_Order::class, \WPLC\Billing\ManualRenewal::start( (int) $licenses[0]['id'], $customer ) );
	}

	public function test_an_endpoint_on_this_site_is_refused(): void {
		$own = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		foreach ( array( 'https://' . $own, 'https://www.' . $own . '/wp-json/', 'https://' . strtoupper( $own ) . '/' ) as $url ) {
			$this->configure( $url );
			$this->assertSame( SelfLicense::STATE_LOOP, SelfLicense::state(), $url );
			$this->assertNull( SelfLicense::manager(), $url );
			remove_all_filters( 'wplc_vendor_license_config' );
		}
		SelfLicense::reset();
		SelfLicense::register_hooks();
		$this->assertFalse( has_action( 'wplc_vendor_license_check' ) );
		$this->assertTrue( SelfUpdateCheck::enabled() );

		// A filter of the client library cannot point the address back at this site either.
		$this->assertSame( '', SelfLicense::guard_server_url( 'https://' . $own . '/' ) );
		$this->assertSame( 'https://licenses.vendor.example', SelfLicense::guard_server_url( 'https://licenses.vendor.example' ) );
		$this->configure( 'https://licenses.vendor.example' );
		SelfLicense::register_hooks();
		add_filter(
			'wplc_vendor_license_server_url',
			static function () use ( $own ) {
				return 'https://' . $own;
			}
		);
		$this->requests = array();
		SelfLicense::manager()->refresh( true );
		$this->assertNotContains( $own, $this->requests, 'No request to itself.' );
		remove_all_filters( 'wplc_vendor_license_server_url' );
	}

	public function test_an_insecure_or_incomplete_configuration_stays_off(): void {
		$this->configure( 'http://licenses.vendor.example' );
		$this->assertSame( SelfLicense::STATE_INVALID, SelfLicense::state() );
		$this->assertNull( SelfLicense::manager() );
		SelfLicense::register_hooks();
		$this->assertFalse( has_action( 'wplc_vendor_license_check' ) );
	}
}
