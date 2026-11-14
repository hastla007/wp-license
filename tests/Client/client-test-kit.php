<?php
/**
 * WordPress stubs, an in-memory store and a fake license server for the unit tests of the client library.
 *
 * The fake server signs like the real one (HMAC over the payload text, Ed25519
 * over "wplc-response-v2\n{uuid}\n{payload}"), so the client's verification is
 * exercised for real. What it cannot prove is that the real server behaves the
 * same way; that is the job of tests/integration/Client*IntegrationTest.php.
 *
 * @package WPLC
 */

use WPLC\Client\LicenseClient;
use WPLC\Client\LicenseManager;
use WPLC\Client\OptionStore;

// phpcs:disable

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $message;
		private $data;
		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}
		public function get_error_code() {
			return $this->code;
		}
		public function get_error_message() {
			return $this->message;
		}
		public function get_error_data() {
			return $this->data;
		}
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, $options = 0, $depth = 512 ) {
		return json_encode( $value, $options, $depth );
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $text ) {
		return trim( strip_tags( (string) $text ) );
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) {
		return $value;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook, ...$args ) {
		$GLOBALS['wplc_test_actions'][] = array_merge( array( $hook ), $args );
	}
}
if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $hook ) {
		return $GLOBALS['wplc_test_cron'][ $hook ] ?? false;
	}
	function wp_schedule_event( $timestamp, $recurrence, $hook ) {
		$GLOBALS['wplc_test_cron'][ $hook ] = $timestamp;
		return true;
	}
	function wp_clear_scheduled_hook( $hook ) {
		unset( $GLOBALS['wplc_test_cron'][ $hook ] );
		return 1;
	}
}
if ( ! function_exists( 'wp_rand' ) ) {
	function wp_rand( $min = 0, $max = 0 ) {
		return $min;
	}
}
foreach ( array( 'MINUTE_IN_SECONDS' => 60, 'HOUR_IN_SECONDS' => 3600, 'DAY_IN_SECONDS' => 86400 ) as $wplc_name => $wplc_value ) {
	if ( ! defined( $wplc_name ) ) {
		define( $wplc_name, $wplc_value );
	}
}

/**
 * In-memory replacement of the option storage.
 */
final class WPLC_Test_Store extends OptionStore {
	/** @var array<string,mixed> */
	public $data = array();
	/** @var string */
	public $site = 'https://shop.example';
	/** @var bool */
	public $fail = false;

	public function __construct() {
	}
	public function is_network(): bool {
		return false;
	}
	public function get( string $name, $fallback = array() ) {
		return array_key_exists( $name, $this->data ) ? $this->data[ $name ] : $fallback;
	}
	public function set( string $name, $value ): bool {
		if ( $this->fail ) {
			return false;
		}
		$this->data[ $name ] = $value;
		return true;
	}
	public function delete( string $name ): void {
		unset( $this->data[ $name ] );
	}
	public function site_url(): string {
		return $this->site;
	}
}

/**
 * Scriptable license server behind the transport callable of the client.
 */
final class WPLC_Test_Server {
	/** @var string Binary Ed25519 secret key. */
	public $secret_key;
	/** @var string Base64 public key. */
	public $public_key;
	/** @var array<int,array<string,mixed>> Requests received. */
	public $requests = array();
	/** @var array<int,mixed> Scripted answers; each is used once, in order. */
	public $queue = array();
	/** @var array<string,string> installation_id => secret, learned from activations. */
	public $secrets = array();
	/** @var int */
	public $now = 1800000000;
	/** @var int */
	public $activation_id = 41;

	public function __construct( string $seed = '' ) {
		$pair             = sodium_crypto_sign_seed_keypair( '' === $seed ? str_repeat( "\x07", 32 ) : $seed );
		$this->secret_key = sodium_crypto_sign_secretkey( $pair );
		$this->public_key = base64_encode( sodium_crypto_sign_publickey( $pair ) );
	}

	/** Transport callable for the client. */
	public function transport(): callable {
		return function ( string $url, array $args ) {
			$headers = array();
			foreach ( $args['headers'] as $name => $value ) {
				$headers[ strtolower( $name ) ] = $value;
			}
			$request          = array(
				'url'     => $url,
				'method'  => $args['method'],
				'route'   => (string) preg_replace( '#^https?://[^/]+/wp-json#', '', $url ),
				'headers' => $headers,
				'raw'     => (string) $args['body'],
				'body'    => json_decode( (string) $args['body'], true ),
				'timeout' => $args['timeout'],
			);
			$this->requests[] = $request;
			$answer           = empty( $this->queue ) ? 'ok' : array_shift( $this->queue );
			if ( $answer instanceof Closure ) {
				$answer = $answer( $request, $this );
			}
			if ( 'ok' === $answer ) {
				$answer = $this->default_answer( $request );
			}

			return $answer;
		};
	}

	/** Entitlement data as the server sends it. */
	public function entitlement( array $overrides = array() ): array {
		return array_merge(
			array(
				'activation_id'     => $this->activation_id,
				'installation_id'   => '',
				'activation_status' => 'active',
				'product_key'       => 'test-addon',
				'license_status'    => 'active',
				'access'            => true,
				'paid_until'        => gmdate( 'Y-m-d H:i:s', $this->now + 30 * 86400 ),
				'access_until'      => gmdate( 'Y-m-d H:i:s', $this->now + 44 * 86400 ),
				'grace_until'       => gmdate( 'Y-m-d H:i:s', $this->now + 44 * 86400 ),
				'site_limit'        => 1,
				'site_count'        => 1,
				'environment'       => 'production',
				'staging_parent_id' => null,
				'entitlements'      => array(
					'feature.flag'  => true,
					'feature.off'   => false,
					'feature.limit' => 3,
					'feature.zero'  => 0,
				),
				'server_time'       => gmdate( 'Y-m-d H:i:s', $this->now ),
				'cache_expires_at'  => gmdate( 'Y-m-d H:i:s', $this->now + 14 * 86400 ),
			),
			$overrides
		);
	}

