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
	/** @var string[] Pinned Ed25519 public keys of the server (base64). */
	private $server_public_keys = array();

	/** Context prefix of the server's Ed25519 response signature (see WPLC\Security\ResponseSigner). */
	public const SIGNATURE_CONTEXT = 'wplc-response-v1';

	/**
	 * @param string          $server_url WP License Captain site URL.
	 * @param string          $product_key Stable product identifier.
	 * @param string          $product_version Installed product version.
	 * @param string          $option_key Product-specific option key.
	 * @param string|string[] $server_public_keys Pinned Ed25519 public key(s) of the license server, base64, as printed
	 *                                            by `wp wplc signing-key --field=public_key`. Embed them in the product
	 *                                            build; pass the old and the new key during a rotation. When set, every
	 *                                            response must carry a valid server signature. Strongly recommended:
	 *                                            without it, anybody who knows the installation secret (stored in this
	 *                                            site's options) can forge entitlement data.
	 */
	public function __construct( string $server_url, string $product_key, string $product_version, string $option_key, $server_public_keys = array() ) {
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
		foreach ( (array) $server_public_keys as $public_key ) {
			if ( '' !== trim( (string) $public_key ) ) {
				$this->server_public_keys[] = trim( $public_key );
			}
		}
	}

	/** Whether this client pins server keys and therefore requires the server's Ed25519 signature. */
	public function requires_server_signature(): bool {
		return array() !== $this->server_public_keys;
	}

	/**
	 * Activate this site and persist only its installation credential.
	 *
	 * @param string $license_key Customer-provided key.
	 * @param string $environment production, staging, or development.
	 * @param string $staging_parent_installation_id Optional production installation UUID.
	 * @param bool $force Create a new activation even when stored credentials still work.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function activate( string $license_key, string $environment = 'production', string $staging_parent_installation_id = '', bool $force = false ) {
		if ( '' === $this->server_url || '' === $this->product_key || '' === trim( $license_key ) ) {
			return new \WP_Error( 'wplc_configuration_invalid', __( 'License server settings or license key are missing.', 'wp-license' ) );
		}
		$license_key = strtoupper( trim( sanitize_text_field( $license_key ) ) );
		$license_key_hash = hash( 'sha256', $license_key );
		if ( ! $force ) {
			// Reuse a working activation instead of occupying another slot. The
			// stored hash prevents answering for a different license key.
			$stored = $this->credentials();
			$stored_hash = is_array( $stored ) ? (string) ( $stored['license_key_hash'] ?? '' ) : '';
			if ( is_array( $stored ) && ( '' === $stored_hash || hash_equals( $stored_hash, $license_key_hash ) ) ) {
				$current = $this->check_entitlements();
				if ( ! is_wp_error( $current ) ) {
					return $current;
				}
			}
		}
		$installation_id = wp_generate_uuid4();
		$secret = bin2hex( random_bytes( 32 ) );
		$payload = array(
			'license_key' => $license_key,
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
		// Activation is not HMAC-authenticated: the server echoes the new
		// installation ID in place of a request nonce.
		$response = $this->request( 'POST', '/wplc/v1/activations', $payload, $secret, false, $installation_id );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$verified = $this->verify_response( $response['envelope'], $secret, $response['nonce'], false, $installation_id );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}
		$saved = update_option(
			$this->option_key,
			array(
				'installation_id' => $installation_id,
				'installation_secret' => $secret,
				'activation_id' => absint( $verified['activation_id'] ?? 0 ),
				'license_key_hash' => $license_key_hash,
				// Once the server has sent a signed payload, a response without
				// one is treated as a downgrade and rejected.
				'signed_payload' => isset( $response['envelope']['payload'] ) ? 1 : 0,
			),
			false
		);
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
		$verified = $this->verify_response( $response['envelope'], (string) $credentials['installation_secret'], $response['nonce'], ! empty( $credentials['signed_payload'] ), (string) $credentials['installation_id'] );
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
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $this->verify_response( $response['envelope'], (string) $credentials['installation_secret'], $response['nonce'], ! empty( $credentials['signed_payload'] ), (string) $credentials['installation_id'] );
	}

	/**
	 * Re-verify a response envelope that the product stored as an offline cache.
	 *
	 * Store the complete envelope as received (the `envelope` key of the array returned by
	 * check_entitlements_envelope()), never only the decoded data, and call this before every use of the cache. With
	 * pinned server keys the cache is accepted only with a valid server signature for this installation, so writing
	 * the option table is not enough to unlock features. Without pinned keys only the HMAC is checked.
	 *
	 * @param array<string,mixed> $envelope Envelope as received from the server.
	 * @return array<string,mixed>|\WP_Error Verified data.
	 */
	public function verify_cached_envelope( array $envelope ) {
		$credentials = $this->credentials();
		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}
		// The request nonce of a cached answer is not known any more; freshness is the product's job (issued_at).
		return $this->verify_response( $envelope, (string) $credentials['installation_secret'], '', true, (string) $credentials['installation_id'] );
	}

	/**
	 * Like check_entitlements(), but also returns the raw envelope for an offline cache.
	 *
	 * @return array{data:array<string,mixed>,envelope:array<string,mixed>}|\WP_Error
	 */
	public function check_entitlements_envelope() {
		$credentials = $this->credentials();
		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}
		$response = $this->request( 'POST', '/wplc/v1/entitlements/check', array( 'product_version' => $this->product_version ), $credentials['installation_secret'], true, $credentials['installation_id'] );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$verified = $this->verify_response( $response['envelope'], (string) $credentials['installation_secret'], $response['nonce'], ! empty( $credentials['signed_payload'] ), (string) $credentials['installation_id'] );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}
		return array( 'data' => $verified, 'envelope' => $response['envelope'] );
	}

	/**
	 * HMAC verification plus, with pinned keys, the server's Ed25519 signature.
	 *
	 * @param array<string,mixed> $envelope Response wrapper.
	 * @param string              $secret Installation secret.
	 * @param string              $expected_nonce Request nonce ('' skips the comparison).
	 * @param bool                $require_payload Reject responses without a signed payload.
	 * @param string              $installation_id Installation UUID the answer must be signed for.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function verify_response( array $envelope, string $secret, string $expected_nonce, bool $require_payload, string $installation_id ) {
		$verified = self::verify_envelope( $envelope, $secret, $expected_nonce, $require_payload || $this->requires_server_signature() );
		if ( is_wp_error( $verified ) || ! $this->requires_server_signature() ) {
			return $verified;
		}
		$server = self::verified_server_data( $envelope, $installation_id, $this->server_public_keys );
		if ( ! is_array( $server ) ) {
			return new \WP_Error( 'wplc_server_signature_invalid', __( 'The license server response is not signed by the expected license server.', 'wp-license' ) );
		}
		// Both checks read the same payload bytes, so the data is identical.
		return $server;
	}

	/**
	 * Pure verification of the server's Ed25519 signature of an envelope.
	 *
	 * The server signs "wplc-response-v1\n{installation UUID}\n{JSON of data}". The JSON of the data is cut out of the
	 * received `payload` string (`{"data":<JSON>,"nonce":<string>}`) byte for byte instead of being re-encoded, so the
	 * check does not depend on the JSON encoder of either side.
	 *
	 * @param array<string,mixed> $envelope Decoded response body.
	 * @param string              $installation_id Installation UUID.
	 * @param string[]            $public_keys Accepted public keys (base64).
	 * @return array<string,mixed>|string Verified data, or `wplc_server_signature_invalid`.
	 */
	public static function verified_server_data( array $envelope, string $installation_id, array $public_keys ) {
		$invalid = 'wplc_server_signature_invalid';
		$payload = $envelope['payload'] ?? null;
		$signature = $envelope['signature_ed25519'] ?? null;
		if ( '' === $installation_id || ! is_string( $payload ) || ! is_string( $signature ) || ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return $invalid;
		}
		$decoded = json_decode( $payload, true );
		if ( ! is_array( $decoded ) || ! isset( $decoded['data'], $decoded['nonce'] ) || ! is_array( $decoded['data'] ) || ! is_string( $decoded['nonce'] ) ) {
			return $invalid;
		}
		$prefix = '{"data":';
		$suffix = wp_json_encode( array( 'nonce' => $decoded['nonce'] ), JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $suffix ) ) {
			return $invalid;
		}
		$suffix = ',' . substr( $suffix, 1 );
		$data_length = strlen( $payload ) - strlen( $prefix ) - strlen( $suffix );
		if ( $data_length < 2 || 0 !== strpos( $payload, $prefix ) || substr( $payload, -strlen( $suffix ) ) !== $suffix ) {
			return $invalid;
		}
		$data_json = substr( $payload, strlen( $prefix ), $data_length );
		// The cut-out part must be exactly the data, nothing smuggled around it.
		if ( json_decode( $data_json, true ) !== $decoded['data'] ) {
			return $invalid;
		}
		$message = self::SIGNATURE_CONTEXT . "\n" . strtolower( $installation_id ) . "\n" . $data_json;
		$raw_signature = base64_decode( $signature, true );
		if ( false === $raw_signature || 64 !== strlen( $raw_signature ) ) {
			return $invalid;
		}
		foreach ( $public_keys as $public_key ) {
			$raw_key = base64_decode( (string) $public_key, true );
			if ( false === $raw_key || 32 !== strlen( $raw_key ) ) {
				continue;
			}
			try {
				if ( sodium_crypto_sign_verify_detached( $raw_signature, $message, $raw_key ) ) {
					return $decoded['data'];
				}
			} catch ( \Throwable $e ) {
				continue;
			}
		}
		return $invalid;
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
	 * @return array{envelope:array<string,mixed>,nonce:string}|\WP_Error Envelope plus the value the server must echo in its signed payload.
	 */
	private function request( string $method, string $route, array $payload, string $secret, bool $authenticated, string $installation_id = '' ) {
		$body = 'GET' === $method || ( 'DELETE' === $method && empty( $payload ) ) ? '' : wp_json_encode( $payload, JSON_UNESCAPED_SLASHES );
		if ( false === $body ) {
			return new \WP_Error( 'wplc_json_error', __( 'Could not encode the license request.', 'wp-license' ) );
		}
		$headers = array( 'Accept' => 'application/json' );
		$expected_nonce = $installation_id;
		if ( '' !== $body ) {
			$headers['Content-Type'] = 'application/json';
		}
		if ( $authenticated ) {
			$timestamp = (string) time();
			$nonce = bin2hex( random_bytes( 16 ) );
			$expected_nonce = $nonce;
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
			return array( 'envelope' => $data, 'nonce' => $expected_nonce );
		}
		return new \WP_Error( 'wplc_response_invalid', __( 'The license server response is missing its signature envelope.', 'wp-license' ) );
	}

	/**
	 * Verify the response HMAC before exposing entitlement data to product code.
	 *
	 * @param array<string,mixed> $envelope Response wrapper.
	 * @param string $secret Installation secret.
	 * @param string $expected_nonce Request nonce (or installation ID for activation).
	 * @param bool $require_payload Reject responses without a signed payload.
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function verify_envelope( array $envelope, string $secret, string $expected_nonce = '', bool $require_payload = false ) {
		$verified = self::verified_data( $envelope, $secret, $expected_nonce, $require_payload );
		if ( is_array( $verified ) ) {
			return $verified;
		}
		if ( 'wplc_nonce_mismatch' === $verified ) {
			return new \WP_Error( 'wplc_nonce_mismatch', __( 'The license server response does not belong to this request.', 'wp-license' ) );
		}
		if ( 'wplc_response_invalid' === $verified ) {
			return new \WP_Error( 'wplc_response_invalid', __( 'The license server returned an invalid signed payload.', 'wp-license' ) );
		}

		return new \WP_Error( 'wplc_signature_invalid', __( 'The license server response signature could not be verified.', 'wp-license' ) );
	}

	/**
	 * Pure verification of a signed response envelope.
	 *
	 * Current servers send `payload` (the exact signed JSON string
	 * `{"data":…,"nonce":…}`) and `payload_signature`; the HMAC is checked over
	 * the received bytes and the echoed nonce is compared with the one sent.
	 * Only when `payload` is absent (older server) the legacy check runs, which
	 * re-encodes `data` and therefore depends on identical JSON encoding.
	 *
	 * @param array<string,mixed> $envelope Decoded response body.
	 * @param string $secret Installation secret.
	 * @param string $expected_nonce Value the server must echo; an empty string skips the comparison.
	 * @param bool $require_payload Whether the legacy fallback is forbidden.
	 * @return array<string,mixed>|string Verified data, or one of the error codes
	 *                                    `wplc_signature_invalid`, `wplc_nonce_mismatch`, `wplc_response_invalid`.
	 */
	public static function verified_data( array $envelope, string $secret, string $expected_nonce = '', bool $require_payload = false ) {
		if ( '' === $secret ) {
			return 'wplc_signature_invalid';
		}

		if ( array_key_exists( 'payload', $envelope ) || array_key_exists( 'payload_signature', $envelope ) ) {
			$payload = $envelope['payload'] ?? null;
			$signature = $envelope['payload_signature'] ?? null;
			if ( ! is_string( $payload ) || ! is_string( $signature ) || ! hash_equals( hash_hmac( 'sha256', $payload, $secret ), strtolower( $signature ) ) ) {
				return 'wplc_signature_invalid';
			}
			$decoded = json_decode( $payload, true );
			if ( ! is_array( $decoded ) || ! isset( $decoded['data'], $decoded['nonce'] ) || ! is_array( $decoded['data'] ) || ! is_string( $decoded['nonce'] ) ) {
				return 'wplc_response_invalid';
			}
			if ( '' !== $expected_nonce && ! hash_equals( $expected_nonce, $decoded['nonce'] ) ) {
				return 'wplc_nonce_mismatch';
			}

			return $decoded['data'];
		}

		if ( $require_payload ) {
			return 'wplc_signature_invalid';
		}

		$json = wp_json_encode( $envelope['data'] ?? null, JSON_UNESCAPED_SLASHES );
		$expected = hash_hmac( 'sha256', false === $json ? '' : $json, $secret );
		if ( ! is_string( $envelope['signature'] ?? null ) || ! hash_equals( $expected, $envelope['signature'] ) ) {
			return 'wplc_signature_invalid';
		}

		return is_array( $envelope['data'] ?? null ) ? $envelope['data'] : array();
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
