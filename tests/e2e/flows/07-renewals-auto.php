<?php
/**
 * Flow 7a: automatic renewals with the fake gateway adapter.
 *
 * Success, decline with retries and grace, expiry after grace, "manual action required",
 * and a failed renewal the customer pays on the pay page.
 *
 * TIME IS SIMULATED by editing dates in the e2e database (helpers e2e_move_paid_until()
 * and e2e_age_renewal() in lib.php). The plugin code itself always runs unmodified.
 *
 * @package WPLC
 */

require getenv( 'WPLC_E2E_DIR' ) . '/lib.php';

use WPLC\Client\LicenseClient;

global $wpdb;
$t         = e2e_tables();
$simple_id = (int) e2e_get( 'simple_id' );
$dave_id   = e2e_customer( 'dave' );
$server    = 'https://shop.test';
$error_of  = static function ( $result ) {
	return is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : 'no error';
};
$utc = static function ( int $timestamp ) {
	return gmdate( 'Y-m-d H:i:s', $timestamp );
};

update_option( 'wplc_e2e_fake_mode', 'success' );
update_option( 'wplc_e2e_fake_charges', array(), false );
$order    = e2e_checkout( $dave_id, array( $simple_id => 3 ), 'wplc_e2e_fake', true );
$licenses = e2e_order_licenses( (int) $order->get_id() );
e2e_eq( array( '1', '1', '1' ), array_column( $licenses, 'renewal_enabled' ), 'three renewing licenses bought' );
$purchase_unit_total = wc_format_decimal( (float) $order->get_total() / 3, 2 );
$l_success = (int) $licenses[0]['id'];
$l_manual  = (int) $licenses[1]['id'];
$l_recover = (int) $licenses[2]['id'];
$license_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['licenses']}" );

// One activated site, to observe what a customer site is told in each state.
e2e_as_site( 'https://dave-shop.example' );
delete_option( 'wplc_e2e_client_dave' );
$client = new LicenseClient( $server, 'voucher-captain', '1.0.0', 'wplc_e2e_client_dave' );
$result = $client->activate( e2e_key( $licenses[0] ) );
e2e_ok( is_array( $result ), 'site activated on the renewing license', $error_of( $result ) );
e2e_as_site( '' );

// =====================================================================================
// A. Success.
// =====================================================================================
wplc_e2e_clear_mails();
$due        = $utc( time() - HOUR_IN_SECONDS );
$renewal_id = e2e_move_paid_until( $l_success, $due );
$renewal    = e2e_renewal( $renewal_id );
e2e_ok( $renewal_id > 0 && 'scheduled' === $renewal['status'] && $due === $renewal['period_start'], '[time simulated] the paid period ended one hour ago; renewal scheduled for it', $renewal );
$expected_end = $utc( \WPLC\Billing\RenewalScheduler::period_end( strtotime( $due . ' UTC' ), 'year' ) );
e2e_eq( $expected_end, $renewal['period_end'], 'renewal period_end = period_start + 1 year' );
e2e_eq( $utc( strtotime( $due . ' UTC' ) + 14 * DAY_IN_SECONDS ), $renewal['grace_ends_at'], 'grace ends 14 days after the period start' );

