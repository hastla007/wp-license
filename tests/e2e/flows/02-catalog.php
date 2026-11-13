<?php
/**
 * Flow 2: catalog mapping through the product editor (real POSTs) and CSV round trip.
 *
 * @package WPLC
 */

require getenv( 'WPLC_E2E_DIR' ) . '/lib.php';

global $wpdb;
$t       = e2e_tables();
$admin   = get_user_by( 'login', 'admin' );
$cookies = e2e_login( (int) $admin->ID );

/**
 * Plan row of a product/variation.
 */
$plan_of = static function ( int $product_id, int $variation_id = 0 ) use ( $wpdb, $t ) {
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['plans']} WHERE woocommerce_product_id = %d AND woocommerce_variation_id = %d", $product_id, $variation_id ), ARRAY_A );
};

// --- Products as a merchant would have created them before marking them as license products. ---
$simple = new WC_Product_Simple();
$simple->set_name( 'Voucher Captain Pro' );
$simple->set_sku( 'VC-PRO' );
$simple->set_regular_price( '49.00' );
$simple->set_virtual( true );
$simple->set_status( 'publish' );
$simple_id = $simple->save();

$variable  = new WC_Product_Variable();
$variable->set_name( 'Affiliate Captain' );
$variable->set_sku( 'AC' );
$attribute = new WC_Product_Attribute();
$attribute->set_name( 'Plan' );
$attribute->set_options( array( 'Business', 'Agency' ) );
$attribute->set_visible( true );
$attribute->set_variation( true );
$variable->set_attributes( array( $attribute ) );
$variable->set_status( 'publish' );
$variable_id = $variable->save();

$variation_ids = array();
foreach ( array( 'Business' => array( 'AC-BUS', '99.00' ), 'Agency' => array( 'AC-AGY', '19.90' ) ) as $option => $spec ) {
	$variation = new WC_Product_Variation();
	$variation->set_parent_id( $variable_id );
	$variation->set_attributes( array( 'plan' => $option ) );
	$variation->set_sku( $spec[0] );
	$variation->set_regular_price( $spec[1] );
	$variation->set_virtual( true );
	$variation->set_status( 'publish' );
	$variation_ids[] = $variation->save();
}
$plain = new WC_Product_Simple();
$plain->set_name( 'Plain T-Shirt' );
$plain->set_sku( 'TSHIRT' );
$plain->set_regular_price( '15.00' );
$plain->set_status( 'publish' );
$plain_id = $plain->save();

// --- Editor screen renders the license fields. ---
$editor = e2e_http( 'GET', admin_url( 'post.php?post=' . $simple_id . '&action=edit' ), array( 'cookies' => $cookies ) );
e2e_ok( 200 === $editor['code'] && false !== strpos( $editor['body'], 'name="_wplc_product_key"' ) && false !== strpos( $editor['body'], 'name="_wplc_entitlement_keys[]"' ), 'product editor (simple) renders the license fields and the entitlement checklist', $editor['code'] );
e2e_ok( false !== strpos( $editor['body'], 'wplc-product-editor.js' ) && false !== strpos( $editor['body'], 'wplc-admin.css' ), 'product editor enqueues the plugin script and style' );
foreach ( array( 'assets/js/wplc-product-editor.js', 'assets/css/wplc-admin.css' ) as $asset ) {
	$asset_response = e2e_http( 'GET', home_url( '/wp-content/plugins/wp-license/' . $asset ) );
	e2e_eq( 200, $asset_response['code'], 'asset is served: ' . $asset );
}

/**
 * Submit the classic product edit form.
 */
$save_product = static function ( int $product_id, string $type, array $fields ) use ( $cookies ) {
	$body = array_merge(
		array(
			'_wpnonce'               => wp_create_nonce( 'update-post_' . $product_id ),
			'action'                 => 'editpost',
			'originalaction'         => 'editpost',
			'post_ID'                => (string) $product_id,
			'post_type'              => 'product',
			'user_ID'                => (string) get_current_user_id(),
			'post_title'             => get_the_title( $product_id ),
			'post_status'            => 'publish',
			'original_post_status'   => 'publish',
			'product-type'           => $type,
			'_visibility'            => 'visible',
			'_tax_status'            => 'taxable',
			'_tax_class'             => '',
			'_sku'                   => (string) get_post_meta( $product_id, '_sku', true ),
			'woocommerce_meta_nonce' => wp_create_nonce( 'woocommerce_save_data' ),
		),
		$fields
	);

	return e2e_http( 'POST', admin_url( 'post.php' ), array( 'cookies' => $cookies, 'body' => $body ) );
};

