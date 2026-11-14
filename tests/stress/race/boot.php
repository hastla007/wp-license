<?php
/**
 * Boots WordPress, WooCommerce and the plugin for the parallel harness (not part of CI).
 *
 * The first process (setup.php) installs the test site; workers set QA_WORKER=1 and attach
 * to the installed site without the per-test transaction of PHPUnit, so that several PHP
 * processes really work on the same rows at the same time.
 *
 * Environment: WP_TESTS_DIR, WP_CORE_DIR, WPLC_WC_DIR as for the integration suite
 * (see tests/integration-bootstrap.php). The database of that test library is TRUNCATED.
 *
 * @package WPLC
 */

if ( ! getenv( 'WP_TESTS_DIR' ) ) {
	fwrite( STDERR, "Set WP_TESTS_DIR (and WP_CORE_DIR, WPLC_WC_DIR); see tests/stress/README.md.\n" );
	exit( 2 );
}
if ( '1' !== getenv( 'WPLC_STRESS_ALLOW_TRUNCATE' ) ) {
	fwrite( STDERR, "The stress harness truncates the plugin, order and Action Scheduler tables of the test database. Set WPLC_STRESS_ALLOW_TRUNCATE=1 to confirm.\n" );
	exit( 2 );
}
define( 'WPLC_STRESS_ROOT', dirname( __DIR__, 3 ) );
putenv( 'WPLC_TEST_HPOS=' . ( '0' === getenv( 'WPLC_TEST_HPOS' ) ? '0' : '1' ) );
$wplc_stress_keep_posts = static function () {
	return false;
};
if ( getenv( 'QA_WORKER' ) === '1' ) {
	putenv( 'WP_TESTS_SKIP_INSTALL=1' );
	// The WordPress test bootstrap deletes all posts on every load; a worker must keep the
	// products the setup process created (same structure as tests_add_filter()).
	$GLOBALS['wp_filter']['pre_delete_post'][10]['wplc_stress_keep_posts'] = array(
		'function'      => $wplc_stress_keep_posts,
		'accepted_args' => 1,
	);
}
ob_start();
require WPLC_STRESS_ROOT . '/tests/integration-bootstrap.php';
ob_end_clean();
remove_all_filters( 'pre_delete_post' );
error_reporting( E_ALL );
$GLOBALS['qa_errors'] = array();
set_error_handler( function ( $no, $str, $file, $line ) { if ( 0 === strpos( $file, WPLC_STRESS_ROOT . '/src' ) ) { $GLOBALS['qa_errors'][] = "$str @ " . basename( $file ) . ":$line"; } return true; } );
function qa_wait( float $t ) { while ( microtime( true ) < $t ) { usleep( 200 ); } }
function qa_req( string $method, string $route, ?array $body, ?array $signer = null, ?string $nonce = null ) {
	$r = new WP_REST_Request( $method, $route ); $raw = ''; $r->set_body( '' );
	if ( null !== $body ) { $raw = wp_json_encode( $body ); $r->set_header( 'Content-Type', 'application/json' ); $r->set_body( $raw ); }
	if ( $signer ) { $ts = (string) time(); $nonce = $nonce ?: 'qa-nonce-' . bin2hex( random_bytes( 8 ) );
		$r->set_header( 'X-WPLC-Installation-Id', $signer['uuid'] ); $r->set_header( 'X-WPLC-Timestamp', $ts ); $r->set_header( 'X-WPLC-Nonce', $nonce );
		$r->set_header( 'X-WPLC-Signature', \WPLC\Api\V1\Authenticator::signature_for( $signer['secret'], $method, $route, $ts, $nonce, $raw ) ); }
	return $r;
}
function qa_dispatch( $r ) { $_SERVER['REMOTE_ADDR'] = '10.' . random_int(1,250) . '.' . random_int(1,250) . '.' . random_int(1,250); $res = rest_ensure_response( rest_get_server()->dispatch( $r ) ); $d = $res->get_data(); return array( 'status' => $res->get_status(), 'code' => is_array( $d ) ? ( $d['code'] ?? 'ok' ) : 'ok', 'data' => $d ); }
function qa_seed_license( array $over = array(), array $ent = array( 'sites.live_limit' => 1, 'feature.export' => true ) ) {
	global $wpdb; $t = \WPLC\Infrastructure\Database\Schema::table_names();
	$uuid = wp_generate_uuid4(); $kid = \WPLC\License\LicenseKey::current_key_id(); $key = \WPLC\License\LicenseKey::for_uuid( $uuid, $kid );
	$now = gmdate( 'Y-m-d H:i:s' ); $pu = gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS );
	$row = array_merge( array( 'license_uuid' => $uuid, 'license_key_hash' => \WPLC\License\LicenseKey::hash( $key ), 'license_key_secret_id' => $kid, 'key_prefix' => \WPLC\License\LicenseKey::prefix( $uuid ), 'customer_id' => 0, 'product_key' => 'test-product', 'plan_key' => 'single', 'status' => 'active', 'billing_interval' => 'year', 'starts_at' => $now, 'paid_until' => $pu, 'next_payment_at' => $pu, 'created_at' => $now, 'updated_at' => $now ), $over );
	$wpdb->insert( $t['licenses'], $row ); $id = (int) $wpdb->insert_id; if ( ! $id ) { throw new Exception( 'seed failed ' . $wpdb->last_error ); }
	foreach ( $ent as $k => $v ) { $wpdb->insert( $t['entitlements'], array( 'license_id' => $id, 'entitlement_key' => $k, 'value_json' => wp_json_encode( $v ), 'starts_at' => $row['starts_at'], 'ends_at' => $row['paid_until'], 'status' => 'active' ) ); }
	return array( 'id' => $id, 'key' => $key, 'uuid' => $uuid );
}
function qa_seed_activation( int $license_id, array $over = array() ) {
	global $wpdb; $t = \WPLC\Infrastructure\Database\Schema::table_names();
	$secret = 'seeded-secret-' . bin2hex( random_bytes( 16 ) ); $uuid = wp_generate_uuid4(); $now = gmdate( 'Y-m-d H:i:s' );
	$row = array_merge( array( 'license_id' => $license_id, 'installation_uuid' => $uuid, 'installation_secret_hash' => hash( 'sha256', $secret ), 'installation_secret_encrypted' => \WPLC\Security\SecretCrypto::encrypt( $secret ), 'site_url' => 'https://site-' . bin2hex( random_bytes( 4 ) ) . '.example.test', 'environment' => 'production', 'status' => 'active', 'activated_at' => $now, 'last_seen_at' => $now ), $over );
	$wpdb->insert( $t['activations'], $row );
	return array( 'id' => (int) $wpdb->insert_id, 'uuid' => $row['installation_uuid'], 'secret' => $secret );
}
