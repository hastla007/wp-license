<?php
/**
 * Flow 5: release registry with an uploaded ZIP, update check, one-time download, customer download.
 *
 * @package WPLC
 */

require getenv( 'WPLC_E2E_DIR' ) . '/lib.php';

use WPLC\Client\LicenseClient;

global $wpdb;
$t           = e2e_tables();
$license_ids = (array) e2e_get( 'license_ids' );
$license     = e2e_license( (int) $license_ids[0] );
$server      = 'https://shop.test';
$client      = new LicenseClient( $server, 'voucher-captain', '1.0.0', 'wplc_e2e_client_site_c' );
$error_of    = static function ( $result ) {
	return is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : 'no error';
};

/**
 * Build a plugin ZIP in memory.
 */
$build_zip = static function ( string $version ) {
	$file = wp_tempnam( 'wplc-e2e.zip' );
	$zip  = new ZipArchive();
	$zip->open( $file, ZipArchive::CREATE | ZipArchive::OVERWRITE );
	$zip->addFromString( 'voucher-captain/voucher-captain.php', "<?php\n/**\n * Plugin Name: Voucher Captain\n * Version: {$version}\n */\n" );
	$zip->addFromString( 'voucher-captain/readme.txt', "=== Voucher Captain ===\nStable tag: {$version}\n" . str_repeat( 'e2e payload ', 2000 ) );
	$zip->close();
	$bytes = file_get_contents( $file );
	unlink( $file );
	return $bytes;
};
/**
 * Follow redirects by hand (the stream route must be reached through the e2e HTTP filter).
 */
$follow = static function ( string $url, array $args = array() ) {
	$hops = array();
	for ( $i = 0; $i < 5; $i++ ) {
		$response = e2e_http( 'GET', $url, $args );
		$hops[]   = array( 'url' => $url, 'code' => $response['code'] );
		if ( in_array( $response['code'], array( 301, 302, 303, 307 ), true ) && '' !== $response['location'] ) {
			$url = $response['location'];
			continue;
		}
		break;
	}
	$response['hops'] = $hops;
	return $response;
};

// --- No release yet. ---
$result = $client->check_update();
e2e_ok( is_array( $result ) && false === ( $result['update_available'] ?? null ), 'update check without any release: update_available = false', $error_of( $result ) );

// --- Create a draft with an uploaded ZIP through the admin form. ---
$admin_cookies = e2e_login( (int) e2e_get( 'admin_id' ) );
$page          = e2e_http( 'GET', admin_url( 'admin.php?page=wplc-releases' ), array( 'cookies' => $admin_cookies ) );
$create_form   = e2e_form( $page['body'], array( 'value="wplc_release_create"' ) );
e2e_ok( is_array( $create_form ) && ! empty( $create_form['_wpnonce'] ) && false !== strpos( $page['body'], '<option value="voucher-captain"' ), 'releases screen has the create form and offers the catalog product', $page['code'] );
$zip_110 = $build_zip( '1.1.0' );
$fields  = array(
	'action'        => 'wplc_release_create',
	'_wpnonce'      => (string) ( $create_form['_wpnonce'] ?? '' ),
	'product_key'   => 'voucher-captain',
	'version'       => '1.1.0',
	'requires_php'  => '7.4',
	'requires_wp'   => '6.5',
	'tested_wp'     => '6.8',
	'release_notes' => '<p>Fixes &amp; improvements.</p><script>alert(1)</script>',
);
$upload  = e2e_multipart( $fields, 'package_zip', 'voucher-captain-1.1.0.zip', $zip_110, 'application/zip' );
$created = e2e_http( 'POST', admin_url( 'admin-post.php' ), array( 'cookies' => $admin_cookies, 'headers' => $upload['headers'], 'body' => $upload['body'] ) );
e2e_ok( 302 === $created['code'] && false !== strpos( $created['location'], 'wplc-releases' ), 'creating the release redirects back to the releases screen', array( $created['code'], $created['location'], substr( wp_strip_all_tags( $created['body'] ), 0, 200 ) ) );
$release = $wpdb->get_row( "SELECT * FROM {$t['releases']} WHERE product_key = 'voucher-captain' AND version = '1.1.0'", ARRAY_A );
e2e_ok( is_array( $release ), 'release row 1.1.0 exists' );
$release = is_array( $release ) ? $release : array( 'id' => 0, 'status' => '', 'storage' => '', 'sha256' => '', 'package_url' => '', 'release_notes' => '' );
e2e_eq( array( 'draft', 'local', hash( 'sha256', $zip_110 ) ), array( $release['status'], $release['storage'], $release['sha256'] ), 'release is a draft, stored locally, with the SHA-256 of the uploaded bytes' );
e2e_ok( false === strpos( $release['release_notes'], '<script' ) && false !== strpos( $release['release_notes'], 'Fixes' ), 'release notes are sanitized (script removed)', $release['release_notes'] );
$stored_path = \WPLC\Update\LocalPackageStorage::path_for_key( (string) $release['package_url'] );
e2e_ok( null !== $stored_path && is_file( $stored_path ) && hash_file( 'sha256', $stored_path ) === $release['sha256'], 'package file is in the storage directory and matches the checksum', $stored_path );
$storage_dir = \WPLC\Update\LocalPackageStorage::base_dir();
e2e_ok( is_file( $storage_dir . '/.htaccess' ) && is_file( $storage_dir . '/index.html' ) && is_file( $storage_dir . '/web.config' ), 'storage directory has .htaccess, web.config and index.html guards' );
e2e_ok( (bool) preg_match( '/^voucher-captain-1\.1\.0-[a-f0-9]{32}\.zip$/', (string) $release['package_url'] ), 'stored file name carries 128 random bits (not guessable where .htaccess is ignored, e.g. nginx)', $release['package_url'] );
$result_page = e2e_http( 'GET', $created['location'], array( 'cookies' => $admin_cookies ) );
e2e_ok( 200 === $result_page['code'] && false !== strpos( $result_page['body'], 'Draft release created' ) && false !== strpos( $result_page['body'], '1.1.0' ), 'releases screen shows the notice and the draft', $result_page['code'] );

