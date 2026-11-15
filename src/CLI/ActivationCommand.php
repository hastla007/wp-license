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
		\WP_CLI::log(
			sprintf(
				/* translators: %s: number of activations. */
				_n( '%s activation.', '%s activations.', count( $rows ), 'wp-license' ),
				number_format_i18n( count( $rows ) )
			)
		);
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
	 * : Release all active activations of this license instead. Asks for confirmation. Cannot be combined
	 * with activation IDs.
	 *
	 * [--environment=<environment>]
	 * : With --license: only activations of this environment.
	 * ---
	 * options:
	 *   - production
	 *   - staging
	 *   - development
	 * ---
	 *
	 * [--reason=<text>]
	 * : Reason for the audit log.
	 *
	 * [--yes]
	 * : Do not ask for confirmation (--license).
	 *
	 * ## EXAMPLES
	 *
	 *     wp wplc activation release 55 56
	 *     wp wplc activation release --license=123 --environment=development
	 *     wp wplc activation release --license=123 --yes
	 *
	 * @param array<int,string>   $args       Positional arguments.
	 * @param array<string,mixed> $assoc_args Options.
	 * @return void
	 */
	public function release( array $args, array $assoc_args ): void {
		$plan = self::release_plan( $args, $assoc_args );
		if ( is_wp_error( $plan ) ) {
			\WP_CLI::error( $plan->get_error_message() );
		}
		$ids = $plan['ids'];
		if ( $plan['license_id'] > 0 ) {
			if ( ! $ids ) {
				\WP_CLI::success(
					sprintf(
						/* translators: %d: license ID. */
						__( 'License %d has no matching active activation.', 'wp-license' ),
						$plan['license_id']
					)
				);
				return;
			}
			\WP_CLI::confirm(
				sprintf(
					/* translators: 1: number of activations, 2: license ID. */
					_n( 'Release %1$s active activation of license %2$d? The site loses its license until it is activated again.', 'Release %1$s active activations of license %2$d? The sites lose their license until they are activated again.', count( $ids ), 'wp-license' ),
					number_format_i18n( count( $ids ) ),
					$plan['license_id']
				),
				$assoc_args
			);
		}

		$released = self::release_ids( $ids, (string) ( $assoc_args['reason'] ?? __( 'Released with WP-CLI', 'wp-license' ) ) );
		\WP_CLI::success(
			sprintf(
				/* translators: 1: number of released activations, 2: number of named activations. */
				_n( 'Released %1$s of %2$s activation (others were not active any more).', 'Released %1$s of %2$s activations (others were not active any more).', count( $ids ), 'wp-license' ),
				number_format_i18n( $released ),
				number_format_i18n( count( $ids ) )
			)
		);
	}

	/**
	 * Validate the arguments of `release` and resolve the activation IDs (P-34).
	 *
	 * Activation IDs and --license are exclusive (IDs of another license must not be
	 * released under a license filter by accident), --environment needs --license
	 * and one of the known environments.
	 *
	 * @param array<int,string>   $args       Positional arguments.
	 * @param array<string,mixed> $assoc_args Options.
	 * @return array{ids:int[],license_id:int}|\WP_Error
	 */
	public static function release_plan( array $args, array $assoc_args ) {
		$ids         = array_values( array_filter( array_map( 'absint', $args ) ) );
		$license_id  = absint( $assoc_args['license'] ?? 0 );
		$environment = isset( $assoc_args['environment'] ) ? (string) $assoc_args['environment'] : '';
		if ( count( $ids ) !== count( $args ) ) {
			return new \WP_Error( 'invalid_id', __( 'Activation IDs must be positive numbers.', 'wp-license' ) );
		}
		if ( $ids && isset( $assoc_args['license'] ) ) {
			return new \WP_Error( 'mixed', __( 'Name activation IDs or --license=<id>, not both.', 'wp-license' ) );
		}
		if ( ! $ids && $license_id < 1 ) {
			return new \WP_Error( 'missing', __( 'Name activation IDs or a license with --license=<id>.', 'wp-license' ) );
		}
		if ( '' !== $environment && $license_id < 1 ) {
			return new \WP_Error( 'environment_without_license', __( '--environment can only be used with --license.', 'wp-license' ) );
		}
		if ( isset( $assoc_args['environment'] ) && ! in_array( $environment, array( 'production', 'staging', 'development' ), true ) ) {
			return new \WP_Error( 'environment', __( '--environment must be "production", "staging" or "development".', 'wp-license' ) );
		}
		if ( $license_id > 0 ) {
			foreach ( self::rows( $license_id, 'active' ) as $row ) {
				if ( '' === $environment || $row['environment'] === $environment ) {
					$ids[] = (int) $row['id'];
				}
			}
		}

		return array(
			'ids'        => $ids,
			'license_id' => $license_id,
		);
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
