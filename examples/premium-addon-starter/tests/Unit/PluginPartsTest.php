<?php
/**
 * Settings page, uninstall and core version check (B1, B8).
 *
 * @package Vendor\Product\Premium
 */

use PHPUnit\Framework\TestCase;
use Vendor\Product\Premium\Admin\SettingsPage;
use Vendor\Product\Premium\Client\LicenseClient;
use Vendor\Product\Premium\Config;
use Vendor\Product\Premium\CoreCheck;
use Vendor\Product\Premium\Tests\Support\FakeServer;
use Vendor\Product\Premium\Tests\Support\Fixtures;
use Vendor\Product\Premium\Uninstaller;

final class PluginPartsTest extends TestCase {
	protected function setUp(): void {
		wp_stub_reset();
	}

	public function test_core_check_bails_out_on_missing_or_old_core(): void {
		$this->assertStringContainsString( 'needs the free plugin Core', CoreCheck::problem( 'STARTER_TEST_NO_CORE', '1.0.0', 'Core' ) );
		define( 'STARTER_TEST_CORE_OLD', '1.1.0' );
		$this->assertStringContainsString( 'installed: 1.1.0', CoreCheck::problem( 'STARTER_TEST_CORE_OLD', '1.2.0', 'Core' ) );
		$this->assertSame( '', CoreCheck::problem( 'STARTER_TEST_CORE_OLD', '1.1.0', 'Core' ) );
		$this->assertSame( array( false, false, true ), array( CoreCheck::passes( 'STARTER_TEST_NO_CORE', '1.0.0' ), CoreCheck::passes( 'STARTER_TEST_CORE_OLD', '1.2.0' ), CoreCheck::passes( 'STARTER_TEST_CORE_OLD', '1.1.0' ) ) );
	}

	public function test_settings_operations_map_to_the_service(): void {
		$server = new FakeServer();
		$server->install();
		$page = new SettingsPage( Fixtures::service( Fixtures::time( 60 ) ), 'starter_test', 'Starter' );

		$this->assertSame( 'error', $page->process( array( 'operation' => 'activate', 'license_key' => 'K', 'environment' => 'staging', 'staging_parent' => '' ) )['type'] );
		$this->assertSame( 'success', $page->process( array( 'operation' => 'activate', 'license_key' => 'KEY-1234', 'environment' => 'production' ) )['type'] );
		$this->assertSame( 'success', $page->process( array( 'operation' => 'refresh' ) )['type'] );
		$this->assertSame( 'success', $page->process( array( 'operation' => 'deactivate' ) )['type'] );
		$this->assertSame( 'error', $page->process( array( 'operation' => 'drop_tables' ) )['type'] );
	}

	public function test_render_escapes_stored_values_and_never_prints_the_secret(): void {
		Fixtures::store_credentials();
		Fixtures::cache( 'entitlements-active.json' );
		$state                = get_option( Fixtures::STATE );
		$state['last_error']  = '<script>alert(1)</script>';
		update_option( Fixtures::STATE, $state );
		$page = new SettingsPage( Fixtures::service( Fixtures::time( 60 ) ), 'starter_test', 'Starter' );

		ob_start();
		$page->render();
		$html = (string) ob_get_clean();
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
		$this->assertStringContainsString( 'starter.pdf_templates', $html );
		$this->assertStringNotContainsString( 'other.feature', $html );
		$this->assertStringNotContainsString( (string) Fixtures::json( 'keys.json' )['installation_secret'], $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );

		$GLOBALS['wp_stub']['can'] = false;
		ob_start();
		$page->render();
		$this->assertSame( '', ob_get_clean(), 'nothing without manage_options' );
	}

