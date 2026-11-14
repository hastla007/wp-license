<?php
/**
 * Stripe card-token adapter for the official WooCommerce Stripe gateway.
 *
 * @package WPLC
 */

namespace WPLC\Gateway;

use WPLC\Compatibility\Requirements;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Charges a customer-owned Stripe payment method through the gateway API.
 *
 * This adapter intentionally supports saved card PaymentMethods only. Other
 * Stripe payment methods may require mandates, delayed settlement, or customer
 * interaction and are rejected until they have a reviewed implementation.
 *
 * The gateway offers no public API for this. The adapter calls its internals
 * (`WC_Stripe_API::request()`, `WC_Stripe_Helper::get_stripe_amount()`, the user
 * option `_stripe_customer_id`, order meta `_stripe_source_id`), verified against
 * the gateway source of versions 9.6.0 and 11.2.0. Requirements::stripe_adapter_status()
 * switches the adapter off when the gateway is missing, too old or has lost one of them.
 */
final class StripeRecurringAdapter implements RecurringGatewayAdapter {

	/**
	 * Register adapter and payment-token lookup.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_filter( 'wplc_recurring_gateway_adapters', array( self::class, 'register_adapter' ) );
		add_filter( 'wplc_payment_token_id_for_order', array( self::class, 'token_id_for_order' ), 10, 2 );
	}

	/**
	 * Add this adapter to the shared registry.
	 *
	 * @param array $adapters Existing adapters.
	 * @return array
	 */
	public static function register_adapter( array $adapters ): array {
		$adapters[] = new self();
		return $adapters;
	}

	/**
	 * @inheritDoc
	 */
	public function gateway_id(): string {
		return 'stripe';
	}

	/**
	 * @inheritDoc
	 */
	public function supports_checkout(): bool {
		// A first-time buyer has no saved card yet; the card is saved with the order
		// when consent is given, so checkout support only depends on the gateway.
		$status = Requirements::stripe_adapter_status();
		return $status['ok'];
	}

	/**
	 * @inheritDoc
	 */
	public function supports_saved_token( int $token_id, int $customer_id ): bool {
		if ( ! $token_id || ! $customer_id || ! class_exists( 'WC_Payment_Tokens' ) || ! class_exists( 'WC_Stripe_API' ) ) {
			return false;
		}
		$token = \WC_Payment_Tokens::get( $token_id );
		if ( ! $token || 'stripe' !== $token->get_gateway_id() || (int) $token->get_user_id() !== $customer_id ) {
			return false;
		}
		// The gateway stores Link and other wallets under the same gateway ID; only cards are supported.
		if ( 'CC' !== $token->get_type() ) {
			return false;
		}
		$payment_method = (string) $token->get_token();
		if ( 0 !== strpos( $payment_method, 'pm_' ) ) {
			return false;
		}
		$customer = (string) get_user_option( '_stripe_customer_id', $customer_id );
		return 0 === strpos( $customer, 'cus_' );
	}

