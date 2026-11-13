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
	 * Return a signed data response.
	 *
	 * `signature` is HMAC-SHA-256 of the canonical JSON of the data keyed by the per-installation secret (integrity
	 * in transit and of the local cache). `signature_ed25519` is the server's Ed25519 signature of
	 * "wplc-response-v1\n{installation UUID}\n{JSON}" (ResponseSigner); clients that pin the server's public key
	 * accept only data this server signed for exactly this installation. `key_id` names the signing key.
	 *
	 * @param array<string,mixed> $data Payload.
	 * @param string $secret Installation secret.
	 * @param int $status HTTP status.
	 * @param string $installation_uuid Installation UUID the data is for.
	 * @return \WP_REST_Response
	 */
	public static function signed( array $data, string $secret, int $status = 200, string $installation_uuid = '' ): \WP_REST_Response {
		$json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES );
		$json = false === $json ? '' : $json;
		$signature = hash_hmac( 'sha256', $json, $secret );

		return new \WP_REST_Response(
			array_merge(
				array(
					'data' => $data,
					'signature' => $signature,
				),
				ResponseSigner::envelope_fields( $installation_uuid, $json )
			),
			$status
		);
	}
}
