<?php
/**
 * Single-use streaming of locally stored release packages.
 *
 * @package WPLC
 */

namespace WPLC\Update;

use WPLC\Infrastructure\Database\Schema;
use WPLC\License\LicenseLifecycle;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a local release into a short-lived download URL on this site.
 *
 * Flow for updates: the registry hands UpdateService a stable placeholder URL
 * (release_url()). When a client redeems its download grant, UpdateService
 * applies `wplc_resolve_update_package_url`; resolve() then issues a stream
 * token and returns the streaming route, which serves the file through PHP
 * (or hands it to the web server, see `wplc_package_offload_header`).
 *
 * A token is meant for one download. Because download managers, scanning
 * proxies and some browsers fetch a URL twice (HEAD, then GET, or GET twice),
 * HEAD never uses the token up and a used token stays valid for a short reuse
 * window (B-19).
 */
final class PackageStream {
	const TTL = 300;
	const DEFAULT_REUSE_SECONDS = 120;
	const SOURCE_UPDATE = 'update';
	const SOURCE_CUSTOMER = 'customer';
	const REST_NAMESPACE = 'wplc/v1';

	/**
	 * Register route, resolver and cleanup.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'register_route' ) );
		add_filter( 'wplc_resolve_update_package_url', array( self::class, 'resolve' ), 10, 2 );
		// Late: the streaming route lives on this site, so the home host must stay allowed.
		add_filter( 'wplc_update_package_hosts', array( self::class, 'allow_home_host' ), 1000 );
		add_action( 'wplc_daily_lifecycle', array( self::class, 'cleanup' ) );
	}

	/**
	 * Register the streaming route.
	 *
	 * @return void
	 */
	public static function register_route(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/packages/(?P<token>[a-f0-9]{64})',
			array(
				// HEAD is answered by the same callback (WordPress maps it to GET).
				'methods' => 'GET',
				'callback' => array( self::class, 'serve' ),
				// The unguessable short-lived token is the credential.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Keep the home host in the package host allow-list.
	 *
	 * @param mixed $hosts Allowed hosts.
	 * @return mixed
	 */
	public static function allow_home_host( $hosts ) {
		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! is_array( $hosts ) || ! is_string( $home_host ) || '' === $home_host ) {
			return $hosts;
		}
		foreach ( $hosts as $host ) {
			if ( is_string( $host ) && strtolower( trim( $host ) ) === strtolower( $home_host ) ) {
				return $hosts;
			}
		}
		$hosts[] = $home_host;

		return $hosts;
	}

	/**
	 * Stable placeholder URL that identifies a local release without exposing its file.
	 *
	 * No route answers at this URL; it is only stored in download grants.
	 *
	 * @param int $release_id Release ID.
	 * @return string
	 */
	public static function release_url( int $release_id ): string {
		return rest_url( self::REST_NAMESPACE . '/packages/release/' . max( 0, $release_id ) );
	}

	/**
	 * Extract the release ID from a placeholder URL. Pure helper.
	 *
	 * @param string $url URL from a download grant.
	 * @return int Release ID, or 0 when the URL is not a placeholder.
	 */
	public static function parse_release_url( string $url ): int {
		// Covers pretty permalinks (/wp-json/wplc/v1/...) and ?rest_route=/wplc/v1/... (raw or encoded).
		$decoded = rawurldecode( $url );
		if ( ! preg_match( '#(?:/|=)wplc/v1/packages/release/([1-9][0-9]{0,17})/?$#D', $decoded, $matches ) ) {
			return 0;
		}

		return (int) $matches[1];
	}

	/**
	 * Release ID of a placeholder URL on this site, or 0 for any other URL.
	 *
	 * @param string $url URL from a download grant.
	 * @return int
	 */
	public static function placeholder_release_id( string $url ): int {
		$release_id = self::parse_release_url( $url );

		return $release_id > 0 && self::is_home_url( $url ) ? $release_id : 0;
	}

	/**
	 * Seconds a used download token keeps working (B-19).
	 *
	 * Covers a second fetch of the same URL by the same client. 0 restores strict single use.
	 *
	 * @return int
	 */
	public static function reuse_seconds(): int {
		/**
		 * Filters how long a download token stays valid after its first use.
		 *
		 * @param int $seconds Default 120, at most 600; 0 for strict single use.
		 */
		$seconds = (int) apply_filters( 'wplc_download_reuse_seconds', self::DEFAULT_REUSE_SECONDS );

		return max( 0, min( 600, $seconds ) );
	}

	/**
	 * Keep a token response out of page caches, CDNs and browsers.
	 *
	 * @return void
	 */
	public static function send_no_cache_headers(): void {
		self::forbid_page_cache();
		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private' );
			header( 'Referrer-Policy: no-referrer' );
			header( 'X-Robots-Tag: noindex, nofollow' );
		}
	}

