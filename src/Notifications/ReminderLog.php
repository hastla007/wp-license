<?php
/**
 * Idempotency log for customer notifications.
 *
 * @package WPLC
 */

namespace WPLC\Notifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the {prefix}wplc_reminder_log table.
 *
 * The table is self-installed because the notification package may not change
 * the central schema. It can be adopted by the schema class later: keep the
 * table name, the columns and the unique key, then drop the installer here.
 */
final class ReminderLog {

	/**
	 * Table layout version.
	 *
	 * 2: status column. A row is "pending" while the email is being handed to
	 *    the mailer and "sent" once the mailer accepted it.
	 */
	public const VERSION = '2';

	/**
	 * Option that stores the installed table version.
	 */
	public const VERSION_OPTION = 'wplc_reminder_log_version';

	/**
	 * A reminder was claimed by this call and must be sent now.
	 */
	public const CLAIMED = 1;

	/**
	 * The reminder was already sent, or is being sent by another process.
	 */
	public const DUPLICATE = 0;

	/**
	 * The claim could not be stored.
	 */
	public const FAILED = -1;

	/**
	 * Seconds after which an unconfirmed claim counts as abandoned (the process
	 * died between claim and send) and may be taken over.
	 */
	public const STALE_CLAIM_SECONDS = 900;

	/**
	 * Whether the table was verified during this request.
	 *
	 * @var bool
	 */
	private static $ready = false;

	/**
	 * Fully prefixed table name.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'wplc_reminder_log';
	}

	/**
	 * Create the table when it is missing or outdated.
	 *
	 * The version option alone is not trusted: after the tables were dropped by
	 * hand (uninstall, restore) the option can still claim that the table exists.
	 *
	 * @return bool True when the table is available.
	 */
	public static function maybe_install(): bool {
		global $wpdb;

		if ( self::$ready ) {
			return true;
		}

		$table = self::table_name();
		if ( version_compare( (string) get_option( self::VERSION_OPTION, '0' ), self::VERSION, '>=' ) && self::table_exists() ) {
			self::$ready = true;
			return true;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		// Rows written before version 2 were only stored for emails that had been handed over: default "sent".
		\dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				license_id bigint(20) unsigned NOT NULL,
				reminder_type varchar(40) NOT NULL,
				period_end datetime NOT NULL,
				sent_at datetime NOT NULL,
				status varchar(10) NOT NULL DEFAULT 'sent',
				PRIMARY KEY  (id),
				UNIQUE KEY license_reminder (license_id,reminder_type,period_end),
				KEY sent_at (sent_at)
			) {$charset_collate};"
		);

		if ( ! self::table_exists() || ! self::has_status_column() ) {
			return false;
		}

