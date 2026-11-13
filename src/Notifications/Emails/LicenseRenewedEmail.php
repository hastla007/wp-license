<?php
/**
 * "License renewed" customer email.
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
 * Confirms a renewal and states the new valid-until date.
 */
final class LicenseRenewedEmail extends LicenseEmail {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			'wplc_license_renewed',
			'wplc-license-renewed.php',
			__( 'License renewed', 'wp-license' ),
			__( 'Sent when a license has been renewed, manually or automatically.', 'wp-license' )
		);
	}

	/**
	 * Default subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( 'Your {product} license is renewed until {valid_until}', 'wp-license' );
	}

	/**
	 * Default heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'Your license has been renewed', 'wp-license' );
	}
}
