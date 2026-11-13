<?php
/**
 * Public read-only list of the response signing keys.
 *
 * @package WPLC
 */

namespace WPLC\Api\V1;

use WPLC\Security\ResponseSigner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GET /wplc/v1/signing-keys: the Ed25519 public keys (current and, during a rotation, next) with their key ids.
 *
 * Public keys are not secret: product release pipelines compare the keys they pin against this list before they
 * publish a build (a wrong pinned key would lock every customer out). The route never creates a key.
 */
final class SigningKeys {
	/**
	 * REST callback.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get(): \WP_REST_Response {
		$response = new \WP_REST_Response( ResponseSigner::public_keys(), 200 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