		update_option( self::VERSION_OPTION, self::VERSION, false );
		self::$ready = true;
		return true;
	}

	/**
	 * Forget the per-request verification (tests, after dropping the table).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$ready = false;
	}

	/**
	 * Whether the table exists in the current database.
	 *
	 * @return bool
	 */
	private static function table_exists(): bool {
		global $wpdb;

		return 1 === (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s',
				self::table_name()
			)
		);
	}

	/**
	 * Whether the status column of layout version 2 exists.
	 *
	 * @return bool
	 */
	private static function has_status_column(): bool {
		global $wpdb;

		return 1 === (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = %s AND column_name = %s',
				self::table_name(),
				'status'
			)
		);
	}

	/**
	 * Claim one notification for one license period before it is sent.
	 *
	 * The claim is stored as "pending". The caller must call confirm() after the
	 * mailer accepted the message, or release() when it did not. A pending claim
	 * that was never confirmed (crashed process) can be taken over after
	 * STALE_CLAIM_SECONDS.
	 *
	 * @param int    $license_id    License ID.
	 * @param string $reminder_type Marker, at most 40 characters.
	 * @param string $period_end    UTC datetime of the period the notification belongs to.
	 * @return int CLAIMED, DUPLICATE or FAILED.
	 */
	public static function claim( int $license_id, string $reminder_type, string $period_end ): int {
		global $wpdb;

		if ( $license_id <= 0 || '' === $reminder_type || ! self::valid_datetime( $period_end ) || ! self::maybe_install() ) {
			return self::FAILED;
		}

		$type   = substr( $reminder_type, 0, 40 );
		$now    = gmdate( 'Y-m-d H:i:s' );
		$result = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (license_id,reminder_type,period_end,sent_at,status) VALUES (%d,%s,%s,%s,%s)',
				self::table_name(),
				$license_id,
				$type,
				$period_end,
				$now,
				'pending'
			)
		);
		if ( false === $result ) {
			return self::FAILED;
		}
		if ( 1 === (int) $result ) {
			return self::CLAIMED;
		}

		// Compare-and-set take-over of a claim whose sender never reported back.
		$taken = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET sent_at = %s WHERE license_id = %d AND reminder_type = %s AND period_end = %s AND status = %s AND sent_at < %s',
				self::table_name(),
				$now,
				$license_id,
				$type,
				$period_end,
				'pending',
				gmdate( 'Y-m-d H:i:s', time() - self::STALE_CLAIM_SECONDS )
			)
		);

		return 1 === (int) $taken ? self::CLAIMED : self::DUPLICATE;
	}

	/**
	 * Mark a claimed notification as sent.
	 *
	 * @param int    $license_id    License ID.
	 * @param string $reminder_type Marker.
	 * @param string $period_end    UTC datetime of the period.
	 * @return bool
	 */
	public static function confirm( int $license_id, string $reminder_type, string $period_end ): bool {
		global $wpdb;

		if ( $license_id <= 0 || ! self::valid_datetime( $period_end ) || ! self::maybe_install() ) {
			return false;
		}

		return false !== $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, sent_at = %s WHERE license_id = %d AND reminder_type = %s AND period_end = %s AND status = %s',
				self::table_name(),
				'sent',
				gmdate( 'Y-m-d H:i:s' ),
				$license_id,
				substr( $reminder_type, 0, 40 ),
				$period_end,
				'pending'
			)
		);
	}

	/**
	 * Give a claim back after the mailer refused the message, so that it is tried again.
	 *
	 * @param int    $license_id    License ID.
	 * @param string $reminder_type Marker.
	 * @param string $period_end    UTC datetime of the period.
	 * @return bool
	 */
	public static function release( int $license_id, string $reminder_type, string $period_end ): bool {
		global $wpdb;

		if ( $license_id <= 0 || ! self::valid_datetime( $period_end ) || ! self::maybe_install() ) {
			return false;
		}

		return false !== $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE license_id = %d AND reminder_type = %s AND period_end = %s AND status = %s',
				self::table_name(),
				$license_id,
				substr( $reminder_type, 0, 40 ),
				$period_end,
				'pending'
			)
		);
	}

	/**
	 * Whether a notification was sent (confirmed) for a license period.
	 *
	 * @param int    $license_id    License ID.
	 * @param string $reminder_type Marker.
	 * @param string $period_end    UTC datetime of the period.
	 * @return bool
	 */
	public static function was_sent( int $license_id, string $reminder_type, string $period_end ): bool {
		global $wpdb;

		if ( $license_id <= 0 || ! self::valid_datetime( $period_end ) || ! self::maybe_install() ) {
			return false;
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE license_id = %d AND reminder_type = %s AND period_end = %s AND status = %s',
				self::table_name(),
				$license_id,
				substr( $reminder_type, 0, 40 ),
				$period_end,
				'sent'
			)
		) > 0;
	}

	/**
	 * Validate a MySQL UTC datetime string.
	 *
	 * @param string $value Datetime.
	 * @return bool
	 */
	private static function valid_datetime( string $value ): bool {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
		return $date instanceof \DateTimeImmutable && $date->format( 'Y-m-d H:i:s' ) === $value;
	}
}
