<?php
/**
 * Currency-safe amount formatting for WooCommerce order snapshots.
 *
 * @package WPLC
 */

namespace WPLC\Billing;

/**
 * Converts stored integer minor units using their persisted decimal precision.
 */
final class Money {
	/**
	 * Format an integer amount as a decimal string without binary float arithmetic.
	 *
	 * @param int $minor Amount in minor units.
	 * @param int $decimals Currency precision captured when the amount was stored.
	 * @return string
	 */
	public static function to_decimal( int $minor, int $decimals ): string {
		$decimals = max( 0, min( 6, $decimals ) );
		$amount = (string) $minor;
		$negative = 0 === strpos( $amount, '-' );
		$digits = $negative ? substr( $amount, 1 ) : $amount;
		$digits = '' === $digits ? '0' : $digits;

		if ( 0 === $decimals ) {
			return ( $negative ? '-' : '' ) . $digits;
		}

		$digits = str_pad( $digits, $decimals + 1, '0', STR_PAD_LEFT );
		$whole = substr( $digits, 0, -$decimals );
		$fraction = substr( $digits, -$decimals );

		return ( $negative ? '-' : '' ) . $whole . '.' . $fraction;
	}
}
