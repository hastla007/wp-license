<?php
/**
 * Flow 6: cancel at period end + resume, refunds, admin suspend/reactivate/extend/release, manual license.
 *
 * @package WPLC
 */

require getenv( 'WPLC_E2E_DIR' ) . '/lib.php';

use WPLC\Client\LicenseClient;

global $wpdb;
$t         = e2e_tables();
$simple_id = (int) e2e_get( 'simple_id' );
$admin_id  = (int) e2e_get( 'admin_id' );
$carol_id  = e2e_customer( 'carol' );
$server    = 'https://shop.test';
$error_of  = static function ( $result ) {
	return is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : 'no error';
};

// --- Purchase with the automatic-renewal consent and a tokenizing gateway (quantity 3). ---
update_option( 'wplc_e2e_fake_mode', 'success' );
$order    = e2e_checkout( $carol_id, array( $simple_id => 3 ), 'wplc_e2e_fake', true );
$order_id = (int) $order->get_id();
$licenses = e2e_order_licenses( $order_id );
e2e_ok( '' !== (string) $order->get_meta( '_wplc_recurring_consent_at', true ), 'checkout stored the renewal consent timestamp on the order' );
e2e_eq( 3, count( $licenses ), 'quantity 3 with consent issues 3 licenses' );
$token_id = e2e_fake_card( $carol_id );
e2e_eq(
	array( array( '1', 'wplc_e2e_fake', (string) $token_id ) ),
	array_values( array_unique( array_map( static function ( $l ) { return array( $l['renewal_enabled'], $l['payment_gateway_id'], $l['payment_token_id'] ); }, $licenses ), SORT_REGULAR ) ),
	'all three licenses have automatic renewal enabled with the gateway and the saved token'
);
$renewals = e2e_rows( 'renewals', 'license_id IN (' . implode( ',', array_map( 'intval', array_column( $licenses, 'id' ) ) ) . ') ORDER BY id' );
e2e_eq( 3, count( $renewals ), 'one scheduled renewal row per license' );
e2e_ok( $renewals && 'scheduled' === $renewals[0]['status'] && $renewals[0]['period_start'] === $licenses[0]['paid_until'], 'renewal row: scheduled for the end of the paid period', $renewals[0] ?? null );
e2e_eq( 3, count( array_filter( array_map( static function ( $r ) { return e2e_pending_actions( 'wplc_process_renewal', array( (int) $r['id'] ) ); }, $renewals ) ) ), 'an Action Scheduler job wplc_process_renewal is pending for each renewal' );

// Without consent the same gateway does not enable renewal.
$no_consent = e2e_checkout( $carol_id, array( $simple_id => 1 ), 'wplc_e2e_fake', false );
$no_consent_license = e2e_order_licenses( (int) $no_consent->get_id() );
e2e_ok( 1 === count( $no_consent_license ) && '0' === $no_consent_license[0]['renewal_enabled'] && '0' === $no_consent_license[0]['payment_token_id'], 'without the consent checkbox the license does not renew automatically and stores no token' );
// Consent with a gateway that cannot renew: order note, no renewal.
$bank = e2e_checkout( $carol_id, array( $simple_id => 1 ), 'bacs', true );
$bank_license = e2e_order_licenses( (int) $bank->get_id() );
$bank_notes   = wp_list_pluck( wc_get_order_notes( array( 'order_id' => $bank->get_id() ) ), 'content' );
e2e_ok( 1 === count( $bank_license ) && '0' === $bank_license[0]['renewal_enabled'] && (bool) preg_grep( '/chosen payment method does not support it/', $bank_notes ), 'consent + bank transfer: no automatic renewal, order note explains why', $bank_notes );

