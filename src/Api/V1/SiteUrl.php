<?php
/**
 * Canonicalizes a site URL used as an activation identity.
 *
 * @package WPLC
 */

namespace WPLC\Api\V1;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Strict, stable normalization for live, staging, and development site URLs.
 *
 * Two forms exist:
 * - normalize() is the stored and displayed form (scheme, host, port, path).
 * - comparison_key() is the identity of a site for the site limit: without
 *   scheme and leading "www.", path in lower case. A site that moves from
 *   http to https or drops "www." keeps its slot.
 */
final class SiteUrl {

	/**
	 * Host labels that mark a staging copy on any domain.
	 */
	private const STAGING_LABELS = array( 'staging', 'stage', 'stg', 'dev', 'test', 'testing', 'preprod', 'preview', 'sandbox', 'uat' );

	/**
	 * Domain suffixes of hosting providers' temporary and staging hosts.
	 */
	private const STAGING_SUFFIXES = array(
		'.wpengine.com',
		'.wpenginepowered.com',
		'.kinsta.cloud',
		'.pantheonsite.io',
		'.cloudwaysapps.com',
		'.flywheelsites.com',
		'.flywheelstaging.com',
		'.myftpupload.com',
		'.sg-host.com',
		'.wpstage.net',
		'.wpcomstaging.com',
		'.instawp.xyz',
		'.tastewp.com',
		'.runcloud.link',
		'.ngrok.io',
		'.ngrok-free.app',
	);

	/**
	 * Suffixes of names that never resolve on the public internet or belong to local development tools.
	 */
	private const LOCAL_SUFFIXES = array(
		'.localhost',
		'.test',
		'.local',
		'.internal',
		'.lan',
		'.home.arpa',
		'.localdomain',
		'.ddev.site',
		'.lndo.site',
	);

	/**
	 * Normalize a site origin and optional WordPress subdirectory path.
	 *
	 * Rules (T-146, B-161, B-177):
	 * - only http and https; no credentials, query string or fragment;
	 * - no whitespace or control characters anywhere, valid UTF-8 only;
	 * - host in lower case, trailing dots removed, IDN as punycode, IPv6 in
	 *   compressed form; a host that ends in a number but is not a dotted-quad
	 *   IPv4 address (`0x7f.1`, `2130706433`, `192.168.001.010`) is rejected;
	 * - default ports are dropped;
	 * - path: duplicate and trailing slashes removed, dot segments rejected,
	 *   percent-escapes of unreserved characters decoded, other escapes in
	 *   upper case, non-ASCII bytes percent-encoded.
	 *
	 * The result is idempotent: normalize( normalize( x ) ) === normalize( x ).
	 *
	 * @param string $url Input URL.
	 * @return string Empty string when invalid.
	 */
	public static function normalize( string $url ): string {
		$url = trim( $url );
		if ( '' === $url || preg_match( '/[\x00-\x20\x7f]/', $url ) || ! preg_match( '//u', $url ) ) {
			return '';
		}

		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return '';
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}

		$host = self::normalize_host( (string) $parts['host'] );
		if ( '' === $host ) {
			return '';
		}

		$port = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
		if ( isset( $parts['port'] ) && ( $port < 1 || $port > 65535 ) ) {
			return '';
		}
		if ( ( 'https' === $scheme && 443 === $port ) || ( 'http' === $scheme && 80 === $port ) ) {
			$port = 0;
		}

		$authority = false !== strpos( $host, ':' ) ? '[' . $host . ']' : $host;
		if ( 0 !== $port ) {
			$authority .= ':' . $port;
		}

		$path = self::normalize_path( isset( $parts['path'] ) ? (string) $parts['path'] : '' );
		if ( null === $path ) {
			return '';
		}

		$normalized = $scheme . '://' . $authority . $path;

