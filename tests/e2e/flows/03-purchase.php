<?php
/**
 * Flow 3: purchase → licenses, idempotent hooks, "license issued" email, forced registration, My Account.
 *
 * @package WPLC
 */

require getenv( 'WPLC_E2E_DIR' ) . '/lib.php';

global $wpdb;
$t             = e2e_tables();
$simple_id     = (int) e2e_get( 'simple_id' );
$plain_id      = (int) e2e_get( 'plain_id' );
$variation_ids = (array) e2e_get( 'variation_ids' );

// --- Customers. ---
$make_customer = static function ( string $login, string $email, string $first ) {
	$existing = get_user_by( 'login', $login );
	if ( $existing ) {
		return (int) $existing->ID;
	}
	$customer = new WC_Customer();
	$customer->set_username( $login );
	$customer->set_email( $email );
	$customer->set_password( 'customer-e2e-pass' );
	$customer->set_first_name( $first );
	$customer->set_last_name( 'Tester' );
	$customer->set_billing_first_name( $first );
	$customer->set_billing_last_name( 'Tester' );
	$customer->set_billing_email( $email );
	$customer->set_billing_address_1( 'Teststr. 1' );
	$customer->set_billing_city( 'Berlin' );
	$customer->set_billing_postcode( '10115' );
	$customer->set_billing_country( 'DE' );

	return (int) $customer->save();
};
$alice_id = $make_customer( 'alice', 'alice@customer.test', 'Alice' );
$bob_id   = $make_customer( 'bob', 'bob@customer.test', 'Bob' );
e2e_ok( $alice_id > 0 && $bob_id > 0, 'two customers created' );

// --- Guest checkout must register when a license product is in the cart. ---
wp_set_current_user( 0 );
wc_load_cart();
WC()->cart->empty_cart();
WC()->cart->add_to_cart( $plain_id, 1 );
e2e_eq( false, (bool) apply_filters( 'woocommerce_checkout_registration_required', false ), 'guest checkout stays possible for a cart without license products' );
WC()->cart->add_to_cart( $simple_id, 1 );
e2e_eq( true, (bool) apply_filters( 'woocommerce_checkout_registration_required', false ), 'filter woocommerce_checkout_registration_required is true with a license product in the cart' );
e2e_eq( true, WC()->checkout()->is_registration_required(), 'WC_Checkout::is_registration_required() is true for the guest' );
WC()->cart->empty_cart();
WC()->cart->add_to_cart( (int) $variation_ids[0], 1 );
e2e_eq( true, WC()->checkout()->is_registration_required(), 'registration is also required for a licensed variation in the cart' );
WC()->cart->empty_cart();

// --- An unpaid order issues nothing. ---
wplc_e2e_clear_mails();
$unpaid = e2e_create_order( $alice_id, array( $simple_id => 1 ), array( 'pay' => false ) );
$unpaid->update_status( 'on-hold' );
e2e_eq( 0, count( e2e_order_licenses( $unpaid->get_id() ) ), 'an on-hold (unpaid bank transfer) order issues no license' );
$unpaid->update_status( 'cancelled' );
e2e_eq( 0, count( e2e_order_licenses( $unpaid->get_id() ) ), 'a cancelled order issues no license' );

// --- Logged-in purchase with quantity 2 through the WooCommerce checkout object. ---
wplc_e2e_clear_mails();
wp_set_current_user( $alice_id );
WC()->customer = new WC_Customer( $alice_id, true );
WC()->cart->empty_cart();
WC()->cart->add_to_cart( $simple_id, 2 );
WC()->cart->calculate_totals();
$order_id = WC()->checkout()->create_order(
	array(
		'billing_first_name' => 'Alice',
		'billing_last_name'  => 'Tester',
		'billing_email'      => 'alice@customer.test',
		'billing_address_1'  => 'Teststr. 1',
		'billing_city'       => 'Berlin',
		'billing_postcode'   => '10115',
		'billing_country'    => 'DE',
		'payment_method'     => 'bacs',
		'order_comments'     => '',
	)
);
e2e_ok( is_int( $order_id ) && $order_id > 0, 'checkout created the order', is_wp_error( $order_id ) ? $order_id->get_error_message() : $order_id );
$order = wc_get_order( $order_id );
e2e_eq( $alice_id, (int) $order->get_customer_id(), 'order belongs to the customer' );
e2e_eq( '98.00', (string) $order->get_total(), 'order total 2 x 49.00 (no tax configured yet)' );
e2e_eq( 0, count( e2e_order_licenses( $order_id ) ), 'no license before payment' );

