<?php
/**
 * "License expired" customer email.
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
 * Tells the customer that the license has ended.
 */
final class LicenseExpiredEmail extends LicenseEmail {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			'wplc_license_expired',
			'wplc-license-expired.php',
			__( 'License expired', 'wp-license' ),
			__( 'Sent when a license expires.', 'wp-license' )
		);
	}

	/**
	 * Default subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( 'Your {product} license has expired', 'wp-license' );
	}

	/**
	 * Default heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'Your license has expired', 'wp-license' );
	}
}
