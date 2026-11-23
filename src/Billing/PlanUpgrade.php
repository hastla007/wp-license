<?php
/**
 * Customer plan changes: prorated upgrade orders and downgrades at renewal (T-110, T-109).
 *
 * @package WPLC
 */

namespace WPLC\Billing;

use WPLC\Account\CustomerPortal;
use WPLC\Infrastructure\Database\Schema;
use WPLC\License\AuditLog;
use WPLC\License\AuditReason;
use WPLC\License\LicenseLifecycle;
use WPLC\License\PlanChange;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Upgrade flow of the customer account.
 *
 * "Upgrade" creates a WooCommerce order for the prorated price difference of
 * the rest of the paid period. When that order is paid, the license switches
 * to the target plan (same key, same paid period). A full refund of the order
 * switches it back while the upgraded period is still running.
 */
final class PlanUpgrade {

	public const META_LICENSE         = '_wplc_upgrade_for_license';
	public const META_FROM_PLAN       = '_wplc_upgrade_from_plan';
	public const META_FROM_PRODUCT    = '_wplc_upgrade_from_product';
	public const META_FROM_AMOUNT     = '_wplc_upgrade_from_renewal_minor';
	public const META_FROM_PLAN_ORDER = '_wplc_upgrade_from_plan_order';
	public const META_TO_PLAN         = '_wplc_upgrade_to_plan';
	public const META_TO_PRODUCT      = '_wplc_upgrade_to_product';
	public const META_PAID_UNTIL      = '_wplc_upgrade_paid_until';
	public const META_RENEWAL_MINOR   = '_wplc_upgrade_renewal_minor';
	public const META_CONSENT         = '_wplc_upgrade_recurring_consent_at';
	public const META_STATE           = '_wplc_upgrade_state';
	public const META_REVIEW_REASON   = '_wplc_upgrade_review_reason';
	/** How META_FROM_AMOUNT is to be read: '1' gross, '0' net, '-' unknown (P-04). */
	public const META_FROM_INCLUDES_TAX = '_wplc_upgrade_from_includes_tax';
	/** How META_RENEWAL_MINOR is to be read: '1' gross, '0' net (the shop's price setting at the quote, P-04). */
	public const META_RENEWAL_INCLUDES_TAX = '_wplc_upgrade_renewal_includes_tax';
	/** Contract price of the current period credited in the quote (P-06). */
	public const META_CREDIT_MINOR = '_wplc_upgrade_credit_minor';
	/** Unix time of the quote (P-17). */
	public const META_QUOTED_AT = '_wplc_upgrade_quoted_at';

	public const STATE_APPLIED   = 'applied';
	public const STATE_REVIEW    = 'review';
	public const STATE_REVERTED  = 'reverted';
	public const STATE_REFUNDED  = 'refunded';
	public const STATE_CANCELLED = 'cancelled';

	/**
	 * Seconds an unpaid upgrade order stays payable after its quote (P-17).
	 *
	 * The prorated price falls every day; an older quote is re-priced by
	 * clicking "Upgrade" again.
	 */
	public const QUOTE_TTL = 86400;

	/**
	 * Notice codes of the customer forms (CustomerPortal::notice_messages()).
	 */
	private const NOTICE_CODES = array( 'upgrade_unavailable', 'upgrade_busy', 'upgrade_paid', 'upgrade_pending', 'upgrade_consent_required', 'plan_change_scheduled', 'plan_change_unavailable', 'plan_change_busy', 'plan_change_withdrawn', 'plan_change_not_withdrawn' );

	/**
	 * Register form handlers, paid-order and refund hooks.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'admin_post_wplc_start_upgrade', array( self::class, 'handle_start' ) );
		add_action( 'admin_post_nopriv_wplc_start_upgrade', array( self::class, 'handle_start' ) );
		add_action( 'admin_post_wplc_schedule_downgrade', array( self::class, 'handle_schedule_downgrade' ) );
		add_action( 'admin_post_wplc_cancel_plan_change', array( self::class, 'handle_cancel_plan_change' ) );
		add_action( 'woocommerce_payment_complete', array( self::class, 'handle_paid_order' ) );
		add_action( 'woocommerce_order_status_processing', array( self::class, 'handle_paid_order' ) );
		add_action( 'woocommerce_order_status_completed', array( self::class, 'handle_paid_order' ) );
		add_action( 'wplc_apply_plan_upgrade', array( self::class, 'handle_paid_order' ) );
		add_action( 'woocommerce_order_fully_refunded', array( self::class, 'handle_full_refund' ), 20, 1 );
		add_action( 'woocommerce_order_status_refunded', array( self::class, 'handle_full_refund' ), 20, 1 );
		add_action( 'woocommerce_order_partially_refunded', array( self::class, 'handle_partial_refund' ), 20, 2 );
		// P-17: a paid upgrade order that is cancelled or fails afterwards (bounced SEPA debit) undoes the upgrade.
		add_action( 'woocommerce_order_status_cancelled', array( self::class, 'handle_cancelled_order' ), 20, 1 );
		add_action( 'woocommerce_order_status_failed', array( self::class, 'handle_cancelled_order' ), 20, 1 );
		add_action( 'wplc_revert_plan_upgrade', array( self::class, 'handle_cancelled_order' ) );
		// P-17: an upgrade order whose quote no longer applies cannot be paid.
		add_filter( 'woocommerce_order_needs_payment', array( self::class, 'filter_needs_payment' ), 20, 2 );
		// P-17: daily sweep for lost hooks, registered like the other daily jobs.
		add_action( 'wplc_daily_lifecycle', array( self::class, 'reconcile_orders' ) );
		// The account template prints the plan change section next to the renewal (T-110).
		add_action( 'wplc_account_license_renewal', array( self::class, 'render_account_section' ), 20, 1 );
	}

	/**
	 * Whether an order is a plan upgrade order.
	 *
	 * @param mixed $order Order.
	 * @return bool
	 */
	public static function is_upgrade_order( $order ): bool {
		return $order instanceof \WC_Order && absint( $order->get_meta( self::META_LICENSE, true ) ) > 0;
	}

	/**
	 * Whether the customer may upgrade this license now (status and renewal state).
	 *
	 * Only active licenses inside their paid period. Not while a renewal of the
	 * next period is being charged or its result is unknown: the renewal could
	 * be booked at the old price.
	 *
	 * @param array<string,mixed> $license License row.
	 * @return bool
	 */
	public static function can_upgrade( array $license ): bool {
		global $wpdb;

		$paid_until = empty( $license['paid_until'] ) ? 0 : (int) strtotime( (string) $license['paid_until'] . ' UTC' );
		if ( 'active' !== (string) $license['status'] || $paid_until <= time() ) {
			return false;
		}
		$renewal_id = PlanChange::next_renewal_id( $license );
		if ( ! $renewal_id ) {
			return true;
		}
		$tables = Schema::table_names();
		$status = (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$tables['renewals']} WHERE id = %d", $renewal_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names(), values prepared.

		return ! in_array( $status, array( 'processing', 'paid', RenewalProcessor::STATUS_RECONCILING, RenewalProcessor::STATUS_REVIEW, 'manual_action_required' ), true );
	}

