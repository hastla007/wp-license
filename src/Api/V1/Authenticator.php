<?php
/**
 * HMAC request authentication and replay protection.
 *
 * @package WPLC
 */

namespace WPLC\Api\V1;

use WPLC\Infrastructure\Database\Schema;
use WPLC\Security\SecretCrypto;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates signed requests from activated plugin installations.
 */
final class Authenticator {

	/**
	 * Authenticate request headers and return the active activation row.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function authenticate( \WP_REST_Request $request ) {
		global $wpdb;

		$installation_uuid = sanitize_text_field( (string) $request->get_header( 'x-wplc-installation-id' ) );
		$timestamp = sanitize_text_field( (string) $request->get_header( 'x-wplc-timestamp' ) );
		$nonce = sanitize_text_field( (string) $request->get_header( 'x-wplc-nonce' ) );
		$provided_signature = strtolower( sanitize_text_field( (string) $request->get_header( 'x-wplc-signature' ) ) );

		if ( ! preg_match( '/^[a-f0-9-]{36}$/i', $installation_uuid ) || ! ctype_digit( $timestamp ) || ! preg_match( '/^[a-zA-Z0-9_-]{16,80}$/', $nonce ) || ! preg_match( '/^[a-f0-9]{64}$/', $provided_signature ) ) {
			return new \WP_Error( 'invalid_request_signature', __( 'Request authentication headers are invalid.', 'wp-license' ), array( 'status' => 401 ) );
		}

		if ( abs( time() - (int) $timestamp ) > 300 ) {
			return new \WP_Error( 'request_expired', __( 'Request timestamp is outside the allowed window.', 'wp-license' ), array( 'status' => 401 ) );
		}

		$tables = Schema::table_names();
		$activation = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$tables['activations']} WHERE installation_uuid = %s AND status = %s LIMIT 1",
				$installation_uuid,
				'active'
			),
			ARRAY_A
		);

		if ( ! is_array( $activation ) ) {
			return new \WP_Error( 'activation_not_found', __( 'The installation is not active.', 'wp-license' ), array( 'status' => 401 ) );
		}

		$secret = SecretCrypto::decrypt( (string) $activation['installation_secret_encrypted'] );
		if ( false === $secret || ! hash_equals( (string) $activation['installation_secret_hash'], hash( 'sha256', $secret ) ) ) {
			return new \WP_Error( 'installation_secret_invalid', __( 'The installation credential is invalid.', 'wp-license' ), array( 'status' => 401 ) );
		}

		$expected_signature = self::signature_for(
			$secret,
			$request->get_method(),
			$request->get_route(),
			$timestamp,
			$nonce,
			$request->get_body()
		);
		if ( ! hash_equals( $expected_signature, $provided_signature ) ) {
			return new \WP_Error( 'invalid_request_signature', __( 'Request signature is invalid.', 'wp-license' ), array( 'status' => 401 ) );
		}

		$nonce_hash = hash( 'sha256', $nonce );
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$tables['request_nonces']} WHERE activation_id = %d AND nonce_hash = %s AND expires_at <= %s",
				(int) $activation['id'],
				$nonce_hash,
				$now
			)
		);
		$nonce_inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$tables['request_nonces']} (activation_id,nonce_hash,expires_at,created_at) VALUES (%d,%s,%s,%s)",
				(int) $activation['id'],
				$nonce_hash,
				gmdate( 'Y-m-d H:i:s', time() + 10 * MINUTE_IN_SECONDS ),
				$now
			)
		);

		if ( false === $nonce_inserted ) {
			return new \WP_Error( 'replay_store_unavailable', __( 'Request replay protection is temporarily unavailable.', 'wp-license' ), array( 'status' => 503 ) );
		}
		if ( 0 === (int) $nonce_inserted ) {
			return new \WP_Error( 'request_replayed', __( 'This request nonce has already been used.', 'wp-license' ), array( 'status' => 409 ) );
		}

		$rate_hits = self::increment_rate_limit( hash( 'sha256', 'installation:' . $installation_uuid ) );
		if ( false === $rate_hits ) {
			return new \WP_Error( 'rate_limit_store_unavailable', __( 'Request rate limiting is temporarily unavailable.', 'wp-license' ), array( 'status' => 503 ) );
		}
		if ( $rate_hits > 120 ) {
			return new \WP_Error( 'rate_limited', __( 'Too many requests for this installation.', 'wp-license' ), array( 'status' => 429 ) );
		}

		if ( SecretCrypto::needs_rotation( (string) $activation['installation_secret_encrypted'] ) ) {
			$rotated = SecretCrypto::encrypt( $secret );
			if ( false !== $rotated ) {
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$tables['activations']} SET installation_secret_encrypted = %s WHERE id = %d AND installation_secret_encrypted = %s",
						$rotated,
						(int) $activation['id'],
						(string) $activation['installation_secret_encrypted']
					)
				);
			}
		}

		$activation['installation_secret'] = $secret;
		// Echoed inside the signed response payload so a recorded response
		// cannot be replayed against a later request.
		$activation['request_nonce'] = $nonce;

		return $activation;
	}

	/**
	 * Build the documented request signature for a raw REST request.
	 *
	 * @param string $secret Installation secret.
	 * @param string $method HTTP method.
	 * @param string $route WordPress REST route.
	 * @param string $timestamp Unix timestamp.
	 * @param string $nonce Request nonce.
	 * @param string $body Raw request body.
	 * @return string Lowercase hexadecimal HMAC-SHA-256.
	 */
	public static function signature_for( string $secret, string $method, string $route, string $timestamp, string $nonce, string $body ): string {
		$canonical = strtoupper( $method ) . "\n" . $route . "\n" . $timestamp . "\n" . $nonce . "\n" . hash( 'sha256', $body );

		return hash_hmac( 'sha256', $canonical, $secret );
	}

