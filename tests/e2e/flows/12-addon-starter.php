<?php
/**
 * Flow 12: the premium add-on starter (examples/premium-addon-starter) against the real server.
 *
 * Generates an add-on with bin/init-addon.php (product key voucher-captain-pro, pinned to this server's Ed25519 key,
 * WooCommerce standing in for the core plugin), installs and activates it, activates a purchased license through
 * the settings page form, checks entitlements, refreshes, simulates an outage, tampers the cache, runs the update
 * check without a logged-in user, refuses a package with a wrong checksum, installs a verified update and uninstalls.
 *
 * @package WPLC
 */

require getenv( 'WPLC_E2E_DIR' ) . '/lib.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/misc.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

global $wpdb;
$t          = e2e_tables();
$starter    = dirname( (string) getenv( 'WPLC_E2E_DIR' ), 2 ) . '/examples/premium-addon-starter';
$slug       = 'voucher-captain-pro';
$basename   = $slug . '/' . $slug . '.php';
$addon_dir  = WP_PLUGIN_DIR . '/' . $slug;
$ns         = 'E2E\\VoucherPro\\';
$prefix     = 'e2e_vcp';
$public_key = \WPLC\Security\ResponseSigner::public_key();
$tmp_root   = get_temp_dir() . 'wplc-e2e-addon-' . getmypid();
$error_of   = static function ( $result ) {
	return is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : wp_json_encode( $result );
};
$rrmdir     = static function ( string $dir ) use ( &$rrmdir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	foreach ( (array) scandir( $dir ) as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}
		$path = $dir . '/' . $entry;
		is_dir( $path ) && ! is_link( $path ) ? $rrmdir( $path ) : unlink( $path );
	}
	rmdir( $dir );
};
$generate = static function ( string $target, string $version ) use ( $starter, $slug, $prefix, $public_key ): array {
	$args = array(
		'--target=' . $target,
		'--name=Voucher Captain Pro',
		'--slug=' . $slug,
		'--namespace=E2E\\VoucherPro',
		'--prefix=' . $prefix,
		'--product-key=voucher-captain-pro',
		'--entitlement-prefix=voucher.',
		'--core-name=WooCommerce',
		'--core-slug=woocommerce',
		'--core-constant=WC_VERSION',
		'--core-min=8.0',
		'--requires-php=7.4',
		'--server-url=https://shop.test',
		'--public-key=' . $public_key,
		'--version=' . $version,
	);
	exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $starter . '/bin/init-addon.php' ) . ' ' . implode( ' ', array_map( 'escapeshellarg', $args ) ) . ' 2>&1', $output, $code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	return array( $code, implode( "\n", $output ) );
};

// --- Clean state (re-runs with WPLC_E2E_KEEP_DB=1). ---
if ( is_plugin_active( $basename ) ) {
	deactivate_plugins( $basename, true );
}
$rrmdir( $addon_dir );
foreach ( array( '_license', '_license_state', '_update' ) as $suffix ) {
	delete_option( $prefix . $suffix );
}
// The driver only scans debug.log for wp-license code; the add-on's own entries are checked at the end.
$debug_log   = (string) getenv( 'WPLC_E2E_DEBUG_LOG' );
$debug_lines = is_file( $debug_log ) ? count( (array) file( $debug_log ) ) : 0;