	/**
	 * @inheritDoc
	 */
	public function charge( $order, int $token_id, string $idempotency_key ) {
		if ( ! $order instanceof \WC_Order || ! $order->get_meta( '_wplc_renewal_id', true ) ) {
			return new \WP_Error( 'stripe_renewal_order_invalid', __( 'The renewal order could not be verified.', 'wp-license' ) );
		}
		$customer_id = (int) $order->get_customer_id();
		if ( ! $this->supports_saved_token( $token_id, $customer_id ) || 0 >= (float) $order->get_total() ) {
			return new \WP_Error( 'stripe_payment_method_unavailable', __( 'A saved Stripe card is unavailable for this customer.', 'wp-license' ) );
		}
		$token = \WC_Payment_Tokens::get( $token_id );
		$customer = (string) get_user_option( '_stripe_customer_id', $customer_id );
		try {
			// Throws since gateway 11.2.0 when the amount exceeds what Stripe accepts.
			$amount = \WC_Stripe_Helper::get_stripe_amount( $order->get_total(), $order->get_currency() );
		} catch ( \Throwable $exception ) {
			$amount = 0;
		}
		if ( ! is_int( $amount ) || $amount <= 0 ) {
			return new \WP_Error( 'stripe_amount_invalid', __( 'The renewal amount is not valid for Stripe.', 'wp-license' ) );
		}

		$request = array(
			'amount' => $amount,
			'currency' => strtolower( $order->get_currency() ),
			'customer' => $customer,
			'payment_method' => (string) $token->get_token(),
			// Without this Stripe enables automatic payment methods and rejects a confirmed
			// intent that has no return_url. The gateway sends the same for its own off-session charges.
			'payment_method_types' => array( 'card' ),
			'off_session' => 'true',
			'confirm' => 'true',
			'description' => self::payment_description( $order ),
			'metadata' => array(
				'order_id' => (string) $order->get_id(),
				'wplc_renewal_id' => (string) $order->get_meta( '_wplc_renewal_id', true ),
				'wplc_license_id' => (string) $order->get_meta( '_wplc_renewal_for_license', true ),
			),
		);
		$idempotency_filter = static function ( $current, $body ) use ( $idempotency_key ) {
			return ! empty( $body['metadata']['wplc_renewal_id'] ) ? $idempotency_key : $current;
		};
		add_filter( 'wc_stripe_idempotency_key', $idempotency_filter, 10, 2 );
		// No answer, or an answer that says nothing about the charge: the caller
		// repeats this attempt with the same idempotency key instead of counting a failure.
		$unknown = new \WP_Error( 'renewal_result_unknown', __( 'Stripe did not return a definite result for the renewal payment.', 'wp-license' ) );
		try {
			$response = \WC_Stripe_API::request( $request, 'payment_intents', 'POST' );
		} catch ( \Throwable $exception ) {
			return $unknown;
		} finally {
			remove_filter( 'wc_stripe_idempotency_key', $idempotency_filter, 10 );
		}

		if ( is_wp_error( $response ) || ! is_object( $response ) ) {
			return $unknown;
		}
		if ( ! empty( $response->error ) ) {
			$this->remember_payment_intent( $order, (string) ( $response->error->payment_intent->id ?? '' ) );
			$code = sanitize_key( (string) ( $response->error->code ?? 'stripe_payment_failed' ) );
			$type = sanitize_key( (string) ( $response->error->type ?? '' ) );
			if ( 'authentication_required' === $code || 'payment_intent_authentication_failure' === $code ) {
				return new \WP_Error( 'renewal_manual_action_required', __( 'The customer must authenticate this payment method before renewal can continue.', 'wp-license' ) );
			}
			// Only card and request errors are a definite "not charged". API, connection,
			// rate-limit and idempotency errors leave the outcome open.
			if ( ! in_array( $type, array( 'card_error', 'invalid_request_error' ), true ) || 'idempotency_key_in_use' === $code ) {
				return $unknown;
			}
			return new \WP_Error( 'stripe_payment_failed', __( 'Stripe declined the renewal payment.', 'wp-license' ) );
		}
		$this->remember_payment_intent( $order, (string) ( $response->id ?? '' ) );
		$status = (string) ( $response->status ?? '' );
		// `processing` is not final either way. Retrying would replay the same answer, so automatic
		// attempts stop here; the payment_intent.succeeded webhook books the renewal when it settles.
		if ( in_array( $status, array( 'requires_action', 'requires_confirmation', 'processing' ), true ) ) {
			return new \WP_Error( 'renewal_manual_action_required', __( 'The customer must authenticate this payment method before renewal can continue.', 'wp-license' ) );
		}
		if ( 'succeeded' !== $status ) {
			return new \WP_Error( 'stripe_payment_unconfirmed', __( 'Stripe did not confirm the renewal payment as succeeded.', 'wp-license' ) );
		}
		$transaction_id = (string) ( $response->latest_charge ?? $response->id ?? '' );
		if ( '' === $transaction_id ) {
			return new \WP_Error( 'stripe_transaction_missing', __( 'Stripe did not return a transaction reference.', 'wp-license' ) );
		}
		return array( 'success' => true, 'transaction_id' => sanitize_text_field( $transaction_id ) );
	}

