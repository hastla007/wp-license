<?php
/**
 * HMAC request authentication and replay protection.
 *
 * @package WPLC
 */

namespace WPLC\Api\V1;

use WPLC\Infrastructure\Database\Schema;
use WPLC\Security\SecretCrypto;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates signed requests from activated plugin installations.
 *
 * Error messages of the API are stable English texts and are not translated:
 * clients decide by `code` (B-23).
 */
final class Authenticator {

	/** @var int Authenticated requests per installation and minute. */
	public const REQUESTS_PER_INSTALLATION = 120;

	/** @var int Seconds a request timestamp may differ from the server clock, in both directions. */
	public const TIMESTAMP_WINDOW = 300;

	/**
	 * @var int Seconds a used nonce is kept: longer than the whole time a request is
	 *          accepted (twice the window), so a nonce never expires while its
	 *          request could still be replayed (P-40).
	 */
	public const NONCE_TTL = 2 * self::TIMESTAMP_WINDOW + 60;

	/**
	 * @var int Failed authentications (unknown installation, wrong signature) per
	 *          client address and minute before the address is refused without a
	 *          database lookup (P-41).
	 */
	public const AUTH_FAILURES_PER_IP = 100;

	/** @var string[] Query parameters WordPress itself may need on a signed route (plain permalinks, method override). */
	private const ALLOWED_QUERY_PARAMS = array( 'rest_route', '_method' );

	/** @var int Days after which rate-limit buckets and API error counters are deleted. */
	private const LIMIT_RETENTION_DAYS = 2;

	/** @var int Rows deleted per statement by the cleanup. */
	private const CLEANUP_BATCH = 5000;

	/** @var int Seconds one cleanup run may take. */
	private const CLEANUP_SECONDS = 20;

