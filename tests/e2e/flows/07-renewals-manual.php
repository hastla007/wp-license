<?php
/**
 * Flow 7b: manual renewal started by the customer in the account.
 *
 * TIME IS SIMULATED by editing paid_until in the e2e database (e2e_move_paid_until()).
 *
 * @package WPLC
 */

require getenv( 'WPLC_E2E_DIR' ) . '/lib.php';

use WPLC\Client\LicenseClient;

global $wpdb;
$t         = e2e_tables();
$simple_id = (int) e2e_get( 'simple_id' );
$erin_id   = e2e_customer( 'erin' );
$server    = 'https://shop.test';
$utc       = static function ( int $timestamp ) {
	return gmdate( 'Y-m-d H:i:s', $timestamp );
};
$error_of = static function ( $result ) {
	return is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : 'no error';
};
$renewal_orders = static function ( int $license_id ) {
	return wc_get_orders(
		array(
			'limit'      => -1,
			'return'     => 'ids',
			'status'     => array_keys( wc_get_order_statuses() ),
			'meta_key'   => '_wplc_renewal_for_license', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value' => (string) $license_id, // phpcs:ignore WordPress.DB.SlowDBQuery
		)
	);
};

// Two licenses paid by bank transfer: no automatic renewal.
$order    = e2e_checkout( $erin_id, array( $simple_id => 2 ), 'bacs', false );
$licenses = e2e_order_licenses( (int) $order->get_id() );
e2e_eq( array( '0', '0' ), array_column( $licenses, 'renewal_enabled' ), 'two licenses without automatic renewal' );
$license_id  = (int) $licenses[0]['id'];
$expired_id  = (int) $licenses[1]['id'];
$unit_total  = wc_format_decimal( (float) $order->get_total() / 2, 2 );
$license_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['licenses']}" );

e2e_as_site( 'https://erin-shop.example' );
delete_option( 'wplc_e2e_client_erin' );
$client = new LicenseClient( $server, 'voucher-captain', '1.0.0', 'wplc_e2e_client_erin' );
e2e_ok( is_array( $client->activate( e2e_key( $licenses[1] ) ) ), 'site activated on the license that will expire' );
e2e_as_site( '' );

// --- Outside the window (a year left): no renew button, direct POST refused. ---
$cookies = e2e_login( $erin_id );
$account = e2e_http( 'GET', wc_get_account_endpoint_url( 'wplc-licenses' ), array( 'cookies' => $cookies ) );
e2e_ok( null === e2e_form( $account['body'], array( 'value="wplc_start_renewal"' ) ), 'a year before the end the account offers no "renew" button' );
$early = e2e_http( 'POST', admin_url( 'admin-post.php' ), array( 'cookies' => $cookies, 'body' => array( 'action' => 'wplc_start_renewal', 'license_id' => (string) $license_id, '_wpnonce' => wp_create_nonce( 'wplc_start_renewal_' . $license_id ) ) ) );
e2e_ok( 302 === $early['code'] && false === strpos( $early['location'], 'order-pay' ) && 0 === count( $renewal_orders( $license_id ) ), 'starting a renewal outside the window creates no order', array( $early['code'], $early['location'] ) );

// --- Inside the window: 10 days before the end. ---
$old_paid_until = $utc( time() + 10 * DAY_IN_SECONDS );
e2e_move_paid_until( $license_id, $old_paid_until );
$account    = e2e_http( 'GET', wc_get_account_endpoint_url( 'wplc-licenses' ), array( 'cookies' => $cookies ) );
$renew_form = e2e_form( $account['body'], array( 'value="wplc_start_renewal"', 'name="license_id" value="' . $license_id . '"' ) );
e2e_ok( is_array( $renew_form ), '[time simulated: 10 days left] the account offers "renew" for the license' );
e2e_ok( null === e2e_form( $account['body'], array( 'value="wplc_start_renewal"', 'name="license_id" value="' . $expired_id . '"' ) ), 'the other license (a year left) still has no renew button' );

