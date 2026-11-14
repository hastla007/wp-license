<?php
/**
 * WooCommerce order of a renewal period.
 *
 * @package WPLC
 */

namespace WPLC\Billing;

use WPLC\Catalog\Catalog;
use WPLC\Infrastructure\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates, finds and prices the single order that belongs to a renewal row.
 *
 * Automatic and manual renewals share one order per renewal. All access goes
 * through WooCommerce CRUD so it works with HPOS and classic order storage.
 */
final class RenewalOrder {

	public const META_LICENSE = '_wplc_renewal_for_license';
	public const META_RENEWAL = '_wplc_renewal_id';
	public const META_MANUAL = '_wplc_manual_renewal';
	public const META_PAYMENT_INTENT = '_wplc_payment_intent_id';
	public const META_NET_MINOR = '_wplc_renewal_net_minor';
	public const META_DECIMALS = '_wplc_renewal_price_decimals';

	/**
	 * Return the order of a renewal, creating it when none is usable.
	 *
	 * @param array<string,mixed> $renewal Renewal row.
	 * @param array<string,mixed> $license License row.
	 * @param bool                $manual Customer-initiated renewal (any payment method, current regular price of the purchased product).
	 * @return \WC_Order|\WP_Error
	 */
	public static function get_or_create( array $renewal, array $license, bool $manual ) {
		if ( ! function_exists( 'wc_get_order' ) || ! function_exists( 'wc_create_order' ) ) {
			return new \WP_Error( 'woocommerce_unavailable', __( 'WooCommerce order services are unavailable.', 'wp-license' ) );
		}

		$order = null;
		if ( ! empty( $renewal['renewal_order_id'] ) ) {
			$linked = wc_get_order( (int) $renewal['renewal_order_id'] );
			if ( $linked && ! self::matches( $linked, $renewal, $license ) ) {
				return new \WP_Error( 'renewal_order_conflict', __( 'The saved renewal order does not match this license renewal.', 'wp-license' ) );
			}
			// A cancelled or refunded order is finished; the renewal gets a fresh one.
			if ( $linked && self::is_open( $linked ) ) {
				$order = $linked;
			}
		}

		if ( ! $order ) {
			$candidates = self::find_open_orders( (int) $renewal['id'] );
			if ( is_wp_error( $candidates ) ) {
				return $candidates;
			}
			if ( count( $candidates ) > 1 ) {
				return new \WP_Error( 'renewal_order_ambiguous', __( 'Multiple WooCommerce orders reference this license renewal; manual review is required.', 'wp-license' ) );
			}
			if ( 1 === count( $candidates ) ) {
				if ( ! self::matches( $candidates[0], $renewal, $license ) ) {
					return new \WP_Error( 'renewal_order_conflict', __( 'An existing WooCommerce order conflicts with this license renewal.', 'wp-license' ) );
				}
				$order = $candidates[0];
				if ( ! self::link( (int) $renewal['id'], (int) $order->get_id() ) ) {
					return new \WP_Error( 'renewal_order_link_failed', __( 'The existing renewal order could not be linked.', 'wp-license' ) );
				}
			}
		}

		if ( $order && ( $order->is_paid() || 'on-hold' === $order->get_status() ) ) {
			// Never touch an order that is paid or waiting for an offline payment.
			return $order;
		}

		$was_manual = $order ? 'yes' === $order->get_meta( self::META_MANUAL, true ) : false;
		$is_new = ! $order;
		$price = self::price( $license, $manual );
		$product = self::product( $license, $price['plan'] );
		if ( ! $product ) {
			return new \WP_Error( 'renewal_product_missing', __( 'The source product is no longer available for renewal.', 'wp-license' ) );
		}
		if ( $price['amount_minor'] <= 0 ) {
			return new \WP_Error( 'renewal_amount_invalid', __( 'This license has no renewal price.', 'wp-license' ) );
		}

		if ( $is_new ) {
			$order = wc_create_order( array( 'customer_id' => (int) $license['customer_id'] ) );
			if ( is_wp_error( $order ) ) {
				return $order;
			}
			$order->update_meta_data( self::META_LICENSE, (int) $license['id'] );
			$order->update_meta_data( self::META_RENEWAL, (int) $renewal['id'] );
			$order->update_meta_data( '_wplc_idempotency_key', (string) $renewal['idempotency_key'] );
			self::set_addresses( $order, $license );
		}

		// Automatic retries keep their order unchanged, so a repeated attempt sends identical parameters.
		if ( $is_new || $manual || $was_manual ) {
			$order->set_currency( $price['currency'] );
			if ( ! self::set_line( $order, $product, $price['amount_minor'], $price['decimals'] ) ) {
				return new \WP_Error( 'renewal_amount_invalid', __( 'The renewal price could not be applied to the order.', 'wp-license' ) );
			}
		}
		if ( $manual ) {
			$order->update_meta_data( self::META_MANUAL, 'yes' );
		} else {
			$order->delete_meta_data( self::META_MANUAL );
			$order->set_payment_method( (string) $license['payment_gateway_id'] );
		}
		$order->save();

		if ( $is_new && ! self::link( (int) $renewal['id'], (int) $order->get_id() ) ) {
			return new \WP_Error( 'renewal_order_link_failed', __( 'The renewal order was created but could not be linked. A retry will attempt recovery.', 'wp-license' ) );
		}

		return $order;
	}

