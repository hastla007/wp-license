<?php
/**
 * Queues and sends the outgoing webhooks.
 *
 * @package WPLC
 */

namespace WPLC\Webhooks;

use WPLC\Compatibility\Requirements;
use WPLC\Events\AfterCommit;
use WPLC\Events\LicenseEvents;
use WPLC\Events\LicenseReader;
use WPLC\Infrastructure\Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Listens to the actions of the hook contract, queues one delivery per subscribed endpoint and
 * sends it through Action Scheduler (SET-LIC-46).
 *
 * - The contract actions fire after the commit; the listener queues through AfterCommit as well,
 *   so a delivery is never queued for a change that is rolled back.
 * - One event ID per event, the same for every endpoint, every attempt and a redelivery: the
 *   receiver uses it as its idempotency key.
 * - Each attempt is a POST with wp_safe_remote_post() to the address checked by UrlGuard (also
 *   right before the request, pinned to the checked IP; an answer from another address than the
 *   checked one never counts as delivered), no redirects, 10 seconds timeout.
 *   A 2xx answer is a success; anything else (3xx included) is retried after 1, 5, 30 and
 *   120 minutes, five attempts in all (filter `wplc_webhook_retry_delays`).
 * - An endpoint whose deliveries failed after their last attempt five times in a row is switched
 *   off (filter `wplc_webhook_disable_after_failures`, 0 = never); administrators see a notice.
 * - The delivery log stores an error code with a technical detail ("code:detail", ERROR_*);
 *   Admin::delivery_error() translates it when it is shown.
 */
final class Dispatcher {

	/** Action Scheduler hook that makes one attempt (argument: delivery ID). */
	public const DELIVER_HOOK = 'wplc_webhook_deliver';

	/** Action Scheduler group of the plugin. */
	public const GROUP = 'wplc';

	/** Seconds one request may take. */
	public const TIMEOUT = 10;

	/** Failed deliveries in a row after which an endpoint is switched off. */
	public const DEFAULT_DISABLE_AFTER = 5;

	/** Error codes stored in the delivery log (with ":detail" where noted). */
	public const ERROR_ADDRESS_REFUSED = 'address_refused';  // Detail: UrlGuard code.
	public const ERROR_SECRET          = 'secret_unreadable';
	public const ERROR_TRANSPORT       = 'transport_error';  // Detail: error of the HTTP API (technical).
	public const ERROR_HTTP_STATUS     = 'http_status';      // Detail: HTTP status.
	public const ERROR_ADDRESS_CHANGED = 'address_mismatch'; // Detail: address cURL connected to.
	public const ERROR_ENDPOINT_GONE   = 'endpoint_deleted';
	public const ERROR_ENDPOINT_OFF    = 'endpoint_off';

	/** All stored error codes. */
	public const ERROR_CODES = array( self::ERROR_ADDRESS_REFUSED, self::ERROR_SECRET, self::ERROR_TRANSPORT, self::ERROR_HTTP_STATUS, self::ERROR_ADDRESS_CHANGED, self::ERROR_ENDPOINT_GONE, self::ERROR_ENDPOINT_OFF );

	/**
	 * Register the listeners and the queue hook.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		foreach ( EventCatalog::hooks() as $hook => $count ) {
			// Late, so the plugin's own listeners (emails, retention) have run.
			add_action( $hook, array( self::class, 'on_contract_action' ), 50, $count );
		}
		add_action( self::DELIVER_HOOK, array( self::class, 'deliver' ), 10, 1 );
	}

	/**
	 * Listener of the contract actions: queue the event for the subscribed endpoints.
	 *
	 * Never throws: a failure is logged and does not disturb the operation that fired the action.
	 *
	 * @param mixed ...$args Arguments of the action.
	 * @return void
	 */
	public static function on_contract_action( ...$args ): void {
		$event = EventCatalog::event_for_hook( (string) current_filter() );
		if ( '' === $event ) {
			return;
		}
		try {
			// Cheap check first: no query at all when no active endpoint wants the event. The index is
			// an autoloaded option (an empty array without endpoints); a missing one is built once.
			$index = get_option( Endpoints::INDEX_OPTION, null );
			if ( null === $index ) {
				Endpoints::refresh_index();
				$index = get_option( Endpoints::INDEX_OPTION, array() );
			}
			if ( ! is_array( $index ) || ! in_array( $event, $index, true ) ) {
				return;
			}
			$data = EventCatalog::data( $event, $args );
			AfterCommit::run(
				static function () use ( $event, $data ): void {
					self::emit( $event, $data );
				}
			);
		} catch ( \Throwable $error ) {
			Log::write( 'error', 'Webhook event could not be queued: ' . $error->getMessage(), array( 'event' => $event ) );
		}
	}

