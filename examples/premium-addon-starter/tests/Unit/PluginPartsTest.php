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

		$client = new LicenseClient( (string) $keys['server_url'], (string) $keys['product_key'], '1.0.0', Config::credentials_option(), array( (string) $keys['server_public_key'] ) );
		$this->assertTrue( Uninstaller::cleanup_site( $client ) );
		$this->assertContains( 'DELETE /wplc/v1/activations/{id}', $server->seen );
		$this->assertSame( array( 'core_plugin_settings' => array( 'keep' => true ) ), $GLOBALS['wp_stub']['options'] );
		$this->assertSame( array(), $GLOBALS['wp_stub']['cron'] );
	}

	public function test_uninstall_deletes_local_data_even_when_the_server_is_down(): void {
		update_option( Config::credentials_option(), array( 'installation_id' => 'x', 'installation_secret' => 'y', 'activation_id' => 1 ) );
		$this->assertFalse( Uninstaller::cleanup_site( Fixtures::client() ) );
		$this->assertSame( array(), $GLOBALS['wp_stub']['options'] );
	}
}
