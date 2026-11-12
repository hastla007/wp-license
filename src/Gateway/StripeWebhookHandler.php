<?php
/**
 * Verifies and reconciles Stripe webhook events for renewal payments.
 *
 * @package WPLC
 */

namespace WPLC\Gateway;

use WPLC\Billing\RenewalScheduler;
use WPLC\Infrastructure\Database\Schema;
use WPLC\License\AuditLog;
use WPLC\License\LicenseLifecycle;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stripe event endpoint. Configure WPLC_STRIPE_WEBHOOK_SECRET with the
 * endpoint signing secret from the Stripe dashboard; never store it in options.
 */
final class StripeWebhookHandler {

	/**
	 * Register the public REST endpoint.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'register_route' ) );
	}

	/**
	 * Register route. Authenticity is established by the Stripe signature.
	 *
	 * @return void
	 */
	public static function register_route(): void {
		register_rest_route(
			'wplc/v1',
			'/stripe/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Verify and process a Stripe event.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function handle( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		if ( ! defined( 'WPLC_STRIPE_WEBHOOK_SECRET' ) || ! is_string( WPLC_STRIPE_WEBHOOK_SECRET ) || '' === WPLC_STRIPE_WEBHOOK_SECRET ) {
			return new \WP_REST_Response( array( 'code' => 'stripe_webhook_unconfigured' ), 503 );
		}
		$payload = (string) $request->get_body();
		$header  = (string) $request->get_header( 'stripe-signature' );
		$event   = self::verify_and_decode( $payload, $header, WPLC_STRIPE_WEBHOOK_SECRET );
		if ( is_wp_error( $event ) ) {
			return new \WP_REST_Response( array( 'code' => $event->get_error_code() ), 400 );
		}

		$event_id = sanitize_text_field( (string) ( $event->id ?? '' ) );
		$event_type = sanitize_key( (string) ( $event->type ?? '' ) );
		if ( '' === $event_id || '' === $event_type || strlen( $event_id ) > 190 ) {
			return new \WP_REST_Response( array( 'code' => 'stripe_event_invalid' ), 400 );
		}

		$tables = Schema::table_names();
		$lock_name = 'wplc_stripe_event_' . substr( hash( 'sha256', $event_id ), 0, 40 );
		$locked = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name ) );
		if ( '1' !== (string) $locked ) {
			return new \WP_REST_Response( array( 'code' => 'stripe_event_busy' ), 503 );
		}
		try {
			$payload_hash = hash( 'sha256', $payload );
			$inserted = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$tables['event_inbox']} (source,source_event_id,event_type,payload_hash,received_at,outcome) VALUES (%s,%s,%s,%s,%s,%s)",
					'stripe',
					$event_id,
					$event_type,
					$payload_hash,
					gmdate( 'Y-m-d H:i:s' ),
					'received'
				)
			);
			if ( false === $inserted ) {
				return new \WP_REST_Response( array( 'code' => 'stripe_inbox_unavailable' ), 503 );
			}
			$inbox = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$tables['event_inbox']} WHERE source = %s AND source_event_id = %s LIMIT 1", 'stripe', $event_id ),
				ARRAY_A
			);
			if ( ! is_array( $inbox ) || ! hash_equals( (string) $inbox['payload_hash'], $payload_hash ) ) {
				return new \WP_REST_Response( array( 'code' => 'stripe_event_conflict' ), 409 );
			}
			if ( ! empty( $inbox['processed_at'] ) ) {
				return new \WP_REST_Response( array( 'received' => true, 'duplicate' => true ), 200 );
			}

			$result = self::reconcile( $event_type, $event );
			if ( is_wp_error( $result ) ) {
				return new \WP_REST_Response( array( 'code' => $result->get_error_code() ), 503 );
			}
			$updated = $wpdb->update(
				$tables['event_inbox'],
				array(
					'processed_at' => gmdate( 'Y-m-d H:i:s' ),
					'outcome'      => $result,
				),
				array( 'id' => (int) $inbox['id'] ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			if ( false === $updated ) {
				return new \WP_REST_Response( array( 'code' => 'stripe_inbox_update_failed' ), 503 );
			}
			return new \WP_REST_Response( array( 'received' => true, 'duplicate' => false ), 200 );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}

	/**
	 * Verify Stripe's timestamped v1 HMAC and decode the event.
	 *
	 * @param string $payload Raw request body.
	 * @param string $header Stripe-Signature header.
	 * @param string $secret Webhook signing secret.
	 * @return object|\WP_Error
	 */
	private static function verify_and_decode( string $payload, string $header, string $secret ) {
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
		$max_age = (int) apply_filters( 'wplc_stripe_webhook_tolerance', 300 );
		if ( ! $timestamp || abs( time() - $timestamp ) > max( 60, min( 600, $max_age ) ) || empty( $signatures ) ) {
			return new \WP_Error( 'stripe_signature_invalid', __( 'The Stripe webhook signature is invalid or expired.', 'wp-license' ) );
		}
		$expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
		$valid = false;
		foreach ( $signatures as $signature ) {
			$valid = hash_equals( $expected, $signature ) || $valid;
		}
		if ( ! $valid ) {
			return new \WP_Error( 'stripe_signature_invalid', __( 'The Stripe webhook signature is invalid or expired.', 'wp-license' ) );
		}
		$event = json_decode( $payload );
		if ( ! is_object( $event ) || JSON_ERROR_NONE !== json_last_error() ) {
			return new \WP_Error( 'stripe_event_invalid', __( 'The Stripe webhook payload is invalid.', 'wp-license' ) );
		}
		return $event;
	}

	/**
	 * Reconcile successful PaymentIntents. Other events are recorded for
	 * idempotency and observability; failed payments remain on the retry path.
	 *
	 * @param string $event_type Event type.
	 * @param object $event Event body.
	 * @return string|\WP_Error Inbox outcome.
	 */
	private static function reconcile( string $event_type, object $event ) {
		if ( 'payment_intent.succeeded' !== $event_type ) {
			return 'recorded';
		}
		$intent = $event->data->object ?? null;
		$metadata = is_object( $intent ) ? ( $intent->metadata ?? null ) : null;
		$renewal_id = is_object( $metadata ) ? absint( $metadata->wplc_renewal_id ?? 0 ) : 0;
		$order_id = is_object( $metadata ) ? absint( $metadata->order_id ?? 0 ) : 0;
		$license_id = is_object( $metadata ) ? absint( $metadata->wplc_license_id ?? 0 ) : 0;
		$intent_id = is_object( $intent ) ? sanitize_text_field( (string) ( $intent->id ?? '' ) ) : '';
		if ( ! $renewal_id && ! $order_id && ! $license_id ) {
			return 'ignored';
		}
		if ( ! $renewal_id || ! $order_id || ! $license_id || '' === $intent_id ) {
			return new \WP_Error( 'stripe_renewal_metadata_invalid', __( 'The Stripe payment renewal metadata is incomplete.', 'wp-license' ) );
		}

		global $wpdb;
		$lock_name = 'wplc_renewal_' . substr( hash( 'sha256', 'renewal:' . $renewal_id ), 0, 48 );
		$locked = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name ) );
		if ( '1' !== (string) $locked ) {
			return new \WP_Error( 'stripe_renewal_busy', __( 'The renewal is being processed.', 'wp-license' ) );
		}
		try {
			$tables = Schema::table_names();
			$renewal = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$tables['renewals']} WHERE id = %d AND renewal_order_id = %d LIMIT 1", $renewal_id, $order_id ),
				ARRAY_A
			);
			$license = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$tables['licenses']} WHERE id = %d LIMIT 1", $license_id ),
				ARRAY_A
			);
			if ( ! is_array( $renewal ) || ! is_array( $license ) || (int) $renewal['license_id'] !== $license_id ) {
				return new \WP_Error( 'stripe_renewal_not_found', __( 'The renewal could not be matched to its license.', 'wp-license' ) );
			}
			if ( 'paid' === $renewal['status'] ) {
				return 'already_paid';
			}
			if ( ! in_array( $renewal['status'], array( 'processing', 'failed', 'manual_action_required' ), true ) ) {
				return new \WP_Error( 'stripe_renewal_state_conflict', __( 'The renewal is not awaiting payment reconciliation.', 'wp-license' ) );
			}
			if ( ! function_exists( 'wc_get_order' ) ) {
				return new \WP_Error( 'woocommerce_unavailable', __( 'WooCommerce order services are unavailable.', 'wp-license' ) );
			}
			$order = wc_get_order( $order_id );
			if ( ! $order || (int) $order->get_meta( '_wplc_renewal_id', true ) !== $renewal_id || (int) $order->get_meta( '_wplc_renewal_for_license', true ) !== $license_id ) {
				return new \WP_Error( 'stripe_order_mismatch', __( 'The renewal order does not match the Stripe payment.', 'wp-license' ) );
			}
			$order->payment_complete( $intent_id );
			$updated = $wpdb->update(
				$tables['renewals'],
				array( 'status' => 'paid', 'retry_at' => null, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ),
				array( 'id' => $renewal_id, 'status' => $renewal['status'] ),
				array( '%s', '%s', '%s' ),
				array( '%d', '%s' )
			);
			if ( false === $updated || 0 === $updated ) {
				return new \WP_Error( 'stripe_renewal_update_failed', __( 'The renewal status could not be updated.', 'wp-license' ) );
			}
			LicenseLifecycle::mark_renewed( $license_id, (string) $renewal['period_end'], $order_id );
			RenewalScheduler::schedule_next( $license_id );
			AuditLog::record(
				'renewal_paid',
				'renewal',
				$renewal_id,
				'Stripe webhook confirmed payment',
				array( 'status' => $renewal['status'] ),
				array( 'status' => 'paid', 'order_id' => $order_id )
			);
			return 'renewal_paid';
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}
}
