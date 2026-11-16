<?php
/**
 * Creates and upgrades WP License Captain tables.
 *
 * @package WPLC
 */

namespace WPLC\Infrastructure\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Versioned database schema.
 */
final class Schema {

	/**
	 * Current schema version.
	 *
	 * Two development lines used the numbers 12 to 20 with different meanings
	 * before they were merged. Nothing was released, but test shops can sit on
	 * either numbering, so every step below is idempotent and step 21 runs all
	 * of them again. The CREATE TABLE statements contain the columns, indexes
	 * and tables of both lines; dbDelta adds what is missing on every migration.
	 *
	 * 11: renewals.status widened to 30 characters ("manual_action_required" has 22;
	 *     WordPress refuses to write a value longer than the column).
	 * Line A (licenses/renewals review, T-numbers):
	 * 12: reminder_log adopted from ReminderLog, which created it on first use
	 *     (same name, columns and keys).
	 * 13: licenses.renewal_product_id, the purchased WooCommerce product or
	 *     variation whose regular price renews the license (T-132).
	 * 14: renewals.review_reason and renewals.review_at, the reason and start of
	 *     an open review case (T-154, T-137); existing cases are backfilled.
	 * 15: indexes for the license list, the account page and diagnostics (T-89).
	 * 16: download_tokens.activation_id, the installation an update grant was
	 *     issued to (T-108); older grants keep 0 and expire within minutes.
	 * 17: licenses.pending_plan_key, licenses.pending_product_id (downgrade at the
	 *     next renewal) and licenses.plan_order_id (the paid upgrade order that set
	 *     the current plan), for plan changes (T-110).
	 * Line B (foundation/Stripe, A-/B-numbers):
	 * 12: renewals.last_error and renewals.last_attempt_at (reason and time of the last
	 *     attempt), licenses.renewal_amount_includes_tax (NULL = unknown). Columns only,
	 *     added by dbDelta; no data step.
	 * 13: table `consents` (evidence of the automatic renewal consent per license, B-37).
	 * 15: releases.requires_core and releases.plugin_file (added by dbDelta, no data step).
	 * 18: {prefix}wplc_reminder_log is part of the schema (it used to install itself),
	 *     with the status column of layout 2; indexes for retention and diagnostics
	 *     (activations.status_deactivated, event_inbox.outcome_processed).
	 * 19: one heartbeat store: the options `wplc_last_daily_run` and `wplc_job_heartbeats`
	 *     are folded into `wplc_last_run` and deleted. Indexes licenses.source_order
	 *     (licenses of an order: refunds, order screen, reconciliation) and
	 *     licenses.created_at (default sorting of the admin list), added by dbDelta (A-105).
	 * 20: licenses.gateway_customer_ref, gateway_method_ref, gateway_mode (payment reference of
	 *     the gateway at purchase, B-57). Nullable columns only, added by dbDelta; no data step.
	 * Merged:
	 * 21: both lines in one schema. Runs every data and verification step of both
	 *     lines again (all idempotent), so an install recorded at 17 (line A) or
	 *     20 (line B) also ends up complete.
	 * 22: indexes licenses.payment_token (licenses of a deleted payment token: Orphans,
	 *     Stripe token sync) and event_inbox.source_received (time of the last event per
	 *     source on the diagnostics page), added by dbDelta (P-31, P-53). From this version
	 *     on the daily structure check also compares the declared indexes.
	 * 24: the entitlement catalog is data (T-150): the catalog files shipped in `catalogs/` are
	 *     imported into the option `wplc_entitlement_catalog`, so an installation that used the
	 *     former hard-coded lists offers the same products and keys. No table changes.
	 * 25: licenses.price_change_amount_minor, price_change_effective_at, price_change_announced_at,
	 *     price_change_notified_at and price_change_notice_days: an announced change of the renewal
	 *     amount (INH-05). Nullable columns added by dbDelta; the step checks that they exist.
	 */
	public const VERSION = '25';

	/**
	 * Option that stores the installed schema version (autoloaded).
	 */
	public const VERSION_OPTION = 'wplc_schema_version';

	/**
	 * Seconds to wait for a concurrent migration before giving up for this request.
	 */
	private const LOCK_TIMEOUT = 3;

	/**
	 * Option with the details of the last failed migration (not autoloaded).
	 */
	public const ERROR_OPTION = 'wplc_schema_last_error';

	/**
	 * Option with the plain message of the last failed migration, shown on the diagnostics page.
	 */
	public const DIAGNOSTICS_ERROR_OPTION = 'wplc_schema_error';

	/**
	 * Autoloaded option "<version>|<unix time>" of the last successful structure check.
	 */
	public const VERIFIED_OPTION = 'wplc_schema_verified';

	/**
	 * Seconds between two structure checks of an up-to-date schema (self-healing).
	 */
	private const VERIFY_INTERVAL = 86400;

	/**
	 * Seconds to wait after a failed migration before the next automatic attempt.
	 */
	private const RETRY_DELAY = 300;

	/**
	 * Data-migration steps, keyed by the schema version they bring the data to.
	 *
	 * Every step runs after dbDelta, must be idempotent (it can run again after a
	 * partial failure or on a fresh install with empty tables) and returns true
	 * on success. Add new steps here together with a VERSION bump.
	 *
	 * Keys are integers at runtime (PHP casts numeric string keys).
	 *
	 * @var array<int,array{0:class-string,1:string}>
	 */
	private static $steps = array(
		'10' => array( self::class, 'step_10_autoload_schema_version' ),
		'12' => array( self::class, 'step_12_adopt_reminder_log' ),
		'13' => array( self::class, 'step_13_renewal_product_column' ),
		'14' => array( self::class, 'step_14_renewal_review_columns' ),
		'15' => array( self::class, 'step_15_list_indexes' ),
		'16' => array( self::class, 'step_16_grant_activation_column' ),
		'17' => array( self::class, 'step_17_plan_change_columns' ),
		'18' => array( self::class, 'step_18_adopt_reminder_log' ),
		'19' => array( self::class, 'step_19_one_heartbeat_store' ),
		'21' => array( self::class, 'step_21_merged_schema' ),
		'22' => array( self::class, 'step_22_lookup_indexes' ),
		'23' => array( self::class, 'step_23_audit_source' ),
		'24' => array( self::class, 'step_24_import_bundled_catalogs' ),
		'25' => array( self::class, 'step_25_price_change_columns' ),
	);

	/**
	 * Register schema related hooks.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		// Also registered by wp-license.php when the file is loaded; add_filter() keeps one entry.
		add_filter( 'wpmu_drop_tables', array( self::class, 'filter_wpmu_drop_tables' ), 10, 2 );
		add_action( 'admin_notices', array( self::class, 'render_admin_notice' ) );
	}

	/**
	 * Run missing schema migrations and repair a damaged schema.
	 *
	 * Called on every request, so the common case (version current, structure
	 * checked recently) costs no query. Rules:
	 * - Installed version newer than this code (downgrade): nothing is changed,
	 *   see is_downgraded().
	 * - Version current: once per VERIFY_INTERVAL the columns are compared with
	 *   information_schema; missing tables or columns are recreated.
	 * - Cached version older than the code but the database row is current
	 *   (stale object cache, restore without flush): only the cache is corrected.
	 * - Version older: migrate, but only in admin, cron and CLI requests and
	 *   without waiting for the lock. Front-end and REST requests never migrate.
	 *
	 * @return bool True when the schema is usable for this code version.
	 */
	public static function maybe_upgrade(): bool {
		$installed = (string) get_option( self::VERSION_OPTION, '0' );
		if ( version_compare( $installed, self::VERSION, '>' ) ) {
			return true;
		}

		if ( version_compare( $installed, self::VERSION, '==' ) ) {
			if ( ! self::verification_due( (string) get_option( self::VERIFIED_OPTION, '' ), self::VERSION, time(), self::VERIFY_INTERVAL ) || ! self::can_migrate_in_this_request() ) {
				return true;
			}
			if ( ! self::verify() ) {
				self::mark_verified();
				return true;
			}

			// Tables or columns are missing although the version says current: rebuild.
			return self::retry_allowed() ? self::migrate( 0 ) : false;
		}

		if ( version_compare( self::stored_version(), self::VERSION, '>=' ) ) {
			// The database is current, only the cached option value is stale.
			self::refresh_cached_version();
			return true;
		}

		if ( ! self::can_migrate_in_this_request() || ! self::retry_allowed() ) {
			return false;
		}

		return self::migrate( 0 );
	}