		// The activation column is varchar(255); reject instead of failing on insert.
		return strlen( $normalized ) > 255 ? '' : $normalized;
	}

	/**
	 * Identity of a site for "is this the same site" decisions.
	 *
	 * Scheme and a leading "www." are ignored and the path is compared in lower
	 * case, so switching to HTTPS or dropping "www." does not create a second
	 * site (B-161).
	 *
	 * @param string $url Site URL (normalized or not).
	 * @return string Empty string when the URL is invalid.
	 */
	public static function comparison_key( string $url ): string {
		$normalized = self::normalize( $url );
		if ( '' === $normalized ) {
			return '';
		}

		$rest = substr( $normalized, strpos( $normalized, '://' ) + 3 );
		$slash = strpos( $rest, '/' );
		$authority = false === $slash ? $rest : substr( $rest, 0, $slash );
		$path = false === $slash ? '' : substr( $rest, $slash );
		if ( 0 === strpos( $authority, 'www.' ) && false !== strpos( substr( $authority, 4 ), '.' ) ) {
			$authority = substr( $authority, 4 );
		}

		return $authority . strtolower( $path );
	}

	/**
	 * Host of a normalized site URL, without brackets and port.
	 *
	 * @param string $url Site URL.
	 * @return string Lower-case host, or an empty string.
	 */
	public static function host( string $url ): string {
		$normalized = self::normalize( $url );
		if ( '' === $normalized ) {
			return '';
		}
		$host = (string) parse_url( $normalized, PHP_URL_HOST );

		return trim( $host, '[]' );
	}

	/**
	 * Whether a host can only be a local development machine.
	 *
	 * Loopback, private, link-local and unique-local addresses, single-label
	 * names, reserved local TLDs and the domains of DDEV and Lando (B-162).
	 *
	 * @param string $host Host without brackets.
	 * @return bool
	 */
	public static function is_local_host( string $host ): bool {
		$host = strtolower( rtrim( trim( $host, '[]' ), '.' ) );
		if ( '' === $host ) {
			return false;
		}
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			// Private (10/8, 172.16/12, 192.168/16, fc00::/7) and reserved
			// (127/8, 169.254/16, ::1, fe80::/10) ranges are not public.
			return false === filter_var( ClientIp::normalize( $host ), FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}
		if ( false === strpos( $host, '.' ) ) {
			return true;
		}
		foreach ( self::LOCAL_SUFFIXES as $suffix ) {
			if ( substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Registrable domain of a host, without a public-suffix list.
	 *
	 * Takes the last two labels, or the last three when the second-level label
	 * is a generic one under a two-letter country code (`shop.example.co.uk`).
	 * IP addresses and single-label hosts are returned unchanged.
	 *
	 * @param string $host Host without brackets.
	 * @return string
	 */
	public static function registrable_domain( string $host ): string {
		$host = strtolower( rtrim( trim( $host, '[]' ), '.' ) );
		if ( '' === $host || false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return $host;
		}
		$labels = explode( '.', $host );
		$count = count( $labels );
		$take = 2;
		if ( $count >= 3 && 2 === strlen( $labels[ $count - 1 ] ) && in_array( $labels[ $count - 2 ], array( 'co', 'com', 'org', 'net', 'ac', 'gov', 'edu', 'or', 'ne', 'gv' ), true ) ) {
			$take = 3;
		}
		$domain = implode( '.', array_slice( $labels, -min( $take, $count ) ) );

		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filter the registrable domain derived from a host (for example with a public-suffix list).
			 *
			 * @param string $domain Derived registrable domain.
			 * @param string $host   Lower-case host.
			 */
			$filtered = apply_filters( 'wplc_registrable_domain', $domain, $host );
			$domain = is_string( $filtered ) && '' !== $filtered ? strtolower( $filtered ) : $domain;
		}

		return $domain;
	}

	/**
	 * Whether a host is plausible for the staging copy of a live site (B-157).
	 *
	 * True when the host is local, shares the registrable domain of the live
	 * site, carries a staging label (`staging.`, `dev.`, ...) or belongs to a
	 * hosting provider's staging domain.
	 *
	 * @param string $staging_url Staging site URL.
	 * @param string $parent_url  Site URL of the live activation.
	 * @return bool
	 */
	public static function is_staging_of( string $staging_url, string $parent_url ): bool {
		$host = self::host( $staging_url );
		$parent_host = self::host( $parent_url );
		$allowed = false;
		if ( '' !== $host ) {
			$allowed = self::is_local_host( $host )
				|| ( '' !== $parent_host && self::registrable_domain( $host ) === self::registrable_domain( $parent_host ) && false === filter_var( $host, FILTER_VALIDATE_IP ) )
				|| ( '' !== $parent_host && $host === $parent_host )
				|| array() !== array_intersect( explode( '.', $host ), self::STAGING_LABELS );
			foreach ( self::STAGING_SUFFIXES as $suffix ) {
				$allowed = $allowed || substr( $host, -strlen( $suffix ) ) === $suffix;
			}
		}

		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filter whether a host is accepted as the staging copy of a live site.
			 *
			 * A staging activation on a host that is not accepted is counted as
			 * a live site.
			 *
			 * @param bool   $allowed     Result of the built-in rule.
			 * @param string $host        Host of the staging site.
			 * @param string $parent_host Host of the live site.
			 */
			$allowed = (bool) apply_filters( 'wplc_is_staging_host', $allowed, $host, $parent_host );
		}

		return $allowed;
	}

	/**
	 * Convert an internationalized host to its ASCII (punycode) form.
	 *
	 * Uses ext-intl when present and the encoder bundled with WordPress
	 * otherwise, so IDN shops can activate on servers without intl (T-146).
	 *
	 * @param string $host     Lower-case or mixed-case host.
	 * @param bool   $use_intl Whether ext-intl may be used (tests cover the fallback with false).
	 * @return string|false ASCII host, or false when the host cannot be encoded.
	 */
	public static function to_ascii( string $host, bool $use_intl = true ) {
		if ( $use_intl && function_exists( 'idn_to_ascii' ) ) {
			$ascii = idn_to_ascii( $host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46 );

			return false === $ascii ? false : strtolower( $ascii );
		}
		if ( ! preg_match( '/[^\x00-\x7f]/', $host ) ) {
			return strtolower( $host );
		}
		if ( ! class_exists( '\\WpOrg\\Requests\\IdnaEncoder' ) ) {
			return false;
		}
		try {
			$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $host, 'UTF-8' ) : $host;

			return strtolower( (string) \WpOrg\Requests\IdnaEncoder::encode( $lower ) );
		} catch ( \Exception $exception ) {
			return false;
		}
	}

	/**
	 * Normalize the host component.
	 *
	 * @param string $raw Host as returned by parse_url().
	 * @return string Empty string when invalid; IPv6 without brackets.
	 */
	private static function normalize_host( string $raw ): string {
		if ( '' !== $raw && '[' === $raw[0] ) {
			if ( ']' !== substr( $raw, -1 ) ) {
				return '';
			}
			$literal = substr( $raw, 1, -1 );
			if ( false === filter_var( $literal, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
				return '';
			}
			$packed = inet_pton( $literal );
			$canonical = false === $packed ? false : inet_ntop( $packed );

			return false === $canonical ? '' : strtolower( $canonical );
		}

		$host = strtolower( rtrim( $raw, '.' ) );
		if ( '' === $host ) {
			return '';
		}
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return $host;
		}

		$ascii = self::to_ascii( $host );
		if ( false === $ascii ) {
			return '';
		}
		$host = $ascii;
		if ( ! preg_match( '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/', $host ) ) {
			return '';
		}
		// "Ends in a number": browsers would read such a host as an IPv4 address.
		$last_dot = strrpos( $host, '.' );
		$last_label = false === $last_dot ? $host : substr( $host, $last_dot + 1 );
		if ( preg_match( '/^(?:[0-9]+|0x[0-9a-f]*)$/', $last_label ) ) {
			return '';
		}

		return $host;
	}

	/**
	 * Normalize the path component.
	 *
	 * @param string $path Path as returned by parse_url().
	 * @return string|null Null when the path is not acceptable.
	 */
	private static function normalize_path( string $path ): ?string {
		if ( false !== strpos( $path, '\\' ) || preg_match( '/%(?![0-9a-fA-F]{2})/', $path ) ) {
			return null;
		}

		$path = (string) preg_replace_callback(
			'/%([0-9a-fA-F]{2})/',
			static function ( array $matches ): string {
				$char = chr( (int) hexdec( $matches[1] ) );

				return preg_match( '/^[A-Za-z0-9._~-]$/', $char ) ? $char : '%' . strtoupper( $matches[1] );
			},
			$path
		);
		$path = (string) preg_replace_callback(
			'/[^\x00-\x7f]/',
			static function ( array $matches ): string {
				return '%' . strtoupper( bin2hex( $matches[0] ) );
			},
			$path
		);
		$path = rtrim( (string) preg_replace( '#/+#', '/', $path ), '/' );

		foreach ( explode( '/', $path ) as $segment ) {
			if ( '.' === $segment || '..' === $segment ) {
				return null;
			}
		}

		return $path;
	}
}
