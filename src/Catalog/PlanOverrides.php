<?php
/**
 * Overrides of global settings per plan.
 *
 * @package WPLC
 */

namespace WPLC\Catalog;

use WPLC\Admin\Capabilities;
use WPLC\Infrastructure\Database\Schema;
use WPLC\Settings\Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A plan can replace some manufacturer rules and shop rules for its own licenses and cart
 * lines (SET-LIC-21): staging and development sites, offline period, largest quantity per
 * order line and co-users; since schema 30 also whether beta versions are allowed (SET-LIC-20).
 * Each one is a column of the plan table; NULL (empty in the product editor and the CSV) means
 * "global setting".
 *
 * Order of precedence for a license of such a plan: built-in default, setting, plan, filter.
 * The filters of the global values still decide last.
 *
 * A plan is found through the product the license was bought with, like the live-site limit
 * (RenewalOrder::plan_for_license()). A changed value applies to the existing licenses of the
 * plan at once. A plan whose product was moved to the trash (inactive) keeps applying to the
 * licenses already sold with it (P2-I1); nobody can buy it any more anyway.
 *
 * Who may change an override (P4-M1): whoever holds the capability of the global setting it
 * replaces. The manufacturer rules (staging and development sites, offline period, co-users,
 * beta versions) need `wplc_manage_policy` like their settings; the largest quantity per order
 * line stays with the catalog capability. Without the capability a save keeps the stored value
 * (Catalog::save_mapping()). Without a user (WP-CLI, cron, Action Scheduler) nothing changes.
 *
 * Offline period: at least 2 days (P2-M5), longer than the client's check cadence; a stored 1
 * of an older version is read as 2.
 */
final class PlanOverrides {

	/**
	 * Column => [ smallest value, largest value, setting it replaces ].
	 */
	public const FIELDS = array(
		'staging_site_limit'     => array( 0, 10, 'staging_sites_per_live' ),
		'development_site_limit' => array( 0, 100, 'development_sites' ),
		'offline_cache_days'     => array( 2, 90, 'offline_cache_days' ),
		'max_quantity_per_line'  => array( 0, 100000, 'max_quantity_per_line' ),
		'co_user_limit'          => array( 0, 100, 'co_user_limit' ),
		// SET-LIC-20 (schema 30): 1 = beta versions allowed, 0 = stable only.
		'beta_allowed'           => array( 0, 1, 'beta_updates' ),
	);

	/**
	 * Smallest values of older versions that are read as the current smallest value (P2-M5:
	 * an offline period of 1 day became 2 days). Anything below counts as "global setting".
	 */
	public const LEGACY_MIN = array( 'offline_cache_days' => 1 );

	/**
	 * Overrides that are a yes/no choice rather than a number (shown as a select).
	 */
	public const SWITCHES = array( 'beta_allowed' );

	/**
	 * Overrides that are rules of the shop: whoever may manage the catalog may change them (P4-M1).
	 */
	public const CATALOG_FIELDS = array( 'max_quantity_per_line' );

	/**
	 * Seconds a looked-up plan (and the answer of any()) is reused. Long processes (WP-CLI,
	 * Action Scheduler batches) see a plan saved by another process after at most this time;
	 * a save in the same process forgets everything at once (flush()).
	 */
	public const MAX_AGE = 60;

	/**
	 * Key of any() in the object cache (persistent where the site has one).
	 */
	private const CACHE_KEY = 'plan_overrides_any';

	/**
	 * Whether any plan has an override; null before the first look.
	 *
	 * @var bool|null
	 */
	private static $any = null;

	/**
	 * Plans looked up for licenses, by license ID, keys and source order line (see license_key()).
	 *
	 * @var array<string,array<string,mixed>|null>
	 */
	private static $plans = array();

	/**
	 * When the cache above was started (Unix time), 0 while empty.
	 *
	 * @var int
	 */
	private static $since = 0;

