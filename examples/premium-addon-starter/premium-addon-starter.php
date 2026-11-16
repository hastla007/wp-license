<?php
/**
 * Plugin Name:       Premium Add-on Starter
 * Plugin URI:        https://shop.example/
 * Description:       Premium features for Core Plugin. Needs the free Core Plugin; licensed and updated through the shop.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Tested up to:      6.8
 * Requires PHP:      8.5
 * Requires Plugins:  core-plugin-slug
 * Author:            Vendor
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       premium-addon-starter
 * Domain Path:       /languages
 * Update URI:        https://shop.example/premium-addon-starter
 *
 * @package Vendor\Product\Premium
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PREMIUM_ADDON_STARTER_VERSION', '1.0.0' );

// Keep this file PHP 5.6 parseable so an old PHP gets a notice instead of a fatal error.
if ( version_compare( PHP_VERSION, '8.5', '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Premium Add-on Starter needs a newer PHP version and is not running.', 'premium-addon-starter' ) . '</p></div>';
		}
	);
	return;
}

require_once __DIR__ . '/src/autoload.php';

\Vendor\Product\Premium\Plugin::boot( __FILE__, PREMIUM_ADDON_STARTER_VERSION );
