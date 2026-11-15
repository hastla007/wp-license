<?php
/**
 * Pricing table of a license product: block and shortcode.
 *
 * @package WPLC
 */

namespace WPLC\Account;

use WPLC\Catalog\Catalog;
use WPLC\Catalog\EntitlementCatalog;
use WPLC\Catalog\TermDisplay;
use WPLC\Infrastructure\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * B-117: `[wplc_pricing product="…" highlight="…" show_features="yes"]` and block `wplc/pricing`.
 *
 * Lists the active plans of one license product from the catalog with name,
 * price (WooCommerce formatting and the shop's tax display), term, live-site
 * limit, the labels of the boolean entitlements known to EntitlementCatalog
 * and a "Buy" link that adds the mapped product or variation to the cart.
 *
 * A plan is only shown when its WooCommerce product (and variation) exists,
 * is published, is not password protected and is purchasable. A plan mapped
 * on a variable parent stands for each of its published variations that has
 * no plan of its own (the same fallback as Catalog::find_mapping()).
 */
final class PricingTable {

	/**
	 * Most plans one table shows.
	 */
	const MAX_PLANS = 12;

	/**
	 * Block `wplc/pricing`.
	 *
	 * @param mixed $attributes Block attributes.
	 * @return string
	 */
	public static function render_block( $attributes = array() ): string {
		$attributes = is_array( $attributes ) ? $attributes : array();

		return self::html(
			(string) ( $attributes['product'] ?? '' ),
			(string) ( $attributes['highlight'] ?? '' ),
			self::to_bool( $attributes['showFeatures'] ?? true )
		);
	}

	/**
	 * Shortcode `[wplc_pricing product="…" highlight="…" show_features="yes"]`.
	 *
	 * @param mixed $atts Shortcode attributes.
	 * @return string
	 */
	public static function shortcode( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'product' => '',
				'highlight' => '',
				'show_features' => 'yes',
			),
			is_array( $atts ) ? $atts : array(),
			'wplc_pricing'
		);

		return self::html( (string) $atts['product'], (string) $atts['highlight'], self::to_bool( $atts['show_features'] ) );
	}

	/**
	 * Read a boolean attribute ("yes", "no", "0", "false", true …).
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	private static function to_bool( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		return ! in_array( strtolower( trim( (string) $value ) ), array( '0', 'no', 'false', 'off', '' ), true );
	}

	/**
	 * The table.
	 *
	 * @param string $product_key   License product key.
	 * @param string $highlight     Plan key to emphasise.
	 * @param bool   $show_features Whether to list the entitlements.
	 * @return string HTML; empty for an unknown or inactive product or one without a plan on sale.
	 */
	public static function html( string $product_key, string $highlight = '', bool $show_features = true ): string {
		$product_key = Catalog::normalize_key( $product_key );
		$highlight   = Catalog::normalize_key( $highlight );
		if ( '' === $product_key || ! function_exists( 'wc_get_product' ) ) {
			return '';
		}

		$plans = self::plans( $product_key );
		if ( ! $plans ) {
			return '';
		}

		CustomerPortal::enqueue_style();
		$labels = $show_features ? self::entitlement_labels() : array();

		$html = '<div class="wplc-pricing">';
		foreach ( $plans as $plan ) {
			$is_highlight = '' !== $highlight && $highlight === $plan['plan_key'];
			$html        .= '<section class="wplc-pricing__plan' . ( $is_highlight ? ' wplc-pricing__plan--highlight' : '' ) . '">';
			if ( $is_highlight ) {
				$html .= '<p class="wplc-pricing__badge">' . esc_html__( 'Recommended', 'wp-license' ) . '</p>';
			}
			$html .= '<h3 class="wplc-pricing__name">' . esc_html( $plan['name'] ) . '</h3>';
			$html .= '<p class="wplc-pricing__price">' . wp_kses_post( $plan['price_html'] ) . ' <span class="wplc-pricing__term">' . esc_html(
				sprintf(
					/* translators: %s: license term, for example "1 year". */
					__( 'for %s', 'wp-license' ),
					TermDisplay::term_label( $plan['interval'] )
				)
			) . '</span></p>';
			$html .= '<ul class="wplc-pricing__features">';
			$html .= '<li class="wplc-pricing__sites">' . esc_html(
				sprintf(
					/* translators: %d: number of live sites a license can be activated on. */
					_n( '%d live site', '%d live sites', $plan['sites'], 'wp-license' ),
					$plan['sites']
				)
			) . '</li>';
			foreach ( $plan['entitlements'] as $key ) {
				if ( isset( $labels[ $key ] ) ) {
					$html .= '<li>' . esc_html( $labels[ $key ] ) . '</li>';
				}
			}
			$html .= '</ul>';
			if ( $plan['in_stock'] ) {
				$html .= '<p class="wplc-pricing__buy"><a class="button wp-element-button" rel="nofollow" href="' . esc_url( $plan['buy_url'] ) . '">' . esc_html__( 'Buy', 'wp-license' ) . '</a></p>';
			} else {
				$html .= '<p class="wplc-pricing__buy wplc-pricing__buy--unavailable">' . esc_html__( 'Currently unavailable', 'wp-license' ) . '</p>';
			}
			$html .= '</section>';
		}

		return $html . '</div>';
	}

	/**
	 * Plans of a product that can be bought, cheapest first.
	 *
	 * @param string $product_key License product key.
	 * @return array<int,array{plan_key:string,name:string,price_html:string,price:float,interval:string,sites:int,entitlements:string[],in_stock:bool,buy_url:string}>
	 */
	public static function plans( string $product_key ): array {
		global $wpdb;

		$tables = Schema::table_names();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from Schema::table_names().
		$active = $wpdb->get_var( $wpdb->prepare( "SELECT active FROM {$tables['products']} WHERE product_key = %s", $product_key ) );
		if ( '1' !== (string) $active ) {
			return array();
		}
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tables['plans']} WHERE product_key = %s AND active = 1 ORDER BY id ASC LIMIT 200", $product_key ), ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$plans = array();
		foreach ( $rows as $row ) {
			$parent_id = (int) $row['woocommerce_product_id'];
			$parent    = $parent_id > 0 ? wc_get_product( $parent_id ) : null;
			// The parent of a variable product has no price of its own; its variations are checked below.
			if ( ! self::visible( $parent ) ) {
				continue;
			}
			$variation_id = (int) $row['woocommerce_variation_id'];
			if ( $variation_id > 0 ) {
				$products = array( wc_get_product( $variation_id ) );
			} elseif ( $parent->is_type( 'variable' ) ) {
				$products = array();
				foreach ( $parent->get_children() as $child_id ) {
					// A variation with a plan of its own appears through that plan.
					if ( null === Catalog::find_exact_mapping( $parent_id, (int) $child_id ) ) {
						$products[] = wc_get_product( $child_id );
					}
				}
			} else {
				$products = array( $parent );
			}

			foreach ( $products as $product ) {
				if ( ! self::on_sale( $product ) || ( $variation_id > 0 && (int) $product->get_parent_id() !== $parent_id ) ) {
					continue;
				}
				$plans[] = self::plan( $row, $product );
			}
		}

		usort(
			$plans,
			static function ( array $a, array $b ): int {
				return array( $a['price'], $a['plan_key'], $a['name'] ) <=> array( $b['price'], $b['plan_key'], $b['name'] );
			}
		);

		/**
		 * Filters the plans of the pricing table (block `wplc/pricing`, shortcode `[wplc_pricing]`).
		 *
		 * @since 0.2.0
		 *
		 * @param array<int,array<string,mixed>> $plans       Plans, cheapest first.
		 * @param string                         $product_key License product key.
		 */
		$filtered = apply_filters( 'wplc_pricing_table_plans', $plans, $product_key );

		return array_slice( is_array( $filtered ) ? array_values( $filtered ) : $plans, 0, self::MAX_PLANS );
	}

	/**
	 * Whether a WooCommerce product or variation may be offered.
	 *
	 * @param mixed $product Product or anything else.
	 * @return bool
	 * @phpstan-assert-if-true \WC_Product $product
	 */
	private static function on_sale( $product ): bool {
		return self::visible( $product ) && $product->is_purchasable();
	}

	/**
	 * Whether a WooCommerce product or variation is published for everyone (no password) and no subscription.
	 *
	 * @param mixed $product Product or anything else.
	 * @return bool
	 * @phpstan-assert-if-true \WC_Product $product
	 */
	private static function visible( $product ): bool {
		if ( ! $product instanceof \WC_Product || 'publish' !== $product->get_status() ) {
			return false;
		}
		$post_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();

		return '' === (string) get_post_field( 'post_password', $post_id ) && ! Catalog::is_subscription_product( $product );
	}

	/**
	 * One plan column.
	 *
	 * @param array<string,mixed> $row     Plan row.
	 * @param \WC_Product         $product Product or variation that is bought.
	 * @return array{plan_key:string,name:string,price_html:string,price:float,interval:string,sites:int,entitlements:string[],in_stock:bool,buy_url:string}
	 */
	private static function plan( array $row, \WC_Product $product ): array {
		$price   = (float) wc_get_price_to_display( $product );
		$regular = (float) wc_get_price_to_display( $product, array( 'price' => $product->get_regular_price() ) );
		$html    = $product->is_on_sale() && $regular > $price ? wc_format_sale_price( $regular, $price ) : wc_price( $price );
		$name    = $product->get_name();
		if ( $product->is_type( 'variation' ) && is_callable( array( $product, 'get_attribute_summary' ) ) && '' !== (string) $product->get_attribute_summary() ) {
			$name = wc_get_formatted_variation( $product, true, false, false );
		}

		$entitlements = json_decode( (string) ( $row['entitlements_json'] ?? '' ), true );
		$granted      = array();
		foreach ( is_array( $entitlements ) ? $entitlements : array() as $key => $value ) {
			if ( true === $value ) {
				$granted[] = (string) $key;
			}
		}

		return array(
			'plan_key' => (string) $row['plan_key'],
			'name' => wp_strip_all_tags( (string) $name ),
			'price_html' => $html . $product->get_price_suffix(),
			'price' => $price,
			'interval' => (string) $row['billing_interval'],
			'sites' => max( 1, (int) $row['live_site_limit'] ),
			'entitlements' => $granted,
			'in_stock' => $product->is_in_stock(),
			'buy_url' => (string) $product->add_to_cart_url(),
		);
	}

	/**
	 * Labels of the known boolean entitlements.
	 *
	 * @return array<string,string> Key => label.
	 */
	private static function entitlement_labels(): array {
		$labels = array();
		foreach ( EntitlementCatalog::all() as $entitlements ) {
			foreach ( $entitlements as $entitlement ) {
				$labels[ $entitlement['key'] ] = $entitlement['label'];
			}
		}

		return $labels;
	}
}
