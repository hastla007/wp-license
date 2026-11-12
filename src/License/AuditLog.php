<?php
/**
 * Append-only audit log for license actions.
 *
 * @package WPLC
 */

namespace WPLC\License;

use WPLC\Infrastructure\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores safe summaries of license state transitions.
 */
final class AuditLog {

	/**
	 * Record an audit event without secrets or payment data.
	 *
	 * @param string $action Action identifier.
	 * @param string $object_type Object type.
	 * @param int    $object_id Object ID.
	 * @param string $reason Human-readable reason.
	 * @param mixed  $before Previous state summary.
	 * @param mixed  $after New state summary.
	 * @param int    $actor_user_id User ID, or zero for system events.
	 * @return void
	 */
	public static function record(
		string $action,
		string $object_type,
		int $object_id,
		string $reason = '',
		$before = null,
		$after = null,
		int $actor_user_id = 0
	): void {
		global $wpdb;

		$tables = Schema::table_names();
		$wpdb->insert(
			$tables['audit_log'],
			array(
				'actor_user_id' => max( 0, $actor_user_id ),
				'action' => sanitize_key( $action ),
				'object_type' => sanitize_key( $object_type ),
				'object_id' => max( 0, $object_id ),
				'reason' => sanitize_textarea_field( $reason ),
				'before_json' => self::encode_summary( $before ),
				'after_json' => self::encode_summary( $after ),
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Encode a small state summary.
	 *
	 * @param mixed $value Summary value.
	 * @return string|null
	 */
	private static function encode_summary( $value ): ?string {
		if ( null === $value ) {
			return null;
		}

		$json = wp_json_encode( $value );
		return false === $json ? null : $json;
	}
}
