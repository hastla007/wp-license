<?php
/**
 * Advance notice of an automatic renewal charge.
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
 * Announces amount and date of the next automatic charge and how to cancel.
 */
final class RenewalNoticeEmail extends LicenseEmail {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			'wplc_renewal_notice',
			'wplc-renewal-notice.php',
			__( 'Upcoming automatic renewal', 'wp-license' ),
			__( 'Sent 7 days before a license with automatic renewal is charged.', 'wp-license' )
		);
	}

	/**
	 * Default subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( 'Your {product} license renews on {charge_date}', 'wp-license' );
	}

	/**
	 * Default heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'Upcoming automatic renewal', 'wp-license' );
	}
}
