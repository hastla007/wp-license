<?php
/**
 * Rotate encrypted per-installation secrets.
 *
 * @package WPLC
 */

namespace WPLC\CLI;

use WPLC\Infrastructure\Database\Schema;
use WPLC\License\AuditLog;
use WPLC\Security\ResponseSigner;
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
	 * All rows are inspected unless --max-rows limits the run. The stored
	 * response signing key is re-encrypted as well.
	 *
	 * The command ends with exit code 1 while rows are left that still use an
	 * old key (not rotated, not inspected, failed). Remove an old key from the
	 * key ring only after a run that ends with exit code 0.
	 *
	 * On a multisite network every site has its own tables: run the command
	 * once per site with --url=<site>.
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
	 * : Maximum total rows to inspect in this invocation. Default 0: all rows.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wplc rotate-installation-secrets
	 *     wp wplc rotate-installation-secrets --apply
	 *     wp wplc rotate-installation-secrets --apply --max-rows=5000 --after-id=1200
	 *
	 * @param array<int,string> $args Positional arguments.
	 * @param array<string,mixed> $assoc_args Options.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$result = self::run(
			array(
				'apply' => isset( $assoc_args['apply'] ),
				'batch_size' => isset( $assoc_args['batch-size'] ) ? absint( $assoc_args['batch-size'] ) : 100,
				'after_id' => isset( $assoc_args['after-id'] ) ? absint( $assoc_args['after-id'] ) : 0,
				'max_rows' => isset( $assoc_args['max-rows'] ) ? absint( $assoc_args['max-rows'] ) : 0,
			)
		);

		foreach ( $result['failed_ids'] as $failed_id ) {
			\WP_CLI::warning( sprintf( 'Could not verify or encrypt activation ID %d; row was not changed.', $failed_id ) );
		}
		\WP_CLI::log( sprintf( 'Mode: %s', $result['apply'] ? 'apply' : 'dry-run' ) );
		\WP_CLI::log( sprintf( 'Inspected: %d; %s: %d; already current: %d; changed concurrently: %d; failed: %d; last ID: %d', $result['inspected'], $result['apply'] ? 'rotated' : 'needing rotation', $result['rotated'], $result['already_current'], $result['stale'], $result['failed'], $result['last_id'] ) );
		\WP_CLI::log( sprintf( 'Response signing key: %s', $result['signing_key'] ) );
		if ( $result['not_inspected'] > 0 ) {
			\WP_CLI::warning( sprintf( '%d rows after ID %d were not inspected (--max-rows). Continue with --after-id=%d.', $result['not_inspected'], $result['last_id'], $result['last_id'] ) );
		}
		if ( $result['failed'] > 0 ) {
			\WP_CLI::warning( 'Some rows need manual investigation. No failed row was modified.' );
		}
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			\WP_CLI::log( 'Multisite: this run covered only the tables of the current site. Repeat it for every site of the network, for example: wp site list --field=url | xargs -I % wp --url=% wplc rotate-installation-secrets' );
		}

		if ( $result['remaining'] > 0 ) {
			\WP_CLI::log( sprintf( 'Rows still using an old key: %d. Keep the old keys in the key ring.', $result['remaining'] ) );
			\WP_CLI::halt( 1 );
		}
		\WP_CLI::success( 'All inspected rows use the current key.' );
	}

	/**
	 * Inspect and optionally rotate the stored secrets.
	 *
	 * @param array<string,mixed> $options Keys: apply (bool), batch_size, after_id, max_rows (0 = all rows).
	 * @return array<string,mixed> Counters: inspected, rotated, already_current, stale, failed, failed_ids, last_id,
	 *                             not_inspected, remaining, signing_key, apply.
	 */
	public static function run( array $options = array() ): array {
		global $wpdb;

		$apply = ! empty( $options['apply'] );
		$batch_size = min( 500, max( 1, (int) ( $options['batch_size'] ?? 100 ) ) );
		$last_id = max( 0, (int) ( $options['after_id'] ?? 0 ) );
		$max_rows = max( 0, (int) ( $options['max_rows'] ?? 0 ) );

		$tables = Schema::table_names();
		$inspected = 0;
		$rotated = 0;
		$already_current = 0;
		$stale_rows = 0;
		$failed_ids = array();

		while ( 0 === $max_rows || $inspected < $max_rows ) {
			$limit = 0 === $max_rows ? $batch_size : min( $batch_size, $max_rows - $inspected );
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
				$new_envelope = false === $secret || ! hash_equals( (string) $row['installation_secret_hash'], hash( 'sha256', $secret ) ) ? false : SecretCrypto::encrypt( $secret );
				if ( false === $new_envelope ) {
					$failed_ids[] = $last_id;
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

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names().
		$not_inspected = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['activations']} WHERE id > %d", $last_id ) );
		$signing_key = ResponseSigner::reencrypt_stored_key( $apply );
		if ( 'rotated' === $signing_key ) {
			AuditLog::record( 'response_signing_key_reencrypted', 'system', 0, 'Response signing key encrypted with the configured current key' );
		}

		$failed = count( $failed_ids );
		$remaining = $not_inspected + $failed + $stale_rows + ( $apply ? 0 : $rotated ) + ( in_array( $signing_key, array( 'pending', 'failed' ), true ) ? 1 : 0 );

		return array(
			'apply' => $apply,
			'inspected' => $inspected,
			'rotated' => $rotated,
			'already_current' => $already_current,
			'stale' => $stale_rows,
			'failed' => $failed,
			'failed_ids' => $failed_ids,
			'last_id' => $last_id,
			'not_inspected' => $not_inspected,
			'signing_key' => $signing_key,
			'remaining' => $remaining,
		);
	}

	/**
	 * Number of activation rows whose secret is not encrypted with the current key.
	 *
	 * One COUNT over the activations table without decrypting anything; meant
	 * for the diagnostics page ("rows with an old key", B-22).
	 *
	 * @return int
	 */
	public static function pending_count(): int {
		global $wpdb;

		$tables = Schema::table_names();
		$current = 'v1:' . SecretCrypto::active_key_id() . ':';

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names().
				"SELECT COUNT(*) FROM {$tables['activations']} WHERE installation_secret_encrypted NOT LIKE %s",
				$wpdb->esc_like( $current ) . '%'
			)
		);
	}
}
