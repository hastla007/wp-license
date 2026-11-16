<?php
/**
 * "License transfer offered" email (B-139).
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
 * Sent to the recipient of a transfer the license owner started. Carries the
 * single-use accept link; the transfer takes effect only when it is accepted.
 */
final class TransferOfferEmail extends LicenseSharingEmail {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			'wplc_transfer_offer',
			'wplc-transfer-offer.php',
			__( 'License transfer offered', 'wp-license' ),
			__( 'Sent to the email address a license owner wants to transfer a license to. Contains the link to accept the transfer; it works once and expires after 14 days by default.', 'wp-license' )
		);
	}

	/**
	 * Default subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( 'A license for {product} is being transferred to you', 'wp-license' );
	}

	/**
	 * Default heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'Accept the license transfer', 'wp-license' );
	}
}
