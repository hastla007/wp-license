<?php
/**
 * Flow 1b: a new vendor sets up the server on an empty installation (PROD-09) with an invented
 * vendor, "Acme Plugins", that has nothing to do with the owner's products (PROD-04).
 *
 * Runs right after 01-activation, so the installation is empty: plugin active, schema created,
 * only the catalog files shipped in catalogs/ imported. The flow walks through the "Getting started"
 * checklist of the settings screen and checks after every step that exactly that step turned to done:
 * keys (wp-config.php of the harness), public key (WPLC_RESPONSE_SIGNING_KEY), catalog file (upload
 * screen), license product (product editor), first release (upload and publish), test purchase
 * (order, license issued; activation, entitlement check and update check through the LicenseClient)
 * and the Stripe webhook secret.
 *
 * Constants are added to wp-config.php of the e2e site while the flow runs and removed at the end.
 * Everything the flow created in the shop is removed again at the end, so the following flows see
 * the same installation as before (they count plans, licenses and mails of their own).
 *
 * @package WPLC
 */

require getenv( 'WPLC_E2E_DIR' ) . '/lib.php';

use WPLC\Catalog\CatalogStore;
use WPLC\Client\LicenseClient;

global $wpdb;
$t       = e2e_tables();
$admin   = get_user_by( 'login', 'admin' );
$cookies = e2e_login( (int) $admin->ID );
$server  = 'https://shop.test';

$vendor      = 'Acme Plugins';
$product_key = 'acme-reports-pro';
$site        = 'https://reports.acme-customer.example';
$client_opt  = 'wplc_e2e_acme_client';

// Where this flow started, for the clean-up at the end.
$mail_mark   = (int) $wpdb->get_var( "SELECT COALESCE(MAX(option_id),0) FROM {$wpdb->options}" );
$action_mark = (int) $wpdb->get_var( "SELECT COALESCE(MAX(action_id),0) FROM {$wpdb->prefix}actionscheduler_actions" );

// --- wp-config.php of the e2e site: constants are added for a step and removed at the end. ---
$config_file   = ABSPATH . 'wp-config.php';
$config_before = (string) file_get_contents( $config_file );
$restore       = static function () use ( $config_file, $config_before ) {
	if ( (string) file_get_contents( $config_file ) !== $config_before ) {
		file_put_contents( $config_file, $config_before );
	}
};
register_shutdown_function( $restore );
$add_constant = static function ( string $name, string $value ) use ( $config_file ) {
	$config = (string) file_get_contents( $config_file );
	$line   = "define( '{$name}', '{$value}' ); // e2e flow 01-vendor-onboarding\n";
	$marker = strpos( $config, "/* That's all" );
	$marker = false === $marker ? strpos( $config, "require_once ABSPATH . 'wp-settings.php';" ) : $marker;
	file_put_contents( $config_file, false === $marker ? $config . "\n" . $line : substr( $config, 0, $marker ) . $line . substr( $config, $marker ) );
	// This CLI process read wp-config.php before; the PHP server reads it on every request.
	if ( ! defined( $name ) ) {
		define( $name, $value );
	}
};

/**
 * State of the "Getting started" checklist as the settings screen renders it.
 *
 * @return array{steps:array<string,bool>,open:bool,summary:string,html:string}
 */
$checklist = static function () use ( $cookies ) {
	$page  = e2e_http( 'GET', admin_url( 'admin.php?page=wplc-settings' ), array( 'cookies' => $cookies ) );
	$steps = array();
	if ( preg_match_all( '/<li data-wplc-step="([a-z_]+)" data-wplc-step-done="([01])">/', $page['body'], $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $match ) {
			$steps[ $match[1] ] = '1' === $match[2];
		}
	}
	$open    = (bool) preg_match( '/<details class="wplc-setup"[^>]*\bopen\b/', $page['body'] );
	$summary = preg_match( '/(\d+ of \d+ steps done)/', $page['body'], $m ) ? $m[1] : '';

	return array( 'steps' => $steps, 'open' => $open, 'summary' => $summary, 'html' => $page['body'] );
};
$expect_done = static function ( array $done, string $label ) use ( $checklist ) {
	$all      = array( 'secrets', 'public_key', 'catalog', 'product', 'release', 'test', 'webhook' );
	$expected = array();
	foreach ( $all as $key ) {
		$expected[ $key ] = in_array( $key, $done, true );
	}
	$state = $checklist();
	e2e_eq( $expected, $state['steps'], $label );
	e2e_eq( count( $done ) . ' of 7 steps done', $state['summary'], $label . ' (summary line)' );

	return $state;
};
$error_of = static function ( $result ) {
	return is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : 'no error';
};

