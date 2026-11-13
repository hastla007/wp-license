<?php
/**
 * Stripe webhook signature verification and idempotent event inbox.
 *
 * @package WPLC
 */

namespace WPLC\Api\V1;

use WPLC\Billing\RenewalOrder;
use WPLC\Infrastructure\Database\Schema;
use WPLC\License\LicenseLifecycle;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Accepts only signed Stripe PaymentIntent lifecycle and dispute events for later reconciliation. */
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

	/** Verify, minimize, and deduplicate a Stripe event. */
	public static function receive( \WP_REST_Request $request ) {
		if ( ! defined( 'WPLC_STRIPE_WEBHOOK_SECRET' ) || '' === (string) WPLC_STRIPE_WEBHOOK_SECRET ) {
			return new \WP_Error( 'wplc_stripe_webhook_unconfigured', __( 'Stripe webhook verification is not configured.', 'wp-license' ), array( 'status' => 503 ) );
		}

		$body = (string) $request->get_body();
		if ( '' === $body || strlen( $body ) > self::MAX_BODY_BYTES ) {
			return new \WP_Error( 'wplc_stripe_webhook_body_invalid', __( 'The Stripe webhook body is missing or too large.', 'wp-license' ), array( 'status' => 400 ) );
		}
		if ( ! self::verify_signature( $body, (string) $request->get_header( 'stripe-signature' ) ) ) {
			return new \WP_Error( 'wplc_stripe_signature_invalid', __( 'The Stripe webhook signature is invalid or expired.', 'wp-license' ), array( 'status' => 400 ) );
		}

		$event = json_decode( $body, true );
		if ( ! is_array( $event ) || empty( $event['id'] ) || empty( $event['type'] ) || ! is_array( $event['data']['object'] ?? null ) ) {
			return new \WP_Error( 'wplc_stripe_event_invalid', __( 'The Stripe webhook event is malformed.', 'wp-license' ), array( 'status' => 400 ) );
		}
		$type = sanitize_text_field( (string) $event['type'] );
		if ( ! in_array( $type, array( 'payment_intent.succeeded', 'payment_intent.payment_failed', 'charge.dispute.created' ), true ) ) {
			return new \WP_REST_Response( array( 'received' => true, 'ignored' => true ), 200 );
		}

		$object = $event['data']['object'];
		if ( 'charge.dispute.created' === $type ) {
			$payload = self::dispute_payload( $object );
			if ( null === $payload ) {
				// Disputes of ordinary shop payments are not ours. Acknowledge them so Stripe does not retry or disable the endpoint.
				return new \WP_REST_Response( array( 'received' => true, 'ignored' => true ), 200 );
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
			if ( 0 === $payload['renewal_id'] && 0 === $payload['license_id'] ) {
				// Ordinary shop payments carry no WPLC metadata. Acknowledge them so Stripe does not retry or disable the endpoint.
				return new \WP_REST_Response( array( 'received' => true, 'ignored' => true ), 200 );
			}
			if ( 0 !== strpos( $payload['payment_intent_id'], 'pi_' ) || 0 === $payload['renewal_id'] || 0 === $payload['license_id'] || 0 === $payload['order_id'] ) {
				return new \WP_Error( 'wplc_stripe_event_metadata_invalid', __( 'The Stripe event is missing required renewal metadata.', 'wp-license' ), array( 'status' => 400 ) );
			}
			if ( 'payment_intent.succeeded' === $type && 'succeeded' !== $payload['payment_status'] ) {
				return new \WP_Error( 'wplc_stripe_event_status_invalid', __( 'The successful Stripe event has an unexpected payment status.', 'wp-license' ), array( 'status' => 400 ) );
			}
		}
		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES );
		if ( false === $json ) {
			return new \WP_Error( 'wplc_stripe_event_encoding_failed', __( 'The Stripe event could not be stored.', 'wp-license' ), array( 'status' => 500 ) );
		}

		global $wpdb;
		$tables = Schema::table_names();
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$tables['event_inbox']} (source,source_event_id,event_type,payload_hash,payload_json,received_at,outcome) VALUES (%s,%s,%s,%s,%s,%s,%s)",
				'stripe',
				sanitize_text_field( (string) $event['id'] ),
				$type,
				hash( 'sha256', $body ),
				$json,
				gmdate( 'Y-m-d H:i:s' ),
				'pending_reconciliation'
			)
		);
		if ( false === $inserted ) {
			return new \WP_Error( 'wplc_stripe_event_store_failed', __( 'The Stripe event could not be recorded.', 'wp-license' ), array( 'status' => 500 ) );
		}
		if ( 0 === (int) $inserted ) {
			$stored_hash = (string) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT payload_hash FROM {$tables['event_inbox']} WHERE source = %s AND source_event_id = %s LIMIT 1",
					'stripe',
					sanitize_text_field( (string) $event['id'] )
				)
			);
			if ( ! hash_equals( $stored_hash, hash( 'sha256', $body ) ) ) {
				return new \WP_Error( 'wplc_stripe_event_conflict', __( 'The Stripe event identifier was already used for a different payload.', 'wp-license' ), array( 'status' => 409 ) );
			}
			return new \WP_REST_Response( array( 'received' => true, 'duplicate' => true ), 200 );
		}

		$inbox_id = (int) $wpdb->insert_id;
		do_action( 'wplc/stripe_webhook_received', $inbox_id, $type, $payload );
		return new \WP_REST_Response( array( 'received' => true, 'reconciliation' => 'pending' ), 200 );
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
		);
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
				$ids = wc_get_orders( array( 'limit' => 2, 'return' => 'ids', 'type' => 'shop_order', 'transaction_id' => $transaction_id ) );
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
		if ( ! $timestamp || abs( time() - $timestamp ) > self::SIGNATURE_TOLERANCE || ! $signatures ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $timestamp . '.' . $body, (string) WPLC_STRIPE_WEBHOOK_SECRET );
		foreach ( $signatures as $signature ) {
			if ( hash_equals( $expected, $signature ) ) {
				return true;
			}
		}
		return false;
	}
}
