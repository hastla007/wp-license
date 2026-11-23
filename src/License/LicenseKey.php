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
	 * the prefix, and so of the key (106 characters) and of the reference (13), never changes.
	 */
	public const DEFAULT_PREFIX = 'WPLC';

	/** Shape of a prefix: a letter, then three letters or digits. */
	public const PREFIX_PATTERN = '[A-Z][A-Z0-9]{3}';

	/**
	 * Build a displayable key for a license UUID and secret key ID.
	 *
	 * @param string $uuid License UUID.
	 * @param string|null $key_id Key ID; defaults to the configured current key.
	 * @return string Empty string when the requested key is unavailable.
	 */
	public static function for_uuid( string $uuid, ?string $key_id = null ): string {
		$keys = self::configured_keys();
		$key_id = null === $key_id ? self::resolve_current_key_id( $keys ) : $key_id;
		if ( ! isset( $keys[ $key_id ] ) || '' === $keys[ $key_id ] ) {
			return '';
		}
		$uuid = strtolower( $uuid );
		$digest = hash_hmac( 'sha256', $uuid, $keys[ $key_id ] );

		return self::key_prefix() . '-' . strtoupper( $uuid ) . '-' . strtoupper( $digest );
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
		$key = self::for_uuid( $uuid, $key_id );
		if ( '' === $key || ! preg_match( '/^[a-f0-9]{64}$/i', $stored_hash ) ) {
			return false;
		}

		return hash_equals( strtolower( $stored_hash ), self::hash( $key ) );
	}

	/**
	 * Displayable key of a stored license, only when it reproduces the stored verifier.
	 *
	 * A wrong license_key_secret_id (an operator mistake, a manual SQL change) or a renamed key-ring entry would
	 * otherwise derive a different, invalid key and show it to the customer as theirs.
	 *
	 * @param string $uuid License UUID.
	 * @param string $key_id Persisted secret key ID.
	 * @param string $stored_hash Stored SHA-256 verifier.
	 * @return string Empty string when the key cannot be reproduced (see `wp wplc recover-license-key-ids`).
	 */
	public static function verified_for_license( string $uuid, string $key_id, string $stored_hash ): string {
		return self::matches_hash( $uuid, $key_id, $stored_hash ) ? self::for_uuid( $uuid, $key_id ) : '';
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
	 * zero-width) spaces, quotes or a trailing dot from an e-mail.
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

		return (string) preg_replace( '/[^A-Z0-9-]/', '', strtoupper( $key ) );
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
