<?php
/**
 * Read-only view of licenses for other plugins in the shop.
 *
 * @package WPLC
 */

namespace WPLC\Events;

use WPLC\Api\V1\EntitlementService;
use WPLC\Infrastructure\Database\Schema;
use WPLC\License\LicenseKey;
use WPLC\License\LicenseLifecycle;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only facade: status, plan, entitlements and expiry of a license by the
 * public reference an event carries (`WPLC-XXXXXXXX`), and the licenses of a user.
 *
 * Every method runs SELECT queries only and returns public fields only: never
 * a license key or key hash, an email address, payment data, an installation
 * secret or the database ID of a license. Changing a license stays with the
 * plugin's own screens and APIs (capabilities `wplc_*`, nonces); this class
 * offers no way to do it. Part of the event contract (docs/LICENSE-EVENTS.md).
 */
final class LicenseReader {

	/** Columns read from the licenses table (no key hash, no secrets). */
	private const COLUMNS = 'license_uuid, customer_id, product_key, plan_key, status, paid_until, renewal_enabled, cancel_at_period_end';

	/** Most licenses for_user() returns. */
	private const USER_LIMIT = 200;

	/**
	 * Public summary of one license.
	 *
	 * The reference is the eight-character prefix of the license's UUID. When
	 * two licenses share it (rare), the reference is ambiguous and null is
	 * returned unless the customer narrows it down.
	 *
	 * @param string $reference        Public reference, e.g. `WPLC-1A2B3C4D` (case-insensitive).
	 * @param int    $customer_user_id Optional owner to narrow the lookup; zero for any.
	 * @return array{license:string,product_key:string,plan_key:string,status:string,access:bool,expires_at:?string,access_until:?string,customer_user_id:int}|null
	 */
	public static function get( string $reference, int $customer_user_id = 0 ): ?array {
		$row = self::row( $reference, $customer_user_id );

		return null === $row ? null : self::summary( $row );
	}

	/**
	 * Stored status of a license (active, past_due, suspended, expired, revoked, cancelled …).
	 *
	 * @param string $reference        Public reference.
	 * @param int    $customer_user_id Optional owner; zero for any.
	 * @return string|null Null for an unknown or ambiguous reference.
	 */
	public static function status( string $reference, int $customer_user_id = 0 ): ?string {
		$license = self::get( $reference, $customer_user_id );

		return null === $license ? null : $license['status'];
	}

	/**
	 * Product and plan of a license.
	 *
	 * @param string $reference        Public reference.
	 * @param int    $customer_user_id Optional owner; zero for any.
	 * @return array{product_key:string,plan_key:string}|null
	 */
	public static function plan( string $reference, int $customer_user_id = 0 ): ?array {
		$license = self::get( $reference, $customer_user_id );

		return null === $license ? null : array(
			'product_key' => $license['product_key'],
			'plan_key' => $license['plan_key'],
		);
	}

	/**
	 * End of the paid period in UTC (ISO 8601), null for none or an unknown reference.
	 *
	 * @param string $reference        Public reference.
	 * @param int    $customer_user_id Optional owner; zero for any.
	 * @return string|null
	 */
	public static function expires_at( string $reference, int $customer_user_id = 0 ): ?string {
		$license = self::get( $reference, $customer_user_id );

		return null === $license ? null : $license['expires_at'];
	}

