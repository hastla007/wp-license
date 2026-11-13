<?php
/**
 * Expiry reminder for licenses without automatic renewal.
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
 * Reminds the customer to renew before the paid period ends.
 */
final class ExpiryReminderEmail extends LicenseEmail {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			'wplc_expiry_reminder',
			'wplc-expiry-reminder.php',
			__( 'License expiry reminder', 'wp-license' ),
			__( 'Sent before a license without automatic renewal (or with a scheduled cancellation) ends. Default: 30 and 7 days before.', 'wp-license' )
		);
	}

	/**
	 * Default subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( 'Your {product} license ends on {valid_until}', 'wp-license' );
	}

	/**
	 * Default heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'Your license is about to end', 'wp-license' );
	}
}
