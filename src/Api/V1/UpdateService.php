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
use WPLC\Update\S3Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves premium update metadata to activated installations.
 *
 * The WordPress updater downloads `package` with a plain GET and no custom headers, so the grant has to be in the
 * URL and reaches access logs and proxies (T-108). The URL therefore carries only a random value that is stored
 * as a SHA-256 hash and has no relation to the license key or the installation secret. It is valid for five
 * minutes, bound to the installation that asked for it (it stops working when that activation ends) and, by
 * default, used up by the first GET that presents it, whatever that request's outcome: a value seen in a log is
 * useless. HEAD probes never use a grant up; a short reuse window can be allowed with the filter
 * `wplc_download_reuse_seconds` (B-19). A check with `download=false` creates no grant at all.
 *
 * An installation holds at most MAX_OPEN_GRANTS unused grants per package: a further check with
 * `download=true` replaces the oldest of them, so repeated checks cannot fill the table (P-11). Unused grants are deleted as soon as they expire.
 *
 * The response fields are documented in docs/UPDATES.md ("Update check
 * response"); product clients are built against that list.
 */
final class UpdateService {
	/** @var int Short-lived download grant lifetime. Clients ask for a fresh grant right before downloading. */
	public const TOKEN_TTL = 300;

	/** @var int Unused, unexpired grants one installation may hold for the same package (P-11). */
	public const MAX_OPEN_GRANTS = 2;

	/** @var int Rows per DELETE when old grants are purged. */
	private const PURGE_BATCH = 1000;

	/** @var int Seconds one purge run may take. */
	private const PURGE_SECONDS = 20;

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
			return new \WP_Error( 'license_inactive', 'An active license for this product is required to access updates.', array( 'status' => 403 ) );
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
		$is_newer = version_compare( $version, self::installed_version( $current, (string) ( $activation['product_version'] ?? '' ) ), '>' );
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
			return new \WP_Error( 'package_configuration_invalid', 'The update package is not configured with a trusted HTTPS URL and SHA-256 checksum.', array( 'status' => 503 ) );
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
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + self::TOKEN_TTL );
		if ( ! self::store_grant( (int) $license['id'], (int) $activation['id'], $url, hash( 'sha256', $token ), $expires_at ) ) {
			return new \WP_Error( 'download_token_error', 'A secure package download could not be prepared.', array( 'status' => 503 ) );
		}
		$payload['package_url'] = rest_url( 'wplc/v1/updates/download/' . $token );
		$payload['package'] = $payload['package_url'];
		$payload['download_expires_at'] = $expires_at;

		return $sign( $payload );
	}

	/**
	 * Redeem a download grant and redirect to the trusted package host.
	 *
	 * HEAD requests are answered without using the grant.
	 *
	 * By default (strict single use, T-108) the grant is used up by the first GET before anything else is checked.
	 * A request that fails afterwards (license without access, installation deactivated, storage hook returning a URL
	 * that is not allowed) has still consumed it; the client asks for a fresh grant with a new update check.
	 *
	 * With a reuse window (filter `wplc_download_reuse_seconds`, B-19) the grant is only marked as used once a
	 * deliverable target is known (T-142, A-111) and keeps working for a second fetch inside the window.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return void
	 */
	public static function download( \WP_REST_Request $request ): void {
		global $wpdb;
		// The token is part of the URL: keep the response out of caches
		// (DONOTCACHEPAGE, no-store) and the URL out of any Referer header.
		PackageStream::send_no_cache_headers();
		// The token is a path parameter of an unsigned route.
		$token = strtolower( self::text_value( $request->get_param( 'token' ), 64 ) );
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
				"SELECT dt.package_url, dt.license_id, dt.activation_id, dt.consumed_at, l.product_key, l.status, l.paid_until, l.renewal_enabled, l.cancel_at_period_end FROM {$tables['download_tokens']} dt INNER JOIN {$tables['licenses']} l ON l.id = dt.license_id WHERE dt.token_hash = %s AND ( ( dt.consumed_at IS NULL AND dt.expires_at > %s ) OR dt.consumed_at > %s ) LIMIT 1",
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
			status_header( 404 );
			exit;
		}
		$is_head = 'HEAD' === strtoupper( (string) $request->get_method() );
		if ( ! $is_head && null === $grant['consumed_at'] && 0 === $reuse ) {
			// T-108: strict single use on first presentation. Only one concurrent request can flip consumed_at,
			// and it does so before any other check, so the value never works a second time, also not after a failure.
			$consumed = $wpdb->query(
				$wpdb->prepare(
					"UPDATE %i SET consumed_at = %s, package_url = '' WHERE token_hash = %s AND consumed_at IS NULL AND expires_at > %s",
					$tables['download_tokens'],
					$now,
					$token_hash,
					$now
				)
			);
			if ( 1 !== (int) $consumed ) {
				status_header( 404 );
				exit;
			}
			$grant['consumed_at'] = $now;
		}
		if ( ! LicenseLifecycle::has_access( $grant )
			|| ! self::activation_is_active( (int) $grant['activation_id'], (int) $grant['license_id'] )
			|| '' === (string) $grant['package_url']
			|| ! self::trusted_package_url( (string) $grant['package_url'] )
			// B-143: a release withdrawn after the grant was issued is not delivered any more.
			|| ! ReleaseRegistry::grant_is_deliverable( (string) $grant['package_url'], (string) $grant['product_key'] )
		) {
			status_header( 404 );
			exit;
		}
		if ( $is_head ) {
			// Probes of download managers and scanning proxies: nothing is used up, nothing is disclosed.
			status_header( 200 );
			if ( ! headers_sent() ) {
				header( 'Content-Type: application/zip' );
			}
			exit;
		}

		// With a reuse window (wplc_download_reuse_seconds > 0) the grant is still unused here: resolve first, so that
		// a failing resolver does not cost the client its grant. With strict single use it was consumed above.
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
	 * Whether the installation a grant was issued to is still active for that license.
	 *
	 * Grants issued before schema 16 carry no activation (0) and are only bound to the license.
	 *
	 * @param int $activation_id Activation ID from the grant.
	 * @param int $license_id    License ID from the grant.
	 * @return bool
	 */
	private static function activation_is_active( int $activation_id, int $license_id ): bool {
		global $wpdb;

		if ( 0 === $activation_id ) {
			return true;
		}
		$tables = Schema::table_names();

		return 1 === (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$tables['activations']} WHERE id = %d AND license_id = %d AND status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names(), values prepared.
				$activation_id,
				$license_id,
				'active'
			)
		);
	}

	/**
	 * Whether a client may be redirected to the resolved package URL (A-32).
	 *
	 * A redirect to the unchanged stored address of a release with storage
	 * "url" would tell every licensed client the permanent address, which
	 * keeps working after the license ended. By default such a redirect is
	 * refused: only a URL changed by `wplc_resolve_update_package_url`
	 * (pre-signed, short-lived) or the local streaming route is delivered.
	 * A shop that accepts the permanent address returns false from
	 * `wplc_require_package_resolver`.
	 *
	 * @param string $resolved URL after `wplc_resolve_update_package_url`.
	 * @param string $stored URL stored with the release or grant.
	 * @return bool
	 */
	public static function may_redirect_to( string $resolved, string $stored ): bool {
		return ! self::requires_package_resolver( $stored ) || $resolved !== $stored;
	}

	/**
	 * Whether a package URL may only be delivered after a resolver changed it (A-32).
	 *
	 * @param string $stored Stored package URL ('' when asked in general, e.g. for the releases screen).
	 * @return bool
	 */
	public static function requires_package_resolver( string $stored = '' ): bool {
		/**
		 * Filters whether packages may only be delivered through a resolver.
		 *
		 * @param bool   $required Default true since A-32 (was false): never redirect to an unchanged stored URL.
		 * @param string $stored Stored package URL, or '' when asked in general.
		 */
		return (bool) apply_filters( 'wplc_require_package_resolver', true, $stored );
	}

	/**
	 * Whether a custom package resolver is registered (A-32).
	 *
	 * Without one, releases with storage "url" are delivered at their permanent address.
	 *
	 * @return bool True when a resolver besides the built-in one for local packages exists, or object storage is configured (T-31).
	 */
	public static function has_package_resolver(): bool {
		global $wp_filter;

		if ( ! isset( $wp_filter['wplc_resolve_update_package_url'] ) ) {
			return false;
		}
		foreach ( $wp_filter['wplc_resolve_update_package_url']->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( array( S3Storage::class, 'resolve' ) === $callback['function'] ) {
					// The built-in object storage resolver only counts once a bucket is configured.
					if ( S3Storage::is_configured() ) {
						return true;
					}
					continue;
				}
				if ( array( PackageStream::class, 'resolve' ) !== $callback['function'] ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Store a download grant, reusing an unused one of the same installation and package (P-11).
	 *
	 * Below MAX_OPEN_GRANTS a row is added. At the limit the oldest unused
	 * grant (lowest ID) is replaced by the new one, so its URL stops working
	 * and the number of rows stays the same. The replacement is a new row, so
	 * the ID order stays the order of issue.
	 *
	 * @param int    $license_id    License ID.
	 * @param int    $activation_id Activation ID.
	 * @param string $package_url   Package URL of the grant.
	 * @param string $token_hash    SHA-256 of the new grant value.
	 * @param string $expires_at    UTC expiry.
	 * @return bool False when the grant could not be stored.
	 */
	private static function store_grant( int $license_id, int $activation_id, string $package_url, string $token_hash, string $expires_at ): bool {
		global $wpdb;

		$tables = Schema::table_names();
		$now = gmdate( 'Y-m-d H:i:s' );
		$open = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names().
				"SELECT id FROM {$tables['download_tokens']} WHERE license_id = %d AND activation_id = %d AND consumed_at IS NULL AND expires_at > %s AND package_url = %s ORDER BY id ASC LIMIT %d",
				$license_id,
				$activation_id,
				$now,
				$package_url,
				self::MAX_OPEN_GRANTS
			),
			ARRAY_A
		);
		if ( is_array( $open ) && count( $open ) >= self::MAX_OPEN_GRANTS ) {
			// Only still unused: a grant redeemed in the meantime stays as download history.
			$wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names().
					"DELETE FROM {$tables['download_tokens']} WHERE id = %d AND consumed_at IS NULL",
					(int) $open[0]['id']
				)
			);
		}

		return false !== $wpdb->insert(
			$tables['download_tokens'],
			array(
				'license_id' => $license_id,
				'activation_id' => $activation_id,
				'token_hash' => $token_hash,
				'package_url' => $package_url,
				'expires_at' => $expires_at,
				'consumed_at' => null,
				'created_at' => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Version the update decision is based on (P-11).
	 *
	 * The higher of the version sent with the check and the one the
	 * installation reported last (activation, entitlement check). A check
	 * with `current_version: "0"` therefore does not turn every check into a
	 * download grant for a version the installation already has. An explicit
	 * older version is requested with `target_version`.
	 *
	 * @param string $current  Version sent with the update check.
	 * @param string $reported Version stored on the activation.
	 * @return string
	 */
	public static function installed_version( string $current, string $reported ): string {
		$reported = trim( $reported );
		if ( '' === $reported || ! preg_match( '/^\d/', $reported ) ) {
			return $current;
		}

		return version_compare( $reported, $current, '>' ) ? $reported : $current;
	}

	/**
	 * Purge expired grants.
	 *
	 * Unused grants are deleted as soon as they expire: they carry no history.
	 * Used grants are kept for the retention period
	 * (`wplc_download_grant_retention_days`, default 7) as download history.
	 * Both deletes run in batches along the (expires_at, consumed_at) index
	 * until nothing is left or the time budget is used, so the job keeps up
	 * with any volume without holding one long lock (A-40, P-11). Runs hourly
	 * and daily.
	 *
	 * @return void
	 */
	public static function purge_expired_grants(): void {
		global $wpdb;

		$days = absint( apply_filters( 'wplc_download_grant_retention_days', 7 ) );
		$days = max( 1, min( 3650, $days ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$now = gmdate( 'Y-m-d H:i:s' );
		$tables = Schema::table_names();
		$deadline = microtime( true ) + self::PURGE_SECONDS;

		// Used grants only need their package location during the reuse window.
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names().
				"UPDATE {$tables['download_tokens']} SET package_url = '' WHERE consumed_at IS NOT NULL AND consumed_at <= %s AND package_url <> '' LIMIT %d",
				gmdate( 'Y-m-d H:i:s', time() - PackageStream::reuse_seconds() ),
				10 * self::PURGE_BATCH
			)
		);
		$queries = array(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names().
			$wpdb->prepare( "DELETE FROM {$tables['download_tokens']} WHERE expires_at < %s AND consumed_at IS NULL ORDER BY expires_at ASC LIMIT %d", $now, self::PURGE_BATCH ),
			// A grant is used before it expires, so expires_at alone decides its age.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names().
			$wpdb->prepare( "DELETE FROM {$tables['download_tokens']} WHERE expires_at < %s ORDER BY expires_at ASC LIMIT %d", $cutoff, self::PURGE_BATCH ),
		);
		foreach ( $queries as $query ) {
			do {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
				$deleted = $wpdb->query( $query );
			} while ( false !== $deleted && (int) $deleted >= self::PURGE_BATCH && microtime( true ) < $deadline );
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
		$value = Authenticator::signed_param( $request, 'download' );
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
	 * Read a signed parameter (JSON body only, P-12) as one short line of text; arrays become an empty string.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @param string           $name Parameter name.
	 * @param int              $max_length Maximum length.
	 * @return string
	 */
	private static function text_param( \WP_REST_Request $request, string $name, int $max_length ): string {
		return self::text_value( Authenticator::signed_param( $request, $name ), $max_length );
	}

	/**
	 * One short line of text from a raw parameter value; arrays become an empty string.
	 *
	 * @param mixed $value      Raw value.
	 * @param int   $max_length Maximum length.
	 * @return string
	 */
	private static function text_value( $value, int $max_length ): string {
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
		// One implementation of the rule for downloads through the update API and from the customer account.
		return \WPLC\Update\PackageStream::is_trusted_url( $url );
	}
}
