<?php
/**
 * Overrides of global settings per plan.
 *
 * @package WPLC
 */

namespace WPLC\Catalog;

use WPLC\Infrastructure\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A plan can replace some manufacturer rules and shop rules for its own licenses and cart
 * lines (SET-LIC-21): staging and development sites, offline period, largest quantity per
 * order line and co-users. Each one is a column of the plan table; NULL (empty in the product
 * editor and the CSV) means "global setting".
 *
 * Order of precedence for a license of such a plan: built-in default, setting, plan, filter.
 * The filters of the global values still decide last.
 *
 * A plan is found through the product the license was bought with, like the live-site limit
 * (RenewalOrder::plan_for_license()). A changed value applies to the existing licenses of the
 * plan at once.
 */
final class PlanOverrides {

	/**
	 * Column => [ smallest value, largest value, setting it replaces ].
	 */
	public const FIELDS = array(
		'staging_site_limit'     => array( 0, 10, 'staging_sites_per_live' ),
		'development_site_limit' => array( 0, 100, 'development_sites' ),
		'offline_cache_days'     => array( 1, 90, 'offline_cache_days' ),
		'max_quantity_per_line'  => array( 0, 100000, 'max_quantity_per_line' ),
		'co_user_limit'          => array( 0, 100, 'co_user_limit' ),
	);

	/**
	 * Whether any active plan has an override; null before the first look in this request.
	 *
	 * @var bool|null
	 */
	private static $any = null;

	/**
	 * Plans looked up for licenses in this request, by license ID.
	 *
	 * @var array<int,array<string,mixed>|null>
	 */
	private static $plans = array();

	/**
	 * Check a submitted value of one override.
	 *
	 * @param string $field Column name (key of FIELDS).
	 * @param mixed  $raw   Submitted value; empty means "global setting".
	 * @return array{ok:bool,value:int|null}
	 */
	public static function normalize( string $field, $raw ): array {
		if ( ! isset( self::FIELDS[ $field ] ) ) {
			return array(
				'ok'    => false,
				'value' => null,
			);
		}
		$text = is_scalar( $raw ) ? trim( (string) $raw ) : '';
		if ( '' === $text ) {
			return array(
				'ok'    => true,
				'value' => null,
			);
		}
		list( $min, $max ) = self::FIELDS[ $field ];
		if ( ! ctype_digit( $text ) || strlen( $text ) > 9 || (int) $text < $min || (int) $text > $max ) {
			return array(
				'ok'    => false,
				'value' => null,
			);
		}

		return array(
			'ok'    => true,
			'value' => (int) $text,
		);
	}

	/**
	 * The override of a plan row, or null for "global setting".
	 *
	 * @param array<string,mixed>|null $plan  Plan row.
	 * @param string                   $field Column name.
	 * @return int|null
	 */
	public static function of_plan( ?array $plan, string $field ): ?int {
		if ( null === $plan || ! isset( self::FIELDS[ $field ] ) || ! isset( $plan[ $field ] ) || '' === (string) $plan[ $field ] ) {
			return null;
		}
		$result = self::normalize( $field, $plan[ $field ] );

		return $result['ok'] ? $result['value'] : null;
	}

	/**
	 * The override that applies to a license, or null for "global setting".
	 *
	 * @param array<string,mixed> $license License row.
	 * @param string              $field   Column name.
	 * @return int|null
	 */
	public static function for_license( array $license, string $field ): ?int {
		if ( ! self::any() ) {
			return null;
		}
		$id = (int) ( $license['id'] ?? 0 );
		if ( $id <= 0 || ! array_key_exists( $id, self::$plans ) ) {
			$plan = class_exists( '\\WPLC\\Billing\\RenewalOrder' ) ? \WPLC\Billing\RenewalOrder::plan_for_license( $license ) : null;
			if ( $id <= 0 ) {
				return self::of_plan( $plan, $field );
			}
			self::$plans[ $id ] = $plan;
		}

		return self::of_plan( self::$plans[ $id ], $field );
	}

	/**
	 * The override that applies to a product or variation in the cart, or null for "global setting".
	 *
	 * @param mixed  $product WooCommerce product or variation.
	 * @param string $field   Column name.
	 * @return int|null
	 */
	public static function for_product( $product, string $field ): ?int {
		if ( ! self::any() ) {
			return null;
		}

		return self::of_plan( Catalog::mapping_for_product( $product ), $field );
	}

	/**
	 * Whether any active plan has an override. One query per request, so licenses of shops that
	 * use none pay nothing on the hot path (entitlement checks).
	 *
	 * @return bool
	 */
	public static function any(): bool {
		global $wpdb;

		if ( null !== self::$any ) {
			return self::$any;
		}
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! function_exists( 'get_option' ) ) {
			return false;
		}
		$tables     = Schema::table_names();
		$conditions = implode( ' OR ', array_map( static fn( string $column ): string => $column . ' IS NOT NULL', array_keys( self::FIELDS ) ) );
		$suppressed = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the column names are constants of this class.
		$found = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM %i WHERE active = 1 AND ({$conditions}) LIMIT 1", $tables['plans'] ) );
		$wpdb->suppress_errors( $suppressed );
		// Before schema 28 the columns do not exist: the query fails and nothing is overridden.
		self::$any = null !== $found;

		return self::$any;
	}

	/**
	 * Forget what this request looked up (a plan was saved or deleted; tests).
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$any   = null;
		self::$plans = array();
	}
}
