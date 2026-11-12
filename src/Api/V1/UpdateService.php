<?php
/**
 * Authenticated update metadata and short-lived package downloads.
 *
 * @package WPLC
 */

namespace WPLC\Api\V1;

use WPLC\Infrastructure\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves premium update metadata to activated installations.
 */
final class UpdateService {
	/** @var int Short-lived download grant lifetime. */
	private const TOKEN_TTL = 600;

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
		if ( ! is_array( $license ) || $license['product_key'] !== $product_key || 'active' !== $license['status'] || ( ! empty( $license['paid_until'] ) && $license['paid_until'] <= gmdate( 'Y-m-d H:i:s' ) ) ) {
			return new \WP_Error( 'license_inactive', __( 'An active license for this product is required to access updates.', 'wp-license' ), array( 'status' => 403 ) );
		}

		$packages = apply_filters( 'wplc_update_packages', array(), $product_key );
		$package = is_array( $packages ) ? ( $packages[ $product_key ] ?? null ) : null;
		if ( ! is_array( $package ) || empty( $package['version'] ) || empty( $package['package_url'] ) || empty( $package['sha256'] ) ) {
			return Response::signed( array( 'update_available' => false, 'reason' => 'package_unavailable' ), (string) $activation['installation_secret'] );
		}

		$version = sanitize_text_field( (string) $package['version'] );
		$url = esc_url_raw( (string) $package['package_url'], array( 'https' ) );
		$checksum = strtolower( sanitize_text_field( (string) $package['sha256'] ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $checksum ) || ! self::trusted_package_url( $url ) ) {
			return new \WP_Error( 'package_configuration_invalid', __( 'The update package is not configured with a trusted HTTPS URL and SHA-256 checksum.', 'wp-license' ), array( 'status' => 503 ) );
		}
		if ( ! version_compare( $version, $current, '>' ) ) {
			return Response::signed( array( 'update_available' => false, 'current_version' => $current ), (string) $activation['installation_secret'] );
		}

		$token = bin2hex( random_bytes( 32 ) );
		if ( ! set_transient(
			'wplc_update_dl_' . hash( 'sha256', $token ),
			array( 'url' => $url, 'license_id' => (int) $license['id'], 'expires_at' => time() + self::TOKEN_TTL ),
			self::TOKEN_TTL
		) ) {
			return new \WP_Error( 'download_grant_unavailable', __( 'A secure update download could not be prepared. Retry shortly.', 'wp-license' ), array( 'status' => 503 ) );
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
			'download_expires_at' => gmdate( 'Y-m-d H:i:s', time() + self::TOKEN_TTL ),
		);

		return Response::signed( $payload, (string) $activation['installation_secret'] );
	}

	/**
	 * Consume a one-time grant and redirect to the trusted package host.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return void
	 */
	public static function download( \WP_REST_Request $request ): void {
		$token = strtolower( sanitize_text_field( (string) $request->get_param( 'token' ) ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
			status_header( 404 );
			exit;
		}
		$key = 'wplc_update_dl_' . hash( 'sha256', $token );
		$grant = get_transient( $key );
		delete_transient( $key );
		if ( ! is_array( $grant ) || empty( $grant['url'] ) || empty( $grant['expires_at'] ) || (int) $grant['expires_at'] < time() || ! self::trusted_package_url( (string) $grant['url'] ) ) {
			status_header( 404 );
			exit;
		}
		wp_redirect( (string) $grant['url'], 302, 'WP License Captain' );
		exit;
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

		return 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) )
			&& $port_is_https_default
			&& '' !== $host
			&& in_array( $host, $allowed, true );
	}
}
