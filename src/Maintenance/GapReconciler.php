<?php
/**
 * Catches up on shop events the plugin missed.
 *
 * @package WPLC
 */

namespace WPLC\Maintenance;

use WPLC\Catalog\Catalog;
use WPLC\Compatibility\Requirements;
use WPLC\Infrastructure\Database\Schema;
use WPLC\Billing\RenewalProcessor;
use WPLC\License\AuditLog;
use WPLC\License\LicenseIssuer;
use WPLC\License\LicenseLifecycle;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reconciles orders and refunds of the last days with the license data.
 *
 * While the plugin is deactivated (or a hook is lost) WooCommerce keeps
 * selling: orders are paid, renewal orders are paid, orders are refunded.
 * None of those hooks is replayed. This job runs after activation and in the
 * daily run and
 * - issues licenses for paid orders that contain license products without
 *   issued licenses (`LicenseIssuer::maybe_issue_order()`),
 * - books paid renewal orders whose renewal is still open
 *   (`RenewalProcessor::handle_paid_order()`),
 * - processes refunds that were never applied (`LicenseLifecycle::handle_refund()`
 *   and `handle_full_refund()`).
 *
 * Every entry point it calls is idempotent, so a repeated run changes nothing.
 * When it finds something, a {@see GapGuard} window is recorded.
 */
final class GapReconciler {

	/**
	 * Action Scheduler hook of the run after activation.
	 */
	public const HOOK = 'wplc_reconcile_gap';

	/**
	 * Days to look back (filter `wplc_gap_reconcile_days`).
	 */
	public const DEFAULT_DAYS = 30;

	/**
	 * Option that remembers renewal orders already handed to the processor: order ID => unix time.
	 */
	public const HANDLED_OPTION = 'wplc_gap_reconciled_orders';

	/**
	 * Seconds one run may take.
	 */
	private const TIME_BUDGET = 20;