	/**
	 * Queue one event for every active endpoint that subscribed to it (or for the given endpoints).
	 *
	 * @param string             $event        Event name.
	 * @param array<string,mixed> $data         Event data (scalar IDs and codes).
	 * @param array<int,int>|null $endpoint_ids Only these endpoints (test event), null for the subscribers.
	 * @return array<int,int> Delivery IDs.
	 */
	public static function emit( string $event, array $data, ?array $endpoint_ids = null ): array {
		if ( null === $endpoint_ids ) {
			$endpoints = Endpoints::subscribed( $event );
		} else {
			$endpoints = array_values( array_filter( array_map( array( Endpoints::class, 'get' ), $endpoint_ids ) ) );
		}
		if ( ! $endpoints ) {
			return array();
		}
		$event_id = wp_generate_uuid4();
		$body     = Payload::encode( self::envelope( $event_id, $event, $data ) );
		$ids      = array();
		foreach ( $endpoints as $endpoint ) {
			$id = Deliveries::create( (int) $endpoint['id'], $event_id, $event, $body );
			if ( $id > 0 ) {
				$ids[] = $id;
				if ( EventCatalog::TEST_EVENT !== $event ) {
					self::queue( $id, 0 );
				}
			}
		}

		return $ids;
	}

	/**
	 * Envelope of an event after the filter `wplc_webhook_payload`.
	 *
	 * License events carry a summary of the license (product, plan, status, end of the paid
	 * period) as read when the event happened; never a key, an address, a name or payment data.
	 *
	 * @param string              $event_id Event ID.
	 * @param string              $event    Event name.
	 * @param array<string,mixed> $data     Event data.
	 * @return array<string,mixed>
	 */
	public static function envelope( string $event_id, string $event, array $data ): array {
		$license_id = isset( $data['license_id'] ) ? (int) $data['license_id'] : 0;
		if ( $license_id > 0 ) {
			$license = LicenseReader::get( $license_id );
			if ( null !== $license ) {
				$data['license'] = array(
					'product_key' => $license['product_key'],
					'plan_key'    => $license['plan_key'],
					'status'      => $license['status'],
					'expires_at'  => $license['expires_at'],
				);
			}
		}
		$envelope = Payload::envelope( $event_id, $event, EventCatalog::hook( $event ), LicenseEvents::contract_version(), time(), (string) home_url( '/' ), $data );

		/**
		 * Filters the body of an outgoing webhook before it is stored and sent.
		 *
		 * Runs once per event; every endpoint receives the same body. Keep `id`, `event` and
		 * `created_at`. Never add a license key, a secret or more personal data than the receiver
		 * needs: the body is stored in the delivery log until the retention rule removes it.
		 *
		 * @since 0.2.0
		 *
		 * @param array<string,mixed> $envelope Body: id, event, hook, contract_version, webhook_version, created_at, source, data.
		 * @param string              $event    Event name, for example license.status_changed.
		 */
		return self::keep_identity( $envelope, apply_filters( 'wplc_webhook_payload', $envelope, $event ) );
	}

	/**
	 * Take the filtered body, but never a changed `id`, `event` or `created_at`; a filter that
	 * returns no array leaves the body as it was.
	 *
	 * @param array<string,mixed> $envelope Body before the filter.
	 * @param mixed               $filtered Result of the filter.
	 * @return array<string,mixed>
	 */
	private static function keep_identity( array $envelope, $filtered ): array {
		if ( ! is_array( $filtered ) ) {
			return $envelope;
		}

		return array_merge(
			$filtered,
			array(
				'id'         => $envelope['id'],
				'event'      => $envelope['event'],
				'created_at' => $envelope['created_at'],
			)
		);
	}

