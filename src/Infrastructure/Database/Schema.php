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
	 * 11: renewals.status widened to 30 characters ("manual_action_required" has 22;
	 *     WordPress refuses to write a value longer than the column).
	 * 12: reminder_log adopted from ReminderLog, which created it on first use
	 *     (same name, columns and keys; the old version option is removed).
	 */
	public const VERSION = '12';

	/**
	 * Option in which ReminderLog recorded its self-installed table (up to schema 11).
	 */
	public const LEGACY_REMINDER_LOG_VERSION_OPTION = 'wplc_reminder_log_version';

	/**
	 * Option that stores the installed schema version (autoloaded).
	 */
	public const VERSION_OPTION = 'wplc_schema_version';

	/**
	 * Seconds to wait for a concurrent migration before giving up for this request.
	 */
	private const LOCK_TIMEOUT = 3;

	/**
	 * Data-migration steps, keyed by the schema version they bring the data to.
	 *
	 * Every step runs after dbDelta, must be idempotent (it can run again after a
	 * partial failure or on a fresh install with empty tables) and returns true
	 * on success. Add new steps here together with a VERSION bump.
	 *
	 * @var array<string,array{0:string,1:string}>
	 */
	private static $steps = array(
		'10' => array( self::class, 'step_10_autoload_schema_version' ),
		'12' => array( self::class, 'step_12_adopt_reminder_log' ),
	);

	/**
	 * Register schema related hooks.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_filter( 'wpmu_drop_tables', array( self::class, 'filter_wpmu_drop_tables' ), 10, 2 );
	}

	/**
	 * Run missing schema migrations.
	 *
	 * @return bool True when the required tables exist.
	 */
	public static function maybe_upgrade(): bool {
		$installed = (string) get_option( self::VERSION_OPTION, '0' );
		if ( version_compare( $installed, self::VERSION, '>=' ) ) {
			return true;
		}

		return self::migrate();
	}

	/**
	 * Create or update the plugin tables, then run pending data migrations.
	 *
	 * Concurrent requests are serialized with a named MySQL lock. A request that
	 * cannot get the lock in time returns false and leaves the work to the
	 * request that holds it.
	 *
	 * @return bool True when every required table exists and all steps succeeded.
	 */
	public static function migrate(): bool {
		global $wpdb;

		$lock_name = self::lock_name();
		$lock      = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, self::LOCK_TIMEOUT ) );
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
	 * @param string[] $step_versions Versions that have a registered step.
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
	 * @param mixed $tables Table names collected by WordPress.
	 * @param mixed $site_id ID of the site that is being deleted.
	 * @return mixed
	 */
	public static function filter_wpmu_drop_tables( $tables, $site_id = 0 ) {
		global $wpdb;

		$site_id = (int) $site_id;
		if ( ! is_array( $tables ) || $site_id <= 0 ) {
			return $tables;
		}

		return array_values( array_unique( array_merge( $tables, array_values( self::table_names_for_prefix( $wpdb->get_blog_prefix( $site_id ) ) ) ) ) );
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
	 * dbDelta, table check and pending data steps. Caller holds the lock.
	 *
	 * @return bool
	 */
	private static function run_migration(): bool {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

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
				renewal_amount_minor bigint(20) unsigned NOT NULL DEFAULT 0,
				renewal_currency_decimals tinyint(3) unsigned NOT NULL DEFAULT 2,
				renewal_currency char(3) NOT NULL DEFAULT 'EUR',
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
				KEY status_paid_until (status,paid_until),
				KEY next_payment_at (next_payment_at)
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
				KEY staging_parent (staging_parent_id)
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
				KEY inbox_pending (processed_at,received_at)
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
				PRIMARY KEY  (id),
				KEY object_history (object_type,object_id,created_at),
				KEY actor_history (actor_user_id,created_at)
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
			// Created by ReminderLog itself up to schema 11 with exactly this layout,
			// so dbDelta leaves an existing table and its rows unchanged.
			"CREATE TABLE {$tables['reminder_log']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				license_id bigint(20) unsigned NOT NULL,
				reminder_type varchar(40) NOT NULL,
				period_end datetime NOT NULL,
				sent_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY license_reminder (license_id,reminder_type,period_end),
				KEY sent_at (sent_at)
			) {$charset_collate};",
		);

		foreach ( $sql as $statement ) {
			\dbDelta( $statement );
		}

		foreach ( $tables as $table_name ) {
			$table_exists = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s',
					$table_name
				)
			);
			if ( 1 !== (int) $table_exists ) {
				return false;
			}
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
			// The last successful step is recorded; the failed one is retried on the next request.
			return false;
		}

		if ( version_compare( $installed, self::VERSION, '<' ) || ! self::version_is_autoloaded() ) {
			self::store_version( self::VERSION );
		}

		return true;
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
	private static function step_10_autoload_schema_version(): bool {
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
	 * Schema 12: the reminder log belongs to the schema.
	 *
	 * dbDelta has already created the table or found the one ReminderLog
	 * installed earlier (identical layout, rows are kept). Only the separate
	 * version option of the old installer is left to remove.
	 *
	 * @return bool
	 */
	private static function step_12_adopt_reminder_log(): bool {
		delete_option( self::LEGACY_REMINDER_LOG_VERSION_OPTION );

		return true;
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