	/**
	 * Price of an upgrade now.
	 *
	 * The credit for the rest of the period is the license's contract price
	 * (PlanChange::contract_price(): what is paid for the current period),
	 * not today's catalog price of the purchased product (P-06). Both prices
	 * are read the way the shop enters prices today (gross or net).
	 *
	 * @param array<string,mixed> $license License row.
	 * @param array<string,mixed> $target Target from PlanChange::targets().
	 * @param int                 $now Unix timestamp.
	 * @return array{amount_minor:int,decimals:int,currency:string,renewal_minor:int,renewal_includes_tax:bool,credit_minor:int,paid_until:string}|null Null when the target is no upgrade or nothing is left to pay.
	 */
	public static function quote( array $license, array $target, int $now ): ?array {
		$current = PlanChange::contract_price( $license );
		if ( ! $current || PlanChange::KIND_UPGRADE !== ( $target['kind'] ?? '' ) || empty( $license['paid_until'] ) ) {
			return null;
		}
		$paid_until = (int) strtotime( (string) $license['paid_until'] . ' UTC' );
		$amount     = PlanChange::upgrade_amount( (int) $current['amount_minor'], (int) $target['price']['amount_minor'], $now, $paid_until, (string) $license['billing_interval'] );
		if ( false === $amount || $amount <= 0 ) {
			return null;
		}

		return array(
			'amount_minor' => $amount,
			'decimals' => (int) $target['price']['decimals'],
			'currency' => (string) $target['price']['currency'],
			'renewal_minor' => (int) $target['price']['amount_minor'],
			'renewal_includes_tax' => PlanChange::shop_prices_include_tax(),
			'credit_minor' => (int) $current['amount_minor'],
			'paid_until' => (string) $license['paid_until'],
		);
	}

	/**
	 * Handle the customer's "Upgrade" form post.
	 *
	 * @return void
	 */
	public static function handle_start(): void {
		$license_id = isset( $_POST['license_id'] ) ? absint( wp_unslash( $_POST['license_id'] ) ) : 0;
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/' ) );
			exit;
		}
		check_admin_referer( 'wplc_start_upgrade_' . $license_id );
		$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
		$consent    = ! empty( $_POST['wplc_upgrade_consent'] );

		$order = self::start( $license_id, get_current_user_id(), $product_id, $consent );
		if ( is_wp_error( $order ) ) {
			self::finish( (string) $order->get_error_code(), 'upgrade_unavailable' );
			exit;
		}

