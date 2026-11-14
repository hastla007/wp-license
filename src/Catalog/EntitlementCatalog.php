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
		$catalog = array(
			'affiliate' => array(
				array( 'key' => 'affiliate.partner_portal', 'label' => __( 'Partner portal', 'wp-license' ) ),
				array( 'key' => 'affiliate.coupon_attribution', 'label' => __( 'Coupon attribution', 'wp-license' ) ),
				array( 'key' => 'affiliate.order_refund_handling', 'label' => __( 'Order refund handling', 'wp-license' ) ),
				array( 'key' => 'affiliate.basic_commissions', 'label' => __( 'Basic commissions', 'wp-license' ) ),
				array( 'key' => 'affiliate.manual_payout_csv', 'label' => __( 'Manual payout CSV', 'wp-license' ) ),
				array( 'key' => 'affiliate.partner_groups', 'label' => __( 'Partner groups', 'wp-license' ) ),
				array( 'key' => 'affiliate.product_category_rates', 'label' => __( 'Product and category rates', 'wp-license' ) ),
				array( 'key' => 'affiliate.recurring_commissions', 'label' => __( 'Recurring commissions', 'wp-license' ) ),
				array( 'key' => 'affiliate.extended_api', 'label' => __( 'Extended API', 'wp-license' ) ),
				array( 'key' => 'affiliate.white_label_portal', 'label' => __( 'White-label portal', 'wp-license' ) ),
				array( 'key' => 'affiliate.webhooks', 'label' => __( 'Webhooks', 'wp-license' ) ),
				array( 'key' => 'affiliate.multiple_programs', 'label' => __( 'Multiple programs', 'wp-license' ) ),
				array( 'key' => 'affiliate.priority_support', 'label' => __( 'Priority support', 'wp-license' ) ),
			),
			'voucher'   => array(
				array( 'key' => 'voucher.custom_branding', 'label' => __( 'Custom branding', 'wp-license' ) ),
				array( 'key' => 'voucher.pdf_templates', 'label' => __( 'PDF templates', 'wp-license' ) ),
				array( 'key' => 'voucher.automatic_delivery', 'label' => __( 'Automatic delivery', 'wp-license' ) ),
				array( 'key' => 'voucher.qr_redemption', 'label' => __( 'QR redemption', 'wp-license' ) ),
				array( 'key' => 'voucher.partial_balance', 'label' => __( 'Partial balance', 'wp-license' ) ),
				array( 'key' => 'voucher.staff_roles', 'label' => __( 'Staff roles', 'wp-license' ) ),
				array( 'key' => 'voucher.scheduled_delivery', 'label' => __( 'Scheduled delivery', 'wp-license' ) ),
				array( 'key' => 'voucher.bulk_import_export', 'label' => __( 'Bulk import and export', 'wp-license' ) ),
				array( 'key' => 'voucher.reports', 'label' => __( 'Reports', 'wp-license' ) ),
				array( 'key' => 'voucher.white_label', 'label' => __( 'White label', 'wp-license' ) ),
				array( 'key' => 'voucher.priority_support', 'label' => __( 'Priority support', 'wp-license' ) ),
			),
			'pass'      => array(
				array( 'key' => 'pass.woocommerce_sales', 'label' => __( 'WooCommerce sales', 'wp-license' ) ),
				array( 'key' => 'pass.membership_passes', 'label' => __( 'Membership passes', 'wp-license' ) ),
				array( 'key' => 'pass.campaign_codes', 'label' => __( 'Campaign codes', 'wp-license' ) ),
				array( 'key' => 'pass.waitlist', 'label' => __( 'Waitlist', 'wp-license' ) ),
				array( 'key' => 'pass.csv_export', 'label' => __( 'CSV export', 'wp-license' ) ),
				array( 'key' => 'pass.seating', 'label' => __( 'Seating', 'wp-license' ) ),
				array( 'key' => 'pass.service_slots', 'label' => __( 'Service slots', 'wp-license' ) ),
				array( 'key' => 'pass.pos', 'label' => __( 'Point of sale', 'wp-license' ) ),
				array( 'key' => 'pass.offline_checkin', 'label' => __( 'Offline check-in', 'wp-license' ) ),
				array( 'key' => 'pass.advanced_reports', 'label' => __( 'Advanced reports', 'wp-license' ) ),
				array( 'key' => 'pass.api_webhooks', 'label' => __( 'API and webhooks', 'wp-license' ) ),
				array( 'key' => 'pass.white_label', 'label' => __( 'White label', 'wp-license' ) ),
				array( 'key' => 'pass.priority_support', 'label' => __( 'Priority support', 'wp-license' ) ),
			),
		);

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
	 * Translated headings for the built-in product groups.
	 *
	 * @param string $group Catalog group key.
	 * @return string
	 */
	public static function group_label( string $group ): string {
		$labels = array(
			'affiliate' => __( 'Affiliate Captain', 'wp-license' ),
			'voucher'   => __( 'Voucher Captain', 'wp-license' ),
			'pass'      => __( 'WP Pass Captain', 'wp-license' ),
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
	 * Catalog groups that belong to a license product key such as "voucher-captain".
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
		foreach ( array_keys( self::all() ) as $group ) {
			if ( false !== strpos( $product_key, (string) $group ) ) {
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