	/**
	 * Payment description Stripe shows on the customer's receipt and statement.
	 *
	 * Written in the shop's language: the charge usually runs in a cron job,
	 * and a run triggered from the admin must not use the administrator's language.
	 *
	 * @param \WC_Order $order Renewal order.
	 * @return string
	 */
	private static function payment_description( $order ): string {
		$product = '';
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$product = (string) $item->get_name();
			break;
		}
		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );

		$switched = function_exists( 'switch_to_locale' ) && switch_to_locale( get_locale() );
		try {
			if ( '' === $product ) {
				/* translators: 1: shop name, 2: WooCommerce order number. */
				$description = sprintf( __( '%1$s: license renewal, order #%2$d', 'wp-license' ), $site, $order->get_id() );
			} else {
				/* translators: 1: shop name, 2: product name, 3: WooCommerce order number. */
				$description = sprintf( __( '%1$s: renewal of %2$s, order #%3$d', 'wp-license' ), $site, $product, $order->get_id() );
			}
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
		}

		// Stripe accepts up to 1000 characters.
		return function_exists( 'mb_substr' ) ? mb_substr( $description, 0, 1000 ) : substr( $description, 0, 1000 );
	}

	/**
	 * Store the PaymentIntent of the current attempt on the renewal order.
	 *
	 * Used to resolve disputes to the order and to look the payment up in Stripe.
	 *
	 * @param \WC_Order $order Renewal order.
	 * @param string    $intent_id Stripe PaymentIntent ID.
	 * @return void
	 */
	private function remember_payment_intent( $order, string $intent_id ): void {
		$intent_id = sanitize_text_field( $intent_id );
		if ( 0 !== strpos( $intent_id, 'pi_' ) || $intent_id === (string) $order->get_meta( '_wplc_payment_intent_id', true ) ) {
			return;
		}
		$order->update_meta_data( '_wplc_payment_intent_id', $intent_id );
		$order->save();
	}

	/**
	 * Find a Stripe card token saved for this order's customer.
	 *
	 * @param int $token_id Existing filter value.
	 * @param \WC_Order $order Paid order.
	 * @return int
	 */
	public static function token_id_for_order( int $token_id, $order ): int {
		if ( $token_id || ! $order instanceof \WC_Order || 'stripe' !== $order->get_payment_method() || ! $order->get_customer_id() ) {
			return $token_id;
		}
		if ( ! class_exists( 'WC_Payment_Tokens' ) ) {
			return 0;
		}
		$adapter = new self();
		// The gateway records the PaymentMethod it charged in `_stripe_source_id` and does not
		// attach the saved token to the order. Prefer the card the customer actually paid with.
		$paid_with = (string) $order->get_meta( '_stripe_source_id', true );
		if ( 0 === strpos( $paid_with, 'pm_' ) ) {
			foreach ( \WC_Payment_Tokens::get_customer_tokens( (int) $order->get_customer_id(), 'stripe' ) as $token ) {
				if ( $paid_with === (string) $token->get_token() && $adapter->supports_saved_token( (int) $token->get_id(), (int) $order->get_customer_id() ) ) {
					return (int) $token->get_id();
				}
			}
		}
		$tokens = \WC_Payment_Tokens::get_order_tokens( $order->get_id() );
		foreach ( $tokens as $token ) {
			if ( 'stripe' === $token->get_gateway_id() && $adapter->supports_saved_token( (int) $token->get_id(), (int) $order->get_customer_id() ) ) {
				return (int) $token->get_id();
			}
		}
		$default = \WC_Payment_Tokens::get_customer_default_token( (int) $order->get_customer_id() );
		if ( $default && 'stripe' === $default->get_gateway_id() && $adapter->supports_saved_token( (int) $default->get_id(), (int) $order->get_customer_id() ) ) {
			return (int) $default->get_id();
		}
		return 0;
	}
}
