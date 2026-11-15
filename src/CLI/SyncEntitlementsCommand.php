<?php
/**
 * Bring existing licenses up to the current settings of their plan (A-23).
 *
 * @package WPLC
 */

namespace WPLC\CLI;

use WPLC\Catalog\Catalog;
use WPLC\License\EntitlementSync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `wp wplc sync-entitlements`: opt-in propagation of plan changes.
 */
final class SyncEntitlementsCommand {

	/**
	 * Replace the entitlement snapshot of existing licenses with their plan's current entitlements.
	 *
	 * A license keeps the entitlements of its plan as they were when it was
	 * bought or last changed plan. After a plan gained a feature or a higher
	 * site limit, run this command to give existing licenses the same.
	 * Changes that take something away (a removed feature, a lower limit or
	 * support level) are skipped unless --allow-reductions is given. Only
	 * active and past-due licenses are changed. Each change is audited as
	 * `license_entitlements_synced`; clients see it at their next check.
	 *
	 * Without --apply the command is a dry run. It ends with exit code 1 while
	 * licenses are left that differ from their plan or were not inspected.
	 *
	 * ## OPTIONS
	 *
	 * [--product=<product-key>]
	 * : Only licenses of this product key. Required unless --license is given.
	 *
	 * [--plan=<plan-key>]
	 * : Only licenses of this plan key.
	 *
	 * [--license=<id>]
	 * : Only this license.
	 *
	 * [--apply]
	 * : Write the changes. Without this flag nothing is changed.
	 *
	 * [--allow-reductions]
	 * : Also apply changes that take something away from the customer.
	 *
	 * [--after-id=<number>]
	 * : Start after this license ID (the "last ID" of the previous run). Default 0.
	 *
	 * [--max-rows=<number>]
	 * : Maximum licenses to inspect. Default 0: all.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wplc sync-entitlements --product=voucher-captain-pro --plan=business
	 *     wp wplc sync-entitlements --product=voucher-captain-pro --plan=business --apply
	 *     wp wplc sync-entitlements --license=123 --apply --allow-reductions
	 *
	 * @param array<int,string>   $args       Positional arguments.
	 * @param array<string,mixed> $assoc_args Options.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$product    = isset( $assoc_args['product'] ) ? Catalog::normalize_key( (string) $assoc_args['product'] ) : '';
		$plan       = isset( $assoc_args['plan'] ) ? Catalog::normalize_key( (string) $assoc_args['plan'] ) : '';
		$license_id = isset( $assoc_args['license'] ) ? absint( $assoc_args['license'] ) : 0;
		if ( '' === $product && 0 === $license_id ) {
			\WP_CLI::error( __( 'Name the licenses with --product=<product-key> (optionally --plan=<plan-key>) or --license=<id>.', 'wp-license' ) );
		}
		$apply      = isset( $assoc_args['apply'] );
		$reductions = isset( $assoc_args['allow-reductions'] );
		$last_id    = isset( $assoc_args['after-id'] ) ? absint( $assoc_args['after-id'] ) : 0;
		$max_rows   = isset( $assoc_args['max-rows'] ) ? absint( $assoc_args['max-rows'] ) : 0;
		$counts     = array_fill_keys(
			array(
				EntitlementSync::RESULT_SYNCED,
				EntitlementSync::RESULT_WOULD_SYNC,
				EntitlementSync::RESULT_UNCHANGED,
				EntitlementSync::RESULT_REDUCTION_SKIPPED,
				EntitlementSync::RESULT_NO_PLAN,
				EntitlementSync::RESULT_NOT_ACTIVE,
				EntitlementSync::RESULT_NOT_FOUND,
				EntitlementSync::RESULT_BUSY,
				EntitlementSync::RESULT_FAILED,
			),
			0
		);
		$inspected    = 0;
		$more_pending = false;

		while ( 0 === $max_rows || $inspected < $max_rows ) {
			if ( $license_id ) {
				$ids = array( $license_id );
			} else {
				$ids = EntitlementSync::license_ids( $product, $plan, $last_id, 0 === $max_rows ? 250 : min( 250, $max_rows - $inspected ) );
			}
			if ( ! $ids ) {
				break;
			}
			foreach ( $ids as $id ) {
				$last_id = $id;
				++$inspected;
				$outcome = EntitlementSync::sync( $id, $apply, $reductions );
				++$counts[ $outcome['result'] ];
				if ( null !== $outcome['diff'] && EntitlementSync::RESULT_UNCHANGED !== $outcome['result'] ) {
					\WP_CLI::log(
						sprintf(
							/* translators: 1: license ID, 2: result code, 3: added keys, 4: changed keys, 5: removed keys, 6: keys whose change takes something away. */
							__( 'License %1$d: %2$s; added: %3$s; changed: %4$s; removed: %5$s; reductions: %6$s', 'wp-license' ),
							$id,
							$outcome['result'],
							self::keys( $outcome['diff']['added'] ),
							self::keys( $outcome['diff']['changed'] ),
							self::keys( $outcome['diff']['removed'] ),
							self::keys( $outcome['diff']['reductions'] )
						)
					);
				}
			}
			if ( $license_id ) {
				break;
			}
		}
		if ( ! $license_id && ( 0 !== $max_rows && $inspected >= $max_rows ) ) {
			$more_pending = (bool) EntitlementSync::license_ids( $product, $plan, $last_id, 1 );
		}

		\WP_CLI::log(
			sprintf(
				/* translators: 1: "apply" or "dry-run", 2: inspected licenses, 3: synced, 4: would be synced, 5: unchanged, 6: skipped because of a reduction, 7: without a current plan, 8: not active, 9: busy or failed, 10: last inspected license ID. */
				__( 'Mode: %1$s; inspected: %2$d; synced: %3$d; would sync: %4$d; unchanged: %5$d; skipped (reduction): %6$d; no current plan: %7$d; not active: %8$d; busy or failed: %9$d; last ID: %10$d', 'wp-license' ),
				$apply ? __( 'apply', 'wp-license' ) : __( 'dry-run', 'wp-license' ),
				$inspected,
				$counts[ EntitlementSync::RESULT_SYNCED ],
				$counts[ EntitlementSync::RESULT_WOULD_SYNC ],
				$counts[ EntitlementSync::RESULT_UNCHANGED ],
				$counts[ EntitlementSync::RESULT_REDUCTION_SKIPPED ],
				$counts[ EntitlementSync::RESULT_NO_PLAN ],
				$counts[ EntitlementSync::RESULT_NOT_ACTIVE ] + $counts[ EntitlementSync::RESULT_NOT_FOUND ],
				$counts[ EntitlementSync::RESULT_BUSY ] + $counts[ EntitlementSync::RESULT_FAILED ],
				$last_id
			)
		);
		if ( $counts[ EntitlementSync::RESULT_REDUCTION_SKIPPED ] > 0 ) {
			\WP_CLI::warning( __( 'Some licenses would lose a feature, a limit or a support level and were left unchanged. Review them and repeat with --allow-reductions only if that is intended.', 'wp-license' ) );
		}
		if ( $more_pending ) {
			/* translators: %d: last inspected license ID. */
			\WP_CLI::warning( sprintf( __( 'More licenses follow (--max-rows). Continue with --after-id=%d.', 'wp-license' ), $last_id ) );
		}
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			\WP_CLI::log( __( 'Multisite: this run covered only the tables of the current site. Repeat it for every site with --url=<site>.', 'wp-license' ) );
		}
		$left = $counts[ EntitlementSync::RESULT_WOULD_SYNC ] + $counts[ EntitlementSync::RESULT_REDUCTION_SKIPPED ] + $counts[ EntitlementSync::RESULT_BUSY ] + $counts[ EntitlementSync::RESULT_FAILED ];
		if ( $left > 0 || $more_pending ) {
			\WP_CLI::halt( 1 );
		}
	}

	/**
	 * Comma-separated keys, or "-".
	 *
	 * @param string[] $keys Keys.
	 * @return string
	 */
	private static function keys( array $keys ): string {
		return $keys ? implode( ',', $keys ) : '-';
	}
}
