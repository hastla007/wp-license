<?php
/**
 * In-process stand-in for the license server's REST API, for request/response tests.
 *
 * @package Vendor\Product\Premium
 */

namespace Vendor\Product\Premium\Tests\Support;

/**
 * Answers LicenseClient requests with envelopes built exactly like WPLC\Api\V1\Response::envelope() and signed with
 * the fixture server key (ServerFixturesTest proves the format equals the real server's output byte for byte).
 */
final class FakeServer {
	/** @var array<string,mixed> keys.json */
	public $keys;
	/** @var string License status to report. */
	public $status = 'active';
	/** @var array<string,mixed> Entitlements to report. */
	public $entitlements = array( 'starter.pdf_templates' => true, 'starter.monthly_limit' => 500 );
	/** @var string Server time (UTC) to report. */
	public $server_time = '2026-11-01 00:00:00';
	/** @var array<string,array{0:int,1:string}> Route => forced error (HTTP status, code). */
	public $errors = array();
	/** @var array<string,mixed>|null Data for updates/check. */
	public $update = null;
	/** @var string|null Sign answers for this installation instead of the requesting one. */
	public $sign_for = null;
	/** @var array<string,string> Download URL => body. */
	public $downloads = array();
	/** @var array<string,string> Download URL => redirect target. */
	public $redirects = array();
	/** @var string[] Routes seen. */
	public $seen = array();
	/** @var array<int,array<string,mixed>> Bodies of updates/check requests. */
	public $update_requests = array();
	/** @var array<string,string> installation_id => secret registered by activations. */
	private $installations = array();

	public function __construct() {
		$this->keys = Fixtures::json( 'keys.json' );
		$this->installations[ (string) $this->keys['installation_id'] ] = (string) $this->keys['installation_secret'];
	}

	/** Server secret key (binary). */
	public function secret_key(): string {
		return sodium_crypto_sign_secretkey( sodium_crypto_sign_seed_keypair( str_repeat( chr( (int) $this->keys['server_seed_byte'] ), 32 ) ) );
	}

	/**
	 * Envelope as Response::envelope() + ResponseSigner::envelope_fields() build it.
	 *
	 * @param array<string,mixed> $data         Data.
	 * @param string              $secret       Installation secret.
	 * @param string              $nonce        Nonce.
	 * @param string              $installation Installation UUID.
	 * @return array<string,mixed>
	 */
	public function envelope( array $data, string $secret, string $nonce, string $installation ): array {
		$json    = (string) json_encode( $data, JSON_UNESCAPED_SLASHES );
		$payload = (string) json_encode( array( 'data' => $data, 'nonce' => $nonce ), JSON_UNESCAPED_SLASHES );
		$message = 'wplc-response-v1' . "\n" . strtolower( $installation ) . "\n" . $json;
		$public  = sodium_crypto_sign_publickey_from_secretkey( $this->secret_key() );
		return array(
			'data'              => $data,
			'signature'         => hash_hmac( 'sha256', $json, $secret ),
			'payload'           => $payload,
			'payload_signature' => hash_hmac( 'sha256', $payload, $secret ),
			'signature_ed25519' => base64_encode( sodium_crypto_sign_detached( $message, $this->secret_key() ) ),
			'payload_signature_ed25519' => base64_encode( sodium_crypto_sign_detached( 'wplc-response-v2' . "\n" . strtolower( $installation ) . "\n" . $payload, $this->secret_key() ) ),
			'key_id'            => substr( hash( 'sha256', $public ), 0, 16 ),
		);
	}

