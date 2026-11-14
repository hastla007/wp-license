<?php
/**
 * "License revoked" customer email.
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
 * Tells the customer that a license was revoked and how to reach support.
 */
final class LicenseRevokedEmail extends LicenseEmail {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			'wplc_license_revoked',
			'wplc-license-blocked.php',
			__( 'License revoked', 'wp-license' ),
			__( 'Sent to the customer when a license is revoked, for example after a refund. Names the support contact.', 'wp-license' )
		);
	}

	/**
	 * Default subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( 'Your {product} license has been revoked', 'wp-license' );
	}

	/**
	 * Default heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'Your license has been revoked', 'wp-license' );
	}

	/**
	 * License status the preview sample describes.
	 *
	 * @return string
	 */
	protected function preview_status(): string {
		return 'revoked';
	}
}