$started = e2e_submit( (array) $renew_form, $cookies );
e2e_ok( 302 === $started['code'] && (bool) preg_match( '#/checkout/order-pay/(\d+)/\?pay_for_order=true&key=wc_order_#', $started['location'], $match ), 'wplc_start_renewal redirects to the pay page of a renewal order', array( $started['code'], $started['location'] ) );
$renewal_order_id = isset( $match[1] ) ? (int) $match[1] : 0;
e2e_eq( array( $renewal_order_id ), array_map( 'intval', $renewal_orders( $license_id ) ), 'exactly one renewal order exists' );
$renewal_order = wc_get_order( $renewal_order_id );
e2e_ok( $renewal_order && 'pending' === $renewal_order->get_status() && $erin_id === (int) $renewal_order->get_customer_id() && 'yes' === $renewal_order->get_meta( '_wplc_manual_renewal', true ), 'renewal order: pending, owned by the customer, marked as manual renewal' );
e2e_eq( (string) $unit_total, wc_format_decimal( $renewal_order->get_total(), 2 ), 'renewal order total equals the price of one unit of the original purchase' );
$renewal = e2e_rows( 'renewals', "license_id = {$license_id}" );
e2e_ok( 1 === count( $renewal ) && 'manual' === $renewal[0]['status'] && $renewal_order_id === (int) $renewal[0]['renewal_order_id'] && $old_paid_until === $renewal[0]['period_start'], 'renewal row: status manual, linked to the order, for the current period end', $renewal );
e2e_eq( 0, count( e2e_pending_actions( 'wplc_process_renewal', array( (int) $renewal[0]['id'] ) ) ), 'no automatic charge job is queued for a manual renewal' );
$pay_page = e2e_http( 'GET', $started['location'], array( 'cookies' => $cookies ) );
e2e_ok( 200 === $pay_page['code'] && false !== strpos( $pay_page['body'], 'id="order_review"' ) && false !== strpos( $pay_page['body'], 'Voucher Captain Pro' ) && false !== strpos( $pay_page['body'], 'payment_method_bacs' ), 'the pay page renders with the product line and offline payment methods', $pay_page['code'] );

// Clicking "renew" again re-uses the open order.
$again = e2e_submit( (array) $renew_form, $cookies );
e2e_ok( false !== strpos( $again['location'], 'order-pay/' . $renewal_order_id . '/' ) && 1 === count( $renewal_orders( $license_id ) ), 'a second click re-uses the same order (still exactly one)', $again['location'] );
// Another customer cannot start it.
$bob_cookies = e2e_login( (int) e2e_get( 'bob_id' ) );
$foreign     = e2e_http( 'POST', admin_url( 'admin-post.php' ), array( 'cookies' => $bob_cookies, 'body' => array( 'action' => 'wplc_start_renewal', 'license_id' => (string) $license_id, '_wpnonce' => wp_create_nonce( 'wplc_start_renewal_' . $license_id ) ) ) );
e2e_ok( false === strpos( $foreign['location'], 'order-pay' ) && 1 === count( $renewal_orders( $license_id ) ), 'another customer cannot start a renewal for this license' );

// --- Pay through the real pay-page POST (bank transfer → on-hold), then the shop confirms the payment. ---
$cookies  = e2e_login( $erin_id );
// New login session: load the pay page again, its nonce belongs to the session.
$pay_page = e2e_http( 'GET', $started['location'], array( 'cookies' => $cookies ) );
$pay_form = e2e_form( $pay_page['body'], array( 'id="order_review"' ) );
$paid     = e2e_http(
	'POST',
	$started['location'],
	array(
		'cookies' => $cookies,
		'body'    => array(
			'payment_method'           => 'bacs',
			'woocommerce_pay'          => '1',
			'woocommerce-pay-nonce'    => (string) ( $pay_form['woocommerce-pay-nonce'] ?? '' ),
			'_wp_http_referer'         => (string) ( $pay_form['_wp_http_referer'] ?? '' ),
		),
	)
);
$renewal_order = wc_get_order( $renewal_order_id );
e2e_ok( 302 === $paid['code'] && 'on-hold' === $renewal_order->get_status(), 'paying by bank transfer on the pay page puts the order on hold', array( $paid['code'], $paid['location'], $renewal_order->get_status() ) );
e2e_eq( $old_paid_until, e2e_license( $license_id )['paid_until'], 'an on-hold (not yet paid) renewal does not extend the license' );
$account    = e2e_http( 'GET', wc_get_account_endpoint_url( 'wplc-licenses' ), array( 'cookies' => $cookies ) );
$renew_form = e2e_form( $account['body'], array( 'value="wplc_start_renewal"', 'name="license_id" value="' . $license_id . '"' ) );
$waiting    = e2e_submit( (array) $renew_form, $cookies );
e2e_eq( 302, $waiting['code'], 'the renew form is still offered and accepted while the transfer is awaited' );
e2e_ok( false === strpos( $waiting['location'], 'order-pay' ) && 1 === count( $renewal_orders( $license_id ) ), 'while the transfer is awaited, "renew" creates no second order', $waiting['location'] );
$waiting_page = e2e_http( 'GET', $waiting['location'], array( 'cookies' => $cookies ) );
e2e_ok( false !== strpos( $waiting_page['body'], 'already waiting for your payment to arrive' ), 'the customer is told that the renewal order waits for the payment', $waiting['location'] );

