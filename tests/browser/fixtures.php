<?php
/**
 * Browser acceptance: data the Playwright script needs, on top of the E2E state.
 *
 * Runs through `wp eval-file` against the E2E site after tests/e2e/bin/run-e2e.sh
 * (flows 01-06 at least). Creates (once) the customer "browser" with a paid license
 * bought with automatic renewal, makes sure a renewal waits for review, and prints
 * one JSON object with the IDs and URLs the script visits.
 *
 * @package WPLC
 */

require getenv( 'WPLC_E2E_DIR' ) . '/lib.php';

global $wpdb;
$t = e2e_tables();

$simple_id     = (int) e2e_get( 'simple_id' );
$variable_id   = (int) e2e_get( 'variable_id' );
$variation_ids = (array) e2e_get( 'variation_ids', array() );
if ( $simple_id <= 0 || $variable_id <= 0 ) {
	fwrite( STDERR, "E2E state is missing: run tests/e2e/bin/run-e2e.sh (at least flows 01-06) first.\n" );
	exit( 1 );
}

// The block checkout needs compiled WooCommerce Blocks scripts; a source build has none.
$blocks_built = file_exists( WP_PLUGIN_DIR . '/woocommerce/assets/client/blocks/checkout.js' );

$customer_id = e2e_customer( 'browser' );
update_user_meta( $customer_id, 'billing_phone', '030123456' );

$licenses = e2e_rows( 'licenses', $wpdb->prepare( 'customer_id = %d', $customer_id ) );
if ( ! $licenses ) {
	e2e_checkout( $customer_id, array( $simple_id => 1 ) );
	if ( $variation_ids ) {
		e2e_checkout( $customer_id, array( (int) $variation_ids[0] => 1 ), 'bacs', false );
	}
	$licenses = e2e_rows( 'licenses', $wpdb->prepare( 'customer_id = %d', $customer_id ) );
}
$license_id = $licenses ? (int) $licenses[0]['id'] : 0;

// One site on the license, so the account and the admin show the sites table. The address has
// no hyphen or space, so a narrow screen must break it inside the word (A-126).
$site_url = 'https://averyverylongsubdomainnamewithoutanybreakopportunity.customerexampleshop.test/shop';
if ( $license_id > 0 && ! e2e_rows( 'activations', $wpdb->prepare( 'license_id = %d AND site_url = %s', $license_id, $site_url ) ) ) {
	$now = gmdate( 'Y-m-d H:i:s' );
	$wpdb->insert(
		$t['activations'],
		array(
			'license_id'                    => $license_id,
			'installation_uuid'             => wp_generate_uuid4(),
			'installation_secret_encrypted' => '',
			'site_url'                      => $site_url,
			'environment'                   => 'production',
			'status'                        => 'active',
			'activated_at'                  => $now,
			'last_seen_at'                  => $now,
		)
	);
	if ( $wpdb->last_error ) {
		fwrite( STDERR, 'activation insert: ' . $wpdb->last_error . "\n" );
	}
}

// WooCommerce installs a block checkout; the classic (shortcode) checkout gets its own page.
$classic = get_page_by_path( 'classic-checkout' );
$classic_id = $classic ? (int) $classic->ID : (int) wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_name'    => 'classic-checkout',
		'post_title'   => 'Classic checkout',
		'post_content' => '<!-- wp:shortcode -->[woocommerce_checkout]<!-- /wp:shortcode -->',
	)
);

$review = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE status = 'review_required' ORDER BY id DESC LIMIT 1", $t['renewals'] ) );

$admin = get_user_by( 'login', 'admin' );

echo wp_json_encode(
	array(
		'admin'        => array( 'login' => 'admin', 'password' => 'admin-e2e-pass' ),
		'customer'     => array( 'login' => 'browser', 'password' => 'customer-e2e-pass', 'id' => $customer_id ),
		'simple_id'    => $simple_id,
		'variable_id'  => $variable_id,
		'license_id'   => $license_id,
		'review_id'    => $review ? (int) $review : 0,
		'admin_id'     => $admin ? (int) $admin->ID : 0,
		'checkout_url' => wc_get_checkout_url(),
		'classic_url'  => get_permalink( $classic_id ),
		'account_url'  => wc_get_page_permalink( 'myaccount' ),
		'licenses_url' => wc_get_account_endpoint_url( \WPLC\Account\CustomerPortal::endpoint_slug() ),
		'sites_url'    => wc_get_account_endpoint_url( \WPLC\Account\SiteOverview::ENDPOINT ),
		'cart_url'     => wc_get_cart_url(),
		'blocks_built' => $blocks_built,
	)
) . "\n";
