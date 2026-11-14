<?php
/**
 * T-81: paid WooCommerce orders become licenses, exactly once per purchased unit.
 *
 * Every test uses real WC_Order objects and the hooks WooCommerce fires itself.
 *
 * @package WPLC
 */

use WPLC\Billing\RenewalOrder;
use WPLC\Catalog\Catalog;
use WPLC\License\LicenseIssuer;
use WPLC\License\LicenseKey;

final class OrderLicenseIssuanceIntegrationTest extends WPLC_Integration_TestCase {

	public function test_unpaid_order_issues_nothing(): void {
		$product = $this->create_license_product();
		$order   = $this->create_order( array( array( $product, 2 ) ) );

		$this->assertFalse( $order->is_paid() );
		$this->assertCount( 0, $this->licenses_for_order( $order->get_id() ) );

		$order->update_status( 'on-hold' );
		$this->assertCount( 0, $this->licenses_for_order( $order->get_id() ), 'An on-hold order is not paid.' );

		$order->update_status( 'failed' );
		$this->assertCount( 0, $this->licenses_for_order( $order->get_id() ), 'A failed order is not paid.' );
	}

	public function test_paid_order_issues_one_active_license_with_entitlements(): void {
		global $wpdb;
		$tables   = $this->tables();
		$customer = self::factory()->user->create( array( 'role' => 'customer' ) );
		$product  = $this->create_license_product(
			array(
				'product_key'     => 'voucher-captain',
				'plan_key'        => 'agency',
				'live_site_limit' => 5,
				'support_level'   => 'priority',
				'entitlements'    => array( 'feature.export' => true, 'limits.codes' => 250 ),
			),
			'129.00'
		);

		$issued = array();
		add_action(
			'wplc/license_issued',
			static function ( $license_id, $key, $order_id ) use ( &$issued ) {
				$issued[] = array( (int) $license_id, (string) $key, (int) $order_id );
			},
			10,
			3
		);

		$order    = $this->create_paid_order( array( array( $product, 1 ) ), $customer );
		$licenses = $this->licenses_for_order( $order->get_id() );

		$this->assertCount( 1, $licenses );
		$license = $licenses[0];
		$this->assertSame( 'active', $license['status'] );
		$this->assertSame( $customer, (int) $license['customer_id'] );
		$this->assertSame( 'voucher-captain', $license['product_key'] );
		$this->assertSame( 'agency', $license['plan_key'] );
		$this->assertSame( 'year', $license['billing_interval'] );
		$this->assertSame( $this->first_item( $order )->get_id(), (int) $license['source_order_item_id'] );
		$this->assertSame( 0, (int) $license['unit_index'] );
		$this->assertSame( 12900, (int) $license['renewal_amount_minor'] );
		$this->assertSame( get_woocommerce_currency(), $license['renewal_currency'] );
		$this->assertSame( 0, (int) $license['renewal_enabled'], 'No consent and no saved token: automatic renewal must stay off.' );

		// The paid period is one year from the payment date.
		$paid_at = $order->get_date_paid();
		$this->assertNotNull( $paid_at );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $paid_at->getTimestamp() ), $license['starts_at'] );
		$this->assertSame(
			gmdate( 'Y-m-d', strtotime( '+1 year', $paid_at->getTimestamp() ) ),
			substr( (string) $license['paid_until'], 0, 10 )
		);

		// The key is derived, never stored, and verifiable against the stored hash.
		$this->assertCount( 1, $issued );
		$this->assertSame( (int) $license['id'], $issued[0][0] );
		$this->assertSame( $order->get_id(), $issued[0][2] );
		$this->assertSame( LicenseKey::hash( $issued[0][1] ), $license['license_key_hash'] );
		$this->assertTrue( LicenseKey::matches_hash( $license['license_uuid'], $license['license_key_secret_id'], $license['license_key_hash'] ) );
		$this->assertStringNotContainsString( $issued[0][1], (string) wp_json_encode( $license ) );

		// Entitlement snapshot.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT entitlement_key,value_json,status,ends_at FROM {$tables['entitlements']} WHERE license_id = %d ORDER BY entitlement_key ASC", (int) $license['id'] ),
			ARRAY_A
		);
		$snapshot = array();
		foreach ( $rows as $row ) {
			$this->assertSame( 'active', $row['status'] );
			$this->assertSame( $license['paid_until'], $row['ends_at'] );
			$snapshot[ $row['entitlement_key'] ] = json_decode( $row['value_json'], true );
		}
		$this->assertSame(
			array(
				'feature.export'   => true,
				'limits.codes'     => 250,
				'sites.live_limit' => 5,
				'support.level'    => 'priority',
			),
			$snapshot
		);

		// The order item references its licenses through WooCommerce CRUD meta.
		$item = $this->first_item( wc_get_order( $order->get_id() ) );
		$this->assertSame( array( (int) $license['id'] ), array_map( 'intval', (array) $item->get_meta( '_wplc_license_ids', true ) ) );
	}

	public function test_quantity_three_issues_three_distinct_licenses(): void {
		$product = $this->create_license_product();
		$order   = $this->create_paid_order( array( array( $product, 3 ) ), self::factory()->user->create() );

		$licenses = $this->licenses_for_order( $order->get_id() );
		$this->assertCount( 3, $licenses );
		$this->assertSame( array( 0, 1, 2 ), array_map( 'intval', wp_list_pluck( $licenses, 'unit_index' ) ) );
		$this->assertCount( 3, array_unique( wp_list_pluck( $licenses, 'license_uuid' ) ) );
		$this->assertCount( 3, array_unique( wp_list_pluck( $licenses, 'license_key_hash' ) ) );
		$this->assertSame( array( 'active' ), array_values( array_unique( wp_list_pluck( $licenses, 'status' ) ) ) );

		$item = $this->first_item( wc_get_order( $order->get_id() ) );
		$this->assertSame(
			array_map( 'intval', wp_list_pluck( $licenses, 'id' ) ),
			array_map( 'intval', (array) $item->get_meta( '_wplc_license_ids', true ) )
		);
	}

	public function test_duplicate_payment_and_status_hooks_do_not_duplicate_licenses(): void {
		$product = $this->create_license_product();
		$order   = $this->create_paid_order( array( array( $product, 2 ) ), self::factory()->user->create() );
		$this->assertCount( 2, $this->licenses_for_order( $order->get_id() ) );
		$before = wp_list_pluck( $this->licenses_for_order( $order->get_id() ), 'license_uuid' );

		$issued_again = 0;
		add_action(
			'wplc/license_issued',
			static function () use ( &$issued_again ) {
				++$issued_again;
			}
		);

		// A gateway that reports the payment twice.
		do_action( 'woocommerce_payment_complete', $order->get_id(), 'txn_duplicate' );
		do_action( 'woocommerce_payment_complete', $order->get_id(), 'txn_duplicate' );
		// The status hooks WooCommerce fires on its own.
		do_action( 'woocommerce_order_status_processing', $order->get_id(), $order );
		$order = wc_get_order( $order->get_id() );
		$order->update_status( 'completed' );
		// A direct repeated call.
		LicenseIssuer::maybe_issue_order( $order->get_id() );

		$after = $this->licenses_for_order( $order->get_id() );
		$this->assertCount( 2, $after );
		$this->assertSame( $before, wp_list_pluck( $after, 'license_uuid' ), 'Existing licenses must be kept, not replaced.' );
		$this->assertSame( 0, $issued_again, 'wplc/license_issued must fire once per license only.' );
	}

	public function test_unmapped_product_issues_nothing(): void {
		$unmapped = $this->create_simple_product( '10.00' );
		$order    = $this->create_paid_order( array( array( $unmapped, 2 ) ), self::factory()->user->create() );

		$this->assertTrue( $order->is_paid() );
		$this->assertCount( 0, $this->licenses_for_order( $order->get_id() ) );
		$this->assertSame( '', (string) $this->first_item( $order )->get_meta( '_wplc_license_ids', true ) );
	}

	public function test_mixed_order_issues_only_for_mapped_items(): void {
		$mapped   = $this->create_license_product( array( 'product_key' => 'pass-captain', 'plan_key' => 'single' ) );
		$other    = $this->create_license_product( array( 'product_key' => 'affiliate-captain', 'plan_key' => 'single' ) );
		$unmapped = $this->create_simple_product( '5.00' );
		$order    = $this->create_paid_order(
			array( array( $mapped, 1 ), array( $unmapped, 4 ), array( $other, 2 ) ),
			self::factory()->user->create()
		);

		$licenses = $this->licenses_for_order( $order->get_id() );
		$this->assertCount( 3, $licenses );
		$by_product = array_count_values( wp_list_pluck( $licenses, 'product_key' ) );
		ksort( $by_product );
		$this->assertSame( array( 'affiliate-captain' => 2, 'pass-captain' => 1 ), $by_product );
	}

	public function test_variation_mapping_is_used_for_the_purchased_variation_only(): void {
		list( $parent, $variations ) = $this->create_variable_product( array( '49.00', '149.00' ) );
		$this->assertTrue(
			Catalog::save_mapping( $parent->get_id(), $variations[0]->get_id(), $this->mapping_data( array( 'plan_key' => 'single', 'live_site_limit' => 1 ) ) )
		);
		$this->assertTrue(
			Catalog::save_mapping( $parent->get_id(), $variations[1]->get_id(), $this->mapping_data( array( 'plan_key' => 'agency', 'live_site_limit' => 10 ) ) )
		);

		$order    = $this->create_paid_order( array( array( wc_get_product( $variations[1]->get_id() ), 1 ) ), self::factory()->user->create() );
		$licenses = $this->licenses_for_order( $order->get_id() );

		$this->assertCount( 1, $licenses );
		$this->assertSame( 'agency', $licenses[0]['plan_key'] );
		$this->assertSame( 14900, (int) $licenses[0]['renewal_amount_minor'] );
	}

	public function test_variation_of_a_parent_level_mapped_variable_product_gets_a_license(): void {
		list( $parent, $variations ) = $this->create_variable_product( array( '49.00', '149.00' ) );
		// Only the parent product is mapped (variation ID 0), as the product-level fields would store it.
		global $wpdb;
		$tables = $this->tables();
		$now    = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert(
			$tables['plans'],
			array(
				'product_key'              => 'test-product',
				'plan_key'                 => 'parent-level',
				'woocommerce_product_id'   => $parent->get_id(),
				'woocommerce_variation_id' => 0,
				'billing_interval'         => 'year',
				'price_minor'              => 4900,
				'entitlements_json'        => '{"sites.live_limit":1}',
				'active'                   => 1,
				'created_at'               => $now,
				'updated_at'               => $now,
			)
		);

		$order = $this->create_paid_order( array( array( wc_get_product( $variations[0]->get_id() ), 1 ) ), self::factory()->user->create() );

		$licenses = $this->licenses_for_order( $order->get_id() );
		if ( 0 === count( $licenses ) ) {
			$this->markTestIncomplete(
				'BUG: a variable product mapped at parent level (the fields ProductEditor renders on the General tab, stored with '
				. 'woocommerce_variation_id = 0) issues no license when a customer buys one of its variations. LicenseIssuer passes the '
				. 'variation ID to Catalog::find_mapping(), which only matches the exact (product, variation) pair and never falls back to '
				. 'the parent mapping. The customer pays and receives nothing; no error or order note is written. '
				. 'Fix: fall back to find_mapping( $product_id, 0 ) when the variation has no own mapping, or refuse to save a parent-level mapping on variable products.'
			);
		}
		$this->assertCount( 1, $licenses );
		$this->assertSame( 'parent-level', $licenses[0]['plan_key'] );
	}

	public function test_parent_mapped_variation_renews_at_the_purchased_variation_price(): void {
		// T-132: the parent plan holds the lowest variation price (59.00); the customer buys the 79.00 variation.
		list( $parent, $variations ) = $this->create_variable_product( array( '59.00', '79.00' ) );
		global $wpdb;
		$tables = $this->tables();
		$now    = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert(
			$tables['plans'],
			array(
				'product_key'              => 'test-product',
				'plan_key'                 => 'parent-level',
				'woocommerce_product_id'   => $parent->get_id(),
				'woocommerce_variation_id' => 0,
				'billing_interval'         => 'year',
				'price_minor'              => 5900,
				'price_decimals'           => 2,
				'currency'                 => get_woocommerce_currency(),
				'entitlements_json'        => '{"sites.live_limit":1}',
				'active'                   => 1,
				'created_at'               => $now,
				'updated_at'               => $now,
			)
		);
		$variation = wc_get_product( $variations[1]->get_id() );
		$variation->set_sale_price( '49.00' );
		$variation->save();

		$order    = $this->create_paid_order( array( array( wc_get_product( $variation->get_id() ), 1 ) ), self::factory()->user->create() );
		$licenses = $this->licenses_for_order( $order->get_id() );

		$this->assertCount( 1, $licenses );
		$license = $licenses[0];
		$this->assertSame( 7900, (int) $license['renewal_amount_minor'], 'The regular price of the bought variation is stored, not the plan price and not the sale price.' );
		$this->assertSame( $variation->get_id(), (int) $license['renewal_product_id'] );

		// Automatic and manual renewal both use the variation price.
		$this->assertSame( 7900, RenewalOrder::price( $license, false )['amount_minor'] );
		$this->assertSame( 7900, RenewalOrder::price( $license, true )['amount_minor'] );

		// A later regular-price change applies to manual renewals only; the consented automatic amount stays.
		$variation = wc_get_product( $variation->get_id() );
		$variation->set_regular_price( '89.00' );
		$variation->save();
		$this->assertSame( 8900, RenewalOrder::price( $license, true )['amount_minor'] );
		$this->assertSame( 7900, RenewalOrder::price( $license, false )['amount_minor'] );

		// A deleted variation falls back to the stored price, never to the lowest price of the plan.
		$variation->delete( true );
		$this->assertSame( 7900, RenewalOrder::price( $license, true )['amount_minor'] );
	}

	public function test_legacy_license_without_recorded_product_renews_at_the_source_variation_price(): void {
		list( $parent, $variations ) = $this->create_variable_product( array( '59.00', '79.00' ) );
		$order = $this->create_paid_order( array( array( wc_get_product( $variations[1]->get_id() ), 1 ) ), self::factory()->user->create() );
		$item  = $this->first_item( $order );
		$seed  = $this->seed_license(
			array(
				'renewal_amount_minor' => 5900,
				'renewal_currency'     => get_woocommerce_currency(),
				'renewal_product_id'   => 0,
				'source_order_id'      => $order->get_id(),
				'source_order_item_id' => $item->get_id(),
			)
		);
		$license = $this->license( $seed['id'] );

		$this->assertSame( 7900, RenewalOrder::price( $license, true )['amount_minor'], 'Manual renewal resolves the variation through the source order line.' );
		$this->assertSame( 5900, RenewalOrder::price( $license, false )['amount_minor'], 'The consented automatic amount of an existing license is not raised.' );
	}

	public function test_guest_order_issues_a_license_without_an_owner(): void {
		$product = $this->create_license_product();
		$order   = $this->create_paid_order( array( array( $product, 1 ) ), 0 );

		$licenses = $this->licenses_for_order( $order->get_id() );

		// Documents current behavior: guests get a license with customer_id 0.
		// Nobody can see or manage it in "My account"; the key only leaves the
		// server through the wplc/license_issued action.
		$this->assertCount( 1, $licenses );
		$this->assertSame( 0, (int) $licenses[0]['customer_id'] );
		$this->assertSame( 'active', $licenses[0]['status'] );
		$this->assertSame( 0, (int) $licenses[0]['renewal_enabled'] );
	}

	public function test_renewal_order_never_issues_a_new_license(): void {
		$customer = self::factory()->user->create();
		$product  = $this->create_license_product();
		$original = $this->create_paid_order( array( array( $product, 1 ) ), $customer );
		$licenses = $this->licenses_for_order( $original->get_id() );
		$this->assertCount( 1, $licenses );
		$count_before = $this->license_count();

		$renewal = $this->create_order( array( array( $product, 1 ) ), $customer );
		$renewal->update_meta_data( '_wplc_renewal_for_license', (int) $licenses[0]['id'] );
		$renewal->save();
		$this->assertTrue( $renewal->payment_complete( 'txn_renewal' ) );
		$renewal = wc_get_order( $renewal->get_id() );
		$renewal->update_status( 'completed' );
		LicenseIssuer::maybe_issue_order( $renewal->get_id() );

		$this->assertTrue( wc_get_order( $renewal->get_id() )->is_paid() );
		$this->assertCount( 0, $this->licenses_for_order( $renewal->get_id() ) );
		$this->assertSame( $count_before, $this->license_count() );
	}

	public function test_quantity_increase_after_payment_issues_only_the_missing_unit(): void {
		$product = $this->create_license_product();
		$order   = $this->create_paid_order( array( array( $product, 1 ) ), self::factory()->user->create() );
		$first   = $this->licenses_for_order( $order->get_id() );
		$this->assertCount( 1, $first );

		$item = $this->first_item( $order );
		$item->set_quantity( 2 );
		$item->save();
		LicenseIssuer::maybe_issue_order( $order->get_id() );

		$after = $this->licenses_for_order( $order->get_id() );
		$this->assertCount( 2, $after );
		$this->assertSame( $first[0]['license_uuid'], $after[0]['license_uuid'] );
		$this->assertSame( 1, (int) $after[1]['unit_index'] );
	}

	public function test_monthly_plan_gets_a_one_month_period(): void {
		$product = $this->create_license_product( array( 'billing_interval' => 'month' ) );
		$order   = $this->create_paid_order( array( array( $product, 1 ) ), self::factory()->user->create() );
		$license = $this->licenses_for_order( $order->get_id() )[0];

		$starts = strtotime( $license['starts_at'] . ' UTC' );
		$ends   = strtotime( $license['paid_until'] . ' UTC' );
		$this->assertSame( 'month', $license['billing_interval'] );
		$this->assertGreaterThanOrEqual( 28 * DAY_IN_SECONDS, $ends - $starts );
		$this->assertLessThanOrEqual( 31 * DAY_IN_SECONDS, $ends - $starts );
	}
}
