<?php
/**
 * Read-only support diagnostics for WP License Captain.
 *
 * @package WPLC
 */

namespace WPLC\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Displays environment facts without exposing license or payment secrets. */
final class Diagnostics {
	/** Register the support page. */
	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ) );
	}

	/** Add a WooCommerce submenu for authorized shop managers. */
	public static function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'License diagnostics', 'wp-license' ),
			__( 'License diagnostics', 'wp-license' ),
			'manage_woocommerce',
			'wplc-diagnostics',
			array( self::class, 'render' )
		);
	}

	/** Render environment checks; never show keys, tokens, or payment data. */
	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to view diagnostics.', 'wp-license' ), '', array( 'response' => 403 ) );
		}

		global $wpdb;
		$rows = array(
			__( 'PHP', 'wp-license' ) => PHP_VERSION,
			__( 'WordPress', 'wp-license' ) => get_bloginfo( 'version' ),
			__( 'WooCommerce', 'wp-license' ) => defined( 'WC_VERSION' ) ? WC_VERSION : __( 'Unavailable', 'wp-license' ),
			__( 'License schema', 'wp-license' ) => (string) get_option( 'wplc_schema_version', __( 'Unknown', 'wp-license' ) ),
			__( 'Database', 'wp-license' ) => method_exists( $wpdb, 'db_version' ) ? $wpdb->db_version() : __( 'Unknown', 'wp-license' ),
			__( 'HTTPS', 'wp-license' ) => is_ssl() ? __( 'Enabled', 'wp-license' ) : __( 'Not detected', 'wp-license' ),
			__( 'OpenSSL', 'wp-license' ) => extension_loaded( 'openssl' ) ? __( 'Available', 'wp-license' ) : __( 'Unavailable', 'wp-license' ),
			__( 'Action Scheduler', 'wp-license' ) => function_exists( 'as_enqueue_async_action' ) ? __( 'Available', 'wp-license' ) : __( 'Unavailable', 'wp-license' ),
			__( 'Stripe gateway class', 'wp-license' ) => class_exists( '\\WC_Stripe' ) ? __( 'Loaded', 'wp-license' ) : __( 'Not loaded', 'wp-license' ),
			__( 'Update package provider', 'wp-license' ) => has_filter( 'wplc_update_packages' ) ? __( 'Registered', 'wp-license' ) : __( 'Not registered', 'wp-license' ),
		);

		echo '<div class="wrap"><h1>' . esc_html__( 'WP License Captain diagnostics', 'wp-license' ) . '</h1>';
		echo '<p>' . esc_html__( 'Read-only environment details for support. No license key, installation secret, token, or payment credential is displayed.', 'wp-license' ) . '</p>';
		echo '<table class="widefat striped"><tbody>';
		foreach ( $rows as $label => $value ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( (string) $value ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}
}
