<?php
/**
 * Runs the HTTP requests of the bundled client library against the REST server of this process.
 *
 * What is real: the client (LicenseClient, LicenseManager, Updater), its
 * request bodies and signatures, the REST routing, the permission checks, the
 * services and the database of the server, the signed envelopes.
 *
 * What the bridge stands in for, and what is therefore not proven here:
 * - the HTTP layer itself (TLS, web server, redirects, a method override on
 *   POST): the bridge follows the redirect of the download route and applies
 *   `X-HTTP-Method-Override` the way WP_REST_Server::serve_request() does;
 * - the bytes of the package stream: the server code runs up to its status
 *   header, then the bridge copies the file the server was about to send.
 *
 * @package WPLC
 */

/**
 * Base class of the client integration tests.
 */
abstract class WPLC_Client_Bridge_TestCase extends WPLC_Integration_TestCase {

	/**
	 * Requests the client sent, in order: method, route, status.
	 *
	 * @var array<int,array{method:string,route:string,status:int,body:mixed}>
	 */
	protected $http_log = array();

	/**
	 * Answers that replace the server for the next requests (FIFO): arrays in wp_remote format or WP_Error.
	 *
	 * @var array<int,mixed>
	 */
	protected $http_faults = array();

	/**
	 * Address this process acts as (customer site), empty for the default home URL.
	 *
	 * @var string
	 */
	protected $acting_home = '';

	/**
	 * Path the package stream was about to send.
	 *
	 * @var string
	 */
	private $stream_path = '';

	/**
	 * Previous value of $_SERVER['HTTPS'].
	 *
	 * @var mixed
	 */
	private $https_before;

	public function set_up(): void {
		parent::set_up();
		\WPLC\Security\ResponseSigner::reset();
		$this->https_before = $_SERVER['HTTPS'] ?? null;
		// The update API only delivers HTTPS URLs.
		$_SERVER['HTTPS'] = 'on';

		// Handlers that redirect or stream end with exit; the bridge catches them instead.
		$this->halt_instead_of_exit();
		add_filter( 'pre_http_request', array( $this, 'bridge' ), 0, 3 );
		add_filter(
			'home_url',
			function ( $url, $path ) {
				return '' !== $this->acting_home && ( '' === $path || '/' === $path ) ? $this->acting_home : $url;
			},
			10,
			2
		);
		add_filter(
			'wplc_package_offload_header',
			function ( $header, $path ) {
				$this->stream_path = (string) $path;
				return $header;
			},
			10,
			2
		);
	}

	public function tear_down(): void {
		if ( null === $this->https_before ) {
			unset( $_SERVER['HTTPS'] );
		} else {
			$_SERVER['HTTPS'] = $this->https_before;
		}
		\WPLC\Security\ResponseSigner::reset();
		parent::tear_down();
	}

	/**
	 * Base64 public key of this server, as an add-on would pin it.
	 *
	 * @return string
	 */
	protected function server_public_key(): string {
		$key = \WPLC\Security\ResponseSigner::public_key();
		$this->assertNotSame( '', $key, 'Fixture: the server has no signing key.' );

		return $key;
	}

	/**
	 * Routes of the logged requests as "METHOD route" strings; download tokens are masked.
	 *
	 * @return string[]
	 */
	protected function http_routes(): array {
		return array_map(
			static function ( array $entry ): string {
				return $entry['method'] . ' ' . preg_replace( '#/[a-f0-9]{64}$#', '/{token}', $entry['route'] ) . ' ' . $entry['status'];
			},
			$this->http_log
		);
	}

	/**
	 * Filter pre_http_request.
	 *
	 * @param mixed               $pre  Short-circuit value.
	 * @param array<string,mixed> $args Request arguments.
	 * @param string              $url  URL.
	 * @return mixed
	 */
	public function bridge( $pre, $args, $url ) {
		if ( false !== $pre ) {
			return $pre;
		}
		$route = $this->route_of( (string) $url );
		if ( '' === $route ) {
			if ( false !== strpos( (string) $url, 'api.wordpress.org' ) ) {
				// wp_update_plugins() asks wordpress.org first; it knows nothing about our add-on.
				return $this->http_response( 200, '{"plugins":[],"themes":[],"translations":[],"no_update":[]}' );
			}
			return new WP_Error( 'http_request_blocked', 'HTTP is blocked in tests: ' . $url );
		}
		$method = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
		if ( ! empty( $this->http_faults ) ) {
			$fault            = array_shift( $this->http_faults );
			$this->http_log[] = array(
				'method' => $method,
				'route'  => $route,
				'status' => is_wp_error( $fault ) ? 0 : (int) $fault['response']['code'],
				'body'   => null,
			);
			return $fault;
		}

		return $this->dispatch_http( $method, $route, $args, 0 );
	}

