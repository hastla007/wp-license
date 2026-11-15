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
		) + self::access_fields( $license );

		return Response::signed( $data, (string) $activation['installation_secret'], 200, (string) $activation['request_nonce'], (string) $activation['installation_uuid'] );
	}

	/**
	 * Access fields of a signed license answer, derived from LicenseLifecycle::has_access().
	 *
	 * Read by the client library of hastla007/captain-pro (LicenseManager unlocks features only when `access` is
	 * true); additive for the client of this repository.
	 *
	 * - `access`: whether the license grants premium rights now.
	 * - `access_until`: end of access (paid period, or its end plus the renewal grace period while renewing).
	 * - `grace_until`: end of the grace period while a renewal is pending or failing, else null.
	 * - `activation_status`: always `active` (answers are only signed for active activations).
	 * - `product_key`: product key of the license.
	 * - `cache_expires_at`: offline window of 14 days, never beyond `access_until`.
	 *
	 * @param array<string,mixed> $license License row.
	 * @param int|null            $now     Unix timestamp, defaults to now.
	 * @return array<string,mixed>
	 */
	public static function access_fields( array $license, ?int $now = null ): array {
		$now        = null === $now ? time() : $now;
		$access     = LicenseLifecycle::has_access( $license, $now );
		$paid_until = empty( $license['paid_until'] ) ? false : strtotime( (string) $license['paid_until'] . ' UTC' );
		$renewing   = 'past_due' === (string) ( $license['status'] ?? '' )
			|| ( ! empty( $license['renewal_enabled'] ) && empty( $license['cancel_at_period_end'] ) );
		$grace_end  = false !== $paid_until && $renewing ? $paid_until + LicenseLifecycle::GRACE_DAYS * DAY_IN_SECONDS : null;
		$access_end = null;
		if ( $access && false !== $paid_until ) {
			$access_end = null !== $grace_end ? $grace_end : $paid_until;
		}
		$cache_expires = $now + 14 * DAY_IN_SECONDS;
		if ( null !== $access_end ) {
			$cache_expires = min( $cache_expires, $access_end );
		}
		return array(
			'access'            => $access,
			'access_until'      => null === $access_end ? null : gmdate( 'Y-m-d H:i:s', $access_end ),
			'grace_until'       => null === $grace_end ? null : gmdate( 'Y-m-d H:i:s', $grace_end ),
			'activation_status' => 'active',
			'product_key'       => (string) ( $license['product_key'] ?? '' ),
			'cache_expires_at'  => gmdate( 'Y-m-d H:i:s', $cache_expires ),
		);
	}
}
