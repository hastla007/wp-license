<?php
/**
 * Flow 9: personal data exporter and eraser, driven through the WordPress privacy AJAX endpoints.
 *
 * @package WPLC
 */

require getenv( 'WPLC_E2E_DIR' ) . '/lib.php';

global $wpdb;
$t        = e2e_tables();
$alice_id = (int) e2e_get( 'alice_id' );
$dave_id  = (int) e2e_get( 'dave_id' );

$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() );
$find      = static function ( array $registered, string $key ) {
	$index = 0;
	foreach ( $registered as $entry ) {
		++$index;
		if ( isset( $entry[ $key ] ) && 'WP License Captain' === $entry[ $key ] ) {
			return $index;
		}
	}
	return 0;
};
$exporter_index = $find( $exporters, 'exporter_friendly_name' );
$eraser_index   = $find( $erasers, 'eraser_friendly_name' );
e2e_ok( $exporter_index > 0, 'personal data exporter "WP License Captain" is registered' );
e2e_ok( $eraser_index > 0, 'personal data eraser "WP License Captain" is registered' );

$admin_cookies = e2e_login( (int) e2e_get( 'admin_id' ) );
$privacy_page  = e2e_http( 'GET', admin_url( 'export-personal-data.php' ), array( 'cookies' => $admin_cookies ) );
e2e_eq( 200, $privacy_page['code'], 'Tools → Export Personal Data screen loads with the plugin active' );

// --- Export for alice. ---
$request_id = wp_create_user_request( 'alice@customer.test', 'export_personal_data', array(), 'confirmed' );
e2e_ok( is_int( $request_id ) && $request_id > 0, 'export request created', is_wp_error( $request_id ) ? $request_id->get_error_message() : $request_id );
$items = array();
$done  = false;
for ( $page = 1; $page <= 10 && ! $done; $page++ ) {
	$response = e2e_http(
		'POST',
		admin_url( 'admin-ajax.php' ),
		array(
			'cookies' => $admin_cookies,
			'body'    => array(
				'action'     => 'wp-privacy-export-personal-data',
				'id'         => (string) $request_id,
				'exporter'   => (string) $exporter_index,
				'page'       => (string) $page,
				'security'   => wp_create_nonce( 'wp-privacy-export-personal-data-' . $request_id ),
				'sendAsEmail' => 'false',
			),
		)
	);
	$json = json_decode( $response['body'], true );
	if ( ! is_array( $json ) || empty( $json['success'] ) ) {
		e2e_ok( false, 'exporter AJAX call succeeds (page ' . $page . ')', substr( $response['body'], 0, 300 ) );
		break;
	}
	$items = array_merge( $items, (array) $json['data']['data'] );
	$done  = ! empty( $json['data']['done'] );
}
e2e_ok( $done, 'exporter reports done' );
$alice_licenses = e2e_rows( 'licenses', 'customer_id = ' . $alice_id );
e2e_eq( count( $alice_licenses ), count( $items ), 'export contains one item per license of the customer (' . count( $alice_licenses ) . ')' );
$flat = wp_json_encode( $items );
e2e_ok( false !== strpos( $flat, $alice_licenses[0]['key_prefix'] ) && false !== strpos( $flat, 'voucher-captain' ), 'export shows license reference and product' );
e2e_ok( false !== strpos( $flat, 'site-c.example' ) || false !== strpos( $flat, 'site-b.example' ), 'export contains the activated site URLs of the customer', substr( $flat, 0, 400 ) );
$leak = false;
foreach ( $alice_licenses as $license ) {
	if ( false !== strpos( $flat, e2e_key( $license ) ) || false !== strpos( $flat, $license['license_key_hash'] ) ) {
		$leak = true;
	}
}
e2e_ok( ! $leak, 'export contains neither full license keys nor key hashes' );
$foreign = e2e_rows( 'licenses', 'customer_id = ' . (int) e2e_get( 'bob_id' ) );
e2e_ok( $foreign && false === strpos( $flat, $foreign[0]['key_prefix'] ), 'export contains no license of another customer' );
e2e_ok( false === strpos( $flat, 'installation_secret' ) && ! preg_match( '/v1:e2e-2026:/', $flat ), 'export contains no installation secrets' );

// Unknown email.
$empty = \WPLC\Privacy\PersonalData::export_personal_data( 'nobody@nowhere.test', 1 );
e2e_eq( array( 'data' => array(), 'done' => true ), $empty, 'exporter returns nothing for an unknown email address' );

