<?php
/**
 * Plan change of an existing license (T-110).
 *
 * @package WPLC
 */

namespace WPLC\License;

use WPLC\Billing\Money;
use WPLC\Billing\RenewalOrder;
use WPLC\Billing\RenewalProcessor;
use WPLC\Billing\RenewalScheduler;
use WPLC\Catalog\Catalog;
use WPLC\Infrastructure\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core service shared by the customer upgrade, the downgrade at the next
 * renewal and the administrator's plan change.
 *
 * A plan change keeps the license, its key, its paid period and its site
 * activations. It replaces the plan key, the entitlement snapshot (including
 * the live site limit) and the product and amount used for future renewals.
 *
 * Plans are WooCommerce products or variations mapped in the catalog. A
 * license can change to another mapped plan of the same product key and the
 * same billing interval. A target is identified by the ID of its product or
 * variation, because catalog rows are replaced (new IDs) when a mapping is
 * saved again.
 */
final class PlanChange {

	public const KIND_UPGRADE   = 'upgrade';
	public const KIND_DOWNGRADE = 'downgrade';

	/**
	 * Start of the period of one billing interval that ends at $paid_until. Pure.
	 *
	 * Inverse of RenewalScheduler::period_end() with the same end-of-month
	 * clamping (31 March minus one month is 28/29 February).
	 *
	 * @param int    $paid_until Unix timestamp.
	 * @param string $interval   'month'; anything else is a year.
	 * @return int
	 */
	public static function period_start_before( int $paid_until, string $interval ): int {
		$date   = ( new \DateTimeImmutable( '@' . $paid_until ) )->setTimezone( new \DateTimeZone( 'UTC' ) );
		$day    = (int) $date->format( 'j' );
		$months = 'month' === $interval ? 1 : 12;
		$first  = $date->modify( 'first day of this month' )->modify( '-' . $months . ' months' );

		return $first->setDate(
			(int) $first->format( 'Y' ),
			(int) $first->format( 'n' ),
			min( $day, (int) $first->format( 't' ) )
		)->setTime(
			(int) $date->format( 'G' ),
			(int) $date->format( 'i' ),
			(int) $date->format( 's' )
		)->getTimestamp();
	}

	/**
	 * Prorated price of an upgrade for the rest of the paid period (T-109). Pure.
	 *
	 * (new price − old price) × remaining time / period length, in integer
	 * minor units, rounded half up. Never negative: a cheaper or equal target
	 * costs nothing now (and is no upgrade).
	 *
	 * @param int    $old_minor  Regular price of the current plan for one period.
	 * @param int    $new_minor  Regular price of the target plan for one period.
	 * @param int    $now        Unix timestamp of the quote.
	 * @param int    $paid_until End of the paid period (Unix timestamp).
	 * @param string $interval   Billing interval of both plans.
	 * @return int|false False on arithmetic overflow.
	 */
	public static function upgrade_amount( int $old_minor, int $new_minor, int $now, int $paid_until, string $interval ) {
		$difference = $new_minor - max( 0, $old_minor );
		$remaining  = $paid_until - $now;
		if ( $difference <= 0 || $remaining <= 0 ) {
			return 0;
		}
		$total = $paid_until - self::period_start_before( $paid_until, $interval );

		return Money::prorate( $difference, $remaining, max( 1, $total ) );
	}

	/**
	 * Renewal amount stored after a plan change. Pure.
	 *
	 * An automatic renewal charges the stored amount off-session. The customer
	 * agreed to that amount only, so it may rise only with a recorded consent
	 * (the customer's upgrade checkout). Without consent a more expensive plan
	 * keeps the old amount; a cheaper one lowers it.
	 *
	 * @param int  $current   Stored renewal amount.
	 * @param int  $target    Regular price of the target plan.
	 * @param bool $automatic Whether the license renews automatically.
	 * @param bool $consented Whether the customer agreed to the target amount.
	 * @return int
	 */
	public static function next_renewal_amount( int $current, int $target, bool $automatic, bool $consented ): int {
		if ( $target <= 0 ) {
			return max( 0, $current );
		}
		if ( ! $automatic || $consented || $current <= 0 ) {
			return $target;
		}

		return min( $current, $target );
	}

