<?php
/**
 * Co-users of a license and transfers by the customer (B-139).
 *
 * @package WPLC
 */

namespace WPLC\License;

use WPLC\Admin\SupportActions;
use WPLC\Infrastructure\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Invitations, co-users and customer-initiated transfers.
 *
 * Owner decision of 16 Nov 2026: the owner of a license (its customer) can invite
 * co-users by email. A co-user sees the license in their account (status, plan,
 * expiry, key, sites, downloads) and may release sites. Only the owner pays,
 * renews, cancels, changes the plan or the payment method, invites and transfers.
 * A transfer takes effect only when the recipient accepts it in their own
 * account; automatic renewal ends with the transfer.
 *
 * Storage: table `license_members` (schema 26). An invitation is a row with
 * status `pending`, the invited address, the SHA-256 hash of a random token and
 * an expiry. Accepting a co-user invitation turns the row into `active` with the
 * user ID and clears the hash, so a link works once. A transfer row is deleted
 * when the transfer is completed. The plaintext token exists only in the email.
 *
 * The recipient must be signed in with an account whose email address is the
 * invited address (case-insensitive); a new customer registers with it first.
 */
final class LicenseMembers {

	public const KIND_MEMBER   = 'member';
	public const KIND_TRANSFER = 'transfer';

	public const STATUS_PENDING = 'pending';
	public const STATUS_ACTIVE  = 'active';

	/**
	 * Default number of co-users (accepted plus open invitations) per license.
	 */
	public const DEFAULT_LIMIT = 5;

	/**
	 * Default days an invitation can be accepted.
	 */
	public const DEFAULT_INVITATION_DAYS = 14;