	/**
	 * Register the hooks: the customer account looks up the plans of its license list at once.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'wplc_before_account_licenses', array( self::class, 'prime_views' ), 0 );
	}

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
	 * A stored value that was the smallest one of an older version (an offline period of 1 day
	 * saved before the minimum became 2, P2-M5, see LEGACY_MIN) is read as the smallest value.
	 *
	 * @param array<string,mixed>|null $plan  Plan row.
	 * @param string                   $field Column name.
	 * @return int|null
	 */
	public static function of_plan( ?array $plan, string $field ): ?int {
		if ( null === $plan || ! isset( self::FIELDS[ $field ] ) || ! isset( $plan[ $field ] ) || ! is_scalar( $plan[ $field ] ) ) {
			return null;
		}
		$text = trim( (string) $plan[ $field ] );
		if ( '' === $text || ! ctype_digit( $text ) || strlen( $text ) > 9 ) {
			return null;
		}
		list( $min, $max ) = self::FIELDS[ $field ];
		$value             = (int) $text;
		if ( $value < $min && $value >= ( self::LEGACY_MIN[ $field ] ?? $min ) ) {
			return $min;
		}

		return $value < $min || $value > $max ? null : $value;
	}

	/**
	 * Capability slug needed to change an override; '' when the catalog capability is enough.
	 *
	 * @param string $field Column name.
	 * @return string
	 */
	public static function capability( string $field ): string {
		if ( ! isset( self::FIELDS[ $field ] ) || in_array( $field, self::CATALOG_FIELDS, true ) ) {
			return '';
		}
		$definition = Registry::definition( self::FIELDS[ $field ][2] );
		$capability = null === $definition ? '' : (string) $definition['capability'];

		// Rules of the shop (manage_settings) stay with the catalog capability, like the largest quantity.
		return 'manage_settings' === $capability ? '' : $capability;
	}

	/**
	 * Whether the current user may change an override (P4-M1). Without a user (WP-CLI, cron,
	 * Action Scheduler) the answer is yes, as before.
	 *
	 * @param string $field Column name.
	 * @return bool
	 */
	public static function may_change( string $field ): bool {
		$capability = self::capability( $field );
		if ( '' === $capability ) {
			return true;
		}
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || ! function_exists( 'get_current_user_id' ) || 0 === (int) get_current_user_id() ) {
			return true;
		}

