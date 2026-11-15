<?php
/**
 * Authenticated update metadata and short-lived package downloads.
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
 * Serves premium update metadata to activated installations.
 *
 * The WordPress updater downloads `package` with a plain GET and no custom headers, so the grant has to be in the
 * URL and reaches access logs and proxies (T-108). The URL therefore carries only a random, single-use value that
 * is stored as a SHA-256 hash and has no relation to the license key or the installation secret. It is valid for
 * five minutes, bound to the installation that asked for it (it stops working when that activation ends) and used
 * up by the first request that presents it, whatever that request's outcome: a value seen in a log is useless.
 */
final class UpdateService {
	/** @var int Short-lived download grant lifetime. Clients ask for a fresh grant right before downloading. */
	public const TOKEN_TTL = 300;

	/**
	 * Return the current update for one active product installation.
	 *
	 * @param \WP_REST_Request $request REST request.
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
		$product_key = sanitize_key( (string) $request->get_param( 'product_key' ) );
		$current = sanitize_text_field( (string) $request->get_param( 'current_version' ) );
		if ( ! is_array( $license ) || $license['product_key'] !== $product_key || ! LicenseLifecycle::has_access( $license ) ) {
			return new \WP_Error( 'license_inactive', __( 'An active license for this product is required to access updates.', 'wp-license' ), array( 'status' => 403 ) );
		}

		$packages = apply_filters( 'wplc_update_packages', array(), $product_key );
		$package = is_array( $packages ) ? ( $packages[ $product_key ] ?? null ) : null;
		if ( ! is_array( $package ) || empty( $package['version'] ) || empty( $package['package_url'] ) || empty( $package['sha256'] ) ) {
			return Response::signed( array( 'update_available' => false, 'reason' => 'package_unavailable' ), (string) $activation['installation_secret'], 200, (string) $activation['request_nonce'], (string) $activation['installation_uuid'] );
		}

		$version = sanitize_text_field( (string) $package['version'] );
		$url = esc_url_raw( (string) $package['package_url'], array( 'https' ) );
		$checksum = strtolower( sanitize_text_field( (string) $package['sha256'] ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $checksum ) || ! self::trusted_package_url( $url ) ) {
			return new \WP_Error( 'package_configuration_invalid', __( 'The update package is not configured with a trusted HTTPS URL and SHA-256 checksum.', 'wp-license' ), array( 'status' => 503 ) );
		}
		if ( ! version_compare( $version, $current, '>' ) ) {
			return Response::signed( array( 'update_available' => false, 'current_version' => $current ), (string) $activation['installation_secret'], 200, (string) $activation['request_nonce'], (string) $activation['installation_uuid'] );
		}

		$token = bin2hex( random_bytes( 32 ) );
		$token_hash = hash( 'sha256', $token );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + self::TOKEN_TTL );
		$now = gmdate( 'Y-m-d H:i:s' );
		$inserted = $wpdb->insert(
			$tables['download_tokens'],
			array(
				'license_id' => (int) $license['id'],
				'activation_id' => (int) $activation['id'],
				'token_hash' => $token_hash,
				'package_url' => $url,
				'expires_at' => $expires_at,
				'consumed_at' => null,
				'created_at' => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( false === $inserted ) {
			return new \WP_Error( 'download_token_error', __( 'A secure package download could not be prepared.', 'wp-license' ), array( 'status' => 503 ) );
		}
		$payload = array(
			'update_available' => true,
			'product_key' => $product_key,
			'version' => $version,
			'package_url' => rest_url( 'wplc/v1/updates/download/' . $token ),
			'sha256' => $checksum,
			'requires_php' => sanitize_text_field( (string) ( $package['requires_php'] ?? '' ) ),
			'requires_wp' => sanitize_text_field( (string) ( $package['requires_wp'] ?? '' ) ),
			'tested_wp' => sanitize_text_field( (string) ( $package['tested_wp'] ?? '' ) ),
			'release_notes' => wp_kses_post( (string) ( $package['release_notes'] ?? '' ) ),
			'download_expires_at' => $expires_at,
		);

		return Response::signed( $payload, (string) $activation['installation_secret'], 200, (string) $activation['request_nonce'], (string) $activation['installation_uuid'] );
	}

	/**
	 * Consume a one-time grant and redirect to the trusted package host.
	 *
	 * The grant is used up before anything else is checked (T-108). A request that fails afterwards (license without
	 * access, installation deactivated, storage hook returning a URL that is not allowed) has still consumed it; the
	 * client asks for a fresh grant with a new update check.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return void
	 */
	public static function download( \WP_REST_Request $request ): void {
		global $wpdb;
		// The one-time token is part of the URL: keep the response out of
		// caches and the URL out of any Referer header.
		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private' );
			header( 'Referrer-Policy: no-referrer' );
		}
		$token = strtolower( sanitize_text_field( (string) $request->get_param( 'token' ) ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
			status_header( 404 );
			exit;
		}
		$tables = Schema::table_names();
		$token_hash = hash( 'sha256', $token );
		$now = gmdate( 'Y-m-d H:i:s' );
		$grant = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT dt.package_url, dt.license_id, dt.activation_id, l.status, l.paid_until, l.renewal_enabled, l.cancel_at_period_end FROM {$tables['download_tokens']} dt INNER JOIN {$tables['licenses']} l ON l.id = dt.license_id WHERE dt.token_hash = %s AND dt.consumed_at IS NULL AND dt.expires_at > %s LIMIT 1",
				$token_hash,
				$now
			),
			ARRAY_A
		);
		if ( ! is_array( $grant ) ) {
			status_header( 404 );
			exit;
		}
		// T-108: single use on first presentation. Only one concurrent request can flip consumed_at, and it does so
		// before any other check, so the value in the URL never works a second time, also not after a failure.
		$consumed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$tables['download_tokens']} SET consumed_at = %s, package_url = '' WHERE token_hash = %s AND consumed_at IS NULL AND expires_at > %s",
				$now,
				$token_hash,
				$now
			)
		);
		if ( 1 !== (int) $consumed
			|| ! LicenseLifecycle::has_access( $grant )
			|| ! self::activation_is_active( (int) $grant['activation_id'], (int) $grant['license_id'] )
			|| ! self::trusted_package_url( (string) $grant['package_url'] )
		) {
			status_header( 404 );
			exit;
		}
		$download_url = apply_filters(
			'wplc_resolve_update_package_url',
			(string) $grant['package_url'],
			(int) $grant['license_id']
		);
		if ( ! is_string( $download_url ) || ! self::trusted_package_url( $download_url ) ) {
			status_header( 404 );
			exit;
		}
		wp_redirect( $download_url, 302, 'WP License Captain' );
		exit;
	}

	/**
	 * Whether the installation a grant was issued to is still active for that license.
	 *
	 * Grants issued before schema 16 carry no activation (0) and are only bound to the license.
	 *
	 * @param int $activation_id Activation ID from the grant.
	 * @param int $license_id    License ID from the grant.
	 * @return bool
	 */
	private static function activation_is_active( int $activation_id, int $license_id ): bool {
		global $wpdb;

		if ( 0 === $activation_id ) {
			return true;
		}
		$tables = Schema::table_names();

		return 1 === (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$tables['activations']} WHERE id = %d AND license_id = %d AND status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names(), values prepared.
				$activation_id,
				$license_id,
				'active'
			)
		);
	}

	/**
	 * Purge expired grants after a configurable retention period.
	 *
	 * @return void
	 */
	public static function purge_expired_grants(): void {
		global $wpdb;

		$days = absint( apply_filters( 'wplc_download_grant_retention_days', 7 ) );
		$days = max( 1, min( 3650, $days ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$tables = Schema::table_names();
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$tables['download_tokens']} WHERE COALESCE(consumed_at, expires_at) < %s",
				$cutoff
			)
		);
	}

	/**
	 * Check package URL against an explicit host allow-list.
	 *
	 * @param string $url Package URL.
	 * @return bool
	 */
	private static function trusted_package_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		$host = strtolower( (string) ( $parts['host'] ?? '' ) );
		$allowed_hosts = apply_filters( 'wplc_update_package_hosts', array( wp_parse_url( home_url(), PHP_URL_HOST ) ) );
		$allowed = array();
		if ( is_array( $allowed_hosts ) ) {
			foreach ( $allowed_hosts as $allowed_host ) {
				if ( is_string( $allowed_host ) && '' !== trim( $allowed_host ) ) {
					$allowed[] = strtolower( trim( $allowed_host ) );
				}
			}
		}
		$port_is_https_default = ! isset( $parts['port'] ) || 443 === (int) $parts['port'];
		$has_no_embedded_credentials = ! isset( $parts['user'] ) && ! isset( $parts['pass'] );
		$has_no_fragment = ! isset( $parts['fragment'] );

		return 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) )
			&& $port_is_https_default
			&& $has_no_embedded_credentials
			&& $has_no_fragment
			&& '' !== $host
			&& in_array( $host, $allowed, true );
	}
}
