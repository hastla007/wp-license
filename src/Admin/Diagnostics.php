<?php
/**
 * Read-only support diagnostics for WP License Captain.
 *
 * @package WPLC
 */

namespace WPLC\Admin;

use WPLC\Infrastructure\Database\Schema;
use WPLC\License\LicenseLifecycle;
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
			'overdue_renewals' => self::count_stuck_renewals( $tables['renewals'], time() ),
			'review_required_renewals' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['renewals']} WHERE status = %s", 'review_required' ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names(), values prepared.
			'expired_download_grants' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['download_tokens']} WHERE consumed_at IS NULL AND expires_at <= %s", $now ) ),
		);
	}

	/**
	 * Seconds a queued renewal job may lag before the renewal counts as stuck.
	 *
	 * Matches the daily requeue sweep (RenewalProcessor::requeue_overdue_renewals()),
	 * which only picks up rows whose retry time is more than an hour ago.
	 */
	public const STUCK_RENEWAL_TOLERANCE = 3600;

	/**
	 * Count renewals that are really stuck (metric `overdue_renewals`).
	 *
	 * Same rules as is_stuck_renewal(); keep the two in step.
	 *
	 * @param string $table Renewals table name.
	 * @param int    $now Current Unix time.
	 * @return int
	 */
	private static function count_stuck_renewals( string $table, int $now ): int {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', $now - self::STUCK_RENEWAL_TOLERANCE );
		// Rows without grace_ends_at: the grace period ends GRACE_DAYS after period_start.
		$period_start_cutoff = gmdate( 'Y-m-d H:i:s', $now - self::STUCK_RENEWAL_TOLERANCE - LicenseLifecycle::GRACE_DAYS * 86400 );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE"
				. ' ( status IN (%s,%s,%s) AND retry_at IS NOT NULL AND retry_at <= %s )'
				. ' OR ( status = %s AND updated_at <= %s )'
				. ' OR ( status IN (%s,%s,%s) AND retry_at IS NULL AND ( grace_ends_at <= %s OR ( grace_ends_at IS NULL AND period_start <= %s ) ) )',
				'scheduled',
				'failed',
				'reconciling',
				$cutoff,
				'processing',
				$cutoff,
				'scheduled',
				'failed',
				'manual_action_required',
				$cutoff,
				$period_start_cutoff
			)
		);
	}

	/**
	 * Whether a renewal row is stuck rather than in a known waiting state.
	 *
	 * Stuck means one of:
	 * - `scheduled`, `failed` or `reconciling` with a retry time more than the
	 *   tolerance ago: the queued attempt or hourly check should have run;
	 * - `processing` for longer than the tolerance: the stuck check (after
	 *   20 minutes) should have resolved it;
	 * - `scheduled`, `failed` or `manual_action_required` without a retry time
	 *   whose grace period ended more than the tolerance ago: the grace-end job
	 *   should have expired the license, which closes the row.
	 *
	 * Not stuck: a retry or check in the future or within the tolerance, a
	 * `failed` renewal without retries left that waits for the end of its grace
	 * period, `manual_action_required` before the grace end, `manual`, and
	 * `review_required` (own metric `review_required_renewals`).
	 *
	 * @param array<string,mixed> $renewal Renewal row (status, retry_at, updated_at, grace_ends_at, period_start).
	 * @param int                 $now Current Unix time.
	 * @return bool
	 */
	public static function is_stuck_renewal( array $renewal, int $now ): bool {
		$cutoff = $now - self::STUCK_RENEWAL_TOLERANCE;
		$status = (string) ( $renewal['status'] ?? '' );
		$retry_at = self::row_time( $renewal, 'retry_at' );

		if ( null !== $retry_at && in_array( $status, array( 'scheduled', 'failed', 'reconciling' ), true ) ) {
			return $retry_at <= $cutoff;
		}
		if ( 'processing' === $status ) {
			$updated_at = self::row_time( $renewal, 'updated_at' );
			return null !== $updated_at && $updated_at <= $cutoff;
		}
		if ( null === $retry_at && in_array( $status, array( 'scheduled', 'failed', 'manual_action_required' ), true ) ) {
			$grace_end = self::row_time( $renewal, 'grace_ends_at' );
			if ( null === $grace_end ) {
				$period_start = self::row_time( $renewal, 'period_start' );
				$grace_end = null === $period_start ? null : $period_start + LicenseLifecycle::GRACE_DAYS * 86400;
			}
			return null !== $grace_end && $grace_end <= $cutoff;
		}

		return false;
	}

	/**
	 * Read a UTC datetime column of a row as Unix time.
	 *
	 * @param array<string,mixed> $row Row.
	 * @param string              $key Column.
	 * @return int|null Null when empty or unparseable.
	 */
	private static function row_time( array $row, string $key ): ?int {
		$value = $row[ $key ] ?? null;
		if ( null === $value || '' === $value ) {
			return null;
		}
		$timestamp = strtotime( (string) $value . ' UTC' );

		return false === $timestamp ? null : $timestamp;
	}

	/**
	 * Emit threshold events for external monitoring integrations.
	 *
	 * @return void
	 */
	public static function emit_alerts(): void {
		$metrics = self::metrics();
		foreach ( self::thresholds() as $metric => $threshold ) {
			if ( isset( $metrics[ $metric ] ) && is_numeric( $threshold ) && $metrics[ $metric ] >= (int) $threshold ) {
				do_action( 'wplc/health_alert', sanitize_key( (string) $metric ), $metrics[ $metric ], (int) $threshold );
			}
		}
	}

	/**
	 * Emit one threshold event right away, outside the daily run.
	 *
	 * Used where waiting for the daily check would be too slow, e.g. a renewal
	 * whose payment result stayed unknown. Respects the configured thresholds.
	 *
	 * @param string $metric Metric key.
	 * @param int    $value Current value.
	 * @return void
	 */
	public static function emit_alert( string $metric, int $value ): void {
		$thresholds = self::thresholds();
		if ( isset( $thresholds[ $metric ] ) && is_numeric( $thresholds[ $metric ] ) && $value >= (int) $thresholds[ $metric ] ) {
			do_action( 'wplc/health_alert', sanitize_key( $metric ), $value, (int) $thresholds[ $metric ] );
		}
	}

	/**
	 * Alert thresholds per metric key, after the filter.
	 *
	 * @return array<string,mixed>
	 */
	private static function thresholds(): array {
		$thresholds = apply_filters(
			'wplc_health_alert_thresholds',
			array(
				'pending_webhooks' => 1,
				'manual_review_webhooks' => 1,
				'overdue_renewals' => 1,
				'review_required_renewals' => 1,
			)
		);

		return is_array( $thresholds ) ? $thresholds : array();
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
			__( 'Stuck renewals (job not run, grace period over)', 'wp-license' ) => $metrics['overdue_renewals'],
			__( 'Renewals with unknown payment result needing review', 'wp-license' ) => $metrics['review_required_renewals'],
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