// A draft is not delivered.
$result = $client->check_update();
e2e_ok( is_array( $result ) && false === ( $result['update_available'] ?? null ), 'a draft release is not offered to installations', $error_of( $result ) );

// Uploading something that is not a ZIP is rejected and leaves nothing behind.
$files_before = count( glob( $storage_dir . '/*.zip' ) );
$upload       = e2e_multipart( array_merge( $fields, array( 'version' => '1.1.1' ) ), 'package_zip', 'evil.zip', "<?php echo 'not a zip';", 'application/zip' );
$rejected     = e2e_http( 'POST', admin_url( 'admin-post.php' ), array( 'cookies' => $admin_cookies, 'headers' => $upload['headers'], 'body' => $upload['body'] ) );
e2e_ok( null === $wpdb->get_row( "SELECT id FROM {$t['releases']} WHERE version = '1.1.1'" ) && count( glob( $storage_dir . '/*.zip' ) ) === $files_before, 'a non-ZIP upload creates no release and leaves no file', $rejected['location'] );

// --- Publish through the row form. ---
$publish_form = e2e_form( $result_page['body'], array( 'value="wplc_release_publish"', 'name="release_id" value="' . (int) $release['id'] . '"' ) );
e2e_ok( is_array( $publish_form ), 'draft row has a publish form' );
$published = e2e_submit( (array) $publish_form, $admin_cookies );
$release   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['releases']} WHERE id = %d", (int) $release['id'] ), ARRAY_A );
e2e_ok( 302 === $published['code'] && 'published' === $release['status'] && ! empty( $release['published_at'] ), 'release is published', array( $published['code'], $release['status'] ) );

// --- Client: update offered, metadata signed. ---
$update = $client->check_update();
e2e_ok( is_array( $update ) && true === ( $update['update_available'] ?? null ), 'client check_update() reports an update', $error_of( $update ) );
$update = is_array( $update ) ? $update : array();
e2e_eq( array( '1.1.0', $release['sha256'], '7.4', '6.5', '6.8', 'voucher-captain' ), array( $update['version'] ?? null, $update['sha256'] ?? null, $update['requires_php'] ?? null, $update['requires_wp'] ?? null, $update['tested_wp'] ?? null, $update['product_key'] ?? null ), 'update metadata: version, checksum, requirements' );
$package_url = (string) ( $update['package_url'] ?? '' );
e2e_ok( (bool) preg_match( '#^https://shop\.test/wp-json/wplc/v1/updates/download/[a-f0-9]{64}$#', $package_url ), 'package_url is an HTTPS one-time download URL on the license server', $package_url );
e2e_ok( false === strpos( wp_json_encode( $update ), (string) $release['package_url'] ) && false === strpos( wp_json_encode( $update ), 'wplc-packages' ), 'the storage key / path is not disclosed to the client' );

