<?php
/**
 * Read-only support diagnostics for WP License Captain.
 *
 * @package WPLC
 */

namespace WPLC\Admin;

use WPLC\Infrastructure\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Displays environment facts without exposing license or payment secrets. */
final class Diagnostics {
	/** Register the support page. */
	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ) );
		add_action( 'wplc_daily_lifecycle', array( self::class, 'emit_alerts' ) );
	}

	/**
	 * Return aggregate queue metrics without exposing customer or payment data.
	 *
	 * @return array<string,int>
	 */
	public static function metrics(): array {
		global $wpdb;
		$tables = Schema::table_names();
		$now = gmdate( 'Y-m-d H:i:s' );
		return array(
			'pending_webhooks' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['event_inbox']} WHERE source = %s AND outcome = %s", 'stripe', 'pending_reconciliation' ) ),
			'manual_review_webhooks' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['event_inbox']} WHERE source = %s AND outcome = %s AND processed_at >= %s", 'stripe', 'manual_review', gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) ),
			'overdue_renewals' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['renewals']} WHERE status IN (%s,%s) AND (retry_at <= %s OR (retry_at IS NULL AND period_start <= %s))", 'scheduled', 'failed', $now, $now ) ),
			'expired_download_grants' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['download_tokens']} WHERE consumed_at IS NULL AND expires_at <= %s", $now ) ),
		);
	}

	/**
	 * Emit threshold events for external monitoring integrations.
	 *
	 * @return void
	 */
	public static function emit_alerts(): void {
		$metrics = self::metrics();
		$thresholds = apply_filters(
			'wplc_health_alert_thresholds',
			array(
				'pending_webhooks' => 1,
				'manual_review_webhooks' => 1,
				'overdue_renewals' => 1,
			)
		);
		if ( ! is_array( $thresholds ) ) {
			return;
		}
		foreach ( $thresholds as $metric => $threshold ) {
			if ( isset( $metrics[ $metric ] ) && is_numeric( $threshold ) && $metrics[ $metric ] >= (int) $threshold ) {
				do_action( 'wplc/health_alert', sanitize_key( (string) $metric ), $metrics[ $metric ], (int) $threshold );
			}
		}
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
		$metrics = self::metrics();
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
			__( 'Pending Stripe events', 'wp-license' ) => $metrics['pending_webhooks'],
			__( 'Stripe events needing review', 'wp-license' ) => $metrics['manual_review_webhooks'],
			__( 'Overdue renewal attempts', 'wp-license' ) => $metrics['overdue_renewals'],
			__( 'Unused expired download grants', 'wp-license' ) => $metrics['expired_download_grants'],
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
