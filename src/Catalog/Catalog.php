<?php
/**
 * Product and plan mapping repository.
 *
 * @package WPLC
 */

namespace WPLC\Catalog;

use WPLC\Billing\Money;
use WPLC\Infrastructure\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists the mapping between WooCommerce products and license plans.
 */
final class Catalog {

	/**
	 * Store or replace a mapping for a WooCommerce product or variation.
	 *
	 * @param int                 $product_id WooCommerce parent product ID.
	 * @param int                 $variation_id WooCommerce variation ID, or zero.
	 * @param array<string,mixed> $data Mapping values.
	 * @return bool
	 */
	public static function save_mapping( int $product_id, int $variation_id, array $data ): bool {
		global $wpdb;

		$product_key = sanitize_key( (string) ( $data['product_key'] ?? '' ) );
		$plan_key    = sanitize_key( (string) ( $data['plan_key'] ?? '' ) );
		$interval    = sanitize_key( (string) ( $data['billing_interval'] ?? 'year' ) );
		$limit       = absint( $data['live_site_limit'] ?? 1 );
		$support     = sanitize_key( (string) ( $data['support_level'] ?? 'standard' ) );

		if ( ! $product_id || '' === $product_key || '' === $plan_key || ! in_array( $interval, array( 'month', 'year' ), true ) ) {
			return false;
		}

		if ( ! in_array( $support, array( 'community', 'standard', 'priority' ), true ) ) {
			$support = 'standard';
		}

		$limit = max( 1, min( 1000, $limit ) );
		$product = wc_get_product( $variation_id ? $variation_id : $product_id );
		if ( ! $product ) {
			return false;
		}

		// The stored price becomes the renewal amount, so it must be the regular
		// price: get_price() returns the sale price while a sale is running.
		$price = is_callable( array( $product, 'get_regular_price' ) ) ? (string) $product->get_regular_price( 'edit' ) : '';
		if ( '' === $price ) {
			$price = (string) $product->get_price( 'edit' );
		}
		$decimals = function_exists( 'wc_get_price_decimals' ) ? max( 0, min( 6, (int) wc_get_price_decimals() ) ) : 2;
		$price_minor = Money::to_minor_units( $price, $decimals );
		if ( false === $price_minor ) {
			return false;
		}
		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'EUR';

		$entitlements = $data['entitlements'] ?? array();
		if ( ! is_array( $entitlements ) ) {
			$entitlements = array();
		}
		$entitlements['sites.live_limit'] = $limit;
		$entitlements['support.level']    = $support;
		$entitlements_json = wp_json_encode( $entitlements );
		if ( false === $entitlements_json ) {
			$entitlements_json = '{}';
		}

		$now = current_time( 'mysql', true );
		$tables = Schema::table_names();

		$product_saved = $wpdb->replace(
			$tables['products'],
			array(
				'product_key' => $product_key,
				'display_name' => sanitize_text_field( (string) ( $data['display_name'] ?? $product->get_name() ) ),
				'active' => 1,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%s', '%s', '%d', '%s', '%s' )
		);

		if ( false === $product_saved ) {
			return false;
		}

		$result = $wpdb->replace(
			$tables['plans'],
			array(
				'product_key' => $product_key,
				'plan_key' => $plan_key,
				'woocommerce_product_id' => $product_id,
				'woocommerce_variation_id' => $variation_id,
				'billing_interval' => $interval,
				'price_minor' => max( 0, $price_minor ),
				'price_decimals' => $decimals,
				'currency' => strtoupper( sanitize_text_field( $currency ) ),
				'live_site_limit' => $limit,
				'support_level' => $support,
				'entitlements_json' => $entitlements_json,
				'active' => 1,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%s', '%s', '%d', '%d', '%s', '%d', '%d', '%s', '%d', '%s', '%s', '%d', '%s', '%s' )
		);

		return false !== $result;
	}

	/**
	 * Current regular price of one purchasable product or variation.
	 *
	 * Used as the renewal price of a license: the regular price, never a running
	 * sale price. A variable parent product has no price of its own (WooCommerce
	 * reports the lowest variation price for it), so it yields null, as do
	 * products without a positive price.
	 *
	 * @param mixed $product WooCommerce product or variation.
	 * @return array{amount_minor:int,decimals:int,currency:string}|null Amount in minor units of the shop currency.
	 */
	public static function product_regular_price( $product ): ?array {
		if ( ! is_object( $product ) || ! is_callable( array( $product, 'get_price' ) ) ) {
			return null;
		}
		if ( is_callable( array( $product, 'is_type' ) ) && $product->is_type( array( 'variable', 'grouped' ) ) ) {
			return null;
		}

		$price = is_callable( array( $product, 'get_regular_price' ) ) ? (string) $product->get_regular_price( 'edit' ) : '';
		if ( '' === $price ) {
			$price = (string) $product->get_price( 'edit' );
		}
		$decimals = function_exists( 'wc_get_price_decimals' ) ? max( 0, min( 6, (int) wc_get_price_decimals() ) ) : 2;
		$minor    = Money::to_minor_units( $price, $decimals );
		if ( false === $minor || $minor <= 0 ) {
			return null;
		}
		$currency = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : 'EUR';

		return array(
			'amount_minor' => $minor,
			'decimals' => $decimals,
			'currency' => strtoupper( sanitize_text_field( $currency ) ),
		);
	}

	/**
	 * Find the active mapping for an order item's product.
	 *
	 * @param int $product_id Parent product ID.
	 * @param int $variation_id Variation ID, or zero.
	 * @return array<string,mixed>|null
	 */
	public static function find_mapping( int $product_id, int $variation_id = 0 ): ?array {
		global $wpdb;

		$tables = Schema::table_names();
		if ( $variation_id ) {
			$sql = $wpdb->prepare(
				"SELECT * FROM {$tables['plans']} WHERE active = 1 AND woocommerce_product_id = %d AND woocommerce_variation_id = %d LIMIT 1",
				$product_id,
				$variation_id
			);
		} else {
			$sql = $wpdb->prepare(
				"SELECT * FROM {$tables['plans']} WHERE active = 1 AND woocommerce_product_id = %d AND woocommerce_variation_id = 0 LIMIT 1",
				$product_id
			);
		}

		$row = $wpdb->get_row( $sql, ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Delete one product/variation mapping.
	 *
	 * @param int $product_id Product ID.
	 * @param int $variation_id Variation ID.
	 * @return void
	 */
	public static function delete_mapping( int $product_id, int $variation_id = 0 ): void {
		global $wpdb;

		$tables = Schema::table_names();
		$wpdb->delete(
			$tables['plans'],
			array(
				'woocommerce_product_id' => $product_id,
				'woocommerce_variation_id' => $variation_id,
			),
			array( '%d', '%d' )
		);
	}
}
