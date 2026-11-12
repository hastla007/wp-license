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

require_once WPLC_PATH . 'src/Plugin.php';

\WPLC\Plugin::boot();
