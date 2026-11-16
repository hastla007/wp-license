<?php
/**
 * B-117: pricing table block and shortcode.
 *
 * @package WPLC
 */

use WPLC\Account\PricingTable;
use WPLC\Catalog\Catalog;

final class PricingTableIntegrationTest extends WPLC_Integration_TestCase {

	/**
	 * Options changed by a test, restored in tear_down().
	 *
	 * @var array<string,mixed>
	 */
	private $options = array();

	public function tear_down(): void {
		foreach ( $this->options as $name => $value ) {
			update_option( $name, $value );
		}
		$this->options = array();
		parent::tear_down();
	}

	private function set_option( string $name, $value ): void {
		if ( ! array_key_exists( $name, $this->options ) ) {
			$this->options[ $name ] = get_option( $name );
		}
		update_option( $name, $value );
	}

	private function text( string $html ): string {
		return html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );
	}

	private function named_license_product( string $name, string $price, array $mapping ): WC_Product_Simple {
		$product = $this->create_license_product( array_merge( array( 'product_key' => 'pricing-product' ), $mapping ), $price );
		$product->set_name( $name );
		$product->save();

		return $product;
	}

	public function test_b117_renders_the_plans_with_prices_sites_features_and_buy_links(): void {
		$agency  = $this->named_license_product(
			'Agency plan',
			'149.00',
			array(
				'plan_key' => 'agency',
				'live_site_limit' => 5,
				'entitlements' => array(
					'voucher.bulk_orders' => true,
					'voucher.white_label' => false,
					'feature.secret_flag' => true,
				),
			)
		);
		$starter = $this->named_license_product( 'Starter plan', '49.00', array( 'plan_key' => 'starter', 'billing_interval' => 'month' ) );

		$html = do_shortcode( '[wplc_pricing product="pricing-product" highlight="agency"]' );

		$this->assertStringContainsString( 'Starter plan', $html );
		$this->assertStringContainsString( 'Agency plan', $html );
		$this->assertLessThan( strpos( $html, 'Agency plan' ), strpos( $html, 'Starter plan' ), 'Cheapest plan first.' );
		$this->assertStringContainsString( $this->text( wc_price( 149 ) ), $this->text( $html ), 'Price with the WooCommerce formatting.' );
		$this->assertStringContainsString( $this->text( wc_price( 49 ) ), $this->text( $html ) );
		$this->assertStringContainsString( 'for 1 month', $html );
		$this->assertStringContainsString( 'for 1 year', $html );
		$this->assertStringContainsString( '5 live sites', $html );
		$this->assertStringContainsString( '1 live site<', $html );
		$this->assertStringContainsString( '<li>Company bulk orders</li>', $html, 'Label from EntitlementCatalog.' );
		$this->assertStringNotContainsString( 'White label', $html, 'An entitlement set to false is not listed.' );
		$this->assertStringNotContainsString( 'secret_flag', $html, 'Keys without a label stay out of the shop front.' );
		$this->assertStringContainsString( 'add-to-cart=' . $agency->get_id(), $html );
		$this->assertStringContainsString( 'add-to-cart=' . $starter->get_id(), $html );
		$this->assertSame( 1, substr_count( $html, 'wplc-pricing__plan--highlight' ) );
		$this->assertSame( 1, preg_match( '/wplc-pricing__plan--highlight">.*?<\/section>/s', $html, $highlighted ) );
		$this->assertStringContainsString( 'Agency plan', $highlighted[0] );
		$this->assertTrue( wp_style_is( 'wplc-account', 'enqueued' ), 'The front-end stylesheet is loaded with the table.' );

		$plain = do_shortcode( '[wplc_pricing product="pricing-product" show_features="no"]' );
		$this->assertStringNotContainsString( 'Company bulk orders', $plain );
		$this->assertStringContainsString( '5 live sites', $plain, 'The site limit is always shown.' );
		$this->assertStringNotContainsString( 'wplc-pricing__plan--highlight', $plain );

		$block = do_blocks( '<!-- wp:wplc/pricing {"product":"pricing-product","showFeatures":false} /-->' );
		$this->assertStringContainsString( 'Agency plan', $block );
		$this->assertStringNotContainsString( 'Company bulk orders', $block );
		$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( 'wplc/pricing' ) );
	}

	public function test_b117_prices_follow_the_tax_display_setting(): void {
		$this->set_option( 'woocommerce_calc_taxes', 'yes' );
		$this->set_option( 'woocommerce_prices_include_tax', 'no' );
		$this->set_option( 'woocommerce_tax_display_shop', 'incl' );
		$this->set_option( 'woocommerce_price_display_suffix', 'incl. VAT' );
		$rate = WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country' => '',
				'tax_rate' => '19.0000',
				'tax_rate_name' => 'VAT',
				'tax_rate_priority' => 1,
				'tax_rate_compound' => 0,
				'tax_rate_shipping' => 1,
				'tax_rate_order' => 1,
				'tax_rate_class' => '',
			)
		);
		WC_Cache_Helper::invalidate_cache_group( 'taxes' );

		try {
			$this->named_license_product( 'Taxed plan', '100.00', array( 'plan_key' => 'taxed' ) );
			$html = $this->text( PricingTable::html( 'pricing-product' ) );
			$this->assertStringContainsString( $this->text( wc_price( 119 ) ), $html, 'Gross price when the shop shows prices incl. tax.' );
			$this->assertStringContainsString( 'incl. VAT', $html, 'The price suffix of the shop is kept.' );

			$this->set_option( 'woocommerce_tax_display_shop', 'excl' );
			$html = $this->text( PricingTable::html( 'pricing-product' ) );
			$this->assertStringContainsString( $this->text( wc_price( 100 ) ), $html, 'Net price when the shop shows prices excl. tax.' );
		} finally {
			WC_Tax::_delete_tax_rate( $rate );
		}
	}

	public function test_b117_variations_of_a_parent_plan_and_own_variation_plans_are_listed(): void {
		list( $parent, $variations ) = $this->create_variable_product(
			array(
				'basic' => '30.00',
				'pro' => '90.00',
			)
		);
		$this->assertTrue( Catalog::save_mapping( $parent->get_id(), 0, $this->mapping_data( array( 'product_key' => 'pricing-product', 'plan_key' => 'basic' ) ) ) );
		$this->assertTrue( Catalog::save_mapping( $parent->get_id(), $variations[1]->get_id(), $this->mapping_data( array( 'product_key' => 'pricing-product', 'plan_key' => 'pro', 'live_site_limit' => 3 ) ) ) );

		$html = PricingTable::html( 'pricing-product' );

		$this->assertSame( 2, substr_count( $html, '<section class="wplc-pricing__plan' ), 'One column per variation, the one with its own plan only once.' );
		$this->assertStringContainsString( '>basic<', $html );
		$this->assertStringContainsString( '>pro<', $html );
		$this->assertStringContainsString( '3 live sites', $html );
		$this->assertStringContainsString( 'variation_id=' . $variations[0]->get_id(), $html );
		$this->assertStringContainsString( 'variation_id=' . $variations[1]->get_id(), $html );
	}

	public function test_b117_hides_unpublished_unmapped_and_inactive_products(): void {
		global $wpdb;
		$t = $this->tables();

		$this->named_license_product( 'Visible plan', '49.00', array( 'plan_key' => 'visible' ) );
		$draft = $this->named_license_product( 'Draft plan', '59.00', array( 'plan_key' => 'draft' ) );
		$draft->set_status( 'draft' );
		$draft->save();
		$protected = $this->named_license_product( 'Protected plan', '69.00', array( 'plan_key' => 'protected' ) );
		wp_update_post(
			array(
				'ID' => $protected->get_id(),
				'post_password' => 'secret',
			)
		);
		$deactivated = $this->named_license_product( 'Deactivated plan', '79.00', array( 'plan_key' => 'deactivated' ) );
		$wpdb->update( $t['plans'], array( 'active' => 0 ), array( 'woocommerce_product_id' => $deactivated->get_id() ) );
		list( $parent, $variations ) = $this->create_variable_product( array( 'hidden' => '89.00' ) );
		$this->assertTrue( Catalog::save_mapping( $parent->get_id(), $variations[0]->get_id(), $this->mapping_data( array( 'product_key' => 'pricing-product', 'plan_key' => 'hidden-variation' ) ) ) );
		$variations[0]->set_status( 'private' );
		$variations[0]->save();
		$unpriced = $this->named_license_product( 'Unpriced plan', '99.00', array( 'plan_key' => 'unpriced' ) );
		$unpriced->set_regular_price( '' );
		$unpriced->save();
		// A plan whose WooCommerce product no longer exists.
		$orphan = $this->named_license_product( 'Orphan plan', '109.00', array( 'plan_key' => 'orphan' ) );
		$wpdb->update( $t['plans'], array( 'woocommerce_product_id' => 999999 ), array( 'woocommerce_product_id' => $orphan->get_id() ) );
		// An ordinary product of the shop without a plan.
		$plain = $this->create_simple_product( '19.00' );

		$html = do_shortcode( '[wplc_pricing product="pricing-product"]' );

		$this->assertStringContainsString( 'Visible plan', $html );
		foreach ( array( 'Draft plan', 'Protected plan', 'Deactivated plan', 'hidden', 'Unpriced plan', 'Orphan plan', $plain->get_name() ) as $hidden ) {
			$this->assertStringNotContainsString( $hidden, $html, $hidden . ' must not be offered.' );
		}
		$this->assertSame( 1, substr_count( $html, '<section class="wplc-pricing__plan' ) );

		$this->assertSame( '', do_shortcode( '[wplc_pricing product="unknown-product"]' ) );
		$this->assertSame( '', do_shortcode( '[wplc_pricing]' ) );

		$wpdb->update( $t['products'], array( 'active' => 0 ), array( 'product_key' => 'pricing-product' ) );
		$this->assertSame( '', do_shortcode( '[wplc_pricing product="pricing-product"]' ), 'An inactive license product shows no table.' );
	}
}