// --- Cancel at period end and resume, through the account forms. ---
$license_a     = $licenses[0];
$carol_cookies = e2e_login( $carol_id );
$account       = e2e_http( 'GET', wc_get_account_endpoint_url( 'wplc-licenses' ), array( 'cookies' => $carol_cookies ) );
$cancel_form   = e2e_form( $account['body'], array( 'value="wplc_cancel_license"', 'name="license_id" value="' . (int) $license_a['id'] . '"' ) );
e2e_ok( is_array( $cancel_form ), 'account page offers "cancel renewal" for a renewing license' );
e2e_ok( null === e2e_form( $account['body'], array( 'value="wplc_cancel_license"', 'name="license_id" value="' . (int) $no_consent_license[0]['id'] . '"' ) ), 'no cancel form for a license that does not renew automatically' );
$response = e2e_submit( (array) $cancel_form, $carol_cookies );
$row      = e2e_license( (int) $license_a['id'] );
e2e_ok( 302 === $response['code'] && '1' === $row['cancel_at_period_end'] && 'active' === $row['status'], 'cancellation is scheduled: cancel_at_period_end = 1, license stays active', array( $response['code'], $row['cancel_at_period_end'], $row['status'] ) );
e2e_eq( $license_a['paid_until'], $row['paid_until'], 'paid_until is unchanged by the cancellation' );
e2e_ok( in_array( 'cancellation_scheduled', e2e_audit_actions( 'license', (int) $license_a['id'] ), true ), 'audit log: cancellation_scheduled' );
$account     = e2e_http( 'GET', $response['location'], array( 'cookies' => $carol_cookies ) );
$resume_form = e2e_form( $account['body'], array( 'value="wplc_resume_license"', 'name="license_id" value="' . (int) $license_a['id'] . '"' ) );
e2e_ok( is_array( $resume_form ) && null === e2e_form( $account['body'], array( 'value="wplc_cancel_license"', 'name="license_id" value="' . (int) $license_a['id'] . '"' ) ), 'account page now offers "resume" instead of "cancel" and renders after the redirect' );

// A due renewal of a cancelled license is not charged.
$renewal_a = e2e_rows( 'renewals', 'license_id = ' . (int) $license_a['id'] )[0];
$charges_before = count( (array) get_option( 'wplc_e2e_fake_charges', array() ) );
\WPLC\Billing\RenewalProcessor::process( (int) $renewal_a['id'] );
wp_cache_delete( 'wplc_e2e_fake_charges', 'options' );
e2e_eq( $charges_before, count( (array) get_option( 'wplc_e2e_fake_charges', array() ) ), 'processing the renewal of a cancelled license charges nothing' );
e2e_eq( 'cancelled', e2e_rows( 'renewals', 'id = ' . (int) $renewal_a['id'] )[0]['status'], 'the renewal row is cancelled' );

$response = e2e_submit( (array) $resume_form, $carol_cookies );
$row      = e2e_license( (int) $license_a['id'] );
e2e_ok( 302 === $response['code'] && '0' === $row['cancel_at_period_end'] && '1' === $row['renewal_enabled'], 'resume: cancel_at_period_end = 0, renewal still enabled', array( $row['cancel_at_period_end'], $row['renewal_enabled'] ) );
$renewal_after = e2e_rows( 'renewals', 'license_id = ' . (int) $license_a['id'] . ' ORDER BY id DESC' );
e2e_ok( $renewal_after && 'scheduled' === $renewal_after[0]['status'], 'after resuming, a renewal for the period is scheduled again', array_column( $renewal_after, 'status' ) );
e2e_ok( count( e2e_pending_actions( 'wplc_process_renewal', array( (int) $renewal_after[0]['id'] ) ) ) >= 1, 'after resuming, the renewal job is pending again' );

// Another customer cannot cancel it.
$bob_cookies = e2e_login( (int) e2e_get( 'bob_id' ) );
e2e_http( 'POST', admin_url( 'admin-post.php' ), array( 'cookies' => $bob_cookies, 'body' => array( 'action' => 'wplc_cancel_license', 'license_id' => (string) $license_a['id'], '_wpnonce' => wp_create_nonce( 'wplc_cancel_license_' . (int) $license_a['id'] ) ) ) );
e2e_eq( '0', e2e_license( (int) $license_a['id'] )['cancel_at_period_end'], 'another customer cannot cancel the license' );
e2e_login( 0 );

// --- Partial refund revokes exactly one license (the highest unit). ---
$GLOBALS['wplc_e2e_home'] = 'https://carol-site.example';
add_filter(
	'home_url',
	static function ( $url, $path ) {
		return '' !== $GLOBALS['wplc_e2e_home'] && ( '' === $path || '/' === $path ) ? $GLOBALS['wplc_e2e_home'] : $url;
	},
	10,
	2
);
delete_option( 'wplc_e2e_client_carol3' );
$client_carol3 = e2e_client( $server, 'voucher-captain', '1.0.0', 'wplc_e2e_client_carol3' );
$activated     = $client_carol3->activate( e2e_key( $licenses[2] ) );
e2e_ok( is_array( $activated ), 'the license that will be refunded is activated on a site first', $error_of( $activated ) );