	/** Entitlement data as EntitlementService::check() returns it. */
	public function entitlement_data( string $installation ): array {
		$time = strtotime( $this->server_time . ' UTC' );
		return array(
			'activation_id'    => (int) $this->keys['activation_id'],
			'installation_id'  => $installation,
			'activation_status' => 'active',
			'product_key'      => (string) $this->keys['product_key'],
			'license_status'   => $this->status,
			'access'           => in_array( $this->status, array( 'active', 'past_due' ), true ),
			'paid_until'       => '2027-11-01 00:00:00',
			'access_until'     => in_array( $this->status, array( 'active', 'past_due' ), true ) ? '2027-11-15 00:00:00' : null,
			'grace_until'      => in_array( $this->status, array( 'active', 'past_due' ), true ) ? '2027-11-15 00:00:00' : null,
			'site_limit'       => 5,
			'site_count'       => 1,
			'environment'      => 'production',
			'entitlements'     => in_array( $this->status, array( 'active', 'past_due' ), true ) ? $this->entitlements : array(),
			'server_time'      => $this->server_time,
			'cache_expires_at' => gmdate( 'Y-m-d H:i:s', $time + 14 * 86400 ),
		);
	}

	/** Install as the HTTP handler of the stubs. */
	public function install(): void {
		$GLOBALS['wp_stub']['http'] = array( $this, 'handle' );
	}

	/**
	 * HTTP handler.
	 *
	 * @param string              $url  URL.
	 * @param array<string,mixed> $args Request args.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function handle( string $url, array $args ) {
		if ( isset( $this->redirects[ $url ] ) ) {
			return array( 'code' => 302, 'headers' => array( 'Location' => $this->redirects[ $url ] ) );
		}
		if ( isset( $this->downloads[ $url ] ) ) {
			return array( 'code' => 200, 'body' => $this->downloads[ $url ], 'headers' => array( 'Content-Length' => (string) strlen( $this->downloads[ $url ] ) ) );
		}
		$base  = (string) $this->keys['server_url'] . '/wp-json';
		$route = 0 === strpos( $url, $base ) ? substr( $url, strlen( $base ) ) : $url;
		$key   = (string) ( $args['method'] ?? 'GET' ) . ' ' . preg_replace( '#/\d+$#', '/{id}', $route );
		$this->seen[] = $key;
		if ( isset( $this->errors[ $key ] ) ) {
			list( $status, $code ) = $this->errors[ $key ];
			if ( 0 === $status ) {
				return new \WP_Error( $code, 'cURL error 7: Failed to connect' );
			}
			return array( 'code' => $status, 'body' => (string) json_encode( array( 'code' => $code, 'message' => 'Server says ' . $code, 'data' => array( 'status' => $status ) ) ) );
		}
		$headers = (array) ( $args['headers'] ?? array() );
		$body    = json_decode( (string) ( $args['body'] ?? '' ), true );
		if ( 'POST /wplc/v1/activations' === $key ) {
			$installation = (string) $body['installation_id'];
			$secret       = (string) $body['installation_secret'];
			$this->installations[ $installation ] = $secret;
			$nonce = $installation;
			$data  = $this->entitlement_data( $installation );
		} else {
			$installation = (string) ( $headers['X-WPLC-Installation-Id'] ?? '' );
			$secret       = $this->installations[ $installation ] ?? '';
			$nonce        = (string) ( $headers['X-WPLC-Nonce'] ?? '' );
			if ( '' === $secret ) {
				return array( 'code' => 401, 'body' => (string) json_encode( array( 'code' => 'activation_not_found', 'message' => 'Unknown installation.' ) ) );
			}
			if ( 'POST /wplc/v1/updates/check' === $key ) {
				$data = null === $this->update ? array( 'update_available' => false ) : $this->update;
				if ( empty( $body['download'] ) && isset( $data['package_url'] ) ) {
					// Like UpdateService: a periodic check (download: false) creates no grant and sends no URL.
					$data['package_url'] = '';
					unset( $data['package'], $data['download_expires_at'] );
				}
				$this->update_requests[] = $body;
			} elseif ( 'DELETE /wplc/v1/activations/{id}' === $key ) {
				unset( $this->installations[ $installation ] );
				$data = array( 'activation_id' => (int) $this->keys['activation_id'], 'status' => 'inactive' );
			} else {
				$data = $this->entitlement_data( $installation );
			}
		}
		return array( 'code' => 200, 'body' => (string) json_encode( $this->envelope( $data, $secret, $nonce, $this->sign_for ?? $installation ) ) );
	}
}