	/**
	 * Whether the database was written by a newer plugin version than this code.
	 *
	 * After a rollback to an older plugin release the old code would run against
	 * a newer schema. Nothing is migrated in that state, administrators see a
	 * notice and SiteGuard::billing_paused() stops charges until the plugin is
	 * updated again.
	 *
	 * @return bool
	 */
	public static function is_downgraded(): bool {
		return version_compare( (string) get_option( self::VERSION_OPTION, '0' ), self::VERSION, '>' );
	}

	/**
	 * Target/actual comparison for the diagnostics page.
	 *
	 * @return array{installed:string,expected:string,downgraded:bool,problems:string[],last_error:array<string,mixed>|null}
	 */
	public static function status(): array {
		$error = get_option( self::ERROR_OPTION, null );

		return array(
			'installed'  => (string) get_option( self::VERSION_OPTION, '0' ),
			'expected'   => self::VERSION,
			'downgraded' => self::is_downgraded(),
			'problems'   => self::is_downgraded() ? array() : self::verify(),
			'last_error' => is_array( $error ) ? $error : null,
		);
	}

	/**
	 * Compare every table with the columns this code expects.
	 *
	 * @return string[] Problems such as "wp_wplc_renewals.status: varchar(20), expected varchar(30)"; empty when the schema is complete.
	 */
	public static function verify(): array {
		global $wpdb;

		$expected = array();
		foreach ( self::table_statements() as $statement ) {
			$parsed = self::expected_columns( $statement );
			if ( '' !== $parsed['table'] ) {
				$expected[ $parsed['table'] ] = $parsed['columns'];
			}
		}
		if ( ! $expected ) {
			return array( 'No table definitions found.' );
		}

		$placeholders = implode( ',', array_fill( 0, count( $expected ), '%s' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- one %s per table name.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT TABLE_NAME AS tbl, COLUMN_NAME AS col, DATA_TYPE AS data_type, CHARACTER_MAXIMUM_LENGTH AS max_length FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name IN ({$placeholders})",
				array_keys( $expected )
			),
			ARRAY_A
		);
		// phpcs:enable
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			return array( 'The table structure could not be read: ' . (string) $wpdb->last_error );
		}

		$actual = array();
		foreach ( $rows as $row ) {
			$actual[ (string) $row['tbl'] ][ strtolower( (string) $row['col'] ) ] = array(
				'type'   => strtolower( (string) $row['data_type'] ),
				'length' => null === $row['max_length'] ? 0 : (int) $row['max_length'],
			);
		}

		$problems = array();
		$present  = array();
		foreach ( $expected as $table => $columns ) {
			// Table names are compared case-insensitively (lower_case_table_names).
			$found = array();
			foreach ( $actual as $actual_table => $actual_columns ) {
				if ( 0 === strcasecmp( $actual_table, $table ) ) {
					$found = $actual_columns;
					break;
				}
			}
			$problems = array_merge( $problems, self::column_problems( $table, $columns, $found ) );
			if ( $found ) {
				$present[] = $table;
			}
		}

