<?php
/**
 * Flow 10: deactivation and uninstall clear scheduled work and keep all data;
 * a renewal job that came due while the plugin was inactive is picked up afterwards.
 *
 * @package WPLC
 */

require getenv( 'WPLC_E2E_DIR' ) . '/lib.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

global $wpdb;
$t         = e2e_tables();
$simple_id = (int) e2e_get( 'simple_id' );
$plugin    = 'wp-license/wp-license.php';
$utc       = static function ( int $timestamp ) {
	return gmdate( 'Y-m-d H:i:s', $timestamp );
};
$counts = static function () use ( $wpdb, $t ) {
	$result = array();
	foreach ( array( 'licenses', 'activations', 'entitlements', 'renewals', 'plans', 'audit_log', 'releases' ) as $table ) {
		$result[ $table ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t[ $table ]}" ); // phpcs:ignore WordPress.DB.PreparedSQL
	}
	return $result;
};
$wplc_cron = static function () {
	$hooks = array();
	foreach ( (array) _get_cron_array() as $events ) {
		foreach ( array_keys( (array) $events ) as $hook ) {
			if ( 0 === strpos( (string) $hook, 'wplc' ) ) {
				$hooks[ $hook ] = true;
			}
		}
	}
	return array_keys( $hooks );
};
$pending_wplc = static function () {
	return count(
		as_get_scheduled_actions(
			array(
				'group'    => 'wplc',
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 500,
			),
			'ids'
		)
	);
};

// A renewing license whose renewal is due while the plugin is switched off.
update_option( 'wplc_e2e_fake_mode', 'success' );
$kate_id    = e2e_customer( 'kate' );
$license    = e2e_order_licenses( (int) e2e_checkout( $kate_id, array( $simple_id => 1 ), 'wplc_e2e_fake', true )->get_id() )[0];
$license_id = (int) $license['id'];
$due        = $utc( time() - 2 * HOUR_IN_SECONDS );
$renewal_id = e2e_move_paid_until( $license_id, $due );
e2e_eq( 1, count( e2e_pending_actions( 'wplc_process_renewal', array( $renewal_id ) ) ), '[time simulated] a renewal is due and its job is pending' );

$before = $counts();
e2e_ok( $pending_wplc() > 0 && count( $wplc_cron() ) >= 1, 'before deactivation: scheduled work exists (cron: ' . implode( ', ', $wplc_cron() ) . '; ' . $pending_wplc() . ' pending actions)' );

// --- Deactivate. ---
deactivate_plugins( $plugin );
wp_cache_flush();
e2e_ok( ! is_plugin_active( $plugin ), 'plugin is deactivated' );
e2e_eq( array(), $wplc_cron(), 'deactivation clears every WPLC WP-Cron event' );
e2e_eq( $before, $counts(), 'deactivation keeps all rows (licenses, activations, entitlements, renewals, plans, audit log, releases)' );
e2e_ok( false !== get_option( 'wplc_schema_version', false ) && false !== get_option( 'wplc_settings', false ), 'deactivation keeps the options' );
$rest = e2e_http( 'GET', home_url( '/wp-json/wplc/v1' ) );
e2e_eq( 404, $rest['code'], 'with the plugin inactive the REST namespace is gone (404), nothing fatals' );
$shop_cookies = e2e_login( $kate_id );
$account      = e2e_http( 'GET', wc_get_page_permalink( 'myaccount' ), array( 'cookies' => $shop_cookies ) );
e2e_ok( 200 === $account['code'] && false === strpos( $account['body'], 'critical error' ), 'the shop account page still works without the plugin', $account['code'] );
$admin_cookies = e2e_login( (int) e2e_get( 'admin_id' ) );
$plugins       = e2e_http( 'GET', admin_url( 'plugins.php' ), array( 'cookies' => $admin_cookies ) );
e2e_ok( 200 === $plugins['code'] && false !== strpos( $plugins['body'], 'WP License Captain' ), 'the plugins screen lists the inactive plugin', $plugins['code'] );
e2e_login( 0 );

// The Action Scheduler queue keeps running while the plugin is off: the job has no handler and is used up.
// (This process still has the plugin's classes loaded, so the handler is unhooked to emulate the inactive state.)
remove_all_actions( 'wplc_process_renewal' );
update_option( 'wplc_e2e_fake_charges', array(), false );
e2e_ok( e2e_run_action( 'wplc_process_renewal', array( $renewal_id ) ), 'the queue runs the due renewal job while the plugin is inactive' );
e2e_eq( array( 0, 'scheduled', 0 ), array( count( e2e_charges() ), e2e_renewal( $renewal_id )['status'], count( e2e_pending_actions( 'wplc_process_renewal', array( $renewal_id ) ) ) ), 'nothing was charged, the renewal is still "scheduled", and its job is gone' );

// --- Activate again. ---
$result = activate_plugin( $plugin );
e2e_ok( ! is_wp_error( $result ) && is_plugin_active( $plugin ), 'plugin is activated again', is_wp_error( $result ) ? $result->get_error_message() : null );
add_action( 'wplc_process_renewal', array( '\\WPLC\\Billing\\RenewalProcessor', 'process' ) );
e2e_ok( in_array( 'wplc_daily_lifecycle', $wplc_cron(), true ), 'activation schedules the daily job again' );
e2e_eq( $before, $counts(), 'all rows are still there after re-activation' );
do_action( 'wplc_daily_lifecycle' );
e2e_eq( 1, count( e2e_pending_actions( 'wplc_process_renewal', array( $renewal_id ) ) ), 'the daily job queues the overdue renewal again' );
e2e_run_action( 'wplc_process_renewal', array( $renewal_id ) );
$after = e2e_license( $license_id );
e2e_eq( array( 'paid', 'active', 1 ), array( e2e_renewal( $renewal_id )['status'], $after['status'], count( e2e_charges() ) ), 'the overdue renewal is charged once and the license renewed' );
e2e_ok( strtotime( $after['paid_until'] . ' UTC' ) > time() + 300 * DAY_IN_SECONDS, 'paid_until moved on by a year', $after['paid_until'] );
do_action( 'wplc_daily_lifecycle' );
e2e_eq( 1, count( e2e_charges() ), 'another daily run does not charge again' );

// --- Uninstall (the script WordPress runs before deleting the files). ---
$before = $counts();
deactivate_plugins( $plugin );
e2e_ok( $pending_wplc() > 0, 'pending Action Scheduler jobs exist before uninstall (' . $pending_wplc() . ')' );
uninstall_plugin( $plugin );
wp_cache_flush();
e2e_eq( 0, $pending_wplc(), 'uninstall removes all pending Action Scheduler jobs of the plugin' );
e2e_eq( array(), $wplc_cron(), 'uninstall leaves no WPLC WP-Cron event' );
e2e_eq( $before, $counts(), 'uninstall keeps all license data (documented behaviour: data is never deleted automatically)' );
$missing = array();
foreach ( $t as $table ) {
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
		$missing[] = $table;
	}
}
e2e_eq( array(), $missing, 'uninstall keeps all tables' );
e2e_ok( false !== get_option( 'wplc_schema_version', false ), 'uninstall keeps the schema version option' );

// Leave the site usable for a re-run: activate again; renewals of running licenses are queued again by the daily job.
$result = activate_plugin( $plugin );
e2e_ok( ! is_wp_error( $result ), 'plugin can be activated again after the uninstall script ran' );
do_action( 'wplc_daily_lifecycle' );
e2e_note( 'After uninstall + re-activation, renewals that are not yet due have no queued job until they are overdue; the daily job then queues them (see assertion above).' );
