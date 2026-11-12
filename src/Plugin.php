<?php
/**
 * Main plugin loader.
 *
 * @package WPLC
 */

namespace WPLC;

use WPLC\Infrastructure\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Boots the plugin after WordPress has loaded its active plugins.
 */
final class Plugin {

	/**
	 * Validate activation requirements before enabling the plugin.
	 *
	 * @return void
	 */
	public static function activate(): void {
		if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
			wp_die( esc_html__( 'WP License Captain requires PHP 7.4 or later.', 'wp-license' ) );
		}

		if ( ! class_exists( '\\WooCommerce' ) ) {
			wp_die( esc_html__( 'Please activate WooCommerce before activating WP License Captain.', 'wp-license' ) );
		}

		Schema::migrate();
	}

	/**
	 * Deactivation preserves all persistent license and order data.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		// Persistent data is never removed on deactivation.
	}

	/**
	 * Boot the plugin once per request.
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_action( 'plugins_loaded', array( self::class, 'on_plugins_loaded' ), 20 );
	}

	/**
	 * Initialize only when WooCommerce is available.
	 *
	 * @return void
	 */
	public static function on_plugins_loaded(): void {
		load_plugin_textdomain(
			'wp-license',
			false,
			dirname( plugin_basename( WPLC_FILE ) ) . '/languages'
		);

		if ( ! class_exists( '\\WooCommerce' ) ) {
			add_action( 'admin_notices', array( self::class, 'render_woocommerce_notice' ) );
			return;
		}

		Schema::maybe_upgrade();
		do_action( 'wplc/loaded' );
	}

	/**
	 * Explain the missing dependency to administrators.
	 *
	 * @return void
	 */
	public static function render_woocommerce_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>';
		echo esc_html__(
			'WP License Captain requires WooCommerce to be installed and active.',
			'wp-license'
		);
		echo '</p></div>';
	}
}
