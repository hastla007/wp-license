<?php
/**
 * Plugin Name: WP License Captain
 * Description: License and update management for WP product plugins sold through WooCommerce.
 * Version: 0.2.0
 * Requires at least: 7.0
 * Requires PHP: 8.5
 * Requires Plugins: woocommerce
 * WC requires at least: 11.2
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI: https://github.com/hastla007/wp-license
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
// Version of the hook contract for other plugins in the shop (docs/LICENSE-EVENTS.md). Semantic versioning, independent of WPLC_VERSION.
define( 'WPLC_CONTRACT_VERSION', '1.0.0' );

// MINVER-1: below the PHP minimum (Requirements::MIN_PHP, `Requires PHP` above) the plugin shows a
// notice and loads nothing else. Written for any PHP version, before any class of src/ is loaded.
// WPLC_TESTS_IGNORE_PHP_MINIMUM is TEST-ONLY: the test bootstraps define it so the suites also run
// on the older PHP of a development machine; never define it on a real site.
if ( version_compare( PHP_VERSION, '8.5', '<' ) && ! ( defined( 'WPLC_TESTS_IGNORE_PHP_MINIMUM' ) && true === WPLC_TESTS_IGNORE_PHP_MINIMUM ) ) {
	$wplc_php_notice = static function () {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		load_plugin_textdomain( 'wp-license', false, dirname( plugin_basename( WPLC_FILE ) ) . '/languages' );
		echo '<div class="notice notice-error"><p>' . esc_html(
			sprintf(
				/* translators: 1: minimum PHP version, 2: running PHP version. */
				__( 'WP License Captain requires PHP %1$s or later; this site runs PHP %2$s. The plugin is not loaded until PHP is updated.', 'wp-license' ),
				'8.5',
				PHP_VERSION
			)
		) . '</p></div>';
	};
	add_action( 'admin_notices', $wplc_php_notice );
	add_action( 'network_admin_notices', $wplc_php_notice );
	unset( $wplc_php_notice );
	return;
}

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

if ( function_exists( 'is_multisite' ) && is_multisite() ) {
	// As early as possible and independent of WooCommerce and plugins_loaded: a site deleted in
	// this request takes its wplc_* tables with it (P-32). See docs/MULTISITE.md for the case in
	// which the plugin is not loaded in the deleting request at all.
	add_filter( 'wpmu_drop_tables', array( 'WPLC\\Infrastructure\\Database\\Schema', 'filter_wpmu_drop_tables' ), 10, 2 );
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
