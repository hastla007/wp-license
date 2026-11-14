<?php
/**
 * Flow 4: the bundled LicenseClient against the REST API over real HTTP.
 *
 * Every "site" is this CLI process with a filtered home_url() and its own credentials option;
 * the server is the PHP server process (separate process, real HTTP, real signatures).
 *
 * @package WPLC
 */

require getenv( 'WPLC_E2E_DIR' ) . '/lib.php';

use WPLC\Client\LicenseClient;

global $wpdb;
$t           = e2e_tables();
$license_ids = (array) e2e_get( 'license_ids' );
$license     = e2e_license( (int) $license_ids[0] );
$key         = e2e_key( $license );
$server      = 'https://shop.test';

$GLOBALS['wplc_e2e_home'] = '';
add_filter(
	'home_url',
	static function ( $url, $path ) {
		if ( '' !== $GLOBALS['wplc_e2e_home'] && ( '' === $path || '/' === $path ) ) {
			return $GLOBALS['wplc_e2e_home'];
		}
		return $url;
	},
	10,
	2
);
$as_site = static function ( string $home ) {
	$GLOBALS['wplc_e2e_home'] = $home;
};
$activations = static function ( int $license_id ) {
	return e2e_rows( 'activations', 'license_id = ' . $license_id . ' ORDER BY id ASC' );
};
$error_of = static function ( $result ) {
	return is_wp_error( $result ) ? $result->get_error_code() . ' (' . ( $result->get_error_data()['status'] ?? '-' ) . '): ' . $result->get_error_message() : 'no error';
};
foreach ( array( 'site_a', 'site_b', 'site_a_staging', 'site_a_staging2', 'site_dev', 'site_local', 'site_a_restored', 'other_product' ) as $name ) {
	delete_option( 'wplc_e2e_client_' . $name );
}

// --- Client refuses a plain-HTTP server URL. ---
$insecure = new LicenseClient( 'http://shop.test', 'voucher-captain', '1.0.0', 'wplc_e2e_client_site_a' );
$result   = $insecure->activate( $key );
e2e_ok( is_wp_error( $result ) && 'wplc_configuration_invalid' === $result->get_error_code(), 'client refuses an http:// license server', $error_of( $result ) );

// --- Activate site A. ---
$as_site( 'https://site-a.example' );
$client_a = new LicenseClient( $server, 'voucher-captain', '1.0.0', 'wplc_e2e_client_site_a' );
$result   = $client_a->activate( $key );
e2e_ok( is_array( $result ), 'site A: activation succeeds', $error_of( $result ) );
$credentials_a = (array) get_option( 'wplc_e2e_client_site_a', array() );
if ( is_array( $result ) ) {
	e2e_eq( 'active', $result['license_status'] ?? null, 'site A: license_status active' );
	e2e_eq( 1, $result['site_limit'] ?? null, 'site A: site_limit 1' );
	e2e_eq( $license['paid_until'], $result['paid_until'] ?? null, 'site A: paid_until equals the license row' );
	e2e_ok( true === ( $result['entitlements']['voucher.custom_branding'] ?? null ) && 500 === ( $result['entitlements']['voucher.monthly_limit'] ?? null ) && 'priority' === ( $result['entitlements']['support.level'] ?? null ), 'site A: activation response carries the entitlements with their JSON types', $result['entitlements'] ?? null );
	e2e_ok( ! empty( $result['cache_expires_at'] ) && ! empty( $result['server_time'] ), 'site A: server_time and cache_expires_at present' );
}
e2e_eq( 1, (int) ( $credentials_a['signed_payload'] ?? 0 ), 'client stored that the server signs `payload` (payload/payload_signature path was used)' );
e2e_ok( ! isset( $credentials_a['license_key'] ) && false === strpos( wp_json_encode( $credentials_a ), $key ), 'client option does not store the license key' );
$rows = $activations( (int) $license['id'] );
e2e_eq( 1, count( $rows ), 'server has 1 activation row' );
if ( $rows ) {
	e2e_eq( array( 'https://site-a.example', 'production', 'active', '1.0.0' ), array( $rows[0]['site_url'], $rows[0]['environment'], $rows[0]['status'], $rows[0]['product_version'] ), 'activation row: site URL, environment, status, product version' );
	e2e_ok( '' !== $rows[0]['installation_secret_hash'] && 0 === strpos( $rows[0]['installation_secret_encrypted'], 'v1:e2e-2026:' ) && false === strpos( $rows[0]['installation_secret_encrypted'], (string) $credentials_a['installation_secret'] ), 'installation secret is stored hashed + encrypted with the configured key ID, not in plaintext', substr( $rows[0]['installation_secret_encrypted'], 0, 20 ) );
}

