<?php
/**
 * Language-neutral reason codes for audit entries and license status reasons.
 *
 * @package WPLC
 */

namespace WPLC\License;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores system reasons as a stable code and translates them when they are shown.
 *
 * Reasons are not translated when they are written: the entry would be in the
 * language of whoever triggered it (often a cron run). The stored form is the
 * code, optionally followed by a space and a JSON object of placeholder values:
 *
 *     renewed_from_order {"order":123}
 *
 * Reasons an administrator typed, rows written before reason codes existed and
 * codes this version does not know are shown as stored.
 */
final class AuditReason {

	public const INSTALLATION_ACTIVATED         = 'installation_activated';
	public const INSTALLATION_REPLACED          = 'installation_replaced';
	public const DEACTIVATED_BY_INSTALLATION    = 'deactivated_by_installation';
	public const DEACTIVATED_BY_OWNER           = 'deactivated_by_owner';
	public const STAGING_DEACTIVATED_WITH_LIVE  = 'staging_deactivated_with_live';
	public const REFUNDED_ORDER_UNIT            = 'refunded_order_unit';
	public const ORDER_REFUNDED                 = 'order_refunded';
	public const RENEWAL_ORDER_REFUNDED         = 'renewal_order_refunded';
	public const RENEWAL_PARTIALLY_REFUNDED     = 'renewal_partially_refunded';
	public const RENEWED_FROM_ORDER             = 'renewed_from_order';
	public const RENEWED_MANUALLY_FROM_ORDER    = 'renewed_manually_from_order';
	public const GRACE_PERIOD_ENDED             = 'grace_period_ended';
	public const PAID_PERIOD_ENDED              = 'paid_period_ended';
	public const CANCELLATION_REQUESTED         = 'cancellation_requested';
	public const CANCELLATION_WITHDRAWN         = 'cancellation_withdrawn';
	public const LICENSE_KEY_ID_RECOVERED       = 'license_key_id_recovered';
	public const INSTALLATION_SECRET_ROTATED    = 'installation_secret_rotated';
	public const SIGNING_KEY_ROTATED            = 'signing_key_rotated';
	public const MANUAL_RENEWAL_STARTED         = 'manual_renewal_started';
	public const MANUAL_RENEWAL_PAID            = 'manual_renewal_paid';
	public const MANUAL_PERIOD_CHANGED          = 'manual_period_changed';
	public const MANUAL_RENEWAL_NOT_ALLOWED     = 'manual_renewal_not_allowed';
	public const MANUAL_PRICE_MISMATCH          = 'manual_price_mismatch';
	public const RENEWAL_ALREADY_PAID           = 'renewal_already_paid';
	public const RENEWAL_CONFIRMED_BY_ADAPTER   = 'renewal_confirmed_by_adapter';
	public const STRIPE_SUCCESS_RECONCILED      = 'stripe_success_reconciled';
	public const CUSTOMER_PAID_RENEWAL          = 'customer_paid_renewal';
	public const STUCK_RENEWAL_PAID             = 'stuck_renewal_paid';
	public const PAYMENT_NOT_TAKEN              = 'payment_not_taken';
	public const STRIPE_DISPUTE                 = 'stripe_dispute';
	public const STRIPE_METADATA_MISMATCH       = 'stripe_metadata_mismatch';
	public const STRIPE_EVENT_FOR_CLOSED_RECORD = 'stripe_event_for_closed_record';
	public const STRIPE_AMOUNT_MISMATCH         = 'stripe_amount_mismatch';
	public const RECOVERY_METADATA_MISMATCH     = 'recovery_metadata_mismatch';
	public const RECOVERY_NOT_ALLOWED           = 'recovery_not_allowed';
	public const RECOVERY_AMOUNT_MISMATCH       = 'recovery_amount_mismatch';
	public const MANUAL_LICENSE_NOT_EXTENDED    = 'manual_license_not_extended';
	public const LICENSE_CHANGED_DURING_CHARGE  = 'license_changed_during_charge';
	public const LICENSE_CHANGED_WHILE_UNKNOWN  = 'license_changed_while_unknown';
	public const PAID_AFTER_REVIEW_CLOSED       = 'paid_after_review_closed';
	public const REVIEW_BOOKED                  = 'review_booked';
	public const REVIEW_NOT_PAID                = 'review_not_paid';
	public const REVIEW_CLOSED                  = 'review_closed';
	public const RENEWED_AFTER_REVIEW           = 'renewed_after_review';

