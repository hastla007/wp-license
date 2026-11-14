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
	 *   (timeout, network or gateway server error). No attempt is counted and the
	 *   same `$idempotency_key` is passed again later. The adapter must derive the
	 *   gateway's key from it deterministically and should look the payment up at
	 *   the gateway before sending the charge again.
	 * - `renewal_configuration_error` (or another shop-side code, see
	 *   RenewalProcessor::SHOP_SIDE_ERRORS): the shop has to act. No attempt is
	 *   counted, the customer gets no mail, `wplc/renewal_blocked` fires. The error
	 *   data may name the cause in `reason`; it is stored on the renewal.
	 * Error data with a meaning:
	 * - `wait` (seconds): nothing failed, look again after that time (the customer
	 *   is paying the order, the payment is still being processed, the gateway
	 *   limits requests). No attempt is counted, no mail, no alert.
	 * - `retryable => false`: a definite decline that must not be tried again
	 *   (lost or stolen card, do-not-retry advice). Retries stop.
	 * Every other error is a definite decline; the next attempt gets a new key.
	 *
	 * On success the array may also carry `payment_intent_id`. The transaction ID
	 * must be the reference the gateway itself would store on the order.
	 *
	 * Adapters should store the gateway payment reference on the order (meta
	 * `_wplc_payment_intent_id`) as soon as it is known.
	 *
	 * @param \WC_Order $order Renewal order.
	 * @param int       $token_id Saved WooCommerce payment token ID.
	 * @param string    $idempotency_key Key of this attempt: `{renewal key}:{attempt_count}`.
	 * @return array|\WP_Error
	 */
	public function charge( $order, int $token_id, string $idempotency_key );
}
