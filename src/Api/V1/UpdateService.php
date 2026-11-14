<?php
/**
 * Authenticated update metadata and short-lived package downloads.
 *
 * @package WPLC
 */

namespace WPLC\Api\V1;

use WPLC\Infrastructure\Database\Schema;
use WPLC\License\LicenseLifecycle;
use WPLC\Update\PackageStream;
use WPLC\Update\ReleaseRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves premium update metadata to activated installations.
 *
 * The response fields are documented in docs/UPDATES.md ("Update check
 * response"); product clients are built against that list.
 */
final class UpdateService {
	/** @var int Short-lived download grant lifetime. */
	private const TOKEN_TTL = 600;

	/** @var int Rows per DELETE when old grants are purged. */
	private const PURGE_BATCH = 1000;

	/**
	 * Return the current update for one active product installation.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function check( \WP_REST_Request $request ) {
		global $wpdb;
		$activation = Authenticator::authenticate( $request );
		if ( is_wp_error( $activation ) ) {
			return $activation;
		}

		$tables = Schema::table_names();
		$license = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$tables['licenses']} WHERE id = %d LIMIT 1", (int) $activation['license_id'] ),
			ARRAY_A
		);
		$product_key = sanitize_key( self::text_param( $request, 'product_key', 100 ) );
		$current = self::text_param( $request, 'current_version', 40 );
		if ( ! is_array( $license ) || $license['product_key'] !== $product_key ) {
			return new \WP_Error( 'license_inactive', __( 'An active license for this product is required to access updates.', 'wp-license' ), array( 'status' => 403 ) );
		}
		$has_access = LicenseLifecycle::has_access( $license );
		$sign = static function ( array $data ) use ( $activation ) {
			return Response::signed( $data, (string) $activation['installation_secret'], 200, (string) $activation['request_nonce'], (string) $activation['installation_uuid'] );
		};

		// Versions sent with this request win; otherwise the ones the installation reported last.
		$context = array(
			'channel' => ReleaseRegistry::normalize_channel( self::text_param( $request, 'channel', 20 ) ),
			'current_version' => $current,
			// A specific (older) version is a download feature and needs a license with access (B-145).
			'target_version' => $has_access ? self::text_param( $request, 'target_version', 40 ) : '',
			'wp_version' => self::first_text( self::text_param( $request, 'wp_version', 30 ), (string) ( $activation['wp_version'] ?? '' ) ),
			'php_version' => self::first_text( self::text_param( $request, 'php_version', 30 ), (string) ( $activation['php_version'] ?? '' ) ),
			'core_version' => self::text_param( $request, 'core_version', 30 ),
		);

		/**
		 * Filters the update packages on offer.
		 *
		 * @param mixed                $packages Array of packages keyed by product key; other values are ignored.
		 * @param string               $product_key Requested product key.
		 * @param array<string,string> $context channel, current_version, target_version, wp_version, php_version, core_version.
		 */
		$packages = apply_filters( 'wplc_update_packages', array(), $product_key, $context );
		$package = is_array( $packages ) ? ( $packages[ $product_key ] ?? null ) : null;

		$payload = array(
			'update_available' => false,
			'current_version' => $current,
			'product_key' => $product_key,
			'channel' => $context['channel'],
			'license_active' => $has_access,
		);
		if ( is_array( $package ) && isset( $package['blocked'] ) && is_array( $package['blocked'] ) ) {
			$payload['blocked'] = self::blocked_summary( $package['blocked'] );
		}
		if ( ! is_array( $package ) || empty( $package['version'] ) || empty( $package['package_url'] ) || empty( $package['sha256'] ) ) {
			if ( ! $has_access ) {
				$payload['reason'] = self::inactive_reason( $license );
			} else {
				$payload['reason'] = isset( $payload['blocked'] ) ? 'incompatible' : 'package_unavailable';
			}
			return $sign( $payload );
		}

		$version = sanitize_text_field( (string) $package['version'] );
		$is_newer = version_compare( $version, $current, '>' );
		$payload = array_merge( $payload, self::metadata( $product_key, $package, $version ) );
		$payload['update_available'] = $is_newer;
		$payload['package_url'] = '';
		$payload['package'] = '';
		if ( ! $has_access ) {
			// B-140: the installation learns that a version exists and why it cannot have it. No package, no grant.
			$payload['reason'] = self::inactive_reason( $license );
			return $sign( $payload );
		}

		$url = esc_url_raw( (string) $package['package_url'], array( 'https' ) );
		$checksum = strtolower( sanitize_text_field( (string) $package['sha256'] ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $checksum ) || ! self::trusted_package_url( $url ) ) {
			return new \WP_Error( 'package_configuration_invalid', __( 'The update package is not configured with a trusted HTTPS URL and SHA-256 checksum.', 'wp-license' ), array( 'status' => 503 ) );
		}
		// With target_version the package is offered even when it is older than the installed one.
		$offer = $is_newer || ( '' !== $context['target_version'] && $version === $context['target_version'] && $version !== $current );
		if ( '' !== $context['target_version'] ) {
			$payload['target_version'] = $context['target_version'];
		}
		if ( ! $offer ) {
			$payload['reason'] = isset( $payload['blocked'] ) ? 'incompatible' : 'up_to_date';
			return $sign( $payload );
		}
		$payload['sha256'] = $checksum;
		if ( ! self::wants_download( $request ) ) {
			// A-40: a periodic check creates no grant. The client asks again with download=true right before it downloads.
			return $sign( $payload );
		}

		$token = bin2hex( random_bytes( 32 ) );
		$token_hash = hash( 'sha256', $token );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + self::TOKEN_TTL );
		$now = gmdate( 'Y-m-d H:i:s' );
		$inserted = $wpdb->insert(
			$tables['download_tokens'],
			array(
				'license_id' => (int) $license['id'],
				'token_hash' => $token_hash,
				'package_url' => $url,
				'expires_at' => $expires_at,
				'consumed_at' => null,
				'created_at' => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( false === $inserted ) {
			return new \WP_Error( 'download_token_error', __( 'A secure package download could not be prepared.', 'wp-license' ), array( 'status' => 503 ) );
		}
		$payload['package_url'] = rest_url( 'wplc/v1/updates/download/' . $token );
		$payload['package'] = $payload['package_url'];
		$payload['download_expires_at'] = $expires_at;

		return $sign( $payload );
	}

	/**
	 * Redeem a download grant and redirect to the trusted package host.
	 *
	 * HEAD requests are answered without using the grant, and a used grant keeps
	 * working for a short reuse window (B-19). The grant is only marked as used
	 * once a deliverable target is known (T-142, A-111).
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return void
	 */
	public static function download( \WP_REST_Request $request ): void {
		global $wpdb;
		// The token is part of the URL: keep the response out of caches
		// (DONOTCACHEPAGE, no-store) and the URL out of any Referer header.
		PackageStream::send_no_cache_headers();
		$token = strtolower( self::text_param( $request, 'token', 64 ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $token ) ) {
			status_header( 404 );
			exit;
		}
		$tables = Schema::table_names();
		$token_hash = hash( 'sha256', $token );
		$now = gmdate( 'Y-m-d H:i:s' );
		$reuse = PackageStream::reuse_seconds();
		$reuse_cutoff = gmdate( 'Y-m-d H:i:s', time() - $reuse );
		$grant = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT dt.package_url, dt.license_id, dt.consumed_at, l.product_key, l.status, l.paid_until, l.renewal_enabled, l.cancel_at_period_end FROM {$tables['download_tokens']} dt INNER JOIN {$tables['licenses']} l ON l.id = dt.license_id WHERE dt.token_hash = %s AND ( ( dt.consumed_at IS NULL AND dt.expires_at > %s ) OR dt.consumed_at > %s ) LIMIT 1",
				$token_hash,
				$now,
				$reuse_cutoff
			),
			ARRAY_A
		);
		if ( ! is_array( $grant ) ) {
			// A used grant must not keep the package location once the reuse window is over.
			$wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names().
					"UPDATE {$tables['download_tokens']} SET package_url = '' WHERE token_hash = %s AND consumed_at IS NOT NULL AND consumed_at <= %s",
					$token_hash,
					$reuse_cutoff
				)
			);
		}
		if ( is_array( $grant ) && ! LicenseLifecycle::has_access( $grant ) ) {
			$grant = null;
		}
		if ( ! is_array( $grant )
			|| '' === (string) $grant['package_url']
			|| ! self::trusted_package_url( (string) $grant['package_url'] )
			// B-143: a release withdrawn after the grant was issued is not delivered any more.
			|| ! ReleaseRegistry::grant_is_deliverable( (string) $grant['package_url'], (string) $grant['product_key'] )
		) {
			status_header( 404 );
			exit;
		}
		if ( 'HEAD' === strtoupper( (string) $request->get_method() ) ) {
			// Probes of download managers and scanning proxies: nothing is used up, nothing is disclosed.
			status_header( 200 );
			if ( ! headers_sent() ) {
				header( 'Content-Type: application/zip' );
			}
			exit;
		}

		// Resolve first: a resolver that fails must not cost the client its grant.
		$download_url = apply_filters(
			'wplc_resolve_update_package_url',
			(string) $grant['package_url'],
			(int) $grant['license_id']
		);
		if ( ! is_string( $download_url ) || ! self::trusted_package_url( $download_url ) || ! self::may_redirect_to( $download_url, (string) $grant['package_url'] ) ) {
			status_header( 404 );
			exit;
		}

		if ( null === $grant['consumed_at'] ) {
			// With strict single use the location is forgotten at once; otherwise after the reuse window.
			$forget = 0 === $reuse ? ", package_url = ''" : '';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $forget is one of two literals.
			$consumed = $wpdb->query( $wpdb->prepare( "UPDATE {$tables['download_tokens']} SET consumed_at = %s{$forget} WHERE token_hash = %s AND consumed_at IS NULL AND expires_at > %s", $now, $token_hash, $now ) );
			// A lost race is a second fetch of the same URL: fine inside the reuse window only.
			if ( 1 !== (int) $consumed && 0 === $reuse ) {
				status_header( 404 );
				exit;
			}
		}
		wp_redirect( $download_url, 302, 'WP License Captain' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- host checked against the package allow-list above.
		exit;
	}

	/**
	 * Whether a client may be redirected to the resolved package URL (A-32).
	 *
	 * Without a resolver, a release with storage "url" redirects every licensed
	 * client to its permanent address, which keeps working after the license
	 * ended. With `wplc_require_package_resolver` returning true such a redirect
	 * is refused: only a URL changed by `wplc_resolve_update_package_url`
	 * (pre-signed, short-lived) or the local streaming route is delivered.
	 *
	 * @param string $resolved URL after `wplc_resolve_update_package_url`.
	 * @param string $stored URL stored with the release or grant.
	 * @return bool
	 */
	public static function may_redirect_to( string $resolved, string $stored ): bool {
		/**
		 * Filters whether packages may only be delivered through a resolver.
		 *
		 * @param bool   $required Default false.
		 * @param string $stored Stored package URL.
		 */
		if ( ! apply_filters( 'wplc_require_package_resolver', false, $stored ) ) {
			return true;
		}

		return $resolved !== $stored;
	}

	/**
	 * Whether a custom package resolver is registered (A-32).
	 *
	 * Without one, releases with storage "url" are delivered at their permanent address.
	 *
	 * @return bool True when a resolver besides the built-in one for local packages exists.
	 */
	public static function has_package_resolver(): bool {
		global $wp_filter;

		if ( ! isset( $wp_filter['wplc_resolve_update_package_url'] ) ) {
			return false;
		}
		foreach ( $wp_filter['wplc_resolve_update_package_url']->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( array( PackageStream::class, 'resolve' ) !== $callback['function'] ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Purge expired grants after a configurable retention period.
	 *
	 * Deletes in batches along the (expires_at, consumed_at) index, so the job
	 * neither scans the table nor holds one long lock (A-40).
	 *
	 * @return void
	 */
	public static function purge_expired_grants(): void {
		global $wpdb;

		$days = absint( apply_filters( 'wplc_download_grant_retention_days', 7 ) );
		$days = max( 1, min( 3650, $days ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$tables = Schema::table_names();

		// Used grants only need their package location during the reuse window.
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names().
				"UPDATE {$tables['download_tokens']} SET package_url = '' WHERE consumed_at IS NOT NULL AND consumed_at <= %s AND package_url <> '' LIMIT %d",
				gmdate( 'Y-m-d H:i:s', time() - PackageStream::reuse_seconds() ),
				10 * self::PURGE_BATCH
			)
		);
		// A grant is used before it expires, so expires_at alone decides its age.
		for ( $i = 0; $i < 50; $i++ ) {
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$tables['download_tokens']} WHERE expires_at < %s ORDER BY expires_at ASC LIMIT %d",
					$cutoff,
					self::PURGE_BATCH
				)
			);
			if ( false === $deleted || (int) $deleted < self::PURGE_BATCH ) {
				break;
			}
		}
	}

	/**
	 * Machine-readable reason why a license gets no package. Pure helper.
	 *
	 * @param array<string,mixed> $license License row.
	 * @return string license_expired, license_suspended, license_revoked, license_pending or license_inactive.
	 */
	public static function inactive_reason( array $license ): string {
		switch ( (string) ( $license['status'] ?? '' ) ) {
			case 'expired':
			case 'active':
			case 'past_due':
				// "active"/"past_due" without access: the paid period (and grace) is over.
				return 'license_expired';
			case 'suspended':
				return 'license_suspended';
			case 'revoked':
				return 'license_revoked';
			case 'pending':
				return 'license_pending';
			default:
				return 'license_inactive';
		}
	}

	/**
	 * Build the changelog HTML of the details view. Pure helper.
	 *
	 * @param array<int,array{version:string,date:string,notes:string}> $changelog Entries, highest version first.
	 * @return string
	 */
	public static function changelog_html( array $changelog ): string {
		$html = '';
		foreach ( $changelog as $entry ) {
			$date = substr( (string) $entry['date'], 0, 10 );
			$html .= '<h4>' . htmlspecialchars( (string) $entry['version'], ENT_QUOTES, 'UTF-8' ) . ( '' !== $date ? ' &ndash; ' . htmlspecialchars( $date, ENT_QUOTES, 'UTF-8' ) : '' ) . '</h4>';
			$html .= (string) $entry['notes'];
		}

		return $html;
	}

	/**
	 * Everything a client may know about the offered version, without the package.
	 *
	 * @param string              $product_key Product key.
	 * @param array<string,mixed> $package Entry of `wplc_update_packages`.
	 * @param string              $version Sanitized version.
	 * @return array<string,mixed>
	 */
	private static function metadata( string $product_key, array $package, string $version ): array {
		$info = ReleaseRegistry::product_info( $product_key );
		$notes = wp_kses_post( (string) ( $package['release_notes'] ?? '' ) );
		$changelog = array();
		foreach ( array_slice( isset( $package['changelog'] ) && is_array( $package['changelog'] ) ? $package['changelog'] : array(), 0, 20 ) as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['version'] ) ) {
				continue;
			}
			$changelog[] = array(
				'version' => sanitize_text_field( (string) $entry['version'] ),
				'date' => sanitize_text_field( (string) ( $entry['date'] ?? '' ) ),
				'notes' => wp_kses_post( (string) ( $entry['notes'] ?? '' ) ),
			);
		}
		if ( ! $changelog ) {
			$changelog[] = array(
				'version' => $version,
				'date' => sanitize_text_field( (string) ( $package['last_updated'] ?? '' ) ),
				'notes' => $notes,
			);
		}
		$slug = sanitize_text_field( (string) ( $package['slug'] ?? '' ) );
		$slug = '' !== $slug ? $slug : ReleaseRegistry::product_slug( $product_key );
		$requires_php = sanitize_text_field( (string) ( $package['requires_php'] ?? '' ) );
		$requires_wp = sanitize_text_field( (string) ( $package['requires_wp'] ?? '' ) );
		$tested_wp = sanitize_text_field( (string) ( $package['tested_wp'] ?? '' ) );

		return array(
			'name' => $info['name'],
			// Folder of the plugin; falls back to the product key until a package was inspected.
			'slug' => '' !== $slug ? $slug : $product_key,
			'plugin' => sanitize_text_field( (string) ( $package['plugin'] ?? '' ) ),
			'homepage' => $info['homepage'],
			'version' => $version,
			'new_version' => $version,
			'last_updated' => sanitize_text_field( (string) ( $package['last_updated'] ?? '' ) ),
			'requires_php' => $requires_php,
			'requires_wp' => $requires_wp,
			'requires' => $requires_wp,
			'tested_wp' => $tested_wp,
			'tested' => $tested_wp,
			'requires_core' => sanitize_text_field( (string) ( $package['requires_core'] ?? '' ) ),
			'release_notes' => $notes,
			'changelog' => $changelog,
			'sections' => array( 'changelog' => self::changelog_html( $changelog ) ),
			// An empty list, not an empty object: clients that re-encode the data to check the legacy signature get the same JSON.
			'icons' => $info['icons'],
		);
	}

	/**
	 * Sanitize the "a higher version exists but does not fit" notice.
	 *
	 * @param array<string,mixed> $blocked Value from the registry.
	 * @return array<string,mixed>
	 */
	private static function blocked_summary( array $blocked ): array {
		$unmet = array();
		foreach ( isset( $blocked['unmet'] ) && is_array( $blocked['unmet'] ) ? $blocked['unmet'] : array() as $field ) {
			if ( in_array( $field, array( 'requires_php', 'requires_wp', 'requires_core' ), true ) ) {
				$unmet[] = $field;
			}
		}

		return array(
			'version' => sanitize_text_field( (string) ( $blocked['version'] ?? '' ) ),
			'unmet' => $unmet,
			'requires_php' => sanitize_text_field( (string) ( $blocked['requires_php'] ?? '' ) ),
			'requires_wp' => sanitize_text_field( (string) ( $blocked['requires_wp'] ?? '' ) ),
			'requires_core' => sanitize_text_field( (string) ( $blocked['requires_core'] ?? '' ) ),
		);
	}

	/**
	 * Whether this check should create a download grant (A-40).
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return bool
	 */
	private static function wants_download( \WP_REST_Request $request ): bool {
		$value = $request->get_param( 'download' );
		if ( null === $value || ! is_scalar( $value ) ) {
			/**
			 * Filters whether an update check without the `download` parameter creates a download grant.
			 *
			 * True keeps clients working that predate the parameter. Set it to
			 * false once every shipped client sends `download`.
			 *
			 * @param bool $default Default true.
			 */
			return (bool) apply_filters( 'wplc_update_check_issues_download', true );
		}

		return rest_sanitize_boolean( $value );
	}

	/**
	 * Read a request parameter as one short line of text; arrays become an empty string.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @param string           $name Parameter name.
	 * @param int              $max_length Maximum length.
	 * @return string
	 */
	private static function text_param( \WP_REST_Request $request, string $name, int $max_length ): string {
		$value = $request->get_param( $name );
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return substr( trim( sanitize_text_field( (string) $value ) ), 0, $max_length );
	}

	/**
	 * First non-empty string.
	 *
	 * @param string $preferred Preferred value.
	 * @param string $fallback Fallback value.
	 * @return string
	 */
	private static function first_text( string $preferred, string $fallback ): string {
		return '' !== $preferred ? $preferred : trim( $fallback );
	}

	/**
	 * Check package URL against an explicit host allow-list.
	 *
	 * @param string $url Package URL.
	 * @return bool
	 */
	private static function trusted_package_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		$host = strtolower( (string) ( $parts['host'] ?? '' ) );
		$allowed_hosts = apply_filters( 'wplc_update_package_hosts', array( wp_parse_url( home_url(), PHP_URL_HOST ) ) );
		$allowed = array();
		if ( is_array( $allowed_hosts ) ) {
			foreach ( $allowed_hosts as $allowed_host ) {
				if ( is_string( $allowed_host ) && '' !== trim( $allowed_host ) ) {
					$allowed[] = strtolower( trim( $allowed_host ) );
				}
			}
		}
		$port_is_https_default = ! isset( $parts['port'] ) || 443 === (int) $parts['port'];
		$has_no_embedded_credentials = ! isset( $parts['user'] ) && ! isset( $parts['pass'] );
		$has_no_fragment = ! isset( $parts['fragment'] );

		return 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) )
			&& $port_is_https_default
			&& $has_no_embedded_credentials
			&& $has_no_fragment
			&& '' !== $host
			&& in_array( $host, $allowed, true );
	}
}
