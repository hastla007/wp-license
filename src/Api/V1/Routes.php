<?php
/**
 * Registers the public versioned license API.
 *
 * @package WPLC
 */

namespace WPLC\Api\V1;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress REST route registration.
 */
final class Routes {

	/**
	 * REST namespace of the API.
	 */
	public const REST_NAMESPACE = 'wplc/v1';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_action( 'wplc_cleanup_security_rows', array( Authenticator::class, 'cleanup_security_rows' ) );
		add_action( 'wplc_daily_lifecycle', array( UpdateService::class, 'purge_expired_grants' ) );
		// Errors are WP_Error objects until here; every answer of the API leaves through these two filters.
		add_filter( 'rest_request_after_callbacks', array( self::class, 'finish_response' ), 999, 3 );
		add_filter( 'rest_post_dispatch', array( self::class, 'finish_unrouted_response' ), 999, 3 );
		// Not before `init`: wp_schedule_event() runs the `cron_schedules` filter, and
		// callbacks of other plugins translate their schedule names there.
		if ( did_action( 'init' ) ) {
			self::schedule_cleanup_event();
		} else {
			add_action( 'init', array( self::class, 'schedule_cleanup_event' ) );
		}
	}

	/**
	 * Schedule the hourly cleanup of replay-protection and rate-limit rows.
	 *
	 * @return void
	 */
	public static function schedule_cleanup_event(): void {
		if ( ! wp_next_scheduled( 'wplc_cleanup_security_rows' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'wplc_cleanup_security_rows' );
		}
	}

	/**
	 * Register v1 endpoints.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		// Registered as strings so the REST server rejects arrays and objects
		// with 400 instead of storing "Array" (B-175).
		$version_args = array(
			'product_version' => array( 'required' => false, 'type' => 'string' ),
			'wp_version' => array( 'required' => false, 'type' => 'string' ),
			'php_version' => array( 'required' => false, 'type' => 'string' ),
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/updates/check',
			array(
				'methods' => 'POST',
				'callback' => array( UpdateService::class, 'check' ),
				'permission_callback' => '__return_true',
				'args' => array(
					'product_key' => array( 'required' => true, 'type' => 'string' ),
					'current_version' => array( 'required' => true, 'type' => 'string' ),
				),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/updates/download/(?P<token>[a-f0-9]{64})',
			array(
				'methods' => 'GET',
				'callback' => array( UpdateService::class, 'download' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/activations',
			array(
				'methods' => 'POST',
				'callback' => array( ActivationService::class, 'activate' ),
				'permission_callback' => '__return_true',
				'args' => array_merge(
					array(
						'license_key' => array( 'required' => true, 'type' => 'string' ),
						'product_key' => array( 'required' => true, 'type' => 'string' ),
						'installation_id' => array( 'required' => true, 'type' => 'string' ),
						'installation_secret' => array( 'required' => true, 'type' => 'string' ),
						'site_url' => array( 'required' => true, 'type' => 'string' ),
						'environment' => array( 'required' => false, 'type' => 'string', 'default' => 'production' ),
						'staging_parent_installation_id' => array( 'required' => false, 'type' => 'string' ),
					),
					$version_args
				),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/activations/(?P<activation_id>\d+)',
			array(
				'methods' => 'DELETE',
				'callback' => array( ActivationService::class, 'deactivate' ),
				'permission_callback' => '__return_true',
				'args' => array(
					'site_url' => array( 'required' => false, 'type' => 'string' ),
				),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/entitlements/check',
			array(
				'methods' => 'POST',
				'callback' => array( EntitlementService::class, 'check' ),
				'permission_callback' => '__return_true',
				'args' => array_merge(
					array(
						'site_url' => array( 'required' => false, 'type' => 'string' ),
					),
					$version_args
				),
			)
		);
		StripeWebhook::register_routes();
	}

	/**
	 * Whether a REST route belongs to this API.
	 *
	 * @param string $route REST route.
	 * @return bool
	 */
	public static function is_api_route( string $route ): bool {
		$prefix = '/' . self::REST_NAMESPACE;

		return $route === $prefix || 0 === strpos( $route, $prefix . '/' );
	}

	/**
	 * Give every answer of the API its cache headers and trace rejected requests.
	 *
	 * Errors too must never be stored by a page cache or CDN: a cached 401 or
	 * 429 would be served to the next installation (B-109).
	 *
	 * @param mixed            $response Result of the route callback: response, WP_Error, or raw data.
	 * @param mixed            $handler  Matched route handler.
	 * @param \WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public static function finish_response( $response, $handler, $request ) {
		if ( ! $request instanceof \WP_REST_Request || ! self::is_api_route( (string) $request->get_route() ) ) {
			return $response;
		}

		if ( is_wp_error( $response ) ) {
			$response = rest_convert_error_to_response( $response );
		} else {
			$response = rest_ensure_response( $response );
		}
		if ( ! $response instanceof \WP_REST_Response ) {
			return $response;
		}

		Response::no_store( $response );

		$status = (int) $response->get_status();
		if ( $status >= 400 ) {
			$data = $response->get_data();
			$code = is_array( $data ) && isset( $data['code'] ) && is_string( $data['code'] ) ? $data['code'] : 'http_' . $status;
			if ( 429 === $status || 503 === $status ) {
				$retry_after = is_array( $data ) && isset( $data['data']['retry_after'] ) ? (int) $data['data']['retry_after'] : 60;
				$response->header( 'Retry-After', (string) max( 1, $retry_after ) );
			}
			ApiLog::rejected( $code, $status, (string) $request->get_method(), (string) $request->get_route() );
		}

		return $response;
	}

	/**
	 * Cache headers for answers that never reached a route callback (unknown route or method).
	 *
	 * @param mixed            $response Response.
	 * @param mixed            $server   REST server.
	 * @param \WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public static function finish_unrouted_response( $response, $server, $request ) {
		if ( $response instanceof \WP_REST_Response && $request instanceof \WP_REST_Request && self::is_api_route( (string) $request->get_route() ) ) {
			Response::no_store( $response );
		}

		return $response;
	}
}
