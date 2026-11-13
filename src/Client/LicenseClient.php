<?php
/**
 * Reusable client for products managed by WP License Captain.
 *
 * @package WPLC
 */

namespace WPLC\Client;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles activation, authenticated entitlement checks, and premium updates.
 *
 * Product plugins should instantiate this client with a product-specific option
 * key and should gate paid features from the signed entitlement payload.
 */
final class LicenseClient {
	/** @var string */
	private $server_url;
	/** @var string */
	private $product_key;
	/** @var string */
	private $product_version;
	/** @var string */
	private $option_key;

	/**
	 * @param string $server_url WP License Captain site URL.
	 * @param string $product_key Stable product identifier.
	 * @param string $product_version Installed product version.
	 * @param string $option_key Product-specific option key.
	 */
	public function __construct( string $server_url, string $product_key, string $product_version, string $option_key ) {
		$server_url = untrailingslashit( esc_url_raw( $server_url, array( 'https' ) ) );
		$server_parts = wp_parse_url( $server_url );
		$this->server_url = (
			is_array( $server_parts )
			&& 'https' === strtolower( (string) ( $server_parts['scheme'] ?? '' ) )
			&& ! empty( $server_parts['host'] )
			&& ! isset( $server_parts['user'] )
			&& ! isset( $server_parts['pass'] )
			&& ! isset( $server_parts['query'] )
			&& ! isset( $server_parts['fragment'] )
		) ? $server_url : '';
		$this->product_key = sanitize_key( $product_key );
		$this->product_version = sanitize_text_field( $product_version );
		$this->option_key = sanitize_key( $option_key );
	}