// --- Simple product: mark as license product. ---
$response = $save_product(
	$simple_id,
	'simple',
	array(
		'_regular_price'           => '49.00',
		'_virtual'                 => 'on',
		'_wplc_is_license_product' => 'yes',
		'_wplc_product_key'        => 'voucher-captain',
		'_wplc_plan_key'           => 'pro',
		'_wplc_billing_interval'   => 'year',
		'_wplc_live_site_limit'    => '1',
		'_wplc_support_level'      => 'priority',
		'_wplc_entitlement_keys'   => array( 'voucher.custom_branding', 'voucher.pdf_templates', 'not.a.known.key' ),
		'_wplc_entitlements_json'  => '{"voucher.monthly_limit":500}',
	)
);
e2e_ok( 302 === $response['code'] && false !== strpos( $response['location'], 'message=' ), 'saving the simple product through post.php succeeds', array( $response['code'], $response['location'], substr( wp_strip_all_tags( $response['body'] ), 0, 300 ) ) );
$plan = $plan_of( $simple_id );
e2e_ok( is_array( $plan ), 'plan row exists for the simple product' );
if ( is_array( $plan ) ) {
	e2e_eq(
		array( 'voucher-captain', 'pro', 'year', '4900', '2', 'EUR', '1', 'priority', '1' ),
		array( $plan['product_key'], $plan['plan_key'], $plan['billing_interval'], $plan['price_minor'], $plan['price_decimals'], $plan['currency'], $plan['live_site_limit'], $plan['support_level'], $plan['active'] ),
		'plan row carries key, interval, price 4900 EUR, limit and support level'
	);
	$entitlements = json_decode( $plan['entitlements_json'], true );
	ksort( $entitlements );
	e2e_eq(
		array( 'sites.live_limit' => 1, 'support.level' => 'priority', 'voucher.custom_branding' => true, 'voucher.monthly_limit' => 500, 'voucher.pdf_templates' => true ),
		$entitlements,
		'plan entitlements = checked boxes + advanced JSON + automatic limit/support (unknown checkbox key dropped)'
	);
}
e2e_eq( 'Voucher Captain Pro', (string) $wpdb->get_var( $wpdb->prepare( "SELECT display_name FROM {$t['products']} WHERE product_key = %s", 'voucher-captain' ) ), 'product row stores the display name' );
e2e_eq( 'yes', (string) wc_get_product( $simple_id )->get_meta( '_wplc_is_license_product', true ), 'product meta _wplc_is_license_product = yes' );

// Editor shows the saved state again.
$editor = e2e_http( 'GET', admin_url( 'post.php?post=' . $simple_id . '&action=edit' ), array( 'cookies' => $cookies ) );
e2e_ok( (bool) preg_match( '/id="wplc_ent_p_voucher_custom_branding"[^>]*checked/', $editor['body'] ) && false !== strpos( $editor['body'], 'voucher.monthly_limit' ), 'editor re-renders the stored entitlements (checkbox checked, advanced JSON shown)' );

