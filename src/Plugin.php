<?php
/**
 * Main plugin loader.
 *
 * @package WPLC
 */

namespace WPLC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Boots the plugin after WordPress has loaded its active plugins.
 */
final class Plugin {

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

		/**
		 * Fires after WP License Captain has confirmed its WooCommerce dependency.
		 *
		 * @since 0.1.0
		 */
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
