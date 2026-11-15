<?php
/**
 * "License suspended" customer email.
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
 * Tells the customer that a license was suspended and how to reach support.
 */
final class LicenseSuspendedEmail extends LicenseEmail {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			'wplc_license_suspended',
			'wplc-license-blocked.php',
			__( 'License suspended', 'wp-license' ),
			__( 'Sent to the customer when a license is suspended, for example after a payment dispute. Names the support contact.', 'wp-license' )
		);
	}

	/**
	 * Default subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( 'Your {product} license has been suspended', 'wp-license' );
	}

	/**
	 * Default heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'Your license has been suspended', 'wp-license' );
	}

	/**
	 * License status the preview sample describes.
	 *
	 * @return string
	 */
	protected function preview_status(): string {
		return 'suspended';
	}
}