// A price change in the editor must reach the plan (the renewal price) with the same save.
$response = $save_product(
	$simple_id,
	'simple',
	array(
		'_regular_price'           => '59.00',
		'_virtual'                 => 'on',
		'_wplc_is_license_product' => 'yes',
		'_wplc_product_key'        => 'voucher-captain',
		'_wplc_plan_key'           => 'pro',
		'_wplc_billing_interval'   => 'year',
		'_wplc_live_site_limit'    => '1',
		'_wplc_support_level'      => 'priority',
		'_wplc_entitlement_keys'   => array( 'voucher.custom_branding', 'voucher.pdf_templates' ),
		'_wplc_entitlements_json'  => '{"voucher.monthly_limit":500}',
	)
);
e2e_eq( '59.00', (string) wc_get_product( $simple_id )->get_regular_price(), 'editor save changed the product price to 59.00' );
$plan = $plan_of( $simple_id );
e2e_eq( '5900', is_array( $plan ) ? (string) $plan['price_minor'] : null, 'the same save updates the plan price to 5900 (price edited together with the license fields)' );
// Back to 49.00 for the following flows.
$save_product(
	$simple_id,
	'simple',
	array(
		'_regular_price'           => '49.00',
		'_virtual'                 => 'on',
		'_wplc_is_license_product' => 'yes',
		'_wplc_product_key'        => 'voucher-captain',
		'_wplc_plan_key'           => 'pro',
		'_wplc_billing_interval'   => 'year',
		'_wplc_live_site_limit'    => '1',
		'_wplc_support_level'      => 'priority',
		'_wplc_entitlement_keys'   => array( 'voucher.custom_branding', 'voucher.pdf_templates' ),
		'_wplc_entitlements_json'  => '{"voucher.monthly_limit":500}',
	)
);
$plan = $plan_of( $simple_id );
e2e_eq( '4900', is_array( $plan ) ? (string) $plan['price_minor'] : null, 'plan price is 4900 again after setting the price back' );

// Invalid advanced JSON: nothing is saved, the existing mapping stays.
$save_product(
	$simple_id,
	'simple',
	array(
		'_regular_price'           => '49.00',
		'_wplc_is_license_product' => 'yes',
		'_wplc_product_key'        => 'voucher-captain',
		'_wplc_plan_key'           => 'changed',
		'_wplc_billing_interval'   => 'year',
		'_wplc_live_site_limit'    => '1',
		'_wplc_support_level'      => 'priority',
		'_wplc_entitlements_json'  => '{broken',
	)
);
$plan = $plan_of( $simple_id );
e2e_eq( 'pro', is_array( $plan ) ? $plan['plan_key'] : null, 'invalid advanced JSON leaves the existing plan untouched' );

// A non-license product gets no plan.
$save_product( $plain_id, 'simple', array( '_regular_price' => '15.00' ) );
e2e_ok( null === $plan_of( $plain_id ), 'a product without the license checkbox has no plan' );

// --- Variations: loaded and saved the way the editor does it (admin-ajax). ---
$loaded = e2e_http(
	'POST',
	admin_url( 'admin-ajax.php' ),
	array(
		'cookies' => $cookies,
		'body'    => array(
			'action'     => 'woocommerce_load_variations',
			'security'   => wp_create_nonce( 'load-variations' ),
			'product_id' => (string) $variable_id,
			'attributes' => array( 'plan' => '' ),
			'page'       => '1',
			'per_page'   => '10',
		),
	)
);
e2e_ok( 200 === $loaded['code'] && false !== strpos( $loaded['body'], 'name="_wplc_product_key[0]"' ) && false !== strpos( $loaded['body'], 'name="_wplc_product_key[1]"' ) && false !== strpos( $loaded['body'], '_wplc_variation_entitlement_keys[1][]' ), 'variation panel renders indexed license fields for both variations', array( $loaded['code'], substr( $loaded['body'], 0, 200 ) ) );

