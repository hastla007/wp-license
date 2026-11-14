<?php
/**
 * Look at one license from the command line.
 *
 * @package WPLC
 */

namespace WPLC\CLI;

use WPLC\Infrastructure\Database\Schema;
use WPLC\License\LicenseKey;
use WPLC\License\LicenseLifecycle;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `wp wplc license`: read-only views for support (A-74).
 */
final class LicenseCommand {

	/**
	 * Show a license with its activations, renewals and latest audit entries.
	 *
	 * The license key itself is never printed.
	 *
	 * ## OPTIONS
	 *
	 * <license>
	 * : License ID, complete license key, or license UUID.
	 *
	 * [--format=<format>]
	 * : "text" (default) or "json".
	 *
	 * ## EXAMPLES
	 *
	 *     wp wplc license show 123
	 *     wp wplc license show WPLC-... --format=json
	 *
	 * @param array<int,string>   $args       Positional arguments.
	 * @param array<string,mixed> $assoc_args Options.
	 * @return void
	 */
	public function show( array $args, array $assoc_args ): void {
		$details = self::details( (string) ( $args[0] ?? '' ) );
		if ( null === $details ) {
			\WP_CLI::error( 'License not found.' );
		}
		if ( 'json' === ( $assoc_args['format'] ?? 'text' ) ) {
			\WP_CLI::line( (string) wp_json_encode( $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		foreach ( $details['license'] as $name => $value ) {
			\WP_CLI::line( sprintf( '%-22s %s', $name . ':', is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : (string) $value ) );
		}
		foreach ( array( 'activations', 'renewals', 'audit' ) as $section ) {
			\WP_CLI::line( '' );
			\WP_CLI::line( strtoupper( $section ) . ' (' . count( $details[ $section ] ) . ')' );
			foreach ( $details[ $section ] as $row ) {
				\WP_CLI::line( '  ' . implode( ' | ', array_map( 'strval', $row ) ) );
			}
		}
	}

	/**
	 * Collect what support needs to know about one license.
	 *
	 * @param string $reference License ID, license key, or license UUID.
	 * @return array{license:array<string,mixed>,activations:array<int,array<string,mixed>>,renewals:array<int,array<string,mixed>>,audit:array<int,array<string,mixed>>}|null
	 */
	public static function details( string $reference ): ?array {
		global $wpdb;

		$tables = Schema::table_names();
		$reference = trim( $reference );
		if ( '' === $reference ) {
			return null;
		}
		if ( ctype_digit( $reference ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names().
			$license = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['licenses']} WHERE id = %d LIMIT 1", (int) $reference ), ARRAY_A );
		} elseif ( wp_is_uuid( strtolower( $reference ) ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names().
			$license = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['licenses']} WHERE license_uuid = %s LIMIT 1", strtolower( $reference ) ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names().
			$license = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['licenses']} WHERE license_key_hash = %s LIMIT 1", LicenseKey::hash( $reference ) ), ARRAY_A );
		}
		if ( ! is_array( $license ) ) {
			return null;
		}

		$license_id = (int) $license['id'];
		$activations = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names().
				"SELECT id,status,environment,site_url,staging_parent_id,product_version,wp_version,php_version,activated_at,last_seen_at,deactivated_at FROM {$tables['activations']} WHERE license_id = %d ORDER BY id ASC",
				$license_id
			),
			ARRAY_A
		);
		$renewals = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names().
				"SELECT id,status,period_start,period_end,renewal_order_id,attempt_count,retry_at,grace_ends_at,updated_at FROM {$tables['renewals']} WHERE license_id = %d ORDER BY id DESC LIMIT 10",
				$license_id
			),
			ARRAY_A
		);
		$audit = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names().
				"SELECT created_at,action,actor_user_id,reason FROM {$tables['audit_log']} WHERE object_type = %s AND object_id = %d ORDER BY id DESC LIMIT 20",
				'license',
				$license_id
			),
			ARRAY_A
		);

		return array(
			'license' => array(
				'id' => $license_id,
				'key_prefix' => (string) $license['key_prefix'],
				'key_verifiable' => LicenseKey::matches_hash( (string) $license['license_uuid'], (string) $license['license_key_secret_id'], (string) $license['license_key_hash'] ),
				'customer_id' => (int) $license['customer_id'],
				'product_key' => (string) $license['product_key'],
				'plan_key' => (string) $license['plan_key'],
				'status' => (string) $license['status'],
				'status_reason' => (string) $license['status_reason'],
				'has_access' => LicenseLifecycle::has_access( $license ),
				'paid_until' => (string) $license['paid_until'],
				'renewal_enabled' => ! empty( $license['renewal_enabled'] ),
				'cancel_at_period_end' => ! empty( $license['cancel_at_period_end'] ),
				'source_order_id' => (int) $license['source_order_id'],
				'created_at' => (string) $license['created_at'],
			),
			'activations' => is_array( $activations ) ? $activations : array(),
			'renewals' => is_array( $renewals ) ? $renewals : array(),
			'audit' => is_array( $audit ) ? $audit : array(),
		);
	}
}