	/**
	 * Tell page-cache plugins not to store the current response.
	 *
	 * DONOTCACHEPAGE is the constant W3 Total Cache, WP Super Cache, WP Rocket,
	 * LiteSpeed Cache and others look for.
	 *
	 * @return void
	 */
	public static function forbid_page_cache(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- shared convention of the caching plugins.
		}
	}

	/**
	 * Resolve the placeholder of a local release to a single-use streaming URL.
	 *
	 * @param mixed $url Package URL from the consumed download grant.
	 * @param mixed $license_id License that owns the grant.
	 * @return mixed
	 */
	public static function resolve( $url, $license_id = 0 ) {
		if ( ! is_string( $url ) ) {
			return $url;
		}
		$release_id = self::placeholder_release_id( $url );
		if ( $release_id <= 0 ) {
			return $url;
		}
		$release = ReleaseRegistry::get( $release_id );
		if ( null === $release || ReleaseRegistry::STORAGE_LOCAL !== $release['storage'] ) {
			return $url;
		}
		$token = self::issue( $release, (int) $license_id, self::SOURCE_UPDATE );

		// Fail closed: an empty URL makes the update API answer 404.
		return '' === $token ? '' : self::stream_url( $token );
	}

	/**
	 * Create a stream grant for a published local release.
	 *
	 * @param array<string,mixed> $release Release row.
	 * @param int                 $license_id License ID.
	 * @param string              $source SOURCE_UPDATE or SOURCE_CUSTOMER.
	 * @return string Plain token, or an empty string on failure.
	 */
	public static function issue( array $release, int $license_id, string $source ): string {
		global $wpdb;

		// Stream links must never be cached, wherever they are created.
		self::forbid_page_cache();
		if ( $license_id <= 0
			|| ReleaseRegistry::STATUS_PUBLISHED !== (string) ( $release['status'] ?? '' )
			|| ReleaseRegistry::STORAGE_LOCAL !== (string) ( $release['storage'] ?? '' )
			|| null === LocalPackageStorage::path_for_key( (string) ( $release['package_url'] ?? '' ) )
		) {
			return '';
		}
		$token = bin2hex( random_bytes( 32 ) );
		$tables = Schema::table_names();
		$inserted = $wpdb->insert(
			$tables['package_streams'],
			array(
				'token_hash' => hash( 'sha256', $token ),
				'release_id' => (int) $release['id'],
				'license_id' => $license_id,
				'source' => self::SOURCE_CUSTOMER === $source ? self::SOURCE_CUSTOMER : self::SOURCE_UPDATE,
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + self::TTL ),
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%d', '%d', '%s', '%s', '%s' )
		);

		return 1 === (int) $inserted ? $token : '';
	}

	/**
	 * Public streaming URL for a token.
	 *
	 * @param string $token Plain token.
	 * @return string
	 */
	public static function stream_url( string $token ): string {
		return rest_url( self::REST_NAMESPACE . '/packages/' . $token );
	}

	/**
	 * Check a stream grant and send the package.
	 *
	 * HEAD answers with the headers of the download and leaves the grant
	 * untouched. The first GET marks the grant as used with one conditional
	 * UPDATE; further GETs succeed during the reuse window only.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_Error|void Exits after streaming.
	 */
	public static function serve( \WP_REST_Request $request ) {
		global $wpdb;

		self::send_no_cache_headers();
		$not_found = new \WP_Error( 'package_not_found', __( 'This download link is invalid or has expired.', 'wp-license' ), array( 'status' => 404 ) );
		$token = strtolower( (string) $request->get_param( 'token' ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $token ) ) {
			return $not_found;
		}
		$tables = Schema::table_names();
		$token_hash = hash( 'sha256', $token );
		$now = gmdate( 'Y-m-d H:i:s' );
		$reuse_cutoff = gmdate( 'Y-m-d H:i:s', time() - self::reuse_seconds() );
		$grant = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT s.release_id, s.source, s.consumed_at, l.status, l.paid_until, l.renewal_enabled, l.cancel_at_period_end FROM {$tables['package_streams']} s INNER JOIN {$tables['licenses']} l ON l.id = s.license_id WHERE s.token_hash = %s AND ( ( s.consumed_at IS NULL AND s.expires_at > %s ) OR s.consumed_at > %s ) LIMIT 1",
				$token_hash,
				$now,
				$reuse_cutoff
			),
			ARRAY_A
		);
		if ( ! is_array( $grant ) ) {
			return $not_found;
		}
		// Updates need a license with access; the account download also serves the versions an ended license paid for.
		$allowed = self::SOURCE_CUSTOMER === $grant['source'] ? CustomerDownload::license_may_download( $grant ) : LicenseLifecycle::has_access( $grant );
		if ( ! $allowed ) {
			return $not_found;
		}
		$release = ReleaseRegistry::get( (int) $grant['release_id'] );
		if ( null === $release
			|| ReleaseRegistry::STATUS_PUBLISHED !== $release['status']
			|| ReleaseRegistry::STORAGE_LOCAL !== $release['storage']
		) {
			return $not_found;
		}
		$path = LocalPackageStorage::path_for_key( (string) $release['package_url'] );
		$size = null === $path ? false : filesize( $path );
		if ( null === $path || false === $size ) {
			return $not_found;
		}

		$is_head = 'HEAD' === strtoupper( (string) $request->get_method() );
		if ( ! $is_head && null === $grant['consumed_at'] ) {
			// Atomic first use: only one concurrent request can flip consumed_at.
			$consumed = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$tables['package_streams']} SET consumed_at = %s WHERE token_hash = %s AND consumed_at IS NULL AND expires_at > %s",
					$now,
					$token_hash,
					$now
				)
			);
			// A lost race is a second fetch: allowed inside the reuse window only.
			if ( 1 !== (int) $consumed && 0 === self::reuse_seconds() ) {
				return $not_found;
			}
		}

		self::send_file(
			$path,
			(int) $size,
			self::download_name( $release ),
			LocalPackageStorage::content_md5( (string) $release['package_url'] ),
			$is_head ? null : self::parse_range( (string) $request->get_header( 'range' ), (int) $size ),
			$is_head,
			$release
		);
		exit;
	}

	/**
	 * Parse a single byte range. Pure helper.
	 *
	 * Lets a client resume an interrupted download while its token is still
	 * valid (A-111). Multiple ranges and anything malformed are ignored, which
	 * HTTP allows: the whole file is sent instead.
	 *
	 * @param string $header Value of the Range request header.
	 * @param int    $size File size in bytes.
	 * @return array{0:int,1:int}|null First and last byte, or null to send everything.
	 */
	public static function parse_range( string $header, int $size ): ?array {
		if ( $size <= 0 || ! preg_match( '/^\s*bytes=(\d*)-(\d*)\s*$/D', $header, $match ) || ( '' === $match[1] && '' === $match[2] ) ) {
			return null;
		}
		if ( '' === $match[1] ) {
			// Suffix range: the last N bytes.
			$length = (int) $match[2];
			if ( $length <= 0 ) {
				return null;
			}
			$start = max( 0, $size - $length );
			$end = $size - 1;
		} else {
			$start = (int) $match[1];
			$end = '' === $match[2] ? $size - 1 : min( (int) $match[2], $size - 1 );
		}
		if ( $start > $end || $start >= $size || ( 0 === $start && $end === $size - 1 ) ) {
			return null;
		}

		return array( $start, $end );
	}

	/**
	 * Status and headers of a package response. Pure helper.
	 *
	 * @param int                     $size File size in bytes.
	 * @param string                  $download_name Safe download file name.
	 * @param string                  $content_md5 Base64 MD5 of the whole file, or an empty string.
	 * @param array{0:int,1:int}|null $range First and last byte of a partial response.
	 * @return array{status:int,headers:array<string,string>}
	 */
	public static function response_headers( int $size, string $download_name, string $content_md5, ?array $range ): array {
		$headers = array(
			'Content-Type' => 'application/zip',
			'Content-Disposition' => 'attachment; filename="' . $download_name . '"',
			'Accept-Ranges' => 'bytes',
			'X-Content-Type-Options' => 'nosniff',
			'Cache-Control' => 'no-store',
			'X-Robots-Tag' => 'noindex, nofollow',
		);
		if ( null !== $range ) {
			$headers['Content-Range'] = 'bytes ' . $range[0] . '-' . $range[1] . '/' . $size;
			$headers['Content-Length'] = (string) ( $range[1] - $range[0] + 1 );

			return array(
				'status' => 206,
				'headers' => $headers,
			);
		}
		$headers['Content-Length'] = (string) $size;
		if ( '' !== $content_md5 ) {
			// Checked by WordPress' download_url() and by many download tools (B-52).
			$headers['Content-MD5'] = $content_md5;
		}

		return array(
			'status' => 200,
			'headers' => $headers,
		);
	}

	/**
	 * Validate a web-server offload header. Pure helper.
	 *
	 * @param mixed $header Header line from the `wplc_package_offload_header` filter.
	 * @return string The header line, or an empty string when it is not an accepted offload header.
	 */
	public static function sanitize_offload_header( $header ): string {
		if ( ! is_string( $header ) || ! preg_match( '/^(X-Accel-Redirect|X-Sendfile|X-LiteSpeed-Location): [^\r\n]{1,1000}$/D', $header ) ) {
			return '';
		}

		return $header;
	}

	/**
	 * Delete stream grants that expired more than a day ago.
	 *
	 * The table also backs the hourly customer download limit, which only looks one hour back.
	 *
	 * @return void
	 */
	public static function cleanup(): void {
		global $wpdb;

		$tables = Schema::table_names();
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$tables['package_streams']} WHERE expires_at < %s",
				gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS )
			)
		);
	}

	/**
	 * Check a URL against the package host allow-list (same rules as the update API).
	 *
	 * HTTPS only, standard port, no credentials, no fragment, host listed in
	 * `wplc_update_package_hosts` (default: the home host).
	 *
	 * @param string $url URL to check.
	 * @return bool
	 */
	public static function is_trusted_url( string $url ): bool {
		$allowed_hosts = apply_filters( 'wplc_update_package_hosts', array( wp_parse_url( home_url(), PHP_URL_HOST ) ) );

		return self::url_matches_hosts( $url, is_array( $allowed_hosts ) ? $allowed_hosts : array() );
	}

	/**
	 * Host allow-list check. Pure helper.
	 *
	 * @param string  $url URL to check.
	 * @param mixed[] $allowed_hosts Allowed host names.
	 * @return bool
	 */
	public static function url_matches_hosts( string $url, array $allowed_hosts ): bool {
		$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure helper, PHP >= 7.4.
		if ( ! is_array( $parts ) ) {
			return false;
		}
		$allowed = array();
		foreach ( $allowed_hosts as $allowed_host ) {
			if ( is_string( $allowed_host ) && '' !== trim( $allowed_host ) ) {
				$allowed[] = strtolower( trim( $allowed_host ) );
			}
		}
		$host = strtolower( (string) ( $parts['host'] ?? '' ) );

		return 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) )
			&& ( ! isset( $parts['port'] ) || 443 === (int) $parts['port'] )
			&& ! isset( $parts['user'] )
			&& ! isset( $parts['pass'] )
			&& ! isset( $parts['fragment'] )
			&& '' !== $host
			&& in_array( $host, $allowed, true );
	}

	/**
	 * Whether a URL points to this site's home host.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private static function is_home_url( string $url ): bool {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );

		return is_string( $host ) && is_string( $home_host ) && '' !== $host && strtolower( $host ) === strtolower( $home_host );
	}

	/**
	 * File name offered to the browser or updater.
	 *
	 * @param array<string,mixed> $release Release row.
	 * @return string
	 */
	private static function download_name( array $release ): string {
		$name = (string) preg_replace( '/[^A-Za-z0-9._-]+/', '-', (string) $release['product_key'] . '-' . (string) $release['version'] );

		return trim( $name, '-.' ) . '.zip';
	}

	/**
	 * Send headers and file contents.
	 *
	 * @param string                  $path Absolute file path.
	 * @param int                     $size File size in bytes.
	 * @param string                  $download_name Safe download file name.
	 * @param string                  $content_md5 Base64 MD5 of the file, or an empty string.
	 * @param array{0:int,1:int}|null $range Requested byte range.
	 * @param bool                    $headers_only Whether this is a HEAD request.
	 * @param array<string,mixed>     $release Release row.
	 * @return void
	 */
	private static function send_file( string $path, int $size, string $download_name, string $content_md5, ?array $range, bool $headers_only, array $release ): void {
		/**
		 * Filters a header that hands the file to the web server instead of PHP (B-25).
		 *
		 * Return one complete header line, for example
		 * "X-Accel-Redirect: /wplc-protected/" . basename( $path ) for nginx or
		 * "X-Sendfile: " . $path for Apache mod_xsendfile. The PHP worker is then
		 * free as soon as the headers are sent, and the web server handles ranges.
		 *
		 * @param string              $header Default: empty, PHP streams the file.
		 * @param string              $path Absolute path of the package.
		 * @param array<string,mixed> $release Release row.
		 */
		$offload = $headers_only ? '' : self::sanitize_offload_header( apply_filters( 'wplc_package_offload_header', '', $path, $release ) );
		$response = self::response_headers( $size, $download_name, $content_md5, '' === $offload ? $range : null );

		// First: tests stop here, and nothing below can be half-sent with a wrong status.
		status_header( $response['status'] );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged
		}
		// Compression would invalidate Content-Length.
		@ini_set( 'zlib.output_compression', 'Off' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.Risky
		// Count once: a buffer that cannot be removed must not turn this into an endless loop (B-104).
		$levels = ob_get_level();
		for ( $i = 0; $i < $levels; $i++ ) {
			if ( ! @ob_end_clean() ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				break;
			}
		}
		if ( function_exists( 'header_remove' ) ) {
			header_remove( 'Content-Encoding' );
			header_remove( 'Link' );
		}
		foreach ( $response['headers'] as $name => $value ) {
			if ( '' !== $offload && 'Content-Length' === $name ) {
				// The web server sets the length of what it sends.
				continue;
			}
			header( $name . ': ' . $value );
		}
		if ( $headers_only ) {
			return;
		}
		if ( '' !== $offload ) {
			header( $offload );
			return;
		}
		if ( null === $range ) {
			readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
			return;
		}
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			return;
		}
		fseek( $handle, $range[0] );
		$remaining = $range[1] - $range[0] + 1;
		while ( $remaining > 0 && ! feof( $handle ) ) {
			$chunk = fread( $handle, (int) min( 1048576, $remaining ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary package data.
			$remaining -= strlen( $chunk );
			flush();
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}
}