// --- Raw envelope: verify both signatures by hand (independent of the client's code path). ---
$raw_secret = bin2hex( random_bytes( 32 ) );
$raw_id     = wp_generate_uuid4();
$raw_body   = wp_json_encode(
	array(
		'license_key'         => $key,
		'product_key'         => 'voucher-captain',
		'installation_id'     => $raw_id,
		'installation_secret' => $raw_secret,
		'site_url'            => 'https://site-a.example',
		'environment'         => 'development',
		'product_version'     => '1.0.0',
		'wp_version'          => '6.8',
		'php_version'         => '8.3',
	)
);
$raw = e2e_http( 'POST', $server . '/wp-json/wplc/v1/activations', array( 'headers' => array( 'Content-Type' => 'application/json' ), 'body' => $raw_body ) );
e2e_eq( 400, $raw['code'], 'raw POST: development activation for a public host is rejected with 400' );
e2e_eq( 'development_site_invalid', json_decode( $raw['body'], true )['code'] ?? null, 'raw POST: error code development_site_invalid' );

// --- Entitlements check (HMAC-authenticated request, nonce-bound signed response). ---
$check = $client_a->check_entitlements();
e2e_ok( is_array( $check ) && 'active' === ( $check['license_status'] ?? null ) && true === ( $check['entitlements']['voucher.pdf_templates'] ?? null ), 'site A: signed entitlements check returns the entitlements', $error_of( $check ) );
$seen = $wpdb->get_var( $wpdb->prepare( "SELECT last_seen_at FROM {$t['activations']} WHERE installation_uuid = %s", (string) $credentials_a['installation_id'] ) );
e2e_ok( ! empty( $seen ), 'entitlements check updates last_seen_at' );

// A second activate() on the same site re-uses the stored credentials (no new activation row).
$again = $client_a->activate( $key );
e2e_ok( is_array( $again ) && 1 === count( $activations( (int) $license['id'] ) ), 'site A: calling activate() again returns the current state without a second activation', $error_of( $again ) );

