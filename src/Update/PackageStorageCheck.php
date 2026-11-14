<?php
/**
 * Self-check: can the private package directory be read over HTTP?
 *
 * @package WPLC
 */

namespace WPLC\Update;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Places a canary file into a web-reachable package directory, requests its
 * public URL and reports whether the web server served it.
 *
 * The probe only runs from the admin pages that show its result, and its
 * outcome is cached in a transient, so no front-end request ever pays for it.
 */
final class PackageStorageCheck {
	const TRANSIENT = 'wplc_package_storage_probe';
	const TTL_DEFINITE = 43200; // 12 hours.
	const TTL_UNKNOWN = 3600; // 1 hour.
	const TIMEOUT = 3;

	const RESULT_EXPOSED = 'exposed';
	const RESULT_PROTECTED = 'protected';
	const RESULT_UNKNOWN = 'unknown';
	const RESULT_OUTSIDE = 'outside_web_root';
	const RESULT_DISABLED = 'disabled';

	/**
	 * Describe the current storage directory and, if requested, probe it.
	 *
	 * @param bool $probe Whether to run (or read the cached) HTTP probe.
	 * @return array{dir:string,source:string,constant_error:string,web_reachable:bool,url:string|null,writable:bool,probe:string,checked_at:int}
	 */
	public static function status( bool $probe = true ): array {
		$resolved = LocalPackageStorage::resolve();
		$dir = $resolved['dir'];
		$web_reachable = LocalPackageStorage::is_inside_any( $dir, LocalPackageStorage::web_reachable_roots() );
		$url = $web_reachable ? LocalPackageStorage::public_url_for_dir( $dir, self::url_map() ) : null;
		$writable = is_dir( $dir ) ? wp_is_writable( $dir ) : self::nearest_parent_writable( $dir );
		$result = self::RESULT_OUTSIDE;
		$checked_at = 0;
		if ( $web_reachable ) {
			$cached = $probe ? self::probe( $dir, $url ) : self::cached( $dir );
			$result = null === $cached ? self::RESULT_UNKNOWN : $cached['result'];
			$checked_at = null === $cached ? 0 : $cached['checked_at'];
		}

		return array(
			'dir' => $dir,
			'source' => $resolved['source'],
			'constant_error' => $resolved['constant_error'],
			'web_reachable' => $web_reachable,
			'url' => $url,
			'writable' => $writable,
			'probe' => $result,
			'checked_at' => $checked_at,
		);
	}

	/**
	 * Cached probe result, probing when the cache is empty or stale.
	 *
	 * @param string      $dir Storage directory.
	 * @param string|null $url Public URL of the directory, if known.
	 * @return array{result:string,checked_at:int}
	 */
	public static function probe( string $dir, ?string $url ): array {
		$cached = self::cached( $dir );
		if ( null !== $cached ) {
			return $cached;
		}
		/**
		 * Filters whether the package directory may be probed over HTTP.
		 *
		 * @param bool $enabled Default true.
		 */
		if ( ! apply_filters( 'wplc_package_storage_probe', true ) ) {
			$result = self::RESULT_DISABLED;
		} elseif ( null === $url || ! LocalPackageStorage::ensure_dir() ) {
			$result = self::RESULT_UNKNOWN;
		} else {
			$result = self::run_probe( $dir, $url, array( self::class, 'fetch' ) );
		}
		$entry = array(
			'result' => $result,
			'checked_at' => time(),
		);
		set_transient(
			self::TRANSIENT,
			$entry + array( 'dir' => $dir ),
			in_array( $result, array( self::RESULT_EXPOSED, self::RESULT_PROTECTED ), true ) ? self::TTL_DEFINITE : self::TTL_UNKNOWN
		);

		return $entry;
	}

