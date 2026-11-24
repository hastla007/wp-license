<?php
/**
 * License key helpers.
 *
 * @package WPLC
 */

namespace WPLC\License;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Derives displayable license keys with explicit key IDs and no plaintext storage.
 */
final class LicenseKey {
	/**
	 * Default first part of every key (`WPLC-…`). Four characters; a shop may choose its own
	 * before the first license is issued (setting "License key prefix", E-04). The length of
	 * the prefix, and so of the key (34 characters, legacy keys 106) and of the reference (13),
	 * never changes.
	 */
	public const DEFAULT_PREFIX = 'WPLC';

	/** Shape of a prefix: a letter, then three letters or digits. */
	public const PREFIX_PATTERN = '[A-Z][A-Z0-9]{3}';

	/**
	 * Key format of every key issued since T-106 (24.11.2026):
	 * `<PREFIX>-XXXXX-XXXXX-XXXXX-XXXXX-XXXXX`, 25 Crockford Base32 characters (125 bits of an
	 * HMAC-SHA-256 of the license UUID with the license-key secret), 34 characters in all.
	 */
	public const FORMAT_SHORT = 'short';

	/**
	 * Key format before T-106: `<PREFIX>-<UUID>-<64 hex HMAC>`, 106 characters. Keys of this
	 * format stay valid forever; nothing stored is rewritten.
	 */
	public const FORMAT_LONG = 'long';

	/** Character class of the Crockford Base32 alphabet (no I, L, O, U), for regular expressions. */
	public const BASE32_CLASS = '[0-9A-HJKMNP-TV-Z]';

	/** Crockford Base32 alphabet. */
	private const BASE32_ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

	/** Number of Base32 characters after the prefix of a short key (5 groups of 5). */
	private const SHORT_BODY_LENGTH = 25;

	/** Separates the short-key HMAC from the legacy one (HMAC of the bare UUID). */
	private const SHORT_CONTEXT = 'wplc-license-key-v2|';

	/**
	 * Build a displayable key for a license UUID and secret key ID.
	 *
	 * For a new license. For a stored license use verified_for_license(), which finds the
	 * format of its key from the stored verifier.
	 *
	 * @param string      $uuid   License UUID.
	 * @param string|null $key_id Key ID; defaults to the configured current key.
	 * @param string      $format FORMAT_SHORT (default, every new key) or FORMAT_LONG (keys issued before T-106).
	 * @return string Empty string when the requested key is unavailable.
	 */
	public static function for_uuid( string $uuid, ?string $key_id = null, string $format = self::FORMAT_SHORT ): string {
		$keys = self::configured_keys();
		$key_id = null === $key_id ? self::resolve_current_key_id( $keys ) : $key_id;
		if ( ! isset( $keys[ $key_id ] ) || '' === $keys[ $key_id ] ) {
			return '';
		}
		$uuid = strtolower( $uuid );
		if ( self::FORMAT_LONG === $format ) {
			$digest = hash_hmac( 'sha256', $uuid, $keys[ $key_id ] );

			return self::key_prefix() . '-' . strtoupper( $uuid ) . '-' . strtoupper( $digest );
		}

		$body = self::base32( hash_hmac( 'sha256', self::SHORT_CONTEXT . $uuid, $keys[ $key_id ], true ), self::SHORT_BODY_LENGTH );

		return self::key_prefix() . '-' . implode( '-', str_split( $body, 5 ) );
	}

	/**
	 * Crockford Base32 of the leading bits of a binary string (5 bits per character).
	 *
	 * @param string $bytes  Binary input of at least ceil( $length * 5 / 8 ) bytes.
	 * @param int    $length Number of characters.
	 * @return string
	 */
	private static function base32( string $bytes, int $length ): string {
		$bits = '';
		$need = (int) ceil( $length * 5 / 8 );
		for ( $i = 0; $i < $need; $i++ ) {
			$bits .= str_pad( decbin( ord( $bytes[ $i ] ) ), 8, '0', STR_PAD_LEFT );
		}
		$out = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$out .= self::BASE32_ALPHABET[ (int) bindec( substr( $bits, $i * 5, 5 ) ) ];
		}