// --- Generate and install. ---
e2e_ok( '' !== $public_key, 'the e2e server has an Ed25519 response signing key to pin' );
list( $code, $output ) = $generate( $addon_dir, '1.0.0' );
e2e_ok( 0 === $code && is_file( $addon_dir . '/' . $slug . '.php' ), 'bin/init-addon.php creates the add-on from the starter', $output );
$config = (string) file_get_contents( $addon_dir . '/src/Config.php' );
e2e_ok( false !== strpos( $config, "PRODUCT_KEY = 'voucher-captain-pro'" ) && false !== strpos( $config, $public_key ) && false === strpos( $config, 'Vendor\\Product' ), 'generated Config carries product key, pinned server key and the new namespace' );
$header = get_plugin_data( $addon_dir . '/' . $slug . '.php', false, false );
e2e_eq( array( 'Voucher Captain Pro', '1.0.0', 'woocommerce', 'https://shop.test/voucher-captain-pro', 'voucher-captain-pro' ), array( $header['Name'], $header['Version'], $header['RequiresPlugins'] ?? '', $header['UpdateURI'], $header['TextDomain'] ), 'plugin header: name, version, Requires Plugins, Update URI (shop, not WordPress.org), text domain' );
wp_clean_plugins_cache();
$activated = activate_plugin( $basename );
e2e_ok( ! is_wp_error( $activated ) && is_plugin_active( $basename ), 'the add-on activates (its required plugin is active)', $error_of( $activated ) );
// plugins_loaded has already run in this WP-CLI process: load the add-on by hand, as WordPress would on the next request.
$plugin_class = $ns . 'Plugin';
$plugin       = $plugin_class::instance();
e2e_ok( null !== $plugin, 'the add-on booted' );
if ( null === $plugin ) {
	return;
}
$plugin->load();
$config_class = $ns . 'Config';
$service_of   = static function ( string $server_url = '', ?callable $clock = null ) use ( $ns, $config_class ) {
	$client_class  = $ns . 'Client\\LicenseClient';
	$service_class = $ns . 'License\\LicenseService';
	$client        = new $client_class( '' === $server_url ? $config_class::server_url() : $server_url, $config_class::PRODUCT_KEY, '1.0.0', $config_class::credentials_option(), array( 'public_keys' => $config_class::server_public_keys() ) );
	return new $service_class( $client, $config_class::credentials_option(), $config_class::state_option(), $config_class::ENTITLEMENT_PREFIX, $config_class::CACHE_MAX_AGE, $clock );
};
$entitlements = $ns . 'License\\Entitlements';
e2e_eq( false, $entitlements::has( 'voucher.custom_branding' ), 'before activation every premium feature is off (fail closed)' );

// --- Purchase a license. ---
$zoe_id   = e2e_customer( 'zoe' );
$order    = e2e_create_order( $zoe_id, array( (int) e2e_get( 'simple_id' ) => 1 ) );
$licenses = e2e_order_licenses( $order->get_id() );
e2e_ok( 1 === count( $licenses ) && 'voucher-captain-pro' === $licenses[0]['product_key'], 'a paid order for the voucher-captain-pro product issues one license', count( $licenses ) );
if ( ! $licenses ) {
	return;
}
$license     = $licenses[0];
$license_key = e2e_key( $license );
$granted     = array();
foreach ( e2e_rows( 'entitlements', 'license_id = ' . (int) $license['id'] . " AND status = 'active'" ) as $row ) {
	$granted[ $row['entitlement_key'] ] = json_decode( $row['value_json'], true );
}
$true_keys = array_keys( array_filter( $granted, static function ( $value, $key ) {
	return true === $value && 0 === strpos( (string) $key, 'voucher.' );
}, ARRAY_FILTER_USE_BOTH ) );
e2e_ok( count( $true_keys ) > 0, 'the license has boolean voucher.* entitlements to check', $granted );

