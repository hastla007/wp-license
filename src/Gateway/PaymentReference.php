<?php
/**
 * Payment reference of a license: gateway customer, payment method and mode.
 *
 * @package WPLC
 */

namespace WPLC\Gateway;

use WPLC\Infrastructure\Database\Schema;
use WPLC\License\AuditLog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores and resolves what a license is renewed with (B-57, B-78).
 *
 * The WooCommerce token ID alone is not stable: the Stripe gateway deletes and
 * recreates token rows when it synchronises saved methods. The license
 * therefore also remembers the gateway's customer and payment method
 * references and the mode (live or test) they belong to.
 */
final class PaymentReference {

	/** Error code of a renewal that cannot be charged because of the shop's configuration. */
	public const CONFIGURATION_ERROR = 'renewal_configuration_error';

	/**
	 * Store the references of the token a license was bought with.
	 *
	 * Called when a license with automatic renewal is issued, and whenever the
	 * license moves to another payment method.
	 *
	 * @param int    $license_id License ID.
	 * @param string $gateway_id WooCommerce gateway ID.
	 * @param int    $token_id WooCommerce payment token ID.
	 * @param int    $customer_id Customer ID.
	 * @return bool True when references were written.
	 */
	public static function store( int $license_id, string $gateway_id, int $token_id, int $customer_id ): bool {
		global $wpdb;

		$adapter = Registry::get( $gateway_id );
		if ( ! $license_id || ! $token_id || ! $adapter instanceof PaymentReferenceAdapter ) {
			return false;
		}
		$reference = $adapter->token_reference( $token_id, $customer_id );
		if ( '' === (string) ( $reference['method_ref'] ?? '' ) ) {
			return false;
		}
		$tables = Schema::table_names();

		return false !== $wpdb->update(
			$tables['licenses'],
			array(
				'gateway_customer_ref' => substr( sanitize_text_field( (string) ( $reference['customer_ref'] ?? '' ) ), 0, 100 ),
				'gateway_method_ref' => substr( sanitize_text_field( (string) $reference['method_ref'] ), 0, 100 ),
				'gateway_mode' => self::normalize_mode( $adapter->current_mode() ),
			),
			array( 'id' => $license_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Remove the gateway references of a license.
	 *
	 * For code that ends automatic renewal for good or erases personal data:
	 * the references identify the customer at the gateway.
	 *
	 * @param int $license_id License ID.
	 * @return void
	 */
	public static function clear( int $license_id ): void {
		global $wpdb;

		if ( ! $license_id ) {
			return;
		}
		$tables = Schema::table_names();
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET gateway_customer_ref = NULL, gateway_method_ref = NULL, gateway_mode = NULL WHERE id = %d',
				$tables['licenses'],
				$license_id
			)
		);
	}

	/**
	 * Decide which token a due renewal is charged with.
	 *
	 * Pure decision; see resolve_token() for the inputs.
	 *
	 * @param string $stored_mode Mode stored on the license, empty when unknown.
	 * @param string $current_mode Mode the gateway runs in now, empty when unknown.
	 * @param bool   $stored_token_usable Whether the stored token ID still is a chargeable token of the customer.
	 * @param string $stored_method_ref Payment method reference stored on the license.
	 * @param int    $token_by_reference Token found for that reference, zero when none.
	 * @return string `stored_token`, `token_by_reference`, `mode_mismatch` or `missing`.
	 */
	public static function decide( string $stored_mode, string $current_mode, bool $stored_token_usable, string $stored_method_ref, int $token_by_reference ): string {
		if ( '' !== $stored_mode && '' !== $current_mode && $stored_mode !== $current_mode ) {
			return 'mode_mismatch';
		}
		if ( $stored_token_usable ) {
			return 'stored_token';
		}
		if ( '' !== $stored_method_ref && $token_by_reference > 0 ) {
			return 'token_by_reference';
		}

		return 'missing';
	}

	/**
	 * Resolve the token of a due renewal.
	 *
	 * - The gateway runs in another mode than at purchase: refused. A live
	 *   payment method does not exist in test mode and the other way round.
	 * - The stored token still exists: it is used. That also covers a card the
	 *   customer chose later in the account.
	 * - The token row is gone: the customer's token with the stored payment
	 *   method reference is used and the license is linked to it.
	 * - Otherwise the payment method is missing. There is no fallback to the
	 *   customer's default card: the customer did not agree to that card (B-78).
	 *
	 * A refusal is a configuration problem, not a declined payment: no attempt
	 * is counted and no dunning mail is sent.
	 *
	 * @param RecurringGatewayAdapter $adapter Adapter of the license's gateway.
	 * @param array<string,mixed>     $license License row.
	 * @return int|\WP_Error Token ID, or an error with code `renewal_configuration_error` whose
	 *                       data holds `reason` (`renewal_gateway_mode_mismatch` or
	 *                       `renewal_payment_method_missing`).
	 */
	public static function resolve_token( RecurringGatewayAdapter $adapter, array $license ) {
		global $wpdb;

		$license_id = (int) ( $license['id'] ?? 0 );
		$customer_id = (int) ( $license['customer_id'] ?? 0 );
		$token_id = absint( $license['payment_token_id'] ?? 0 );
		$method_ref = (string) ( $license['gateway_method_ref'] ?? '' );
		$stored_mode = self::normalize_mode( (string) ( $license['gateway_mode'] ?? '' ) );
		$knows_references = $adapter instanceof PaymentReferenceAdapter;
		$current_mode = $knows_references ? self::normalize_mode( $adapter->current_mode() ) : '';

		$usable = self::is_usable( $adapter, $token_id, $customer_id );
		$by_reference = 0;
		if ( ! $usable && $knows_references && '' !== $method_ref && ( '' === $stored_mode || '' === $current_mode || $stored_mode === $current_mode ) ) {
			$by_reference = $adapter->find_token( $customer_id, $method_ref );
			if ( $by_reference && ! self::is_usable( $adapter, $by_reference, $customer_id ) ) {
				$by_reference = 0;
			}
		}

		$decision = self::decide( (string) $stored_mode, (string) $current_mode, $usable, $method_ref, $by_reference );
		if ( 'mode_mismatch' === $decision ) {
			return new \WP_Error(
				self::CONFIGURATION_ERROR,
				sprintf(
					/* translators: 1: mode at purchase (live or test), 2: current mode. */
					__( 'The payment gateway runs in %2$s mode, but this license was bought in %1$s mode. The renewal is not charged.', 'wp-license' ),
					$stored_mode,
					$current_mode
				),
				array( 'reason' => 'renewal_gateway_mode_mismatch' )
			);
		}
		if ( 'missing' === $decision ) {
			return new \WP_Error(
				self::CONFIGURATION_ERROR,
				__( 'The payment method of this license no longer exists in the shop. The renewal is not charged.', 'wp-license' ),
				array( 'reason' => 'renewal_payment_method_missing' )
			);
		}

		$resolved = 'stored_token' === $decision ? $token_id : $by_reference;
		if ( $knows_references && $license_id ) {
			$tables = Schema::table_names();
			if ( 'token_by_reference' === $decision ) {
				// The gateway recreated the row: link the license to the new one.
				$wpdb->query(
					$wpdb->prepare(
						'UPDATE %i SET payment_token_id = %d, updated_at = %s WHERE id = %d AND renewal_enabled = 1 AND payment_token_id = %d',
						$tables['licenses'],
						$resolved,
						gmdate( 'Y-m-d H:i:s' ),
						$license_id,
						$token_id
					)
				);
				AuditLog::record( 'payment_method_relinked', 'license', $license_id, 'payment_token_recreated', array( 'payment_token_id' => $token_id ), array( 'payment_token_id' => $resolved ) );
			} else {
				$reference = $adapter->token_reference( $resolved, $customer_id );
				if ( '' !== (string) ( $reference['method_ref'] ?? '' ) && ( $reference['method_ref'] !== $method_ref || ( '' === $stored_mode && '' !== $current_mode ) ) ) {
					// Bought before the references existed, or moved to another card in the account.
					self::store( $license_id, $adapter->gateway_id(), $resolved, $customer_id );
				}
			}
		}

		return $resolved;
	}

	/**
	 * Tell the shop and listeners that a renewal has no payment method (B-78).
	 *
	 * Writes a note on the order the license came from, once per cause, and
	 * fires `wplc/renewal_payment_method_missing`. Informing the customer is
	 * up to the listener.
	 *
	 * @param array<string,mixed> $license License row.
	 * @param int                 $renewal_id Renewal ID.
	 * @param \WP_Error           $error Error from resolve_token().
	 * @param bool                $first Whether this cause is reported for the first time.
	 * @return void
	 */
	public static function report_unresolved( array $license, int $renewal_id, \WP_Error $error, bool $first ): void {
		$license_id = (int) ( $license['id'] ?? 0 );
		$reason = self::reason( $error );
		if ( $first ) {
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( absint( $license['source_order_id'] ?? 0 ) ) : false;
			if ( $order instanceof \WC_Order ) {
				$order->add_order_note(
					sprintf(
						/* translators: 1: license ID, 2: reason. */
						__( 'WP License Captain: the automatic renewal of license #%1$d was not charged. %2$s', 'wp-license' ),
						$license_id,
						$error->get_error_message()
					)
				);
			}
		}
		if ( 'renewal_payment_method_missing' !== $reason ) {
			return;
		}
		/**
		 * Fires when a due renewal has no usable payment method.
		 *
		 * Nothing was charged and no failed attempt was counted. The plugin does
		 * not fall back to another card of the customer.
		 *
		 * @param int  $license_id License ID.
		 * @param int  $renewal_id Renewal ID.
		 * @param int  $customer_id Customer ID.
		 * @param bool $first True the first time this is noticed for the renewal.
		 */
		do_action( 'wplc/renewal_payment_method_missing', $license_id, $renewal_id, (int) ( $license['customer_id'] ?? 0 ), $first );
	}

	/**
	 * Reason code of a configuration error, for the renewal row.
	 *
	 * @param \WP_Error $error Error.
	 * @return string
	 */
	public static function reason( \WP_Error $error ): string {
		$data = $error->get_error_data();
		$reason = is_array( $data ) ? sanitize_key( (string) ( $data['reason'] ?? '' ) ) : '';

		return '' !== $reason ? $reason : sanitize_key( (string) $error->get_error_code() );
	}

	/**
	 * Whether a token exists, belongs to the customer and can be charged.
	 *
	 * @param RecurringGatewayAdapter $adapter Adapter.
	 * @param int                     $token_id Token ID.
	 * @param int                     $customer_id Customer ID.
	 * @return bool
	 */
	private static function is_usable( RecurringGatewayAdapter $adapter, int $token_id, int $customer_id ): bool {
		if ( ! $token_id || ! $customer_id || ! class_exists( 'WC_Payment_Tokens' ) ) {
			return false;
		}
		$token = \WC_Payment_Tokens::get( $token_id );

		return $token && (int) $token->get_user_id() === $customer_id && $adapter->supports_saved_token( $token_id, $customer_id );
	}

	/**
	 * Reduce a mode to `live`, `test` or an empty string.
	 *
	 * @param string $mode Raw value.
	 * @return string
	 */
	public static function normalize_mode( string $mode ): string {
		$mode = strtolower( trim( $mode ) );

		return in_array( $mode, array( 'live', 'test' ), true ) ? $mode : '';
	}
}
