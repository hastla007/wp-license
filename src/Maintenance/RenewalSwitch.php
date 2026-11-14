<?php
/**
 * Switches automatic renewal off when its basis is gone.
 *
 * @package WPLC
 */

namespace WPLC\Maintenance;

use WPLC\Infrastructure\Database\Schema;
use WPLC\License\AuditLog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One place for "this license must not be charged automatically any more".
 *
 * Used by the privacy eraser, when the customer account is deleted and when
 * the stored payment method is deleted. The license itself is not changed: it
 * stays usable until the end of the paid period and then expires through the
 * normal rules (the customer can still renew manually).
 */
final class RenewalSwitch {

	/**
	 * Switch automatic renewal off, clear the payment token reference and cancel open renewal rows.
	 *
	 * Compare-and-set: returns false and writes nothing when there is nothing to switch off.
	 *
	 * @param int    $license_id    License ID.
	 * @param string $reason        Audit reason (no personal data).
	 * @param int    $actor_user_id Acting user, 0 for system events.
	 * @return bool True when the license was changed.
	 */
	public static function off( int $license_id, string $reason, int $actor_user_id = 0 ): bool {
		global $wpdb;

		if ( $license_id < 1 ) {
			return false;
		}
		$tables = Schema::table_names();
		$now    = gmdate( 'Y-m-d H:i:s' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- only table names are interpolated.
		$license = $wpdb->get_row( $wpdb->prepare( "SELECT renewal_enabled, payment_token_id FROM {$tables['licenses']} WHERE id = %d", $license_id ), ARRAY_A );
		if ( ! is_array( $license ) || ( empty( $license['renewal_enabled'] ) && empty( $license['payment_token_id'] ) ) ) {
			return false;
		}

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$tables['licenses']} SET renewal_enabled = 0, payment_token_id = 0, next_payment_at = NULL, updated_at = %s WHERE id = %d AND ( renewal_enabled = 1 OR payment_token_id > 0 )",
				$now,
				$license_id
			)
		);
		if ( ! $updated ) {
			return false;
		}

		$cancelled = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$tables['renewals']} SET status = %s, retry_at = NULL, updated_at = %s WHERE license_id = %d AND status IN (%s,%s,%s)",
				'cancelled',
				$now,
				$license_id,
				'scheduled',
				'failed',
				'manual_action_required'
			)
		);
		// phpcs:enable

		AuditLog::record(
			'auto_renewal_disabled',
			'license',
			$license_id,
			$reason,
			array(
				'renewal_enabled'         => (int) $license['renewal_enabled'],
				'payment_method_on_file'  => ! empty( $license['payment_token_id'] ),
			),
			array(
				'renewal_enabled'         => 0,
				'payment_method_on_file'  => false,
				'open_renewals_cancelled' => (int) $cancelled,
			),
			$actor_user_id
		);

		/**
		 * Fires after automatic renewal was switched off because its basis is gone.
		 *
		 * @param int    $license_id License ID.
		 * @param string $reason     Reason.
		 */
		do_action( 'wplc/auto_renewal_disabled', $license_id, $reason );

		return true;
	}
}