	/**
	 * Entitlements the license grants now (key => value), as the license API reports them.
	 *
	 * Empty when the license grants no access (ended, suspended, revoked) or the reference is unknown.
	 *
	 * @param string $reference        Public reference.
	 * @param int    $customer_user_id Optional owner; zero for any.
	 * @return array<string,mixed>
	 */
	public static function entitlements( string $reference, int $customer_user_id = 0 ): array {
		global $wpdb;

		$row = self::row( $reference, $customer_user_id );
		if ( null === $row ) {
			return array();
		}
		$now = time();
		if ( ! LicenseLifecycle::has_access( $row, $now ) ) {
			return array();
		}
		$tables = Schema::table_names();
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT entitlement_key, value_json FROM %i WHERE license_id = %d AND status = %s AND (ends_at IS NULL OR ends_at > %s) ORDER BY entitlement_key',
				$tables['entitlements'],
				(int) $row['id'],
				'active',
				LicenseLifecycle::entitlement_cutoff( $row, $now )
			),
			ARRAY_A
		);
		$entitlements = array();
		foreach ( is_array( $rows ) ? $rows : array() as $entitlement ) {
			$value = json_decode( (string) $entitlement['value_json'], true );
			if ( null !== $value || 'null' === trim( (string) $entitlement['value_json'] ) ) {
				$entitlements[ (string) $entitlement['entitlement_key'] ] = $value;
			}
		}

		return $entitlements;
	}

	/**
	 * Whether the license grants a boolean entitlement now.
	 *
	 * @param string $reference        Public reference.
	 * @param string $entitlement_key  Entitlement key, e.g. `acme_reports.pdf_export`.
	 * @param int    $customer_user_id Optional owner; zero for any.
	 * @return bool
	 */
	public static function is_entitled( string $reference, string $entitlement_key, int $customer_user_id = 0 ): bool {
		$entitlements = self::entitlements( $reference, $customer_user_id );

		return isset( $entitlements[ $entitlement_key ] ) && true === $entitlements[ $entitlement_key ];
	}

	/**
	 * Licenses owned by a WordPress user, newest first (at most 200).
	 *
	 * @param int    $user_id     WordPress user ID.
	 * @param string $product_key Optional product key to filter by.
	 * @return array<int,array{license:string,product_key:string,plan_key:string,status:string,access:bool,expires_at:?string,access_until:?string,customer_user_id:int}>
	 */
	public static function for_user( int $user_id, string $product_key = '' ): array {
		global $wpdb;

		if ( $user_id < 1 ) {
			return array();
		}
		$tables = Schema::table_names();
		$rows   = '' === $product_key
			? $wpdb->get_results(
				$wpdb->prepare( 'SELECT id, ' . self::COLUMNS . ' FROM %i WHERE customer_id = %d AND status <> %s ORDER BY id DESC LIMIT %d', $tables['licenses'], $user_id, 'pending', self::USER_LIMIT ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- COLUMNS is a constant.
				ARRAY_A
			)
			: $wpdb->get_results(
				$wpdb->prepare( 'SELECT id, ' . self::COLUMNS . ' FROM %i WHERE customer_id = %d AND product_key = %s AND status <> %s ORDER BY id DESC LIMIT %d', $tables['licenses'], $user_id, $product_key, 'pending', self::USER_LIMIT ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- COLUMNS is a constant.
				ARRAY_A
			);

		return array_map( array( self::class, 'summary' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Find the single license row of a reference.
	 *
	 * @param string $reference        Public reference.
	 * @param int    $customer_user_id Optional owner; zero for any.
	 * @return array<string,mixed>|null
	 */
	private static function row( string $reference, int $customer_user_id ): ?array {
		global $wpdb;

		$reference = strtoupper( trim( $reference ) );
		if ( ! preg_match( '/^WPLC-[0-9A-F]{8}$/', $reference ) ) {
			return null;
		}
		$tables = Schema::table_names();
		$rows   = $customer_user_id > 0
			? $wpdb->get_results(
				$wpdb->prepare( 'SELECT id, ' . self::COLUMNS . ' FROM %i WHERE key_prefix = %s AND customer_id = %d AND status <> %s LIMIT 2', $tables['licenses'], $reference, $customer_user_id, 'pending' ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- COLUMNS is a constant.
				ARRAY_A
			)
			: $wpdb->get_results(
				$wpdb->prepare( 'SELECT id, ' . self::COLUMNS . ' FROM %i WHERE key_prefix = %s AND status <> %s LIMIT 2', $tables['licenses'], $reference, 'pending' ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- COLUMNS is a constant.
				ARRAY_A
			);
		if ( ! is_array( $rows ) || 1 !== count( $rows ) ) {
			return null;
		}
		$row = $rows[0];
		// key_prefix is stored for display; the reference must also match the UUID it came from.
		if ( LicenseKey::prefix( (string) $row['license_uuid'] ) !== $reference ) {
			return null;
		}

		return $row;
	}

	/**
	 * Public fields of a license row.
	 *
	 * @param array<string,mixed> $row License row.
	 * @return array{license:string,product_key:string,plan_key:string,status:string,access:bool,expires_at:?string,access_until:?string,customer_user_id:int}
	 */
	private static function summary( array $row ): array {
		$access = EntitlementService::access_fields( $row );

		return array(
			'license' => LicenseKey::prefix( (string) $row['license_uuid'] ),
			'product_key' => (string) $row['product_key'],
			'plan_key' => (string) $row['plan_key'],
			'status' => (string) $row['status'],
			'access' => (bool) $access['access'],
			'expires_at' => self::iso( $row['paid_until'] ?? null ),
			'access_until' => self::iso( $access['access_until'] ),
			'customer_user_id' => (int) $row['customer_id'],
		);
	}

	/**
	 * 'Y-m-d H:i:s' (UTC) to ISO 8601.
	 *
	 * @param mixed $value Stored datetime.
	 * @return string|null
	 */
	private static function iso( $value ): ?string {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}
		$time = strtotime( $value . ' UTC' );

		return false === $time ? null : gmdate( 'Y-m-d\TH:i:s\Z', $time );
	}
}
