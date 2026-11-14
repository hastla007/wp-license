<?php
/**
 * Optional checkout consent for automatic license renewals.
 *
 * @package WPLC
 */

namespace WPLC\Billing;

use WPLC\Catalog\Catalog;
use WPLC\Catalog\TermDisplay;
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
 *
 * "Does the cart or order contain a license product" is always answered by
 * Catalog::find_mapping(), the rule the license issuer uses as well.
 */
final class RecurringConsent {

	/**
	 * Order meta with the UTC time of the consent. LicenseIssuer reads it.
	 */
	const META_CONSENT_AT = '_wplc_recurring_consent_at';

	/**
	 * Order meta with the evidence of the consent: array with `text` (wording and
	 * renewal terms the customer saw), `hash` (SHA-256 of the text), `locale` and
	 * `source` (classic or block). LicenseIssuer copies it to every license.
	 */
	const META_EVIDENCE = '_wplc_recurring_consent_evidence';

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
		// With WooCommerce's defaults account creation in the checkout is off; requiring an
		// account without offering one would lock every new customer out.
		add_filter( 'woocommerce_checkout_registration_enabled', array( self::class, 'enable_registration' ) );
		add_action( 'woocommerce_checkout_order_processed', array( self::class, 'add_unsupported_note_for_classic_order' ), 10, 3 );
		// The older `wc_stripe_force_save_source` is deprecated since Stripe gateway 9.6.0 and
		// raises a deprecation notice when hooked; the supported minimum is 9.6.0.
		add_filter( 'wc_stripe_force_save_payment_method', array( self::class, 'force_save_payment_method' ), 10, 2 );
		add_action( 'woocommerce_email_after_order_table', array( self::class, 'render_email_hint' ), 10, 3 );
		add_action( 'woocommerce_thankyou', array( self::class, 'render_thankyou_hint' ), 5 );