e2e_ok( e2e_run_action( 'wplc_process_renewal', array( $renewal_id ) ), 'Action Scheduler runs the pending wplc_process_renewal job' );
$charges = e2e_charges();
e2e_eq( 1, count( $charges ), 'the adapter was asked to charge exactly once' );
$renewal = e2e_renewal( $renewal_id );
$license = e2e_license( $l_success );
e2e_eq( 'paid', $renewal['status'], 'renewal row is paid' );
e2e_eq( array( 'active', $expected_end, $expected_end ), array( $license['status'], $license['paid_until'], $license['next_payment_at'] ), 'license stays active; paid_until and next_payment_at moved one year on' );
e2e_eq( $licenses[0]['license_key_hash'], $license['license_key_hash'], 'same license, same key' );
e2e_eq( array( $expected_end ), array_values( array_unique( array_column( e2e_rows( 'entitlements', 'license_id = ' . $l_success ), 'ends_at' ) ) ), 'entitlements run until the new paid_until' );
$renewal_order = wc_get_order( (int) $renewal['renewal_order_id'] );
e2e_ok( $renewal_order instanceof WC_Order, 'a renewal order exists and is linked to the renewal row' );
if ( $renewal_order instanceof WC_Order ) {
	e2e_ok( $renewal_order->is_paid() && 'wplc_e2e_fake' === $renewal_order->get_payment_method() && $dave_id === (int) $renewal_order->get_customer_id(), 'renewal order: paid, gateway of the license, customer of the license', array( $renewal_order->get_status(), $renewal_order->get_payment_method() ) );
	e2e_eq( (string) $purchase_unit_total, wc_format_decimal( $renewal_order->get_total(), 2 ), 'renewal order total equals the price of one unit of the original purchase' );
	e2e_eq( wc_format_decimal( $renewal_order->get_total(), 2 ), wc_format_decimal( $charges[0]['total'], 2 ), 'the adapter was asked to charge the order total' );
	e2e_eq( (int) $renewal_order->get_id(), (int) $charges[0]['order_id'], 'the charge belongs to the renewal order' );
	e2e_eq( $renewal['idempotency_key'] . ':0', $charges[0]['key'], 'first attempt uses the idempotency key "{renewal key}:0"' );
	e2e_eq( e2e_fake_card( $dave_id ), (int) $charges[0]['token_id'], 'the saved token of the customer is charged' );
	e2e_eq( 'e2e_txn_1', (string) $renewal_order->get_transaction_id(), 'the gateway transaction ID is stored on the order' );
	e2e_eq( array( (string) $l_success, (string) $renewal_id ), array( (string) $renewal_order->get_meta( '_wplc_renewal_for_license', true ), (string) $renewal_order->get_meta( '_wplc_renewal_id', true ) ), 'renewal order meta links license and renewal' );
	$items = $renewal_order->get_items( 'line_item' );
	e2e_ok( 1 === count( $items ) && $simple_id === (int) current( $items )->get_product_id() && 1 === (int) current( $items )->get_quantity(), 'renewal order has one line: the license product, quantity 1' );
	e2e_eq( 'Berlin', $renewal_order->get_billing_city(), 'renewal order carries the customer billing address' );
}
e2e_eq( $license_count, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['licenses']}" ), 'paying the renewal order issues NO new license' );
$next = e2e_rows( 'renewals', "license_id = {$l_success} AND status = 'scheduled'" );
e2e_ok( 1 === count( $next ) && $expected_end === $next[0]['period_start'], 'the next renewal is scheduled for the new period end', $next );
e2e_ok( $next && 1 === count( e2e_pending_actions( 'wplc_process_renewal', array( (int) $next[0]['id'] ) ) ), 'the next renewal job is pending in Action Scheduler' );
$renewed_mails = e2e_mails_with_subject( 'license is renewed until' );
e2e_ok( 1 === count( $renewed_mails ) && false !== strpos( wp_json_encode( $renewed_mails[0]['to'] ), 'dave@customer.test' ), 'exactly one "license renewed" email to the customer', array_column( wplc_e2e_mails(), 'subject' ) );
e2e_ok( $renewed_mails && ! preg_match( '/\{[a-z_]+\}/', $renewed_mails[0]['subject'] . $renewed_mails[0]['message'] ), 'no unreplaced placeholder in the renewed email', $renewed_mails ? $renewed_mails[0]['subject'] : '' );
e2e_ok( in_array( 'renewal_paid', e2e_audit_actions( 'renewal', $renewal_id ), true ) && in_array( 'renewal_succeeded', e2e_audit_actions( 'license', $l_success ), true ), 'audit log: renewal_paid and renewal_succeeded' );

// Running the same job again or repeating the payment hooks changes nothing.
\WPLC\Billing\RenewalProcessor::process( $renewal_id );
do_action( 'woocommerce_payment_complete', (int) $renewal['renewal_order_id'] );
do_action( 'woocommerce_order_status_processing', (int) $renewal['renewal_order_id'] );
e2e_run_actions( 'wplc_reconcile_paid_renewal_order' );
e2e_eq( 1, count( e2e_charges() ), 'a second run of the job does not charge again' );
e2e_eq( $expected_end, e2e_license( $l_success )['paid_until'], 'repeated payment hooks do not extend the license a second time' );
e2e_eq( 1, count( e2e_mails_with_subject( 'license is renewed until' ) ), 'still exactly one "renewed" email' );
e2e_as_site( 'https://dave-shop.example' );
$check = $client->check_entitlements();
e2e_ok( is_array( $check ) && $expected_end === ( $check['paid_until'] ?? null ) && 'active' === ( $check['license_status'] ?? null ), 'the customer site sees the new paid_until', $error_of( $check ) );
e2e_as_site( '' );

