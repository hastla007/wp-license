<?php
/**
 * Overrides per plan: who may change them, sold licenses of trashed products, cost per check,
 * the product editor form (P4-M1, P2-I1, P6-I1, P6-M5, P5-I2, P5-I3, P5-M17, P2-M5).
 *
 * @package WPLC
 */

use WPLC\Admin\CatalogCsv;
use WPLC\Api\V1\ActivationService;
use WPLC\Api\V1\EntitlementService;
use WPLC\Billing\RenewalOrder;
use WPLC\Catalog\Catalog;
use WPLC\Catalog\PlanOverrides;
use WPLC\Catalog\ProductEditor;
use WPLC\License\LicenseMembers;

final class PlanOverridesIntegrationTest extends WPLC_Integration_TestCase {

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
		$this->admin   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->manager = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		wp_set_current_user( $this->admin );
		PlanOverrides::flush();
		require_once WC()->plugin_path() . '/includes/admin/wc-meta-box-functions.php';
		if ( class_exists( 'WC_Admin_Meta_Boxes' ) ) {
			WC_Admin_Meta_Boxes::$meta_box_errors = array();
		}
	}

	public function tear_down(): void {
		PlanOverrides::flush();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function plan( int $product_id ): array {
		$plan = Catalog::find_exact_mapping( $product_id, 0, true );
		$this->assertIsArray( $plan );
		return $plan;
	}

	private function editor_html( int $product_id ): string {
		ob_start();
		try {
			global $post, $thepostid;
			$post      = get_post( $product_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			$thepostid = $product_id; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			ProductEditor::render_product_fields();
		} finally {
			$html = (string) ob_get_clean();
		}
		return $html;
	}

	private function editor_post( array $fields ): void {
		$_POST = wp_slash(
			array_merge(
				array(
					'woocommerce_meta_nonce'   => wp_create_nonce( 'woocommerce_save_data' ),
					ProductEditor::PRESENT_FIELD => '1',
					'_wplc_is_license_product' => 'yes',
					'_wplc_product_key'        => 'test-product',
					'_wplc_plan_key'           => 'single',
					'_wplc_billing_interval'   => 'year',
					'_wplc_live_site_limit'    => '1',
					'_wplc_support_level'      => 'standard',
					'_wplc_entitlements_json'  => '',
				),
				$fields
			)
		);
	}

	private function meta_box_errors(): string {
		return class_exists( 'WC_Admin_Meta_Boxes' ) ? implode( "\n", (array) WC_Admin_Meta_Boxes::$meta_box_errors ) : '';
	}

	private function csv_file( string $content ): string {
		$path = (string) tempnam( sys_get_temp_dir(), 'wplc-csv-' );
		file_put_contents( $path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return $path;
	}

	/** P4-M1: a shop manager cannot change the manufacturer rules of a plan; the largest quantity stays with the catalog. */
	public function test_a_shop_manager_keeps_the_manufacturer_rules_of_a_plan(): void {
		$product = $this->create_license_product( array( 'overrides' => array( 'staging_site_limit' => '2' ) ) );

		wp_set_current_user( $this->manager );
		$this->assertSame( array( 'staging_site_limit', 'development_site_limit', 'offline_cache_days', 'co_user_limit', 'beta_allowed' ), PlanOverrides::locked_fields() );
		$this->assertTrue(
			Catalog::save_mapping(
				$product->get_id(),
				0,
				$this->mapping_data(
					array(
						'overrides' => array(
							'staging_site_limit'    => '9',
							'offline_cache_days'    => '0',
							'co_user_limit'         => '',
							'beta_allowed'          => '1',
							'max_quantity_per_line' => '3',
						),
					)
				)
			),
			'Rules the user may not change do not fail the save, also when out of range.'
		);
		$plan = $this->plan( $product->get_id() );
		$this->assertSame( '2', (string) $plan['staging_site_limit'], 'Stored value kept.' );
		$this->assertNull( $plan['beta_allowed'] );
		$this->assertSame( '3', (string) $plan['max_quantity_per_line'], 'The largest quantity is a rule of the shop.' );
		$this->assertSame( array( 'staging_site_limit', 'offline_cache_days', 'beta_allowed' ), Catalog::kept_overrides(), 'Only fields whose value differed are reported.' );

		// The product editor shows them read-only, without a form field, with the reason.
		$html = $this->editor_html( $product->get_id() );
		$this->assertStringNotContainsString( 'name="_wplc_staging_site_limit"', $html );
		$this->assertStringNotContainsString( 'name="_wplc_beta_allowed"', $html );
		$this->assertStringContainsString( 'name="_wplc_max_quantity_per_line"', $html );
		$this->assertMatchesRegularExpression( '#id="wplc-locked-wplc_staging_site_limit" value="2" readonly aria-describedby="wplc-help-wplc-locked-wplc_staging_site_limit"#', $html );
		$this->assertStringContainsString( 'only users with the capability wplc_manage_policy can change it', $html );

		// A crafted form with the field: kept, and the editor says so.
		$this->editor_post( array( '_wplc_staging_site_limit' => '7' ) );
		ProductEditor::save_product_fields( $product->get_id() );
		$_POST = array();
		$this->assertSame( '2', (string) $this->plan( $product->get_id() )['staging_site_limit'] );
		$this->assertStringContainsString( 'were not changed', $this->meta_box_errors() );

		// The catalog CSV imports the row, keeps the rule and reports it.
		$product->set_sku( 'PO-SKU' );
		$product->save();
		$header = 'product_id,variation_id,sku,product_key,plan_key,billing_interval,live_site_limit,support_level,entitlements_json,staging_site_limit,development_site_limit,offline_cache_days,max_quantity_per_line,co_user_limit,beta_allowed';
		$line   = $product->get_id() . ',0,PO-SKU,test-product,single,year,1,standard,,8,,,5,,';
		$result = CatalogCsv::import_file( $this->csv_file( $header . "\n" . $line . "\n" ) );
		$this->assertSame( 1, $result['imported'], wp_json_encode( $result ) );
		$this->assertSame( array( array( 'line' => 2, 'fields' => array( 'staging_site_limit' ) ) ), $result['kept'] );
		$plan = $this->plan( $product->get_id() );
		$this->assertSame( '2', (string) $plan['staging_site_limit'] );
		$this->assertSame( '5', (string) $plan['max_quantity_per_line'] );

		// An administrator, and a run without a user (WP-CLI, cron), change them as before.
		wp_set_current_user( $this->admin );
		$this->assertSame( array(), PlanOverrides::locked_fields() );
		$this->assertTrue( Catalog::save_mapping( $product->get_id(), 0, $this->mapping_data( array( 'overrides' => array( 'staging_site_limit' => '4' ) ) ) ) );
		$this->assertSame( '4', (string) $this->plan( $product->get_id() )['staging_site_limit'] );
		wp_set_current_user( 0 );
		$this->assertTrue( Catalog::save_mapping( $product->get_id(), 0, $this->mapping_data( array( 'overrides' => array( 'staging_site_limit' => '6' ) ) ) ) );
		$this->assertSame( '6', (string) $this->plan( $product->get_id() )['staging_site_limit'] );
		$this->assertSame( array(), Catalog::kept_overrides() );
	}

	/** P2-I1: a trashed product keeps the rules of its plan for the licenses already sold; prices still need an active plan. */
	public function test_sold_licenses_keep_the_rules_of_a_trashed_product(): void {
		$product  = $this->create_license_product(
			array(
				'overrides' => array(
					'staging_site_limit' => '3',
					'co_user_limit'      => '9',
					'offline_cache_days' => '30',
				),
			)
		);
		$customer = self::factory()->user->create( array( 'role' => 'customer' ) );
		$license  = $this->licenses_for_order( $this->create_paid_order( array( array( $product, 1 ) ), $customer )->get_id() )[0];
		$this->assertSame( 3, ActivationService::staging_site_limit( $license ) );

		wp_trash_post( $product->get_id() );
		$this->assertSame( '0', (string) $this->plan( $product->get_id() )['active'] );

		$this->assertSame( 3, ActivationService::staging_site_limit( $license ), 'Staging rule of the plan kept.' );
		$this->assertSame( 9, LicenseMembers::member_limit( $license ) );
		$now = time();
		$this->assertEqualsWithDelta( $now + 30 * DAY_IN_SECONDS, strtotime( EntitlementService::access_fields( $license, $now )['cache_expires_at'] . ' UTC' ), 2 );
		$this->assertNull( RenewalOrder::plan_for_license( $license ), 'Renewal price keeps asking for active plans.' );
		$this->assertSame( $product->get_id(), (int) RenewalOrder::plan_for_license( $license, true )['woocommerce_product_id'] );

		wp_untrash_post( $product->get_id() );
		$this->assertSame( $product->get_id(), (int) RenewalOrder::plan_for_license( $license )['woocommerce_product_id'] );
	}

	/** P2-I1: a sold license without a live-site entitlement row keeps the live-site limit of its plan after the product is trashed. */
	public function test_sold_licenses_keep_the_live_site_limit_of_a_trashed_product(): void {
		global $wpdb;

		$product  = $this->create_license_product( array( 'live_site_limit' => 4 ) );
		$customer = self::factory()->user->create( array( 'role' => 'customer' ) );
		$license  = $this->licenses_for_order( $this->create_paid_order( array( array( $product, 1 ) ), $customer )->get_id() )[0];
		$tables   = \WPLC\Infrastructure\Database\Schema::table_names();
		$wpdb->delete( $tables['entitlements'], array( 'license_id' => (int) $license['id'], 'entitlement_key' => 'sites.live_limit' ) );
		$this->assertSame( 4, ActivationService::site_limit( (int) $license['id'], $license ), 'Fallback: the plan of the order line.' );

		wp_trash_post( $product->get_id() );
		$this->assertSame( '0', (string) $this->plan( $product->get_id() )['active'] );
		$this->assertSame( 4, ActivationService::site_limit( (int) $license['id'], $license ), 'The limit the customer bought stays.' );

		// Without a plan at all the limit is still 1.
		$orphan = $this->license( $this->seed_license( array( 'product_key' => 'no-such-product', 'plan_key' => 'none' ) )['id'] );
		$wpdb->delete( $tables['entitlements'], array( 'license_id' => (int) $orphan['id'], 'entitlement_key' => 'sites.live_limit' ) );
		$this->assertSame( 1, ActivationService::site_limit( (int) $orphan['id'], $orphan ) );
	}

	/** P6-I1: finding the plan of a license does not load the order; a list costs the same few queries as one license. */
	public function test_plan_lookups_do_not_grow_with_the_number_of_licenses(): void {
		global $wpdb;

		$product  = $this->create_license_product( array( 'overrides' => array( 'staging_site_limit' => '3' ) ) );
		$plain    = $this->create_license_product( array( 'plan_key' => 'plain' ) );
		$customer = self::factory()->user->create( array( 'role' => 'customer' ) );
		$licenses = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$licenses[] = $this->licenses_for_order( $this->create_paid_order( array( array( 0 === $i % 2 ? $product : $plain, 1 ) ), $customer )->get_id() )[0];
		}

		// One license: no order is loaded (nothing in the object cache helps either).
		wp_cache_flush();
		PlanOverrides::flush();
		$this->assertTrue( PlanOverrides::any() );
		$before = $wpdb->num_queries;
		$this->assertSame( 3, PlanOverrides::for_license( $licenses[0], 'staging_site_limit' ) );
		$this->assertSame( 3, ActivationService::staging_site_limit( $licenses[0] ) );
		$this->assertNull( PlanOverrides::for_license( $licenses[0], 'co_user_limit' ) );
		$this->assertLessThanOrEqual( 2, $wpdb->num_queries - $before, 'Order line meta and plan: two queries for every rule of the license.' );

		// A list: the same queries for one license and for five.
		$cost = function ( array $list ) use ( $wpdb ): int {
			wp_cache_flush();
			PlanOverrides::flush();
			PlanOverrides::any();
			$before = $wpdb->num_queries;
			PlanOverrides::prime( $list );
			foreach ( $list as $license ) {
				PlanOverrides::for_license( $license, 'staging_site_limit' );
				PlanOverrides::for_license( $license, 'co_user_limit' );
			}
			return $wpdb->num_queries - $before;
		};
		$one  = $cost( array( $licenses[0] ) );
		$five = $cost( $licenses );
		$this->assertSame( $one, $five, 'Flat: ' . $one . ' vs ' . $five );
		$this->assertLessThanOrEqual( 2, $five );

		// The account page hook reads the license rows of its views in one more query.
		wp_cache_flush();
		PlanOverrides::flush();
		PlanOverrides::any();
		$before = $wpdb->num_queries;
		PlanOverrides::prime_views( array_map( static fn( array $license ): array => array( 'id' => (int) $license['id'] ), $licenses ) );
		foreach ( $licenses as $index => $license ) {
			$this->assertSame( 0 === $index % 2 ? 3 : null, PlanOverrides::for_license( $license, 'staging_site_limit' ) );
		}
		$this->assertLessThanOrEqual( 3, $wpdb->num_queries - $before );
		$this->assertSame( 0, has_action( 'wplc_before_account_licenses', array( PlanOverrides::class, 'prime_views' ) ), 'Registered with the product editor hooks.' );
	}

	/** P6-M5: the cache follows a changed plan key of the license, and a plan saved by another process within MAX_AGE seconds. */
	public function test_the_plan_cache_follows_changes(): void {
		global $wpdb;

		$single   = $this->create_license_product( array( 'overrides' => array( 'staging_site_limit' => '3' ) ) );
		$this->create_license_product( array( 'plan_key' => 'agency', 'overrides' => array( 'staging_site_limit' => '7' ) ) );
		$license  = $this->license( $this->seed_license( array( 'product_key' => 'test-product', 'plan_key' => 'single' ) )['id'] );
		$this->assertSame( 3, PlanOverrides::for_license( $license, 'staging_site_limit' ) );
		$license['plan_key'] = 'agency';
		$this->assertSame( 7, PlanOverrides::for_license( $license, 'staging_site_limit' ), 'Another plan key: looked up again.' );

		// Written by another process (direct SQL, no flush in this one).
		$wpdb->update( $this->tables()['plans'], array( 'staging_site_limit' => 5 ), array( 'woocommerce_product_id' => $single->get_id() ) );
		$license['plan_key'] = 'single';
		$this->assertSame( 3, PlanOverrides::for_license( $license, 'staging_site_limit' ), 'Within MAX_AGE the cached plan is used.' );
		$since = new ReflectionProperty( PlanOverrides::class, 'since' );
		$since->setAccessible( true );
		$since->setValue( null, time() - PlanOverrides::MAX_AGE - 1 );
		$this->assertSame( 5, PlanOverrides::for_license( $license, 'staging_site_limit' ), 'After MAX_AGE the plan is read again.' );
	}

	/** P5-I2, P5-I3, P5-M17, P5-M10, P2-M5: visible help, kept input after an error, the wrong field named, the clamp said. */
	public function test_product_editor_help_errors_and_kept_input(): void {
		$product = $this->create_license_product();

		$html = $this->editor_html( $product->get_id() );
		$this->assertStringNotContainsString( 'woocommerce-help-tip', $html, 'No help only as a tooltip.' );
		$this->assertStringContainsString( 'aria-describedby="wplc-help-wplc_live_site_limit"', $html );
		$this->assertStringContainsString( '<span id="wplc-help-wplc_live_site_limit">Live (production) sites', $html );
		$this->assertStringContainsString( 'aria-describedby="wplc-help-wplc_billing_interval"', $html );
		$this->assertMatchesRegularExpression( '#<span id="wplc-help-wplc_offline_cache_days">[^<]*\(2 to 90\)[^<]*Global: 14</span>#', $html, 'Global value as visible text.' );

		// An override out of range: nothing saved, the message names that field and its limits, the form keeps the input.
		$this->editor_post(
			array(
				'_wplc_plan_key'           => 'business',
				'_wplc_offline_cache_days' => '1',
				'_wplc_staging_site_limit' => '4',
			)
		);
		ProductEditor::save_product_fields( $product->get_id() );
		$_POST  = array();
		$errors = $this->meta_box_errors();
		$this->assertStringContainsString( '&quot;Offline period (days)&quot; must be a whole number from 2 to 90.', $errors );
		$this->assertStringNotContainsString( 'staging sites 0 to 10', $errors );
		$this->assertSame( 'single', (string) $this->plan( $product->get_id() )['plan_key'] );
		$html = $this->editor_html( $product->get_id() );
		$this->assertStringContainsString( 'value="business"', $html );
		$this->assertMatchesRegularExpression( '#name="_wplc_offline_cache_days" id="_wplc_offline_cache_days" value="1"[^>]*aria-invalid="true"#', $html );
		$this->assertMatchesRegularExpression( '#name="_wplc_staging_site_limit" id="_wplc_staging_site_limit" value="4"#', $html );
		$this->assertStringNotContainsString( 'value="business"', $this->editor_html( $product->get_id() ), 'Shown once.' );

		// P5-M10: the interval message names the choices of the select.
		$this->assertSame( 'Choose "Monthly" or "Yearly" as the license billing interval.', Catalog::error_message( 'invalid_interval' ) );
		$this->assertSame( 'The billing interval must be "month" or "year".', CatalogCsv::reason_message( 'invalid_interval' ), 'The CSV holds the stored values.' );

		// P5-M17: a live-site limit outside 1 to 1000 is saved within the limits, with a message.
		WC_Admin_Meta_Boxes::$meta_box_errors = array();
		$this->editor_post( array( '_wplc_live_site_limit' => '5000' ) );
		ProductEditor::save_product_fields( $product->get_id() );
		$_POST = array();
		$this->assertSame( '1000', (string) $this->plan( $product->get_id() )['live_site_limit'] );
		$this->assertStringContainsString( 'The live site limit must be a whole number from 1 to 1000; 1,000 was saved.', $this->meta_box_errors() );
	}

	/** P2-M5: the offline period is at least 2 days; a stored 1 day of an older version counts as 2. */
	public function test_offline_period_is_at_least_two_days(): void {
		global $wpdb;

		$product = $this->create_license_product();
		$this->assertFalse( Catalog::save_mapping( $product->get_id(), 0, $this->mapping_data( array( 'overrides' => array( 'offline_cache_days' => '1' ) ) ) ) );
		$this->assertSame( 'offline_cache_days', Catalog::last_error_field() );
		$wpdb->update( $this->tables()['plans'], array( 'offline_cache_days' => 1 ), array( 'woocommerce_product_id' => $product->get_id() ) );
		PlanOverrides::flush();
		$license = $this->licenses_for_order( $this->create_paid_order( array( array( $product, 1 ) ), self::factory()->user->create() )->get_id() )[0];
		$this->assertSame( 2, PlanOverrides::for_license( $license, 'offline_cache_days' ) );

		update_option( \WPLC\Settings\Registry::OPTION, array( 'offline_cache_days' => 1 ) );
		$this->assertSame( 2, \WPLC\Settings\Registry::int( 'offline_cache_days' ), 'A stored 1 of an older version counts as 2.' );
		delete_option( \WPLC\Settings\Registry::OPTION );
	}
}