	/**
	 * Authenticate request headers and return the activation row.
	 *
	 * A correctly signed request of an installation whose activation was
	 * released (deactivated by the customer, the admin, a replacement or the
	 * end of the live site) is not answered with an anonymous 401: the caller
	 * gets `activation_deactivated` (403) whose error data carries a signed
	 * envelope in `signed`, so the client can prove the refusal and stop using
	 * its cached rights (B-70). Handlers that answer such installations
	 * themselves pass $allow_deactivated and check `status`.
	 *
	 * @param \WP_REST_Request $request           REST request.
	 * @param bool             $allow_deactivated Return deactivated activations instead of the signed refusal.
	 * @return array<string,mixed>|\WP_Error Activation row plus `installation_secret` and `request_nonce`.
	 */
	public static function authenticate( \WP_REST_Request $request, bool $allow_deactivated = false ) {
		global $wpdb;

		// Lower case before lookup and limiter hash: the column collation is
		// case-insensitive, the hash is not (B-166).
		$installation_uuid = strtolower( trim( (string) $request->get_header( 'x-wplc-installation-id' ) ) );
		$timestamp = trim( (string) $request->get_header( 'x-wplc-timestamp' ) );
		$nonce = trim( (string) $request->get_header( 'x-wplc-nonce' ) );
		$provided_signature = strtolower( trim( (string) $request->get_header( 'x-wplc-signature' ) ) );

		if ( ! preg_match( '/^[a-f0-9-]{36}$/', $installation_uuid ) || ! preg_match( '/^[0-9]{1,12}$/', $timestamp ) || ! preg_match( '/^[a-zA-Z0-9_-]{16,80}$/', $nonce ) || ! preg_match( '/^[a-f0-9]{64}$/', $provided_signature ) ) {
			return new \WP_Error( 'invalid_request_signature', 'Request authentication headers are invalid.', array( 'status' => 401 ) );
		}

		if ( abs( time() - (int) $timestamp ) > self::TIMESTAMP_WINDOW ) {
			return new \WP_Error( 'request_expired', 'Request timestamp is outside the allowed window.', array( 'status' => 401, 'server_time' => time() ) );
		}

		// Only the body is signed: parameters from the query string or a body
		// WordPress parses as a form would be unsigned input (P-12).
		if ( ! self::parameters_are_signed( $request ) ) {
			return new \WP_Error( 'request_parameters_unsigned', 'Signed requests must send their parameters as a JSON body (Content-Type: application/json) and no query parameters.', array( 'status' => 400 ) );
		}

		// Cheap refusal before any lookup or decryption: one address that keeps
		// failing authentication is answered from one indexed read (P-41).
		$failure_limiter = self::limiter_hash( 'auth-failure-ip', ClientIp::current_bucket() );
		$failures = self::current_rate_limit( $failure_limiter );
		if ( false === $failures ) {
			return new \WP_Error( 'rate_limit_store_unavailable', 'Request rate limiting is temporarily unavailable.', array( 'status' => 503 ) );
		}
		if ( $failures >= self::AUTH_FAILURES_PER_IP ) {
			return new \WP_Error( 'rate_limited', 'Too many failed requests from this address.', array( 'status' => 429, 'retry_after' => 60 ) );
		}

		$tables = Schema::table_names();
		$activation = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$tables['activations']} WHERE installation_uuid = %s LIMIT 1",
				$installation_uuid
			),
			ARRAY_A
		);

		// An unknown installation and a wrong signature get the same answer, so
		// the API does not tell which installation IDs exist (P-41). For a real
		// client both mean the same: its credential is not valid here, activate again.
		if ( ! is_array( $activation ) ) {
			return self::authentication_failed( $failure_limiter );
		}

		$secret = SecretCrypto::decrypt( (string) $activation['installation_secret_encrypted'] );
		if ( false === $secret || ! hash_equals( (string) $activation['installation_secret_hash'], hash( 'sha256', $secret ) ) ) {
			// A server-side key problem (encryption key missing on this node), not something a caller can cause.
			return new \WP_Error( 'installation_secret_invalid', 'The installation credential is invalid.', array( 'status' => 401 ) );
		}

		$expected_signature = self::signature_for(
			$secret,
			$request->get_method(),
			$request->get_route(),
			$timestamp,
			$nonce,
			(string) $request->get_body()
		);
		if ( ! hash_equals( $expected_signature, $provided_signature ) ) {
			return self::authentication_failed( $failure_limiter );
		}

		// Limit before the nonce is stored: a throttled installation must not
		// fill the replay table (A-36).
		$rate_hits = self::increment_rate_limit( self::limiter_hash( 'installation', $installation_uuid ) );
		if ( false === $rate_hits ) {
			return new \WP_Error( 'rate_limit_store_unavailable', 'Request rate limiting is temporarily unavailable.', array( 'status' => 503 ) );
		}
		if ( $rate_hits > self::REQUESTS_PER_INSTALLATION ) {
			return new \WP_Error( 'rate_limited', 'Too many requests for this installation.', array( 'status' => 429, 'retry_after' => 60 ) );
		}

		// One statement instead of DELETE + INSERT (P-50): a new nonce is
		// inserted (1 row affected), an expired row of the same nonce is
		// overwritten (2), a nonce that is still valid stays untouched (0).
		$nonce_hash = hash( 'sha256', $nonce );
		$now = gmdate( 'Y-m-d H:i:s' );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + self::NONCE_TTL );
		$nonce_inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO %i (activation_id,nonce_hash,expires_at,created_at) VALUES (%d,%s,%s,%s) ON DUPLICATE KEY UPDATE created_at = IF(expires_at <= %s, %s, created_at), expires_at = IF(expires_at <= %s, %s, expires_at)",
				$tables['request_nonces'],
				(int) $activation['id'],
				$nonce_hash,
				$expires_at,
				$now,
				$now,
				$now,
				$now,
				$expires_at
			)
		);

		if ( false === $nonce_inserted ) {
			return new \WP_Error( 'replay_store_unavailable', 'Request replay protection is temporarily unavailable.', array( 'status' => 503 ) );
		}
		if ( 0 === (int) $nonce_inserted ) {
			return new \WP_Error( 'request_replayed', 'This request nonce has already been used.', array( 'status' => 409 ) );
		}

		if ( SecretCrypto::needs_rotation( (string) $activation['installation_secret_encrypted'] ) ) {
			$rotated = SecretCrypto::encrypt( $secret );
			if ( false !== $rotated ) {
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$tables['activations']} SET installation_secret_encrypted = %s WHERE id = %d AND installation_secret_encrypted = %s",
						$rotated,
						(int) $activation['id'],
						(string) $activation['installation_secret_encrypted']
					)
				);
			}
		}

		$activation['installation_secret'] = $secret;
		// Echoed inside the signed response payload so a recorded response
		// cannot be replayed against a later request.
		$activation['request_nonce'] = $nonce;

		if ( 'active' !== (string) $activation['status'] && ! $allow_deactivated ) {
			return new \WP_Error(
				'activation_deactivated',
				'This installation was deactivated on the license server.',
				array(
					'status' => 403,
					'signed' => Response::envelope( self::deactivated_data( $activation ), $secret, $nonce, (string) $activation['installation_uuid'] ),
				)
			);
		}

		return $activation;
	}

	/**
	 * Read one parameter of an authenticated request (P-12).
	 *
	 * Only the JSON body is covered by the signature, so only the JSON body
	 * is read; authenticate() has refused query parameters and other content
	 * types before.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @param string           $name    Parameter name.
	 * @return mixed Null when the body does not carry the parameter.
	 */
	public static function signed_param( \WP_REST_Request $request, string $name ) {
		$params = $request->get_json_params();

		return is_array( $params ) && array_key_exists( $name, $params ) ? $params[ $name ] : null;
	}

	/**
	 * Whether every parameter of a request is covered by its signature (P-12).
	 *
	 * The body must be JSON (a form body or text/plain would leave WordPress
	 * reading the parameters from somewhere else) and the query string may
	 * only carry what WordPress needs for routing. An empty body needs no
	 * content type.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return bool
	 */
	public static function parameters_are_signed( \WP_REST_Request $request ): bool {
		if ( array() !== array_diff( array_keys( (array) $request->get_query_params() ), self::ALLOWED_QUERY_PARAMS ) ) {
			return false;
		}
		if ( '' === trim( (string) $request->get_body() ) ) {
			return true;
		}

		return $request->is_json_content_type() && is_array( $request->get_json_params() );
	}

	/**
	 * Count a failed authentication of the client address and build the uniform answer (P-41).
	 *
	 * @param string $failure_limiter Bucket of the client address.
	 * @return \WP_Error
	 */
	private static function authentication_failed( string $failure_limiter ): \WP_Error {
		self::increment_rate_limit( $failure_limiter );

		return new \WP_Error( 'activation_not_found', 'The installation is not known to this server or the request signature is invalid.', array( 'status' => 401 ) );
	}

	/**
	 * Signed data that tells a released installation to stop.
	 *
	 * @param array<string,mixed> $activation Activation row.
	 * @return array<string,mixed>
	 */
	public static function deactivated_data( array $activation ): array {
		$now = gmdate( 'Y-m-d H:i:s' );

		return array(
			'activation_id' => (int) $activation['id'],
			'installation_id' => (string) $activation['installation_uuid'],
			'activation_status' => 'deactivated',
			'license_status' => 'deactivated',
			'access' => false,
			'access_until' => null,
			'grace_until' => null,
			'environment' => (string) ( $activation['environment'] ?? 'production' ),
			'entitlements' => array(),
			'deactivated_at' => isset( $activation['deactivated_at'] ) ? (string) $activation['deactivated_at'] : null,
			'server_time' => $now,
			// Nothing to keep: the refusal is final for this installation ID.
			'cache_expires_at' => $now,
		);
	}

	/**
	 * Build the documented request signature for a raw REST request.
	 *
	 * @param string $secret Installation secret.
	 * @param string $method HTTP method.
	 * @param string $route WordPress REST route.
	 * @param string $timestamp Unix timestamp.
	 * @param string $nonce Request nonce.
	 * @param string $body Raw request body.
	 * @return string Lowercase hexadecimal HMAC-SHA-256.
	 */
	public static function signature_for( string $secret, string $method, string $route, string $timestamp, string $nonce, string $body ): string {
		$canonical = strtoupper( $method ) . "\n" . $route . "\n" . $timestamp . "\n" . $nonce . "\n" . hash( 'sha256', $body );

		return hash_hmac( 'sha256', $canonical, $secret );
	}

	/**
	 * Key of a rate-limit bucket.
	 *
	 * An HMAC with a server-side key, so the stored value cannot be reversed
	 * into a client address, key prefix or installation ID by trying
	 * candidates (B-130). The key is `WPLC_RATE_LIMIT_KEY` from wp-config.php
	 * or is derived from the WordPress auth salt; changing it only resets the
	 * running buckets.
	 *
	 * @param string $scope Kind of bucket (`installation`, `auth-failure-ip`, `activation-ip`, `activation-key-ip`, `activation-license`).
	 * @param string $value Identifier inside the scope.
	 * @return string 64 hex characters.
	 */
	public static function limiter_hash( string $scope, string $value ): string {
		if ( defined( 'WPLC_RATE_LIMIT_KEY' ) && '' !== (string) constant( 'WPLC_RATE_LIMIT_KEY' ) ) {
			$key = (string) constant( 'WPLC_RATE_LIMIT_KEY' );
		} else {
			$key = 'wplc-rate-limit|' . ( function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : '' );
		}

		return hash_hmac( 'sha256', $scope . ':' . $value, $key );
	}

	/**
	 * Atomically increment a per-minute request bucket.
	 *
	 * @param string $limiter_hash Bucket key from limiter_hash().
	 * @return int|false Current hit count, or false on database failure.
	 */
	public static function increment_rate_limit( string $limiter_hash ) {
		return self::increment_bucket( $limiter_hash, gmdate( 'Y-m-d H:i:00' ) );
	}

	/**
	 * Atomically increment a counter row in the rate-limit table.
	 *
	 * @param string $limiter_hash 64-character key.
	 * @param string $bucket       UTC start of the bucket (`Y-m-d H:i:s`).
	 * @return int|false Current hit count, or false on database failure.
	 */
	public static function increment_bucket( string $limiter_hash, string $bucket ) {
		global $wpdb;

		$tables = Schema::table_names();
		$now = gmdate( 'Y-m-d H:i:s' );
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$tables['request_limits']} (limiter_hash,bucket_start,hits,created_at) VALUES (%s,%s,1,%s) ON DUPLICATE KEY UPDATE hits = hits + 1",
				$limiter_hash,
				$bucket,
				$now
			)
		);
		if ( false === $result ) {
			return false;
		}

		$hits = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT hits FROM {$tables['request_limits']} WHERE limiter_hash = %s AND bucket_start = %s LIMIT 1",
				$limiter_hash,
				$bucket
			)
		);

		return is_numeric( $hits ) ? (int) $hits : false;
	}

	/**
	 * Read a request bucket without counting.
	 *
	 * @param string      $limiter_hash SHA-256 limiter key.
	 * @param string|null $bucket       UTC start of the bucket; null for the current minute.
	 * @return int|false Current hit count (0 without a row), or false on database failure.
	 */
	public static function current_rate_limit( string $limiter_hash, ?string $bucket = null ) {
		global $wpdb;

		$tables = Schema::table_names();
		$hits   = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT hits FROM {$tables['request_limits']} WHERE limiter_hash = %s AND bucket_start = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names(), values prepared.
				$limiter_hash,
				null === $bucket ? gmdate( 'Y-m-d H:i:00' ) : $bucket
			)
		);
		if ( null === $hits ) {
			return empty( $wpdb->last_error ) ? 0 : false;
		}

		return is_numeric( $hits ) ? (int) $hits : false;
	}

	/**
	 * Read the current value of a minute bucket without counting.
	 *
	 * @param string $limiter_hash Bucket key from limiter_hash().
	 * @return int
	 */
	public static function current_rate( string $limiter_hash ): int {
		global $wpdb;

		$tables = Schema::table_names();
		$hits = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names().
				"SELECT hits FROM {$tables['request_limits']} WHERE limiter_hash = %s AND bucket_start = %s LIMIT 1",
				$limiter_hash,
				gmdate( 'Y-m-d H:i:00' )
			)
		);

		return is_numeric( $hits ) ? (int) $hits : 0;
	}

	/**
	 * Hourly job (`wplc_cleanup_security_rows`): the cleanup as an action callback without a return value.
	 *
	 * @return void
	 */
	public static function run_cleanup(): void {
		self::cleanup_security_rows();
	}

	/**
	 * Remove expired replay and rate-limit records.
	 *
	 * Deletes in batches until nothing is left or the time budget is used,
	 * so the tables cannot grow faster than they are cleaned (A-36).
	 *
	 * @return array{nonces:int,limits:int} Deleted rows.
	 */
	public static function cleanup_security_rows(): array {
		global $wpdb;

		$tables = Schema::table_names();
		$now = gmdate( 'Y-m-d H:i:s' );
		$rate_cutoff = gmdate( 'Y-m-d H:i:s', time() - self::LIMIT_RETENTION_DAYS * DAY_IN_SECONDS );
		$deadline = microtime( true ) + self::CLEANUP_SECONDS;
		$deleted = array(
			'nonces' => 0,
			'limits' => 0,
		);
		$queries = array(
			'nonces' => $wpdb->prepare( "DELETE FROM {$tables['request_nonces']} WHERE expires_at <= %s LIMIT %d", $now, self::CLEANUP_BATCH ),
			'limits' => $wpdb->prepare( "DELETE FROM {$tables['request_limits']} WHERE bucket_start < %s LIMIT %d", $rate_cutoff, self::CLEANUP_BATCH ),
		);

		foreach ( $queries as $name => $query ) {
			do {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
				$rows = $wpdb->query( $query );
				$deleted[ $name ] += (int) $rows;
			} while ( (int) $rows >= self::CLEANUP_BATCH && microtime( true ) < $deadline );
		}

		return $deleted;
	}
}
