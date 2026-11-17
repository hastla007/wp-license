<?php
/**
 * Built-in package resolver for S3-compatible object storage.
 *
 * @package WPLC
 */

namespace WPLC\Update;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Delivers URL releases that live in one private S3-compatible bucket (T-31).
 *
 * Configured only with constants in wp-config.php:
 *
 * - WPLC_S3_BUCKET, WPLC_S3_ACCESS_KEY_ID, WPLC_S3_SECRET_ACCESS_KEY (required);
 * - WPLC_S3_REGION (default "us-east-1"; "auto" for Cloudflare R2);
 * - WPLC_S3_ENDPOINT (default "https://s3.{region}.amazonaws.com");
 * - WPLC_S3_PATH_STYLE (default false; always true for bucket names that are no DNS label).
 *
 * Nothing is stored in the database. The secret is passed to S3Presigner only
 * and never appears in a return value of this class, a log or the admin.
 *
 * A release stores the permanent object URL (object_url()), or is created
 * from "s3://bucket/key", which is turned into that URL. When a download grant
 * is redeemed, resolve() runs on `wplc_resolve_update_package_url` after the
 * custom resolvers (priority RESOLVER_PRIORITY) and replaces an unchanged
 * object URL of the bucket with a pre-signed GET URL that is valid for
 * expires() seconds. A URL that a custom resolver already changed (it then
 * carries a query) is left alone, so custom resolvers keep precedence.
 */
final class S3Storage {
	/** Runs after custom resolvers on the default priority 10. */
	public const RESOLVER_PRIORITY = 20;

	/** Default validity of a pre-signed URL in seconds. */
	public const DEFAULT_EXPIRES = 300;

	/** Default region. */
	public const DEFAULT_REGION = 'us-east-1';

	/** URI scheme accepted as package location of a release. */
	public const URI_SCHEME = 's3://';

	/**
	 * Configuration used instead of the constants. Tests only.
	 *
	 * @var array<string,mixed>|null
	 */
	private static ?array $override = null;

	/**
	 * Register the resolver and the host allow-list entry.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_filter( 'wplc_resolve_update_package_url', array( self::class, 'resolve' ), self::RESOLVER_PRIORITY, 2 );
		add_filter( 'wplc_update_package_hosts', array( self::class, 'allow_bucket_host' ), 1000 );
	}

	/**
	 * Use these values instead of the WPLC_S3_* constants (null: back to the constants).
	 *
	 * @internal Tests only. Constants cannot be undefined within one PHP process.
	 *
	 * @param array<string,mixed>|null $values Keys endpoint, region, bucket, access_key_id, secret_access_key, path_style.
	 * @return void
	 */
	public static function override_config( ?array $values ): void {
		self::$override = $values;
	}

	/**
	 * Raw configuration values from the constants.
	 *
	 * @return array<string,mixed>
	 */
	private static function raw_values(): array {
		if ( null !== self::$override ) {
			return self::$override;
		}
		$constants = array(
			'endpoint' => 'WPLC_S3_ENDPOINT',
			'region' => 'WPLC_S3_REGION',
			'bucket' => 'WPLC_S3_BUCKET',
			'access_key_id' => 'WPLC_S3_ACCESS_KEY_ID',
			'secret_access_key' => 'WPLC_S3_SECRET_ACCESS_KEY',
			'path_style' => 'WPLC_S3_PATH_STYLE',
		);
		$values = array();
		foreach ( $constants as $key => $constant ) {
			if ( defined( $constant ) ) {
				$values[ $key ] = constant( $constant );
			}
		}

		return $values;
	}

