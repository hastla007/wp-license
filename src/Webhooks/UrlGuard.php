<?php
/**
 * Protection against requests to internal addresses (SSRF) for webhook target URLs.
 *
 * @package WPLC
 */

namespace WPLC\Webhooks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks a target URL when it is saved and again before every request.
 *
 * Accepted: `https://` URLs on port 443 with a public host name or a public IP address, without
 * user name or password. Refused: other schemes, other ports, host names without a dot and local
 * names (`localhost`, `.local`, `.internal`, `.lan`, `.home.arpa`, `.localdomain`), and every host
 * that resolves to a loopback, private, link-local, shared (CGNAT), documentation, multicast or
 * reserved address, IPv4 and IPv6 (also IPv4 inside IPv6). Every address of the host must be public.
 * A host name that ends with a dot ("example.com.") is refused: cURL would resolve it itself and
 * bypass the pinned address. The request is then pinned to the checked address, so a second DNS
 * answer cannot change it, and the address cURL actually connected to is compared afterwards.
 */
final class UrlGuard {

	public const OK               = '';
	public const INVALID          = 'invalid_url';
	public const NOT_HTTPS        = 'not_https';
	public const CREDENTIALS      = 'credentials_in_url';
	public const PORT             = 'port_not_allowed';
	public const LOCAL_HOST       = 'local_host';
	public const BLOCKED_ADDRESS  = 'blocked_address';
	public const UNRESOLVED       = 'unresolved_host';
	public const TRAILING_DOT     = 'trailing_dot';

	/** Longest accepted URL. */
	public const MAX_LENGTH = 2000;

	/** Name endings that never denote a public host. */
	private const LOCAL_SUFFIXES = array( 'localhost', 'local', 'internal', 'lan', 'home.arpa', 'localdomain', 'intranet', 'corp', 'test', 'invalid', 'example' );

	/** IPv4 ranges that are not public (network, prefix length). */
	private const BLOCKED_V4 = array(
		array( '0.0.0.0', 8 ),
		array( '10.0.0.0', 8 ),
		array( '100.64.0.0', 10 ),
		array( '127.0.0.0', 8 ),
		array( '169.254.0.0', 16 ),
		array( '172.16.0.0', 12 ),
		array( '192.0.0.0', 24 ),
		array( '192.0.2.0', 24 ),
		array( '192.88.99.0', 24 ),
		array( '192.168.0.0', 16 ),
		array( '198.18.0.0', 15 ),
		array( '198.51.100.0', 24 ),
		array( '203.0.113.0', 24 ),
		array( '224.0.0.0', 4 ),
		array( '240.0.0.0', 4 ),
	);

	/** IPv6 ranges that are not public (network, prefix length). IPv4-mapped and NAT64 addresses are checked by their IPv4 part. */
	private const BLOCKED_V6 = array(
		array( '::', 96 ),
		array( '100::', 64 ),
		array( '2001::', 23 ),
		array( '2001:db8::', 32 ),
		array( '2002::', 16 ),
		array( 'fc00::', 7 ),
		array( 'fe80::', 10 ),
		array( 'fec0::', 10 ),
		array( 'ff00::', 8 ),
	);

	/**
	 * Replacement for the DNS lookup (tests): callable( string $host ): string[].
	 *
	 * @var callable|null
	 */
	private static $resolver = null;

	/**
	 * Use another DNS lookup (tests), or the real one again with null.
	 *
	 * @param callable|null $resolver Returns the IP addresses of a host name.
	 * @return void
	 */
	public static function set_resolver( ?callable $resolver ): void {
		self::$resolver = $resolver;
	}