	/**
	 * Build the stored form of a reason.
	 *
	 * @param string                    $code Reason code (lowercase letters, digits, underscores).
	 * @param array<string,int|string> $params Placeholder values; only scalars are kept.
	 * @return string
	 */
	public static function encode( string $code, array $params = array() ): string {
		$code = self::normalize_code( $code );
		$clean = array();
		foreach ( $params as $name => $value ) {
			$name = self::normalize_code( (string) $name );
			if ( '' === $name || ! is_scalar( $value ) ) {
				continue;
			}
			$clean[ $name ] = is_int( $value ) ? $value : (string) $value;
		}
		if ( '' === $code || ! $clean ) {
			return $code;
		}
		$json = function_exists( 'wp_json_encode' )
			? wp_json_encode( $clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG )
			: json_encode( $clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG );

		return false === $json ? $code : $code . ' ' . $json;
	}

	/**
	 * Split a stored reason into code and placeholder values.
	 *
	 * @param string $stored Stored reason.
	 * @return array{code:string,params:array<string,mixed>}|null Null for free text.
	 */
	public static function parse( string $stored ): ?array {
		if ( ! preg_match( '/^([a-z0-9_]+)(?: (\{.*\}))?$/s', trim( $stored ), $match ) ) {
			return null;
		}
		$params = array();
		if ( isset( $match[2] ) && '' !== $match[2] ) {
			$decoded = json_decode( $match[2], true );
			if ( ! is_array( $decoded ) ) {
				return null;
			}
			$params = $decoded;
		}

		return array(
			'code'   => $match[1],
			'params' => $params,
		);
	}

	/**
	 * Text for a stored reason in the current language.
	 *
	 * @param string $stored Stored reason: a code, a code with values, or free text.
	 * @return string
	 */
	public static function label( string $stored ): string {
		$stored = trim( $stored );
		if ( '' === $stored ) {
			return '';
		}
		$parsed = self::parse( $stored );
		if ( null === $parsed ) {
			return $stored;
		}
		$messages = self::messages();
		if ( ! isset( $messages[ $parsed['code'] ] ) ) {
			return $stored;
		}
		list( $template, $names ) = $messages[ $parsed['code'] ];
		$values = array();
		foreach ( $names as $name ) {
			if ( ! isset( $parsed['params'][ $name ] ) || ! is_scalar( $parsed['params'][ $name ] ) ) {
				// Without its values the sentence would be wrong; show what is stored.
				return $stored;
			}
			$values[] = $parsed['params'][ $name ];
		}

		return $names ? vsprintf( $template, $values ) : $template;
	}

	/**
	 * Whether a code has a translatable text.
	 *
	 * @param string $code Reason code.
	 * @return bool
	 */
	public static function is_known( string $code ): bool {
		return isset( self::messages()[ $code ] );
	}

