<?php
/**
 * Plugin Name: WP License Captain
 * Description: License and update management for WP product plugins sold through WooCommerce.
 * Version: 0.2.0
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 8.9
 * Text Domain: wp-license
 * Domain Path: /languages
 *
 * @package WPLC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPLC_VERSION', '0.2.0' );
define( 'WPLC_FILE', __FILE__ );
define( 'WPLC_PATH', plugin_dir_path( __FILE__ ) );

$wplc_autoloader = WPLC_PATH . 'vendor/autoload.php';
if ( is_readable( $wplc_autoloader ) ) {
	require_once $wplc_autoloader;
}

if ( ! class_exists( '\\WPLC\\Plugin' ) ) {
	// Fallback PSR-4 autoloader so the plugin also works from a ZIP built without Composer.
	spl_autoload_register(
		static function ( $class ) {
			if ( 0 !== strpos( $class, 'WPLC\\' ) ) {
				return;
			}
			$relative = str_replace( '\\', '/', substr( $class, 5 ) );
			if ( ! preg_match( '#^[A-Za-z0-9_/]+$#', $relative ) ) {
				return;
			}
			$file = WPLC_PATH . 'src/' . $relative . '.php';
			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}
	);
}

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
			// Orders are only accessed through WooCommerce CRUD APIs.
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WPLC_FILE, true );
			// The renewal opt-in is an additional checkout field in the block checkout (BlockCheckoutConsent).
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', WPLC_FILE, true );
		}
	}
);

register_activation_hook( WPLC_FILE, array( '\\WPLC\\Plugin', 'activate' ) );
register_deactivation_hook( WPLC_FILE, array( '\\WPLC\\Plugin', 'deactivate' ) );

\WPLC\Plugin::boot();
