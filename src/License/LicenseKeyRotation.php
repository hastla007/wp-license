<?php
/**
 * Re-key licenses whose key was derived with a leaked secret (B-155).
 *
 * @package WPLC
 */

namespace WPLC\License;

use WPLC\Infrastructure\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Replaces the keys of every license issued under one license-key secret ID.
 *
 * The server looks a key up by its stored SHA-256 verifier, so removing a
 * leaked secret from `WPLC_LICENSE_KEY_SECRETS` does not stop a key derived
 * from it. Whoever knows the secret and a license UUID can derive that key.
 * Re-keying gives the license a new UUID and a key derived with the current
 * secret; the old key stops working at once. Site activations keep working
 * (they authenticate with their installation secret) unless they are released.
 */
final class LicenseKeyRotation {

	public const RESULT_REKEYED     = 'rekeyed';
	public const RESULT_NOT_FOUND   = 'not_found';
	public const RESULT_OTHER_KEY   = 'other_key_id';
	public const RESULT_PENDING     = 'pending';
	public const RESULT_FAILED      = 'failed';

	/**
	 * Number of licenses per stored key ID.
	 *
	 * @return array<string,int>
	 */
	public static function count_by_key_id(): array {
		global $wpdb;

		$tables = Schema::table_names();
		$rows   = $wpdb->get_results(
			$wpdb->prepare( 'SELECT license_key_secret_id AS key_id, COUNT(*) AS licenses FROM %i GROUP BY license_key_secret_id ORDER BY license_key_secret_id ASC', $tables['licenses'] ),
			ARRAY_A
		);
		$counts = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$counts[ (string) $row['key_id'] ] = (int) $row['licenses'];
		}

		return $counts;
	}

	/**
	 * IDs of licenses stored with a key ID, in ID order.
	 *
	 * @param string $key_id   Key ID.
	 * @param int    $after_id Start after this license ID.
	 * @param int    $limit    Maximum rows.
	 * @return int[]
	 */
	public static function license_ids( string $key_id, int $after_id, int $limit ): array {
		global $wpdb;

		$tables = Schema::table_names();
		$ids    = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE license_key_secret_id = %s AND id > %d ORDER BY id ASC LIMIT %d',
				$tables['licenses'],
				$key_id,
				max( 0, $after_id ),
				max( 1, min( 1000, $limit ) )
			)
		);

		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * Give one license a new key derived with the current secret.
	 *
	 * Revoked licenses are re-keyed too, so restoring one later does not bring
	 * the leaked key back; their owner is never notified. Pending licenses are
	 * still being issued and are skipped.
	 *
	 * @param int    $license_id    License ID.
	 * @param string $from_key_id   Key ID the license must still have (compare-and-set).
	 * @param bool   $release_sites Also release every active site activation.
	 * @param bool   $notify        Fire `wplc/license_key_reissued` so the owner gets the new key by email.
	 * @param int    $actor_user_id Acting user (0 for WP-CLI).
	 * @return string One of the RESULT_* constants.
	 */
	public static function rekey( int $license_id, string $from_key_id, bool $release_sites = false, bool $notify = false, int $actor_user_id = 0 ): string {
		global $wpdb;

		$tables  = Schema::table_names();
		$license = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', $tables['licenses'], $license_id ), ARRAY_A );
		if ( ! is_array( $license ) ) {
			return self::RESULT_NOT_FOUND;
		}
		if ( (string) $license['license_key_secret_id'] !== $from_key_id ) {
			return self::RESULT_OTHER_KEY;
		}
		if ( 'pending' === (string) $license['status'] ) {
			return self::RESULT_PENDING;
		}

		$key_id = LicenseKey::current_key_id();
		if ( $key_id === $from_key_id ) {
			return self::RESULT_FAILED;
		}
		$uuid = LicenseIssuer::new_uuid();
		$key  = LicenseKey::for_uuid( $uuid, $key_id );
		if ( '' === $key ) {
			return self::RESULT_FAILED;
		}
		$prefix  = LicenseKey::prefix( $uuid );
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET license_uuid = %s, license_key_hash = %s, license_key_secret_id = %s, key_prefix = %s, updated_at = %s WHERE id = %d AND license_uuid = %s AND license_key_secret_id = %s',
				$tables['licenses'],
				$uuid,
				LicenseKey::hash( $key ),
				$key_id,
				$prefix,
				gmdate( 'Y-m-d H:i:s' ),
				$license_id,
				(string) $license['license_uuid'],
				$from_key_id
			)
		);
		if ( 1 !== (int) $updated ) {
			return self::RESULT_FAILED;
		}

		$reason   = AuditReason::encode( AuditReason::LICENSE_KEY_SECRET_REVOKED, array( 'key_id' => $from_key_id ) );
		$released = $release_sites ? self::release_sites( $license_id, $reason, $actor_user_id ) : 0;

		AuditLog::record(
			'license_key_reissued',
			'license',
			$license_id,
			$reason,
			array(
				'key_prefix'   => (string) $license['key_prefix'],
				'license_uuid' => (string) $license['license_uuid'],
				'key_id'       => $from_key_id,
			),
			array(
				'key_prefix'     => $prefix,
				'license_uuid'   => $uuid,
				'key_id'         => $key_id,
				'released_sites' => $released,
			),
			$actor_user_id
		);

		if ( $notify && 'revoked' !== (string) $license['status'] && ! empty( $license['customer_id'] ) ) {
			/** This action is documented in src/Admin/SupportActions.php */
			do_action( 'wplc/license_key_reissued', $license_id, $key, $actor_user_id );
		}

		return self::RESULT_REKEYED;
	}

	/**
	 * Release every active site activation of a license (staging children first).
	 *
	 * @param int    $license_id    License ID.
	 * @param string $reason        Audit reason.
	 * @param int    $actor_user_id Acting user.
	 * @return int Released activations.
	 */
	private static function release_sites( int $license_id, string $reason, int $actor_user_id ): int {
		global $wpdb;

		$tables = Schema::table_names();
		$ids    = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE license_id = %d AND status = %s ORDER BY staging_parent_id IS NULL ASC, id ASC LIMIT 1000',
				$tables['activations'],
				$license_id,
				'active'
			)
		);
		$count = 0;
		foreach ( is_array( $ids ) ? $ids : array() as $activation_id ) {
			if ( ActivationManager::deactivate( (int) $activation_id, $reason, $actor_user_id, 'admin_site_released' ) ) {
				++$count;
			}
		}

		return $count;
	}
}
