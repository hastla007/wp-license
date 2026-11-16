<?php
/**
 * "Co-user invitation" email (B-139).
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
 * Sent to the invited address when the license owner invites a co-user. Carries the
 * single-use accept link.
 */
final class MemberInvitationEmail extends LicenseSharingEmail {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			'wplc_member_invitation',
			'wplc-member-invitation.php',
			__( 'License co-user invitation', 'wp-license' ),
			__( 'Sent to an email address the license owner invited as a co-user. Contains the link to accept the invitation; it works once and expires after 14 days by default.', 'wp-license' )
		);
	}

	/**
	 * Default subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return __( 'You have been invited to use a license for {product}', 'wp-license' );
	}

	/**
	 * Default heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'Invitation to a license', 'wp-license' );
	}
}