	/**
	 * Send a test event to one endpoint now and return the delivery.
	 *
	 * The test event (`webhook.test`) is sent once, also to an endpoint that is switched off, is
	 * not retried and does not count towards switching the endpoint off.
	 *
	 * @param int $endpoint_id Endpoint ID.
	 * @return array<string,mixed>|null The delivery after the attempt, null for an unknown endpoint.
	 */
	public static function send_test( int $endpoint_id ): ?array {
		$ids = self::emit(
			EventCatalog::TEST_EVENT,
			array( 'message' => 'Test event. No license changed.' ),
			array( $endpoint_id )
		);
		if ( ! $ids ) {
			return null;
		}
		self::deliver( $ids[0] );

		return Deliveries::get( $ids[0] );
	}

	/**
	 * Queue a finished delivery again (same event ID and body).
	 *
	 * @param int $delivery_id Delivery ID.
	 * @return bool False when it is still queued, being sent, or unknown.
	 */
	public static function redeliver( int $delivery_id ): bool {
		$delivery = Deliveries::get( $delivery_id );
		if ( null === $delivery || ! Deliveries::reset_for_redelivery( $delivery_id ) ) {
			return false;
		}
		self::queue( $delivery_id, 0 );

		return true;
	}

	/**
	 * Make one attempt of a delivery (Action Scheduler job).
	 *
	 * @param mixed $delivery_id Delivery ID.
	 * @return void
	 */
	public static function deliver( $delivery_id ): void {
		$delivery_id = (int) $delivery_id;
		$delivery    = Deliveries::get( $delivery_id );
		if ( null === $delivery ) {
			return;
		}
		$is_test  = EventCatalog::TEST_EVENT === $delivery['event'];
		$endpoint = Endpoints::get( (int) $delivery['endpoint_id'] );
		if ( null === $endpoint ) {
			Deliveries::cancel( $delivery_id, self::ERROR_ENDPOINT_GONE );
			return;
		}
		if ( ! $endpoint['active'] && ! $is_test ) {
			if ( in_array( $delivery['status'], array( Deliveries::PENDING, Deliveries::RETRYING ), true ) ) {
				Deliveries::cancel( $delivery_id, self::ERROR_ENDPOINT_OFF );
			}
			return;
		}
		if ( ! Deliveries::claim( $delivery_id ) ) {
			return; // Already sent, being sent, or finished: a duplicate queue entry.
		}
		$attempt = (int) $delivery['attempts'] + 1;
		$result  = self::send( $endpoint, $delivery, $attempt );

		if ( $result['ok'] ) {
			Deliveries::finish_attempt( $delivery_id, Deliveries::DELIVERED, $result['code'], $result['duration_ms'], '', null );
			if ( ! $is_test ) {
				Endpoints::record_success( (int) $endpoint['id'] );
			}
			return;
		}

		$delay = $is_test ? null : Payload::retry_delay( $attempt, self::retry_delays() );
		if ( null !== $delay ) {
			Deliveries::finish_attempt( $delivery_id, Deliveries::RETRYING, $result['code'], $result['duration_ms'], $result['error'], gmdate( 'Y-m-d H:i:s', time() + $delay ) );
			self::queue( $delivery_id, $delay );
			return;
		}
		Deliveries::finish_attempt( $delivery_id, Deliveries::FAILED, $result['code'], $result['duration_ms'], $result['error'], null );
		if ( $is_test ) {
			return;
		}
		if ( Endpoints::record_failure( (int) $endpoint['id'], self::disable_after() ) ) {
			/**
			 * Fires after a webhook endpoint was switched off because its deliveries failed repeatedly.
			 *
			 * Administrators see a notice; the endpoint stays off until it is switched on again.
			 *
			 * @since 0.2.0
			 *
			 * @param int $endpoint_id Endpoint ID.
			 * @param int $failures    Failed deliveries in a row.
			 */
			do_action( 'wplc/webhook_endpoint_disabled', (int) $endpoint['id'], self::disable_after() );
		}
	}

