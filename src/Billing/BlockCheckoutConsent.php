<?php
/**
 * Automatic renewal opt-in for the block checkout (Store API).
 *
 * @package WPLC
 */

namespace WPLC\Billing;

use WPLC\Catalog\Catalog;
use WPLC\Compatibility\Requirements;
use WPLC\Gateway\Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the opt-in as an "additional checkout field" (WooCommerce 8.9+)
 * and turns its value into the same order meta the classic checkout writes,
 * so license issuing does not need to know which checkout was used.
 *
 * No JavaScript is shipped: WooCommerce renders the checkbox, validates it and
 * stores it as order meta `_wc_other/wplc/recurring-consent` ("1" or "0").
 */
final class BlockCheckoutConsent {

	/**
	 * Field ID (namespace/name, as the API requires).
	 */
	const FIELD_ID = 'wplc/recurring-consent';

	/**
	 * Order meta key WooCommerce stores the field under (group prefix `_wc_other/` + field ID).
	 */
	const FIELD_META_KEY = '_wc_other/wplc/recurring-consent';

	/**
	 * Former cache of the license product IDs (a transient built from product meta).
	 * The list now comes from Catalog::license_product_ids(); the name is only kept
	 * so that the old transient can be removed.
	 */
	const PRODUCT_IDS_TRANSIENT = 'wplc_license_product_ids';

	/**
	 * Above this many license products the visibility rule is not sent to the browser.
	 */
	const MAX_RULE_PRODUCTS = 300;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'woocommerce_init', array( self::class, 'register_field' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( self::class, 'save_consent' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( self::class, 'after_order_processed' ) );

		// The ID list contains the variations of products mapped at parent level, so it follows product changes.
		foreach ( array( 'woocommerce_new_product', 'woocommerce_update_product', 'woocommerce_new_product_variation', 'woocommerce_update_product_variation', 'trashed_post', 'untrashed_post', 'deleted_post' ) as $hook ) {
			add_action( $hook, array( self::class, 'flush_product_ids' ), 10, 0 );
		}
		// A scheduled product goes live without any of the save hooks above.
		add_action( 'transition_post_status', array( self::class, 'flush_on_status_change' ), 10, 3 );
	}

	/**
	 * Register the checkbox. It is optional and never preselected.
	 *
	 * @return void
	 */
	public static function register_field(): void {
		if ( ! Requirements::supports_additional_checkout_fields() ) {
			return;
		}

		/**
		 * Filters whether the automatic renewal opt-in is offered in the block checkout.
		 *
		 * @since 0.1.0
		 *
		 * @param bool $enabled Default true.
		 */
		if ( ! apply_filters( 'wplc_block_checkout_consent_enabled', true ) || ! Registry::any_supports_checkout() ) {
			return;
		}

		$product_ids = self::license_product_ids();
		if ( ! $product_ids ) {
			return;
		}

		$field = array(
			'id'       => self::FIELD_ID,
			// One wording for both checkouts. The renewal price of every license stands in the
			// order summary (TermDisplay), so the label does not have to repeat amounts.
			'label'    => RecurringConsent::label(),
			'location' => 'order',
			'type'     => 'checkbox',
			'required' => false,
			// Deliberately shown on the order confirmation and in the order emails: "yes" there is the
			// customer's own record of the consent. save_consent() resets the value when nothing will renew.
			'show_in_order_confirmation' => true,
		);
		// Before WooCommerce 9.9 a field cannot be hidden; it is then shown for every cart
		// as soon as the shop sells license products. The value is ignored for other orders.
		if ( Requirements::supports_conditional_checkout_fields() && count( $product_ids ) <= self::MAX_RULE_PRODUCTS ) {
			$field['hidden'] = self::hidden_rule( $product_ids );
		}

		try {
			woocommerce_register_additional_checkout_field( $field );
		} catch ( \Throwable $exception ) {
			// Registration problems must never break the shop; the checkout then simply has no opt-in.
			return;
		}
	}