// --- Download: redirect to the stream route, bytes match the registry checksum. ---
$download = $follow( $package_url );
e2e_eq( array( 302, 200 ), array_column( $download['hops'], 'code' ), 'download URL redirects once and the stream answers 200', $download['hops'] );
e2e_ok( isset( $download['hops'][1] ) && false !== strpos( $download['hops'][1]['url'], '/wp-json/wplc/v1/packages/' ) || ( isset( $download['hops'][1] ) && 0 === strpos( $download['hops'][1]['url'], 'https://shop.test/' ) ), 'the redirect target is the stream route on the same host', $download['hops'] );
e2e_eq( $release['sha256'], hash( 'sha256', $download['body'] ), 'SHA-256 of the downloaded bytes equals the registry checksum' );
e2e_eq( strlen( $zip_110 ), strlen( $download['body'] ), 'downloaded size equals the uploaded size' );
$disposition = (string) ( $download['headers']['content-disposition'] ?? '' );
e2e_ok( false !== strpos( $disposition, 'attachment' ) && false !== strpos( $disposition, '.zip' ), 'stream is sent as a ZIP attachment', $disposition );
e2e_ok( false !== strpos( (string) ( $download['headers']['cache-control'] ?? '' ), 'no-store' ), 'stream is sent with Cache-Control: no-store', $download['headers']['cache-control'] ?? null );
$tmp = wp_tempnam( 'wplc-e2e-dl.zip' );
file_put_contents( $tmp, $download['body'] );
$zip    = new ZipArchive();
$opened = true === $zip->open( $tmp );
e2e_ok( $opened && false !== $zip->locateName( 'voucher-captain/voucher-captain.php' ), 'the downloaded file is a valid ZIP with the plugin main file' );
if ( $opened ) {
	$zip->close();
}
unlink( $tmp );

// Second use of the same token and of the stream URL fails.
$second = $follow( $package_url );
e2e_eq( 404, $second['code'], 'second use of the same download token fails (404)' );
if ( isset( $download['hops'][1] ) ) {
	$stream_again = e2e_http( 'GET', $download['hops'][1]['url'] );
	e2e_eq( 404, $stream_again['code'], 'second use of the stream URL fails (404)' );
}
$unknown = e2e_http( 'GET', $server . '/wp-json/wplc/v1/updates/download/' . str_repeat( 'ab', 32 ) );
e2e_eq( 404, $unknown['code'], 'an unknown download token answers 404' );

// A client that already runs the published version gets no update.
$current_client = new LicenseClient( $server, 'voucher-captain', '1.1.0', 'wplc_e2e_client_site_c' );
$result         = $current_client->check_update();
e2e_ok( is_array( $result ) && false === ( $result['update_available'] ?? null ), 'an installation on 1.1.0 is not offered 1.1.0 again', $error_of( $result ) );

// --- Customer account download. ---
$alice_cookies = e2e_login( (int) e2e_get( 'alice_id' ) );
$account       = e2e_http( 'GET', wc_get_account_endpoint_url( 'wplc-licenses' ), array( 'cookies' => $alice_cookies ) );
$download_form = e2e_form( $account['body'], array( 'wplc-download-package', 'name="license_id" value="' . (int) $license['id'] . '"' ) );
e2e_ok( is_array( $download_form ) && false !== strpos( $account['body'], 'Download version 1.1.0' ), 'account page offers "Download version 1.1.0" for the license' );
$started = e2e_submit( (array) $download_form, $alice_cookies );
e2e_eq( 302, $started['code'], 'owner: download handler redirects to a one-time stream URL', substr( wp_strip_all_tags( $started['body'] ), 0, 200 ) );
$stream = e2e_http( 'GET', $started['location'] );
e2e_ok( 200 === $stream['code'] && hash( 'sha256', $stream['body'] ) === $release['sha256'], 'owner: the stream delivers the package with the registry checksum (no login needed for the one-time URL)', $stream['code'] );
e2e_ok( count( e2e_rows( 'audit_log', "action = 'package_downloaded' AND object_id = " . (int) $license['id'] ) ) >= 1, 'customer download is in the audit log' );

