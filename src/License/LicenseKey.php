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
	 * Build a displayable key for a license UUID and secret key ID.
	 *
	 * @param string $uuid License UUID.
	 * @param string|null $key_id Key ID; defaults to the configured current key.
	 * @return string Empty string when the requested key is unavailable.
	 */
	public static function for_uuid( string $uuid, ?string $key_id = null ): string {
		$keys = self::configured_keys();
		$key_id = null === $key_id ? self::current_key_id( $keys ) : $key_id;
		if ( ! isset( $keys[ $key_id ] ) || '' === $keys[ $key_id ] ) {
			return '';
		}
		$uuid = strtolower( $uuid );
		$digest = hash_hmac( 'sha256', $uuid, $keys[ $key_id ] );

		return 'WPLC-' . strtoupper( $uuid ) . '-' . strtoupper( $digest );
	}

	/**
	 * Return the configured key ID used for newly issued licenses.
	 *
	 * @return string
	 */
	public static function current_key_id(): string {
		return self::current_key_id( self::configured_keys() );
	}

	/**
	 * Hash a normalized license key for database verification.
	 *
	 * @param string $key License key.
	 * @return string
	 */
	public static function hash( string $key ): string {
		return hash( 'sha256', strtoupper( trim( $key ) ) );
	}

	/**
	 * Return a non-secret prefix for the admin/customer interface.
	 *
	 * @param string $uuid License UUID.
	 * @return string
	 */
	public static function prefix( string $uuid ): string {
		return 'WPLC-' . strtoupper( substr( str_replace( '-', '', $uuid ), 0, 8 ) );
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
	private static function current_key_id( array $keys ): string {
		if ( defined( 'WPLC_LICENSE_KEY_ID' ) ) {
			return (string) WPLC_LICENSE_KEY_ID;
		}
		reset( $keys );
		return (string) key( $keys );
	}
}
