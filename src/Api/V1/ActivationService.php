<?php
/**
 * Activation and deactivation operations.
 *
 * @package WPLC
 */

namespace WPLC\Api\V1;

use WPLC\Infrastructure\Database\Schema;
use WPLC\License\ActivationManager;
use WPLC\License\AuditLog;
use WPLC\License\LicenseKey;
use WPLC\License\LicenseLifecycle;
use WPLC\Security\SecretCrypto;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles first-time activation and signed deactivation requests.
 *
 * Error messages are stable English texts and are not translated; clients
 * decide by the error `code` (B-23). The codes are listed in docs/API-V1.md.
 */
final class ActivationService {
	/** @var int Failed anonymous activation attempts per client address and minute. */
	public const ATTEMPTS_PER_IP = 30;

	/** @var int Activation attempts per license-key prefix and minute. */
	public const ATTEMPTS_PER_KEY = 20;

	/** @var int Length of the normalized key prefix (`WPLC-` plus eight characters). */
	private const KEY_PREFIX_LENGTH = 13;

	/** @var int Development activations every license may hold, whatever its live-site limit. */
	public const DEVELOPMENT_SITES_MIN = 5;

	/** @var string Characters and length of an installation secret (B-75). */
	public const SECRET_PATTERN = '/^[A-Za-z0-9._~+\/=-]{32,128}$/';

	/** @var string[] Accepted values of `environment`. */
	public const ENVIRONMENTS = array( 'production', 'staging', 'development' );

