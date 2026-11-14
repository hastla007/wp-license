<?php
/**
 * Terms of the next automatic renewal as shown to the customer.
 *
 * @package WPLC
 */

namespace WPLC\Notifications;

use WPLC\Billing\Money;
use WPLC\Infrastructure\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Calculates what the customer will be charged at the next renewal: date,
 * amount including tax and interval.
 *
 * The amount mirrors RenewalOrder::set_line(): the stored renewal amount is a
 * catalog price (gross when the shop enters prices including tax, net
 * otherwise), tax is calculated for the customer's tax location. It is an
 * estimate for display; the renewal order is the binding calculation.
 */
final class RenewalTerms {

	/**
	 * Amount of the next renewal including tax.
	 *
	 * @param array<string,mixed> $license License row.
	 * @return array{minor:int,tax_minor:int,decimals:int,currency:string,includes_tax:bool}
	 */
	public static function gross( array $license ): array {
		$fallback = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;
		$decimals = max( 0, min( 6, (int) ( $license['renewal_currency_decimals'] ?? $fallback ) ) );
		$minor    = max( 0, (int) ( $license['renewal_amount_minor'] ?? 0 ) );
		$result   = array(
			'minor'        => $minor,
			'tax_minor'    => 0,
			'decimals'     => $decimals,
			'currency'     => strtoupper( (string) ( $license['renewal_currency'] ?? '' ) ),
			'includes_tax' => false,
		);
		if ( $minor <= 0 || ! function_exists( 'wc_tax_enabled' ) || ! wc_tax_enabled() || ! class_exists( '\\WC_Tax' ) ) {
			return self::filtered( $result, $license );
		}

		$product = self::product( $license );
		if ( ! $product || ! $product->is_taxable() ) {
			return self::filtered( $result, $license );
		}

		$catalog = Money::to_decimal( $minor, $decimals );
		$net     = (float) wc_get_price_excluding_tax(
			$product,
			array(
				'qty'   => 1,
				'price' => $catalog,
			)
		);
		$rates   = self::tax_rates( $license, (string) $product->get_tax_class() );
		$tax     = 0.0;
		foreach ( \WC_Tax::calc_tax( $net, $rates, false ) as $amount ) {
			$tax += (float) wc_round_tax_total( $amount, $decimals );
		}

		$gross_minor = Money::to_minor_units( number_format( $net + $tax, $decimals, '.', '' ), $decimals );
		$tax_minor   = Money::to_minor_units( number_format( $tax, $decimals, '.', '' ), $decimals );
		if ( false === $gross_minor || false === $tax_minor ) {
			return self::filtered( $result, $license );
		}

		$result['minor']        = (int) $gross_minor;
		$result['tax_minor']    = (int) $tax_minor;
		$result['includes_tax'] = $tax_minor > 0;

		return self::filtered( $result, $license );
	}

	/**
	 * Renewal amount of a catalog plan as the shop displays prices in cart and checkout.
	 *
	 * For the product page, the cart and the consent text, where no license exists
	 * yet: the plan's regular price for the product that is bought, including or
	 * excluding tax by the shop setting "display prices during cart and checkout".
	 *
	 * @param array<string,mixed> $mapping Plan row (price_minor, price_decimals, currency).
	 * @param mixed               $product WC_Product that is bought, for the tax class.
	 * @return array{minor:int,tax_minor:int,decimals:int,currency:string,includes_tax:bool,tax_label:string}
	 */
	public static function plan_amount( array $mapping, $product ): array {
		$decimals = max( 0, min( 6, (int) ( $mapping['price_decimals'] ?? 2 ) ) );
		$minor    = max( 0, (int) ( $mapping['price_minor'] ?? 0 ) );
		$result   = array(
			'minor'        => $minor,
			'tax_minor'    => 0,
			'decimals'     => $decimals,
			'currency'     => (string) ( $mapping['currency'] ?? '' ),
			'includes_tax' => false,
			'tax_label'    => '',
		);
		if ( ! is_object( $product ) || ! function_exists( 'wc_tax_enabled' ) || ! wc_tax_enabled() ) {
			return $result;
		}

		$catalog   = (float) Money::to_decimal( $minor, $decimals );
		// The shop setting for cart and checkout; it also holds where no cart exists (emails, admin).
		$including = 'incl' === get_option( 'woocommerce_tax_display_cart' );
		$amount    = (float) ( $including
			? wc_get_price_including_tax( $product, array( 'price' => $catalog ) )
			: wc_get_price_excluding_tax( $product, array( 'price' => $catalog ) ) );
		$shown     = Money::to_minor_units( number_format( $amount, $decimals, '.', '' ), $decimals );
		if ( false !== $shown ) {
			$result['minor'] = (int) $shown;
		}
		$result['includes_tax'] = $including;
		$result['tax_label']    = trim( (string) ( $including ? WC()->countries->inc_tax_or_vat() : WC()->countries->ex_tax_or_vat() ) );

		return $result;
	}