	/** Signed envelope for a request. */
	public function envelope( array $request, array $data, array $options = array() ): array {
		$installation = (string) ( $request['headers']['x-wplc-installation-id'] ?? ( $request['body']['installation_id'] ?? '' ) );
		$nonce        = (string) ( $request['headers']['x-wplc-nonce'] ?? $installation );
		$secret       = (string) ( $request['body']['installation_secret'] ?? ( $this->secrets[ $installation ] ?? '' ) );
		if ( array_key_exists( 'installation_id', $data ) && '' === $data['installation_id'] ) {
			$data['installation_id'] = $installation;
		}
		$payload  = json_encode( array( 'data' => $data, 'nonce' => $options['nonce'] ?? $nonce ), JSON_UNESCAPED_SLASHES );
		$key      = $options['secret_key'] ?? $this->secret_key;
		$envelope = array(
			'data'                      => $data,
			'signature'                 => hash_hmac( 'sha256', (string) json_encode( $data, JSON_UNESCAPED_SLASHES ), $secret ),
			'payload'                   => $payload,
			'payload_signature'         => hash_hmac( 'sha256', $payload, $secret ),
			'signature_ed25519'         => base64_encode( sodium_crypto_sign_detached( "wplc-response-v1\n" . strtolower( $installation ) . "\n" . json_encode( $data, JSON_UNESCAPED_SLASHES ), $key ) ),
			'payload_signature_ed25519' => base64_encode( sodium_crypto_sign_detached( "wplc-response-v2\n" . strtolower( $installation ) . "\n" . $payload, $key ) ),
			'key_id'                    => 'test',
		);
		foreach ( $options['strip'] ?? array() as $member ) {
			unset( $envelope[ $member ] );
		}

		return $envelope;
	}

	/** HTTP answer with a JSON body. */
	public static function json( int $status, $body, array $headers = array() ): array {
		return array(
			'headers'  => array_merge( array( 'content-type' => 'application/json; charset=UTF-8' ), $headers ),
			'body'     => is_string( $body ) ? $body : json_encode( $body, JSON_UNESCAPED_SLASHES ),
			'response' => array( 'code' => $status, 'message' => '' ),
		);
	}

	/** REST error answer. */
	public static function error( int $status, string $code, array $data = array(), array $headers = array() ): array {
		return self::json( $status, array( 'code' => $code, 'message' => 'Server text for ' . $code . '.', 'data' => array_merge( array( 'status' => $status ), $data ) ), $headers );
	}

	/** Answer scripted as "signed 200 with this data". */
	public function signed( array $data, array $options = array() ): Closure {
		return function ( array $request ) use ( $data, $options ) {
			return self::json( 200, $this->envelope( $request, $data, $options ) );
		};
	}

	/** What the real server would answer to a well-formed request of an active license. */
	private function default_answer( array $request ): array {
		if ( '/wplc/v1/activations' === $request['route'] ) {
			$this->secrets[ $request['body']['installation_id'] ] = $request['body']['installation_secret'];
			return self::json( 200, $this->envelope( $request, $this->entitlement( array( 'environment' => $request['body']['environment'] ) ) ) );
		}
		if ( 'DELETE' === $request['method'] || 'DELETE' === ( $request['headers']['x-http-method-override'] ?? '' ) ) {
			return self::json( 200, $this->envelope( $request, array( 'activation_id' => $this->activation_id, 'status' => 'deactivated' ) ) );
		}
		if ( '/wplc/v1/updates/check' === $request['route'] ) {
			return self::json( 200, $this->envelope( $request, array( 'update_available' => false, 'current_version' => $request['body']['current_version'], 'product_key' => $request['body']['product_key'], 'license_active' => true, 'reason' => 'up_to_date' ) ) );
		}

		return self::json( 200, $this->envelope( $request, $this->entitlement() ) );
	}
}

/**
 * Builds clients and managers that talk to a WPLC_Test_Server.
 */
final class WPLC_Client_Test_Kit {
	/** @var WPLC_Test_Server */
	public $server;
	/** @var WPLC_Test_Store */
	public $store;
	/** @var int Local clock of the customer site. */
	public $now = 1800000000;

	public function __construct() {
		$this->server = new WPLC_Test_Server();
		$this->store  = new WPLC_Test_Store();
	}

	public function client( array $args = array(), string $server_url = 'https://license.example' ): LicenseClient {
		return new LicenseClient( $server_url, 'test-addon', '1.0.0', 'test_addon_license', $this->args( $args ) );
	}

	public function manager( array $config = array() ): LicenseManager {
		return new LicenseManager(
			array_merge(
				array(
					'product_key' => 'test-addon',
					'version'     => '1.0.0',
					'name'        => 'Test Add-on',
					'server_url'  => 'https://license.example',
				),
				$this->args( $config )
			)
		);
	}

	/** Move the local clock and the clock of the server. */
	public function advance( int $seconds ): void {
		$this->now         += $seconds;
		$this->server->now += $seconds;
	}

	public function credentials(): array {
		return (array) $this->store->get( 'test_addon_license', array() );
	}

	private function args( array $args ): array {
		return array_merge(
			array(
				'public_keys' => array( $this->server->public_key ),
				'store'       => $this->store,
				'transport'   => $this->server->transport(),
				'clock'       => function () {
					return $this->now;
				},
			),
			$args
		);
	}
}
