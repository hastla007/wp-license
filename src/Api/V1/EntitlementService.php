<?php
/**
 * Entitlement checks for active installations.
 *
 * @package WPLC
 */

namespace WPLC\Api\V1;

use WPLC\Infrastructure\Database\Schema;
use WPLC\License\LicenseLifecycle;

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

		$active = LicenseLifecycle::has_access( $license );
		$reported_status = (string) $license['status'];
		if ( 'active' === $reported_status && ! $active ) {
			$reported_status = 'expired';
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT entitlement_key,value_json FROM {$tables['entitlements']} WHERE license_id = %d AND status = %s AND (ends_at IS NULL OR ends_at > %s)",
				(int) $license['id'],
				'active',
				LicenseLifecycle::entitlement_cutoff( $license )
			),
			ARRAY_A
		);

		$entitlements = array();
		if ( $active ) {
			foreach ( $rows as $row ) {
				$entitlements[ $row['entitlement_key'] ] = json_decode( $row['value_json'], true );
			}
		}

		// Only overwrite the versions the client actually reported.
		$seen = array( 'last_seen_at' => gmdate( 'Y-m-d H:i:s' ) );
		foreach ( array( 'product_version', 'wp_version', 'php_version' ) as $version_field ) {
			$reported = sanitize_text_field( (string) $request->get_param( $version_field ) );
			if ( '' !== $reported ) {
				$seen[ $version_field ] = substr( $reported, 0, 30 );
			}
		}
		$wpdb->update(
			$tables['activations'],
			$seen,
			array( 'id' => (int) $activation['id'] ),
			array_fill( 0, count( $seen ), '%s' ),
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

		return Response::signed( $data, (string) $activation['installation_secret'], 200, (string) $activation['request_nonce'], (string) $activation['installation_uuid'] );
	}
}
