<?php
/**
 * Must-use plugin for load tests (T-89): logs every database query of a request.
 *
 * Copy into wp-content/mu-plugins of a TEST site and start the web server with
 * the environment variable WPLC_LOAD_QUERY_LOG=/path/to/file.jsonl. Each request
 * appends one JSON line: method, URI, wall time, and every query with its time
 * (seconds) and caller. Without the variable the plugin does nothing.
 *
 * @package WPLC
 */

// phpcs:disable -- Test tooling, not shipped.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( getenv( 'WPLC_LOAD_QUERY_LOG' ) ) {
	if ( ! defined( 'SAVEQUERIES' ) ) {
		define( 'SAVEQUERIES', true );
	}
	register_shutdown_function(
		static function () {
			global $wpdb;
			$queries = array();
			foreach ( is_array( $wpdb->queries ) ? $wpdb->queries : array() as $query ) {
				$queries[] = array( 'sql' => (string) $query[0], 'time' => (float) $query[1], 'caller' => (string) $query[2] );
			}
			$line = wp_json_encode(
				array(
					'method'  => isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : '',
					'uri'     => isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '',
					'wall'    => microtime( true ) - ( isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime( true ) ),
					'queries' => $queries,
				)
			);
			file_put_contents( (string) getenv( 'WPLC_LOAD_QUERY_LOG' ), $line . "\n", FILE_APPEND | LOCK_EX );
		}
	);
}