		return $out;
	}

	/**
	 * Return the configured key ID used for newly issued licenses.
	 *
	 * @return string
	 */
	public static function current_key_id(): string {
		return self::resolve_current_key_id( self::configured_keys() );
	}

	/**
	 * Fingerprint of the secret new keys are derived from, for the key age (SET-LIC-28).
	 *
	 * Key ID and the first 16 hex characters of an HMAC of a fixed text: it tells two secrets apart
	 * and reveals nothing usable about them. '' while the WordPress salt stands in for the secret.
	 *
	 * @return string
	 */
	public static function fingerprint(): string {
		if ( self::uses_fallback_secret() ) {
			return '';
		}
		$keys   = self::configured_keys();
		$key_id = self::resolve_current_key_id( $keys );
		if ( ! isset( $keys[ $key_id ] ) || '' === $keys[ $key_id ] ) {
			return '';
		}

		return $key_id . ':' . substr( hash_hmac( 'sha256', 'wplc-key-age', $keys[ $key_id ] ), 0, 16 );
	}

	/**
	 * Verify that a UUID and key ID reproduce a stored license-key verifier.
	 *
	 * @param string $uuid License UUID.
	 * @param string $key_id Persisted secret key ID.
	 * @param string $stored_hash Stored SHA-256 verifier.
	 * @return bool
	 */
	public static function matches_hash( string $uuid, string $key_id, string $stored_hash ): bool {
		return '' !== self::stored_format( $uuid, $key_id, $stored_hash );
	}

	/**
	 * Format of a stored license's key: the one whose derived key reproduces the verifier.
	 *
	 * @param string $uuid        License UUID.
	 * @param string $key_id      Persisted secret key ID.
	 * @param string $stored_hash Stored SHA-256 verifier.
	 * @return string FORMAT_SHORT, FORMAT_LONG, or '' when neither reproduces it.
	 */
	public static function stored_format( string $uuid, string $key_id, string $stored_hash ): string {
		if ( ! preg_match( '/^[a-f0-9]{64}$/i', $stored_hash ) ) {
			return '';
		}
		foreach ( array( self::FORMAT_SHORT, self::FORMAT_LONG ) as $format ) {
			$key = self::for_uuid( $uuid, $key_id, $format );
			if ( '' !== $key && hash_equals( strtolower( $stored_hash ), self::hash( $key ) ) ) {
				return $format;
			}
		}

		return '';
	}

	/**
	 * Displayable key of a stored license, only when it reproduces the stored verifier.
	 *
	 * A wrong license_key_secret_id (an operator mistake, a manual SQL change) or a renamed key-ring entry would
	 * otherwise derive a different, invalid key and show it to the customer as theirs.
	 *
	 * Licenses issued before T-106 carry a 106-character key, later ones a 34-character key; the
	 * stored verifier tells which (stored_format()), so no column records the format.
	 *
	 * @param string $uuid License UUID.
	 * @param string $key_id Persisted secret key ID.
	 * @param string $stored_hash Stored SHA-256 verifier.
	 * @return string Empty string when the key cannot be reproduced (see `wp wplc recover-license-key-ids`).
	 */
	public static function verified_for_license( string $uuid, string $key_id, string $stored_hash ): string {
		$format = self::stored_format( $uuid, $key_id, $stored_hash );

		return '' === $format ? '' : self::for_uuid( $uuid, $key_id, $format );
	}

	/**
	 * Whether a key ID is configured in the license-key ring.
	 *
	 * @param string $key_id Key ID.
	 * @return bool
	 */
	public static function is_configured( string $key_id ): bool {
		$keys = self::configured_keys();
		return isset( $keys[ $key_id ] ) && '' !== $keys[ $key_id ];
	}

	/**
	 * Normalize a license key as typed or pasted by a customer (B-173).
	 *
	 * Typographic dashes become "-", letters become upper case and everything
	 * outside `[A-Z0-9-]` is removed: line breaks, (non-breaking and
	 * zero-width) spaces, quotes or a trailing dot from an email.
	 *
	 * A key of the short format (T-106) is then brought into its canonical form: hyphens may be
	 * missing or misplaced, and in the 25 characters after the prefix O counts as 0 and I and L
	 * as 1 (Crockford Base32). Legacy 106-character keys keep exactly the rule above.
	 *
	 * @param string $key License key as entered.
	 * @return string
	 */
	public static function normalize( string $key ): string {
		$key = strtr(
			$key,
			array(
				"\u{2010}" => '-',
				"\u{2011}" => '-',
				"\u{2012}" => '-',
				"\u{2013}" => '-',
				"\u{2014}" => '-',
				"\u{2015}" => '-',
				"\u{2212}" => '-',
				"\u{FE58}" => '-',
				"\u{FE63}" => '-',
				"\u{FF0D}" => '-',
			)
		);

		$key = (string) preg_replace( '/[^A-Z0-9-]/', '', strtoupper( $key ) );

		return self::canonical_short( $key ) ?? $key;
	}

	/**
	 * Canonical short key for a cleaned input of exactly a prefix plus 25 key characters.
	 *
	 * @param string $key Upper-case input with only `[A-Z0-9-]`.
	 * @return string|null Null when the input is not a short key.
	 */
	private static function canonical_short( string $key ): ?string {
		$compact = str_replace( '-', '', $key );
		if ( 4 + self::SHORT_BODY_LENGTH !== strlen( $compact ) ) {
			return null;
		}
		$prefix = substr( $compact, 0, 4 );
		$body   = strtr( substr( $compact, 4 ), 'OIL', '011' );
		if ( 1 !== preg_match( '/^' . self::PREFIX_PATTERN . '$/D', $prefix ) || 1 !== preg_match( '/^' . self::BASE32_CLASS . '{25}$/D', $body ) ) {
			return null;
		}

		return $prefix . '-' . implode( '-', str_split( $body, 5 ) );
	}

	/**
	 * Whether a normalized string has the shape of a complete key, in either format:
	 * `<prefix>-XXXXX-XXXXX-XXXXX-XXXXX-XXXXX` or the legacy `<prefix>-<uuid>-<64 hex>`.
	 * The prefix may be the shop's or the default one (prefix_alternatives()).
	 *
	 * @param string $key Output of normalize().
	 * @return bool
	 */
	public static function is_well_formed( string $key ): bool {
		$prefix = self::prefix_alternatives();
		$group  = self::BASE32_CLASS . '{5}';

		return 1 === preg_match( '/^' . $prefix . '-(?:' . $group . '-){4}' . $group . '$/D', $key )
			|| 1 === preg_match( '/^' . $prefix . '-[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12}-[0-9A-F]{64}$/D', $key );
	}

	/**
	 * Hash a license key for database verification.
	 *
	 * @param string $key License key (normalized here).
	 * @return string
	 */
	public static function hash( string $key ): string {
		return hash( 'sha256', self::normalize( $key ) );
	}

	/**
	 * Check how many stored license keys can still be derived with the configured secrets (B-64).
	 *
	 * After a salt change or a wrong secret in wp-config.php the displayed
	 * keys no longer match the stored verifiers. Diagnostics can show
	 * "verified of checked".
	 *
	 * @param int $limit Number of most recent licenses to check.
	 * @return array{checked:int,verified:int}
	 */
	public static function verification_sample( int $limit = 50 ): array {
		global $wpdb;

		$tables = \WPLC\Infrastructure\Database\Schema::table_names();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names().
				"SELECT license_uuid,license_key_hash,license_key_secret_id FROM {$tables['licenses']} ORDER BY id DESC LIMIT %d",
				max( 1, min( 500, $limit ) )
			),
			ARRAY_A
		);
		$verified = 0;
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( self::matches_hash( (string) $row['license_uuid'], (string) $row['license_key_secret_id'], (string) $row['license_key_hash'] ) ) {
				++$verified;
			}
		}

		return array(
			'checked' => is_array( $rows ) ? count( $rows ) : 0,
			'verified' => $verified,
		);
	}

	/**
	 * Return a non-secret prefix for the admin/customer interface.
	 *
	 * @param string $uuid License UUID.
	 * @return string
	 */
	public static function prefix( string $uuid ): string {
		return self::key_prefix() . '-' . strtoupper( substr( str_replace( '-', '', $uuid ), 0, 8 ) );
	}

	/**
	 * The first part of every key of this shop, without the hyphen: the setting "License key
	 * prefix" (default WPLC, E-04). There is no filter: a value that changed after the first
	 * license would make every issued key invalid, so the setting is fixed from then on
	 * (prefix_locked()).
	 *
	 * @return string
	 */
	public static function key_prefix(): string {
		$prefix = class_exists( '\\WPLC\\Settings\\Registry' ) ? \WPLC\Settings\Registry::string( 'license_key_prefix' ) : '';

		return 1 === preg_match( '/^' . self::PREFIX_PATTERN . '$/D', $prefix ) ? $prefix : self::DEFAULT_PREFIX;
	}

	/**
	 * Regular expression part that matches the prefix of this shop and the default one,
	 * for redaction and searches (keys issued before a shop chose its own prefix cannot exist,
	 * but text written by tests or support may still carry WPLC-).
	 *
	 * @return string Without delimiters, e.g. `(?:ACME|WPLC)`.
	 */
	public static function prefix_alternatives(): string {
		$own = self::key_prefix();

		return self::DEFAULT_PREFIX === $own ? self::DEFAULT_PREFIX : '(?:' . $own . '|' . self::DEFAULT_PREFIX . ')';
	}

	/**
	 * Whether the key prefix may no longer change: as soon as one license exists (E-04).
	 *
	 * Every key, and every stored verifier, contains the prefix. Before schema install (no
	 * table) nothing is locked.
	 *
	 * @return bool
	 */
	public static function prefix_locked(): bool {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return false;
		}
		$tables     = \WPLC\Infrastructure\Database\Schema::table_names();
		$suppressed = $wpdb->suppress_errors( true );
		$found      = $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i LIMIT 1', $tables['licenses'] ) );
		$wpdb->suppress_errors( $suppressed );

		return null !== $found;
	}

	/**
	 * Whether license keys are derived from the WordPress auth salt because no
	 * dedicated secret is configured in wp-config.php.
	 *
	 * Must mirror the precedence in configured_keys().
	 *
	 * @return bool
	 */
	public static function uses_fallback_secret(): bool {
		if ( defined( 'WPLC_LICENSE_KEY_SECRETS' ) && is_array( WPLC_LICENSE_KEY_SECRETS ) ) {
			return false;
		}

		return ! ( defined( 'WPLC_LICENSE_KEY_SECRET' ) && '' !== (string) WPLC_LICENSE_KEY_SECRET );
	}

	/**
	 * Resolve configured license-key secrets, retaining previous key IDs during rotation.
	 *
	 * @return array<string,string>
	 */
	private static function configured_keys(): array {
		if ( defined( 'WPLC_LICENSE_KEY_SECRETS' ) && is_array( WPLC_LICENSE_KEY_SECRETS ) ) {
			$keys = array();
			foreach ( WPLC_LICENSE_KEY_SECRETS as $key_id => $secret ) {
				$key_id = (string) $key_id;
				if ( preg_match( '/^[a-zA-Z0-9._-]{1,32}$/', $key_id ) && is_string( $secret ) && '' !== $secret ) {
					$keys[ $key_id ] = $secret;
				}
			}
			return $keys;
		}

		if ( defined( 'WPLC_LICENSE_KEY_SECRET' ) && '' !== (string) WPLC_LICENSE_KEY_SECRET ) {
			return array( 'legacy' => (string) WPLC_LICENSE_KEY_SECRET );
		}

		return array( 'legacy' => (string) wp_salt( 'auth' ) );
	}

	/**
	 * Resolve the active license key ID.
	 *
	 * @param array<string,string> $keys Available secrets.
	 * @return string
	 */
	private static function resolve_current_key_id( array $keys ): string {
		if ( defined( 'WPLC_LICENSE_KEY_ID' ) ) {
			return (string) WPLC_LICENSE_KEY_ID;
		}
		reset( $keys );
		return (string) key( $keys );
	}
}