wplc_e2e_clear_mails();
$item   = current( $order->get_items( 'line_item' ) );
$refund = wc_create_refund(
	array(
		'order_id'   => $order_id,
		'amount'     => wc_format_decimal( (float) $item->get_total() / 3, 2 ),
		'reason'     => 'e2e partial refund',
		'line_items' => array( $item->get_id() => array( 'qty' => 1, 'refund_total' => wc_format_decimal( (float) $item->get_total() / 3, 2 ) ) ),
	)
);
e2e_ok( ! is_wp_error( $refund ), 'partial refund (1 of 3 units) created', is_wp_error( $refund ) ? $refund->get_error_message() : null );
$statuses = array_column( e2e_order_licenses( $order_id ), 'status' );
e2e_eq( array( 'active', 'active', 'revoked' ), $statuses, 'partial refund revokes exactly one license: the last unit' );
$revoked = e2e_license( (int) $licenses[2]['id'] );
e2e_ok( ! empty( $revoked['revoked_at'] ) && '0' === $revoked['renewal_enabled'] && null === $revoked['next_payment_at'], 'revoked license: revoked_at set, renewal disabled' );
e2e_eq( array( 'revoked' ), array_values( array_unique( array_column( e2e_rows( 'entitlements', 'license_id = ' . (int) $licenses[2]['id'] ), 'status' ) ) ), 'revoked license: entitlements are revoked' );
e2e_eq( 'cancelled', e2e_rows( 'renewals', 'license_id = ' . (int) $licenses[2]['id'] )[0]['status'], 'revoked license: its renewal is cancelled' );
$check = $client_carol3->check_entitlements();
// A revoked license loses its installations at once; the site gets a signed answer without rights
// ("deactivated", or "revoked" while the installation still exists).
e2e_ok( ( is_array( $check ) && in_array( $check['license_status'] ?? '', array( 'revoked', 'deactivated' ), true ) && false === ( $check['access'] ?? null ) && empty( $check['entitlements'] ) ) || is_wp_error( $check ), 'the activated site of the revoked license gets no entitlements any more', is_array( $check ) ? $check : $error_of( $check ) );
e2e_note( 'entitlements check after revocation answered: ' . ( is_array( $check ) ? wp_json_encode( array( 'license_status' => $check['license_status'] ?? null, 'entitlements' => $check['entitlements'] ?? null ) ) : $error_of( $check ) ) );
delete_option( 'wplc_e2e_client_other' );
$other_site = e2e_client( $server, 'voucher-captain', '1.0.0', 'wplc_e2e_client_other' );
$GLOBALS['wplc_e2e_home'] = 'https://carol-new.example';
$denied = $other_site->activate( e2e_key( $licenses[2] ) );
e2e_ok( is_wp_error( $denied ) && 403 === (int) ( $denied->get_error_data()['status'] ?? 0 ), 'a revoked license cannot be activated (403)', $error_of( $denied ) );
$GLOBALS['wplc_e2e_home'] = '';

// The refund hook firing again must not revoke a second license.
do_action( 'woocommerce_order_refunded', $order_id, $refund->get_id() );
e2e_eq( array( 'active', 'active', 'revoked' ), array_column( e2e_order_licenses( $order_id ), 'status' ), 'a repeated woocommerce_order_refunded for the same refund revokes nothing more' );

// A refund of money only (no line quantity) revokes nothing.
$money_only = wc_create_refund( array( 'order_id' => $order_id, 'amount' => '5.00', 'reason' => 'e2e goodwill' ) );
e2e_eq( array( 'active', 'active', 'revoked' ), array_column( e2e_order_licenses( $order_id ), 'status' ), 'a partial refund of an amount without units revokes nothing' );

// --- Order status → refunded revokes the rest. ---
$order = wc_get_order( $order_id );
$order->update_status( 'refunded', 'e2e full refund' );
e2e_eq( array( 'revoked', 'revoked', 'revoked' ), array_column( e2e_order_licenses( $order_id ), 'status' ), 'order status "refunded" revokes the remaining licenses' );
e2e_eq( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['renewals']} WHERE license_id IN (" . implode( ',', array_map( 'intval', array_column( $licenses, 'id' ) ) ) . ") AND status IN ('scheduled','failed','processing')" ), 'no renewal of the refunded licenses is left open' );
$charges_before = count( (array) get_option( 'wplc_e2e_fake_charges', array() ) );
foreach ( e2e_rows( 'renewals', 'license_id IN (' . implode( ',', array_map( 'intval', array_column( $licenses, 'id' ) ) ) . ')' ) as $renewal ) {
	\WPLC\Billing\RenewalProcessor::process( (int) $renewal['id'] );
}
wp_cache_delete( 'wplc_e2e_fake_charges', 'options' );
e2e_eq( $charges_before, count( (array) get_option( 'wplc_e2e_fake_charges', array() ) ), 'running the old renewal jobs of revoked licenses charges nothing' );