	/**
	 * Wrap an answer in the wp_remote format.
	 *
	 * @param int                  $status  Status.
	 * @param string               $body    Body.
	 * @param array<string,string> $headers Headers.
	 * @return array<string,mixed>
	 */
	protected function http_response( int $status, string $body, array $headers = array() ): array {
		return array(
			'headers'  => array_merge( array( 'content-type' => 'application/json; charset=UTF-8' ), array_change_key_case( $headers ) ),
			'body'     => $body,
			'response' => array(
				'code'    => $status,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * REST route of a URL of this API, or an empty string.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function route_of( string $url ): string {
		$parts = wp_parse_url( $url );
		$query = array();
		parse_str( (string) ( $parts['query'] ?? '' ), $query );
		if ( isset( $query['rest_route'] ) ) {
			$route = (string) $query['rest_route'];
		} else {
			$path  = (string) ( $parts['path'] ?? '' );
			$route = false === strpos( $path, '/wp-json/' ) ? '' : substr( $path, strpos( $path, '/wp-json/' ) + 8 );
		}

		return 0 === strpos( $route, '/wplc/v1/' ) ? $route : '';
	}

	/**
	 * Dispatch one request through the REST server.
	 *
	 * @param string              $method    HTTP method.
	 * @param string              $route     REST route.
	 * @param array<string,mixed> $args      Request arguments of the client.
	 * @param int                 $redirects Redirects followed so far.
	 * @return array<string,mixed>|WP_Error
	 */
	private function dispatch_http( string $method, string $route, array $args, int $redirects ) {
		$headers = array_change_key_case( (array) ( $args['headers'] ?? array() ) );
		if ( 'POST' === $method && isset( $headers['x-http-method-override'] ) ) {
			// WP_REST_Server::serve_request() does the same before it dispatches.
			$effective = strtoupper( (string) $headers['x-http-method-override'] );
		} else {
			$effective = $method;
		}
		$request = new WP_REST_Request( $effective, $route );
		foreach ( $headers as $name => $value ) {
			$request->set_header( (string) $name, (string) $value );
		}
		$request->set_body( is_string( $args['body'] ?? null ) ? $args['body'] : '' );

		$this->stream_path = '';
		try {
			$response = rest_ensure_response( rest_get_server()->dispatch( $request ) );
		} catch ( WPLC_Test_Halt $halt ) {
			$this->http_log[] = array(
				'method' => $method,
				'route'  => $route,
				'status' => $halt->status,
				'body'   => null,
			);
			if ( 'redirect' === $halt->kind ) {
				$next = $this->route_of( $halt->location );
				if ( '' === $next || $redirects >= 3 ) {
					return new WP_Error( 'http_request_failed', 'Redirect to a location outside this API: ' . $halt->location );
				}
				return $this->dispatch_http( 'GET', $next, array_merge( $args, array( 'headers' => array() ) ), $redirects + 1 );
			}
			if ( 200 === $halt->status && '' !== $this->stream_path && ! empty( $args['filename'] ) ) {
				copy( $this->stream_path, (string) $args['filename'] );
			}
			$answer             = $this->http_response( $halt->status, '', array( 'content-type' => 'application/zip' ) );
			$answer['filename'] = $args['filename'] ?? null;

			return $answer;
		}

		$status           = $response->get_status();
		$data             = rest_get_server()->response_to_data( $response, false );
		$this->http_log[] = array(
			'method' => $method,
			'route'  => $route,
			'status' => $status,
			'body'   => json_decode( (string) $request->get_body(), true ),
		);

		return $this->http_response( $status, null === $data ? '' : (string) wp_json_encode( $data ), (array) $response->get_headers() );
	}
}
