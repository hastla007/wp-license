<?php
/**
 * Plugin Name: WP License Captain site cleanup
 * Description: Multisite only. Drops the wplc_* tables of a deleted site, also when WP License Captain is not loaded in the request that deletes the site. Optional; install with `wp wplc schema install-mu-helper`.
 * Version: 1.0.0
 * Network: true
 * License: GPL-2.0-or-later
 * WPLC MU Helper: site-cleanup
 *
 * Copy this file into wp-content/mu-plugins/. It does not depend on the plugin and keeps
 * working when the plugin is deactivated or deleted. See docs/MULTISITE.md (P-32).
 *
 * @package WPLC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wplc_mu_site_cleanup_tables' ) ) {
	/**
	 * Select the tables of one site that belong to WP License Captain.
	 *
	 * Pure helper: only names that start with exactly the site's prefix followed by
	 * "wplc_" ("wp_2_wplc_licenses" for "wp_2_", never "wp_22_wplc_licenses"). Nothing
	 * is returned for the main site's prefix (the base prefix of the network).
	 *
	 * @param array<int,mixed> $found       Table names found in the database.
	 * @param string           $prefix      Table prefix of the deleted site, e.g. "wp_2_".
	 * @param string           $base_prefix Base prefix of the network, e.g. "wp_".
	 * @return string[]
	 */
	function wplc_mu_site_cleanup_tables( array $found, string $prefix, string $base_prefix ): array {
		if ( '' === $prefix || $prefix === $base_prefix ) {
			return array();
		}
		$pattern = '/^' . preg_quote( $prefix . 'wplc_', '/' ) . '[a-z0-9_]+$/i';
		$tables  = array();
		foreach ( $found as $table ) {
			if ( is_string( $table ) && 1 === preg_match( $pattern, $table ) ) {
				$tables[] = $table;
			}
		}

		return array_values( array_unique( $tables ) );
	}
}

if ( ! function_exists( 'wplc_mu_site_cleanup_filter' ) ) {
	/**
	 * `wpmu_drop_tables`: add the wplc_* tables of the deleted site.
	 *
	 * @param mixed $tables  Table names collected by WordPress.
	 * @param mixed $site_id ID of the site that is being deleted.
	 * @return mixed
	 */
	function wplc_mu_site_cleanup_filter( $tables, $site_id = 0 ) {
		global $wpdb;

		$site_id = (int) $site_id;
		if ( ! is_array( $tables ) || $site_id <= 0 || ! is_object( $wpdb ) || ! is_multisite() || is_main_site( $site_id ) ) {
			return $tables;
		}
		$prefix = (string) $wpdb->get_blog_prefix( $site_id );
		$found  = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix . 'wplc_' ) . '%' ) );
		$own    = wplc_mu_site_cleanup_tables( is_array( $found ) ? $found : array(), $prefix, (string) $wpdb->base_prefix );

		return array_values( array_unique( array_merge( $tables, $own ) ) );
	}

	if ( function_exists( 'add_filter' ) ) {
		add_filter( 'wpmu_drop_tables', 'wplc_mu_site_cleanup_filter', 10, 2 );
	}
}
