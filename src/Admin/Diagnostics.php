<?php
/**
 * Read-only support diagnostics for WP License Captain.
 *
 * @package WPLC
 */

namespace WPLC\Admin;

use WPLC\Infrastructure\Database\Schema;
use WPLC\Update\LocalPackageStorage;
use WPLC\Update\PackageStorageCheck;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Displays environment facts without exposing license or payment secrets. */
final class Diagnostics {
	/** Register the support page. */
	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ) );
		add_action( 'wplc_daily_lifecycle', array( self::class, 'emit_alerts' ) );
		add_action( 'admin_post_wplc_storage_recheck', array( self::class, 'handle_storage_recheck' ) );
	}

	/** Forget the cached package storage probe and return to the page. */
	public static function handle_storage_recheck(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to view diagnostics.', 'wp-license' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'wplc_storage_recheck' );
		PackageStorageCheck::forget();
		wp_safe_redirect( admin_url( 'admin.php?page=wplc-diagnostics' ) );
		exit;
	}

	/**
	 * Render the package storage section: location, source and HTTP self-check.
	 *
	 * @return void
	 */
	private static function render_storage(): void {
		$status = PackageStorageCheck::status();
		$sources = array(
			'constant' => __( 'WPLC_PACKAGE_STORAGE_DIR constant', 'wp-license' ),
			'filter' => __( 'wplc_package_storage_dir filter', 'wp-license' ),
			'default' => __( 'Default (uploads directory)', 'wp-license' ),
		);
		$rows = array(
			__( 'Directory', 'wp-license' ) => $status['dir'],
			__( 'Configured by', 'wp-license' ) => $sources[ $status['source'] ] ?? $status['source'],
			__( 'Writable', 'wp-license' ) => $status['writable'] ? __( 'Yes', 'wp-license' ) : __( 'No', 'wp-license' ),
			__( 'Public access', 'wp-license' ) => PackageStorageCheck::result_label( $status['probe'] ),
		);
		if ( $status['checked_at'] > 0 ) {
			$rows[ __( 'Last checked (UTC)', 'wp-license' ) ] = gmdate( 'Y-m-d H:i:s', $status['checked_at'] );
		}

		echo '<h2>' . esc_html__( 'Package storage', 'wp-license' ) . '</h2>';
		if ( '' !== $status['constant_error'] ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( LocalPackageStorage::dir_error_message( $status['constant_error'] ) ) . ' ' . esc_html__( 'The constant is ignored and the default directory is used.', 'wp-license' ) . '</p></div>';
		}
		echo '<table class="widefat striped"><tbody>';
		foreach ( $rows as $label => $value ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( (string) $value ) . '</td></tr>';
		}
		echo '</tbody></table>';
		if ( $status['web_reachable'] ) {
			$rule = null !== $status['url'] ? PackageStorageCheck::nginx_rule( $status['url'] ) : '';
			echo '<p>' . esc_html__( 'The directory lies inside the web root. Apache and IIS honour the generated .htaccess and web.config; nginx does not. Recommended: set WPLC_PACKAGE_STORAGE_DIR in wp-config.php to a directory outside the web root, or add this rule to the nginx server block:', 'wp-license' ) . '</p>';
			if ( '' !== $rule ) {
				echo '<pre><code>' . esc_html( $rule ) . '</code></pre>';
			}
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="wplc_storage_recheck">';
			wp_nonce_field( 'wplc_storage_recheck' );
			echo '<button type="submit" class="button button-secondary">' . esc_html__( 'Check again', 'wp-license' ) . '</button></form>';
		}
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
			__( 'Response signing key (Ed25519, public)', 'wp-license' ) => \WPLC\Security\ResponseSigner::public_key() ?: __( 'Unavailable', 'wp-license' ),
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
		echo '</tbody></table>';
		self::render_storage();
		echo '</div>';
	}
}
