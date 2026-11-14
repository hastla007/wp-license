<?php

use WPLC\Catalog\Catalog;

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) { return strtolower( preg_replace( '/[^a-z0-9_\\-]/', '', (string) $key ) ); }
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) { return abs( (int) $value ); }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value ) { return json_encode( $value ); }
}
if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type, $gmt = false ) { return '2026-11-12 00:00:00'; }
}
if ( ! function_exists( 'wc_get_price_decimals' ) ) {
	function wc_get_price_decimals() { return 3; }
}
if ( ! function_exists( 'get_woocommerce_currency' ) ) {
	function get_woocommerce_currency() { return 'KWD'; }
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() { return 0; }
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); }
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $name ) { return true; }
}
if ( ! function_exists( 'wc_get_product' ) ) {
	function wc_get_product( $product_id ) { return $GLOBALS['wplc_catalog_test_product'] ?? null; }
}

final class CatalogPrecisionProduct {
	public function get_price( $context = 'view' ) { return '12.345'; }
	public function get_name() { return 'Precision product'; }
}

final class CatalogPrecisionWpdb {
	public $prefix = 'wp_';
	public $writes = array();
	public $posts = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	/** B-51: the catalog upserts with INSERT ... ON DUPLICATE KEY UPDATE instead of REPLACE. */
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		return array( 'query' => $query, 'args' => $args );
	}
	public function query( $prepared ) {
		$this->writes[] = $prepared;
		return 1;
	}
	public function get_row( $prepared, $output = null ) {
		return null;
	}
	public function insert( $table, $data, $formats = null ) {
		return 1;
	}
}

final class CatalogPricePrecisionTest extends PHPUnit\Framework\TestCase {
	private $previous_wpdb;
	private $previous_product;

	protected function setUp(): void {
		global $wpdb;
		$this->previous_wpdb = $wpdb ?? null;
		$this->previous_product = $GLOBALS['wplc_catalog_test_product'] ?? null;
		$wpdb = new CatalogPrecisionWpdb();
		$GLOBALS['wplc_catalog_test_product'] = new CatalogPrecisionProduct();
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb = $this->previous_wpdb;
		$GLOBALS['wplc_catalog_test_product'] = $this->previous_product;
		unset( $GLOBALS['wplc_catalog_test_product'] );
	}

	public function test_catalog_persists_store_currency_decimal_precision(): void {
		global $wpdb;
		$this->assertTrue( Catalog::save_mapping( 123, 0, array(
			'product_key' => 'license-product',
			'plan_key' => 'business',
			'billing_interval' => 'year',
		) ) );
		$plan_write = $wpdb->writes[1];
		$this->assertStringContainsString( 'wplc_plans', $plan_write['query'] );
		$this->assertStringContainsString( 'ON DUPLICATE KEY UPDATE', $plan_write['query'] );
		$this->assertStringNotContainsString( 'REPLACE', $plan_write['query'] );
		// Insert values: product_key, plan_key, product ID, variation ID, interval, price_minor, price_decimals, currency.
		$this->assertSame( 12345, $plan_write['args'][5] );
		$this->assertSame( 3, $plan_write['args'][6] );
		$this->assertSame( 'KWD', $plan_write['args'][7] );
	}

	/** B-123, B-174: keys are validated against one pattern and never rewritten. */
	public function test_keys_are_rejected_not_rewritten(): void {
		global $wpdb;
		foreach ( array( 'Jährlich', '_pro', '-pro', 'x', 'has space', 'under_score', str_repeat( 'a', 101 ) ) as $bad ) {
			$this->assertFalse( Catalog::save_mapping( 123, 0, array( 'product_key' => $bad, 'plan_key' => 'business', 'billing_interval' => 'year' ) ), $bad );
			$this->assertSame( 'invalid_product_key', Catalog::last_error() );
		}
		foreach ( array( 'Jährlich', '_pro', '-pro', '', 'a b' ) as $bad ) {
			$this->assertFalse( Catalog::save_mapping( 123, 0, array( 'product_key' => 'license-product', 'plan_key' => $bad, 'billing_interval' => 'year' ) ), $bad );
			$this->assertSame( 'invalid_plan_key', Catalog::last_error() );
		}
		$this->assertSame( array(), $wpdb->writes );

		$this->assertTrue( Catalog::is_valid_product_key( 'voucher-captain' ) );
		$this->assertTrue( Catalog::is_valid_product_key( '2fa-pro' ) );
		$this->assertTrue( Catalog::is_valid_plan_key( 'p' ) );
		// Surrounding blanks and capitals typed by a person are normalized, nothing else.
		$this->assertSame( 'voucher-captain', Catalog::normalize_key( '  Voucher-Captain ' ) );
		// Every valid catalog key is also a valid release product key.
		$this->assertSame( 1, preg_match( \WPLC\Update\ReleaseRegistry::PRODUCT_KEY_PATTERN, 'voucher-captain' ) );
		$this->assertSame( 0, preg_match( \WPLC\Update\ReleaseRegistry::PRODUCT_KEY_PATTERN, 'x' ) );
	}

	/** B-179: a non-finite entitlement value is rejected. */
	public function test_non_finite_entitlement_value_is_rejected(): void {
		$this->assertFalse( Catalog::save_mapping( 123, 0, array( 'product_key' => 'license-product', 'plan_key' => 'business', 'billing_interval' => 'year', 'entitlements' => json_decode( '{"limit.x":1e999}', true ) ) ) );
		$this->assertSame( 'invalid_entitlements', Catalog::last_error() );
	}
}
