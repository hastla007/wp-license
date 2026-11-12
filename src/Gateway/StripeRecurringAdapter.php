<?php
/**
 * Stripe card-token adapter for the official WooCommerce Stripe gateway.
 *
 * @package WPLC
 */

namespace WPLC\Gateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Charges a customer-owned Stripe payment method through the gateway API.
 *
 * This adapter intentionally supports saved card PaymentMethods only. Other
 * Stripe payment methods may require mandates, delayed settlement, or customer
 * interaction and are rejected until they have a reviewed implementation.
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
		if ( ! class_exists( 'WC_Stripe_API' ) || ! class_exists( 'WC_Stripe_Helper' ) || ! class_exists( 'WC_Payment_Tokens' ) ) {
			return false;
		}
		$gateways = function_exists( 'WC' ) && WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		if ( empty( $gateways['stripe'] ) || 'yes' !== $gateways['stripe']->enabled || ! get_current_user_id() ) {
			return false;
		}
		$tokens = \WC_Payment_Tokens::get_customer_tokens( get_current_user_id(), 'stripe' );
		foreach ( $tokens as $token ) {
			if ( $token && $this->supports_saved_token( (int) $token->get_id(), get_current_user_id() ) ) {
				return true;
			}
		}
		return false;
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
		$amount = \WC_Stripe_Helper::get_stripe_amount( $order->get_total(), $order->get_currency() );
		if ( ! is_int( $amount ) || $amount <= 0 ) {
			return new \WP_Error( 'stripe_amount_invalid', __( 'The renewal amount is not valid for Stripe.', 'wp-license' ) );
		}

		$request = array(
			'amount' => $amount,
			'currency' => strtolower( $order->get_currency() ),
			'customer' => $customer,
			'payment_method' => (string) $token->get_token(),
			'off_session' => 'true',
			'confirm' => 'true',
			'description' => sprintf( 'WP License Captain renewal order #%d', $order->get_id() ),
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
		try {
			$response = \WC_Stripe_API::request( $request, 'payment_intents', 'POST' );
		} catch ( \Throwable $exception ) {
			return new \WP_Error( 'stripe_request_failed', __( 'Stripe could not confirm the renewal payment.', 'wp-license' ) );
		} finally {
			remove_filter( 'wc_stripe_idempotency_key', $idempotency_filter, 10 );
		}

		if ( is_wp_error( $response ) || ! is_object( $response ) ) {
			return new \WP_Error( 'stripe_request_failed', __( 'Stripe returned an invalid payment response.', 'wp-license' ) );
		}
		if ( ! empty( $response->error ) ) {
			$code = sanitize_key( (string) ( $response->error->code ?? 'stripe_payment_failed' ) );
			if ( 'authentication_required' === $code || 'payment_intent_authentication_failure' === $code ) {
				return new \WP_Error( 'renewal_manual_action_required', __( 'The customer must authenticate this payment method before renewal can continue.', 'wp-license' ) );
			}
			return new \WP_Error( 'stripe_payment_failed', __( 'Stripe declined the renewal payment.', 'wp-license' ) );
		}
		$status = (string) ( $response->status ?? '' );
		if ( in_array( $status, array( 'requires_action', 'requires_confirmation' ), true ) ) {
			return new \\WP_Error( 'renewal_manual_action_required', __( 'The customer must authenticate this payment method before renewal can continue.', 'wp-license' ) );
		}
		if ( 'succeeded' !== $status ) {
			return new \\WP_Error( 'stripe_payment_unconfirmed', __( 'Stripe did not confirm the renewal payment as succeeded.', 'wp-license' ) );
		}
		$transaction_id = (string) ( $response->latest_charge ?? $response->id ?? '' );
		if ( '' === $transaction_id ) {
			return new \WP_Error( 'stripe_transaction_missing', __( 'Stripe did not return a transaction reference.', 'wp-license' ) );
		}
		return array( 'success' => true, 'transaction_id' => sanitize_text_field( $transaction_id ) );
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
		$adapter = new self();
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