	/**
	 * "59,00 € per year (incl. VAT)": an amount of plan_amount() or gross() with its interval.
	 *
	 * @param array<string,mixed> $amount   Result of plan_amount() or gross().
	 * @param string              $interval Billing interval (`month`, anything else is a year).
	 * @return string
	 */
	public static function per_interval_text( array $amount, string $interval ): string {
		$price = Placeholders::plain( self::price_html( $amount ) );
		if ( '' === $price && function_exists( 'wc_price' ) ) {
			// A free plan is still named with its price.
			$price = Placeholders::plain( (string) wc_price( 0, array( 'currency' => (string) ( $amount['currency'] ?? '' ) ) ) );
		}
		$text = 'month' === $interval
			/* translators: %s: price, for example "9,90 €". */
			? sprintf( __( '%s per month', 'wp-license' ), $price )
			/* translators: %s: price, for example "59,00 €". */
			: sprintf( __( '%s per year', 'wp-license' ), $price );
		$label = trim( (string) ( $amount['tax_label'] ?? '' ) );

		return '' === $label ? $text : $text . ' ' . $label;
	}

	/**
	 * Let integrations (VAT exemption, external tax services) correct the estimate.
	 *
	 * @param array<string,mixed> $result  Calculated amount.
	 * @param array<string,mixed> $license License row.
	 * @return array{minor:int,tax_minor:int,decimals:int,currency:string,includes_tax:bool}
	 */
	private static function filtered( array $result, array $license ): array {
		/**
		 * Filter the displayed amount of the next renewal.
		 *
		 * @param array<string,mixed> $result  Keys: minor, tax_minor, decimals, currency, includes_tax.
		 * @param array<string,mixed> $license License row.
		 */
		$filtered = apply_filters( 'wplc_renewal_display_amount', $result, $license );
		if ( ! is_array( $filtered ) ) {
			return $result;
		}

		return array(
			'minor'        => max( 0, (int) ( $filtered['minor'] ?? $result['minor'] ) ),
			'tax_minor'    => max( 0, (int) ( $filtered['tax_minor'] ?? $result['tax_minor'] ) ),
			'decimals'     => max( 0, min( 6, (int) ( $filtered['decimals'] ?? $result['decimals'] ) ) ),
			'currency'     => (string) ( $filtered['currency'] ?? $result['currency'] ),
			'includes_tax' => ! empty( $filtered['includes_tax'] ),
		);
	}

	/**
	 * Format an amount of gross() with the shop's price format.
	 *
	 * @param array<string,mixed> $gross Result of gross().
	 * @return string HTML from wc_price(), or an empty string without an amount.
	 */
	public static function price_html( array $gross ): string {
		if ( (int) ( $gross['minor'] ?? 0 ) <= 0 || ! function_exists( 'wc_price' ) ) {
			return '';
		}
		$decimals = (int) ( $gross['decimals'] ?? 2 );

		return (string) wc_price(
			Money::to_decimal( (int) $gross['minor'], $decimals ),
			array(
				'currency' => (string) ( $gross['currency'] ?? '' ),
				'decimals' => $decimals,
			)
		);
	}

	/**
	 * Plain-text amount, with "incl. tax" when tax is part of it.
	 *
	 * @param array<string,mixed> $gross Result of gross().
	 * @return string Empty without an amount.
	 */
	public static function amount_text( array $gross ): string {
		$amount = Placeholders::plain( self::price_html( $gross ) );
		if ( '' === $amount ) {
			return '';
		}
		if ( empty( $gross['includes_tax'] ) ) {
			return $amount;
		}

		/* translators: %s: formatted amount. */
		return sprintf( __( '%s incl. tax', 'wp-license' ), $amount );
	}

	/**
	 * Unix timestamp of the next automatic charge, zero when unknown.
	 *
	 * @param array<string,mixed> $license License row.
	 * @return int
	 */
	public static function charge_timestamp( array $license ): int {
		foreach ( array( 'next_payment_at', 'paid_until' ) as $column ) {
			if ( ! empty( $license[ $column ] ) ) {
				$timestamp = strtotime( (string) $license[ $column ] . ' UTC' );
				if ( false !== $timestamp && $timestamp > 0 ) {
					return (int) $timestamp;
				}
			}
		}
		return 0;
	}

	/**
	 * Translated billing interval, e.g. "every year".
	 *
	 * @param string $interval Billing interval of the license.
	 * @return string
	 */
	public static function interval_label( string $interval ): string {
		return 'month' === $interval ? __( 'every month', 'wp-license' ) : __( 'every year', 'wp-license' );
	}

