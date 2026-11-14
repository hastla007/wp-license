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
 * Rules (documented in docs/API-V1.md, "site_url rules"):
 * - http or https only; no credentials, query or fragment; scheme and host lower-cased;
 *   trailing dots dropped; the default port of the scheme dropped.
 * - IPv4 hosts only in canonical dotted-decimal form. A host whose last label is a
 *   number (`2130706433`, `192.168.001.010`, `999.1.1.1`, `10.1`, `0x7f.1`) is rejected:
 *   resolvers read such spellings differently (octal, hex, short forms), so they are
 *   not canonicalized but refused.
 * - IPv6 literals are written in their canonical compressed form (RFC 5952).
 * - Internationalized hosts become Punycode (UTS #46, non-transitional, so `ß` stays
 *   `ß`). With ext-intl this uses idn_to_ascii(); without it a built-in encoder accepts
 *   only labels it can convert to exactly the same result, and rejects the others.
 * - No label may have `--` in its third and fourth position unless it starts with `xn--`.
 * - Paths: duplicate slashes are collapsed, `.` and `..` segments (also as `%2e`) are
 *   resolved, trailing slashes removed. Case and other percent-encoding are kept.
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
		return self::normalize_with( $url, function_exists( 'idn_to_ascii' ) );
	}

	/**
	 * Normalize with or without the intl extension (the switch exists for tests).
	 *
	 * @param string $url      Input URL.
	 * @param bool   $use_intl Whether to use idn_to_ascii(); it must exist when true.
	 * @return string Empty string when invalid.
	 */
	public static function normalize_with( string $url, bool $use_intl ): string {
		$parts = self::parts( $url );
		if ( null === $parts ) {
			return '';
		}
		list( $scheme, $host, $port, $raw_path ) = $parts;

		$is_ipv6 = false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 );
		if ( $is_ipv6 ) {
			$packed = inet_pton( $host );
			$canonical = false === $packed ? false : inet_ntop( $packed );
			if ( false === $canonical ) {
				return '';
			}
			$host = strtolower( $canonical );
		} elseif ( ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$host = self::ascii_host( $host, $use_intl );
			if ( '' === $host || self::ends_in_number( $host ) || ! self::is_valid_hostname( $host ) ) {
				return '';
			}
		}

		$path = self::normalize_path( $raw_path );

		return self::assemble( $scheme, $host, $is_ipv6, $port, $path );
	}

	/**
	 * The normalization used before T-146, kept to find activations stored with it.
	 *
	 * Differences to normalize(): paths kept verbatim apart from trailing slashes,
	 * IPv6 not compressed, non-canonical IPv4 spellings accepted as host names,
	 * no `--` rule, and non-ASCII hosts rejected without ext-intl.
	 *
	 * @internal Only for matching existing rows; never store its result.
	 * @param string $url Input URL.
	 * @return string Empty string when invalid.
	 */
	public static function legacy_normalize( string $url ): string {
		$parts = self::parts( $url );
		if ( null === $parts ) {
			return '';
		}
		list( $scheme, $host, $port, $raw_path ) = $parts;

		if ( ! filter_var( $host, FILTER_VALIDATE_IP ) && function_exists( 'idn_to_ascii' ) ) {
			$ascii_host = idn_to_ascii( $host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46 );
			if ( false === $ascii_host ) {
				return '';
			}
			$host = strtolower( $ascii_host );
		}
		$is_ipv6 = false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 );
		if ( ! filter_var( $host, FILTER_VALIDATE_IP ) && ! preg_match( '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/', $host ) ) {
			return '';
		}

		return self::assemble( $scheme, $host, $is_ipv6, $port, rtrim( $raw_path, '/' ) );
	}

	/**
	 * Spellings an existing activation of the same site may be stored under.
	 *
	 * @param string $url Input URL.
	 * @return string[] The current normal form first, then a different legacy form, if any.
	 */
	public static function identity_candidates( string $url ): array {
		$current = self::normalize( $url );
		if ( '' === $current ) {
			return array();
		}
		$legacy = self::legacy_normalize( $url );

		return '' === $legacy || $legacy === $current ? array( $current ) : array( $current, $legacy );
	}

	/**
	 * Parse and check the parts both normalizations share.
	 *
	 * @param string $url Input URL.
	 * @return array{0:string,1:string,2:int,3:string}|null Scheme, lower-cased host, port (0 = default), raw path.
	 */
	private static function parts( string $url ): ?array {
		$parts = parse_url( trim( $url ) );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return null;
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return null;
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return null;
		}

		$host = strtolower( trim( (string) $parts['host'], '[] .' ) );
		if ( '' === $host ) {
			return null;
		}

		$port = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
		if ( isset( $parts['port'] ) && ( $port < 1 || $port > 65535 ) ) {
			return null;
		}
		if ( ( 'https' === $scheme && 443 === $port ) || ( 'http' === $scheme && 80 === $port ) ) {
			$port = 0;
		}

		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		if ( false !== strpos( $path, chr( 0 ) ) || false !== strpos( $path, '\\' ) ) {
			return null;
		}

		return array( $scheme, $host, $port, $path );
	}

	/**
	 * Join the parts and apply the column length limit.
	 *
	 * @param string $scheme  Scheme.
	 * @param string $host    Host.
	 * @param bool   $is_ipv6 Whether to bracket the host.
	 * @param int    $port    Port, 0 for the default.
	 * @param string $path    Normalized path.
	 * @return string
	 */
	private static function assemble( string $scheme, string $host, bool $is_ipv6, int $port, string $path ): string {
		$authority = $is_ipv6 ? '[' . $host . ']' : $host;
		if ( 0 !== $port ) {
			$authority .= ':' . $port;
		}
		$normalized = $scheme . '://' . $authority . $path;

		// The activation column is varchar(255); reject instead of failing on insert.
		return strlen( $normalized ) > 255 ? '' : $normalized;
	}

	/**
	 * Collapse duplicate slashes, resolve dot segments, drop trailing slashes.
	 *
	 * @param string $path Raw path.
	 * @return string Normalized path ('' for the root).
	 */
	public static function normalize_path( string $path ): string {
		if ( '' === $path ) {
			return '';
		}
		$output = array();
		foreach ( explode( '/', $path ) as $segment ) {
			$dots = strtolower( str_replace( array( '%2e', '%2E' ), '.', $segment ) );
			if ( '' === $segment || '.' === $dots ) {
				continue;
			}
			if ( '..' === $dots ) {
				array_pop( $output );
				continue;
			}
			$output[] = $segment;
		}

		return $output ? '/' . implode( '/', $output ) : '';
	}

	/**
	 * Convert a lower-cased host to ASCII (Punycode) without changing ASCII hosts' meaning.
	 *
	 * @param string $host     Host.
	 * @param bool   $use_intl Whether to use idn_to_ascii().
	 * @return string ASCII host, empty when it cannot be converted.
	 */
	private static function ascii_host( string $host, bool $use_intl ): string {
		if ( $use_intl ) {
			$ascii = idn_to_ascii( $host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46 );
			return false === $ascii ? '' : strtolower( $ascii );
		}
		if ( ! preg_match( '/[^\x00-\x7f]/', $host ) ) {
			return $host;
		}

		return self::fallback_to_ascii( $host );
	}

	/**
	 * Punycode a Unicode host without ext-intl.
	 *
	 * Accepts only what this code can map exactly like UTS #46 (non-transitional)
	 * does: letters from an allow-list of scripts, ASCII digits and hyphens, after
	 * lower-casing. Characters that UTS #46 maps or normalizes in other ways
	 * (combining marks, compatibility forms, ligatures, symbols, other dots) are
	 * rejected, so a host is either rejected here or stored exactly as with intl.
	 *
	 * @param string $host UTF-8 host.
	 * @return string ASCII host, empty when rejected.
	 */
	public static function fallback_to_ascii( string $host ): string {
		if ( ! function_exists( 'mb_strtolower' ) || ! preg_match( '//u', $host ) ) {
			return '';
		}
		// UTS #46 maps capital sigma to σ everywhere; full case mapping would give ς at a word end.
		$host = mb_strtolower( str_replace( 'Σ', 'σ', $host ), 'UTF-8' );

		$labels = array();
		foreach ( explode( '.', $host ) as $label ) {
			if ( '' === $label || ! preg_match( '/[^\x00-\x7f]/', $label ) ) {
				$labels[] = $label;
				continue;
			}
			$code_points = self::code_points( $label );
			// UTS #46 CheckHyphens, which idn_to_ascii() applies to the Unicode label.
			$count = count( $code_points );
			if ( 0x2d === $code_points[0] || 0x2d === $code_points[ $count - 1 ] || ( $count >= 4 && 0x2d === $code_points[2] && 0x2d === $code_points[3] ) ) {
				return '';
			}
			foreach ( $code_points as $code_point ) {
				if ( ! self::fallback_allows( $code_point ) ) {
					return '';
				}
			}
			$labels[] = 'xn--' . self::punycode( $code_points );
		}

		return implode( '.', $labels );
	}

	/**
	 * Whether the fallback encoder maps this lower-cased code point exactly like UTS #46.
	 *
	 * The ranges were checked against idn_to_ascii() code point by code point
	 * (tests/Api/SiteUrlNormalizationTest.php repeats the check when intl exists).
	 *
	 * @param int $cp Code point.
	 * @return bool
	 */
	private static function fallback_allows( int $cp ): bool {
		if ( ( $cp >= 0x61 && $cp <= 0x7a ) || ( $cp >= 0x30 && $cp <= 0x39 ) || 0x2d === $cp ) {
			return true;
		}
		$ranges = array(
			array( 0x00DF, 0x00F6 ), // Latin-1 lower-case letters (ß, ä, ö ...).
			array( 0x00F8, 0x00FF ), // ø ... ÿ.
			array( 0x0100, 0x017E ), // Latin Extended-A.
			array( 0x0180, 0x024F ), // Latin Extended-B.
			array( 0x03AC, 0x03CE ), // Greek lower case.
			array( 0x0430, 0x045F ), // Cyrillic lower case.
			array( 0x0460, 0x0481 ), // Historic Cyrillic.
			array( 0x048A, 0x04FF ), // Cyrillic, extended letters.
			array( 0x0E01, 0x0E30 ), // Thai consonants and vowels without marks.
			array( 0x1E00, 0x1EFF ), // Latin Extended Additional (Vietnamese).
			array( 0x3041, 0x3096 ), // Hiragana.
			array( 0x30A1, 0x30FA ), // Katakana.
			array( 0x30FC, 0x30FC ), // Katakana prolonged sound mark.
			array( 0x4E00, 0x9FFF ), // CJK unified ideographs.
			array( 0xAC00, 0xD7A3 ), // Hangul syllables.
		);
		foreach ( $ranges as $range ) {
			if ( $cp >= $range[0] && $cp <= $range[1] ) {
				return ! in_array( $cp, self::FALLBACK_EXCLUDED, true );
			}
		}

		return false;
	}

	/**
	 * Code points inside the allowed ranges that UTS #46 maps, normalizes or
	 * disallows, or that lower-casing leaves in a form UTS #46 would change.
	 * Filled from the code point comparison with idn_to_ascii().
	 */
	private const FALLBACK_EXCLUDED = array(
		0x0133, // ĳ → ij.
		0x0140, // ŀ → l·.
		0x0149, // ŉ → ʼn.
		0x01C6, // ǆ → dž.
		0x01C9, // ǉ → lj.
		0x01CC, // ǌ → nj.
		0x01F3, // ǳ → dz.
		0x04CF, // ӏ: Ӏ is disallowed, so its lower case is refused as well.
		0x1E9A, // ẚ → aʾ.
		0x1E9B, // ẛ → ṡ.
	);

	/**
	 * Split a UTF-8 string into code points.
	 *
	 * @param string $value Valid UTF-8.
	 * @return int[]
	 */
	private static function code_points( string $value ): array {
		$result = array();
		foreach ( preg_split( '//u', $value, -1, PREG_SPLIT_NO_EMPTY ) ?: array() as $char ) {
			$result[] = (int) mb_ord( $char, 'UTF-8' );
		}

		return $result;
	}

	/**
	 * RFC 3492 Punycode encoding of one label.
	 *
	 * @param int[] $input Code points.
	 * @return string
	 */
	private static function punycode( array $input ): string {
		$base = 36;
		$tmin = 1;
		$tmax = 26;
		$n = 128;
		$bias = 72;
		$delta = 0;
		$output = '';
		foreach ( $input as $cp ) {
			if ( $cp < 0x80 ) {
				$output .= chr( $cp );
			}
		}
		$basic = strlen( $output );
		$handled = $basic;
		if ( $basic > 0 ) {
			$output .= '-';
		}
		$length = count( $input );
		while ( $handled < $length ) {
			$m = PHP_INT_MAX;
			foreach ( $input as $cp ) {
				if ( $cp >= $n && $cp < $m ) {
					$m = $cp;
				}
			}
			$delta += ( $m - $n ) * ( $handled + 1 );
			$n = $m;
			foreach ( $input as $cp ) {
				if ( $cp < $n ) {
					++$delta;
				}
				if ( $cp === $n ) {
					$q = $delta;
					for ( $k = $base; ; $k += $base ) {
						$t = $k <= $bias ? $tmin : ( $k >= $bias + $tmax ? $tmax : $k - $bias );
						if ( $q < $t ) {
							break;
						}
						$output .= self::punycode_digit( $t + ( $q - $t ) % ( $base - $t ) );
						$q = intdiv( $q - $t, $base - $t );
					}
					$output .= self::punycode_digit( $q );
					$bias = self::punycode_adapt( $delta, $handled + 1, $handled === $basic );
					$delta = 0;
					++$handled;
				}
			}
			++$delta;
			++$n;
		}

		return $output;
	}

	/**
	 * @param int $digit 0..35.
	 * @return string
	 */
	private static function punycode_digit( int $digit ): string {
		return chr( $digit < 26 ? $digit + 97 : $digit + 22 );
	}

	/**
	 * @param int  $delta      Delta.
	 * @param int  $num_points Points handled so far.
	 * @param bool $first_time Whether this is the first adaptation.
	 * @return int
	 */
	private static function punycode_adapt( int $delta, int $num_points, bool $first_time ): int {
		$delta = $first_time ? intdiv( $delta, 700 ) : intdiv( $delta, 2 );
		$delta += intdiv( $delta, $num_points );
		$k = 0;
		while ( $delta > 455 ) { // ((36 - 1) * 26) / 2.
			$delta = intdiv( $delta, 35 );
			$k += 36;
		}

		return $k + intdiv( 36 * $delta, $delta + 38 );
	}

	/**
	 * Whether the host's last label is a number, so a resolver may read it as IPv4.
	 *
	 * Follows the WHATWG URL "ends in a number" rule: digits only, or 0x plus hex digits.
	 *
	 * @param string $host Lower-case ASCII host.
	 * @return bool
	 */
	private static function ends_in_number( string $host ): bool {
		$labels = explode( '.', $host );
		$last = (string) end( $labels );

		return 1 === preg_match( '/^(?:[0-9]+|0x[0-9a-f]*)$/', $last );
	}

	/**
	 * Validate a lower-case ASCII host name.
	 *
	 * @param string $host Host.
	 * @return bool
	 */
	private static function is_valid_hostname( string $host ): bool {
		if ( ! preg_match( '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/', $host ) ) {
			return false;
		}
		foreach ( explode( '.', $host ) as $label ) {
			if ( '--' === substr( $label, 2, 2 ) && 0 !== strpos( $label, 'xn--' ) ) {
				return false;
			}
		}

		return true;
	}
}