		wp_safe_redirect( $order->get_checkout_payment_url() );
		exit;
	}

	/**
	 * Handle the customer's "Switch at next renewal" form post.
	 *
	 * @return void
	 */
	public static function handle_schedule_downgrade(): void {
		$license_id = isset( $_POST['license_id'] ) ? absint( wp_unslash( $_POST['license_id'] ) ) : 0;
		if ( ! is_user_logged_in() || ! $license_id ) {
			wp_die( esc_html__( 'You are not allowed to change this license.', 'wp-license' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'wplc_schedule_downgrade_' . $license_id );
		$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
		if ( ! self::owned_by( $license_id, get_current_user_id() ) ) {
			self::finish( 'license_not_found', 'license_not_found' );
		}
		$result = PlanChange::schedule_pending( $license_id, $product_id, get_current_user_id(), AuditReason::PLAN_DOWNGRADE_REQUESTED, false );
		self::finish( $result, 'plan_change_unavailable' );
	}

	/**
	 * Handle the customer's "Keep current plan" form post.
	 *
	 * @return void
	 */
	public static function handle_cancel_plan_change(): void {
		$license_id = isset( $_POST['license_id'] ) ? absint( wp_unslash( $_POST['license_id'] ) ) : 0;
		if ( ! is_user_logged_in() || ! $license_id ) {
			wp_die( esc_html__( 'You are not allowed to change this license.', 'wp-license' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'wplc_cancel_plan_change_' . $license_id );
		if ( ! self::owned_by( $license_id, get_current_user_id() ) ) {
			self::finish( 'license_not_found', 'license_not_found' );
		}
		$withdrawn = PlanChange::cancel_pending( $license_id, get_current_user_id(), AuditReason::PLAN_CHANGE_WITHDRAWN );
		self::finish( $withdrawn ? 'plan_change_withdrawn' : 'plan_change_not_withdrawn', 'plan_change_not_withdrawn' );
	}

	/**
	 * Create or reuse the upgrade order of a license.
	 *
	 * One open upgrade order per license: an unpaid one is re-priced for the
	 * chosen target and the current time. With automatic renewal switched on,
	 * the customer must agree to the new automatic amount; the consent is
	 * stored on the order and applied with the payment.
	 *
	 * @param int  $license_id License ID.
	 * @param int  $customer_id Acting customer.
	 * @param int  $product_id Target product or variation ID.
	 * @param bool $consent Customer ticked the consent to the new automatic renewal amount.
	 * @return \WC_Order|\WP_Error Order awaiting payment.
	 */
	public static function start( int $license_id, int $customer_id, int $product_id, bool $consent ) {
		$unavailable = new \WP_Error( 'upgrade_unavailable', __( 'This license cannot be upgraded to the chosen plan at the moment.', 'wp-license' ) );
		if ( ! function_exists( 'wc_create_order' ) || ! $customer_id || ! self::owned_by( $license_id, $customer_id ) ) {
			return $unavailable;
		}
		$result = LicenseLifecycle::run_locked(
			$license_id,
			static function () use ( $license_id, $customer_id, $product_id, $consent, $unavailable ) {
				$license = PlanChange::get_license( $license_id );
				if ( ! $license || ! self::can_upgrade( $license ) ) {
					return $unavailable;
				}
				$target = PlanChange::resolve_target( $license, $product_id );
				$quote  = $target ? self::quote( $license, $target, time() ) : null;
				if ( ! $target || ! $quote ) {
					return $unavailable;
				}
				if ( ! empty( $license['renewal_enabled'] ) && ! $consent ) {
					return new \WP_Error( 'upgrade_consent_required', __( 'Please agree to the new amount of the automatic renewal to upgrade.', 'wp-license' ) );
				}

				$order = null;
				$ids   = RenewalOrder::find_ids_by_meta( self::META_LICENSE, (string) $license_id, 10 );
				if ( is_wp_error( $ids ) ) {
					return $unavailable;
				}
				foreach ( $ids as $id ) {
					$candidate = wc_get_order( $id );
					if ( ! $candidate instanceof \WC_Order || absint( $candidate->get_meta( self::META_LICENSE, true ) ) !== $license_id ) {
						continue;
					}
					$state = (string) $candidate->get_meta( self::META_STATE, true );
					if ( $candidate->is_paid() && '' === $state ) {
						// Paid, not applied yet (hook pending or lock busy).
						self::queue_apply( (int) $candidate->get_id() );
						return new \WP_Error( 'upgrade_paid', __( 'Your upgrade payment has been received and is being processed.', 'wp-license' ) );
					}
					if ( 'on-hold' === $candidate->get_status() ) {
						return new \WP_Error( 'upgrade_pending', __( 'An upgrade order for this license is already waiting for your payment to arrive.', 'wp-license' ) );
					}
					// An open order is re-priced below, also one whose quote expired (not payable any more, P-17).
					if ( ! $order && in_array( $candidate->get_status(), array( 'pending', 'failed' ), true ) && ! $candidate->is_paid() && '' === $state ) {
						$order = $candidate;
					}
				}

				if ( null === $order ) {
					$order = wc_create_order( array( 'customer_id' => $customer_id ) );
					if ( is_wp_error( $order ) ) {
						return $unavailable;
					}
					$order->update_meta_data( self::META_LICENSE, (string) $license_id );
					$order->set_created_via( 'wplc_plan_upgrade' );
					RenewalOrder::set_addresses( $order, $license );
				}
				$order->set_currency( $quote['currency'] );
				if ( strtoupper( (string) $order->get_currency() ) !== strtoupper( (string) $quote['currency'] ) ) {
					// B-189: the upgrade is billed in the license's currency or not at all.
					return $unavailable;
				}
				if ( ! RenewalOrder::set_plain_line( $order, $target['product'], $quote['amount_minor'], $quote['decimals'] ) ) {
					return $unavailable;
				}
				foreach ( $order->get_items( 'line_item' ) as $item ) {
					/* translators: %s: end of the paid period (date). */
					$item->add_meta_data( __( 'Plan upgrade', 'wp-license' ), sprintf( __( 'Prorated price for the rest of the paid period until %s', 'wp-license' ), self::date_label( $quote['paid_until'] ) ), true );
					$item->save();
				}
				$metas = array(
					self::META_FROM_PLAN => (string) $license['plan_key'],
					self::META_FROM_PRODUCT => (int) $license['renewal_product_id'],
					self::META_FROM_AMOUNT => (int) $license['renewal_amount_minor'],
					self::META_FROM_PLAN_ORDER => (int) $license['plan_order_id'],
					self::META_TO_PLAN => (string) $target['plan']['plan_key'],
					self::META_TO_PRODUCT => (int) $target['product_id'],
					self::META_PAID_UNTIL => $quote['paid_until'],
					self::META_RENEWAL_MINOR => $quote['renewal_minor'],
					self::META_RENEWAL_INCLUDES_TAX => $quote['renewal_includes_tax'] ? '1' : '0',
					self::META_FROM_INCLUDES_TAX => self::flag_meta( PlanChange::stored_includes_tax( $license ) ),
					self::META_CREDIT_MINOR => $quote['credit_minor'],
					self::META_QUOTED_AT => time(),
				);
				foreach ( $metas as $key => $value ) {
					$order->update_meta_data( $key, $value );
				}
				if ( ! empty( $license['renewal_enabled'] ) ) {
					$order->update_meta_data( self::META_CONSENT, gmdate( 'Y-m-d H:i:s' ) );
				} else {
					$order->delete_meta_data( self::META_CONSENT );
				}
				$order->save();

				AuditLog::record(
					'plan_upgrade_started',
					'license',
					$license_id,
					AuditReason::encode( AuditReason::PLAN_UPGRADE_STARTED, array( 'order' => (int) $order->get_id() ) ),
					array( 'plan_key' => (string) $license['plan_key'] ),
					array(
						'plan_key' => (string) $target['plan']['plan_key'],
						'order_id' => (int) $order->get_id(),
						'amount_minor' => $quote['amount_minor'],
						'credit_minor' => $quote['credit_minor'],
						'renewal_amount_minor' => $quote['renewal_minor'],
						'renewal_amount_includes_tax' => $quote['renewal_includes_tax'],
						'recurring_consent' => ! empty( $license['renewal_enabled'] ),
					),
					$customer_id
				);

				return $order;
			}
		);

		return $result instanceof \WC_Order || is_wp_error( $result )
			? $result
			: new \WP_Error( 'upgrade_busy', __( 'This license is being changed right now. Please try again in a few minutes.', 'wp-license' ) );
	}

	/**
	 * Apply a paid upgrade order. Idempotent under duplicate hooks.
	 *
	 * An order that was cancelled after payment (STATE_CANCELLED, upgrade
	 * undone) and is then marked as paid again is applied again, with the
	 * usual checks (problem()).
	 *
	 * @param mixed $order_id Order ID.
	 * @return void
	 */
	public static function handle_paid_order( $order_id ): void {
		$order_id = absint( $order_id );
		$order    = $order_id && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof \WC_Order || ! self::is_upgrade_order( $order ) || ! $order->is_paid() || ! in_array( (string) $order->get_meta( self::META_STATE, true ), array( '', self::STATE_CANCELLED ), true ) ) {
			return;
		}
		// Cash on delivery is "processing" before any money arrived: the upgrade applies when the order is completed.
		if ( ! \WPLC\License\LicenseIssuer::money_has_arrived( $order ) ) {
			return;
		}
		$license_id = absint( $order->get_meta( self::META_LICENSE, true ) );
		if ( ! PlanChange::get_license( $license_id ) ) {
			self::flag_review( $order, $license_id, AuditReason::UPGRADE_LICENSE_NOT_ACTIVE );
			return;
		}
		$result = PlanChange::with_locks(
			$license_id,
			static function ( array $license, int $renewal_id ) use ( $order ) {
				return self::apply_locked( $order, $license, $renewal_id );
			}
		);
		if ( false === $result ) {
			// A lock was busy (a renewal is running) or the period moved meanwhile: try again shortly.
			self::queue_apply( $order_id );
			return;
		}
		if ( true === $result ) {
			PlanChange::fire_changed( $license_id );
		}
	}

	/**
	 * Body of handle_paid_order() under the renewal lock (if any) and the license lock.
	 *
	 * @param \WC_Order           $order Paid upgrade order.
	 * @param array<string,mixed> $license License row read under the locks.
	 * @param int                 $renewal_id Renewal of the next period, zero for none.
	 * @return bool|string True when the plan was changed now, 'done' when nothing was left to do.
	 */
	private static function apply_locked( $order, array $license, int $renewal_id ) {
		$order_id = (int) $order->get_id();
		if ( (int) $license['plan_order_id'] === $order_id ) {
			// A duplicate hook: the plan was switched by this order already.
			self::set_state( $order, self::STATE_APPLIED );
			return 'done';
		}
		$problem = self::problem( $order, $license );
		$target  = null;
		if ( '' === $problem ) {
			$target = PlanChange::mapped_target( $license, absint( $order->get_meta( self::META_TO_PRODUCT, true ) ), (string) $order->get_meta( self::META_TO_PLAN, true ) );
			if ( ! $target ) {
				$problem = AuditReason::UPGRADE_TARGET_UNAVAILABLE;
			}
		}
		if ( '' === $problem ) {
			$amount = array(
				'amount_minor' => absint( $order->get_meta( self::META_RENEWAL_MINOR, true ) ),
				'decimals' => (int) $license['renewal_currency_decimals'],
				'currency' => (string) $license['renewal_currency'],
				// P-04: the consented amount is a catalog price, read as the shop entered prices at the quote.
				'includes_tax' => self::meta_flag( $order, self::META_RENEWAL_INCLUDES_TAX, PlanChange::shop_prices_include_tax() ),
			);
			if ( $amount['amount_minor'] <= 0 || ! PlanChange::switch_locked( $license, $target, $amount, $order_id, 'plan_upgraded', AuditReason::encode( AuditReason::PLAN_UPGRADED_FROM_ORDER, array( 'order' => $order_id ) ), 0 ) ) {
				$problem = AuditReason::UPGRADE_NOT_APPLIED;
			}
		}
		if ( '' !== $problem ) {
			self::flag_review( $order, (int) $license['id'], $problem );
			return 'done';
		}

		$changed = PlanChange::get_license( (int) $license['id'] );
		if ( $changed ) {
			PlanChange::reprice_open_renewal_order( $changed, $renewal_id );
		}
		self::set_state( $order, self::STATE_APPLIED );
		$consent = (string) $order->get_meta( self::META_CONSENT, true );
		$order->add_order_note(
			sprintf(
				/* translators: 1: license ID, 2: new plan key. */
				__( 'WP License Captain switched license #%1$d to plan %2$s.', 'wp-license' ),
				(int) $license['id'],
				(string) $order->get_meta( self::META_TO_PLAN, true )
			) . ( '' !== $consent ? ' ' . sprintf(
				/* translators: 1: renewal amount, 2: time of the consent (UTC). */
				__( 'The customer agreed to automatic renewals of %1$s from the next period (%2$s UTC).', 'wp-license' ),
				Money::to_decimal( absint( $order->get_meta( self::META_RENEWAL_MINOR, true ) ), (int) $license['renewal_currency_decimals'] ) . ' ' . (string) $license['renewal_currency'],
				$consent
			) : '' )
		);

		return true;
	}

	/**
	 * Why a paid upgrade order cannot be applied, or ''.
	 *
	 * @param \WC_Order           $order Paid upgrade order.
	 * @param array<string,mixed> $license License row.
	 * @return string AuditReason code or ''.
	 */
	private static function problem( $order, array $license ): string {
		$paid_until = empty( $license['paid_until'] ) ? 0 : (int) strtotime( (string) $license['paid_until'] . ' UTC' );
		if ( (int) $order->get_customer_id() !== (int) $license['customer_id'] || ! in_array( (string) $license['status'], array( 'active', 'past_due' ), true ) ) {
			return AuditReason::UPGRADE_LICENSE_NOT_ACTIVE;
		}
		if ( (string) $license['paid_until'] !== (string) $order->get_meta( self::META_PAID_UNTIL, true ) || $paid_until <= time() ) {
			return AuditReason::UPGRADE_PERIOD_CHANGED;
		}
		if (
			(string) $license['plan_key'] !== (string) $order->get_meta( self::META_FROM_PLAN, true )
			|| (int) $license['renewal_product_id'] !== absint( $order->get_meta( self::META_FROM_PRODUCT, true ) )
			|| (int) $license['plan_order_id'] !== absint( $order->get_meta( self::META_FROM_PLAN_ORDER, true ) )
		) {
			return AuditReason::UPGRADE_PLAN_CHANGED;
		}
		if ( ! RenewalOrder::net_matches( $order ) || strtoupper( (string) $order->get_currency() ) !== strtoupper( (string) $license['renewal_currency'] ) ) {
			return AuditReason::UPGRADE_PRICE_MISMATCH;
		}
		if ( ! empty( $license['renewal_enabled'] ) && '' === (string) $order->get_meta( self::META_CONSENT, true ) ) {
			return AuditReason::UPGRADE_CONSENT_MISSING;
		}

		return '';
	}

	/**
	 * Record a paid upgrade order that could not be applied.
	 *
	 * The license is not changed. The order gets a note and the meta
	 * `_wplc_upgrade_state = review`; the license page shows a warning. The
	 * shop refunds the order or changes the plan by hand.
	 *
	 * @param \WC_Order $order Order.
	 * @param int       $license_id License ID.
	 * @param string    $problem Reason code.
	 * @return void
	 */
	private static function flag_review( $order, int $license_id, string $problem ): void {
		$order->update_meta_data( self::META_REVIEW_REASON, $problem );
		self::set_state( $order, self::STATE_REVIEW );
		$order->add_order_note( __( 'WP License Captain: this plan upgrade payment could not be applied to the license. Refund the order or change the plan on the license page.', 'wp-license' ) . ' ' . AuditReason::label( $problem ) );
		AuditLog::record( 'plan_upgrade_rejected', 'license', $license_id, $problem, null, array( 'order_id' => (int) $order->get_id() ) );
	}

	/**
	 * Revert an applied upgrade whose order was refunded in full.
	 *
	 * The previous plan is restored (entitlements, renewal product and amount)
	 * while the upgraded period is still running and the plan was not changed
	 * since. Otherwise the refund is only recorded: a renewal at the new plan
	 * has already been paid, or the plan changed again.
	 *
	 * @param mixed $order_id Order ID.
	 * @return void
	 */
	public static function handle_full_refund( $order_id ): void {
		$order_id = absint( $order_id );
		$order    = $order_id && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof \WC_Order || ! self::is_upgrade_order( $order ) ) {
			return;
		}
		$state = (string) $order->get_meta( self::META_STATE, true );
		if ( in_array( $state, array( self::STATE_REVERTED, self::STATE_REFUNDED ), true ) ) {
			return;
		}
		$license_id = absint( $order->get_meta( self::META_LICENSE, true ) );
		$result     = PlanChange::with_locks(
			$license_id,
			static function ( array $license, int $renewal_id ) use ( $order, $state ) {
				$reverted = self::revert_locked( $order, $license, $renewal_id, $state );
				self::record_refund( $order, (int) $license['id'], $reverted );

				return $reverted ? true : 'done';
			}
		);
		if ( false === $result ) {
			// Lock busy: record the refund so the shop can act; the plan stays.
			self::record_refund( $order, $license_id, false );
			return;
		}
		if ( true === $result ) {
			PlanChange::fire_changed( $license_id );
		}
	}

	/**
	 * Undo an applied upgrade whose paid order was cancelled or failed afterwards (P-17).
	 *
	 * A bounced SEPA debit or a cancellation by the shop means the upgrade was
	 * not paid. Like a full refund, the previous plan is restored while the
	 * upgraded period is still running and the plan was not changed since.
	 * Otherwise (a renewal at the new plan was paid, or the plan changed again)
	 * the plan stays and the license is reported for review
	 * (`wplc/license_needs_review`); the license is not suspended, because the
	 * customer has paid for the plan they had before.
	 *
	 * If the order is marked as paid again later, handle_paid_order() applies
	 * it again.
	 *
	 * @param mixed $order_id Order ID.
	 * @return void
	 */
	public static function handle_cancelled_order( $order_id ): void {
		$order_id = absint( $order_id );
		$order    = $order_id && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		if (
			! $order instanceof \WC_Order
			|| ! self::is_upgrade_order( $order )
			|| $order->is_paid()
			|| ! in_array( $order->get_status(), array( 'cancelled', 'failed' ), true )
			|| self::STATE_APPLIED !== (string) $order->get_meta( self::META_STATE, true )
		) {
			// Nothing was applied: an unpaid order, or one in review (the plan never changed).
			return;
		}
		$license_id = absint( $order->get_meta( self::META_LICENSE, true ) );
		$result     = PlanChange::with_locks(
			$license_id,
			static function ( array $license, int $renewal_id ) use ( $order ) {
				$reverted = self::revert_locked( $order, $license, $renewal_id, self::STATE_APPLIED );
				self::record_cancellation( $order, (int) $license['id'], $reverted );

				return $reverted ? true : 'done';
			}
		);
		if ( false === $result ) {
			if ( ! PlanChange::get_license( $license_id ) ) {
				self::record_cancellation( $order, $license_id, false );
				return;
			}
			// A lock was busy: try again shortly (the daily sweep also picks it up).
			self::queue_job( 'wplc_revert_plan_upgrade', $order_id );
			return;
		}
		if ( true === $result ) {
			PlanChange::fire_changed( $license_id );
		}
	}

	/**
	 * Restore the plan an applied upgrade order replaced, if that is still safe. Caller holds the locks.
	 *
	 * @param \WC_Order           $order Refunded or cancelled upgrade order.
	 * @param array<string,mixed> $license License row.
	 * @param int                 $renewal_id Renewal of the next period, zero for none.
	 * @param string              $state Upgrade state before the refund or cancellation.
	 * @return bool True when the plan was reverted.
	 */
	private static function revert_locked( $order, array $license, int $renewal_id, string $state ): bool {
		$order_id = (int) $order->get_id();
		$reverted = false;
		if (
			self::STATE_APPLIED === $state
			&& (int) $license['plan_order_id'] === $order_id
			&& (string) $license['paid_until'] === (string) $order->get_meta( self::META_PAID_UNTIL, true )
			&& in_array( (string) $license['status'], array( 'active', 'past_due', 'suspended' ), true )
		) {
			$target = PlanChange::mapped_target( $license, absint( $order->get_meta( self::META_FROM_PRODUCT, true ) ), (string) $order->get_meta( self::META_FROM_PLAN, true ) );
			if ( $target ) {
				$amount   = array(
					'amount_minor' => absint( $order->get_meta( self::META_FROM_AMOUNT, true ) ),
					'decimals' => (int) $license['renewal_currency_decimals'],
					'currency' => (string) $license['renewal_currency'],
					// P-04: the old amount keeps its old flag. Orders created before the flag was
					// recorded: the upgrade did not change the flag then, so the license still has it.
					'includes_tax' => self::meta_flag( $order, self::META_FROM_INCLUDES_TAX, PlanChange::stored_includes_tax( $license ) ),
				);
				$reverted = PlanChange::switch_locked(
					$license,
					$target,
					$amount,
					absint( $order->get_meta( self::META_FROM_PLAN_ORDER, true ) ),
					'plan_upgrade_reverted',
					AuditReason::encode( AuditReason::PLAN_UPGRADE_REFUNDED, array( 'order' => $order_id ) ),
					0
				);
			}
		}
		if ( $reverted ) {
			$changed = PlanChange::get_license( (int) $license['id'] );
			if ( $changed ) {
				PlanChange::reprice_open_renewal_order( $changed, $renewal_id );
			}
		}

		return $reverted;
	}

	/**
	 * Audit entry, order note, state and review notice of a cancelled or failed paid upgrade order.
	 *
	 * @param \WC_Order $order Order.
	 * @param int       $license_id License ID.
	 * @param bool      $reverted Whether the previous plan was restored.
	 * @return void
	 */
	private static function record_cancellation( $order, int $license_id, bool $reverted ): void {
		$reason = AuditReason::encode( AuditReason::PLAN_UPGRADE_CANCELLED, array( 'order' => (int) $order->get_id(), 'status' => (string) $order->get_status() ) );
		self::set_state( $order, self::STATE_CANCELLED );
		AuditLog::record(
			'plan_upgrade_cancelled',
			'license',
			$license_id,
			$reason,
			array( 'state' => self::STATE_APPLIED ),
			array( 'reverted' => $reverted, 'order_id' => (int) $order->get_id(), 'order_status' => (string) $order->get_status() )
		);
		$order->add_order_note(
			$reverted
				? __( 'WP License Captain: the plan upgrade was not paid (order cancelled or failed); the license is back on its previous plan.', 'wp-license' )
				: __( 'WP License Captain: the plan upgrade was not paid (order cancelled or failed), but the license plan was not changed back (the upgraded period has ended or the plan changed since). Review the license.', 'wp-license' )
		);
		if ( ! $reverted && $license_id > 0 ) {
			/** This action is documented in src/License/LicenseLifecycle.php */
			do_action( 'wplc/license_needs_review', $license_id, AuditReason::label( $reason ) );
		}
	}

	/**
	 * Audit entry, order note and state of a full refund.
	 *
	 * @param \WC_Order $order Order.
	 * @param int       $license_id License ID.
	 * @param bool      $reverted Whether the previous plan was restored.
	 * @return void
	 */
	private static function record_refund( $order, int $license_id, bool $reverted ): void {
		$state = (string) $order->get_meta( self::META_STATE, true );
		self::set_state( $order, $reverted ? self::STATE_REVERTED : self::STATE_REFUNDED );
		AuditLog::record(
			'plan_upgrade_refunded',
			'license',
			$license_id,
			AuditReason::encode( AuditReason::PLAN_UPGRADE_REFUNDED, array( 'order' => (int) $order->get_id() ) ),
			array( 'state' => $state ),
			array( 'reverted' => $reverted, 'order_id' => (int) $order->get_id() )
		);
		$order->add_order_note(
			$reverted
				? __( 'WP License Captain: the plan upgrade was refunded; the license is back on its previous plan.', 'wp-license' )
				: __( 'WP License Captain: the plan upgrade was refunded; the license plan was not changed (the upgraded period has ended, the plan changed since, or the upgrade was never applied). Change the plan on the license page if needed.', 'wp-license' )
		);
	}

	/**
	 * Record a partial refund of an upgrade order; the plan stays.
	 *
	 * @param mixed $order_id Order ID.
	 * @param mixed $refund_id Refund ID.
	 * @return void
	 */
	public static function handle_partial_refund( $order_id, $refund_id = 0 ): void {
		$order_id = absint( $order_id );
		$order    = $order_id && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		$refund   = $order && absint( $refund_id ) ? wc_get_order( absint( $refund_id ) ) : false;
		if ( ! $order instanceof \WC_Order || ! self::is_upgrade_order( $order ) || ! $refund instanceof \WC_Order_Refund || (int) $refund->get_parent_id() !== $order_id ) {
			return;
		}
		if ( $refund->get_meta( '_wplc_partial_upgrade_refund_recorded', true ) ) {
			return;
		}
		$refund->update_meta_data( '_wplc_partial_upgrade_refund_recorded', gmdate( 'Y-m-d H:i:s' ) );
		$refund->save();
		$license_id = absint( $order->get_meta( self::META_LICENSE, true ) );
		$amount     = (string) $refund->get_amount();
		$currency   = (string) $order->get_currency();
		AuditLog::record(
			'plan_upgrade_partially_refunded',
			'license',
			$license_id,
			AuditReason::encode( AuditReason::PLAN_UPGRADE_PARTIALLY_REFUNDED, array( 'order' => $order_id, 'amount' => $amount, 'currency' => $currency ) ),
			null,
			array( 'refund_id' => (int) $refund->get_id(), 'amount' => $amount, 'currency' => $currency )
		);
		$order->add_order_note( __( 'Partial refund: the license keeps its upgraded plan. Change the plan on the license page if the refund should undo the upgrade.', 'wp-license' ) );
	}

	/**
	 * Upgrade orders of a license that were paid but could not be applied.
	 *
	 * @param int $license_id License ID.
	 * @return array<int,string> Order ID => reason code.
	 */
	public static function orders_in_review( int $license_id ): array {
		$ids = RenewalOrder::find_ids_by_meta( self::META_LICENSE, (string) $license_id, 20 );
		if ( is_wp_error( $ids ) || ! function_exists( 'wc_get_order' ) ) {
			return array();
		}
		$cases = array();
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( $order instanceof \WC_Order && self::is_upgrade_order( $order ) && absint( $order->get_meta( self::META_LICENSE, true ) ) === $license_id && self::STATE_REVIEW === (string) $order->get_meta( self::META_STATE, true ) && $order->is_paid() ) {
				$cases[ (int) $order->get_id() ] = (string) $order->get_meta( self::META_REVIEW_REASON, true );
			}
		}

		return $cases;
	}

	/**
	 * Render the plan section of one license in the customer account.
	 *
	 * @param array<string,mixed> $license License row.
	 * @return void
	 */
	public static function render_account_section( array $license ): void {
		$license_id = (int) $license['id'];
		$running    = in_array( (string) $license['status'], array( 'active', 'past_due' ), true );
		// B-139: a co-user sees a shared license, but only its owner changes the plan.
		if ( ! $running || (int) ( $license['customer_id'] ?? 0 ) !== get_current_user_id() ) {
			return;
		}
		$pending = PlanChange::pending_target( $license );
		if ( $pending ) {
			echo '<p class="wplc-plan-pending"><strong>' . esc_html__( 'Plan change:', 'wp-license' ) . '</strong> ';
			/* translators: %s: name of the plan (product) the license changes to. */
			echo esc_html( sprintf( __( 'Changes to %s with the next renewal. Sites above the new limit stay active, but no new sites can be activated until you are within the limit.', 'wp-license' ), self::product_name( $pending['product'] ) ) ) . '</p>';
			echo '<form method="post" class="wplc-inline" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="wplc_cancel_plan_change">';
			echo '<input type="hidden" name="license_id" value="' . esc_attr( (string) $license_id ) . '">';
			echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( 'wplc_cancel_plan_change_' . $license_id ) ) . '">';
			echo '<button type="submit" class="button">' . esc_html__( 'Keep current plan', 'wp-license' ) . '</button></form>';
		}

		$targets = PlanChange::targets( $license );
		if ( ! $targets ) {
			return;
		}
		$can_upgrade = self::can_upgrade( $license );
		$per_period  = 'month' === (string) $license['billing_interval'] ? __( 'per month', 'wp-license' ) : __( 'per year', 'wp-license' );
		$rendered    = false;
		foreach ( $targets as $product_id => $target ) {
			if ( $pending && (int) $pending['product_id'] === (int) $product_id ) {
				continue;
			}
			$name  = self::product_name( $target['product'] );
			$price = self::money( (int) $target['price']['amount_minor'], (int) $target['price']['decimals'], (string) $target['price']['currency'] );
			if ( PlanChange::KIND_UPGRADE === $target['kind'] ) {
				$quote = $can_upgrade ? self::quote( $license, $target, time() ) : null;
				if ( ! $quote ) {
					continue;
				}
				if ( ! $rendered ) {
					echo '<h4>' . esc_html__( 'Change plan', 'wp-license' ) . '</h4>';
					$rendered = true;
				}
				$due = self::money( $quote['amount_minor'], $quote['decimals'], $quote['currency'] );
				echo '<form method="post" class="wplc-plan-upgrade" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				echo '<input type="hidden" name="action" value="wplc_start_upgrade">';
				echo '<input type="hidden" name="license_id" value="' . esc_attr( (string) $license_id ) . '">';
				echo '<input type="hidden" name="product_id" value="' . esc_attr( (string) $product_id ) . '">';
				echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( 'wplc_start_upgrade_' . $license_id ) ) . '">';
				echo '<p>';
				/* translators: 1: plan name, 2: amount due now, 3: end of the paid period, 4: regular price, 5: "per year" or "per month". */
				echo esc_html( sprintf( __( 'Upgrade to %1$s now: %2$s for the rest of the paid period until %3$s (taxes are calculated on the payment page). Afterwards %4$s %5$s.', 'wp-license' ), $name, $due, self::date_label( (string) $license['paid_until'] ), $price, $per_period ) );
				echo '</p>';
				if ( ! empty( $license['renewal_enabled'] ) ) {
					$field = 'wplc-upgrade-consent-' . $license_id . '-' . $product_id;
					echo '<p><label for="' . esc_attr( $field ) . '"><input type="checkbox" id="' . esc_attr( $field ) . '" name="wplc_upgrade_consent" value="1" required> ';
					/* translators: 1: new renewal amount, 2: "per year" or "per month", 3: date of the next automatic charge. */
					echo esc_html( sprintf( __( 'I agree that my saved payment method is charged %1$s %2$s automatically from the next renewal on %3$s, until I cancel.', 'wp-license' ), $price, $per_period, self::date_label( (string) $license['paid_until'] ) ) );
					echo '</label></p>';
				}
				/* translators: %s: plan name. */
				echo '<p><button type="submit" class="button alt">' . esc_html( sprintf( __( 'Upgrade to %s', 'wp-license' ), $name ) ) . '</button></p></form>';
				continue;
			}
			if ( ! $rendered ) {
				echo '<h4>' . esc_html__( 'Change plan', 'wp-license' ) . '</h4>';
				$rendered = true;
			}
			/* translators: 1: plan name, 2: regular price, 3: "per year" or "per month". */
			$question = sprintf( __( 'Switch to %1$s (%2$s %3$s) with the next renewal? Until then the current plan stays. Sites above the new limit stay active, but no new sites can be activated until you are within the limit.', 'wp-license' ), $name, $price, $per_period );
			echo '<form method="post" class="wplc-plan-downgrade" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-wplc-confirm="' . esc_attr( $question ) . '">';
			echo '<input type="hidden" name="action" value="wplc_schedule_downgrade">';
			echo '<input type="hidden" name="license_id" value="' . esc_attr( (string) $license_id ) . '">';
			echo '<input type="hidden" name="product_id" value="' . esc_attr( (string) $product_id ) . '">';
			echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( 'wplc_schedule_downgrade_' . $license_id ) ) . '">';
			/* translators: 1: plan name, 2: regular price, 3: "per year" or "per month". */
			echo '<p><button type="submit" class="button">' . esc_html( sprintf( __( 'Switch to %1$s at the next renewal (%2$s %3$s)', 'wp-license' ), $name, $price, $per_period ) ) . '</button></p></form>';
		}
	}

	/**
	 * Queue the application of a paid upgrade order.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	private static function queue_apply( int $order_id ): void {
		self::queue_job( 'wplc_apply_plan_upgrade', $order_id );
	}

	/**
	 * Queue a job for one upgrade order.
	 *
	 * @param string $hook Hook name.
	 * @param int    $order_id Order ID.
	 * @return void
	 */
	private static function queue_job( string $hook, int $order_id ): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			\WPLC\Compatibility\Requirements::enqueue_async_action( $hook, array( $order_id ), 'wplc' );
			return;
		}
		if ( ! wp_next_scheduled( $hook, array( $order_id ) ) ) {
			wp_schedule_single_event( time() + 30, $hook, array( $order_id ) );
		}
	}

	/**
	 * Why an unpaid upgrade order may no longer be paid, or '' (P-17).
	 *
	 * The quote is fixed when the order is created; it no longer applies when
	 * the license's period or plan changed, the license cannot be upgraded
	 * right now, or the quote is older than QUOTE_TTL (the prorated price
	 * falls every day). The customer clicks "Upgrade" again for a new quote.
	 *
	 * @param \WC_Order $order Upgrade order.
	 * @param int       $now Unix timestamp.
	 * @return string Reason code or ''.
	 */
	public static function open_order_problem( $order, int $now ): string {
		$license = PlanChange::get_license( absint( $order->get_meta( self::META_LICENSE, true ) ) );
		if ( ! $license || (int) $order->get_customer_id() !== (int) $license['customer_id'] || ! self::can_upgrade( $license ) ) {
			return AuditReason::UPGRADE_LICENSE_NOT_ACTIVE;
		}
		if ( (string) $license['paid_until'] !== (string) $order->get_meta( self::META_PAID_UNTIL, true ) ) {
			return AuditReason::UPGRADE_PERIOD_CHANGED;
		}
		if (
			(string) $license['plan_key'] !== (string) $order->get_meta( self::META_FROM_PLAN, true )
			|| (int) $license['renewal_product_id'] !== absint( $order->get_meta( self::META_FROM_PRODUCT, true ) )
			|| (int) $license['plan_order_id'] !== absint( $order->get_meta( self::META_FROM_PLAN_ORDER, true ) )
		) {
			return AuditReason::UPGRADE_PLAN_CHANGED;
		}
		$quoted_at = absint( $order->get_meta( self::META_QUOTED_AT, true ) );
		if ( ! $quoted_at ) {
			// Orders created before the quote time was recorded.
			$created   = $order->get_date_created();
			$quoted_at = $created ? (int) $created->getTimestamp() : 0;
		}
		if ( $now - $quoted_at > self::QUOTE_TTL ) {
			return AuditReason::UPGRADE_QUOTE_EXPIRED;
		}

		return '';
	}

	/**
	 * Filter `woocommerce_order_needs_payment`: an upgrade order whose quote no longer applies cannot be paid (P-17).
	 *
	 * @param mixed $needs_payment Whether the order needs payment.
	 * @param mixed $order Order.
	 * @return mixed
	 */
	public static function filter_needs_payment( $needs_payment, $order = null ) {
		if ( ! $needs_payment || ! self::is_upgrade_order( $order ) || '' !== (string) $order->get_meta( self::META_STATE, true ) ) {
			return $needs_payment;
		}

		return '' === self::open_order_problem( $order, time() ) ? $needs_payment : false;
	}

	/**
	 * Cancel the unpaid upgrade orders of a license whose quote no longer applies (P-17).
	 *
	 * Called after a renewal was booked (PlanChange::apply_pending_after_renewal())
	 * and by the daily sweep. Orders waiting for an offline payment (on-hold)
	 * are not cancelled; when that payment arrives, the order is checked and
	 * goes to review if it no longer fits.
	 *
	 * @param int $license_id License ID.
	 * @return int Number of cancelled orders.
	 */
	public static function close_stale_orders( int $license_id ): int {
		$ids = $license_id > 0 ? RenewalOrder::find_ids_by_meta( self::META_LICENSE, (string) $license_id, 20 ) : array();
		if ( is_wp_error( $ids ) || ! function_exists( 'wc_get_order' ) ) {
			return 0;
		}
		$closed = 0;
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( $order instanceof \WC_Order && absint( $order->get_meta( self::META_LICENSE, true ) ) === $license_id && self::close_if_stale( $order ) ) {
				++$closed;
			}
		}

		return $closed;
	}

	/**
	 * Cancel one unpaid (pending or failed) upgrade order whose quote no longer applies.
	 *
	 * @param \WC_Order $order Upgrade order.
	 * @return bool True when the order was cancelled.
	 */
	private static function close_if_stale( $order ): bool {
		if (
			$order->is_paid()
			|| ! in_array( $order->get_status(), array( 'pending', 'failed' ), true )
			|| '' !== (string) $order->get_meta( self::META_STATE, true )
			|| '' === self::open_order_problem( $order, time() )
		) {
			return false;
		}
		$order->update_status( 'cancelled', __( 'WP License Captain: the price of this plan upgrade no longer applies (the paid period or the plan of the license changed, or the offer expired). The customer can start the upgrade again from their account.', 'wp-license' ) );

		return true;
	}

	/**
	 * Daily sweep of upgrade orders (P-17).
	 *
	 * - Paid orders that were not applied (lost hook or job, plugin inactive
	 *   during the payment) are applied now, or go to review.
	 * - Applied orders that were cancelled or failed afterwards while their
	 *   hook was lost are undone (handle_cancelled_order()).
	 * - Unpaid orders whose quote no longer applies are cancelled.
	 *
	 * Looks at upgrade orders changed during the last 60 days.
	 *
	 * @return void
	 */
	public static function reconcile_orders(): void {
		if ( RenewalProcessor::billing_paused() || ! function_exists( 'wc_get_orders' ) ) {
			return;
		}
		LicenseLifecycle::run_guarded(
			'reconcile_plan_upgrades',
			static function () {
				$deadline = microtime( true ) + 20;
				$page     = 1;
				do {
					$ids = wc_get_orders(
						array(
							'limit' => 100,
							'page' => $page,
							'return' => 'ids',
							'type' => 'shop_order',
							'status' => array_keys( wc_get_order_statuses() ),
							'created_via' => 'wplc_plan_upgrade',
							'date_modified' => '>' . ( time() - 60 * DAY_IN_SECONDS ),
							'orderby' => 'ID',
							'order' => 'ASC',
						)
					);
					$ids = is_array( $ids ) ? $ids : array();
					foreach ( $ids as $id ) {
						self::reconcile_order( absint( $id ) );
					}
					++$page;
				} while ( count( $ids ) >= 100 && microtime( true ) < $deadline );
			}
		);
	}

	/**
	 * Reconcile one upgrade order (see reconcile_orders()).
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public static function reconcile_order( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || ! self::is_upgrade_order( $order ) ) {
			return;
		}
		$state = (string) $order->get_meta( self::META_STATE, true );
		if ( $order->is_paid() ) {
			if ( in_array( $state, array( '', self::STATE_CANCELLED ), true ) ) {
				self::handle_paid_order( $order_id );
			}
			return;
		}
		if ( self::STATE_APPLIED === $state ) {
			self::handle_cancelled_order( $order_id );
			return;
		}
		self::close_if_stale( $order );
	}

	/**
	 * Read a stored gross/net flag of an upgrade order.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $key Meta key.
	 * @param bool|null $missing Value for orders without the meta (created before it was recorded).
	 * @return bool|null
	 */
	private static function meta_flag( $order, string $key, ?bool $missing ): ?bool {
		if ( ! $order->meta_exists( $key ) ) {
			return $missing;
		}
		$value = (string) $order->get_meta( $key, true );

		return '1' === $value ? true : ( '0' === $value ? false : null );
	}

	/**
	 * Stored form of a gross/net flag.
	 *
	 * @param bool|null $flag Flag.
	 * @return string '1', '0' or '-' (unknown).
	 */
	private static function flag_meta( ?bool $flag ): string {
		return null === $flag ? '-' : ( $flag ? '1' : '0' );
	}

	/**
	 * Store the upgrade state of an order.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $state STATE_* constant.
	 * @return void
	 */
	private static function set_state( $order, string $state ): void {
		$order->update_meta_data( self::META_STATE, $state );
		$order->save();
	}

	/**
	 * Whether a license belongs to a customer.
	 *
	 * @param int $license_id License ID.
	 * @param int $customer_id Customer.
	 * @return bool
	 */
	private static function owned_by( int $license_id, int $customer_id ): bool {
		$license = PlanChange::get_license( $license_id );

		return $license && $customer_id > 0 && (int) $license['customer_id'] === $customer_id;
	}

	/**
	 * Return to the license page with an allow-listed notice.
	 *
	 * @param string $code Result code.
	 * @param string $fallback Code used when $code is not a known notice.
	 * @return void
	 */
	private static function finish( string $code, string $fallback ): void {
		$known = array_merge( self::NOTICE_CODES, array( 'license_not_found' ) );
		CustomerPortal::redirect_with_notice( in_array( $code, $known, true ) ? $code : $fallback );
		exit;
	}

	/**
	 * Customer-facing product name.
	 *
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	private static function product_name( $product ): string {
		return trim( wp_strip_all_tags( (string) $product->get_name() ) );
	}

	/**
	 * Amount with currency for customer texts.
	 *
	 * @param int    $minor Amount in minor units.
	 * @param int    $decimals Precision.
	 * @param string $currency Currency code.
	 * @return string
	 */
	private static function money( int $minor, int $decimals, string $currency ): string {
		$decimal = Money::to_decimal( $minor, $decimals );
		if ( function_exists( 'wc_price' ) ) {
			return html_entity_decode( wp_strip_all_tags( (string) wc_price( (float) $decimal, array( 'currency' => $currency, 'decimals' => $decimals ) ) ), ENT_QUOTES, 'UTF-8' );
		}

		return $decimal . ' ' . $currency;
	}

	/**
	 * Date in the site's format and timezone.
	 *
	 * @param string $utc 'Y-m-d H:i:s' UTC.
	 * @return string
	 */
	private static function date_label( string $utc ): string {
		return \WPLC\Admin\Format::date( $utc );
	}
}