	/**
	 * Upgrade or downgrade, by the regular prices of both plans. Pure.
	 *
	 * The catalog has no ranking of plans; a more expensive plan is the higher
	 * one. An equally priced plan counts as a downgrade (no charge, takes
	 * effect at the next renewal).
	 *
	 * @param int $current_minor Current plan price.
	 * @param int $target_minor  Target plan price.
	 * @return string KIND_* constant.
	 */
	public static function kind( int $current_minor, int $target_minor ): string {
		return $target_minor > $current_minor ? self::KIND_UPGRADE : self::KIND_DOWNGRADE;
	}

	/**
	 * Current regular price of the license's plan for one period.
	 *
	 * The regular price of the purchased product or variation; the stored
	 * renewal amount when that product is gone or has no price.
	 *
	 * @param array<string,mixed> $license License row.
	 * @return array{amount_minor:int,decimals:int,currency:string}|null
	 */
	public static function current_price( array $license ): ?array {
		$product = RenewalOrder::purchased_product( $license );
		$price   = $product ? Catalog::product_regular_price( $product ) : null;
		if ( $price ) {
			return $price;
		}
		if ( (int) $license['renewal_amount_minor'] > 0 ) {
			return array(
				'amount_minor' => (int) $license['renewal_amount_minor'],
				'decimals' => (int) $license['renewal_currency_decimals'],
				'currency' => strtoupper( (string) $license['renewal_currency'] ),
			);
		}

		return null;
	}

