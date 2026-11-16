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
 */
/*
 * Only premium features are listed. Core features of the free plugins are never
 * license-gated and therefore are not entitlements.
 */
final class EntitlementCatalog {

	/**
	 * Pattern every entitlement key must match.
	 */
	public const KEY_PATTERN = '/^[a-zA-Z0-9_.-]{1,120}$/';

	/**
	 * All known boolean entitlements.
	 *
	 * @return array<string,array<int,array{key:string,label:string}>> Product group => list of entitlements.
	 */
	public static function all(): array {
		/*
		 * A key is listed only when the premium add-on ships the function (hastla007/captain-pro). Removed on
		 * 16 Nov 2026 (R-07, R-08): the keys that named functions of the free core plugins (affiliate: partner
		 * portal, coupon attribution, refund handling, basic commissions, payout CSV, partner groups, product
		 * and category rates, recurring commissions; voucher: branding, PDF templates, automatic and scheduled
		 * delivery, QR redemption, partial balance, import/export, reports, staff roles). A plan or a license
		 * that still stores one of them keeps it: split() leaves unknown keys in the advanced JSON field.
		 */
		$catalog = array(
			'affiliate' => array(
				array( 'key' => 'affiliate.extended_api', 'label' => __( 'Extended API', 'wp-license' ) ),
				array( 'key' => 'affiliate.automated_payouts', 'label' => __( 'Automated payouts', 'wp-license' ) ),
				array( 'key' => 'affiliate.white_label_portal', 'label' => __( 'White-label portal', 'wp-license' ) ),
				array( 'key' => 'affiliate.webhooks', 'label' => __( 'Webhooks', 'wp-license' ) ),
				array( 'key' => 'affiliate.priority_support', 'label' => __( 'Priority support', 'wp-license' ) ),
			),
			'voucher'   => array(
				array( 'key' => 'voucher.priority_support', 'label' => __( 'Priority support', 'wp-license' ) ),
			),
			'pass'      => array(
				// Pass Captain Pro, decided 14 Nov 2026 (captain-pro docs/PASS-PRO.md): payment providers,
				// integrations, seating, box office, offline check-in, reports and the other former keys are
				// part of the free Pass core. Every plan contains every Pro function; plans differ by sites.
				// E-invoicing delivers XML (XRechnung as UBL and CII); a ZUGFeRD hybrid PDF is not built.
				array( 'key' => 'pass.e_invoicing', 'label' => __( 'E-invoicing (XRechnung XML)', 'wp-license' ) ),
				array( 'key' => 'pass.card_terminal', 'label' => __( 'Card terminal (Stripe Terminal, SumUp)', 'wp-license' ) ),
				array( 'key' => 'pass.waiting_room', 'label' => __( 'Virtual waiting room', 'wp-license' ) ),
				array( 'key' => 'pass.write_api', 'label' => __( 'Write API', 'wp-license' ) ),
				array( 'key' => 'pass.white_label', 'label' => __( 'White label', 'wp-license' ) ),
				array( 'key' => 'pass.priority_support', 'label' => __( 'Priority support', 'wp-license' ) ),
			),
			// Planned add-ons (R-22): the groups exist so that their product keys resolve to the right
			// prefix; no entitlement is offered before the add-on ships it.
			'workflow'  => array(),
			'form'      => array(),
			'review'    => array(),
		);

		/**
		 * Filters whether entitlements that are planned but not shipped by any add-on yet are offered
		 * in the product editor. Off by default: a shop must not sell what does not exist.
		 *
		 * @param bool $offer Whether to offer the planned keys of EntitlementCatalog::planned().
		 * @since 0.2.0
		 */
		if ( apply_filters( 'wplc_offer_planned_entitlements', false ) ) {
			foreach ( self::planned() as $group => $entitlements ) {
				$catalog[ $group ] = array_merge( isset( $catalog[ $group ] ) ? $catalog[ $group ] : array(), $entitlements );
			}
		}

		/**
		 * Filters the catalog of known boolean entitlements offered in the product editor.
		 *
		 * @param array<string,array<int,array{key:string,label:string}>> $catalog Product group => entitlements.
		 * @since 0.2.0
		 */
		$filtered = apply_filters( 'wplc_entitlement_catalog', $catalog );

		return self::normalize( is_array( $filtered ) ? $filtered : $catalog );
	}

