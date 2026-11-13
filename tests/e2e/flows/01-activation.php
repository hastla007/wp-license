<?php
/**
 * Flow 1: plugin activation, fallback autoloader, schema, scheduled hooks, admin screens.
 *
 * @package WPLC
 */

require getenv( 'WPLC_E2E_DIR' ) . '/lib.php';

global $wpdb;

// --- Activate through the real activation path (register_activation_hook callback included). ---
require_once ABSPATH . 'wp-admin/includes/plugin.php';
if ( ! is_plugin_active( 'wp-license/wp-license.php' ) ) {
	$result = activate_plugin( 'wp-license/wp-license.php' );
	e2e_ok( ! is_wp_error( $result ), 'activate_plugin() succeeds', is_wp_error( $result ) ? $result->get_error_message() : null );
	// activate_plugin() includes the main file after plugins_loaded has fired; boot the plugin as a normal request would.
	if ( ! did_action( 'wplc/loaded' ) ) {
		\WPLC\Plugin::on_plugins_loaded();
	}
} else {
	e2e_ok( true, 'plugin already active (re-run)' );
}

e2e_ok( ! file_exists( WPLC_PATH . 'vendor/autoload.php' ), 'site copy has no vendor/: classes come from the fallback autoloader' );
e2e_ok( class_exists( '\\WPLC\\Billing\\ManualRenewal' ) && class_exists( '\\WPLC\\Update\\ReleaseRegistry' ), 'fallback autoloader resolves WPLC classes' );
$loaded_from = ( new ReflectionClass( '\\WPLC\\Plugin' ) )->getFileName();
e2e_ok( 0 === strpos( $loaded_from, WP_PLUGIN_DIR . '/wp-license/src/' ), 'WPLC\\Plugin loaded from the site copy', $loaded_from );

// --- Tables and schema option. ---
$expected_tables = array( 'products', 'plans', 'licenses', 'activations', 'entitlements', 'renewals', 'download_tokens', 'event_inbox', 'request_nonces', 'request_limits', 'audit_log', 'releases', 'package_streams' );
$tables          = e2e_tables();
e2e_eq( $expected_tables, array_keys( $tables ), 'Schema::table_names() lists the 13 expected tables' );
$missing = array();
foreach ( $tables as $name => $table ) {
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
		$missing[] = $table;
	}
}
e2e_ok( ! $missing, 'all tables exist: ' . implode( ', ', array_values( $tables ) ), $missing );
e2e_eq( \WPLC\Infrastructure\Database\Schema::VERSION, (string) get_option( 'wplc_schema_version' ), 'schema version option equals Schema::VERSION' );
e2e_ok( in_array( $wpdb->get_var( "SELECT autoload FROM {$wpdb->options} WHERE option_name = 'wplc_schema_version'" ), array( 'yes', 'on', 'auto', 'auto-on' ), true ), 'schema version option is autoloaded' );

// dbDelta must be stable: a second migration changes nothing and reports no SQL error.
$wpdb->last_error = '';
e2e_ok( \WPLC\Infrastructure\Database\Schema::migrate(), 'Schema::migrate() is repeatable' );
e2e_eq( '', (string) $wpdb->last_error, 'no SQL error after the second migration' );

// --- Scheduled hooks. ---
e2e_ok( false !== wp_next_scheduled( 'wplc_daily_lifecycle' ), 'WP-Cron event wplc_daily_lifecycle is scheduled' );
$cron_hooks = array();
foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
	foreach ( array_keys( (array) $hooks ) as $hook ) {
		if ( 0 === strpos( $hook, 'wplc' ) ) {
			$cron_hooks[ $hook ] = true;
		}
	}
}
e2e_note( 'WP-Cron hooks: ' . implode( ', ', array_keys( $cron_hooks ) ) );
e2e_ok( isset( $cron_hooks['wplc_cleanup_security_rows'] ), 'WP-Cron event wplc_cleanup_security_rows is scheduled after the first normal request' );
e2e_ok( function_exists( 'as_schedule_single_action' ), 'Action Scheduler is available (renewals use it)' );

// --- Rewrite endpoint for My Account. ---
$rules = (array) get_option( 'rewrite_rules' );
$found = false;
foreach ( array_keys( $rules ) as $rule ) {
	if ( false !== strpos( (string) $rule, 'wplc-licenses' ) ) {
		$found = true;
		break;
	}
}
e2e_ok( $found, 'rewrite rules contain the wplc-licenses endpoint' );

// --- REST routes. ---
$index = e2e_http( 'GET', home_url( '/wp-json/wplc/v1' ) );
e2e_eq( 200, $index['code'], 'GET /wp-json/wplc/v1 over real HTTP answers 200' );
$index_data = json_decode( $index['body'], true );
$routes     = is_array( $index_data ) && isset( $index_data['routes'] ) ? array_keys( $index_data['routes'] ) : array();
foreach ( array( '/wplc/v1/activations', '/wplc/v1/activations/(?P<activation_id>\\d+)', '/wplc/v1/entitlements/check', '/wplc/v1/updates/check', '/wplc/v1/updates/download/(?P<token>[a-f0-9]{64})' ) as $route ) {
	e2e_ok( in_array( $route, $routes, true ), 'REST route registered: ' . $route, $routes );
}

// --- Admin screens over real HTTP as administrator. ---
$admin   = get_user_by( 'login', 'admin' );
$cookies = e2e_login( (int) $admin->ID );
$screens = array(
	'licenses list'  => array( 'admin.php?page=wplc-licenses', 'Add license manually' ),
	'add license'    => array( 'admin.php?page=wplc-licenses&view=add', 'There is no active plan in the catalog yet' ),
	'settings'       => array( 'admin.php?page=wplc-settings', 'wplc_settings' ),
	'releases'       => array( 'admin.php?page=wplc-releases', 'License releases' ),
	'diagnostics'    => array( 'admin.php?page=wplc-diagnostics', 'License diagnostics' ),
	'catalog CSV'    => array( 'admin.php?page=wplc-catalog-csv', 'wplc_export_catalog_csv' ),
	'plugins screen' => array( 'plugins.php', 'WP License Captain' ),
	'dashboard'      => array( 'index.php', 'wpbody-content' ),
);
foreach ( $screens as $name => $screen ) {
	$response = e2e_http( 'GET', admin_url( $screen[0] ), array( 'cookies' => $cookies ) );
	$bad      = preg_match( '/(Fatal error|Warning|Notice|Deprecated)<\/b>:|There has been a critical error/', $response['body'], $match ) ? $match[0] : '';
	e2e_ok(
		200 === $response['code'] && false !== strpos( $response['body'], $screen[1] ) && '' === $bad && false !== strpos( $response['body'], '</html>' ),
		'admin screen renders completely: ' . $name . ' (' . $screen[0] . ')',
		array( 'code' => $response['code'], 'location' => $response['location'], 'marker' => false !== strpos( $response['body'], $screen[1] ), 'error' => $bad, 'tail' => substr( $response['body'], -300 ) )
	);
}
$anonymous = e2e_http( 'GET', admin_url( 'admin.php?page=wplc-licenses' ) );
e2e_eq( 302, $anonymous['code'], 'admin screen redirects an anonymous visitor to the login' );

e2e_set( 'admin_id', (int) $admin->ID );
