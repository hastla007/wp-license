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
	 */
	public const VERSION = '1';

	/**
	 * Option that stores the installed table version.
	 */
	public const VERSION_OPTION = 'wplc_reminder_log_version';

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
	 * @return bool True when the table is available.
	 */
	public static function maybe_install(): bool {
		global $wpdb;

		if ( self::$ready ) {
			return true;
		}
		if ( version_compare( (string) get_option( self::VERSION_OPTION, '0' ), self::VERSION, '>=' ) ) {
			self::$ready = true;
			return true;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();
		\dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				license_id bigint(20) unsigned NOT NULL,
				reminder_type varchar(40) NOT NULL,
				period_end datetime NOT NULL,
				sent_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY license_reminder (license_id,reminder_type,period_end),
				KEY sent_at (sent_at)
			) {$charset_collate};"
		);

		$exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s',
				$table
			)
		);
		if ( 1 !== (int) $exists ) {
			return false;
		}

		update_option( self::VERSION_OPTION, self::VERSION, false );
		self::$ready = true;
		return true;
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

		if ( $license_id <= 0 || '' === $reminder_type || ! self::valid_datetime( $period_end ) || ! self::maybe_install() ) {
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