// =====================================================================================
// B. Decline → past_due, retries on day 1/3/7, rights during grace, expiry after grace.
// =====================================================================================
wplc_e2e_clear_mails();
update_option( 'wplc_e2e_fake_mode', 'decline' );
update_option( 'wplc_e2e_fake_charges', array(), false );
// A different second than in A: the renewal key is derived from license and period start.
$due        = $utc( time() - 2 * HOUR_IN_SECONDS );
$renewal_id = e2e_move_paid_until( $l_success, $due );
$start      = strtotime( $due . ' UTC' );
e2e_ok( e2e_run_action( 'wplc_process_renewal', array( $renewal_id ) ), '[time simulated] next period is due; the renewal job runs with a declining card' );
$renewal = e2e_renewal( $renewal_id );
$license = e2e_license( $l_success );
e2e_eq( array( 'failed', '1' ), array( $renewal['status'], $renewal['attempt_count'] ), 'declined: renewal failed, attempt_count 1' );
e2e_eq( array( 'past_due', $due ), array( $license['status'], $license['paid_until'] ), 'declined: license is past_due, paid_until unchanged' );
e2e_eq( $utc( $start + DAY_IN_SECONDS ), $renewal['retry_at'], 'first retry is scheduled one day after the period start' );
e2e_eq( 1, count( e2e_pending_actions( 'wplc_process_renewal', array( $renewal_id ) ) ), 'the retry job is pending in Action Scheduler' );
$failed_order_id = (int) $renewal['renewal_order_id'];
$failed_order    = wc_get_order( $failed_order_id );
e2e_ok( $failed_order instanceof WC_Order && 'failed' === $failed_order->get_status(), 'the renewal order is linked and has status failed', $failed_order ? $failed_order->get_status() : null );
$failed_mails = e2e_mails_with_subject( 'renewal payment for' );
e2e_ok( 1 === count( $failed_mails ) && false !== strpos( wp_json_encode( $failed_mails[0]['to'] ), 'dave@customer.test' ), 'one "payment failed" email to the customer', array_column( wplc_e2e_mails(), 'subject' ) );
e2e_ok( $failed_mails && false !== strpos( $failed_mails[0]['message'], 'order-pay' ) || ( $failed_mails && false !== strpos( $failed_mails[0]['message'], 'wplc-licenses' ) ), 'the payment-failed email links to the pay page or the account', $failed_mails ? wp_strip_all_tags( $failed_mails[0]['message'] ) : '' );

// Rights during grace.
e2e_as_site( 'https://dave-shop.example' );
$check = $client->check_entitlements();
e2e_ok( is_array( $check ) && 'past_due' === ( $check['license_status'] ?? null ) && true === ( $check['entitlements']['voucher.custom_branding'] ?? null ), 'during grace the site still gets its entitlements (license_status past_due)', is_array( $check ) ? $check : $error_of( $check ) );
$update = $client->check_update();
e2e_ok( is_array( $update ) && true === ( $update['update_available'] ?? null ), 'during grace updates are still offered', $error_of( $update ) );
e2e_as_site( '' );
$dave_cookies = e2e_login( $dave_id );
$account      = e2e_http( 'GET', wc_get_account_endpoint_url( 'wplc-licenses' ), array( 'cookies' => $dave_cookies ) );
e2e_ok( false !== strpos( $account['body'], 'Complete renewal payment' ) && false !== strpos( $account['body'], 'order-pay/' . $failed_order_id ), 'account page shows "Complete renewal payment" with the pay link of the failed order' );
e2e_login( 0 );

