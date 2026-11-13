<?php
/**
 * Uninstall handler.
 *
 * License, activation, renewal and audit data are business records and are
 * deliberately kept when the plugin is deleted. Only scheduled work is removed.
 * Drop the {prefix}wplc_* tables manually if the data is really no longer needed.
 *
 * @package WPLC
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

wp_clear_scheduled_hook( 'wplc_daily_lifecycle' );
wp_clear_scheduled_hook( 'wplc_cleanup_security_rows' );
wp_clear_scheduled_hook( 'wplc_send_reminders' );

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'wplc' );
}
