<?php
/**
 * Announced change of the renewal price of existing licenses (INH-05).
 *
 * @package WPLC
 */

namespace WPLC\Billing;

use WPLC\Infrastructure\Database\Schema;
use WPLC\License\AuditLog;
use WPLC\Maintenance\SiteGuard;
use WPLC\Notifications\Notifications;
use WPLC\Notifications\ReminderLog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The price guarantee of a license (RenewalOrder::price()) can end, with advance notice.
 *
 * Owner decision of 16 Nov 2026: an existing license may be moved to a higher renewal amount
 * when the customer was told by email a notice period before and can cancel. The rules:
 *
 * - An announcement stores the new amount, the date from which it applies, the notice period
 *   in force and when the announcement was made. The stored renewal amount stays.
 * - The email is sent at once. Only when the mailer accepted it is `price_change_notified_at`
 *   written (compare-and-set). Without that timestamp the new amount never applies.
 * - The new amount applies from `applies_at()`: the later of the announced date and the notice
 *   date plus the notice period. Before that every renewal, automatic and manual, bills the
 *   stored amount; from then on the new one. An order that was priced before keeps its amount.
 * - The daily job writes the new amount into `renewal_amount_minor` once it applies and
 *   clears the announcement; until the job ran, RenewalOrder::price() already uses it.
 * - Lowering an amount needs no announcement (support action "Change renewal amount").
 *
 * The notice period and the wording of the email are subject to legal review (T-93).
 */
final class PriceChange {

	/**
	 * Default notice period in days.
	 */
	public const DEFAULT_NOTICE_DAYS = 60;

	/**
	 * Shortest notice period that can be configured.
	 */
	public const MIN_NOTICE_DAYS = 30;

	/**
	 * Longest notice period that can be configured.
	 */
	public const MAX_NOTICE_DAYS = 365;

	/**
	 * Marker of the notice in the reminder log.
	 */
	public const REMINDER_TYPE = 'price_change';

	public const RESULT_OK                = 'ok';
	public const RESULT_FAILED            = 'failed';
	public const RESULT_STALE             = 'stale';
	public const RESULT_NOT_AN_INCREASE   = 'not_an_increase';
	public const RESULT_NOTICE_TOO_SHORT  = 'notice_too_short';
	public const RESULT_ALREADY_ANNOUNCED = 'already_announced';
	public const RESULT_NOTHING_ANNOUNCED = 'nothing_announced';

