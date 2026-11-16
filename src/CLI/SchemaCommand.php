<?php
/**
 * Look at the database schema from the command line.
 *
 * @package WPLC
 */

namespace WPLC\CLI;

use WPLC\Infrastructure\Database\Schema;
use WPLC\Infrastructure\Database\SiteCleanupHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `wp wplc schema`: structure check, leftover tables of deleted network sites and the site cleanup helper.
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

	/**
	 * Install the must-use helper that drops a deleted site's wplc_* tables (multisite).
	 *
	 * Copies mu-plugins/wplc-site-cleanup.php from the plugin into wp-content/mu-plugins/
	 * (replacing an older copy). Needed only when the plugin is active on single sites of a
	 * network: WordPress does not load it in the request that deletes such a site.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wplc schema install-mu-helper --yes
	 *
	 * @subcommand install-mu-helper
	 *
	 * @param array<int,string>   $args       Positional arguments.
	 * @param array<string,mixed> $assoc_args Options.
	 * @return void
	 */
	public function install_mu_helper( array $args, array $assoc_args ): void {
		if ( ! is_multisite() ) {
			\WP_CLI::warning( __( 'Not a multisite network: the helper is not needed.', 'wp-license' ) );
		}
		$target = SiteCleanupHelper::target();
		if ( SiteCleanupHelper::STATUS_INSTALLED === SiteCleanupHelper::status() ) {
			/* translators: %s: file path. */
			\WP_CLI::success( sprintf( __( 'The helper is already installed: %s', 'wp-license' ), $target ) );
			return;
		}
		\WP_CLI::confirm(
			/* translators: %s: file path. */
			sprintf( __( 'Install %s? From then on, deleting a network site also drops its wplc_* tables with all license data.', 'wp-license' ), $target ),
			$assoc_args
		);
		$error = SiteCleanupHelper::install();
		if ( '' !== $error ) {
			\WP_CLI::error( $error );
		}
		/* translators: %s: file path. */
		\WP_CLI::success( sprintf( __( 'Installed %s.', 'wp-license' ), $target ) );
	}

	/**
	 * Remove the must-use helper installed with install-mu-helper.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wplc schema remove-mu-helper --yes
	 *
	 * @subcommand remove-mu-helper
	 *
	 * @param array<int,string>   $args       Positional arguments.
	 * @param array<string,mixed> $assoc_args Options.
	 * @return void
	 */
	public function remove_mu_helper( array $args, array $assoc_args ): void {
		$target = SiteCleanupHelper::target();
		if ( SiteCleanupHelper::STATUS_MISSING === SiteCleanupHelper::status() ) {
			\WP_CLI::success( __( 'The helper is not installed.', 'wp-license' ) );
			return;
		}
		/* translators: %s: file path. */
		\WP_CLI::confirm( sprintf( __( 'Delete %s?', 'wp-license' ), $target ), $assoc_args );
		$error = SiteCleanupHelper::remove();
		if ( '' !== $error ) {
			\WP_CLI::error( $error );
		}
		/* translators: %s: file path. */
		\WP_CLI::success( sprintf( __( 'Removed %s.', 'wp-license' ), $target ) );
	}
}
