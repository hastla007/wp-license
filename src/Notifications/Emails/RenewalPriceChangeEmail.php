<?php
/**
 * "New renewal price" customer email (INH-05).
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
 * Announces a higher renewal price of an existing license, a notice period ahead (INH-05).
 *
 * Names the current price, the new price, the date from which it applies and how to cancel.
 * The new price is only ever charged when this email was accepted by the mailer at least the
 * notice period before. The wording is subject to legal review (T-93).
 */
final class RenewalPriceChangeEmail extends LicenseEmail {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			'wplc_price_change',
			'wplc-price-change.php',
			__( 'License renewal: new price announced', 'wp-license' ),
			__( 'Sent when the shop announces a higher renewal price for an existing license. Names the current and the new price, the date from which the new price applies, and how to cancel. The new price is only charged when this email was sent at least the notice period before.', 'wp-license' )
		);
	}

	/**
	 * Default subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( 'The renewal price of your {product} license changes', 'wp-license' );
	}

	/**
	 * Default heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'New renewal price for your license', 'wp-license' );
	}
}
