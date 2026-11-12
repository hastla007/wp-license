<?php
/**
 * Checkout consent for automatic license renewals.
 *
 * @package WPLC
 */

namespace WPLC\Billing;

use WPLC\Gateway\Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds checkout disclosure and prevents unsupported renewal setups.
 */
final class RecurringConsent {

	/**
	 * Register checkout hooks.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'woocommerce_review_order_before_submit', array( self::class, 'render_checkbox' ) );
		add_action( 'woocommerce_checkout_process', array( self::class, 'validate_checkout' ) );
		add_action( 'woocommerce_checkout_create_order', array( self::class, 'save_consent' ), 10, 2 );
	}

	/**
	 * Render consent when a license product is in the cart.
	 *
	 * @return void
	 */
	public static function render_checkbox(): void {
		if ( ! self::cart_contains_license() ) {
			return;
		}

		woocommerce_form_field(
			'wplc_recurring_consent',
			array(
				'type' => 'checkbox',
				'class' => array( 'form-row-wide' ),
				'required' => true,
				'label' => __( 'I authorize automatic renewal charges for this license at the displayed monthly or yearly price until I cancel.', 'wp-license' ),
			),
			isset( $_POST['wplc_recurring_consent'] ) ? wc_clean( wp_unslash( $_POST['wplc_recurring_consent'] ) ) : ''
		);
	}

	/**
	 * Reject license checkout without consent or a configured off-session adapter.
	 *
	 * @return void
	 */
	public static function validate_checkout(): void {
		if ( ! self::cart_contains_license() ) {
			return;
		}

		if ( empty( $_POST['wplc_recurring_consent'] ) ) {
			wc_add_notice( __( 'Please authorize automatic license renewal to continue.', 'wp-license' ), 'error' );
			return;
		}

		$gateway_id = isset( $_POST['payment_method'] ) ? sanitize_key( wp_unslash( $_POST['payment_method'] ) ) : '';
		if ( ! Registry::supports_checkout( $gateway_id ) ) {
			wc_add_notice( __( 'Automatic renewal is not configured for this payment method. Please contact the store owner.', 'wp-license' ), 'error' );
		}
	}

	/**
	 * Save the customer's consent time on the WooCommerce order.
	 *
	 * @param \WC_Order $order Order being created.
	 * @param array $data Checkout data.
	 * @return void
	 */
	public static function save_consent( $order, array $data ): void {
		if ( self::cart_contains_license() && ! empty( $_POST['wplc_recurring_consent'] ) ) {
			$order->update_meta_data( '_wplc_recurring_consent_at', gmdate( 'Y-m-d H:i:s' ) );
		}
	}

	/**
	 * Check whether a mapped license product is in the cart.
	 *
	 * @return bool
	 */
	private static function cart_contains_license(): bool {
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
			return false;
		}

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$product = $cart_item['data'] ?? null;
			if ( $product && 'yes' === $product->get_meta( '_wplc_is_license_product', true ) ) {
				return true;
			}
		}

		return false;
	}
}