// --- Signature checks on the wire. ---
$sign = static function ( string $method, string $route, string $body, array $credentials, array $override = array() ) {
	$timestamp = $override['timestamp'] ?? (string) time();
	$nonce     = $override['nonce'] ?? bin2hex( random_bytes( 16 ) );
	$secret    = $override['secret'] ?? (string) $credentials['installation_secret'];

	return array(
		'Content-Type'           => 'application/json',
		'X-WPLC-Installation-Id' => (string) $credentials['installation_id'],
		'X-WPLC-Timestamp'       => $timestamp,
		'X-WPLC-Nonce'           => $nonce,
		'X-WPLC-Signature'       => hash_hmac( 'sha256', implode( "\n", array( $method, $route, $timestamp, $nonce, hash( 'sha256', $body ) ) ), $secret ),
	);
};
$body    = '{"product_version":"1.0.0"}';
$headers = $sign( 'POST', '/wplc/v1/entitlements/check', $body, $credentials_a );
$signed  = e2e_http( 'POST', $server . '/wp-json/wplc/v1/entitlements/check', array( 'headers' => $headers, 'body' => $body ) );
$envelope = json_decode( $signed['body'], true );
e2e_eq( 200, $signed['code'], 'hand-signed entitlements request is accepted' );
e2e_ok( is_array( $envelope ) && isset( $envelope['data'], $envelope['signature'], $envelope['payload'], $envelope['payload_signature'] ), 'response envelope has data, signature, payload and payload_signature' );
if ( is_array( $envelope ) && isset( $envelope['payload'] ) ) {
	e2e_ok( hash_equals( hash_hmac( 'sha256', (string) $envelope['payload'], (string) $credentials_a['installation_secret'] ), (string) $envelope['payload_signature'] ), 'payload_signature = HMAC-SHA-256(payload as transmitted, installation secret)' );
	$payload = json_decode( (string) $envelope['payload'], true );
	e2e_eq( $headers['X-WPLC-Nonce'], $payload['nonce'] ?? null, 'signed payload echoes the request nonce' );
	e2e_eq( $envelope['data'], $payload['data'] ?? null, 'payload.data equals the top-level data' );
	e2e_ok( hash_equals( hash_hmac( 'sha256', (string) wp_json_encode( $envelope['data'], JSON_UNESCAPED_SLASHES ), (string) $credentials_a['installation_secret'] ), (string) $envelope['signature'] ), 'legacy `signature` verifies over the re-encoded data' );
	$tampered = $envelope;
	$tampered['payload'] = str_replace( '"active"', '"activE"', (string) $envelope['payload'] );
	e2e_eq( 'wplc_signature_invalid', LicenseClient::verified_data( $tampered, (string) $credentials_a['installation_secret'], $headers['X-WPLC-Nonce'] ), 'client rejects a tampered payload' );
	e2e_eq( 'wplc_nonce_mismatch', LicenseClient::verified_data( $envelope, (string) $credentials_a['installation_secret'], 'another-nonce-0000' ), 'client rejects a response that answers a different request (nonce)' );
	$stripped = $envelope;
	unset( $stripped['payload'], $stripped['payload_signature'] );
	e2e_eq( 'wplc_signature_invalid', LicenseClient::verified_data( $stripped, (string) $credentials_a['installation_secret'], $headers['X-WPLC-Nonce'], true ), 'client rejects a response with the payload stripped once the server is known to send it' );
}
e2e_ok( false !== strpos( (string) ( $signed['headers']['cache-control'] ?? '' ), 'no-store' ), 'signed response is sent with Cache-Control: no-store', $signed['headers']['cache-control'] ?? null );
$replay = e2e_http( 'POST', $server . '/wp-json/wplc/v1/entitlements/check', array( 'headers' => $headers, 'body' => $body ) );
e2e_eq( array( 409, 'request_replayed' ), array( $replay['code'], json_decode( $replay['body'], true )['code'] ?? null ), 'replaying the same nonce is rejected (409 request_replayed)' );
$bad = e2e_http( 'POST', $server . '/wp-json/wplc/v1/entitlements/check', array( 'headers' => $sign( 'POST', '/wplc/v1/entitlements/check', $body, $credentials_a, array( 'secret' => str_repeat( 'a', 64 ) ) ), 'body' => $body ) );
e2e_eq( 401, $bad['code'], 'a request signed with a wrong secret is rejected (401)' );
$old = e2e_http( 'POST', $server . '/wp-json/wplc/v1/entitlements/check', array( 'headers' => $sign( 'POST', '/wplc/v1/entitlements/check', $body, $credentials_a, array( 'timestamp' => (string) ( time() - 900 ) ) ), 'body' => $body ) );
e2e_eq( array( 401, 'request_expired' ), array( $old['code'], json_decode( $old['body'], true )['code'] ?? null ), 'a 15 minute old timestamp is rejected (401 request_expired)' );
$changed = e2e_http( 'POST', $server . '/wp-json/wplc/v1/entitlements/check', array( 'headers' => $sign( 'POST', '/wplc/v1/entitlements/check', $body, $credentials_a ), 'body' => '{"product_version":"9.9.9"}' ) );
e2e_eq( 401, $changed['code'], 'a body that differs from the signed body is rejected (401)' );

// --- Wrong key, wrong product. ---
$as_site( 'https://other.example' );
$other  = new LicenseClient( $server, 'affiliate-captain', '1.0.0', 'wplc_e2e_client_other_product' );
$result = $other->activate( $key );
e2e_ok( is_wp_error( $result ) && 'product_mismatch' === $result->get_error_code(), 'a key of another product is refused (product_mismatch)', $error_of( $result ) );
$result = $other->activate( 'WPLC-' . strtoupper( substr( hash( 'sha256', 'nope' ), 0, 40 ) ) );
e2e_ok( is_wp_error( $result ) && 'invalid_key' === $result->get_error_code(), 'an unknown key is refused (invalid_key)', $error_of( $result ) );

// --- Second live site: site limit. ---
$as_site( 'https://site-b.example' );
$client_b = new LicenseClient( $server, 'voucher-captain', '1.0.0', 'wplc_e2e_client_site_b' );
$result   = $client_b->activate( $key );
e2e_ok( is_wp_error( $result ) && 'site_limit' === $result->get_error_code() && 409 === (int) ( $result->get_error_data()['status'] ?? 0 ), 'site B: second live activation is refused with 409 site_limit', $error_of( $result ) );
e2e_ok( false === get_option( 'wplc_e2e_client_site_b', false ), 'site B: no credentials stored after the refusal' );

