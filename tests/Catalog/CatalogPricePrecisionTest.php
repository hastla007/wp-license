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
	public function replace( $table, $data, $formats = null ) {
		$this->writes[] = array( 'table' => $table, 'data' => $data, 'formats' => $formats );
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
		$this->assertSame( 3, $wpdb->writes[1]['data']['price_decimals'] );
		$this->assertSame( 'KWD', $wpdb->writes[1]['data']['currency'] );
	}
}