// Retries 2, 3, 4.
// After the last retry the renewal waits for the end of grace with a date (B-190), without a queued charge job.
$expected_retries = array( 2 => $utc( $start + 3 * DAY_IN_SECONDS ), 3 => $utc( $start + 7 * DAY_IN_SECONDS ), 4 => $utc( $start + \WPLC\License\LicenseLifecycle::GRACE_DAYS * DAY_IN_SECONDS ) );
foreach ( $expected_retries as $attempt => $retry_at ) {
	// B-188: a job that runs before retry_at does not charge, so the retry is made due first.
	$wpdb->update( $t['renewals'], array( 'retry_at' => $utc( time() - 5 ) ), array( 'id' => $renewal_id ) );
	$ran     = e2e_run_action( 'wplc_process_renewal', array( $renewal_id ) );
	$renewal = e2e_renewal( $renewal_id );
	e2e_ok( $ran && (string) $attempt === $renewal['attempt_count'] && 'failed' === $renewal['status'] && $retry_at === $renewal['retry_at'], "[job run early] attempt {$attempt}: failed again, next retry " . ( $retry_at ? $retry_at : 'none' ), array( $ran, $renewal['attempt_count'], $renewal['status'], $renewal['retry_at'] ) );
	e2e_eq( $failed_order_id, (int) $renewal['renewal_order_id'], "attempt {$attempt}: the same renewal order is reused" );
}
$charges = e2e_charges();
e2e_eq( 4, count( $charges ), 'four charge attempts in total (first + day 1, 3, 7)' );
e2e_eq( array( $renewal['idempotency_key'] . ':0', $renewal['idempotency_key'] . ':1', $renewal['idempotency_key'] . ':2', $renewal['idempotency_key'] . ':3' ), array_column( $charges, 'key' ), 'every definite decline gets a new idempotency key' );
e2e_eq( 1, count( array_unique( array_column( $charges, 'order_id' ) ) ), 'all attempts charge the same order' );
e2e_eq( 0, count( e2e_pending_actions( 'wplc_process_renewal', array( $renewal_id ) ) ), 'after the last retry no further charge job is pending' );
e2e_eq( 1, count( e2e_pending_actions( 'wplc/renewal_grace_ended', array( $l_success ) ) ), 'the grace-end job is pending' );
e2e_eq( 1, count( e2e_mails_with_subject( 'renewal payment for' ) ), 'still only one "payment failed" email after four declines' );
e2e_eq( 'past_due', e2e_license( $l_success )['status'], 'license is still past_due (grace) after the last retry' );
e2e_eq( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['renewals']} WHERE license_id = {$l_success} AND period_start = '{$due}'" ), 'one renewal row for the period' );

// Expiry after grace.
wplc_e2e_clear_mails();
e2e_age_renewal( $renewal_id, 15 );
e2e_as_site( 'https://dave-shop.example' );
$check = $client->check_entitlements();
e2e_ok( ( is_array( $check ) && empty( $check['entitlements'] ) ) || is_wp_error( $check ), '[time simulated: grace ended 1 day ago] the site no longer gets entitlements, even before the expiry job ran', is_array( $check ) ? $check : $error_of( $check ) );
e2e_as_site( '' );
e2e_ok( e2e_run_action( 'wplc/renewal_grace_ended', array( $l_success ) ), 'the grace-end job runs' );
$license = e2e_license( $l_success );
e2e_eq( array( 'expired', '0', null ), array( $license['status'], $license['renewal_enabled'], $license['next_payment_at'] ), 'after grace: license expired, renewal switched off' );
e2e_ok( ! in_array( e2e_renewal( $renewal_id )['status'], array( 'scheduled', 'failed', 'processing' ), true ), 'after grace: the renewal row is closed', e2e_renewal( $renewal_id )['status'] );
$expired_mails = e2e_mails_with_subject( 'license has expired' );
e2e_eq( 1, count( $expired_mails ), 'one "license expired" email', array_column( wplc_e2e_mails(), 'subject' ) );
e2e_as_site( 'https://dave-shop.example' );
$check = $client->check_entitlements();
e2e_ok( is_array( $check ) && 'expired' === ( $check['license_status'] ?? null ) && empty( $check['entitlements'] ), 'expired: the site is told "expired" and gets no entitlements', is_array( $check ) ? $check : $error_of( $check ) );
$update = $client->check_update();
// B-140: signed answer with the reason instead of a bare 403; never a package.
e2e_ok( is_array( $update ) && false === ( $update['license_active'] ?? null ) && 'license_expired' === ( $update['reason'] ?? null ) && '' === ( $update['package_url'] ?? '' ), 'expired: no updates', $error_of( $update ) );
e2e_as_site( '' );
update_option( 'wplc_e2e_fake_mode', 'success' );
update_option( 'wplc_e2e_fake_charges', array(), false );
\WPLC\Billing\RenewalProcessor::process( $renewal_id );
e2e_eq( 0, count( e2e_charges() ), 'an expired license is never charged, even if an old job runs with a working card' );

