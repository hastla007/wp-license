<?php
/**
 * "Renewal payment failed" customer email.
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
 * Asks the customer to complete a failed renewal payment within the grace period.
 */
final class PaymentFailedEmail extends LicenseEmail {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			'wplc_payment_failed',
			'wplc-payment-failed.php',
			__( 'License renewal payment failed', 'wp-license' ),
			__( 'Sent when an automatic renewal payment fails and the license becomes past due.', 'wp-license' )
		);
	}

	/**
	 * Default subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( 'Action required: renewal payment for {product} failed', 'wp-license' );
	}

	/**
	 * Default heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'Your renewal payment failed', 'wp-license' );
	}
}