	/**
	 * Sentence that tells the customer how to cancel.
	 *
	 * @return string
	 */
	public static function cancel_text(): string {
		return __( 'You can cancel the automatic renewal at any time before the renewal date in your account under "Licenses". The license then stays valid until the end of the paid period and is not charged again.', 'wp-license' );
	}

	/**
	 * The renewal terms as label => value rows for emails and the account.
	 *
	 * @param array<string,mixed> $license License row.
	 * @return array<string,string> Empty when the license does not renew automatically.
	 */
	public static function rows( array $license ): array {
		if ( ! ReminderSchedule::renews_automatically( $license ) ) {
			return array();
		}

		$charge_at = self::charge_timestamp( $license );
		$rows      = array(
			__( 'Next renewal', 'wp-license' ) => $charge_at > 0 ? Notifications::format_date( $charge_at ) : '',
			__( 'Amount', 'wp-license' )       => self::amount_text( self::gross( $license ) ),
			__( 'Interval', 'wp-license' )     => self::interval_label( (string) ( $license['billing_interval'] ?? 'year' ) ),
		);

		return array_filter( $rows, 'strlen' );
	}

	/**
	 * Product the renewal is billed for: the purchased product, else the plan's product.
	 *
	 * @param array<string,mixed> $license License row.
	 * @return \WC_Product|null
	 */
	private static function product( array $license ) {
		global $wpdb;

		if ( ! function_exists( 'wc_get_order' ) || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}

		$order   = empty( $license['source_order_id'] ) ? false : wc_get_order( (int) $license['source_order_id'] );
		$item    = $order instanceof \WC_Order ? $order->get_item( (int) ( $license['source_order_item_id'] ?? 0 ) ) : false;
		$product = $item && is_callable( array( $item, 'get_product' ) ) ? $item->get_product() : false;
		if ( $product instanceof \WC_Product ) {
			return $product;
		}

		$tables = Schema::table_names();
		$plan   = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT woocommerce_product_id, woocommerce_variation_id FROM %i WHERE active = 1 AND product_key = %s AND plan_key = %s ORDER BY id DESC LIMIT 1',
				$tables['plans'],
				(string) ( $license['product_key'] ?? '' ),
				(string) ( $license['plan_key'] ?? '' )
			),
			ARRAY_A
		);
		if ( ! is_array( $plan ) ) {
			return null;
		}
		$product_id = (int) $plan['woocommerce_variation_id'] ? (int) $plan['woocommerce_variation_id'] : (int) $plan['woocommerce_product_id'];
		$product    = $product_id > 0 ? wc_get_product( $product_id ) : false;

		return $product instanceof \WC_Product ? $product : null;
	}

	/**
	 * Tax rates for the location a renewal order of this license would use.
	 *
	 * Same source of the address as RenewalOrder::set_addresses(): the customer's
	 * current addresses, else those of the order the license was bought with.
	 *
	 * @param array<string,mixed> $license   License row.
	 * @param string              $tax_class Tax class of the product.
	 * @return array<int,array<string,mixed>>
	 */
	private static function tax_rates( array $license, string $tax_class ): array {
		$billing  = array();
		$shipping = array();
		if ( class_exists( '\\WC_Customer' ) && ! empty( $license['customer_id'] ) ) {
			try {
				$customer = new \WC_Customer( (int) $license['customer_id'] );
				$billing  = (array) $customer->get_billing();
				$shipping = (array) $customer->get_shipping();
			} catch ( \Exception $exception ) {
				$billing = array();
			}
		}
		if ( empty( $billing['country'] ) && ! empty( $license['source_order_id'] ) ) {
			$order = wc_get_order( (int) $license['source_order_id'] );
			if ( $order instanceof \WC_Order ) {
				$billing  = (array) $order->get_address( 'billing' );
				$shipping = (array) $order->get_address( 'shipping' );
			}
		}

		$based_on = (string) get_option( 'woocommerce_tax_based_on', 'shipping' );
		$address  = 'shipping' === $based_on && ! empty( $shipping['country'] ) ? $shipping : $billing;
		if ( 'base' === $based_on || empty( $address['country'] ) ) {
			return \WC_Tax::get_base_tax_rates( $tax_class );
		}

		return \WC_Tax::find_rates(
			array(
				'country'   => (string) $address['country'],
				'state'     => (string) ( $address['state'] ?? '' ),
				'postcode'  => (string) ( $address['postcode'] ?? '' ),
				'city'      => (string) ( $address['city'] ?? '' ),
				'tax_class' => $tax_class,
			)
		);
	}
}