// --- Admin: suspend, reactivate, extend. ---
$license_ids   = (array) e2e_get( 'license_ids' );
$target_id     = (int) $license_ids[1];
$before        = e2e_license( $target_id );
$admin_cookies = e2e_login( $admin_id );
$detail_url    = admin_url( 'admin.php?page=wplc-licenses&license=' . $target_id );
$detail        = e2e_http( 'GET', $detail_url, array( 'cookies' => $admin_cookies ) );
$suspend_form  = e2e_form( $detail['body'], array( 'value="wplc_admin_license_status"', 'name="status" value="suspended"' ) );
e2e_ok( 200 === $detail['code'] && is_array( $suspend_form ), 'license detail offers "Suspend"', $detail['code'] );

$no_reason = e2e_submit( (array) $suspend_form, $admin_cookies, array( 'reason' => '   ' ) );
e2e_eq( 'active', e2e_license( $target_id )['status'], 'suspending without a reason changes nothing' );
$notice_page = e2e_http( 'GET', $no_reason['location'], array( 'cookies' => $admin_cookies ) );
e2e_ok( 200 === $notice_page['code'] && false !== strpos( $notice_page['body'], 'notice-error' ), 'the missing reason is reported as an error notice', array( $no_reason['code'], $no_reason['location'] ) );

$response = e2e_submit( (array) $suspend_form, $admin_cookies, array( 'reason' => 'e2e: payment dispute' ) );
$row      = e2e_license( $target_id );
e2e_ok( 302 === $response['code'] && 'suspended' === $row['status'] && 'e2e: payment dispute' === $row['status_reason'], 'admin suspend: status suspended with the reason', array( $response['code'], $row['status'], $row['status_reason'] ) );
e2e_eq( array( 'suspended' ), array_values( array_unique( array_column( e2e_rows( 'entitlements', 'license_id = ' . $target_id ), 'status' ) ) ), 'suspended license: entitlements suspended' );
$audit = e2e_rows( 'audit_log', "object_type = 'license' AND object_id = {$target_id} ORDER BY id DESC LIMIT 1" )[0];
e2e_eq( array( 'admin_status_change', (string) $admin_id, 'e2e: payment dispute' ), array( $audit['action'], $audit['actor_user_id'], $audit['reason'] ), 'audit log: admin_status_change with actor and reason' );
$detail = e2e_http( 'GET', $response['location'], array( 'cookies' => $admin_cookies ) );
e2e_ok( 200 === $detail['code'] && false !== strpos( $detail['body'], 'notice-success' ) && false !== strpos( $detail['body'], 'e2e: payment dispute' ), 'detail page after the redirect shows a success notice and the audit entry' );
e2e_ok( null === e2e_form( $detail['body'], array( 'value="wplc_admin_extend_license"' ) ), 'a suspended license has no "Change term" form' );
delete_option( 'wplc_e2e_client_suspended' );
$GLOBALS['wplc_e2e_home'] = 'https://suspended.example';
$suspended_client = e2e_client( $server, 'voucher-captain', '1.0.0', 'wplc_e2e_client_suspended' );
$denied           = $suspended_client->activate( e2e_key( $before ) );
e2e_ok( is_wp_error( $denied ) && 403 === (int) ( $denied->get_error_data()['status'] ?? 0 ), 'a suspended license cannot be activated (403)', $error_of( $denied ) );

