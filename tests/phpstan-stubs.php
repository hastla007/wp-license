<?php
/**
 * Declarations of third-party classes PHPStan cannot find in the installed stubs.
 *
 * Only loaded by PHPStan (phpstan.neon.dist, scanFiles); never by the plugin or PHPUnit.
 *
 * @package WPLC
 */

/**
 * API wrapper of the WooCommerce Stripe Gateway (woocommerce-gateway-stripe), as far as the plugin calls it.
 */
class WC_Stripe_API {

	/**
	 * Send a request to the Stripe API.
	 *
	 * @param array<string,mixed> $request Request body.
	 * @param string              $api     Path below the API root.
	 * @param string              $method  HTTP method.
	 * @param bool                $with_headers Whether to return the headers as well.
	 * @return object|array<string,mixed>|\WP_Error
	 */
	public static function request( $request, $api = 'charges', $method = 'POST', $with_headers = false ) {
		return (object) array();
	}

	/**
	 * GET a resource of the Stripe API.
	 *
	 * @param string $api Path below the API root.
	 * @return object|array<string,mixed>|\WP_Error
	 */
	public static function retrieve( $api ) {
		return (object) array();
	}
}
