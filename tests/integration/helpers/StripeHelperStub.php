<?php
/**
 * Test double for the one WooCommerce Stripe gateway helper the reconciler calls.
 *
 * Loaded only when the real gateway plugin is absent.
 *
 * @package WPLC
 */

if ( ! class_exists( 'WC_Stripe_Helper' ) ) {
	/**
	 * Mirrors WC_Stripe_Helper::get_stripe_amount() for two-decimal and zero-decimal currencies.
	 */
	class WC_Stripe_Helper { // phpcs:ignore Generic.Classes.OpeningBraceSameLine, Universal.Files.SeparateFunctionsFromOO
		/**
		 * Convert an order total to Stripe's minor units.
		 *
		 * @param string|float $total    Order total.
		 * @param string       $currency Currency code.
		 * @return int
		 */
		public static function get_stripe_amount( $total, $currency = '' ) {
			$zero_decimal = array( 'bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf' );
			if ( in_array( strtolower( (string) $currency ), $zero_decimal, true ) ) {
				return absint( $total );
			}

			return absint( wc_format_decimal( ( (float) $total * 100 ), wc_get_price_decimals() ) );
		}
	}
}
