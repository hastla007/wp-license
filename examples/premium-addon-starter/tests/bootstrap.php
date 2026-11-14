<?php
/**
 * PHPUnit bootstrap: WordPress stubs, add-on autoloader, test support classes.
 *
 * Runs standalone in the add-on repository. Inside the WP License Captain repository (or with WPLC_SERVER_DIR set)
 * the server code is found too, and the sync tests compare the vendored client and the fixtures with it.
 *
 * @package Vendor\Product\Premium
 */

define( 'ABSPATH', sys_get_temp_dir() . '/starter-abspath/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

$premium_addon_starter_root = dirname( __DIR__ );
$premium_addon_starter_vendor = $premium_addon_starter_root . '/vendor/autoload.php';
if ( is_file( $premium_addon_starter_vendor ) ) {
	require $premium_addon_starter_vendor;
}

require __DIR__ . '/Support/wordpress-stubs.php';
require $premium_addon_starter_root . '/src/autoload.php';
require __DIR__ . '/Support/Fixtures.php';
require __DIR__ . '/Support/FakeServer.php';

/** License server repository root, or '' when it is not available. */
function premium_addon_starter_server_dir(): string {
	$dir = getenv( 'WPLC_SERVER_DIR' ) ? rtrim( (string) getenv( 'WPLC_SERVER_DIR' ), '/' ) : dirname( __DIR__, 3 );
	return is_file( $dir . '/src/Client/LicenseClient.php' ) && is_file( $dir . '/src/Api/V1/Response.php' ) ? $dir : '';
}
