<?php
/**
 * Entitlement checks for active installations.
 *
 * @package WPLC
 */

namespace WPLC\Api\V1;

use WPLC\Infrastructure\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns the current license and feature rights to an installation.
 */
final class EntitlementService {

	/**
	 * Check the authenticated installation's current rights.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function check( \WP_REST_Request $request ) {
		global $wpdb;

		$activation = Authenticator::authenticate( $request );
		if ( is_wp_error( $activation ) ) {
			return $activation;
		}

		$tables = Schema::table_names();
		$license = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$tables['licenses']} WHERE id = %d LIMIT 1", (int) $activation['license_id'] ),
			ARRAY_A
		);
		if ( ! is_array( $license ) ) {
			return new \WP_Error( 'license_not_found', __( 'The license record could not be found.', 'wp-license' ), array( 'status' => 404 ) );
		}

		$active = 'active' === $license['status']
			&& ( empty( $license['paid_until'] ) || (string) $license['paid_until'] > gmdate( 'Y-m-d H:i:s' ) );
		$reported_status = $active ? (string) $license['status'] : (string) $license['status'];
		if ( 'active' === $reported_status && ! $active ) {
			$reported_status = 'expired';
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT entitlement_key,value_json FROM {$tables['entitlements']} WHERE license_id = %d AND status = %s AND (ends_at IS NULL OR ends_at > %s)",
				(int) $license['id'],
				'active',
				gmdate( 'Y-m-d H:i:s' )
			),
			ARRAY_A
		);

		$entitlements = array();
		if ( $active ) {
			foreach ( $rows as $row ) {
				$entitlements[ $row['entitlement_key'] ] = json_decode( $row['value_json'], true );
			}
		}

		$wpdb->update(
			$tables['activations'],
			array(
				'last_seen_at' => gmdate( 'Y-m-d H:i:s' ),
				'product_version' => sanitize_text_field( (string) $request->get_param( 'product_version' ) ),
				'wp_version' => sanitize_text_field( (string) $request->get_param( 'wp_version' ) ),
				'php_version' => sanitize_text_field( (string) $request->get_param( 'php_version' ) ),
			),
			array( 'id' => (int) $activation['id'] ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		$site_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$tables['activations']} WHERE license_id = %d AND status = %s AND environment = %s",
				(int) $license['id'],
				'active',
				'production'
			)
		);
		$data = array(
			'activation_id' => (int) $activation['id'],
			'installation_id' => (string) $activation['installation_uuid'],
			'license_status' => $reported_status,
			'paid_until' => $license['paid_until'],
			'site_limit' => ActivationService::site_limit( (int) $license['id'], $license ),
			'site_count' => $site_count,
			'environment' => (string) $activation['environment'],
			'entitlements' => $entitlements,
			'server_time' => gmdate( 'Y-m-d H:i:s' ),
			'cache_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 14 * DAY_IN_SECONDS ),
		);

		return Response::signed( $data, (string) $activation['installation_secret'] );
	}
}
