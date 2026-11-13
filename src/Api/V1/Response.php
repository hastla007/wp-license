<?php
/**
 * Signed REST response envelope.
 *
 * @package WPLC
 */

namespace WPLC\Api\V1;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates consistent response envelopes for license clients.
 */
final class Response {

	/**
	 * Return a signed data response.
	 *
	 * @param array<string,mixed> $data Payload.
	 * @param string $secret Installation secret.
	 * @param int $status HTTP status.
	 * @param string $nonce Value bound into the signed payload: the request's
	 *                      X-WPLC-Nonce for authenticated calls, the installation
	 *                      ID for activation.
	 * @return \WP_REST_Response
	 */
	public static function signed( array $data, string $secret, int $status = 200, string $nonce = '' ): \WP_REST_Response {
		$response = new \WP_REST_Response( self::envelope( $data, $secret, $nonce ), $status );
		// Signed license data and download grants must never be stored by a
		// page cache, CDN, or browser.
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'Expires', 'Wed, 11 Jan 1984 05:00:00 GMT' );
		$response->header( 'Referrer-Policy', 'no-referrer' );

		return $response;
	}

	/**
	 * Build the response envelope.
	 *
	 * `data` and `signature` are the original v1 fields: the signature covers
	 * the server's JSON encoding of `data`, which a client can only check by
	 * re-encoding. `payload` is the exact signed JSON string
	 * (`{"data":…,"nonce":…}`) and `payload_signature` its HMAC, so current
	 * clients verify the transmitted bytes and the echoed nonce instead.
	 *
	 * @param array<string,mixed> $data Payload.
	 * @param string $secret Installation secret.
	 * @param string $nonce Request nonce or installation ID.
	 * @return array<string,mixed>
	 */
	public static function envelope( array $data, string $secret, string $nonce = '' ): array {
		$json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES );
		$payload = wp_json_encode( array( 'data' => $data, 'nonce' => $nonce ), JSON_UNESCAPED_SLASHES );
		$payload = false === $payload ? '' : $payload;

		return array(
			'data' => $data,
			'signature' => hash_hmac( 'sha256', false === $json ? '' : $json, $secret ),
			'payload' => $payload,
			'payload_signature' => hash_hmac( 'sha256', $payload, $secret ),
		);
	}
}
