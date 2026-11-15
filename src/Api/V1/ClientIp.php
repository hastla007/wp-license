<?php
/**
 * Client address resolution for rate limiting behind reverse proxies.
 *
 * @package WPLC
 */

namespace WPLC\Api\V1;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the address of the client that reached the first trusted proxy.
 *
 * Without configuration only REMOTE_ADDR is used. A forwarding header is
 * honored only when WPLC_TRUSTED_PROXY_HEADER names it and the direct peer is
 * listed in WPLC_TRUSTED_PROXIES. A header without a proxy list is ignored:
 * otherwise every client could choose its own rate-limit bucket (P-42).
 */
final class ClientIp {

	/**
	 * Resolve the client address of the current request from configuration.
	 *
	 * @return string Valid IP address, or an empty string when none can be resolved.
	 */
	public static function current(): string {
		$header = defined( 'WPLC_TRUSTED_PROXY_HEADER' ) ? (string) constant( 'WPLC_TRUSTED_PROXY_HEADER' ) : '';
		$proxies = null;
		if ( defined( 'WPLC_TRUSTED_PROXIES' ) ) {
			$configured = constant( 'WPLC_TRUSTED_PROXIES' );
			// A defined but malformed list trusts nobody rather than everybody.
			$proxies = is_array( $configured ) ? $configured : array();
		}

		$ip = self::resolve( $_SERVER, $header, $proxies );

		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filter the client address used for anonymous rate limiting.
			 *
			 * @param string $ip Resolved address, or an empty string.
			 */
			$filtered = apply_filters( 'wplc_client_ip', $ip );
			$ip = is_string( $filtered ) ? self::normalize( $filtered ) : '';
		}

