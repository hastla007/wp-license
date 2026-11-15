<?php
/**
 * Checkout consent to the immediate start of the license (withdrawal right for digital content).
 *
 * @package WPLC
 */

namespace WPLC\Billing;

use WPLC\Catalog\Catalog;
use WPLC\Compatibility\Requirements;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * B-39: in the EU a consumer may withdraw from a contract for digital content
 * within 14 days. The right ends early only when the consumer expressly agreed
 * that the trader starts before the period ends and acknowledged losing the right
 * (Directive 2011/83/EU, Art. 16 (m)); the trader must confirm this on a durable
 * medium (Art. 8 (7)).
 *
 * The plugin offers the technical default: a checkbox in the classic and the
 * block checkout for carts with a license product, required by default, recorded
 * on the order with the UTC time, the wording, its hash, the language and the
 * checkout, and repeated in the customer's order emails. The wording is filterable
 * (`wplc_withdrawal_consent_text`); whether and how a shop asks is the shop owner's
 * legal decision (docs/CONCEPT-CHECKOUT-RENEWAL.md, "Widerrufsrecht").
 *
 * When Germanized or German Market is active, those plugins ask for this consent
 * themselves and this one stays off unless `wplc_withdrawal_consent_enabled` says otherwise.
 */
final class WithdrawalConsent {

	/**
	 * Order meta with the UTC time of the consent.
	 */
	const META_CONSENT_AT = '_wplc_withdrawal_consent_at';

	/**
	 * Order meta with the evidence: array with `text`, `hash` (SHA-256 of the text), `locale` and `source`.
	 */
	const META_EVIDENCE = '_wplc_withdrawal_consent_evidence';

	/**
	 * Field name in the classic checkout.
	 */
	const FIELD_NAME = 'wplc_withdrawal_consent';

