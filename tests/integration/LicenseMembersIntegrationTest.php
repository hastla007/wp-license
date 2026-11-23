<?php
/**
 * B-139: co-users invited by email and transfers started by the customer.
 *
 * The account page is rendered and its forms are posted to the front-end handler
 * with the fields the page printed; accept links are taken from the sent emails.
 *
 * @package WPLC
 */

use WPLC\Account\SiteOverview;
use WPLC\Admin\LicenseAdmin;
use WPLC\Admin\SupportActions;
use WPLC\License\ActivationTransfer;
use WPLC\License\AuditReason;
use WPLC\License\LicenseMembers;
use WPLC\Privacy\PersonalData;

require_once __DIR__ . '/helpers/PortalTestCase.php';

final class LicenseMembersIntegrationTest extends WPLC_Portal_TestCase {

	/**
	 * License owner.
	 *
	 * @var int
	 */
	private $owner;

	/**
	 * Person who is invited (account email invitee@example.test).
	 *
	 * @var int
	 */
	private $invitee;

	/**
	 * Unrelated customer.
	 *
	 * @var int
	 */
	private $stranger;

	public function set_up(): void {
		parent::set_up();
		$this->owner    = self::factory()->user->create(
			array(
				'role'         => 'customer',
				'user_email'   => 'owner@example.test',
				'display_name' => 'Agency Owner',
			)
		);
		$this->invitee  = self::factory()->user->create(
			array(
				'role'         => 'customer',
				'user_email'   => 'invitee@example.test',
				'display_name' => 'Client Person',
			)
		);
		$this->stranger = self::factory()->user->create( array( 'role' => 'customer', 'user_email' => 'stranger@example.test' ) );
	}

	// ------------------------------------------------------------------
	// Helpers.
	// ------------------------------------------------------------------

	/**
	 * Post one of the forms of a license on the owner's (or any user's) account page.
	 *
	 * @param int                 $user_id User who sees the page.
	 * @param string              $action  Form action.
	 * @param array<string,mixed> $extra   Fields typed into the form.
	 * @param array<int,string>   $needles Extra substrings that identify the form.
	 * @return array{outcome:string,status:int,message:string,location:string}
	 */
	private function post_form( int $user_id, string $action, array $extra = array(), array $needles = array() ): array {
		$form = $this->find_form( $this->render_portal( $user_id ), array_merge( array( 'value="' . $action . '"' ), $needles ) );
		$this->assertNotSame( '', $form, 'The page shows no "' . $action . '" form.' );

		return $this->front_post( array_merge( $this->form_fields( $form ), $extra ) );
	}

	/**
	 * Invite an address as the owner through the account page.
	 *
	 * @param string $email Address.
	 * @return array{outcome:string,status:int,message:string,location:string}
	 */
	private function invite( string $email ): array {
		return $this->post_form( $this->owner, 'wplc_invite_member', array( 'email' => $email ) );
	}

	/**
	 * Mail body as text: the mailer switches to quoted-printable for long lines.
	 *
	 * @param string $body Body as sent.
	 * @return string
	 */
	private static function body( string $body ): string {
		return false !== strpos( $body, '=3D' ) ? quoted_printable_decode( $body ) : $body;
	}

	/**
	 * Invitation ID and token of the last email with an accept link.
	 *
	 * @return array{0:int,1:string}
	 */
	private function last_link(): array {
		$mails = $this->mails();
		for ( $i = count( $mails ) - 1; $i >= 0; --$i ) {
			if ( preg_match( '/wplc_invitation=(\d+)&(?:amp;|#038;)?wplc_token=([a-f0-9]{64})/', self::body( $mails[ $i ]['body'] ), $match ) ) {
				return array( (int) $match[1], $match[2] );
			}
		}
		$this->fail( 'No email with an accept link was sent.' );
	}

	/**
	 * Open the accept link as a user and press the button.
	 *
	 * @param int    $user_id       User.
	 * @param int    $invitation_id Invitation ID.
	 * @param string $token         Token.
	 * @return array{outcome:string,status:int,message:string,location:string}
	 */
	private function accept( int $user_id, int $invitation_id, string $token ): array {
		$_GET = array(
			'wplc_invitation' => (string) $invitation_id,
			'wplc_token'      => $token,
		);
		$html = $this->render_portal( $user_id );
		$_GET = array();
		$form = $this->find_form( $html, array( 'value="wplc_accept_invitation"' ) );
		if ( '' === $form ) {
			// The page explains why there is no button; post what a stale page would have sent.
			wp_set_current_user( $user_id );
			return $this->front_post(
				array(
					'wplc_portal'   => '1',
					'action'        => 'wplc_accept_invitation',
					'invitation_id' => (string) $invitation_id,
					'token'         => $token,
					'_wpnonce'      => wp_create_nonce( 'wplc_accept_invitation_' . $invitation_id ),
				)
			);
		}

		return $this->front_post( $this->form_fields( $form ) );
	}