	/**
	 * Translatable texts by code, with the names of their placeholder values in order.
	 *
	 * Built on every call so that the current locale applies.
	 *
	 * @return array<string,array{0:string,1:array<int,string>}>
	 */
	private static function messages(): array {
		return array(
			// Installations.
			self::INSTALLATION_ACTIVATED         => array( __( 'Product installation activated.', 'wp-license' ), array() ),
			self::INSTALLATION_REPLACED          => array( __( 'Replaced by a new installation of the same site.', 'wp-license' ), array() ),
			self::DEACTIVATED_BY_INSTALLATION    => array( __( 'Deactivated by the installation owner.', 'wp-license' ), array() ),
			self::DEACTIVATED_BY_OWNER           => array( __( 'Deactivated by the license owner.', 'wp-license' ), array() ),
			self::STAGING_DEACTIVATED_WITH_LIVE  => array( __( 'Staging site deactivated together with its live site.', 'wp-license' ), array() ),
			// License lifecycle.
			self::REFUNDED_ORDER_UNIT            => array( __( 'The order unit of this license was refunded.', 'wp-license' ), array() ),
			self::ORDER_REFUNDED                 => array( __( 'The order was refunded in full.', 'wp-license' ), array() ),
			/* translators: %d: WooCommerce order number. */
			self::RENEWAL_ORDER_REFUNDED         => array( __( 'Renewal order #%d was refunded in full.', 'wp-license' ), array( 'order' ) ),
			/* translators: 1: WooCommerce order number, 2: refunded amount, 3: currency code. */
			self::RENEWAL_PARTIALLY_REFUNDED     => array( __( 'Renewal order #%1$d was partially refunded (%2$s %3$s); the license term is unchanged.', 'wp-license' ), array( 'order', 'amount', 'currency' ) ),
			/* translators: %d: WooCommerce order number. */
			self::RENEWED_FROM_ORDER             => array( __( 'Renewed from WooCommerce order #%d.', 'wp-license' ), array( 'order' ) ),
			/* translators: %d: WooCommerce order number. */
			self::RENEWED_MANUALLY_FROM_ORDER    => array( __( 'Manually renewed from WooCommerce order #%d.', 'wp-license' ), array( 'order' ) ),
			self::GRACE_PERIOD_ENDED             => array( __( 'The grace period of the automatic renewal ended.', 'wp-license' ), array() ),
			self::PAID_PERIOD_ENDED              => array( __( 'The paid period ended.', 'wp-license' ), array() ),
			self::CANCELLATION_REQUESTED         => array( __( 'The customer requested the cancellation.', 'wp-license' ), array() ),
			self::CANCELLATION_WITHDRAWN         => array( __( 'The cancellation was withdrawn.', 'wp-license' ), array() ),
			// Maintenance commands.
			self::LICENSE_KEY_ID_RECOVERED       => array( __( 'License key ID corrected after matching the stored verifier.', 'wp-license' ), array() ),
			self::INSTALLATION_SECRET_ROTATED    => array( __( 'Installation secret encrypted with the configured current key.', 'wp-license' ), array() ),
			self::SIGNING_KEY_ROTATED            => array( __( 'Response signing key rotated.', 'wp-license' ), array() ),
			// Manual renewals.
			self::MANUAL_RENEWAL_STARTED         => array( __( 'The customer started a manual renewal.', 'wp-license' ), array() ),
			self::MANUAL_RENEWAL_PAID            => array( __( 'The customer paid a manual renewal order.', 'wp-license' ), array() ),
			self::MANUAL_PERIOD_CHANGED          => array( __( 'The license period changed after the renewal order was created.', 'wp-license' ), array() ),
			self::MANUAL_RENEWAL_NOT_ALLOWED     => array( __( 'The license status or renewal window did not allow a manual renewal.', 'wp-license' ), array() ),
			self::MANUAL_PRICE_MISMATCH          => array( __( 'The paid order line did not match the renewal price.', 'wp-license' ), array() ),
			self::MANUAL_LICENSE_NOT_EXTENDED    => array( __( 'The license could not be extended when the paid manual renewal was booked.', 'wp-license' ), array() ),
			// Review of renewals that need an administrator (T-154, T-137). The note is the administrator's own text.
			/* translators: %s: reason entered by the administrator. */
			self::REVIEW_BOOKED                  => array( __( 'Renewal booked by an administrator after review: %s', 'wp-license' ), array( 'note' ) ),
			/* translators: %s: reason entered by the administrator. */
			self::REVIEW_NOT_PAID                => array( __( 'Marked as not paid by an administrator after review: %s', 'wp-license' ), array( 'note' ) ),
			/* translators: %s: reason entered by the administrator. */
			self::REVIEW_CLOSED                  => array( __( 'Review closed without booking the renewal: %s', 'wp-license' ), array( 'note' ) ),
			/* translators: 1: WooCommerce order number, 2: reason entered by the administrator. */
			self::RENEWED_AFTER_REVIEW           => array( __( 'Renewed by an administrator after reviewing order #%1$d: %2$s', 'wp-license' ), array( 'order', 'note' ) ),
			self::LICENSE_CHANGED_WHILE_UNKNOWN  => array( __( 'The license was changed while the payment result was still unknown.', 'wp-license' ), array() ),
			self::PAID_AFTER_REVIEW_CLOSED       => array( __( 'The renewal order was paid after its review had been closed without booking.', 'wp-license' ), array() ),
			// Automatic renewals and payment events.
			self::RENEWAL_ALREADY_PAID           => array( __( 'The renewal order was already paid.', 'wp-license' ), array() ),
			self::RENEWAL_CONFIRMED_BY_ADAPTER   => array( __( 'The payment gateway confirmed the renewal.', 'wp-license' ), array() ),
			self::STRIPE_SUCCESS_RECONCILED      => array( __( 'A verified successful Stripe payment was booked.', 'wp-license' ), array() ),
			self::CUSTOMER_PAID_RENEWAL          => array( __( 'The customer paid the renewal order.', 'wp-license' ), array() ),
			self::STUCK_RENEWAL_PAID             => array( __( 'A stuck renewal had a paid order.', 'wp-license' ), array() ),
			self::PAYMENT_NOT_TAKEN              => array( __( 'The renewal order was set to failed: the payment was not taken.', 'wp-license' ), array() ),
			/* translators: 1: Stripe dispute ID, 2: dispute reason reported by Stripe (a code such as "fraudulent"). */
			self::STRIPE_DISPUTE                 => array( __( 'Stripe dispute %1$s (reason: %2$s).', 'wp-license' ), array( 'dispute', 'reason' ) ),
			self::STRIPE_METADATA_MISMATCH       => array( __( 'The Stripe event metadata did not match its renewal order.', 'wp-license' ), array() ),
			self::STRIPE_EVENT_FOR_CLOSED_RECORD => array( __( 'A payment event arrived for a cancelled, expired, refunded, revoked or suspended record.', 'wp-license' ), array() ),
			self::STRIPE_AMOUNT_MISMATCH         => array( __( 'The Stripe amount or currency did not match the WooCommerce renewal order.', 'wp-license' ), array() ),
			self::RECOVERY_METADATA_MISMATCH     => array( __( 'The metadata of the paid renewal order did not match its license.', 'wp-license' ), array() ),
			self::RECOVERY_NOT_ALLOWED           => array( __( 'The renewal status did not allow a customer payment recovery.', 'wp-license' ), array() ),
			self::RECOVERY_AMOUNT_MISMATCH       => array( __( 'The paid recovery order did not match the stored renewal amount.', 'wp-license' ), array() ),
			// Review reasons of a renewal whose payment result is unknown.
			'license_changed_during_charge'      => array( __( 'The renewal was paid, but the license status changed while the payment was running, so the license was not extended.', 'wp-license' ), array() ),
			'license_missing'                    => array( __( 'The license of this renewal no longer exists.', 'wp-license' ), array() ),
			'license_not_renewable'              => array( __( 'The license can no longer be renewed.', 'wp-license' ), array() ),
			'renewal_cancelled'                  => array( __( 'The renewal was cancelled.', 'wp-license' ), array() ),
			'renewal_order_missing'              => array( __( 'The renewal order no longer exists.', 'wp-license' ), array() ),
			'renewal_order_closed'               => array( __( 'The renewal order was closed.', 'wp-license' ), array() ),
			'result_unknown_timeout'             => array( __( 'The payment result stayed unknown for too long.', 'wp-license' ), array() ),
			// Error codes of failed renewal attempts (same texts as the errors themselves).
			'renewal_payment_failed'             => array( __( 'The payment gateway did not confirm the renewal.', 'wp-license' ), array() ),
			'renewal_method_unavailable'         => array( __( 'A verified automatic renewal payment method is unavailable.', 'wp-license' ), array() ),
			'renewal_awaiting_customer_payment'  => array( __( 'The renewal order is waiting for a payment started by the customer.', 'wp-license' ), array() ),
			'renewal_order_lookup_failed'        => array( __( 'Existing renewal orders could not be checked safely.', 'wp-license' ), array() ),
			'renewal_order_ambiguous'            => array( __( 'Multiple WooCommerce orders reference this license renewal; manual review is required.', 'wp-license' ), array() ),
			'renewal_order_conflict'             => array( __( 'An existing WooCommerce order conflicts with this license renewal.', 'wp-license' ), array() ),
			'renewal_order_link_failed'          => array( __( 'The existing renewal order could not be linked.', 'wp-license' ), array() ),
			'renewal_product_missing'            => array( __( 'The source product is no longer available for renewal.', 'wp-license' ), array() ),
			'renewal_amount_invalid'             => array( __( 'This license has no renewal price.', 'wp-license' ), array() ),
			'woocommerce_unavailable'            => array( __( 'WooCommerce order services are unavailable.', 'wp-license' ), array() ),
			'renewal_payment_not_taken'          =>array( __( 'The renewal order was set to failed after its payment result was checked.', 'wp-license' ), array() ),
			'renewal_result_unknown'             => array( __( 'Stripe did not return a definite result for the renewal payment.', 'wp-license' ), array() ),
			'renewal_manual_action_required'     => array( __( 'The customer must authenticate this payment method before renewal can continue.', 'wp-license' ), array() ),
			'stripe_renewal_order_invalid'       => array( __( 'The renewal order could not be verified.', 'wp-license' ), array() ),
			'stripe_payment_method_unavailable'  => array( __( 'A saved Stripe card is unavailable for this customer.', 'wp-license' ), array() ),
			'stripe_amount_invalid'              => array( __( 'The renewal amount is not valid for Stripe.', 'wp-license' ), array() ),
			'stripe_payment_failed'              => array( __( 'Stripe declined the renewal payment.', 'wp-license' ), array() ),
			'stripe_payment_unconfirmed'         => array( __( 'Stripe did not confirm the renewal payment as succeeded.', 'wp-license' ), array() ),
			'stripe_transaction_missing'         => array( __( 'Stripe did not return a transaction reference.', 'wp-license' ), array() ),
		);
	}

	/**
	 * Reduce a code to lowercase letters, digits and underscores.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	private static function normalize_code( string $code ): string {
		return (string) preg_replace( '/[^a-z0-9_]/', '', strtolower( $code ) );
	}
}
