<?php
/**
 * Server-side trace of rejected API requests.
 *
 * @package WPLC
 */

namespace WPLC\Api\V1;

use WPLC\Infrastructure\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Counts and logs every error answer of the `wplc/v1` routes in one place (A-71).
 *
 * - A counter per error code and UTC day (kept for two days) that the
 *   diagnostics page or a monitor can read with counts().
 * - The action `wplc/api_request_rejected`.
 * - A line in the WooCommerce log `wplc-api`, throttled per code so that an
 *   attack cannot fill the log.
 * Nothing that identifies a customer is written: no address, key or site.
 */
final class ApiLog {

	/**
	 * Error codes of the API with their HTTP status. The contract of the API:
	 * clients decide by these codes, never by the message text.
	 */
	public const CODES = array(
		'invalid_activation' => 400,
		'development_site_invalid' => 400,
		'staging_parent_required' => 400,
		'rest_missing_callback_param' => 400,
		'rest_invalid_param' => 400,
		'invalid_key' => 401,
		'invalid_request_signature' => 401,
		'request_expired' => 401,
		'activation_not_found' => 401,
		'installation_secret_invalid' => 401,
		'product_mismatch' => 403,
		'license_expired' => 403,
		'license_suspended' => 403,
		'license_revoked' => 403,
		'license_inactive' => 403,
		'activation_mismatch' => 403,
		'activation_deactivated' => 403,
		'license_not_found' => 404,
		'site_limit' => 409,
		'staging_limit' => 409,
		'development_limit' => 409,
		'staging_parent_invalid' => 409,
		'staging_site_invalid' => 409,
		'installation_conflict' => 409,
		'activation_conflict' => 409,
		'site_mismatch' => 409,
		'deactivation_failed' => 409,
		'request_replayed' => 409,
		'rate_limited' => 429,
		'activation_failed' => 500,
		'server_configuration' => 500,
		'response_encoding_failed' => 500,
		'retry_later' => 503,
		'rate_limit_store_unavailable' => 503,
		'replay_store_unavailable' => 503,
		'package_configuration_invalid' => 503,
		'download_token_error' => 503,
	);

	/** @var int Log lines per code and day before only every hundredth rejection is logged. */
	private const LOG_FIRST = 20;

	/**
	 * Record one rejected request.
	 *
	 * @param string $code   Error code.
	 * @param int    $status HTTP status.
	 * @param string $method HTTP method.
	 * @param string $route  REST route.
	 * @return void
	 */
	public static function rejected( string $code, int $status, string $method, string $route ): void {
		$code = sanitize_key( $code );
		if ( '' === $code ) {
			return;
		}
		// Path parameters (activation IDs, tokens) do not belong in a log.
		$route = (string) preg_replace( '#/(?:\d+|[a-f0-9]{32,})(?=/|$)#', '/{id}', $route );

		$count = Authenticator::increment_bucket( self::counter_hash( $code ), gmdate( 'Y-m-d 00:00:00' ) );

		/**
		 * Fires for every error answer of the license API.
		 *
		 * @param string $code   Error code.
		 * @param int    $status HTTP status.
		 * @param string $method HTTP method.
		 * @param string $route  REST route with IDs and tokens replaced.
		 */
		do_action( 'wplc/api_request_rejected', $code, $status, $method, $route );

		$count = false === $count ? 0 : (int) $count;
		$log = $count <= self::LOG_FIRST || 0 === $count % 100;

		/**
		 * Filter whether this rejection is written to the log.
		 *
		 * @param bool   $log    Default: the first 20 per code and day, then every hundredth.
		 * @param string $code   Error code.
		 * @param int    $status HTTP status.
		 */
		if ( ! apply_filters( 'wplc_log_api_rejection', $log, $code, $status ) || ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		wc_get_logger()->log(
			$status >= 500 ? 'error' : 'notice',
			sprintf( 'API request rejected: %s (%d) %s %s; %d today', $code, $status, $method, $route, $count ),
			array( 'source' => 'wplc-api' )
		);
	}

	/**
	 * Rejections per error code on one UTC day.
	 *
	 * @param int $days_ago 0 for today, 1 for yesterday (older counters are deleted).
	 * @return array<string,int> Codes with at least one rejection.
	 */
	public static function counts( int $days_ago = 0 ): array {
		global $wpdb;

		$tables = Schema::table_names();
		$day = gmdate( 'Y-m-d 00:00:00', time() - max( 0, $days_ago ) * DAY_IN_SECONDS );
		$by_hash = array();
		foreach ( array_keys( self::CODES ) as $code ) {
			$by_hash[ self::counter_hash( $code ) ] = $code;
		}
		$placeholders = implode( ',', array_fill( 0, count( $by_hash ), '%s' ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders only.
				"SELECT limiter_hash,hits FROM {$tables['request_limits']} WHERE bucket_start = %s AND limiter_hash IN ({$placeholders})",
				array_merge( array( $day ), array_keys( $by_hash ) )
			),
			ARRAY_A
		);

		$counts = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( isset( $by_hash[ $row['limiter_hash'] ] ) ) {
				$counts[ $by_hash[ $row['limiter_hash'] ] ] = (int) $row['hits'];
			}
		}
		arsort( $counts );

		return $counts;
	}

	/**
	 * Row key of the daily counter of one code.
	 *
	 * @param string $code Error code.
	 * @return string
	 */
	private static function counter_hash( string $code ): string {
		return hash( 'sha256', 'api-error:' . $code );
	}
}
