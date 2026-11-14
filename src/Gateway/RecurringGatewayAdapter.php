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
	 * Failure codes with a meaning for the retry logic:
	 * - `renewal_manual_action_required`: the customer must authenticate; retries stop.
	 * - `renewal_result_unknown`: it is not known whether the charge went through
	 *   (timeout, network or gateway server error). This is not a decline: the
	 *   license is not touched and the renewal waits for reconciliation. Checks
	 *   call charge() again with the SAME key for up to 12 hours after the first
	 *   request, so the adapter must pass the key to the gateway unchanged and the
	 *   gateway must replay the first result for a known key. After that the
	 *   renewal goes to administrator review; a new key is never used before the
	 *   outcome is known.
	 * Every other error is a definite decline; the next attempt gets a new key.
	 *
	 * Adapters should store the gateway payment reference on the order (meta
	 * `_wplc_payment_intent_id`) as soon as it is known.
	 *
	 * @param \WC_Order $order Renewal order.
	 * @param int $token_id Saved WooCommerce payment token ID.
	 * @param string $idempotency_key Key of this attempt: `{renewal key}:{attempt_count}`.
	 * @return array|\WP_Error
	 */
	public function charge( $order, int $token_id, string $idempotency_key );
}