	/**
	 * JSON-schema rule for the `hidden` option: hide the field unless the cart
	 * holds at least one license product.
	 *
	 * WooCommerce evaluates the rule against a document whose `cart.items` is the
	 * list of product (or variation) IDs in the cart, in the browser and again on the server.
	 *
	 * @param array<int,int|string> $product_ids License product and variation IDs.
	 * @return array<string,mixed>
	 */
	public static function hidden_rule( array $product_ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $product_ids ) ) ) );

		return array(
			'cart' => array(
				'properties' => array(
					'items' => array(
						'not' => array(
							'contains' => array(
								'enum' => $ids,
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Whether a posted field value means "ticked".
	 *
	 * @param mixed $value Raw value from the request (`true`, "1", "true" ...).
	 * @return bool
	 */
	public static function is_checked( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( ! is_scalar( $value ) ) {
			return false;
		}
		return true === filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Translate the field into the consent meta when the order is placed.
	 *
	 * Fires after WooCommerce set the payment method and stored the additional
	 * fields on the draft order, and before the payment is processed.
	 *
	 * @param \WC_Order        $order   Draft order.
	 * @param \WP_REST_Request $request Store API request.
	 * @return void
	 */
	public static function save_consent( $order, $request ): void {
		// The hook also fires while the customer is still editing the checkout (PUT/PATCH).
		if ( ! $order instanceof \WC_Order || ! $request instanceof \WP_REST_Request || 'POST' !== $request->get_method() ) {
			return;
		}

		$fields    = (array) $request->get_param( 'additional_fields' );
		$requested = self::is_checked( $fields[ self::FIELD_ID ] ?? false );
		$decision  = RecurringConsent::decide(
			$requested,
			RecurringConsent::order_contains_license( $order ),
			Registry::supports_checkout( (string) $order->get_payment_method() )
		);

		RecurringConsent::apply_decision( $order, $decision, 'block' );
		if ( $requested && 'granted' !== $decision ) {
			// Keep "Yes" off the order confirmation and the admin order screen when nothing will renew.
			$order->update_meta_data( self::FIELD_META_KEY, '0' );
		}
	}

	/**
	 * Last checks before the payment: the buyer has an account, and the order
	 * explains a declined opt-in.
	 *
	 * WooCommerce already applies `woocommerce_checkout_registration_required` in
	 * the Store API (it creates the account or rejects the guest). This guard only
	 * catches a shop configuration or extension that lets a guest through anyway.
	 *
	 * @param \WC_Order $order Order about to be paid.
	 * @return void
	 * @throws \Exception When a guest tries to buy a license.
	 */
	public static function after_order_processed( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		if ( ! $order->get_customer_id() && RecurringConsent::order_contains_license( $order ) ) {
			$message   = __( 'A customer account is required to buy a license. Please log in or create an account and try again.', 'wp-license' );
			$exception = '\\Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException';
			if ( class_exists( $exception ) ) {
				throw new $exception( 'wplc_account_required', esc_html( $message ), 403 );
			}
			throw new \Exception( esc_html( $message ) );
		}

		RecurringConsent::add_unsupported_note( $order );
	}

	/**
	 * IDs of all products and variations that issue a license.
	 *
	 * Read from the license catalog (autoloaded option, no query per request),
	 * not from product meta: a product is a license product when it has a plan.
	 *
	 * @return array<int,int>
	 */
	public static function license_product_ids(): array {
		return Catalog::license_product_ids();
	}

	/**
	 * Forget the cached product IDs after a product change.
	 *
	 * @return void
	 */
	public static function flush_product_ids(): void {
		Catalog::flush_product_ids();
	}

	/**
	 * Forget the cached product IDs when a product or variation changes its status
	 * (scheduled publication, private, draft).
	 *
	 * @param mixed $new_status New post status.
	 * @param mixed $old_status Old post status.
	 * @param mixed $post       Post object.
	 * @return void
	 */
	public static function flush_on_status_change( $new_status, $old_status, $post ): void {
		if ( $new_status === $old_status || ! is_object( $post ) || ! isset( $post->post_type ) ) {
			return;
		}
		if ( in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) {
			Catalog::flush_product_ids();
		}
	}
}
