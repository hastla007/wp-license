<?php
/**
 * Classifies what an administrator typed into the license search box.
 *
 * @package WPLC
 */

namespace WPLC\Admin;

use WPLC\License\LicenseKey;

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
	public const TYPE_TEXT        = 'text';

	/**
	 * A complete license key reduced to its hash; the only form in which a
	 * searched key is kept between requests (T-141).
	 */
	public const TYPE_LICENSE_KEY_HASH = 'license_key_hash';

	/**
	 * Length of the public key prefix "WPLC-" + 8 hex characters (LicenseKey::prefix()).
	 */
	private const KEY_PREFIX_LENGTH = 13;

	/**
	 * Classify a search term.
	 *
	 * - A complete pasted license key is matched by its hash only.
	 * - "#123" addresses a license ID.
	 * - A plain number may be a license ID, a customer ID or part of a key prefix.
	 * - Anything containing "@" is an email address (fragment).
	 * - Other text matches key prefix, product key, plan key or a customer email fragment.
	 *
	 * @param string $term Raw search term.
	 * @return array{type:string,value:string}
	 */
	public static function classify( string $term ): array {
		$term = trim( $term );
		if ( '' === $term ) {
			return array( 'type' => self::TYPE_NONE, 'value' => '' );
		}

		$compact = preg_replace( '/\s+/', '', $term );
		$compact = is_string( $compact ) ? $compact : $term;
		if ( self::is_full_license_key( $compact ) ) {
			return array( 'type' => self::TYPE_LICENSE_KEY, 'value' => strtoupper( $compact ) );
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
	 * Classify a term into the criteria that are kept between requests.
	 *
	 * A complete license key never leaves this method: it is replaced by its
	 * hash (the same hash the licenses table stores in license_key_hash).
	 * A longer fragment of a key (a key with a typo or cut off) is reduced to
	 * the public key prefix: only the prefix is stored in key_prefix, so the
	 * rest could never match, and it is as secret as the key itself.
	 * Every other term keeps its classified value plus a display label.
	 *
	 * @param string $term Raw search term.
	 * @return array{type:string,value:string,label:string}
	 */
	public static function criteria( string $term ): array {
		$search = self::classify( $term );
		if ( self::TYPE_LICENSE_KEY === $search['type'] ) {
			return array(
				'type'  => self::TYPE_LICENSE_KEY_HASH,
				'value' => LicenseKey::hash( $search['value'] ),
				'label' => '',
			);
		}

		if ( self::TYPE_TEXT === $search['type'] ) {
			$compact = preg_replace( '/\s+/', '', $search['value'] );
			$compact = is_string( $compact ) ? $compact : $search['value'];
			if ( 0 === stripos( $compact, 'WPLC-' ) && strlen( $compact ) > self::KEY_PREFIX_LENGTH ) {
				$prefix = strtoupper( substr( $compact, 0, self::KEY_PREFIX_LENGTH ) );
				return array(
					'type'  => self::TYPE_TEXT,
					'value' => $prefix,
					'label' => $prefix,
				);
			}
		}

		return array(
			'type'  => $search['type'],
			'value' => $search['value'],
			'label' => self::TYPE_NONE === $search['type'] ? '' : trim( $term ),
		);
	}

	/**
	 * Validate criteria read back from storage.
	 *
	 * @param mixed $criteria Stored value.
	 * @return array{type:string,value:string,label:string}|null Null when unusable.
	 */
	public static function sanitize_criteria( $criteria ): ?array {
		if ( ! is_array( $criteria ) || ! isset( $criteria['type'], $criteria['value'] ) || ! is_string( $criteria['type'] ) || ! is_string( $criteria['value'] ) ) {
			return null;
		}
		$types = array( self::TYPE_LICENSE_KEY_HASH, self::TYPE_EMAIL, self::TYPE_LICENSE_ID, self::TYPE_NUMBER, self::TYPE_TEXT );
		if ( ! in_array( $criteria['type'], $types, true ) || '' === $criteria['value'] ) {
			return null;
		}
		if ( self::TYPE_LICENSE_KEY_HASH === $criteria['type'] && 1 !== preg_match( '/^[0-9a-f]{64}$/', $criteria['value'] ) ) {
			return null;
		}
		$label = isset( $criteria['label'] ) && is_string( $criteria['label'] ) ? $criteria['label'] : '';

		return array(
			'type'  => $criteria['type'],
			'value' => $criteria['value'],
			'label' => self::TYPE_LICENSE_KEY_HASH === $criteria['type'] ? '' : $label,
		);
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