// --- Activate through the settings page form (real admin-post handler in the server process). ---
$customer_cookies = e2e_login( $zoe_id );
$denied           = e2e_http( 'POST', admin_url( 'admin-post.php' ), array( 'cookies' => $customer_cookies, 'body' => array( 'action' => $prefix . '_license', 'operation' => 'deactivate' ) ) );
e2e_ok( 302 !== $denied['code'] || false === strpos( $denied['location'], 'e2e-vcp-license' ), 'a customer cannot use the license handler (manage_options)', $denied['code'] );
// Nonces belong to the login session: log in once, then fetch the form.
$admin_cookies = e2e_login( (int) e2e_get( 'admin_id' ) );
$page_url      = admin_url( 'options-general.php?page=e2e-vcp-license' );
$page          = e2e_http( 'GET', $page_url, array( 'cookies' => $admin_cookies ) );
$form          = e2e_form( $page['body'], array( 'name="operation" value="activate"' ) );
e2e_ok( 200 === $page['code'] && is_array( $form ) && ! empty( $form['_wpnonce'] ) && false !== strpos( $page['body'], 'name="environment"' ), 'Settings → Voucher Captain Pro license shows the activation form with nonce and environment selector', $page['code'] );
$bad = e2e_submit( (array) $form, $admin_cookies, array( 'license_key' => $license_key, '_wpnonce' => 'wrong' ) );
e2e_ok( in_array( $bad['code'], array( 403, 200 ), true ) && false === get_option( $prefix . '_license' ), 'a forged nonce is refused and stores nothing', $bad['code'] );
$submitted     = e2e_submit( (array) $form, $admin_cookies, array( 'license_key' => strtolower( $license_key ), 'environment' => 'production', 'staging_parent' => '' ) );
e2e_ok( 302 === $submitted['code'] && false !== strpos( $submitted['location'], 'page=e2e-vcp-license' ), 'activation form redirects back to the license page', array( $submitted['code'], $submitted['location'] ) );
$page = e2e_http( 'GET', $page_url, array( 'cookies' => $admin_cookies ) );
e2e_ok( false !== strpos( $page['body'], 'The license is active on this site.' ) && false !== strpos( $page['body'], '<td>active</td>' ) && false !== strpos( $page['body'], $true_keys[0] ), 'license page shows the success notice, status active and the plan features', substr( wp_strip_all_tags( $page['body'] ), 0, 600 ) );
$credentials = get_option( $prefix . '_license' );
$credentials = is_array( $credentials ) ? $credentials : array();
$activation  = e2e_rows( 'activations', "installation_uuid = '" . esc_sql( (string) ( $credentials['installation_id'] ?? '' ) ) . "'" );
e2e_ok( 1 === count( $activation ) && 'active' === $activation[0]['status'] && (int) $activation[0]['license_id'] === (int) $license['id'] && 'production' === $activation[0]['environment'], 'the server has one active production activation for this installation and license', $activation );
e2e_ok( false === strpos( $page['body'], (string) ( $credentials['installation_secret'] ?? 'no-secret' ) ) && false === strpos( wp_json_encode( get_option( $prefix . '_license_state' ) ), $license_key ), 'neither the installation secret nor the license key is shown or stored in the state' );

// --- Entitlements from the signed cache. ---
$service = $service_of();
$state   = get_option( $prefix . '_license_state' );
e2e_ok( is_array( $state['envelope'] ?? null ) && isset( $state['envelope']['payload'], $state['envelope']['payload_signature_ed25519'] ) && '' !== $state['envelope']['payload_signature_ed25519'], 'the cache is the complete signed envelope (payload + server signature)' );
$entitlements::use_service( $service );
$all = true;
foreach ( $true_keys as $key ) {
	$all = $all && $entitlements::has( $key );
}
e2e_ok( $all, 'Entitlements::has() is true for every granted key: ' . implode( ', ', $true_keys ) );
if ( isset( $granted['voucher.monthly_limit'] ) ) {
	e2e_eq( (int) $granted['voucher.monthly_limit'], $entitlements::limit( 'voucher.monthly_limit' ), 'numeric entitlement voucher.monthly_limit is read as limit' );
}
e2e_eq( array( false, false ), array( $entitlements::has( 'voucher.white_label' ), $entitlements::has( 'affiliate.partner_portal' ) ), 'a key the plan lacks and a key of another product are off' );

// --- Refresh (as the daily cron does). ---
$before = (int) ( $state['checked_at'] ?? 0 );
sleep( 1 );
$plugin->daily();
wp_cache_flush();
$after = get_option( $prefix . '_license_state' );
e2e_ok( (int) ( $after['checked_at'] ?? 0 ) > $before && '' === ( $after['last_error'] ?? 'x' ), 'the daily job refreshes the signed cache without errors', $after['last_error'] ?? null );
e2e_ok( is_array( get_option( $prefix . '_update' ) ), 'the daily job also stored an update check result' );

