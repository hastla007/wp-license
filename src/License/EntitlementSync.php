<?php
/**
 * Opt-in propagation of changed plan settings to existing licenses (A-23).
 *
 * @package WPLC
 */

namespace WPLC\License;

use WPLC\Infrastructure\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Brings the entitlement snapshot of existing licenses up to the current
 * settings of their plan.
 *
 * A license keeps the entitlements of its plan as they were at purchase (or at
 * its last plan change). That is deliberate: what a customer bought does not
 * change behind their back. When a plan gains a feature or a higher site
 * limit, the shop decides whether existing licenses get it, and runs this sync
 * (`wp wplc sync-entitlements`). Changes that take something away (a removed
 * feature, a lower limit, a lower support level) are only applied with
 * `$allow_reductions`.
 *
 * The plan of a license is the catalog mapping of the product it renews with
 * (`renewal_product_id`), checked to carry the license's product key, plan key
 * and billing interval. Only active and past-due licenses are synced.
 */
final class EntitlementSync {

	public const RESULT_SYNCED            = 'synced';
	public const RESULT_WOULD_SYNC        = 'would_sync';
	public const RESULT_UNCHANGED         = 'unchanged';
	public const RESULT_REDUCTION_SKIPPED = 'reduction_skipped';
	public const RESULT_NO_PLAN           = 'no_plan';
	public const RESULT_NOT_ACTIVE        = 'not_active';
	public const RESULT_NOT_FOUND         = 'not_found';
	public const RESULT_BUSY              = 'busy';
	public const RESULT_FAILED            = 'failed';

	/** Statuses whose snapshot is synced. */
	private const STATUSES = array( 'active', 'past_due' );

	/** Order of the support levels (higher is more). */
	private const SUPPORT_LEVELS = array(
		'community' => 0,
		'standard'  => 1,
		'priority'  => 2,
	);

	/**
	 * Difference between a stored snapshot and the plan's entitlements.
	 *
	 * @param array<string,mixed> $current Stored snapshot: key => value.
	 * @param array<string,mixed> $target  Plan entitlements: key => value.
	 * @return array{added:string[],changed:string[],removed:string[],reductions:string[]}
	 */
	public static function diff( array $current, array $target ): array {
		$diff = array(
			'added'      => array(),
			'changed'    => array(),
			'removed'    => array(),
			'reductions' => array(),
		);
		foreach ( $target as $key => $value ) {
			$key = (string) $key;
			if ( ! array_key_exists( $key, $current ) ) {
				$diff['added'][] = $key;
				continue;
			}
			if ( self::encode( $current[ $key ] ) !== self::encode( $value ) ) {
				$diff['changed'][] = $key;
				if ( self::reduces( $key, $current[ $key ], $value ) ) {
					$diff['reductions'][] = $key;
				}
			}
		}
		foreach ( $current as $key => $value ) {
			$key = (string) $key;
			if ( ! array_key_exists( $key, $target ) ) {
				$diff['removed'][] = $key;
				if ( self::grants( $value ) ) {
					$diff['reductions'][] = $key;
				}
			}
		}
		foreach ( $diff as $name => $keys ) {
			sort( $keys );
			$diff[ $name ] = $keys;
		}

		return $diff;
	}

	/**
	 * Whether changing one value takes something away from the customer.
	 *
	 * @param string $key Entitlement key.
	 * @param mixed  $old Stored value.
	 * @param mixed  $new Plan value.
	 * @return bool
	 */
	public static function reduces( string $key, $old, $new ): bool {
		if ( ! self::grants( $old ) ) {
			return false;
		}
		if ( 'support.level' === $key && is_string( $old ) && is_string( $new ) && isset( self::SUPPORT_LEVELS[ $old ], self::SUPPORT_LEVELS[ $new ] ) ) {
			return self::SUPPORT_LEVELS[ $new ] < self::SUPPORT_LEVELS[ $old ];
		}
		if ( true === $old ) {
			return true !== $new;
		}
		if ( is_int( $old ) || is_float( $old ) ) {
			return ! ( is_int( $new ) || is_float( $new ) ) || $new < $old;
		}

		// Strings and structured values have no order: any change counts as a reduction.
		return true;
	}

