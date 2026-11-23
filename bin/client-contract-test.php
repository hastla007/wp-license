#!/usr/bin/env php
<?php
/**
 * Contract test of the license client library against a license server (API v1).
 *
 * Usage:
 *   php bin/client-contract-test.php --server=https://shop.example --product=my-addon \
 *       --key=WPLC-XXXX-XXXX-XXXX-XXXX --public-key=BASE64 [--public-key=BASE64 ...] \
 *       [--site=https://contract-test.local] [--environment=development] \
 *       [--version=0.0.1] [--rest-base=https://shop.example/index.php?rest_route=] \
 *       [--timeout=20] [--allow-insecure]
 *
 * Runs the library of this package (`src/Client`, unchanged) without WordPress over real HTTP:
 *
 * 1. activate with the license key (as a `development` site by default, so no live site slot is used;
 *    the server accepts that only for a local address such as `*.local`, the default `--site`),
 * 2. check the entitlements,
 * 3. check for an update (metadata only, no download grant),
 * 4. verify the signatures: the last signed answer verifies against the pinned key, a changed payload and a
 *    different key are refused,
 * 5. deactivate (also when a step after the activation failed, so the test leaves nothing behind).
 *
 * Every answer must carry a valid Ed25519 signature of one of the pinned public keys
 * (`--public-key`, the key of the server: `wp wplc signing-key`), as in an add-on. Needs PHP 8.5+
 * (the minimum of the library since 2.2.0) with the sodium extension; curl is used when available. Exit code 0 when every step passed,
 * 1 when one failed, 2 for wrong arguments.
 *
 * This script talks to the network; it is not part of any CI run. Its argument parsing and the
 * flow against a fake server are unit tested (tests/Client/ClientPackageTest.php).
 *
 * @package WPLC
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput, WordPress.NamingConventions, WordPress.PHP.DiscouragedPHPFunctions, Generic.CodeAnalysis.UnusedFunctionParameter, Universal.Files.SeparateFunctionsFromOO, Generic.Files.OneObjectStructurePerFile -- command line script without WordPress; base64 handles public keys.

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

if ( ! defined( 'ABSPATH' ) ) {
	// Only for the guards at the top of the library files; nothing of WordPress is loaded.
	define( 'ABSPATH', __DIR__ . '/' );
}

// The few WordPress functions the library calls without checking for them, when WordPress is not loaded.
if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal WP_Error.
	 */
	class WP_Error {
		/** @var string|int */
		private $code;
		/** @var string */
		private $message;
		/** @var mixed */
		private $data;
		/**
		 * @param string|int $code    Code.
		 * @param string     $message Message.
		 * @param mixed      $data    Data.
		 */
		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}
		/** @return string|int */
		public function get_error_code() {
			return $this->code;
		}
		/** @return string */
		public function get_error_message() {
			return $this->message;
		}
		/** @return mixed */
		public function get_error_data() {
			return $this->data;
		}
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * @param mixed $thing Value.
	 * @return bool
	 */
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}
if ( ! function_exists( '__' ) ) {
	/**
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * @param mixed $value   Value.
	 * @param int   $options Options.
	 * @param int   $depth   Depth.
	 * @return string|false
	 */
	function wp_json_encode( $value, $options = 0, $depth = 512 ) {
		return json_encode( $value, $options, $depth );
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * @param string $url       URL.
	 * @param int    $component Component.
	 * @return mixed
	 */
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * @param string $text Text.
	 * @return string
	 */
	function wp_strip_all_tags( $text ) {
		return trim( strip_tags( (string) $text ) );
	}
}

require_once dirname( __DIR__ ) . '/src/Client/load.php';

/**
 * Credentials of the test installation, in memory only.
 */
final class WPLC_Contract_Store extends \WPLC\Client\OptionStore {
	/** @var array<string,mixed> */
	public $data = array();
	/** @var string */
	private $site;

	/**
	 * @param string $site Address the test installation reports.
	 */
	public function __construct( string $site ) {
		$this->site = $site;
	}
	/** @return bool */
	public function is_network(): bool {
		return false;
	}
	/**
	 * @param string $name     Option.
	 * @param mixed  $fallback Fallback.
	 * @return mixed
	 */
	public function get( string $name, $fallback = array() ) {
		return array_key_exists( $name, $this->data ) ? $this->data[ $name ] : $fallback;
	}
	/**
	 * @param string $name  Option.
	 * @param mixed  $value Value.
	 * @return bool
	 */
	public function set( string $name, $value ): bool {
		$this->data[ $name ] = $value;
		return true;
	}
	/**
	 * @param string $name Option.
	 * @return bool
	 */
	public function delete( string $name ): bool {
		unset( $this->data[ $name ] );
		return true;
	}
	/** @return string */
	public function site_url(): string {
		return $this->site;
	}
}

/**
 * The contract test: argument parsing, HTTP transport and the steps.
 */
final class WPLC_Client_Contract_Test {

	const OPTION_KEY = 'wplc_contract_test_license';

	const USAGE = "Usage: php bin/client-contract-test.php --server=https://shop.example --product=my-addon --key=LICENSE-KEY --public-key=BASE64 [--public-key=BASE64] [--site=https://contract-test.local] [--environment=development|staging|production] [--version=0.0.1] [--rest-base=URL] [--timeout=20] [--allow-insecure]\n";

	/** @var array<string,mixed> */
	private $options;

	/** @var array<string,mixed> Extra arguments of LicenseClient (tests: transport, clock). */
	private $client_args;

	/** @var callable Receives each output line. */
	private $out;

	/** @var bool */
	private $failed = false;

	/**
	 * Parse the command line.
	 *
	 * @param string[] $argv Arguments without the script name.
	 * @return array{options:array<string,mixed>,errors:string[]}
	 */
	public static function parse_args( array $argv ): array {
		$options = array(
			'server'         => '',
			'product'        => '',
			'key'            => '',
			'public_keys'    => array(),
			'site'           => 'https://contract-test.local',
			'environment'    => 'development',
			'version'        => '0.0.1',
			'rest_base'      => '',
			'timeout'        => 20,
			'allow_insecure' => false,
			'help'           => false,
		);
		$errors  = array();
		$names   = array( 'server', 'product', 'key', 'public-key', 'site', 'environment', 'version', 'rest-base', 'timeout' );
		foreach ( $argv as $argument ) {
			$argument = (string) $argument;
			if ( '--help' === $argument || '-h' === $argument ) {
				$options['help'] = true;
				continue;
			}
			if ( '--allow-insecure' === $argument ) {
				$options['allow_insecure'] = true;
				continue;
			}
			if ( 1 !== preg_match( '/^--([a-z-]+)=(.*)$/s', $argument, $match ) || ! in_array( $match[1], $names, true ) ) {
				$errors[] = sprintf( 'Unknown argument %s.', $argument );
				continue;
			}
			$value = trim( $match[2] );
			switch ( $match[1] ) {
				case 'public-key':
					$options['public_keys'][] = $value;
					break;
				case 'rest-base':
					$options['rest_base'] = $value;
					break;
				case 'timeout':
					$options['timeout'] = ctype_digit( $value ) ? (int) $value : -1;
					break;
				default:
					$options[ $match[1] ] = $value;
			}
		}
		if ( $options['help'] ) {
			return array(
				'options' => $options,
				'errors'  => $errors,
			);
		}

		$scheme = $options['allow_insecure'] ? '#^https?://[^/\s]+#i' : '#^https://[^/\s]+#i';
		if ( 1 !== preg_match( $scheme, (string) $options['server'] ) ) {
			$errors[] = $options['allow_insecure'] ? '--server must be an http(s) address.' : '--server must be an https address (--allow-insecure for a local http server).';
		}
		if ( 1 !== preg_match( '/^[a-z0-9_-]{1,100}$/', (string) $options['product'] ) ) {
			$errors[] = '--product must be the product key of the catalog (a-z, 0-9, - and _).';
		}
		if ( '' === $options['key'] ) {
			$errors[] = '--key (the license key) is required.';
		}
		if ( array() === $options['public_keys'] ) {
			$errors[] = '--public-key (base64 Ed25519 public key of the server, `wp wplc signing-key`) is required.';
		}
		foreach ( $options['public_keys'] as $key ) {
			$binary = base64_decode( (string) $key, true );
			if ( ! is_string( $binary ) || 32 !== strlen( $binary ) ) {
				$errors[] = sprintf( '--public-key %s is not a base64 Ed25519 public key (32 bytes).', $key );
			}
		}
		if ( 1 !== preg_match( '#^https?://[^/\s]+#i', (string) $options['site'] ) ) {
			$errors[] = '--site must be an http(s) address.';
		}
		if ( ! in_array( $options['environment'], array( 'production', 'staging', 'development' ), true ) ) {
			$errors[] = '--environment must be production, staging or development.';
		}
		if ( 1 !== preg_match( '/^[0-9A-Za-z.+-]{1,30}$/', (string) $options['version'] ) ) {
			$errors[] = '--version must be a version number.';
		}
		if ( '' !== $options['rest_base'] && 1 !== preg_match( $scheme, (string) $options['rest_base'] ) ) {
			$errors[] = '--rest-base must be an address like --server.';
		}
		if ( $options['timeout'] < 1 || $options['timeout'] > 120 ) {
			$errors[] = '--timeout must be 1 to 120 seconds.';
		}

		return array(
			'options' => $options,
			'errors'  => $errors,
		);
	}

	/**
	 * @param array<string,mixed> $options     Result of parse_args().
	 * @param array<string,mixed> $client_args Extra arguments of LicenseClient (tests).
	 * @param callable|null       $out         Receives each output line; default STDOUT.
	 */
	public function __construct( array $options, array $client_args = array(), ?callable $out = null ) {
		$this->options     = $options;
		$this->client_args = $client_args;
		$this->out         = $out ?? static function ( string $line ): void {
			fwrite( STDOUT, $line . "\n" );
		};
	}

	/**
	 * Build a client of the library with the given public keys.
	 *
	 * @param string[]            $public_keys Base64 public keys.
	 * @param WPLC_Contract_Store $store       Store.
	 * @return \WPLC\Client\LicenseClient
	 */
	private function client( array $public_keys, WPLC_Contract_Store $store ): \WPLC\Client\LicenseClient {
		$args = array_merge(
			array(
				'public_keys'        => $public_keys,
				'store'              => $store,
				'timeout'            => (int) $this->options['timeout'],
				'allow_insecure'     => (bool) $this->options['allow_insecure'],
				'transport'          => array( self::class, 'http' ),
			),
			'' === $this->options['rest_base'] ? array() : array( 'rest_base' => (string) $this->options['rest_base'] ),
			$this->client_args
		);

		return new \WPLC\Client\LicenseClient( (string) $this->options['server'], (string) $this->options['product'], (string) $this->options['version'], self::OPTION_KEY, $args );
	}

	/**
	 * Run all steps.
	 *
	 * @return int Exit code: 0 all passed, 1 a step failed.
	 */
	public function run(): int {
		$store  = new WPLC_Contract_Store( (string) $this->options['site'] );
		$client = $this->client( (array) $this->options['public_keys'], $store );
		$this->line( sprintf( 'License client %s against %s, product %s, site %s (%s).', \WPLC\Client\LicenseClient::VERSION, (string) $this->options['server'], (string) $this->options['product'], (string) $this->options['site'], (string) $this->options['environment'] ) );

		if ( ! $client->is_configured() ) {
			return $this->fail( 'setup', 'the client does not accept these settings (server address, product key, public keys).' );
		}
		if ( ! $client->can_verify_server_signature() ) {
			return $this->fail( 'setup', 'this PHP cannot verify Ed25519 signatures; install the sodium extension.' );
		}

		$data = $client->activate( (string) $this->options['key'], (string) $this->options['environment'] );
		if ( is_wp_error( $data ) ) {
			return $this->fail( 'activate', self::describe( $data ) );
		}
		$this->check( 'activate', $client->is_activated() && $this->belongs( $data ), sprintf( 'activation %d, license %s, access %s, %d entitlements', (int) ( $data['activation_id'] ?? 0 ), (string) ( $data['license_status'] ?? '?' ), true === ( $data['access'] ?? null ) ? 'yes' : 'no', is_array( $data['entitlements'] ?? null ) ? count( $data['entitlements'] ) : 0 ) );

		$data = $client->check_entitlements();
		if ( is_wp_error( $data ) ) {
			$this->fail( 'check', self::describe( $data ) );
		} else {
			$this->check( 'check', $this->belongs( $data ) && 'deactivated' !== ( $data['activation_status'] ?? '' ), sprintf( 'license %s, offline until %s', (string) ( $data['license_status'] ?? '?' ), (string) ( $data['cache_expires_at'] ?? '?' ) ) );
			$this->signatures( $client, $store );
		}

		$data = $client->check_update( false );
		if ( is_wp_error( $data ) ) {
			$this->fail( 'update check', self::describe( $data ) );
		} else {
			$available = true === ( $data['update_available'] ?? null );
			$detail    = $available ? sprintf( 'version %s available', (string) ( $data['version'] ?? $data['new_version'] ?? '?' ) ) : sprintf( 'no update (%s)', (string) ( $data['reason'] ?? 'up to date' ) );
			$this->check( 'update check', $this->belongs( $data ), $detail );
		}

		$data = $client->deactivate();
		if ( is_wp_error( $data ) ) {
			$this->fail( 'deactivate', self::describe( $data ) . ' Release the activation in the admin of the server.' );
		} else {
			$this->check( 'deactivate', ! $client->is_activated(), 'activation released, local credentials removed' );
		}

		$this->line( $this->failed ? 'Result: FAILED' : 'Result: all steps passed' );

		return $this->failed ? 1 : 0;
	}

	/**
	 * Step 4: the stored answer verifies with the pinned key; a changed payload or another key does not.
	 *
	 * @param \WPLC\Client\LicenseClient $client Client after a successful check.
	 * @param WPLC_Contract_Store        $store  Its store.
	 * @return void
	 */
	private function signatures( \WPLC\Client\LicenseClient $client, WPLC_Contract_Store $store ): void {
		$envelope = $client->last_envelope();
		if ( empty( $envelope['payload'] ) || empty( $envelope['payload_signature_ed25519'] ) ) {
			$this->fail( 'signature', 'the last answer has no Ed25519 payload signature.' );
			return;
		}
		$valid = $client->verify_snapshot( $envelope );
		if ( is_wp_error( $valid ) ) {
			$this->fail( 'signature', 'the last answer does not verify against the pinned key: ' . self::describe( $valid ) );
			return;
		}

		$changed            = $envelope;
		$changed['payload'] = str_replace( '"access":true', '"access":false', (string) $changed['payload'], $count );
		if ( 0 === $count ) {
			$changed['payload'] = (string) $changed['payload'] . ' ';
		}
		$tampered = $client->verify_snapshot( $changed );

		$other_key = base64_encode( str_repeat( "\x01", 32 ) );
		if ( function_exists( 'sodium_crypto_sign_keypair' ) ) {
			$other_key = base64_encode( sodium_crypto_sign_publickey( sodium_crypto_sign_keypair() ) );
		}
		// The same credentials, but a client that pins another key and ignores the HMAC result alone.
		$other       = $this->client( array( $other_key ), $store );
		$wrong_key   = $other->verify_snapshot( $envelope );
		$refused_all = is_wp_error( $tampered ) && is_wp_error( $wrong_key );
		$this->check( 'signature', $refused_all, $refused_all ? 'verified with the pinned key (' . (string) ( $envelope['key_id'] ?? '' ) . '); a changed payload and another key are refused' : 'a changed payload or another key was accepted' );
	}

	/**
	 * Whether signed data names the product under test.
	 *
	 * @param array<string,mixed> $data Signed data.
	 * @return bool
	 */
	private function belongs( array $data ): bool {
		return ( $data['product_key'] ?? null ) === $this->options['product'];
	}

	/**
	 * @param string $step   Step.
	 * @param bool   $passed Result.
	 * @param string $detail Detail.
	 * @return void
	 */
	private function check( string $step, bool $passed, string $detail ): void {
		if ( ! $passed ) {
			$this->fail( $step, $detail );
			return;
		}
		$this->line( sprintf( 'PASS %s: %s', $step, $detail ) );
	}

	/**
	 * @param string $step   Step.
	 * @param string $detail Detail.
	 * @return int 1
	 */
	private function fail( string $step, string $detail ): int {
		$this->failed = true;
		$this->line( sprintf( 'FAIL %s: %s', $step, $detail ) );

		return 1;
	}

	/**
	 * @param string $line Line.
	 * @return void
	 */
	private function line( string $line ): void {
		( $this->out )( $line );
	}

	/**
	 * Code, status and transport detail of an error; never the key or a secret.
	 *
	 * @param \WP_Error $error Error.
	 * @return string
	 */
	public static function describe( $error ): string {
		$data   = (array) $error->get_error_data();
		$detail = (string) $error->get_error_code();
		if ( isset( $data['status'] ) ) {
			$detail .= ' (HTTP ' . (int) $data['status'] . ')';
		}
		if ( ! empty( $data['transport_message'] ) ) {
			$detail .= ': ' . (string) $data['transport_message'];
		}

		return $detail;
	}

	/**
	 * HTTP transport in the shape of wp_remote_request(): curl, or PHP streams without curl.
	 *
	 * @param string              $url  URL.
	 * @param array<string,mixed> $args `method`, `headers`, `body`, `timeout`.
	 * @return array{headers:array<string,string>,body:string,response:array{code:int,message:string}}|\WP_Error
	 */
	public static function http( string $url, array $args ) {
		$headers = array();
		foreach ( (array) ( $args['headers'] ?? array() ) as $name => $value ) {
			$headers[] = $name . ': ' . $value;
		}
		$method  = (string) ( $args['method'] ?? 'GET' );
		$body    = (string) ( $args['body'] ?? '' );
		$timeout = max( 1, (int) ( $args['timeout'] ?? 20 ) );

		if ( function_exists( 'curl_init' ) ) {
			$handle = curl_init( $url );
			curl_setopt_array(
				$handle,
				array(
					CURLOPT_CUSTOMREQUEST  => $method,
					CURLOPT_HTTPHEADER     => $headers,
					CURLOPT_POSTFIELDS     => $body,
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_HEADER         => true,
					CURLOPT_FOLLOWLOCATION => false,
					CURLOPT_TIMEOUT        => $timeout,
					CURLOPT_SSL_VERIFYPEER => true,
					CURLOPT_SSL_VERIFYHOST => 2,
				)
			);
			$raw = curl_exec( $handle );
			if ( false === $raw ) {
				$error = curl_error( $handle );
				return new WP_Error( 'http_request_failed', $error );
			}
			$size = (int) curl_getinfo( $handle, CURLINFO_HEADER_SIZE );

			return self::response( (string) substr( (string) $raw, 0, $size ), (string) substr( (string) $raw, $size ) );
		}

		$context = stream_context_create(
			array(
				'http' => array(
					'method'          => $method,
					'header'          => implode( "\r\n", $headers ),
					'content'         => $body,
					'timeout'         => $timeout,
					'ignore_errors'   => true,
					'follow_location' => 0,
				),
			)
		);
		$result  = @file_get_contents( $url, false, $context ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the error is returned.
		if ( false === $result || ! isset( $http_response_header ) ) {
			$last = error_get_last();
			return new WP_Error( 'http_request_failed', (string) ( $last['message'] ?? 'request failed' ) );
		}

		return self::response( implode( "\r\n", $http_response_header ), $result );
	}

	/**
	 * Turn raw response headers and body into the array the library reads.
	 *
	 * With several header blocks (100 Continue, proxies) the last one counts.
	 *
	 * @param string $head Raw header text.
	 * @param string $body Body.
	 * @return array{headers:array<string,string>,body:string,response:array{code:int,message:string}}
	 */
	public static function response( string $head, string $body ): array {
		$blocks  = preg_split( "/\r?\n\r?\n/", trim( $head ) );
		$block   = is_array( $blocks ) ? (string) end( $blocks ) : '';
		$lines   = preg_split( "/\r?\n/", $block );
		$lines   = is_array( $lines ) ? $lines : array();
		$code    = 0;
		$message = '';
		$headers = array();
		foreach ( $lines as $index => $line ) {
			if ( 1 === preg_match( '#^HTTP/\S+\s+(\d{3})\s*(.*)$#', $line, $match ) ) {
				$code    = (int) $match[1];
				$message = trim( $match[2] );
				$headers = array();
				continue;
			}
			$pair = explode( ':', $line, 2 );
			if ( 2 === count( $pair ) ) {
				$headers[ strtolower( trim( $pair[0] ) ) ] = trim( $pair[1] );
			}
		}

		return array(
			'headers'  => $headers,
			'body'     => $body,
			'response' => array(
				'code'    => $code,
				'message' => $message,
			),
		);
	}
}

if ( isset( $argv[0] ) && realpath( (string) $argv[0] ) === realpath( __FILE__ ) ) {
	$wplc_parsed = WPLC_Client_Contract_Test::parse_args( array_slice( $argv, 1 ) );
	if ( $wplc_parsed['options']['help'] ) {
		fwrite( STDOUT, WPLC_Client_Contract_Test::USAGE );
		exit( 0 );
	}
	if ( array() !== $wplc_parsed['errors'] ) {
		fwrite( STDERR, implode( "\n", $wplc_parsed['errors'] ) . "\n" . WPLC_Client_Contract_Test::USAGE );
		exit( 2 );
	}
	exit( ( new WPLC_Client_Contract_Test( $wplc_parsed['options'] ) )->run() );
}
