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
 * Derives a displayable key from the license UUID without storing plaintext.
 *
 * The WordPress authentication salt is the signing secret. If site salts are
 * rotated, customers should be offered a key-rotation flow before their key is
 * displayed again; the existing verifier remains valid until it is rotated.
 */
final class LicenseKey {

	/**
	 * Build a license key for a license UUID.
	 *
	 * @param string $uuid License UUID.
	 * @return string
	 */
	public static function for_uuid( string $uuid ): string {
		$uuid = strtolower( $uuid );
		$secret = defined( 'WPLC_LICENSE_KEY_SECRET' ) ? (string) WPLC_LICENSE_KEY_SECRET : wp_salt( 'auth' );
		$digest = hash_hmac( 'sha256', $uuid, $secret );

		return 'WPLC-' . strtoupper( $uuid ) . '-' . strtoupper( $digest );
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
}