// =====================================================================================
// C. "Manual action required" (e.g. 3-D Secure).
// =====================================================================================
wplc_e2e_clear_mails();
update_option( 'wplc_e2e_fake_mode', 'manual' );
update_option( 'wplc_e2e_fake_charges', array(), false );
$due        = $utc( time() - HOUR_IN_SECONDS );
$renewal_id = e2e_move_paid_until( $l_manual, $due );
e2e_run_action( 'wplc_process_renewal', array( $renewal_id ) );
$renewal = e2e_renewal( $renewal_id );
$license = e2e_license( $l_manual );
e2e_eq( array( 'manual_action_required', 'past_due' ), array( $renewal['status'], $license['status'] ), 'manual action required: renewal waits for the customer, license past_due' );
$manual_order_id = (int) $renewal['renewal_order_id'];
$manual_order    = wc_get_order( $manual_order_id );
e2e_ok( $manual_order_id > 0 && $manual_order instanceof WC_Order && $manual_order->needs_payment(), 'manual action required: the order stays linked to the renewal and is payable', array( $manual_order_id, $manual_order ? $manual_order->get_status() : null ) );
e2e_eq( 0, count( e2e_pending_actions( 'wplc_process_renewal', array( $renewal_id ) ) ), 'manual action required: automatic retries stopped' );
e2e_eq( 1, count( e2e_pending_actions( 'wplc/renewal_grace_ended', array( $l_manual ) ) ), 'manual action required: grace-end job pending' );
e2e_ok( (bool) preg_grep( '/requires customer authentication/', wp_list_pluck( wc_get_order_notes( array( 'order_id' => $manual_order_id ) ), 'content' ) ), 'manual action required: order note for the shop owner' );
$dave_cookies = e2e_login( $dave_id );
$account      = e2e_http( 'GET', wc_get_account_endpoint_url( 'wplc-licenses' ), array( 'cookies' => $dave_cookies ) );
e2e_ok( false !== strpos( $account['body'], 'order-pay/' . $manual_order_id ) && false !== strpos( $account['body'], 'Complete renewal payment' ), 'account page shows the pay link of that order' );
$pay_url = html_entity_decode( $manual_order->get_checkout_payment_url() );
$pay     = e2e_http( 'GET', $pay_url, array( 'cookies' => $dave_cookies ) );
e2e_ok( 200 === $pay['code'] && false !== strpos( $pay['body'], 'payment_method' ) && ! preg_match( '/critical error/', $pay['body'] ), 'the order pay page opens for the customer and offers payment methods', $pay['code'] );
e2e_login( 0 );
e2e_eq( 1, count( e2e_mails_with_subject( 'renewal payment for' ) ), 'manual action required: one "payment failed / action required" email' );
\WPLC\Billing\RenewalProcessor::process( $renewal_id );
e2e_eq( 1, count( e2e_charges() ), 'manual action required: a later job run does not charge again' );

// The customer pays on the pay page (here: the gateway completes the order).
$manual_order->set_payment_method( 'wplc_e2e_fake' );
$manual_order->payment_complete( 'e2e-customer-authenticated' );
e2e_eq( $due, e2e_license( $l_manual )['paid_until'], 'payment of an automatic renewal order is booked asynchronously (not yet extended)' );
e2e_ok( e2e_run_actions( 'wplc_reconcile_paid_renewal_order' ) >= 1, 'the reconciliation job runs' );
$license = e2e_license( $l_manual );
e2e_eq( array( 'active', $utc( \WPLC\Billing\RenewalScheduler::period_end( strtotime( $due . ' UTC' ), 'year' ) ) ), array( $license['status'], $license['paid_until'] ), 'after the customer paid: license active again, paid_until + 1 year' );
e2e_eq( 'paid', e2e_renewal( $renewal_id )['status'], 'after the customer paid: renewal row paid' );
e2e_ok( in_array( 'renewal_customer_recovered', e2e_audit_actions( 'renewal', $renewal_id ), true ), 'audit log: renewal_customer_recovered' );
e2e_eq( 1, count( e2e_rows( 'renewals', "license_id = {$l_manual} AND status = 'scheduled'" ) ), 'the following period is scheduled' );
do_action( 'woocommerce_order_status_completed', $manual_order_id );
e2e_run_actions( 'wplc_reconcile_paid_renewal_order' );
e2e_eq( $license['paid_until'], e2e_license( $l_manual )['paid_until'], 'duplicate paid hooks do not extend twice' );
// The grace-end job that was scheduled when the renewal failed is still in the queue and fires on day 14.
e2e_ok( e2e_run_action( 'wplc/renewal_grace_ended', array( $l_manual ) ), 'the grace-end job scheduled at the time of the failure still runs later' );
$license = e2e_license( $l_manual );
e2e_eq( array( 'active', '1' ), array( $license['status'], $license['renewal_enabled'] ), 'a license the customer paid in the meantime is NOT expired by the old grace-end job' );