	public function test_uninstall_releases_the_activation_and_deletes_only_add_on_data(): void {
		$server = new FakeServer();
		$server->install();
		$keys = Fixtures::json( 'keys.json' );
		update_option( Config::credentials_option(), array( 'installation_id' => $keys['installation_id'], 'installation_secret' => $keys['installation_secret'], 'activation_id' => $keys['activation_id'], 'signed_payload' => 1 ) );
		update_option( Config::state_option(), array( 'envelope' => array() ) );
		update_option( Config::update_option(), array( 'checked_at' => 1 ) );
		update_option( 'core_plugin_settings', array( 'keep' => true ) );
		$GLOBALS['wp_stub']['cron'][ Config::cron_hook() ] = 123;

		$client = new LicenseClient( (string) $keys['server_url'], (string) $keys['product_key'], '1.0.0', Config::credentials_option(), array( 'public_keys' => array( (string) $keys['server_public_key'] ) ) );
		$this->assertTrue( Uninstaller::cleanup_site( $client ) );
		$this->assertContains( 'DELETE /wplc/v1/activations/{id}', $server->seen );
		$this->assertSame( array( 'core_plugin_settings' => array( 'keep' => true ) ), $GLOBALS['wp_stub']['options'] );
		$this->assertSame( array(), $GLOBALS['wp_stub']['cron'] );
	}

	public function test_p26_uninstall_on_a_network_runs_in_batches_within_a_time_budget(): void {
		$server = new FakeServer();
		$server->install();
		$keys                        = Fixtures::json( 'keys.json' );
		$GLOBALS['wp_stub']['sites'] = range( 1, 250 );
		update_option( Config::credentials_option(), array( 'installation_id' => $keys['installation_id'], 'installation_secret' => $keys['installation_secret'], 'activation_id' => $keys['activation_id'] ) );
		update_option( Config::credentials_option() . '_pending', array( 'installation_id' => 'p', 'installation_secret' => 'pending-secret' ) );
		update_option( Config::state_option(), array( 'envelope' => array() ) );

		// The server is far away: after the first site the 20 seconds are used up.
		$now   = 1000;
		$clock = static function () use ( &$now ): int {
			$now += 25;
			return $now;
		};
		Uninstaller::run( $clock );

		$this->assertCount( 250, $GLOBALS['wp_stub']['switched'] );
		$this->assertCount( 3, $GLOBALS['wp_stub']['get_sites'] );
		$this->assertSame( array( 0, 100, 200 ), array_column( $GLOBALS['wp_stub']['get_sites'], 'offset' ) );
		$this->assertSame( Uninstaller::BATCH, $GLOBALS['wp_stub']['get_sites'][0]['number'], 'Never number => 0 (all sites at once).' );
		$this->assertNotContains( 'DELETE /wplc/v1/activations/{id}', $server->seen, 'No request once the budget is used up.' );
		$this->assertSame( array(), $GLOBALS['wp_stub']['options'], 'Local data (also the pending activation with its secret) is deleted all the same.' );
	}

	public function test_p26_admin_catch_up_refreshes_when_wp_cron_did_not_run(): void {
		$server = new FakeServer();
		$server->install();
		Fixtures::store_credentials();
		Fixtures::service( Fixtures::time( 60 ) )->refresh();
		$server->seen = array();

		// DISABLE_WP_CRON without a system cron: the daily event never ran for 37 hours.
		$plugin   = ( new ReflectionClass( Vendor\Product\Premium\Plugin::class ) )->newInstanceWithoutConstructor();
		$property = new ReflectionProperty( Vendor\Product\Premium\Plugin::class, 'license' );
		$property->setAccessible( true );
		$property->setValue( $plugin, Fixtures::service( Fixtures::time( 60 + 37 * 3600 ) ) );

		$GLOBALS['wp_stub']['can'] = false;
		$plugin->catch_up();
		$GLOBALS['wp_stub']['can']  = true;
		$GLOBALS['wp_stub']['ajax'] = true;
		$plugin->catch_up();
		$this->assertSame( array(), $server->seen, 'Only administrators, never in AJAX requests.' );

		$GLOBALS['wp_stub']['ajax'] = false;
		$plugin->catch_up();
		$this->assertSame( array( 'POST /wplc/v1/entitlements/check' ), $server->seen );
		$plugin->catch_up();
		$this->assertCount( 1, $server->seen, 'Not again right after the check.' );
	}

	public function test_uninstall_deletes_local_data_even_when_the_server_is_down(): void {
		update_option( Config::credentials_option(), array( 'installation_id' => 'x', 'installation_secret' => 'y', 'activation_id' => 1 ) );
		$this->assertFalse( Uninstaller::cleanup_site( Fixtures::client() ) );
		$this->assertSame( array(), $GLOBALS['wp_stub']['options'] );
	}
}