		// Express buttons (Apple Pay, Google Pay, Link) skip the checkout form: no account, no opt-in.
		add_filter( 'wc_stripe_hide_payment_request_on_product_page', array( self::class, 'hide_express_on_product_page' ), 10, 2 );
		add_filter( 'wc_stripe_show_payment_request_on_cart', array( self::class, 'show_express_for_cart' ) );
		add_filter( 'wc_stripe_show_payment_request_on_checkout', array( self::class, 'show_express_for_cart' ) );
	}

	/**
	 * Wording of the opt-in, shared by both checkouts.
	 *
	 * It refers to the renewal price that TermDisplay prints for every license
	 * in the cart and the order summary ("Automatic renewal (optional): 59,00 € per year"),
	 * which is the regular price that will be charged, not a sale price.
	 *
	 * @return string
	 */
	public static function label(): string {
		return __( 'Renew automatically: I authorize charging my saved payment method the renewal price shown for each license at the end of every term, until I cancel. Without this option the license ends after the paid term.', 'wp-license' );
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
				'label' => self::label(),
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
		$gateway_id = sanitize_key( (string) ( $data['payment_method'] ?? '' ) );
		self::apply_decision(
			$order,
			self::decide( self::consent_posted(), self::order_contains_license( $order ) || self::cart_contains_license(), Registry::supports_checkout( $gateway_id ) ),
			'classic'
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
	 * @param string    $source   Checkout that collected the consent: `classic` or `block`.
	 * @return void
	 */
	public static function apply_decision( $order, string $decision, string $source = '' ): void {
		if ( 'granted' === $decision ) {
			$order->update_meta_data( self::META_CONSENT_AT, gmdate( 'Y-m-d H:i:s' ) );
			$order->update_meta_data( self::META_EVIDENCE, self::evidence( $order, $source ) );
			$order->delete_meta_data( self::META_UNSUPPORTED );
			return;
		}

		$order->delete_meta_data( self::META_CONSENT_AT );
		$order->delete_meta_data( self::META_EVIDENCE );
		if ( 'unsupported' !== $decision ) {
			$order->delete_meta_data( self::META_UNSUPPORTED );
		} elseif ( 'noted' !== $order->get_meta( self::META_UNSUPPORTED, true ) ) {
			$order->update_meta_data( self::META_UNSUPPORTED, '1' );
		}
	}

	/**
	 * What the customer agreed to: the wording of the opt-in plus the renewal
	 * terms of every license in the order, in the language of the checkout.
	 *
	 * @param \WC_Order $order  Order.
	 * @param string    $source `classic` or `block`.
	 * @return array{text:string,hash:string,locale:string,source:string}
	 */
	public static function evidence( $order, string $source ): array {
		$lines = array( self::label() );
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$mapping = Catalog::find_mapping( (int) $item->get_product_id(), (int) $item->get_variation_id() );
			if ( ! $mapping ) {
				continue;
			}
			$lines[] = $item->get_name() . ': ' . TermDisplay::renewal_price_text( $mapping, $item->get_product() );
		}
		$text = implode( "\n", $lines );

		return array(
			'text' => $text,
			'hash' => hash( 'sha256', $text ),
			'locale' => function_exists( 'determine_locale' ) ? (string) determine_locale() : (string) get_locale(),
			'source' => $source,
		);
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
	 * Offer account creation in the checkout whenever a license is in the cart.
	 *
	 * WooCommerce's default is "no account creation during checkout". Together
	 * with require_account() a new customer could then not buy a license at all.
	 *
	 * @param bool $enabled Whether the shop allows account creation in the checkout.
	 * @return bool
	 */
	public static function enable_registration( $enabled ) {
		return $enabled || self::cart_contains_license();
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
	 * Whether an order contains a product that issues a license.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	public static function order_contains_license( $order ): bool {
		if ( ! $order instanceof \WC_Order ) {
			return false;
		}
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( $item instanceof \WC_Order_Item_Product && Catalog::is_license_item( (int) $item->get_product_id(), (int) $item->get_variation_id() ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Tell the customer where to find the license key, and that an opt-in was not possible.
	 *
	 * @param \WC_Order $order         Order.
	 * @param bool      $sent_to_admin Whether the email goes to the admin.
	 * @param bool      $plain_text    Whether the email is plain text.
	 * @return void
	 */
	public static function render_email_hint( $order, $sent_to_admin = false, $plain_text = false ): void {
		if ( $sent_to_admin || ! $order instanceof \WC_Order ) {
			return;
		}

		if ( '' !== (string) $order->get_meta( self::META_UNSUPPORTED, true ) ) {
			$text = self::unsupported_text();
			echo $plain_text ? "\n" . esc_html( $text ) . "\n" : '<p>' . esc_html( $text ) . '</p>';
		}

		if ( ! $order->is_paid() || ! function_exists( 'wc_get_account_endpoint_url' ) ) {
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
	 * Order received page: say that the requested automatic renewal is not active.
	 *
	 * @param mixed $order_id Order ID.
	 * @return void
	 */
	public static function render_thankyou_hint( $order_id ): void {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( absint( $order_id ) ) : null;
		if ( ! $order instanceof \WC_Order || '' === (string) $order->get_meta( self::META_UNSUPPORTED, true ) ) {
			return;
		}
		echo '<p class="woocommerce-info wplc-renewal-unsupported">' . esc_html( self::unsupported_text() ) . '</p>';
	}

	/**
	 * Customer-facing explanation for an opt-in that could not be honored.
	 *
	 * @return string
	 */
	private static function unsupported_text(): string {
		return __( 'You asked for automatic renewal, but the payment method you chose does not support it. Your license will not renew automatically and ends after the paid term. You can renew it in your account before it ends.', 'wp-license' );
	}

	/**
	 * Hide the express payment buttons on the page of a license product for guests.
	 *
	 * @param mixed $hide Whether the buttons are hidden already.
	 * @param mixed $post Product post.
	 * @return bool
	 */
	public static function hide_express_on_product_page( $hide, $post = null ) {
		if ( $hide || is_user_logged_in() ) {
			return (bool) $hide;
		}
		$post_id = is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : (int) get_the_ID();
		if ( $post_id <= 0 ) {
			return (bool) $hide;
		}

		return in_array( $post_id, Catalog::license_product_ids(), true );
	}

	/**
	 * Hide the express payment buttons in cart and checkout while a guest has a license in the cart.
	 *
	 * @param mixed $show Whether the buttons are shown.
	 * @return bool
	 */
	public static function show_express_for_cart( $show ) {
		if ( ! $show || is_user_logged_in() ) {
			return (bool) $show;
		}
		return ! self::cart_contains_license();
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
	 * Check whether the cart contains a product that issues a license.
	 *
	 * @return bool
	 */
	public static function cart_contains_license(): bool {
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
			return false;
		}

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( ! is_array( $cart_item ) ) {
				continue;
			}
			if ( Catalog::is_license_item( (int) ( $cart_item['product_id'] ?? 0 ), (int) ( $cart_item['variation_id'] ?? 0 ) ) ) {
				return true;
			}
		}

		return false;
	}
}
