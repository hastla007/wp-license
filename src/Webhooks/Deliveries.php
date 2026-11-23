<?php
/**
 * Delivery log of the outgoing webhooks.
 *
 * @package WPLC
 */

namespace WPLC\Webhooks;

use WPLC\Infrastructure\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rows of `webhook_deliveries`: one per event and endpoint, holding the payload and the result of
 * the last attempt (status code, duration, number of the attempt, short error).
 *
 * Statuses: `pending` (queued), `sending` (an attempt is running), `retrying` (failed, the next
 * attempt is scheduled), `delivered`, `failed` (no attempt left), `cancelled` (the endpoint was
 * switched off before the attempt). The log keeps the newest KEEP_PER_ENDPOINT rows per endpoint;
 * older finished rows are removed by the retention rule `webhook_deliveries`.
 */
final class Deliveries {

	public const PENDING   = 'pending';
	public const SENDING   = 'sending';
	public const RETRYING  = 'retrying';
	public const DELIVERED = 'delivered';
	public const FAILED    = 'failed';
	public const CANCELLED = 'cancelled';

	/** Statuses in which a row is finished. */
	public const FINISHED = array( self::DELIVERED, self::FAILED, self::CANCELLED );

	/** Rows kept per endpoint; older finished rows are removed when a new one is added. */
	public const KEEP_PER_ENDPOINT = 100;

	/** Seconds after which an attempt that never reported back counts as lost and may be sent again. */
	public const STALE_SENDING_SECONDS = 600;