	/**
	 * Licenses handled per run of the daily job and per step.
	 */
	private const BATCH = 200;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		// After the lifecycle and reminder jobs of the day.
		add_action( 'wplc_daily_lifecycle', array( self::class, 'daily' ), 60 );
		add_action( 'wplc/license_plan_changed', array( self::class, 'on_plan_changed' ), 10, 1 );
	}

	/**
	 * Notice period in days: the setting, at least MIN_NOTICE_DAYS.
	 *
	 * @return int
	 */
	public static function notice_days(): int {
		$days    = self::DEFAULT_NOTICE_DAYS;
		$setting = \WPLC\Settings\Registry::stored()['price_change_notice_days'] ?? null;
		if ( is_numeric( $setting ) ) {
			$days = (int) $setting;
		}

		/**
		 * Filters the notice period of a renewal price change in days.
		 *
		 * The result is kept between 30 and 365 days: the floor cannot be lowered by a filter.
		 *
		 * @param int $days Days from the setting "Price change notice period" (default 60).
		 */
		$days = function_exists( 'apply_filters' ) ? (int) apply_filters( 'wplc_price_change_notice_days', $days ) : $days;

		return self::clamp_notice_days( $days );
	}

	/**
	 * Keep a notice period inside the allowed range. Pure.
	 *
	 * @param int $days Requested days.
	 * @return int
	 */
	public static function clamp_notice_days( int $days ): int {
		return max( self::MIN_NOTICE_DAYS, min( self::MAX_NOTICE_DAYS, $days ) );
	}

	/**
	 * The announcement stored on a license. Pure.
	 *
	 * @param array<string,mixed> $license License row.
	 * @return array{amount_minor:int,effective_at:int,announced_at:string,notified_at:int,notice_days:int,applies_at:int}|null
	 *         Timestamps are Unix times; `notified_at` and `applies_at` are 0 while the notice was not sent.
	 */
	public static function pending( array $license ): ?array {
		$amount    = (int) ( $license['price_change_amount_minor'] ?? 0 );
		$effective = empty( $license['price_change_effective_at'] ) ? false : strtotime( (string) $license['price_change_effective_at'] . ' UTC' );
		if ( $amount <= 0 || false === $effective ) {
			return null;
		}
		$notified = empty( $license['price_change_notified_at'] ) ? false : strtotime( (string) $license['price_change_notified_at'] . ' UTC' );
		$notified = false === $notified ? 0 : (int) $notified;
		// A row without the stored period is treated with the longest one, never with a shorter one.
		$days = isset( $license['price_change_notice_days'] ) && (int) $license['price_change_notice_days'] > 0
			? self::clamp_notice_days( (int) $license['price_change_notice_days'] )
			: self::MAX_NOTICE_DAYS;

		return array(
			'amount_minor' => $amount,
			'effective_at' => (int) $effective,
			'announced_at' => (string) ( $license['price_change_announced_at'] ?? '' ),
			'notified_at'  => $notified,
			'notice_days'  => $days,
			'applies_at'   => $notified > 0 ? max( (int) $effective, $notified + $days * 86400 ) : 0,
		);
	}

	/**
	 * Whether the announced amount is the price of a renewal priced at a given time. Pure.
	 *
	 * True only when the notice was accepted by the mailer at least the notice period before.
	 *
	 * @param array<string,mixed> $license License row.
	 * @param int                 $now     Unix time at which the renewal is priced.
	 * @return bool
	 */
	public static function applies( array $license, int $now ): bool {
		$pending = self::pending( $license );

		return null !== $pending && $pending['applies_at'] > 0 && $now >= $pending['applies_at'];
	}

	/**
	 * The license row as it is to be priced at a given time: with the announced amount once it applies. Pure.
	 *
	 * Currency, precision and the gross/net flag stay those of the stored amount: an announcement
	 * is entered in the same form as the amount it replaces.
	 *
	 * @param array<string,mixed> $license License row.
	 * @param int                 $now     Unix time.
	 * @return array<string,mixed>
	 */
	public static function license_at( array $license, int $now ): array {
		if ( self::applies( $license, $now ) ) {
			$license['renewal_amount_minor'] = (int) $license['price_change_amount_minor'];
		}

		return $license;
	}

	/**
	 * Why an announcement would be refused, before anything is written. Pure.
	 *
	 * @param array<string,mixed> $license      License row.
	 * @param int                 $amount_minor New amount in minor units.
	 * @param int                 $effective_at Unix time from which the amount is to apply.
	 * @param int                 $now          Unix time.
	 * @param int                 $notice_days  Notice period in days.
	 * @return string RESULT_OK or the reason.
	 */
	public static function check( array $license, int $amount_minor, int $effective_at, int $now, int $notice_days ): string {
		if ( $amount_minor <= 0 || ! in_array( (string) ( $license['status'] ?? '' ), array( 'active', 'past_due' ), true ) || (int) ( $license['renewal_amount_minor'] ?? 0 ) <= 0 ) {
			return self::RESULT_FAILED;
		}
		if ( null !== self::pending( $license ) ) {
			return self::RESULT_ALREADY_ANNOUNCED;
		}
		if ( $amount_minor <= (int) $license['renewal_amount_minor'] ) {
			return self::RESULT_NOT_AN_INCREASE;
		}
		// Whole days: the form takes a date, and the day of the announcement does not count.
		if ( $effective_at < $now + self::clamp_notice_days( $notice_days ) * 86400 ) {
			return self::RESULT_NOTICE_TOO_SHORT;
		}

		return self::RESULT_OK;
	}

	/**
	 * Earliest date an announcement made now can name (UTC day, 00:00).
	 *
	 * @param int $now Unix time.
	 * @return int Unix time.
	 */
	public static function earliest_effective_at( int $now ): int {
		$day = (int) ( floor( ( $now + self::notice_days() * 86400 ) / 86400 ) + 1 ) * 86400;

		return $day;
	}

	/**
	 * Announce a higher renewal amount for one license and send the notice.
	 *
	 * @param int    $license_id    License ID.
	 * @param int    $amount_minor  New amount in minor units, in the form of the stored amount.
	 * @param int    $effective_at  Unix time from which it is to apply; at least the notice period ahead.
	 * @param string $reason        Reason for the audit log.
	 * @param int    $actor_user_id Acting user.
	 * @return string One of the RESULT_* constants.
	 */
	public static function announce( int $license_id, int $amount_minor, int $effective_at, string $reason, int $actor_user_id ): string {
		global $wpdb;

		$reason  = trim( $reason );
		$license = Notifications::get_license( $license_id );
		if ( '' === $reason || ! $license ) {
			return self::RESULT_FAILED;
		}
		$now    = time();
		$days   = self::notice_days();
		$result = self::check( $license, $amount_minor, $effective_at, $now, $days );
		if ( self::RESULT_OK !== $result ) {
			return $result;
		}

		$tables    = Schema::table_names();
		$announced = gmdate( 'Y-m-d H:i:s', $now );
		// The reminder log knows one notice per license and announcement time. An announcement made in the
		// same second as a withdrawn one (scripts) must still get its own notice.
		for ( $shift = 1; $shift <= 5 && ReminderLog::was_sent( $license_id, self::REMINDER_TYPE, $announced ); $shift++ ) {
			$announced = gmdate( 'Y-m-d H:i:s', $now + $shift );
		}
		// Compare-and-set: only a license that still has the amount and status that were checked, and no announcement.
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET price_change_amount_minor = %d, price_change_effective_at = %s, price_change_announced_at = %s, price_change_notified_at = NULL, price_change_notice_days = %d, updated_at = %s
				WHERE id = %d AND price_change_amount_minor IS NULL AND renewal_amount_minor = %d AND status = %s',
				$tables['licenses'],
				$amount_minor,
				gmdate( 'Y-m-d H:i:s', $effective_at ),
				$announced,
				$days,
				$announced,
				$license_id,
				(int) $license['renewal_amount_minor'],
				(string) $license['status']
			)
		);
		if ( 1 !== (int) $updated ) {
			return self::RESULT_STALE;
		}

		AuditLog::record(
			'renewal_price_change_announced',
			'license',
			$license_id,
			$reason,
			array(
				'renewal_amount_minor' => (int) $license['renewal_amount_minor'],
				'renewal_currency'     => (string) $license['renewal_currency'],
			),
			array(
				'price_change_amount_minor' => $amount_minor,
				'price_change_effective_at' => gmdate( 'Y-m-d H:i:s', $effective_at ),
				'price_change_notice_days'  => $days,
			),
			$actor_user_id
		);

		self::send_notice( $license_id );

		return self::RESULT_OK;
	}

	/**
	 * Send the notice of an announcement that has none yet, and record when the mailer accepted it.
	 *
	 * @param int $license_id License ID.
	 * @return bool True when the notice is recorded as sent after this call.
	 */
	public static function send_notice( int $license_id ): bool {
		global $wpdb;

		$license = Notifications::get_license( $license_id );
		$pending = $license ? self::pending( $license ) : null;
		if ( ! $license || null === $pending ) {
			return false;
		}
		if ( $pending['notified_at'] > 0 ) {
			return true;
		}
		// A copy of the shop (staging) never writes to customers.
		if ( SiteGuard::billing_paused() || '' === $pending['announced_at'] ) {
			return false;
		}
		if ( ! Notifications::send_price_change_notice( $license, $pending ) ) {
			return false;
		}

		$tables = Schema::table_names();
		$now    = gmdate( 'Y-m-d H:i:s' );
		// The timestamp belongs to this announcement only: a withdrawn or replaced one is not marked.
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET price_change_notified_at = %s, updated_at = %s WHERE id = %d AND price_change_announced_at = %s AND price_change_amount_minor = %d AND price_change_notified_at IS NULL',
				$tables['licenses'],
				$now,
				$now,
				$license_id,
				$pending['announced_at'],
				$pending['amount_minor']
			)
		);
		if ( 1 !== (int) $updated ) {
			return false;
		}
		AuditLog::record(
			'renewal_price_change_notified',
			'license',
			$license_id,
			'',
			null,
			array(
				'price_change_notified_at' => $now,
				'applies_from'             => gmdate( 'Y-m-d H:i:s', max( $pending['effective_at'], time() + $pending['notice_days'] * 86400 ) ),
			),
			0
		);

		return true;
	}

	/**
	 * Withdraw an announcement before it took effect and tell the customer.
	 *
	 * @param int    $license_id    License ID.
	 * @param string $reason        Reason for the audit log.
	 * @param int    $actor_user_id Acting user.
	 * @return string RESULT_OK, RESULT_NOTHING_ANNOUNCED, RESULT_STALE or RESULT_FAILED.
	 */
	public static function withdraw( int $license_id, string $reason, int $actor_user_id ): string {
		$reason  = trim( $reason );
		$license = Notifications::get_license( $license_id );
		if ( '' === $reason || ! $license ) {
			return self::RESULT_FAILED;
		}
		$pending = self::pending( $license );
		if ( null === $pending ) {
			return self::RESULT_NOTHING_ANNOUNCED;
		}
		if ( ! self::clear( $license, $pending ) ) {
			return self::RESULT_STALE;
		}
		AuditLog::record( 'renewal_price_change_withdrawn', 'license', $license_id, $reason, self::audit_state( $pending ), null, $actor_user_id );

		// Only a customer who was told about the change is told that it is off.
		if ( $pending['notified_at'] > 0 && ! SiteGuard::billing_paused() ) {
			Notifications::send_price_change_withdrawn( $license, $pending );
		}

		return self::RESULT_OK;
	}

	/**
	 * A plan change sets the renewal amount of the new plan: an announcement for the old plan no longer fits.
	 *
	 * @param mixed $license_id License ID.
	 * @return void
	 */
	public static function on_plan_changed( $license_id = 0 ): void {
		$license = Notifications::get_license( (int) $license_id );
		$pending = $license ? self::pending( $license ) : null;
		if ( ! $license || null === $pending ) {
			return;
		}
		if ( self::clear( $license, $pending ) ) {
			AuditLog::record( 'renewal_price_change_dropped', 'license', (int) $license_id, 'plan_changed', self::audit_state( $pending ), null, 0 );
		}
	}

	/**
	 * Daily job: send notices that are still missing, write amounts that apply, report announcements that cannot apply.
	 *
	 * @return void
	 */
	public static function daily(): void {
		global $wpdb;

		$tables = Schema::table_names();
		$now    = time();

		// 1. Notices the mailer refused or that were announced while billing was paused.
		$missing = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE price_change_amount_minor IS NOT NULL AND price_change_notified_at IS NULL AND status IN (%s, %s) ORDER BY id ASC LIMIT %d',
				$tables['licenses'],
				'active',
				'past_due',
				self::BATCH
			)
		);
		foreach ( (array) $missing as $license_id ) {
			self::send_notice( (int) $license_id );
		}

		// 2. Announcements whose date has come.
		$due = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE price_change_amount_minor IS NOT NULL AND price_change_effective_at <= %s AND status IN (%s, %s) ORDER BY id ASC LIMIT %d',
				$tables['licenses'],
				gmdate( 'Y-m-d H:i:s', $now ),
				'active',
				'past_due',
				self::BATCH
			),
			ARRAY_A
		);
		foreach ( (array) $due as $license ) {
			if ( self::applies( $license, $now ) ) {
				self::promote( $license );
			} elseif ( empty( $license['price_change_notified_at'] ) ) {
				self::report_not_applied( $license );
			}
		}
	}

	/**
	 * Make the announced amount the stored renewal amount.
	 *
	 * @param array<string,mixed> $license License row with an announcement that applies.
	 * @return bool
	 */
	public static function promote( array $license ): bool {
		global $wpdb;

		$pending = self::pending( $license );
		if ( null === $pending ) {
			return false;
		}
		$tables  = Schema::table_names();
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET renewal_amount_minor = %d, price_change_amount_minor = NULL, price_change_effective_at = NULL, price_change_announced_at = NULL, price_change_notified_at = NULL, price_change_notice_days = NULL, updated_at = %s
				WHERE id = %d AND price_change_amount_minor = %d AND renewal_amount_minor = %d AND price_change_notified_at IS NOT NULL',
				$tables['licenses'],
				$pending['amount_minor'],
				gmdate( 'Y-m-d H:i:s' ),
				(int) $license['id'],
				$pending['amount_minor'],
				(int) $license['renewal_amount_minor']
			)
		);
		if ( 1 !== (int) $updated ) {
			return false;
		}
		AuditLog::record(
			'renewal_price_change_applied',
			'license',
			(int) $license['id'],
			'',
			array( 'renewal_amount_minor' => (int) $license['renewal_amount_minor'] ) + self::audit_state( $pending ),
			array( 'renewal_amount_minor' => $pending['amount_minor'] ),
			0
		);

		return true;
	}

	/**
	 * The date of an announcement passed without a recorded notice: the old amount stays. Reported once.
	 *
	 * @param array<string,mixed> $license License row.
	 * @return void
	 */
	private static function report_not_applied( array $license ): void {
		global $wpdb;

		$tables = Schema::table_names();
		$known  = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE object_type = %s AND object_id = %d AND action = %s AND created_at >= %s',
				$tables['audit_log'],
				'license',
				(int) $license['id'],
				'renewal_price_change_not_applied',
				(string) $license['price_change_announced_at']
			)
		);
		if ( (int) $known > 0 ) {
			return;
		}
		$pending = self::pending( $license );
		AuditLog::record( 'renewal_price_change_not_applied', 'license', (int) $license['id'], 'notice_not_sent', null === $pending ? null : self::audit_state( $pending ), null, 0 );

		/**
		 * Fires once when the date of an announced renewal price passed without a recorded notice email.
		 *
		 * The license keeps renewing at its stored amount. Passed on as `wplc/health_alert`.
		 *
		 * @param int    $license_id License ID.
		 * @param string $reason     `notice_not_sent`.
		 */
		do_action( 'wplc/price_change_not_applied', (int) $license['id'], 'notice_not_sent' );
	}

	/**
	 * Remove the announcement from a license (compare-and-set on the announcement itself).
	 *
	 * @param array<string,mixed>                                                                                              $license License row.
	 * @param array{amount_minor:int,effective_at:int,announced_at:string,notified_at:int,notice_days:int,applies_at:int} $pending Announcement.
	 * @return bool
	 */
	private static function clear( array $license, array $pending ): bool {
		global $wpdb;

		$tables  = Schema::table_names();
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET price_change_amount_minor = NULL, price_change_effective_at = NULL, price_change_announced_at = NULL, price_change_notified_at = NULL, price_change_notice_days = NULL, updated_at = %s
				WHERE id = %d AND price_change_amount_minor = %d AND price_change_announced_at = %s',
				$tables['licenses'],
				gmdate( 'Y-m-d H:i:s' ),
				(int) $license['id'],
				$pending['amount_minor'],
				$pending['announced_at']
			)
		);

		return 1 === (int) $updated;
	}

	/**
	 * @param array{amount_minor:int,effective_at:int,announced_at:string,notified_at:int,notice_days:int,applies_at:int} $pending Announcement.
	 * @return array<string,mixed>
	 */
	private static function audit_state( array $pending ): array {
		return array(
			'price_change_amount_minor' => $pending['amount_minor'],
			'price_change_effective_at' => gmdate( 'Y-m-d H:i:s', $pending['effective_at'] ),
			'price_change_notified_at'  => $pending['notified_at'] > 0 ? gmdate( 'Y-m-d H:i:s', $pending['notified_at'] ) : '',
		);
	}

	/**
	 * Announce one amount for every license of a product and plan.
	 *
	 * Only licenses in the given currency whose stored amount is below the new one and that have no
	 * announcement are read; each is checked like a single announcement.
	 *
	 * @param string $product_key   License product key.
	 * @param string $plan_key      Plan key, or an empty string for every plan of the product.
	 * @param int    $amount_minor  New amount in minor units.
	 * @param string $currency      Currency of the amount.
	 * @param int    $effective_at  Unix time from which it is to apply.
	 * @param string $reason        Reason for the audit log.
	 * @param int    $actor_user_id Acting user.
	 * @param bool   $dry_run       Count only.
	 * @param int    $after_id      Continue after this license ID.
	 * @param int    $limit         Licenses per call.
	 * @return array{announced:int,skipped:array<string,int>,last_id:int,more:bool}
	 */
	public static function announce_for_plan( string $product_key, string $plan_key, int $amount_minor, string $currency, int $effective_at, string $reason, int $actor_user_id, bool $dry_run = false, int $after_id = 0, int $limit = 200 ): array {
		global $wpdb;

		$tables = Schema::table_names();
		$limit  = max( 1, min( 1000, $limit ) );
		$report = array(
			'announced' => 0,
			'skipped'   => array(),
			'last_id'   => $after_id,
			'more'      => false,
		);
		// Only licenses an announcement can apply to, so that a repeated call always moves on.
		$sql    = 'SELECT * FROM %i WHERE product_key = %s AND status IN (%s, %s) AND id > %d AND price_change_amount_minor IS NULL AND renewal_amount_minor > 0 AND renewal_amount_minor < %d AND renewal_currency = %s';
		$args   = array( $tables['licenses'], $product_key, 'active', 'past_due', $after_id, $amount_minor, strtoupper( $currency ) );
		if ( '' !== $plan_key ) {
			$sql   .= ' AND plan_key = %s';
			$args[] = $plan_key;
		}
		$sql   .= ' ORDER BY id ASC LIMIT %d';
		$args[] = $limit + 1;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the statement is assembled from fixed parts and prepared here.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		if ( count( $rows ) > $limit ) {
			$report['more'] = true;
			array_pop( $rows );
		}
		$now  = time();
		$days = self::notice_days();
		foreach ( $rows as $license ) {
			$report['last_id'] = (int) $license['id'];
			$result            = strtoupper( (string) $license['renewal_currency'] ) !== strtoupper( $currency )
				? 'other_currency'
				: self::check( $license, $amount_minor, $effective_at, $now, $days );
			if ( self::RESULT_OK === $result && ! $dry_run ) {
				$result = self::announce( (int) $license['id'], $amount_minor, $effective_at, $reason, $actor_user_id );
			}
			if ( self::RESULT_OK === $result ) {
				++$report['announced'];
			} else {
				$report['skipped'][ $result ] = ( $report['skipped'][ $result ] ?? 0 ) + 1;
			}
		}

		return $report;
	}
}
