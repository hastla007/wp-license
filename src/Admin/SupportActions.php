<?php
/**
 * Audited support actions that are not part of the license lifecycle class.
 *
 * @package WPLC
 */

namespace WPLC\Admin;

use WPLC\Infrastructure\Database\Schema;
use WPLC\License\ActivationManager;
use WPLC\License\AuditLog;
use WPLC\License\LicenseIssuer;
use WPLC\License\LicenseKey;
use WPLC\License\LicenseLifecycle;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Re-issue a key, change the owner, restore a suspended or revoked license,
 * guarded term changes and bulk extensions.
 *
 * Every change is a compare-and-set on the values the administrator saw and
 * writes one audit entry. Capability checks happen in the request handlers.
 */
final class SupportActions {

	public const RESULT_OK         = 'ok';
	public const RESULT_STALE      = 'stale';
	public const RESULT_PROCESSING = 'processing';
	public const RESULT_FAILED     = 'failed';

	/**
	 * Owner changed, but the requested new key could not be issued (P-38).
	 */
	public const RESULT_KEY_KEPT = 'key_kept';

	/**
	 * A higher automatic renewal amount needs the customer's consent (A-11).
	 */
	public const RESULT_INCREASE_REFUSED = 'increase_refused';

	/**
	 * Longest reason stored with a status (licenses.status_reason is varchar(255)).
	 */
	public const REASON_MAX_LENGTH = 255;

	/**
	 * Largest number of licenses changed by one bulk request.
	 */
	public const BULK_LIMIT = 200;

	/**
	 * Trim a reason to the stored length.
	 *
	 * @param string $reason Sanitized reason.
	 * @return string
	 */
	public static function limit_reason( string $reason ): string {
		$reason = trim( $reason );

		return function_exists( 'mb_substr' ) ? mb_substr( $reason, 0, self::REASON_MAX_LENGTH ) : substr( $reason, 0, self::REASON_MAX_LENGTH );
	}

