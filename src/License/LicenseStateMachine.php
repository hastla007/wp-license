<?php
/**
 * Allowed license lifecycle transitions.
 *
 * @package WPLC
 */

namespace WPLC\License;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Explicit transition policy shared by lifecycle and renewal handlers.
 */
final class LicenseStateMachine {
	/**
	 * Days before paid_until from which, and after paid_until until which, a
	 * customer may renew manually. An expired license can only be reactivated
	 * by a manual renewal payment inside this window.
	 *
	 * This is the default of the two settings; read the effective values with
	 * manual_renewal_window().
	 */
	public const MANUAL_RENEWAL_WINDOW_DAYS = 30;

	/** @var array<string,array<string,string[]>> */
	private const TRANSITIONS = array(
		'order_refund' => array(
			'active' => array( 'revoked' ),
			'past_due' => array( 'revoked' ),
			'suspended' => array( 'revoked' ),
		),
		'renewal_failed' => array(
			'active' => array( 'past_due' ),
		),
		'renewal_succeeded' => array(
			'active' => array( 'active' ),
			'past_due' => array( 'active' ),
		),
		'chargeback' => array(
			'active' => array( 'suspended' ),
			'past_due' => array( 'suspended' ),
		),
		// A paid order was cancelled or failed afterwards (bounced debit): reversible, unlike a refund.
		'order_cancelled' => array(
			'active' => array( 'suspended' ),
			'past_due' => array( 'suspended' ),
		),
		// Undo of a revocation (e.g. an order set to "refunded" by mistake; needs its own permission in
		// the UI) and the way back for a suspended license, also when its term ended meanwhile.
		'admin_restore' => array(
			'revoked' => array( 'active' ),
			'suspended' => array( 'active' ),
		),
		'license_expired' => array(
			'active' => array( 'expired' ),
			'past_due' => array( 'expired' ),
		),
		'admin_status_change' => array(
			'active' => array( 'suspended', 'revoked' ),
			'past_due' => array( 'active', 'suspended', 'revoked' ),
			// `expired` is where a suspended license goes when its term ended while it was suspended.
			'suspended' => array( 'active', 'revoked', 'expired' ),
			'expired' => array( 'active', 'suspended', 'revoked' ),
		),
		'admin_extend' => array(
			'active' => array( 'active' ),
			'past_due' => array( 'active' ),
			'expired' => array( 'active' ),
		),
	);

	/**
	 * Check whether an action may move a license between two states.
	 *
	 * @param string $from Current state.
	 * @param string $to Requested state.
	 * @param string $action Lifecycle action.
	 * @return bool
	 */
	public static function allows( string $from, string $to, string $action ): bool {
		return isset( self::TRANSITIONS[ $action ][ $from ] )
			&& in_array( $to, self::TRANSITIONS[ $action ][ $from ], true );
	}

	/**
	 * Whether an authenticated successful payment may renew this license state.
	 *
	 * This is the rule for automatic charges: they never apply to expired,
	 * suspended or revoked licenses.
	 *
	 * @param string $status Current license state.
	 * @return bool
	 */
	public static function can_apply_renewal_payment( string $status ): bool {
		return self::allows( $status, 'active', 'renewal_succeeded' );
	}

	/**
	 * Whether a customer-initiated (manual) renewal payment may renew this license.
	 *
	 * Same as an automatic renewal, plus the one exception `expired -> active`
	 * while the payment falls inside the manual renewal window after paid_until.
	 *
	 * @param string $status Current license state.
	 * @param int    $paid_until Unix timestamp of paid_until, zero when unknown.
	 * @param int    $paid_at Unix timestamp of the payment.
	 * @return bool
	 */
	public static function can_apply_manual_renewal( string $status, int $paid_until, int $paid_at ): bool {
		if ( self::can_apply_renewal_payment( $status ) ) {
			return true;
		}

		return 'expired' === $status
			&& $paid_until > 0
			&& $paid_at <= $paid_until + self::renewal_days_after() * 86400;
	}

	/**
	 * The window of manual renewal in days before and after paid_until (SET-LIC-16).
	 *
	 * Settings "Manual renewal: days before the end" and "… after the end" (30 and 30 by
	 * default), then the filter `wplc_manual_renewal_window_days`, which wins. Each value is
	 * kept between 0 and 365.
	 *
	 * @return array{before:int,after:int}
	 */
	public static function manual_renewal_window(): array {
		$window = array(
			'before' => \WPLC\Settings\Registry::int( 'manual_renewal_days_before' ),
			'after'  => \WPLC\Settings\Registry::int( 'manual_renewal_days_after' ),
		);
		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filters the window of manual renewal. Wins over the two settings.
			 *
			 * @since 0.2.0
			 *
			 * @param array{before:int,after:int} $window Days before and after the end of the paid period.
			 */
			$filtered = apply_filters( 'wplc_manual_renewal_window_days', $window );
			if ( is_array( $filtered ) ) {
				foreach ( array( 'before', 'after' ) as $side ) {
					if ( isset( $filtered[ $side ] ) && is_numeric( $filtered[ $side ] ) ) {
						$window[ $side ] = (int) $filtered[ $side ];
					}
				}
			}
		}

		return array(
			'before' => max( 0, min( 365, $window['before'] ) ),
			'after'  => max( 0, min( 365, $window['after'] ) ),
		);
	}

	/**
	 * Days before paid_until from which a customer may renew manually.
	 *
	 * @return int
	 */
	public static function renewal_days_before(): int {
		return self::manual_renewal_window()['before'];
	}

	/**
	 * Days after paid_until until which an expired license may still be renewed manually.
	 *
	 * @return int
	 */
	public static function renewal_days_after(): int {
		return self::manual_renewal_window()['after'];
	}
}
