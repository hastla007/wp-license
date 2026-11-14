<?php
/**
 * "Saved card no longer available" customer email (B-192).
 *
 * @package WPLC
 */

namespace WPLC\Notifications\Emails;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\\WC_Email' ) ) {
	return;
}

/**
 * Tells the customer that the card saved for the automatic renewal is gone (B-192).
 *
 * No other card of the customer is charged in its place: the customer adds a
 * card and switches automatic renewal on again, or renews manually.
 */
final class PaymentMethodMissingEmail extends LicenseEmail {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			'wplc_payment_method_missing',
			'wplc-payment-method-missing.php',
			__( 'License renewal: saved card no longer available', 'wp-license' ),
			__( 'Sent once per period when an automatic renewal cannot be charged because the saved card no longer exists. Asks the customer to add a card or to renew manually.', 'wp-license' )
		);
	}

	/**
	 * Default subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( 'Action required: the card for your {product} license is no longer available', 'wp-license' );
	}

	/**
	 * Default heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'Your saved card is no longer available', 'wp-license' );
	}
}
