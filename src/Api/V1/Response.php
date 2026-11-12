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
	 * The client signs the canonical JSON representation of the data with its
	 * per-installation secret; this is tamper evidence for the local cache.
	 *
	 * @param array<string,mixed> $data Payload.
	 * @param string $secret Installation secret.
	 * @param int $status HTTP status.
	 * @return \WP_REST_Response
	 */
	public static function signed( array $data, string $secret, int $status = 200 ): \WP_REST_Response {
		$json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES );
		$signature = hash_hmac( 'sha256', false === $json ? '' : $json, $secret );

		return new \WP_REST_Response(
			array(
				'data' => $data,
				'signature' => $signature,
			),
			$status
		);
	}
}