// --- Erase for dave: expired licenses are detached, active ones retained. ---
$dave_licenses = e2e_rows( 'licenses', 'customer_id = ' . $dave_id );
$terminal      = array_filter(
	$dave_licenses,
	static function ( $license ) {
		return in_array( $license['status'], array( 'expired', 'revoked' ), true );
	}
);
$living        = count( $dave_licenses ) - count( $terminal );
e2e_ok( count( $terminal ) >= 1 && $living >= 1, 'the customer has ended and running licenses (' . count( $terminal ) . ' ended, ' . $living . ' running)' );
$orders_before = count( wc_get_orders( array( 'customer_id' => $dave_id, 'limit' => -1, 'return' => 'ids', 'status' => array_keys( wc_get_order_statuses() ) ) ) );
$request_id    = wp_create_user_request( 'dave@customer.test', 'remove_personal_data', array(), 'confirmed' );
$response      = e2e_http(
	'POST',
	admin_url( 'admin-ajax.php' ),
	array(
		'cookies' => $admin_cookies,
		'body'    => array(
			'action'   => 'wp-privacy-erase-personal-data',
			'id'       => (string) $request_id,
			'eraser'   => (string) $eraser_index,
			'page'     => '1',
			'security' => wp_create_nonce( 'wp-privacy-erase-personal-data-' . $request_id ),
		),
	)
);
$json = json_decode( $response['body'], true );
e2e_ok( is_array( $json ) && ! empty( $json['success'] ) && ! empty( $json['data']['done'] ), 'eraser AJAX call succeeds and reports done', substr( $response['body'], 0, 300 ) );
e2e_eq( array( true, true ), array( (bool) ( $json['data']['items_removed'] ?? false ), (bool) ( $json['data']['items_retained'] ?? false ) ), 'eraser reports removed AND retained items' );
e2e_ok( 2 === count( (array) ( $json['data']['messages'] ?? array() ) ), 'eraser explains what was retained and what was detached', $json['data']['messages'] ?? null );
$after = e2e_rows( 'licenses', 'id IN (' . implode( ',', array_map( 'intval', array_column( $dave_licenses, 'id' ) ) ) . ')' );
$detached = 0;
$kept     = 0;
foreach ( $after as $license ) {
	if ( in_array( $license['status'], array( 'expired', 'revoked' ), true ) ) {
		$detached += '0' === $license['customer_id'] ? 1 : 0;
	} else {
		$kept += (string) $dave_id === $license['customer_id'] ? 1 : 0;
	}
}
e2e_eq( array( count( $terminal ), $living ), array( $detached, $kept ), 'ended licenses are detached from the account, running licenses stay with the customer' );
e2e_eq( count( $dave_licenses ), count( $after ), 'no license row is deleted (order evidence is kept)' );
e2e_eq( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['activations']} WHERE status <> 'active' AND license_id IN (" . implode( ',', array_map( 'intval', array_column( $terminal, 'id' ) ) ) . ')' ), 'inactive site activations (site URLs) of the ended licenses are deleted' );
e2e_eq( $orders_before, count( wc_get_orders( array( 'customer_id' => $dave_id, 'limit' => -1, 'return' => 'ids', 'status' => array_keys( wc_get_order_statuses() ) ) ) ), 'WooCommerce orders are left to WooCommerce\'s own eraser' );
$dave_cookies = e2e_login( $dave_id );
$account      = e2e_http( 'GET', wc_get_account_endpoint_url( 'wplc-licenses' ), array( 'cookies' => $dave_cookies ) );
$first_terminal = current( $terminal );
e2e_ok( 200 === $account['code'] && false === strpos( $account['body'], $first_terminal['key_prefix'] ), 'the detached license no longer appears in the customer account' );
e2e_login( 0 );

// The privacy policy text suggestion is registered.
$policy = e2e_http( 'GET', admin_url( 'options-privacy.php?tab=policyguide' ), array( 'cookies' => e2e_login( (int) e2e_get( 'admin_id' ) ) ) );
e2e_note( 'privacy policy guide mentions the plugin: ' . ( false !== strpos( $policy['body'], 'WP License Captain' ) ? 'yes' : 'no (no suggested policy text is registered)' ) );
e2e_login( 0 );
