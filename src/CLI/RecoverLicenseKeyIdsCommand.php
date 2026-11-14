<?php
/**
 * Verify and repair persisted license-key secret IDs.
 *
 * @package WPLC
 */

namespace WPLC\CLI;

use WPLC\Infrastructure\Database\Schema;
use WPLC\License\AuditLog;
use WPLC\License\LicenseKey;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-CLI recovery command that changes only a verified key ID.
 */
final class RecoverLicenseKeyIdsCommand {
	/**
	 * Register the command when WP-CLI is active.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'wplc recover-license-key-ids', self::class );
		}
	}

	/**
	 * Verify license keys and optionally remap rows to a matching configured key ID.
	 *
	 * No plaintext key is printed. The stored key hash is never rewritten.
	 * All rows are inspected unless --max-rows limits the run. The command
	 * ends with exit code 1 while rows are left that could not be verified,
	 * were not inspected, or (in a dry run) still wait for --apply.
	 *
	 * On a multisite network run the command once per site with --url=<site>.
	 *
	 * ## OPTIONS
	 *
	 * --to=<key-id>
	 * : Configured key ID to test as a recovery target.
	 *
	 * [--apply]
	 * : Persist verified key-ID corrections. Without this flag the command is a dry run.
	 *
	 * [--after-id=<number>]
	 * : Start after this license ID. Default 0.
	 *
	 * [--max-rows=<number>]
	 * : Maximum rows to inspect. Default 0: all rows.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wplc recover-license-key-ids --to=legacy
	 *     wp wplc recover-license-key-ids --to=legacy --apply
	 *
	 * @param array<int,string> $args Positional arguments.
	 * @param array<string,mixed> $assoc_args Options.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		global $wpdb;

		$target_key_id = isset( $assoc_args['to'] ) ? sanitize_text_field( (string) $assoc_args['to'] ) : '';
		if ( '' === $target_key_id || ! preg_match( '/^[a-zA-Z0-9._-]{1,32}$/', $target_key_id ) ) {
			\WP_CLI::error( 'Provide a valid configured key ID with --to=<key-id>.' );
		}
		$apply = isset( $assoc_args['apply'] );
		$last_id = isset( $assoc_args['after-id'] ) ? absint( $assoc_args['after-id'] ) : 0;
		$max_rows = isset( $assoc_args['max-rows'] ) ? absint( $assoc_args['max-rows'] ) : 0;
		$tables = Schema::table_names();
		$inspected = 0;
		$verified = 0;
		$recovered = 0;
		$unmatched = 0;
		$stale = 0;

		while ( 0 === $max_rows || $inspected < $max_rows ) {
			$limit = 0 === $max_rows ? 250 : min( 250, $max_rows - $inspected );
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id,license_uuid,license_key_hash,license_key_secret_id FROM {$tables['licenses']} WHERE id > %d ORDER BY id ASC LIMIT %d",
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
				$uuid = (string) $row['license_uuid'];
				$hash = (string) $row['license_key_hash'];
				$current_key_id = (string) $row['license_key_secret_id'];

				if ( LicenseKey::matches_hash( $uuid, $current_key_id, $hash ) ) {
					++$verified;
					continue;
				}
				if ( ! LicenseKey::matches_hash( $uuid, $target_key_id, $hash ) ) {
					++$unmatched;
					continue;
				}
				if ( ! $apply ) {
					++$recovered;
					continue;
				}

				$updated = $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$tables['licenses']} SET license_key_secret_id = %s WHERE id = %d AND license_key_secret_id = %s AND license_key_hash = %s",
						$target_key_id,
					$last_id,
					$current_key_id,
					$hash
					)
				);
				if ( 1 !== (int) $updated ) {
					++$stale;
					continue;
				}

				++$recovered;
				AuditLog::record(
					'license_key_id_recovered',
					'license',
					$last_id,
					'License key ID corrected after matching the stored verifier',
					array( 'key_id' => $current_key_id ),
					array( 'key_id' => $target_key_id )
				);
			}
		}

		\WP_CLI::log( sprintf( 'Mode: %s; inspected: %d; already verified: %d; recoverable: %d; unmatched: %d; changed concurrently: %d; last ID: %d', $apply ? 'apply' : 'dry-run', $inspected, $verified, $recovered, $unmatched, $stale, $last_id ) );
		if ( $unmatched > 0 ) {
			\WP_CLI::warning( 'Unmatched rows were not modified. Check the configured key ring and recovery target.' );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names().
		$not_inspected = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['licenses']} WHERE id > %d", $last_id ) );
		if ( $not_inspected > 0 ) {
			\WP_CLI::warning( sprintf( '%d rows after ID %d were not inspected (--max-rows). Continue with --after-id=%d.', $not_inspected, $last_id, $last_id ) );
		}
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			\WP_CLI::log( 'Multisite: this run covered only the tables of the current site. Repeat it for every site with --url=<site>.' );
		}
		if ( $unmatched + $stale + $not_inspected + ( $apply ? 0 : $recovered ) > 0 ) {
			\WP_CLI::halt( 1 );
		}
	}
}
