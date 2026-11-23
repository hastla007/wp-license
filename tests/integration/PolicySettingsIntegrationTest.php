<?php
/**
 * Settings for the sale, part 3 (SET-LIC-09, -10, -18, -19, -21, -31): manufacturer rules in WordPress.
 *
 * @package WPLC
 */

use WPLC\Admin\Capabilities;
use WPLC\Admin\CatalogCsv;
use WPLC\Api\V1\ActivationService;
use WPLC\Api\V1\EntitlementService;
use WPLC\Catalog\Catalog;
use WPLC\Catalog\PlanOverrides;
use WPLC\Catalog\ProductEditor;
use WPLC\Catalog\QuantityLimit;
use WPLC\License\LicenseMembers;
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
		$this->assertMatchesRegularExpression( '#Settings that need wplc_manage_policy: [^<]*Offline period of customer sites#', $page );

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

	// ------------------------------------------------------------------ SET-LIC-21

	/**
	 * Temporary CSV file.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	private function csv_file( string $content ): string {
		$path = (string) tempnam( sys_get_temp_dir(), 'wplc-csv-' );
		file_put_contents( $path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return $path;
	}

	/**
	 * Stored plan of a simple product.
	 *
	 * @param int $product_id Product ID.
	 * @return array<string,mixed>
	 */
	private function plan( int $product_id ): array {
		$plan = Catalog::find_exact_mapping( $product_id );
		$this->assertIsArray( $plan );
		return $plan;
	}

	/** SET-LIC-21: a plan replaces the global values for its licenses and cart lines; empty = global; the filters win. */
	public function test_overrides_per_plan_replace_the_global_settings(): void {
		$product = $this->create_license_product();
		$other   = $this->create_license_product( array( 'plan_key' => 'agency' ) );
		$this->assertNull( $this->plan( $product->get_id() )['staging_site_limit'], 'A new plan uses the global settings.' );

		$this->assertTrue(
			Catalog::save_mapping(
				$product->get_id(),
				0,
				$this->mapping_data(
					array(
						'overrides' => array(
							'staging_site_limit'     => '3',
							'development_site_limit' => '0',
							'offline_cache_days'     => '30',
							'max_quantity_per_line'  => '2',
							'co_user_limit'          => '',
						),
					)
				)
			)
		);
		$plan = $this->plan( $product->get_id() );
		$this->assertSame( '3', (string) $plan['staging_site_limit'] );
		$this->assertSame( '0', (string) $plan['development_site_limit'] );
		$this->assertNull( $plan['co_user_limit'], 'Empty = global setting.' );

		// Saving without overrides (another path, e.g. the price sync) keeps them.
		$this->assertTrue( Catalog::save_mapping( $product->get_id(), 0, $this->mapping_data() ) );
		$this->assertSame( '3', (string) $this->plan( $product->get_id() )['staging_site_limit'] );

		// Outside the limits: nothing is saved.
		$this->assertFalse( Catalog::save_mapping( $product->get_id(), 0, $this->mapping_data( array( 'overrides' => array( 'offline_cache_days' => '0' ) ) ) ) );
		$this->assertSame( 'invalid_override', Catalog::last_error() );
		$this->assertSame( '30', (string) $this->plan( $product->get_id() )['offline_cache_days'] );

		$customer = self::factory()->user->create( array( 'role' => 'customer' ) );
		$order    = $this->create_paid_order( array( array( $product, 1 ), array( $other, 1 ) ), $customer );
		$licenses = $this->licenses_for_order( $order->get_id() );
		$this->assertCount( 2, $licenses );
		list( $own, $global ) = (string) $licenses[0]['plan_key'] === 'single' ? array( $licenses[0], $licenses[1] ) : array( $licenses[1], $licenses[0] );

		$this->assertSame( 3, ActivationService::staging_site_limit( $own ) );
		$this->assertSame( 1, ActivationService::staging_site_limit( $global ) );
		$this->assertSame( 0, ActivationService::development_site_limit( $own, 1 ), 'Exactly the number of the plan.' );
		$this->assertSame( 5, ActivationService::development_site_limit( $global, 1 ) );
		$now = time();
		$this->assertEqualsWithDelta( $now + 30 * DAY_IN_SECONDS, strtotime( EntitlementService::access_fields( $own, $now )['cache_expires_at'] . ' UTC' ), 2 );
		$this->assertEqualsWithDelta( $now + 14 * DAY_IN_SECONDS, strtotime( EntitlementService::access_fields( $global, $now )['cache_expires_at'] . ' UTC' ), 2 );
		$this->assertSame( 2, QuantityLimit::max( wc_get_product( $product->get_id() ) ) );
		$this->assertSame( 100, QuantityLimit::max( wc_get_product( $other->get_id() ) ) );

		// Empty override: the global setting, also after it changed.
		$this->submit( array( 'co_user_limit' => '7' ) );
		$this->assertSame( 7, LicenseMembers::member_limit( $own ) );

		// The filters of the global values still decide last.
		add_filter( 'wplc_staging_site_limit', static fn() => 9 );
		add_filter( 'wplc_offline_cache_days', static fn() => 2 );
		try {
			$this->assertSame( 9, ActivationService::staging_site_limit( $own ) );
			$this->assertEqualsWithDelta( $now + 2 * DAY_IN_SECONDS, strtotime( EntitlementService::access_fields( $own, $now )['cache_expires_at'] . ' UTC' ), 2 );
		} finally {
			remove_all_filters( 'wplc_staging_site_limit' );
			remove_all_filters( 'wplc_offline_cache_days' );
		}

		// A changed plan applies to its existing licenses at once.
		$this->assertTrue( Catalog::save_mapping( $product->get_id(), 0, $this->mapping_data( array( 'overrides' => array( 'staging_site_limit' => '' ) ) ) ) );
		$this->assertSame( 1, ActivationService::staging_site_limit( $own ) );
	}

	/** SET-LIC-21: the product editor shows and saves the overrides; empty fields mean "global setting". */
	public function test_product_editor_saves_the_overrides(): void {
		$product = $this->create_license_product();
		require_once WC()->plugin_path() . '/includes/admin/wc-meta-box-functions.php';
		$html    = $this->capture(
			static function () use ( $product ) {
				global $post, $thepostid;
				$post      = get_post( $product->get_id() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
				$thepostid = $product->get_id(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
				ProductEditor::render_product_fields();
			}
		);
		foreach ( array_keys( PlanOverrides::FIELDS ) as $field ) {
			$this->assertStringContainsString( 'name="_wplc_' . $field . '"', $html, $field );
		}
		$this->assertStringContainsString( 'placeholder="Global: 14"', $html );

		$_POST = wp_slash(
			array(
				'woocommerce_meta_nonce'      => wp_create_nonce( 'woocommerce_save_data' ),
				ProductEditor::PRESENT_FIELD  => '1',
				'_wplc_is_license_product'    => 'yes',
				'_wplc_product_key'           => 'test-product',
				'_wplc_plan_key'              => 'single',
				'_wplc_billing_interval'      => 'year',
				'_wplc_live_site_limit'       => '1',
				'_wplc_support_level'         => 'standard',
				'_wplc_entitlements_json'     => '',
				'_wplc_staging_site_limit'    => '2',
				'_wplc_development_site_limit' => '',
				'_wplc_offline_cache_days'    => '45',
				'_wplc_max_quantity_per_line' => '',
				'_wplc_co_user_limit'         => '0',
			)
		);
		ProductEditor::save_product_fields( $product->get_id() );
		$_POST = array();
		$plan  = $this->plan( $product->get_id() );
		$this->assertSame( '2', (string) $plan['staging_site_limit'] );
		$this->assertNull( $plan['development_site_limit'] );
		$this->assertSame( '45', (string) $plan['offline_cache_days'] );
		$this->assertSame( '0', (string) $plan['co_user_limit'] );
	}

	/** SET-LIC-21: the catalog CSV carries the overrides; a file with the old header still imports and keeps them. */
	public function test_catalog_csv_carries_the_overrides(): void {
		$product = $this->create_license_product( array( 'overrides' => array( 'staging_site_limit' => '4' ) ) );
		$product->set_sku( 'POLICY-SKU' );
		$product->save();

		$rows = CatalogCsv::export_rows();
		$row  = null;
		foreach ( $rows as $candidate ) {
			if ( (string) $product->get_id() === $candidate[0] ) {
				$row = $candidate;
			}
		}
		$this->assertIsArray( $row );
		$this->assertCount( 15, $row );
		$this->assertSame( '4', $row[9] );
		$this->assertSame( '', $row[10] );

		$header = 'product_id,variation_id,sku,product_key,plan_key,billing_interval,live_site_limit,support_level,entitlements_json';
		$line   = $product->get_id() . ',0,POLICY-SKU,test-product,single,year,1,standard,';
		$result = CatalogCsv::import_file( $this->csv_file( $header . "\n" . $line . "\n" ) );
		$this->assertSame( 1, $result['imported'], wp_json_encode( $result ) );
		$this->assertSame( '4', (string) $this->plan( $product->get_id() )['staging_site_limit'], 'Old header: overrides kept.' );

		$full   = $header . ',staging_site_limit,development_site_limit,offline_cache_days,max_quantity_per_line,co_user_limit';
		$result = CatalogCsv::import_file( $this->csv_file( $full . "\n" . $line . ',,3,60,,1' . "\n" ) );
		$this->assertSame( 1, $result['imported'], wp_json_encode( $result ) );
		$plan = $this->plan( $product->get_id() );
		$this->assertNull( $plan['staging_site_limit'], 'An empty cell clears the override.' );
		$this->assertSame( '3', (string) $plan['development_site_limit'] );
		$this->assertSame( '60', (string) $plan['offline_cache_days'] );
		$this->assertSame( '1', (string) $plan['co_user_limit'] );

		$result = CatalogCsv::import_file( $this->csv_file( $full . "\n" . $line . ',11,,,,' . "\n" ) );
		$this->assertSame( 0, $result['imported'] );
		$this->assertSame( 'invalid_override', $result['errors'][0]['reason'] );
	}

	// ------------------------------------------------------------------ SET-LIC-19

	/** SET-LIC-19: the download rules come from the settings; the filters receive them and decide last. */
	public function test_download_rules_follow_the_settings(): void {
		$expired = array(
			'status'     => 'expired',
			'paid_until' => gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ),
		);
		$this->assertSame( $expired['paid_until'], \WPLC\Update\CustomerDownload::download_scope( $expired ), 'Default as before: versions of the paid period.' );

		$this->submit(
			array(
				'expired_download'         => '0',
				'download_version_choices' => '1',
				'downloads_per_hour'       => '3',
				'download_reuse_seconds'   => '45',
				'download_grant_seconds'   => '120',
			)
		);
		$this->assertNull( \WPLC\Update\CustomerDownload::download_scope( $expired ), 'No download after the end.' );
		$this->assertSame( 3, \WPLC\Update\CustomerDownload::hourly_limit() );
		$this->assertSame( 45, \WPLC\Update\PackageStream::reuse_seconds() );
		$this->assertSame( 120, \WPLC\Api\V1\UpdateService::grant_seconds() );

		$seen = null;
		add_filter(
			'wplc_customer_download_version_choices',
			static function ( $choices ) use ( &$seen ) {
				$seen = $choices;
				return $choices;
			}
		);
		\WPLC\Update\CustomerDownload::downloadable_versions( array( 'status' => 'active', 'paid_until' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ), 'product_key' => 'test-product' ) );
		remove_all_filters( 'wplc_customer_download_version_choices' );
		$this->assertSame( 1, $seen, 'The filter receives the setting.' );

		add_filter( 'wplc_expired_license_download', '__return_true' );
		add_filter( 'wplc_download_grant_seconds', static fn() => 600 );
		try {
			$this->assertSame( $expired['paid_until'], \WPLC\Update\CustomerDownload::download_scope( $expired ), 'The filter wins.' );
			$this->assertSame( 600, \WPLC\Api\V1\UpdateService::grant_seconds() );
		} finally {
			remove_all_filters( 'wplc_expired_license_download' );
			remove_all_filters( 'wplc_download_grant_seconds' );
		}

		// A shop manager sees them but cannot change them.
		wp_set_current_user( $this->manager );
		$this->submit( array( 'downloads_per_hour' => '500' ) );
		$this->assertSame( 3, \WPLC\Update\CustomerDownload::hourly_limit() );
	}

	/** SET-LIC-19: an update check hands out a download link that expires after the configured time. */
	public function test_update_grant_expires_after_the_configured_time(): void {
		$license = $this->seed_license();
		$site    = $this->seed_activation( $license['id'] );
		$this->submit( array( 'download_grant_seconds' => '90' ) );
		add_filter(
			'wplc_update_packages',
			static function ( $packages ) {
				$packages['test-product'] = array(
					'version'     => '2.0.0',
					'package_url' => 'https://' . wp_parse_url( home_url(), PHP_URL_HOST ) . '/downloads/test-product-2.0.0.zip',
					'sha256'      => str_repeat( 'ab', 32 ),
				);
				return $packages;
			}
		);
		$response = $this->dispatch( $this->api_request( 'POST', '/wplc/v1/updates/check', array( 'product_key' => 'test-product', 'current_version' => '1.0.0', 'download' => true ), $site ) );
		$data     = $response->get_data();
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $data ) );
		$expires = $data['data']['download_expires_at'] ?? ( $data['download_expires_at'] ?? null );
		$this->assertIsString( $expires, (string) wp_json_encode( $data ) );
		$this->assertEqualsWithDelta( time() + 90, strtotime( $expires . ' UTC' ), 5 );
		remove_all_filters( 'wplc_update_packages' );
	}

	// ------------------------------------------------------------------ SET-LIC-26

	/** SET-LIC-26: switches for functions that were filters only; defaults as before, the filters win. */
	public function test_switches_for_functions_that_were_filters(): void {
		// Term at the price.
		$product = $this->create_license_product();
		$product = wc_get_product( $product->get_id() );
		$this->assertNotSame( 'PRICE', \WPLC\Catalog\TermDisplay::price_html( 'PRICE', $product ), 'Default: the term is shown.' );
		$this->submit( array( 'show_license_term' => '0' ) );
		$this->assertSame( 'PRICE', \WPLC\Catalog\TermDisplay::price_html( 'PRICE', $product ) );
		add_filter( 'wplc_show_license_term', '__return_true' );
		$this->assertNotSame( 'PRICE', \WPLC\Catalog\TermDisplay::price_html( 'PRICE', $product ), 'The filter wins.' );
		remove_all_filters( 'wplc_show_license_term' );

		// Manual renewal of products that are no longer sold.
		$customer = self::factory()->user->create( array( 'role' => 'customer' ) );
		$order    = $this->create_paid_order( array( array( $product, 1 ) ), $customer );
		$license = $this->licenses_for_order( $order->get_id() )[0];
		$product->set_status( 'private' );
		$product->save();
		$this->assertTrue( \WPLC\Billing\ManualRenewal::blocked_as_not_for_sale( $license ), 'Default: blocked as before.' );
		$this->submit( array( 'renew_unsold_products' => '1' ) );
		$this->assertFalse( \WPLC\Billing\ManualRenewal::blocked_as_not_for_sale( $license ) );

		// Payment methods paid on delivery.
		$paid = $this->create_order( array( array( $this->create_license_product( array( 'plan_key' => 'bank' ) ), 1 ) ), $customer );
		$paid->set_payment_method( 'bacs' );
		$paid->set_status( 'processing' );
		$paid->save();
		$this->assertTrue( \WPLC\License\LicenseIssuer::money_has_arrived( $paid ), 'Default: only cash on delivery waits.' );
		$this->submit( array( 'issue_after_completion_gateways' => "cod\nbacs" ) );
		$this->assertSame( array( 'cod', 'bacs' ), Registry::list( 'issue_after_completion_gateways' ) );
		$this->assertFalse( \WPLC\License\LicenseIssuer::money_has_arrived( $paid ) );
		$this->submit( array( 'issue_after_completion_gateways' => 'Not a gateway!' ) );
		$this->assertSame( array( 'cod', 'bacs' ), Registry::list( 'issue_after_completion_gateways' ), 'Invalid IDs are refused.' );

		// Deleting the saved card.
		$card = $this->seed_license( array( 'renewal_enabled' => 1, 'payment_token_id' => 4811 ) );
		wp_set_current_user( $customer );
		$GLOBALS['wp']->query_vars['delete-payment-method'] = '4811';
		wp_set_current_user( $this->admin );
		$this->submit( array( 'renewal_off_on_card_deletion' => '0' ) );
		wp_set_current_user( $customer );
		do_action( 'woocommerce_payment_token_deleted', 4811, null );
		unset( $GLOBALS['wp']->query_vars['delete-payment-method'] );
		$this->assertSame( 1, (int) $this->license( $card['id'] )['renewal_enabled'], 'Switched off: automatic renewal stays on.' );
		wp_set_current_user( $this->admin );

		// Cancellation page: requests per hour (legal weight).
		$this->assertTrue( Registry::definition( 'public_cancel_hourly_limit' )['legal'] );
		$this->submit( array( 'public_cancel_hourly_limit' => '2' ) );
		$seen = null;
		add_filter(
			'wplc_public_cancel_hourly_limit',
			static function ( $limit ) use ( &$seen ) {
				$seen = $limit;
				return $limit;
			}
		);
		$method = new ReflectionMethod( \WPLC\Account\PublicCancellation::class, 'limited' );
		$method->setAccessible( true );
		$method->invoke( null, 'someone@example.test', 'WPLC-1A2B3C4D' );
		remove_all_filters( 'wplc_public_cancel_hourly_limit' );
		$this->assertSame( 2, $seen, 'The filter receives the setting.' );
	}
}
