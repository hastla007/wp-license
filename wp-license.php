<?php
/**
 * Plugin Name: WP License Captain
 * Description: License and update management for WP product plugins sold through WooCommerce.
 * Version: 0.1.0
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Text Domain: wp-license
 * Domain Path: /languages
 *
 * @package WPLC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPLC_VERSION', '0.1.0' );
define( 'WPLC_FILE', __FILE__ );
define( 'WPLC_PATH', plugin_dir_path( __FILE__ ) );

$wplc_autoloader = WPLC_PATH . 'vendor/autoload.php';
if ( is_readable( $wplc_autoloader ) ) {
	require_once $wplc_autoloader;
}

if ( ! class_exists( '\\WPLC\\Plugin' ) ) {
	require_once WPLC_PATH . 'src/Infrastructure/Database/Schema.php';
require_once WPLC_PATH . 'src/Catalog/Catalog.php';
require_once WPLC_PATH . 'src/Catalog/ProductEditor.php';
require_once WPLC_PATH . 'src/License/LicenseKey.php';
require_once WPLC_PATH . 'src/License/LicenseIssuer.php';
require_once WPLC_PATH . 'src/Plugin.php';
}

register_activation_hook( WPLC_FILE, array( '\\WPLC\\Plugin', 'activate' ) );
register_deactivation_hook( WPLC_FILE, array( '\\WPLC\\Plugin', 'deactivate' ) );

\\WPLC\\Plugin::boot();
