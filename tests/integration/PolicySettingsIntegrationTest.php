<?php
/**
 * Settings for the sale, part 3 (SET-LIC-09, -10, -18, -19, -21, -31): manufacturer rules in WordPress.
 *
 * @package WPLC
 */

use WPLC\Admin\Capabilities;
use WPLC\Admin\Settings;
use WPLC\Security\ResponseSigner;
use WPLC\Api\V1\SiteUrl;
use WPLC\CLI\SettingsCommand;
use WPLC\Settings\Changes;
use WPLC\Settings\Definitions;
use WPLC\Settings\Registry;

final class PolicySettingsIntegrationTest extends WPLC_Integration_TestCase {

	/**
	 * Administrator.
	 *
	 * @var int
	 */
	private $admin;

	/**
	 * Shop manager.
	 *
	 * @var int
	 */
	private $manager;

	public function set_up(): void {
		parent::set_up();
		ResponseSigner::reset();
		$this->admin   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->manager = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		delete_option( Settings::OPTION );
		add_filter( 'sanitize_option_' . Settings::OPTION, array( Settings::class, 'sanitize' ) );
		wp_set_current_user( $this->admin );
		$GLOBALS['wp_settings_errors'] = array();
	}

	public function tear_down(): void {
		remove_all_filters( 'sanitize_option_' . Settings::OPTION );
		$_POST                         = array();
		$GLOBALS['wp_settings_errors'] = array();
		delete_option( Settings::OPTION );
		ResponseSigner::reset();
		parent::tear_down();
	}

	private function capture( callable $callback ): string {
		ob_start();
		try {
			$callback();
		} finally {
			$html = (string) ob_get_clean();
		}

		return $html;
	}

	/**
	 * Save settings as the settings form does.
	 *
	 * @param array<string,mixed> $values Submitted fields.
	 * @return void
	 */
	private function submit( array $values ): void {
		$_POST['option_page'] = Settings::OPTION;
		update_option( Settings::OPTION, array_merge( Registry::stored(), $values ) );
		$_POST = array();
	}

	private function reset_limits(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$this->tables()['request_limits']}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	// ------------------------------------------------------------------ SET-LIC-31

	/** SET-LIC-31: a shop manager can read the manufacturer rules but not change them; an administrator can. */
	public function test_a_shop_manager_reads_but_cannot_change_manufacturer_rules(): void {
		wp_set_current_user( $this->manager );
		$this->assertTrue( Capabilities::can( 'manage_settings' ) );
		$this->assertFalse( Capabilities::can( 'manage_policy' ) );

		$field = $this->capture(
			static function () {
				Settings::render_field( array( 'key' => 'offline_cache_days' ) );
			}
		);
		$this->assertStringContainsString( 'value="14"', $field, 'The value is shown.' );
		$this->assertStringContainsString( ' readonly', $field );
		$this->assertStringNotContainsString( 'name="wplc_settings[offline_cache_days]"', $field, 'A read-only field is not part of the form.' );
		$this->assertStringContainsString( 'wplc_manage_policy', $field );

		$list = $this->capture(
			static function () {
				Settings::render_field( array( 'key' => 'staging_host_suffixes' ) );
			}
		);
		$this->assertStringContainsString( 'kinsta.cloud', $list );
		$this->assertStringNotContainsString( 'staging_host_suffixes_reset', $list, 'No reset without the capability.' );

		$this->submit( array( 'offline_cache_days' => '60', 'staging_sites_per_live' => '4', 'staging_labels_reset' => '1', 'renewal_notice_days' => '9' ) );
		$this->assertSame( 14, Registry::int( 'offline_cache_days' ) );
		$this->assertSame( 1, Registry::int( 'staging_sites_per_live' ) );
		$this->assertSame( 9, Registry::int( 'renewal_notice_days' ), 'Rules of the shop stay with the shop manager.' );

		// The overview names every capability and says what the current user holds.
		$page = $this->capture( array( Settings::class, 'render' ) );
		$this->assertStringContainsString( 'Who may do what', $page );
		$this->assertMatchesRegularExpression( '#<code>wplc_manage_policy</code></td><td>[^<]+</td><td>Administrators</td><td>no</td>#', $page );
		$this->assertMatchesRegularExpression( '#<code>wplc_manage_settings</code></td><td>[^<]+</td><td>Shop managers and administrators</td><td>yes</td>#', $page );
		$this->assertStringContainsString( 'Settings that need wplc_manage_policy: Offline period of customer sites', $page );

		// Granted on the role, the shop manager may change them.
		$role = get_role( 'shop_manager' );
		$role->add_cap( 'wplc_manage_policy' );
		try {
			wp_set_current_user( 0 );
			wp_set_current_user( $this->manager );
			$this->assertTrue( Capabilities::can( 'manage_policy' ) );
			$this->submit( array( 'offline_cache_days' => '60' ) );
			$this->assertSame( 60, Registry::int( 'offline_cache_days' ) );
		} finally {
			$role->remove_cap( 'wplc_manage_policy' );
		}

		wp_set_current_user( $this->admin );
		$this->submit( array( 'staging_sites_per_live' => '3' ) );
		$this->assertSame( 3, Registry::int( 'staging_sites_per_live' ) );
	}

