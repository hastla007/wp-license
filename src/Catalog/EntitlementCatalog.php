<?php
/**
 * Known entitlement keys per product.
 *
 * @package WPLC
 */

namespace WPLC\Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Catalog of feature entitlements (see docs/ENTITLEMENTS.md) and helpers for the product editor.
 *
 * The class holds no product, key or label itself: it reads the catalog files the vendor's add-ons
 * ship (CatalogStore). Only premium features belong into such a file; functions of a free core
 * plugin are never license-gated and therefore are not entitlements.
 */
final class EntitlementCatalog {

	/**
	 * Pattern every entitlement key must match.
	 */
	public const KEY_PATTERN = '/^[a-zA-Z0-9_.-]{1,120}$/';

	/**
	 * All known boolean entitlements that the product editor offers.
	 *
	 * The definitions come from the imported catalog files (CatalogStore, docs/CATALOG-FILE.md): the
	 * add-on of a product ships them, the server holds no product or key of any vendor in its code
	 * (T-150, owner decisions G2 and E8 of 16 Nov 2026). A key is offered when its product and the
	 * key itself are "available". A key that is not in the catalog (never was, or left it with a
	 * later file) is not lost on a plan or license that stores it: split() leaves unknown keys in
	 * the advanced JSON field.
	 *
	 * @return array<string,array<int,array{key:string,label:string}>> Entitlement prefix (group) => list of entitlements.
	 */
	public static function all(): array {
		/**
		 * Filters whether entitlements that are planned but not shipped by any add-on yet are offered
		 * in the product editor. Off by default: a shop must not sell what does not exist.
		 *
		 * @param bool $offer Whether to offer the planned keys of EntitlementCatalog::planned().
		 * @since 0.2.0
		 */
		$offer_planned = (bool) apply_filters( 'wplc_offer_planned_entitlements', false );

		$catalog = array();
		foreach ( CatalogStore::all() as $product ) {
			$group = (string) $product['prefix'];
			// A planned product keeps its group, so that its product key resolves to the right prefix.
			$catalog[ $group ] = isset( $catalog[ $group ] ) ? $catalog[ $group ] : array();
			$product_available = CatalogFile::STATUS_AVAILABLE === (string) $product['status'];
			foreach ( (array) $product['entitlements'] as $entitlement ) {
				$available = $product_available && CatalogFile::STATUS_AVAILABLE === (string) $entitlement['status'];
				if ( $available || $offer_planned ) {
					$catalog[ $group ][] = self::entry( $entitlement );
				}
			}
		}

		/**
		 * Filters the catalog of known boolean entitlements offered in the product editor.
		 *
		 * @param array<string,array<int,array{key:string,label:string}>> $catalog Entitlement prefix => entitlements.
		 * @since 0.2.0
		 */
		$filtered = apply_filters( 'wplc_entitlement_catalog', $catalog );

		return self::normalize( is_array( $filtered ) ? $filtered : $catalog );
	}

	/**
	 * Entitlements that are planned but that no released add-on checks yet (R-09).
	 *
	 * These are the keys with the status "planned" and every key of a planned product. They are
	 * not part of all() and therefore neither offered as tick boxes nor shown in the pricing table,
	 * unless the filter `wplc_offer_planned_entitlements` returns true.
	 *
	 * @return array<string,array<int,array{key:string,label:string}>> Entitlement prefix => list of entitlements.
	 */
	public static function planned(): array {
		$planned = array();
		foreach ( CatalogStore::all() as $product ) {
			$product_available = CatalogFile::STATUS_AVAILABLE === (string) $product['status'];
			foreach ( (array) $product['entitlements'] as $entitlement ) {
				if ( ! $product_available || CatalogFile::STATUS_AVAILABLE !== (string) $entitlement['status'] ) {
					$planned[ (string) $product['prefix'] ][] = self::entry( $entitlement );
				}
			}
		}

		return $planned;
	}

	/**
	 * Heading of a group in the product editor: the name of the product with that prefix.
	 *
	 * @param string $group Entitlement prefix.
	 * @return string
	 */
	public static function group_label( string $group ): string {
		foreach ( CatalogStore::all() as $product ) {
			if ( (string) $product['prefix'] === $group ) {
				$name = CatalogStore::text( $product['name'] ?? array() );
				if ( '' !== $name ) {
					return $name;
				}
			}
		}

		return ucfirst( $group );
	}

	/**
	 * Key and label in the current language.
	 *
	 * @param array<string,mixed> $entitlement Entitlement of a stored product.
	 * @return array{key:string,label:string}
	 */
	private static function entry( array $entitlement ): array {
		$label = CatalogStore::text( $entitlement['label'] ?? array() );

		return array(
			'key'   => (string) $entitlement['key'],
			'label' => '' !== $label ? $label : (string) $entitlement['key'],
		);
	}

	/**
	 * Flat list of every known entitlement key.
	 *
	 * @return string[]
	 */
	public static function known_keys(): array {
		$keys = array();
		foreach ( self::all() as $entitlements ) {
			foreach ( $entitlements as $entitlement ) {
				$keys[] = $entitlement['key'];
			}
		}
		return array_values( array_unique( $keys ) );
	}

	/**
	 * Product keys of the products in the catalog with a given status.
	 *
	 * @param string $status CatalogFile::STATUS_AVAILABLE or CatalogFile::STATUS_PLANNED.
	 * @return array<string,string> Product key => entitlement prefix.
	 */
	private static function product_keys( string $status ): array {
		$keys = array();
		foreach ( CatalogStore::all() as $product_key => $product ) {
			if ( (string) $product['status'] === $status ) {
				$keys[ (string) $product_key ] = (string) $product['prefix'];
			}
		}

		return $keys;
	}

