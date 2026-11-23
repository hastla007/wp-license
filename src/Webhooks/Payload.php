<?php
/**
 * Envelope, signature and retry schedule of outgoing webhooks.
 *
 * @package WPLC
 */

namespace WPLC\Webhooks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure helpers (no WordPress functions) for the webhook format described in docs/WEBHOOKS.md.
 *
 * The body is JSON:
 *
 *     { "id": "<event id>", "event": "license.status_changed", "hook": "wplc/license_status_changed",
 *       "contract_version": "1.0.0", "webhook_version": 1, "created_at": "2026-11-23T10:00:00Z",
 *       "source": "https://shop.example", "data": { ... } }
 *
 * The signature header is `X-WPLC-Signature: v1=<hex HMAC-SHA256 of "<timestamp>.<body>">` with the
 * endpoint secret as the key and the value of `X-WPLC-Timestamp` (Unix seconds of the attempt).
 */
final class Payload {

	/** Version of the webhook envelope (independent of the hook contract version). */
	public const WEBHOOK_VERSION = 1;

	/** Header with the event ID (the same for every attempt and for a redelivery). */
	public const HEADER_ID = 'X-WPLC-Event-Id';

	/** Header with the event name. */
	public const HEADER_EVENT = 'X-WPLC-Event';

	/** Header with the Unix time of the attempt; part of the signed string. */
	public const HEADER_TIMESTAMP = 'X-WPLC-Timestamp';

	/** Header with the signature. */
	public const HEADER_SIGNATURE = 'X-WPLC-Signature';

	/** Header with the number of the attempt (1 for the first). */
	public const HEADER_ATTEMPT = 'X-WPLC-Attempt';

	/** Seconds to wait after attempt 1, 2, 3 and 4 before the next one (five attempts in all). */
	public const RETRY_DELAYS = array( 60, 300, 1800, 7200 );

	/**
	 * Build the envelope of one event.
	 *
	 * @param string              $event_id         Event ID (UUID).
	 * @param string              $event            Event name.
	 * @param string              $hook             Action of the hook contract ('' for the test event).
	 * @param string              $contract_version Version of the hook contract.
	 * @param int                 $time             Unix time of the event.
	 * @param string              $source           Address of the shop that sends the event.
	 * @param array<string,mixed> $data             Event data.
	 * @return array<string,mixed>
	 */
	public static function envelope( string $event_id, string $event, string $hook, string $contract_version, int $time, string $source, array $data ): array {
		return array(
			'id'               => $event_id,
			'event'            => $event,
			'hook'             => $hook,
			'contract_version' => $contract_version,
			'webhook_version'  => self::WEBHOOK_VERSION,
			'created_at'       => gmdate( 'Y-m-d\TH:i:s\Z', $time ),
			'source'           => $source,
			'data'             => $data,
		);
	}

	/**
	 * JSON body of an envelope (unescaped slashes and Unicode, so receivers see readable values).
	 *
	 * @param array<string,mixed> $envelope Envelope.
	 * @return string
	 */
	public static function encode( array $envelope ): string {
		$json = json_encode( $envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR );

		return false === $json ? '{}' : $json;
	}

	/**
	 * Signature header value for a body.
	 *
	 * @param string $secret    Endpoint secret.
	 * @param int    $timestamp Unix time of the attempt (value of X-WPLC-Timestamp).
	 * @param string $body      Raw request body.
	 * @return string "v1=<64 hex characters>"
	 */
	public static function signature( string $secret, int $timestamp, string $body ): string {
		return 'v1=' . hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
	}

	/**
	 * Verify a received signature the way a receiver should (reference implementation, used by the tests).
	 *
	 * @param string $secret    Endpoint secret.
	 * @param string $timestamp Value of X-WPLC-Timestamp.
	 * @param string $body      Raw request body.
	 * @param string $header    Value of X-WPLC-Signature.
	 * @param int    $now       Unix time of the receiver.
	 * @param int    $tolerance Largest accepted age of the timestamp in seconds.
	 * @return bool
	 */
	public static function verify( string $secret, string $timestamp, string $body, string $header, int $now, int $tolerance = 300 ): bool {
		if ( '' === $secret || ! preg_match( '/^\d{1,12}$/', $timestamp ) || abs( $now - (int) $timestamp ) > $tolerance ) {
			return false;
		}

		return hash_equals( self::signature( $secret, (int) $timestamp, $body ), trim( $header ) );
	}

	/**
	 * Request headers of one attempt.
	 *
	 * @param string $secret    Endpoint secret.
	 * @param string $event_id  Event ID.
	 * @param string $event     Event name.
	 * @param int    $timestamp Unix time of the attempt.
	 * @param int    $attempt   Number of the attempt (1 for the first).
	 * @param string $body      Raw request body.
	 * @param string $user_agent User agent.
	 * @return array<string,string>
	 */
	public static function headers( string $secret, string $event_id, string $event, int $timestamp, int $attempt, string $body, string $user_agent ): array {
		return array(
			'Content-Type'         => 'application/json; charset=utf-8',
			'User-Agent'           => $user_agent,
			self::HEADER_ID        => $event_id,
			self::HEADER_EVENT     => $event,
			self::HEADER_TIMESTAMP => (string) $timestamp,
			self::HEADER_ATTEMPT   => (string) max( 1, $attempt ),
			self::HEADER_SIGNATURE => self::signature( $secret, $timestamp, $body ),
		);
	}

	/**
	 * Seconds until the next attempt after a failed one, or null when no attempt is left.
	 *
	 * @param int             $attempt Number of the attempt that failed (1 for the first).
	 * @param array<int,int>  $delays  Delays after attempt 1, 2, ... (the filtered RETRY_DELAYS).
	 * @return int|null
	 */
	public static function retry_delay( int $attempt, array $delays = self::RETRY_DELAYS ): ?int {
		$delays = array_values( $delays );
		if ( $attempt < 1 || ! isset( $delays[ $attempt - 1 ] ) ) {
			return null;
		}

		return max( 1, (int) $delays[ $attempt - 1 ] );
	}

	/**
	 * Clean a filtered list of retry delays: at most nine positive integers up to one day each.
	 *
	 * @param mixed $delays Filtered value.
	 * @return array<int,int>
	 */
	public static function sanitize_delays( $delays ): array {
		if ( ! is_array( $delays ) ) {
			return self::RETRY_DELAYS;
		}
		$clean = array();
		foreach ( $delays as $delay ) {
			if ( is_numeric( $delay ) && (int) $delay > 0 ) {
				$clean[] = min( 86400, (int) $delay );
			}
		}

		return array_slice( $clean, 0, 9 );
	}

	/**
	 * Short, single-line error text for the delivery log (at most 190 characters, no control characters).
	 *
	 * @param string $error Error text.
	 * @return string
	 */
	public static function short_error( string $error ): string {
		$error = trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $error ) );

		return function_exists( 'mb_substr' ) ? mb_substr( $error, 0, 190 ) : substr( $error, 0, 190 );
	}

	/**
	 * Generate a new endpoint secret: "whsec_" and 48 hex characters (192 bits).
	 *
	 * @return string
	 */
	public static function generate_secret(): string {
		return 'whsec_' . bin2hex( random_bytes( 24 ) );
	}
}
