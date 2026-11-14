<?php
/**
 * Product and plan mapping repository.
 *
 * @package WPLC
 */

namespace WPLC\Catalog;

use WPLC\Billing\Money;
use WPLC\Infrastructure\Database\Schema;
use WPLC\License\AuditLog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists the mapping between WooCommerce products and license plans.
 *
 * The table `plans` is the one source of truth for "is this a license product":
 * checkout, license issuing, the product editor and the CSV transfer all ask
 * find_mapping(). The product meta `_wplc_*` only mirrors the form values.
 */
final class Catalog {

	/**
	 * Pattern of a license product key: lowercase letters, digits and hyphens,
	 * 2 to 100 characters, starting with a letter or digit. Every key that
	 * matches is also accepted by the release registry and the package storage.
	 */
	public const PRODUCT_KEY_PATTERN = '/^[a-z0-9][a-z0-9-]{1,99}$/D';

	/**
	 * Pattern of a plan key: like a product key, but one character is enough.
	 */
	public const PLAN_KEY_PATTERN = '/^[a-z0-9][a-z0-9-]{0,99}$/D';

	/**
	 * Autoloaded option with the IDs of all mapped products and variations
	 * (derived from the table `plans`, rebuilt when missing).
	 */
	public const IDS_OPTION = 'wplc_license_product_ids';

	/**
	 * Code of the last save_mapping() failure, empty after a success.
	 *
	 * @var string
	 */
	private static $last_error = '';

	/**
	 * Trim and lowercase a key typed by a person. Nothing else is rewritten:
	 * a key that does not match the pattern afterwards is rejected, not "cleaned".
	 *
	 * @param mixed $raw Raw value.
	 * @return string
	 */
	public static function normalize_key( $raw ): string {
		return is_scalar( $raw ) ? strtolower( trim( (string) $raw ) ) : '';
	}

	/**
	 * Whether a string is a valid license product key.
	 *
	 * @param string $key Normalized key.
	 * @return bool
	 */
	public static function is_valid_product_key( string $key ): bool {
		return 1 === preg_match( self::PRODUCT_KEY_PATTERN, $key );
	}

	/**
	 * Whether a string is a valid plan key.
	 *
	 * @param string $key Normalized key.
	 * @return bool
	 */
	public static function is_valid_plan_key( string $key ): bool {
		return 1 === preg_match( self::PLAN_KEY_PATTERN, $key );
	}

	/**
	 * Code of the last save_mapping() failure.
	 *
	 * @return string Empty string, or one of invalid_product, invalid_product_key, invalid_plan_key,
	 *                invalid_interval, invalid_entitlements, product_not_found, invalid_price, db_error.
	 */
	public static function last_error(): string {
		return self::$last_error;
	}

	/**
	 * Human-readable reason for a save_mapping() failure code.
	 *
	 * @param string $code Failure code.
	 * @return string
	 */
	public static function error_message( string $code ): string {
		switch ( $code ) {
			case 'invalid_product_key':
				return __( 'The license product key may only contain lowercase letters, digits and hyphens, must start with a letter or digit and needs 2 to 100 characters (for example voucher-captain).', 'wp-license' );
			case 'invalid_plan_key':
				return __( 'The license plan key may only contain lowercase letters, digits and hyphens, must start with a letter or digit and may have up to 100 characters (for example business).', 'wp-license' );
			case 'invalid_interval':
				return __( 'The billing interval must be "month" or "year".', 'wp-license' );
			case 'invalid_entitlements':
				return __( 'The entitlements contain a value that cannot be stored (for example a number that is too large).', 'wp-license' );
			case 'product_not_found':
			case 'invalid_product':
				return __( 'The WooCommerce product does not exist.', 'wp-license' );
			case 'invalid_price':
				return __( 'The product needs a valid regular price before it can become a license product.', 'wp-license' );
			default:
				return __( 'The license plan could not be written to the database.', 'wp-license' );
		}
	}

	/**
	 * Remember a failure code and return false.
	 *
	 * @param string $code Failure code.
	 * @return bool Always false.
	 */
	private static function fail( string $code ): bool {
		self::$last_error = $code;
		return false;
	}

