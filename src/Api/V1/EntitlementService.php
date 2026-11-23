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
	 * Days a client may keep using a signed snapshot without reaching the server.
	 */
	public const OFFLINE_DAYS = 14;

	/**
	 * Length of the version columns of an activation.
	 */
	private const VERSION_LENGTH = 30;

	/**
	 * Seconds between two writes of `last_seen_at` when nothing else changed (P-50).
	 */
	public const LAST_SEEN_INTERVAL = 3600;

	/**
	 * Check the authenticated installation's current rights.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function check( \WP_REST_Request $request ) {
		global $wpdb;

		$activation = Authenticator::authenticate( $request, true );
		if ( is_wp_error( $activation ) ) {
			return $activation;
		}
		if ( 'active' !== (string) $activation['status'] ) {
			// A released installation gets a signed "deactivated" instead of an
			// anonymous error, so it cannot keep running on its cache (B-70).
			return Response::signed(
				Authenticator::deactivated_data( $activation ),
				(string) $activation['installation_secret'],
				200,
				(string) $activation['request_nonce'],
				(string) $activation['installation_uuid']
			);
		}

		$tables = Schema::table_names();
		$license = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$tables['licenses']} WHERE id = %d LIMIT 1", (int) $activation['license_id'] ),
			ARRAY_A
		);
		if ( ! is_array( $license ) ) {
			return new \WP_Error( 'license_not_found', 'The license record could not be found.', array( 'status' => 404 ) );
		}

		$seen = self::seen_changes( $activation, $request );
		if ( array() !== $seen ) {
			$wpdb->update(
				$tables['activations'],
				$seen,
				array( 'id' => (int) $activation['id'] ),
				array_fill( 0, count( $seen ), '%s' ),
				array( '%d' )
			);
		}

		$data = self::snapshot( $license, $activation );

		// A client may report its current address. A copy of the site on
		// another address (clone, staging copy with the same credentials) is
		// then visible to both sides (A-34); it is not refused until the rule
		// for moving a site is decided (B-164).
		$reported_url = Authenticator::signed_param( $request, 'site_url' );
		if ( is_string( $reported_url ) && '' !== $reported_url ) {
			$reported_key = SiteUrl::comparison_key( $reported_url );
			$data['site_match'] = '' !== $reported_key && SiteUrl::comparison_key( (string) $activation['site_url'] ) === $reported_key;
			if ( ! $data['site_match'] ) {
				/**
				 * Fires when an installation reports another site address than the one it was activated for.
				 *
				 * @param array<string,mixed> $activation   Activation row (without secrets).
				 * @param string              $reported_url Address sent by the client.
				 */
				do_action(
					'wplc/activation_site_mismatch',
					array_diff_key( $activation, array_flip( array( 'installation_secret', 'installation_secret_hash', 'installation_secret_encrypted', 'request_nonce' ) ) ),
					$reported_url
				);
			}
		}

		return Response::signed( $data, (string) $activation['installation_secret'], 200, (string) $activation['request_nonce'], (string) $activation['installation_uuid'] );
	}

	/**
	 * Build the signed entitlement data of one activation.
	 *
	 * Used by the activation response and the entitlement check, so both
	 * always carry the same fields (A-135).
	 *
	 * - `access`: whether the license grants premium rights right now.
	 * - `access_until`: UTC time at which `access` ends if nothing changes
	 *   (end of the paid period, or of the renewal grace period when the
	 *   license renews automatically or is past due); null for a license
	 *   without an end or without access.
	 * - `grace_until`: end of the grace period when one applies, else null.
	 *   The license is inside its grace period when `server_time` is not
	 *   before `paid_until` and `access` is true.
	 * - `cache_expires_at`: until when the client may rely on this snapshot
	 *   without reaching the server: the offline window, but never longer
	 *   than `access_until` (B-68).
	 *
	 * @param array<string,mixed> $license    License row.
	 * @param array<string,mixed> $activation Activation row.
	 * @param int|null            $now        Unix timestamp, defaults to now.
	 * @return array<string,mixed>
	 */
	public static function snapshot( array $license, array $activation, ?int $now = null ): array {
		global $wpdb;

		$now = null === $now ? time() : $now;
		$tables = Schema::table_names();
		$license_id = (int) $license['id'];
		$access = LicenseLifecycle::has_access( $license, $now );

		$reported_status = (string) $license['status'];
		if ( 'active' === $reported_status && ! $access ) {
			$reported_status = 'expired';
		}

		$entitlements = array();
		if ( $access ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT entitlement_key,value_json FROM {$tables['entitlements']} WHERE license_id = %d AND status = %s AND (ends_at IS NULL OR ends_at > %s)",
					$license_id,
					'active',
					LicenseLifecycle::entitlement_cutoff( $license, $now )
				),
				ARRAY_A
			);
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				$value = json_decode( (string) $row['value_json'], true );
				// 1e999 decodes to INF, which cannot be encoded again (B-179).
				if ( self::is_encodable( $value ) ) {
					$entitlements[ (string) $row['entitlement_key'] ] = $value;
				}
			}
		}

		$access_fields = self::access_fields( $license, $now );

		$site_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$tables['activations']} WHERE license_id = %d AND status = %s AND environment = %s",
				$license_id,
				'active',
				'production'
			)
		);

		return array(
			'activation_id' => (int) $activation['id'],
			'installation_id' => (string) $activation['installation_uuid'],
			'activation_status' => 'active',
			'product_key' => (string) $license['product_key'],
			'license_status' => $reported_status,
			'access' => $access_fields['access'],
			'paid_until' => empty( $license['paid_until'] ) ? null : (string) $license['paid_until'],
			'access_until' => $access_fields['access_until'],
			'grace_until' => $access_fields['grace_until'],
			'site_limit' => ActivationService::site_limit( $license_id, $license ),
			'site_count' => $site_count,
			'environment' => (string) $activation['environment'],
			'staging_parent_id' => empty( $activation['staging_parent_id'] ) ? null : (int) $activation['staging_parent_id'],
			'entitlements' => $entitlements,
			'server_time' => gmdate( 'Y-m-d H:i:s', $now ),
			'cache_expires_at' => $access_fields['cache_expires_at'],
		);
	}

	/**
	 * Columns of the activation an entitlement check has to write (P-50).
	 *
	 * Only versions the client reported and that differ from the stored ones;
	 * `last_seen_at` once per LAST_SEEN_INTERVAL, or together with a version
	 * change. An empty result means no write at all.
	 *
	 * @param array<string,mixed> $activation Activation row as read by the authenticator.
	 * @param \WP_REST_Request    $request    Authenticated request.
	 * @param int|null            $now        Unix timestamp, defaults to now.
	 * @return array<string,string>
	 */
	public static function seen_changes( array $activation, \WP_REST_Request $request, ?int $now = null ): array {
		$now = null === $now ? time() : $now;
		$changes = array();
		foreach ( array( 'product_version', 'wp_version', 'php_version' ) as $version_field ) {
			$reported = self::clean_version( Authenticator::signed_param( $request, $version_field ) );
			if ( '' !== $reported && (string) ( $activation[ $version_field ] ?? '' ) !== $reported ) {
				$changes[ $version_field ] = $reported;
			}
		}
		$last_seen = empty( $activation['last_seen_at'] ) ? false : strtotime( (string) $activation['last_seen_at'] . ' UTC' );
		if ( array() !== $changes || false === $last_seen || $now - $last_seen >= self::LAST_SEEN_INTERVAL ) {
			$changes['last_seen_at'] = gmdate( 'Y-m-d H:i:s', $now );
		}

		return $changes;
	}

	/**
	 * Read one of the reported version strings.
	 *
	 * The columns hold 30 characters; anything longer is cut instead of
	 * failing the insert (A-06), and non-string input is ignored (B-175).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param string           $name    Parameter name.
	 * @return string
	 */
	public static function version_param( \WP_REST_Request $request, string $name ): string {
		return self::clean_version( $request->get_param( $name ) );
	}

	/**
	 * Clean one reported version value (see version_param()).
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function clean_version( $value ): string {
		if ( ! is_string( $value ) && ! is_int( $value ) && ! is_float( $value ) ) {
			return '';
		}
		$value = sanitize_text_field( (string) $value );

		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, self::VERSION_LENGTH, 'UTF-8' ) : substr( $value, 0, self::VERSION_LENGTH );
	}

	/**
	 * Whether a decoded entitlement value survives JSON encoding.
	 *
	 * @param mixed $value Decoded value.
	 * @return bool
	 */
	private static function is_encodable( $value ): bool {
		if ( is_float( $value ) ) {
			return is_finite( $value );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( ! self::is_encodable( $item ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Access fields of a signed license answer, derived from LicenseLifecycle::has_access().
	 *
	 * Read by add-on client libraries (LicenseManager unlocks features only when `access` is true);
	 * additive for older clients.
	 *
	 * - `access`: whether the license grants premium rights now.
	 * - `access_until`: end of access (paid period, or its end plus the renewal grace period while renewing).
	 * - `grace_until`: end of the grace period when one applies (the license is renewing or past due), else null.
	 * - `activation_status`: always `active` (answers are only signed for active activations).
	 * - `product_key`: product key of the license.
	 * - `cache_expires_at`: offline window (14 days, filter `wplc_offline_cache_days`), never beyond `access_until`.
	 *
	 * @param array<string,mixed> $license License row.
	 * @param int|null            $now     Unix timestamp, defaults to now.
	 * @return array<string,mixed>
	 */
	public static function access_fields( array $license, ?int $now = null ): array {
		$now        = null === $now ? time() : $now;
		$access     = LicenseLifecycle::has_access( $license, $now );
		$paid_until = empty( $license['paid_until'] ) ? false : strtotime( (string) $license['paid_until'] . ' UTC' );
		$grace_end  = null;
		$access_end = null;
		if ( false !== $paid_until ) {
			// has_access() at the end of the paid period is true exactly when a grace period applies.
			if ( LicenseLifecycle::has_access( $license, $paid_until ) ) {
				$grace_end = $paid_until + LicenseLifecycle::grace_days() * DAY_IN_SECONDS;
			}
			if ( $access ) {
				$access_end = null === $grace_end ? $paid_until : $grace_end;
			}
		}
		// SET-LIC-11: the setting "Offline period of customer sites" (default 14); SET-LIC-21: the period of
		// the plan replaces it; the filter wins.
		$offline_days = \WPLC\Catalog\PlanOverrides::for_license( $license, 'offline_cache_days' ) ?? \WPLC\Settings\Registry::int( 'offline_cache_days' );
		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filter the number of days a client may rely on a signed snapshot offline.
			 *
			 * @param int                 $days    Default: the period of the plan, else the setting "Offline period of customer sites" (14).
			 * @param array<string,mixed> $license License row.
			 */
			$offline_days = max( 1, (int) apply_filters( 'wplc_offline_cache_days', $offline_days, $license ) );
		}
		$cache_expires = $now + $offline_days * DAY_IN_SECONDS;
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