	/**
	 * Activate this site and persist only its installation credential.
	 *
	 * @param string $license_key Customer-provided key.
	 * @param string $environment production, staging, or development.
	 * @param string $staging_parent_installation_id Optional production installation UUID.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function activate( string $license_key, string $environment = 'production', string $staging_parent_installation_id = '' ) {
		if ( '' === $this->server_url || '' === $this->product_key || '' === trim( $license_key ) ) {
			return new \WP_Error( 'wplc_configuration_invalid', __( 'License server settings or license key are missing.', 'wp-license' ) );
		}
		$installation_id = wp_generate_uuid4();
		$secret = bin2hex( random_bytes( 32 ) );
		$payload = array(
			'license_key' => strtoupper( trim( sanitize_text_field( $license_key ) ) ),
			'product_key' => $this->product_key,
			'installation_id' => $installation_id,
			'installation_secret' => $secret,
			'site_url' => home_url(),
			'environment' => in_array( $environment, array( 'production', 'staging', 'development' ), true ) ? $environment : 'production',
			'staging_parent_installation_id' => sanitize_text_field( $staging_parent_installation_id ),
			'product_version' => $this->product_version,
			'wp_version' => get_bloginfo( 'version' ),
			'php_version' => PHP_VERSION,
		);
		$response = $this->request( 'POST', '/wplc/v1/activations', $payload, $secret, false );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$verified = self::verify_envelope( $response, $secret );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}
		$saved = update_option( $this->option_key, array( 'installation_id' => $installation_id, 'installation_secret' => $secret, 'activation_id' => absint( $verified['activation_id'] ?? 0 ) ), false );
		if ( ! $saved ) {
			return new \WP_Error( 'wplc_credentials_not_saved', __( 'The activation succeeded but its credentials could not be stored locally. Retry after checking database storage.', 'wp-license' ) );
		}

		return $verified;
	}

	/**
	 * Fetch current signed feature rights.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function check_entitlements() {
		return $this->authenticated_post( '/wplc/v1/entitlements/check', array( 'product_version' => $this->product_version ) );
	}

	/**
	 * Check for a product update and receive a short-lived package URL.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function check_update() {
		return $this->authenticated_post(
			'/wplc/v1/updates/check',
			array( 'product_key' => $this->product_key, 'current_version' => $this->product_version )
		);
	}

	/**
	 * Deactivate this site and remove the local credential after confirmation.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function deactivate() {
		$credentials = $this->credentials();
		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}
		$response = $this->request(
			'DELETE',
			'/wplc/v1/activations/' . absint( $credentials['activation_id'] ?? 0 ),
			array(),
			$credentials['installation_secret'],
			true,
			$credentials['installation_id']
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$verified = self::verify_envelope( $response, $credentials['installation_secret'] );
		if ( ! is_wp_error( $verified ) ) {
			delete_option( $this->option_key );
		}
		return $verified;
	}

	/**
	 * Sign and send an authenticated POST using the installation credential.
	 *
	 * @param string $path REST route.
	 * @param array<string,mixed> $payload Request payload.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function authenticated_post( string $path, array $payload ) {
		$credentials = $this->credentials();
		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}
		$response = $this->request( 'POST', $path, $payload, $credentials['installation_secret'], true, $credentials['installation_id'] );
		return is_wp_error( $response ) ? $response : self::verify_envelope( $response, $credentials['installation_secret'] );
	}

	/**
	 * Send a request to the license server.
	 *
	 * @param string $method HTTP method.
	 * @param string $route REST route.
	 * @param array<string,mixed> $payload Data.
	 * @param string $secret Signing secret.
	 * @param bool $authenticated Whether to include authentication headers.
	 * @param string $installation_id Installation UUID.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function request( string $method, string $route, array $payload, string $secret, bool $authenticated, string $installation_id = '' ) {
		$body = 'GET' === $method || ( 'DELETE' === $method && empty( $payload ) ) ? '' : wp_json_encode( $payload, JSON_UNESCAPED_SLASHES );
		if ( false === $body ) {
			return new \WP_Error( 'wplc_json_error', __( 'Could not encode the license request.', 'wp-license' ) );
		}
		$headers = array( 'Accept' => 'application/json' );
		if ( '' !== $body ) {
			$headers['Content-Type'] = 'application/json';
		}
		if ( $authenticated ) {
			$timestamp = (string) time();
			$nonce = bin2hex( random_bytes( 16 ) );
			$canonical = implode( "\n", array( $method, $route, $timestamp, $nonce, hash( 'sha256', $body ) ) );
			$headers['X-WPLC-Installation-Id'] = $installation_id;
			$headers['X-WPLC-Timestamp'] = $timestamp;
			$headers['X-WPLC-Nonce'] = $nonce;
			$headers['X-WPLC-Signature'] = hash_hmac( 'sha256', $canonical, $secret );
		}
		$http = wp_remote_request(
			$this->server_url . '/wp-json' . $route,
			array(
				'method' => $method,
				'timeout' => 20,
				'redirection' => 0,
				'sslverify' => true,
				'headers' => $headers,
				'body' => $body,
			)
		);
		if ( is_wp_error( $http ) ) {
			return $http;
		}
		$code = (int) wp_remote_retrieve_response_code( $http );
		$data = json_decode( (string) wp_remote_retrieve_body( $http ), true );
		if ( $code < 200 || $code >= 300 ) {
			$error = is_array( $data ) ? ( $data['code'] ?? 'wplc_http_error' ) : 'wplc_http_error';
			$message = is_array( $data ) ? ( $data['message'] ?? __( 'The license server returned an error.', 'wp-license' ) ) : __( 'The license server returned an invalid response.', 'wp-license' );
			return new \WP_Error( sanitize_key( (string) $error ), sanitize_text_field( (string) $message ), array( 'status' => $code ) );
		}
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'wplc_response_invalid', __( 'The license server returned invalid JSON.', 'wp-license' ) );
		}
		if ( isset( $data['data'] ) && isset( $data['signature'] ) ) {
			return $data;
		}
		return new \WP_Error( 'wplc_response_invalid', __( 'The license server response is missing its signature envelope.', 'wp-license' ) );
	}

	/**
	 * Verify the response HMAC before exposing entitlement data to product code.
	 *
	 * @param array<string,mixed> $envelope Response wrapper.
	 * @param string $secret Installation secret.
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function verify_envelope( array $envelope, string $secret ) {
		$json = wp_json_encode( $envelope['data'], JSON_UNESCAPED_SLASHES );
		$expected = hash_hmac( 'sha256', false === $json ? '' : $json, $secret );
		if ( ! is_string( $envelope['signature'] ?? null ) || ! hash_equals( $expected, $envelope['signature'] ) ) {
			return new \WP_Error( 'wplc_signature_invalid', __( 'The license server response signature could not be verified.', 'wp-license' ) );
		}
		return is_array( $envelope['data'] ) ? $envelope['data'] : array();
	}

	/**
	 * Read credentials saved after activation.
	 *
	 * @return array<string,string>|\WP_Error
	 */
	private function credentials() {
		$credentials = get_option( $this->option_key, array() );
		if ( ! is_array( $credentials ) || empty( $credentials['installation_id'] ) || empty( $credentials['installation_secret'] ) || empty( $credentials['activation_id'] ) ) {
			return new \WP_Error( 'wplc_not_activated', __( 'This product installation has not been activated.', 'wp-license' ) );
		}
		return $credentials;
	}
}
