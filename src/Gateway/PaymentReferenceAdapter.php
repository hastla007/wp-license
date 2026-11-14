<?php
/**
 * Optional contract for gateways whose payment method outlives its WooCommerce token row.
 *
 * @package WPLC
 */

namespace WPLC\Gateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A gateway may delete and recreate WooCommerce payment token rows on its own
 * (the Stripe gateway does when it synchronises saved methods). Adapters that
 * implement this contract let a license remember the gateway's own references
 * instead of only the row ID (B-57).
 */
interface PaymentReferenceAdapter {

	/**
	 * Mode the gateway currently runs in.
	 *
	 * @return string `live`, `test`, or an empty string when unknown.
	 */
	public function current_mode(): string;

	/**
	 * Gateway references of a saved token.
	 *
	 * @param int $token_id WooCommerce payment token ID.
	 * @param int $customer_id Customer ID.
	 * @return array{customer_ref:string,method_ref:string} Empty strings when unknown.
	 */
	public function token_reference( int $token_id, int $customer_id ): array;

	/**
	 * Find the customer's chargeable token for a gateway payment method reference.
	 *
	 * @param int    $customer_id Customer ID.
	 * @param string $method_ref Gateway payment method reference.
	 * @return int Token ID, zero when the customer has no such token.
	 */
	public function find_token( int $customer_id, string $method_ref ): int;
}
