<?php
/**
 * Look at the database schema from the command line.
 *
 * @package WPLC
 */

namespace WPLC\CLI;

use WPLC\Infrastructure\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `wp wplc schema`: structure check and leftover tables of deleted network sites (P-32, P-53).
 */
final class SchemaCommand {

	/**
	 * Show the installed and expected schema version and every structure problem (missing tables, columns, indexes).
	 *
	 * ## EXAMPLES
	 *
	 *     wp wplc schema status
	 *
	 * @return void
	 */
	public function status(): void {
		$status = Schema::status();
		\WP_CLI::log(
			sprintf(
				/* translators: 1: installed schema version, 2: schema version of this plugin version. */
				__( 'Schema installed: %1$s; expected: %2$s', 'wp-license' ),
				$status['installed'],
				$status['expected']
			)
		);
		foreach ( $status['problems'] as $problem ) {
			\WP_CLI::warning( $problem );
		}
		if ( $status['problems'] ) {
			\WP_CLI::halt( 1 );
		}
		\WP_CLI::success( __( 'Every table, column and index exists.', 'wp-license' ) );
	}

	/**
	 * List (and drop) the wplc_* tables of network sites that no longer exist.
	 *
	 * WordPress drops a deleted site's plugin tables only when this plugin is loaded in the
	 * request that deletes the site. A site that had the plugin active on its own and was
	 * deleted from the network admin keeps them. Check the list before dropping: another
	 * installation in the same database could use a similar table prefix.
	 *
	 * ## OPTIONS
	 *
	 * [--drop]
	 * : Drop the listed tables (asks for confirmation).
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wplc schema orphans
	 *     wp wplc schema orphans --drop
	 *
	 * @param array<int,string>   $args       Positional arguments.
	 * @param array<string,mixed> $assoc_args Options.
	 * @return void
	 */
	public function orphans( array $args, array $assoc_args ): void {
		global $wpdb;

		if ( ! is_multisite() ) {
			\WP_CLI::success( __( 'Not a multisite network: there are no tables of deleted sites.', 'wp-license' ) );
			return;
		}
		$orphans = Schema::orphaned_site_tables();
		if ( ! $orphans ) {
			\WP_CLI::success( __( 'No wplc_* tables of deleted sites found.', 'wp-license' ) );
			return;
		}
		$tables = array();
		foreach ( $orphans as $site_id => $names ) {
			\WP_CLI::log(
				sprintf(
					/* translators: 1: ID of the deleted site, 2: comma-separated table names. */
					__( 'Site %1$d (deleted): %2$s', 'wp-license' ),
					$site_id,
					implode( ', ', $names )
				)
			);
			$tables = array_merge( $tables, $names );
		}
		if ( empty( $assoc_args['drop'] ) ) {
			\WP_CLI::log( __( 'Run the command again with --drop to delete these tables.', 'wp-license' ) );
			return;
		}
		\WP_CLI::confirm(
			sprintf(
				/* translators: %s: number of tables. */
				_n( 'Drop %s table with all its license data? This cannot be undone.', 'Drop %s tables with all their license data? This cannot be undone.', count( $tables ), 'wp-license' ),
				number_format_i18n( count( $tables ) )
			),
			$assoc_args
		);
		foreach ( $tables as $table ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- explicit, confirmed operator command.
		}
		\WP_CLI::success(
			sprintf(
				/* translators: %s: number of tables. */
				_n( 'Dropped %s table.', 'Dropped %s tables.', count( $tables ), 'wp-license' ),
				number_format_i18n( count( $tables ) )
			)
		);
	}
}
