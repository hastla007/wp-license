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
 * Turns a local release into a short-lived, single-use download URL on this site.
 *
 * Flow for updates: the registry hands UpdateService a stable placeholder URL
 * (release_url()). After UpdateService has consumed its own download grant it
 * applies `wplc_resolve_update_package_url`; resolve() then issues a stream
 * token and returns the streaming route, which serves the file through PHP.
 */
final class PackageStream {
	const TTL = 300;
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
				'methods' => 'GET',
				'callback' => array( self::class, 'serve' ),
				// The unguessable single-use token is the credential.
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
		$release_id = self::parse_release_url( $url );
		if ( $release_id <= 0 || ! self::is_home_url( $url ) ) {
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
	 * Consume a stream grant and send the package.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_Error|void Exits after streaming.
	 */
	public static function serve( \WP_REST_Request $request ) {
		global $wpdb;

		$not_found = new \WP_Error( 'package_not_found', __( 'This download link is invalid or has expired.', 'wp-license' ), array( 'status' => 404 ) );
		$token = strtolower( (string) $request->get_param( 'token' ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
			return $not_found;
		}
		$tables = Schema::table_names();
		$token_hash = hash( 'sha256', $token );
		$now = gmdate( 'Y-m-d H:i:s' );
		$grant = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT s.release_id, l.status, l.paid_until, l.renewal_enabled, l.cancel_at_period_end FROM {$tables['package_streams']} s INNER JOIN {$tables['licenses']} l ON l.id = s.license_id WHERE s.token_hash = %s AND s.consumed_at IS NULL AND s.expires_at > %s LIMIT 1",
				$token_hash,
				$now
			),
			ARRAY_A
		);
		if ( ! is_array( $grant ) || ! LicenseLifecycle::has_access( $grant ) ) {
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

		// Atomic single use: only one concurrent request can flip consumed_at.
		$consumed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$tables['package_streams']} SET consumed_at = %s WHERE token_hash = %s AND consumed_at IS NULL AND expires_at > %s",
				$now,
				$token_hash,
				$now
			)
		);
		if ( 1 !== (int) $consumed ) {
			return $not_found;
		}

		self::send_file( $path, (int) $size, self::download_name( $release ) );
		exit;
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
	 * @param string $path Absolute file path.
	 * @param int    $size File size in bytes.
	 * @param string $download_name Safe download file name.
	 * @return void
	 */
	private static function send_file( string $path, int $size, string $download_name ): void {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged
		}
		// Compression would invalidate Content-Length.
		@ini_set( 'zlib.output_compression', 'Off' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.Risky
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		if ( function_exists( 'header_remove' ) ) {
			header_remove( 'Content-Encoding' );
			header_remove( 'Link' );
		}
		status_header( 200 );
		header( 'Content-Type: application/zip' );
		header( 'Content-Length: ' . $size );
		header( 'Content-Disposition: attachment; filename="' . $download_name . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: no-store' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
	}
}
