<?php
/**
 * Flow 7c: tax correctness of renewal orders.
 *
 * One tax rate (DE 20 %). For prices entered INCLUDING and EXCLUDING tax the renewal order
 * (manual and automatic) must cost exactly what a normal purchase of the product costs
 * for the same customer. Checked for several prices to catch rounding differences.
 *
 * @package WPLC
 */

require getenv( 'WPLC_E2E_DIR' ) . '/lib.php';

global $wpdb;
$t        = e2e_tables();
$utc      = static function ( int $timestamp ) {
	return gmdate( 'Y-m-d H:i:s', $timestamp );
};

// --- Tax setup: one standard rate of 20 % for Germany. ---
update_option( 'woocommerce_calc_taxes', 'yes' );
update_option( 'woocommerce_tax_based_on', 'billing' );
update_option( 'woocommerce_tax_display_cart', 'incl' );
if ( ! $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_country = 'DE'" ) ) {
	WC_Tax::_insert_tax_rate(
		array(
			'tax_rate_country'  => 'DE',
			'tax_rate_state'    => '',
			'tax_rate'          => '20.0000',
			'tax_rate_name'     => 'VAT',
			'tax_rate_priority' => 1,
			'tax_rate_compound' => 0,
			'tax_rate_shipping' => 1,
			'tax_rate_order'    => 1,
			'tax_rate_class'    => '',
		)
	);
}
WC_Cache_Helper::invalidate_cache_group( 'taxes' );

$german_id  = e2e_customer( 'frank' );
$foreign_id = e2e_customer( 'grace' );
$foreign    = new WC_Customer( $foreign_id );
$foreign->set_billing_country( 'US' );
$foreign->set_billing_state( 'CA' );
$foreign->set_billing_postcode( '94103' );
$foreign->set_billing_city( 'San Francisco' );
$foreign->save();

/**
 * A license product with the given price, mapped through the catalog like the editor does.
 */
$make_product = static function ( string $price, string $mode ) {
	$product = new WC_Product_Simple();
	$product->set_name( 'Tax Captain ' . $price . ' ' . $mode );
	$product->set_sku( 'TAX-' . $mode . '-' . str_replace( '.', '', $price ) . '-' . wp_rand( 1000, 9999 ) );
	$product->set_regular_price( $price );
	$product->set_virtual( true );
	$product->set_tax_status( 'taxable' );
	$product->set_status( 'publish' );
	$product->update_meta_data( '_wplc_is_license_product', 'yes' );
	$id = $product->save();
	\WPLC\Catalog\Catalog::save_mapping(
		$id,
		0,
		array(
			'product_key'      => 'tax-captain',
			'plan_key'         => 'p' . str_replace( '.', '', $price ) . $mode . $id,
			'billing_interval' => 'year',
			'live_site_limit'  => 1,
			'support_level'    => 'standard',
			'entitlements'     => array(),
		)
	);
	return $id;
};

$prices = array( '49.00', '19.90', '99.00', '9.99', '14.95', '29.99', '0.99', '119.00', '1234.56' );
$modes  = array(
	'incl' => 'yes',
	'excl' => 'no',
);

