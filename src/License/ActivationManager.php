<?php
/**
 * Shared deactivation of product installations.
 *
 * @package WPLC
 */

namespace WPLC\License;

use WPLC\Infrastructure\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single code path for deactivating an activation from the REST API, the
 * customer portal, and the admin screens.
 */
final class ActivationManager {

	/**
	 * Deactivate an active activation and its active staging children.
	 *
	 * Takes the per-license advisory lock so a staging activation cannot be
	 * created for a parent that is being deactivated at the same moment.
	 *
	 * @param int    $activation_id Activation ID.
	 * @param string $reason Human-readable reason (redacted by the audit log).
	 * @param int    $actor_user_id Acting user, or zero for system/API events.
	 * @param string $audit_action Audit action of the requested activation.
	 * @return bool False when the activation is not active, the license is busy, or the database failed.
	 */
	public static function deactivate( int $activation_id, string $reason, int $actor_user_id = 0, string $audit_action = 'site_deactivated' ): bool {
		global $wpdb;

		if ( $activation_id < 1 ) {
			return false;
		}

		$tables = Schema::table_names();
		$license_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT license_id FROM {$tables['activations']} WHERE id = %d AND status = %s LIMIT 1",
				$activation_id,
				'active'
			)
		);
		if ( null === $license_id ) {
			return false;
		}

		$lock_name = self::lock_name( (int) $license_id );
		self::use_primary_database();
		$locked = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name ) );
		if ( '1' !== (string) $locked ) {
			return false;
		}

		try {
			return self::deactivate_while_locked( $activation_id, $reason, $actor_user_id, $audit_action );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}

	/**
	 * Deactivate without acquiring the per-license lock.
	 *
	 * Only for callers that already hold the lock returned by lock_name() for
	 * the activation's license (the REST activation and deactivation handlers).
	 *
	 * @param int    $activation_id Activation ID.
	 * @param string $reason Human-readable reason.
	 * @param int    $actor_user_id Acting user, or zero.
	 * @param string $audit_action Audit action of the requested activation.
	 * @param int    $reparent_to Activation that takes over the active staging children (B-167); zero deactivates them.
	 * @return bool
	 */
	public static function deactivate_while_locked( int $activation_id, string $reason, int $actor_user_id = 0, string $audit_action = 'site_deactivated', int $reparent_to = 0 ): bool {
		global $wpdb;

		$tables = Schema::table_names();
		$activation = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id,license_id,site_url,environment FROM {$tables['activations']} WHERE id = %d AND status = %s LIMIT 1",
				$activation_id,
				'active'
			),
			ARRAY_A
		);
		if ( ! is_array( $activation ) ) {
			return false;
		}

		if ( ! self::compare_and_set( $activation_id ) ) {
			return false;
		}
		AuditLog::record(
			'' === sanitize_key( $audit_action ) ? 'site_deactivated' : $audit_action,
			'activation',
			$activation_id,
			$reason,
			array(
				'status' => 'active',
				'license_id' => (int) $activation['license_id'],
				'environment' => (string) $activation['environment'],
				'site_url' => (string) $activation['site_url'],
			),
			array( 'status' => 'deactivated' ),
			$actor_user_id
		);

		// A staging site is only licensed through its live parent.
		$children = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id,site_url FROM {$tables['activations']} WHERE staging_parent_id = %d AND license_id = %d AND status = %s",
				$activation_id,
				(int) $activation['license_id'],
				'active'
			),
			ARRAY_A
		);
		foreach ( is_array( $children ) ? $children : array() as $child ) {
			if ( $reparent_to > 0 ) {
				// The live site activated again with a new installation ID: its
				// staging copy stays licensed through the new activation.
				$moved = $wpdb->update(
					$tables['activations'],
					array( 'staging_parent_id' => $reparent_to ),
					array( 'id' => (int) $child['id'], 'status' => 'active', 'staging_parent_id' => $activation_id ),
					array( '%d' ),
					array( '%d', '%s', '%d' )
				);
				if ( 1 === $moved ) {
					AuditLog::record(
						'staging_reparented',
						'activation',
						(int) $child['id'],
						'Staging site linked to the new activation of its live site',
						array( 'parent_activation_id' => $activation_id, 'license_id' => (int) $activation['license_id'] ),
						array( 'parent_activation_id' => $reparent_to ),
						$actor_user_id
					);
				}
				continue;
			}
			if ( ! self::compare_and_set( (int) $child['id'] ) ) {
				continue;
			}
			AuditLog::record(
				'site_deactivated',
				'activation',
				(int) $child['id'],
				'Staging site deactivated together with its live site',
				array(
					'status' => 'active',
					'license_id' => (int) $activation['license_id'],
					'environment' => 'staging',
					'site_url' => (string) $child['site_url'],
				),
				array( 'status' => 'deactivated', 'parent_activation_id' => $activation_id ),
				$actor_user_id
			);
		}

		return true;
	}

	/**
	 * Name of the MySQL advisory lock that serializes activation changes of one license.
	 *
	 * @param int $license_id License ID.
	 * @return string
	 */
	public static function lock_name( int $license_id ): string {
		return 'wplc_' . substr( hash( 'sha256', \WPLC\Infrastructure\Database\Schema::lock_scope() . '|license:' . $license_id ), 0, 48 );
	}

	/**
	 * Send the following queries of this request to the primary database (B-24).
	 *
	 * With read/write splitting (HyperDB, LudicrousDB) GET_LOCK() and the
	 * counting SELECTs could otherwise run on different servers, which makes
	 * the site limit bypassable. A no-op for the stock wpdb.
	 *
	 * @return void
	 */
	public static function use_primary_database(): void {
		global $wpdb;

		if ( is_object( $wpdb ) && method_exists( $wpdb, 'send_reads_to_master' ) ) {
			$wpdb->send_reads_to_master();
		}
	}

	/**
	 * Check that the database server grants named locks (B-165).
	 *
	 * Activation, deactivation and renewals depend on GET_LOCK(). When the
	 * server refuses it (proxy in transaction mode, missing privilege), every
	 * activation answers 503 `retry_later`. The diagnostics page and the
	 * activation routine can call this and warn.
	 *
	 * @return bool True when a lock could be taken and released.
	 */
	public static function lock_self_test(): bool {
		global $wpdb;

		self::use_primary_database();
		$lock_name = 'wplc_' . substr( hash( 'sha256', Schema::lock_scope() . '|self-test:' . wp_generate_uuid4() ), 0, 48 );
		$locked = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name ) );
		if ( '1' !== (string) $locked ) {
			return false;
		}
		$released = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );

		return '1' === (string) $released;
	}

	/**
	 * Flip one activation from active to deactivated.
	 *
	 * @param int $activation_id Activation ID.
	 * @return bool True only when this call changed the row.
	 */
	private static function compare_and_set( int $activation_id ): bool {
		global $wpdb;

		$tables = Schema::table_names();
		$now = gmdate( 'Y-m-d H:i:s' );
		$updated = $wpdb->update(
			$tables['activations'],
			array( 'status' => 'deactivated', 'deactivated_at' => $now ),
			array( 'id' => $activation_id, 'status' => 'active' ),
			array( '%s', '%s' ),
			array( '%d', '%s' )
		);

		return 1 === $updated;
	}
}
