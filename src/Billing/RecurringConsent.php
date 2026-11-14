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
 * This class renders the opt-in in the classic (shortcode) checkout and holds
 * the rules both checkouts share. The block checkout field lives in
 * BlockCheckoutConsent and ends in the same order meta.
 */
final class RecurringConsent {

	/**
	 * Order meta with the UTC time of the consent. LicenseIssuer reads it.
	 */
	const META_CONSENT_AT = '_wplc_recurring_consent_at';

	/**
	 * Order meta: consent was requested with a payment method that cannot renew.
	 * "1" until the order note is written, "noted" afterwards.
	 */
	const META_UNSUPPORTED = '_wplc_recurring_consent_unsupported';

	/**
	 * Register checkout hooks.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'woocommerce_review_order_before_submit', array( self::class, 'render_checkbox' ) );
		add_action( 'woocommerce_checkout_create_order', array( self::class, 'save_consent' ), 10, 2 );
		add_filter( 'woocommerce_checkout_registration_required', array( self::class, 'require_account' ) );
		add_action( 'woocommerce_checkout_order_processed', array( self::class, 'add_unsupported_note_for_classic_order' ), 10, 3 );
		// The older `wc_stripe_force_save_source` is deprecated since Stripe gateway 9.6.0 and
		// raises a deprecation notice when hooked; the supported minimum is 9.6.0.
		add_filter( 'wc_stripe_force_save_payment_method', array( self::class, 'force_save_payment_method' ), 10, 2 );
		add_action( 'woocommerce_email_after_order_table', array( self::class, 'render_email_hint' ), 10, 3 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_checkout_script' ) );
	}

	/**
	 * Classic checkout: show the opt-in only while a payment method that can renew is selected.
	 *
	 * Without JavaScript the box stays visible; the server still records an opt-in with another payment method as
	 * unsupported (save_consent()).
	 *
	 * @return void
	 */
	public static function enqueue_checkout_script(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		wp_enqueue_script( 'wplc-checkout', plugins_url( 'assets/js/wplc-checkout.js', WPLC_FILE ), array( 'jquery' ), WPLC_VERSION, true );
	}

	/**
	 * IDs of the available payment methods that can renew automatically.
	 *
	 * @return string[]
	 */
	private static function renewal_gateway_ids(): array {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return array();
		}
		$ids = array();
		foreach ( array_keys( WC()->payment_gateways()->get_available_payment_gateways() ) as $gateway_id ) {
			if ( Registry::supports_checkout( (string) $gateway_id ) ) {
				$ids[] = (string) $gateway_id;
			}
		}
		return $ids;
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

		echo '<div class="wplc-recurring-consent" data-wplc-gateways="' . esc_attr( implode( ' ', self::renewal_gateway_ids() ) ) . '">';
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
		echo '</div>';
	}

	/**
	 * Store the consent timestamp only when the chosen gateway can renew.
	 *
	 * @param \WC_Order           $order Order being created.
	 * @param array<string,mixed> $data  Posted checkout data.
	 * @return void
	 */
	public static function save_consent( $order, array $data ): void {
		$gateway_id = sanitize_key( (string) ( $data['payment_method'] ?? '' ) );
		self::apply_decision(
			$order,
			self::decide( self::consent_posted(), self::cart_contains_license(), Registry::supports_checkout( $gateway_id ) )
		);
	}

	/**
	 * The one rule for both checkouts.
	 *
	 * @param bool $requested         The customer ticked the opt-in.
	 * @param bool $has_license       The cart or order contains a license product.
	 * @param bool $gateway_supported The chosen payment method has a renewal adapter that is ready.
	 * @return string `granted`, `unsupported` (requested, but the payment method cannot renew) or `none`.
	 */
	public static function decide( bool $requested, bool $has_license, bool $gateway_supported ): string {
		if ( ! $requested || ! $has_license ) {
			return 'none';
		}
		return $gateway_supported ? 'granted' : 'unsupported';
	}

	/**
	 * Write the decision to the order. Does not save the order.
	 *
	 * Also clears an earlier decision, because the block checkout reuses its draft
	 * order when the customer changes the choice and submits again.
	 *
	 * @param \WC_Order $order    Order.
	 * @param string    $decision Result of decide().
	 * @return void
	 */
	public static function apply_decision( $order, string $decision ): void {
		if ( 'granted' === $decision ) {
			$order->update_meta_data( self::META_CONSENT_AT, gmdate( 'Y-m-d H:i:s' ) );
			$order->delete_meta_data( self::META_UNSUPPORTED );
			return;
		}

		$order->delete_meta_data( self::META_CONSENT_AT );
		if ( 'unsupported' !== $decision ) {
			$order->delete_meta_data( self::META_UNSUPPORTED );
		} elseif ( 'noted' !== $order->get_meta( self::META_UNSUPPORTED, true ) ) {
			$order->update_meta_data( self::META_UNSUPPORTED, '1' );
		}
	}

	/**
	 * Explain on the order why it will not renew although the customer asked for it.
	 *
	 * Runs after the order has an ID: a note added in `woocommerce_checkout_create_order`
	 * is dropped for a new order, because notes need a saved order.
	 *
	 * @param \WC_Order $order Order.
	 * @return void
	 */
	public static function add_unsupported_note( $order ): void {
		if ( ! $order instanceof \WC_Order || ! $order->get_id() || '1' !== $order->get_meta( self::META_UNSUPPORTED, true ) ) {
			return;
		}
		$order->add_order_note( __( 'Automatic renewal was requested, but the chosen payment method does not support it. The license will not renew automatically.', 'wp-license' ) );
		$order->update_meta_data( self::META_UNSUPPORTED, 'noted' );
		$order->save();
	}

	/**
	 * Classic checkout: write the note once the order is stored.
	 *
	 * @param int                 $order_id Order ID.
	 * @param array<string,mixed> $data     Posted checkout data.
	 * @param \WC_Order|null      $order    Order.
	 * @return void
	 */
	public static function add_unsupported_note_for_classic_order( $order_id, $data = array(), $order = null ): void {
		self::add_unsupported_note( $order instanceof \WC_Order ? $order : wc_get_order( $order_id ) );
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
	 * The gateway passes the order ID while it processes a payment
	 * (`WC_Stripe_Helper::should_force_save_payment_method( false, $order_id )`).
	 * Both checkouts have stored the consent on the order by then, so the order is
	 * the source; `$_POST` is empty in block checkout (Store API) requests. Without
	 * an order ID the gateway is only building its page settings and nothing is forced.
	 *
	 * @param bool            $force    Current value.
	 * @param int|string|null $order_id Order being paid, null outside payment processing.
	 * @return bool
	 */
	public static function force_save_payment_method( $force, $order_id = null ) {
		if ( $force || ! $order_id || ! function_exists( 'wc_get_order' ) ) {
			return (bool) $force;
		}
		$order = wc_get_order( absint( $order_id ) );
		return $order instanceof \WC_Order && '' !== (string) $order->get_meta( self::META_CONSENT_AT, true );
	}

	/**
	 * Whether an order contains a mapped license product.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	public static function order_contains_license( $order ): bool {
		if ( ! $order instanceof \WC_Order ) {
			return false;
		}
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$product = is_callable( array( $item, 'get_product' ) ) ? $item->get_product() : null;
			if ( $product && 'yes' === $product->get_meta( '_wplc_is_license_product', true ) ) {
				return true;
			}
		}
		return false;
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
		return array() !== self::renewal_gateway_ids();
	}

	/**
	 * Check whether the cart contains a mapped license product.
	 *
	 * @return bool
	 */
	public static function cart_contains_license(): bool {
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