	/**
	 * Plans the license can change to, cheapest first.
	 *
	 * Active catalog mappings of the same product key and billing interval
	 * with another plan key, on a simple product or a variation that has a
	 * regular price in the license's currency (a mapping on a variable parent
	 * has no price of its own and is skipped).
	 *
	 * @param array<string,mixed> $license License row.
	 * @param bool                $purchasable_only Only products customers can buy (customer view).
	 * @return array<int,array{product_id:int,product:\WC_Product,plan:array<string,mixed>,price:array{amount_minor:int,decimals:int,currency:string},kind:string}>
	 */
	public static function targets( array $license, bool $purchasable_only = true ): array {
		global $wpdb;

		if ( ! function_exists( 'wc_get_product' ) ) {
			return array();
		}
		$tables = Schema::table_names();
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$tables['plans']} WHERE active = 1 AND product_key = %s AND plan_key <> %s AND billing_interval = %s ORDER BY price_minor ASC, id ASC LIMIT 100", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names(), values prepared.
				(string) $license['product_key'],
				(string) $license['plan_key'],
				(string) $license['billing_interval']
			),
			ARRAY_A
		);
		$current   = self::current_price( $license );
		$current_m = $current ? (int) $current['amount_minor'] : 0;
		$targets   = array();
		foreach ( is_array( $rows ) ? $rows : array() as $plan ) {
			$product_id = (int) $plan['woocommerce_variation_id'] ? (int) $plan['woocommerce_variation_id'] : (int) $plan['woocommerce_product_id'];
			$product    = wc_get_product( $product_id );
			if ( ! $product instanceof \WC_Product || ( $purchasable_only && ! $product->is_purchasable() ) ) {
				continue;
			}
			$price = Catalog::product_regular_price( $product );
			if ( ! $price || ! self::same_money( $price, $license ) ) {
				continue;
			}
			$targets[ $product_id ] = array(
				'product_id' => $product_id,
				'product' => $product,
				'plan' => $plan,
				'price' => $price,
				'kind' => self::kind( $current_m, (int) $price['amount_minor'] ),
			);
		}
		uasort(
			$targets,
			static function ( array $a, array $b ): int {
				return $a['price']['amount_minor'] <=> $b['price']['amount_minor'];
			}
		);

		return $targets;
	}

	/**
	 * One target of targets() by its product or variation ID.
	 *
	 * @param array<string,mixed> $license License row.
	 * @param int                 $product_id Product or variation ID.
	 * @param bool                $purchasable_only See targets().
	 * @return array{product_id:int,product:\WC_Product,plan:array<string,mixed>,price:array{amount_minor:int,decimals:int,currency:string},kind:string}|null
	 */
	public static function resolve_target( array $license, int $product_id, bool $purchasable_only = true ): ?array {
		$targets = self::targets( $license, $purchasable_only );

		return $targets[ $product_id ] ?? null;
	}

	/**
	 * The catalog mapping of one product or variation, if it is a plan of the
	 * license's product key and billing interval with the given plan key.
	 *
	 * Used to re-check a target stored earlier (pending plan, upgrade order).
	 *
	 * @param array<string,mixed> $license License row.
	 * @param int                 $product_id Product or variation ID.
	 * @param string              $plan_key Expected plan key.
	 * @return array{product_id:int,product:\WC_Product,plan:array<string,mixed>,price:array{amount_minor:int,decimals:int,currency:string}|null}|null
	 */
	public static function mapped_target( array $license, int $product_id, string $plan_key ): ?array {
		if ( $product_id <= 0 || '' === $plan_key || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof \WC_Product ) {
			return null;
		}
		$parent  = (int) $product->get_parent_id();
		$mapping = $parent ? Catalog::find_mapping( $parent, $product_id ) : Catalog::find_mapping( $product_id, 0 );
		if (
			! $mapping
			|| (string) $mapping['product_key'] !== (string) $license['product_key']
			|| (string) $mapping['plan_key'] !== $plan_key
			|| (string) $mapping['billing_interval'] !== (string) $license['billing_interval']
		) {
			return null;
		}
		$price = Catalog::product_regular_price( $product );

		return array(
			'product_id' => $product_id,
			'product' => $product,
			'plan' => $mapping,
			'price' => $price && self::same_money( $price, $license ) ? $price : null,
		);
	}

	/**
	 * The plan change scheduled for the next renewal, if it is still valid.
	 *
	 * @param array<string,mixed> $license License row.
	 * @return array{product_id:int,product:\WC_Product,plan:array<string,mixed>,price:array{amount_minor:int,decimals:int,currency:string}|null}|null
	 */
	public static function pending_target( array $license ): ?array {
		if ( empty( $license['pending_product_id'] ) || empty( $license['pending_plan_key'] ) ) {
			return null;
		}

		return self::mapped_target( $license, (int) $license['pending_product_id'], (string) $license['pending_plan_key'] );
	}

	/**
	 * Schedule a plan change for the next renewal (downgrade).
	 *
	 * The license keeps its plan until a renewal for the pending plan is paid;
	 * that renewal is priced and billed with the pending plan's product (see
	 * RenewalOrder::price()). Customers may only schedule a cheaper or equally
	 * priced plan; administrators any plan of the product.
	 *
	 * @param int    $license_id License ID.
	 * @param int    $product_id Target product or variation ID.
	 * @param int    $actor_user_id Acting user.
	 * @param string $reason Audit reason (administrator's text or a code).
	 * @param bool   $by_admin Administrator action (no price restriction).
	 * @return string Result code: plan_change_scheduled, plan_change_unavailable or plan_change_busy.
	 */
	public static function schedule_pending( int $license_id, int $product_id, int $actor_user_id, string $reason, bool $by_admin ): string {
		$result = self::with_locks(
			$license_id,
			static function ( array $license, int $renewal_id ) use ( $product_id, $actor_user_id, $reason, $by_admin ) {
				global $wpdb;

				$target = self::resolve_target( $license, $product_id, ! $by_admin );
				if (
					! $target
					|| ! in_array( (string) $license['status'], array( 'active', 'past_due' ), true )
					|| ( ! $by_admin && self::KIND_DOWNGRADE !== $target['kind'] )
				) {
					return 'plan_change_unavailable';
				}
				$tables  = Schema::table_names();
				$updated = $wpdb->update(
					$tables['licenses'],
					array(
						'pending_plan_key' => (string) $target['plan']['plan_key'],
						'pending_product_id' => $product_id,
						'updated_at' => gmdate( 'Y-m-d H:i:s' ),
					),
					array( 'id' => (int) $license['id'], 'plan_key' => (string) $license['plan_key'] ),
					array( '%s', '%d', '%s' ),
					array( '%d', '%s' )
				);
				if ( 1 !== $updated ) {
					return 'plan_change_unavailable';
				}
				AuditLog::record(
					'plan_change_scheduled',
					'license',
					(int) $license['id'],
					$reason,
					array( 'plan_key' => (string) $license['plan_key'], 'pending_plan_key' => (string) $license['pending_plan_key'] ),
					array( 'pending_plan_key' => (string) $target['plan']['plan_key'], 'pending_product_id' => $product_id, 'price_minor' => (int) $target['price']['amount_minor'] ),
					$actor_user_id
				);
				$license['pending_plan_key']   = (string) $target['plan']['plan_key'];
				$license['pending_product_id'] = $product_id;
				self::reprice_open_renewal_order( $license, $renewal_id );

				return 'plan_change_scheduled';
			}
		);

		return is_string( $result ) ? $result : 'plan_change_busy';
	}

	/**
	 * Withdraw a plan change scheduled for the next renewal.
	 *
	 * @param int    $license_id License ID.
	 * @param int    $actor_user_id Acting user.
	 * @param string $reason Audit reason.
	 * @return bool True when a pending change was withdrawn.
	 */
	public static function cancel_pending( int $license_id, int $actor_user_id, string $reason ): bool {
		$result = self::with_locks(
			$license_id,
			static function ( array $license, int $renewal_id ) use ( $actor_user_id, $reason ) {
				if ( empty( $license['pending_product_id'] ) && '' === (string) $license['pending_plan_key'] ) {
					return false;
				}
				if ( ! self::clear_pending( $license, 'plan_change_cancelled', $reason, $actor_user_id ) ) {
					return false;
				}
				$license['pending_plan_key']   = '';
				$license['pending_product_id'] = 0;
				self::reprice_open_renewal_order( $license, $renewal_id );

				return true;
			}
		);

		return true === $result;
	}

	/**
	 * Change the plan right away without a payment (administrator).
	 *
	 * The paid period stays. Future renewals use the target product; the
	 * stored automatic amount rises only with the customer's consent, so for a
	 * more expensive plan on a license with automatic renewal it stays as it
	 * is (see next_renewal_amount()).
	 *
	 * @param int    $license_id License ID.
	 * @param int    $product_id Target product or variation ID.
	 * @param string $reason Administrator's reason.
	 * @param int    $actor_user_id Administrator.
	 * @return string Result code: plan_changed, plan_change_unavailable or plan_change_busy.
	 */
	public static function admin_change_now( int $license_id, int $product_id, string $reason, int $actor_user_id ): string {
		if ( '' === trim( $reason ) ) {
			return 'plan_change_unavailable';
		}
		$result = self::with_locks(
			$license_id,
			static function ( array $license, int $renewal_id ) use ( $product_id, $reason, $actor_user_id ) {
				$target = self::resolve_target( $license, $product_id, false );
				if ( ! $target || ! in_array( (string) $license['status'], array( 'active', 'past_due' ), true ) ) {
					return 'plan_change_unavailable';
				}
				$amount = array(
					'amount_minor' => self::next_renewal_amount( (int) $license['renewal_amount_minor'], (int) $target['price']['amount_minor'], ! empty( $license['renewal_enabled'] ), false ),
					'decimals' => (int) $target['price']['decimals'],
					'currency' => (string) $target['price']['currency'],
				);
				if ( ! self::switch_locked( $license, $target, $amount, 0, 'admin_plan_change', $reason, $actor_user_id ) ) {
					return 'plan_change_unavailable';
				}
				$changed = self::get_license( (int) $license['id'] );
				if ( $changed ) {
					self::reprice_open_renewal_order( $changed, $renewal_id );
				}

				return 'plan_changed';
			}
		);
		if ( 'plan_changed' === $result ) {
			self::fire_changed( $license_id );
		}

		return is_string( $result ) ? $result : 'plan_change_busy';
	}

	/**
	 * Apply a pending plan change after a renewal was booked.
	 *
	 * Called by every path that books a paid renewal, after the license was
	 * extended, while the caller holds the per-renewal lock. The change applies
	 * only when the paid order was billed with the pending plan's product: an
	 * order priced before the change was scheduled renews the current plan,
	 * and the change waits for the next renewal.
	 *
	 * @param int       $license_id License ID.
	 * @param \WC_Order $order Paid renewal order.
	 * @return bool True when the plan was changed.
	 */
	public static function apply_pending_after_renewal( int $license_id, $order ): bool {
		$applied = LicenseLifecycle::run_locked(
			$license_id,
			static function () use ( $license_id, $order ) {
				$license = self::get_license( $license_id );
				if ( ! $license || empty( $license['pending_product_id'] ) ) {
					return false;
				}
				$target = self::pending_target( $license );
				if ( ! $target ) {
					// The mapping was removed or changed: the change can never be billed.
					self::clear_pending( $license, 'plan_change_dropped', AuditReason::PLAN_CHANGE_TARGET_UNAVAILABLE, 0 );
					return false;
				}
				if ( ! self::order_bills_product( $order, (int) $target['product_id'] ) ) {
					return false;
				}
				$target_minor = $target['price'] ? (int) $target['price']['amount_minor'] : 0;
				$amount       = array(
					'amount_minor' => self::next_renewal_amount( (int) $license['renewal_amount_minor'], $target_minor, ! empty( $license['renewal_enabled'] ), false ),
					'decimals' => (int) $license['renewal_currency_decimals'],
					'currency' => (string) $license['renewal_currency'],
				);

				return self::switch_locked(
					$license,
					$target,
					$amount,
					0,
					'plan_changed_at_renewal',
					AuditReason::encode( AuditReason::PLAN_CHANGED_AT_RENEWAL, array( 'order' => (int) $order->get_id() ) ),
					0
				);
			}
		);
		if ( true === $applied ) {
			self::fire_changed( $license_id );
			return true;
		}

		return false;
	}

	/**
	 * Switch the plan of a license. The caller holds the license lock (and the
	 * renewal lock of the next period first, if it touches that renewal).
	 *
	 * Claims the change with a conditional update on the values read by the
	 * caller (plan, product, paid period, upgrade order), so a duplicate or
	 * concurrent call changes nothing. Then replaces the entitlement snapshot
	 * (upsert of the target's keys, removal of the others; no transaction, the
	 * WordPress test suite and other plugins may hold one); entitlements keep
	 * the license's paid period as end date.
	 *
	 * @param array<string,mixed> $license License row read under the lock.
	 * @param array<string,mixed> $target Target: product_id and plan (catalog row).
	 * @param array{amount_minor:int,decimals:int,currency:string} $amount Renewal amount for future periods.
	 * @param int                 $plan_order_id Upgrade order that pays for the new plan, zero for none.
	 * @param string              $action Audit action.
	 * @param string              $reason Audit reason.
	 * @param int                 $actor_user_id Acting user, zero for the system.
	 * @return bool
	 */
	public static function switch_locked( array $license, array $target, array $amount, int $plan_order_id, string $action, string $reason, int $actor_user_id ): bool {
		global $wpdb;

		$plan       = (array) $target['plan'];
		$product_id = (int) $target['product_id'];
		$license_id = (int) $license['id'];
		$tables     = Schema::table_names();
		$now        = gmdate( 'Y-m-d H:i:s' );
		$paid_until = empty( $license['paid_until'] ) ? null : (string) $license['paid_until'];
		if ( $product_id <= 0 || empty( $plan['plan_key'] ) || (string) $plan['product_key'] !== (string) $license['product_key'] ) {
			return false;
		}
		$before_limit = self::site_limit_of( $license_id );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from Schema::table_names(), values prepared.
		$claimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$tables['licenses']} SET plan_key = %s, renewal_amount_minor = %d, renewal_currency_decimals = %d, renewal_currency = %s, renewal_product_id = %d, pending_plan_key = '', pending_product_id = 0, plan_order_id = %d, updated_at = %s"
				. " WHERE id = %d AND plan_key = %s AND renewal_product_id = %d AND plan_order_id = %d AND COALESCE( paid_until, '' ) = %s",
				(string) $plan['plan_key'],
				max( 0, (int) $amount['amount_minor'] ),
				max( 0, min( 6, (int) $amount['decimals'] ) ),
				strtoupper( (string) $amount['currency'] ),
				$product_id,
				max( 0, $plan_order_id ),
				$now,
				$license_id,
				(string) $license['plan_key'],
				(int) $license['renewal_product_id'],
				(int) $license['plan_order_id'],
				null === $paid_until ? '' : $paid_until
			)
		);
		// phpcs:enable
		if ( 1 !== (int) $claimed ) {
			return false;
		}
		$entitlements_updated = self::replace_entitlements( $license, $plan, $now );
		if ( ! $entitlements_updated && function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error( sprintf( 'Plan of license %d changed, but its entitlements could not be replaced completely.', $license_id ), array( 'source' => 'wp-license' ) );
		}

		$entitlements = json_decode( (string) $plan['entitlements_json'], true );
		AuditLog::record(
			$action,
			'license',
			$license_id,
			$reason,
			array(
				'plan_key' => (string) $license['plan_key'],
				'renewal_product_id' => (int) $license['renewal_product_id'],
				'renewal_amount_minor' => (int) $license['renewal_amount_minor'],
				'site_limit' => $before_limit,
				'pending_plan_key' => (string) $license['pending_plan_key'],
			),
			array(
				'plan_key' => (string) $plan['plan_key'],
				'renewal_product_id' => $product_id,
				'renewal_amount_minor' => (int) $amount['amount_minor'],
				'site_limit' => is_array( $entitlements ) && isset( $entitlements['sites.live_limit'] ) ? (int) $entitlements['sites.live_limit'] : null,
				'paid_until' => $paid_until,
				'order_id' => $plan_order_id,
				'entitlements_updated' => $entitlements_updated,
			),
			$actor_user_id
		);

		return true;
	}

	/**
	 * Re-price an unpaid manual renewal order of the next period after the
	 * plan or the pending plan changed, so the customer pays the right amount
	 * for the right plan. The caller holds that renewal's lock.
	 *
	 * Orders that are paid or wait for an offline payment (on-hold) are not
	 * touched (RenewalOrder::get_or_create() never changes them). Automatic
	 * orders keep their parameters for retries; an active license has none.
	 *
	 * @param array<string,mixed> $license License row after the change.
	 * @param int                 $renewal_id Renewal of the period starting at paid_until, zero for none.
	 * @return void
	 */
	public static function reprice_open_renewal_order( array $license, int $renewal_id ): void {
		global $wpdb;

		if ( ! $renewal_id || ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$tables  = Schema::table_names();
		$renewal = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['renewals']} WHERE id = %d LIMIT 1", $renewal_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names(), values prepared.
		if ( ! is_array( $renewal ) || 'manual' !== (string) $renewal['status'] || empty( $renewal['renewal_order_id'] ) ) {
			return;
		}
		$order = wc_get_order( (int) $renewal['renewal_order_id'] );
		if ( ! $order instanceof \WC_Order || ! RenewalOrder::is_manual( $order ) || ! $order->needs_payment() || 'on-hold' === $order->get_status() ) {
			return;
		}
		RenewalOrder::get_or_create( $renewal, $license, true );
	}

	/**
	 * Run a plan change under the renewal lock of the next period (if that
	 * renewal exists) and then the license lock.
	 *
	 * @param int      $license_id License ID.
	 * @param callable $callback Receives the license row read under the locks and the renewal ID (0 for none).
	 * @return mixed The callback result; false when a lock was busy or the license is missing.
	 */
	public static function with_locks( int $license_id, callable $callback ) {
		$license = self::get_license( $license_id );
		if ( ! $license ) {
			return false;
		}
		$renewal_id = self::next_renewal_id( $license );
		if ( $renewal_id && ! RenewalProcessor::lock( $renewal_id ) ) {
			return false;
		}
		try {
			return LicenseLifecycle::run_locked(
				$license_id,
				static function () use ( $license_id, $renewal_id, $callback ) {
					$license = self::get_license( $license_id );
					if ( ! $license || self::next_renewal_id( $license ) !== $renewal_id ) {
						// The period moved between the two reads; the caller may retry.
						return false;
					}
					return $callback( $license, $renewal_id );
				}
			);
		} finally {
			if ( $renewal_id ) {
				RenewalProcessor::unlock( $renewal_id );
			}
		}
	}

	/**
	 * Renewal row of the period that starts at the license's paid_until (not created).
	 *
	 * @param array<string,mixed> $license License row.
	 * @return int Renewal ID or zero.
	 */
	public static function next_renewal_id( array $license ): int {
		global $wpdb;

		if ( empty( $license['paid_until'] ) ) {
			return 0;
		}
		$tables = Schema::table_names();

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$tables['renewals']} WHERE idempotency_key = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names(), values prepared.
				RenewalScheduler::idempotency_key( (int) $license['id'], (string) $license['paid_until'] )
			)
		);
	}

	/**
	 * Live site limit from the license's entitlement snapshot.
	 *
	 * @param int $license_id License ID.
	 * @return int|null
	 */
	public static function site_limit_of( int $license_id ): ?int {
		global $wpdb;

		$tables = Schema::table_names();
		$value  = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT value_json FROM {$tables['entitlements']} WHERE license_id = %d AND entitlement_key = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names(), values prepared.
				$license_id,
				'sites.live_limit'
			)
		);
		$value = null === $value ? null : json_decode( (string) $value, true );

		return is_numeric( $value ) ? (int) $value : null;
	}

	/**
	 * Live site limit of a catalog plan.
	 *
	 * @param array<string,mixed> $plan Catalog plan row.
	 * @return int|null
	 */
	public static function site_limit_from_plan( array $plan ): ?int {
		$entitlements = json_decode( (string) ( $plan['entitlements_json'] ?? '' ), true );
		if ( is_array( $entitlements ) && isset( $entitlements['sites.live_limit'] ) && is_numeric( $entitlements['sites.live_limit'] ) ) {
			return (int) $entitlements['sites.live_limit'];
		}

		return isset( $plan['live_site_limit'] ) ? (int) $plan['live_site_limit'] : null;
	}

	/**
	 * Whether an order has a line for the product or variation.
	 *
	 * @param \WC_Order $order Order.
	 * @param int       $product_id Product or variation ID.
	 * @return bool
	 */
	public static function order_bills_product( $order, int $product_id ): bool {
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$billed = (int) $item->get_variation_id() ? (int) $item->get_variation_id() : (int) $item->get_product_id();
			if ( $billed === $product_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Remove a pending plan change and record why. Caller holds the license lock.
	 *
	 * @param array<string,mixed> $license License row.
	 * @param string              $action Audit action.
	 * @param string              $reason Audit reason.
	 * @param int                 $actor_user_id Acting user.
	 * @return bool
	 */
	private static function clear_pending( array $license, string $action, string $reason, int $actor_user_id ): bool {
		global $wpdb;

		$tables  = Schema::table_names();
		$updated = $wpdb->update(
			$tables['licenses'],
			array( 'pending_plan_key' => '', 'pending_product_id' => 0, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ),
			array( 'id' => (int) $license['id'], 'pending_product_id' => (int) $license['pending_product_id'] ),
			array( '%s', '%d', '%s' ),
			array( '%d', '%d' )
		);
		if ( 1 !== $updated ) {
			return false;
		}
		AuditLog::record(
			$action,
			'license',
			(int) $license['id'],
			$reason,
			array( 'pending_plan_key' => (string) $license['pending_plan_key'], 'pending_product_id' => (int) $license['pending_product_id'] ),
			array( 'pending_plan_key' => '' ),
			$actor_user_id
		);

		return true;
	}

	/**
	 * Replace the entitlement snapshot with the target plan's entitlements.
	 *
	 * @param array<string,mixed> $license License row.
	 * @param array<string,mixed> $plan Catalog plan row.
	 * @param string              $now Current UTC datetime.
	 * @return bool
	 */
	private static function replace_entitlements( array $license, array $plan, string $now ): bool {
		global $wpdb;

		$tables       = Schema::table_names();
		$license_id   = (int) $license['id'];
		$paid_until   = empty( $license['paid_until'] ) ? null : (string) $license['paid_until'];
		$entitlements = json_decode( (string) $plan['entitlements_json'], true );
		$keys         = array();
		$ok           = true;
		foreach ( is_array( $entitlements ) ? $entitlements : array() as $key => $value ) {
			if ( ! is_string( $key ) || ! preg_match( '/^[a-zA-Z0-9_.-]{1,120}$/', $key ) ) {
				continue;
			}
			$keys[]     = $key;
			$value_json = wp_json_encode( $value );
			$value_json = false === $value_json ? 'null' : $value_json;
			// UNIQUE (license_id, entitlement_key): kept keys keep their row and source item.
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names(), values prepared.
			$saved = null === $paid_until
				? $wpdb->query(
					$wpdb->prepare(
						"INSERT INTO {$tables['entitlements']} (license_id, entitlement_key, value_json, starts_at, ends_at, status) VALUES (%d, %s, %s, %s, NULL, %s)"
						. ' ON DUPLICATE KEY UPDATE value_json = VALUES(value_json), ends_at = NULL, status = VALUES(status)',
						$license_id,
						$key,
						$value_json,
						$now,
						'active'
					)
				)
				: $wpdb->query(
					$wpdb->prepare(
						"INSERT INTO {$tables['entitlements']} (license_id, entitlement_key, value_json, starts_at, ends_at, status) VALUES (%d, %s, %s, %s, %s, %s)"
						. ' ON DUPLICATE KEY UPDATE value_json = VALUES(value_json), ends_at = VALUES(ends_at), status = VALUES(status)',
						$license_id,
						$key,
						$value_json,
						$now,
						$paid_until,
						'active'
					)
				);
			// phpcs:enable
			$ok = $ok && false !== $saved;
		}
		$marks = $keys ? implode( ',', array_fill( 0, count( $keys ), '%s' ) ) : '';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders generated above.
		$removed = $keys
			? $wpdb->query( $wpdb->prepare( "DELETE FROM {$tables['entitlements']} WHERE license_id = %d AND entitlement_key NOT IN ({$marks})", array_merge( array( $license_id ), $keys ) ) )
			: $wpdb->query( $wpdb->prepare( "DELETE FROM {$tables['entitlements']} WHERE license_id = %d", $license_id ) );
		// phpcs:enable

		return $ok && false !== $removed;
	}

	/**
	 * Whether a price is in the license's currency and precision.
	 *
	 * @param array{amount_minor:int,decimals:int,currency:string} $price Price.
	 * @param array<string,mixed>                                  $license License row.
	 * @return bool
	 */
	private static function same_money( array $price, array $license ): bool {
		return strtoupper( (string) $price['currency'] ) === strtoupper( (string) $license['renewal_currency'] )
			&& (int) $price['decimals'] === (int) $license['renewal_currency_decimals'];
	}

	/**
	 * Announce a plan change (after all locks are released).
	 *
	 * @param int $license_id License ID.
	 * @return void
	 */
	public static function fire_changed( int $license_id ): void {
		/**
		 * Fires after the plan of a license changed (upgrade, downgrade at renewal, administrator change, revert).
		 *
		 * @param int $license_id License ID.
		 */
		do_action( 'wplc/license_plan_changed', $license_id );
	}

	/**
	 * Fetch one license row.
	 *
	 * @param int $license_id License ID.
	 * @return array<string,mixed>|null
	 */
	public static function get_license( int $license_id ): ?array {
		global $wpdb;

		$tables = Schema::table_names();
		$row    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['licenses']} WHERE id = %d LIMIT 1", $license_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names(), values prepared.

		return is_array( $row ) ? $row : null;
	}
}
