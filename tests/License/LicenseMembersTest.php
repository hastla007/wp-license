<?php
/**
 * B-139: pure rules of co-users, invitations and transfers.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\Account\LicenseSharing;
use WPLC\License\LicenseMembers;

require_once dirname( __DIR__ ) . '/Admin/wp-function-stubs.php';

final class LicenseMembersTest extends TestCase {

	public function test_only_the_hash_of_a_token_is_derived_and_it_is_stable(): void {
		$token = str_repeat( 'ab', 32 );

		$this->assertSame( hash( 'sha256', $token ), LicenseMembers::hash_token( $token ) );
		$this->assertSame( 64, strlen( LicenseMembers::hash_token( $token ) ) );
		$this->assertNotSame( $token, LicenseMembers::hash_token( $token ) );
	}

	public function test_token_shape_is_64_lowercase_hex_characters(): void {
		$this->assertTrue( LicenseMembers::is_token_shape( str_repeat( 'a1', 32 ) ) );
		$this->assertFalse( LicenseMembers::is_token_shape( str_repeat( 'a1', 31 ) ) );
		$this->assertFalse( LicenseMembers::is_token_shape( str_repeat( 'A1', 32 ) ) );
		$this->assertFalse( LicenseMembers::is_token_shape( str_repeat( 'g1', 32 ) ) );
		$this->assertFalse( LicenseMembers::is_token_shape( '' ) );
	}

	public function test_email_addresses_are_compared_without_case_and_spaces(): void {
		$this->assertSame( 'anna@example.com', LicenseMembers::normalize_email( '  Anna@Example.COM ' ) );
		$this->assertTrue( LicenseMembers::same_email( 'Anna@Example.com', 'anna@example.com ' ) );
		$this->assertFalse( LicenseMembers::same_email( 'anna@example.com', 'anna@example.org' ) );
		$this->assertFalse( LicenseMembers::same_email( '', '' ), 'Two empty addresses are no match.' );
	}

	public function test_an_invitation_expires_at_its_expiry_time(): void {
		$now = 1800000000;

		$this->assertFalse( LicenseMembers::is_expired( gmdate( 'Y-m-d H:i:s', $now + 60 ), $now ) );
		$this->assertTrue( LicenseMembers::is_expired( gmdate( 'Y-m-d H:i:s', $now ), $now ) );
		$this->assertTrue( LicenseMembers::is_expired( gmdate( 'Y-m-d H:i:s', $now - 1 ), $now ) );
		$this->assertTrue( LicenseMembers::is_expired( 'not a date', $now ), 'An unreadable expiry counts as expired.' );
		$this->assertFalse( LicenseMembers::is_expired( null, $now ), 'An accepted co-user has no expiry.' );
		$this->assertFalse( LicenseMembers::is_expired( '', $now ) );
	}

	public function test_masked_address_keeps_the_first_letter_and_the_domain(): void {
		$this->assertSame( 'a***@example.com', LicenseMembers::mask_email( 'anna@example.com' ) );
		$this->assertSame( '***', LicenseMembers::mask_email( 'no-address' ) );
		$this->assertSame( '***', LicenseMembers::mask_email( '@example.com' ) );
	}

	public function test_room_for_another_co_user(): void {
		$this->assertTrue( LicenseMembers::has_room( 0, 5 ) );
		$this->assertTrue( LicenseMembers::has_room( 4, 5 ) );
		$this->assertFalse( LicenseMembers::has_room( 5, 5 ) );
		$this->assertFalse( LicenseMembers::has_room( 0, 0 ), 'A limit of 0 switches inviting off.' );
	}

	public function test_a_license_is_shared_only_with_an_owner_and_while_it_was_issued_and_not_revoked(): void {
		$this->assertTrue( LicenseMembers::can_share( array( 'customer_id' => 3, 'status' => 'active' ) ) );
		$this->assertTrue( LicenseMembers::can_share( array( 'customer_id' => 3, 'status' => 'expired' ) ) );
		$this->assertFalse( LicenseMembers::can_share( array( 'customer_id' => 3, 'status' => 'revoked' ) ) );
		$this->assertFalse( LicenseMembers::can_share( array( 'customer_id' => 3, 'status' => 'pending' ) ) );
		$this->assertFalse( LicenseMembers::can_share( array( 'customer_id' => 0, 'status' => 'active' ) ), 'A license without owner (deleted account) cannot be shared.' );
	}

	public function test_every_result_maps_to_a_customer_notice(): void {
		$notices = LicenseSharing::notice_messages() + array(
			'license_not_found' => array( 'error', '' ),
		);
		$results = array(
			LicenseMembers::RESULT_INVITED,
			LicenseMembers::RESULT_TRANSFER_STARTED,
			LicenseMembers::RESULT_ACCEPTED_MEMBER,
			LicenseMembers::RESULT_ACCEPTED_TRANSFER,
			LicenseMembers::RESULT_NOT_FOUND,
			LicenseMembers::RESULT_NOT_OWNER,
			LicenseMembers::RESULT_NOT_ALLOWED,
			LicenseMembers::RESULT_INVALID_EMAIL,
			LicenseMembers::RESULT_OWN_EMAIL,
			LicenseMembers::RESULT_DUPLICATE,
			LicenseMembers::RESULT_LIMIT,
			LicenseMembers::RESULT_TRANSFER_PENDING,
			LicenseMembers::RESULT_INVALID,
			LicenseMembers::RESULT_EXPIRED,
			LicenseMembers::RESULT_WRONG_EMAIL,
			LicenseMembers::RESULT_ALREADY_MEMBER,
			LicenseMembers::RESULT_IS_OWNER,
			LicenseMembers::RESULT_BUSY,
			LicenseMembers::RESULT_FAILED,
		);
		foreach ( $results as $result ) {
			$this->assertArrayHasKey( LicenseSharing::code( $result, 'member_removed' ), $notices, $result );
		}
		$this->assertSame( 'member_removed', LicenseSharing::code( LicenseMembers::RESULT_REMOVED, 'member_removed' ) );
		$this->assertSame( 'member_not_found', LicenseSharing::code( LicenseMembers::RESULT_NOT_FOUND, 'member_removed' ) );
		$this->assertSame( 'license_not_found', LicenseSharing::code( LicenseMembers::RESULT_NOT_OWNER, 'member_invited' ), 'A stranger learns nothing about the license.' );
	}
}