	/**
	 * Rows of the license_members table of a license.
	 *
	 * @param int $license_id License ID.
	 * @return array<int,array<string,mixed>>
	 */
	private function member_rows( int $license_id ): array {
		global $wpdb;
		$tables = $this->tables();

		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tables['license_members']} WHERE license_id = %d ORDER BY id ASC", $license_id ), ARRAY_A );
	}

	/**
	 * A license of the owner with one active site and a co-user who already accepted.
	 *
	 * @param array<string,mixed> $overrides License columns.
	 * @return array{id:int,key:string,uuid:string,site:int}
	 */
	private function shared_license( array $overrides = array() ): array {
		$license = $this->seed_license( array_merge( array( 'customer_id' => $this->owner ), $overrides ), array( 'sites.live_limit' => 3 ) );
		$site    = $this->seed_activation( $license['id'], array( 'site_url' => 'https://client-site.example' ) );
		$this->assertRedirectWithNotice( 'member_invited', $this->invite( 'invitee@example.test' ) );
		list( $id, $token ) = $this->last_link();
		$this->assertRedirectWithNotice( 'invitation_accepted', $this->accept( $this->invitee, $id, $token ) );
		reset_phpmailer_instance();
		$license['site'] = $site['id'];

		return $license;
	}

	/**
	 * Audit reasons of one action on a license.
	 *
	 * @param int    $license_id License ID.
	 * @param string $action     Audit action.
	 * @return string[]
	 */
	private function audit_reasons( int $license_id, string $action ): array {
		global $wpdb;
		$tables = $this->tables();

		return (array) $wpdb->get_col( $wpdb->prepare( "SELECT reason FROM {$tables['audit_log']} WHERE object_type = 'license' AND object_id = %d AND action = %s ORDER BY id ASC", $license_id, $action ) );
	}

	// ------------------------------------------------------------------
	// Invitations.
	// ------------------------------------------------------------------

	public function test_invitation_email_carries_a_single_use_link_and_only_its_hash_is_stored(): void {
		$license = $this->seed_license( array( 'customer_id' => $this->owner ) );

		$this->assertRedirectWithNotice( 'member_invited', $this->invite( 'Invitee@Example.test' ) );

		$mails = $this->mails();
		$this->assertCount( 1, $mails );
		$this->assertSame( 'invitee@example.test', $mails[0]['to'] );
		$this->assertStringContainsString( 'invited to use a license', $mails[0]['subject'] );
		$this->assertStringContainsString( 'Agency Owner', self::body( $mails[0]['body'] ) );
		$this->assertStringNotContainsString( $license['key'], $mails[0]['body'], 'The invitation never contains the key.' );

		list( $id, $token ) = $this->last_link();
		$rows = $this->member_rows( $license['id'] );
		$this->assertCount( 1, $rows );
		$this->assertSame( (string) $id, (string) $rows[0]['id'] );
		$this->assertSame( 'pending', $rows[0]['status'] );
		$this->assertSame( 'invitee@example.test', $rows[0]['email'], 'The address is stored normalized.' );
		$this->assertNull( $rows[0]['user_id'] );
		$this->assertSame( hash( 'sha256', $token ), $rows[0]['token_hash'] );
		$this->assertStringNotContainsString( $token, implode( '|', array_map( 'strval', $rows[0] ) ), 'The plaintext token is never stored.' );
		$expires = strtotime( $rows[0]['expires_at'] . ' UTC' );
		$this->assertEqualsWithDelta( time() + 14 * DAY_IN_SECONDS, $expires, 120, 'Invitations expire after 14 days by default.' );
		$this->assertContains( AuditReason::MEMBER_INVITED, $this->audit_reasons( $license['id'], 'license_member_invited' ) );

		// The owner sees the open invitation with a "Withdraw" button.
		$html = $this->render_portal( $this->owner );
		$this->assertStringContainsString( 'Invitation open until', $html );
		$this->assertNotSame( '', $this->find_form( $html, array( 'value="wplc_remove_member"', 'Withdraw' ) ) );
	}

	public function test_invitee_accepts_once_and_the_link_cannot_be_used_again(): void {
		$license = $this->seed_license( array( 'customer_id' => $this->owner ) );
		$this->invite( 'invitee@example.test' );
		list( $id, $token ) = $this->last_link();

		// Opening the link changes nothing; the page asks for a click.
		$_GET = array(
			'wplc_invitation' => (string) $id,
			'wplc_token'      => $token,
		);
		$html = $this->render_portal( $this->invitee );
		$_GET = array();
		$this->assertStringContainsString( 'Agency Owner invites you to use the license', $html );
		$this->assertSame( 'pending', $this->member_rows( $license['id'] )[0]['status'] );

		$this->assertRedirectWithNotice( 'invitation_accepted', $this->accept( $this->invitee, $id, $token ) );
		$rows = $this->member_rows( $license['id'] );
		$this->assertSame( 'active', $rows[0]['status'] );
		$this->assertSame( (string) $this->invitee, (string) $rows[0]['user_id'] );
		$this->assertNull( $rows[0]['token_hash'], 'The hash is cleared on acceptance: the link works once.' );
		$this->assertNull( $rows[0]['expires_at'] );
		$this->assertTrue( LicenseMembers::is_member( $license['id'], $this->invitee ) );
		$this->assertContains( AuditReason::MEMBER_ACCEPTED, $this->audit_reasons( $license['id'], 'license_member_added' ) );

		// Reuse of the same link.
		$this->assertRedirectWithNotice( 'invitation_invalid', $this->accept( $this->invitee, $id, $token ) );
		$this->assertCount( 1, $this->member_rows( $license['id'] ) );
		// A forged token for the row.
		$this->assertRedirectWithNotice( 'invitation_invalid', $this->accept( $this->invitee, $id, str_repeat( 'a', 64 ) ) );
	}

	public function test_an_account_with_another_email_cannot_accept_and_the_right_one_still_can(): void {
		$license = $this->seed_license( array( 'customer_id' => $this->owner ) );
		$this->invite( 'invitee@example.test' );
		list( $id, $token ) = $this->last_link();

		$_GET = array(
			'wplc_invitation' => (string) $id,
			'wplc_token'      => $token,
		);
		$html = $this->render_portal( $this->stranger );
		$_GET = array();
		$this->assertStringContainsString( 'This invitation was sent to another email address', $html );
		$this->assertStringContainsString( 'i***@example.test', $html, 'Only a masked address is shown.' );
		$this->assertStringNotContainsString( 'invitee@example.test', $html );

		$this->assertRedirectWithNotice( 'invitation_wrong_email', $this->accept( $this->stranger, $id, $token ) );
		$this->assertSame( 'pending', $this->member_rows( $license['id'] )[0]['status'], 'A wrong account does not use up the link.' );
		$this->assertFalse( LicenseMembers::is_member( $license['id'], $this->stranger ) );

		// The invitee changes the case of their address: still the same mailbox.
		wp_update_user( array( 'ID' => $this->invitee, 'user_email' => 'INVITEE@example.test' ) );
		$this->assertRedirectWithNotice( 'invitation_accepted', $this->accept( $this->invitee, $id, $token ) );
	}

	public function test_an_expired_invitation_is_refused_and_removed(): void {
		global $wpdb;
		$license = $this->seed_license( array( 'customer_id' => $this->owner ) );
		$this->invite( 'invitee@example.test' );
		list( $id, $token ) = $this->last_link();
		$wpdb->update( $this->tables()['license_members'], array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => $id ) );

		$this->assertRedirectWithNotice( 'invitation_expired', $this->accept( $this->invitee, $id, $token ) );
		$this->assertSame( array(), $this->member_rows( $license['id'] ) );
		$this->assertFalse( LicenseMembers::is_member( $license['id'], $this->invitee ) );

		// The daily clean-up removes expired invitations nobody opened.
		$this->invite( 'other@example.test' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$this->tables()['license_members']} SET expires_at = %s WHERE license_id = %d", gmdate( 'Y-m-d H:i:s', time() - 1 ), $license['id'] ) );
		$this->assertSame( 1, LicenseMembers::delete_expired() );
		$this->assertSame( array(), $this->member_rows( $license['id'] ) );
	}

	public function test_limit_duplicates_own_address_and_strangers_are_refused(): void {
		add_filter(
			'wplc_license_member_limit',
			static function () {
				return 2;
			}
		);
		$license = $this->seed_license( array( 'customer_id' => $this->owner ) );

		$this->assertRedirectWithNotice( 'member_invited', $this->invite( 'one@example.test' ) );
		$this->assertRedirectWithNotice( 'member_duplicate', $this->invite( 'ONE@example.test' ) );
		$this->assertRedirectWithNotice( 'member_own_email', $this->invite( 'owner@example.test' ) );
		$this->assertRedirectWithNotice( 'member_invited', $this->invite( 'two@example.test' ) );
		// Two places used: the page no longer offers the form, and a stale form is refused.
		$this->assertSame( '', $this->find_form( $this->render_portal( $this->owner ), array( 'value="wplc_invite_member"' ) ) );
		$this->assertSame( LicenseMembers::RESULT_LIMIT, LicenseMembers::invite( $license['id'], 'three@example.test', $this->owner ) );
		$this->assertCount( 2, $this->member_rows( $license['id'] ) );

		// Withdrawing an invitation frees its place.
		$first = (int) $this->member_rows( $license['id'] )[0]['id'];
		$this->assertRedirectWithNotice( 'member_removed', $this->post_form( $this->owner, 'wplc_remove_member', array(), array( 'name="member_id" value="' . $first . '"' ) ) );
		$this->assertRedirectWithNotice( 'member_invited', $this->invite( 'three@example.test' ) );

		// Only the owner invites.
		$this->assertSame( LicenseMembers::RESULT_NOT_OWNER, LicenseMembers::invite( $license['id'], 'x@example.test', $this->stranger ) );
		$this->assertSame( LicenseMembers::RESULT_INVALID_EMAIL, LicenseMembers::invite( $license['id'], 'not-an-address', $this->owner ) );
		// A revoked license is not shared.
		$revoked = $this->seed_license( array( 'customer_id' => $this->owner, 'status' => 'revoked' ) );
		$this->assertSame( LicenseMembers::RESULT_NOT_ALLOWED, LicenseMembers::invite( $revoked['id'], 'x@example.test', $this->owner ) );
	}

	public function test_owner_removes_a_co_user_and_a_co_user_can_leave(): void {
		$license = $this->shared_license();
		$this->assertStringContainsString( 'Client Person', $this->render_portal( $this->owner ) );

		$member = (int) $this->member_rows( $license['id'] )[0]['id'];
		$this->assertRedirectWithNotice( 'member_removed', $this->post_form( $this->owner, 'wplc_remove_member', array(), array( 'name="member_id" value="' . $member . '"' ) ) );
		$this->assertFalse( LicenseMembers::is_member( $license['id'], $this->invitee ) );
		$this->assertStringNotContainsString( $license['key'], $this->render_portal( $this->invitee ), 'The license is gone from the former co-user\'s account.' );
		$this->assertContains( AuditReason::MEMBER_REMOVED_BY_OWNER, $this->audit_reasons( $license['id'], 'license_member_removed' ) );

		// Invited again; this time the co-user leaves.
		$this->invite( 'invitee@example.test' );
		list( $id, $token ) = $this->last_link();
		$this->accept( $this->invitee, $id, $token );
		$this->assertRedirectWithNotice( 'member_left', $this->post_form( $this->invitee, 'wplc_leave_license' ) );
		$this->assertFalse( LicenseMembers::is_member( $license['id'], $this->invitee ) );
		$this->assertContains( AuditReason::MEMBER_LEFT, $this->audit_reasons( $license['id'], 'license_member_left' ) );
		// A co-user cannot remove others, the owner cannot "leave".
		$this->assertSame( LicenseMembers::RESULT_NOT_FOUND, LicenseMembers::leave( $license['id'], $this->owner ) );
	}

	// ------------------------------------------------------------------
	// Rights of a co-user.
	// ------------------------------------------------------------------

	public function test_co_user_sees_key_sites_and_downloads_but_no_payment_or_owner_actions(): void {
		$license = $this->shared_license(
			array(
				'renewal_enabled'      => 1,
				'renewal_amount_minor' => 4900,
				'renewal_currency'     => 'EUR',
			)
		);

		$html = $this->render_portal( $this->invitee );
		$this->assertStringContainsString( $license['key'], $html );
		$this->assertStringContainsString( 'https://client-site.example', $html );
		$this->assertStringContainsString( 'Shared with you by Agency Owner', $html );
		$this->assertStringContainsString( 'No download is available for this product yet', $html, 'The download area is shown to co-users.' );
		$this->assertNotSame( '', $this->find_form( $html, array( 'value="wplc_deactivate_site"' ) ) );
		$this->assertNotSame( '', $this->find_form( $html, array( 'value="wplc_leave_license"' ) ) );
		foreach ( array( 'wplc_cancel_license', 'wplc_resume_license', 'wplc_start_renewal', 'wplc_set_payment_method', 'wplc_enable_renewal', 'wplc_invite_member', 'wplc_start_transfer', 'wplc_remove_member', 'wplc_cancel_plan_change', 'wplc_upgrade_plan' ) as $owner_action ) {
			$this->assertStringNotContainsString( 'value="' . $owner_action . '"', $html, $owner_action );
		}
		$this->assertStringNotContainsString( '49.00', $html, 'The renewal price is the owner\'s business.' );
		$this->assertStringNotContainsString( 'Renews automatically', $html );

		// The Sites page lists the shared license's site; a stranger sees nothing.
		$this->assertSame( array( $license['site'] ), array_map( 'intval', array_column( SiteOverview::load( $this->invitee )['rows'], 'activation_id' ) ) );
		$this->assertTrue( SiteOverview::customer_has_license( $this->invitee ) );
		$this->assertSame( array(), SiteOverview::load( $this->stranger )['rows'] );
	}

	public function test_co_user_may_release_a_site_and_nothing_else(): void {
		global $wpdb;
		$license = $this->shared_license( array( 'renewal_enabled' => 1 ) );
		$other   = $this->seed_license( array( 'customer_id' => $this->owner ) );

		// Refused: cancel, renew, plan change, invite, transfer, moving a site, the owner list.
		wp_set_current_user( $this->invitee );
		$cancel = $this->front_post(
			array(
				'wplc_portal' => '1',
				'action'      => 'wplc_cancel_license',
				'license_id'  => (string) $license['id'],
				'_wpnonce'    => wp_create_nonce( 'wplc_cancel_license_' . $license['id'] ),
			)
		);
		$this->assertRedirectWithNotice( 'license_not_found', $cancel );
		$this->assertSame( '0', (string) $this->license( $license['id'] )['cancel_at_period_end'] );
		$this->assertWPError( \WPLC\Billing\ManualRenewal::start( $license['id'], $this->invitee ) );
		$this->assertWPError( \WPLC\Billing\PlanUpgrade::start( $license['id'], $this->invitee, 1, true ) );
		$this->assertSame( LicenseMembers::RESULT_NOT_OWNER, LicenseMembers::invite( $license['id'], 'friend@example.test', $this->invitee ) );
		$this->assertSame( LicenseMembers::RESULT_NOT_OWNER, LicenseMembers::start_transfer( $license['id'], 'friend@example.test', $this->invitee ) );
		$this->assertSame( LicenseMembers::RESULT_NOT_OWNER, LicenseMembers::remove_member( $license['id'], (int) $this->member_rows( $license['id'] )[0]['id'], $this->invitee ) );
		$this->assertSame( 'not_found', ActivationTransfer::move( $license['site'], $other['id'], $this->invitee ) );
		$this->assertStringNotContainsString( $other['key'], $this->render_portal( $this->invitee ), 'Other licenses of the owner stay hidden.' );

		// Allowed: releasing a site.
		$this->assertRedirectWithNotice( 'site_deactivated', $this->post_form( $this->invitee, 'wplc_deactivate_site' ) );
		$this->assertSame( 'deactivated', $this->activation( $license['site'] )['status'] );
		$reason = (string) $wpdb->get_var( $wpdb->prepare( "SELECT reason FROM {$this->tables()['audit_log']} WHERE object_type = 'activation' AND object_id = %d AND action = 'site_deactivated'", $license['site'] ) );
		$this->assertSame( AuditReason::DEACTIVATED_BY_CO_USER, $reason );
		$this->assertSame( '1', (string) $this->license( $license['id'] )['renewal_enabled'], 'Nothing else changed.' );

		// A stranger cannot release a site of the license.
		$site2 = $this->seed_activation( $license['id'], array( 'site_url' => 'https://second.example' ) );
		wp_set_current_user( $this->stranger );
		$stranger = $this->front_post(
			array(
				'wplc_portal'   => '1',
				'action'        => 'wplc_deactivate_site',
				'activation_id' => (string) $site2['id'],
				'_wpnonce'      => wp_create_nonce( 'wplc_deactivate_site_' . $site2['id'] ),
			)
		);
		$this->assertRedirectWithNotice( 'site_not_found', $stranger );
		$this->assertSame( 'active', $this->activation( $site2['id'] )['status'] );
	}

	public function test_co_user_passes_the_download_check_and_a_stranger_does_not(): void {
		$license = $this->shared_license();
		$post    = function ( int $user_id ) use ( $license ) {
			wp_set_current_user( $user_id );
			$this->halt_instead_of_exit();
			$_SERVER['REQUEST_METHOD'] = 'POST';
			$_POST                     = array(
				'wplc_action' => \WPLC\Update\CustomerDownload::ACTION,
				'license_id'  => (string) $license['id'],
				'_wpnonce'    => wp_create_nonce( \WPLC\Update\CustomerDownload::nonce_action( $license['id'] ) ),
			);
			try {
				\WPLC\Update\CustomerDownload::maybe_handle_frontend();
			} catch ( WPDieException $die ) {
				return $die->getMessage();
			} finally {
				$_SERVER['REQUEST_METHOD'] = 'GET';
			}
			return '';
		};

		// The co-user gets past the ownership check: only the missing release stops the download.
		$this->assertStringContainsString( 'No download is available for this product yet', $post( $this->invitee ) );
		$this->assertStringContainsString( 'This license does not belong to your account', $post( $this->stranger ) );
	}

	// ------------------------------------------------------------------
	// Transfers.
	// ------------------------------------------------------------------

	public function test_accepted_transfer_moves_the_license_ends_renewal_detaches_the_token_and_removes_co_users(): void {
		global $wpdb;
		$license   = $this->shared_license(
			array(
				'renewal_enabled'    => 1,
				'payment_gateway_id' => 'stripe',
				'payment_token_id'   => 55,
				'gateway_customer_ref' => 'cus_owner',
				'gateway_method_ref' => 'pm_owner',
			)
		);
		$recipient = self::factory()->user->create(
			array(
				'role'         => 'customer',
				'user_email'   => 'recipient@example.test',
				'display_name' => 'New Owner',
			)
		);

		// The confirmation is required.
		$fields = $this->form_fields( $this->find_form( $this->render_portal( $this->owner ), array( 'value="wplc_start_transfer"' ) ) );
		unset( $fields['confirm_transfer'] );
		$this->assertRedirectWithNotice( 'transfer_confirm_missing', $this->front_post( $fields + array( 'email' => 'recipient@example.test' ) ) );
		$this->assertRedirectWithNotice( 'transfer_started', $this->post_form( $this->owner, 'wplc_start_transfer', array( 'email' => 'recipient@example.test', 'confirm_transfer' => '1' ) ) );
		$this->assertSame( (string) $this->owner, (string) $this->license( $license['id'] )['customer_id'], 'Nothing changes before the recipient accepts.' );
		$this->assertStringContainsString( 'Waiting for recipient@example.test to accept the transfer', $this->render_portal( $this->owner ) );
		$this->assertSame( LicenseMembers::RESULT_TRANSFER_PENDING, LicenseMembers::start_transfer( $license['id'], 'x@example.test', $this->owner ) );
		$hash_before = (string) $this->license( $license['id'] )['license_key_hash'];
		$mails = $this->mails();
		$this->assertSame( 'recipient@example.test', $mails[0]['to'] );
		$this->assertStringContainsString( 'is being transferred to you', $mails[0]['subject'] );
		$this->assertStringNotContainsString( $license['key'], self::body( $mails[0]['body'] ) );
		list( $id, $token ) = $this->last_link();
		reset_phpmailer_instance();

		// The co-user cannot accept a transfer meant for someone else.
		$this->assertRedirectWithNotice( 'invitation_wrong_email', $this->accept( $this->invitee, $id, $token ) );

		$this->assertRedirectWithNotice( 'transfer_accepted', $this->accept( $recipient, $id, $token ) );
		$after = $this->license( $license['id'] );
		$this->assertSame( (string) $recipient, (string) $after['customer_id'] );
		$this->assertSame( '0', (string) $after['renewal_enabled'], 'Automatic renewal ends with the transfer.' );
		$this->assertSame( '0', (string) $after['payment_token_id'], 'The previous owner\'s payment token is detached.' );
		$this->assertSame( '', (string) $after['payment_gateway_id'] );
		$this->assertNull( $after['gateway_customer_ref'] );
		$this->assertNull( $after['gateway_method_ref'] );
		$this->assertSame( $hash_before, (string) $after['license_key_hash'], 'The key stays by default.' );
		$this->assertSame( array(), $this->member_rows( $license['id'] ), 'Co-users and the used transfer row are gone.' );
		$this->assertFalse( LicenseMembers::is_member( $license['id'], $this->invitee ) );

		// Audit: transfer started, owner changed through the admin path with the customer reason, co-users removed.
		$this->assertContains( AuditReason::TRANSFER_STARTED, $this->audit_reasons( $license['id'], 'license_transfer_started' ) );
		$this->assertSame( array( AuditReason::LICENSE_TRANSFERRED_BY_CUSTOMER ), $this->audit_reasons( $license['id'], 'license_owner_changed' ) );
		$this->assertContains( AuditReason::MEMBERS_REMOVED_WITH_OWNER, $this->audit_reasons( $license['id'], 'license_members_removed' ) );
		$actor = (int) $wpdb->get_var( $wpdb->prepare( "SELECT actor_user_id FROM {$this->tables()['audit_log']} WHERE object_type = 'license' AND object_id = %d AND action = 'license_owner_changed'", $license['id'] ) );
		$this->assertSame( $recipient, $actor );

		// Both parties are told.
		$to = array_column( $this->mails(), 'to' );
		sort( $to );
		$this->assertSame( array( 'owner@example.test', 'recipient@example.test' ), $to );
		foreach ( $this->mails() as $mail ) {
			$this->assertStringContainsString( 'transferred', $mail['subject'] );
		}

		// The license changed accounts; the link is used up.
		$this->assertStringContainsString( $license['key'], $this->render_portal( $recipient ) );
		$this->assertStringNotContainsString( $license['key'], $this->render_portal( $this->owner ) );
		$this->assertStringNotContainsString( $license['key'], $this->render_portal( $this->invitee ) );
		$this->assertRedirectWithNotice( 'invitation_invalid', $this->accept( $recipient, $id, $token ) );
	}

	/** SET-LIC-18: number of co-users, validity of invitations, transfer on/off and a new key come from the settings. */
	public function test_co_users_and_transfer_follow_the_settings(): void {
		global $wpdb;
		$recipient = self::factory()->user->create( array( 'role' => 'customer', 'user_email' => 'recipient@example.test' ) );
		$license   = $this->seed_license( array( 'customer_id' => $this->owner ) );
		$this->assertSame( 5, LicenseMembers::member_limit( $this->license( $license['id'] ) ), 'Default as before.' );
		$this->assertSame( 14, LicenseMembers::invitation_days( LicenseMembers::KIND_MEMBER ) );

		update_option( \WPLC\Settings\Registry::OPTION, array( 'co_user_limit' => 1, 'invitation_days' => 3 ) );
		$this->assertRedirectWithNotice( 'member_invited', $this->invite( 'one@example.test' ) );
		$row = $this->member_rows( $license['id'] )[0];
		$this->assertEqualsWithDelta( time() + 3 * DAY_IN_SECONDS, strtotime( $row['expires_at'] . ' UTC' ), 120, 'Valid for the configured days.' );
		$this->assertSame( LicenseMembers::RESULT_LIMIT, LicenseMembers::invite( $license['id'], 'two@example.test', $this->owner ) );

		// Switched off (0): no new invitation, and an open one can no longer be accepted.
		$this->assertRedirectWithNotice( 'member_removed', $this->post_form( $this->owner, 'wplc_remove_member', array(), array( 'name="member_id" value="' . (int) $row['id'] . '"' ) ) );
		reset_phpmailer_instance();
		$this->assertRedirectWithNotice( 'member_invited', $this->invite( 'invitee@example.test' ) );
		list( $id, $token ) = $this->last_link();
		update_option( \WPLC\Settings\Registry::OPTION, array( 'co_user_limit' => 0 ) );
		$this->assertSame( '', $this->find_form( $this->render_portal( $this->owner ), array( 'value="wplc_invite_member"' ) ), 'No invitation form.' );
		$this->assertRedirectWithNotice( 'member_not_allowed', $this->accept( $this->invitee, $id, $token ) );
		$this->assertFalse( LicenseMembers::is_member( $license['id'], $this->invitee ) );

		// The filter still decides last.
		add_filter( 'wplc_license_member_limit', static fn() => 2 );
		$this->assertSame( 2, LicenseMembers::member_limit( $this->license( $license['id'] ) ) );
		remove_all_filters( 'wplc_license_member_limit' );

		// Transfers switched off: no form, and an open transfer can no longer be accepted.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->tables()['license_members']} WHERE license_id = %d", $license['id'] ) );
		reset_phpmailer_instance();
		$this->assertRedirectWithNotice( 'transfer_started', $this->post_form( $this->owner, 'wplc_start_transfer', array( 'email' => 'recipient@example.test', 'confirm_transfer' => '1' ) ) );
		list( $id, $token ) = $this->last_link();
		update_option( \WPLC\Settings\Registry::OPTION, array( 'transfers_enabled' => 0 ) );
		$this->assertSame( '', $this->find_form( $this->render_portal( $this->owner ), array( 'value="wplc_start_transfer"' ) ) );
		$this->assertRedirectWithNotice( 'member_not_allowed', $this->accept( $recipient, $id, $token ) );
		$this->assertSame( (string) $this->owner, (string) $this->license( $license['id'] )['customer_id'] );

		// Switched on again with "new key on transfer": the accepted transfer issues a new key.
		update_option( \WPLC\Settings\Registry::OPTION, array( 'transfers_enabled' => 1, 'transfer_new_key' => 1 ) );
		$hash_before = (string) $this->license( $license['id'] )['license_key_hash'];
		$this->assertRedirectWithNotice( 'transfer_accepted', $this->accept( $recipient, $id, $token ) );
		$after = $this->license( $license['id'] );
		$this->assertSame( (string) $recipient, (string) $after['customer_id'] );
		$this->assertNotSame( $hash_before, (string) $after['license_key_hash'], 'A new key.' );
	}

	public function test_owner_cancels_a_transfer_and_an_expired_transfer_is_refused(): void {
		global $wpdb;
		$license   = $this->seed_license( array( 'customer_id' => $this->owner, 'renewal_enabled' => 1 ) );
		$recipient = self::factory()->user->create( array( 'role' => 'customer', 'user_email' => 'recipient@example.test' ) );

		$this->assertRedirectWithNotice( 'transfer_started', $this->post_form( $this->owner, 'wplc_start_transfer', array( 'email' => 'recipient@example.test', 'confirm_transfer' => '1' ) ) );
		list( $id, $token ) = $this->last_link();
		$this->assertRedirectWithNotice( 'transfer_cancelled', $this->post_form( $this->owner, 'wplc_cancel_transfer' ) );
		$this->assertContains( AuditReason::TRANSFER_CANCELLED, $this->audit_reasons( $license['id'], 'license_transfer_cancelled' ) );
		$this->assertRedirectWithNotice( 'invitation_invalid', $this->accept( $recipient, $id, $token ) );
		$this->assertSame( (string) $this->owner, (string) $this->license( $license['id'] )['customer_id'] );
		$this->assertSame( '1', (string) $this->license( $license['id'] )['renewal_enabled'] );

		// A new offer that runs out.
		$this->post_form( $this->owner, 'wplc_start_transfer', array( 'email' => 'recipient@example.test', 'confirm_transfer' => '1' ) );
		list( $id, $token ) = $this->last_link();
		$wpdb->update( $this->tables()['license_members'], array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 5 ) ), array( 'id' => $id ) );
		$this->assertRedirectWithNotice( 'invitation_expired', $this->accept( $recipient, $id, $token ) );
		$this->assertSame( (string) $this->owner, (string) $this->license( $license['id'] )['customer_id'] );
		$this->assertSame( array(), $this->member_rows( $license['id'] ) );

		// The offer becomes void when the license changed hands in the meantime.
		$this->post_form( $this->owner, 'wplc_start_transfer', array( 'email' => 'recipient@example.test', 'confirm_transfer' => '1' ) );
		list( $id, $token ) = $this->last_link();
		$wpdb->update( $this->tables()['licenses'], array( 'customer_id' => $this->stranger ), array( 'id' => $license['id'] ) );
		$this->assertRedirectWithNotice( 'invitation_invalid', $this->accept( $recipient, $id, $token ) );
		$this->assertSame( (string) $this->stranger, (string) $this->license( $license['id'] )['customer_id'] );
	}

	public function test_transfer_waits_while_a_renewal_payment_is_processing(): void {
		global $wpdb;
		$license   = $this->seed_license( array( 'customer_id' => $this->owner ) );
		$recipient = self::factory()->user->create( array( 'role' => 'customer', 'user_email' => 'recipient@example.test' ) );
		$this->post_form( $this->owner, 'wplc_start_transfer', array( 'email' => 'recipient@example.test', 'confirm_transfer' => '1' ) );
		list( $id, $token ) = $this->last_link();
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert( $this->tables()['renewals'], array( 'license_id' => $license['id'], 'period_start' => $now, 'period_end' => $now, 'idempotency_key' => hash( 'sha256', 'busy' . $license['id'] ), 'status' => 'processing', 'created_at' => $now, 'updated_at' => $now ) );

		$this->assertRedirectWithNotice( 'invitation_busy', $this->accept( $recipient, $id, $token ) );
		$this->assertSame( (string) $this->owner, (string) $this->license( $license['id'] )['customer_id'] );
		$this->assertCount( 1, $this->member_rows( $license['id'] ), 'The link stays valid.' );
	}

	public function test_admin_owner_change_removes_co_users_and_open_offers(): void {
		$license = $this->shared_license();
		LicenseMembers::start_transfer( $license['id'], 'recipient@example.test', $this->owner );
		LicenseMembers::invite( $license['id'], 'second@example.test', $this->owner );

		$this->assertSame( SupportActions::RESULT_OK, SupportActions::change_owner( $license['id'], $this->stranger, 'Agency handed over', 1 ) );
		$this->assertSame( array(), $this->member_rows( $license['id'] ) );
		$this->assertContains( AuditReason::MEMBERS_REMOVED_WITH_OWNER, $this->audit_reasons( $license['id'], 'license_members_removed' ) );
	}

	// ------------------------------------------------------------------
	// Admin screen.
	// ------------------------------------------------------------------

	public function test_admin_sees_co_users_and_the_open_transfer_and_removes_a_co_user(): void {
		$license = $this->shared_license();
		LicenseMembers::start_transfer( $license['id'], 'recipient@example.test', $this->owner );
		$manager = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		wp_set_current_user( $manager );

		set_current_screen( 'woocommerce_page_wplc-licenses' );
		$_GET = array(
			'page'    => 'wplc-licenses',
			'license' => (string) $license['id'],
		);
		ob_start();
		LicenseAdmin::render();
		$html = (string) ob_get_clean();
		$_GET = array();

		$this->assertStringContainsString( 'Co-users and transfer', $html );
		$this->assertStringContainsString( 'invitee@example.test', $html );
		$this->assertStringContainsString( 'recipient@example.test', $html );
		$this->assertStringContainsString( 'Transfer offered, waiting for the recipient', $html );
		$this->assertStringContainsString( 'value="wplc_admin_remove_member"', $html );

		$member = 0;
		foreach ( $this->member_rows( $license['id'] ) as $row ) {
			if ( 'member' === $row['kind'] ) {
				$member = (int) $row['id'];
			}
		}
		$missing = $this->run_admin_post(
			'wplc_admin_remove_member',
			array(
				'license_id' => $license['id'],
				'member_id'  => $member,
				'_wpnonce'   => wp_create_nonce( 'wplc_admin_remove_member_' . $license['id'] ),
			)
		);
		$this->assertStringContainsString( 'member_missing_input', $missing['location'] );
		$this->assertTrue( LicenseMembers::is_member( $license['id'], $this->invitee ) );

		$done = $this->run_admin_post(
			'wplc_admin_remove_member',
			array(
				'license_id' => $license['id'],
				'member_id'  => $member,
				'reason'     => 'Customer asked by phone',
				'_wpnonce'   => wp_create_nonce( 'wplc_admin_remove_member_' . $license['id'] ),
			)
		);
		$this->assertStringContainsString( 'member_removed', $done['location'] );
		$this->assertFalse( LicenseMembers::is_member( $license['id'], $this->invitee ) );
		$this->assertContains( 'Customer asked by phone', $this->audit_reasons( $license['id'], 'license_member_removed' ) );

		// A customer has no access to the admin handler.
		wp_set_current_user( $this->owner );
		$refused = $this->run_admin_post(
			'wplc_admin_remove_member',
			array(
				'license_id' => $license['id'],
				'member_id'  => $member,
				'reason'     => 'x',
				'_wpnonce'   => wp_create_nonce( 'wplc_admin_remove_member_' . $license['id'] ),
			)
		);
		$this->assertSame( 'die', $refused['outcome'] );
	}

	// ------------------------------------------------------------------
	// Privacy and deleted accounts.
	// ------------------------------------------------------------------

	public function test_privacy_export_and_erasure_include_memberships_and_invitations(): void {
		$license = $this->shared_license();
		$second  = $this->seed_license( array( 'customer_id' => $this->owner ) );
		LicenseMembers::start_transfer( $second['id'], 'invitee@example.test', $this->owner );

		$items = array();
		for ( $page = 1; $page < 20; ++$page ) {
			$result = PersonalData::export_personal_data( 'invitee@example.test', $page );
			$items  = array_merge( $items, $result['data'] );
			if ( $result['done'] ) {
				break;
			}
		}
		$memberships = array_values(
			array_filter(
				$items,
				static function ( $item ) {
					return 'wp-license-captain-memberships' === $item['group_id'];
				}
			)
		);
		$this->assertCount( 2, $memberships, 'The active co-user access and the open transfer to the address.' );
		$values = array();
		foreach ( $memberships as $item ) {
			foreach ( $item['data'] as $field ) {
				$values[] = $field['name'] . '=' . $field['value'];
			}
		}
		$this->assertContains( 'Role=Co-user', $values );
		$this->assertContains( 'Role=Transfer offered to you', $values );
		$this->assertContains( 'Invited address=invitee@example.test', $values );
		$this->assertStringNotContainsString( 'token', strtolower( wp_json_encode( $memberships ) ) );

		// The owner's export does not list the co-user's address.
		$owner_items = PersonalData::export_personal_data( 'owner@example.test', 1 );
		$this->assertStringNotContainsString( 'wp-license-captain-memberships', wp_json_encode( $owner_items ) );

		$erased = PersonalData::erase_personal_data( 'invitee@example.test', 1 );
		$this->assertTrue( $erased['items_removed'] );
		$this->assertFalse( LicenseMembers::is_member( $license['id'], $this->invitee ) );
		$this->assertSame( array(), $this->member_rows( $second['id'] ), 'The open transfer to the erased address is gone.' );
		$this->assertSame( (string) $this->owner, (string) $this->license( $license['id'] )['customer_id'], 'The owner\'s license is not touched.' );
	}

	public function test_deleting_an_account_removes_its_memberships_and_invitations(): void {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$license = $this->shared_license();
		LicenseMembers::invite( $license['id'], 'later@example.test', $this->owner );
		$later = self::factory()->user->create( array( 'role' => 'customer', 'user_email' => 'later@example.test' ) );

		wp_delete_user( $this->invitee );
		$this->assertFalse( LicenseMembers::is_member( $license['id'], $this->invitee ) );
		$this->assertCount( 1, $this->member_rows( $license['id'] ), 'Only the invitation to another address is left.' );

		wp_delete_user( $later );
		$this->assertSame( array(), $this->member_rows( $license['id'] ), 'An open invitation to the deleted account\'s address is removed.' );

		// The owner's account: open offers the owner made disappear with it.
		LicenseMembers::start_transfer( $license['id'], 'recipient@example.test', $this->owner );
		wp_delete_user( $this->owner );
		$this->assertSame( array(), $this->member_rows( $license['id'] ) );
	}
}