	/**
	 * JSON form of a value for comparisons.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function encode( $value ): string {
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $value ) : json_encode( $value );

		return false === $json ? '' : (string) $json;
	}

	/**
	 * Whether a stored value grants something (see docs/ENTITLEMENTS.md).
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function grants( $value ): bool {
		if ( null === $value || false === $value || '' === $value || array() === $value ) {
			return false;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return $value > 0;
		}

		return true;
	}

	/**
	 * Stored entitlement snapshot of a license.
	 *
	 * @param int $license_id License ID.
	 * @return array<string,mixed>
	 */
	public static function snapshot( int $license_id ): array {
		global $wpdb;

		$tables = Schema::table_names();
		$rows   = $wpdb->get_results(
			$wpdb->prepare( 'SELECT entitlement_key, value_json FROM %i WHERE license_id = %d', $tables['entitlements'], $license_id ),
			ARRAY_A
		);
		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[ (string) $row['entitlement_key'] ] = json_decode( (string) $row['value_json'], true );
		}

		return $out;
	}

	/**
	 * Entitlements of a catalog plan as they would be stored (same filtering as PlanChange::replace_entitlements()).
	 *
	 * @param array<string,mixed> $plan Catalog plan row.
	 * @return array<string,mixed>
	 */
	public static function plan_entitlements( array $plan ): array {
		$decoded = json_decode( (string) ( $plan['entitlements_json'] ?? '' ), true );
		$out     = array();
		foreach ( is_array( $decoded ) ? $decoded : array() as $key => $value ) {
			if ( is_string( $key ) && preg_match( '/^[a-zA-Z0-9_.-]{1,120}$/', $key ) ) {
				$out[ $key ] = $value;
			}
		}

		return $out;
	}

	/**
	 * Current catalog plan of a license: the mapping of its renewal product.
	 *
	 * Licenses without a renewal product (old manual licenses) use the active
	 * plans of the same product key, plan key and billing interval, but only
	 * when all of them grant the same entitlements.
	 *
	 * @param array<string,mixed> $license License row.
	 * @return array<string,mixed>|null
	 */
	public static function plan_for_license( array $license ): ?array {
		global $wpdb;

		$product_id = (int) ( $license['renewal_product_id'] ?? 0 );
		if ( $product_id > 0 ) {
			$target = PlanChange::mapped_target( $license, $product_id, (string) $license['plan_key'] );

			return $target ? $target['plan'] : null;
		}

		$tables = Schema::table_names();
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE active = 1 AND product_key = %s AND plan_key = %s AND billing_interval = %s ORDER BY id ASC LIMIT 50',
				$tables['plans'],
				(string) $license['product_key'],
				(string) $license['plan_key'],
				(string) $license['billing_interval']
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) || ! $rows ) {
			return null;
		}
		$first = self::encode( self::plan_entitlements( $rows[0] ) );
		foreach ( $rows as $row ) {
			if ( self::encode( self::plan_entitlements( $row ) ) !== $first ) {
				return null;
			}
		}

		return $rows[0];
	}

	/**
	 * IDs of licenses a sync run covers, in ID order.
	 *
	 * @param string $product_key Product key, or '' for all.
	 * @param string $plan_key    Plan key, or '' for all.
	 * @param int    $after_id    Start after this license ID.
	 * @param int    $limit       Maximum rows.
	 * @return int[]
	 */
	public static function license_ids( string $product_key, string $plan_key, int $after_id, int $limit ): array {
		global $wpdb;

		$tables = Schema::table_names();
		$ids    = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE status IN (%s, %s) AND ( %s = \'\' OR product_key = %s ) AND ( %s = \'\' OR plan_key = %s ) AND id > %d ORDER BY id ASC LIMIT %d',
				$tables['licenses'],
				self::STATUSES[0],
				self::STATUSES[1],
				$product_key,
				$product_key,
				$plan_key,
				$plan_key,
				max( 0, $after_id ),
				max( 1, min( 1000, $limit ) )
			)
		);

		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * Compare one license with its plan and, with $apply, replace its snapshot.
	 *
	 * @param int  $license_id       License ID.
	 * @param bool $apply            Write the change; otherwise only report it.
	 * @param bool $allow_reductions Also apply changes that take something away.
	 * @param int  $actor_user_id    Acting user (0 for WP-CLI).
	 * @return array{result:string,diff:array{added:string[],changed:string[],removed:string[],reductions:string[]}|null}
	 */
	public static function sync( int $license_id, bool $apply, bool $allow_reductions, int $actor_user_id = 0 ): array {
		if ( ! $apply ) {
			return self::compare( $license_id, false, $allow_reductions, $actor_user_id );
		}
		$outcome = LicenseLifecycle::run_locked(
			$license_id,
			static function () use ( $license_id, $allow_reductions, $actor_user_id ) {
				return self::compare( $license_id, true, $allow_reductions, $actor_user_id );
			}
		);
		if ( ! is_array( $outcome ) ) {
			return array( 'result' => self::RESULT_BUSY, 'diff' => null );
		}
		if ( self::RESULT_SYNCED === $outcome['result'] ) {
			/**
			 * Fires after the entitlement snapshot of a license was brought up to its plan's current settings.
			 *
			 * @param int $license_id License ID.
			 */
			do_action( 'wplc/license_entitlements_synced', $license_id );
		}

		return $outcome;
	}

	/**
	 * Body of sync(); with $apply the caller holds the license lock.
	 *
	 * @param int  $license_id       License ID.
	 * @param bool $apply            Write the change.
	 * @param bool $allow_reductions Also apply reductions.
	 * @param int  $actor_user_id    Acting user.
	 * @return array{result:string,diff:array{added:string[],changed:string[],removed:string[],reductions:string[]}|null}
	 */
	private static function compare( int $license_id, bool $apply, bool $allow_reductions, int $actor_user_id ): array {
		$license = PlanChange::get_license( $license_id );
		if ( ! $license ) {
			return array( 'result' => self::RESULT_NOT_FOUND, 'diff' => null );
		}
		if ( ! in_array( (string) $license['status'], self::STATUSES, true ) ) {
			return array( 'result' => self::RESULT_NOT_ACTIVE, 'diff' => null );
		}
		$plan = self::plan_for_license( $license );
		if ( ! $plan ) {
			return array( 'result' => self::RESULT_NO_PLAN, 'diff' => null );
		}
		$current = self::snapshot( $license_id );
		$target  = self::plan_entitlements( $plan );
		$diff    = self::diff( $current, $target );
		if ( ! $diff['added'] && ! $diff['changed'] && ! $diff['removed'] ) {
			return array( 'result' => self::RESULT_UNCHANGED, 'diff' => $diff );
		}
		if ( $diff['reductions'] && ! $allow_reductions ) {
			return array( 'result' => self::RESULT_REDUCTION_SKIPPED, 'diff' => $diff );
		}
		if ( ! $apply ) {
			return array( 'result' => self::RESULT_WOULD_SYNC, 'diff' => $diff );
		}
		if ( ! PlanChange::replace_entitlements( $license, $plan, gmdate( 'Y-m-d H:i:s' ) ) ) {
			return array( 'result' => self::RESULT_FAILED, 'diff' => $diff );
		}

		$changed_keys = array_merge( $diff['added'], $diff['changed'], $diff['removed'] );
		AuditLog::record(
			'license_entitlements_synced',
			'license',
			$license_id,
			AuditReason::ENTITLEMENTS_SYNCED_FROM_PLAN,
			array_intersect_key( $current, array_flip( $changed_keys ) ),
			array_intersect_key( $target, array_flip( $changed_keys ) ),
			$actor_user_id
		);

		return array( 'result' => self::RESULT_SYNCED, 'diff' => $diff );
	}
}
