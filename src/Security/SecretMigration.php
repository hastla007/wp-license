<?php
/**
 * One-time move of stored secrets to the current envelope format.
 *
 * @package WPLC
 */

namespace WPLC\Security;

use WPLC\Infrastructure\Database\Schema;
use WPLC\License\AuditLog;
use WPLC\Webhooks\Endpoints;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Re-encrypts stored secrets of the legacy formats (v1 and unversioned) as v2 envelopes bound to purpose and
 * context (UEB-12).
 *
 * Runs in small batches on the hourly cleanup hook (`wplc_cleanup_security_rows`), without a schema step. Each run
 * continues where the previous one stopped (option STATE_OPTION, per site: on a multisite network every site has
 * its own tables, option and cron). A value is only replaced when it decrypts, the installation secret still
 * matches its stored hash, and the row still holds the value that was read; every step can therefore run again.
 * When a full pass is through, the state is marked done and later runs cost one option read. Values that cannot
 * be decrypted stay untouched and keep using the legacy fallback of SecretCrypto; legacy_count() reports them.
 *
 * What is covered: installation secrets (table `activations`), webhook secrets (table `webhook_endpoints`) and the
 * stored response signing keys (options of ResponseSigner). `wp wplc rotate-installation-secrets` covers the same
 * values when the encryption key ring rotates.
 */
final class SecretMigration {

	/** Option: array{format:string,after_id:int,failed:int,done:bool}. */
	public const STATE_OPTION = 'wplc_secret_format_migration';

	/** Rows read per query. */
	public const BATCH_SIZE = 200;

	/** Most activation rows one scheduled run inspects. */
	public const ROWS_PER_RUN = 2000;

	/** Seconds after which a run stops starting new batches. */
	public const TIME_BUDGET = 20;

	/**
	 * Hourly job: one bounded run, unless the migration is done.
	 *
	 * @return void
	 */
	public static function run_scheduled(): void {
		self::run( self::ROWS_PER_RUN );
	}

