<?php
/**
 * Classifies what an administrator typed into the license search box.
 *
 * @package WPLC
 */

namespace WPLC\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure search-term classification; the list table turns the result into SQL.
 */
final class LicenseSearch {

	public const TYPE_NONE        = 'none';
	public const TYPE_LICENSE_KEY = 'license_key';
	public const TYPE_EMAIL       = 'email';
	public const TYPE_LICENSE_ID  = 'license_id';
	public const TYPE_NUMBER      = 'number';
	public const TYPE_UUID        = 'uuid';
	public const TYPE_TEXT        = 'text';

	/**
	 * Classify a search term.
	 *
	 * - A complete pasted license key is matched by its hash only.
	 * - "#123" addresses a license ID.
	 * - A plain number may be a license ID, a customer ID or part of a key prefix.
	 * - Anything containing "@" is an email address (fragment).
	 * - A UUID is a license UUID or the installation ID of an activated site.
	 * - Other text matches key prefix, product key, plan key, a customer email fragment
	 *   or the address of an activated site.
	 *
	 * @param string $term Raw search term.
	 * @return array{type:string,value:string}
	 */
	public static function classify( string $term ): array {
		$term = trim( $term );
		if ( '' === $term ) {
			return array( 'type' => self::TYPE_NONE, 'value' => '' );
		}

		$compact = self::compact_key( $term );
		if ( self::is_full_license_key( $compact ) ) {
			return array( 'type' => self::TYPE_LICENSE_KEY, 'value' => strtoupper( $compact ) );
		}

		if ( preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $term ) ) {
			return array(
				'type'  => self::TYPE_UUID,
				'value' => strtolower( $term ),
			);
		}

		if ( preg_match( '/^#(\d{1,18})$/', $term, $matches ) ) {
			return array( 'type' => self::TYPE_LICENSE_ID, 'value' => ltrim( $matches[1], '0' ) ?: '0' );
		}

		if ( preg_match( '/^\d{1,18}$/', $term ) ) {
			return array( 'type' => self::TYPE_NUMBER, 'value' => $term );
		}

		if ( false !== strpos( $term, '@' ) ) {
			return array( 'type' => self::TYPE_EMAIL, 'value' => strtolower( $term ) );
		}

		return array( 'type' => self::TYPE_TEXT, 'value' => $term );
	}

	/**
	 * Remove everything a mail client or word processor may have put into a pasted key:
	 * whitespace, non-breaking and zero-width spaces, and typographic dashes (B-173).
	 *
	 * @param string $term Pasted text.
	 * @return string
	 */
	public static function compact_key( string $term ): string {
		// U+2010..U+2015 hyphens and dashes, U+2212 minus, U+00AD soft hyphen is dropped below.
		$dashes  = array( "\xE2\x80\x90", "\xE2\x80\x91", "\xE2\x80\x92", "\xE2\x80\x93", "\xE2\x80\x94", "\xE2\x80\x95", "\xE2\x88\x92" );
		$compact = str_replace( $dashes, '-', $term );
		$compact = preg_replace( '/[^A-Za-z0-9-]+/', '', $compact );

		return is_string( $compact ) ? $compact : $term;
	}

	/**
	 * Whether a string has the shape of a complete license key (WPLC-<uuid>-<64 hex>).
	 *
	 * @param string $value Candidate without whitespace.
	 * @return bool
	 */
	public static function is_full_license_key( string $value ): bool {
		return 1 === preg_match( '/^WPLC-[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12}-[0-9A-F]{64}$/i', $value );
	}
}