$order->payment_complete( 'e2e-bank-' . $order_id );
$order    = wc_get_order( $order_id );
$licenses = e2e_order_licenses( $order_id );
e2e_eq( 2, count( $licenses ), 'payment of quantity 2 issues exactly 2 licenses' );
e2e_eq( array( 'active', 'active' ), array_column( $licenses, 'status' ), 'both licenses are active' );
e2e_eq( array( '0', '1' ), array_column( $licenses, 'unit_index' ), 'unit_index 0 and 1' );
e2e_ok( $licenses[0]['license_key_hash'] !== $licenses[1]['license_key_hash'] && $licenses[0]['license_uuid'] !== $licenses[1]['license_uuid'], 'the two licenses have different keys' );
foreach ( $licenses as $index => $license ) {
	$key = e2e_key( $license );
	e2e_ok( 0 === strpos( $key, 'WPLC-' ) && hash_equals( $license['license_key_hash'], \WPLC\License\LicenseKey::hash( $key ) ), 'license ' . ( $index + 1 ) . ': derived key matches the stored hash' );
	e2e_eq(
		array( (string) $alice_id, 'voucher-captain', 'pro', 'year', '4900', 'EUR', '0', '', (string) $order_id ),
		array( $license['customer_id'], $license['product_key'], $license['plan_key'], $license['billing_interval'], $license['renewal_amount_minor'], $license['renewal_currency'], $license['renewal_enabled'], $license['payment_gateway_id'], $license['source_order_id'] ),
		'license ' . ( $index + 1 ) . ': customer, plan, renewal price 4900 EUR, no automatic renewal (bank transfer)'
	);
	$paid_until = strtotime( $license['paid_until'] . ' UTC' );
	e2e_ok( abs( $paid_until - strtotime( '+1 year' ) ) < 2 * DAY_IN_SECONDS, 'license ' . ( $index + 1 ) . ': paid_until is one year ahead', $license['paid_until'] );
	$entitlements = e2e_rows( 'entitlements', 'license_id = ' . (int) $license['id'] . ' ORDER BY entitlement_key' );
	e2e_eq(
		array( 'sites.live_limit', 'support.level', 'voucher.custom_branding', 'voucher.monthly_limit', 'voucher.pdf_templates' ),
		array_column( $entitlements, 'entitlement_key' ),
		'license ' . ( $index + 1 ) . ': 5 entitlement rows copied from the plan'
	);
	e2e_eq( array( 'active' ), array_values( array_unique( array_column( $entitlements, 'status' ) ) ), 'license ' . ( $index + 1 ) . ': entitlements are active' );
}
$item     = current( $order->get_items( 'line_item' ) );
$item_ids = array_map( 'intval', (array) $item->get_meta( '_wplc_license_ids', true ) );
e2e_eq( array_map( 'intval', array_column( $licenses, 'id' ) ), $item_ids, 'order item meta _wplc_license_ids lists both licenses' );
$notes = wc_get_order_notes( array( 'order_id' => $order_id ) );
$issued_notes = array_filter(
	$notes,
	static function ( $note ) {
		return false !== strpos( $note->content, 'WP License Captain issued license' );
	}
);
e2e_eq( 2, count( $issued_notes ), 'two "issued license" order notes' );

