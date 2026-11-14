<?php
/**
 * Handles verified Stripe events that are not PaymentIntent results.
 *
 * @package WPLC
 */

namespace WPLC\Gateway;

use WPLC\License\AuditLog;
use WPLC\License\LicenseLifecycle;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dispute follow-ups and fraud warnings (B-148).
 *
 * None of these events changes a license. A won dispute does not lift the
 * suspension by itself: whether the customer gets the license back is the
 * shop's decision. The events are written to the order and the audit log and
 * announced through actions.
 */
final class StripeEventHandler {

	/**
	 * Event types handled here.
	 *
	 * @var string[]
	 */
	public const EVENT_TYPES = array(
		'charge.dispute.closed',
		'charge.dispute.funds_reinstated',
		'radar.early_fraud_warning.created',
	);

	/**
	 * Whether an event type is handled by this class.
	 *
	 * @param string $event_type Stripe event type.
	 * @return bool
	 */
	public static function handles( string $event_type ): bool {
		return in_array( $event_type, self::EVENT_TYPES, true );
	}

	/**
	 * Process one stored event.
	 *
	 * @param string              $event_type Stripe event type.
	 * @param array<string,mixed> $payload Minimized payload from the inbox.
	 * @return string Inbox outcome: `processed` or `ignored`.
	 */
	public static function handle( string $event_type, array $payload ): string {
		$order_id = absint( $payload['order_id'] ?? 0 );
		$order = $order_id && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		$license_ids = LicenseLifecycle::license_ids_for_order( $order_id );
		if ( ! $order instanceof \WC_Order || ! $license_ids ) {
			return 'ignored';
		}

		if ( 'radar.early_fraud_warning.created' === $event_type ) {
			$warning_id = sanitize_text_field( (string) ( $payload['warning_id'] ?? '' ) );
			$fraud_type = sanitize_key( (string) ( $payload['fraud_type'] ?? '' ) );
			$order->add_order_note(
				sprintf(
					/* translators: 1: Stripe early fraud warning ID, 2: fraud type. */
					__( 'WP License Captain: Stripe reported an early fraud warning %1$s (%2$s) for this payment. Nothing was changed. A refund now usually avoids a dispute.', 'wp-license' ),
					$warning_id,
					'' !== $fraud_type ? $fraud_type : '-'
				)
			);
			AuditLog::record( 'stripe_early_fraud_warning', 'order', $order_id, $warning_id . ' ' . $fraud_type, null, array( 'licenses' => $license_ids ) );
			/**
			 * Fires when Stripe reports an early fraud warning for a license payment. Alert only.
			 *
			 * @param int    $order_id Order ID.
			 * @param int[]  $license_ids Licenses paid by the order.
			 * @param string $warning_id Stripe early fraud warning ID.
			 * @param string $fraud_type Fraud type reported by the card issuer.
			 */
			do_action( 'wplc/stripe_early_fraud_warning', $order_id, $license_ids, $warning_id, $fraud_type );

			return 'processed';
		}

		$dispute_id = sanitize_text_field( (string) ( $payload['dispute_id'] ?? '' ) );
		if ( 'charge.dispute.funds_reinstated' === $event_type ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: Stripe dispute ID. */
					__( 'WP License Captain: Stripe returned the disputed amount of dispute %s. The licenses were not changed.', 'wp-license' ),
					$dispute_id
				)
			);
			AuditLog::record( 'dispute_funds_reinstated', 'order', $order_id, $dispute_id, null, array( 'licenses' => $license_ids ) );
			/**
			 * Fires when Stripe returned the money of a dispute.
			 *
			 * @param int    $order_id Order ID.
			 * @param int[]  $license_ids Licenses paid by the order.
			 * @param string $dispute_id Stripe dispute ID.
			 */
			do_action( 'wplc/dispute_funds_reinstated', $order_id, $license_ids, $dispute_id );

			return 'processed';
		}

		$status = sanitize_key( (string) ( $payload['dispute_status'] ?? '' ) );
		if ( 'won' === $status ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: Stripe dispute ID. */
					__( 'WP License Captain: dispute %s was decided for the shop. Suspended licenses stay suspended until an administrator reactivates them.', 'wp-license' ),
					$dispute_id
				)
			);
			AuditLog::record( 'dispute_won', 'order', $order_id, $dispute_id, null, array( 'licenses' => $license_ids ) );
			foreach ( $license_ids as $license_id ) {
				/**
				 * Fires when a dispute about a license payment was decided for the shop.
				 *
				 * The license is not reactivated automatically.
				 *
				 * @param int    $license_id License ID.
				 * @param int    $order_id Disputed order.
				 * @param string $dispute_id Stripe dispute ID.
				 */
				do_action( 'wplc/dispute_won', (int) $license_id, $order_id, $dispute_id );
			}

			return 'processed';
		}

		$order->add_order_note(
			sprintf(
				/* translators: 1: Stripe dispute ID, 2: dispute status, e.g. lost. */
				__( 'WP License Captain: dispute %1$s was closed with status "%2$s".', 'wp-license' ),
				$dispute_id,
				'' !== $status ? $status : '-'
			)
		);
		AuditLog::record( 'dispute_closed', 'order', $order_id, $dispute_id . ' ' . $status, null, array( 'licenses' => $license_ids ) );
		/**
		 * Fires when a dispute about a license payment was closed without being won.
		 *
		 * @param int    $order_id Disputed order.
		 * @param int[]  $license_ids Licenses paid by the order.
		 * @param string $dispute_id Stripe dispute ID.
		 * @param string $status Dispute status, e.g. `lost` or `warning_closed`.
		 */
		do_action( 'wplc/dispute_closed', $order_id, $license_ids, $dispute_id, $status );

		return 'processed';
	}
}