	/**
	 * Drop the cached result so that the next status() call probes again.
	 *
	 * @return void
	 */
	public static function forget(): void {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * Read the cached result for this directory.
	 *
	 * @param string $dir Storage directory.
	 * @return array{result:string,checked_at:int}|null
	 */
	private static function cached( string $dir ): ?array {
		$cached = get_transient( self::TRANSIENT );
		if ( ! is_array( $cached ) || ! isset( $cached['result'], $cached['dir'] ) || $dir !== $cached['dir'] ) {
			return null;
		}

		return array(
			'result' => (string) $cached['result'],
			'checked_at' => (int) ( $cached['checked_at'] ?? 0 ),
		);
	}

	/**
	 * Write a canary, fetch it through its public URL and remove it again.
	 *
	 * @param string   $dir Existing storage directory.
	 * @param string   $base_url Public URL of that directory.
	 * @param callable $fetch Receives the canary URL; returns array{code:int,body:string} or null on transport errors.
	 * @return string One of the RESULT_* constants.
	 */
	public static function run_probe( string $dir, string $base_url, callable $fetch ): string {
		// Underscores keep the name from ever matching LocalPackageStorage::KEY_PATTERN.
		$name = 'wplc_canary_' . bin2hex( random_bytes( 16 ) ) . '.zip';
		$token = 'wplc-canary-' . bin2hex( random_bytes( 16 ) );
		$path = rtrim( $dir, '/\\' ) . '/' . $name;
		if ( false === @file_put_contents( $path, $token ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- reported as "unknown".
			return self::RESULT_UNKNOWN;
		}
		try {
			$response = $fetch( rtrim( $base_url, '/' ) . '/' . $name );
		} finally {
			if ( is_file( $path ) ) {
				unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
		if ( ! is_array( $response ) ) {
			return self::RESULT_UNKNOWN;
		}

		return self::classify( (int) ( $response['code'] ?? 0 ), (string) ( $response['body'] ?? '' ), $token );
	}

	/**
	 * Interpret the probe response. Pure helper.
	 *
	 * @param int    $code HTTP status code, 0 when unknown.
	 * @param string $body Response body.
	 * @param string $token Canary content.
	 * @return string
	 */
	public static function classify( int $code, string $body, string $token ): string {
		if ( $code >= 200 && $code < 300 ) {
			// A 200 without the token is a login page, soft 404 or similar.
			return false !== strpos( $body, $token ) ? self::RESULT_EXPOSED : self::RESULT_PROTECTED;
		}
		if ( in_array( $code, array( 401, 403, 404, 410, 444 ), true ) ) {
			return self::RESULT_PROTECTED;
		}

		return self::RESULT_UNKNOWN;
	}

	/**
	 * Loopback request with a short timeout.
	 *
	 * @param string $url Canary URL.
	 * @return array{code:int,body:string}|null
	 */
	public static function fetch( string $url ): ?array {
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => self::TIMEOUT,
				'redirection' => 2,
				'limit_response_size' => 4096,
				'reject_unsafe_urls' => false,
				/** This filter is documented in wp-includes/class-wp-http-streams.php */
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter.
				'headers' => array( 'Cache-Control' => 'no-cache' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return null;
		}

		return array(
			'code' => (int) wp_remote_retrieve_response_code( $response ),
			'body' => (string) wp_remote_retrieve_body( $response ),
		);
	}

	/**
	 * Served directories and their URLs, most specific first.
	 *
	 * @return array<string,string>
	 */
	private static function url_map(): array {
		$map = array();
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['basedir'] ) && ! empty( $uploads['baseurl'] ) ) {
			$map[ (string) $uploads['basedir'] ] = (string) $uploads['baseurl'];
		}
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$map[ (string) WP_CONTENT_DIR ] = content_url();
		}
		$map[ (string) ABSPATH ] = site_url( '/' );

		return $map;
	}

	/**
	 * Whether the closest existing ancestor of a missing directory is writable.
	 *
	 * @param string $dir Directory.
	 * @return bool
	 */
	private static function nearest_parent_writable( string $dir ): bool {
		$parent = dirname( $dir );
		while ( ! is_dir( $parent ) && dirname( $parent ) !== $parent ) {
			$parent = dirname( $parent );
		}

		return is_dir( $parent ) && wp_is_writable( $parent );
	}

	/**
	 * Deny rule for nginx that covers a directory URL. Pure helper.
	 *
	 * @param string $url Public URL of the directory.
	 * @return string Empty when the URL has no usable path.
	 */
	public static function nginx_rule( string $url ): string {
		$path = (string) parse_url( $url, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure helper, also used without WordPress.
		$path = '/' . trim( $path, '/' ) . '/';
		if ( '//' === $path || ! preg_match( '#^[A-Za-z0-9/._~%-]+$#', $path ) ) {
			return '';
		}

		return 'location ^~ ' . $path . ' { deny all; }';
	}

	/**
	 * Human-readable probe result.
	 *
	 * @param string $result RESULT_* constant.
	 * @return string
	 */
	public static function result_label( string $result ): string {
		switch ( $result ) {
			case self::RESULT_EXPOSED:
				return __( 'Publicly readable: the web server delivers files from this directory. Add a deny rule or move the directory outside the web root.', 'wp-license' );
			case self::RESULT_PROTECTED:
				return __( 'Inside the web root, but the web server refused to deliver a test file.', 'wp-license' );
			case self::RESULT_OUTSIDE:
				return __( 'Outside the web root.', 'wp-license' );
			case self::RESULT_DISABLED:
				return __( 'Inside the web root; the HTTP self-check is disabled by a filter.', 'wp-license' );
			default:
				return __( 'Inside the web root; the HTTP self-check could not reach the site. Verify the deny rule manually.', 'wp-license' );
		}
	}
}