// --- 0. Empty installation: only the harness keys in wp-config.php are there. ---
e2e_eq( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['plans']}" ) + (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['licenses']}" ) + (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['releases']}" ), 'the installation is empty: no plan, no license, no release' );
$state = $expect_done( array( 'secrets' ), 'empty installation: only the keys step is done (the harness defines the secrets in wp-config.php before the install); the shipped catalog files do not count' );
e2e_ok( $state['open'], 'the checklist is unfolded while steps are open' );
foreach ( array( 'page=wplc-diagnostics', 'page=wplc-catalog-files', 'edit.php?post_type=product', 'page=wplc-releases', 'page=wplc-licenses' ) as $link ) {
	e2e_ok( false !== strpos( $state['html'], $link ), 'the checklist links to ' . $link );
}
e2e_ok( false === stripos( $state['html'], 'voucher' ) && false === stripos( wp_strip_all_tags( $state['html'] ), 'captain-pro' ), 'the checklist names no product of another vendor (PROD-04)' );

// --- 1. Public key for the add-on: the signing key goes into wp-config.php. ---
$seed       = random_bytes( 32 );
$public_key = base64_encode( sodium_crypto_sign_publickey( sodium_crypto_sign_seed_keypair( $seed ) ) );
$add_constant( 'WPLC_RESPONSE_SIGNING_KEY', base64_encode( $seed ) );
e2e_eq( $public_key, \WPLC\Security\ResponseSigner::public_key(), 'the server uses the signing key from wp-config.php' );
$expect_done( array( 'secrets', 'public_key' ), 'after WPLC_RESPONSE_SIGNING_KEY: the public key step is done' );
$diagnostics = e2e_http( 'GET', admin_url( 'admin.php?page=wplc-diagnostics' ), array( 'cookies' => $cookies ) );
e2e_ok( false !== strpos( $diagnostics['body'], $public_key ), 'the linked diagnostics screen shows the public key the add-on pins' );

// --- 2. Catalog file of the invented vendor, through the upload screen. ---
$catalog = array(
	'format'       => 1,
	'product_key'  => $product_key,
	'prefix'       => 'acme_reports',
	'name'         => array( 'en' => 'Acme Reports Pro', 'de_DE' => 'Acme Reports Pro' ),
	'vendor'       => $vendor, // Unknown field: ignored by the server.
	'entitlements' => array(
		array( 'key' => 'acme_reports.scheduled_exports', 'label' => array( 'en' => 'Scheduled exports', 'de_DE' => 'Geplante Exporte' ) ),
		array( 'key' => 'acme_reports.white_label', 'label' => 'White label' ),
		array( 'key' => 'acme_reports.ai_summaries', 'label' => 'AI summaries', 'status' => 'planned' ),
	),
);
$files_page  = e2e_http( 'GET', admin_url( 'admin.php?page=wplc-catalog-files' ), array( 'cookies' => $cookies ) );
$import_form = e2e_form( $files_page['body'], array( 'value="wplc_import_catalog_file"' ) );
e2e_ok( is_array( $import_form ) && ! empty( $import_form['_wpnonce'] ), 'the catalog files screen has the import form', $files_page['code'] );
$upload   = e2e_multipart( array( 'action' => 'wplc_import_catalog_file', '_wpnonce' => (string) ( $import_form['_wpnonce'] ?? '' ) ), 'catalog_file', 'wplc-catalog.json', (string) wp_json_encode( $catalog ), 'application/json' );
$imported = e2e_http( 'POST', admin_url( 'admin-post.php' ), array( 'cookies' => $cookies, 'headers' => $upload['headers'], 'body' => $upload['body'] ) );
e2e_ok( 302 === $imported['code'], 'the catalog file upload redirects back', array( $imported['code'], $imported['location'] ) );
CatalogStore::reset_cache();
$stored = CatalogStore::get( $product_key );
e2e_ok( is_array( $stored ) && 'admin' === ( $stored['source'] ?? '' ) && 'acme_reports' === ( $stored['prefix'] ?? '' ) && 3 === count( (array) $stored['entitlements'] ), 'the catalog of Acme Reports Pro is imported with its three entitlements', $stored );
$expect_done( array( 'secrets', 'public_key', 'catalog' ), 'after the catalog file import: the catalog step is done' );