	/**
	 * Atomically increment a per-minute request bucket.
	 *
	 * @param string $limiter_hash SHA-256 key for the installation or IP.
	 * @return int|false Current hit count, or false on database failure.
	 */
	public static function increment_rate_limit( string $limiter_hash ) {
		global $wpdb;

		$tables = Schema::table_names();
		$bucket = gmdate( 'Y-m-d H:i:00' );
		$now = gmdate( 'Y-m-d H:i:s' );
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$tables['request_limits']} (limiter_hash,bucket_start,hits,created_at) VALUES (%s,%s,1,%s) ON DUPLICATE KEY UPDATE hits = hits + 1",
				$limiter_hash,
				$bucket,
				$now
			)
		);
		if ( false === $result ) {
			return false;
		}

		$hits = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT hits FROM {$tables['request_limits']} WHERE limiter_hash = %s AND bucket_start = %s LIMIT 1",
				$limiter_hash,
				$bucket
			)
		);

		return is_numeric( $hits ) ? (int) $hits : false;
	}

	/**
	 * Read a per-minute request bucket without counting.
	 *
	 * @param string $limiter_hash SHA-256 limiter key.
	 * @return int|false Current hit count (0 without a row), or false on database failure.
	 */
	public static function current_rate_limit( string $limiter_hash ) {
		global $wpdb;

		$tables = Schema::table_names();
		$hits   = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT hits FROM {$tables['request_limits']} WHERE limiter_hash = %s AND bucket_start = %s LIMIT 1",
				$limiter_hash,
				gmdate( 'Y-m-d H:i:00' )
			)
		);
		if ( null === $hits ) {
			return empty( $wpdb->last_error ) ? 0 : false;
		}

		return is_numeric( $hits ) ? (int) $hits : false;
	}

	/**
	 * Remove expired replay and rate-limit records in bounded batches.
	 *
	 * @return void
	 */
	public static function cleanup_security_rows(): void {
		global $wpdb;

		$tables = Schema::table_names();
		$now = gmdate( 'Y-m-d H:i:s' );
		$rate_cutoff = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$tables['request_nonces']} WHERE expires_at <= %s LIMIT 1000", $now ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$tables['request_limits']} WHERE bucket_start < %s LIMIT 1000", $rate_cutoff ) );
	}
}