		return array_merge( $problems, self::missing_index_problems( $present ) );
	}

	/**
	 * Indexes declared in the CREATE TABLE statements that the database does not have.
	 *
	 * A lost index (restore from a dump without keys, a manual or aborted ALTER)
	 * does not break anything at once, it only makes the list, the account page
	 * or a daily job slow. The daily check reports it like a missing column, so
	 * maybe_upgrade() lets dbDelta add it again and diagnostics shows it meanwhile.
	 *
	 * @param string[] $tables Existing tables to check (missing tables are reported by the column check).
	 * @return string[] Problems such as "wp_wplc_licenses: index payment_token is missing".
	 */
	private static function missing_index_problems( array $tables ): array {
		global $wpdb;

		$declared = array();
		foreach ( self::table_statements() as $statement ) {
			$table = self::expected_columns( $statement )['table'];
			if ( in_array( $table, $tables, true ) ) {
				$declared[ $table ] = self::expected_indexes( $statement );
			}
		}
		$declared = array_filter( $declared );
		if ( ! $declared ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $declared ), '%s' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- one %s per table name.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT TABLE_NAME AS tbl, INDEX_NAME AS idx FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name IN ({$placeholders})",
				array_keys( $declared )
			),
			ARRAY_A
		);
		// phpcs:enable
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			return array( 'The indexes could not be read: ' . (string) $wpdb->last_error );
		}

		$actual = array();
		foreach ( $rows as $row ) {
			$actual[ strtolower( (string) $row['tbl'] ) ][] = (string) $row['idx'];
		}
		$problems = array();
		foreach ( $declared as $table => $indexes ) {
			$found    = isset( $actual[ strtolower( $table ) ] ) ? $actual[ strtolower( $table ) ] : array();
			$problems = array_merge( $problems, self::index_problems( $table, $indexes, $found ) );
		}

		return $problems;
	}

	/**
	 * Names of the indexes one CREATE TABLE statement declares (KEY and UNIQUE KEY lines).
	 *
	 * Pure helper. The primary key is covered by the column check.
	 *
	 * @param string $statement CREATE TABLE statement in dbDelta layout.
	 * @return string[]
	 */
	public static function expected_indexes( string $statement ): array {
		$indexes = array();
		$lines   = preg_split( '/\R/', $statement );
		foreach ( is_array( $lines ) ? $lines : array() as $line ) {
			if ( preg_match( '/^\s*(?:UNIQUE\s+)?(?:KEY|INDEX)\s+`?([A-Za-z0-9_]+)`?\s*\(/i', $line, $match ) ) {
				$indexes[] = $match[1];
			}
		}

		return $indexes;
	}

	/**
	 * Compare declared index names with the names the database reports.
	 *
	 * Pure helper; names are compared case-insensitively.
	 *
	 * @param string   $table    Table name for the messages.
	 * @param string[] $expected Declared index names.
	 * @param string[] $actual   Existing index names.
	 * @return string[]
	 */
	public static function index_problems( string $table, array $expected, array $actual ): array {
		$actual   = array_map( 'strtolower', array_map( 'strval', $actual ) );
		$problems = array();
		foreach ( $expected as $name ) {
			if ( ! in_array( strtolower( $name ), $actual, true ) ) {
				$problems[] = $table . ': index ' . $name . ' is missing';
			}
		}

		return $problems;
	}

	/**
	 * Extract the table name and the column types from one CREATE TABLE statement.
	 *
	 * Pure helper. The CREATE TABLE statements are the single source for the
	 * expected structure, so a new column is verified without a second list.
	 *
	 * @param string $statement CREATE TABLE statement in dbDelta layout (one column per line).
	 * @return array{table:string,columns:array<string,array{type:string,length:int}>}
	 */
	public static function expected_columns( string $statement ): array {
		$result = array(
			'table'   => '',
			'columns' => array(),
		);
		if ( ! preg_match( '/CREATE TABLE\s+`?([^\s`(]+)`?\s*\(/i', $statement, $match ) ) {
			return $result;
		}
		$result['table'] = $match[1];

		$lines = preg_split( '/\R/', $statement );
		foreach ( is_array( $lines ) ? $lines : array() as $line ) {
			$line = trim( $line );
			if ( '' === $line || preg_match( '/^(?:(?:CREATE\s+TABLE|PRIMARY\s+KEY|UNIQUE\s+KEY|UNIQUE\s+INDEX|KEY|INDEX|FULLTEXT|SPATIAL|CONSTRAINT|FOREIGN\s+KEY)\b|\))/i', $line ) ) {
				continue;
			}
			if ( preg_match( '/^`?([A-Za-z0-9_]+)`?\s+([A-Za-z]+)(?:\s*\(\s*(\d+)[^)]*\))?/', $line, $column ) ) {
				$result['columns'][ strtolower( $column[1] ) ] = array(
					'type'   => self::normalize_type( $column[2] ),
					'length' => isset( $column[3] ) ? (int) $column[3] : 0,
				);
			}
		}

		return $result;
	}

	/**
	 * Compare the expected columns of one table with what the database reports.
	 *
	 * Pure helper. A character column that is wider than expected is accepted
	 * (widened by hand), a narrower one is a problem: WordPress refuses to
	 * write a value that is longer than the column.
	 *
	 * @param string                                      $table    Table name for the messages.
	 * @param array<string,array{type:string,length:int}> $expected Expected columns.
	 * @param array<string,array{type:string,length:int}> $actual   Existing columns; empty when the table is missing.
	 * @return string[]
	 */
	public static function column_problems( string $table, array $expected, array $actual ): array {
		if ( ! $actual ) {
			return array( $table . ': table is missing' );
		}

		$problems = array();
		foreach ( $expected as $name => $definition ) {
			if ( ! isset( $actual[ $name ] ) ) {
				$problems[] = $table . '.' . $name . ': column is missing';
				continue;
			}
			$actual_type = self::normalize_type( (string) $actual[ $name ]['type'] );
			$is_text     = in_array( $definition['type'], array( 'char', 'varchar' ), true );
			if ( $actual_type !== $definition['type'] || ( $is_text && (int) $actual[ $name ]['length'] < (int) $definition['length'] ) ) {
				$problems[] = sprintf(
					'%s.%s: %s, expected %s',
					$table,
					$name,
					$actual_type . ( $is_text || in_array( $actual_type, array( 'char', 'varchar' ), true ) ? '(' . (int) $actual[ $name ]['length'] . ')' : '' ),
					$definition['type'] . ( $is_text ? '(' . (int) $definition['length'] . ')' : '' )
				);
			}
		}

		return $problems;
	}

	/**
	 * Whether the structure of an up-to-date schema has to be checked again.
	 *
	 * Pure helper.
	 *
	 * @param string $marker   Stored "<version>|<unix time>" marker.
	 * @param string $version  Current schema version.
	 * @param int    $now      Unix timestamp.
	 * @param int    $interval Seconds between two checks.
	 * @return bool
	 */
	public static function verification_due( string $marker, string $version, int $now, int $interval ): bool {
		$parts = explode( '|', $marker );
		if ( 2 !== count( $parts ) || $parts[0] !== $version || ! ctype_digit( $parts[1] ) ) {
			return true;
		}
		$checked = (int) $parts[1];

		return $checked > $now || $now - $checked >= $interval;
	}

	/**
	 * Show a failed migration or a plugin downgrade to administrators.
	 *
	 * @return void
	 */
	public static function render_admin_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( self::is_downgraded() ) {
			echo '<div class="notice notice-error"><p>';
			echo esc_html(
				sprintf(
					/* translators: 1: installed database schema version, 2: schema version this plugin version supports. */
					__( 'WP License Captain: the database was upgraded by a newer plugin version (schema %1$s, this version supports %2$s). Renewal charges and customer emails are paused. Install the newer plugin version again.', 'wp-license' ),
					(string) get_option( self::VERSION_OPTION, '0' ),
					self::VERSION
				)
			);
			echo '</p></div>';
			return;
		}

		if ( version_compare( (string) get_option( self::VERSION_OPTION, '0' ), self::VERSION, '>=' ) ) {
			// Autoloaded check first: the error option is only read while something is wrong.
			$marker = (string) get_option( self::VERIFIED_OPTION, '' );
			if ( 0 === strpos( $marker, self::VERSION . '|' ) ) {
				return;
			}
		}
		$error = get_option( self::ERROR_OPTION, null );
		if ( ! is_array( $error ) || empty( $error['message'] ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>';
		echo esc_html(
			sprintf(
				/* translators: %s: technical error details. */
				__( 'WP License Captain could not update its database tables. The plugin keeps working with the previous structure and retries automatically. Details: %s', 'wp-license' ),
				(string) $error['message']
			)
		);
		echo '</p></div>';
	}

	/**
	 * Create or update the plugin tables, then run pending data migrations.
	 *
	 * Concurrent requests are serialized with a named MySQL lock. A request that
	 * cannot get the lock in time returns false and leaves the work to the
	 * request that holds it.
	 *
	 * @param int $lock_timeout Seconds to wait for a concurrent migration. maybe_upgrade() passes 0.
	 * @return bool True when every table has the expected columns and all steps succeeded.
	 */
	public static function migrate( int $lock_timeout = self::LOCK_TIMEOUT ): bool {
		global $wpdb;

		$lock_name = self::lock_name();
		$lock      = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, max( 0, $lock_timeout ) ) );
		if ( null !== $lock && 1 !== (int) $lock ) {
			// Another request is migrating right now.
			return false;
		}
		// A NULL result means named locks are unavailable; migrate unlocked rather than never.
		$locked = null !== $lock;

		try {
			return self::run_migration();
		} finally {
			if ( $locked ) {
				$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
			}
		}
	}

	/**
	 * Return the step versions that must run to get from one version to another.
	 *
	 * Pure helper: selects every version greater than the installed and not
	 * greater than the target version, in ascending version order.
	 *
	 * @param string   $installed Installed schema version.
	 * @param string   $target Target schema version.
	 * @param array<int|string> $step_versions Versions that have a registered step.
	 * @return string[]
	 */
	public static function pending_step_versions( string $installed, string $target, array $step_versions ): array {
		$pending = array();
		foreach ( $step_versions as $version ) {
			$version = (string) $version;
			if ( version_compare( $version, $installed, '>' ) && version_compare( $version, $target, '<=' ) ) {
				$pending[ $version ] = $version;
			}
		}
		$pending = array_values( $pending );
		usort( $pending, 'version_compare' );

		return $pending;
	}

	/**
	 * Run the given steps in order and report whether all of them succeeded.
	 *
	 * Pure helper: stops at the first step that fails or throws. The recorder is
	 * called with the version after every successful step so that a later
	 * failure only repeats the failed step.
	 *
	 * @param string[]               $versions Ordered step versions.
	 * @param array<string,callable> $steps Step callables keyed by version.
	 * @param callable               $record Called with each completed version.
	 * @return bool True when every step succeeded.
	 */
	public static function run_steps( array $versions, array $steps, callable $record ): bool {
		foreach ( $versions as $version ) {
			$version = (string) $version;
			if ( ! isset( $steps[ $version ] ) || ! is_callable( $steps[ $version ] ) ) {
				return false;
			}
			try {
				$ok = true === call_user_func( $steps[ $version ] );
			} catch ( \Throwable $error ) {
				$ok = false;
			}
			if ( ! $ok ) {
				return false;
			}
			call_user_func( $record, $version );
		}

		return true;
	}

	/**
	 * Add a deleted site's tables to the tables WordPress drops with the site.
	 *
	 * Registered from wp-license.php as soon as the plugin file is loaded (P-32), so
	 * it does not depend on WooCommerce or on plugins_loaded. Besides the tables
	 * of this schema it adds every other `{site prefix}wplc_*` table of that site
	 * (left by an older or newer plugin version). The main site is never touched:
	 * WordPress does not delete it, and its prefix is the prefix of the whole network.
	 *
	 * @param mixed $tables Table names collected by WordPress.
	 * @param mixed $site_id ID of the site that is being deleted.
	 * @return mixed
	 */
	public static function filter_wpmu_drop_tables( $tables, $site_id = 0 ) {
		global $wpdb;

		$site_id = (int) $site_id;
		if ( ! is_array( $tables ) || $site_id <= 0 || ! is_object( $wpdb ) ) {
			return $tables;
		}
		if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'is_main_site' ) && is_main_site( $site_id ) ) {
			// WordPress never deletes the main site; its prefix is the prefix of every network table.
			return $tables;
		}
		$prefix = (string) $wpdb->get_blog_prefix( $site_id );
		if ( '' === $prefix ) {
			return $tables;
		}

		$own   = array_values( self::table_names_for_prefix( $prefix ) );
		$found = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix . 'wplc_' ) . '%' ) );
		foreach ( is_array( $found ) ? $found : array() as $table ) {
			if ( self::is_site_table( (string) $table, $prefix ) ) {
				$own[] = (string) $table;
			}
		}

		return array_values( array_unique( array_merge( $tables, $own ) ) );
	}

	/**
	 * Whether a table name is a plugin table of the site with the given prefix.
	 *
	 * Pure helper. "wp_2_wplc_licenses" belongs to "wp_2_", "wp_22_wplc_licenses" does not.
	 *
	 * @param string $table  Table name.
	 * @param string $prefix Site prefix, e.g. "wp_2_".
	 * @return bool
	 */
	public static function is_site_table( string $table, string $prefix ): bool {
		return '' !== $prefix && 1 === preg_match( '/^' . preg_quote( $prefix . 'wplc_', '/' ) . '[a-z0-9_]+$/i', $table );
	}

	/**
	 * Plugin tables of network sites that no longer exist, grouped by site ID.
	 *
	 * `wpmu_drop_tables` only runs in a request in which the plugin is loaded. A site
	 * that had the plugin active on its own and was deleted from the network admin
	 * (main site without the plugin) keeps its tables. `wp wplc schema orphans` lists
	 * them; nothing is dropped automatically, because another installation sharing
	 * the database could use a similar prefix.
	 *
	 * @return array<int,string[]> Site ID => table names; empty on a single site.
	 */
	public static function orphaned_site_tables(): array {
		global $wpdb;

		if ( ! function_exists( 'is_multisite' ) || ! is_multisite() || ! function_exists( 'get_sites' ) ) {
			return array();
		}
		$base   = (string) $wpdb->base_prefix;
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $base ) . '%' . $wpdb->esc_like( '_wplc_' ) . '%' ) );
		$ids    = array_map(
			'intval',
			(array) get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			)
		);

		return self::orphans_from( is_array( $tables ) ? array_map( 'strval', $tables ) : array(), $base, $ids );
	}

	/**
	 * Group plugin tables of sites that are not in the list of existing sites.
	 *
	 * Pure helper.
	 *
	 * @param string[] $tables   Table names.
	 * @param string   $base     Base prefix of the network, e.g. "wp_".
	 * @param int[]    $site_ids IDs of the existing sites.
	 * @return array<int,string[]>
	 */
	public static function orphans_from( array $tables, string $base, array $site_ids ): array {
		$orphans = array();
		foreach ( $tables as $table ) {
			if ( ! preg_match( '/^' . preg_quote( $base, '/' ) . '(\d+)_wplc_[a-z0-9_]+$/i', $table, $match ) ) {
				continue;
			}
			$site_id = (int) $match[1];
			if ( $site_id > 1 && ! in_array( $site_id, $site_ids, true ) ) {
				$orphans[ $site_id ][] = $table;
			}
		}
		ksort( $orphans );

		return $orphans;
	}

	/**
	 * Run one migration step by version. Public only so run_steps() can call it.
	 *
	 * @param string $version Step version.
	 * @return bool
	 */
	public static function run_registered_step( string $version ): bool {
		if ( ! isset( self::$steps[ $version ] ) ) {
			return false;
		}

		return true === call_user_func( self::$steps[ $version ] );
	}

	/**
	 * Run dbDelta, the structure check and the pending data steps. Caller holds the lock.
	 *
	 * The version is only stored when dbDelta reported no database error, every
	 * expected column exists with the expected type and all data steps succeeded.
	 *
	 * @return bool
	 */
	private static function run_migration(): bool {
		global $wpdb, $EZSQL_ERROR;

		if ( version_compare( self::stored_version(), self::VERSION, '>' ) ) {
			// Downgrade: dbDelta with older definitions could narrow columns of the newer schema.
			return true;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// wpdb appends every failed query to $EZSQL_ERROR, also while error output is suppressed.
		$error_offset = is_array( $EZSQL_ERROR ) ? count( $EZSQL_ERROR ) : 0;
		$suppressed   = $wpdb->suppress_errors( true );
		foreach ( self::table_statements() as $statement ) {
			\dbDelta( $statement );
		}
		$wpdb->suppress_errors( $suppressed );

		$problems = array_merge( self::verify(), self::database_errors_since( $error_offset ) );
		if ( $problems ) {
			self::record_failure( $problems );
			return false;
		}

		// Re-read from the database: another request may have migrated while this one waited for the lock.
		$installed = self::stored_version();
		$pending   = self::pending_step_versions( $installed, self::VERSION, array_keys( self::$steps ) );
		$callables = array();
		foreach ( $pending as $version ) {
			$callables[ $version ] = static function () use ( $version ) {
				return self::run_registered_step( $version );
			};
		}
		if ( ! self::run_steps( $pending, $callables, array( self::class, 'store_version' ) ) ) {
			// The last successful step is recorded; the failed one is retried later.
			self::record_failure( array_merge( array( 'A data migration step failed (installed version ' . self::stored_version() . ').' ), self::database_errors_since( $error_offset ) ) );
			return false;
		}

		if ( version_compare( $installed, self::VERSION, '<' ) || ! self::version_is_autoloaded() ) {
			self::store_version( self::VERSION );
		}
		self::mark_verified();
		if ( null !== get_option( self::ERROR_OPTION, null ) ) {
			delete_option( self::ERROR_OPTION );
		}
		if ( null !== get_option( self::DIAGNOSTICS_ERROR_OPTION, null ) ) {
			delete_option( self::DIAGNOSTICS_ERROR_OPTION );
		}

		return true;
	}

	/**
	 * Create or complete one plugin table from its CREATE TABLE statement.
	 *
	 * For code that finds its table missing outside a migration request (the
	 * reminder log before a reminder is sent). The statement is the one of the
	 * schema, so there is no second definition of the table.
	 *
	 * @param string $key Table key as in table_names(), e.g. "reminder_log".
	 * @return bool False for an unknown key or in the downgrade state; true after dbDelta ran.
	 */
	public static function create_table( string $key ): bool {
		global $wpdb;

		$tables = self::table_names();
		if ( ! isset( $tables[ $key ] ) || self::is_downgraded() ) {
			return false;
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( self::table_statements() as $statement ) {
			if ( self::expected_columns( $statement )['table'] === $tables[ $key ] ) {
				$suppressed = $wpdb->suppress_errors( true );
				\dbDelta( $statement );
				$wpdb->suppress_errors( $suppressed );

				return true;
			}
		}

		return false;
	}

	/**
	 * CREATE TABLE statements of every plugin table (dbDelta layout).
	 *
	 * @return string[]
	 */
	private static function table_statements(): array {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$tables          = self::table_names();

		$sql = array(
			"CREATE TABLE {$tables['products']} (
				product_key varchar(100) NOT NULL,
				display_name varchar(190) NOT NULL,
				active tinyint(1) NOT NULL DEFAULT 1,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (product_key)
			) {$charset_collate};",
			"CREATE TABLE {$tables['plans']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				product_key varchar(100) NOT NULL,
				plan_key varchar(100) NOT NULL,
				woocommerce_product_id bigint(20) unsigned NOT NULL DEFAULT 0,
				woocommerce_variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
				billing_interval varchar(10) NOT NULL DEFAULT 'year',
				price_minor bigint(20) unsigned NOT NULL DEFAULT 0,
				price_decimals tinyint(3) unsigned NOT NULL DEFAULT 2,
				currency char(3) NOT NULL DEFAULT 'EUR',
				live_site_limit smallint(5) unsigned NOT NULL DEFAULT 1,
				support_level varchar(20) NOT NULL DEFAULT 'standard',
				entitlements_json longtext NOT NULL,
				active tinyint(1) NOT NULL DEFAULT 1,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY plan_mapping (woocommerce_product_id,woocommerce_variation_id),
				KEY product_plan (product_key,plan_key),
				KEY active (active)
			) {$charset_collate};",
			"CREATE TABLE {$tables['licenses']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				license_uuid char(36) NOT NULL,
				license_key_hash char(64) NOT NULL,
				license_key_secret_id varchar(32) NOT NULL DEFAULT 'legacy',
				key_prefix varchar(32) NOT NULL,
				customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
				product_key varchar(100) NOT NULL,
				plan_key varchar(100) NOT NULL,
				parent_license_id bigint(20) unsigned DEFAULT NULL,
				status varchar(20) NOT NULL DEFAULT 'pending',
				status_reason varchar(255) NOT NULL DEFAULT '',
				renewal_enabled tinyint(1) NOT NULL DEFAULT 0,
				payment_gateway_id varchar(100) NOT NULL DEFAULT '',
				payment_token_id bigint(20) unsigned NOT NULL DEFAULT 0,
				gateway_customer_ref varchar(100) DEFAULT NULL,
				gateway_method_ref varchar(100) DEFAULT NULL,
				gateway_mode varchar(10) DEFAULT NULL,
				renewal_amount_minor bigint(20) unsigned NOT NULL DEFAULT 0,
				renewal_currency_decimals tinyint(3) unsigned NOT NULL DEFAULT 2,
				renewal_currency char(3) NOT NULL DEFAULT 'EUR',
				renewal_amount_includes_tax tinyint(1) DEFAULT NULL,
				renewal_product_id bigint(20) unsigned NOT NULL DEFAULT 0,
				price_change_amount_minor bigint(20) unsigned DEFAULT NULL,
				price_change_effective_at datetime DEFAULT NULL,
				price_change_announced_at datetime DEFAULT NULL,
				price_change_notified_at datetime DEFAULT NULL,
				price_change_notice_days smallint(5) unsigned DEFAULT NULL,
				pending_plan_key varchar(100) NOT NULL DEFAULT '',
				pending_product_id bigint(20) unsigned NOT NULL DEFAULT 0,
				plan_order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				cancel_at_period_end tinyint(1) NOT NULL DEFAULT 0,
				revoked_at datetime DEFAULT NULL,
				billing_interval varchar(10) NOT NULL DEFAULT 'year',
				starts_at datetime NOT NULL,
				paid_until datetime DEFAULT NULL,
				next_payment_at datetime DEFAULT NULL,
				source_order_id bigint(20) unsigned DEFAULT NULL,
				source_order_item_id bigint(20) unsigned DEFAULT NULL,
				unit_index smallint(5) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY license_uuid (license_uuid),
				UNIQUE KEY license_key_hash (license_key_hash),
				UNIQUE KEY order_unit (source_order_item_id,unit_index),
				KEY customer_product (customer_id,product_key),
				KEY customer_created (customer_id,created_at),
				KEY status_paid_until (status,paid_until),
				KEY status_created (status,created_at),
				KEY product_created (product_key,created_at),
				KEY product_plan (product_key,plan_key),
				KEY created_at (created_at),
				KEY paid_until (paid_until),
				KEY key_prefix (key_prefix),
				KEY next_payment_at (next_payment_at),
				KEY source_order (source_order_id),
				KEY payment_token (payment_token_id)
			) {$charset_collate};",
			"CREATE TABLE {$tables['activations']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				license_id bigint(20) unsigned NOT NULL,
				installation_uuid char(36) NOT NULL,
				installation_secret_hash char(64) NOT NULL DEFAULT '',
				installation_secret_encrypted longtext NOT NULL,
				site_url varchar(255) NOT NULL,
				environment varchar(20) NOT NULL DEFAULT 'production',
				staging_parent_id bigint(20) unsigned DEFAULT NULL,
				product_version varchar(30) NOT NULL DEFAULT '',
				wp_version varchar(30) NOT NULL DEFAULT '',
				php_version varchar(30) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'active',
				activated_at datetime NOT NULL,
				last_seen_at datetime DEFAULT NULL,
				deactivated_at datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY installation_uuid (installation_uuid),
				KEY license_status (license_id,status),
				KEY staging_parent (staging_parent_id),
				KEY status_deactivated (status,deactivated_at)
			) {$charset_collate};",
			"CREATE TABLE {$tables['entitlements']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				license_id bigint(20) unsigned NOT NULL,
				entitlement_key varchar(120) NOT NULL,
				value_json longtext NOT NULL,
				source_order_item_id bigint(20) unsigned DEFAULT NULL,
				starts_at datetime NOT NULL,
				ends_at datetime DEFAULT NULL,
				status varchar(20) NOT NULL DEFAULT 'active',
				PRIMARY KEY  (id),
				UNIQUE KEY license_entitlement (license_id,entitlement_key),
				KEY entitlement_status (entitlement_key,status)
			) {$charset_collate};",
			"CREATE TABLE {$tables['renewals']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				license_id bigint(20) unsigned NOT NULL,
				period_start datetime NOT NULL,
				period_end datetime NOT NULL,
				renewal_order_id bigint(20) unsigned DEFAULT NULL,
				gateway_attempt_id varchar(190) NOT NULL DEFAULT '',
				idempotency_key char(64) NOT NULL,
				status varchar(30) NOT NULL DEFAULT 'scheduled',
				retry_at datetime DEFAULT NULL,
				grace_ends_at datetime DEFAULT NULL,
				attempt_count smallint(5) unsigned NOT NULL DEFAULT 0,
				last_error varchar(100) NOT NULL DEFAULT '',
				last_attempt_at datetime DEFAULT NULL,
				review_reason varchar(255) NOT NULL DEFAULT '',
				review_at datetime DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY idempotency_key (idempotency_key),
				KEY due_renewals (status,retry_at),
				KEY license_period (license_id,period_start)
			) {$charset_collate};",
			"CREATE TABLE {$tables['download_tokens']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				license_id bigint(20) unsigned NOT NULL,
				activation_id bigint(20) unsigned NOT NULL DEFAULT 0,
				token_hash char(64) NOT NULL,
				package_url text NOT NULL,
				expires_at datetime NOT NULL,
				consumed_at datetime DEFAULT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY token_hash (token_hash),
				KEY expiry_consumed (expires_at,consumed_at),
				KEY license_created (license_id,created_at)
			) {$charset_collate};",
			"CREATE TABLE {$tables['event_inbox']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				source varchar(40) NOT NULL,
				source_event_id varchar(190) NOT NULL,
				event_type varchar(100) NOT NULL,
				payload_hash char(64) NOT NULL,
				payload_json longtext NOT NULL,
				received_at datetime NOT NULL,
				processed_at datetime DEFAULT NULL,
				outcome varchar(40) NOT NULL DEFAULT 'received',
				PRIMARY KEY  (id),
				UNIQUE KEY source_event (source,source_event_id),
				KEY inbox_pending (processed_at,received_at),
				KEY outcome_processed (outcome,processed_at),
				KEY source_received (source,received_at)
			) {$charset_collate};",
			"CREATE TABLE {$tables['request_nonces']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				activation_id bigint(20) unsigned NOT NULL,
				nonce_hash char(64) NOT NULL,
				expires_at datetime NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY activation_nonce (activation_id,nonce_hash),
				KEY nonce_expiry (expires_at)
			) {$charset_collate};",
			"CREATE TABLE {$tables['request_limits']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				limiter_hash char(64) NOT NULL,
				bucket_start datetime NOT NULL,
				hits int(10) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY limiter_bucket (limiter_hash,bucket_start),
				KEY limit_bucket (bucket_start)
			) {$charset_collate};",
			"CREATE TABLE {$tables['audit_log']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				action varchar(100) NOT NULL,
				object_type varchar(40) NOT NULL,
				object_id bigint(20) unsigned NOT NULL DEFAULT 0,
				reason text NOT NULL,
				before_json longtext DEFAULT NULL,
				after_json longtext DEFAULT NULL,
				created_at datetime NOT NULL,
				source varchar(20) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY object_history (object_type,object_id,created_at),
				KEY actor_history (actor_user_id,created_at),
				KEY source_entries (source)
			) {$charset_collate};",
			"CREATE TABLE {$tables['releases']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				product_key varchar(100) NOT NULL,
				version varchar(40) NOT NULL,
				channel varchar(20) NOT NULL DEFAULT 'stable',
				package_url text NOT NULL,
				storage varchar(10) NOT NULL DEFAULT 'url',
				sha256 char(64) NOT NULL DEFAULT '',
				requires_php varchar(20) NOT NULL DEFAULT '',
				requires_wp varchar(20) NOT NULL DEFAULT '',
				tested_wp varchar(20) NOT NULL DEFAULT '',
				requires_core varchar(20) NOT NULL DEFAULT '',
				plugin_file varchar(190) NOT NULL DEFAULT '',
				release_notes longtext NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'draft',
				published_at datetime DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY product_version_channel (product_key,version,channel),
				KEY product_status (product_key,status)
			) {$charset_collate};",
			"CREATE TABLE {$tables['package_streams']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				token_hash char(64) NOT NULL,
				release_id bigint(20) unsigned NOT NULL,
				license_id bigint(20) unsigned NOT NULL,
				source varchar(20) NOT NULL DEFAULT 'update',
				expires_at datetime NOT NULL,
				consumed_at datetime DEFAULT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY token_hash (token_hash),
				KEY license_source_created (license_id,source,created_at),
				KEY stream_expiry (expires_at)
			) {$charset_collate};",
			"CREATE TABLE {$tables['consents']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				license_id bigint(20) unsigned NOT NULL,
				order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
				consent_at datetime NOT NULL,
				source varchar(20) NOT NULL DEFAULT '',
				locale varchar(20) NOT NULL DEFAULT '',
				text_hash char(64) NOT NULL DEFAULT '',
				consent_text text NOT NULL,
				amount_minor bigint(20) unsigned NOT NULL DEFAULT 0,
				currency_decimals tinyint(3) unsigned NOT NULL DEFAULT 2,
				currency char(3) NOT NULL DEFAULT 'EUR',
				billing_interval varchar(10) NOT NULL DEFAULT 'year',
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY license_consent (license_id,consent_at),
				KEY customer_id (customer_id)
			) {$charset_collate};",
			"CREATE TABLE {$tables['reminder_log']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				license_id bigint(20) unsigned NOT NULL,
				reminder_type varchar(40) NOT NULL,
				period_end datetime NOT NULL,
				sent_at datetime NOT NULL,
				status varchar(10) NOT NULL DEFAULT 'sent',
				PRIMARY KEY  (id),
				UNIQUE KEY license_reminder (license_id,reminder_type,period_end),
				KEY sent_at (sent_at)
			) {$charset_collate};",
		);

		return $sql;
	}

	/**
	 * Return fully prefixed table names for the current site.
	 *
	 * @return array<string,string>
	 */
	public static function table_names(): array {
		global $wpdb;

		return self::table_names_for_prefix( (string) $wpdb->prefix );
	}

	/**
	 * Return fully prefixed table names for a given site prefix.
	 *
	 * @param string $site_prefix Database prefix of the site, e.g. "wp_" or "wp_2_".
	 * @return array<string,string>
	 */
	public static function table_names_for_prefix( string $site_prefix ): array {
		$prefix = $site_prefix . 'wplc_';

		return array(
			'products'     => $prefix . 'products',
			'plans'        => $prefix . 'plans',
			'licenses'     => $prefix . 'licenses',
			'activations'  => $prefix . 'activations',
			'entitlements' => $prefix . 'entitlements',
			'renewals'     => $prefix . 'renewals',
			'download_tokens' => $prefix . 'download_tokens',
			'event_inbox'  => $prefix . 'event_inbox',
			'request_nonces' => $prefix . 'request_nonces',
			'request_limits' => $prefix . 'request_limits',
			'audit_log'    => $prefix . 'audit_log',
			'releases'     => $prefix . 'releases',
			'package_streams' => $prefix . 'package_streams',
			'consents'     => $prefix . 'consents',
			'reminder_log' => $prefix . 'reminder_log',
		);
	}

	/**
	 * Persist the installed schema version as an autoloaded option.
	 *
	 * @param string $version Completed schema version.
	 * @return void
	 */
	public static function store_version( string $version ): void {
		update_option( self::VERSION_OPTION, $version, true );
		// update_option() only changes the autoload flag together with the value.
		if ( ! self::version_is_autoloaded() && function_exists( 'wp_set_option_autoload' ) ) {
			wp_set_option_autoload( self::VERSION_OPTION, true );
		}
	}

	/**
	 * Schema 10: the version option becomes autoloaded (no extra query per request).
	 *
	 * Installs up to schema 9 stored the option with autoload disabled. The row
	 * is flagged here; store_version() keeps the flag for all later writes.
	 *
	 * @return bool
	 */
	private static function step_10_autoload_schema_version(): bool { // @phpstan-ignore method.unused (called through self::$steps)
		if ( false === get_option( self::VERSION_OPTION, false ) || self::version_is_autoloaded() ) {
			// Fresh install (store_version() creates the row autoloaded) or already migrated.
			return true;
		}
		if ( function_exists( 'wp_set_option_autoload' ) ) {
			wp_set_option_autoload( self::VERSION_OPTION, true );
		}

		// Not fatal when the flag could not be changed: the value is rewritten with autoload right after.
		return true;
	}

	/**
	 * Schema 12 (line A): the reminder log belongs to the schema.
	 *
	 * dbDelta has already created the table or found the one ReminderLog
	 * installed earlier (rows are kept; the status column of layout 2 is
	 * added with the default "sent"). The layout option ReminderLog checks
	 * before it trusts the table is aligned, the same as step 18 of line B.
	 *
	 * @return bool
	 */
	private static function step_12_adopt_reminder_log(): bool { // @phpstan-ignore method.unused (called through self::$steps)
		return self::step_18_adopt_reminder_log();
	}

	/**
	 * Schema 13: confirm that dbDelta added licenses.renewal_product_id.
	 *
	 * dbDelta reports no errors, so a column it could not add would only show
	 * up as failing license inserts. Existing licenses keep the value 0: their
	 * renewal price is resolved through the source order item at renewal time
	 * (see RenewalOrder::price()). The stored renewal amounts are not changed.
	 *
	 * @return bool
	 */
	private static function step_13_renewal_product_column(): bool { // @phpstan-ignore method.unused (called through self::$steps)
		global $wpdb;

		$tables = self::table_names();
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = %s AND column_name = %s',
				$tables['licenses'],
				'renewal_product_id'
			)
		);

		return 1 === (int) $exists;
	}

	/**
	 * Schema 14: reason and start of open review cases (T-154, T-137).
	 *
	 * dbDelta adds the columns. Existing `review_required` rows get the reason
	 * and time of their latest `renewal_review_required` audit entry (else the
	 * row's last change). Paid manual renewals that were rejected before this
	 * version (audit `manual_renewal_rejected`; until now only an order note)
	 * become review cases as well, unless their period was paid, refunded or
	 * is already in review. Idempotent: only rows without `review_at` change.
	 *
	 * @return bool
	 */
	private static function step_14_renewal_review_columns(): bool { // @phpstan-ignore method.unused (called through self::$steps)
		global $wpdb;

		$tables = self::table_names();
		$count  = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = %s AND column_name IN (%s, %s)',
				$tables['renewals'],
				'review_reason',
				'review_at'
			)
		);
		if ( 2 !== $count ) {
			return false;
		}

		$renewals = $tables['renewals'];
		$audit    = $tables['audit_log'];
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from table_names(), values prepared.
		$open = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$renewals} r LEFT JOIN {$audit} a ON a.id = ( SELECT MAX(a2.id) FROM {$audit} a2 WHERE a2.object_type = %s AND a2.object_id = r.id AND a2.action = %s )"
				. " SET r.review_reason = COALESCE( LEFT( a.reason, 255 ), '' ), r.review_at = COALESCE( a.created_at, r.updated_at ) WHERE r.status = %s AND r.review_at IS NULL",
				'renewal',
				'renewal_review_required',
				'review_required'
			)
		);
		$rejected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$renewals} r JOIN {$audit} a ON a.id = ( SELECT MAX(a2.id) FROM {$audit} a2 WHERE a2.object_type = %s AND a2.object_id = r.id AND a2.action = %s )"
				. ' SET r.review_reason = LEFT( a.reason, 255 ), r.review_at = a.created_at, r.retry_at = NULL, r.status = %s WHERE r.status NOT IN (%s, %s, %s, %s) AND r.review_at IS NULL',
				'renewal',
				'manual_renewal_rejected',
				'review_required',
				'paid',
				'refunded',
				'review_required',
				'closed'
			)
		);
		// phpcs:enable

		return false !== $open && false !== $rejected;
	}

	/**
	 * Indexes added by schema 15 (T-89), table key => index names.
	 *
	 * @var array<string,string[]>
	 */
	private const LIST_INDEXES = array(
		'licenses'    => array( 'customer_created', 'status_created', 'product_created', 'product_plan', 'created_at', 'paid_until', 'key_prefix' ),
		'event_inbox' => array( 'outcome_processed' ),
	);

	/**
	 * Schema 15: indexes for the license list, the account page and diagnostics (T-89).
	 *
	 * dbDelta adds the indexes (see docs/PERFORMANCE.md for the queries they
	 * serve); no data changes. dbDelta reports no errors, so the step checks
	 * that every index exists; a missing one is retried on the next request.
	 *
	 * @return bool
	 */
	private static function step_15_list_indexes(): bool { // @phpstan-ignore method.unused (called through self::$steps)
		return self::indexes_exist( self::LIST_INDEXES );
	}

	/**
	 * Schema 16: download_tokens.activation_id (T-108).
	 *
	 * dbDelta adds the column; existing grants keep 0 (not bound, they expire
	 * within minutes). The step checks that the column exists, because the
	 * update check cannot issue grants without it.
	 *
	 * @return bool
	 */
	private static function step_16_grant_activation_column(): bool { // @phpstan-ignore method.unused (called through self::$steps)
		global $wpdb;

		$tables = self::table_names();
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = %s AND column_name = %s',
				$tables['download_tokens'],
				'activation_id'
			)
		);

		return 1 === (int) $exists;
	}

	/**
	 * Schema 17: confirm that dbDelta added the plan change columns (T-110).
	 *
	 * No data changes: existing licenses have no pending plan change (empty key,
	 * product 0) and no upgrade order (0). dbDelta reports no errors, so a
	 * missing column is detected here and the step is retried.
	 *
	 * @return bool
	 */
	private static function step_17_plan_change_columns(): bool { // @phpstan-ignore method.unused (called through self::$steps)
		global $wpdb;

		$tables = self::table_names();
		$count  = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = %s AND column_name IN (%s, %s, %s)',
				$tables['licenses'],
				'pending_plan_key',
				'pending_product_id',
				'plan_order_id'
			)
		);

		return 3 === $count;
	}

	/**
	 * Schema 18: the reminder log belongs to the schema.
	 *
	 * The table used to be created by the notification package, guarded by its
	 * own version option. When the tables were dropped by hand and the option
	 * survived, the table was never created again and no reminder was sent.
	 * dbDelta has created the table by now; the old option is aligned so the
	 * former installer stays quiet.
	 *
	 * Public only so the step table can call it.
	 *
	 * @return bool
	 */
	public static function step_18_adopt_reminder_log(): bool {
		if ( '2' !== (string) get_option( 'wplc_reminder_log_version', '' ) ) {
			update_option( 'wplc_reminder_log_version', '2', false );
		}

		return true;
	}

	/**
	 * Schema 19: one heartbeat store.
	 *
	 * Three packages kept the time of the last job run in three options. The
	 * time of the last completed daily run moves into `wplc_last_run` (job
	 * `daily_lifecycle`, read by the diagnostics page, the admin notice and the
	 * gap detection); the two other options are deleted.
	 *
	 * Public only so the step table can call it.
	 *
	 * @return bool
	 */
	public static function step_19_one_heartbeat_store(): bool {
		$legacy = (int) get_option( 'wplc_last_daily_run', 0 );
		if ( $legacy > 0 ) {
			$runs = get_option( 'wplc_last_run', array() );
			$runs = is_array( $runs ) ? $runs : array();
			if ( empty( $runs['daily_lifecycle'] ) || (int) $runs['daily_lifecycle'] < $legacy ) {
				$runs['daily_lifecycle'] = $legacy;
				update_option( 'wplc_last_run', $runs, false );
			}
		}
		delete_option( 'wplc_last_daily_run' );
		delete_option( 'wplc_job_heartbeats' );

		return true;
	}

	/**
	 * Indexes of line B that step 21 checks in addition to LIST_INDEXES (table key => index names).
	 *
	 * @var array<string,string[]>
	 */
	private const MERGED_INDEXES = array(
		'licenses'    => array( 'source_order', 'created_at' ),
		'activations' => array( 'status_deactivated' ),
		'event_inbox' => array( 'outcome_processed' ),
	);

	/**
	 * Schema 21: the two development lines in one schema.
	 *
	 * Lines A and B both used the versions 12 to 20 with different meanings.
	 * An install recorded at 17 (line A) skipped the steps 18 and 19 of line B
	 * only when it was at 19 or later, and an install recorded at 20 (line B)
	 * never ran the steps 12 to 17 of line A. dbDelta has added every missing
	 * column, index and table by now; this step runs every data and
	 * verification step of both lines again and moves line B's review
	 * status `payment_review` to `review_required`. All of it is idempotent.
	 *
	 * @return bool
	 */
	private static function step_21_merged_schema(): bool { // @phpstan-ignore method.unused (called through self::$steps)
		$steps = array(
			'step_10_autoload_schema_version',
			'step_12_adopt_reminder_log',
			'step_13_renewal_product_column',
			'step_14_renewal_review_columns',
			'step_15_list_indexes',
			'step_16_grant_activation_column',
			'step_17_plan_change_columns',
			'step_18_adopt_reminder_log',
			'step_19_one_heartbeat_store',
		);
		foreach ( $steps as $step ) {
			if ( true !== call_user_func( array( self::class, $step ) ) ) {
				return false;
			}
		}

		if ( ! self::adopt_payment_review_rows() ) {
			return false;
		}

		return self::indexes_exist( self::MERGED_INDEXES );
	}

	/**
	 * Indexes added by schema 22 (table key => index names).
	 *
	 * @var array<string,string[]>
	 */
	private const LOOKUP_INDEXES = array(
		'licenses'    => array( 'payment_token' ),
		'event_inbox' => array( 'source_received' ),
	);

	/**
	 * Schema 22: indexes for the payment token and the last event per source (P-31, P-53).
	 *
	 * `licenses.payment_token (payment_token_id)` serves the lookups that run when
	 * WooCommerce deletes a saved payment method (Orphans::on_payment_token_deleted(),
	 * the Stripe token sync); without it each of them scans the whole table, and the
	 * UPDATE locks every row it reads. `event_inbox.source_received (source,
	 * received_at)` answers "time of the last Stripe event" on the diagnostics page
	 * from the index. dbDelta adds both; no data changes. dbDelta reports no errors,
	 * so the step checks that the indexes exist and is retried otherwise.
	 *
	 * @return bool
	 */
	private static function step_22_lookup_indexes(): bool { // @phpstan-ignore method.unused (called through self::$steps)
		return self::indexes_exist( self::LOOKUP_INDEXES );
	}

	/**
	 * Schema 23: audit_log.source with its index (A-76).
	 *
	 * AuditLog::record() stores where an entry came from (cli, cron, webhook,
	 * rest, admin, customer, system). dbDelta adds the column and the index
	 * `source_entries (source)`, which the source filter of the audit screen
	 * uses (InnoDB appends the primary key, so the list stays ordered by ID).
	 * Older entries keep the empty default: their source is unknown and is
	 * shown as such. No data changes; the step checks that column and index
	 * exist, because dbDelta reports no errors, and is retried otherwise.
	 *
	 * @return bool
	 */
	private static function step_23_audit_source(): bool { // @phpstan-ignore method.unused (called through self::$steps)
		global $wpdb;

		$tables = self::table_names();
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = %s AND column_name = %s',
				$tables['audit_log'],
				'source'
			)
		);

		return 1 === (int) $exists && self::indexes_exist( array( 'audit_log' => array( 'source_entries' ) ) );
	}

	/**
	 * Schema 25: the columns of an announced renewal price change (INH-05).
	 *
	 * dbDelta adds them and reports no errors, so the step checks that they exist. No data changes:
	 * existing licenses have no announcement.
	 *
	 * @return bool
	 */
	private static function step_25_price_change_columns(): bool { // @phpstan-ignore method.unused (called through self::$steps)
		global $wpdb;

		$tables = self::table_names();
		$count  = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = %s AND column_name IN (%s, %s, %s, %s, %s)',
				$tables['licenses'],
				'price_change_amount_minor',
				'price_change_effective_at',
				'price_change_announced_at',
				'price_change_notified_at',
				'price_change_notice_days'
			)
		);

		return 5 === $count;
	}

	/**
	 * Import the catalog files shipped with the plugin (T-150).
	 *
	 * Until schema 23 the products, entitlement keys and labels were lists in the code. They are
	 * data now; this step fills the store from `catalogs/*.json` so that existing installations
	 * and new ones offer what they offered before. A product an administrator imported is never
	 * replaced. Plans and licenses are not touched: they store their keys themselves.
	 * Idempotent: an unchanged file is skipped.
	 *
	 * @return bool False when a shipped file is invalid; the migration is then retried.
	 */
	private static function step_24_import_bundled_catalogs(): bool { // @phpstan-ignore method.unused (called through self::$steps)
		$report = \WPLC\Catalog\CatalogStore::import_bundled();

		return array() === $report['failed'];
	}

	/**
	 * Move renewals of line B's review status `payment_review` to `review_required`.
	 *
	 * Line B parked a payment that was taken but not applied, or whose result
	 * stayed unknown, in `payment_review`. The merged code knows one review
	 * status with its admin screen (RenewalReview). The reason comes from
	 * `last_error`, the start of the case from the last change of the row.
	 * Idempotent: rows already moved no longer match.
	 *
	 * @return bool
	 */
	private static function adopt_payment_review_rows(): bool {
		global $wpdb;

		$tables = self::table_names();
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = %s, retry_at = NULL, review_reason = IF( review_reason = '', LEFT( IF( last_error = '', %s, last_error ), 255 ), review_reason ), review_at = COALESCE( review_at, updated_at ) WHERE status = %s",
				$tables['renewals'],
				'review_required',
				'payment_review',
				'payment_review'
			)
		);

		return false !== $result;
	}

	/**
	 * Whether every listed index exists.
	 *
	 * dbDelta reports no errors, so a step that depends on new indexes checks them.
	 *
	 * @param array<string,string[]> $indexes Table key => index names.
	 * @return bool
	 */
	private static function indexes_exist( array $indexes ): bool {
		global $wpdb;

		$tables = self::table_names();
		foreach ( $indexes as $table => $names ) {
			$found = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT DISTINCT index_name FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = %s',
					$tables[ $table ]
				)
			);
			if ( array_diff( $names, array_map( 'strval', (array) $found ) ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Migrations run in admin, cron and CLI requests only.
	 *
	 * A front-end or REST request must never wait for a schema lock or run DDL.
	 *
	 * @return bool
	 */
	private static function can_migrate_in_this_request(): bool {
		$allowed = 'cli' === PHP_SAPI
			|| ( defined( 'WP_CLI' ) && WP_CLI )
			|| ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() )
			|| ( is_admin() && ! ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) );

		/**
		 * Filter whether this request may run a schema migration.
		 *
		 * @param bool $allowed True in admin (not AJAX), cron and CLI requests.
		 */
		return (bool) apply_filters( 'wplc_schema_can_migrate', $allowed );
	}

	/**
	 * Whether enough time has passed since the last failed migration.
	 *
	 * @return bool
	 */
	private static function retry_allowed(): bool {
		$error = get_option( self::ERROR_OPTION, null );
		if ( ! is_array( $error ) || empty( $error['time'] ) ) {
			return true;
		}
		$failed_at = (int) $error['time'];

		return $failed_at > time() || time() - $failed_at >= self::RETRY_DELAY;
	}

	/**
	 * Remember why a migration failed (diagnostics, admin notice, retry delay).
	 *
	 * @param string[] $problems Structure problems and database errors.
	 * @return void
	 */
	private static function record_failure( array $problems ): void {
		$message = implode( '; ', array_slice( array_values( array_unique( array_map( 'strval', $problems ) ) ), 0, 20 ) );
		update_option(
			self::ERROR_OPTION,
			array(
				'time'    => time(),
				'version' => self::VERSION,
				'message' => function_exists( 'mb_substr' ) ? mb_substr( $message, 0, 2000 ) : substr( $message, 0, 2000 ),
			),
			false
		);
		update_option( self::DIAGNOSTICS_ERROR_OPTION, function_exists( 'mb_substr' ) ? mb_substr( $message, 0, 2000 ) : substr( $message, 0, 2000 ), false );
		if ( '' !== (string) get_option( self::VERIFIED_OPTION, '' ) ) {
			delete_option( self::VERIFIED_OPTION );
		}
	}

	/**
	 * Database errors wpdb collected since the given offset.
	 *
	 * @param int $offset Number of entries in $EZSQL_ERROR before the migration started.
	 * @return string[]
	 */
	private static function database_errors_since( int $offset ): array {
		global $EZSQL_ERROR;

		$errors = array();
		foreach ( array_slice( is_array( $EZSQL_ERROR ) ? $EZSQL_ERROR : array(), $offset ) as $entry ) {
			if ( is_array( $entry ) && isset( $entry['query'] ) && preg_match( '/^\s*DESCRIBE\s/i', (string) $entry['query'] ) ) {
				// dbDelta probes every table with DESCRIBE; a missing table is its normal "create it" case.
				continue;
			}
			if ( is_array( $entry ) && ! empty( $entry['error_str'] ) ) {
				$errors[] = 'Database error: ' . (string) $entry['error_str'];
			}
		}

		return $errors;
	}

	/**
	 * Store the time of a successful structure check.
	 *
	 * @return void
	 */
	private static function mark_verified(): void {
		update_option( self::VERIFIED_OPTION, self::VERSION . '|' . time(), true );
	}

	/**
	 * Drop a stale cached copy of the version option.
	 *
	 * With a persistent object cache the cached value can be older than the
	 * database row (restore without flush, cache written by another release).
	 * Without this the whole migration would run on every request.
	 *
	 * @return void
	 */
	private static function refresh_cached_version(): void {
		wp_cache_delete( self::VERSION_OPTION, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Lower-case type name with MySQL synonyms resolved.
	 *
	 * @param string $type Type from a CREATE TABLE statement or information_schema.
	 * @return string
	 */
	private static function normalize_type( string $type ): string {
		$type     = strtolower( $type );
		$synonyms = array(
			'integer' => 'int',
			'bool'    => 'tinyint',
			'boolean' => 'tinyint',
			'numeric' => 'decimal',
		);

		return isset( $synonyms[ $type ] ) ? $synonyms[ $type ] : $type;
	}

	/**
	 * Read the stored schema version directly, bypassing the options cache.
	 *
	 * @return string
	 */
	private static function stored_version(): string {
		global $wpdb;

		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", self::VERSION_OPTION ) );

		return null === $value ? '0' : (string) $value;
	}

	/**
	 * Whether the version option row is flagged for autoloading.
	 *
	 * @return bool
	 */
	private static function version_is_autoloaded(): bool {
		global $wpdb;

		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", self::VERSION_OPTION ) );

		return is_string( $autoload ) && in_array( $autoload, array( 'yes', 'on', 'auto', 'auto-on' ), true );
	}

	/**
	 * Insert a row whose UNIQUE key a concurrent request may have inserted first.
	 *
	 * The duplicate-key error of the losing request is expected and not logged;
	 * every other database error is reported as $wpdb->insert() would.
	 *
	 * @param string                $table Table name.
	 * @param array<string,mixed>   $data Column => value.
	 * @param array<int,string>     $format Value formats.
	 * @return int|null Insert ID, 0 when the unique key already exists, null on another error.
	 */
	public static function insert_unique( string $table, array $data, array $format ): ?int {
		global $wpdb;

		$suppressed = $wpdb->suppress_errors( true );
		$result     = $wpdb->insert( $table, $data, $format );
		$wpdb->suppress_errors( $suppressed );
		if ( false !== $result ) {
			return (int) $wpdb->insert_id;
		}
		$dbh   = $wpdb->dbh;
		$errno = $dbh instanceof \mysqli ? (int) mysqli_errno( $dbh ) : 0; // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_errno
		if ( 1062 === $errno || false !== stripos( (string) $wpdb->last_error, 'Duplicate entry' ) ) {
			return 0;
		}
		$wpdb->print_error();

		return null;
	}

	/**
	 * Server-wide lock name for this site's schema (MySQL limits names to 64 characters).
	 *
	 * @return string
	 */
	private static function lock_name(): string {
		return 'wplc_schema_' . md5( self::lock_scope() );
	}

	/**
	 * Identifies this site among all sites on the same database server.
	 *
	 * MySQL advisory locks are global to the server. Every lock name must
	 * include this scope, otherwise two shops (or a shop and its staging copy)
	 * on one server would block each other's licenses and renewals.
	 *
	 * @return string
	 */
	public static function lock_scope(): string {
		global $wpdb;

		$prefix = is_object( $wpdb ) && isset( $wpdb->prefix ) ? (string) $wpdb->prefix : '';

		return ( defined( 'DB_NAME' ) ? (string) DB_NAME : '' ) . '|' . $prefix;
	}
}