	/**
	 * Validate configuration values. Pure helper (no WordPress calls).
	 *
	 * @param array<string,mixed> $values Keys endpoint, region, bucket, access_key_id, secret_access_key, path_style.
	 * @return array{state:string,error:string,endpoint_host:string,region:string,bucket:string,path_style:bool}
	 *         state: off (nothing set), invalid or configured; error: '' or an error code (see error_message()).
	 */
	public static function validate_config( array $values ): array {
		$result = array(
			'state' => 'off',
			'error' => '',
			'endpoint_host' => '',
			'region' => '',
			'bucket' => '',
			'path_style' => false,
		);
		$text = static function ( string $key ) use ( $values ): string {
			return is_string( $values[ $key ] ?? null ) ? trim( $values[ $key ] ) : '';
		};
		$bucket = $text( 'bucket' );
		$access_key_id = $text( 'access_key_id' );
		$secret = is_string( $values['secret_access_key'] ?? null ) ? $values['secret_access_key'] : '';
		if ( '' === $bucket && '' === $access_key_id && '' === $secret ) {
			return $result;
		}
		$result['state'] = 'invalid';
		$result['bucket'] = $bucket;
		if ( '' === $bucket || '' === $access_key_id || '' === $secret ) {
			$result['error'] = 'incomplete';
			return $result;
		}
		if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{1,61}[A-Za-z0-9]$/D', $bucket ) || false !== strpos( $bucket, '..' ) ) {
			$result['error'] = 'invalid_bucket';
			return $result;
		}
		if ( strlen( $access_key_id ) > 128 || ! preg_match( '/^[\x21-\x7e]+$/D', $access_key_id ) || preg_match( '/[\x00-\x1f\x7f]/', $secret ) ) {
			$result['error'] = 'invalid_credentials';
			return $result;
		}
		$region = '' !== $text( 'region' ) ? strtolower( $text( 'region' ) ) : self::DEFAULT_REGION;
		if ( ! preg_match( '/^[a-z0-9][a-z0-9-]{0,31}$/D', $region ) ) {
			$result['error'] = 'invalid_region';
			return $result;
		}
		$result['region'] = $region;
		$endpoint = '' !== $text( 'endpoint' ) ? $text( 'endpoint' ) : 'https://s3.' . $region . '.amazonaws.com';
		if ( false === strpos( $endpoint, '://' ) ) {
			$endpoint = 'https://' . $endpoint;
		}
		$host = self::endpoint_host( $endpoint );
		if ( '' === $host ) {
			$result['error'] = 'invalid_endpoint';
			return $result;
		}
		$result['endpoint_host'] = $host;
		// Virtual-hosted-style needs a bucket that is one valid lower-case DNS label (TLS wildcard certificates cover one label).
		$path_style = ! empty( $values['path_style'] ) || ! preg_match( '/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/D', $bucket );
		$result['path_style'] = $path_style;
		$result['state'] = 'configured';