	/**
	 * Confirm an order belongs to exactly this renewal, license, and customer.
	 *
	 * @param mixed               $order Candidate order.
	 * @param array<string,mixed> $renewal Renewal row.
	 * @param array<string,mixed> $license License row.
	 * @return bool
	 */
	public static function matches( $order, array $renewal, array $license ): bool {
		return $order instanceof \WC_Order
			&& (int) $order->get_meta( self::META_RENEWAL, true ) === (int) $renewal['id']
			&& (int) $order->get_meta( self::META_LICENSE, true ) === (int) $license['id']
			&& (int) $order->get_customer_id() === (int) $license['customer_id'];
	}

	/**
	 * Whether the order is a customer-initiated renewal.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	public static function is_manual( $order ): bool {
		return 'yes' === $order->get_meta( self::META_MANUAL, true );
	}

	/**
	 * Whether the pre-tax line total still equals the net amount stored when
	 * the order was priced. Integer minor units only, no float arithmetic.
	 *
	 * @param \WC_Order $order Renewal order.
	 * @return bool
	 */
	public static function net_matches( $order ): bool {
		$expected = (string) $order->get_meta( self::META_NET_MINOR, true );
		if ( ! ctype_digit( $expected ) ) {
			return false;
		}
		$actual = self::line_net_minor( $order, absint( $order->get_meta( self::META_DECIMALS, true ) ) );

		return false !== $actual && $actual === (int) $expected;
	}

	/**
	 * Find order IDs by one exact meta value on both order storages.
	 *
	 * `meta_key`/`meta_value` are understood by the classic (WP_Query) and the
	 * HPOS data store; `meta_query` is only guaranteed with HPOS.
	 *
	 * @param string $meta_key Meta key.
	 * @param string $meta_value Exact value.
	 * @param int    $limit Maximum number of IDs.
	 * @return int[]|\WP_Error
	 */
	public static function find_ids_by_meta( string $meta_key, string $meta_value, int $limit = 5 ) {
		if ( ! function_exists( 'wc_get_orders' ) || '' === $meta_value ) {
			return array();
		}
		$ids = wc_get_orders(
			array(
				'limit' => $limit,
				'return' => 'ids',
				'type' => 'shop_order',
				'status' => array_keys( wc_get_order_statuses() ),
				'meta_key' => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $meta_value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_compare' => '=',
			)
		);
		if ( ! is_array( $ids ) ) {
			return new \WP_Error( 'renewal_order_lookup_failed', __( 'Existing renewal orders could not be checked safely.', 'wp-license' ) );
		}

		return array_map( 'absint', $ids );
	}

	/**
	 * Orders that reference a renewal and can still be paid or are paid.
	 *
	 * @param int $renewal_id Renewal ID.
	 * @return \WC_Order[]|\WP_Error
	 */
	private static function find_open_orders( int $renewal_id ) {
		$ids = self::find_ids_by_meta( self::META_RENEWAL, (string) $renewal_id, 10 );
		if ( is_wp_error( $ids ) ) {
			return $ids;
		}
		$orders = array();
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			// Re-check the meta: the storage may ignore the meta arguments and return unrelated orders.
			if ( $order instanceof \WC_Order && (int) $order->get_meta( self::META_RENEWAL, true ) === $renewal_id && self::is_open( $order ) ) {
				$orders[] = $order;
			}
		}