$reactivate_form = e2e_form( $detail['body'], array( 'value="wplc_admin_license_status"', 'name="status" value="active"' ) );
e2e_ok( is_array( $reactivate_form ), 'suspended license offers "Reactivate"' );
e2e_submit( (array) $reactivate_form, $admin_cookies, array( 'reason' => 'e2e: dispute resolved' ) );
e2e_eq( 'active', e2e_license( $target_id )['status'], 'admin reactivate: status active' );
e2e_eq( array( 'active' ), array_values( array_unique( array_column( e2e_rows( 'entitlements', 'license_id = ' . $target_id ), 'status' ) ) ), 'reactivated license: entitlements active again' );
$allowed = $suspended_client->activate( e2e_key( $before ) );
e2e_ok( is_array( $allowed ), 'the reactivated license can be activated', $error_of( $allowed ) );
$GLOBALS['wplc_e2e_home'] = '';

$detail      = e2e_http( 'GET', $detail_url, array( 'cookies' => $admin_cookies ) );
$extend_form = e2e_form( $detail['body'], array( 'value="wplc_admin_extend_license"' ) );
e2e_ok( is_array( $extend_form ), 'active license offers "Change term"' );
$new_date = ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( '+2 years' )->format( 'Y-m-d' );
$response = e2e_submit( (array) $extend_form, $admin_cookies, array( 'paid_until' => $new_date, 'reason' => 'e2e: goodwill extension' ) );
$row      = e2e_license( $target_id );
$expected = ( new DateTimeImmutable( $new_date . ' 23:59:59', wp_timezone() ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
e2e_eq( $expected, $row['paid_until'], 'admin extend: paid_until is the end of the chosen day in the site timezone, stored in UTC' );
e2e_eq( array( $expected ), array_values( array_unique( array_column( e2e_rows( 'entitlements', 'license_id = ' . $target_id ), 'ends_at' ) ) ), 'admin extend: entitlement end dates follow' );
e2e_ok( in_array( 'admin_extend', e2e_audit_actions( 'license', $target_id ), true ) || (bool) preg_grep( '/extend/', e2e_audit_actions( 'license', $target_id ) ), 'audit log has the extension', e2e_audit_actions( 'license', $target_id ) );
$past = e2e_submit( (array) $extend_form, $admin_cookies, array( 'paid_until' => '2020-01-01', 'reason' => 'e2e: past date' ) );
e2e_eq( $expected, e2e_license( $target_id )['paid_until'], 'a date in the past is refused' );
$check = $suspended_client;
$GLOBALS['wplc_e2e_home'] = 'https://suspended.example';
$entitlements = $suspended_client->check_entitlements();
e2e_ok( is_array( $entitlements ) && $expected === ( $entitlements['paid_until'] ?? null ), 'the activated site sees the new paid_until', $error_of( $entitlements ) );
$GLOBALS['wplc_e2e_home'] = '';

// --- Admin releases a site activation. ---
$detail       = e2e_http( 'GET', $detail_url, array( 'cookies' => $admin_cookies ) );
$release_form = e2e_form( $detail['body'], array( 'value="wplc_admin_release_activation"' ) );
e2e_ok( is_array( $release_form ) && false !== strpos( $detail['body'], 'suspended.example' ), 'detail page lists the activation with a release form' );
e2e_submit( (array) $release_form, $admin_cookies, array( 'reason' => 'e2e: customer lost access to the site' ) );
e2e_eq( 'deactivated', (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$t['activations']} WHERE id = %d", (int) ( $release_form['activation_id'] ?? 0 ) ) ), 'admin release: activation is deactivated' );
e2e_ok( in_array( 'admin_site_released', e2e_audit_actions( 'activation', (int) ( $release_form['activation_id'] ?? 0 ) ), true ), 'audit log: admin_site_released' );

// A shop manager may do this, a customer may not.
$carol_cookies = e2e_login( $carol_id );
$forbidden     = e2e_http( 'POST', admin_url( 'admin-post.php' ), array( 'cookies' => $carol_cookies, 'body' => array( 'action' => 'wplc_admin_license_status', 'license_id' => (string) $target_id, 'status' => 'revoked', 'reason' => 'x', '_wpnonce' => wp_create_nonce( 'wplc_admin_license_status_' . $target_id ) ) ) );
e2e_ok( 'active' === e2e_license( $target_id )['status'] && in_array( $forbidden['code'], array( 302, 403 ), true ), 'a customer cannot use the admin status action', $forbidden['code'] );

// --- Manual license creation through the admin form. ---
wplc_e2e_clear_mails();
$admin_cookies = e2e_login( $admin_id );
$add_page      = e2e_http( 'GET', admin_url( 'admin.php?page=wplc-licenses&view=add' ), array( 'cookies' => $admin_cookies ) );
$add_form      = e2e_form( $add_page['body'], array( 'value="wplc_admin_add_license"' ) );
$plan_id       = (int) $wpdb->get_var( "SELECT id FROM {$t['plans']} WHERE plan_key = 'agency-monthly'" );
e2e_ok( is_array( $add_form ) && false !== strpos( $add_page['body'], '<option value="' . $plan_id . '"' ), 'add form offers the agency-monthly plan' );
$end_date = ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( '+6 months' )->format( 'Y-m-d' );
$count_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['licenses']}" );
$unknown = e2e_submit( (array) $add_form, $admin_cookies, array( 'customer' => 'nobody@nowhere.test', 'plan_id' => (string) $plan_id, 'paid_until' => $end_date, 'reason' => 'e2e' ) );
e2e_eq( $count_before, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['licenses']}" ), 'an unknown customer email creates no license' );
$response = e2e_submit( (array) $add_form, $admin_cookies, array( 'customer' => 'bob@customer.test', 'plan_id' => (string) $plan_id, 'paid_until' => $end_date, 'reason' => 'e2e: migrated customer' ) );
$manual   = $wpdb->get_row( "SELECT * FROM {$t['licenses']} ORDER BY id DESC LIMIT 1", ARRAY_A );
e2e_ok( 302 === $response['code'] && $count_before + 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['licenses']}" ) && false !== strpos( $response['location'], 'license=' . (int) $manual['id'] ), 'manual license created, redirect to its detail page', array( $response['code'], $response['location'] ) );
e2e_eq(
	array( (string) e2e_get( 'bob_id' ), 'affiliate-captain', 'agency-monthly', 'active', '0', 'month', '1990', null ),
	array( $manual['customer_id'], $manual['product_key'], $manual['plan_key'], $manual['status'], $manual['renewal_enabled'], $manual['billing_interval'], $manual['renewal_amount_minor'], $manual['source_order_id'] ),
	'manual license: customer, plan, active, no automatic renewal, renewal price from the plan, no order'
);
e2e_eq( ( new DateTimeImmutable( $end_date . ' 23:59:59', wp_timezone() ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ), $manual['paid_until'], 'manual license: paid_until = end of the chosen day' );
e2e_ok( count( e2e_rows( 'entitlements', 'license_id = ' . (int) $manual['id'] ) ) >= 3, 'manual license has the plan entitlements' );
e2e_ok( in_array( 'license_issued_manually', e2e_audit_actions( 'license', (int) $manual['id'] ), true ), 'audit log: license_issued_manually' );
$created_page = e2e_http( 'GET', $response['location'], array( 'cookies' => $admin_cookies ) );
e2e_ok( 200 === $created_page['code'] && false !== strpos( $created_page['body'], $manual['key_prefix'] ) && false !== strpos( $created_page['body'], 'e2e: migrated customer' ), 'detail page of the manual license renders with its audit entry' );
$issued_mails = array_filter( wplc_e2e_mails(), static function ( $mail ) { return false !== strpos( (string) $mail['subject'], 'license is ready' ); } );
e2e_ok( 1 === count( $issued_mails ) && false !== strpos( wp_json_encode( current( $issued_mails )['to'] ), 'bob@customer.test' ), 'the customer gets the "license issued" email for the manual license', count( $issued_mails ) );
$bob_cookies = e2e_login( (int) e2e_get( 'bob_id' ) );
$bob_account = e2e_http( 'GET', wc_get_account_endpoint_url( 'wplc-licenses' ), array( 'cookies' => $bob_cookies ) );
e2e_ok( false !== strpos( $bob_account['body'], $manual['key_prefix'] ) && false !== strpos( $bob_account['body'], e2e_key( $manual ) ), 'the manual license appears in the customer account with its key' );
delete_option( 'wplc_e2e_client_manual' );
$GLOBALS['wplc_e2e_home'] = 'https://bob-agency.example';
$manual_client = e2e_client( $server, 'affiliate-captain', '2.0.0', 'wplc_e2e_client_manual' );
$activated     = $manual_client->activate( e2e_key( $manual ) );
e2e_ok( is_array( $activated ) && 10 === ( $activated['site_limit'] ?? null ), 'the manual license activates on a site (limit 10 from the plan)', $error_of( $activated ) );
$GLOBALS['wplc_e2e_home'] = '';
e2e_login( 0 );

e2e_set( 'carol_id', $carol_id );
e2e_set( 'manual_license_id', (int) $manual['id'] );
