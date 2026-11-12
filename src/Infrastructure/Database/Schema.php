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
	 */
	public const VERSION = '8';

	/**
	 * Run missing schema migrations.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		$installed = (string) get_option( 'wplc_schema_version', '0' );
		if ( version_compare( $installed, self::VERSION, '>=' ) ) {
			return;
		}

		self::migrate();
	}

	/**
	 * Create or update the plugin tables.
	 *
	 * @return void
	 */
	public static function migrate(): void {
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
				status varchar(20) NOT NULL DEFAULT 'scheduled',
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
		);

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		update_option( 'wplc_schema_version', self::VERSION, false );
	}

	/**
	 * Return fully prefixed table names.
	 *
	 * @return array<string,string>
	 */
	public static function table_names(): array {
		global $wpdb;

		$prefix = $wpdb->prefix . 'wplc_';

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
		);
	}
}