	/**
	 * Result codes.
	 */
	public const RESULT_INVITED          = 'invited';
	public const RESULT_TRANSFER_STARTED = 'transfer_started';
	public const RESULT_ACCEPTED_MEMBER  = 'accepted_member';
	public const RESULT_ACCEPTED_TRANSFER = 'accepted_transfer';
	public const RESULT_NOT_FOUND        = 'not_found';
	public const RESULT_NOT_OWNER        = 'not_owner';
	public const RESULT_NOT_ALLOWED      = 'not_allowed';
	public const RESULT_INVALID_EMAIL    = 'invalid_email';
	public const RESULT_OWN_EMAIL        = 'own_email';
	public const RESULT_DUPLICATE        = 'duplicate';
	public const RESULT_LIMIT            = 'limit';
	public const RESULT_TRANSFER_PENDING = 'transfer_pending';
	public const RESULT_INVALID          = 'invalid';
	public const RESULT_EXPIRED          = 'expired';
	public const RESULT_WRONG_EMAIL      = 'wrong_email';
	public const RESULT_ALREADY_MEMBER   = 'already_member';
	public const RESULT_IS_OWNER         = 'is_owner';
	public const RESULT_BUSY             = 'busy';
	public const RESULT_REMOVED          = 'removed';
	public const RESULT_FAILED           = 'failed';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		// Every change of the owner (admin "Change owner" and accepted transfers) ends the sharing.
		add_action( 'wplc/license_owner_changed', array( self::class, 'on_owner_changed' ), 5, 4 );
		add_action( 'wplc_daily_lifecycle', array( self::class, 'delete_expired' ), 40 );
		// Before the user row is deleted: its email address is still readable.
		add_action( 'delete_user', array( self::class, 'on_user_deleted' ), 10, 1 );
		add_action( 'wpmu_delete_user', array( self::class, 'on_user_deleted' ), 10, 1 );
	}

	// ------------------------------------------------------------------
	// Pure helpers (unit tested).
	// ------------------------------------------------------------------

	/**
	 * SHA-256 hash of an invitation token, as stored.
	 *
	 * @param string $token Plaintext token.
	 * @return string 64 hex characters.
	 */
	public static function hash_token( string $token ): string {
		return hash( 'sha256', $token );
	}

	/**
	 * Whether a string has the shape of a token this class issues (64 hex characters).
	 *
	 * @param string $token Candidate.
	 * @return bool
	 */
	public static function is_token_shape( string $token ): bool {
		return 1 === preg_match( '/^[a-f0-9]{64}$/', $token );
	}

	/**
	 * Normalized email address for comparisons and storage (trimmed, lower case).
	 *
	 * @param string $email Address.
	 * @return string
	 */
	public static function normalize_email( string $email ): string {
		return strtolower( trim( $email ) );
	}

	/**
	 * Whether two addresses are the same mailbox for this feature (case-insensitive).
	 *
	 * @param string $a First address.
	 * @param string $b Second address.
	 * @return bool
	 */
	public static function same_email( string $a, string $b ): bool {
		$a = self::normalize_email( $a );

		return '' !== $a && self::normalize_email( $b ) === $a;
	}

	/**
	 * Whether a pending invitation has expired.
	 *
	 * @param string|null $expires_at UTC datetime; empty means no expiry.
	 * @param int         $now        Unix timestamp.
	 * @return bool
	 */
	public static function is_expired( ?string $expires_at, int $now ): bool {
		if ( null === $expires_at || '' === $expires_at ) {
			return false;
		}
		$time = strtotime( $expires_at . ' UTC' );

		return false === $time || $time <= $now;
	}

	/**
	 * Address with most of the local part hidden, for pages that must not reveal it in full.
	 *
	 * @param string $email Address.
	 * @return string E.g. "j***@example.com".
	 */
	public static function mask_email( string $email ): string {
		$email = trim( $email );
		$at    = strrpos( $email, '@' );
		if ( false === $at || 0 === $at ) {
			return '***';
		}

		return substr( $email, 0, 1 ) . '***' . substr( $email, $at );
	}

	/**
	 * Whether another co-user may be added: accepted co-users plus open invitations below the limit.
	 *
	 * @param int $used  Co-users and open invitations.
	 * @param int $limit Limit; 0 switches co-users off.
	 * @return bool
	 */
	public static function has_room( int $used, int $limit ): bool {
		return $limit > 0 && $used < $limit;
	}

	// ------------------------------------------------------------------
	// Settings.
	// ------------------------------------------------------------------

	/**
	 * Most co-users (accepted plus open invitations) of one license.
	 *
	 * @param array<string,mixed> $license License row.
	 * @return int 0 switches inviting off.
	 */
	public static function member_limit( array $license ): int {
		/**
		 * Filters how many co-users a license can have (accepted plus open invitations).
		 *
		 * @param int                 $limit   Default 5; 0 switches inviting off.
		 * @param array<string,mixed> $license License row.
		 */
		$limit = (int) apply_filters( 'wplc_license_member_limit', self::DEFAULT_LIMIT, $license );

		return max( 0, min( 100, $limit ) );
	}

	/**
	 * Days an invitation (co-user or transfer) can be accepted.
	 *
	 * @param string $kind KIND_MEMBER or KIND_TRANSFER.
	 * @return int
	 */
	public static function invitation_days( string $kind ): int {
		/**
		 * Filters how many days an invitation link can be used.
		 *
		 * @param int    $days Default 14.
		 * @param string $kind 'member' (co-user invitation) or 'transfer'.
		 */
		$days = (int) apply_filters( 'wplc_license_invitation_days', self::DEFAULT_INVITATION_DAYS, $kind );

		return max( 1, min( 90, $days ) );
	}

	/**
	 * Whether a license can be shared or transferred in its current status.
	 *
	 * @param array<string,mixed> $license License row.
	 * @return bool
	 */
	public static function can_share( array $license ): bool {
		return ! empty( $license['customer_id'] ) && ! in_array( (string) ( $license['status'] ?? '' ), array( 'pending', 'revoked' ), true );
	}

	// ------------------------------------------------------------------
	// Queries.
	// ------------------------------------------------------------------

	/**
	 * Whether a user is an active co-user of a license.
	 *
	 * @param int $license_id License ID.
	 * @param int $user_id    User ID.
	 * @return bool
	 */
	public static function is_member( int $license_id, int $user_id ): bool {
		global $wpdb;

		if ( $license_id <= 0 || $user_id <= 0 ) {
			return false;
		}
		$tables = Schema::table_names();

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT 1 FROM %i WHERE license_id = %d AND kind = %s AND status = %s AND user_id = %d LIMIT 1',
				$tables['license_members'],
				$license_id,
				self::KIND_MEMBER,
				self::STATUS_ACTIVE,
				$user_id
			)
		);
	}

	/**
	 * Whether a user may see a license and release its sites: its owner or an active co-user.
	 *
	 * @param array<string,mixed> $license License row.
	 * @param int                 $user_id User ID.
	 * @return bool
	 */
	public static function can_view( array $license, int $user_id ): bool {
		if ( $user_id <= 0 || empty( $license['id'] ) ) {
			return false;
		}
		if ( (int) ( $license['customer_id'] ?? 0 ) === $user_id ) {
			return true;
		}

		return self::is_member( (int) $license['id'], $user_id );
	}

	/**
	 * SQL condition "the license with alias $alias is owned by or shared with %d", with its two arguments.
	 *
	 * @param string $alias   Alias of the licenses table, e.g. "l"; empty for none.
	 * @param int    $user_id User ID.
	 * @return array{0:string,1:array<int,mixed>} SQL fragment with placeholders and its arguments.
	 */
	public static function visible_condition( string $alias, int $user_id ): array {
		$tables = Schema::table_names();
		$column = '' === $alias ? '' : $alias . '.';

		return array(
			"( {$column}customer_id = %d OR {$column}id IN ( SELECT m.license_id FROM {$tables['license_members']} m WHERE m.user_id = %d AND m.kind = %s AND m.status = %s ) )",
			array( $user_id, $user_id, self::KIND_MEMBER, self::STATUS_ACTIVE ),
		);
	}

	/**
	 * Co-users, open co-user invitations and the open transfer of a license.
	 *
	 * @param int $license_id License ID.
	 * @return array<int,array<string,mixed>> Rows ordered by kind and ID; expired invitations are left out.
	 */
	public static function for_license( int $license_id ): array {
		global $wpdb;

		$tables = Schema::table_names();
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, license_id, kind, status, user_id, email, invited_by, created_at, expires_at, accepted_at FROM %i WHERE license_id = %d AND ( status = %s OR expires_at IS NULL OR expires_at > %s ) ORDER BY kind ASC, id ASC LIMIT 200',
				$tables['license_members'],
				$license_id,
				self::STATUS_ACTIVE,
				gmdate( 'Y-m-d H:i:s' )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The open transfer of a license.
	 *
	 * @param int $license_id License ID.
	 * @return array<string,mixed>|null
	 */
	public static function pending_transfer( int $license_id ): ?array {
		foreach ( self::for_license( $license_id ) as $row ) {
			if ( self::KIND_TRANSFER === $row['kind'] && self::STATUS_PENDING === $row['status'] ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * Accepted co-users plus open, unexpired co-user invitations of a license.
	 *
	 * @param int $license_id License ID.
	 * @return int
	 */
	public static function used_places( int $license_id ): int {
		global $wpdb;

		$tables = Schema::table_names();

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE license_id = %d AND kind = %s AND ( status = %s OR ( status = %s AND expires_at > %s ) )',
				$tables['license_members'],
				$license_id,
				self::KIND_MEMBER,
				self::STATUS_ACTIVE,
				self::STATUS_PENDING,
				gmdate( 'Y-m-d H:i:s' )
			)
		);
	}

	/**
	 * One invitation by its ID and plaintext token, whatever its state.
	 *
	 * @param int    $invitation_id Row ID.
	 * @param string $token         Plaintext token from the link.
	 * @return array<string,mixed>|null Null when the ID and token do not belong together.
	 */
	public static function find_invitation( int $invitation_id, string $token ): ?array {
		global $wpdb;

		if ( $invitation_id <= 0 || ! self::is_token_shape( $token ) ) {
			return null;
		}
		$tables = Schema::table_names();
		$row    = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d AND status = %s LIMIT 1', $tables['license_members'], $invitation_id, self::STATUS_PENDING ),
			ARRAY_A
		);
		if ( ! is_array( $row ) || ! is_string( $row['token_hash'] ) || ! hash_equals( (string) $row['token_hash'], self::hash_token( $token ) ) ) {
			return null;
		}

		return $row;
	}

	// ------------------------------------------------------------------
	// Owner actions.
	// ------------------------------------------------------------------

	/**
	 * Invite a co-user by email (owner only).
	 *
	 * @param int    $license_id License ID.
	 * @param string $email      Address of the invitee.
	 * @param int    $actor      Acting user; must own the license.
	 * @return string RESULT_INVITED or an error code.
	 */
	public static function invite( int $license_id, string $email, int $actor ): string {
		$license = self::license( $license_id );
		if ( ! $license ) {
			return self::RESULT_NOT_FOUND;
		}
		if ( $actor <= 0 || (int) $license['customer_id'] !== $actor ) {
			return self::RESULT_NOT_OWNER;
		}
		if ( ! self::can_share( $license ) ) {
			return self::RESULT_NOT_ALLOWED;
		}
		$email = self::normalize_email( $email );
		if ( '' === $email || strlen( $email ) > 100 || ! is_email( $email ) ) {
			return self::RESULT_INVALID_EMAIL;
		}
		$owner = get_userdata( $actor );
		if ( $owner && self::same_email( (string) $owner->user_email, $email ) ) {
			return self::RESULT_OWN_EMAIL;
		}
		if ( self::address_taken( $license_id, $email ) ) {
			return self::RESULT_DUPLICATE;
		}
		$limit = self::member_limit( $license );
		if ( ! self::has_room( self::used_places( $license_id ), $limit ) ) {
			return self::RESULT_LIMIT;
		}

		$created = self::create_invitation( $license, self::KIND_MEMBER, $email, $actor );
		if ( null === $created ) {
			return self::RESULT_FAILED;
		}
		// Counted after the insert, so two concurrent invitations cannot exceed the limit together.
		if ( self::used_places( $license_id ) > $limit ) {
			self::delete_row( (int) $created['id'] );
			return self::RESULT_LIMIT;
		}

		AuditLog::record( 'license_member_invited', 'license', $license_id, AuditReason::MEMBER_INVITED, null, array( 'invitation_id' => (int) $created['id'] ), $actor );
		self::send( 'member_invitation', $license, $email, $created );

		/**
		 * Fires after the owner invited a co-user.
		 *
		 * @param int $license_id    License ID.
		 * @param int $invitation_id Invitation ID.
		 * @param int $actor         Owner.
		 */
		do_action( 'wplc/license_member_invited', $license_id, (int) $created['id'], $actor );

		return self::RESULT_INVITED;
	}

	/**
	 * Start a transfer to another customer (owner only). It takes effect when the recipient accepts it.
	 *
	 * @param int    $license_id License ID.
	 * @param string $email      Address of the recipient.
	 * @param int    $actor      Acting user; must own the license.
	 * @return string RESULT_TRANSFER_STARTED or an error code.
	 */
	public static function start_transfer( int $license_id, string $email, int $actor ): string {
		$license = self::license( $license_id );
		if ( ! $license ) {
			return self::RESULT_NOT_FOUND;
		}
		if ( $actor <= 0 || (int) $license['customer_id'] !== $actor ) {
			return self::RESULT_NOT_OWNER;
		}
		if ( ! self::can_share( $license ) || ! self::transfers_enabled( $license ) ) {
			return self::RESULT_NOT_ALLOWED;
		}
		$email = self::normalize_email( $email );
		if ( '' === $email || strlen( $email ) > 100 || ! is_email( $email ) ) {
			return self::RESULT_INVALID_EMAIL;
		}
		$owner = get_userdata( $actor );
		if ( $owner && self::same_email( (string) $owner->user_email, $email ) ) {
			return self::RESULT_OWN_EMAIL;
		}
		if ( null !== self::pending_transfer( $license_id ) ) {
			return self::RESULT_TRANSFER_PENDING;
		}

		$created = self::create_invitation( $license, self::KIND_TRANSFER, $email, $actor );
		if ( null === $created ) {
			return self::RESULT_FAILED;
		}
		AuditLog::record( 'license_transfer_started', 'license', $license_id, AuditReason::TRANSFER_STARTED, null, array( 'invitation_id' => (int) $created['id'] ), $actor );
		self::send( 'transfer_offer', $license, $email, $created );

		/**
		 * Fires after the owner started a transfer of a license.
		 *
		 * @param int $license_id    License ID.
		 * @param int $invitation_id Invitation ID.
		 * @param int $actor         Owner.
		 */
		do_action( 'wplc/license_transfer_started', $license_id, (int) $created['id'], $actor );

		return self::RESULT_TRANSFER_STARTED;
	}

	/**
	 * Whether the owner may transfer this license from the account.
	 *
	 * @param array<string,mixed> $license License row.
	 * @return bool
	 */
	public static function transfers_enabled( array $license ): bool {
		/**
		 * Filters whether the owner can transfer a license to another customer from the account.
		 *
		 * @param bool                $enabled Default true.
		 * @param array<string,mixed> $license License row.
		 */
		return (bool) apply_filters( 'wplc_license_transfer_enabled', true, $license );
	}

	/**
	 * Cancel the open transfer (owner only; until the recipient accepted it).
	 *
	 * @param int $license_id License ID.
	 * @param int $actor      Acting user; must own the license.
	 * @return string RESULT_REMOVED, RESULT_NOT_OWNER or RESULT_NOT_FOUND.
	 */
	public static function cancel_transfer( int $license_id, int $actor ): string {
		global $wpdb;

		$license = self::license( $license_id );
		if ( ! $license ) {
			return self::RESULT_NOT_FOUND;
		}
		if ( $actor <= 0 || (int) $license['customer_id'] !== $actor ) {
			return self::RESULT_NOT_OWNER;
		}
		$tables  = Schema::table_names();
		$deleted = (int) $wpdb->query(
			$wpdb->prepare( 'DELETE FROM %i WHERE license_id = %d AND kind = %s AND status = %s', $tables['license_members'], $license_id, self::KIND_TRANSFER, self::STATUS_PENDING )
		);
		if ( $deleted < 1 ) {
			return self::RESULT_NOT_FOUND;
		}
		AuditLog::record( 'license_transfer_cancelled', 'license', $license_id, AuditReason::TRANSFER_CANCELLED, null, null, $actor );

		return self::RESULT_REMOVED;
	}

	/**
	 * Remove a co-user or withdraw an open co-user invitation.
	 *
	 * @param int    $license_id License ID.
	 * @param int    $member_id  Row ID.
	 * @param int    $actor      Acting user.
	 * @param bool   $as_admin   True for the admin screen: no owner check, $reason is required.
	 * @param string $reason     Reason typed by the administrator.
	 * @return string RESULT_REMOVED, RESULT_NOT_OWNER or RESULT_NOT_FOUND.
	 */
	public static function remove_member( int $license_id, int $member_id, int $actor, bool $as_admin = false, string $reason = '' ): string {
		global $wpdb;

		$license = self::license( $license_id );
		if ( ! $license ) {
			return self::RESULT_NOT_FOUND;
		}
		if ( ! $as_admin && ( $actor <= 0 || (int) $license['customer_id'] !== $actor ) ) {
			return self::RESULT_NOT_OWNER;
		}
		$reason = $as_admin ? SupportActions::limit_reason( $reason ) : AuditReason::MEMBER_REMOVED_BY_OWNER;
		if ( '' === $reason ) {
			return self::RESULT_FAILED;
		}
		$tables = Schema::table_names();
		$row    = $wpdb->get_row(
			$wpdb->prepare( 'SELECT id, status, user_id FROM %i WHERE id = %d AND license_id = %d AND kind = %s LIMIT 1', $tables['license_members'], $member_id, $license_id, self::KIND_MEMBER ),
			ARRAY_A
		);
		if ( ! is_array( $row ) || 1 !== self::delete_row( (int) $row['id'] ) ) {
			return self::RESULT_NOT_FOUND;
		}
		AuditLog::record(
			'license_member_removed',
			'license',
			$license_id,
			$reason,
			array(
				'member_id' => (int) $row['id'],
				'status'    => (string) $row['status'],
				'user_id'   => (int) $row['user_id'],
			),
			null,
			$actor
		);

		/**
		 * Fires after a co-user was removed or an open invitation withdrawn.
		 *
		 * @param int $license_id License ID.
		 * @param int $user_id    Co-user (0 for an invitation that was not accepted).
		 * @param int $actor      Acting user (owner or administrator).
		 */
		do_action( 'wplc/license_member_removed', $license_id, (int) $row['user_id'], $actor );

		return self::RESULT_REMOVED;
	}

	// ------------------------------------------------------------------
	// Co-user and recipient actions.
	// ------------------------------------------------------------------

	/**
	 * A co-user leaves a license.
	 *
	 * @param int $license_id License ID.
	 * @param int $user_id    Co-user.
	 * @return string RESULT_REMOVED or RESULT_NOT_FOUND.
	 */
	public static function leave( int $license_id, int $user_id ): string {
		global $wpdb;

		if ( $license_id <= 0 || $user_id <= 0 ) {
			return self::RESULT_NOT_FOUND;
		}
		$tables  = Schema::table_names();
		$deleted = (int) $wpdb->query(
			$wpdb->prepare( 'DELETE FROM %i WHERE license_id = %d AND kind = %s AND status = %s AND user_id = %d', $tables['license_members'], $license_id, self::KIND_MEMBER, self::STATUS_ACTIVE, $user_id )
		);
		if ( $deleted < 1 ) {
			return self::RESULT_NOT_FOUND;
		}
		AuditLog::record( 'license_member_left', 'license', $license_id, AuditReason::MEMBER_LEFT, array( 'user_id' => $user_id ), null, $user_id );
		/** This action is documented in LicenseMembers::remove_member(). */
		do_action( 'wplc/license_member_removed', $license_id, $user_id, $user_id );

		return self::RESULT_REMOVED;
	}

	/**
	 * Check an invitation link for the signed-in user without using it.
	 *
	 * @param array<string,mixed>|null $row     Result of find_invitation().
	 * @param int                      $user_id Signed-in user.
	 * @return string Empty when the user may accept it, otherwise an error code.
	 */
	public static function check_invitation( ?array $row, int $user_id ): string {
		if ( null === $row ) {
			return self::RESULT_INVALID;
		}
		if ( self::is_expired( isset( $row['expires_at'] ) ? (string) $row['expires_at'] : null, time() ) ) {
			return self::RESULT_EXPIRED;
		}
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( ! $user || ! self::same_email( (string) $user->user_email, (string) $row['email'] ) ) {
			return self::RESULT_WRONG_EMAIL;
		}
		$license = self::license( (int) $row['license_id'] );
		if ( ! $license || ! self::can_share( $license ) ) {
			return self::RESULT_INVALID;
		}
		if ( (int) $license['customer_id'] === $user_id ) {
			return self::RESULT_IS_OWNER;
		}
		if ( self::KIND_MEMBER === $row['kind'] && self::is_member( (int) $license['id'], $user_id ) ) {
			return self::RESULT_ALREADY_MEMBER;
		}

		return '';
	}

	/**
	 * Accept an invitation with its link (co-user or transfer).
	 *
	 * @param int    $invitation_id Row ID from the link.
	 * @param string $token         Plaintext token from the link.
	 * @param int    $user_id       Signed-in user; their account email must be the invited address.
	 * @return string RESULT_ACCEPTED_MEMBER, RESULT_ACCEPTED_TRANSFER or an error code.
	 */
	public static function accept( int $invitation_id, string $token, int $user_id ): string {
		$row   = self::find_invitation( $invitation_id, $token );
		$error = self::check_invitation( $row, $user_id );
		if ( self::RESULT_EXPIRED === $error && null !== $row ) {
			self::delete_row( (int) $row['id'] );
		}
		if ( self::RESULT_ALREADY_MEMBER === $error && null !== $row ) {
			// The link is used up either way.
			self::delete_row( (int) $row['id'] );
		}
		if ( '' !== $error || null === $row ) {
			return '' !== $error ? $error : self::RESULT_INVALID;
		}

		return self::KIND_TRANSFER === $row['kind'] ? self::accept_transfer( $row, $user_id ) : self::accept_member( $row, $user_id );
	}

	/**
	 * Turn an open co-user invitation into an active co-user (single use).
	 *
	 * @param array<string,mixed> $row     Invitation row.
	 * @param int                 $user_id Accepting user.
	 * @return string
	 */
	private static function accept_member( array $row, int $user_id ): string {
		global $wpdb;

		$tables     = Schema::table_names();
		$suppressed = $wpdb->suppress_errors( true );
		// Compare-and-set on the hash: a second request with the same link changes nothing.
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, user_id = %d, token_hash = NULL, expires_at = NULL, accepted_at = %s WHERE id = %d AND status = %s AND token_hash = %s',
				$tables['license_members'],
				self::STATUS_ACTIVE,
				$user_id,
				gmdate( 'Y-m-d H:i:s' ),
				(int) $row['id'],
				self::STATUS_PENDING,
				(string) $row['token_hash']
			)
		);
		$wpdb->suppress_errors( $suppressed );
		if ( false === $updated ) {
			// Unique key license_member: the user became a co-user through another invitation meanwhile.
			self::delete_row( (int) $row['id'] );
			return self::RESULT_ALREADY_MEMBER;
		}
		if ( 1 !== (int) $updated ) {
			return self::RESULT_INVALID;
		}
		$license_id = (int) $row['license_id'];
		AuditLog::record(
			'license_member_added',
			'license',
			$license_id,
			AuditReason::MEMBER_ACCEPTED,
			null,
			array(
				'member_id' => (int) $row['id'],
				'user_id' => $user_id,
			),
			$user_id
		);

		/**
		 * Fires after a user accepted a co-user invitation.
		 *
		 * @param int $license_id License ID.
		 * @param int $user_id    New co-user.
		 */
		do_action( 'wplc/license_member_added', $license_id, $user_id );

		return self::RESULT_ACCEPTED_MEMBER;
	}

	/**
	 * Complete a transfer: the recipient becomes the owner (single use).
	 *
	 * Uses the admin owner change (SupportActions::change_owner()): compare-and-set on the owner who
	 * started the transfer, automatic renewal off, payment token and gateway references detached,
	 * open automatic renewals cancelled, audit entries. The co-users are removed by on_owner_changed().
	 * The key stays the same unless `wplc_license_transfer_new_key` says otherwise.
	 *
	 * @param array<string,mixed> $row     Transfer row.
	 * @param int                 $user_id Recipient.
	 * @return string
	 */
	private static function accept_transfer( array $row, int $user_id ): string {
		$license_id = (int) $row['license_id'];
		$license    = self::license( $license_id );
		$previous   = (int) $row['invited_by'];
		if ( ! $license || (int) $license['customer_id'] !== $previous ) {
			// The license changed hands since the transfer was offered.
			self::delete_row( (int) $row['id'] );
			return self::RESULT_INVALID;
		}
		/**
		 * Filters whether a transfer accepted in the account also issues a new license key.
		 *
		 * @param bool                $new_key Default false: the key stays, activated sites keep working.
		 * @param array<string,mixed> $license License row before the transfer.
		 */
		$new_key = (bool) apply_filters( 'wplc_license_transfer_new_key', false, $license );
		$result  = SupportActions::change_owner( $license_id, $user_id, AuditReason::LICENSE_TRANSFERRED_BY_CUSTOMER, $user_id, $previous, $new_key, false );
		if ( SupportActions::RESULT_PROCESSING === $result ) {
			// A renewal payment is running; the link stays valid.
			return self::RESULT_BUSY;
		}
		if ( ! in_array( $result, array( SupportActions::RESULT_OK, SupportActions::RESULT_KEY_KEPT ), true ) ) {
			return self::RESULT_INVALID;
		}
		// on_owner_changed() deleted every row of the license, this one included.
		self::delete_row( (int) $row['id'] );

		$after         = self::license( $license_id );
		$previous_user = get_userdata( $previous );
		$new_user      = get_userdata( $user_id );
		if ( $after ) {
			if ( $previous_user ) {
				self::send(
					'transferred',
					$after,
					(string) $previous_user->user_email,
					array(
						'role' => 'previous',
						'other_party' => $new_user ? (string) $new_user->display_name : '',
					)
				);
			}
			if ( $new_user ) {
				self::send(
					'transferred',
					$after,
					(string) $new_user->user_email,
					array(
						'role' => 'new',
						'other_party' => $previous_user ? (string) $previous_user->display_name : '',
					)
				);
			}
		}

		/**
		 * Fires after a transfer started by the owner was accepted by the recipient.
		 *
		 * `wplc/license_owner_changed` has fired before.
		 *
		 * @param int $license_id      License ID.
		 * @param int $previous_owner  Previous owner.
		 * @param int $new_owner       New owner.
		 */
		do_action( 'wplc/license_transferred', $license_id, $previous, $user_id );

		return self::RESULT_ACCEPTED_TRANSFER;
	}

	// ------------------------------------------------------------------
	// Clean-up.
	// ------------------------------------------------------------------

	/**
	 * A license changed its owner: co-users and open invitations end.
	 *
	 * @param mixed $license_id   License ID.
	 * @param mixed $old_customer Previous owner.
	 * @param mixed $new_customer New owner.
	 * @param mixed $actor        Acting user.
	 * @return void
	 */
	public static function on_owner_changed( $license_id = 0, $old_customer = 0, $new_customer = 0, $actor = 0 ): void {
		global $wpdb;

		unset( $old_customer, $new_customer );
		$license_id = (int) $license_id;
		if ( $license_id <= 0 ) {
			return;
		}
		$tables  = Schema::table_names();
		$members = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE license_id = %d AND kind = %s', $tables['license_members'], $license_id, self::KIND_MEMBER ) );
		// Co-users, open invitations and an open transfer (also the one being accepted right now).
		self::forget_license( $license_id );
		if ( $members > 0 ) {
			AuditLog::record( 'license_members_removed', 'license', $license_id, AuditReason::MEMBERS_REMOVED_WITH_OWNER, array( 'co_users' => $members ), null, (int) $actor );
		}
	}

	/**
	 * Delete every co-user and invitation of a license.
	 *
	 * @param int $license_id License ID.
	 * @return int Rows deleted.
	 */
	public static function forget_license( int $license_id ): int {
		global $wpdb;

		if ( $license_id <= 0 ) {
			return 0;
		}
		$tables = Schema::table_names();

		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE license_id = %d', $tables['license_members'], $license_id ) );
	}

	/**
	 * Delete the memberships of a person and the invitations sent to or by them.
	 *
	 * Used when the account is deleted and by the personal data eraser.
	 *
	 * @param int $user_id User ID.
	 * @return int|false Rows deleted, false on a database error.
	 */
	public static function forget_user( int $user_id ) {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return 0;
		}
		$tables  = Schema::table_names();
		$user    = get_userdata( $user_id );
		$email   = $user ? self::normalize_email( (string) $user->user_email ) : '';
		$removed = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE user_id = %d OR ( status = %s AND ( invited_by = %d OR ( email <> %s AND email = %s ) ) )',
				$tables['license_members'],
				$user_id,
				self::STATUS_PENDING,
				$user_id,
				'',
				$email
			)
		);
		if ( false === $removed ) {
			return false;
		}
		// Accepted co-users the person invited stay; only the reference to the person goes.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET invited_by = 0 WHERE invited_by = %d', $tables['license_members'], $user_id ) );

		return (int) $removed;
	}

	/**
	 * Hook `delete_user` / `wpmu_delete_user`.
	 *
	 * @param mixed $user_id User being deleted.
	 * @return void
	 */
	public static function on_user_deleted( $user_id ): void {
		self::forget_user( (int) $user_id );
	}

	/**
	 * Delete expired invitations (daily).
	 *
	 * @return int Rows deleted.
	 */
	public static function delete_expired(): int {
		global $wpdb;

		$tables = Schema::table_names();

		return (int) $wpdb->query(
			$wpdb->prepare( 'DELETE FROM %i WHERE status = %s AND expires_at IS NOT NULL AND expires_at <= %s LIMIT 5000', $tables['license_members'], self::STATUS_PENDING, gmdate( 'Y-m-d H:i:s' ) )
		);
	}

	// ------------------------------------------------------------------
	// Internals.
	// ------------------------------------------------------------------

	/**
	 * Whether an address is already a co-user or has an open invitation for this license.
	 *
	 * @param int    $license_id License ID.
	 * @param string $email      Normalized address.
	 * @return bool
	 */
	private static function address_taken( int $license_id, string $email ): bool {
		global $wpdb;

		$tables  = Schema::table_names();
		$pending = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE license_id = %d AND kind = %s AND status = %s AND email = %s AND expires_at > %s',
				$tables['license_members'],
				$license_id,
				self::KIND_MEMBER,
				self::STATUS_PENDING,
				$email,
				gmdate( 'Y-m-d H:i:s' )
			)
		);
		if ( $pending > 0 ) {
			return true;
		}
		$user = get_user_by( 'email', $email );

		return $user && self::is_member( $license_id, (int) $user->ID );
	}

	/**
	 * Store an invitation and return it with its plaintext token.
	 *
	 * @param array<string,mixed> $license License row.
	 * @param string              $kind    KIND_MEMBER or KIND_TRANSFER.
	 * @param string              $email   Normalized address.
	 * @param int                 $actor   Owner.
	 * @return array<string,mixed>|null Row values plus `token` and `expires_at`; null on a database error.
	 */
	private static function create_invitation( array $license, string $kind, string $email, int $actor ): ?array {
		global $wpdb;

		$tables  = Schema::table_names();
		$token   = bin2hex( random_bytes( 32 ) );
		$now     = time();
		$expires = gmdate( 'Y-m-d H:i:s', $now + self::invitation_days( $kind ) * DAY_IN_SECONDS );
		$data    = array(
			'license_id' => (int) $license['id'],
			'kind'       => $kind,
			'status'     => self::STATUS_PENDING,
			'email'      => $email,
			'token_hash' => self::hash_token( $token ),
			'invited_by' => $actor,
			'created_at' => gmdate( 'Y-m-d H:i:s', $now ),
			'expires_at' => $expires,
		);
		if ( false === $wpdb->insert( $tables['license_members'], $data, array( '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ) ) ) {
			return null;
		}
		$data['id']    = (int) $wpdb->insert_id;
		$data['token'] = $token;

		return $data;
	}

	/**
	 * Delete one row.
	 *
	 * @param int $id Row ID.
	 * @return int Rows deleted.
	 */
	private static function delete_row( int $id ): int {
		global $wpdb;

		$tables = Schema::table_names();

		return (int) $wpdb->delete( $tables['license_members'], array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * License row.
	 *
	 * @param int $license_id License ID.
	 * @return array<string,mixed>|null
	 */
	private static function license( int $license_id ): ?array {
		global $wpdb;

		if ( $license_id <= 0 ) {
			return null;
		}
		$tables = Schema::table_names();
		$row    = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', $tables['licenses'], $license_id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Link that accepts an invitation in the customer account.
	 *
	 * @param int    $invitation_id Row ID.
	 * @param string $token         Plaintext token.
	 * @return string
	 */
	public static function accept_url( int $invitation_id, string $token ): string {
		return add_query_arg(
			array(
				'wplc_invitation' => $invitation_id,
				'wplc_token'      => $token,
			),
			\WPLC\Account\CustomerPortal::account_url()
		);
	}

	/**
	 * Send one of the sharing emails to an explicit address.
	 *
	 * @param string              $email_key  member_invitation, transfer_offer or transferred.
	 * @param array<string,mixed> $license    License row.
	 * @param string              $recipient  Address.
	 * @param array<string,mixed> $invitation Invitation (with `token`) or extra context.
	 * @return bool
	 */
	private static function send( string $email_key, array $license, string $recipient, array $invitation ): bool {
		$context = array( 'recipient' => $recipient );
		if ( isset( $invitation['token'], $invitation['id'] ) ) {
			$owner                 = get_userdata( (int) ( $invitation['invited_by'] ?? 0 ) );
			$context['accept_url'] = self::accept_url( (int) $invitation['id'], (string) $invitation['token'] );
			$context['expires_at'] = (string) $invitation['expires_at'];
			$context['inviter']    = $owner ? (string) $owner->display_name : '';
		} else {
			$context = array_merge( $context, $invitation );
		}

		return \WPLC\Notifications\Notifications::send_sharing_email( $email_key, $license, $context );
	}
}
