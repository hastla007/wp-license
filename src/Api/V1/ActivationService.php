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
use WPLC\License\AuditReason;
use WPLC\License\LicenseKey;
use WPLC\License\LicenseLifecycle;
use WPLC\Security\SecretCrypto;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles first-time activation and signed deactivation requests.
 */
final class ActivationService {
	/** @var int Failed anonymous activation attempts per client address and minute. */
	private const ATTEMPTS_PER_IP = 30;

	/** @var int Failed activation attempts per license-key prefix and minute. */
	private const ATTEMPTS_PER_KEY = 20;

	/** @var int Length of the normalized key prefix (`WPLC-` plus eight characters). */
	private const KEY_PREFIX_LENGTH = 13;

	/**
	 * Activate one WordPress installation.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function activate( \WP_REST_Request $request ) {
		global $wpdb;

		$license_key = strtoupper( trim( sanitize_text_field( (string) $request->get_param( 'license_key' ) ) ) );
		$product_key = sanitize_key( (string) $request->get_param( 'product_key' ) );
		$installation_uuid = strtolower( sanitize_text_field( (string) $request->get_param( 'installation_id' ) ) );
		$secret = sanitize_text_field( (string) $request->get_param( 'installation_secret' ) );
		$site_url = self::normalize_site_url( (string) $request->get_param( 'site_url' ) );
		$environment = sanitize_key( (string) ( $request->get_param( 'environment' ) ?: 'production' ) );
		$parent_uuid = strtolower( sanitize_text_field( (string) $request->get_param( 'staging_parent_installation_id' ) ) );

		// T-142: only failed attempts count, per client address and per key
		// prefix (one key cannot be hammered from many addresses). A valid key
		// is not blocked by other people's failures; see ActivationRateLimit.
		$limiters   = self::attempt_limiters( $license_key );
		$rate_state = ActivationRateLimit::check( $limiters );
		if ( ActivationRateLimit::UNAVAILABLE === $rate_state ) {
			return self::rate_limit_unavailable();
		}
		if ( ActivationRateLimit::BLOCKED === $rate_state ) {
			return self::rate_limited();
		}
		if ( '' === $license_key || '' === $product_key || ! wp_is_uuid( $installation_uuid ) || strlen( $secret ) < 32 || strlen( $secret ) > 128 || '' === $site_url || ! in_array( $environment, array( 'production', 'staging', 'development' ), true ) ) {
			return self::failed_attempt( $limiters, $rate_state, new \WP_Error( 'invalid_activation', __( 'Required activation fields are missing or invalid.', 'wp-license' ), array( 'status' => 400 ) ) );
		}

		if ( 'development' === $environment && ! self::is_local_development_url( $site_url ) ) {
			return self::failed_attempt( $limiters, $rate_state, new \WP_Error( 'development_site_invalid', __( 'Development activations must use a recognized local site URL.', 'wp-license' ), array( 'status' => 400 ) ) );
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
			return self::failed_attempt( $limiters, $rate_state, new \WP_Error( 'invalid_key', __( 'The license key was not recognized.', 'wp-license' ), array( 'status' => 401 ) ) );
		}
		if ( $license['product_key'] !== $product_key ) {
			return new \WP_Error( 'product_mismatch', __( 'This license belongs to a different product.', 'wp-license' ), array( 'status' => 403 ) );
		}
		if ( ! LicenseLifecycle::has_access( $license ) ) {
			return new \WP_Error( 'expired', __( 'This license is not currently active.', 'wp-license' ), array( 'status' => 403 ) );
		}

		$staging_parent_id = null;
		if ( 'staging' === $environment ) {
			if ( ! wp_is_uuid( $parent_uuid ) ) {
				return new \WP_Error( 'staging_parent_required', __( 'A staging activation must be linked to a live activation.', 'wp-license' ), array( 'status' => 400 ) );
			}
			$parent = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id FROM {$tables['activations']} WHERE installation_uuid = %s AND license_id = %d AND environment = %s AND status = %s LIMIT 1",
					$parent_uuid,
					(int) $license['id'],
					'production',
					'active'
				),
				ARRAY_A
			);
			if ( ! is_array( $parent ) ) {
				return new \WP_Error( 'staging_parent_invalid', __( 'The staging site must link to an active live site on this license.', 'wp-license' ), array( 'status' => 409 ) );
			}
			$staging_parent_id = (int) $parent['id'];
		}

		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$tables['activations']} WHERE installation_uuid = %s LIMIT 1",
				$installation_uuid
			),
			ARRAY_A
		);
		if ( is_array( $existing ) ) {
			if (
				(int) $existing['license_id'] !== (int) $license['id']
				|| $existing['environment'] !== $environment
				|| (int) $existing['staging_parent_id'] !== (int) $staging_parent_id
				|| ! hash_equals( (string) $existing['installation_secret_hash'], hash( 'sha256', $secret ) )
			) {
				return new \WP_Error( 'installation_conflict', __( 'This installation ID is already assigned to another credential.', 'wp-license' ), array( 'status' => 409 ) );
			}
			if ( 'active' !== $existing['status'] ) {
				return new \WP_Error( 'activation_deactivated', __( 'This installation was deactivated. Generate a new installation ID to activate again.', 'wp-license' ), array( 'status' => 409 ) );
			}

			$wpdb->update(
				$tables['activations'],
				array( 'site_url' => $site_url, 'last_seen_at' => gmdate( 'Y-m-d H:i:s' ) ),
				array( 'id' => (int) $existing['id'] ),
				array( '%s', '%s' ),
				array( '%d' )
			);

			return self::signed_entitlement_response( (int) $license['id'], (int) $existing['id'], $installation_uuid, $secret );
		}

		$limit = self::site_limit( (int) $license['id'], $license );
		$lock_name = ActivationManager::lock_name( (int) $license['id'] );
		$locked = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name ) );
		if ( '1' !== (string) $locked ) {
			return new \WP_Error( 'retry_later', __( 'The license is being updated. Retry shortly.', 'wp-license' ), array( 'status' => 503 ) );
		}

		try {
			// Recheck under the per-license lock to make retries idempotent and
			// to prevent a staging activation racing with parent deactivation.
			if ( 'staging' === $environment ) {
				$parent = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT id FROM {$tables['activations']} WHERE id = %d AND license_id = %d AND environment = %s AND status = %s LIMIT 1",
						$staging_parent_id,
						(int) $license['id'],
						'production',
						'active'
					),
					ARRAY_A
				);
				if ( ! is_array( $parent ) ) {
					return new \WP_Error( 'staging_parent_invalid', __( 'The staging site must link to an active live site on this license.', 'wp-license' ), array( 'status' => 409 ) );
				}
			}

			$existing = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$tables['activations']} WHERE installation_uuid = %s LIMIT 1", $installation_uuid ),
				ARRAY_A
			);
			if ( is_array( $existing ) ) {
				if (
					(int) $existing['license_id'] !== (int) $license['id']
					|| $existing['environment'] !== $environment
					|| (int) $existing['staging_parent_id'] !== (int) $staging_parent_id
					|| ! hash_equals( (string) $existing['installation_secret_hash'], hash( 'sha256', $secret ) )
				) {
					return new \WP_Error( 'installation_conflict', __( 'This installation ID is already assigned to another credential.', 'wp-license' ), array( 'status' => 409 ) );
				}
				if ( 'active' !== $existing['status'] ) {
					return new \WP_Error( 'activation_deactivated', __( 'This installation was deactivated. Generate a new installation ID to activate again.', 'wp-license' ), array( 'status' => 409 ) );
				}
				$updated = $wpdb->update(
					$tables['activations'],
					array( 'site_url' => $site_url, 'last_seen_at' => gmdate( 'Y-m-d H:i:s' ) ),
					array( 'id' => (int) $existing['id'], 'status' => 'active' ),
					array( '%s', '%s' ),
					array( '%d', '%s' )
				);
				if ( false === $updated ) {
					return new \WP_Error( 'activation_failed', __( 'The activation could not be updated. Retry the request.', 'wp-license' ), array( 'status' => 500 ) );
				}
				return self::signed_entitlement_response( (int) $license['id'], (int) $existing['id'], $installation_uuid, $secret );
			}

			// The same site activating with a new installation ID (lost options,
			// database restore) replaces its previous activation instead of
			// occupying a second slot. The caller proved possession of the key.
			// Rows stored before the T-146 normalization rules may hold the older
			// spelling of the same URL; they are matched as well.
			$site_urls = SiteUrl::identity_candidates( (string) $request->get_param( 'site_url' ) );
			if ( ! in_array( $site_url, $site_urls, true ) ) {
				$site_urls[] = $site_url;
			}
			$url_marks = implode( ',', array_fill( 0, count( $site_urls ), '%s' ) );
			$replaced = $wpdb->get_col(
				$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the values are passed as one array.
					"SELECT id FROM {$tables['activations']} WHERE license_id = %d AND environment = %s AND site_url IN ({$url_marks}) AND status = %s AND installation_uuid <> %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only generated placeholders are interpolated.
					array_merge( array( (int) $license['id'], $environment ), $site_urls, array( 'active', $installation_uuid ) )
				)
			);
			if ( '' !== $wpdb->last_error ) {
				return new \WP_Error( 'activation_failed', __( 'Existing activations of this site could not be verified.', 'wp-license' ), array( 'status' => 500 ) );
			}
			foreach ( is_array( $replaced ) ? $replaced : array() as $replaced_id ) {
				// A replaced live site takes its staging child with it.
				if ( ! ActivationManager::deactivate_while_locked( (int) $replaced_id, AuditReason::INSTALLATION_REPLACED, 0, 'site_replaced' ) ) {
					return new \WP_Error( 'activation_failed', __( 'The previous activation of this site could not be replaced. Retry the request.', 'wp-license' ), array( 'status' => 500 ) );
				}
			}

			if ( 'production' === $environment ) {
				$count_result = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$tables['activations']} WHERE license_id = %d AND status = %s AND environment = %s",
						(int) $license['id'],
						'active',
						'production'
					)
				);
				if ( null === $count_result || '' !== $wpdb->last_error ) {
					return new \WP_Error( 'activation_failed', __( 'The current live-site count could not be verified.', 'wp-license' ), array( 'status' => 500 ) );
				}
				$count = (int) $count_result;
				if ( $count >= $limit ) {
					return new \WP_Error( 'site_limit', __( 'The license has no remaining live site activations.', 'wp-license' ), array( 'status' => 409, 'site_limit' => $limit, 'site_count' => $count ) );
				}
			} elseif ( 'staging' === $environment ) {
				$count_result = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$tables['activations']} WHERE license_id = %d AND staging_parent_id = %d AND status = %s AND environment = %s",
						(int) $license['id'],
						(int) $staging_parent_id,
						'active',
						'staging'
					)
				);
				if ( null === $count_result || '' !== $wpdb->last_error ) {
					return new \WP_Error( 'activation_failed', __( 'The current staging-site count could not be verified.', 'wp-license' ), array( 'status' => 500 ) );
				}
				$count = (int) $count_result;
				if ( $count >= 1 ) {
					return new \WP_Error( 'staging_limit', __( 'This live site already has its included staging activation.', 'wp-license' ), array( 'status' => 409 ) );
				}
			}

			$encrypted_secret = SecretCrypto::encrypt( $secret );
			if ( false === $encrypted_secret ) {
				return new \WP_Error( 'server_configuration', __( 'The license server could not securely store the installation credential.', 'wp-license' ), array( 'status' => 500 ) );
			}

			$now = gmdate( 'Y-m-d H:i:s' );
			$inserted = $wpdb->insert(
				$tables['activations'],
				array(
					'license_id' => (int) $license['id'],
					'installation_uuid' => $installation_uuid,
					'installation_secret_hash' => hash( 'sha256', $secret ),
					'installation_secret_encrypted' => $encrypted_secret,
					'site_url' => $site_url,
					'environment' => $environment,
					'staging_parent_id' => $staging_parent_id,
					'product_version' => sanitize_text_field( (string) $request->get_param( 'product_version' ) ),
					'wp_version' => sanitize_text_field( (string) $request->get_param( 'wp_version' ) ),
					'php_version' => sanitize_text_field( (string) $request->get_param( 'php_version' ) ),
					'status' => 'active',
					'activated_at' => $now,
					'last_seen_at' => $now,
					'deactivated_at' => null,
				),
				array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);

			if ( false === $inserted ) {
				return new \WP_Error( 'activation_conflict', __( 'The installation could not be activated. Retry the request.', 'wp-license' ), array( 'status' => 409 ) );
			}

			$activation_id = (int) $wpdb->insert_id;
			AuditLog::record(
				'site_activated',
				'activation',
				$activation_id,
				AuditReason::INSTALLATION_ACTIVATED,
				null,
				array( 'license_id' => (int) $license['id'], 'site_url' => $site_url, 'environment' => $environment )
			);

			return self::signed_entitlement_response( (int) $license['id'], $activation_id, $installation_uuid, $secret );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}

	/**
	 * Deactivate an authenticated installation.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function deactivate( \WP_REST_Request $request ) {
		global $wpdb;

		$activation = Authenticator::authenticate( $request );
		if ( is_wp_error( $activation ) ) {
			return $activation;
		}
		if ( (int) $activation['id'] !== absint( $request->get_param( 'activation_id' ) ) ) {
			return new \WP_Error( 'activation_mismatch', __( 'The authenticated installation does not match this activation.', 'wp-license' ), array( 'status' => 403 ) );
		}

		$lock_name = ActivationManager::lock_name( (int) $activation['license_id'] );
		$locked = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name ) );
		if ( '1' !== (string) $locked ) {
			return new \WP_Error( 'retry_later', __( 'The license is being updated. Retry shortly.', 'wp-license' ), array( 'status' => 503 ) );
		}

		try {
			if ( ! ActivationManager::deactivate_while_locked( (int) $activation['id'], AuditReason::DEACTIVATED_BY_INSTALLATION ) ) {
				return new \WP_Error( 'deactivation_failed', __( 'The installation could not be deactivated.', 'wp-license' ), array( 'status' => 409 ) );
			}

			return Response::signed(
				array( 'activation_id' => (int) $activation['id'], 'status' => 'deactivated' ),
				(string) $activation['installation_secret'],
				200,
				(string) $activation['request_nonce'],
				(string) $activation['installation_uuid']
			);
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
	 * @return \WP_REST_Response
	 */
	private static function signed_entitlement_response( int $license_id, int $activation_id, string $installation_uuid, string $secret ): \WP_REST_Response {
		global $wpdb;

		$tables = Schema::table_names();
		$license = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$tables['licenses']} WHERE id = %d LIMIT 1", $license_id ),
			ARRAY_A
		);
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT entitlement_key,value_json FROM {$tables['entitlements']} WHERE license_id = %d AND status = %s AND (ends_at IS NULL OR ends_at > %s)",
				$license_id,
				'active',
				is_array( $license ) ? LicenseLifecycle::entitlement_cutoff( $license ) : gmdate( 'Y-m-d H:i:s' )
			),
			ARRAY_A
		);

		$entitlements = array();
		foreach ( $rows as $row ) {
			$entitlements[ $row['entitlement_key'] ] = json_decode( $row['value_json'], true );
		}

		$paid_until = $license['paid_until'] ?? null;
		$activation = $wpdb->get_row(
			$wpdb->prepare( "SELECT environment,staging_parent_id FROM {$tables['activations']} WHERE id = %d LIMIT 1", $activation_id ),
			ARRAY_A
		);
		$site_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$tables['activations']} WHERE license_id = %d AND status = %s AND environment = %s",
				$license_id,
				'active',
				'production'
			)
		);
		$data = array(
			'activation_id' => $activation_id,
			'installation_id' => $installation_uuid,
			'license_status' => (string) $license['status'],
			'paid_until' => $paid_until,
			'site_limit' => self::site_limit( $license_id, $license ),
			'site_count' => $site_count,
			'environment' => is_array( $activation ) ? (string) $activation['environment'] : 'production',
			'staging_parent_id' => is_array( $activation ) && $activation['staging_parent_id'] ? (int) $activation['staging_parent_id'] : null,
			'entitlements' => $entitlements,
			'server_time' => gmdate( 'Y-m-d H:i:s' ),
			'cache_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 14 * DAY_IN_SECONDS ),
		);

		// Activation is not HMAC-authenticated; the installation ID the client
		// just generated takes the place of the request nonce.
		return Response::signed( $data, $secret, 200, $installation_uuid, $installation_uuid );
	}

	/**
	 * Restrict non-counting development activations to local development hosts.
	 *
	 * @param string $site_url Normalized site URL.
	 * @return bool
	 */
	private static function is_local_development_url( string $site_url ): bool {
		$host = strtolower( (string) wp_parse_url( $site_url, PHP_URL_HOST ) );
		// wp_parse_url() keeps the brackets of an IPv6 literal.
		$host = trim( rtrim( $host, '.' ), '[]' );
		$allowed = in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true )
			|| '.localhost' === substr( $host, -10 )
			|| '.test' === substr( $host, -5 )
			|| '.local' === substr( $host, -6 );

		/**
		 * Allow a shop owner to explicitly approve a custom development hostname.
		 *
		 * @param bool   $allowed  Whether the hostname is local.
		 * @param string $site_url Normalized site URL.
		 */
		return (bool) apply_filters( 'wplc_is_local_development_url', $allowed, $site_url );
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
			return max( 1, absint( json_decode( (string) $value, true ) ) );
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
	 * Normalize a URL to a stable site origin/path.
	 *
	 * @param string $url Input URL.
	 * @return string
	 */
	private static function normalize_site_url( string $url ): string {
		return SiteUrl::normalize( $url );
	}

	/**
	 * Failure limiters of one activation attempt.
	 *
	 * The address comes from ClientIp (REMOTE_ADDR, or a configured trusted
	 * proxy header). IPv6 clients share one bucket per /64. A request without a
	 * resolvable address lands in a shared bucket instead of failing for
	 * everyone. The key prefix limiter is added when a key was sent.
	 *
	 * @param string $license_key Normalized (trimmed, uppercase) license key.
	 * @return array<string,int> Limiter hash => failures allowed per minute.
	 */
	private static function attempt_limiters( string $license_key ): array {
		$bucket = ClientIp::rate_limit_bucket( ClientIp::current() );
		if ( '' === $bucket ) {
			$bucket = 'unknown';
		}

		$limiters = array( hash( 'sha256', 'activation-ip:' . $bucket ) => self::ATTEMPTS_PER_IP );
		if ( '' !== $license_key ) {
			$limiters[ self::key_limiter_hash( $license_key ) ] = self::ATTEMPTS_PER_KEY;
		}

		return $limiters;
	}

	/**
	 * Count a failed attempt and choose its answer.
	 *
	 * While a limiter is throttled every failure is answered 429, so a guesser
	 * past the limit learns nothing from the error code (only a valid key gets
	 * through, which ActivationRateLimit bounds).
	 *
	 * @param array<string,int> $limiters   Limiters of the attempt.
	 * @param string            $rate_state ActivationRateLimit::check() result.
	 * @param \WP_Error         $error      Answer below the limit.
	 * @return \WP_Error
	 */
	private static function failed_attempt( array $limiters, string $rate_state, \WP_Error $error ): \WP_Error {
		if ( ! ActivationRateLimit::record_failure( $limiters ) ) {
			return self::rate_limit_unavailable();
		}

		return ActivationRateLimit::THROTTLED === $rate_state ? self::rate_limited() : $error;
	}

	/**
	 * 429 answer of the activation limiter.
	 *
	 * @return \WP_Error
	 */
	private static function rate_limited(): \WP_Error {
		return new \WP_Error( 'rate_limited', __( 'Too many activation attempts. Try again later.', 'wp-license' ), array( 'status' => 429 ) );
	}

	/**
	 * 503 answer when the limiter store fails (fail closed).
	 *
	 * @return \WP_Error
	 */
	private static function rate_limit_unavailable(): \WP_Error {
		return new \WP_Error( 'rate_limit_store_unavailable', __( 'Activation rate limiting is temporarily unavailable.', 'wp-license' ), array( 'status' => 503 ) );
	}

	/**
	 * Limiter key for a license-key prefix; never stores key material.
	 *
	 * @param string $license_key License key.
	 * @return string SHA-256 limiter hash.
	 */
	public static function key_limiter_hash( string $license_key ): string {
		return hash( 'sha256', 'activation-key:' . substr( strtoupper( trim( $license_key ) ), 0, self::KEY_PREFIX_LENGTH ) );
	}
}
