<?php
/**
 * Read-only view of a license for other plugins in the same WordPress (WFC-LIC-05).
 *
 * @package WPLC
 */

namespace WPLC\License;

use WPLC\Api\V1\ActivationService;
use WPLC\Infrastructure\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The supported way for another plugin on the shop's own site (for example the license adapter
 * of Workflow Captain Pro) to read the state of a license.
 *
 * Read-only: every method issues SELECT queries only and never writes, locks, caches, audits or
 * fires an action. It checks no capability: it returns nothing secret, and the caller decides
 * whom it shows the values to. The internal `get_license()` methods of other classes return the
 * whole row (key hash, key prefix, `license_uuid`, payment references) and are not part of the
 * contract.
 *
 * Never returned: the license key or any part of it (key prefix), the key hash, `license_uuid`,
 * installation IDs, secrets or tokens of activations, site URLs, payment references, email
 * addresses, names. The license row is read by an explicit column list, so those columns are not
 * even loaded. The keys of a result are exactly ReadFacade::KEYS; new keys may be added in a
 * later version, existing ones keep their name and type.
 *
 * @since 0.2.0
 */
final class ReadFacade {

	/**
	 * Keys of a result of get(), in this order.
	 *
	 * @since 0.2.0
	 */
	public const KEYS = array(
		'license_id',
		'product_key',
		'plan_key',
		'status',
		'expires_at',
		'renewed_at',
		'live_site_count',
		'live_site_limit',
		'staging_site_count',
		'development_site_count',
		'development_site_limit',
		'order_id',
		'customer_user_id',
	);

	/**
	 * Most license IDs license_ids_for_customer() returns.
	 */
	public const MAX_LICENSES_PER_CUSTOMER = 200;

	/**
	 * Columns of the licenses table this class reads. Never `*`.
	 */
	private const LICENSE_COLUMNS = 'id, customer_id, product_key, plan_key, status, billing_interval, paid_until, source_order_id, source_order_item_id';