// --- Repeated hooks do not duplicate. ---
do_action( 'woocommerce_payment_complete', $order_id );
do_action( 'woocommerce_order_status_processing', $order_id );
$order->update_status( 'completed' );
do_action( 'woocommerce_order_status_completed', $order_id );
\WPLC\License\LicenseIssuer::maybe_issue_order( $order_id );
e2e_eq( 2, count( e2e_order_licenses( $order_id ) ), 'repeated payment/status hooks keep exactly 2 licenses' );
e2e_eq( 10, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['entitlements']} WHERE license_id IN (" . implode( ',', array_map( 'intval', array_column( $licenses, 'id' ) ) ) . ')' ), 'entitlement rows are not duplicated (10)' );

// --- Emails. ---
$mails  = wplc_e2e_mails();
$issued = array_values(
	array_filter(
		$mails,
		static function ( $mail ) {
			return false !== strpos( (string) $mail['subject'], 'license is ready' );
		}
	)
);
e2e_eq( 2, count( $issued ), 'exactly 2 "license issued" emails for 2 licenses, also after the repeated hooks', array_column( $mails, 'subject' ) );
if ( $issued ) {
	e2e_eq( 'alice@customer.test', is_array( $issued[0]['to'] ) ? implode( ',', $issued[0]['to'] ) : (string) $issued[0]['to'], 'issued email goes to the customer' );
	e2e_eq( 'Your Voucher Captain Pro license is ready', (string) $issued[0]['subject'], 'issued email subject names the product' );
	$bodies = implode( "\n", array_column( $issued, 'message' ) );
	$leaked = false;
	foreach ( $licenses as $license ) {
		if ( false !== strpos( $bodies, e2e_key( $license ) ) ) {
			$leaked = true;
		}
	}
	e2e_ok( ! $leaked, 'issued email does NOT contain the full license key by default' );
	e2e_ok( false !== strpos( $bodies, $licenses[0]['key_prefix'] ) && false !== strpos( $bodies, $licenses[1]['key_prefix'] ), 'issued emails show the key prefixes' );
	e2e_ok( false !== strpos( $bodies, 'wplc-licenses' ), 'issued email links to the account licenses page' );
	e2e_ok( false === strpos( $bodies, '{product}' ) && false === strpos( $bodies, '{site_title}' ) && ! preg_match( '/\{[a-z_]+\}/', (string) $issued[0]['subject'] ), 'no unreplaced placeholders in the issued email' );
}
$order_mails = array_filter(
	$mails,
	static function ( $mail ) {
		return false !== strpos( (string) $mail['message'], 'Your license key and site activations are available in your account' );
	}
);
e2e_ok( count( $order_mails ) >= 1, 'WooCommerce order email to the customer carries the "Licenses" account hint', array_column( $mails, 'subject' ) );

// --- Full key in the email when the shop owner switches it on; purchase of a variation. ---
wplc_e2e_clear_mails();
$email_settings                     = (array) get_option( 'woocommerce_wplc_license_issued_settings', array() );
$email_settings['include_full_key'] = 'yes';
update_option( 'woocommerce_wplc_license_issued_settings', $email_settings );
// WooCommerce built the mailer (and each email's settings) earlier in this process.
WC()->mailer()->init();
$variation_order    = e2e_create_order( $bob_id, array( (int) $variation_ids[0] => 1 ) );
$variation_licenses = e2e_order_licenses( $variation_order->get_id() );
e2e_eq( 1, count( $variation_licenses ), 'purchase of a mapped variation issues 1 license' );
if ( $variation_licenses ) {
	e2e_eq( array( 'affiliate-captain', 'business', '9900' ), array( $variation_licenses[0]['product_key'], $variation_licenses[0]['plan_key'], $variation_licenses[0]['renewal_amount_minor'] ), 'variation license uses the variation plan' );
	$issued = array_values(
		array_filter(
			wplc_e2e_mails(),
			static function ( $mail ) {
				return false !== strpos( (string) $mail['subject'], 'license is ready' );
			}
		)
	);
	e2e_ok( 1 === count( $issued ) && false !== strpos( (string) $issued[0]['message'], e2e_key( $variation_licenses[0] ) ), 'with "include the full key" enabled the issued email contains the key', count( $issued ) );
}
$email_settings['include_full_key'] = 'no';
update_option( 'woocommerce_wplc_license_issued_settings', $email_settings );
WC()->mailer()->init();

// --- A mixed order: only the license product issues. ---
$mixed = e2e_create_order( $bob_id, array( $plain_id => 3, $simple_id => 1 ) );
e2e_eq( 1, count( e2e_order_licenses( $mixed->get_id() ) ), 'mixed order: only the license line issues (1 license, T-shirt x3 none)' );

// --- A variation of a variable product that is mapped at parent level. ---
$pass_variation_ids = (array) e2e_get( 'pass_variation_ids' );
$pass_order         = e2e_create_order( $bob_id, array( (int) $pass_variation_ids[0] => 1 ) );
$pass_licenses      = e2e_order_licenses( $pass_order->get_id() );
e2e_ok( 1 === count( $pass_licenses ) && 'pass-captain' === $pass_licenses[0]['product_key'], 'buying a variation of a parent-mapped variable product issues a license with the parent plan' );
if ( $pass_licenses ) {
	// T-132: the license renews at the regular price of the bought variation (79.00), not at the
	// lowest variation price the parent plan holds (59.00).
	e2e_eq(
		array( '7900', (string) (int) $pass_variation_ids[0] ),
		array( (string) $pass_licenses[0]['renewal_amount_minor'], (string) $pass_licenses[0]['renewal_product_id'] ),
		'parent-mapped variation bought for ' . $pass_order->get_total() . ' stores the variation price and the variation as renewal product'
	);
	e2e_eq( 7900, \WPLC\Billing\RenewalOrder::price( $pass_licenses[0], true )['amount_minor'], 'manual renewal of the parent-mapped variation is priced at 79.00' );
}

// --- The plugin emails are registered with WooCommerce and their settings form renders. ---
// (The WooCommerce settings screens themselves cannot be loaded in a WooCommerce build without compiled JS.)
$registered = array();
foreach ( WC()->mailer()->get_emails() as $registry_key => $email ) {
	if ( 0 === strpos( (string) $email->id, 'wplc_' ) ) {
		$registered[ $email->id ] = $email;
	}
}
ksort( $registered );
e2e_eq( array( 'wplc_expiry_reminder', 'wplc_license_expired', 'wplc_license_issued', 'wplc_license_renewed', 'wplc_payment_failed', 'wplc_renewal_notice' ), array_keys( $registered ), 'six license emails are registered with the WooCommerce mailer' );
$settings_ok = true;
foreach ( $registered as $email ) {
	ob_start();
	$email->admin_options();
	$html = (string) ob_get_clean();
	if ( false === strpos( $html, 'woocommerce_' . $email->id . '_subject' ) || ! $email->is_customer_email() ) {
		$settings_ok = false;
		e2e_note( 'settings form problem: ' . $email->id );
	}
}
e2e_ok( $settings_ok, 'each license email renders its WooCommerce settings form and is a customer email' );
ob_start();
$registered['wplc_license_issued']->admin_options();
e2e_ok( false !== strpos( (string) ob_get_clean(), 'woocommerce_wplc_license_issued_include_full_key' ), '"License issued" settings offer the "include full key" switch' );

// --- My Account → Licenses over HTTP. ---
$alice_cookies = e2e_login( $alice_id );
$account       = e2e_http( 'GET', wc_get_account_endpoint_url( 'wplc-licenses' ), array( 'cookies' => $alice_cookies ) );
e2e_eq( 200, $account['code'], 'My Account → Licenses answers 200 for the customer' );
e2e_ok( false !== strpos( $account['body'], $licenses[0]['key_prefix'] ) && false !== strpos( $account['body'], $licenses[1]['key_prefix'] ), 'account page lists both licenses of the customer' );
e2e_ok( false !== strpos( $account['body'], e2e_key( $licenses[0] ) ), 'account page shows the full license key to its owner' );
e2e_ok( $variation_licenses && false === strpos( $account['body'], $variation_licenses[0]['key_prefix'] ), 'account page does not show another customer\'s license' );
e2e_ok( false !== strpos( $account['body'], 'wplc-licenses' ) && (bool) preg_match( '/<a[^>]+wplc-licenses[^>]*>\s*Licenses/i', $account['body'] ), 'account navigation has the "Licenses" item' );
e2e_ok( ! preg_match( '/There has been a critical error|Fatal error/', $account['body'] ), 'account page rendered without a fatal error' );
$anonymous = e2e_http( 'GET', wc_get_account_endpoint_url( 'wplc-licenses' ) );
e2e_ok( false === strpos( $anonymous['body'], $licenses[0]['key_prefix'] ), 'anonymous visitor sees no license on the account URL' );

e2e_login( 0 );
e2e_set( 'alice_id', $alice_id );
e2e_set( 'bob_id', $bob_id );
e2e_set( 'order_id', $order_id );
e2e_set( 'license_ids', array_map( 'intval', array_column( $licenses, 'id' ) ) );
e2e_set( 'bob_variation_license_id', $variation_licenses ? (int) $variation_licenses[0]['id'] : 0 );
e2e_set( 'bob_variation_order_id', (int) $variation_order->get_id() );
e2e_set( 'bob_mixed_order_id', (int) $mixed->get_id() );
