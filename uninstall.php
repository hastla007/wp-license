<?php
/**
 * Uninstall handler.
 *
 * License, activation, renewal and audit data are business records and are
 * deliberately kept when the plugin is deleted. Only scheduled work is removed,
 * on a network from every site.
 * Drop the {prefix}wplc_* tables manually if the data is really no longer needed.
 *
 * @package WPLC
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove the scheduled work and leftover options of the current site.
 *
 * @return void
 */
function wplc_uninstall_site() {
	global $wpdb;

	wp_clear_scheduled_hook( 'wplc_daily_lifecycle' );
	wp_clear_scheduled_hook( 'wplc_cleanup_security_rows' );
	wp_clear_scheduled_hook( 'wplc_send_reminders' );

	// On a network, sites without WooCommerce have no Action Scheduler tables.
	$actions_table = isset( $wpdb->actionscheduler_actions ) ? (string) $wpdb->actionscheduler_actions : '';
	if ( function_exists( 'as_unschedule_all_actions' ) && '' !== $actions_table
		&& $actions_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $actions_table ) ) ) ) {
		as_unschedule_all_actions( '', array(), 'wplc' );
	}

	// Left behind when the plugin is removed before schema 12 adopted {prefix}wplc_reminder_log.
	// The table itself is kept like all other {prefix}wplc_* tables.
	delete_option( 'wplc_reminder_log_version' );
}

if ( is_multisite() ) {
	// Cron events, Action Scheduler actions and options are stored per site.
	$wplc_site_ids = get_sites(
		array(
			'fields'     => 'ids',
			'number'     => 0,
			'network_id' => get_current_network_id(),
		)
	);
	foreach ( (array) $wplc_site_ids as $wplc_site_id ) {
		switch_to_blog( (int) $wplc_site_id );
		wplc_uninstall_site();
		restore_current_blog();
	}
} else {
	wplc_uninstall_site();
}
