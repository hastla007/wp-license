<?php
/**
 * Largest quantity of a license product in one order line.
 *
 * @package WPLC
 */

namespace WPLC\Catalog;

use WPLC\Admin\Settings;
use WPLC\License\AuditLog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every unit of a license product becomes a license of its own, with its own key, renewal and
 * emails. A very large quantity in one order line means that many of each in one request. The
 * shop sets the largest quantity (setting "Largest quantity per order line", default 100,
 * 0 = no limit).
 *
 * Order of precedence: the filter `wplc_max_license_quantity_per_line` decides last, so code
 * of the shop wins over the setting (it also gets the product, for a limit per product).
 *
 * Where the limit is enforced, always on the server:
 * - the quantity field of the product page and the cart (classic) and the quantity limits of
 *   the Store API (block cart and checkout) carry the maximum;
 * - adding to the cart is refused when the cart would hold more (classic);
 * - cart and checkout are checked again (`woocommerce_check_cart_items`, which the classic
 *   checkout and the Store API both run), so an order above the limit cannot be placed.
 *
 * An order the shop owner creates in the admin or through the REST API is never blocked:
 * the licenses are issued, and the order gets a note and an audit entry.
 */
final class QuantityLimit {

	/**
	 * Default of the setting.
	 */
	public const DEFAULT_MAX = 100;

	/**
	 * Largest value the setting accepts.
	 */
	public const MAX_SETTING = 100000;

