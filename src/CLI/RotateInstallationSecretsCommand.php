<?php
/**
 * Rotate encrypted per-installation secrets in bounded batches.
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
	 * Rotate activation secrets and the stored response signing keys to the configured current key.
	 *
	 * By default this command reports what would change. Pass --apply to write. The stored response signing key
	 * (and a prepared next key) are checked on every run, also with --after-id.
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
					/* translators: %d: activation ID. */
					\WP_CLI::warning( sprintf( __( 'Could not verify activation ID %d; row was not changed.', 'wp-license' ), $last_id ) );
					continue;
				}

				$new_envelope = SecretCrypto::encrypt( $secret );
				if ( false === $new_envelope ) {
					++$failed;
					/* translators: %d: activation ID. */
					\WP_CLI::warning( sprintf( __( 'Could not encrypt activation ID %d; row was not changed.', 'wp-license' ), $last_id ) );
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
					\WPLC\License\AuditReason::INSTALLATION_SECRET_ROTATED,
					array( 'envelope_version' => 'legacy_or_previous' ),
					array( 'envelope_version' => 'v1_current' )
				);
			}
		}

		/* translators: %s: "apply" or "dry-run". */
		\WP_CLI::log( sprintf( __( 'Mode: %s', 'wp-license' ), $apply ? __( 'apply', 'wp-license' ) : __( 'dry-run', 'wp-license' ) ) );
		\WP_CLI::log(
			$apply
				? sprintf(
					/* translators: 1: inspected rows, 2: rotated rows, 3: rows already current, 4: rows changed concurrently, 5: failed rows, 6: last inspected activation ID (resume with --after-id). */
					__( 'Inspected: %1$d; rotated: %2$d; already current: %3$d; changed concurrently: %4$d; failed: %5$d; last ID: %6$d', 'wp-license' ),
					$inspected,
					$rotated,
					$already_current,
					$stale_rows,
					$failed,
					$last_id
				)
				: sprintf(
					/* translators: 1: inspected rows, 2: rows needing rotation, 3: rows already current, 4: rows changed concurrently, 5: failed rows, 6: last inspected activation ID (resume with --after-id). */
					__( 'Inspected: %1$d; needing rotation: %2$d; already current: %3$d; changed concurrently: %4$d; failed: %5$d; last ID: %6$d', 'wp-license' ),
					$inspected,
					$rotated,
					$already_current,
					$stale_rows,
					$failed,
					$last_id
				)
		);
		if ( $failed > 0 ) {
			\WP_CLI::warning( __( 'Some rows need manual investigation. No failed row was modified.', 'wp-license' ) );
		}

		// The stored response signing keys are encrypted with the same key ring. Without this step an old
		// encryption key looked unused after the activations were done, and removing it silently disabled the
		// Ed25519 response signature, so every client that pins the public key rejected the server.
		$signing_failed = false;
		foreach ( array( ResponseSigner::OPTION => __( 'Response signing key', 'wp-license' ), ResponseSigner::NEXT_OPTION => __( 'Prepared next signing key', 'wp-license' ) ) as $option => $label ) {
			$status = ResponseSigner::reencrypt_option( $option, $apply );
			if ( 'rotated' === $status ) {
				AuditLog::record(
					'response_signing_key_reencrypted',
					'system',
					0,
					\WPLC\License\AuditReason::INSTALLATION_SECRET_ROTATED,
					array( 'option' => $option, 'envelope_version' => 'legacy_or_previous' ),
					array( 'option' => $option, 'envelope_version' => 'v1_current' )
				);
			}
			$signing_failed = $signing_failed || 'undecryptable' === $status;
			if ( 'empty' !== $status || ResponseSigner::OPTION === $option ) {
				/* translators: 1: "Response signing key" or "Prepared next signing key", 2: status, for example "already current". */
				\WP_CLI::log( sprintf( __( '%1$s: %2$s', 'wp-license' ), $label, self::status_text( $status ) ) );
			}
		}
		if ( $signing_failed ) {
			\WP_CLI::warning( __( 'A stored response signing key cannot be decrypted with the configured key ring. Put the previous encryption key back into WPLC_INSTALLATION_ENCRYPTION_KEYS; until then responses carry no Ed25519 signature and clients that pin the public key reject them.', 'wp-license' ) );
		}
	}

	/**
	 * Human-readable status of ResponseSigner::reencrypt_option().
	 *
	 * @param string $status Status.
	 */
	private static function status_text( string $status ): string {
		$texts = array(
			'constant'      => __( 'from WPLC_RESPONSE_SIGNING_KEY, nothing stored', 'wp-license' ),
			'empty'         => __( 'not stored yet', 'wp-license' ),
			'current'       => __( 'already current', 'wp-license' ),
			'rotate'        => __( 'needs rotation', 'wp-license' ),
			'rotated'       => __( 'rotated', 'wp-license' ),
			'undecryptable' => __( 'CANNOT BE DECRYPTED', 'wp-license' ),
		);
		return $texts[ $status ] ?? $status;
	}
}
