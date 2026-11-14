<?php
/**
 * Stripe webhook signature verification and idempotent event inbox.
 *
 * @package WPLC
 */

namespace WPLC\Api\V1;

use WPLC\Billing\RenewalOrder;
use WPLC\Gateway\StripeRecurringAdapter;
use WPLC\Infrastructure\Database\Schema;
use WPLC\License\LicenseLifecycle;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Accepts only signed Stripe PaymentIntent lifecycle, dispute and fraud-warning events for later reconciliation. */
final class StripeWebhook {
	const MAX_BODY_BYTES = 1048576;
	const SIGNATURE_TOLERANCE = 300;

	/** Register the public Stripe callback route. */
	public static function register_routes(): void {
		register_rest_route(
			'wplc/v1',
			'/webhooks/stripe',
			array(
				'methods' => 'POST',
				'callback' => array( self::class, 'receive' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Event types that are stored and reconciled. Everything else is acknowledged and dropped.
	 *
	 * @var string[]
	 */
	public const PAYMENT_INTENT_EVENTS = array( 'payment_intent.succeeded', 'payment_intent.payment_failed', 'payment_intent.canceled' );

	/**
	 * Events resolved to an order through the charge or PaymentIntent they concern.
	 *
	 * @var string[]
	 */
	public const CHARGE_EVENTS = array( 'charge.dispute.created', 'charge.dispute.closed', 'charge.dispute.funds_reinstated', 'radar.early_fraud_warning.created' );

	/**
	 * Verify, minimize, and deduplicate a Stripe event.
	 *
	 * Error messages are fixed English text on purpose (B-148): the reader is
	 * Stripe's delivery log and monitoring, and `code` is the contract.
	 */
	public static function receive( \WP_REST_Request $request ) {
		$secrets = self::secrets();
		if ( ! $secrets ) {
			return new \WP_Error( 'wplc_stripe_webhook_unconfigured', 'Stripe webhook verification is not configured.', array( 'status' => 503 ) );
		}

		$body = (string) $request->get_body();
		if ( '' === $body || strlen( $body ) > self::MAX_BODY_BYTES ) {
			return new \WP_Error( 'wplc_stripe_webhook_body_invalid', 'The Stripe webhook body is missing or too large.', array( 'status' => 400 ) );
		}
		$secret_mode = self::matching_secret_mode( $body, (string) $request->get_header( 'stripe-signature' ), $secrets, time() );
		if ( null === $secret_mode ) {
			self::heartbeat( 'stripe_webhook_rejected', true );
			return new \WP_Error( 'wplc_stripe_signature_invalid', 'The Stripe webhook signature is invalid or expired.', array( 'status' => 400 ) );
		}
		// A-72, B-148: the diagnostics page shows when the last correctly signed event arrived.
		self::heartbeat( 'stripe_webhook' );

		$event = json_decode( $body, true );
		if ( ! is_array( $event ) || empty( $event['id'] ) || empty( $event['type'] ) || ! is_array( $event['data']['object'] ?? null ) ) {
			return new \WP_Error( 'wplc_stripe_event_invalid', 'The Stripe webhook event is malformed.', array( 'status' => 400 ) );
		}
		if ( array_key_exists( 'livemode', $event ) ) {
			$event_mode = $event['livemode'] ? 'live' : 'test';
			if ( '' !== $secret_mode && $secret_mode !== $event_mode ) {
				// Signed with the secret of the other mode: not an event of the endpoint this secret belongs to.
				return new \WP_Error( 'wplc_stripe_event_mode_mismatch', 'The Stripe event mode does not match the mode of the signing secret.', array( 'status' => 400 ) );
			}
			self::heartbeat( 'stripe_webhook_' . $event_mode );
			if ( 'test' === $event_mode && 'live' === StripeRecurringAdapter::mode() ) {
				// A test payment must never extend a license of a shop that sells for real money.
				return new \WP_REST_Response(
					array(
						'received' => true,
						'ignored' => true,
						'reason' => 'test_event_in_live_mode',
					),
					200
				);
			}
		}
		$type = sanitize_text_field( (string) $event['type'] );
		if ( ! in_array( $type, self::PAYMENT_INTENT_EVENTS, true ) && ! in_array( $type, self::CHARGE_EVENTS, true ) ) {
			return new \WP_REST_Response(
				array(
					'received' => true,
					'ignored' => true,
				),
				200
			);
		}

		$object = $event['data']['object'];
		if ( in_array( $type, self::CHARGE_EVENTS, true ) ) {
			$payload = 'radar.early_fraud_warning.created' === $type ? self::fraud_warning_payload( $object ) : self::dispute_payload( $object );
			if ( null === $payload ) {
				// Disputes of ordinary shop payments are not ours. Acknowledge them so Stripe does not retry or disable the endpoint.
				return new \WP_REST_Response(
					array(
						'received' => true,
						'ignored' => true,
					),
					200
				);
			}
		} else {
			$metadata = is_array( $object['metadata'] ?? null ) ? $object['metadata'] : array();
			$payload = array(
				'payment_intent_id' => sanitize_text_field( (string) ( $object['id'] ?? '' ) ),
				'payment_status' => sanitize_key( (string) ( $object['status'] ?? '' ) ),
				'amount_received' => absint( $object['amount_received'] ?? $object['amount'] ?? 0 ),
				'currency' => sanitize_key( (string) ( $object['currency'] ?? '' ) ),
				'renewal_id' => absint( $metadata['wplc_renewal_id'] ?? 0 ),
				'license_id' => absint( $metadata['wplc_license_id'] ?? 0 ),
				'order_id' => absint( $metadata['order_id'] ?? 0 ),
			);
			$charge_id = self::charge_id_of( $object );
			if ( '' !== $charge_id ) {
				// B-60: the order's transaction ID is the charge, as the gateway stores it.
				$payload['charge_id'] = $charge_id;
			}
			if ( 0 === $payload['renewal_id'] && 0 === $payload['license_id'] ) {
				// Ordinary shop payments carry no WPLC metadata. Acknowledge them so Stripe does not retry or disable the endpoint.
				return new \WP_REST_Response(
					array(
						'received' => true,
						'ignored' => true,
					),
					200
				);
			}
			$site = (string) ( $metadata['wplc_site'] ?? '' );
			if ( '' !== $site && ! hash_equals( StripeRecurringAdapter::site_id(), $site ) ) {
				// B-29: a renewal of another site that shares this Stripe account (multisite, staging copy).
				return new \WP_REST_Response(
					array(
						'received' => true,
						'ignored' => true,
						'reason' => 'other_site',
					),
					200
				);
			}
			if ( 0 !== strpos( $payload['payment_intent_id'], 'pi_' ) || 0 === $payload['renewal_id'] || 0 === $payload['license_id'] || 0 === $payload['order_id'] ) {
				return new \WP_Error( 'wplc_stripe_event_metadata_invalid', 'The Stripe event is missing required renewal metadata.', array( 'status' => 400 ) );
			}
			if ( 'payment_intent.succeeded' === $type && 'succeeded' !== $payload['payment_status'] ) {
				return new \WP_Error( 'wplc_stripe_event_status_invalid', 'The successful Stripe event has an unexpected payment status.', array( 'status' => 400 ) );
			}
		}
		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES );
		if ( false === $json ) {
			return new \WP_Error( 'wplc_stripe_event_encoding_failed', 'The Stripe event could not be stored.', array( 'status' => 500 ) );
		}
		// A-113: Stripe may deliver one event again with a slightly different body (API version,
		// field order, new fields). Only what is stored and acted on decides whether it is the same event.
		$payload_hash = hash( 'sha256', $json );

		global $wpdb;
		$tables = Schema::table_names();
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$tables['event_inbox']} (source,source_event_id,event_type,payload_hash,payload_json,received_at,outcome) VALUES (%s,%s,%s,%s,%s,%s,%s)",
				'stripe',
				sanitize_text_field( (string) $event['id'] ),
				$type,
				$payload_hash,
				$json,
				gmdate( 'Y-m-d H:i:s' ),
				'pending_reconciliation'
			)
		);
		if ( false === $inserted ) {
			return new \WP_Error( 'wplc_stripe_event_store_failed', 'The Stripe event could not be recorded.', array( 'status' => 500 ) );
		}
		if ( 0 === (int) $inserted ) {
			$stored_hash = (string) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT payload_hash FROM {$tables['event_inbox']} WHERE source = %s AND source_event_id = %s LIMIT 1",
					'stripe',
					sanitize_text_field( (string) $event['id'] )
				)
			);
			// Rows stored before A-113 carry the hash of the raw body; that delivery is accepted as well.
			if ( ! hash_equals( $stored_hash, $payload_hash ) && ! hash_equals( $stored_hash, hash( 'sha256', $body ) ) ) {
				return new \WP_Error( 'wplc_stripe_event_conflict', 'The Stripe event identifier was already used for a different payload.', array( 'status' => 409 ) );
			}
			return new \WP_REST_Response(
				array(
					'received' => true,
					'duplicate' => true,
				),
				200
			);
		}

		$inbox_id = (int) $wpdb->insert_id;
		do_action( 'wplc/stripe_webhook_received', $inbox_id, $type, $payload );
		return new \WP_REST_Response(
			array(
				'received' => true,
				'reconciliation' => 'pending',
			),
			200
		);
	}

	/**
	 * Build the minimized payload of a dispute that concerns a license payment.
	 *
	 * @param array<string,mixed> $dispute Stripe dispute object.
	 * @return array<string,mixed>|null Null when the dispute has no WPLC relation.
	 */
	private static function dispute_payload( array $dispute ): ?array {
		$intent = $dispute['payment_intent'] ?? '';
		$charge = $dispute['charge'] ?? '';
		// Both fields are IDs, or objects when the event was sent expanded.
		$intent_id = sanitize_text_field( (string) ( is_array( $intent ) ? ( $intent['id'] ?? '' ) : $intent ) );
		$charge_id = sanitize_text_field( (string) ( is_array( $charge ) ? ( $charge['id'] ?? '' ) : $charge ) );
		$order_id = self::order_id_for_payment( $intent_id, $charge_id );
		if ( ! $order_id || ! LicenseLifecycle::license_ids_for_order( $order_id ) ) {
			return null;
		}

		return array(
			'dispute_id' => sanitize_text_field( (string) ( $dispute['id'] ?? '' ) ),
			'payment_intent_id' => $intent_id,
			'charge_id' => $charge_id,
			'reason' => sanitize_key( (string) ( $dispute['reason'] ?? '' ) ),
			'order_id' => $order_id,
			'dispute_status' => sanitize_key( (string) ( $dispute['status'] ?? '' ) ),
		);
	}

	/**
	 * Build the minimized payload of an early fraud warning that concerns a license payment.
	 *
	 * @param array<string,mixed> $warning Stripe `radar.early_fraud_warning` object.
	 * @return array<string,mixed>|null Null when the warning has no WPLC relation.
	 */
	private static function fraud_warning_payload( array $warning ): ?array {
		$intent = $warning['payment_intent'] ?? '';
		$charge = $warning['charge'] ?? '';
		$intent_id = sanitize_text_field( (string) ( is_array( $intent ) ? ( $intent['id'] ?? '' ) : $intent ) );
		$charge_id = sanitize_text_field( (string) ( is_array( $charge ) ? ( $charge['id'] ?? '' ) : $charge ) );
		$order_id = self::order_id_for_payment( $intent_id, $charge_id );
		if ( ! $order_id || ! LicenseLifecycle::license_ids_for_order( $order_id ) ) {
			return null;
		}

		return array(
			'warning_id' => sanitize_text_field( (string) ( $warning['id'] ?? '' ) ),
			'payment_intent_id' => $intent_id,
			'charge_id' => $charge_id,
			'fraud_type' => sanitize_key( (string) ( $warning['fraud_type'] ?? '' ) ),
			'order_id' => $order_id,
		);
	}

	/**
	 * Charge ID of a PaymentIntent object from an event.
	 *
	 * @param array<string,mixed> $intent PaymentIntent.
	 * @return string
	 */
	private static function charge_id_of( array $intent ): string {
		$charge = $intent['latest_charge'] ?? '';
		if ( is_array( $charge ) ) {
			$charge = $charge['id'] ?? '';
		}
		if ( '' === (string) $charge && is_array( $intent['charges']['data'] ?? null ) && $intent['charges']['data'] ) {
			$last = end( $intent['charges']['data'] );
			$charge = is_array( $last ) ? ( $last['id'] ?? '' ) : '';
		}
		$charge = sanitize_text_field( (string) $charge );

		return preg_match( '/^(ch|py)_[A-Za-z0-9_]+$/', $charge ) ? $charge : '';
	}

	/**
	 * Signing secrets this site accepts (B-29, B-148).
	 *
	 * - `WPLC_STRIPE_WEBHOOK_SECRET`: secret of the live endpoint. While no test
	 *   secret is defined it is accepted for events of both modes, as before.
	 * - `WPLC_STRIPE_WEBHOOK_SECRET_TEST`: secret of the test-mode endpoint.
	 * - `WPLC_STRIPE_WEBHOOK_SECRETS`: per site of a multisite network, keyed by
	 *   blog ID. A value is the live secret, or an array with `live` and `test`.
	 *   Once defined, a site without an entry accepts nothing: every site has
	 *   its own endpoint URL and therefore its own secret.
	 *
	 * @return array<int,array{secret:string,mode:string}> mode is `live`, `test` or empty for both.
	 */
	private static function secrets(): array {
		$live = defined( 'WPLC_STRIPE_WEBHOOK_SECRET' ) && is_string( WPLC_STRIPE_WEBHOOK_SECRET ) ? (string) WPLC_STRIPE_WEBHOOK_SECRET : '';
		$test = defined( 'WPLC_STRIPE_WEBHOOK_SECRET_TEST' ) && is_string( WPLC_STRIPE_WEBHOOK_SECRET_TEST ) ? (string) WPLC_STRIPE_WEBHOOK_SECRET_TEST : '';
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
		if ( defined( 'WPLC_STRIPE_WEBHOOK_SECRETS' ) && is_array( WPLC_STRIPE_WEBHOOK_SECRETS ) ) {
			$entry = WPLC_STRIPE_WEBHOOK_SECRETS[ $blog_id ] ?? '';
			$live = is_array( $entry ) ? (string) ( $entry['live'] ?? '' ) : ( is_string( $entry ) ? $entry : '' );
			$test = is_array( $entry ) ? (string) ( $entry['test'] ?? '' ) : '';
		}
		$configured = array(
			'live' => $live,
			'test' => $test,
		);
		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filters the Stripe webhook signing secrets of the current site.
			 *
			 * @param array{live:string,test:string} $configured Secrets from the constants.
			 * @param int                            $blog_id Current blog ID.
			 */
			$filtered = apply_filters( 'wplc_stripe_webhook_secrets', $configured, $blog_id );
			$configured = is_array( $filtered ) ? $filtered : $configured;
		}

		return self::secret_list( (string) ( $configured['live'] ?? '' ), (string) ( $configured['test'] ?? '' ) );
	}

	/**
	 * Whether this site has a signing secret, for settings and diagnostics screens.
	 *
	 * @return bool
	 */
	public static function is_configured(): bool {
		return array() !== self::secrets();
	}

	/**
	 * Turn the configured secrets into the list the verifier checks. Pure.
	 *
	 * @param string $live Secret of the live endpoint.
	 * @param string $test Secret of the test endpoint.
	 * @return array<int,array{secret:string,mode:string}>
	 */
	public static function secret_list( string $live, string $test ): array {
		$list = array();
		if ( '' !== $live ) {
			$list[] = array(
				'secret' => $live,
				'mode' => '' === $test ? '' : 'live',
			);
		}
		if ( '' !== $test ) {
			$list[] = array(
				'secret' => $test,
				'mode' => 'test',
			);
		}

		return $list;
	}

	/**
	 * Record a heartbeat for the diagnostics page.
	 *
	 * @param string $name Heartbeat name.
	 * @param bool   $throttle Write at most once a minute (for unauthenticated requests).
	 * @return void
	 */
	private static function heartbeat( string $name, bool $throttle = false ): void {
		if ( ! class_exists( '\\WPLC\\Admin\\Diagnostics' ) || ! function_exists( 'update_option' ) ) {
			return;
		}
		if ( $throttle ) {
			if ( get_transient( 'wplc_hb_' . $name ) ) {
				return;
			}
			set_transient( 'wplc_hb_' . $name, 1, MINUTE_IN_SECONDS );
		}
		\WPLC\Admin\Diagnostics::mark_run( $name );
	}

	/**
	 * Resolve a Stripe payment to its WooCommerce order.
	 *
	 * Renewal charges store the PaymentIntent in `_wplc_payment_intent_id`; the
	 * WooCommerce Stripe gateway stores it in `_stripe_intent_id` and uses the
	 * charge (or intent) as the order's transaction ID.
	 *
	 * @param string $intent_id PaymentIntent ID.
	 * @param string $charge_id Charge ID.
	 * @return int Order ID, or zero when unknown or ambiguous.
	 */
	private static function order_id_for_payment( string $intent_id, string $charge_id ): int {
		$found = array();
		if ( 0 === strpos( $intent_id, 'pi_' ) ) {
			foreach ( array( RenewalOrder::META_PAYMENT_INTENT, '_stripe_intent_id' ) as $meta_key ) {
				$ids = RenewalOrder::find_ids_by_meta( $meta_key, $intent_id, 2 );
				foreach ( is_array( $ids ) ? $ids : array() as $id ) {
					$order = wc_get_order( $id );
					// Re-check: an order storage that ignores the meta arguments must not produce a match.
					if ( $order && $intent_id === (string) $order->get_meta( $meta_key, true ) ) {
						$found[ (int) $id ] = true;
					}
				}
			}
		}
		if ( ! $found && function_exists( 'wc_get_orders' ) ) {
			foreach ( array_filter( array( $charge_id, $intent_id ) ) as $transaction_id ) {
				$ids = wc_get_orders(
					array(
						'limit' => 2,
						'return' => 'ids',
						'type' => 'shop_order',
						'transaction_id' => $transaction_id,
					)
				);
				foreach ( is_array( $ids ) ? $ids : array() as $id ) {
					$order = wc_get_order( $id );
					if ( $order && $transaction_id === (string) $order->get_transaction_id() ) {
						$found[ (int) $id ] = true;
					}
				}
			}
		}

		return 1 === count( $found ) ? (int) key( $found ) : 0;
	}

	/** Verify Stripe's timestamped HMAC-SHA256 signature with a five-minute tolerance. */
	private static function verify_signature( string $body, string $header ): bool {
		return null !== self::matching_secret_mode( $body, $header, self::secrets(), time() );
	}

	/**
	 * Find the configured secret a signature header was made with. Pure.
	 *
	 * @param string                                      $body Raw request body.
	 * @param string                                      $header `Stripe-Signature` header.
	 * @param array<int,array{secret:string,mode:string}> $secrets Result of secret_list().
	 * @param int                                         $now Current Unix time.
	 * @return string|null Mode of the matching secret (`live`, `test`, empty for both), null when none matches.
	 */
	public static function matching_secret_mode( string $body, string $header, array $secrets, int $now ): ?string {
		$timestamp = 0;
		$signatures = array();
		foreach ( explode( ',', $header ) as $part ) {
			$pair = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $pair ) ) {
				continue;
			}
			if ( 't' === $pair[0] && ctype_digit( $pair[1] ) ) {
				$timestamp = (int) $pair[1];
			} elseif ( 'v1' === $pair[0] && preg_match( '/^[a-f0-9]{64}$/i', $pair[1] ) ) {
				$signatures[] = strtolower( $pair[1] );
			}
		}
		if ( ! $timestamp || abs( $now - $timestamp ) > self::SIGNATURE_TOLERANCE || ! $signatures ) {
			return null;
		}
		$matched = null;
		foreach ( $secrets as $candidate ) {
			$secret = (string) ( $candidate['secret'] ?? '' );
			if ( '' === $secret ) {
				continue;
			}
			$expected = hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
			foreach ( $signatures as $signature ) {
				// No early exit: every candidate is compared, whichever matches.
				if ( hash_equals( $expected, $signature ) && null === $matched ) {
					$matched = (string) ( $candidate['mode'] ?? '' );
				}
			}
		}

		return $matched;
	}
}