// --- 3. First license product: the editor offers exactly the entitlements of the catalog file. ---
$product = new WC_Product_Simple();
$product->set_name( 'Acme Reports Pro' );
$product->set_sku( 'ACME-REPORTS-PRO' );
$product->set_regular_price( '79.00' );
$product->set_virtual( true );
$product->set_status( 'publish' );
$product_id = $product->save();

$editor = e2e_http( 'GET', admin_url( 'post.php?post=' . $product_id . '&action=edit' ), array( 'cookies' => $cookies ) );
e2e_ok( 200 === $editor['code'] && false !== strpos( $editor['body'], 'value="acme_reports.scheduled_exports"' ) && false !== strpos( $editor['body'], 'value="acme_reports.white_label"' ), 'the product editor offers the available entitlements of the imported catalog file', $editor['code'] );
e2e_ok( false === strpos( $editor['body'], 'value="acme_reports.ai_summaries"' ), 'the planned entitlement is not offered' );
e2e_ok( false !== strpos( $editor['body'], '<option value="' . $product_key . '"' ) && false !== strpos( html_entity_decode( $editor['body'] ), '"' . $product_key . '":"acme_reports"' ), 'the product key is suggested and mapped to its group only through the catalog (PK-01)' );
e2e_ok( false !== strpos( $editor['body'], 'placeholder="acme-pro"' ) && false === stripos( $editor['body'], 'placeholder="voucher' ), 'the product key placeholder is a neutral example (PROD-04)' );

$saved = e2e_http(
	'POST',
	admin_url( 'post.php' ),
	array(
		'cookies' => $cookies,
		'body'    => array(
			'_wpnonce'                 => wp_create_nonce( 'update-post_' . $product_id ),
			'action'                   => 'editpost',
			'originalaction'           => 'editpost',
			'post_ID'                  => (string) $product_id,
			'post_type'                => 'product',
			'user_ID'                  => (string) get_current_user_id(),
			'post_title'               => 'Acme Reports Pro',
			'post_status'              => 'publish',
			'original_post_status'     => 'publish',
			'product-type'             => 'simple',
			'_visibility'              => 'visible',
			'_tax_status'              => 'taxable',
			'_tax_class'               => '',
			'_sku'                     => 'ACME-REPORTS-PRO',
			'woocommerce_meta_nonce'   => wp_create_nonce( 'woocommerce_save_data' ),
			'_wplc_fields_present'     => '1',
			'_regular_price'           => '79.00',
			'_virtual'                 => 'on',
			'_wplc_is_license_product' => 'yes',
			'_wplc_product_key'        => $product_key,
			'_wplc_plan_key'           => 'pro',
			'_wplc_billing_interval'   => 'year',
			'_wplc_live_site_limit'    => '2',
			'_wplc_support_level'      => 'standard',
			'_wplc_entitlement_keys'   => array( 'acme_reports.scheduled_exports', 'acme_reports.white_label' ),
			'_wplc_entitlements_json'  => '{"acme_reports.report_limit":50}',
		),
	)
);
e2e_ok( 302 === $saved['code'], 'saving the license product succeeds', array( $saved['code'], $saved['location'] ) );
$plan = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['plans']} WHERE woocommerce_product_id = %d", $product_id ), ARRAY_A );
e2e_ok( is_array( $plan ) && $product_key === $plan['product_key'] && 'pro' === $plan['plan_key'] && '1' === (string) $plan['active'], 'the plan of Acme Reports Pro exists', $plan );
$plan_entitlements = is_array( $plan ) ? (array) json_decode( (string) $plan['entitlements_json'], true ) : array();
e2e_ok( true === ( $plan_entitlements['acme_reports.scheduled_exports'] ?? null ) && true === ( $plan_entitlements['acme_reports.white_label'] ?? null ) && 50 === ( $plan_entitlements['acme_reports.report_limit'] ?? null ), 'the plan stores the ticked entitlements and the limit', $plan_entitlements );
$expect_done( array( 'secrets', 'public_key', 'catalog', 'product' ), 'after the license product: the product step is done' );