	/**
	 * Orders per query.
	 */
	private const PAGE_SIZE = 50;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( self::HOOK, array( self::class, 'run_scheduled' ), 10, 0 );
		// Before the expiry run (priority 10), so a found gap protects licenses in the same run.
		add_action( DailyRunner::HOOK, array( self::class, 'run_scheduled' ), 5, 0 );
	}

	/**
	 * Scheduled job.
	 *
	 * @return void
	 */
	public static function run_scheduled(): void {
		self::run();
	}

	/**
	 * Order ID of an entry returned by wc_get_orders(), which yields IDs or objects.
	 *
	 * @param mixed $entry ID or order object.
	 * @return int
	 */
	private static function order_id( $entry ): int {
		if ( is_object( $entry ) && is_callable( array( $entry, 'get_id' ) ) ) {
			return (int) $entry->get_id();
		}

		return is_numeric( $entry ) ? (int) $entry : 0;
	}

	/**
	 * Queue a run shortly after activation.
	 *
	 * The activation request itself has no plugin hooks registered (no key
	 * mail, no audit listeners), so the work is done in a later request.
	 *
	 * @return void
	 */
	public static function schedule(): void {
		Requirements::schedule_single_action( time() + 60, self::HOOK, array(), 'wplc' );
	}

	/**
	 * Reconcile the last days.
	 *
	 * @return array{orders_checked:int,orders_issued:int,renewal_orders:int,refunds:int,full_refunds:int,complete:bool}
	 */
	public static function run(): array {
		$summary = array(
			'orders_checked' => 0,
			'orders_issued'  => 0,
			'renewal_orders' => 0,
			'refunds'        => 0,
			'full_refunds'   => 0,
			'complete'       => true,
		);
		if ( ! function_exists( 'wc_get_orders' ) || SiteGuard::billing_paused() ) {
			return $summary;
		}

		/**
		 * Filter how many days of orders and refunds are reconciled.
		 *
		 * @param int $days Default 30.
		 */
		$days     = max( 1, min( 365, (int) apply_filters( 'wplc_gap_reconcile_days', self::DEFAULT_DAYS ) ) );
		$now      = time();
		$since    = $now - $days * DAY_IN_SECONDS;
		$deadline = microtime( true ) + self::TIME_BUDGET;

		$summary = self::reconcile_paid_orders( $since, $now, $deadline, $summary );
		$summary = self::reconcile_refunds( $since, $deadline, $summary );
		$summary = self::reconcile_refunded_orders( $since, $deadline, $summary );

		if ( $summary['orders_issued'] || $summary['renewal_orders'] || $summary['refunds'] || $summary['full_refunds'] || ! $summary['complete'] ) {
			self::log(
				sprintf(
					'Gap reconciliation: %d paid orders checked, licenses issued for %d orders, %d renewal orders handed over, %d refunds and %d full refunds processed%s.',
					$summary['orders_checked'],
					$summary['orders_issued'],
					$summary['renewal_orders'],
					$summary['refunds'],
					$summary['full_refunds'],
					$summary['complete'] ? '' : ' (time budget reached, continues with the next run)'
				)
			);
		}

		return $summary;
	}

	/**
	 * Paid orders without licenses and paid renewal orders with an open renewal.
	 *
	 * @param int                 $since    Unix time of the oldest payment date.
	 * @param int                 $now      Unix time.
	 * @param float               $deadline microtime() at which the run stops.
	 * @param array<string,mixed> $summary  Running summary.
	 * @return array<string,mixed>
	 */
	private static function reconcile_paid_orders( int $since, int $now, float $deadline, array $summary ): array {
		$statuses = array();
		foreach ( (array) wc_get_is_paid_statuses() as $status ) {
			$statuses[] = 'wc-' . $status;
		}
		$handled = get_option( self::HANDLED_OPTION, array() );
		$handled = is_array( $handled ) ? $handled : array();
		$changed = false;

		for ( $page = 1; $page <= 200; ++$page ) {
			$order_ids = wc_get_orders(
				array(
					'type'      => 'shop_order',
					'status'    => $statuses,
					'date_paid' => '>' . $since,
					'limit'     => self::PAGE_SIZE,
					'paged'     => $page,
					'orderby'   => 'ID',
					'order'     => 'ASC',
					'return'    => 'ids',
				)
			);
			if ( ! is_array( $order_ids ) || ! $order_ids ) {
				break;
			}

			foreach ( $order_ids as $order_id ) {
				if ( microtime( true ) >= $deadline ) {
					$summary['complete'] = false;
					break 2;
				}
				$order_id = self::order_id( $order_id );
				$order    = wc_get_order( $order_id );
				if ( ! $order instanceof \WC_Order || ! $order->is_paid() ) {
					continue;
				}
				++$summary['orders_checked'];

				if ( $order->get_meta( '_wplc_renewal_for_license', true ) ) {
					if ( ! isset( $handled[ $order_id ] ) && self::renewal_is_open( absint( $order->get_meta( '_wplc_renewal_id', true ) ) ) ) {
						RenewalProcessor::handle_paid_order( $order_id );
						$handled[ $order_id ] = $now;
						$changed              = true;
						++$summary['renewal_orders'];
						AuditLog::record( 'gap_renewal_order_reconciled', 'order', $order_id, 'Paid renewal order found without a booked renewal; handed to the renewal processor.' );
					}
					continue;
				}

				$missing = self::missing_license_units( $order );
				if ( $missing < 1 ) {
					continue;
				}
				LicenseIssuer::maybe_issue_order( $order_id );
				$still_missing = self::missing_license_units( $order );
				if ( $still_missing < $missing ) {
					++$summary['orders_issued'];
					AuditLog::record(
						'gap_order_reconciled',
						'order',
						$order_id,
						'Paid order found without licenses; licenses were issued afterwards.',
						array( 'missing_units' => $missing ),
						array( 'missing_units' => $still_missing )
					);
					$paid = $order->get_date_paid();
					GapGuard::record( $paid ? (int) $paid->getTimestamp() : $now, $now, 'reconciliation' );
				}
			}

			if ( count( $order_ids ) < self::PAGE_SIZE ) {
				break;
			}
		}

		if ( $changed ) {
			foreach ( $handled as $order_id => $time ) {
				if ( (int) $time < $since ) {
					unset( $handled[ $order_id ] );
				}
			}
			update_option( self::HANDLED_OPTION, array_slice( $handled, -1000, null, true ), false );
		}

		return $summary;
	}

	/**
	 * Number of license units an order should have but does not have.
	 *
	 * @param \WC_Order $order Paid order.
	 * @return int
	 */
	public static function missing_license_units( \WC_Order $order ): int {
		global $wpdb;

		$expected = array();
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$product_id   = (int) $item->get_product_id();
			$variation_id = (int) $item->get_variation_id();
			$mapping      = Catalog::find_mapping( $product_id, $variation_id );
			if ( ! $mapping && $variation_id ) {
				$mapping = Catalog::find_mapping( $product_id, 0 );
			}
			if ( $mapping ) {
				$expected[ (int) $item_id ] = max( 0, (int) $item->get_quantity() );
			}
		}
		if ( ! $expected ) {
			return 0;
		}

		$tables       = Schema::table_names();
		$placeholders = implode( ',', array_fill( 0, count( $expected ), '%d' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- table name and one %d per order item.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source_order_item_id AS item_id, COUNT(*) AS issued FROM {$tables['licenses']} WHERE source_order_item_id IN ({$placeholders}) GROUP BY source_order_item_id",
				array_keys( $expected )
			),
			ARRAY_A
		);
		// phpcs:enable
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			// Unknown: never issue on a failed lookup.
			return 0;
		}
		$issued = array();
		foreach ( $rows as $row ) {
			$issued[ (int) $row['item_id'] ] = (int) $row['issued'];
		}

		$missing = 0;
		foreach ( $expected as $item_id => $quantity ) {
			$missing += max( 0, $quantity - ( isset( $issued[ $item_id ] ) ? $issued[ $item_id ] : 0 ) );
		}

		return $missing;
	}

	/**
	 * Refunds whose units were never revoked.
	 *
	 * @param int                 $since    Unix time of the oldest refund.
	 * @param float               $deadline microtime() at which the run stops.
	 * @param array<string,mixed> $summary  Running summary.
	 * @return array<string,mixed>
	 */
	private static function reconcile_refunds( int $since, float $deadline, array $summary ): array {
		for ( $page = 1; $page <= 200; ++$page ) {
			$refunds = wc_get_orders(
				array(
					'type'         => 'shop_order_refund',
					'date_created' => '>' . $since,
					'limit'        => self::PAGE_SIZE,
					'paged'        => $page,
					'orderby'      => 'ID',
					'order'        => 'ASC',
					'return'       => 'ids',
				)
			);
			if ( ! is_array( $refunds ) || ! $refunds ) {
				break;
			}

			foreach ( $refunds as $refund_id ) {
				if ( microtime( true ) >= $deadline ) {
					$summary['complete'] = false;
					break 2;
				}
				$refund = wc_get_order( self::order_id( $refund_id ) );
				if ( ! $refund instanceof \WC_Order_Refund || $refund->get_meta( '_wplc_refund_processed', true ) ) {
					continue;
				}
				$parent_id = (int) $refund->get_parent_id();
				$parent    = $parent_id ? wc_get_order( $parent_id ) : false;
				if ( ! $parent instanceof \WC_Order || ! self::refund_is_unprocessed( $refund, $parent ) ) {
					continue;
				}
				LicenseLifecycle::handle_refund( $parent_id, (int) $refund->get_id() );
				++$summary['refunds'];
				AuditLog::record( 'gap_refund_reconciled', 'order', $parent_id, 'Refund found that was never applied to the licenses; processed afterwards.', null, array( 'refund_id' => (int) $refund->get_id() ) );
			}

			if ( count( $refunds ) < self::PAGE_SIZE ) {
				break;
			}
		}

		return $summary;
	}

	/**
	 * Whether a refund with line items still has units whose license is not revoked.
	 *
	 * Independent of the "processed" marker on the refund, so a refund that
	 * was applied by an older plugin version is not applied a second time.
	 *
	 * @param \WC_Order_Refund $refund Refund.
	 * @param \WC_Order        $order  Refunded order.
	 * @return bool
	 */
	private static function refund_is_unprocessed( \WC_Order_Refund $refund, \WC_Order $order ): bool {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- only table names are interpolated.
		$tables = Schema::table_names();
		foreach ( $refund->get_items( 'line_item' ) as $refund_item ) {
			$source_item_id = absint( $refund_item->get_meta( '_refunded_item_id', true ) );
			if ( ! $source_item_id || ! absint( $refund_item->get_quantity() ) ) {
				continue;
			}
			$counts = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT COUNT(*) AS total, SUM( CASE WHEN status = %s THEN 1 ELSE 0 END ) AS revoked FROM {$tables['licenses']} WHERE source_order_item_id = %d",
					'revoked',
					$source_item_id
				),
				ARRAY_A
			);
			if ( ! is_array( $counts ) || (int) $counts['total'] < 1 ) {
				continue;
			}
			$refunded_units = min( (int) $counts['total'], absint( $order->get_qty_refunded_for_item( $source_item_id ) ) );
			if ( (int) $counts['revoked'] < $refunded_units ) {
				return true;
			}
		}
		// phpcs:enable

		return false;
	}

	/**
	 * Fully refunded orders whose licenses are still usable.
	 *
	 * @param int                 $since    Unix time of the oldest modification.
	 * @param float               $deadline microtime() at which the run stops.
	 * @param array<string,mixed> $summary  Running summary.
	 * @return array<string,mixed>
	 */
	private static function reconcile_refunded_orders( int $since, float $deadline, array $summary ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- only table names are interpolated.
		$tables = Schema::table_names();

		for ( $page = 1; $page <= 200; ++$page ) {
			$order_ids = wc_get_orders(
				array(
					'type'          => 'shop_order',
					'status'        => array( 'wc-refunded' ),
					'date_modified' => '>' . $since,
					'limit'         => self::PAGE_SIZE,
					'paged'         => $page,
					'orderby'       => 'ID',
					'order'         => 'ASC',
					'return'        => 'ids',
				)
			);
			if ( ! is_array( $order_ids ) || ! $order_ids ) {
				break;
			}

			foreach ( $order_ids as $order_id ) {
				if ( microtime( true ) >= $deadline ) {
					$summary['complete'] = false;
					break 2;
				}
				$order_id = self::order_id( $order_id );
				$order    = wc_get_order( $order_id );
				if ( ! $order instanceof \WC_Order ) {
					continue;
				}

				$renewal_id = absint( $order->get_meta( '_wplc_renewal_id', true ) );
				if ( $renewal_id ) {
					$open = 'paid' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$tables['renewals']} WHERE id = %d", $renewal_id ) );
				} else {
					$open = (int) $wpdb->get_var(
						$wpdb->prepare(
							"SELECT COUNT(*) FROM {$tables['licenses']} WHERE source_order_id = %d AND status IN (%s,%s,%s)",
							$order_id,
							'active',
							'past_due',
							'suspended'
						)
					) > 0;
				}
				if ( ! $open ) {
					continue;
				}
				LicenseLifecycle::handle_full_refund( $order_id );
				++$summary['full_refunds'];
				AuditLog::record( 'gap_full_refund_reconciled', 'order', $order_id, 'Refunded order found whose licenses were still usable; processed afterwards.' );
			}

			if ( count( $order_ids ) < self::PAGE_SIZE ) {
				break;
			}
		}
		// phpcs:enable

		return $summary;
	}

	/**
	 * Whether a renewal row still waits for its payment to be booked.
	 *
	 * @param int $renewal_id Renewal ID.
	 * @return bool
	 */
	private static function renewal_is_open( int $renewal_id ): bool {
		global $wpdb;

		if ( ! $renewal_id ) {
			return false;
		}
		$tables = Schema::table_names();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- only the table name is interpolated.
		$status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$tables['renewals']} WHERE id = %d", $renewal_id ) );

		return is_string( $status ) && ! in_array( $status, array( 'paid', 'refunded', 'cancelled' ), true );
	}

	/**
	 * Write to the WooCommerce log.
	 *
	 * @param string $message Message without personal data.
	 * @return void
	 */
	private static function log( string $message ): void {
		try {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->info( $message, array( 'source' => 'wp-license' ) );
			}
		} catch ( \Throwable $error ) {
			unset( $error );
		}
	}
}