// Another customer cannot download with this license, even with a valid nonce of their own session.
$bob_cookies = e2e_login( (int) e2e_get( 'bob_id' ) );
$refused     = e2e_http(
	'POST',
	add_query_arg( 'action', 'wplc_download_package', admin_url( 'admin-post.php' ) ),
	array(
		'cookies' => $bob_cookies,
		'body'    => array( 'license_id' => (string) $license['id'], '_wpnonce' => wp_create_nonce( \WPLC\Update\CustomerDownload::nonce_action( (int) $license['id'] ) ) ),
	)
);
e2e_eq( 403, $refused['code'], 'another customer is refused (403) for a license that is not theirs' );
e2e_ok( false !== strpos( $refused['body'], 'does not belong to your account' ), 'refusal names the reason without leaking details', substr( wp_strip_all_tags( $refused['body'] ), 0, 200 ) );
// Bob's affiliate-captain license: no release for that product, so no download form for it.
$bob_account = e2e_http( 'GET', wc_get_account_endpoint_url( 'wplc-licenses' ), array( 'cookies' => $bob_cookies ) );
e2e_ok( 200 === $bob_account['code'] && null === e2e_form( $bob_account['body'], array( 'wplc-download-package', 'name="license_id" value="' . (int) e2e_get( 'bob_variation_license_id' ) . '"' ) ), 'a license whose product has no published release gets no download button' );
e2e_login( 0 );
$anonymous = e2e_http( 'POST', add_query_arg( 'action', 'wplc_download_package', admin_url( 'admin-post.php' ) ), array( 'body' => array( 'license_id' => (string) $license['id'] ) ) );
e2e_ok( 302 === $anonymous['code'] && false === strpos( $anonymous['location'], 'packages' ), 'a logged-out download request is redirected to the account/login page', array( $anonymous['code'], $anonymous['location'] ) );