		return $orders;
	}

	/**
	 * Paid, payable or awaiting an offline payment.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	private static function is_open( $order ): bool {
		return $order->is_paid() || in_array( $order->get_status(), array( 'pending', 'failed', 'on-hold' ), true );
	}

	/**
	 * Price of the renewal in minor units.
	 *
	 * Automatic renewals charge the amount stored on the license at purchase
	 * (the regular price of the purchased product or variation). Manual
	 * renewals use the current regular price of that product or variation; see
	 * choose_price() for the fallbacks.
	 *
	 * @param array<string,mixed> $license License row.
	 * @param bool                $manual Manual renewal.
	 * @return array{amount_minor:int,decimals:int,currency:string,plan:array<string,mixed>|null}
	 */
	public static function price( array $license, bool $manual ): array {
		global $wpdb;

		$tables = Schema::table_names();
		$plan = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$tables['plans']} WHERE active = 1 AND product_key = %s AND plan_key = %s ORDER BY id DESC LIMIT 1",
				(string) $license['product_key'],
				(string) $license['plan_key']
			),
			ARRAY_A
		);
		$plan = is_array( $plan ) ? $plan : null;
		$current = null;
		if ( $manual ) {
			$purchased = self::purchased_product( $license );
			$current = $purchased ? Catalog::product_regular_price( $purchased ) : null;
		}

		$price = self::choose_price( $license, $plan, $current, $manual );
		$price['plan'] = $plan;

		return $price;
	}

	/**
	 * Pick the renewal amount. Pure helper.
	 *
	 * - Automatic: the amount stored on the license. The customer agreed to
	 *   off-session charges of that amount, so a later price change in the
	 *   shop never raises it silently.
	 * - Manual: the current regular price of the purchased product or
	 *   variation, because the customer sees the price on the pay page before
	 *   paying. When that product no longer exists or has no price:
	 *   - licenses that recorded their product (renewal_product_id) use the
	 *     stored amount, which is the price of that product at purchase;
	 *   - older licenses without a recorded product use the plan price, as
	 *     before, and the stored amount when the plan has no price.
	 *
	 * @param array<string,mixed>                                       $license License row.
	 * @param array<string,mixed>|null                                  $plan Active plan of the license.
	 * @param array{amount_minor:int,decimals:int,currency:string}|null $current Current regular price of the purchased product.
	 * @param bool                                                      $manual Manual renewal.
	 * @return array{amount_minor:int,decimals:int,currency:string}
	 */
	public static function choose_price( array $license, ?array $plan, ?array $current, bool $manual ): array {
		$fallback_decimals = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;
		$stored = array(
			'amount_minor' => (int) $license['renewal_amount_minor'],
			'decimals' => max( 0, min( 6, (int) ( $license['renewal_currency_decimals'] ?? $fallback_decimals ) ) ),
			'currency' => strtoupper( (string) $license['renewal_currency'] ),
		);
		if ( ! $manual ) {
			return $stored;
		}
		if ( is_array( $current ) && (int) $current['amount_minor'] > 0 ) {
			return array(
				'amount_minor' => (int) $current['amount_minor'],
				'decimals' => max( 0, min( 6, (int) $current['decimals'] ) ),
				'currency' => strtoupper( (string) $current['currency'] ),
			);
		}
		$recorded_product = (int) ( $license['renewal_product_id'] ?? 0 ) > 0;
		if ( ! $recorded_product && $plan && (int) $plan['price_minor'] > 0 ) {
			return array(
				'amount_minor' => (int) $plan['price_minor'],
				'decimals' => max( 0, min( 6, (int) $plan['price_decimals'] ) ),
				'currency' => strtoupper( (string) $plan['currency'] ),
			);
		}

		return $stored;
	}

	/**
	 * The product or variation the license was bought as.
	 *
	 * Licenses issued since schema 13 record it in renewal_product_id; older
	 * ones (and licenses whose recorded product is gone) are resolved through
	 * their source order item.
	 *
	 * @param array<string,mixed> $license License row.
	 * @return \WC_Product|false
	 */
	private static function purchased_product( array $license ) {
		$product_id = (int) ( $license['renewal_product_id'] ?? 0 );
		if ( $product_id > 0 ) {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : false;
			// A recorded product that no longer exists is not replaced by the
			// source line: that line points to the same deleted product.
			return $product ? $product : false;
		}
		if ( empty( $license['source_order_id'] ) || ! function_exists( 'wc_get_order' ) ) {
			return false;
		}
		$source_order = wc_get_order( (int) $license['source_order_id'] );
		$source_item = $source_order ? $source_order->get_item( (int) $license['source_order_item_id'] ) : false;
		$product = $source_item && is_callable( array( $source_item, 'get_product' ) ) ? $source_item->get_product() : false;

		return $product ? $product : false;
	}

	/**
	 * Product for the order line: the purchased product, else the plan's product.
	 *
	 * @param array<string,mixed>      $license License row.
	 * @param array<string,mixed>|null $plan Plan row.
	 * @return \WC_Product|false
	 */
	private static function product( array $license, ?array $plan ) {
		$product = self::purchased_product( $license );
		if ( ! $product && $plan && function_exists( 'wc_get_product' ) ) {
			$plan_product_id = (int) $plan['woocommerce_variation_id'] ? (int) $plan['woocommerce_variation_id'] : (int) $plan['woocommerce_product_id'];
			$product = wc_get_product( $plan_product_id );
		}

		return $product ? $product : false;
	}

	/**
	 * Replace the order lines with one renewal line and recalculate totals.
	 *
	 * The amount is a catalog price: gross when the shop enters prices
	 * including tax, net otherwise. WooCommerce expects pre-tax line totals and
	 * adds tax in calculate_totals(), so the amount is converted with
	 * wc_get_price_excluding_tax(), which is a no-op for net-price shops.
	 *
	 * @param \WC_Order   $order Order.
	 * @param \WC_Product $product Product.
	 * @param int         $amount_minor Catalog price in minor units.
	 * @param int         $decimals Precision of the amount.
	 * @return bool
	 */
	private static function set_line( $order, $product, int $amount_minor, int $decimals ): bool {
		$catalog_price = Money::to_decimal( $amount_minor, $decimals );
		$net = function_exists( 'wc_get_price_excluding_tax' )
			? wc_get_price_excluding_tax( $product, array( 'qty' => 1, 'price' => $catalog_price ) )
			: $catalog_price;

		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			$order->remove_item( $item_id );
		}
		$order->add_product( $product, 1, array( 'subtotal' => $net, 'total' => $net ) );
		$order->calculate_totals();

		// Store what WooCommerce kept as the pre-tax total, in minor units, for later verification.
		$net_minor = self::line_net_minor( $order, $decimals );
		if ( false === $net_minor || $net_minor <= 0 ) {
			return false;
		}
		$order->update_meta_data( self::META_NET_MINOR, (string) $net_minor );
		$order->update_meta_data( self::META_DECIMALS, (string) $decimals );

		return true;
	}

	/**
	 * Sum of the pre-tax line totals in minor units.
	 *
	 * @param \WC_Order $order Order.
	 * @param int       $decimals Precision.
	 * @return int|false False when the order does not hold exactly one valid line.
	 */
	private static function line_net_minor( $order, int $decimals ) {
		$items = $order->get_items( 'line_item' );
		if ( 1 !== count( $items ) ) {
			return false;
		}
		$item = reset( $items );
		$total = function_exists( 'wc_format_decimal' ) ? (string) wc_format_decimal( $item->get_total() ) : (string) $item->get_total();

		return Money::to_minor_units( $total, $decimals );
	}

	/**
	 * Copy the customer's addresses to the order so the tax location resolves.
	 *
	 * @param \WC_Order           $order Order.
	 * @param array<string,mixed> $license License row.
	 * @return void
	 */
	private static function set_addresses( $order, array $license ): void {
		$billing = array();
		$shipping = array();
		if ( class_exists( '\\WC_Customer' ) ) {
			try {
				$customer = new \WC_Customer( (int) $license['customer_id'] );
				$billing = (array) $customer->get_billing();
				$shipping = (array) $customer->get_shipping();
			} catch ( \Exception $exception ) {
				$billing = array();
			}
		}
		if ( empty( $billing['country'] ) ) {
			// Fall back to the address the license was bought with.
			$source_order = wc_get_order( (int) $license['source_order_id'] );
			if ( $source_order ) {
				$billing = (array) $source_order->get_address( 'billing' );
				$shipping = (array) $source_order->get_address( 'shipping' );
			}
		}
		if ( $billing ) {
			$order->set_address( $billing, 'billing' );
		}
		if ( ! empty( $shipping['country'] ) ) {
			$order->set_address( $shipping, 'shipping' );
		}
	}

	/**
	 * Persist the order association so a retry can resume the same attempt.
	 *
	 * @param int $renewal_id Renewal ID.
	 * @param int $order_id WooCommerce order ID.
	 * @return bool
	 */
	private static function link( int $renewal_id, int $order_id ): bool {
		global $wpdb;

		$tables = Schema::table_names();
		$updated = $wpdb->update(
			$tables['renewals'],
			array( 'renewal_order_id' => $order_id, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ),
			array( 'id' => $renewal_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}
}