	/**
	 * Additional checkout field ID in the block checkout.
	 */
	const FIELD_ID = 'wplc/withdrawal-consent';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		// Before the automatic renewal opt-in (priority 10): it is part of the purchase itself.
		add_action( 'woocommerce_review_order_before_submit', array( self::class, 'render_checkbox' ), 9 );
		add_action( 'woocommerce_after_checkout_validation', array( self::class, 'validate_classic' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( self::class, 'save_classic' ), 10, 2 );

		add_action( 'woocommerce_init', array( self::class, 'register_field' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( self::class, 'save_block' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( self::class, 'validate_block_order' ), 5 );

		add_action( 'woocommerce_email_after_order_table', array( self::class, 'render_email_confirmation' ), 9, 3 );
		add_action( 'woocommerce_thankyou', array( self::class, 'render_thankyou_confirmation' ), 6 );
	}

	/**
	 * Whether the plugin asks for the consent.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		/**
		 * Filters whether the checkout asks for consent to the immediate start of the license.
		 *
		 * Default: true, unless Germanized or German Market is active (they ask for it themselves).
		 *
		 * @since 0.2.0
		 *
		 * @param bool $enabled Default as described.
		 */
		return (bool) apply_filters( 'wplc_withdrawal_consent_enabled', ! self::legal_plugin_active() );
	}

	/**
	 * Whether the consent is required to buy a license. Without it the box is optional
	 * and only recorded when ticked; the customer then keeps the right of withdrawal.
	 *
	 * @return bool
	 */
	public static function required(): bool {
		/**
		 * Filters whether a license can only be bought with the consent to the immediate start.
		 *
		 * @since 0.2.0
		 *
		 * @param bool $required Default true.
		 */
		return (bool) apply_filters( 'wplc_withdrawal_consent_required', true );
	}

	/**
	 * Whether a German legal-texts plugin that handles this consent is active.
	 *
	 * @return bool
	 */
	public static function legal_plugin_active(): bool {
		return class_exists( 'WooCommerce_Germanized', false ) || class_exists( 'Woocommerce_German_Market', false );
	}

	/**
	 * Wording next to the checkbox. Recorded verbatim as evidence.
	 *
	 * @return string
	 */
	public static function label(): string {
		$text = __( 'I expressly agree that you start providing the license and the download before the withdrawal period ends. I am aware that I lose my right of withdrawal once the license has been provided.', 'wp-license' );

		/**
		 * Filters the wording of the withdrawal consent. Legal wording is the shop owner's decision.
		 *
		 * @since 0.2.0
		 *
		 * @param string $text Default wording.
		 */
		$filtered = (string) apply_filters( 'wplc_withdrawal_consent_text', $text );

		return '' !== trim( $filtered ) ? $filtered : $text;
	}

	/**
	 * Message when the consent is missing.
	 *
	 * @return string
	 */
	public static function missing_message(): string {
		return __( 'Please agree to the immediate start of the license to complete your purchase.', 'wp-license' );
	}

	/**
	 * Classic checkout: render the checkbox. Never preselected; kept ticked across checkout refreshes (A-118).
	 *
	 * @return void
	 */
	public static function render_checkbox(): void {
		if ( ! self::enabled() || ! RecurringConsent::cart_contains_license() ) {
			return;
		}

		echo '<div class="wplc-withdrawal-consent">';
		woocommerce_form_field(
			self::FIELD_NAME,
			array(
				'type' => 'checkbox',
				'class' => array( 'form-row-wide', 'validate-required' ),
				'required' => self::required(),
				'label' => self::label(),
			),
			RecurringConsent::checkbox_posted( self::FIELD_NAME ) ? '1' : ''
		);
		echo '</div>';
	}

	/**
	 * Classic checkout: refuse the order without the consent.
	 *
	 * @param array<string,mixed> $data   Posted checkout data.
	 * @param mixed               $errors Validation errors (WP_Error).
	 * @return void
	 */
	public static function validate_classic( $data, $errors ): void {
		if ( ! $errors instanceof \WP_Error || ! self::enabled() || ! self::required() || ! RecurringConsent::cart_contains_license() ) {
			return;
		}
		if ( ! self::classic_posted() ) {
			$errors->add( 'wplc_withdrawal_consent', self::missing_message() );
		}
	}

	/**
	 * Classic checkout: record the consent on the order.
	 *
	 * @param mixed               $order Order being created (WC_Order).
	 * @param array<string,mixed> $data  Posted checkout data.
	 * @return void
	 */
	public static function save_classic( $order, $data = array() ): void {
		if ( ! $order instanceof \WC_Order || ! self::enabled() ) {
			return;
		}
		self::apply( $order, self::classic_posted() && RecurringConsent::order_contains_license( $order ), 'classic' );
	}

	/**
	 * Write or clear the consent on the order. Does not save the order.
	 *
	 * The block checkout reuses its draft order, so an earlier tick is removed when the box is unticked.
	 *
	 * @param \WC_Order $order   Order.
	 * @param bool      $granted Whether the customer ticked the box (for an order with a license).
	 * @param string    $source  `classic` or `block`.
	 * @return void
	 */
	public static function apply( $order, bool $granted, string $source ): void {
		if ( ! $granted ) {
			$order->delete_meta_data( self::META_CONSENT_AT );
			$order->delete_meta_data( self::META_EVIDENCE );
			return;
		}
		$text = self::label();
		$order->update_meta_data( self::META_CONSENT_AT, gmdate( 'Y-m-d H:i:s' ) );
		$order->update_meta_data(
			self::META_EVIDENCE,
			array(
				'text' => $text,
				'hash' => hash( 'sha256', $text ),
				'locale' => function_exists( 'determine_locale' ) ? (string) determine_locale() : (string) get_locale(),
				'source' => $source,
			)
		);
	}

	/**
	 * Block checkout: register the checkbox as an additional checkout field.
	 *
	 * From WooCommerce 9.9 it is shown and required only for carts with a license
	 * product (WooCommerce does not require a hidden field). Before 9.9, or above
	 * BlockCheckoutConsent::MAX_RULE_PRODUCTS license products, it cannot be hidden:
	 * it is then optional for WooCommerce and validate_block_order() enforces it for
	 * orders with a license.
	 *
	 * @return void
	 */
	public static function register_field(): void {
		if ( ! Requirements::supports_additional_checkout_fields() || ! self::enabled() ) {
			return;
		}
		$product_ids = Catalog::license_product_ids();
		if ( ! $product_ids ) {
			return;
		}
		$with_rule = Requirements::supports_conditional_checkout_fields() && count( $product_ids ) <= BlockCheckoutConsent::MAX_RULE_PRODUCTS;

		$field = array(
			'id' => self::FIELD_ID,
			'label' => self::label(),
			'location' => 'order',
			'type' => 'checkbox',
			'required' => $with_rule && self::required(),
			// The emails and the order confirmation print their own short confirmation with the date.
			'show_in_order_confirmation' => false,
		);
		if ( $with_rule ) {
			$field['hidden'] = BlockCheckoutConsent::hidden_rule( $product_ids );
		}

		try {
			woocommerce_register_additional_checkout_field( $field );
		} catch ( \Throwable $exception ) {
			// Registration problems must never break the shop; validate_block_order() still enforces the rule.
			return;
		}
	}

	/**
	 * Block checkout: record the consent when the order is placed.
	 *
	 * @param mixed $order   Draft order (WC_Order).
	 * @param mixed $request Store API request (WP_REST_Request).
	 * @return void
	 */
	public static function save_block( $order, $request ): void {
		if ( ! $order instanceof \WC_Order || ! $request instanceof \WP_REST_Request || 'POST' !== $request->get_method() || ! self::enabled() ) {
			return;
		}
		$fields = (array) $request->get_param( 'additional_fields' );
		self::apply( $order, BlockCheckoutConsent::is_checked( $fields[ self::FIELD_ID ] ?? false ) && RecurringConsent::order_contains_license( $order ), 'block' );
	}

	/**
	 * Block checkout: refuse an order with a license but without the consent, before the payment.
	 *
	 * @param mixed $order Order about to be paid (WC_Order).
	 * @return void
	 * @throws \Exception When the consent is missing.
	 */
	public static function validate_block_order( $order ): void {
		if ( ! $order instanceof \WC_Order || ! self::enabled() || ! self::required() ) {
			return;
		}
		if ( '' !== (string) $order->get_meta( self::META_CONSENT_AT, true ) || ! RecurringConsent::order_contains_license( $order ) ) {
			return;
		}
		$exception = '\\Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException';
		if ( class_exists( $exception ) ) {
			throw new $exception( 'wplc_withdrawal_consent_required', esc_html( self::missing_message() ), 400 );
		}
		throw new \Exception( esc_html( self::missing_message() ) );
	}

	/**
	 * Confirmation of the consent for the customer, or an empty string.
	 *
	 * @param mixed $order Order (WC_Order).
	 * @return string
	 */
	public static function confirmation_text( $order ): string {
		if ( ! $order instanceof \WC_Order ) {
			return '';
		}
		$at = (string) $order->get_meta( self::META_CONSENT_AT, true );
		if ( '' === $at ) {
			return '';
		}
		$evidence = $order->get_meta( self::META_EVIDENCE, true );
		$text     = is_array( $evidence ) && isset( $evidence['text'] ) && is_string( $evidence['text'] ) ? $evidence['text'] : self::label();

		return sprintf(
			/* translators: 1: date and time (UTC), 2: the wording the customer agreed to. */
			__( 'Start of the license: on %1$s (UTC) you agreed: "%2$s"', 'wp-license' ),
			substr( $at, 0, 16 ),
			$text
		);
	}

	/**
	 * Customer order emails: confirm the consent on a durable medium.
	 *
	 * @param \WC_Order $order         Order.
	 * @param bool      $sent_to_admin Whether the email goes to the admin.
	 * @param bool      $plain_text    Whether the email is plain text.
	 * @return void
	 */
	public static function render_email_confirmation( $order, $sent_to_admin = false, $plain_text = false ): void {
		if ( $sent_to_admin ) {
			return;
		}
		$text = self::confirmation_text( $order );
		if ( '' === $text ) {
			return;
		}
		echo $plain_text ? "\n" . esc_html( $text ) . "\n" : '<p class="wplc-withdrawal-consent-confirmation">' . esc_html( $text ) . '</p>';
	}

	/**
	 * Order received page: show the same confirmation.
	 *
	 * @param mixed $order_id Order ID.
	 * @return void
	 */
	public static function render_thankyou_confirmation( $order_id ): void {
		$text = self::confirmation_text( function_exists( 'wc_get_order' ) ? wc_get_order( absint( $order_id ) ) : null );
		if ( '' !== $text ) {
			echo '<p class="wplc-withdrawal-consent-confirmation">' . esc_html( $text ) . '</p>';
		}
	}

	/**
	 * Whether the classic checkout posted the box.
	 *
	 * @return bool
	 */
	private static function classic_posted(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before these hooks run.
		return ! empty( $_POST[ self::FIELD_NAME ] );
	}
}