// --- Server outage: cache keeps working within 14 days, not beyond. ---
$offline = $service_of( 'https://outage.invalid' );
$result  = $offline->refresh();
e2e_ok( is_wp_error( $result ) && $offline->has( $true_keys[0] ), 'server unreachable: refresh fails, the cached rights stay valid', $error_of( $result ) );
e2e_ok( false !== strpos( (string) ( $offline->state()['last_error'] ?? '' ), '(' ), 'the outage is recorded as last error for the settings page', $offline->state() );
$later = $service_of( '', static function () {
	return time() + 15 * DAY_IN_SECONDS;
} );
e2e_eq( array( false, 'stale' ), array( $later->has( $true_keys[0] ), $later->cache_problem() ), 'the same cache 15 days later is rejected [time simulated]' );

// --- Tampered cache: valid HMAC (secret is in the options), no valid server signature. ---
$state           = get_option( $prefix . '_license_state' );
if ( ! is_array( $state['envelope'] ?? null ) || empty( $credentials['installation_secret'] ) ) {
	e2e_ok( false, 'cannot continue without an activation and a cached envelope' );
	return;
}
$original_state  = $state;
$payload         = json_decode( (string) $state['envelope']['payload'], true );
$payload['data']['entitlements']['voucher.white_label'] = true;
$state['envelope']['payload']           = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES );
$state['envelope']['payload_signature'] = hash_hmac( 'sha256', $state['envelope']['payload'], (string) $credentials['installation_secret'] );
$state['envelope']['data']              = $payload['data'];
$state['envelope']['signature']         = hash_hmac( 'sha256', (string) wp_json_encode( $payload['data'], JSON_UNESCAPED_SLASHES ), (string) $credentials['installation_secret'] );
update_option( $prefix . '_license_state', $state, false );
$tampered = $service_of();
e2e_eq( array( false, false, 'invalid' ), array( $tampered->has( 'voucher.white_label' ), $tampered->has( $true_keys[0] ), $tampered->cache_problem() ), 'a tampered cache with recomputed HMAC is rejected entirely' );
update_option( $prefix . '_license_state', $original_state, false );
e2e_ok( $service_of()->has( $true_keys[0] ), 'the untouched cache verifies again' );

// --- Suspension at the server switches only premium features off. ---
$wpdb->update( $t['licenses'], array( 'status' => 'suspended' ), array( 'id' => (int) $license['id'] ) );
$suspended = $service_of();
$data      = $suspended->refresh();
e2e_ok( is_array( $data ) && 'suspended' === $data['license_status'] && ! $suspended->has( $true_keys[0] ), 'after a suspension the next refresh switches the features off', $error_of( $data ) );
$wpdb->update( $t['licenses'], array( 'status' => 'active' ), array( 'id' => (int) $license['id'] ) );
$resumed = $service_of();
$resumed->refresh();
e2e_ok( $resumed->has( $true_keys[0] ), 'after reactivation the next refresh switches them on again' );
e2e_note( 'license status was toggled directly in the e2e database' );

