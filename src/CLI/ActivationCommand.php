<?php
/**
 * List and release activations from the command line.
 *
 * @package WPLC
 */

namespace WPLC\CLI;

use WPLC\Infrastructure\Database\Schema;
use WPLC\License\ActivationManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `wp wplc activation`: scripted way to look at and reset the sites of a license (B-172).
 */
final class ActivationCommand {

	/**
	 * List the activations of a license.
	 *
	 * ## OPTIONS
	 *
	 * --license=<id>
	 * : License ID.
	 *
	 * [--status=<status>]
	 * : "active" (default), "deactivated" or "all".
	 *
	 * ## EXAMPLES
	 *
	 *     wp wplc activation list --license=123
	 *
	 * @subcommand list
	 *
	 * @param array<int,string>   $args       Positional arguments.
	 * @param array<string,mixed> $assoc_args Options.
	 * @return void
	 */
	public function list_( array $args, array $assoc_args ): void {
		$rows = self::rows( absint( $assoc_args['license'] ?? 0 ), (string) ( $assoc_args['status'] ?? 'active' ) );
		foreach ( $rows as $row ) {
			\WP_CLI::line( implode( ' | ', array_map( 'strval', $row ) ) );
		}
		\WP_CLI::log( sprintf( '%d activations.', count( $rows ) ) );
	}

	/**
	 * Release (deactivate) activations.
	 *
	 * Uses the same code path as the customer account and the admin: the
	 * staging site of a released live site is released with it, and every
	 * change is written to the audit log.
	 *
	 * ## OPTIONS
	 *
	 * [<activation-id>...]
	 * : Activation IDs to release.
	 *
	 * [--license=<id>]
	 * : Release all active activations of this license instead.
	 *
	 * [--environment=<environment>]
	 * : With --license: only "production", "staging" or "development" activations.
	 *
	 * [--reason=<text>]
	 * : Reason for the audit log.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wplc activation release 55 56
	 *     wp wplc activation release --license=123 --environment=development
	 *
	 * @param array<int,string>   $args       Positional arguments.
	 * @param array<string,mixed> $assoc_args Options.
	 * @return void
	 */
	public function release( array $args, array $assoc_args ): void {
		$ids = array_filter( array_map( 'absint', $args ) );
		$license_id = absint( $assoc_args['license'] ?? 0 );
		if ( ! $ids && $license_id < 1 ) {
			\WP_CLI::error( 'Name activation IDs or a license with --license=<id>.' );
		}
		if ( ! $ids ) {
			$environment = (string) ( $assoc_args['environment'] ?? '' );
			foreach ( self::rows( $license_id, 'active' ) as $row ) {
				if ( '' === $environment || $row['environment'] === $environment ) {
					$ids[] = (int) $row['id'];
				}
			}
		}

		$released = self::release_ids( $ids, (string) ( $assoc_args['reason'] ?? 'Released with WP-CLI' ) );
		\WP_CLI::success( sprintf( 'Released %d of %d activations (others were not active any more).', $released, count( $ids ) ) );
	}

	/**
	 * Activation rows of a license without credentials.
	 *
	 * @param int    $license_id License ID.
	 * @param string $status     active, deactivated, or all.
	 * @return array<int,array<string,mixed>>
	 */
	public static function rows( int $license_id, string $status = 'active' ): array {
		global $wpdb;

		$tables = Schema::table_names();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names().
				"SELECT id,status,environment,site_url,staging_parent_id,installation_uuid,activated_at,last_seen_at,deactivated_at FROM {$tables['activations']} WHERE license_id = %d ORDER BY id ASC",
				$license_id
			),
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();
		if ( 'all' === $status ) {
			return $rows;
		}

		return array_values(
			array_filter(
				$rows,
				static function ( array $row ) use ( $status ): bool {
					return $row['status'] === $status;
				}
			)
		);
	}

	/**
	 * Release activations through ActivationManager.
	 *
	 * @param int[]  $ids    Activation IDs.
	 * @param string $reason Reason for the audit log.
	 * @return int Number of activations this call released (staging children released with a parent are not counted).
	 */
	public static function release_ids( array $ids, string $reason ): int {
		$released = 0;
		foreach ( array_unique( array_map( 'absint', $ids ) ) as $id ) {
			if ( ActivationManager::deactivate( $id, $reason, function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 ) ) {
				++$released;
			}
		}

		return $released;
	}
}
