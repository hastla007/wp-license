<?php
/**
 * "Announced renewal price withdrawn" customer email (INH-05).
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
 * Tells the customer that an announced renewal price was withdrawn: the license keeps its price (INH-05).
 */
final class RenewalPriceChangeWithdrawnEmail extends LicenseEmail {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			'wplc_price_change_withdrawn',
			'wplc-price-change-withdrawn.php',
			__( 'License renewal: announced price withdrawn', 'wp-license' ),
			__( 'Sent when the shop withdraws an announced renewal price before it took effect. The license keeps its current price.', 'wp-license' )
		);
	}

	/**
	 * Default subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( 'The renewal price of your {product} license stays the same', 'wp-license' );
	}

	/**
	 * Default heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'Your renewal price stays the same', 'wp-license' );
	}
}
