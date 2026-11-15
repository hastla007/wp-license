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
	 * @param string              $installation_uuid Installation the data is for (enables the Ed25519 signatures).
	 * @return \WP_REST_Response
	 */
	public static function signed( array $data, string $secret, int $status = 200, string $nonce = '', string $installation_uuid = '' ): \WP_REST_Response {
		$envelope = self::envelope( $data, $secret, $nonce, $installation_uuid );
		if ( '' === $envelope['payload'] ) {
			// Data that cannot be encoded (INF, NAN, invalid UTF-8) must never
			// be answered with a signature over an empty string (B-179).
			$response = new \WP_REST_Response(
				array(
					'code' => 'response_encoding_failed',
					'message' => 'The response could not be encoded.',
					'data' => array( 'status' => 500 ),
				),
				500
			);
		} else {
			$response = new \WP_REST_Response( $envelope, $status );
		}
		self::no_store( $response );

		return $response;
	}

	/**
	 * Forbid storing a response in a page cache, CDN, or browser.
	 *
	 * Applied to every `wplc/v1` response, including errors (B-109).
	 *
	 * @param \WP_REST_Response $response Response.
	 * @return void
	 */
	public static function no_store( \WP_REST_Response $response ): void {
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'Expires', 'Wed, 11 Jan 1984 05:00:00 GMT' );
		$response->header( 'Referrer-Policy', 'no-referrer' );
	}

	/**
	 * Build the signed envelope.
	 *
	 * Signatures with different jobs:
	 * - `payload` + `payload_signature`: the exact signed string, which also
	 *   carries the request nonce, and its HMAC keyed by the installation
	 *   secret. Protects transport and binds the answer to one request.
	 * - `payload_signature_ed25519` + `key_id`: the server's own signature over
	 *   "wplc-response-v2\n{installation UUID}\n{payload}". The client holds
	 *   the installation secret too, so only this one proves that the license
	 *   server produced exactly this answer to this request (A-30).
	 * - `signature` (HMAC of the JSON of the data) and `signature_ed25519`
	 *   (server signature over "wplc-response-v1\n{installation UUID}\n{JSON
	 *   of data}"): legacy fields without the nonce. They are still sent for
	 *   client copies that predate `payload`; new clients must not accept
	 *   them in place of the payload signatures (B-158).
	 * The Ed25519 fields are present when a signing key exists.
	 *
	 * When the data cannot be JSON-encoded, `payload` is an empty string and
	 * signed() answers with an error instead.
	 *
	 * @param array<string,mixed> $data              Payload.
	 * @param string              $secret            Installation secret.
	 * @param string              $nonce             Request nonce (installation ID for activations).
	 * @param string              $installation_uuid Installation the data is for.
	 * @return array<string,mixed>
	 */
	public static function envelope( array $data, string $secret, string $nonce = '', string $installation_uuid = '' ): array {
		$json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES );
		$payload = false === $json ? false : wp_json_encode( array( 'data' => $data, 'nonce' => $nonce ), JSON_UNESCAPED_SLASHES );
		if ( false === $json || false === $payload ) {
			return array(
				'data' => array(),
				'signature' => '',
				'payload' => '',
				'payload_signature' => '',
			);
		}

		$envelope = array(
			'data' => $data,
			'signature' => hash_hmac( 'sha256', $json, $secret ),
			'payload' => $payload,
			'payload_signature' => hash_hmac( 'sha256', $payload, $secret ),
		);

		return '' === $installation_uuid ? $envelope : array_merge( $envelope, ResponseSigner::envelope_fields( $installation_uuid, $json, $payload ) );
	}
}