// --- Release 1.3.x of the add-on (built with bin/build-release.php) uploaded and published. ---
// Releases are immutable: a re-run on the same database (WPLC_E2E_KEEP_DB=1) takes the next free patch version.
$new_version = '1.3.0';
for ( $patch = 0; $patch < 100 && null !== $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t['releases']} WHERE product_key = 'voucher-captain-pro' AND version = %s", '1.3.' . $patch ) ); $patch++ ) {
	$new_version = '1.3.' . ( $patch + 1 );
}
wp_mkdir_p( $tmp_root );
list( $code, $output ) = $generate( $tmp_root . '/src', $new_version );
exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $tmp_root . '/src/bin/build-release.php' ) . ' --out=' . escapeshellarg( $tmp_root . '/dist' ) . ' 2>&1', $build_output, $build_code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
$zip_path = $tmp_root . '/dist/' . $slug . '-' . $new_version . '.zip';
e2e_ok( 0 === $code && 0 === $build_code && is_file( $zip_path ) && is_file( $zip_path . '.sha256' ), 'bin/build-release.php builds the ' . $new_version . ' ZIP and its .sha256', implode( "\n", $build_output ) );
$zip_bytes = (string) file_get_contents( $zip_path );
e2e_eq( hash( 'sha256', $zip_bytes ), strtok( (string) file_get_contents( $zip_path . '.sha256' ), ' ' ), '.sha256 file matches the ZIP' );
// B-53: flow 05 published voucher-captain-pro packages in the folder "voucher-captain-pro"; this add-on build uses
// its own folder, so the operator moves the product to it first (a later package must use the same folder).
\WPLC\Update\ReleaseRegistry::set_product_slug( 'voucher-captain-pro', $slug );
$releases = e2e_http( 'GET', admin_url( 'admin.php?page=wplc-releases' ), array( 'cookies' => $admin_cookies ) );
$create   = e2e_form( $releases['body'], array( 'value="wplc_release_create"' ) );
$upload   = e2e_multipart(
	array( 'action' => 'wplc_release_create', '_wpnonce' => (string) ( $create['_wpnonce'] ?? '' ), 'product_key' => 'voucher-captain-pro', 'version' => $new_version, 'requires_php' => '7.4', 'requires_wp' => '6.5', 'tested_wp' => '6.8', 'release_notes' => 'Starter update' ),
	'package_zip',
	$slug . '-' . $new_version . '.zip',
	$zip_bytes,
	'application/zip'
);
e2e_http( 'POST', admin_url( 'admin-post.php' ), array( 'cookies' => $admin_cookies, 'headers' => $upload['headers'], 'body' => $upload['body'] ) );
$release  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['releases']} WHERE product_key = 'voucher-captain-pro' AND version = %s", $new_version ), ARRAY_A );
$releases = e2e_http( 'GET', admin_url( 'admin.php?page=wplc-releases' ), array( 'cookies' => $admin_cookies ) );
if ( is_array( $release ) ) {
	e2e_submit( (array) e2e_form( $releases['body'], array( 'value="wplc_release_publish"', 'name="release_id" value="' . (int) $release['id'] . '"' ) ), $admin_cookies, array( 'reason' => 'e2e: starter release' ) ); // B-137: publishing needs a reason.
	$release = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['releases']} WHERE id = %d", (int) $release['id'] ), ARRAY_A );
}
e2e_ok( is_array( $release ) && 'published' === $release['status'] && hash( 'sha256', $zip_bytes ) === $release['sha256'], 'release ' . $new_version . ' is published with the checksum of the built ZIP', $release );
e2e_login( 0 );
if ( ! is_array( $release ) ) {
	return;
}

// --- Update detection without a logged-in user (as from WP-Cron). ---
wp_set_current_user( 0 );
delete_option( $prefix . '_update' );
set_site_transient( 'update_plugins', (object) array( 'last_checked' => time(), 'checked' => array( $basename => '1.0.0' ), 'response' => array(), 'translations' => array() ) );
$transient = get_site_transient( 'update_plugins' );
$offer     = $transient->response[ $basename ] ?? null;
e2e_ok( is_object( $offer ) && $new_version === $offer->new_version && 0 === strpos( (string) $offer->package, 'https://shop.test/?wplc-package=' ), 'without a logged-in user the update_plugins transient offers ' . $new_version . ' with a placeholder package (P-26: periodic check with download false, no one-time URL)', $offer );
$info = apply_filters( 'plugins_api', false, 'plugin_information', (object) array( 'slug' => $slug ) );
e2e_ok( is_object( $info ) && $new_version === $info->version && false !== strpos( (string) $info->sections['changelog'], 'Starter update' ), 'plugins_api answers "View details" with version and release notes' );

// After an upgrade WordPress pings WordPress.org, which the e2e site blocks; those warnings are not ours.
remove_action( 'upgrader_process_complete', 'wp_version_check' );
remove_action( 'upgrader_process_complete', 'wp_update_plugins' );
remove_action( 'upgrader_process_complete', 'wp_update_themes' );

