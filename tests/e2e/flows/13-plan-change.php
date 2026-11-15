<?php
/**
 * Flow 13: plan change of an existing license (T-110, T-109).
 *
 * Two plans of one product (Pro, Business). The customer upgrades from the account page, pays the prorated
 * order, and the activated site gets the Business entitlements through the client without re-activation.
 * Automatic renewal needs the consent to the new amount. A downgrade is scheduled for the next renewal and
 * withdrawn again; the administrator sees the "Change plan" form; a full refund of the upgrade reverts it.
 *
 * @package WPLC
 */

require getenv( 'WPLC_E2E_DIR' ) . '/lib.php';

use WPLC\Catalog\Catalog;
use WPLC\Client\LicenseClient;

global $wpdb;
$server   = 'https://shop.test';
$error_of = static function ( $result ) {
	return is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : wp_json_encode( $result );
};
$upgrade_orders = static function ( int $license_id ) {
	return array_map(
		'intval',
		wc_get_orders(
			array(
				'limit'      => -1,
				'return'     => 'ids',
				'status'     => array_keys( wc_get_order_statuses() ),
				'meta_key'   => '_wplc_upgrade_for_license', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => (string) $license_id, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		)
	);
};

// --- Catalog: Pass Captain Pro (1 site) and Business (3 sites, seating). ---
$make_plan = static function ( string $name, string $price, string $plan_key, int $sites, array $entitlements ) {
	$product = new WC_Product_Simple();
	$product->set_name( $name );
	$product->set_status( 'publish' );
	$product->set_regular_price( $price );
	$product->set_virtual( true );
	// Marks a license product (the checkout offers the automatic renewal consent for it).
	$product->update_meta_data( '_wplc_is_license_product', 'yes' );
	$product->save();
	$saved = Catalog::save_mapping(
		$product->get_id(),
		0,
		array(
			'product_key'      => 'pass-captain',
			'plan_key'         => $plan_key,
			'billing_interval' => 'year',
			'live_site_limit'  => $sites,
			'support_level'    => 'standard',
			'entitlements'     => $entitlements,
		)
	);
	e2e_ok( $saved, 'catalog mapping saved for ' . $name );

	return (int) $product->get_id();
};
$pro_id      = $make_plan( 'Pass Captain Pro', '49.00', 'pro', 1, array( 'pass.csv_export' => true ) );
$business_id = $make_plan( 'Pass Captain Business', '149.00', 'business', 3, array( 'pass.csv_export' => true, 'pass.seating' => true ) );

$paula_id    = e2e_customer( 'paula' );
$order      = e2e_create_order( $paula_id, array( $pro_id => 1 ), array( 'payment_method' => 'bacs' ) );
$licenses   = e2e_order_licenses( (int) $order->get_id() );
e2e_eq( 1, count( $licenses ), 'one Pro license bought by bank transfer' );
$license    = $licenses[0];
$license_id = (int) $license['id'];
$count      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wplc_licenses" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

e2e_as_site( 'https://paula-shop.example' );
delete_option( 'wplc_e2e_client_paula' );
$client = new LicenseClient( $server, 'pass-captain', '1.0.0', 'wplc_e2e_client_paula' );
e2e_ok( is_array( $client->activate( e2e_key( $license ) ) ), 'the client activates a site with the Pro key' );
$check = $client->check_entitlements();
e2e_ok( is_array( $check ) && 1 === ( $check['site_limit'] ?? null ) && empty( $check['entitlements']['pass.seating'] ) && true === ( $check['entitlements']['pass.csv_export'] ?? null ), 'Pro: one site, no seating', is_array( $check ) ? $check : $error_of( $check ) );
e2e_as_site( '' );

// --- The account page offers the upgrade with the prorated price. ---
$cookies = e2e_login( $paula_id );
$account = e2e_http( 'GET', wc_get_account_endpoint_url( 'wplc-licenses' ), array( 'cookies' => $cookies ) );
$form    = e2e_form( $account['body'], array( 'value="wplc_start_upgrade"', 'name="license_id" value="' . $license_id . '"', 'name="product_id" value="' . $business_id . '"' ) );
e2e_ok( is_array( $form ), 'the account offers "Upgrade to Pass Captain Business" for the Pro license' );
e2e_ok( false !== strpos( $account['body'], 'Upgrade to Pass Captain Business now:' ) && false === strpos( $account['body'], 'name="wplc_upgrade_consent"' ), 'the upgrade shows the amount due now; no consent box without automatic renewal' );

$started = e2e_submit( (array) $form, $cookies );
e2e_ok( 302 === $started['code'] && (bool) preg_match( '#/checkout/order-pay/(\d+)/#', $started['location'], $match ), 'wplc_start_upgrade redirects to the pay page of an upgrade order', array( $started['code'], $started['location'] ) );
$upgrade_id = (int) ( $match[1] ?? 0 );
e2e_eq( array( $upgrade_id ), $upgrade_orders( $license_id ), 'exactly one upgrade order exists' );
$upgrade = wc_get_order( $upgrade_id );
e2e_ok( $upgrade && 'pending' === $upgrade->get_status() && $paula_id === (int) $upgrade->get_customer_id(), 'upgrade order: pending, owned by the customer' );
$total = (float) $upgrade->get_total();
e2e_ok( $total > 99.0 && $total <= 100.0 * 1.19 + 0.01, 'the order charges the prorated difference (about 100.00 for an almost full year, plus tax if configured)', $total );
$pay_page = e2e_http( 'GET', $started['location'], array( 'cookies' => $cookies ) );
e2e_ok( 200 === $pay_page['code'] && false !== strpos( $pay_page['body'], 'Pass Captain Business' ), 'the pay page shows the Business line', $pay_page['code'] );
$again = e2e_submit( (array) $form, $cookies );
e2e_ok( false !== strpos( $again['location'], 'order-pay/' . $upgrade_id . '/' ) && array( $upgrade_id ) === $upgrade_orders( $license_id ), 'a second click re-uses the open upgrade order' );
$bob_cookies = e2e_login( (int) e2e_get( 'bob_id' ) );
$foreign     = e2e_http( 'POST', admin_url( 'admin-post.php' ), array( 'cookies' => $bob_cookies, 'body' => array( 'action' => 'wplc_start_upgrade', 'license_id' => (string) $license_id, 'product_id' => (string) $business_id, '_wpnonce' => wp_create_nonce( 'wplc_start_upgrade_' . $license_id ) ) ) );
e2e_ok( false === strpos( $foreign['location'], 'order-pay' ) && array( $upgrade_id ) === $upgrade_orders( $license_id ), 'another customer cannot start an upgrade of this license' );

// --- Paid: same key and period, Business plan, the site sees the new entitlements. ---
$upgrade->payment_complete( 'e2e-upgrade' );
do_action( 'woocommerce_order_status_completed', $upgrade_id );
$after = e2e_license( $license_id );
e2e_eq( array( 'business', $license['paid_until'], $license['license_key_hash'], (string) $business_id, '14900' ), array( $after['plan_key'], $after['paid_until'], $after['license_key_hash'], $after['renewal_product_id'], $after['renewal_amount_minor'] ), 'paid: plan business, same period and key, renewals use the Business product and price' );
e2e_eq( $count, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wplc_licenses" ), 'the upgrade order issued no additional license' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
e2e_eq( 1, count( array_keys( e2e_audit_actions( 'license', $license_id ), 'plan_upgraded', true ) ), 'one plan_upgraded audit entry despite duplicate hooks' );
e2e_as_site( 'https://paula-shop.example' );
$check = $client->check_entitlements();
e2e_ok( is_array( $check ) && 3 === ( $check['site_limit'] ?? null ) && true === ( $check['entitlements']['pass.seating'] ?? null ), 'the activated site gets the Business entitlements without re-activation', is_array( $check ) ? $check : $error_of( $check ) );
e2e_as_site( '' );

// --- The administrator sees the plan change form. ---
$admin_cookies = e2e_login( (int) e2e_get( 'admin_id' ) );
$detail        = e2e_http( 'GET', admin_url( 'admin.php?page=wplc-licenses&license=' . $license_id ), array( 'cookies' => $admin_cookies ) );
e2e_ok( 200 === $detail['code'] && is_array( e2e_form( $detail['body'], array( 'value="wplc_admin_change_plan"', 'value="' . $pro_id . '"' ) ) ), 'the license detail page offers "Change plan" with the Pro plan', $detail['code'] );

// --- Downgrade at the next renewal, then withdrawn. ---
$cookies   = e2e_login( $paula_id );
$account   = e2e_http( 'GET', wc_get_account_endpoint_url( 'wplc-licenses' ), array( 'cookies' => $cookies ) );
$downgrade = e2e_form( $account['body'], array( 'value="wplc_schedule_downgrade"', 'name="license_id" value="' . $license_id . '"', 'name="product_id" value="' . $pro_id . '"' ) );
e2e_ok( is_array( $downgrade ) && null === e2e_form( $account['body'], array( 'value="wplc_start_upgrade"', 'name="license_id" value="' . $license_id . '"' ) ), 'after the upgrade the account offers the downgrade and no further upgrade' );
$scheduled = e2e_submit( (array) $downgrade, $cookies );
e2e_eq( 302, $scheduled['code'], 'the downgrade form redirects back' );
$after = e2e_license( $license_id );
e2e_eq( array( 'business', 'pro' ), array( $after['plan_key'], $after['pending_plan_key'] ), 'the downgrade is pending; the Business plan stays until the renewal' );
$account = e2e_http( 'GET', wc_get_account_endpoint_url( 'wplc-licenses' ), array( 'cookies' => $cookies ) );
$keep    = e2e_form( $account['body'], array( 'value="wplc_cancel_plan_change"', 'name="license_id" value="' . $license_id . '"' ) );
e2e_ok( is_array( $keep ) && false !== strpos( $account['body'], 'Changes to Pass Captain Pro with the next renewal' ), 'the account shows the pending change and "Keep current plan"' );
e2e_submit( (array) $keep, $cookies );
e2e_eq( '', e2e_license( $license_id )['pending_plan_key'], '"Keep current plan" withdraws the downgrade' );

// --- A full refund of the upgrade reverts the plan. ---
$refund = wc_create_refund( array( 'order_id' => $upgrade_id, 'amount' => wc_format_decimal( wc_get_order( $upgrade_id )->get_total(), 2 ), 'reason' => 'e2e' ) );
e2e_ok( $refund instanceof WC_Order_Refund, 'the upgrade order is refunded in full', $error_of( $refund ) );
$after = e2e_license( $license_id );
e2e_eq( array( 'pro', 'active', '4900', (string) $pro_id ), array( $after['plan_key'], $after['status'], $after['renewal_amount_minor'], $after['renewal_product_id'] ), 'the refund reverts to Pro; the license stays active' );
e2e_as_site( 'https://paula-shop.example' );
$check = $client->check_entitlements();
e2e_ok( is_array( $check ) && 1 === ( $check['site_limit'] ?? null ) && empty( $check['entitlements']['pass.seating'] ), 'the site is back on the Pro entitlements', is_array( $check ) ? $check : $error_of( $check ) );
e2e_as_site( '' );

// --- Automatic renewal: the upgrade needs the consent to the new amount. ---
$quinn_id   = e2e_customer( 'quinn' );
$auto      = e2e_checkout( $quinn_id, array( $pro_id => 1 ), 'wplc_e2e_fake', true );
$auto_lic  = e2e_order_licenses( (int) $auto->get_id() )[0];
$auto_id   = (int) $auto_lic['id'];
e2e_eq( '1', $auto_lic['renewal_enabled'], 'a Pro license with automatic renewal' );
$cookies   = e2e_login( $quinn_id );
$account   = e2e_http( 'GET', wc_get_account_endpoint_url( 'wplc-licenses' ), array( 'cookies' => $cookies ) );
$form      = e2e_form( $account['body'], array( 'value="wplc_start_upgrade"', 'name="license_id" value="' . $auto_id . '"' ) );
e2e_ok( is_array( $form ) && false !== strpos( $account['body'], 'name="wplc_upgrade_consent"' ), 'the upgrade form asks for the consent to the new automatic amount' );
$refused = e2e_submit( (array) $form, $cookies );
e2e_ok( false === strpos( $refused['location'], 'order-pay' ) && array() === $upgrade_orders( $auto_id ), 'without the consent no upgrade order is created', $refused['location'] );
$started = e2e_submit( (array) $form, $cookies, array( 'wplc_upgrade_consent' => '1' ) );
e2e_ok( (bool) preg_match( '#/checkout/order-pay/(\d+)/#', $started['location'], $match ), 'with the consent the customer goes to the pay page', $started['location'] );
$auto_upgrade = wc_get_order( (int) ( $match[1] ?? 0 ) );
e2e_ok( $auto_upgrade instanceof WC_Order && '' !== (string) $auto_upgrade->get_meta( '_wplc_upgrade_recurring_consent_at', true ), 'the consent is stored on the upgrade order' );
$auto_upgrade->payment_complete( 'e2e-auto-upgrade' );
$after = e2e_license( $auto_id );
e2e_eq( array( 'business', '14900', '1' ), array( $after['plan_key'], $after['renewal_amount_minor'], $after['renewal_enabled'] ), 'paid: the next automatic renewal charges the consented Business price' );