	/**
	 * Replace the key of a license (new UUID, hash, prefix) after it leaked.
	 *
	 * The old key stops working immediately. Existing site activations keep
	 * working because they authenticate with their installation secret; pass
	 * $release_sites to disconnect all of them as well.
	 *
	 * Fires `wplc/license_key_reissued` with the license ID, the new plaintext key
	 * (never stored or logged) and the acting user, so the notification package
	 * can send the key to the customer.
	 *
	 * @param int    $license_id    License ID.
	 * @param string $reason        Required reason.
	 * @param int    $actor_user_id Acting user.
	 * @param bool   $release_sites Also release every active site activation.
	 * @return bool
	 */
	public static function reissue_key( int $license_id, string $reason, int $actor_user_id, bool $release_sites = false ): bool {
		global $wpdb;

		$reason  = self::limit_reason( $reason );
		$license = self::license( $license_id );
		if ( '' === $reason || ! $license || in_array( (string) $license['status'], array( 'revoked', 'pending' ), true ) ) {
			return false;
		}

		$uuid   = LicenseIssuer::new_uuid();
		$key_id = LicenseKey::current_key_id();
		$key    = LicenseKey::for_uuid( $uuid, $key_id );
		if ( '' === $key ) {
			return false;
		}

		$tables  = Schema::table_names();
		$prefix  = LicenseKey::prefix( $uuid );
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET license_uuid = %s, license_key_hash = %s, license_key_secret_id = %s, key_prefix = %s, updated_at = %s WHERE id = %d AND license_uuid = %s AND status = %s",
				$tables['licenses'],
				$uuid,
				LicenseKey::hash( $key ),
				$key_id,
				$prefix,
				gmdate( 'Y-m-d H:i:s' ),
				$license_id,
				(string) $license['license_uuid'],
				(string) $license['status']
			)
		);
		if ( 1 !== (int) $updated ) {
			return false;
		}

		$released = 0;
		if ( $release_sites ) {
			$released = self::release_all_sites( $license_id, $reason, $actor_user_id );
		}

		AuditLog::record(
			'license_key_reissued',
			'license',
			$license_id,
			$reason,
			array(
				'key_prefix'   => (string) $license['key_prefix'],
				'license_uuid' => (string) $license['license_uuid'],
			),
			array(
				'key_prefix'     => $prefix,
				'license_uuid'   => $uuid,
				'released_sites' => $released,
			),
			$actor_user_id
		);

		/**
		 * Fires after a license received a new key.
		 *
		 * @since 0.1.0
		 *
		 * @param int    $license_id    License ID.
		 * @param string $key           New plaintext license key. Never store or log it.
		 * @param int    $actor_user_id Acting user.
		 */
		do_action( 'wplc/license_key_reissued', $license_id, $key, $actor_user_id );

		return true;
	}

	/**
	 * Ask for the key email to be sent again.
	 *
	 * The key is derived again from the stored UUID; when the configured secret no
	 * longer reproduces the stored hash nothing is sent.
	 *
	 * @param int    $license_id    License ID.
	 * @param string $reason        Required reason.
	 * @param int    $actor_user_id Acting user.
	 * @return bool
	 */
	public static function resend_key( int $license_id, string $reason, int $actor_user_id ): bool {
		$reason  = self::limit_reason( $reason );
		$license = self::license( $license_id );
		if ( '' === $reason || ! $license || empty( $license['customer_id'] ) || in_array( (string) $license['status'], array( 'revoked', 'pending' ), true ) ) {
			return false;
		}
		// T-106: the key in the format it was issued with (34 or, before 24.11.2026, 106 characters).
		$key = LicenseKey::verified_for_license( (string) $license['license_uuid'], (string) $license['license_key_secret_id'], (string) $license['license_key_hash'] );
		if ( '' === $key ) {
			return false;
		}

		AuditLog::record( 'license_key_resend_requested', 'license', $license_id, $reason, null, array( 'customer_id' => (int) $license['customer_id'] ), $actor_user_id );

		/**
		 * Fires when an administrator asks for the key email to be sent again.
		 *
		 * @since 0.1.0
		 *
		 * @param int    $license_id    License ID.
		 * @param string $key           Plaintext license key. Never store or log it.
		 * @param int    $actor_user_id Acting user.
		 */
		do_action( 'wplc/license_key_resend_requested', $license_id, $key, $actor_user_id );

		return true;
	}

	/**
	 * Hand a license over to another user account.
	 *
	 * The stored payment method belongs to the previous owner, so automatic
	 * renewal is switched off and open automatic renewals are cancelled. The new
	 * owner can renew manually or opt in again with the next payment.
	 *
	 * The previous owner knows the key (account, emails). With $new_key the
	 * license gets a new key right after the hand-over (reissue_key(), so the
	 * new owner is the one told about it) and the old key stops working; with
	 * $release_sites every activated site is released as well (P-38).
	 *
	 * @param int    $license_id        License ID.
	 * @param int    $new_customer_id   New owner (existing user).
	 * @param string $reason            Required reason.
	 * @param int    $actor_user_id     Acting user.
	 * @param int    $expected_customer Owner the administrator saw; -1 skips the comparison.
	 * @param bool   $new_key           Also issue a new key.
	 * @param bool   $release_sites     With $new_key: also release every active site.
	 * @return string One of the RESULT_* constants; RESULT_KEY_KEPT when the owner changed but no new key could be issued.
	 */
	public static function change_owner( int $license_id, int $new_customer_id, string $reason, int $actor_user_id, int $expected_customer = -1, bool $new_key = false, bool $release_sites = false ): string {
		global $wpdb;

		$reason  = self::limit_reason( $reason );
		$license = self::license( $license_id );
		if ( '' === $reason || ! $license || $new_customer_id <= 0 || ! get_userdata( $new_customer_id ) || 'pending' === $license['status'] ) {
			return self::RESULT_FAILED;
		}
		$old_customer = (int) $license['customer_id'];
		if ( $expected_customer >= 0 && $expected_customer !== $old_customer ) {
			return self::RESULT_STALE;
		}
		if ( $old_customer === $new_customer_id ) {
			return self::RESULT_FAILED;
		}
		if ( self::has_processing_renewal( $license_id ) ) {
			return self::RESULT_PROCESSING;
		}

		$tables  = Schema::table_names();
		$now     = gmdate( 'Y-m-d H:i:s' );
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET customer_id = %d, renewal_enabled = 0, next_payment_at = NULL, payment_gateway_id = %s, payment_token_id = 0, cancel_at_period_end = 0, updated_at = %s WHERE id = %d AND customer_id = %d AND status = %s",
				$tables['licenses'],
				$new_customer_id,
				'',
				$now,
				$license_id,
				$old_customer,
				(string) $license['status']
			)
		);
		if ( 1 === (int) $updated ) {
			// The gateway references identify the former owner at the payment provider.
			\WPLC\Gateway\PaymentReference::clear( $license_id );
		}
		if ( 1 !== (int) $updated ) {
			return self::RESULT_STALE;
		}

		// Automatic attempts would charge the previous owner's payment method. Each renewal
		// is cancelled with a compare-and-set on its status and gets its own audit entry (A-73).
		$open = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, status FROM %i WHERE license_id = %d AND status IN ('scheduled','failed','manual_action_required') ORDER BY id ASC LIMIT 500",
				$tables['renewals'],
				$license_id
			),
			ARRAY_A
		);
		foreach ( is_array( $open ) ? $open : array() as $renewal ) {
			$cancelled = $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET status = %s, retry_at = NULL, updated_at = %s WHERE id = %d AND status = %s',
					$tables['renewals'],
					'cancelled',
					$now,
					(int) $renewal['id'],
					(string) $renewal['status']
				)
			);
			if ( 1 === (int) $cancelled ) {
				AuditLog::record(
					'renewal_cancelled',
					'renewal',
					(int) $renewal['id'],
					'license_owner_changed',
					array( 'status' => (string) $renewal['status'] ),
					array( 'status' => 'cancelled' ),
					$actor_user_id
				);
			}
		}

		AuditLog::record(
			'license_owner_changed',
			'license',
			$license_id,
			$reason,
			array(
				'customer_id'     => $old_customer,
				'renewal_enabled' => (int) $license['renewal_enabled'],
			),
			array(
				'customer_id'     => $new_customer_id,
				'renewal_enabled' => 0,
			),
			$actor_user_id
		);

		/**
		 * Fires after a license was handed over to another user.
		 *
		 * @since 0.1.0
		 *
		 * @param int $license_id      License ID.
		 * @param int $old_customer_id Previous owner (0 when there was none).
		 * @param int $new_customer_id New owner.
		 * @param int $actor_user_id   Acting user.
		 */
		do_action( 'wplc/license_owner_changed', $license_id, $old_customer, $new_customer_id, $actor_user_id );

		if ( $new_key && ! self::reissue_key( $license_id, $reason, $actor_user_id, $release_sites ) ) {
			return self::RESULT_KEY_KEPT;
		}

		return self::RESULT_OK;
	}

	/**
	 * Change the end of the term, protected against a stale browser tab.
	 *
	 * Refuses when the term is no longer the one the administrator saw, and while
	 * an automatic charge is in flight (the charge would book the old period on
	 * top of the changed one).
	 *
	 * @param int    $license_id          License ID.
	 * @param string $paid_until_utc      New end, 'Y-m-d H:i:s' UTC.
	 * @param string $expected_paid_until End of the term the administrator saw ('' for none).
	 * @param string $reason              Required reason.
	 * @param int    $actor_user_id       Acting user.
	 * @return string One of the RESULT_* constants.
	 */
	public static function change_term( int $license_id, string $paid_until_utc, string $expected_paid_until, string $reason, int $actor_user_id ): string {
		$reason  = self::limit_reason( $reason );
		$license = self::license( $license_id );
		if ( '' === $reason || ! $license ) {
			return self::RESULT_FAILED;
		}
		if ( (string) $license['paid_until'] !== $expected_paid_until ) {
			return self::RESULT_STALE;
		}
		if ( self::has_processing_renewal( $license_id ) ) {
			return self::RESULT_PROCESSING;
		}

		// B-133: the lifecycle repeats the check inside its conditional update (5th argument),
		// so two administrators cannot both change the term they saw.
		if ( LicenseLifecycle::admin_extend( $license_id, $paid_until_utc, $reason, $actor_user_id, $expected_paid_until ) ) {
			return self::RESULT_OK;
		}

		return self::result_for_refusal( LicenseLifecycle::last_refusal() );
	}

	/**
	 * Extend several licenses by a number of days, counted from the later of today and the current end.
	 *
	 * @param int[]  $license_ids   License IDs (at most BULK_LIMIT are processed).
	 * @param int    $days          Days to add, 1 to 3650.
	 * @param string $reason        Required reason.
	 * @param int    $actor_user_id Acting user.
	 * @return array{extended:int,skipped:int}
	 */
	public static function bulk_extend( array $license_ids, int $days, string $reason, int $actor_user_id ): array {
		$result = array(
			'extended' => 0,
			'skipped'  => 0,
		);
		$reason = self::limit_reason( $reason );
		$ids    = array_values( array_unique( array_filter( array_map( 'absint', $license_ids ) ) ) );
		if ( '' === $reason || $days < 1 || $days > 3650 || ! $ids ) {
			$result['skipped'] = count( $ids );
			return $result;
		}

		$result['skipped'] = max( 0, count( $ids ) - self::BULK_LIMIT );
		foreach ( array_slice( $ids, 0, self::BULK_LIMIT ) as $license_id ) {
			$license = self::license( $license_id );
			$base    = $license && ! empty( $license['paid_until'] ) ? strtotime( (string) $license['paid_until'] . ' UTC' ) : false;
			if ( ! $license || false === $base ) {
				++$result['skipped'];
				continue;
			}
			$new_end = gmdate( 'Y-m-d H:i:s', max( $base, time() ) + $days * DAY_IN_SECONDS );
			if ( self::RESULT_OK === self::change_term( $license_id, $new_end, (string) $license['paid_until'], $reason, $actor_user_id ) ) {
				++$result['extended'];
			} else {
				++$result['skipped'];
			}
		}

		return $result;
	}

	/**
	 * Bring a suspended or revoked license back with a new end of term.
	 *
	 * This is the way out of "suspended and the period has ended" and of an
	 * accidental revocation (for example an order set to "refunded" by mistake).
	 * Automatic renewal of a revoked license stays off.
	 *
	 * @param int    $license_id      License ID.
	 * @param string $paid_until_utc  New end, 'Y-m-d H:i:s' UTC, in the future.
	 * @param string $expected_status Status the administrator saw: suspended or revoked.
	 * @param string $reason          Required reason.
	 * @param int    $actor_user_id   Acting user.
	 * @return string One of the RESULT_* constants.
	 */
	public static function restore( int $license_id, string $paid_until_utc, string $expected_status, string $reason, int $actor_user_id ): string {
		$reason = self::limit_reason( $reason );
		if ( '' === $reason || ! in_array( $expected_status, array( 'suspended', 'revoked' ), true ) || ! self::valid_utc_datetime( $paid_until_utc ) || $paid_until_utc <= gmdate( 'Y-m-d H:i:s' ) ) {
			return self::RESULT_FAILED;
		}

		// One implementation: LicenseLifecycle::admin_restore() (compare-and-set on the status the
		// administrator saw, open renewals of the old period cancelled, audit entry, status event).
		if ( LicenseLifecycle::admin_restore( $license_id, $reason, $actor_user_id, $paid_until_utc, $expected_status ) ) {
			return self::RESULT_OK;
		}

		return self::result_for_refusal( LicenseLifecycle::last_refusal() );
	}

	/**
	 * Cancel the automatic renewal for the end of the paid period on behalf of the customer (A-21).
	 *
	 * The same cancellation as in the customer account: the license runs until the end of the
	 * paid period, nothing more is charged, and the customer gets the confirmation email.
	 * Withdraw it with withdraw_cancellation().
	 *
	 * @param int    $license_id    License ID.
	 * @param string $reason        Required reason.
	 * @param int    $actor_user_id Acting user.
	 * @return string One of the RESULT_* constants.
	 */
	public static function cancel_renewal( int $license_id, string $reason, int $actor_user_id ): string {
		$reason  = self::limit_reason( $reason );
		$license = self::license( $license_id );
		if ( '' === $reason || ! $license || empty( $license['renewal_enabled'] ) || ! in_array( (string) $license['status'], array( 'active', 'past_due' ), true ) ) {
			return self::RESULT_FAILED;
		}
		if ( ! empty( $license['cancel_at_period_end'] ) ) {
			return self::RESULT_STALE;
		}

		return LicenseLifecycle::schedule_cancellation( $license_id, $reason, $actor_user_id ) ? self::RESULT_OK : self::RESULT_FAILED;
	}

	/**
	 * Withdraw a cancellation at the end of the paid period, so the license renews again (A-21).
	 *
	 * Only the automatic renewal the customer agreed to comes back: a license whose
	 * automatic renewal was switched off stays manual, because switching it on needs
	 * the customer's consent to off-session charges (they do that in their account).
	 *
	 * @param int    $license_id    License ID.
	 * @param string $reason        Required reason.
	 * @param int    $actor_user_id Acting user.
	 * @return string One of the RESULT_* constants.
	 */
	public static function withdraw_cancellation( int $license_id, string $reason, int $actor_user_id ): string {
		$reason  = self::limit_reason( $reason );
		$license = self::license( $license_id );
		if ( '' === $reason || ! $license ) {
			return self::RESULT_FAILED;
		}
		if ( empty( $license['cancel_at_period_end'] ) ) {
			return self::RESULT_STALE;
		}

		return LicenseLifecycle::resume_renewal( $license_id, $actor_user_id, $reason ) ? self::RESULT_OK : self::RESULT_FAILED;
	}

	/**
	 * Change the amount of the next automatic renewals of a license (A-11).
	 *
	 * The amount stored on the license is what the customer agreed to be charged
	 * off-session, so by default it can only be lowered (or set back to a lower
	 * value). Raising it needs the customer's consent; the filter
	 * `wplc_admin_can_raise_renewal_amount` allows it for shops that collected that
	 * consent elsewhere. The currency stays. Renewal orders that already exist keep
	 * their amount.
	 *
	 * @param int    $license_id      License ID.
	 * @param int    $amount_minor    New amount in minor units, more than zero.
	 * @param int    $expected_minor  Amount the administrator saw.
	 * @param string $reason          Required reason.
	 * @param int    $actor_user_id   Acting user.
	 * @return string One of the RESULT_* constants or RESULT_INCREASE_REFUSED.
	 */
	public static function change_renewal_amount( int $license_id, int $amount_minor, int $expected_minor, string $reason, int $actor_user_id ): string {
		global $wpdb;

		$reason  = self::limit_reason( $reason );
		$license = self::license( $license_id );
		if ( '' === $reason || ! $license || $amount_minor <= 0 || ! in_array( (string) $license['status'], array( 'active', 'past_due' ), true ) ) {
			return self::RESULT_FAILED;
		}
		$current = (int) $license['renewal_amount_minor'];
		if ( $current !== $expected_minor ) {
			return self::RESULT_STALE;
		}
		if ( $amount_minor === $current ) {
			return self::RESULT_OK;
		}
		if ( $amount_minor > $current ) {
			/**
			 * Filters whether an administrator may raise the automatic renewal amount of a license.
			 *
			 * Off by default: the customer agreed to off-session charges of the stored amount.
			 * Return true only when the customer consented to the new amount.
			 *
			 * @param bool                $allowed       Default false.
			 * @param array<string,mixed> $license       License row.
			 * @param int                 $amount_minor  Requested amount in minor units.
			 * @param int                 $actor_user_id Acting user.
			 */
			if ( ! apply_filters( 'wplc_admin_can_raise_renewal_amount', false, $license, $amount_minor, $actor_user_id ) ) {
				return self::RESULT_INCREASE_REFUSED;
			}
		}
		if ( self::has_processing_renewal( $license_id ) ) {
			return self::RESULT_PROCESSING;
		}

		$tables  = Schema::table_names();
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET renewal_amount_minor = %d, updated_at = %s WHERE id = %d AND renewal_amount_minor = %d AND status = %s',
				$tables['licenses'],
				$amount_minor,
				gmdate( 'Y-m-d H:i:s' ),
				$license_id,
				$current,
				(string) $license['status']
			)
		);
		if ( 1 !== (int) $updated ) {
			return self::RESULT_STALE;
		}

		AuditLog::record(
			'admin_renewal_amount_changed',
			'license',
			$license_id,
			$reason,
			array(
				'renewal_amount_minor' => $current,
				'renewal_currency'     => (string) $license['renewal_currency'],
			),
			array(
				'renewal_amount_minor' => $amount_minor,
				'renewal_currency'     => (string) $license['renewal_currency'],
			),
			$actor_user_id
		);

		return self::RESULT_OK;
	}

	/**
	 * Map the reason a lifecycle action was refused to a RESULT_* constant.
	 *
	 * @param string $refusal LicenseLifecycle::last_refusal().
	 * @return string
	 */
	private static function result_for_refusal( string $refusal ): string {
		if ( 'renewal_in_progress' === $refusal ) {
			return self::RESULT_PROCESSING;
		}

		return in_array( $refusal, array( 'term_changed', 'status_changed' ), true ) ? self::RESULT_STALE : self::RESULT_FAILED;
	}

	/**
	 * Issue the licenses a paid order should have but does not have.
	 *
	 * @param int    $order_id      WooCommerce order ID.
	 * @param string $reason        Required reason.
	 * @param int    $actor_user_id Acting user.
	 * @return int Number of licenses created, -1 when the order cannot receive licenses.
	 */
	public static function issue_for_order( int $order_id, string $reason, int $actor_user_id ): int {
		$reason = self::limit_reason( $reason );
		$order  = $order_id > 0 && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		if ( '' === $reason || ! $order || ! is_callable( array( $order, 'is_paid' ) ) || ! $order->is_paid() ) {
			return -1;
		}

		$before = count( LicenseLifecycle::license_ids_for_order( $order_id ) );
		LicenseIssuer::maybe_issue_order( $order_id );
		$created = count( LicenseLifecycle::license_ids_for_order( $order_id ) ) - $before;

		AuditLog::record( 'admin_issue_for_order', 'order', $order_id, $reason, array( 'licenses' => $before ), array( 'licenses' => $before + $created ), $actor_user_id );

		return max( 0, $created );
	}

	/**
	 * Renewal orders of a license that a customer can still pay.
	 *
	 * Changing the term does not cancel them: a later payment is accepted by
	 * WooCommerce but may not extend the license.
	 *
	 * @param int $license_id License ID.
	 * @return array<int,array{renewal_id:int,order_id:int,status:string}>
	 */
	public static function open_renewal_orders( int $license_id ): array {
		global $wpdb;

		$tables = Schema::table_names();
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, renewal_order_id, status FROM %i WHERE license_id = %d AND renewal_order_id > 0 AND status NOT IN ('paid','refunded') ORDER BY id DESC LIMIT 20",
				$tables['renewals'],
				$license_id
			),
			ARRAY_A
		);
		$open   = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $row['renewal_order_id'] ) : false;
			if ( $order && is_callable( array( $order, 'needs_payment' ) ) && $order->needs_payment() ) {
				$open[] = array(
					'renewal_id' => (int) $row['id'],
					'order_id'   => (int) $row['renewal_order_id'],
					'status'     => (string) $row['status'],
				);
			}
		}

		return $open;
	}

	/**
	 * Whether an automatic charge of this license is in flight.
	 *
	 * @param int $license_id License ID.
	 * @return bool
	 */
	public static function has_processing_renewal( int $license_id ): bool {
		global $wpdb;

		$tables = Schema::table_names();

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE license_id = %d AND status = %s", $tables['renewals'], $license_id, 'processing' )
		) > 0;
	}

	/**
	 * Release every active site of a license through the shared activation manager.
	 *
	 * @param int    $license_id    License ID.
	 * @param string $reason        Reason.
	 * @param int    $actor_user_id Acting user.
	 * @return int Number of released activations.
	 */
	private static function release_all_sites( int $license_id, string $reason, int $actor_user_id ): int {
		global $wpdb;

		$tables = Schema::table_names();
		$ids    = $wpdb->get_col(
			$wpdb->prepare( "SELECT id FROM %i WHERE license_id = %d AND status = %s ORDER BY staging_parent_id IS NULL ASC, id ASC LIMIT 1000", $tables['activations'], $license_id, 'active' )
		);
		$count  = 0;
		foreach ( is_array( $ids ) ? $ids : array() as $activation_id ) {
			// Shared implementation: also releases staging children and writes the audit entries.
			if ( ActivationManager::deactivate( (int) $activation_id, $reason, $actor_user_id, 'admin_site_released' ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Load one license row.
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
		$row    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE id = %d LIMIT 1", $tables['licenses'], $license_id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Whether a value is a real 'Y-m-d H:i:s' date.
	 *
	 * @param string $value Candidate.
	 * @return bool
	 */
	private static function valid_utc_datetime( string $value ): bool {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );

		return $date instanceof \DateTimeImmutable && $date->format( 'Y-m-d H:i:s' ) === $value;
	}
}
