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
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_action( 'wplc_cleanup_security_rows', array( Authenticator::class, 'cleanup_security_rows' ) );
		add_action( 'wplc_daily_lifecycle', array( UpdateService::class, 'purge_expired_grants' ) );
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
		register_rest_route(
			'wplc/v1',
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
			'wplc/v1',
			'/updates/download/(?P<token>[a-f0-9]{64})',
			array(
				'methods' => 'GET',
				'callback' => array( UpdateService::class, 'download' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'wplc/v1',
			'/activations',
			array(
				'methods' => 'POST',
				'callback' => array( ActivationService::class, 'activate' ),
				'permission_callback' => '__return_true',
				'args' => array(
					'license_key' => array( 'required' => true, 'type' => 'string' ),
					'product_key' => array( 'required' => true, 'type' => 'string' ),
					'installation_id' => array( 'required' => true, 'type' => 'string' ),
					'installation_secret' => array( 'required' => true, 'type' => 'string' ),
					'site_url' => array( 'required' => true, 'type' => 'string' ),
					'environment' => array( 'required' => false, 'type' => 'string', 'default' => 'production' ),
					'staging_parent_installation_id' => array( 'required' => false, 'type' => 'string' ),
				),
			)
		);
		register_rest_route(
			'wplc/v1',
			'/activations/(?P<activation_id>\d+)',
			array(
				'methods' => 'DELETE',
				'callback' => array( ActivationService::class, 'deactivate' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'wplc/v1',
			'/entitlements/check',
			array(
				'methods' => 'POST',
				'callback' => array( EntitlementService::class, 'check' ),
				'permission_callback' => '__return_true',
			)
		);
		StripeWebhook::register_routes();
	}
}
