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
	 * Convert a plain decimal amount to integer minor units using half-up rounding.
	 *
	 * Supports non-negative decimal strings with at most 12 whole digits and
	 * precision from zero to six. Invalid/overflowing values return false.
	 *
	 * @param string $amount Decimal amount from a WooCommerce product.
	 * @param int $decimals Currency precision.
	 * @return int|false
	 */
	public static function to_minor_units( string $amount, int $decimals ) {
		$decimals = max( 0, min( 6, $decimals ) );
		if ( ! preg_match( '/^(\\d{1,12})(?:\\.(\\d+))?$/', trim( $amount ), $matches ) ) {
			return false;
		}

		$whole = ltrim( $matches[1], '0' );
		$whole = '' === $whole ? '0' : $whole;
		$fraction = $matches[2] ?? '';
		$kept_fraction = substr( str_pad( $fraction, $decimals, '0' ), 0, $decimals );
		$scaled = $whole . $kept_fraction;
		$scaled = ltrim( $scaled, '0' );
		$scaled = '' === $scaled ? '0' : $scaled;

		if ( strlen( $fraction ) > $decimals && (int) $fraction[ $decimals ] >= 5 ) {
			$digits = str_split( $scaled );
			$carry = true;
			for ( $index = count( $digits ) - 1; $index >= 0; --$index ) {
				if ( '9' === $digits[ $index ] ) {
					$digits[ $index ] = '0';
					continue;
				}
				$digits[ $index ] = (string) ( (int) $digits[ $index ] + 1 );
				$carry = false;
				break;
			}
			$scaled = ( $carry ? '1' : '' ) . implode( '', $digits );
		}

		$max = (string) PHP_INT_MAX;
		if ( strlen( $scaled ) > strlen( $max ) || ( strlen( $scaled ) === strlen( $max ) && strcmp( $scaled, $max ) > 0 ) ) {
			return false;
		}

		return (int) $scaled;
	}

	/**
	 * Calculate a half-up prorated minor-unit amount using integer arithmetic.
	 *
	 * @param int $full_period_amount Full-period amount in minor units.
	 * @param int $remaining_seconds Remaining seconds in the paid period.
	 * @param int $total_seconds Full period length in seconds.
	 * @return int|false False for invalid inputs or multiplication overflow.
	 */
	public static function prorate( int $full_period_amount, int $remaining_seconds, int $total_seconds ) {
		if ( $full_period_amount < 0 || $remaining_seconds < 0 || $total_seconds <= 0 ) {
			return false;
		}
		$remaining_seconds = min( $remaining_seconds, $total_seconds );
		if ( 0 === $full_period_amount || 0 === $remaining_seconds ) {
			return 0;
		}
		if ( $remaining_seconds === $total_seconds ) {
			return $full_period_amount;
		}
		if ( $remaining_seconds > intdiv( PHP_INT_MAX, $full_period_amount ) ) {
			return false;
		}

		$product = $full_period_amount * $remaining_seconds;
		$amount = intdiv( $product, $total_seconds );
		$remainder = $product % $total_seconds;
		$half_up_threshold = intdiv( $total_seconds, 2 ) + ( $total_seconds % 2 );

		return $amount + ( $remainder >= $half_up_threshold ? 1 : 0 );
	}

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