	/**
	 * Product keys of planned products that are not released yet (R-22). They resolve to their
	 * entitlement group, but the product editor does not suggest them and no entitlement is offered for them.
	 *
	 * @return array<string,string> Product key => entitlement prefix.
	 */
	public static function planned_product_keys(): array {
		return self::product_keys( CatalogFile::STATUS_PLANNED );
	}

	/**
	 * Product keys the product editor suggests: the available products of the catalog. The key on a
	 * license product must equal the key the add-on sends; each maps to its entitlement prefix.
	 *
	 * @return array<string,string> Product key => entitlement group.
	 */
	public static function known_product_keys(): array {
		$available = self::product_keys( CatalogFile::STATUS_AVAILABLE );
		/**
		 * Filter the product keys suggested in the product editor.
		 *
		 * @param array<string,string> $keys Product key => entitlement group (prefix without the dot).
		 */
		$keys = function_exists( 'apply_filters' ) ? apply_filters( 'wplc_known_product_keys', $available ) : $available;
		$clean = array();
		foreach ( (array) $keys as $key => $group ) {
			if ( is_string( $key ) && is_string( $group ) && '' !== $key ) {
				$clean[ $key ] = $group;
			}
		}
		return $clean;
	}

	/**
	 * Every product key the catalog resolves, with its entitlement prefix: the suggested (available)
	 * product keys, including those added with `wplc_known_product_keys`, and the planned ones.
	 *
	 * @return array<string,string> Product key => entitlement group.
	 */
	public static function product_key_groups(): array {
		return self::known_product_keys() + self::planned_product_keys();
	}

	/**
	 * Catalog groups that belong to a license product key such as "voucher-captain-pro".
	 *
	 * The group is resolved only through the catalog (product key => prefix). There is no match by
	 * substring (PK-01): "learnboating-form-connector" contains "learnboating", the prefix of another
	 * project product, and must not be offered its entitlements. A key the catalog does not know has
	 * no group, so the product editor offers nothing for it until its catalog file is imported.
	 *
	 * @param string $product_key License product key from the product editor.
	 * @return string[] The group of the product key; empty when the catalog does not know the key.
	 */
	public static function groups_for_product_key( string $product_key ): array {
		$product_key = strtolower( $product_key );
		if ( '' === $product_key ) {
			return array();
		}
		$groups = self::product_key_groups();

		return isset( $groups[ $product_key ] ) ? array( $groups[ $product_key ] ) : array();
	}

	/**
	 * Split a stored entitlement map into ticked catalog keys and the remaining advanced values.
	 *
	 * Only known keys whose value is exactly true become ticked boxes; numeric
	 * limits, false values and unknown keys stay in the advanced JSON field.
	 *
	 * @param array<string,mixed> $entitlements Stored entitlement map.
	 * @return array{checked:string[],advanced:array<string,mixed>}
	 */
	public static function split( array $entitlements ): array {
		$known    = array_flip( self::known_keys() );
		$checked  = array();
		$advanced = array();
		foreach ( $entitlements as $key => $value ) {
			if ( ! is_string( $key ) ) {
				continue;
			}
			if ( true === $value && isset( $known[ $key ] ) ) {
				$checked[] = $key;
			} else {
				$advanced[ $key ] = $value;
			}
		}

		return array(
			'checked'  => $checked,
			'advanced' => $advanced,
		);
	}

	/**
	 * Merge ticked catalog keys with the advanced JSON values into one entitlement map.
	 *
	 * A ticked box always wins over a value for the same key in the advanced field.
	 * Ticked keys that are not in the catalog are ignored.
	 *
	 * @param mixed               $checked  Submitted checkbox values.
	 * @param array<string,mixed> $advanced Decoded advanced JSON object.
	 * @return array<string,mixed>
	 */
	public static function merge( $checked, array $advanced ): array {
		$merged = array();
		foreach ( $advanced as $key => $value ) {
			if ( is_string( $key ) && preg_match( self::KEY_PATTERN, $key ) ) {
				$merged[ $key ] = $value;
			}
		}

		$known = array_flip( self::known_keys() );
		foreach ( is_array( $checked ) ? $checked : array() as $key ) {
			if ( is_string( $key ) && isset( $known[ $key ] ) && preg_match( self::KEY_PATTERN, $key ) ) {
				$merged[ $key ] = true;
			}
		}

		return $merged;
	}

	/**
	 * Drop malformed entries a filter may have added.
	 *
	 * @param array<mixed,mixed> $catalog Raw catalog.
	 * @return array<string,array<int,array{key:string,label:string}>>
	 */
	private static function normalize( array $catalog ): array {
		$clean = array();
		foreach ( $catalog as $group => $entitlements ) {
			if ( ! is_string( $group ) || ! preg_match( '/^[a-z0-9_-]{1,40}$/', $group ) || ! is_array( $entitlements ) ) {
				continue;
			}
			$clean[ $group ] = array();
			foreach ( $entitlements as $entitlement ) {
				if (
					is_array( $entitlement )
					&& isset( $entitlement['key'] )
					&& is_string( $entitlement['key'] )
					&& preg_match( self::KEY_PATTERN, $entitlement['key'] )
				) {
					$clean[ $group ][] = array(
						'key'   => $entitlement['key'],
						'label' => isset( $entitlement['label'] ) && is_string( $entitlement['label'] ) && '' !== $entitlement['label'] ? $entitlement['label'] : $entitlement['key'],
					);
				}
			}
		}
		return $clean;
	}
}