		return Capabilities::can( $capability );
	}

	/**
	 * Overrides the current user may not change.
	 *
	 * @return string[]
	 */
	public static function locked_fields(): array {
		return array_values( array_filter( array_keys( self::FIELDS ), static fn( string $field ): bool => ! self::may_change( $field ) ) );
	}

	/**
	 * Visible name of an override (the label in the product editor).
	 *
	 * @param string $field Column name.
	 * @return string
	 */
	public static function label( string $field ): string {
		$texts = ProductEditor::override_texts();

		return isset( $texts[ $field ] ) ? $texts[ $field ][0] : $field;
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
		$key = self::license_key( $license );
		if ( '' === $key || ! array_key_exists( $key, self::$plans ) ) {
			$plan = self::resolve( array( $license ) )[0] ?? null;
			if ( '' === $key ) {
				return self::of_plan( $plan, $field );
			}
			self::remember( $key, $plan );
		}

		return self::of_plan( self::$plans[ $key ], $field );
	}

	/**
	 * Look up the plans of many licenses at once (a list page), so that the later for_license()
	 * calls cost no query. Does nothing while no plan has an override.
	 *
	 * @param array<int,array<string,mixed>> $licenses License rows.
	 * @return void
	 */
	public static function prime( array $licenses ): void {
		if ( ! $licenses || ! self::any() ) {
			return;
		}
		$missing = array();
		foreach ( $licenses as $license ) {
			$key = self::license_key( $license );
			if ( '' !== $key && ! array_key_exists( $key, self::$plans ) ) {
				$missing[ $key ] = $license;
			}
		}
		if ( ! $missing ) {
			return;
		}
		$plans = self::resolve( array_values( $missing ) );
		$index = 0;
		foreach ( array_keys( $missing ) as $key ) {
			self::remember( (string) $key, $plans[ $index ] ?? null );
			++$index;
		}
	}

	/**
	 * Hook `wplc_before_account_licenses`: look up the plans of the licenses on the account page
	 * in one go. The views carry the license ID only, so the rows are read in one query.
	 *
	 * @param mixed $views Display values per license.
	 * @return void
	 */
	public static function prime_views( $views ): void {
		global $wpdb;

		if ( ! is_array( $views ) || ! $views || ! self::any() ) {
			return;
		}
		$ids = array();
		foreach ( $views as $view ) {
			if ( is_array( $view ) && (int) ( $view['id'] ?? 0 ) > 0 ) {
				$ids[] = (int) $view['id'];
			}
		}
		$ids = array_slice( array_values( array_unique( $ids ) ), 0, 200 );
		if ( ! $ids ) {
			return;
		}
		$tables       = Schema::table_names();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one %d per ID; table name from Schema::table_names().
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, product_key, plan_key, billing_interval, source_order_id, source_order_item_id FROM {$tables['licenses']} WHERE id IN ({$placeholders})", $ids ), ARRAY_A );
		self::prime( is_array( $rows ) ? $rows : array() );
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
	 * Whether any plan has an override, an inactive one included (its licenses keep it, P2-I1).
	 * At most one query per MAX_AGE seconds and process, none while a persistent object cache
	 * holds the answer: licenses of shops that use no override pay nothing on the hot path.
	 *
	 * @return bool
	 */
	public static function any(): bool {
		global $wpdb;

		self::expire();
		if ( null !== self::$any ) {
			return self::$any;
		}
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! function_exists( 'get_option' ) ) {
			return false;
		}
		$cached = function_exists( 'wp_cache_get' ) ? wp_cache_get( self::CACHE_KEY, 'wplc' ) : false;
		if ( 'yes' === $cached || 'no' === $cached ) {
			self::$any = 'yes' === $cached;
			self::start();

			return self::$any;
		}
		$tables     = Schema::table_names();
		$conditions = implode( ' OR ', array_map( static fn( string $column ): string => $column . ' IS NOT NULL', array_keys( self::FIELDS ) ) );
		$suppressed = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the column names are constants of this class.
		$found = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM %i WHERE {$conditions} LIMIT 1", $tables['plans'] ) );
		$wpdb->suppress_errors( $suppressed );
		// Before schema 28 (30 for beta_allowed) the columns do not exist: the query fails and nothing is overridden.
		self::$any = null !== $found;
		self::start();
		if ( function_exists( 'wp_cache_set' ) ) {
			wp_cache_set( self::CACHE_KEY, self::$any ? 'yes' : 'no', 'wplc', self::MAX_AGE );
		}

		return self::$any;
	}

	/**
	 * Forget what was looked up (a plan was saved, deactivated or deleted; tests). Long processes
	 * may call it between batches; otherwise the entries expire after MAX_AGE seconds.
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$any   = null;
		self::$plans = array();
		self::$since = 0;
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::CACHE_KEY, 'wplc' );
		}
	}

	/**
	 * Cache key of a license: its plan depends on the keys and the source order line (P6-M5).
	 *
	 * @param array<string,mixed> $license License row.
	 * @return string Empty for a license without ID (not cached).
	 */
	private static function license_key( array $license ): string {
		$id = (int) ( $license['id'] ?? 0 );
		if ( $id <= 0 ) {
			return '';
		}

		return implode(
			'|',
			array(
				$id,
				(string) ( $license['product_key'] ?? '' ),
				(string) ( $license['plan_key'] ?? '' ),
				(string) ( $license['billing_interval'] ?? '' ),
				(int) ( $license['source_order_item_id'] ?? 0 ),
			)
		);
	}

	/**
	 * Plans of sold licenses, inactive ones included (P2-I1).
	 *
	 * @param array<int,array<string,mixed>> $licenses License rows (a list).
	 * @return array<int,array<string,mixed>|null> In the same order.
	 */
	private static function resolve( array $licenses ): array {
		if ( ! class_exists( '\\WPLC\\Billing\\RenewalOrder' ) ) {
			return array();
		}

		return \WPLC\Billing\RenewalOrder::plans_for_licenses( $licenses, true );
	}

	/**
	 * Keep one looked-up plan.
	 *
	 * @param string                   $key  Cache key.
	 * @param array<string,mixed>|null $plan Plan row.
	 * @return void
	 */
	private static function remember( string $key, ?array $plan ): void {
		self::start();
		if ( count( self::$plans ) >= 2000 ) {
			self::$plans = array();
		}
		self::$plans[ $key ] = $plan;
	}

	/**
	 * Note when the cache was started.
	 *
	 * @return void
	 */
	private static function start(): void {
		if ( 0 === self::$since ) {
			self::$since = time();
		}
	}

	/**
	 * Forget entries older than MAX_AGE seconds (WP-CLI, Action Scheduler batches).
	 *
	 * @return void
	 */
	private static function expire(): void {
		if ( 0 !== self::$since && time() - self::$since > self::MAX_AGE ) {
			self::$any   = null;
			self::$plans = array();
			self::$since = 0;
		}
	}
}
