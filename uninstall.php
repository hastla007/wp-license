<?php
/**
 * Uninstall handler.
 *
 * License, activation, renewal and audit data are business records and are
 * deliberately kept when the plugin is deleted. Removed are scheduled work and
 * the plugin's working state (cursors, heartbeats, gap flags).
 *
 * What stays after "Delete" in the plugin list, on every site of a network:
 * - the {prefix}wplc_* tables,
 * - the options wplc_schema_version, wplc_reminder_log_version, wplc_settings,
 *   wplc_site_fingerprint, wplc_rewrite_version and wplc_response_signing_key
 *   (the signing key the delivered add-ons verify responses with),
 * - uploaded packages in wp-content/uploads/wplc-packages/ (or the directory
 *   set with the wplc_package_storage_dir filter),
 * - order and order item meta (_wplc_*) and the refund markers.
 *
 * To remove the tables and options as well, define this in wp-config.php
 * before deleting the plugin:
 *
 *     define( 'WPLC_REMOVE_ALL_DATA', true );
 *
 * Package files and order meta are never removed automatically.
 *
 * @package WPLC
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! function_exists( 'wplc_uninstall_site' ) ) {
	/**
	 * Clean up the current site.
	 *
	 * @param bool $remove_data Whether tables and all options are removed as well.
	 * @return void
	 */
	function wplc_uninstall_site( $remove_data ) {
		global $wpdb;

		foreach ( array( 'wplc_daily_lifecycle', 'wplc_cleanup_security_rows', 'wplc_send_reminders' ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}

		// Action Scheduler refuses to work before its data store is initialized (it is on a normal uninstall request).
		if ( function_exists( 'as_unschedule_all_actions' ) && ( ! is_callable( array( 'ActionScheduler', 'is_initialized' ) ) || ActionScheduler::is_initialized() ) ) {
			as_unschedule_all_actions( '', array(), 'wplc' );
		}

		$working_state = array(
			'wplc_reminder_cursor',
			'wplc_deactivated_at',
			'wplc_last_daily_run',
			'wplc_job_failures',
			'wplc_job_heartbeats',
			'wplc_gap_window',
			'wplc_gap_reconciled_orders',
			'wplc_billing_paused_since',
			'wplc_site_guard_keep_paused',
			'wplc_schema_last_error',
			'wplc_schema_error',
			'wplc_last_run',
			'wplc_schema_verified',
		);
		foreach ( $working_state as $option ) {
			delete_option( $option );
		}
		// Cursors of the former privacy eraser (one option per user).
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'wplc_privacy_eraser_cursor_' ) . '%' ) );

		if ( ! $remove_data ) {
			return;
		}

		$tables = array( 'products', 'plans', 'licenses', 'activations', 'entitlements', 'renewals', 'download_tokens', 'event_inbox', 'request_nonces', 'request_limits', 'audit_log', 'releases', 'package_streams', 'reminder_log' );
		foreach ( $tables as $table ) {
			$name = $wpdb->prefix . 'wplc_' . $table;
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- fixed table names, explicit opt-in.
			$wpdb->query( "DROP TABLE IF EXISTS `{$name}`" );
		}
		// Also the version options: a later reinstall must create the tables again.
		foreach ( array( 'wplc_schema_version', 'wplc_reminder_log_version', 'wplc_settings', 'wplc_site_fingerprint', 'wplc_rewrite_version', 'wplc_response_signing_key' ) as $option ) {
			delete_option( $option );
		}
	}
}

$wplc_remove_data = defined( 'WPLC_REMOVE_ALL_DATA' ) && true === WPLC_REMOVE_ALL_DATA;

if ( is_multisite() && function_exists( 'get_sites' ) ) {
	// Scheduled events and options are per site; without the loop the sub-sites keep theirs.
	$wplc_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 500,
		)
	);
	foreach ( (array) $wplc_site_ids as $wplc_site_id ) {
		switch_to_blog( (int) $wplc_site_id );
		wplc_uninstall_site( $wplc_remove_data );
		restore_current_blog();
	}
} else {
	wplc_uninstall_site( $wplc_remove_data );
}