	/**
	 * Activate one WordPress installation.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function activate( \WP_REST_Request $request ) {
		global $wpdb;

		$license_key = LicenseKey::normalize( self::string_param( $request, 'license_key' ) );
		$product_key = strtolower( trim( self::string_param( $request, 'product_key' ) ) );
		$installation_uuid = strtolower( trim( self::string_param( $request, 'installation_id' ) ) );
		// Validated, never rewritten: a sanitized secret would differ from the one the client keeps (B-75).
		$secret = self::string_param( $request, 'installation_secret' );
		$site_url = SiteUrl::normalize( self::string_param( $request, 'site_url' ) );
		$environment = self::string_param( $request, 'environment' );
		$environment = '' === $environment ? 'production' : $environment;
		$parent_uuid = strtolower( trim( self::string_param( $request, 'staging_parent_installation_id' ) ) );

		$invalid = array();
		if ( '' === $license_key ) {
			$invalid[] = 'license_key';
		}
		if ( ! preg_match( '/^[a-z0-9_-]{1,100}$/', $product_key ) ) {
			$invalid[] = 'product_key';
		}
		if ( ! wp_is_uuid( $installation_uuid ) ) {
			$invalid[] = 'installation_id';
		}
		if ( ! preg_match( self::SECRET_PATTERN, $secret ) ) {
			$invalid[] = 'installation_secret';
		}
		if ( '' === $site_url ) {
			$invalid[] = 'site_url';
		}
		// Exact match: "Production" or "pro duction" must not silently become a live activation (B-72).
		if ( ! in_array( $environment, self::ENVIRONMENTS, true ) ) {
			$invalid[] = 'environment';
		}
		if ( $invalid ) {
			return self::anonymous_failure(
				new \WP_Error( 'invalid_activation', 'Required activation fields are missing or invalid.', array( 'status' => 400, 'fields' => $invalid ) )
			);
		}

		// One key cannot be hammered from many addresses.
		$key_attempts = Authenticator::increment_rate_limit( self::key_limiter_hash( $license_key ) );
		if ( false === $key_attempts ) {
			return new \WP_Error( 'rate_limit_store_unavailable', 'Activation rate limiting is temporarily unavailable.', array( 'status' => 503 ) );
		}
		if ( $key_attempts > self::ATTEMPTS_PER_KEY ) {
			return new \WP_Error( 'rate_limited', 'Too many activation attempts. Try again later.', array( 'status' => 429, 'retry_after' => 60 ) );
		}

		$tables = Schema::table_names();
		$license = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$tables['licenses']} WHERE license_key_hash = %s LIMIT 1",
				LicenseKey::hash( $license_key )
			),
			ARRAY_A
		);

		if ( ! is_array( $license ) ) {
			// Only failed attempts count against the client address, so one
			// guessing client does not lock out the valid keys of everybody
			// behind the same address (T-142).
			return self::anonymous_failure( new \WP_Error( 'invalid_key', 'The license key was not recognized.', array( 'status' => 401 ) ) );
		}
		if ( $license['product_key'] !== $product_key ) {
			return new \WP_Error( 'product_mismatch', 'This license belongs to a different product.', array( 'status' => 403 ) );
		}
		if ( ! LicenseLifecycle::has_access( $license ) ) {
			return self::license_status_error( $license );
		}

		if ( 'development' === $environment && ! self::is_local_development_url( $site_url ) ) {
			return new \WP_Error( 'development_site_invalid', 'Development activations must use a recognized local site URL.', array( 'status' => 400 ) );
		}
		if ( 'staging' === $environment && ! wp_is_uuid( $parent_uuid ) ) {
			return new \WP_Error( 'staging_parent_required', 'A staging activation must be linked to a live activation.', array( 'status' => 400 ) );
		}

		$license_id = (int) $license['id'];
		$lock_name = ActivationManager::lock_name( $license_id );
		ActivationManager::use_primary_database();
		$locked = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name ) );
		if ( '1' !== (string) $locked ) {
			return new \WP_Error( 'retry_later', 'The license is being updated. Retry shortly.', array( 'status' => 503, 'retry_after' => 5 ) );
		}

		try {
			return self::activate_locked(
				$request,
				$license,
				array(
					'installation_uuid' => $installation_uuid,
					'secret' => $secret,
					'site_url' => $site_url,
					'environment' => $environment,
					'parent_uuid' => $parent_uuid,
				)
			);
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}

	/**
	 * Create or refresh the activation while the per-license lock is held.
	 *
	 * @param \WP_REST_Request     $request Request.
	 * @param array<string,mixed>  $license License row.
	 * @param array<string,string> $input   Validated input: installation_uuid, secret, site_url, environment, parent_uuid.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private static function activate_locked( \WP_REST_Request $request, array $license, array $input ) {
		global $wpdb;

		$tables = Schema::table_names();
		$license_id = (int) $license['id'];
		$installation_uuid = $input['installation_uuid'];
		$secret = $input['secret'];
		$site_url = $input['site_url'];
		$environment = $input['environment'];

		$active = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id,installation_uuid,site_url,environment,staging_parent_id FROM {$tables['activations']} WHERE license_id = %d AND status = %s ORDER BY id ASC",
				$license_id,
				'active'
			),
			ARRAY_A
		);
		if ( ! is_array( $active ) || '' !== $wpdb->last_error ) {
			return new \WP_Error( 'activation_failed', 'The current activations of this license could not be verified.', array( 'status' => 500 ) );
		}

		// A staging site is licensed through one active live site of the same license.
		$parent = null;
		if ( 'staging' === $environment ) {
			foreach ( $active as $row ) {
				if ( 'production' === $row['environment'] && $row['installation_uuid'] === $input['parent_uuid'] ) {
					$parent = $row;
				}
			}
			if ( null === $parent ) {
				return new \WP_Error( 'staging_parent_invalid', 'The staging site must link to an active live site on this license.', array( 'status' => 409 ) );
			}
		}
		$staging_allowed = null === $parent || SiteUrl::is_staging_of( $site_url, (string) $parent['site_url'] );

		$existing = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$tables['activations']} WHERE installation_uuid = %s LIMIT 1", $installation_uuid ),
			ARRAY_A
		);
		if ( is_array( $existing ) ) {
			return self::refresh_existing( $request, $license, $existing, $input, $parent, $staging_allowed );
		}

		// A "staging" site on a host that is neither related to its live
		// site nor a known staging host is a second live site (B-157).
		$requested_environment = $environment;
		if ( ! $staging_allowed ) {
			$environment = 'production';
			$parent = null;
		}
		$staging_parent_id = null === $parent ? null : (int) $parent['id'];

		// The same site activating with a new installation ID (lost options,
		// database restore) replaces its previous activation instead of
		// occupying a second slot. The caller proved possession of the key.
		// Not for development: `localhost:8888` is a different machine for
		// every developer (B-160).
		$site_key = SiteUrl::comparison_key( $site_url );
		$replaced = array();
		$counts = array(
			'production' => 0,
			'staging' => 0,
			'development' => 0,
		);
		foreach ( $active as $row ) {
			$same_site = 'development' !== $environment
				&& $row['environment'] === $environment
				&& SiteUrl::comparison_key( (string) $row['site_url'] ) === $site_key;
			if ( $same_site ) {
				$replaced[] = (int) $row['id'];
				continue;
			}
			if ( 'staging' === $row['environment'] ) {
				$counts['staging'] += (int) $row['staging_parent_id'] === (int) $staging_parent_id ? 1 : 0;
			} elseif ( isset( $counts[ $row['environment'] ] ) ) {
				++$counts[ $row['environment'] ];
			}
		}

		// Limits are checked before anything is replaced: a site that may not
		// activate must keep the activation it has (B-168).
		$limit = self::site_limit( $license_id, $license );
		if ( 'production' === $environment && $counts['production'] >= $limit ) {
			return new \WP_Error(
				'site_limit',
				'The license has no remaining live site activations.',
				array( 'status' => 409, 'site_limit' => $limit, 'site_count' => $counts['production'], 'environment' => $environment, 'requested_environment' => $requested_environment )
			);
		}
		if ( 'staging' === $environment && $counts['staging'] >= 1 ) {
			return new \WP_Error( 'staging_limit', 'This live site already has its included staging activation.', array( 'status' => 409 ) );
		}
		if ( 'development' === $environment ) {
			$development_limit = self::development_site_limit( $license, $limit );
			if ( $counts['development'] >= $development_limit ) {
				return new \WP_Error(
					'development_limit',
					'The license has no remaining development site activations. Deactivate a development site that is no longer used.',
					array( 'status' => 409, 'development_limit' => $development_limit, 'development_count' => $counts['development'] )
				);
			}
		}

		$encrypted_secret = SecretCrypto::encrypt( $secret );
		if ( false === $encrypted_secret ) {
			return new \WP_Error( 'server_configuration', 'The license server could not securely store the installation credential.', array( 'status' => 500 ) );
		}

		// The new row is written first; only then the replaced activation
		// ends. A failing insert leaves the site with its old activation (A-06).
		$now = gmdate( 'Y-m-d H:i:s' );
		$inserted = $wpdb->insert(
			$tables['activations'],
			array(
				'license_id' => $license_id,
				'installation_uuid' => $installation_uuid,
				'installation_secret_hash' => hash( 'sha256', $secret ),
				'installation_secret_encrypted' => $encrypted_secret,
				'site_url' => $site_url,
				'environment' => $environment,
				'staging_parent_id' => $staging_parent_id,
				'product_version' => EntitlementService::version_param( $request, 'product_version' ),
				'wp_version' => EntitlementService::version_param( $request, 'wp_version' ),
				'php_version' => EntitlementService::version_param( $request, 'php_version' ),
				'status' => 'active',
				'activated_at' => $now,
				'last_seen_at' => $now,
				'deactivated_at' => null,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			if ( false !== stripos( (string) $wpdb->last_error, 'duplicate' ) ) {
				return new \WP_Error( 'activation_conflict', 'The installation could not be activated. Retry the request.', array( 'status' => 409 ) );
			}
			return new \WP_Error( 'activation_failed', 'The activation could not be stored. Retry the request.', array( 'status' => 500 ) );
		}

		$activation_id = (int) $wpdb->insert_id;
		foreach ( $replaced as $replaced_id ) {
			// The staging copy of a replaced live site moves to the new activation (B-167).
			$reparent_to = 'production' === $environment ? $activation_id : 0;
			if ( ! ActivationManager::deactivate_while_locked( $replaced_id, 'Replaced by a new installation of the same site', 0, 'site_replaced', $reparent_to ) ) {
				$wpdb->delete( $tables['activations'], array( 'id' => $activation_id ), array( '%d' ) );
				return new \WP_Error( 'activation_failed', 'The previous activation of this site could not be replaced. Retry the request.', array( 'status' => 500 ) );
			}
		}

		$after = array( 'license_id' => $license_id, 'site_url' => $site_url, 'environment' => $environment );
		if ( $requested_environment !== $environment ) {
			$after['requested_environment'] = $requested_environment;
		}
		AuditLog::record(
			'site_activated',
			'activation',
			$activation_id,
			$requested_environment !== $environment ? 'Product installation activated; the staging host is not related to the live site and counts as a live site' : 'Product installation activated',
			null,
			$after
		);

		return self::signed_entitlement_response( $license_id, $activation_id, $installation_uuid, $secret );
	}

	/**
	 * Answer a repeated activation of a known installation ID.
	 *
	 * @param \WP_REST_Request         $request         Request.
	 * @param array<string,mixed>      $license         License row.
	 * @param array<string,mixed>      $existing        Activation row with this installation ID.
	 * @param array<string,string>     $input           Validated input.
	 * @param array<string,mixed>|null $parent          Live activation named by a staging request.
	 * @param bool                     $staging_allowed Whether the host is acceptable for a staging site.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private static function refresh_existing( \WP_REST_Request $request, array $license, array $existing, array $input, ?array $parent, bool $staging_allowed ) {
		global $wpdb;

		$tables = Schema::table_names();
		$environment = $input['environment'];
		$conflict = (int) $existing['license_id'] !== (int) $license['id']
			|| ! hash_equals( (string) $existing['installation_secret_hash'], hash( 'sha256', $input['secret'] ) );
		if ( ! $conflict ) {
			if ( $existing['environment'] === $environment ) {
				$conflict = (int) $existing['staging_parent_id'] !== ( null === $parent ? 0 : (int) $parent['id'] );
			} else {
				// A staging request that was counted as a live site keeps answering for that live activation.
				$conflict = ! ( 'staging' === $environment && 'production' === $existing['environment'] && ! $staging_allowed );
			}
		}
		if ( $conflict ) {
			return new \WP_Error( 'installation_conflict', 'This installation ID is already assigned to another credential.', array( 'status' => 409 ) );
		}
		if ( 'active' !== $existing['status'] ) {
			return new \WP_Error( 'activation_deactivated', 'This installation was deactivated. Generate a new installation ID to activate again.', array( 'status' => 409 ) );
		}
		if ( 'staging' === $existing['environment'] && ! $staging_allowed && (string) $existing['site_url'] !== $input['site_url'] ) {
			return new \WP_Error( 'staging_site_invalid', 'A staging activation cannot move to a host that is unrelated to its live site.', array( 'status' => 409 ) );
		}

		$changes = array( 'site_url' => $input['site_url'], 'last_seen_at' => gmdate( 'Y-m-d H:i:s' ) );
		foreach ( array( 'product_version', 'wp_version', 'php_version' ) as $version_field ) {
			$reported = EntitlementService::version_param( $request, $version_field );
			if ( '' !== $reported ) {
				$changes[ $version_field ] = $reported;
			}
		}
		$updated = $wpdb->update(
			$tables['activations'],
			$changes,
			array( 'id' => (int) $existing['id'], 'status' => 'active' ),
			array_fill( 0, count( $changes ), '%s' ),
			array( '%d', '%s' )
		);
		if ( false === $updated ) {
			return new \WP_Error( 'activation_failed', 'The activation could not be updated. Retry the request.', array( 'status' => 500 ) );
		}
		if ( (string) $existing['site_url'] !== $input['site_url'] ) {
			// The address of an installation is part of its history (A-77).
			AuditLog::record(
				'site_url_changed',
				'activation',
				(int) $existing['id'],
				'Site address changed by a repeated activation',
				array( 'license_id' => (int) $license['id'], 'site_url' => (string) $existing['site_url'] ),
				array( 'site_url' => $input['site_url'] )
			);
		}

		return self::signed_entitlement_response( (int) $license['id'], (int) $existing['id'], $input['installation_uuid'], $input['secret'] );
	}

	/**
	 * Deactivate an authenticated installation.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function deactivate( \WP_REST_Request $request ) {
		global $wpdb;

		$activation = Authenticator::authenticate( $request, true );
		if ( is_wp_error( $activation ) ) {
			return $activation;
		}
		if ( (int) $activation['id'] !== absint( $request->get_param( 'activation_id' ) ) ) {
			return new \WP_Error( 'activation_mismatch', 'The authenticated installation does not match this activation.', array( 'status' => 403 ) );
		}

		$confirmation = Response::signed(
			array( 'activation_id' => (int) $activation['id'], 'status' => 'deactivated' ),
			(string) $activation['installation_secret'],
			200,
			(string) $activation['request_nonce'],
			(string) $activation['installation_uuid']
		);
		if ( 'active' !== (string) $activation['status'] ) {
			// Already released (customer account, admin, replacement): the
			// client gets the same signed confirmation and can drop its
			// local credentials.
			return $confirmation;
		}

		// A copy of the site (staging clone with the copied credentials) must
		// not end the activation of the original: when the client states its
		// address, it has to be the activated one (B-69).
		$reported_url = $request->get_param( 'site_url' );
		if ( is_string( $reported_url ) && '' !== $reported_url ) {
			$reported_key = SiteUrl::comparison_key( $reported_url );
			if ( '' === $reported_key || SiteUrl::comparison_key( (string) $activation['site_url'] ) !== $reported_key ) {
				return new \WP_Error( 'site_mismatch', 'This activation belongs to another site address and was not deactivated.', array( 'status' => 409 ) );
			}
		}

		$lock_name = ActivationManager::lock_name( (int) $activation['license_id'] );
		ActivationManager::use_primary_database();
		$locked = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name ) );
		if ( '1' !== (string) $locked ) {
			return new \WP_Error( 'retry_later', 'The license is being updated. Retry shortly.', array( 'status' => 503, 'retry_after' => 5 ) );
		}

		try {
			if ( ! ActivationManager::deactivate_while_locked( (int) $activation['id'], 'Deactivated by the installation owner' ) ) {
				return new \WP_Error( 'deactivation_failed', 'The installation could not be deactivated.', array( 'status' => 409 ) );
			}

			return $confirmation;
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}

	/**
	 * Build and sign the current entitlement payload.
	 *
	 * @param int $license_id License ID.
	 * @param int $activation_id Activation ID.
	 * @param string $installation_uuid Installation UUID.
	 * @param string $secret Installation secret.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private static function signed_entitlement_response( int $license_id, int $activation_id, string $installation_uuid, string $secret ) {
		global $wpdb;

		$tables = Schema::table_names();
		$license = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$tables['licenses']} WHERE id = %d LIMIT 1", $license_id ),
			ARRAY_A
		);
		$activation = $wpdb->get_row(
			$wpdb->prepare( "SELECT id,installation_uuid,environment,staging_parent_id FROM {$tables['activations']} WHERE id = %d LIMIT 1", $activation_id ),
			ARRAY_A
		);
		if ( ! is_array( $license ) || ! is_array( $activation ) ) {
			return new \WP_Error( 'activation_failed', 'The activation could not be read back. Retry the request.', array( 'status' => 500 ) );
		}

		// Activation is not HMAC-authenticated; the installation ID the client
		// just generated takes the place of the request nonce.
		return Response::signed( EntitlementService::snapshot( $license, $activation ), $secret, 200, $installation_uuid, $installation_uuid );
	}

	/**
	 * Distinct refusal for a license that grants no access (B-72).
	 *
	 * @param array<string,mixed> $license License row.
	 * @return \WP_Error
	 */
	private static function license_status_error( array $license ): \WP_Error {
		$status = (string) $license['status'];
		$data = array( 'status' => 403, 'license_status' => in_array( $status, array( 'active', 'past_due' ), true ) ? 'expired' : $status );
		if ( 'suspended' === $status ) {
			return new \WP_Error( 'license_suspended', 'This license is suspended.', $data );
		}
		if ( 'revoked' === $status ) {
			return new \WP_Error( 'license_revoked', 'This license was revoked.', $data );
		}
		if ( in_array( $status, array( 'active', 'past_due', 'expired' ), true ) ) {
			return new \WP_Error( 'license_expired', 'This license has expired.', $data );
		}

		return new \WP_Error( 'license_inactive', 'This license is not active.', $data );
	}

