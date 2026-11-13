<?php
/**
 * Signed REST response envelope.
 *
 * @package WPLC
 */

namespace WPLC\Api\V1;

use WPLC\Security\ResponseSigner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates consistent response envelopes for license clients.
 */
final class Response {

	/**
	 * Return a signed data response with no-store headers.
	 *
	 * @param array<string,mixed> $data              Payload.
	 * @param string              $secret            Installation secret.
	 * @param int                 $status            HTTP status.
	 * @param string              $nonce             Request nonce to bind into the payload.
	 * @param string              $installation_uuid Installation the data is for (enables the Ed25519 signature).
	 * @return \WP_REST_Response
	 */
	public static function signed( array $data, string $secret, int $status = 200, string $nonce = '', string $installation_uuid = '' ): \WP_REST_Response {
		$response = new \WP_REST_Response( self::envelope( $data, $secret, $nonce, $installation_uuid ), $status );
		// Signed license data and download grants must never be stored by a
		// page cache, CDN, or browser.
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'Expires', 'Wed, 11 Jan 1984 05:00:00 GMT' );
		$response->header( 'Referrer-Policy', 'no-referrer' );

		return $response;
	}

	/**
	 * Build the signed envelope.
	 *
	 * Three signatures with different jobs:
	 * - `signature`: HMAC of the JSON of the data, keyed by the installation
	 *   secret. Kept for clients that predate `payload`.
	 * - `payload` + `payload_signature`: the exact signed string, which also
	 *   carries the request nonce, and its HMAC. Protects transport and binds
	 *   the answer to one request.
	 * - `signature_ed25519` + `key_id`: the server's own signature over
	 *   "wplc-response-v1\n{installation UUID}\n{JSON of data}". The client
	 *   holds the installation secret too, so only this one proves that the
	 *   license server produced the data. Present when a signing key exists.
	 *
	 * @param array<string,mixed> $data              Payload.
	 * @param string              $secret            Installation secret.
	 * @param string              $nonce             Request nonce (installation ID for activations).
	 * @param string              $installation_uuid Installation the data is for.
	 * @return array<string,mixed>
	 */
	public static function envelope( array $data, string $secret, string $nonce = '', string $installation_uuid = '' ): array {
		$json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES );
		$json = false === $json ? '' : $json;
		$payload = wp_json_encode( array( 'data' => $data, 'nonce' => $nonce ), JSON_UNESCAPED_SLASHES );
		$payload = false === $payload ? '' : $payload;

		$envelope = array(
			'data' => $data,
			'signature' => hash_hmac( 'sha256', $json, $secret ),
			'payload' => $payload,
			'payload_signature' => hash_hmac( 'sha256', $payload, $secret ),
		);

		return '' === $installation_uuid ? $envelope : array_merge( $envelope, ResponseSigner::envelope_fields( $installation_uuid, $json ) );
	}
}