	/** SET-LIC-31: export, import and WP-CLI know the manufacturer rules; an import as shop manager leaves them alone. */
	public function test_manufacturer_rules_in_export_import_and_cli(): void {
		$result = SettingsCommand::change( array( 'staging_sites_per_live' => '2', 'staging_labels' => "staging\nqa" ), 'settings_changed', 'Test.' );
		$this->assertTrue( $result['ok'] );
		$export = Changes::export();
		$this->assertSame( 2, $export['settings']['staging_sites_per_live'] );
		$this->assertSame( array( 'staging', 'qa' ), $export['settings']['staging_labels'] );

		delete_option( Settings::OPTION );
		wp_set_current_user( $this->manager );
		$parsed  = Changes::parse_import( (string) wp_json_encode( $export ) );
		$applied = Changes::apply( $parsed['values'], 'settings_imported', 'Test.' )['applied'];
		$this->assertNotContains( 'staging_sites_per_live', $applied );
		$this->assertSame( 1, Registry::int( 'staging_sites_per_live' ) );

		wp_set_current_user( $this->admin );
		Changes::apply( $parsed['values'], 'settings_imported', 'Test.' );
		$this->assertSame( 2, Registry::int( 'staging_sites_per_live' ) );
		$this->assertSame( array( 'staging', 'qa' ), SiteUrl::staging_labels() );
	}

	// ------------------------------------------------------------------ SET-LIC-09

	/** SET-LIC-09: the lists come from the form, invalid entries are refused, and "reset" brings back the default. */
	public function test_staging_lists_from_the_form_with_reset(): void {
		$license = $this->seed_license( array(), array( 'sites.live_limit' => 1 ) );
		$live    = $this->activate_via_api( $license['key'], array( 'site_url' => 'https://www.shop-one.com' ) );
		$staging = array( 'environment' => 'staging', 'staging_parent_installation_id' => $live['uuid'] );

		$this->submit( array( 'staging_host_suffixes' => "kinsta.cloud\n.previews.example-host.net" ) );
		$this->assertSame( array( 'kinsta.cloud', 'previews.example-host.net' ), Registry::list( 'staging_host_suffixes' ) );
		$copy = $this->activate_via_api( $license['key'], array_merge( $staging, array( 'site_url' => 'https://shop-one.previews.example-host.net' ) ) )['response'];
		$this->assertSame( 200, $copy->get_status() );
		$this->assertSame( 'staging', $copy->get_data()['data']['environment'], 'The added hosting domain is a staging host.' );
		$this->assertFalse( SiteUrl::is_staging_of( 'https://myshop.wpengine.com', 'https://www.shop-one.com' ), 'A removed domain no longer counts.' );

		$this->submit( array( 'staging_host_suffixes' => 'com' ) );
		$this->assertSame( array( 'kinsta.cloud', 'previews.example-host.net' ), Registry::list( 'staging_host_suffixes' ), 'Not accepted: the stored list stays.' );
		$this->assertContains( 'wplc_invalid_staging_host_suffixes', wp_list_pluck( get_settings_errors( Settings::OPTION ), 'code' ) );

		$this->submit( array( 'staging_host_suffixes' => 'other.example', 'staging_host_suffixes_reset' => '1' ) );
		$this->assertSame( Definitions::STAGING_HOST_SUFFIXES, Registry::list( 'staging_host_suffixes' ), 'Reset wins over the text sent with it.' );
		$this->assertTrue( SiteUrl::is_staging_of( 'https://myshop.wpengine.com', 'https://www.shop-one.com' ) );

		// Local endings decide where development sites may run; the filter on the result still wins.
		$this->submit( array( 'local_host_suffixes' => "test\ndevbox" ) );
		$dev = $this->activate_via_api( $license['key'], array( 'environment' => 'development', 'site_url' => 'http://shop.devbox' ) )['response'];
		$this->assertSame( 200, $dev->get_status() );
		$this->assertApiError( 400, 'development_site_invalid', $this->activate_via_api( $license['key'], array( 'environment' => 'development', 'site_url' => 'http://shop.local' ) )['response'] );
		add_filter( 'wplc_is_local_development_url', '__return_true' );
		try {
			$this->assertSame( 200, $this->activate_via_api( $license['key'], array( 'environment' => 'development', 'site_url' => 'http://shop.local' ) )['response']->get_status() );
		} finally {
			remove_all_filters( 'wplc_is_local_development_url' );
		}
	}