	/**
	 * State of one license.
	 *
	 * Times are UTC in RFC 3339 with `Z` (`2027-01-15T10:00:00Z`).
	 * - `license_id` int; `product_key`, `plan_key` (free text in the server; `single`, `business`
	 *   and `agency` by convention) and `status` (`pending`, `active`, `past_due`, `suspended`,
	 *   `expired`, `revoked`) strings as stored.
	 * - `expires_at` ?string: end of the paid period (`paid_until`); null when the license has none.
	 *   Access may last a grace period longer; that is the license API's business.
	 * - `renewed_at` ?string: when the last paid renewal was recorded; null when the license was
	 *   never renewed.
	 * - `live_site_count`, `staging_site_count`, `development_site_count` int: active activations
	 *   per environment. `live_site_limit` int: live sites the license may activate (each live site
	 *   may have one staging site on top). `development_site_limit` int (filter
	 *   `wplc_development_site_limit`, which receives only the columns this class reads).
	 * - `order_id` ?int: the WooCommerce order that created the license.
	 * - `customer_user_id` ?int: WordPress user ID of the owner; null when the license has none.
	 *
	 * The previous status is not stored on a license; `wplc/license_status_changed` passes it.
	 *
	 * @since 0.2.0
	 *
	 * @param int $license_id License ID (the ID the license actions pass).
	 * @return array{license_id:int,product_key:string,plan_key:string,status:string,expires_at:?string,renewed_at:?string,live_site_count:int,live_site_limit:int,staging_site_count:int,development_site_count:int,development_site_limit:int,order_id:?int,customer_user_id:?int}|null Null for an unknown ID.
	 */
	public static function get( int $license_id ): ?array {
		global $wpdb;

		if ( $license_id <= 0 || ! is_object( $wpdb ) ) {
			return null;
		}
		$tables = Schema::table_names();
		$row    = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT ' . self::LICENSE_COLUMNS . ' FROM %i WHERE id = %d LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- constant column list, values prepared.
				$tables['licenses'],
				$license_id
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) || (int) ( $row['id'] ?? 0 ) !== $license_id ) {
			return null;
		}

		$counts = self::activation_counts( $license_id );
		$limit  = ActivationService::site_limit( $license_id, $row );

		return array(
			'license_id'             => $license_id,
			'product_key'            => (string) $row['product_key'],
			'plan_key'               => (string) $row['plan_key'],
			'status'                 => (string) $row['status'],
			'expires_at'             => self::utc( $row['paid_until'] ?? null ),
			'renewed_at'             => self::utc( self::last_renewal( $license_id ) ),
			'live_site_count'        => $counts['production'],
			'live_site_limit'        => $limit,
			'staging_site_count'     => $counts['staging'],
			'development_site_count' => $counts['development'],
			'development_site_limit' => ActivationService::development_site_limit( $row, $limit ),
			'order_id'               => self::positive( $row['source_order_id'] ?? null ),
			'customer_user_id'       => self::positive( $row['customer_id'] ?? null ),
		);
	}

	/**
	 * IDs of the licenses a WordPress user owns, newest first, at most MAX_LICENSES_PER_CUSTOMER.
	 * Read each with get().
	 *
	 * @since 0.2.0
	 *
	 * @param int $user_id WordPress user ID.
	 * @return int[] Empty for a user without licenses or an invalid ID.
	 */
	public static function license_ids_for_customer( int $user_id ): array {
		global $wpdb;

		if ( $user_id <= 0 || ! is_object( $wpdb ) ) {
			return array();
		}
		$tables = Schema::table_names();
		$ids    = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE customer_id = %d ORDER BY id DESC LIMIT %d',
				$tables['licenses'],
				$user_id,
				self::MAX_LICENSES_PER_CUSTOMER
			)
		);

		return array_values( array_filter( array_map( 'intval', is_array( $ids ) ? $ids : array() ) ) );
	}

	/**
	 * Active activations of a license per environment.
	 *
	 * @param int $license_id License ID.
	 * @return array{production:int,staging:int,development:int}
	 */
	private static function activation_counts( int $license_id ): array {
		global $wpdb;

		$tables = Schema::table_names();
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT environment, COUNT(*) AS activations FROM %i WHERE license_id = %d AND status = %s GROUP BY environment',
				$tables['activations'],
				$license_id,
				'active'
			),
			ARRAY_A
		);
		$counts = array(
			'production'  => 0,
			'staging'     => 0,
			'development' => 0,
		);
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$environment = (string) ( $row['environment'] ?? '' );
			if ( isset( $counts[ $environment ] ) ) {
				$counts[ $environment ] = (int) ( $row['activations'] ?? 0 );
			}
		}

		return $counts;
	}

	/**
	 * When the last paid renewal of a license was recorded.
	 *
	 * @param int $license_id License ID.
	 * @return string|null MySQL datetime (UTC).
	 */
	private static function last_renewal( int $license_id ): ?string {
		global $wpdb;

		$tables = Schema::table_names();
		$value  = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT MAX(updated_at) FROM %i WHERE license_id = %d AND status = %s',
				$tables['renewals'],
				$license_id,
				'paid'
			)
		);

		return null === $value ? null : (string) $value;
	}

	/**
	 * A stored UTC datetime as RFC 3339 with `Z`.
	 *
	 * @param mixed $value MySQL datetime or null.
	 * @return string|null Null for an empty or invalid value.
	 */
	private static function utc( $value ): ?string {
		if ( ! is_string( $value ) || '' === $value || 0 === strpos( $value, '0000-00-00' ) ) {
			return null;
		}
		$time = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );

		return false === $time ? null : $time->format( 'Y-m-d\TH:i:s\Z' );
	}

	/**
	 * A positive integer or null.
	 *
	 * @param mixed $value Value from the database.
	 * @return int|null
	 */
	private static function positive( $value ): ?int {
		$int = is_numeric( $value ) ? (int) $value : 0;

		return $int > 0 ? $int : null;
	}
}
