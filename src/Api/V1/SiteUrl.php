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
		if ( false !== strpos( $path, chr( 0 ) ) || false !== strpos( $path, '\\\\' ) ) {
			return '';
		}

		return $scheme . '://' . $authority . $path;
	}
}