wplc_e2e_clear_mails();
$renewal_order->payment_complete( 'e2e-bank-received' );
$license      = e2e_license( $license_id );
$new_expected = $utc( \WPLC\Billing\RenewalScheduler::period_end( strtotime( $old_paid_until . ' UTC' ), 'year' ) );
e2e_eq( array( 'active', $new_expected ), array( $license['status'], $license['paid_until'] ), 'payment received: the license is extended by one year from its old end (remaining days are kept)' );
e2e_eq( array( $licenses[0]['license_key_hash'], $licenses[0]['license_uuid'] ), array( $license['license_key_hash'], $license['license_uuid'] ), 'it is the same license with the same key' );
e2e_eq( $license_count, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['licenses']}" ), 'the renewal order issued NO additional license' );
e2e_eq( '0', $license['renewal_enabled'], 'a manual renewal does not switch automatic renewal on' );
e2e_eq( array( $new_expected ), array_values( array_unique( array_column( e2e_rows( 'entitlements', "license_id = {$license_id}" ), 'ends_at' ) ) ), 'entitlements run until the new end' );
$renewal = e2e_rows( 'renewals', "license_id = {$license_id}" );
e2e_ok( 1 === count( $renewal ) && 'paid' === $renewal[0]['status'] && $new_expected === $renewal[0]['period_end'], 'renewal row is paid', $renewal );
e2e_ok( (bool) preg_grep( '/renewed license #' . $license_id . '/', wp_list_pluck( wc_get_order_notes( array( 'order_id' => $renewal_order_id ) ), 'content' ) ), 'order note documents the renewal' );

// Duplicate payment hooks.
do_action( 'woocommerce_payment_complete', $renewal_order_id );
do_action( 'woocommerce_order_status_processing', $renewal_order_id );
$renewal_order = wc_get_order( $renewal_order_id );
$renewal_order->update_status( 'completed' );
\WPLC\Billing\RenewalProcessor::reconcile_paid_renewal_order( $renewal_order_id );
e2e_run_actions( 'wplc_reconcile_paid_renewal_order' );
e2e_eq( $new_expected, e2e_license( $license_id )['paid_until'], 'duplicate payment hooks do not extend the license twice' );
e2e_eq( 1, count( e2e_mails_with_subject( 'license is renewed until' ) ), 'exactly one "renewed" email' );
e2e_eq( $license_count, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['licenses']}" ), 'still no additional license' );
$account = e2e_http( 'GET', wc_get_account_endpoint_url( 'wplc-licenses' ), array( 'cookies' => $cookies ) );
e2e_ok( null === e2e_form( $account['body'], array( 'value="wplc_start_renewal"', 'name="license_id" value="' . $license_id . '"' ) ), 'after the renewal the button is gone (a year left again)' );

