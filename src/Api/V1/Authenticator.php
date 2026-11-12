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

		if ( ! preg_match( '/^[a-f0-9-]{36}$/i', $installation_uuid ) || ! ctype_digit( $timestamp ) || ! preg_match( '/^[a-zA-Z0-9_-]{16,80}$/', $nonce ) ) {
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

		$nonce_key = 'wplc_nonce_' . hash( 'sha256', $installation_uuid . ':' . $nonce );
		if ( get_transient( $nonce_key ) ) {
			return new \WP_Error( 'request_replayed', __( 'This request nonce has already been used.', 'wp-license' ), array( 'status' => 409 ) );
		}

		$body_hash = hash( 'sha256', (string) $request->get_body() );
		$canonical = implode(
			"\n",
			array(
				strtoupper( $request->get_method() ),
				$request->get_route(),
				$timestamp,
				$nonce,
				$body_hash,
			)
		);
		$expected_signature = hash_hmac( 'sha256', $canonical, $secret );

		if ( ! hash_equals( $expected_signature, $provided_signature ) ) {
			return new \WP_Error( 'invalid_request_signature', __( 'Request signature could not be verified.', 'wp-license' ), array( 'status' => 401 ) );
		}

		set_transient( $nonce_key, 1, 10 * MINUTE_IN_SECONDS );

		$rate_key = 'wplc_rate_' . hash( 'sha256', $installation_uuid );
		$counter = (int) get_transient( $rate_key );
		if ( $counter >= 120 ) {
			return new \WP_Error( 'rate_limited', __( 'Too many requests for this installation.', 'wp-license' ), array( 'status' => 429 ) );
		}
		set_transient( $rate_key, $counter + 1, MINUTE_IN_SECONDS );

		$activation['installation_secret'] = $secret;

		return $activation;
	}
}
