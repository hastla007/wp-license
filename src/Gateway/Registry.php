<?php
/**
 * Registry for automatic renewal payment adapters.
 *
 * @package WPLC
 */

namespace WPLC\Gateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Locates the gateway adapter for a WooCommerce payment method.
 */
final class Registry {

	/**
	 * Return an adapter registered by the corresponding gateway integration.
	 *
	 * @param string $gateway_id WooCommerce payment method ID.
	 * @return RecurringGatewayAdapter|null
	 */
	public static function get( string $gateway_id ): ?RecurringGatewayAdapter {
		$adapters = apply_filters( 'wplc_recurring_gateway_adapters', array() );
		if ( ! is_array( $adapters ) ) {
			return null;
		}

		foreach ( $adapters as $adapter ) {
			if ( $adapter instanceof RecurringGatewayAdapter && $adapter->gateway_id() === $gateway_id ) {
				return $adapter;
			}
		}

		return null;
	}

	/**
	 * Whether a gateway is ready to collect explicit off-session consent.
	 *
	 * @param string $gateway_id Gateway ID.
	 * @return bool
	 */
	public static function supports_checkout( string $gateway_id ): bool {
		$adapter = self::get( $gateway_id );
		return $adapter && $adapter->supports_checkout();
	}

	/**
	 * Whether any registered adapter is ready, whatever the customer picks later.
	 *
	 * Used where the cart and the chosen payment method are not known yet.
	 *
	 * @return bool
	 */
	public static function any_supports_checkout(): bool {
		$adapters = apply_filters( 'wplc_recurring_gateway_adapters', array() );
		if ( ! is_array( $adapters ) ) {
			return false;
		}

		foreach ( $adapters as $adapter ) {
			if ( $adapter instanceof RecurringGatewayAdapter && $adapter->supports_checkout() ) {
				return true;
			}
		}

		return false;
	}
}
