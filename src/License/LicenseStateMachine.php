<?php
/**
 * Allowed license lifecycle transitions.
 *
 * @package WPLC
 */

namespace WPLC\License;

/**
 * Explicit transition policy shared by lifecycle and renewal handlers.
 */
final class LicenseStateMachine {
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
		'license_expired' => array(
			'active' => array( 'expired' ),
			'past_due' => array( 'expired' ),
		),
		'admin_status_change' => array(
			'active' => array( 'suspended', 'revoked' ),
			'past_due' => array( 'active', 'suspended', 'revoked' ),
			'suspended' => array( 'active', 'revoked' ),
			'expired' => array( 'active', 'suspended', 'revoked' ),
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
	 * @param string $status Current license state.
	 * @return bool
	 */
	public static function can_apply_renewal_payment( string $status ): bool {
		return self::allows( $status, 'active', 'renewal_succeeded' );
	}
}