$variation_fields = array(
	'variable_post_id'                  => array( (string) $variation_ids[0], (string) $variation_ids[1] ),
	'variable_sku'                      => array( 'AC-BUS', 'AC-AGY' ),
	'variable_regular_price'            => array( '99.00', '19.90' ),
	'variable_enabled'                  => array( 'on', 'on' ),
	'variable_is_virtual'               => array( 'on', 'on' ),
	'variation_menu_order'              => array( '0', '1' ),
	'attribute_plan'                    => array( 'Business', 'Agency' ),
	'_wplc_is_license_product'          => array( 'yes', 'yes' ),
	'_wplc_product_key'                 => array( 'affiliate-captain', 'affiliate-captain' ),
	'_wplc_plan_key'                    => array( 'business', 'agency-monthly' ),
	'_wplc_billing_interval'            => array( 'year', 'month' ),
	'_wplc_live_site_limit'             => array( '2', '10' ),
	'_wplc_support_level'               => array( 'standard', 'priority' ),
	'_wplc_entitlements_json'           => array( '', '{"affiliate.program_limit":3}' ),
	'_wplc_variation_entitlement_keys'  => array( array( 'affiliate.partner_portal' ), array( 'affiliate.partner_portal', 'affiliate.webhooks' ) ),
);
$saved = e2e_http(
	'POST',
	admin_url( 'admin-ajax.php' ),
	array(
		'cookies' => $cookies,
		'body'    => array_merge(
			array(
				'action'       => 'woocommerce_save_variations',
				'security'     => wp_create_nonce( 'save-variations' ),
				'product_id'   => (string) $variable_id,
				'product-type' => 'variable',
				// The AJAX save carries the WooCommerce meta nonce of the surrounding form.
				'woocommerce_meta_nonce' => wp_create_nonce( 'woocommerce_save_data' ),
			),
			$variation_fields
		),
	)
);
e2e_eq( 200, $saved['code'], 'admin-ajax woocommerce_save_variations answers 200' );
$business = $plan_of( $variable_id, $variation_ids[0] );
$agency   = $plan_of( $variable_id, $variation_ids[1] );
e2e_ok( is_array( $business ) && is_array( $agency ), 'both variations have a plan after the AJAX save', array( $business, $agency, substr( $saved['body'], 0, 300 ) ) );
if ( is_array( $business ) && is_array( $agency ) ) {
	e2e_eq( array( 'affiliate-captain', 'business', 'year', '9900', '2' ), array( $business['product_key'], $business['plan_key'], $business['billing_interval'], $business['price_minor'], $business['live_site_limit'] ), 'variation 1 plan: business, yearly, 9900, 2 sites' );
	e2e_eq( array( 'affiliate-captain', 'agency-monthly', 'month', '1990', '10' ), array( $agency['product_key'], $agency['plan_key'], $agency['billing_interval'], $agency['price_minor'], $agency['live_site_limit'] ), 'variation 2 plan: agency-monthly, monthly, 1990, 10 sites' );
	$agency_entitlements = json_decode( $agency['entitlements_json'], true );
	e2e_ok( true === ( $agency_entitlements['affiliate.webhooks'] ?? null ) && 3 === ( $agency_entitlements['affiliate.program_limit'] ?? null ) && 10 === ( $agency_entitlements['sites.live_limit'] ?? null ), 'variation 2 entitlements merged from its own checkboxes and JSON', $agency_entitlements );
}

