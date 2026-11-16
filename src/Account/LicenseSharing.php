<?php
/**
 * Co-users and transfers in the customer account (B-139).
 *
 * @package WPLC
 */

namespace WPLC\Account;

use WPLC\Admin\Format;
use WPLC\License\LicenseMembers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The "Sharing" part of a license in My Account and the form handlers behind it.
 *
 * - Owner: invite co-users by email, remove co-users and withdraw invitations,
 *   transfer the license to another customer and cancel an open transfer.
 * - Co-user: sees who shared the license and can leave it.
 * - Anyone signed in who opens an invitation link: a confirmation box with an
 *   "Accept" button; nothing is changed by opening the link.
 *
 * The rules live in LicenseMembers; this class only renders and maps results
 * to the notices of CustomerPortal.
 */
final class LicenseSharing {

	/**
	 * Form actions handled here, with their nonce prefixes.
	 */
	public const ACTIONS = array(
		'wplc_invite_member'      => 'wplc_invite_member_',
		'wplc_remove_member'      => 'wplc_remove_member_',
		'wplc_leave_license'      => 'wplc_leave_license_',
		'wplc_start_transfer'     => 'wplc_start_transfer_',
		'wplc_cancel_transfer'    => 'wplc_cancel_transfer_',
		'wplc_accept_invitation'  => 'wplc_accept_invitation_',
	);

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		// The invitation box is printed by CustomerPortal::output() itself, above the list.
		add_action( 'wplc_account_license_sharing', array( self::class, 'render_section' ), 10, 2 );
	}

	/**
	 * Notices of the sharing forms (merged into CustomerPortal::notice_messages()).
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function notice_messages(): array {
		return array(
			'member_invited'           => array( 'success', __( 'The invitation was sent. The person becomes a co-user once they accept it in their account.', 'wp-license' ) ),
			'member_invite_failed'     => array( 'error', __( 'The invitation could not be sent. Please try again.', 'wp-license' ) ),
			'member_invalid_email'     => array( 'error', __( 'Please enter a valid email address.', 'wp-license' ) ),
			'member_own_email'         => array( 'error', __( 'This is the email address of your own account.', 'wp-license' ) ),
			'member_duplicate'         => array( 'notice', __( 'This person is already a co-user or has an open invitation.', 'wp-license' ) ),
			'member_limit'             => array( 'error', __( 'This license has reached the maximum number of co-users. Remove a co-user or withdraw an invitation first.', 'wp-license' ) ),
			'member_not_allowed'       => array( 'error', __( 'This license cannot be shared or transferred in its current state.', 'wp-license' ) ),
			'member_removed'           => array( 'success', __( 'The co-user was removed.', 'wp-license' ) ),
			'member_not_found'         => array( 'error', __( 'The co-user or invitation was not found.', 'wp-license' ) ),
			'member_left'              => array( 'success', __( 'You no longer use this license. It has been removed from your account.', 'wp-license' ) ),
			'transfer_started'         => array( 'success', __( 'The transfer was offered. The license stays yours until the recipient accepts it in their account.', 'wp-license' ) ),
			'transfer_already_pending' => array( 'notice', __( 'A transfer of this license is already waiting for the recipient. Cancel it first to offer the license to someone else.', 'wp-license' ) ),
			'transfer_confirm_missing' => array( 'error', __( 'Please confirm that automatic renewal ends with the transfer.', 'wp-license' ) ),
			'transfer_cancelled'       => array( 'success', __( 'The transfer was cancelled. The license stays yours.', 'wp-license' ) ),
			'transfer_not_found'       => array( 'notice', __( 'There is no open transfer for this license.', 'wp-license' ) ),
			'invitation_accepted'      => array( 'success', __( 'You are now a co-user of this license. You find it below.', 'wp-license' ) ),
			'transfer_accepted'        => array( 'success', __( 'The license now belongs to your account. It does not renew automatically: renew it before it ends or switch on automatic renewal.', 'wp-license' ) ),
			'invitation_invalid'       => array( 'error', __( 'This invitation link is not valid. It may have been used already or withdrawn.', 'wp-license' ) ),
			'invitation_expired'       => array( 'error', __( 'This invitation has expired. Please ask the license owner for a new one.', 'wp-license' ) ),
			'invitation_wrong_email'   => array( 'error', __( 'This invitation was sent to another email address. Sign in with the account that uses that address, or create one with it.', 'wp-license' ) ),
			'invitation_already_member' => array( 'notice', __( 'You are already a co-user of this license.', 'wp-license' ) ),
			'invitation_own_license'   => array( 'notice', __( 'This license already belongs to your account.', 'wp-license' ) ),
			'invitation_busy'          => array( 'notice', __( 'A renewal of this license is being processed. Please try again in a few minutes.', 'wp-license' ) ),
		);
	}

	/**
	 * Run one of the sharing actions (nonce already verified) and return a notice code.
	 *
	 * @param string              $action Form action.
	 * @param int                 $target License ID, or the invitation ID for wplc_accept_invitation.
	 * @param array<string,mixed> $post Unslashed POST fields.
	 * @return string Key of CustomerPortal::notice_messages().
	 */
	public static function handle( string $action, int $target, array $post ): string {
		$user_id = get_current_user_id();
		$email   = isset( $post['email'] ) && is_string( $post['email'] ) ? sanitize_email( $post['email'] ) : '';

		switch ( $action ) {
			case 'wplc_invite_member':
				return self::code( LicenseMembers::invite( $target, $email, $user_id ), 'member_invited' );

			case 'wplc_remove_member':
				$member_id = isset( $post['member_id'] ) ? absint( $post['member_id'] ) : 0;
				return self::code( LicenseMembers::remove_member( $target, $member_id, $user_id ), 'member_removed' );

			case 'wplc_leave_license':
				return LicenseMembers::RESULT_REMOVED === LicenseMembers::leave( $target, $user_id ) ? 'member_left' : 'license_not_found';

			case 'wplc_start_transfer':
				if ( empty( $post['confirm_transfer'] ) ) {
					return 'transfer_confirm_missing';
				}
				return self::code( LicenseMembers::start_transfer( $target, $email, $user_id ), 'transfer_started' );

			case 'wplc_cancel_transfer':
				$result = LicenseMembers::cancel_transfer( $target, $user_id );
				return LicenseMembers::RESULT_REMOVED === $result ? 'transfer_cancelled' : ( LicenseMembers::RESULT_NOT_OWNER === $result ? 'license_not_found' : 'transfer_not_found' );

			case 'wplc_accept_invitation':
				$token = isset( $post['token'] ) && is_string( $post['token'] ) ? strtolower( trim( $post['token'] ) ) : '';
				return self::code( LicenseMembers::accept( $target, $token, $user_id ), '' );
		}

		return 'action_unavailable';
	}

	/**
	 * Map a LicenseMembers result to a notice code.
	 *
	 * Pure.
	 *
	 * @param string $result  Result code.
	 * @param string $success Notice for a successful owner action.
	 * @return string
	 */
	public static function code( string $result, string $success ): string {
		$map = array(
			LicenseMembers::RESULT_INVITED           => 'member_invited',
			LicenseMembers::RESULT_TRANSFER_STARTED  => 'transfer_started',
			LicenseMembers::RESULT_ACCEPTED_MEMBER   => 'invitation_accepted',
			LicenseMembers::RESULT_ACCEPTED_TRANSFER => 'transfer_accepted',
			LicenseMembers::RESULT_REMOVED           => $success,
			LicenseMembers::RESULT_NOT_FOUND         => 'license_not_found',
			LicenseMembers::RESULT_NOT_OWNER         => 'license_not_found',
			LicenseMembers::RESULT_NOT_ALLOWED       => 'member_not_allowed',
			LicenseMembers::RESULT_INVALID_EMAIL     => 'member_invalid_email',
			LicenseMembers::RESULT_OWN_EMAIL         => 'member_own_email',
			LicenseMembers::RESULT_DUPLICATE         => 'member_duplicate',
			LicenseMembers::RESULT_LIMIT             => 'member_limit',
			LicenseMembers::RESULT_TRANSFER_PENDING  => 'transfer_already_pending',
			LicenseMembers::RESULT_INVALID           => 'invitation_invalid',
			LicenseMembers::RESULT_EXPIRED           => 'invitation_expired',
			LicenseMembers::RESULT_WRONG_EMAIL       => 'invitation_wrong_email',
			LicenseMembers::RESULT_ALREADY_MEMBER    => 'invitation_already_member',
			LicenseMembers::RESULT_IS_OWNER          => 'invitation_own_license',
			LicenseMembers::RESULT_BUSY              => 'invitation_busy',
		);
		if ( LicenseMembers::RESULT_NOT_FOUND === $result && 'member_removed' === $success ) {
			return 'member_not_found';
		}

		return isset( $map[ $result ] ) && '' !== $map[ $result ] ? $map[ $result ] : 'member_invite_failed';
	}

	/**
	 * Print the box of an invitation link the signed-in user opened.
	 *
	 * @return void
	 */
	public static function render_invitation_prompt(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only lookup; accepting needs a nonce.
		$invitation_id = isset( $_GET['wplc_invitation'] ) ? absint( wp_unslash( $_GET['wplc_invitation'] ) ) : 0;
		$token         = isset( $_GET['wplc_token'] ) ? strtolower( sanitize_text_field( wp_unslash( $_GET['wplc_token'] ) ) ) : '';
		// phpcs:enable
		if ( $invitation_id <= 0 || '' === $token || ! is_user_logged_in() ) {
			return;
		}
		$row     = LicenseMembers::find_invitation( $invitation_id, $token );
		$problem = LicenseMembers::check_invitation( $row, get_current_user_id() );
		$code    = '' === $problem ? '' : self::code( $problem, '' );
		$notices = self::notice_messages();

		echo '<section class="wplc-invitation woocommerce-info" role="region" aria-label="' . esc_attr__( 'Invitation', 'wp-license' ) . '">';
		if ( null === $row || '' !== $code ) {
			$message = isset( $notices[ $code ] ) ? $notices[ $code ][1] : $notices['invitation_invalid'][1];
			if ( 'invitation_wrong_email' === $code && null !== $row ) {
				/* translators: %s: masked email address such as j***@example.com. */
				$message .= ' ' . sprintf( __( 'The invitation is for %s.', 'wp-license' ), LicenseMembers::mask_email( (string) $row['email'] ) );
			}
			echo '<p>' . esc_html( $message ) . '</p></section>';
			return;
		}

		$license = \WPLC\Notifications\Notifications::get_license( (int) $row['license_id'] );
		$product = is_array( $license ) ? \WPLC\Notifications\Notifications::product_name( (string) $license['product_key'] ) : '';
		$owner   = get_userdata( (int) $row['invited_by'] );
		$name    = $owner ? (string) $owner->display_name : '';
		if ( LicenseMembers::KIND_TRANSFER === $row['kind'] ) {
			echo '<h3>' . esc_html__( 'License transfer', 'wp-license' ) . '</h3>';
			echo '<p>' . esc_html(
				sprintf(
					/* translators: 1: name of the license owner, 2: product name, 3: license reference such as WPLC-1A2B3C4D. */
					__( '%1$s wants to transfer the license %3$s for %2$s to your account. After you accept, the license is yours; it does not renew automatically, and you can renew it or switch on automatic renewal with your own payment method.', 'wp-license' ),
					$name,
					$product,
					is_array( $license ) ? (string) $license['key_prefix'] : ''
				)
			) . '</p>';
			$label = __( 'Accept the transfer', 'wp-license' );
		} else {
			echo '<h3>' . esc_html__( 'Invitation to a license', 'wp-license' ) . '</h3>';
			echo '<p>' . esc_html(
				sprintf(
					/* translators: 1: name of the license owner, 2: product name, 3: license reference such as WPLC-1A2B3C4D. */
					__( '%1$s invites you to use the license %3$s for %2$s. You will see its key, sites and downloads in your account and can deactivate sites. Payments and renewal stay with the owner.', 'wp-license' ),
					$name,
					$product,
					is_array( $license ) ? (string) $license['key_prefix'] : ''
				)
			) . '</p>';
			$label = __( 'Accept the invitation', 'wp-license' );
		}
		CustomerPortal::open_form(
			'wplc_accept_invitation',
			array(
				'invitation_id' => (string) $invitation_id,
				'token'         => $token,
			),
			'wplc_accept_invitation_' . $invitation_id
		);
		echo '<button type="submit" class="button alt">' . esc_html( $label ) . '</button></form>';
		echo '</section>';
	}

	/**
	 * Print the sharing part of one license (hooked to `wplc_account_license_sharing`).
	 *
	 * @param mixed $license License row.
	 * @param mixed $view    Display values.
	 * @return void
	 */
	public static function render_section( $license, $view = array() ): void {
		if ( ! is_array( $license ) || empty( $license['id'] ) || ! is_user_logged_in() ) {
			return;
		}
		$view       = is_array( $view ) ? $view : array();
		$license_id = (int) $license['id'];
		$user_id    = get_current_user_id();

		if ( (int) $license['customer_id'] !== $user_id ) {
			if ( ! LicenseMembers::is_member( $license_id, $user_id ) ) {
				return;
			}
			$owner = get_userdata( (int) $license['customer_id'] );
			echo '<div class="wplc-license-sharing wplc-license-sharing--member">';
			echo '<p>' . esc_html(
				$owner
					/* translators: %s: name of the license owner. */
					? sprintf( __( 'Shared with you by %s. Payments, renewal and cancellation are handled by the owner.', 'wp-license' ), (string) $owner->display_name )
					: __( 'Shared with you. Payments, renewal and cancellation are handled by the owner.', 'wp-license' )
			) . '</p>';
			CustomerPortal::open_form( 'wplc_leave_license', array( 'license_id' => (string) $license_id ), 'wplc_leave_license_' . $license_id, __( 'Stop using this license? It disappears from your account; the owner can invite you again.', 'wp-license' ), 'wplc-inline' );
			CustomerPortal::submit_button( __( 'Leave license', 'wp-license' ), (string) ( $view['title'] ?? '' ) );
			echo '</form></div>';
			return;
		}
		if ( ! LicenseMembers::can_share( $license ) ) {
			return;
		}

		$rows     = LicenseMembers::for_license( $license_id );
		$members  = array();
		$transfer = null;
		foreach ( $rows as $row ) {
			if ( LicenseMembers::KIND_TRANSFER === $row['kind'] ) {
				$transfer = $row;
			} else {
				$members[] = $row;
			}
		}
		$limit = LicenseMembers::member_limit( $license );

		echo '<div class="wplc-license-sharing">';
		echo '<h4>' . esc_html__( 'Co-users', 'wp-license' ) . '</h4>';
		echo '<p class="wplc-license-sharing__help">' . esc_html__( 'Co-users see this license with its key, sites and downloads in their own account and can deactivate sites. They cannot pay, renew, cancel, change the plan or transfer the license.', 'wp-license' ) . '</p>';
		if ( $members ) {
			echo '<ul class="wplc-license-members">';
			foreach ( $members as $member ) {
				$active = LicenseMembers::STATUS_ACTIVE === $member['status'];
				$user   = $active ? get_userdata( (int) $member['user_id'] ) : false;
				$label  = $user ? (string) $user->display_name . ' (' . (string) $user->user_email . ')' : (string) $member['email'];
				echo '<li>' . esc_html( $label ) . ' ';
				if ( ! $active ) {
					/* translators: %s: date. */
					echo '<span class="wplc-license-members__state">' . esc_html( sprintf( __( 'Invitation open until %s', 'wp-license' ), Format::date( $member['expires_at'] ) ) ) . '</span> ';
				}
				$question = $active
					/* translators: %s: name or email address of the co-user. */
					? sprintf( __( 'Remove %s as a co-user? The license disappears from their account.', 'wp-license' ), $label )
					/* translators: %s: email address. */
					: sprintf( __( 'Withdraw the invitation to %s? The link in the email stops working.', 'wp-license' ), $label );
				CustomerPortal::open_form(
					'wplc_remove_member',
					array(
						'license_id' => (string) $license_id,
						'member_id'  => (string) (int) $member['id'],
					),
					'wplc_remove_member_' . $license_id,
					$question,
					'wplc-inline'
				);
				CustomerPortal::submit_button( $active ? __( 'Remove', 'wp-license' ) : __( 'Withdraw', 'wp-license' ), $label );
				echo '</form></li>';
			}
			echo '</ul>';
		} else {
			echo '<p>' . esc_html__( 'Nobody else uses this license.', 'wp-license' ) . '</p>';
		}

		if ( LicenseMembers::has_room( count( $members ), $limit ) ) {
			$field = 'wplc-invite-' . $license_id;
			CustomerPortal::open_form( 'wplc_invite_member', array( 'license_id' => (string) $license_id ), 'wplc_invite_member_' . $license_id, '', 'wplc-invite-member' );
			echo '<label for="' . esc_attr( $field ) . '">' . esc_html__( 'Invite a co-user (email address)', 'wp-license' ) . '</label> ';
			echo '<input type="email" id="' . esc_attr( $field ) . '" name="email" required maxlength="100" autocomplete="off" class="input-text"> ';
			CustomerPortal::submit_button( __( 'Send invitation', 'wp-license' ), (string) ( $view['title'] ?? '' ) );
			echo '</form>';
		} elseif ( $limit > 0 ) {
			/* translators: %d: maximum number of co-users. */
			echo '<p>' . esc_html( sprintf( _n( 'A license can have %d co-user.', 'A license can have %d co-users.', $limit, 'wp-license' ), $limit ) ) . '</p>';
		}

		if ( ! LicenseMembers::transfers_enabled( $license ) ) {
			echo '</div>';
			return;
		}
		echo '<h4>' . esc_html__( 'Transfer', 'wp-license' ) . '</h4>';
		if ( null !== $transfer ) {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: 1: email address, 2: date. */
					__( 'Waiting for %1$s to accept the transfer (until %2$s). The license stays yours until then.', 'wp-license' ),
					(string) $transfer['email'],
					Format::date( $transfer['expires_at'] )
				)
			) . '</p>';
			CustomerPortal::open_form( 'wplc_cancel_transfer', array( 'license_id' => (string) $license_id ), 'wplc_cancel_transfer_' . $license_id, __( 'Cancel the transfer? The link in the email stops working.', 'wp-license' ), 'wplc-inline' );
			CustomerPortal::submit_button( __( 'Cancel transfer', 'wp-license' ), (string) ( $view['title'] ?? '' ) );
			echo '</form>';
		} else {
			$field = 'wplc-transfer-' . $license_id;
			echo '<details class="wplc-transfer"><summary>' . esc_html__( 'Transfer this license to another customer', 'wp-license' ) . '</summary>';
			echo '<p>' . esc_html__( 'The recipient gets an email and must accept the transfer in their own account. Then the license, its key and its sites belong to them, co-users are removed and automatic renewal ends: your saved payment method is no longer used.', 'wp-license' ) . '</p>';
			CustomerPortal::open_form( 'wplc_start_transfer', array( 'license_id' => (string) $license_id ), 'wplc_start_transfer_' . $license_id, __( 'Offer this license to the recipient? It stays yours until they accept.', 'wp-license' ) );
			echo '<label for="' . esc_attr( $field ) . '">' . esc_html__( 'Email address of the recipient', 'wp-license' ) . '</label> ';
			echo '<input type="email" id="' . esc_attr( $field ) . '" name="email" required maxlength="100" autocomplete="off" class="input-text"> ';
			echo '<label><input type="checkbox" name="confirm_transfer" value="1" required> ' . esc_html__( 'I understand that automatic renewal ends with the transfer.', 'wp-license' ) . '</label> ';
			CustomerPortal::submit_button( __( 'Offer transfer', 'wp-license' ), (string) ( $view['title'] ?? '' ) );
			echo '</form></details>';
		}
		echo '</div>';
	}
}
