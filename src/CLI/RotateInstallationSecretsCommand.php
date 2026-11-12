<?php
/**
 * Rotate encrypted per-installation secrets in bounded batches.
 *
 * @package WPLC
 */

namespace WPLC\CLI;

use WPLC\Infrastructure\Database\Schema;
use WPLC\License\AuditLog;
use WPLC\Security\SecretCrypto;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-CLI command for migrating retained-key and legacy installation secrets.
 */
final class RotateInstallationSecretsCommand {
	/**
	 * Register the command when WP-CLI is active.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'wplc rotate-installation-secrets', self::class );
		}
	}

	/**
	 * Rotate activation secrets to the configured current key.
	 *
	 * By default this command reports what would change. Pass --apply to write.
	 * Use --after-id to resume from a recorded activation ID.
	 *
	 * ## OPTIONS
	 *
	 * [--apply]
	 * : Persist re-encrypted secrets. Without this flag the command is a dry run.
	 *
	 * [--batch-size=<number>]
	 * : Number of rows to inspect per database query. Default 100, maximum 500.
	 *
	 * [--after-id=<number>]
	 * : Start after this activation ID. Default 0.
	 *
	 * [--max-rows=<number>]
	 * : Maximum total rows to inspect in this invocation. Default 5000.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wplc rotate-installation-secrets
	 *     wp wplc rotate-installation-secrets --apply --batch-size=100 --max-rows=5000
	 *     wp wplc rotate-installation-secrets --apply --after-id=1200
	 *
	 * @param array<int,string> $args Positional arguments.
	 * @param array<string,mixed> $assoc_args Options.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		global $wpdb;

		$apply = isset( $assoc_args['apply'] );
		$batch_size = isset( $assoc_args['batch-size'] ) ? absint( $assoc_args['batch-size'] ) : 100;
		$batch_size = min( 500, max( 1, $batch_size ) );
		$after_id = isset( $assoc_args['after-id'] ) ? absint( $assoc_args['after-id'] ) : 0;
		$max_rows = isset( $assoc_args['max-rows'] ) ? absint( $assoc_args['max-rows'] ) : 5000;
		$max_rows = max( 1, $max_rows );

		$tables = Schema::table_names();
		$inspected = 0;
		$rotated = 0;
		$already_current = 0;
		$stale_rows = 0;
		$failed = 0;
		$last_id = $after_id;

		while ( $inspected < $max_rows ) {
			$limit = min( $batch_size, $max_rows - $inspected );
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id,installation_secret_hash,installation_secret_encrypted FROM {$tables['activations']} WHERE id > %d ORDER BY id ASC LIMIT %d",
					$last_id,
					$limit
				),
				ARRAY_A
			);
			if ( ! is_array( $rows ) || empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$last_id = (int) $row['id'];
				++$inspected;

				$old_envelope = (string) $row['installation_secret_encrypted'];
				if ( ! SecretCrypto::needs_rotation( $old_envelope ) ) {
					++$already_current;
					continue;
				}

				$secret = SecretCrypto::decrypt( $old_envelope );
				if ( false === $secret || ! hash_equals( (string) $row['installation_secret_hash'], hash( 'sha256', $secret ) ) ) {
					++$failed;
					\WP_CLI::warning( sprintf( 'Could not verify activation ID %d; row was not changed.', $last_id ) );
					continue;
				}

				$new_envelope = SecretCrypto::encrypt( $secret );
				if ( false === $new_envelope ) {
					++$failed;
					\WP_CLI::warning( sprintf( 'Could not encrypt activation ID %d; row was not changed.', $last_id ) );
					continue;
				}

				if ( ! $apply ) {
					++$rotated;
					continue;
				}

				$updated = $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$tables['activations']} SET installation_secret_encrypted = %s WHERE id = %d AND installation_secret_encrypted = %s",
						$new_envelope,
						$last_id,
						$old_envelope
					)
				);
				if ( 1 !== (int) $updated ) {
					++$stale_rows;
					continue;
				}

				++$rotated;
				AuditLog::record(
					'installation_secret_rotated',
					'activation',
					$last_id,
					'Installation secret encrypted with the configured current key',
					array( 'envelope_version' => 'legacy_or_previous' ),
					array( 'envelope_version' => 'v1_current' )
				);
			}
		}

		\WP_CLI::log( sprintf( 'Mode: %s', $apply ? 'apply' : 'dry-run' ) );
		\WP_CLI::log( sprintf( 'Inspected: %d; needing rotation: %d; already current: %d; changed concurrently: %d; failed: %d; last ID: %d', $inspected, $rotated, $already_current, $stale_rows, $failed, $last_id ) );
		if ( $failed > 0 ) {
			\WP_CLI::warning( 'Some rows need manual investigation. No failed row was modified.' );
		}
	}
}