	/**
	 * Send one attempt.
	 *
	 * @param array<string,mixed> $endpoint Endpoint.
	 * @param array<string,mixed> $delivery Delivery with payload.
	 * @param int                 $attempt  Number of the attempt.
	 * @return array{ok:bool,code:int,duration_ms:int,error:string}
	 */
	private static function send( array $endpoint, array $delivery, int $attempt ): array {
		$checked = UrlGuard::check( (string) $endpoint['url'] );
		if ( UrlGuard::OK !== $checked['error'] ) {
			return self::failure( 0, 0, self::error_code( self::ERROR_ADDRESS_REFUSED, $checked['error'] ) );
		}
		$secret = Endpoints::secret( (int) $endpoint['id'] );
		if ( '' === $secret ) {
			return self::failure( 0, 0, self::ERROR_SECRET );
		}
		$body    = (string) ( $delivery['payload'] ?? '' );
		$version = defined( 'WPLC_VERSION' ) ? (string) WPLC_VERSION : '0';
		$headers = Payload::headers( $secret, (string) $delivery['event_id'], (string) $delivery['event'], time(), $attempt, $body, 'WP-License-Captain-Webhooks/' . $version );
		$url     = (string) $endpoint['url'];
		$pin     = self::pin( $url, $checked['ip'] );
		$seen    = array();
		$watch   = self::watch_address( $seen );

		add_action( 'http_api_curl', $pin, 10, 3 );
		add_action( 'requests-curl.after_request', $watch, 10, 2 );
		$start = microtime( true );
		try {
			$response = wp_safe_remote_post(
				$url,
				array(
					'body'                => $body,
					'headers'             => $headers,
					'timeout'             => self::TIMEOUT,
					'redirection'         => 0,
					'sslverify'           => true,
					'limit_response_size' => 4096,
					'user-agent'          => $headers['User-Agent'],
				)
			);
		} finally {
			remove_action( 'http_api_curl', $pin, 10 );
			remove_action( 'requests-curl.after_request', $watch, 10 );
		}
		$duration = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $response ) ) {
			return self::failure( 0, $duration, self::error_code( self::ERROR_TRANSPORT, $response->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		// Second line of defence behind the pin: the connection must have gone to the checked address.
		$connected = isset( $seen[0] ) ? (string) $seen[0] : '';
		if ( '' !== $connected && '' !== $checked['ip'] && ! UrlGuard::same_ip( $connected, $checked['ip'] ) ) {
			return self::failure( $code, $duration, self::error_code( self::ERROR_ADDRESS_CHANGED, $connected ) );
		}
		if ( $code >= 200 && $code < 300 ) {
			return array(
				'ok'          => true,
				'code'        => $code,
				'duration_ms' => $duration,
				'error'       => '',
			);
		}

		return self::failure( $code, $duration, self::error_code( self::ERROR_HTTP_STATUS, (string) $code ) );
	}

	/**
	 * Stored form of an error: code, or code and technical detail ("code:detail").
	 *
	 * @param string $code   One of the ERROR_* codes.
	 * @param string $detail Technical detail, '' for none.
	 * @return string
	 */
	public static function error_code( string $code, string $detail = '' ): string {
		return '' === $detail ? $code : $code . ':' . $detail;
	}

	/**
	 * Split a stored error into code and detail; code '' for an old row with a free text.
	 *
	 * @param string $stored Stored error.
	 * @return array{code:string,detail:string}
	 */
	public static function parse_error( string $stored ): array {
		if ( preg_match( '/^([a-z_]+)(?::(.*))?$/s', $stored, $match ) && in_array( $match[1], self::ERROR_CODES, true ) ) {
			return array(
				'code'   => $match[1],
				'detail' => isset( $match[2] ) ? (string) $match[2] : '',
			);
		}

		return array(
			'code'   => '',
			'detail' => $stored,
		);
	}

	/**
	 * Requests hook that notes the address cURL connected to (curl_getinfo "primary_ip").
	 *
	 * Hooked only around the one request of an attempt (no redirects), so every call belongs to it.
	 *
	 * @param array<int,string> $seen Receives the address.
	 * @return callable
	 */
	private static function watch_address( array &$seen ): callable {
		return static function ( $headers = '', $info = array() ) use ( &$seen ): void {
			if ( is_array( $info ) && isset( $info['primary_ip'] ) && '' !== (string) $info['primary_ip'] ) {
				$seen[0] = (string) $info['primary_ip'];
			}
		};
	}

	/**
	 * cURL hook that pins the request to the address UrlGuard checked (no second DNS answer).
	 *
	 * @param string $url URL.
	 * @param string $ip  Checked address.
	 * @return callable
	 */
	private static function pin( string $url, string $ip ): callable {
		$host = UrlGuard::host( $url );

		return static function ( $handle, $args = array(), $request_url = '' ) use ( $host, $ip, $url ): void {
			if ( '' === $ip || UrlGuard::is_ip( $host ) || $request_url !== $url || ! defined( 'CURLOPT_RESOLVE' ) ) {
				return;
			}
			$address = false !== strpos( $ip, ':' ) ? '[' . $ip . ']' : $ip;
			curl_setopt( $handle, CURLOPT_RESOLVE, array( $host . ':443:' . $address ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- pins the checked address inside the WordPress HTTP API.
		};
	}

	/**
	 * Failed attempt.
	 *
	 * @param int    $code     HTTP status or 0.
	 * @param int    $duration Duration in milliseconds.
	 * @param string $error    Error.
	 * @return array{ok:bool,code:int,duration_ms:int,error:string}
	 */
	private static function failure( int $code, int $duration, string $error ): array {
		return array(
			'ok'          => false,
			'code'        => $code,
			'duration_ms' => $duration,
			'error'       => $error,
		);
	}

	/**
	 * Queue an attempt now or after a delay (Action Scheduler; WP-Cron without it).
	 *
	 * @param int $delivery_id Delivery ID.
	 * @param int $delay       Seconds.
	 * @return void
	 */
	private static function queue( int $delivery_id, int $delay ): void {
		$args = array( $delivery_id );
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			if ( $delay > 0 ) {
				Requirements::schedule_single_action( time() + $delay, self::DELIVER_HOOK, $args, self::GROUP );
			} else {
				Requirements::enqueue_async_action( self::DELIVER_HOOK, $args, self::GROUP );
			}
			return;
		}
		if ( ! wp_next_scheduled( self::DELIVER_HOOK, $args ) ) {
			wp_schedule_single_event( time() + max( 0, $delay ), self::DELIVER_HOOK, $args );
		}
	}

	/**
	 * Retry delays after the filter.
	 *
	 * @return array<int,int>
	 */
	public static function retry_delays(): array {
		/**
		 * Filters the seconds between the attempts of a webhook delivery.
		 *
		 * One entry per retry: the default (60, 300, 1800, 7200) gives five attempts in all.
		 * At most nine retries of up to one day each; an empty list means one attempt only.
		 *
		 * @since 0.2.0
		 *
		 * @param array<int,int> $delays Seconds after attempt 1, 2, ...
		 */
		return Payload::sanitize_delays( apply_filters( 'wplc_webhook_retry_delays', Payload::RETRY_DELAYS ) );
	}

	/**
	 * Failed deliveries in a row after which an endpoint is switched off, after the filter.
	 *
	 * @return int 0 = never.
	 */
	public static function disable_after(): int {
		/**
		 * Filters after how many failed deliveries in a row an endpoint is switched off.
		 *
		 * A delivery counts as failed after its last attempt. 0 switches the rule off.
		 *
		 * @since 0.2.0
		 *
		 * @param int $failures Default 5.
		 */
		return max( 0, min( 1000, (int) apply_filters( 'wplc_webhook_disable_after_failures', self::DEFAULT_DISABLE_AFTER ) ) );
	}
}