// --- Staging with parent. ---
$as_site( 'https://staging.site-a.example' );
$client_staging = new LicenseClient( $server, 'voucher-captain', '1.0.0', 'wplc_e2e_client_site_a_staging' );
$result         = $client_staging->activate( $key, 'staging' );
e2e_ok( is_wp_error( $result ) && 'staging_parent_required' === $result->get_error_code(), 'staging without a parent installation is refused (staging_parent_required)', $error_of( $result ) );
$result = $client_staging->activate( $key, 'staging', wp_generate_uuid4() );
e2e_ok( is_wp_error( $result ) && 'staging_parent_invalid' === $result->get_error_code(), 'staging with an unknown parent is refused (staging_parent_invalid)', $error_of( $result ) );
$result = $client_staging->activate( $key, 'staging', (string) $credentials_a['installation_id'] );
e2e_ok( is_array( $result ) && 'active' === ( $result['license_status'] ?? null ), 'staging activation linked to site A succeeds although the live limit (1) is used up', $error_of( $result ) );
$staging_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['activations']} WHERE installation_uuid = %s", (string) get_option( 'wplc_e2e_client_site_a_staging' )['installation_id'] ), ARRAY_A );
$parent_row  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['activations']} WHERE installation_uuid = %s", (string) $credentials_a['installation_id'] ), ARRAY_A );
e2e_ok( is_array( $staging_row ) && 'staging' === $staging_row['environment'] && (int) $staging_row['staging_parent_id'] === (int) $parent_row['id'], 'staging row is linked to the live activation', $staging_row );
$as_site( 'https://staging2.site-a.example' );
$client_staging2 = new LicenseClient( $server, 'voucher-captain', '1.0.0', 'wplc_e2e_client_site_a_staging2' );
$result          = $client_staging2->activate( $key, 'staging', (string) $credentials_a['installation_id'] );
e2e_ok( is_wp_error( $result ) && 'staging_limit' === $result->get_error_code(), 'a second staging site for the same live site is refused (staging_limit)', $error_of( $result ) );

// --- Development. ---
$as_site( 'https://dev.site-a.example' );
$client_dev = new LicenseClient( $server, 'voucher-captain', '1.0.0', 'wplc_e2e_client_site_dev' );
$result     = $client_dev->activate( $key, 'development' );
e2e_ok( is_wp_error( $result ) && 'development_site_invalid' === $result->get_error_code(), 'development activation for a non-local host is refused (development_site_invalid)', $error_of( $result ) );
$as_site( 'https://voucher.local' );
$client_local = new LicenseClient( $server, 'voucher-captain', '1.0.0', 'wplc_e2e_client_site_local' );
$result       = $client_local->activate( $key, 'development' );
e2e_ok( is_array( $result ), 'development activation for a .local host succeeds without using a live slot', $error_of( $result ) );