	/**
	 * Restrict development activations to local development hosts.
	 *
	 * @param string $site_url Normalized site URL.
	 * @return bool
	 */
	private static function is_local_development_url( string $site_url ): bool {
		$allowed = SiteUrl::is_local_host( SiteUrl::host( $site_url ) );

		/**
		 * Allow a shop owner to explicitly approve a custom development hostname.
		 *
		 * @param bool   $allowed  Whether the hostname is local.
		 * @param string $site_url Normalized site URL.
		 */
		return (bool) apply_filters( 'wplc_is_local_development_url', $allowed, $site_url );
	}

	/**
	 * Number of development activations a license may hold at the same time (A-33).
	 *
	 * Development sites do not use a live slot, and their host is only
	 * checked by its name, so they are capped: twice the live-site limit,
	 * at least DEVELOPMENT_SITES_MIN.
	 *
	 * @param array<string,mixed> $license    License row.
	 * @param int                 $site_limit Live-site limit of the license.
	 * @return int
	 */
	public static function development_site_limit( array $license, int $site_limit ): int {
		$limit = max( self::DEVELOPMENT_SITES_MIN, 2 * $site_limit );

		/**
		 * Filter the number of development activations per license. Zero forbids them.
		 *
		 * @param int                 $limit      Default: twice the live-site limit, at least five.
		 * @param array<string,mixed> $license    License row.
		 * @param int                 $site_limit Live-site limit.
		 */
		return max( 0, (int) apply_filters( 'wplc_development_site_limit', $limit, $license, $site_limit ) );
	}