		return $ip;
	}

	/**
	 * Rate-limit bucket of the current request's client.
	 *
	 * A request without a resolvable address lands in a shared bucket instead
	 * of failing for everyone.
	 *
	 * @return string Bucket identifier, `unknown` when no address is known.
	 */
	public static function current_bucket(): string {
		$bucket = self::rate_limit_bucket( self::current() );

		return '' === $bucket ? 'unknown' : $bucket;
	}

	/**
	 * Configuration problem of the proxy settings, for the diagnostics page (P-42).
	 *
	 * @param string|null $header  Header name; null reads WPLC_TRUSTED_PROXY_HEADER.
	 * @param mixed       $proxies Proxy list; null reads WPLC_TRUSTED_PROXIES (undefined counts as missing).
	 * @return string Warning text, or an empty string when the configuration is consistent.
	 */
	public static function configuration_warning( ?string $header = null, $proxies = null ): string {
		if ( null === $header ) {
			$header = defined( 'WPLC_TRUSTED_PROXY_HEADER' ) ? (string) constant( 'WPLC_TRUSTED_PROXY_HEADER' ) : '';
			$proxies = defined( 'WPLC_TRUSTED_PROXIES' ) ? constant( 'WPLC_TRUSTED_PROXIES' ) : null;
		}
		if ( '' === trim( $header ) || ( is_array( $proxies ) && array() !== $proxies ) ) {
			return '';
		}

		return sprintf(
			/* translators: 1: constant name WPLC_TRUSTED_PROXY_HEADER, 2: constant name WPLC_TRUSTED_PROXIES. */
			__( '%1$s is set, but %2$s does not list your proxies. The header is ignored and every request is rate-limited by the proxy address, so all customers behind the proxy share one limit. Add the addresses of your proxies to %2$s.', 'wp-license' ),
			'WPLC_TRUSTED_PROXY_HEADER',
			'WPLC_TRUSTED_PROXIES'
		);
	}

	/**
	 * Resolve the client address from a server array.
	 *
	 * @param array<string,mixed> $server Server variables.
	 * @param string $trusted_header Header name (`HTTP_X_FORWARDED_FOR` or `X-Forwarded-For`); empty disables proxies.
	 * @param array<int,mixed>|null $trusted_proxies IPs/CIDRs allowed to set the header; null (list not configured) ignores the header.
	 * @return string Valid IP address, or an empty string.
	 */
	public static function resolve( array $server, string $trusted_header = '', ?array $trusted_proxies = null ): string {
		$remote = self::normalize( self::server_value( $server, 'REMOTE_ADDR' ) );
		$key = self::header_key( $trusted_header );
		if ( '' === $key || null === $trusted_proxies ) {
			return $remote;
		}
		if ( '' === $remote || ! self::matches_any( $remote, $trusted_proxies ) ) {
			return $remote;
		}

		$value = self::server_value( $server, $key );
		if ( '' === $value ) {
			return $remote;
		}

		// Each proxy appends the peer it saw, so walk from the right and skip
		// our own proxies; everything further left is client-controlled.
		$candidates = array_reverse( explode( ',', $value ) );
		foreach ( $candidates as $candidate ) {
			$ip = self::normalize( $candidate );
			if ( '' === $ip ) {
				return $remote;
			}
			if ( self::matches_any( $ip, $trusted_proxies ) ) {
				continue;
			}
			return $ip;
		}

		return $remote;
	}

	/**
	 * Reduce an address to the unit one subscriber controls.
	 *
	 * IPv6 customers usually receive at least a /64, so limiting single
	 * addresses would be trivially bypassed.
	 *
	 * @param string $ip Valid IP address.
	 * @return string Bucket identifier, or an empty string for invalid input.
	 */
	public static function rate_limit_bucket( string $ip ): string {
		$ip = self::normalize( $ip );
		if ( '' === $ip ) {
			return '';
		}
		$packed = inet_pton( $ip );
		if ( false === $packed || 16 !== strlen( $packed ) ) {
			return $ip;
		}

		return inet_ntop( substr( $packed, 0, 8 ) . str_repeat( "\0", 8 ) ) . '/64';
	}

	/**
	 * Whether an address equals an IP or lies inside a CIDR range.
	 *
	 * @param string $ip Address to test.
	 * @param string $range Single IP or CIDR (IPv4 or IPv6).
	 * @return bool
	 */
	public static function in_range( string $ip, string $range ): bool {
		$ip = self::normalize( $ip );
		$range = trim( $range );
		if ( '' === $ip || '' === $range ) {
			return false;
		}

		$bits = null;
		if ( false !== strpos( $range, '/' ) ) {
			list( $range, $suffix ) = explode( '/', $range, 2 );
			if ( ! ctype_digit( $suffix ) ) {
				return false;
			}
			$bits = (int) $suffix;
		}

		$subnet = self::normalize( $range );
		if ( '' === $subnet ) {
			return false;
		}
		// normalize() turns ::ffff:a.b.c.d into a.b.c.d; the prefix length of
		// an IPv4-mapped range counts from the start of the IPv6 form (B-180).
		if ( null !== $bits && false !== strpos( $range, ':' ) && false === strpos( $subnet, ':' ) ) {
			$bits = max( 0, $bits - 96 );
		}
		$ip_packed = inet_pton( $ip );
		$subnet_packed = inet_pton( $subnet );
		if ( false === $ip_packed || false === $subnet_packed || strlen( $ip_packed ) !== strlen( $subnet_packed ) ) {
			return false;
		}

		$max_bits = strlen( $ip_packed ) * 8;
		if ( null === $bits ) {
			$bits = $max_bits;
		}
		if ( $bits > $max_bits ) {
			return false;
		}

		$full_bytes = intdiv( $bits, 8 );
		if ( $full_bytes > 0 && substr( $ip_packed, 0, $full_bytes ) !== substr( $subnet_packed, 0, $full_bytes ) ) {
			return false;
		}
		$remaining = $bits % 8;
		if ( 0 === $remaining ) {
			return true;
		}
		$mask = ( 0xFF << ( 8 - $remaining ) ) & 0xFF;

		return ( ord( $ip_packed[ $full_bytes ] ) & $mask ) === ( ord( $subnet_packed[ $full_bytes ] ) & $mask );
	}

	/**
	 * Validate and canonicalize one address token.
	 *
	 * Accepts bracketed IPv6, an optional port, and IPv4-mapped IPv6.
	 *
	 * @param string $value Raw value.
	 * @return string Canonical address, or an empty string.
	 */
	public static function normalize( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		if ( preg_match( '/^\[([0-9a-fA-F:.]+)\](?::\d{1,5})?$/', $value, $matches ) ) {
			$value = $matches[1];
		} elseif ( preg_match( '/^(\d{1,3}(?:\.\d{1,3}){3}):\d{1,5}$/', $value, $matches ) ) {
			$value = $matches[1];
		}
		if ( false === filter_var( $value, FILTER_VALIDATE_IP ) ) {
			return '';
		}
		$packed = inet_pton( $value );
		if ( false === $packed ) {
			return '';
		}
		// ::ffff:a.b.c.d is the same host as a.b.c.d.
		if ( 16 === strlen( $packed ) && str_repeat( "\0", 10 ) . "\xff\xff" === substr( $packed, 0, 12 ) ) {
			$packed = substr( $packed, 12 );
		}
		$canonical = inet_ntop( $packed );

		return false === $canonical ? '' : $canonical;
	}

	/**
	 * Whether an address matches any entry of a proxy list.
	 *
	 * @param string $ip Address.
	 * @param array<int,mixed> $ranges IPs/CIDRs.
	 * @return bool
	 */
	private static function matches_any( string $ip, array $ranges ): bool {
		foreach ( $ranges as $range ) {
			if ( is_string( $range ) && self::in_range( $ip, $range ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Convert a header name to its $_SERVER key.
	 *
	 * @param string $header Header or server key.
	 * @return string Empty string when the name is not a plausible header.
	 */
	private static function header_key( string $header ): string {
		$key = strtoupper( str_replace( '-', '_', trim( $header ) ) );
		if ( '' === $key || ! preg_match( '/^[A-Z0-9_]{1,64}$/', $key ) ) {
			return '';
		}
		if ( 0 !== strpos( $key, 'HTTP_' ) ) {
			$key = 'HTTP_' . $key;
		}

		return $key;
	}

	/**
	 * Read a scalar server value.
	 *
	 * @param array<string,mixed> $server Server variables.
	 * @param string $key Key.
	 * @return string
	 */
	private static function server_value( array $server, string $key ): string {
		return isset( $server[ $key ] ) && is_string( $server[ $key ] ) ? trim( $server[ $key ] ) : '';
	}
}
