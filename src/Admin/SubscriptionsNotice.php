<?php
/**
 * Warning when WooCommerce Subscriptions runs next to license products (P-20).
 *
 * @package WPLC
 */

namespace WPLC\Admin;

use WPLC\Catalog\Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce Subscriptions bills its own renewals. WP License Captain renews
 * licenses itself and does not issue licenses for Subscriptions renewal
 * orders, so a subscription product mapped to a license plan would be charged
 * by both (or renew the subscription without extending the license).
 */
final class SubscriptionsNotice {

	/**
	 * Most license products checked for a subscription product type per screen.
	 */
	private const CHECK_LIMIT = 200;

	/**
	 * Register the notice.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'admin_notices', array( self::class, 'render' ) );
	}

	/**
	 * Whether WooCommerce Subscriptions is active.
	 *
	 * @return bool
	 */
	public static function subscriptions_active(): bool {
		return class_exists( 'WC_Subscriptions' ) || function_exists( 'wcs_order_contains_renewal' );
	}

	/**
	 * License product IDs whose product is a subscription product.
	 *
	 * @param int[] $product_ids License product and variation IDs.
	 * @return int[]
	 */
	public static function subscription_products( array $product_ids ): array {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return array();
		}
		$found = array();
		foreach ( array_slice( $product_ids, 0, self::CHECK_LIMIT ) as $product_id ) {
			$product = wc_get_product( (int) $product_id );
			if ( $product instanceof \WC_Product && $product->is_type( array( 'subscription', 'variable-subscription', 'subscription_variation' ) ) ) {
				$found[] = (int) $product_id;
			}
		}

		return $found;
	}

	/**
	 * Render the warning on the plugin screens and the product screens.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! self::subscriptions_active() || ! Capabilities::can( 'manage_catalog' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id     = is_object( $screen ) && isset( $screen->id ) ? (string) $screen->id : '';
		if ( false === strpos( $id, 'wplc' ) && 'product' !== $id && 'edit-product' !== $id ) {
			return;
		}
		$license_products = Catalog::license_product_ids();
		if ( ! $license_products ) {
			return;
		}
		echo wp_kses_post( self::message( self::subscription_products( $license_products ) ) );
	}

	/**
	 * Notice HTML.
	 *
	 * @param int[] $subscription_products License products that are subscription products.
	 * @return string
	 */
	public static function message( array $subscription_products ): string {
		$text = __( 'WooCommerce Subscriptions is active. WP License Captain renews licenses itself and issues no license for Subscriptions renewal orders. Do not map subscription products to license plans: the customer would be charged by both.', 'wp-license' );
		if ( ! $subscription_products ) {
			return '<div class="notice notice-info"><p>' . esc_html( $text ) . '</p></div>';
		}
		$count = count( $subscription_products );
		$text .= ' ' . sprintf(
			/* translators: 1: number of products, 2: comma-separated product IDs. */
			_n( '%1$s license product is a subscription product (ID %2$s). Remove its license plan or change the product type.', '%1$s license products are subscription products (IDs %2$s). Remove their license plans or change the product type.', $count, 'wp-license' ),
			number_format_i18n( $count ),
			implode( ', ', array_map( 'intval', $subscription_products ) )
		);

		return '<div class="notice notice-error"><p>' . esc_html( $text ) . '</p></div>';
	}
}