// --- 4. First release: package with the catalog file in its plugin folder, uploaded and published. ---
$zip_file = wp_tempnam( 'wplc-e2e-acme.zip' );
$zip      = new ZipArchive();
$zip->open( $zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE );
$zip->addFromString( $product_key . '/' . $product_key . '.php', "<?php\n/**\n * Plugin Name: Acme Reports Pro\n * Author: {$vendor}\n * Version: 1.1.0\n */\n" );
$zip->addFromString( $product_key . '/wplc-catalog.json', (string) wp_json_encode( $catalog ) );
$zip->close();
$zip_bytes = (string) file_get_contents( $zip_file );
unlink( $zip_file );

$releases_page = e2e_http( 'GET', admin_url( 'admin.php?page=wplc-releases' ), array( 'cookies' => $cookies ) );
$create_form   = e2e_form( $releases_page['body'], array( 'value="wplc_release_create"' ) );
e2e_ok( is_array( $create_form ) && false !== strpos( $releases_page['body'], '<option value="' . $product_key . '"' ), 'the releases screen offers the product of the catalog file' );
$upload  = e2e_multipart(
	array(
		'action'        => 'wplc_release_create',
		'_wpnonce'      => (string) ( $create_form['_wpnonce'] ?? '' ),
		'product_key'   => $product_key,
		'version'       => '1.1.0',
		'requires_php'  => '7.4',
		'requires_wp'   => '6.5',
		'tested_wp'     => '6.8',
		'release_notes' => '<p>First release of Acme Reports Pro.</p>',
	),
	'package_zip',
	$product_key . '-1.1.0.zip',
	$zip_bytes,
	'application/zip'
);
$created = e2e_http( 'POST', admin_url( 'admin-post.php' ), array( 'cookies' => $cookies, 'headers' => $upload['headers'], 'body' => $upload['body'] ) );
$release = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['releases']} WHERE product_key = %s AND version = %s", $product_key, '1.1.0' ), ARRAY_A );
e2e_ok( 302 === $created['code'] && is_array( $release ) && 'draft' === $release['status'], 'the release draft 1.1.0 is created from the uploaded package', array( $created['code'], $created['location'] ) );
$expect_done( array( 'secrets', 'public_key', 'catalog', 'product' ), 'a draft release does not finish the release step' );
$result_page  = e2e_http( 'GET', $created['location'], array( 'cookies' => $cookies ) );
$publish_form = e2e_form( $result_page['body'], array( 'value="wplc_release_publish"', 'name="release_id" value="' . (int) ( $release['id'] ?? 0 ) . '"' ) );
$published    = e2e_submit( (array) $publish_form, $cookies, array( 'reason' => 'e2e: first Acme release' ) );
e2e_eq( 'published', (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$t['releases']} WHERE id = %d", (int) ( $release['id'] ?? 0 ) ) ), 'the release is published' );
$expect_done( array( 'secrets', 'public_key', 'catalog', 'product', 'release' ), 'after publishing: the release step is done' );

// --- 5. Test purchase: order paid, license issued. ---
$customer_id = e2e_customer( 'acmebuyer' );
$order       = e2e_create_order( $customer_id, array( $product_id => 1 ) );
$licenses    = e2e_order_licenses( $order->get_id() );
e2e_eq( 1, count( $licenses ), 'the test purchase issues one license' );
$license = $licenses[0] ?? array( 'id' => 0, 'product_key' => '', 'license_uuid' => '', 'license_key_secret_id' => '' );
e2e_eq( $product_key, $license['product_key'], 'the license belongs to Acme Reports Pro' );
$expect_done( array( 'secrets', 'public_key', 'catalog', 'product', 'release', 'test' ), 'after the test purchase: the test purchase step is done' );

// The add-on of the vendor: activation, entitlement check and update check through the client,
// pinned to the public key from step 1.
e2e_as_site( $site );
delete_option( $client_opt );
$client     = new LicenseClient( $server, $product_key, '1.0.0', $client_opt, array( 'public_keys' => array( $public_key ) ) );
$activation = $client->activate( e2e_key( $license ) );
e2e_ok( is_array( $activation ) && 'active' === ( $activation['license_status'] ?? null ) && 2 === ( $activation['site_limit'] ?? null ), 'the add-on activates the license (signed with the pinned key)', $error_of( $activation ) );
$check = $client->check_entitlements();
e2e_ok( is_array( $check ), 'the entitlement check succeeds', $error_of( $check ) );
$entitlements = is_array( $check ) ? (array) ( $check['entitlements'] ?? array() ) : array();
e2e_ok( true === ( $entitlements['acme_reports.scheduled_exports'] ?? null ) && true === ( $entitlements['acme_reports.white_label'] ?? null ) && 50 === ( $entitlements['acme_reports.report_limit'] ?? null ), 'the entitlements of the plan reach the add-on', $entitlements );
$foreign = array_filter(
	array_keys( $entitlements ),
	static function ( $key ) {
		return 0 !== strpos( (string) $key, 'acme_reports.' ) && 0 !== strpos( (string) $key, 'sites.' ) && 0 !== strpos( (string) $key, 'support.' ) && 0 !== strpos( (string) $key, 'updates.' );
	}
);
e2e_eq( array(), array_values( $foreign ), 'the add-on gets no entitlement of another product' );
$update = $client->check_update( true );
e2e_ok( is_array( $update ) && true === ( $update['update_available'] ?? null ) && '1.1.0' === ( $update['version'] ?? null ) && $product_key === ( $update['product_key'] ?? null ) && hash( 'sha256', $zip_bytes ) === ( $update['sha256'] ?? null ), 'the update check offers release 1.1.0 with its checksum', $error_of( $update ) );
e2e_as_site( '' );

// --- 6. Stripe webhook secret: every step is done, the checklist folds away. ---
$add_constant( 'WPLC_STRIPE_WEBHOOK_SECRET', 'whsec_e2e_acme_' . bin2hex( random_bytes( 8 ) ) );
$state = $expect_done( array( 'secrets', 'public_key', 'catalog', 'product', 'release', 'test', 'webhook' ), 'after the webhook secret: every step is done' );
e2e_ok( ! $state['open'], 'with every step done the checklist is folded away' );

// --- Clean-up: wp-config.php and everything this flow created, so later flows see the empty shop. ---
$restore();
$state = $checklist();
e2e_ok( false === $state['steps']['public_key'] && false === $state['steps']['webhook'], 'the checklist reads the state live: without the constants both steps are open again' );

$license_ids = array_map( 'intval', array_column( e2e_rows( 'licenses', "product_key = '" . esc_sql( $product_key ) . "'" ), 'id' ) );
wp_delete_post( $product_id, true );
$order->delete( true );
if ( $license_ids ) {
	$in = implode( ',', $license_ids );
	foreach ( array( 'activations', 'entitlements', 'renewals', 'download_tokens', 'package_streams', 'consents', 'reminder_log' ) as $table ) {
		$wpdb->query( "DELETE FROM {$t[ $table ]} WHERE license_id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL
	}
	$wpdb->query( "DELETE FROM {$t['licenses']} WHERE id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL
}
foreach ( e2e_rows( 'releases', "product_key = '" . esc_sql( $product_key ) . "'" ) as $row ) {
	$path = \WPLC\Update\LocalPackageStorage::path_for_key( (string) $row['package_url'] );
	if ( null !== $path && is_file( $path ) ) {
		unlink( $path );
	}
}
foreach ( array( 'releases', 'plans', 'products' ) as $table ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$t[ $table ]} WHERE product_key = %s", $product_key ) ); // phpcs:ignore WordPress.DB.PreparedSQL
}
CatalogStore::remove( $product_key );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $customer_id );
delete_option( $client_opt );
$wpdb->query( "DELETE FROM {$t['request_limits']}" ); // phpcs:ignore WordPress.DB.PreparedSQL
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_id > %d", $wpdb->esc_like( 'wplc_e2e_mail_' ) . '%', $mail_mark ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}actionscheduler_actions WHERE action_id > %d AND status = 'pending' AND hook LIKE %s", $action_mark, 'wplc%' ) );
wp_cache_flush();
CatalogStore::reset_cache();
e2e_eq( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['plans']}" ) + (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['licenses']}" ) + (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['releases']}" ), 'clean-up: the following flows start from the empty shop again' );
e2e_ok( null === CatalogStore::get( $product_key ), 'clean-up: the Acme catalog is removed' );
