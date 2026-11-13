<?php
/**
 * Optional checkout consent for automatic license renewals.
 *
 * @package WPLC
 */

namespace WPLC\Billing;

use WPLC\Gateway\Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Offers automatic renewal as an opt-in. A purchase never depends on it:
 * without consent, or with a payment method that cannot renew, the license
 * is issued for one paid period and simply expires at its end.
 *
 * Only the classic (shortcode) checkout is supported. The block checkout
 * renders no consent field, so licenses bought there never renew automatically.
 */
final class RecurringConsent {

	/**
	 * Register checkout hooks.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'woocommerce_review_order_before_submit', array( self::class, 'render_checkbox' ) );
		add_action( 'woocommerce_checkout_create_order', array( self::class, 'save_consent' ), 10, 2 );
		add_filter( 'woocommerce_checkout_registration_required', array( self::class, 'require_account' ) );
		add_filter( 'wc_stripe_force_save_source', array( self::class, 'force_save_payment_method' ) );
		add_filter( 'wc_stripe_force_save_payment_method', array( self::class, 'force_save_payment_method' ) );
		add_action( 'woocommerce_email_after_order_table', array( self::class, 'render_email_hint' ), 10, 3 );
	}

	/**
	 * Render the opt-in checkbox. It is never preselected.
	 *
	 * @return void
	 */
	public static function render_checkbox(): void {
		if ( ! self::cart_contains_license() || ! self::any_renewal_gateway_available() ) {
			return;
		}

		woocommerce_form_field(
			'wplc_recurring_consent',
			array(
				'type' => 'checkbox',
				'class' => array( 'form-row-wide' ),
				'required' => false,
				'label' => __( 'Renew this license automatically: I authorize renewal charges at the displayed monthly or yearly price to my saved card until I cancel. Without this option the license ends after the paid period.', 'wp-license' ),
			),
			''
		);
	}

	/**
	 * Store the consent timestamp only when the chosen gateway can renew.
	 *
	 * @param \WC_Order           $order Order being created.
	 * @param array<string,mixed> $data  Posted checkout data.
	 * @return void
	 */
	public static function save_consent( $order, array $data ): void {
		if ( ! self::consent_posted() || ! self::cart_contains_license() ) {
			return;
		}

		$gateway_id = sanitize_key( (string) ( $data['payment_method'] ?? '' ) );
		if ( ! Registry::supports_checkout( $gateway_id ) ) {
			$order->add_order_note( __( 'Automatic renewal was requested, but the chosen payment method does not support it. The license will not renew automatically.', 'wp-license' ) );
			return;
		}

		$order->update_meta_data( '_wplc_recurring_consent_at', gmdate( 'Y-m-d H:i:s' ) );
	}

	/**
	 * Licenses belong to a customer account, so guest checkout is not possible.
	 *
	 * @param bool $required Whether registration is already required.
	 * @return bool
	 */
	public static function require_account( $required ) {
		return $required || self::cart_contains_license();
	}

	/**
	 * Ask the Stripe gateway to save the card when the customer opted in,
	 * otherwise there is no payment method to renew with.
	 *
	 * @param bool $force Current value.
	 * @return bool
	 */
	public static function force_save_payment_method( $force ) {
		return $force || ( self::consent_posted() && self::cart_contains_license() );
	}

	/**
	 * Tell the customer where to find the license key.
	 *
	 * @param \WC_Order $order         Order.
	 * @param bool      $sent_to_admin Whether the email goes to the admin.
	 * @param bool      $plain_text    Whether the email is plain text.
	 * @return void
	 */
	public static function render_email_hint( $order, $sent_to_admin = false, $plain_text = false ): void {
		if ( $sent_to_admin || ! $order instanceof \WC_Order || ! $order->is_paid() || ! function_exists( 'wc_get_account_endpoint_url' ) ) {
			return;
		}

		$has_license = false;
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( $item->get_meta( '_wplc_license_ids', true ) ) {
				$has_license = true;
				break;
			}
		}
		if ( ! $has_license ) {
			return;
		}

		$url  = wc_get_account_endpoint_url( 'wplc-licenses' );
		$text = __( 'Your license key and site activations are available in your account under "Licenses":', 'wp-license' );
		if ( $plain_text ) {
			echo "\n" . esc_html( $text ) . ' ' . esc_url( $url ) . "\n";
			return;
		}
		echo '<p>' . esc_html( $text ) . ' <a href="' . esc_url( $url ) . '">' . esc_html( $url ) . '</a></p>';
	}

	/**
	 * Whether the classic checkout posted the opt-in.
	 *
	 * @return bool
	 */
	private static function consent_posted(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before these hooks run.
		return ! empty( $_POST['wplc_recurring_consent'] );
	}

	/**
	 * Whether any enabled payment method can renew automatically.
	 *
	 * @return bool
	 */
	private static function any_renewal_gateway_available(): bool {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return false;
		}
		foreach ( array_keys( WC()->payment_gateways()->get_available_payment_gateways() ) as $gateway_id ) {
			if ( Registry::supports_checkout( (string) $gateway_id ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Check whether the cart contains a mapped license product.
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