// --- Admin and customer screens show the activations. ---
$admin_cookies = e2e_login( (int) e2e_get( 'admin_id' ) );
$detail        = e2e_http( 'GET', admin_url( 'admin.php?page=wplc-licenses&license=' . (int) $license['id'] ), array( 'cookies' => $admin_cookies ) );
e2e_ok( 200 === $detail['code'] && false !== strpos( $detail['body'], 'site-a.example' ) && false !== strpos( $detail['body'], 'voucher.local' ) && false === strpos( $detail['body'], $key ), 'admin license detail lists the activations and does not print the full key', $detail['code'] );
$list = e2e_http( 'GET', admin_url( 'admin.php?page=wplc-licenses' ), array( 'cookies' => $admin_cookies ) );
e2e_ok( 200 === $list['code'] && false !== strpos( $list['body'], $license['key_prefix'] ), 'admin license list shows the license', $list['code'] );
// T-141: the search is a nonce-protected POST; the redirect URL carries only an opaque token.
preg_match( '/name="_wpnonce" value="([^"]+)"/', $list['body'], $nonce_match );
$posted = e2e_http(
	'POST',
	admin_url( 'admin-post.php' ),
	array(
		'cookies' => $admin_cookies,
		'body'    => array(
			'action'           => 'wplc_admin_license_search',
			'_wpnonce'         => $nonce_match[1] ?? '',
			'_wp_http_referer' => '/wp-admin/admin.php?page=wplc-licenses',
			's'                => $key,
		),
	)
);
e2e_ok( 302 === $posted['code'] && false !== strpos( $posted['location'], 'wplc_search=' ) && false === strpos( $posted['location'], $key ) && false === strpos( $posted['location'], $license['key_prefix'] ), 'admin search redirects to a URL without the key', $posted['code'] . ' ' . $posted['location'] );
$search = e2e_http( 'GET', $posted['location'], array( 'cookies' => $admin_cookies ) );
$other_license = e2e_license( (int) $license_ids[1] );
e2e_ok( 200 === $search['code'] && false !== strpos( $search['body'], $license['key_prefix'] ) && false === strpos( $search['body'], $other_license['key_prefix'] ) && false !== strpos( $search['body'], 'Search results for the pasted license key' ) && false === strpos( $search['body'], $key ), 'admin search by pasted full key finds exactly that license and does not echo the key', $search['code'] );
// Known and reported, not asserted: the pasted key stays in the GET URL, so WordPress repeats it in the search box and the sort links.
$search = e2e_http( 'GET', admin_url( 'admin.php?page=wplc-licenses&s=' . rawurlencode( 'alice@customer.test' ) ), array( 'cookies' => $admin_cookies ) );
e2e_ok( 200 === $search['code'] && false !== strpos( $search['body'], $license['key_prefix'] ) && false !== strpos( $search['body'], $other_license['key_prefix'] ), 'admin search by customer email finds both licenses', $search['code'] );
$alice_cookies = e2e_login( (int) e2e_get( 'alice_id' ) );
$account       = e2e_http( 'GET', wc_get_account_endpoint_url( 'wplc-licenses' ), array( 'cookies' => $alice_cookies ) );
e2e_ok( false !== strpos( $account['body'], 'site-a.example' ) && false !== strpos( $account['body'], 'wplc_deactivate_site' ), 'customer account lists the activated sites with a deactivate form' );
e2e_login( 0 );

// --- Deactivate site A through the client: staging goes with it. ---
$as_site( 'https://site-a.example' );
$result = $client_a->deactivate();
e2e_ok( is_array( $result ) && 'deactivated' === ( $result['status'] ?? null ), 'site A: deactivation through the client succeeds (signed DELETE)', $error_of( $result ) );
e2e_ok( false === get_option( 'wplc_e2e_client_site_a', false ), 'site A: client removed its credentials' );
$parent_row  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['activations']} WHERE id = %d", (int) $parent_row['id'] ), ARRAY_A );
$staging_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['activations']} WHERE id = %d", (int) $staging_row['id'] ), ARRAY_A );
e2e_eq( array( 'deactivated', 'deactivated' ), array( $parent_row['status'], $staging_row['status'] ), 'deactivating the live site cascades to its staging site' );
$as_site( 'https://staging.site-a.example' );
$result = $client_staging->check_entitlements();
e2e_ok( is_wp_error( $result ) && 'activation_not_found' === $result->get_error_code(), 'the staging site\'s credentials stop working (activation_not_found)', $error_of( $result ) );
$audit = e2e_rows( 'audit_log', "object_type = 'activation' AND object_id IN (" . (int) $parent_row['id'] . ',' . (int) $staging_row['id'] . ')' );
e2e_ok( count( $audit ) >= 2, 'both deactivations are in the audit log', count( $audit ) );
e2e_ok( false === strpos( wp_json_encode( e2e_rows( 'audit_log' ) ), (string) $credentials_a['installation_secret'] ) && false === strpos( wp_json_encode( e2e_rows( 'audit_log' ) ), $key ), 'audit log contains neither the installation secret nor the license key' );

// --- The freed slot can be used by site B. ---
$as_site( 'https://site-b.example' );
$result = $client_b->activate( $key );
e2e_ok( is_array( $result ), 'site B: activation succeeds after site A released its slot', $error_of( $result ) );
$credentials_b = (array) get_option( 'wplc_e2e_client_site_b', array() );