// --- Expired inside the window: becomes active again. ---
wplc_e2e_clear_mails();
$expired_at = $utc( time() - 5 * DAY_IN_SECONDS );
e2e_move_paid_until( $expired_id, $expired_at );
do_action( 'wplc_daily_lifecycle' );
e2e_eq( 'expired', e2e_license( $expired_id )['status'], '[time simulated: ended 5 days ago] the daily job expires the non-renewing license' );
e2e_eq( 1, count( e2e_mails_with_subject( 'license has expired' ) ), 'one "license expired" email' );
e2e_as_site( 'https://erin-shop.example' );
$check = $client->check_entitlements();
e2e_ok( is_array( $check ) && 'expired' === ( $check['license_status'] ?? null ) && empty( $check['entitlements'] ), 'the site of the expired license gets no entitlements', is_array( $check ) ? $check : $error_of( $check ) );
e2e_as_site( '' );
$account    = e2e_http( 'GET', wc_get_account_endpoint_url( 'wplc-licenses' ), array( 'cookies' => $cookies ) );
$renew_form = e2e_form( $account['body'], array( 'value="wplc_start_renewal"', 'name="license_id" value="' . $expired_id . '"' ) );
e2e_ok( is_array( $renew_form ), 'the account offers "renew" for the expired license (within 30 days)' );
$started = e2e_submit( (array) $renew_form, $cookies );
preg_match( '#order-pay/(\d+)/#', $started['location'], $match );
$expired_order = wc_get_order( (int) ( $match[1] ?? 0 ) );
e2e_ok( $expired_order instanceof WC_Order, 'renewal order created for the expired license', $started['location'] );
$expired_order->set_payment_method( 'cod' );
$expired_order->payment_complete( 'e2e-expired-renewal' );
$license  = e2e_license( $expired_id );
$paid_at  = $expired_order->get_date_paid() ? $expired_order->get_date_paid()->getTimestamp() : time();
e2e_eq( 'active', $license['status'], 'paying the renewal reactivates the expired license' );
e2e_eq( $utc( \WPLC\Billing\RenewalScheduler::period_end( $paid_at, 'year' ) ), $license['paid_until'], 'the new period of an expired license starts at the payment (no back-dating)' );
e2e_eq( $licenses[1]['license_key_hash'], $license['license_key_hash'], 'same key as before' );
e2e_eq( array( 'active' ), array_values( array_unique( array_column( e2e_rows( 'entitlements', "license_id = {$expired_id}" ), 'status' ) ) ), 'entitlements are active again' );
e2e_as_site( 'https://erin-shop.example' );
$check = $client->check_entitlements();
e2e_ok( is_array( $check ) && 'active' === ( $check['license_status'] ?? null ) && true === ( $check['entitlements']['voucher.custom_branding'] ?? null ), 'the already activated site works again without re-activation', is_array( $check ) ? $check : $error_of( $check ) );
e2e_as_site( '' );

// --- Expired long ago: outside the window. ---
e2e_move_paid_until( $expired_id, $utc( time() - 45 * DAY_IN_SECONDS ) );
do_action( 'wplc_daily_lifecycle' );
e2e_eq( 'expired', e2e_license( $expired_id )['status'], '[time simulated: ended 45 days ago] expired' );
$account = e2e_http( 'GET', wc_get_account_endpoint_url( 'wplc-licenses' ), array( 'cookies' => $cookies ) );
e2e_ok( null === e2e_form( $account['body'], array( 'value="wplc_start_renewal"', 'name="license_id" value="' . $expired_id . '"' ) ), '45 days after the end there is no renew button any more' );
$orders_before = count( $renewal_orders( $expired_id ) );
$late          = e2e_http( 'POST', admin_url( 'admin-post.php' ), array( 'cookies' => $cookies, 'body' => array( 'action' => 'wplc_start_renewal', 'license_id' => (string) $expired_id, '_wpnonce' => wp_create_nonce( 'wplc_start_renewal_' . $expired_id ) ) ) );
e2e_eq( $orders_before, count( $renewal_orders( $expired_id ) ), 'a forced POST outside the window creates no order' );
$notice = e2e_http( 'GET', $late['location'], array( 'cookies' => $cookies ) );
e2e_ok( false !== strpos( $notice['body'], 'cannot be renewed at the moment' ), 'the customer is told that the license cannot be renewed', $late['location'] );

// --- A renewal order paid AFTER the window closed must not silently extend; it is flagged for review. ---
$late_id = (int) e2e_order_licenses( (int) e2e_checkout( $erin_id, array( $simple_id => 1 ), 'bacs', false )->get_id() )[0]['id'];
e2e_move_paid_until( $late_id, $utc( time() + 5 * DAY_IN_SECONDS ) );
$late_order = \WPLC\Billing\ManualRenewal::start( $late_id, $erin_id );
e2e_ok( $late_order instanceof WC_Order, 'renewal order started inside the window' );
$wpdb->query( $wpdb->prepare( "UPDATE {$t['licenses']} SET status = 'revoked' WHERE id = %d", $late_id ) );
$late_order->payment_complete( 'e2e-late' );
$late_license = e2e_license( $late_id );
e2e_ok( 'revoked' === $late_license['status'] && 'yes' === wc_get_order( $late_order->get_id() )->get_meta( '_wplc_renewal_review', true ), '[status edited in the e2e DB] payment for a license revoked in the meantime does not reactivate it; the order is flagged for manual review' );

e2e_login( 0 );
e2e_set( 'erin_id', $erin_id );
e2e_set( 'erin_license_id', $license_id );