	/**
	 * Add a delivery; an event that already has one for this endpoint is not added again.
	 *
	 * @param int    $endpoint_id Endpoint ID.
	 * @param string $event_id    Event ID.
	 * @param string $event       Event name.
	 * @param string $payload     JSON body.
	 * @return int Delivery ID, 0 when it existed already or could not be stored.
	 */
	public static function create( int $endpoint_id, string $event_id, string $event, string $payload ): int {
		global $wpdb;

		$now = gmdate( 'Y-m-d H:i:s' );
		$ok  = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (endpoint_id, event_id, event, payload, status, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s, %s)',
				self::table(),
				$endpoint_id,
				$event_id,
				$event,
				$payload,
				self::PENDING,
				$now,
				$now
			)
		);
		if ( 1 !== (int) $ok ) {
			return 0;
		}
		$id = (int) $wpdb->insert_id;
		self::prune( $endpoint_id );

		return $id;
	}

	/**
	 * One delivery.
	 *
	 * @param int $id Delivery ID.
	 * @return array<string,mixed>|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ), ARRAY_A );

		return is_array( $row ) ? self::shape( $row ) : null;
	}

	/**
	 * Newest deliveries of an endpoint, without the payload.
	 *
	 * @param int $endpoint_id Endpoint ID.
	 * @param int $limit       Most rows.
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_endpoint( int $endpoint_id, int $limit = 20 ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, endpoint_id, event_id, event, status, attempts, response_code, duration_ms, error, next_attempt_at, delivered_at, created_at, updated_at FROM %i WHERE endpoint_id = %d ORDER BY id DESC LIMIT %d',
				self::table(),
				$endpoint_id,
				max( 1, min( self::KEEP_PER_ENDPOINT, $limit ) )
			),
			ARRAY_A
		);

		return array_map( array( self::class, 'shape' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Take a delivery for an attempt: only a queued row, or one whose attempt was lost, can be taken.
	 *
	 * The conditional update makes a duplicate queue entry harmless: only one request sends.
	 *
	 * @param int $id Delivery ID.
	 * @return bool
	 */
	public static function claim( int $id ): bool {
		global $wpdb;

		$now   = time();
		$taken = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, attempts = attempts + 1, updated_at = %s WHERE id = %d AND ( status IN (%s, %s) OR ( status = %s AND updated_at < %s ) )',
				self::table(),
				self::SENDING,
				gmdate( 'Y-m-d H:i:s', $now ),
				$id,
				self::PENDING,
				self::RETRYING,
				self::SENDING,
				gmdate( 'Y-m-d H:i:s', $now - self::STALE_SENDING_SECONDS )
			)
		);

		return 1 === (int) $taken;
	}

	/**
	 * Store the result of an attempt.
	 *
	 * @param int         $id              Delivery ID.
	 * @param string      $status          New status.
	 * @param int         $response_code   HTTP status, 0 when no answer came.
	 * @param int         $duration_ms     Duration of the request.
	 * @param string      $error           Short error, '' on success.
	 * @param string|null $next_attempt_at Time of the next attempt (UTC) or null.
	 * @return void
	 */
	public static function finish_attempt( int $id, string $status, int $response_code, int $duration_ms, string $error, ?string $next_attempt_at ): void {
		global $wpdb;

		$now  = gmdate( 'Y-m-d H:i:s' );
		$data = array(
			'status'          => $status,
			'response_code'   => max( 0, min( 65535, $response_code ) ),
			'duration_ms'     => max( 0, $duration_ms ),
			'error'           => Payload::short_error( $error ),
			'next_attempt_at' => $next_attempt_at,
			'updated_at'      => $now,
		);
		if ( self::DELIVERED === $status ) {
			$data['delivered_at'] = $now;
		}
		$wpdb->update( self::table(), $data, array( 'id' => $id ) );
	}

	/**
	 * Mark a delivery as not sent because its endpoint is switched off or gone.
	 *
	 * @param int    $id    Delivery ID.
	 * @param string $error Reason.
	 * @return void
	 */
	public static function cancel( int $id, string $error ): void {
		self::finish_attempt( $id, self::CANCELLED, 0, 0, $error, null );
	}

	/**
	 * Queue a finished delivery again with the same event ID and payload; the attempts start at one.
	 *
	 * @param int $id Delivery ID.
	 * @return bool False when it is still queued or being sent.
	 */
	public static function reset_for_redelivery( int $id ): bool {
		global $wpdb;

		$now   = time();
		$reset = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = %s, attempts = 0, response_code = 0, duration_ms = 0, error = '', next_attempt_at = NULL, delivered_at = NULL, updated_at = %s WHERE id = %d AND ( status IN (%s, %s, %s) OR ( status = %s AND updated_at < %s ) )",
				self::table(),
				self::PENDING,
				gmdate( 'Y-m-d H:i:s', $now ),
				$id,
				self::DELIVERED,
				self::FAILED,
				self::CANCELLED,
				self::SENDING,
				gmdate( 'Y-m-d H:i:s', $now - self::STALE_SENDING_SECONDS )
			)
		);

		return 1 === (int) $reset;
	}

	/**
	 * Remove finished rows beyond the newest KEEP_PER_ENDPOINT of an endpoint.
	 *
	 * @param int $endpoint_id Endpoint ID.
	 * @return int Removed rows.
	 */
	public static function prune( int $endpoint_id ): int {
		global $wpdb;

		$border = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE endpoint_id = %d ORDER BY id DESC LIMIT 1 OFFSET %d',
				self::table(),
				$endpoint_id,
				self::KEEP_PER_ENDPOINT
			)
		);
		if ( null === $border ) {
			return 0;
		}

		return (int) $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE endpoint_id = %d AND id <= %d AND status IN (%s, %s, %s) LIMIT 500',
				self::table(),
				$endpoint_id,
				(int) $border,
				self::DELIVERED,
				self::FAILED,
				self::CANCELLED
			)
		);
	}

	/**
	 * Retention: delete one batch of rows created before a cutoff (rule `webhook_deliveries`).
	 *
	 * Rows that are still queued or retrying are kept for one more day past the cutoff, so a short
	 * period never removes a delivery while its retries are running (they end within about 2.5 hours
	 * with the default schedule).
	 *
	 * @param string $cutoff UTC datetime.
	 * @param int    $limit  Batch size.
	 * @return int Deleted rows.
	 */
	public static function purge_before( string $cutoff, int $limit ): int {
		global $wpdb;

		$open_cutoff = gmdate( 'Y-m-d H:i:s', (int) strtotime( $cutoff . ' UTC' ) - DAY_IN_SECONDS );
		$result      = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE created_at < %s AND ( status IN (%s, %s, %s) OR created_at < %s ) ORDER BY created_at ASC LIMIT %d',
				self::table(),
				$cutoff,
				self::DELIVERED,
				self::FAILED,
				self::CANCELLED,
				$open_cutoff,
				max( 1, $limit )
			)
		);

		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Typed row.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	private static function shape( array $row ): array {
		$out = array(
			'id'              => (int) $row['id'],
			'endpoint_id'     => (int) $row['endpoint_id'],
			'event_id'        => (string) $row['event_id'],
			'event'           => (string) $row['event'],
			'status'          => (string) $row['status'],
			'attempts'        => (int) $row['attempts'],
			'response_code'   => (int) $row['response_code'],
			'duration_ms'     => (int) $row['duration_ms'],
			'error'           => (string) $row['error'],
			'next_attempt_at' => (string) ( $row['next_attempt_at'] ?? '' ),
			'delivered_at'    => (string) ( $row['delivered_at'] ?? '' ),
			'created_at'      => (string) $row['created_at'],
			'updated_at'      => (string) $row['updated_at'],
		);
		if ( array_key_exists( 'payload', $row ) ) {
			$out['payload'] = (string) $row['payload'];
		}

		return $out;
	}

	/**
	 * Table of the deliveries.
	 *
	 * @return string
	 */
	private static function table(): string {
		return Schema::table_names()['webhook_deliveries'];
	}
}