	/**
	 * Entitlements that are planned but that no released add-on checks yet (R-09).
	 *
	 * They are not part of all() and therefore neither offered as tick boxes nor shown in the
	 * pricing table, unless the filter `wplc_offer_planned_entitlements` returns true.
	 *
	 * @return array<string,array<int,array{key:string,label:string}>> Product group => list of entitlements.
	 */
	public static function planned(): array {
		return array(
			'affiliate' => array(
				// Added 15 Nov 2026 on request of the captain-pro development; not built there yet.
				array( 'key' => 'affiliate.store_credit', 'label' => __( 'Payout as store credit', 'wp-license' ) ),
				array( 'key' => 'affiliate.stripe_connect', 'label' => __( 'Payouts via Stripe Connect', 'wp-license' ) ),
				array( 'key' => 'affiliate.leaderboard', 'label' => __( 'Leaderboard and milestone emails', 'wp-license' ) ),
				array( 'key' => 'affiliate.multi_tier', 'label' => __( 'Multi-tier commissions', 'wp-license' ) ),
				array( 'key' => 'affiliate.multiple_programs', 'label' => __( 'Multiple programs', 'wp-license' ) ),
			),
			'voucher'   => array(
				array( 'key' => 'voucher.white_label', 'label' => __( 'White label', 'wp-license' ) ),
			),
		);
	}

	/**
	 * Translated headings for the built-in product groups.
	 *
	 * @param string $group Catalog group key.
	 * @return string
	 */
	public static function group_label( string $group ): string {
		$labels = array(
			'affiliate' => __( 'Affiliate Captain', 'wp-license' ),
			'voucher'   => __( 'Voucher Captain', 'wp-license' ),
			'pass'      => __( 'Pass Captain', 'wp-license' ),
			'workflow'  => __( 'Workflow Captain', 'wp-license' ),
			'form'      => __( 'Form Captain', 'wp-license' ),
			'review'    => __( 'Review Captain', 'wp-license' ),
		);
		return isset( $labels[ $group ] ) ? $labels[ $group ] : ucfirst( $group );
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
	 * Product keys of the premium add-ons (decided 14.11.2026, T-158): key = add-on folder = text domain
	 * (hastla007/captain-pro).
	 *
	 * The key on a license product must equal the key the add-on sends; each maps to its entitlement prefix.
	 */
	public const PRODUCT_KEYS = array(
		'voucher-captain-pro'   => 'voucher',
		'pass-captain-pro'      => 'pass',
		'affiliate-captain-pro' => 'affiliate',
	);

	/**
	 * Product keys of planned add-ons that are not released yet (R-22). They resolve to their entitlement
	 * group, but the product editor does not suggest them and no entitlement is offered for them.
	 */
	public const PLANNED_PRODUCT_KEYS = array(
		'workflow-captain-pro' => 'workflow',
		'form-captain-pro'     => 'form',
		'review-captain-pro'   => 'review',
	);

	/**
	 * Known product keys, filterable for further products.
	 *
	 * @return array<string,string> Product key => entitlement group.
	 */
	public static function known_product_keys(): array {
		/**
		 * Filter the product keys suggested in the product editor.
		 *
		 * @param array<string,string> $keys Product key => entitlement group (prefix without the dot).
		 */
		$keys = function_exists( 'apply_filters' ) ? apply_filters( 'wplc_known_product_keys', self::PRODUCT_KEYS ) : self::PRODUCT_KEYS;
		$clean = array();
		foreach ( (array) $keys as $key => $group ) {
			if ( is_string( $key ) && is_string( $group ) && '' !== $key ) {
				$clean[ $key ] = $group;
			}
		}
		return $clean;
	}

	/**
	 * Catalog groups that belong to a license product key such as "voucher-captain-pro".
	 *
	 * @param string $product_key License product key from the product editor.
	 * @return string[] Matching group keys; empty when none matches.
	 */
	public static function groups_for_product_key( string $product_key ): array {
		$product_key = strtolower( $product_key );
		$matches     = array();
		if ( '' === $product_key ) {
			return $matches;
		}
		$known = self::known_product_keys();
		if ( isset( $known[ $product_key ] ) ) {
			return array( $known[ $product_key ] );
		}
		if ( isset( self::PLANNED_PRODUCT_KEYS[ $product_key ] ) ) {
			return array( self::PLANNED_PRODUCT_KEYS[ $product_key ] );
		}
		foreach ( self::all() as $group => $entitlements ) {
			// Groups without entitlements (planned products) match by their exact product key only.
			if ( $entitlements && false !== strpos( $product_key, (string) $group ) ) {
				$matches[] = (string) $group;
			}
		}
		return $matches;
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
