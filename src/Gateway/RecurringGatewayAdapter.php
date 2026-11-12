<?php
/**
 * Contract for gateways that support customer-authorized off-session renewals.
 *
 * @package WPLC
 */

namespace WPLC\Gateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gateway integrations implement this contract and register via the filter.
 */
interface RecurringGatewayAdapter {

	/**
	 * Return the WooCommerce gateway ID handled by this adapter.
	 *
	 * @return string
	 */
	public function gateway_id(): string;

	/**
	 * Confirm this gateway is configured for tokenized off-session charges.
	 *
	 * @return bool
	 */
	public function supports_checkout(): bool;

	/**
	 * Confirm the saved token belongs to the customer and can be charged off-session.
	 *
	 * @param int $token_id WooCommerce payment token ID.
	 * @param int $customer_id Customer ID.
	 * @return bool
	 */
	public function supports_saved_token( int $token_id, int $customer_id ): bool;

	/**
	 * Charge a renewal order with an idempotency key.
	 *
	 * Return an array with success=true and transaction_id on success, or WP_Error
	 * on failure. The adapter must never store card data or omit off-session consent.
	 *
	 * @param \WC_Order $order Renewal order.
	 * @param int $token_id Saved WooCommerce payment token ID.
	 * @param string $idempotency_key Stable per-period idempotency key.
	 * @return array|\WP_Error
	 */
	public function charge( $order, int $token_id, string $idempotency_key );
}
