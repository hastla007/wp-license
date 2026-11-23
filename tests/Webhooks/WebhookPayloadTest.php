<?php
/**
 * SET-LIC-46: envelope, signature, retry schedule and event catalog of the outgoing webhooks.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\Webhooks\EventCatalog;
use WPLC\Webhooks\Payload;

final class WebhookPayloadTest extends TestCase {

	private const SECRET = 'whsec_0123456789abcdef0123456789abcdef0123456789abcdef';

	public function test_the_signature_is_hmac_sha256_over_timestamp_dot_body(): void {
		$body = '{"id":"e1","event":"license.plan_changed"}';

		$this->assertSame( 'v1=' . hash_hmac( 'sha256', '1764000000.' . $body, self::SECRET ), Payload::signature( self::SECRET, 1764000000, $body ) );
		$this->assertSame( 67, strlen( Payload::signature( self::SECRET, 1764000000, $body ) ) );
		// Known answer, so a receiver in another language can check its implementation.
		$this->assertSame( 'v1=' . hash_hmac( 'sha256', '1.{}', 'k' ), Payload::signature( 'k', 1, '{}' ) );
	}

	public function test_verification_accepts_the_signed_body_and_refuses_changes_age_and_wrong_secret(): void {
		$body      = Payload::encode( array( 'id' => 'e1', 'data' => array( 'license_id' => 7 ) ) );
		$timestamp = 1764000000;
		$header    = Payload::signature( self::SECRET, $timestamp, $body );

		$this->assertTrue( Payload::verify( self::SECRET, (string) $timestamp, $body, $header, $timestamp + 10 ) );
		$this->assertFalse( Payload::verify( self::SECRET, (string) $timestamp, $body . ' ', $header, $timestamp ), 'A changed body fails.' );
		$this->assertFalse( Payload::verify( self::SECRET, (string) ( $timestamp + 1 ), $body, $header, $timestamp ), 'The timestamp is signed.' );
		$this->assertFalse( Payload::verify( 'whsec_other', (string) $timestamp, $body, $header, $timestamp ), 'Another secret fails.' );
		$this->assertFalse( Payload::verify( self::SECRET, (string) $timestamp, $body, $header, $timestamp + 301 ), 'Older than five minutes: replay refused.' );
		$this->assertFalse( Payload::verify( self::SECRET, 'abc', $body, $header, $timestamp ) );
		$this->assertFalse( Payload::verify( '', (string) $timestamp, $body, $header, $timestamp ) );
	}

	public function test_headers_carry_id_event_timestamp_attempt_and_signature(): void {
		$body    = '{"a":1}';
		$headers = Payload::headers( self::SECRET, 'evt-1', 'license.issued', 1764000000, 3, $body, 'Agent/1' );

		$this->assertSame( 'evt-1', $headers['X-WPLC-Event-Id'] );
		$this->assertSame( 'license.issued', $headers['X-WPLC-Event'] );
		$this->assertSame( '1764000000', $headers['X-WPLC-Timestamp'] );
		$this->assertSame( '3', $headers['X-WPLC-Attempt'] );
		$this->assertSame( Payload::signature( self::SECRET, 1764000000, $body ), $headers['X-WPLC-Signature'] );
		$this->assertSame( 'application/json; charset=utf-8', $headers['Content-Type'] );
		$this->assertStringNotContainsString( self::SECRET, implode( "\n", $headers ), 'The secret itself is never sent.' );
	}

	public function test_the_envelope_has_the_documented_fields(): void {
		$envelope = Payload::envelope( 'evt-1', 'license.status_changed', 'wplc/license_status_changed', '1.0.0', 1764000000, 'https://shop.example.com/', array( 'license_id' => 5 ) );

		$this->assertSame( array( 'id', 'event', 'hook', 'contract_version', 'webhook_version', 'created_at', 'source', 'data' ), array_keys( $envelope ) );
		$this->assertSame( '2025-11-24T16:00:00Z', $envelope['created_at'] );
		$this->assertSame( 1, $envelope['webhook_version'] );
		$this->assertSame( '{"id":"evt-1","event":"license.status_changed","hook":"wplc/license_status_changed","contract_version":"1.0.0","webhook_version":1,"created_at":"2025-11-24T16:00:00Z","source":"https://shop.example.com/","data":{"license_id":5}}', Payload::encode( $envelope ) );
	}

	public function test_backoff_gives_five_attempts_and_then_stops(): void {
		$this->assertSame( 60, Payload::retry_delay( 1 ) );
		$this->assertSame( 300, Payload::retry_delay( 2 ) );
		$this->assertSame( 1800, Payload::retry_delay( 3 ) );
		$this->assertSame( 7200, Payload::retry_delay( 4 ) );
		$this->assertNull( Payload::retry_delay( 5 ), 'The fifth attempt is the last.' );
		$this->assertNull( Payload::retry_delay( 0 ) );
		$this->assertNull( Payload::retry_delay( 1, array() ), 'An empty schedule means one attempt.' );
		// Delays grow.
		$previous = 0;
		foreach ( Payload::RETRY_DELAYS as $delay ) {
			$this->assertGreaterThan( $previous, $delay );
			$previous = $delay;
		}
	}

	public function test_filtered_delays_are_cleaned(): void {
		$this->assertSame( Payload::RETRY_DELAYS, Payload::sanitize_delays( 'x' ) );
		$this->assertSame( array( 5, 86400 ), Payload::sanitize_delays( array( 5, -1, 'a', 999999, 0 ) ) );
		$this->assertCount( 9, Payload::sanitize_delays( array_fill( 0, 20, 10 ) ) );
		$this->assertSame( array(), Payload::sanitize_delays( array() ) );
	}

	public function test_short_errors_are_single_line_and_bounded(): void {
		$this->assertSame( 'a b', Payload::short_error( "a\r\n\tb" ) );
		$this->assertSame( 190, strlen( Payload::short_error( str_repeat( 'x', 500 ) ) ) );
	}

	public function test_generated_secrets_are_long_random_and_prefixed(): void {
		$one = Payload::generate_secret();
		$two = Payload::generate_secret();
		$this->assertMatchesRegularExpression( '/^whsec_[0-9a-f]{48}$/', $one );
		$this->assertNotSame( $one, $two );
	}

	public function test_the_catalog_offers_the_eight_contract_actions_and_never_a_key_action(): void {
		$this->assertSame(
			array(
				'wplc/license_issued_followup' => 2,
				'wplc/license_status_changed'  => 4,
				'wplc/license_plan_changed'    => 1,
				'wplc/site_limit_reached'      => 3,
				'wplc/activation_created'      => 3,
				'wplc/activation_removed'      => 4,
				'wplc/release_published'       => 4,
				'wplc/release_withdrawn'       => 4,
			),
			EventCatalog::hooks()
		);
		foreach ( array( 'wplc/license_issued', 'wplc/license_key_reissued', 'wplc/license_key_resend_requested' ) as $key_action ) {
			$this->assertSame( '', EventCatalog::event_for_hook( $key_action ), $key_action . ' passes the key and is never offered.' );
		}
		$this->assertFalse( EventCatalog::exists( EventCatalog::TEST_EVENT ), 'The test event cannot be subscribed to.' );
		$this->assertSame( 'license.status_changed', EventCatalog::event_for_hook( 'wplc/license_status_changed' ) );
		$this->assertSame( 'wplc/activation_removed', EventCatalog::hook( 'activation.removed' ) );
	}

	public function test_event_data_is_named_typed_and_scalar(): void {
		$this->assertSame(
			array(
				'license_id' => 12,
				'old_status' => 'active',
				'new_status' => 'suspended',
				'action'     => 'chargeback',
			),
			EventCatalog::data( 'license.status_changed', array( '12', 'active', 'suspended', 'chargeback', 'extra' ) )
		);
		$this->assertSame(
			array(
				'license_id'  => 3,
				'environment' => '',
				'limit'       => 0,
			),
			EventCatalog::data( 'license.site_limit_reached', array( 3, array( 'not scalar' ) ) ),
			'Missing or non-scalar arguments become empty values.'
		);
		$this->assertSame( array(), EventCatalog::data( 'unknown.event', array( 1 ) ) );
	}

	public function test_a_selection_keeps_known_events_in_catalog_order(): void {
		$this->assertSame( array( 'license.issued', 'release.published' ), EventCatalog::sanitize_selection( array( 'release.published', 'nope', 'license.issued', 'license.issued' ) ) );
		$this->assertSame( array( 'license.plan_changed', 'activation.created' ), EventCatalog::sanitize_selection( ' activation.created , license.plan_changed ' ) );
		$this->assertSame( array(), EventCatalog::sanitize_selection( null ) );
	}
}