	/**
	 * Check the form of a URL without a DNS lookup.
	 *
	 * @param string $url URL.
	 * @return string One of the constants; OK ('') when the form is acceptable.
	 */
	public static function check_syntax( string $url ): string {
		$url = trim( $url );
		if ( '' === $url || strlen( $url ) > self::MAX_LENGTH || preg_match( '/[\s\x00-\x1F\x7F]/', $url ) ) {
			return self::INVALID;
		}
		$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure helper, no WordPress here.
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return self::INVALID;
		}
		if ( 'https' !== strtolower( (string) $parts['scheme'] ) ) {
			return self::NOT_HTTPS;
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return self::CREDENTIALS;
		}
		if ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] ) {
			return self::PORT;
		}
		if ( '.' === substr( (string) $parts['host'], -1 ) ) {
			// "host." is the same name for DNS, but not for the pin (CURLOPT_RESOLVE matches the exact name).
			return self::TRAILING_DOT;
		}
		$host = self::host( $url );
		if ( '' === $host ) {
			return self::INVALID;
		}
		if ( self::is_ip( $host ) ) {
			return self::is_public_ip( $host ) ? self::OK : self::BLOCKED_ADDRESS;
		}
		if ( ! preg_match( '/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $host ) ) {
			return false === strpos( $host, '.' ) ? self::LOCAL_HOST : self::INVALID;
		}
		foreach ( self::LOCAL_SUFFIXES as $suffix ) {
			if ( $host === $suffix || substr( $host, -strlen( $suffix ) - 1 ) === '.' . $suffix ) {
				return self::LOCAL_HOST;
			}
		}

		return self::OK;
	}

	/**
	 * Check the form and every address the host resolves to.
	 *
	 * @param string $url URL.
	 * @return array{error:string,ip:string} error OK ('') and the first checked address on success.
	 */
	public static function check( string $url ): array {
		$error = self::check_syntax( $url );
		if ( self::OK !== $error ) {
			return array(
				'error' => $error,
				'ip'    => '',
			);
		}
		$host = self::host( $url );
		$ips  = self::is_ip( $host ) ? array( $host ) : self::resolve( $host );
		if ( ! $ips ) {
			return array(
				'error' => self::UNRESOLVED,
				'ip'    => '',
			);
		}
		foreach ( $ips as $ip ) {
			if ( ! self::is_public_ip( $ip ) ) {
				return array(
					'error' => self::BLOCKED_ADDRESS,
					'ip'    => '',
				);
			}
		}

		return array(
			'error' => self::OK,
			'ip'    => (string) $ips[0],
		);
	}

	/**
	 * Lower-case host of a URL without the brackets of an IPv6 literal, '' when there is none.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function host( string $url ): string {
		$host = parse_url( trim( $url ), PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure helper.
		if ( ! is_string( $host ) ) {
			return '';
		}

		return rtrim( strtolower( trim( $host, '[]' ) ), '.' );
	}

	/**
	 * Whether two strings are the same IP address (any notation), false when one is no address.
	 *
	 * @param string $first  Address.
	 * @param string $second Address.
	 * @return bool
	 */
	public static function same_ip( string $first, string $second ): bool {
		$first  = trim( $first, '[] ' );
		$second = trim( $second, '[] ' );
		if ( ! self::is_ip( $first ) || ! self::is_ip( $second ) ) {
			return false;
		}

		return inet_pton( $first ) === inet_pton( $second );
	}

	/**
	 * Whether a string is an IPv4 or IPv6 address.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	public static function is_ip( string $value ): bool {
		return false !== filter_var( $value, FILTER_VALIDATE_IP );
	}

	/**
	 * Whether an address is public (not loopback, private, link-local, shared, documentation, multicast or reserved).
	 *
	 * @param string $ip IPv4 or IPv6 address.
	 * @return bool
	 */
	public static function is_public_ip( string $ip ): bool {
		$packed = self::is_ip( $ip ) ? inet_pton( $ip ) : false;
		if ( false === $packed ) {
			return false;
		}
		if ( 4 === strlen( $packed ) ) {
			foreach ( self::BLOCKED_V4 as $range ) {
				if ( self::in_range( $packed, (string) inet_pton( $range[0] ), $range[1] ) ) {
					return false;
				}
			}
			return '255.255.255.255' !== $ip;
		}
		// IPv4-mapped (::ffff:0:0/96) and NAT64 (64:ff9b::/96): judge the IPv4 address inside.
		foreach ( array( '::ffff:0:0', '64:ff9b::' ) as $wrapper ) {
			if ( self::in_range( $packed, (string) inet_pton( $wrapper ), 96 ) ) {
				return self::is_public_ip( (string) inet_ntop( substr( $packed, 12 ) ) );
			}
		}
		foreach ( self::BLOCKED_V6 as $range ) {
			if ( self::in_range( $packed, (string) inet_pton( $range[0] ), $range[1] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * IP addresses of a host name (A and AAAA records).
	 *
	 * @param string $host Host name.
	 * @return array<int,string>
	 */
	public static function resolve( string $host ): array {
		if ( null !== self::$resolver ) {
			$ips = call_user_func( self::$resolver, $host );
		} else {
			$ips = array();
			if ( function_exists( 'dns_get_record' ) ) {
				$records = @dns_get_record( $host, DNS_A | DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failed lookup is reported as "unresolved".
				foreach ( is_array( $records ) ? $records : array() as $record ) {
					if ( isset( $record['ip'] ) ) {
						$ips[] = (string) $record['ip'];
					} elseif ( isset( $record['ipv6'] ) ) {
						$ips[] = (string) $record['ipv6'];
					}
				}
			}
			if ( ! $ips ) {
				$fallback = gethostbynamel( $host );
				$ips      = is_array( $fallback ) ? $fallback : array();
			}
		}
		$ips = is_array( $ips ) ? array_values( array_filter( array_map( 'strval', $ips ), array( self::class, 'is_ip' ) ) ) : array();

		return array_values( array_unique( $ips ) );
	}

	/**
	 * Whether a packed address lies in a packed network of the same family.
	 *
	 * @param string $address Packed address.
	 * @param string $network Packed network.
	 * @param int    $prefix  Prefix length in bits.
	 * @return bool
	 */
	private static function in_range( string $address, string $network, int $prefix ): bool {
		if ( strlen( $address ) !== strlen( $network ) ) {
			return false;
		}
		$bytes = intdiv( $prefix, 8 );
		if ( substr( $address, 0, $bytes ) !== substr( $network, 0, $bytes ) ) {
			return false;
		}
		$bits = $prefix % 8;
		if ( 0 === $bits ) {
			return true;
		}
		$mask = ( 0xFF << ( 8 - $bits ) ) & 0xFF;

		return ( ord( $address[ $bytes ] ) & $mask ) === ( ord( $network[ $bytes ] ) & $mask );
	}
}