	// ------------------------------------------------------------------ SET-LIC-10

	/** SET-LIC-10: staging sites per live site from the setting; 0 = none; the refusal names the number. */
	public function test_staging_sites_per_live_site(): void {
		$license = $this->seed_license( array(), array( 'sites.live_limit' => 1 ) );
		$live    = $this->activate_via_api( $license['key'], array( 'site_url' => 'https://www.shop-one.com' ) );
		$staging = array( 'environment' => 'staging', 'staging_parent_installation_id' => $live['uuid'] );

		$this->submit( array( 'staging_sites_per_live' => '2' ) );
		foreach ( array( 'https://staging.shop-one.com', 'https://stage.shop-one.com' ) as $url ) {
			$this->assertSame( 'staging', $this->activate_via_api( $license['key'], array_merge( $staging, array( 'site_url' => $url ) ) )['response']->get_data()['data']['environment'], $url );
		}
		$third = $this->activate_via_api( $license['key'], array_merge( $staging, array( 'site_url' => 'https://test.shop-one.com' ) ) )['response'];
		$this->assertApiError( 409, 'staging_limit', $third );
		$this->assertSame( 2, $third->get_data()['data']['staging_limit'] );
		$this->assertSame( 2, $third->get_data()['data']['staging_count'] );
		$this->assertStringContainsString( '2 included staging activations', $third->get_data()['message'] );

		$other = $this->seed_license( array(), array( 'sites.live_limit' => 1 ) );
		$site  = $this->activate_via_api( $other['key'], array( 'site_url' => 'https://www.shop-two.com' ) );
		$this->submit( array( 'staging_sites_per_live' => '0' ) );
		$none = $this->activate_via_api( $other['key'], array( 'environment' => 'staging', 'staging_parent_installation_id' => $site['uuid'], 'site_url' => 'https://staging.shop-two.com' ) )['response'];
		$this->assertApiError( 409, 'staging_limit', $none );
		$this->assertSame( 0, $none->get_data()['data']['staging_limit'] );
		$this->assertSame( 'This license does not include staging sites.', $none->get_data()['message'] );
	}

	/** SET-LIC-10: development sites per license from the setting; 0 = none. */
	public function test_development_sites_per_license(): void {
		$license = $this->seed_license( array(), array( 'sites.live_limit' => 1 ) );
		$this->submit( array( 'development_sites' => '2' ) );
		$this->assertSame( 2, \WPLC\Api\V1\ActivationService::development_site_limit( $this->license( $license['id'] ), 1 ) );
		for ( $i = 1; $i <= 2; $i++ ) {
			$this->assertSame( 200, $this->activate_via_api( $license['key'], array( 'environment' => 'development', 'site_url' => 'http://dev' . $i . '.local' ) )['response']->get_status() );
		}
		$over = $this->activate_via_api( $license['key'], array( 'environment' => 'development', 'site_url' => 'http://dev3.local' ) )['response'];
		$this->assertApiError( 409, 'development_limit', $over );
		$this->assertSame( 2, $over->get_data()['data']['development_limit'] );
		$this->assertStringContainsString( '(2 included)', $over->get_data()['message'] );

		$this->reset_limits();
		$this->submit( array( 'development_sites' => '0' ) );
		$other = $this->seed_license( array(), array( 'sites.live_limit' => 3 ) );
		$none  = $this->activate_via_api( $other['key'], array( 'environment' => 'development', 'site_url' => 'http://dev1.local' ) )['response'];
		$this->assertApiError( 409, 'development_limit', $none );
		$this->assertSame( 'This license does not include development sites.', $none->get_data()['message'] );
	}
}