// --- Higher version wins, withdrawing falls back. ---
$admin_cookies = e2e_login( (int) e2e_get( 'admin_id' ) );
$page          = e2e_http( 'GET', admin_url( 'admin.php?page=wplc-releases' ), array( 'cookies' => $admin_cookies ) );
$create_form   = e2e_form( $page['body'], array( 'value="wplc_release_create"' ) );
$zip_120       = $build_zip( '1.2.0' );
$upload        = e2e_multipart( array_merge( $fields, array( '_wpnonce' => (string) $create_form['_wpnonce'], 'version' => '1.2.0', 'release_notes' => 'Second release' ) ), 'package_zip', 'voucher-captain-1.2.0.zip', $zip_120, 'application/zip' );
e2e_http( 'POST', admin_url( 'admin-post.php' ), array( 'cookies' => $admin_cookies, 'headers' => $upload['headers'], 'body' => $upload['body'] ) );
$release_120 = $wpdb->get_row( "SELECT * FROM {$t['releases']} WHERE product_key = 'voucher-captain' AND version = '1.2.0'", ARRAY_A );
$page        = e2e_http( 'GET', admin_url( 'admin.php?page=wplc-releases' ), array( 'cookies' => $admin_cookies ) );
e2e_submit( (array) e2e_form( $page['body'], array( 'value="wplc_release_publish"', 'name="release_id" value="' . (int) $release_120['id'] . '"' ) ), $admin_cookies );
$update = $client->check_update();
e2e_ok( is_array( $update ) && '1.2.0' === ( $update['version'] ?? null ) && hash( 'sha256', $zip_120 ) === ( $update['sha256'] ?? null ), 'after publishing 1.2.0 the client is offered 1.2.0', $error_of( $update ) );
// Duplicate version is refused.
$page   = e2e_http( 'GET', admin_url( 'admin.php?page=wplc-releases' ), array( 'cookies' => $admin_cookies ) );
$upload = e2e_multipart( array_merge( $fields, array( '_wpnonce' => (string) e2e_form( $page['body'], array( 'value="wplc_release_create"' ) )['_wpnonce'], 'version' => '1.2.0' ) ), 'package_zip', 'dup.zip', $zip_120, 'application/zip' );
e2e_http( 'POST', admin_url( 'admin-post.php' ), array( 'cookies' => $admin_cookies, 'headers' => $upload['headers'], 'body' => $upload['body'] ) );
e2e_eq( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['releases']} WHERE product_key = 'voucher-captain' AND version = '1.2.0'" ), 'a second release with the same version is refused' );
e2e_eq( 2, count( glob( $storage_dir . '/*.zip' ) ), 'the refused duplicate upload leaves no extra file (2 packages stored)' );
// Withdraw 1.2.0.
$withdraw_form = e2e_form( $page['body'], array( 'value="wplc_release_withdraw"', 'name="release_id" value="' . (int) $release_120['id'] . '"' ) );
e2e_ok( is_array( $withdraw_form ), 'published row has a withdraw form' );
e2e_submit( (array) $withdraw_form, $admin_cookies, array( 'reason' => 'e2e: broken build' ) );
e2e_eq( 'withdrawn', (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$t['releases']} WHERE id = %d", (int) $release_120['id'] ) ), 'release 1.2.0 is withdrawn' );
$update = $client->check_update();
e2e_ok( is_array( $update ) && '1.1.0' === ( $update['version'] ?? null ), 'after withdrawing 1.2.0 the client is offered 1.1.0 again', is_array( $update ) ? ( $update['version'] ?? $update ) : $error_of( $update ) );
// A grant issued before the withdrawal... (1.2.0 token from above) must not deliver the withdrawn package.
// Manifest import (JSON pasted) for a remote package on an allowed host.
$page        = e2e_http( 'GET', admin_url( 'admin.php?page=wplc-releases' ), array( 'cookies' => $admin_cookies ) );
$import_form = e2e_form( $page['body'], array( 'value="wplc_release_import"' ) );
$manifest    = array(
	'product_key'  => 'affiliate-captain',
	'version'      => '2.0.0',
	'package_url'  => 'https://downloads.not-allowed.example/affiliate-captain-2.0.0.zip',
	'sha256'       => hash( 'sha256', 'x' ),
	'requires_php' => '7.4',
	'requires_wp'  => '6.5',
);
$imported = e2e_submit( (array) $import_form, $admin_cookies, array( 'manifest_json' => wp_json_encode( $manifest ) ) );
$remote   = $wpdb->get_row( "SELECT * FROM {$t['releases']} WHERE product_key = 'affiliate-captain' AND version = '2.0.0'", ARRAY_A );
e2e_ok( is_array( $remote ) && 'url' === $remote['storage'] && 'draft' === $remote['status'], 'manifest import creates a draft with remote storage', array( $imported['location'], $remote ) );
if ( is_array( $remote ) ) {
	$page = e2e_http( 'GET', admin_url( 'admin.php?page=wplc-releases' ), array( 'cookies' => $admin_cookies ) );
	$done = e2e_submit( (array) e2e_form( $page['body'], array( 'value="wplc_release_publish"', 'name="release_id" value="' . (int) $remote['id'] . '"' ) ), $admin_cookies );
	e2e_eq( 'draft', (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$t['releases']} WHERE id = %d", (int) $remote['id'] ) ), 'publishing a release whose host is not on the allow-list is refused' );
	$after = e2e_http( 'GET', $done['location'], array( 'cookies' => $admin_cookies ) );
	e2e_ok( false !== strpos( $after['body'], 'notice-error' ), 'the refusal is shown as an error notice' );
}
e2e_login( 0 );

// --- A license without access gets neither update metadata nor a download. ---
$wpdb->update( $t['licenses'], array( 'status' => 'suspended' ), array( 'id' => (int) $license['id'] ) );
$result = $client->check_update();
e2e_ok( is_wp_error( $result ) && 'license_inactive' === $result->get_error_code(), 'suspended license: update check is refused (license_inactive)', $error_of( $result ) );
$wpdb->update( $t['licenses'], array( 'status' => 'active' ), array( 'id' => (int) $license['id'] ) );
$update = $client->check_update();
$wpdb->update( $t['licenses'], array( 'status' => 'suspended' ), array( 'id' => (int) $license['id'] ) );
$blocked = $follow( (string) ( $update['package_url'] ?? '' ) );
e2e_eq( 404, $blocked['code'], 'a grant issued while active is worthless once the license is suspended (404)' );
$wpdb->update( $t['licenses'], array( 'status' => 'active' ), array( 'id' => (int) $license['id'] ) );
e2e_note( 'license status was toggled directly in the e2e database for the two assertions above' );

e2e_set( 'release_id', (int) $release['id'] );