// =====================================================================================
// D. Declined renewal, customer pays the failed order during grace.
// =====================================================================================
update_option( 'wplc_e2e_fake_mode', 'decline' );
$due        = $utc( time() - 2 * DAY_IN_SECONDS );
$renewal_id = e2e_move_paid_until( $l_recover, $due );
e2e_run_action( 'wplc_process_renewal', array( $renewal_id ) );
$renewal = e2e_renewal( $renewal_id );
e2e_eq( 'past_due', e2e_license( $l_recover )['status'], '[time simulated: period ended 2 days ago] declined → past_due' );
$recover_order = wc_get_order( (int) $renewal['renewal_order_id'] );
$recover_order->set_payment_method( 'bacs' );
$recover_order->payment_complete( 'e2e-paid-by-customer' );
e2e_run_actions( 'wplc_reconcile_paid_renewal_order' );
$license = e2e_license( $l_recover );
e2e_eq( array( 'active', $utc( \WPLC\Billing\RenewalScheduler::period_end( strtotime( $due . ' UTC' ), 'year' ) ) ), array( $license['status'], $license['paid_until'] ), 'customer paid the failed order: active, period counted from the old period end (no free days)' );
update_option( 'wplc_e2e_fake_mode', 'success' );
update_option( 'wplc_e2e_fake_charges', array(), false );
e2e_run_action( 'wplc_process_renewal', array( $renewal_id ) );
e2e_eq( 0, count( e2e_charges() ), 'the still-pending retry job of the recovered renewal charges nothing' );

// =====================================================================================
// E. Customer cancels while past_due.
// =====================================================================================
update_option( 'wplc_e2e_fake_mode', 'decline' );
$due        = $utc( time() - HOUR_IN_SECONDS );
$renewal_id = e2e_move_paid_until( $l_recover, $due );
e2e_run_action( 'wplc_process_renewal', array( $renewal_id ) );
e2e_eq( 'past_due', e2e_license( $l_recover )['status'], 'declined again → past_due' );
e2e_ok( \WPLC\License\LicenseLifecycle::schedule_cancellation( $l_recover, 'e2e: customer cancels while past due', $dave_id ), 'the customer cancels the renewal while the license is past_due' );
update_option( 'wplc_e2e_fake_charges', array(), false );
e2e_run_action( 'wplc_process_renewal', array( $renewal_id ) );
e2e_eq( 0, count( e2e_charges() ), 'no further charge attempt after the cancellation' );
e2e_age_renewal( $renewal_id, 16 );
do_action( 'wplc_daily_lifecycle' );
e2e_run_actions( 'wplc/renewal_grace_ended' );
$license = e2e_license( $l_recover );
e2e_eq( 'expired', $license['status'], '[time simulated: 16 days later] a cancelled past_due license ends up expired (daily job or grace job)' );

// =====================================================================================
// F. Outcome unknown (timeout): the attempt is repeated with the SAME key and does not count.
// =====================================================================================
update_option( 'wplc_e2e_fake_mode', 'unknown' );
update_option( 'wplc_e2e_fake_charges', array(), false );
$renewal_id_m = e2e_move_paid_until( $l_manual, $utc( time() - 3 * HOUR_IN_SECONDS ) );
e2e_run_action( 'wplc_process_renewal', array( $renewal_id_m ) );
$unknown = e2e_renewal( $renewal_id_m );
e2e_ok( 'failed' === $unknown['status'] && '0' === $unknown['attempt_count'] && abs( strtotime( $unknown['retry_at'] . ' UTC' ) - ( time() + HOUR_IN_SECONDS ) ) < 120, 'unknown outcome (timeout): attempt not counted, retry within the hour', $unknown );
e2e_ok( 'failed' !== wc_get_order( (int) $unknown['renewal_order_id'] )->get_status(), 'unknown outcome: the order is not marked failed', wc_get_order( (int) $unknown['renewal_order_id'] )->get_status() );
// B-188: make the repeat due; a job that runs before retry_at does not charge.
$wpdb->update( $t['renewals'], array( 'retry_at' => $utc( time() - 5 ) ), array( 'id' => $renewal_id_m ) );
e2e_run_action( 'wplc_process_renewal', array( $renewal_id_m ) );
$keys = array_column( e2e_charges(), 'key' );
e2e_ok( 2 === count( $keys ) && $keys[0] === $keys[1], 'the repeated attempt sends the same idempotency key', $keys );


update_option( 'wplc_e2e_fake_mode', 'success' );
e2e_set( 'dave_id', $dave_id );