// --- "Update" of the whole variable product: the form contains the parent fields AND the variation fields. ---
// A browser submits the parent's `_wplc_product_key=` first and the variations' `_wplc_product_key[0]=` after it.
$encode = static function ( array $pairs ) {
	$parts = array();
	foreach ( $pairs as $pair ) {
		$parts[] = rawurlencode( $pair[0] ) . '=' . rawurlencode( $pair[1] );
	}
	return implode( '&', $parts );
};
$pairs = array(
	array( '_wpnonce', wp_create_nonce( 'update-post_' . $variable_id ) ),
	array( 'action', 'editpost' ),
	array( 'originalaction', 'editpost' ),
	array( 'post_ID', (string) $variable_id ),
	array( 'post_type', 'product' ),
	array( 'user_ID', (string) get_current_user_id() ),
	array( 'post_title', 'Affiliate Captain' ),
	array( 'post_status', 'publish' ),
	array( 'original_post_status', 'publish' ),
	array( 'product-type', 'variable' ),
	array( '_sku', 'AC' ),
	array( 'woocommerce_meta_nonce', wp_create_nonce( 'woocommerce_save_data' ) ),
	array( 'attribute_names[0]', 'Plan' ),
	array( 'attribute_values[0]', 'Business | Agency' ),
	array( 'attribute_visibility[0]', '1' ),
	array( 'attribute_variation[0]', '1' ),
	array( 'attribute_position[0]', '0' ),
	// Parent ("General" tab) license fields: unchecked checkbox is not submitted, the rest is.
	array( '_wplc_product_key', '' ),
	array( '_wplc_plan_key', '' ),
	array( '_wplc_billing_interval', 'year' ),
	array( '_wplc_live_site_limit', '1' ),
	array( '_wplc_support_level', 'standard' ),
	array( '_wplc_entitlements_json', '' ),
);
foreach ( $variation_fields as $name => $values ) {
	foreach ( $values as $index => $value ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				$pairs[] = array( $name . '[' . $index . '][]', $item );
			}
		} else {
			$pairs[] = array( $name . '[' . $index . ']', $value );
		}
	}
}
$response = e2e_http( 'POST', admin_url( 'post.php' ), array( 'cookies' => $cookies, 'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ), 'body' => $encode( $pairs ) ) );
e2e_ok( 302 === $response['code'] && false !== strpos( $response['location'], 'message=' ), 'saving the whole variable product (parent + loaded variations) through post.php succeeds', array( $response['code'], $response['location'] ) );
$business = $plan_of( $variable_id, $variation_ids[0] );
$agency   = $plan_of( $variable_id, $variation_ids[1] );
e2e_ok( is_array( $business ) && 'business' === $business['plan_key'] && is_array( $agency ) && 'agency-monthly' === $agency['plan_key'], 'variation plans survive a full "Update" of the variable product', array( $business, $agency ) );
e2e_ok( null === $plan_of( $variable_id, 0 ), 'the unlicensed parent of the variable product has no plan of its own' );
e2e_eq( '99.00', (string) wc_get_product( $variation_ids[0] )->get_regular_price(), 'variation price unchanged by the full save' );

// --- CSV export / import round trip over HTTP. ---
$export = e2e_http( 'POST', admin_url( 'admin-post.php' ), array( 'cookies' => $cookies, 'body' => array( 'action' => 'wplc_export_catalog_csv', '_wpnonce' => wp_create_nonce( 'wplc_catalog_csv' ) ) ) );
$content_type = isset( $export['headers']['content-type'] ) ? (string) $export['headers']['content-type'] : '';
e2e_ok( 200 === $export['code'] && 0 === strpos( $content_type, 'text/csv' ), 'CSV export answers 200 text/csv', array( $export['code'], $content_type ) );
$lines = array_values( array_filter( explode( "\n", trim( $export['body'] ) ) ) );
e2e_eq( 4, count( $lines ), 'CSV export has the header and 3 mapped rows' );
e2e_ok( false === strpos( $export['body'], 'WPLC-' ), 'CSV export contains no license key' );
$parsed = array();
$stream = fopen( 'php://memory', 'w+' );
fwrite( $stream, $export['body'] );
rewind( $stream );
while ( false !== ( $row = fgetcsv( $stream, 0, ',', '"', '\\' ) ) ) {
	$parsed[] = $row;
}
fclose( $stream );
e2e_eq( array( 'product_id', 'variation_id', 'sku', 'product_key', 'plan_key', 'billing_interval', 'live_site_limit', 'support_level', 'entitlements_json' ), $parsed[0] ?? null, 'CSV header row' );
e2e_eq( array( (string) $simple_id, '0', 'VC-PRO', 'voucher-captain', 'pro', 'year', '1', 'priority' ), array_slice( $parsed[1] ?? array(), 0, 8 ), 'CSV row of the simple product' );

// Wipe the mappings, import the exported file unchanged: the catalog must be identical.
$before = $wpdb->get_results( "SELECT product_key, plan_key, woocommerce_product_id, woocommerce_variation_id, billing_interval, price_minor, live_site_limit, support_level, entitlements_json FROM {$t['plans']} ORDER BY woocommerce_product_id, woocommerce_variation_id", ARRAY_A );
$wpdb->query( "DELETE FROM {$t['plans']}" );
$upload = e2e_multipart( array( 'action' => 'wplc_import_catalog_csv', '_wpnonce' => wp_create_nonce( 'wplc_catalog_csv' ) ), 'catalog_csv', 'wp-license-catalog.csv', $export['body'], 'text/csv' );
$import = e2e_http( 'POST', admin_url( 'admin-post.php' ), array( 'cookies' => $cookies, 'headers' => $upload['headers'], 'body' => $upload['body'] ) );
e2e_ok( 302 === $import['code'] && false !== strpos( $import['location'], 'imported=3' ) && false !== strpos( $import['location'], 'failed=0' ), 'CSV import of the exported file reports imported=3, failed=0', array( $import['code'], $import['location'] ) );
$after = $wpdb->get_results( "SELECT product_key, plan_key, woocommerce_product_id, woocommerce_variation_id, billing_interval, price_minor, live_site_limit, support_level, entitlements_json FROM {$t['plans']} ORDER BY woocommerce_product_id, woocommerce_variation_id", ARRAY_A );
$normalize = static function ( array $rows ) {
	foreach ( $rows as &$row ) {
		$decoded = json_decode( $row['entitlements_json'], true );
		ksort( $decoded );
		$row['entitlements_json'] = $decoded;
	}
	return $rows;
};
e2e_eq( $normalize( $before ), $normalize( $after ), 'catalog after export → wipe → import equals the catalog before' );

// A changed row is applied; a row with a foreign SKU and a broken row are rejected.
$changed   = $parsed;
$changed[1][6] = '5';
$changed[] = array( (string) $plain_id, '0', 'WRONG-SKU', 'voucher-captain', 'x', 'year', '1', 'standard', '{}' );
$changed[] = array( (string) $simple_id, '0', 'VC-PRO', 'voucher-captain', 'pro', 'decade', '1', 'standard', '{}' );
$stream    = fopen( 'php://memory', 'w+' );
foreach ( $changed as $row ) {
	fputcsv( $stream, $row, ',', '"', '\\' );
}
rewind( $stream );
$csv = stream_get_contents( $stream );
fclose( $stream );
$upload = e2e_multipart( array( 'action' => 'wplc_import_catalog_csv', '_wpnonce' => wp_create_nonce( 'wplc_catalog_csv' ) ), 'catalog_csv', 'changed.csv', $csv, 'text/csv' );
$import = e2e_http( 'POST', admin_url( 'admin-post.php' ), array( 'cookies' => $cookies, 'headers' => $upload['headers'], 'body' => $upload['body'] ) );
e2e_ok( false !== strpos( $import['location'], 'imported=3' ) && false !== strpos( $import['location'], 'failed=2' ), 'CSV import applies valid rows and counts the 2 invalid ones', $import['location'] );
$plan = $plan_of( $simple_id );
e2e_eq( '5', is_array( $plan ) ? (string) $plan['live_site_limit'] : null, 'imported site limit 5 is in the plan row' );
e2e_ok( null === $plan_of( $plain_id ), 'row with a foreign SKU did not create a mapping' );
$result_page = e2e_http( 'GET', $import['location'], array( 'cookies' => $cookies ) );
e2e_ok( 200 === $result_page['code'] && false !== strpos( $result_page['body'], 'Imported: 3. Skipped or invalid: 2.' ), 'CSV screen shows the import result', $result_page['code'] );

// Import without a nonce is refused.
$upload = e2e_multipart( array( 'action' => 'wplc_import_catalog_csv', '_wpnonce' => 'invalid' ), 'catalog_csv', 'x.csv', $export['body'], 'text/csv' );
$denied = e2e_http( 'POST', admin_url( 'admin-post.php' ), array( 'cookies' => $cookies, 'headers' => $upload['headers'], 'body' => $upload['body'] ) );
e2e_eq( 403, $denied['code'], 'CSV import with an invalid nonce is refused (403)' );

// Site limit back to 1 through the editor for the activation flow (limit 1 → second site is refused).
$save_product(
	$simple_id,
	'simple',
	array(
		'_regular_price'           => '49.00',
		'_virtual'                 => 'on',
		'_wplc_is_license_product' => 'yes',
		'_wplc_product_key'        => 'voucher-captain',
		'_wplc_plan_key'           => 'pro',
		'_wplc_billing_interval'   => 'year',
		'_wplc_live_site_limit'    => '1',
		'_wplc_support_level'      => 'priority',
		'_wplc_entitlement_keys'   => array( 'voucher.custom_branding', 'voucher.pdf_templates' ),
		'_wplc_entitlements_json'  => '{"voucher.monthly_limit":500}',
	)
);
$plan = $plan_of( $simple_id );
e2e_eq( '1', is_array( $plan ) ? (string) $plan['live_site_limit'] : null, 'site limit is 1 again' );

// The manual "Add license" form now lists the plans.
$add_form = e2e_http( 'GET', admin_url( 'admin.php?page=wplc-licenses&view=add' ), array( 'cookies' => $cookies ) );
e2e_ok( 200 === $add_form['code'] && false !== strpos( $add_form['body'], 'name="plan_id"' ) && false !== strpos( $add_form['body'], 'agency-monthly' ), '"Add license manually" form lists the catalog plans' );

e2e_set( 'simple_id', $simple_id );
e2e_set( 'variable_id', $variable_id );
e2e_set( 'variation_ids', $variation_ids );
e2e_set( 'plain_id', $plain_id );