foreach ( $modes as $mode => $include ) {
	update_option( 'woocommerce_prices_include_tax', $include );
	WC_Cache_Helper::invalidate_cache_group( 'taxes' );
	$label = 'yes' === $include ? 'prices INCLUDING tax' : 'prices EXCLUDING tax';

	$mismatch = array();
	$checked  = 0;
	$sample   = array();
	foreach ( $prices as $price ) {
		$product_id = $make_product( $price, $mode );
		foreach ( array( 'DE' => $german_id, 'US' => $foreign_id ) as $country => $customer_id ) {
			// Normal purchase through the cart/checkout.
			$purchase = e2e_checkout( $customer_id, array( $product_id => 1 ), 'bacs', false );
			$license  = e2e_order_licenses( (int) $purchase->get_id() );
			if ( 1 !== count( $license ) ) {
				$mismatch[] = "{$price}/{$country}: no license issued";
				continue;
			}
			$license_id = (int) $license[0]['id'];
			// [time simulated] 10 days before the end; the customer renews manually.
			e2e_move_paid_until( $license_id, $utc( time() + 10 * DAY_IN_SECONDS ) );
			$renewal_order = \WPLC\Billing\ManualRenewal::start( $license_id, $customer_id );
			if ( is_wp_error( $renewal_order ) ) {
				$mismatch[] = "{$price}/{$country}: " . $renewal_order->get_error_code();
				continue;
			}
			++$checked;
			$row = array(
				'price'          => $price,
				'country'        => $country,
				'purchase_total' => wc_format_decimal( $purchase->get_total(), 2 ),
				'purchase_tax'   => wc_format_decimal( $purchase->get_total_tax(), 2 ),
				'renewal_total'  => wc_format_decimal( $renewal_order->get_total(), 2 ),
				'renewal_tax'    => wc_format_decimal( $renewal_order->get_total_tax(), 2 ),
			);
			if ( $row['purchase_total'] !== $row['renewal_total'] || $row['purchase_tax'] !== $row['renewal_tax'] ) {
				$mismatch[] = $row;
			}
			if ( '49.00' === $price ) {
				$sample[ $country ] = $row;
				// Paying it must be accepted by the net-price check and extend the license.
				$renewal_order->set_payment_method( 'bacs' );
				$renewal_order->payment_complete( 'e2e-tax-' . $renewal_order->get_id() );
				$after = e2e_license( $license_id );
				e2e_ok( 'active' === $after['status'] && strtotime( $after['paid_until'] . ' UTC' ) > time() + 300 * DAY_IN_SECONDS && 'yes' !== wc_get_order( $renewal_order->get_id() )->get_meta( '_wplc_renewal_review', true ), "{$label}, customer {$country}: the paid renewal order (49.00) passes the net-price check and extends the license", array( $after['status'], $after['paid_until'] ) );
			}
		}
	}
	e2e_note( $label . ': 49.00 → DE ' . wp_json_encode( $sample['DE'] ?? null ) . ' / US ' . wp_json_encode( $sample['US'] ?? null ) );
	e2e_eq( count( $prices ) * 2, $checked, "{$label}: " . ( count( $prices ) * 2 ) . ' purchase/renewal pairs compared (9 prices x customer in DE and in a country without tax rate)' );
	e2e_ok( ! $mismatch, "{$label}: manual renewal order total and tax equal a normal purchase for every price", $mismatch );
	if ( 'incl' === $mode ) {
		e2e_eq( array( '49.00', '8.17' ), array( $sample['DE']['renewal_total'] ?? null, $sample['DE']['renewal_tax'] ?? null ), 'including tax: German customer pays 49.00 with 8.17 tax on renewal' );
		e2e_eq( array( '40.83', '0.00' ), array( $sample['US']['renewal_total'] ?? null, $sample['US']['renewal_tax'] ?? null ), 'including tax: customer without tax rate pays the net 40.83 on renewal, like in the shop' );
	} else {
		e2e_eq( array( '58.80', '9.80' ), array( $sample['DE']['renewal_total'] ?? null, $sample['DE']['renewal_tax'] ?? null ), 'excluding tax: German customer pays 58.80 with 9.80 tax on renewal' );
		e2e_eq( array( '49.00', '0.00' ), array( $sample['US']['renewal_total'] ?? null, $sample['US']['renewal_tax'] ?? null ), 'excluding tax: customer without tax rate pays 49.00 on renewal' );
	}

	// Automatic renewal in this mode: the amount handed to the gateway.
	update_option( 'wplc_e2e_fake_mode', 'success' );
	update_option( 'wplc_e2e_fake_charges', array(), false );
	$auto_mismatch = array();
	foreach ( array( '49.00', '19.90', '9.99' ) as $price ) {
		$product_id = $make_product( $price, $mode . 'a' );
		$purchase   = e2e_checkout( $german_id, array( $product_id => 1 ), 'wplc_e2e_fake', true );
		$license    = e2e_order_licenses( (int) $purchase->get_id() )[0];
		$renewal_id = e2e_move_paid_until( (int) $license['id'], $utc( time() - HOUR_IN_SECONDS ) );
		e2e_run_action( 'wplc_process_renewal', array( $renewal_id ) );
		$charges = e2e_charges();
		$charge  = end( $charges );
		$renewal = e2e_renewal( $renewal_id );
		if ( 'paid' !== ( $renewal['status'] ?? '' ) || ! $charge || wc_format_decimal( $charge['total'], 2 ) !== wc_format_decimal( $purchase->get_total(), 2 ) ) {
			$auto_mismatch[] = array( 'price' => $price, 'purchase_total' => $purchase->get_total(), 'charged' => $charge ? $charge['total'] : null, 'renewal' => $renewal['status'] ?? null );
		}
	}
	e2e_ok( ! $auto_mismatch, "{$label}: the automatic renewal charges exactly the total of a normal purchase (3 prices, German customer)", $auto_mismatch );
}

// --- Price change in the shop: manual renewal uses today's plan price, automatic renewal the agreed one. ---
update_option( 'woocommerce_prices_include_tax', 'yes' );
WC_Cache_Helper::invalidate_cache_group( 'taxes' );
$product_id = $make_product( '50.00', 'chg' );
$manual     = e2e_order_licenses( (int) e2e_checkout( $german_id, array( $product_id => 1 ), 'bacs', false )->get_id() )[0];
$auto       = e2e_order_licenses( (int) e2e_checkout( $german_id, array( $product_id => 1 ), 'wplc_e2e_fake', true )->get_id() )[0];
$product    = wc_get_product( $product_id );
$product->set_regular_price( '60.00' );
$product->save();
$plan = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['plans']} WHERE woocommerce_product_id = %d", $product_id ), ARRAY_A );
\WPLC\Catalog\Catalog::save_mapping( $product_id, 0, array( 'product_key' => $plan['product_key'], 'plan_key' => $plan['plan_key'], 'billing_interval' => 'year', 'live_site_limit' => 1, 'support_level' => 'standard', 'entitlements' => array() ) );
e2e_move_paid_until( (int) $manual['id'], $utc( time() + 10 * DAY_IN_SECONDS ) );
$manual_order = \WPLC\Billing\ManualRenewal::start( (int) $manual['id'], $german_id );
e2e_eq( '60.00', is_wp_error( $manual_order ) ? $manual_order->get_error_code() : wc_format_decimal( $manual_order->get_total(), 2 ), 'after a price increase to 60.00 a manual renewal costs the current price' );
update_option( 'wplc_e2e_fake_charges', array(), false );
$renewal_id = e2e_move_paid_until( (int) $auto['id'], $utc( time() - HOUR_IN_SECONDS ) );
e2e_run_action( 'wplc_process_renewal', array( $renewal_id ) );
$charges = e2e_charges();
e2e_eq( '50.00', $charges ? wc_format_decimal( end( $charges )['total'], 2 ) : null, 'the automatic renewal still charges the price the customer agreed to (50.00)' );

// Back to a shop without taxes for the remaining flows.
update_option( 'woocommerce_prices_include_tax', 'no' );
update_option( 'woocommerce_calc_taxes', 'no' );
WC_Cache_Helper::invalidate_cache_group( 'taxes' );