	/**
	 * Order meta: the note about a quantity above the limit was written.
	 */
	public const META_NOTED = '_wplc_quantity_limit_noted';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_filter( 'woocommerce_quantity_input_args', array( self::class, 'filter_input_args' ), 20, 2 );
		add_filter( 'woocommerce_store_api_product_quantity_maximum', array( self::class, 'filter_store_api_maximum' ), 20, 2 );
		add_filter( 'woocommerce_add_to_cart_validation', array( self::class, 'validate_add_to_cart' ), 20, 4 );
		add_action( 'woocommerce_check_cart_items', array( self::class, 'check_cart' ) );
	}

	/**
	 * Largest quantity of a license product in one order line; 0 = no limit.
	 *
	 * @param \WC_Product|null $product Product or variation, when known.
	 * @return int
	 */
	public static function max( $product = null ): int {
		$max = (int) Settings::get( 'max_quantity_per_line' );
		/**
		 * Filters the largest quantity of a license product in one order line. Wins over the setting.
		 *
		 * @since 0.2.0
		 *
		 * @param int              $max     Largest quantity; 0 = no limit. Default: the setting (100).
		 * @param \WC_Product|null $product Product or variation the quantity is for, null when not known.
		 */
		$max = (int) apply_filters( 'wplc_max_license_quantity_per_line', $max, $product instanceof \WC_Product ? $product : null );

		return max( 0, $max );
	}

	/**
	 * Limit of a product: 0 when it is no license product or no limit applies.
	 *
	 * @param mixed $product Product or variation.
	 * @return int
	 */
	public static function limit_for( $product ): int {
		if ( ! $product instanceof \WC_Product ) {
			return 0;
		}
		$variation_id = $product->is_type( 'variation' ) ? (int) $product->get_id() : 0;
		$product_id   = $variation_id ? (int) $product->get_parent_id() : (int) $product->get_id();

		return Catalog::is_license_item( $product_id, $variation_id ) ? self::max( $product ) : 0;
	}

	/**
	 * Message for a quantity above the limit.
	 *
	 * @param string $name Product name.
	 * @param int    $max  Largest quantity.
	 * @return string
	 */
	public static function message( string $name, int $max ): string {
		return sprintf(
			/* translators: 1: largest quantity, 2: product name. */
			_n(
				'You can order at most %1$d unit of "%2$s" in one order. Please reduce the quantity.',
				'You can order at most %1$d units of "%2$s" in one order. Please reduce the quantity.',
				$max,
				'wp-license'
			),
			$max,
			$name
		);
	}

	/**
	 * Filter `woocommerce_quantity_input_args`: the quantity field does not offer more.
	 *
	 * @param mixed $args    Arguments of the quantity field.
	 * @param mixed $product Product.
	 * @return mixed
	 */
	public static function filter_input_args( $args, $product = null ) {
		$limit = self::limit_for( $product );
		if ( $limit < 1 || ! is_array( $args ) ) {
			return $args;
		}
		$current = isset( $args['max_value'] ) && is_numeric( $args['max_value'] ) ? (int) $args['max_value'] : -1;
		if ( $current < 1 || $current > $limit ) {
			$args['max_value'] = $limit;
		}

		return $args;
	}

	/**
	 * Filter `woocommerce_store_api_product_quantity_maximum`: block cart and checkout.
	 *
	 * @param mixed $value   Maximum WooCommerce found.
	 * @param mixed $product Product.
	 * @return mixed
	 */
	public static function filter_store_api_maximum( $value, $product = null ) {
		$limit = self::limit_for( $product );
		if ( $limit < 1 ) {
			return $value;
		}

		return is_numeric( $value ) && (int) $value > 0 ? min( (int) $value, $limit ) : $limit;
	}

	/**
	 * Filter `woocommerce_add_to_cart_validation`: refuse what would put more than the limit into the cart.
	 *
	 * @param mixed $passed       Result so far.
	 * @param mixed $product_id   Product ID.
	 * @param mixed $quantity     Quantity to add.
	 * @param mixed $variation_id Variation ID.
	 * @return mixed
	 */
	public static function validate_add_to_cart( $passed, $product_id = 0, $quantity = 1, $variation_id = 0 ) {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( absint( $variation_id ) ? absint( $variation_id ) : absint( $product_id ) ) : null;
		$limit   = self::limit_for( $product );
		if ( ! $passed || $limit < 1 ) {
			return $passed;
		}
		$in_cart = 0;
		$cart    = self::cart();
		if ( $cart ) {
			foreach ( $cart->get_cart() as $item ) {
				if ( (int) ( $item['product_id'] ?? 0 ) === (int) $product_id && (int) ( $item['variation_id'] ?? 0 ) === (int) $variation_id ) {
					$in_cart += (int) ( $item['quantity'] ?? 0 );
				}
			}
		}
		if ( $in_cart + max( 0, (int) $quantity ) > $limit ) {
			wc_add_notice( self::message( (string) $product->get_name(), $limit ), 'error' );
			return false;
		}

		return $passed;
	}

	/**
	 * Action `woocommerce_check_cart_items`: an order above the limit cannot be placed
	 * (classic cart and checkout, and the Store API, which turns the notice into an error).
	 *
	 * @return void
	 */
	public static function check_cart(): void {
		$cart = self::cart();
		if ( ! $cart ) {
			return;
		}
		foreach ( $cart->get_cart() as $item ) {
			$product = $item['data'] ?? null;
			$limit   = self::limit_for( $product );
			if ( $limit > 0 && (int) ( $item['quantity'] ?? 0 ) > $limit ) {
				wc_add_notice( self::message( (string) $product->get_name(), $limit ), 'error' );
			}
		}
	}

	/**
	 * The cart of this request; null in the admin, in cron and in the REST API of WooCommerce.
	 *
	 * @return \WC_Cart|null
	 */
	private static function cart() {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}
		/** @var \WC_Cart|null $cart */
		$cart = WC()->cart;

		return $cart;
	}

	/**
	 * Note an order whose license lines are above the limit (created in the admin or through the
	 * REST API, or placed before the limit was lowered). Nothing is blocked. Once per order.
	 *
	 * @param mixed $order Order (WC_Order); anything else is ignored.
	 * @return bool Whether a note was written now.
	 */
	public static function note_order( $order ): bool {
		if ( ! $order instanceof \WC_Order || '' !== (string) $order->get_meta( self::META_NOTED, true ) ) {
			return false;
		}
		$above = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$limit = self::limit_for( $item->get_product() );
			if ( $limit > 0 && (int) $item->get_quantity() > $limit ) {
				$above[] = array(
					'name'     => (string) $item->get_name(),
					'quantity' => (int) $item->get_quantity(),
					'limit'    => $limit,
				);
			}
		}
		if ( ! $above ) {
			return false;
		}
		$order->update_meta_data( self::META_NOTED, gmdate( 'Y-m-d H:i:s' ) );
		$order->save_meta_data();
		foreach ( $above as $line ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: product name, 2: ordered quantity, 3: largest quantity customers can order. */
					__( 'WP License Captain: "%1$s" was ordered %2$d times; customers can order at most %3$d in one order (License settings). The order was not created through the checkout, so nothing was blocked and every unit gets its license.', 'wp-license' ),
					$line['name'],
					$line['quantity'],
					$line['limit']
				)
			);
		}
		AuditLog::record( 'order_quantity_above_limit', 'order', (int) $order->get_id(), 'quantity_above_limit', null, array( 'lines' => $above, 'created_via' => (string) $order->get_created_via() ), get_current_user_id() );

		return true;
	}
}
