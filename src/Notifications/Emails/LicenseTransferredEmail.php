<?php
/**
 * "License transferred" email (B-139).
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
 * Sent to the previous and to the new owner when a transfer was accepted. The
 * template tells each side what changed (`$sharing['role']`: previous or new).
 */
final class LicenseTransferredEmail extends LicenseSharingEmail {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			'wplc_license_transferred',
			'wplc-license-transferred.php',
			__( 'License transferred', 'wp-license' ),
			__( 'Sent to the previous and to the new owner when the recipient accepted a license transfer. Automatic renewal ends with the transfer.', 'wp-license' )
		);
	}

	/**
	 * Default subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( 'License for {product} transferred', 'wp-license' );
	}

	/**
	 * Default heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'The license was transferred', 'wp-license' );
	}
}