// --- A package that does not match its signed checksum is refused; nothing changes. ---
$stored_path = \WPLC\Update\LocalPackageStorage::path_for_key( (string) $release['package_url'] );
$original    = (string) file_get_contents( (string) $stored_path );
file_put_contents( (string) $stored_path, $original . 'tampered' );
$skin     = new Automatic_Upgrader_Skin();
$upgrader = new Plugin_Upgrader( $skin );
$result   = $upgrader->upgrade( $basename );
$messages = implode( ' | ', array_map( 'wp_strip_all_tags', (array) $skin->get_upgrade_messages() ) );
$header   = get_plugin_data( $addon_dir . '/' . $slug . '.php', false, false );
e2e_ok( true !== $result && '1.0.0' === $header['Version'], 'a package with a wrong checksum is not installed; 1.0.0 stays in place', array( $error_of( $result ), $messages ) );
e2e_ok( false !== strpos( $messages, 'does not match its signed SHA-256' ) || ( is_wp_error( $result ) && 'package_checksum_mismatch' === $result->get_error_code() ), 'the upgrader reports the checksum mismatch', $messages );
file_put_contents( (string) $stored_path, $original );
e2e_ok( is_file( $addon_dir . '/src/Plugin.php' ), 'the installed add-on is intact after the refused update' );

// --- The verified package installs. ---
wp_clean_plugins_cache();
set_site_transient( 'update_plugins', (object) array( 'last_checked' => time(), 'checked' => array( $basename => '1.0.0' ), 'response' => array(), 'translations' => array() ) );
$skin     = new Automatic_Upgrader_Skin();
$upgrader = new Plugin_Upgrader( $skin );
$result   = $upgrader->upgrade( $basename );
wp_clean_plugins_cache();
$header = get_plugin_data( $addon_dir . '/' . $slug . '.php', false, false );
e2e_ok( true === $result && $new_version === $header['Version'], 'the update with a matching checksum installs ' . $new_version, array( $error_of( $result ), implode( ' | ', (array) $skin->get_upgrade_messages() ) ) );
e2e_ok( false !== strpos( (string) file_get_contents( $addon_dir . '/' . $slug . '.php' ), "E2E_VCP_VERSION', '" . $new_version . "'" ), 'the installed main file carries the new version constant' );
$rrmdir( $tmp_root );

// --- Uninstall: activation released at the server, add-on options gone, server data untouched. ---
if ( ! is_plugin_active( $basename ) ) {
	activate_plugin( $basename );
}
deactivate_plugins( $basename, true );
uninstall_plugin( $basename );
wp_cache_flush();
$activation = e2e_rows( 'activations', "installation_uuid = '" . esc_sql( (string) ( $credentials['installation_id'] ?? '' ) ) . "'" );
e2e_ok( 1 === count( $activation ) && 'active' !== $activation[0]['status'], 'uninstall releases the activation at the server', $activation );
e2e_eq( array( false, false, false ), array( get_option( $prefix . '_license' ), get_option( $prefix . '_license_state' ), get_option( $prefix . '_update' ) ), 'uninstall deletes credentials, cache and update data' );
e2e_eq( 'active', (string) e2e_license( (int) $license['id'] )['status'], 'the license itself stays active (only the site slot was freed)' );
$rrmdir( $addon_dir );
wp_clean_plugins_cache();

$new_log_lines = is_file( $debug_log ) ? array_slice( (array) file( $debug_log ), $debug_lines ) : array();
$addon_entries = array_values( preg_grep( '/^\[.*(' . preg_quote( $slug, '/' ) . '|E2E\\\\VoucherPro)/', $new_log_lines ) );
e2e_eq( array(), array_map( static function ( $line ) {
	return substr( (string) $line, 0, 300 );
}, array_slice( $addon_entries, 0, 5 ) ), 'debug.log: no entries from the add-on (CLI and server process)' );