	/**
	 * Re-encrypt up to $max_rows activation rows plus every webhook secret and signing key of a legacy format.
	 *
	 * @param int $max_rows Most activation rows to inspect in this run.
	 * @return array{status:string,migrated:int,failed:int,webhooks:int,after_id:int}
	 *         status: 'done' (nothing left, nothing was to do), 'finished' (this run completed the pass) or
	 *         'continues' (rows are left for the next run).
	 */
	public static function run( int $max_rows ): array {
		global $wpdb;

		$state = self::state();
		if ( $state['done'] ) {
			return array( 'status' => 'done', 'migrated' => 0, 'failed' => 0, 'webhooks' => 0, 'after_id' => $state['after_id'] );
		}

		$started  = time();
		$webhooks = self::migrate_webhooks( true );
		self::migrate_signing_keys();

		$tables   = Schema::table_names();
		$migrated = 0;
		$failed   = 0;
		$seen     = 0;
		$finished = false;
		while ( $seen < $max_rows && time() - $started < self::TIME_BUDGET ) {
			$limit = min( self::BATCH_SIZE, $max_rows - $seen );
			$rows  = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT id,installation_uuid,installation_secret_hash,installation_secret_encrypted FROM %i WHERE id > %d AND installation_secret_encrypted NOT LIKE %s ORDER BY id ASC LIMIT %d',
					$tables['activations'],
					$state['after_id'],
					$wpdb->esc_like( SecretCrypto::FORMAT . ':' ) . '%',
					$limit
				),
				ARRAY_A
			);
			if ( ! is_array( $rows ) ) {
				break; // Database error: try again next hour.
			}
			foreach ( $rows as $row ) {
				$state['after_id'] = (int) $row['id'];
				++$seen;
				if ( self::migrate_activation( $row ) ) {
					++$migrated;
				} else {
					++$failed;
				}
			}
			if ( count( $rows ) < $limit ) {
				$finished = true;
				break;
			}
		}

		$state['failed'] += $failed;
		if ( $finished ) {
			$state['done'] = true;
			AuditLog::record(
				'secret_format_migrated',
				'system',
				0,
				'Stored secrets moved to the envelope format bound to purpose and row.',
				null,
				array(
					'format'      => SecretCrypto::FORMAT,
					'not_moved'   => $state['failed'],
					'legacy_left' => self::legacy_count(),
				)
			);
		}
		update_option( self::STATE_OPTION, $state, false );

		return array(
			'status'   => $finished ? 'finished' : 'continues',
			'migrated' => $migrated,
			'failed'   => $failed,
			'webhooks' => $webhooks,
			'after_id' => $state['after_id'],
		);
	}

	/**
	 * Re-encrypt the webhook secrets that use a legacy format or a previous key.
	 *
	 * @param bool $apply False only counts.
	 * @return int Number of secrets re-encrypted (or, without $apply, that would be).
	 */
	public static function migrate_webhooks( bool $apply ): int {
		global $wpdb;

		$table = Schema::table_names()['webhook_endpoints'];
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT id,secret_encrypted FROM %i WHERE secret_encrypted <> '' AND secret_encrypted NOT LIKE %s ORDER BY id ASC LIMIT %d", $table, $wpdb->esc_like( SecretCrypto::current_prefix() ) . '%', 500 ),
			ARRAY_A
		);
		$count = 0;
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$id      = (int) $row['id'];
			$stored  = (string) $row['secret_encrypted'];
			$context = Endpoints::secret_context( $id );
			$plain   = SecretCrypto::decrypt( $stored, SecretCrypto::PURPOSE_WEBHOOK, $context );
			$fresh   = is_string( $plain ) ? SecretCrypto::encrypt( $plain, SecretCrypto::PURPOSE_WEBHOOK, $context ) : false;
			if ( false === $fresh ) {
				continue;
			}
			if ( ! $apply || 1 === (int) $wpdb->query( $wpdb->prepare( 'UPDATE %i SET secret_encrypted = %s WHERE id = %d AND secret_encrypted = %s', $table, $fresh, $id, $stored ) ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Number of stored secrets still in a legacy format on this site (activations, webhooks, signing key options).
	 *
	 * @return int
	 */
	public static function legacy_count(): int {
		global $wpdb;

		$tables = Schema::table_names();
		$like   = $wpdb->esc_like( SecretCrypto::FORMAT . ':' ) . '%';
		$count  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE installation_secret_encrypted NOT LIKE %s', $tables['activations'], $like ) );
		$count += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE secret_encrypted <> '' AND secret_encrypted NOT LIKE %s", $tables['webhook_endpoints'], $like ) );
		foreach ( array( ResponseSigner::OPTION, ResponseSigner::NEXT_OPTION ) as $option ) {
			$value = (string) get_option( $option, '' );
			if ( '' !== $value && SecretCrypto::is_legacy( $value ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Current state of the migration on this site.
	 *
	 * @return array{format:string,after_id:int,failed:int,done:bool}
	 */
	public static function state(): array {
		$stored = get_option( self::STATE_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		if ( SecretCrypto::FORMAT !== ( $stored['format'] ?? '' ) ) {
			// No state yet, or the state of an older format: start a new pass.
			$stored = array();
		}

		return array(
			'format'   => SecretCrypto::FORMAT,
			'after_id' => max( 0, (int) ( $stored['after_id'] ?? 0 ) ),
			'failed'   => max( 0, (int) ( $stored['failed'] ?? 0 ) ),
			'done'     => ! empty( $stored['done'] ),
		);
	}

	/**
	 * Re-encrypt one activation row.
	 *
	 * @param array<string,mixed> $row Columns id, installation_uuid, installation_secret_hash, installation_secret_encrypted.
	 * @return bool False when the value could not be decrypted, did not match its hash or could not be encrypted.
	 */
	private static function migrate_activation( array $row ): bool {
		global $wpdb;

		$stored = (string) $row['installation_secret_encrypted'];
		$uuid   = (string) $row['installation_uuid'];
		$secret = SecretCrypto::decrypt( $stored, SecretCrypto::PURPOSE_INSTALLATION, $uuid );
		if ( false === $secret || ! hash_equals( (string) $row['installation_secret_hash'], hash( 'sha256', $secret ) ) ) {
			return false;
		}
		$fresh = SecretCrypto::encrypt( $secret, SecretCrypto::PURPOSE_INSTALLATION, $uuid );
		if ( false === $fresh ) {
			return false;
		}
		// Only the value that was read is replaced; a concurrent lazy rotation (Authenticator) wins and is fine too.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET installation_secret_encrypted = %s WHERE id = %d AND installation_secret_encrypted = %s',
				Schema::table_names()['activations'],
				$fresh,
				(int) $row['id'],
				$stored
			)
		);

		return true;
	}

	/**
	 * Re-encrypt the stored signing keys (current and prepared) when they use a legacy format.
	 *
	 * @return void
	 */
	private static function migrate_signing_keys(): void {
		foreach ( array( ResponseSigner::OPTION, ResponseSigner::NEXT_OPTION ) as $option ) {
			$value = (string) get_option( $option, '' );
			if ( '' !== $value && SecretCrypto::is_envelope( $value ) && SecretCrypto::is_legacy( $value ) ) {
				ResponseSigner::reencrypt_option( $option, true );
			}
		}
	}
}