	/**
	 * Resolve the live site limit from a license entitlement.
	 *
	 * @param int $license_id License ID.
	 * @param array<string,mixed> $license License row.
	 * @return int
	 */
	public static function site_limit( int $license_id, array $license ): int {
		global $wpdb;

		$tables = Schema::table_names();
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT value_json FROM {$tables['entitlements']} WHERE license_id = %d AND entitlement_key = %s AND status = %s LIMIT 1",
				$license_id,
				'sites.live_limit',
				'active'
			)
		);
		if ( null !== $value ) {
			$decoded = json_decode( (string) $value, true );

			return max( 1, is_numeric( $decoded ) && is_finite( (float) $decoded ) ? absint( $decoded ) : 0 );
		}

		$limit = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT live_site_limit FROM {$tables['plans']} WHERE product_key = %s AND plan_key = %s AND active = 1 ORDER BY id DESC LIMIT 1",
				(string) $license['product_key'],
				(string) $license['plan_key']
			)
		);

		return max( 1, absint( $limit ) );
	}

	/**
	 * Read a request parameter as a string; arrays and objects count as missing.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param string           $name    Parameter name.
	 * @return string
	 */
	private static function string_param( \WP_REST_Request $request, string $name ): string {
		$value = $request->get_param( $name );

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Count a failed anonymous attempt against the client address.
	 *
	 * The address comes from ClientIp (REMOTE_ADDR, or a configured trusted
	 * proxy header). IPv6 clients share one bucket per /64. A request without a
	 * resolvable address lands in a shared bucket instead of failing for
	 * everyone.
	 *
	 * @param \WP_Error $error The error of this attempt.
	 * @return \WP_Error The same error, or `rate_limited` once the address used up its attempts.
	 */
	private static function anonymous_failure( \WP_Error $error ): \WP_Error {
		$bucket = ClientIp::rate_limit_bucket( ClientIp::current() );
		if ( '' === $bucket ) {
			$bucket = 'unknown';
		}

		$attempts = Authenticator::increment_rate_limit( Authenticator::limiter_hash( 'activation-ip', $bucket ) );
		if ( false === $attempts ) {
			return new \WP_Error( 'rate_limit_store_unavailable', 'Activation rate limiting is temporarily unavailable.', array( 'status' => 503 ) );
		}
		if ( $attempts > self::ATTEMPTS_PER_IP ) {
			return new \WP_Error( 'rate_limited', 'Too many activation attempts. Try again later.', array( 'status' => 429, 'retry_after' => 60 ) );
		}

		return $error;
	}

	/**
	 * Limiter key for a license-key prefix; never stores key material.
	 *
	 * @param string $license_key License key.
	 * @return string Limiter hash.
	 */
	public static function key_limiter_hash( string $license_key ): string {
		return Authenticator::limiter_hash( 'activation-key', substr( LicenseKey::normalize( $license_key ), 0, self::KEY_PREFIX_LENGTH ) );
	}
}
