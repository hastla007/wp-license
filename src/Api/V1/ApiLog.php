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
 * - A counter per error code and UTC minute (kept for two days) that the
 *   diagnostics page or a monitor reads as daily sums with counts(). One row
 *   per minute instead of one per day, and above SAMPLE_FROM rejections in a
 *   minute only every SAMPLE_RATE-th rejection writes (adding SAMPLE_RATE),
 *   so a flood of rejected requests does not queue on one row lock (P-41).
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
		'request_parameters_unsigned' => 400,
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
		'site_move_refused' => 409,
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

	/** @var int Rejections of one code in one minute from which the counter is sampled. */
	public const SAMPLE_FROM = 100;

	/** @var int Above SAMPLE_FROM, one rejection in this many writes and adds this many. */
	public const SAMPLE_RATE = 10;

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

		$count = self::count( self::counter_hash( $code ), gmdate( 'Y-m-d H:i:00' ) );

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
		$log = 1 === $count || 0 === $count % 100;

		/**
		 * Filter whether this rejection is written to the log.
		 *
		 * @param bool   $log    Default: the first per code and minute, then every hundredth.
		 * @param string $code   Error code.
		 * @param int    $status HTTP status.
		 */
		if ( ! apply_filters( 'wplc_log_api_rejection', $log, $code, $status ) || ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		wc_get_logger()->log(
			$status >= 500 ? 'error' : 'notice',
			sprintf( 'API request rejected: %s (%d) %s %s; about %d this minute', $code, $status, $method, $route, $count ),
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
		$day_start = strtotime( gmdate( 'Y-m-d 00:00:00', time() - max( 0, $days_ago ) * DAY_IN_SECONDS ) . ' UTC' );
		$by_hash = array();
		foreach ( array_keys( self::CODES ) as $code ) {
			$by_hash[ self::counter_hash( $code ) ] = $code;
		}
		$placeholders = implode( ',', array_fill( 0, count( $by_hash ), '%s' ) );
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the values of all placeholders come as one array.
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders only.
				"SELECT limiter_hash,SUM(hits) AS hits FROM {$tables['request_limits']} WHERE bucket_start >= %s AND bucket_start < %s AND limiter_hash IN ({$placeholders}) GROUP BY limiter_hash",
				array_merge( array( gmdate( 'Y-m-d H:i:s', $day_start ), gmdate( 'Y-m-d H:i:s', $day_start + DAY_IN_SECONDS ) ), array_keys( $by_hash ) )
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
	 * Count one rejection in a minute bucket, sampled under load (P-41).
	 *
	 * Below SAMPLE_FROM every rejection is counted exactly. Above it the row
	 * is read without a lock and only one rejection in SAMPLE_RATE writes,
	 * adding SAMPLE_RATE: the sum stays right on average and the row lock is
	 * taken a tenth as often.
	 *
	 * @param string $counter_hash Row key.
	 * @param string $bucket       UTC minute.
	 * @return int|false Approximate count of the minute, or false on database failure.
	 */
	private static function count( string $counter_hash, string $bucket ) {
		global $wpdb;

		$current = Authenticator::current_rate_limit( $counter_hash, $bucket );
		if ( false === $current || $current < self::SAMPLE_FROM ) {
			return Authenticator::increment_bucket( $counter_hash, $bucket );
		}
		if ( 0 !== random_int( 0, self::SAMPLE_RATE - 1 ) ) {
			return $current;
		}

		$tables = Schema::table_names();
		$result = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names().
				"UPDATE {$tables['request_limits']} SET hits = hits + %d WHERE limiter_hash = %s AND bucket_start = %s",
				self::SAMPLE_RATE,
				$counter_hash,
				$bucket
			)
		);

		return false === $result ? false : $current + self::SAMPLE_RATE;
	}

	/**
	 * Row key of the counter of one code.
	 *
	 * @param string $code Error code.
	 * @return string
	 */
	private static function counter_hash( string $code ): string {
		return hash( 'sha256', 'api-error:' . $code );
	}
}
