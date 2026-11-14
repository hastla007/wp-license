<?php
/**
 * "Cancellation confirmed" customer email.
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
 * Confirms a cancellation of the automatic renewal in text form: when it was
 * received and when the license ends.
 */
final class CancellationConfirmedEmail extends LicenseEmail {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			'wplc_cancellation_confirmed',
			'wplc-cancellation-confirmed.php',
			__( 'License cancellation confirmed', 'wp-license' ),
			__( 'Sent to the customer when the automatic renewal of a license was cancelled, in the account or on the public cancellation page. Confirms the time of the cancellation and the end of the license.', 'wp-license' )
		);
	}

	/**
	 * Default subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( 'Confirmation: automatic renewal of {product} cancelled', 'wp-license' );
	}

	/**
	 * Default heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'Your cancellation is confirmed', 'wp-license' );
	}
}