// --- Same site again with lost credentials: replaces instead of consuming a slot. ---
$client_b_restored = new LicenseClient( $server, 'voucher-captain', '1.0.1', 'wplc_e2e_client_site_a_restored' );
$result            = $client_b_restored->activate( $key );
e2e_ok( is_array( $result ), 'site B with a new installation ID (lost options): activation succeeds although the limit is 1', $error_of( $result ) );
$live = e2e_rows( 'activations', 'license_id = ' . (int) $license['id'] . " AND environment = 'production' AND status = 'active'" );
e2e_eq( 1, count( $live ), 'exactly 1 active live activation afterwards (replaced, not added)' );
$replaced = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$t['activations']} WHERE installation_uuid = %s", (string) $credentials_b['installation_id'] ) );
e2e_eq( 'deactivated', $replaced, 'the previous activation of site B is deactivated' );
e2e_ok( count( e2e_rows( 'audit_log', "action = 'site_replaced'" ) ) >= 1, 'audit log has a site_replaced entry' );
$result = $client_b->check_entitlements();
e2e_ok( is_wp_error( $result ) && 'activation_not_found' === $result->get_error_code(), 'the replaced credentials stop working', $error_of( $result ) );
$result = $client_b_restored->check_entitlements();
e2e_ok( is_array( $result ) && 'active' === ( $result['license_status'] ?? null ), 'the new credentials work', $error_of( $result ) );

// --- Customer deactivates a site from the account (real POST), another customer cannot. ---
$live_row    = $live ? $live[0] : array( 'id' => 0 );
$bob_cookies = e2e_login( (int) e2e_get( 'bob_id' ) );
$forbidden   = e2e_http(
	'POST',
	admin_url( 'admin-post.php' ),
	array(
		'cookies' => $bob_cookies,
		'body'    => array( 'action' => 'wplc_deactivate_site', 'activation_id' => (string) $live_row['id'], '_wpnonce' => wp_create_nonce( 'wplc_deactivate_site_' . (int) $live_row['id'] ) ),
	)
);
$still = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$t['activations']} WHERE id = %d", (int) $live_row['id'] ) );
e2e_eq( 'active', $still, 'another customer cannot deactivate the site (activation stays active)' );
$alice_cookies = e2e_login( (int) e2e_get( 'alice_id' ) );
$account       = e2e_http( 'GET', wc_get_account_endpoint_url( 'wplc-licenses' ), array( 'cookies' => $alice_cookies ) );
$form_ok       = (bool) preg_match( '/<form[^>]*>(?:(?!<\/form>).)*name="action" value="wplc_deactivate_site"(?:(?!<\/form>).)*<\/form>/s', $account['body'], $form );
$fields        = array();
if ( $form_ok && preg_match_all( '/<input[^>]+name="([^"]+)"[^>]+value="([^"]*)"/', $form[0], $inputs, PREG_SET_ORDER ) ) {
	foreach ( $inputs as $input ) {
		$fields[ $input[1] ] = html_entity_decode( $input[2] );
	}
}
e2e_ok( $form_ok && ! empty( $fields['_wpnonce'] ), 'account page has a deactivate form with a nonce', array_keys( $fields ) );
$done = e2e_http( 'POST', admin_url( 'admin-post.php' ), array( 'cookies' => $alice_cookies, 'body' => $fields ) );
e2e_ok( 302 === $done['code'] && false !== strpos( $done['location'], 'wplc-licenses' ), 'submitting the form as shown redirects back to the account licenses page', array( $done['code'], $done['location'] ) );
$after = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$t['activations']} WHERE id = %d", (int) ( $fields['activation_id'] ?? 0 ) ) );
e2e_eq( 'deactivated', $after, 'the owner deactivated the site from the account' );
$notice_page = e2e_http( 'GET', $done['location'], array( 'cookies' => $alice_cookies ) );
e2e_ok( 200 === $notice_page['code'] && ! preg_match( '/critical error/', $notice_page['body'] ), 'account page renders after the redirect' );
e2e_login( 0 );

// --- Leave one live activation on license 1 for the following flows (updates). ---
$as_site( 'https://site-c.example' );
delete_option( 'wplc_e2e_client_site_c' );
$client_c = new LicenseClient( $server, 'voucher-captain', '1.0.0', 'wplc_e2e_client_site_c' );
$result   = $client_c->activate( $key );
e2e_ok( is_array( $result ), 'site C activated for the following flows', $error_of( $result ) );
$as_site( '' );
