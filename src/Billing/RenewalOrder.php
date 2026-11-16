<?php
/**
 * WooCommerce order of a renewal period.
 *
 * @package WPLC
 */

namespace WPLC\Billing;

use WPLC\Catalog\Catalog;
use WPLC\Infrastructure\Database\Schema;
use WPLC\License\AuditLog;
use WPLC\License\AuditReason;
use WPLC\License\PlanChange;

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
	/** User ID of the administrator who changed the renewal amount on the order (B-182). */
	public const META_AMOUNT_OVERRIDE = '_wplc_renewal_amount_override';
	/** Set while the order total does not match the agreed renewal amount (P-46); such an order is priced again, never charged. */
	public const META_AMOUNT_MISMATCH = '_wplc_renewal_amount_mismatch';
	/** Set once a paid order could not be applied and was reported for manual review. */
	public const META_REVIEW = '_wplc_renewal_review';
	/** Value of WC_Order::get_created_via() for every renewal order. */
	public const CREATED_VIA = 'wplc_renewal';

	/** Seconds during which an order the customer is paying is not taken over by an automatic charge (B-184). */
	public const CUSTOMER_PAYMENT_QUIET_SECONDS = 900;

	/**
	 * Register order related filters. Called from RenewalProcessor::register_hooks().
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		// B-187: a renewal sells time, not a stock unit.
		add_filter( 'woocommerce_can_reduce_order_stock', array( self::class, 'filter_can_reduce_stock' ), 10, 2 );
		add_filter( 'woocommerce_pay_order_product_in_stock', array( self::class, 'filter_pay_order_in_stock' ), 10, 3 );
		// B-182: an amount changed by an administrator on the order screen is the intended amount.
		add_action( 'woocommerce_saved_order_items', array( self::class, 'on_saved_order_items' ), 20, 1 );
	}

	/**
	 * Whether an order is a renewal order of this plugin.
	 *
	 * @param mixed $order Candidate.
	 * @return bool
	 */
	public static function is_renewal_order( $order ): bool {
		return $order instanceof \WC_Order && absint( $order->get_meta( self::META_RENEWAL, true ) ) > 0;
	}

	/**
	 * Renewal orders never reduce stock.
	 *
	 * @param mixed $can Current decision.
	 * @param mixed $order Order.
	 * @return mixed
	 */
	public static function filter_can_reduce_stock( $can, $order = null ) {
		return self::is_renewal_order( $order ) ? false : $can;
	}

	/**
	 * A renewal order stays payable when the product is out of stock.
	 *
	 * @param mixed $in_stock Current decision.
	 * @param mixed $product Product.
	 * @param mixed $order Order.
	 * @return mixed
	 */
	public static function filter_pay_order_in_stock( $in_stock, $product = null, $order = null ) {
		return self::is_renewal_order( $order ) ? true : $in_stock;
	}

	/**
	 * Accept a renewal amount an administrator changed on the order screen (B-182).
	 *
	 * The stored net amount is what both paid-order paths verify. Without this an
	 * order discounted by support would be paid and never booked.
	 *
	 * @param mixed $order_id Order ID.
	 * @return void
	 */
	public static function on_saved_order_items( $order_id ): void {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( absint( $order_id ) ) : false;
		if ( ! self::is_renewal_order( $order ) || $order->is_paid() ) {
			return;
		}
		/**
		 * Filters whether the current user may change the amount of a renewal order.
		 *
		 * @param bool      $allowed Default: users who can manage WooCommerce.
		 * @param \WC_Order $order Renewal order.
		 */
		if ( ! apply_filters( 'wplc_can_override_renewal_amount', current_user_can( 'manage_woocommerce' ), $order ) ) {
			return;
		}
		$decimals = absint( $order->get_meta( self::META_DECIMALS, true ) );
		$actual = self::line_net_minor( $order, $decimals );
		$stored = (string) $order->get_meta( self::META_NET_MINOR, true );
		if ( false === $actual || $actual <= 0 || ! ctype_digit( $stored ) || (int) $stored === $actual ) {
			return;
		}
		$refusal = self::amount_override_refusal( $order, (int) $stored, $actual );
		if ( '' !== $refusal ) {
			// P-60: the stored amount stays, so the changed order fails the amount check and is not charged.
			$order->add_order_note( 'WP License Captain: ' . $refusal );
			AuditLog::record(
				'renewal_amount_override_refused',
				'renewal',
				absint( $order->get_meta( self::META_RENEWAL, true ) ),
				$refusal,
				array( 'net_minor' => (int) $stored ),
				array( 'net_minor' => $actual, 'order_id' => (int) $order->get_id() ),
				get_current_user_id()
			);
			return;
		}
		$order->update_meta_data( self::META_NET_MINOR, (string) $actual );
		$order->update_meta_data( self::META_AMOUNT_OVERRIDE, (string) get_current_user_id() );
		$order->save();
		$order->add_order_note(
			sprintf(
				/* translators: 1: old net amount, 2: new net amount. */
				__( 'WP License Captain: the renewal amount was changed on this order (net %1$s to %2$s). A payment of the new amount renews the license.', 'wp-license' ),
				Money::to_decimal( (int) $stored, $decimals ),
				Money::to_decimal( $actual, $decimals )
			)
		);
		AuditLog::record(
			'renewal_amount_overridden',
			'renewal',
			absint( $order->get_meta( self::META_RENEWAL, true ) ),
			__( 'An administrator changed the amount of the renewal order', 'wp-license' ),
			array( 'net_minor' => (int) $stored ),
			array( 'net_minor' => $actual, 'order_id' => (int) $order->get_id() ),
			get_current_user_id()
		);
	}

	/**
	 * Why an amount changed on the order screen is not accepted; empty when it is (P-60).
	 *
	 * - While a payment of the order is being taken or checked (processing,
	 *   unknown result, review) the amount stays as it was charged.
	 * - An automatic renewal is charged off-session with the consent and the
	 *   advance notice of the agreed amount: it may be lowered, never raised.
	 *
	 * @param \WC_Order $order Renewal order.
	 * @param int       $stored Stored net amount (minor units).
	 * @param int       $actual Net amount now on the order (minor units).
	 * @return string
	 */
	private static function amount_override_refusal( $order, int $stored, int $actual ): string {
		global $wpdb;

		$tables = Schema::table_names();
		$status = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE id = %d LIMIT 1', $tables['renewals'], absint( $order->get_meta( self::META_RENEWAL, true ) ) ) );
		if ( in_array( $status, array( 'processing', RenewalProcessor::STATUS_RECONCILING, RenewalProcessor::STATUS_REVIEW ), true ) ) {
			return __( 'The renewal amount cannot be changed while a payment of this order is being taken or checked. The change was not accepted; the order is not charged until its amount matches the agreed amount again.', 'wp-license' );
		}
		if ( ! self::is_manual( $order ) && $actual > $stored ) {
			return __( 'The amount of an automatic renewal can only be lowered: a higher amount would be charged without the customer\'s consent. The change was not accepted; the order is not charged until its amount matches the agreed amount again.', 'wp-license' );
		}

		return '';
	}

	/**
	 * Return the order of a renewal, creating it when none is usable.
	 *
	 * @param array<string,mixed> $renewal Renewal row.
	 * @param array<string,mixed> $license License row.
	 * @param bool                $manual Customer-initiated renewal (any payment method; same price as the automatic one).
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
		if ( $order && ! $manual && $was_manual && self::recently_touched( $order ) ) {
			// B-184: the customer opened this order a moment ago and may be paying it right now.
			return new \WP_Error( 'renewal_order_in_use', __( 'The customer is paying this renewal order at the moment.', 'wp-license' ) );
		}

		$is_new = ! $order;
		$price = self::price( $license, $manual );
		// A downgrade scheduled for the next renewal (T-110) is billed with its own product.
		$product = $price['pending_product'] ? $price['pending_product'] : self::product( $license, $price['plan'] );
		if ( ! $product ) {
			return new \WP_Error( 'renewal_product_missing', __( 'The source product is no longer available for renewal.', 'wp-license' ) );
		}
		if ( $price['amount_minor'] <= 0 ) {
			return new \WP_Error( 'renewal_amount_invalid', __( 'This license has no renewal price.', 'wp-license' ) );
		}

		if ( $is_new ) {
			$order = wc_create_order(
				array(
					'customer_id' => (int) $license['customer_id'],
					'created_via' => self::CREATED_VIA,
				)
			);
			if ( is_wp_error( $order ) ) {
				return $order;
			}
			$order->update_meta_data( self::META_LICENSE, (int) $license['id'] );
			$order->update_meta_data( self::META_RENEWAL, (int) $renewal['id'] );
			$order->update_meta_data( '_wplc_idempotency_key', (string) $renewal['idempotency_key'] );
		}

		// Automatic retries keep their order unchanged, so a repeated attempt sends identical parameters.
		// An amount set by an administrator on the order screen is kept as well (B-182).
		$overridden = '' !== (string) $order->get_meta( self::META_AMOUNT_OVERRIDE, true );
		$mismatch   = 'yes' === $order->get_meta( self::META_AMOUNT_MISMATCH, true );
		if ( $is_new || $mismatch || ( ( $manual || $was_manual ) && ! $overridden ) ) {
			// The tax location follows the customer's current billing address (B-129).
			self::set_addresses( $order, $license );
			$order->set_currency( $price['currency'] );
			if ( ! self::set_line( $order, $product, $price, $renewal, $license ) ) {
				return new \WP_Error( 'renewal_amount_invalid', __( 'The renewal price could not be applied to the order.', 'wp-license' ) );
			}
			if ( ! self::gross_matches( $order, $price ) ) {
				// B-76: the stored gross amount no longer produces the same total (tax setting changed).
				// P-46: the order is kept and priced again on the next run instead of a new order every
				// time; it is marked so that it is never charged before its total matches again.
				if ( ! $mismatch ) {
					$order->update_meta_data( self::META_AMOUNT_MISMATCH, 'yes' );
					$order->add_order_note( __( 'WP License Captain: the order total did not match the agreed renewal amount. The order is not charged; it is priced again on the next attempt. Check the shop tax settings.', 'wp-license' ) );
				}
				$order->save();
				if ( $is_new && ! self::link( (int) $renewal['id'], (int) $order->get_id() ) ) {
					return new \WP_Error( 'renewal_order_link_failed', __( 'The renewal order was created but could not be linked. A retry will attempt recovery.', 'wp-license' ) );
				}
				return new \WP_Error( 'renewal_amount_mismatch', __( 'The renewal order total does not match the agreed renewal amount. Check the shop tax settings.', 'wp-license' ) );
			}
			if ( $mismatch ) {
				$order->delete_meta_data( self::META_AMOUNT_MISMATCH );
			}
		}
		if ( $manual ) {
			$order->update_meta_data( self::META_MANUAL, 'yes' );
		} else {
			$order->delete_meta_data( self::META_MANUAL );
			self::set_payment_method( $order, (string) $license['payment_gateway_id'] );
		}
		$order->save();

		if ( $is_new ) {
			// B-187: the downloads were granted with the first purchase.
			$data_store = $order->get_data_store();
			if ( is_callable( array( $data_store, 'set_download_permissions_granted' ) ) ) {
				$data_store->set_download_permissions_granted( $order, true );
			}
			if ( ! self::link( (int) $renewal['id'], (int) $order->get_id() ) ) {
				return new \WP_Error( 'renewal_order_link_failed', __( 'The renewal order was created but could not be linked. A retry will attempt recovery.', 'wp-license' ) );
			}
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
	 * Whether the order has the billing country taxes are calculated from (B-66).
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	public static function has_tax_location( $order ): bool {
		return '' !== (string) $order->get_billing_country();
	}

	/**
	 * Report a paid order that could not renew its license, once per order.
	 *
	 * Writes an order note and an audit entry and fires `wplc/renewal_needs_review`,
	 * so a payment that was accepted but not booked never stays invisible.
	 *
	 * @param mixed     $order Order (WC_Order); anything else is ignored, callers pass the result of wc_get_order().
	 * @param int       $renewal_id Renewal ID, zero when unknown.
	 * @param string    $problem Reason for the audit log.
	 * @param string    $audit_action Audit action.
	 * @return void
	 */
	public static function flag_for_review( $order, int $renewal_id, string $problem, string $audit_action = 'renewal_payment_not_applied' ): void {
		if ( ! $order instanceof \WC_Order || 'yes' === $order->get_meta( self::META_REVIEW, true ) ) {
			return;
		}
		$order->update_meta_data( self::META_REVIEW, 'yes' );
		$order->save();
		$order->add_order_note( __( 'WP License Captain: this renewal payment could not be applied to the license. Please review the license manually.', 'wp-license' ) . ' ' . AuditReason::label( $problem ) );
		AuditLog::record( $audit_action, $renewal_id ? 'renewal' : 'order', $renewal_id ? $renewal_id : (int) $order->get_id(), $problem, null, array( 'order_id' => (int) $order->get_id() ) );
		/**
		 * Fires when a paid renewal order could not be applied to its license.
		 *
		 * @param int    $order_id Order ID.
		 * @param int    $renewal_id Renewal ID, zero when unknown.
		 * @param string $problem Reason.
		 */
		do_action( 'wplc/renewal_needs_review', (int) $order->get_id(), $renewal_id, $problem );
	}

	/**
	 * Close the unpaid order of a renewal that will not be charged any more.
	 *
	 * Pending and failed orders are cancelled so they cannot be paid without
	 * effect (A-12, B-46). An order waiting for an offline payment keeps its
	 * status and gets a note; a paid order is never touched.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $note Order note.
	 * @return bool True when the order was cancelled.
	 */
	public static function close_unpaid( int $order_id, string $note ): bool {
		$order = $order_id && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		if ( ! self::is_renewal_order( $order ) || $order->is_paid() ) {
			return false;
		}
		if ( in_array( $order->get_status(), array( 'pending', 'failed' ), true ) ) {
			$order->update_status( 'cancelled', $note );
			return true;
		}
		if ( 'on-hold' === $order->get_status() ) {
			$order->add_order_note( $note . ' ' . __( 'The order is waiting for a payment; review the license before accepting it.', 'wp-license' ) );
		}

		return false;
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
	 * Whether the order was changed within the quiet period.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	private static function recently_touched( $order ): bool {
		$modified = $order->get_date_modified();

		return $modified && $modified->getTimestamp() > time() - self::CUSTOMER_PAYMENT_QUIET_SECONDS;
	}

	/**
	 * The catalog plan a license belongs to (A-03).
	 *
	 * The pair product key / plan key is not unique: a yearly and a monthly
	 * product may share it. The plan is therefore looked up through the product
	 * the license was bought with (unique mapping per product and variation,
	 * stable across catalog saves). Only licenses without a source order fall
	 * back to the key pair, narrowed by the billing interval.
	 *
	 * The returned row carries `_ambiguous => true` when several active plans
	 * with different prices match and none could be preferred.
	 *
	 * @param array<string,mixed> $license License row.
	 * @return array<string,mixed>|null
	 */
	public static function plan_for_license( array $license ): ?array {
		global $wpdb;

		$tables = Schema::table_names();
		$product_key = (string) ( $license['product_key'] ?? '' );
		$plan_key = (string) ( $license['plan_key'] ?? '' );

		$ids = self::source_product_ids( $license );
		if ( $ids['product_id'] ) {
			$variations = $ids['variation_id'] ? array( $ids['variation_id'], 0 ) : array( 0 );
			foreach ( $variations as $variation_id ) {
				$plan = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT * FROM %i WHERE active = 1 AND woocommerce_product_id = %d AND woocommerce_variation_id = %d AND product_key = %s LIMIT 1",
						$tables['plans'],
						$ids['product_id'],
						$variation_id,
						$product_key
					),
					ARRAY_A
				);
				if ( is_array( $plan ) ) {
					return $plan;
				}
			}
		}

		$plans = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE active = 1 AND product_key = %s AND plan_key = %s ORDER BY id DESC",
				$tables['plans'],
				$product_key,
				$plan_key
			),
			ARRAY_A
		);
		if ( ! $plans ) {
			return null;
		}
		$same_interval = array();
		foreach ( $plans as $plan ) {
			if ( (string) $plan['billing_interval'] === (string) ( $license['billing_interval'] ?? '' ) ) {
				$same_interval[] = $plan;
			}
		}
		$candidates = $same_interval ? $same_interval : $plans;
		$prices = array();
		foreach ( $candidates as $plan ) {
			$prices[ $plan['price_minor'] . '|' . $plan['currency'] . '|' . $plan['billing_interval'] ] = true;
		}
		$plan = $candidates[0];
		if ( count( $prices ) > 1 ) {
			$plan['_ambiguous'] = true;
		}

		return $plan;
	}

	/**
	 * Product and variation the license was bought with.
	 *
	 * @param array<string,mixed> $license License row.
	 * @return array{product_id:int,variation_id:int}
	 */
	private static function source_product_ids( array $license ): array {
		$ids = array( 'product_id' => 0, 'variation_id' => 0 );
		if ( empty( $license['source_order_id'] ) || empty( $license['source_order_item_id'] ) || ! function_exists( 'wc_get_order' ) ) {
			return $ids;
		}
		$source_order = wc_get_order( (int) $license['source_order_id'] );
		$item = $source_order ? $source_order->get_item( (int) $license['source_order_item_id'] ) : false;
		if ( $item && is_callable( array( $item, 'get_product_id' ) ) ) {
			$ids['product_id'] = (int) $item->get_product_id();
			$ids['variation_id'] = is_callable( array( $item, 'get_variation_id' ) ) ? (int) $item->get_variation_id() : 0;
		}

		return $ids;
	}

	/**
	 * Price of the renewal in minor units.
	 *
	 * Price guarantee (owner decision A4 of 16 Nov 2026, INH-01): automatic
	 * and manual renewals both bill the amount stored on the license at
	 * purchase (the regular price of the purchased product or variation on
	 * that day). A later price change in the shop reaches new purchases only.
	 * A license without a stored amount is priced from the catalog when the
	 * customer renews manually, see choose_price(). A plan change scheduled
	 * for the next renewal (T-110) replaces the price, see
	 * choose_pending_price(). The shop changes the agreed amount of one
	 * license with the support action "Change renewal amount"
	 * (docs/SUPPORT-ACTIONS.md).
	 *
	 * `includes_tax` tells how the amount is to be read (B-76): true = gross,
	 * false = net, null = unknown, i.e. by the shop's current price setting.
	 *
	 * @param array<string,mixed> $license License row.
	 * @param bool                $manual Manual renewal.
	 * @return array{amount_minor:int,decimals:int,currency:string,plan:array<string,mixed>|null,pending_product:\WC_Product|null,includes_tax:bool|null}
	 */
	public static function price( array $license, bool $manual ): array {
		$plan = self::plan_for_license( $license );
		$flag = $license['renewal_amount_includes_tax'] ?? null;
		$flag = null === $flag || '' === $flag ? null : (bool) (int) $flag;
		// An ambiguous plan (several prices for one key pair) never sets the price.
		$priced_plan = $plan && empty( $plan['_ambiguous'] ) ? $plan : null;
		$stored = (int) ( $license['renewal_amount_minor'] ?? 0 ) > 0;
		$current = null;
		if ( $manual && ! $stored ) {
			$purchased = self::purchased_product( $license );
			$current = $purchased ? Catalog::product_regular_price( $purchased ) : null;
		}

		$price = self::choose_price( $license, $priced_plan, $current, $manual );
		// The stored amount is read the way it was entered at purchase; a catalog price the way the shop enters prices today.
		$includes_tax = $stored || ! $manual ? $flag : null;
		$pending_product = null;
		$pending = PlanChange::pending_target( $license );
		if ( $pending ) {
			$with_pending = self::choose_pending_price( $price, $pending['price'], $manual );
			if ( null !== $with_pending ) {
				if ( $manual || $with_pending['amount_minor'] !== $price['amount_minor'] ) {
					$includes_tax = null;
				}
				$price = $with_pending;
				$pending_product = $pending['product'];
			}
		}
		$price['plan'] = $plan;
		$price['pending_product'] = $pending_product;
		$price['includes_tax'] = $includes_tax;

		return $price;
	}

	/**
	 * Price of a renewal that switches to a pending plan (T-110). Pure helper.
	 *
	 * A change to another plan is a new agreement, so the price guarantee of
	 * the current plan (INH-01) does not apply to it:
	 * - Manual: the current regular price of the pending plan's product; the
	 *   customer sees it on the pay page.
	 * - Automatic: that price, but never more than the stored amount: the
	 *   customer agreed to off-session charges of the stored amount only.
	 *
	 * @param array{amount_minor:int,decimals:int,currency:string}      $base Price without the plan change.
	 * @param array{amount_minor:int,decimals:int,currency:string}|null $pending Current regular price of the pending product.
	 * @param bool                                                      $manual Manual renewal.
	 * @return array{amount_minor:int,decimals:int,currency:string}|null Null when the pending plan cannot be billed (no price, other currency or precision): the renewal keeps the current plan.
	 */
	public static function choose_pending_price( array $base, ?array $pending, bool $manual ): ?array {
		if (
			null === $pending
			|| (int) $pending['amount_minor'] <= 0
			|| strtoupper( (string) $pending['currency'] ) !== strtoupper( (string) $base['currency'] )
			|| (int) $pending['decimals'] !== (int) $base['decimals']
		) {
			return null;
		}
		$amount = (int) $pending['amount_minor'];
		if ( ! $manual && (int) $base['amount_minor'] > 0 ) {
			$amount = min( $amount, (int) $base['amount_minor'] );
		}

		return array(
			'amount_minor' => $amount,
			'decimals' => (int) $base['decimals'],
			'currency' => strtoupper( (string) $base['currency'] ),
		);
	}

	/**
	 * Pick the renewal amount. Pure helper.
	 *
	 * - A stored amount above zero is the price of every renewal, automatic
	 *   or manual: the amount agreed at purchase (price guarantee, INH-01).
	 *   A later price change in the shop never changes it.
	 * - Automatic renewal without a stored amount: zero (nothing is charged;
	 *   the caller reports "no renewal price").
	 * - Manual renewal without a stored amount (a license issued without a
	 *   price): the current regular price of the purchased product or
	 *   variation; when that product no longer exists or has no price, the
	 *   plan price for licenses that did not record their product.
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
		if ( ! $manual || $stored['amount_minor'] > 0 ) {
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
	public static function purchased_product( array $license ) {
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
	 * Pre-tax unit price for an amount in minor units.
	 *
	 * @param \WC_Product $product Product.
	 * @param string      $catalog_price Decimal amount.
	 * @param bool|null   $includes_tax True gross, false net, null by the shop's current setting.
	 * @return float|string
	 */
	private static function net_price( $product, string $catalog_price, ?bool $includes_tax ) {
		if ( false === $includes_tax ) {
			return $catalog_price;
		}
		if ( true === $includes_tax ) {
			if ( ! function_exists( 'wc_tax_enabled' ) || ! wc_tax_enabled() || ! $product->is_taxable() || ! class_exists( '\\WC_Tax' ) ) {
				return $catalog_price;
			}
			$rates = \WC_Tax::get_base_tax_rates( $product->get_tax_class( 'unfiltered' ) );
			$taxes = \WC_Tax::calc_tax( (float) $catalog_price, $rates, true );

			return (float) $catalog_price - (float) array_sum( $taxes );
		}

		// Unknown: gross when the shop enters prices including tax, net otherwise.
		return function_exists( 'wc_get_price_excluding_tax' )
			? wc_get_price_excluding_tax( $product, array( 'qty' => 1, 'price' => $catalog_price ) )
			: $catalog_price;
	}

	/**
	 * Replace the order lines with one line of a product at a given amount (plan upgrades, T-110).
	 *
	 * The amount is read the way the shop enters prices today (gross or net).
	 *
	 * @param \WC_Order   $order Order.
	 * @param \WC_Product $product Product.
	 * @param int         $amount_minor Amount in minor units.
	 * @param int         $decimals Precision of the amount.
	 * @return bool
	 */
	public static function set_plain_line( $order, $product, int $amount_minor, int $decimals ): bool {
		$catalog_price = Money::to_decimal( $amount_minor, $decimals );
		$net = self::net_price( $product, $catalog_price, null );

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
	 * Replace the order lines with one renewal line and recalculate totals.
	 *
	 * WooCommerce expects pre-tax line totals and adds tax in calculate_totals().
	 *
	 * @param \WC_Order           $order Order.
	 * @param \WC_Product         $product Product.
	 * @param array<string,mixed> $price Result of price().
	 * @param array<string,mixed> $renewal Renewal row.
	 * @param array<string,mixed> $license License row.
	 * @return bool
	 */
	private static function set_line( $order, $product, array $price, array $renewal, array $license ): bool {
		$decimals = (int) $price['decimals'];
		$catalog_price = Money::to_decimal( (int) $price['amount_minor'], $decimals );
		$net = self::net_price( $product, $catalog_price, $price['includes_tax'] );

		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			$order->remove_item( $item_id );
		}
		$item_id = $order->add_product(
			$product,
			1,
			array(
				/* translators: %s: product name. */
				'name' => sprintf( __( '%s (license renewal)', 'wp-license' ), $product->get_name() ),
				'subtotal' => $net,
				'total' => $net,
			)
		);
		// B-42: the invoice shows which license and which period the line pays for.
		$item = $item_id ? $order->get_item( $item_id, false ) : false;
		if ( $item ) {
			$item->add_meta_data( __( 'License', 'wp-license' ), sprintf( '#%d %s', (int) $license['id'], (string) ( $license['key_prefix'] ?? '' ) ), true );
			$item->add_meta_data(
				__( 'Service period (UTC)', 'wp-license' ),
				substr( (string) $renewal['period_start'], 0, 10 ) . ' – ' . substr( (string) $renewal['period_end'], 0, 10 ),
				true
			);
			$item->save();
		}
		$order->calculate_totals();

		// Store what WooCommerce kept as the pre-tax total, in minor units, for later verification.
		$net_minor = self::line_net_minor( $order, $decimals );
		if ( false === $net_minor || $net_minor <= 0 ) {
			return false;
		}
		$order->update_meta_data( self::META_NET_MINOR, (string) $net_minor );
		$order->update_meta_data( self::META_DECIMALS, (string) $decimals );
		$order->delete_meta_data( self::META_AMOUNT_OVERRIDE );

		return true;
	}

	/**
	 * B-76: when the stored amount is known to be gross and the customer is
	 * taxed like the shop base, the order total must equal that amount.
	 *
	 * @param \WC_Order           $order Priced order.
	 * @param array<string,mixed> $price Result of price().
	 * @return bool False only for a proven mismatch.
	 */
	private static function gross_matches( $order, array $price ): bool {
		if ( true !== $price['includes_tax'] || ! function_exists( 'wc_get_base_location' ) || 'yes' === $order->get_meta( 'is_vat_exempt', true ) ) {
			return true;
		}
		$base = wc_get_base_location();
		if ( (string) $order->get_billing_country() !== (string) ( $base['country'] ?? '' ) ) {
			return true;
		}
		$decimals = (int) $price['decimals'];
		$total = Money::to_minor_units( (string) wc_format_decimal( $order->get_total(), $decimals ), $decimals );

		return false === $total || abs( $total - (int) $price['amount_minor'] ) <= 1;
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
	 * Set the payment method and its title like a checkout would (B-186).
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $gateway_id Gateway ID.
	 * @return void
	 */
	private static function set_payment_method( $order, string $gateway_id ): void {
		$gateways = function_exists( 'WC' ) ? WC()->payment_gateways()->payment_gateways() : array();
		$order->set_payment_method( $gateway_id );
		if ( isset( $gateways[ $gateway_id ] ) && $gateways[ $gateway_id ] instanceof \WC_Payment_Gateway ) {
			// What WC_Order::set_payment_method() does with a gateway object at checkout.
			$order->set_payment_method_title( (string) $gateways[ $gateway_id ]->get_title() );
		}
	}

	/**
	 * Copy the customer's billing address to the order so the tax location resolves.
	 *
	 * B-77: no shipping address is set. A license has no delivery, and with
	 * "tax based on shipping address" a saved shipping address in another
	 * country would tax the renewal differently from the first purchase;
	 * without one WooCommerce falls back to the billing address.
	 *
	 * A-04: the VAT exemption (reverse charge) and the VAT ID of the first
	 * order are carried over while the billing country is unchanged.
	 *
	 * @param \WC_Order           $order Order.
	 * @param array<string,mixed> $license License row.
	 * @return void
	 */
	public static function set_addresses( $order, array $license ): void {
		$billing = array();
		if ( class_exists( '\\WC_Customer' ) ) {
			try {
				$customer = new \WC_Customer( (int) $license['customer_id'] );
				$billing = (array) $customer->get_billing();
			} catch ( \Exception $exception ) {
				$billing = array();
			}
		}
		$source_order = empty( $license['source_order_id'] ) ? false : wc_get_order( (int) $license['source_order_id'] );
		if ( empty( $billing['country'] ) && $source_order ) {
			// Fall back to the address the license was bought with.
			$billing = (array) $source_order->get_address( 'billing' );
		}
		if ( $billing ) {
			$order->set_address( $billing, 'billing' );
		}

		/**
		 * Filters the order meta keys copied from the first order to a renewal order.
		 *
		 * @param string[] $keys Meta keys (VAT exemption and VAT ID fields of common plugins).
		 */
		$keys = (array) apply_filters(
			'wplc_renewal_order_copied_meta_keys',
			array( 'is_vat_exempt', '_billing_vat_number', '_vat_number', '_billing_vat', '_billing_vat_id', '_billing_eu_vat_number', 'billing_vat_number', '_vat_number_is_validated', '_vat_number_is_valid' )
		);
		$same_country = $source_order && (string) $source_order->get_billing_country() === (string) $order->get_billing_country();
		foreach ( $keys as $key ) {
			$value = $same_country ? $source_order->get_meta( (string) $key, true ) : '';
			if ( '' !== $value && null !== $value && array() !== $value ) {
				$order->update_meta_data( (string) $key, $value );
			} else {
				$order->delete_meta_data( (string) $key );
			}
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