	/**
	 * Store or update a mapping for a WooCommerce product or variation.
	 *
	 * An existing plan row keeps its ID (INSERT ... ON DUPLICATE KEY UPDATE), so
	 * a plan ID stays valid across saves. The customer-facing product name is
	 * written when the license product is created and afterwards only when
	 * `update_display_name` is set.
	 *
	 * @param int                 $product_id WooCommerce parent product ID.
	 * @param int                 $variation_id WooCommerce variation ID, or zero.
	 * @param array<string,mixed> $data Mapping values: product_key, plan_key, billing_interval, live_site_limit,
	 *                                  support_level, entitlements, display_name, update_display_name.
	 * @return bool False on failure; last_error() tells why.
	 */
	public static function save_mapping( int $product_id, int $variation_id, array $data ): bool {
		global $wpdb;

		self::$last_error = '';

		$product_key = self::normalize_key( $data['product_key'] ?? '' );
		$plan_key    = self::normalize_key( $data['plan_key'] ?? '' );
		$interval    = self::normalize_key( $data['billing_interval'] ?? 'year' );
		$limit       = absint( $data['live_site_limit'] ?? 1 );
		$support     = self::normalize_key( $data['support_level'] ?? 'standard' );

		if ( $product_id <= 0 || $variation_id < 0 ) {
			return self::fail( 'invalid_product' );
		}
		if ( ! self::is_valid_product_key( $product_key ) ) {
			return self::fail( 'invalid_product_key' );
		}
		if ( ! self::is_valid_plan_key( $plan_key ) ) {
			return self::fail( 'invalid_plan_key' );
		}
		if ( ! in_array( $interval, array( 'month', 'year' ), true ) ) {
			return self::fail( 'invalid_interval' );
		}

		if ( ! in_array( $support, array( 'community', 'standard', 'priority' ), true ) ) {
			$support = 'standard';
		}

		$limit   = max( 1, min( 1000, $limit ) );
		$product = wc_get_product( $variation_id ? $variation_id : $product_id );
		if ( ! $product ) {
			return self::fail( 'product_not_found' );
		}

		$decimals    = function_exists( 'wc_get_price_decimals' ) ? max( 0, min( 6, (int) wc_get_price_decimals() ) ) : 2;
		$price_minor = self::product_price_minor( $product, $decimals );
		if ( null === $price_minor && ! $variation_id && $product->is_type( 'variable' ) ) {
			// A variable product has no price of its own (and none at all before its variations exist).
			// A purchased variation brings its own price, see find_mapping().
			$price_minor = 0;
		}
		if ( null === $price_minor ) {
			return self::fail( 'invalid_price' );
		}
		$currency = strtoupper( sanitize_text_field( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'EUR' ) );

		$entitlements = $data['entitlements'] ?? array();
		if ( ! is_array( $entitlements ) ) {
			$entitlements = array();
		}
		foreach ( $entitlements as $value ) {
			// json_decode() turns 1e999 into INF, which cannot be encoded again.
			if ( is_float( $value ) && ! is_finite( $value ) ) {
				return self::fail( 'invalid_entitlements' );
			}
		}
		$entitlements['sites.live_limit'] = $limit;
		$entitlements['support.level']    = $support;
		$entitlements_json                = wp_json_encode( $entitlements );
		if ( false === $entitlements_json ) {
			return self::fail( 'invalid_entitlements' );
		}

		$display_name = (string) ( $data['display_name'] ?? '' );
		if ( '' === trim( $display_name ) ) {
			// A variation is called "Product - Attribute"; the license product carries the parent's name.
			$named        = $variation_id ? wc_get_product( $product_id ) : $product;
			$display_name = $named ? (string) $named->get_name() : (string) $product->get_name();
		}
		$display_name = self::truncate( sanitize_text_field( $display_name ), 190 );
		$rename       = ! empty( $data['update_display_name'] );

		$now    = current_time( 'mysql', true );
		$tables = Schema::table_names();
		$before = self::find_exact_mapping( $product_id, $variation_id, true );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from Schema::table_names().
		if ( $rename ) {
			$product_saved = $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$tables['products']} (product_key, display_name, active, created_at, updated_at) VALUES (%s, %s, 1, %s, %s) ON DUPLICATE KEY UPDATE active = 1, updated_at = %s, display_name = %s",
					$product_key,
					$display_name,
					$now,
					$now,
					$now,
					$display_name
				)
			);
		} else {
			$product_saved = $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$tables['products']} (product_key, display_name, active, created_at, updated_at) VALUES (%s, %s, 1, %s, %s) ON DUPLICATE KEY UPDATE active = 1, updated_at = %s",
					$product_key,
					$display_name,
					$now,
					$now,
					$now
				)
			);
		}

		if ( false === $product_saved ) {
			return self::fail( 'db_error' );
		}

		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$tables['plans']} (product_key, plan_key, woocommerce_product_id, woocommerce_variation_id, billing_interval, price_minor, price_decimals, currency, live_site_limit, support_level, entitlements_json, active, created_at, updated_at) VALUES (%s, %s, %d, %d, %s, %d, %d, %s, %d, %s, %s, 1, %s, %s) ON DUPLICATE KEY UPDATE product_key = %s, plan_key = %s, billing_interval = %s, price_minor = %d, price_decimals = %d, currency = %s, live_site_limit = %d, support_level = %s, entitlements_json = %s, active = 1, updated_at = %s",
				$product_key,
				$plan_key,
				$product_id,
				$variation_id,
				$interval,
				$price_minor,
				$decimals,
				$currency,
				$limit,
				$support,
				$entitlements_json,
				$now,
				$now,
				$product_key,
				$plan_key,
				$interval,
				$price_minor,
				$decimals,
				$currency,
				$limit,
				$support,
				$entitlements_json,
				$now
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( false === $result ) {
			return self::fail( 'db_error' );
		}

		self::flush_product_ids();
		self::audit_change( $before, self::find_exact_mapping( $product_id, $variation_id, true ) );

		return true;
	}

	/**
	 * Find the active plan for a purchased product: the one source of truth for
	 * checkout, license issuing and display.
	 *
	 * A variation without its own plan uses the plan of its parent product (a
	 * variable product mapped once on the "General" tab covers all variations).
	 * The row then carries `inherited_from_parent = 1`, and `price_minor` is the
	 * regular price of the purchased variation instead of the parent's lowest
	 * variation price, so the renewal costs what the customer bought.
	 *
	 * @param int $product_id Parent product ID.
	 * @param int $variation_id Variation ID, or zero.
	 * @return array<string,mixed>|null
	 */
	public static function find_mapping( int $product_id, int $variation_id = 0 ): ?array {
		$row = self::find_exact_mapping( $product_id, $variation_id );
		if ( $row || ! $variation_id ) {
			return $row;
		}

		$row = self::find_exact_mapping( $product_id, 0 );
		if ( ! $row ) {
			return null;
		}

		$row['inherited_from_parent'] = 1;
		$variation                    = function_exists( 'wc_get_product' ) ? wc_get_product( $variation_id ) : null;
		$price_minor                  = $variation ? self::product_price_minor( $variation, (int) ( $row['price_decimals'] ?? 2 ) ) : null;
		if ( null !== $price_minor ) {
			$row['price_minor'] = (string) $price_minor;
		}

		return $row;
	}

	/**
	 * Find the plan stored for exactly this product/variation pair, without the parent fallback.
	 *
	 * @param int  $product_id Parent product ID.
	 * @param int  $variation_id Variation ID, or zero.
	 * @param bool $include_inactive Also return a deactivated plan (trashed product).
	 * @return array<string,mixed>|null
	 */
	public static function find_exact_mapping( int $product_id, int $variation_id = 0, bool $include_inactive = false ): ?array {
		global $wpdb;

		if ( $product_id <= 0 ) {
			return null;
		}

		$tables = Schema::table_names();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from Schema::table_names().
		if ( $include_inactive ) {
			$sql = $wpdb->prepare(
				"SELECT * FROM {$tables['plans']} WHERE woocommerce_product_id = %d AND woocommerce_variation_id = %d LIMIT 1",
				$product_id,
				$variation_id
			);
		} else {
			$sql = $wpdb->prepare(
				"SELECT * FROM {$tables['plans']} WHERE active = 1 AND woocommerce_product_id = %d AND woocommerce_variation_id = %d LIMIT 1",
				$product_id,
				$variation_id
			);
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
		$row = $wpdb->get_row( $sql, ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * The plan a WooCommerce product object would be sold with.
	 *
	 * @param mixed $product WC_Product (simple, variable or variation).
	 * @return array<string,mixed>|null
	 */
	public static function mapping_for_product( $product ): ?array {
		if ( ! is_object( $product ) || ! is_callable( array( $product, 'get_id' ) ) ) {
			return null;
		}

		$parent_id = is_callable( array( $product, 'get_parent_id' ) ) ? (int) $product->get_parent_id() : 0;
		if ( $parent_id && is_callable( array( $product, 'is_type' ) ) && $product->is_type( 'variation' ) ) {
			return self::find_mapping( $parent_id, (int) $product->get_id() );
		}

		return self::find_mapping( (int) $product->get_id(), 0 );
	}

	/**
	 * Whether buying this product issues a license.
	 *
	 * @param mixed $product WC_Product.
	 * @return bool
	 */
	public static function is_license_product( $product ): bool {
		return null !== self::mapping_for_product( $product );
	}

	/**
	 * Whether a cart or order line (product ID plus variation ID) issues a license.
	 *
	 * @param int $product_id Parent product ID.
	 * @param int $variation_id Variation ID, or zero.
	 * @return bool
	 */
	public static function is_license_item( int $product_id, int $variation_id = 0 ): bool {
		return null !== self::find_mapping( $product_id, $variation_id );
	}

	/**
	 * Regular price of a product in minor units. The plan price becomes the
	 * renewal amount, so it must be the regular price: get_price() returns the
	 * sale price while a sale is running.
	 *
	 * @param mixed $product WC_Product.
	 * @param int   $decimals Currency decimals.
	 * @return int|null Null when the product has no parseable price.
	 */
	private static function product_price_minor( $product, int $decimals ): ?int {
		$price = is_callable( array( $product, 'get_regular_price' ) ) ? (string) $product->get_regular_price( 'edit' ) : '';
		if ( '' === $price ) {
			$price = (string) $product->get_price( 'edit' );
		}
		$minor = Money::to_minor_units( $price, $decimals );

		return false === $minor ? null : max( 0, (int) $minor );
	}

	/**
	 * Copy the current regular price of the product into its plan.
	 *
	 * Called after every product save (quick edit, bulk edit, REST, import), so
	 * the plan price does not depend on the classic product editor.
	 *
	 * @param int $product_id Parent product ID.
	 * @param int $variation_id Variation ID, or zero.
	 * @return bool True when the plan price was changed.
	 */
	public static function refresh_price( int $product_id, int $variation_id = 0 ): bool {
		global $wpdb;

		$plan = self::find_exact_mapping( $product_id, $variation_id, true );
		if ( ! $plan ) {
			return false;
		}
		$product = wc_get_product( $variation_id ? $variation_id : $product_id );
		if ( ! $product ) {
			return false;
		}
		$decimals    = function_exists( 'wc_get_price_decimals' ) ? max( 0, min( 6, (int) wc_get_price_decimals() ) ) : 2;
		$price_minor = self::product_price_minor( $product, $decimals );
		if ( null === $price_minor || ( (int) $plan['price_minor'] === $price_minor && (int) $plan['price_decimals'] === $decimals ) ) {
			return false;
		}

		$tables  = Schema::table_names();
		$updated = $wpdb->update(
			$tables['plans'],
			array(
				'price_minor' => $price_minor,
				'price_decimals' => $decimals,
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => (int) $plan['id'] ),
			array( '%d', '%d', '%s' ),
			array( '%d' )
		);
		if ( 1 !== $updated ) {
			return false;
		}

		self::audit_change( $plan, self::find_exact_mapping( $product_id, $variation_id, true ) );

		return true;
	}

	/**
	 * Delete one product/variation mapping.
	 *
	 * @param int $product_id Product ID.
	 * @param int $variation_id Variation ID.
	 * @return void
	 */
	public static function delete_mapping( int $product_id, int $variation_id = 0 ): void {
		global $wpdb;

		$before = self::find_exact_mapping( $product_id, $variation_id, true );
		if ( ! $before ) {
			return;
		}

		$tables = Schema::table_names();
		$wpdb->delete(
			$tables['plans'],
			array(
				'woocommerce_product_id' => $product_id,
				'woocommerce_variation_id' => $variation_id,
			),
			array( '%d', '%d' )
		);
		self::flush_product_ids();
		self::audit_change( $before, null );
	}

	/**
	 * Remove every plan of a deleted product or variation.
	 *
	 * @param int $post_id ID of the deleted product or variation.
	 * @return void
	 */
	public static function delete_mappings_of_post( int $post_id ): void {
		global $wpdb;

		if ( $post_id <= 0 ) {
			return;
		}
		$tables = Schema::table_names();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name comes from Schema::table_names().
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tables['plans']} WHERE woocommerce_product_id = %d OR woocommerce_variation_id = %d", $post_id, $post_id ), ARRAY_A );
		foreach ( (array) $rows as $row ) {
			$wpdb->delete( $tables['plans'], array( 'id' => (int) $row['id'] ), array( '%d' ) );
			self::audit_change( $row, null );
		}
		if ( $rows ) {
			self::flush_product_ids();
		}
	}

	/**
	 * Deactivate (trash) or reactivate (untrash) the plans of a product or variation.
	 *
	 * @param int  $post_id Product or variation ID.
	 * @param bool $active New state.
	 * @return void
	 */
	public static function set_active_for_post( int $post_id, bool $active ): void {
		global $wpdb;

		if ( $post_id <= 0 ) {
			return;
		}
		$tables = Schema::table_names();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name comes from Schema::table_names().
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$tables['plans']} SET active = %d, updated_at = %s WHERE ( woocommerce_product_id = %d OR woocommerce_variation_id = %d ) AND active <> %d", $active ? 1 : 0, gmdate( 'Y-m-d H:i:s' ), $post_id, $post_id, $active ? 1 : 0 ) );
		if ( $changed ) {
			self::flush_product_ids();
			AuditLog::record( $active ? 'catalog_plan_reactivated' : 'catalog_plan_deactivated', 'product', $post_id, '', null, array( 'plans' => (int) $changed ), function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0 );
		}
	}

	/**
	 * IDs of all products and variations that issue a license, for display and
	 * for the block checkout rule. Variations of a variable product mapped at
	 * parent level are included.
	 *
	 * The list is a cache of the table `plans` in an autoloaded option: reading it
	 * costs no query. Decisions about money or accounts use find_mapping() instead.
	 *
	 * @return array<int,int>
	 */
	public static function license_product_ids(): array {
		global $wpdb;

		$cached = get_option( self::IDS_OPTION, null );
		if ( is_array( $cached ) ) {
			return array_values( array_map( 'intval', $cached ) );
		}

		$tables = Schema::table_names();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name comes from Schema::table_names().
		$rows    = $wpdb->get_results( "SELECT woocommerce_product_id, woocommerce_variation_id FROM {$tables['plans']} WHERE active = 1", ARRAY_A );
		$ids     = array();
		$parents = array();
		foreach ( (array) $rows as $row ) {
			if ( (int) $row['woocommerce_variation_id'] ) {
				$ids[] = (int) $row['woocommerce_variation_id'];
			} else {
				$ids[]     = (int) $row['woocommerce_product_id'];
				$parents[] = (int) $row['woocommerce_product_id'];
			}
		}
		foreach ( array_chunk( $parents, 200 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one %d per ID.
			$children = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product_variation' AND post_status <> 'trash' AND post_parent IN ({$placeholders})", $chunk ) );
			foreach ( (array) $children as $child_id ) {
				$ids[] = (int) $child_id;
			}
		}
		$ids = array_values( array_unique( array_filter( $ids ) ) );
		sort( $ids );
		update_option( self::IDS_OPTION, $ids, true );

		return $ids;
	}

	/**
	 * Forget the cached ID list; the next reader rebuilds it from the table.
	 *
	 * @return void
	 */
	public static function flush_product_ids(): void {
		if ( function_exists( 'delete_option' ) ) {
			delete_option( self::IDS_OPTION );
		}
	}

	/**
	 * Products and variations whose meta says "license product" but that have no
	 * plan (after a copy that bypassed WooCommerce, for example by a translation
	 * plugin). Customers who buy them get no license. For the diagnostics screen
	 * (the one check behind the row and the health metric `unmapped_license_products`).
	 *
	 * A product counts as mapped when it has an active plan itself or through one
	 * of its variations; a variation also through the plan of its parent.
	 *
	 * @param int $limit Maximum number of IDs.
	 * @return array<int,int>
	 */
	public static function flagged_without_mapping( int $limit = 50 ): array {
		global $wpdb;

		$tables = Schema::table_names();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from Schema::table_names() and $wpdb.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				LEFT JOIN {$tables['plans']} pl ON pl.active = 1 AND (
					( p.post_type = 'product' AND pl.woocommerce_product_id = p.ID )
					OR ( p.post_type = 'product_variation' AND ( pl.woocommerce_variation_id = p.ID OR ( pl.woocommerce_product_id = p.post_parent AND pl.woocommerce_variation_id = 0 ) ) )
				)
				WHERE pm.meta_key = '_wplc_is_license_product' AND pm.meta_value = 'yes'
					AND p.post_type IN ( 'product', 'product_variation' )
					AND p.post_status NOT IN ( 'trash', 'auto-draft' )
					AND pl.id IS NULL
				ORDER BY p.ID ASC
				LIMIT %d",
				max( 1, $limit )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_values( array_map( 'intval', (array) $ids ) );
	}

	/**
	 * Customer-facing name of a license product.
	 *
	 * @param string $product_key License product key.
	 * @return string Empty when the product key is unknown.
	 */
	public static function product_display_name( string $product_key ): string {
		global $wpdb;

		if ( '' === $product_key ) {
			return '';
		}
		$tables = Schema::table_names();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name comes from Schema::table_names().
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT display_name FROM {$tables['products']} WHERE product_key = %s", $product_key ) );
	}

	/**
	 * Cut a string to a column width without splitting a multibyte character.
	 *
	 * @param string $value Value.
	 * @param int    $length Maximum number of characters.
	 * @return string
	 */
	private static function truncate( string $value, int $length ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $length ) : substr( $value, 0, $length );
	}

	/**
	 * Write an audit entry when a plan was created, changed or removed.
	 *
	 * @param array<string,mixed>|null $before Plan row before the change.
	 * @param array<string,mixed>|null $after Plan row after the change.
	 * @return void
	 */
	private static function audit_change( ?array $before, ?array $after ): void {
		$fields = array( 'product_key', 'plan_key', 'woocommerce_product_id', 'woocommerce_variation_id', 'billing_interval', 'price_minor', 'price_decimals', 'currency', 'live_site_limit', 'support_level', 'entitlements_json', 'active' );
		$pick   = static function ( ?array $row ) use ( $fields ): ?array {
			if ( null === $row ) {
				return null;
			}
			$summary = array();
			foreach ( $fields as $field ) {
				$summary[ $field ] = isset( $row[ $field ] ) ? (string) $row[ $field ] : '';
			}
			return $summary;
		};

		$old = $pick( $before );
		$new = $pick( $after );
		if ( $old === $new ) {
			return;
		}

		if ( null === $new ) {
			$action = 'catalog_plan_deleted';
		} elseif ( null === $old ) {
			$action = 'catalog_plan_created';
		} else {
			$action = 'catalog_plan_changed';
		}
		$row = null !== $after ? $after : $before;

		AuditLog::record( $action, 'plan', (int) ( $row['id'] ?? 0 ), '', $old, $new, function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0 );
	}
}