		return $result;
	}

	/**
	 * Host of an endpoint URL: HTTPS, port 443, no path, query, fragment or credentials. Pure helper.
	 *
	 * @param string $endpoint Endpoint URL.
	 * @return string Lower-case host, or '' when the endpoint is not usable.
	 */
	public static function endpoint_host( string $endpoint ): string {
		if ( preg_match( '/[\x00-\x20\x7f]/', $endpoint ) ) {
			return '';
		}
		$parts = parse_url( $endpoint ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure helper.
		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) )
			|| ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] )
			|| isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] )
			|| ! in_array( (string) ( $parts['path'] ?? '' ), array( '', '/' ), true )
		) {
			return '';
		}
		$host = strtolower( (string) ( $parts['host'] ?? '' ) );

		return preg_match( '/^(?=.{1,253}$)[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/D', $host ) ? $host : '';
	}

	/**
	 * Validated configuration without the credentials.
	 *
	 * @return array{state:string,error:string,endpoint_host:string,region:string,bucket:string,path_style:bool}
	 */
	public static function config(): array {
		return self::validate_config( self::raw_values() );
	}

	/**
	 * Whether a usable bucket is configured.
	 *
	 * @return bool
	 */
	public static function is_configured(): bool {
		return 'configured' === self::config()['state'];
	}

	/**
	 * Presigner for the configured bucket.
	 *
	 * @return S3Presigner|null Null when not (or not validly) configured.
	 */
	public static function presigner(): ?S3Presigner {
		$values = self::raw_values();
		$config = self::validate_config( $values );
		if ( 'configured' !== $config['state'] ) {
			return null;
		}

		return new S3Presigner(
			$config['endpoint_host'],
			$config['region'],
			$config['bucket'],
			trim( (string) $values['access_key_id'] ),
			(string) $values['secret_access_key'],
			$config['path_style']
		);
	}

	/**
	 * Validity of a pre-signed download URL in seconds.
	 *
	 * @return int 1 … 604800.
	 */
	public static function expires(): int {
		/**
		 * Filters how long a pre-signed object storage URL is valid.
		 *
		 * The client follows the redirect at once, so a few minutes suffice. Clamped to 1 s … 7 days.
		 *
		 * @param int|string $seconds Default 300. A numeric string is accepted; any other value restores the default.
		 */
		$seconds = apply_filters( 'wplc_s3_presign_expires', self::DEFAULT_EXPIRES );

		return S3Presigner::clamp_expires( is_numeric( $seconds ) ? (int) $seconds : self::DEFAULT_EXPIRES );
	}

	/**
	 * Replace an unchanged object URL of the bucket with a pre-signed GET URL.
	 *
	 * @param mixed $url        Package URL after the resolvers with a lower priority.
	 * @param mixed $license_id License ID (0 when the server verifies a release on publish). Unused.
	 * @return mixed
	 */
	public static function resolve( $url, $license_id = 0 ) {
		unset( $license_id );
		if ( ! is_string( $url ) || '' === $url ) {
			return $url;
		}
		$presigner = self::presigner();
		$key = null !== $presigner ? $presigner->key_from_url( $url ) : null;
		if ( null === $presigner || null === $key ) {
			return $url;
		}
		$signed = $presigner->presign( 'GET', $key, self::expires(), time() );

		// Fail closed: an empty URL makes the download answer 404.
		return '' === $signed ? '' : $signed;
	}

	/**
	 * Whether the built-in resolver would sign this stored package URL.
	 *
	 * @param string $url Stored package URL.
	 * @return bool
	 */
	public static function owns_url( string $url ): bool {
		$presigner = self::presigner();

		return null !== $presigner && null !== $presigner->key_from_url( $url );
	}

	/**
	 * Add the request host of the bucket to the package host allow-list.
	 *
	 * @param mixed $hosts Allowed hosts.
	 * @return mixed
	 */
	public static function allow_bucket_host( $hosts ) {
		$presigner = self::presigner();
		if ( ! is_array( $hosts ) || null === $presigner ) {
			return $hosts;
		}
		$host = $presigner->host();
		foreach ( $hosts as $existing ) {
			if ( is_string( $existing ) && strtolower( trim( $existing ) ) === $host ) {
				return $hosts;
			}
		}
		$hosts[] = $host;

		return $hosts;
	}

	/**
	 * Turn "s3://bucket/key" into the permanent object URL of the configured bucket.
	 *
	 * Any other value is returned unchanged. An s3:// value that cannot be
	 * turned into a URL (storage not configured, other bucket, invalid key) is
	 * also returned unchanged, so the caller can report it.
	 *
	 * The key after the bucket is taken as it is (not URL-decoded), like the
	 * AWS command line does: "s3://releases/acme pro/1.0.zip".
	 *
	 * @param string $value Package location entered for a release.
	 * @return string
	 */
	public static function normalize_package_url( string $value ): string {
		if ( ! self::is_object_uri( $value ) ) {
			return $value;
		}
		$rest = substr( $value, strlen( self::URI_SCHEME ) );
		$slash = strpos( $rest, '/' );
		$presigner = self::presigner();
		if ( false === $slash || null === $presigner || substr( $rest, 0, $slash ) !== $presigner->bucket() ) {
			return $value;
		}
		$url = $presigner->object_url( substr( $rest, $slash + 1 ) );

		return '' === $url ? $value : $url;
	}

	/**
	 * Whether a value uses the s3:// scheme. Pure helper.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	public static function is_object_uri( string $value ): bool {
		return 0 === strncasecmp( $value, self::URI_SCHEME, strlen( self::URI_SCHEME ) );
	}

	/**
	 * Check the credentials with a signed HEAD request on the bucket or on one object.
	 *
	 * Neither the secret nor the signed URL is part of the result.
	 *
	 * @param string $key Object key, or '' for the bucket (needs the permission to list the bucket on AWS).
	 * @return array{ok:bool,code:string,status:int,message:string}
	 *         code: ok, not_configured, invalid_key, unreachable, denied, not_found, wrong_endpoint or unexpected.
	 */
	public static function check( string $key = '' ): array {
		$presigner = self::presigner();
		if ( null === $presigner ) {
			$error = self::config()['error'];
			return self::check_result( 'not_configured', 0, self::error_message( '' !== $error ? $error : 'not_configured' ) );
		}
		if ( '' !== $key && ! S3Presigner::is_valid_key( $key ) ) {
			return self::check_result( 'invalid_key', 0, __( 'The object key is not valid.', 'wp-license' ) );
		}
		$url = $presigner->presign( 'HEAD', $key, 60, time() );
		$response = wp_remote_request(
			$url,
			array(
				'method' => 'HEAD',
				'timeout' => 10,
				'redirection' => 0,
			)
		);
		if ( is_wp_error( $response ) ) {
			// Never repeat the signed URL, should a transport put it into its message.
			$message = str_replace( $url, $presigner->host(), $response->get_error_message() );
			$message = (string) preg_replace( '/X-Amz-[A-Za-z]+=[^&\s]*/', '', $message );
			return self::check_result(
				'unreachable',
				0,
				/* translators: %s: error message of the HTTP request. */
				sprintf( __( 'The object storage could not be reached: %s', 'wp-license' ), $message )
			);
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status >= 200 && $status < 300 ) {
			return self::check_result( 'ok', $status, __( 'The object storage accepted the signed request.', 'wp-license' ) );
		}
		if ( 403 === $status ) {
			return self::check_result( 'denied', $status, __( 'Access denied (HTTP 403): check the access key, the secret, the region, the server clock and the read permission of the key.', 'wp-license' ) );
		}
		if ( 404 === $status ) {
			return self::check_result( 'not_found', $status, '' === $key ? __( 'The bucket was not found (HTTP 404).', 'wp-license' ) : __( 'The object was not found (HTTP 404).', 'wp-license' ) );
		}
		if ( in_array( $status, array( 301, 307, 400 ), true ) ) {
			/* translators: %d: HTTP status code. */
			return self::check_result( 'wrong_endpoint', $status, sprintf( __( 'The storage answered HTTP %d: the region or the endpoint does not match the bucket, or path-style addressing is needed.', 'wp-license' ), $status ) );
		}

		/* translators: %d: HTTP status code. */
		return self::check_result( 'unexpected', $status, sprintf( __( 'Unexpected answer from the object storage: HTTP %d.', 'wp-license' ), $status ) );
	}

	/**
	 * Build a check result.
	 *
	 * @param string $code    Result code.
	 * @param int    $status  HTTP status, 0 without a response.
	 * @param string $message Message.
	 * @return array{ok:bool,code:string,status:int,message:string}
	 */
	private static function check_result( string $code, int $status, string $message ): array {
		return array(
			'ok' => 'ok' === $code,
			'code' => $code,
			'status' => $status,
			'message' => $message,
		);
	}

	/**
	 * Message for a configuration error code.
	 *
	 * @param string $code Error code of validate_config(), or not_configured / object_storage_unavailable.
	 * @return string
	 */
	public static function error_message( string $code ): string {
		$messages = array(
			'not_configured' => __( 'Object storage is not configured (WPLC_S3_BUCKET, WPLC_S3_ACCESS_KEY_ID and WPLC_S3_SECRET_ACCESS_KEY in wp-config.php).', 'wp-license' ),
			'incomplete' => __( 'Object storage is configured incompletely: WPLC_S3_BUCKET, WPLC_S3_ACCESS_KEY_ID and WPLC_S3_SECRET_ACCESS_KEY are all required.', 'wp-license' ),
			'invalid_bucket' => __( 'WPLC_S3_BUCKET is not a valid bucket name.', 'wp-license' ),
			'invalid_credentials' => __( 'WPLC_S3_ACCESS_KEY_ID or WPLC_S3_SECRET_ACCESS_KEY contains characters that are not allowed.', 'wp-license' ),
			'invalid_region' => __( 'WPLC_S3_REGION is not a valid region name.', 'wp-license' ),
			'invalid_endpoint' => __( 'WPLC_S3_ENDPOINT must be an HTTPS address on the standard port without a path, for example https://s3.eu-central-1.amazonaws.com.', 'wp-license' ),
		);

		return $messages[ $code ] ?? $messages['not_configured'];
	}
}
