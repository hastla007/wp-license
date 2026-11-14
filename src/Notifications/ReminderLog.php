<?php
/**
 * Idempotency log for customer notifications.
 *
 * @package WPLC
 */

namespace WPLC\Notifications;

use WPLC\Infrastructure\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes the {prefix}wplc_reminder_log table.
 *
 * The table is part of the versioned schema (Schema, since version 12).
 */
final class ReminderLog {

	/**
	 * A reminder was claimed by this call and must be sent now.
	 */
	public const CLAIMED = 1;

	/**
	 * The reminder was already claimed earlier.
	 */
	public const DUPLICATE = 0;

	/**
	 * The claim could not be stored.
	 */
	public const FAILED = -1;

	/**
	 * Fully prefixed table name.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		return Schema::table_names()['reminder_log'];
	}

	/**
	 * Whether the table can be used: the schema is current or was just migrated.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return Schema::maybe_upgrade();
	}

	/**
	 * Claim one notification for one license period before it is sent.
	 *
	 * @param int    $license_id    License ID.
	 * @param string $reminder_type Marker, at most 40 characters.
	 * @param string $period_end    UTC datetime of the period the notification belongs to.
	 * @return int CLAIMED, DUPLICATE or FAILED.
	 */
	public static function claim( int $license_id, string $reminder_type, string $period_end ): int {
		global $wpdb;

		if ( $license_id <= 0 || '' === $reminder_type || ! self::valid_datetime( $period_end ) || ! self::is_available() ) {
			return self::FAILED;
		}

		$table  = self::table_name();
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (license_id,reminder_type,period_end,sent_at) VALUES (%d,%s,%s,%s)",
				$license_id,
				substr( $reminder_type, 0, 40 ),
				$period_end,
				gmdate( 'Y-m-d H:i:s' )
			)
		);
		if ( false === $result ) {
			return self::FAILED;
		}

		return 1 === (int) $result ? self::CLAIMED : self::DUPLICATE;
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
