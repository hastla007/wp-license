<?php
/**
 * Canonicalizes a site URL used as an activation identity.
 *
 * @package WPLC
 */

namespace WPLC\Api\V1;

/**
 * Strict, stable normalization for live, staging, and development site URLs.
 */
final class SiteUrl {
	/**
	 * Normalize a site origin and optional WordPress subdirectory path.
	 *
	 * Query strings, fragments, and embedded credentials are rejected because
	 * they are not part of a site's identity and can leak or spoof identifiers.
	 *
	 * @param string $url Input URL.
	 * @return string Empty string when invalid.
	 */
	public static function normalize( string $url ): string {
		$parts = parse_url( trim( $url ) );
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

		$host = strtolower( trim( (string) $parts['host'], '[] .' ) );
		if ( '' === $host ) {
			return '';
		}

		if ( ! filter_var( $host, FILTER_VALIDATE_IP ) && function_exists( 'idn_to_ascii' ) ) {
			$ascii_host = idn_to_ascii( $host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
			if ( false === $ascii_host ) {
				return '';
			}
			$host = strtolower( $ascii_host );
		}
		$is_ipv6 = false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 );
		if ( ! filter_var( $host, FILTER_VALIDATE_IP ) && ! preg_match( '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/', $host ) ) {
			return '';
		}

		$port = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
		if ( isset( $parts['port'] ) && ( $port < 1 || $port > 65535 ) ) {
			return '';
		}
		if ( ( 'https' === $scheme && 443 === $port ) || ( 'http' === $scheme && 80 === $port ) ) {
			$port = 0;
		}

		$authority = $is_ipv6 ? '[' . $host . ']' : $host;
		if ( 0 !== $port ) {
			$authority .= ':' . $port;
		}

		$path = isset( $parts['path'] ) ? rtrim( (string) $parts['path'], '/' ) : '';
		if ( false !== strpos( $path, chr( 0 ) ) || false !== strpos( $path, '\\' ) ) {
			return '';
		}

		return $scheme . '://' . $authority . $path;
	}

	/**
	 * Whether a reported site URL names the same site as the stored activation URL.
	 *
	 * The scheme and a leading "www." are ignored, so moving a site to HTTPS or between the bare and the www host
	 * does not lock it out; host, port and path must match. Invalid URLs never match.
	 *
	 * @param string $stored Site URL stored with the activation.
	 * @param string $reported Site URL reported by the installation.
	 * @return bool
	 */
	public static function same_site( string $stored, string $reported ): bool {
		$stored = self::identity( $stored );
		$reported = self::identity( $reported );

		return '' !== $stored && '' !== $reported && hash_equals( $stored, $reported );
	}

	/**
	 * Normalized URL without scheme and leading "www." ('' when invalid).
	 *
	 * @param string $url Input URL.
	 * @return string
	 */
	private static function identity( string $url ): string {
		$normalized = self::normalize( $url );
		if ( '' === $normalized ) {
			return '';
		}
		$identity = (string) preg_replace( '#^https?://#', '', $normalized );

		return 0 === strpos( $identity, 'www.' ) ? substr( $identity, 4 ) : $identity;
	}
}
