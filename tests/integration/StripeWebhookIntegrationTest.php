<?php
/**
 * T-85: Stripe webhook intake through the real REST route, and reconciliation of stored events.
 *
 * @package WPLC
 */

use WPLC\Billing\RenewalProcessor;

final class StripeWebhookIntegrationTest extends WPLC_Integration_TestCase {

	/**
	 * Inbox IDs announced through wplc/stripe_webhook_received.
	 *
	 * @var int[]
	 */
	private $announced = array();

	public function set_up(): void {
		parent::set_up();
		$this->announced = array();
		add_action(
			'wplc/stripe_webhook_received',
			function ( $inbox_id ) {
				$this->announced[] = (int) $inbox_id;
			},
			1
		);
	}

	/**
	 * A Stripe event body.
	 *
	 * @param string              $id       Event ID.
	 * @param string              $type     Event type.
	 * @param array<string,mixed> $object   data.object overrides.
	 * @return string JSON.
	 */
	private function event( string $id, string $type, array $object = array() ): string {
		return (string) wp_json_encode(
			array(
				'id'   => $id,
				'type' => $type,
				'data' => array(
					'object' => array_merge(
						array(
							'id'              => 'pi_test_123',
							'status'          => 'succeeded',
							'amount_received' => 4900,
							'currency'        => strtolower( get_woocommerce_currency() ),
							'receipt_email'   => 'private-customer@example.test',
							'metadata'        => array(),
						),
						$object
					),
				),
			)
		);
	}

	/**
	 * Renewal metadata as the Stripe adapter attaches it.
	 *
	 * @param int $renewal_id Renewal ID.
	 * @param int $license_id License ID.
	 * @param int $order_id   Order ID.
	 * @return array<string,string>
	 */
	private function renewal_metadata( int $renewal_id = 11, int $license_id = 22, int $order_id = 33 ): array {
		return array(
			'wplc_renewal_id' => (string) $renewal_id,
			'wplc_license_id' => (string) $license_id,
			'order_id'        => (string) $order_id,
		);
	}

	/**
	 * POST a body to the webhook route.
	 *
	 * @param string      $body      Raw body.
	 * @param string|null $signature Stripe-Signature header; computed with the configured secret when null.
	 * @return WP_REST_Response
	 */
	private function post_webhook( string $body, ?string $signature = null ): WP_REST_Response {
		if ( null === $signature ) {
			$timestamp = time();
			$signature = 't=' . $timestamp . ',v1=' . hash_hmac( 'sha256', $timestamp . '.' . $body, WPLC_STRIPE_WEBHOOK_SECRET );
		}
		$request = new WP_REST_Request( 'POST', '/wplc/v1/webhooks/stripe' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'Stripe-Signature', $signature );
		$request->set_body( $body );

		return $this->dispatch( $request );
	}

	/**
	 * All inbox rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function inbox(): array {
		global $wpdb;
		$tables = $this->tables();

		return (array) $wpdb->get_results( "SELECT * FROM {$tables['event_inbox']} ORDER BY id ASC", ARRAY_A );
	}

	// ------------------------------------------------------------------
	// Intake.
	// ------------------------------------------------------------------

	public function test_ordinary_shop_payment_is_acknowledged_and_not_stored(): void {
		$response = $this->post_webhook( $this->event( 'evt_shop_payment', 'payment_intent.succeeded' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'received' => true, 'ignored' => true ), $response->get_data() );
		$this->assertCount( 0, $this->inbox() );
		$this->assertSame( array(), $this->announced );
	}

	public function test_unrelated_event_type_is_acknowledged_and_not_stored(): void {
		$response = $this->post_webhook( $this->event( 'evt_charge', 'charge.refunded', array( 'metadata' => $this->renewal_metadata() ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['ignored'] );
		$this->assertCount( 0, $this->inbox() );
	}

	public function test_renewal_event_is_stored_once_minimized_and_announced_once(): void {
		$body     = $this->event( 'evt_renewal_1', 'payment_intent.succeeded', array( 'metadata' => $this->renewal_metadata() ) );
		$response = $this->post_webhook( $body );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'received' => true, 'reconciliation' => 'pending' ), $response->get_data() );

		$rows = $this->inbox();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'stripe', $rows[0]['source'] );
		$this->assertSame( 'evt_renewal_1', $rows[0]['source_event_id'] );
		$this->assertSame( 'payment_intent.succeeded', $rows[0]['event_type'] );
		$this->assertSame( hash( 'sha256', $body ), $rows[0]['payload_hash'] );
		$this->assertNull( $rows[0]['processed_at'] );
		$this->assertSame(
			array(
				'payment_intent_id' => 'pi_test_123',
				'payment_status'    => 'succeeded',
				'amount_received'   => 4900,
				'currency'          => strtolower( get_woocommerce_currency() ),
				'renewal_id'        => 11,
				'license_id'        => 22,
				'order_id'          => 33,
			),
			json_decode( $rows[0]['payload_json'], true )
		);
		$this->assertStringNotContainsString( 'private-customer@example.test', (string) wp_json_encode( $rows[0] ), 'Personal data from the Stripe object must not be stored.' );
		$this->assertSame( array( (int) $rows[0]['id'] ), $this->announced );
	}

	public function test_duplicate_delivery_is_acknowledged_without_a_second_row_or_action(): void {
		$body = $this->event( 'evt_renewal_dup', 'payment_intent.succeeded', array( 'metadata' => $this->renewal_metadata() ) );
		$this->assertSame( 200, $this->post_webhook( $body )->get_status() );

		$duplicate = $this->post_webhook( $body );

		$this->assertSame( 200, $duplicate->get_status() );
		$this->assertSame( array( 'received' => true, 'duplicate' => true ), $duplicate->get_data() );
		$this->assertCount( 1, $this->inbox() );
		$this->assertCount( 1, $this->announced );
	}

	public function test_same_event_id_with_a_different_payload_is_a_conflict(): void {
		$metadata = $this->renewal_metadata();
		$this->assertSame( 200, $this->post_webhook( $this->event( 'evt_conflict', 'payment_intent.succeeded', array( 'metadata' => $metadata ) ) )->get_status() );

		$conflict = $this->post_webhook( $this->event( 'evt_conflict', 'payment_intent.succeeded', array( 'metadata' => $metadata, 'amount_received' => 1 ) ) );

		$this->assertApiError( 409, 'wplc_stripe_event_conflict', $conflict );
		$rows = $this->inbox();
		$this->assertCount( 1, $rows );
		$this->assertSame( 4900, json_decode( $rows[0]['payload_json'], true )['amount_received'], 'The first stored payload must win.' );
		$this->assertCount( 1, $this->announced );

		$spec = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/docs/openapi-v1.json' ), true );
		if ( ! isset( $spec['paths']['/webhooks/stripe']['post']['responses']['409'] ) ) {
			$this->markTestIncomplete( 'SPEC GAP: docs/openapi-v1.json does not declare the 409 (wplc_stripe_event_conflict) response of POST /webhooks/stripe.' );
		}
	}

	/**
	 * Signature headers that must be rejected.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function data_invalid_signatures(): array {
		return array(
			'missing'              => array( 'none' ),
			'wrong secret'         => array( 'wrong-secret' ),
			'signed other body'    => array( 'other-body' ),
			'timestamp too old'    => array( 'old' ),
			'timestamp in future'  => array( 'future' ),
			'unsupported v0 only'  => array( 'v0' ),
			'malformed'            => array( 'garbage' ),
		);
	}

	/**
	 * @dataProvider data_invalid_signatures
	 *
	 * @param string $kind Kind of invalid header.
	 */
	public function test_invalid_signature_is_rejected_and_nothing_is_stored( string $kind ): void {
		$body = $this->event( 'evt_bad_sig', 'payment_intent.succeeded', array( 'metadata' => $this->renewal_metadata() ) );
		$now  = time();
		$sign = static function ( int $timestamp, string $payload, string $secret ): string {
			return hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
		};
		$headers = array(
			'none'         => '',
			'wrong-secret' => 't=' . $now . ',v1=' . $sign( $now, $body, 'whsec_someone_else' ),
			'other-body'   => 't=' . $now . ',v1=' . $sign( $now, $body . ' ', WPLC_STRIPE_WEBHOOK_SECRET ),
			'old'          => 't=' . ( $now - 301 ) . ',v1=' . $sign( $now - 301, $body, WPLC_STRIPE_WEBHOOK_SECRET ),
			'future'       => 't=' . ( $now + 301 ) . ',v1=' . $sign( $now + 301, $body, WPLC_STRIPE_WEBHOOK_SECRET ),
			'v0'           => 't=' . $now . ',v0=' . $sign( $now, $body, WPLC_STRIPE_WEBHOOK_SECRET ),
			'garbage'      => 'v1=zzzz,t=abc',
		);

		$response = $this->post_webhook( $body, $headers[ $kind ] );

		$this->assertApiError( 400, 'wplc_stripe_signature_invalid', $response );
		$this->assertCount( 0, $this->inbox() );
		$this->assertSame( array(), $this->announced );
	}

	public function test_signature_with_several_v1_values_is_accepted_during_secret_rotation(): void {
		$body      = $this->event( 'evt_rotation', 'payment_intent.succeeded', array( 'metadata' => $this->renewal_metadata() ) );
		$timestamp = time();
		$header    = 't=' . $timestamp
			. ',v1=' . hash_hmac( 'sha256', $timestamp . '.' . $body, 'whsec_previous' )
			. ',v1=' . hash_hmac( 'sha256', $timestamp . '.' . $body, WPLC_STRIPE_WEBHOOK_SECRET );

		$this->assertSame( 200, $this->post_webhook( $body, $header )->get_status() );
	}

	public function test_empty_oversized_and_malformed_bodies_are_rejected(): void {
		$this->assertApiError( 400, 'wplc_stripe_webhook_body_invalid', $this->post_webhook( '' ) );
		$oversized = $this->event( 'evt_big', 'payment_intent.succeeded', array( 'description' => str_repeat( 'a', \WPLC\Api\V1\StripeWebhook::MAX_BODY_BYTES ) ) );
		$this->assertApiError( 400, 'wplc_stripe_webhook_body_invalid', $this->post_webhook( $oversized ) );
		$this->assertApiError( 400, 'wplc_stripe_event_invalid', $this->post_webhook( '{"id":"evt_x","type":"payment_intent.succeeded"}' ) );
		$this->assertApiError( 400, 'wplc_stripe_event_invalid', $this->post_webhook( '{"id":"evt_x","type":"payment_intent.succeeded","data":{"object":"pi_123"}}' ) );
		// Invalid JSON never reaches the handler: WordPress rejects it for application/json requests.
		$this->assertApiError( 400, 'rest_invalid_json', $this->post_webhook( 'not json' ) );
		$this->assertCount( 0, $this->inbox() );
	}

	public function test_renewal_event_with_incomplete_metadata_is_rejected(): void {
		$incomplete = array(
			'no order'          => array( 'wplc_renewal_id' => '11', 'wplc_license_id' => '22' ),
			'no license'        => array( 'wplc_renewal_id' => '11', 'order_id' => '33' ),
			'no renewal'        => array( 'wplc_license_id' => '22', 'order_id' => '33' ),
			'non-numeric order' => array( 'wplc_renewal_id' => '11', 'wplc_license_id' => '22', 'order_id' => 'abc' ),
		);
		foreach ( $incomplete as $label => $metadata ) {
			$response = $this->post_webhook( $this->event( 'evt_' . md5( $label ), 'payment_intent.succeeded', array( 'metadata' => $metadata ) ) );
			$this->assertApiError( 400, 'wplc_stripe_event_metadata_invalid', $response );
		}

		$not_a_payment_intent = $this->post_webhook( $this->event( 'evt_wrong_object', 'payment_intent.succeeded', array( 'id' => 'ch_123', 'metadata' => $this->renewal_metadata() ) ) );
		$this->assertApiError( 400, 'wplc_stripe_event_metadata_invalid', $not_a_payment_intent );

		$not_succeeded = $this->post_webhook( $this->event( 'evt_wrong_status', 'payment_intent.succeeded', array( 'status' => 'processing', 'metadata' => $this->renewal_metadata() ) ) );
		$this->assertApiError( 400, 'wplc_stripe_event_status_invalid', $not_succeeded );

		$this->assertCount( 0, $this->inbox() );
	}

	public function test_failed_payment_event_is_stored_for_reconciliation(): void {
		$body = $this->event( 'evt_failed', 'payment_intent.payment_failed', array( 'status' => 'requires_payment_method', 'amount_received' => 0, 'amount' => 4900, 'metadata' => $this->renewal_metadata() ) );

		$this->assertSame( 200, $this->post_webhook( $body )->get_status() );
		$rows = $this->inbox();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'payment_intent.payment_failed', $rows[0]['event_type'] );
		$this->assertSame( 'requires_payment_method', json_decode( $rows[0]['payload_json'], true )['payment_status'] );
	}

	public function test_missing_webhook_secret_cannot_be_exercised_in_this_process(): void {
		// The bootstrap defines WPLC_STRIPE_WEBHOOK_SECRET and PHP constants cannot be
		// removed, so the 503 wplc_stripe_webhook_unconfigured branch is not reachable here.
		$this->assertTrue( defined( 'WPLC_STRIPE_WEBHOOK_SECRET' ) );
		$this->markTestSkipped( 'The "secret not configured" (503) branch needs a process without WPLC_STRIPE_WEBHOOK_SECRET; covered by code review only.' );
	}

	// ------------------------------------------------------------------
	// Reconciliation of stored events (late, duplicate and wrongly linked events).
	// ------------------------------------------------------------------

	/**
	 * Store one event through the route and return its inbox ID.
	 *
	 * @param string              $event_id Event ID.
	 * @param array<string,mixed> $metadata Metadata.
	 * @param int                 $amount   Amount in minor units.
	 * @return int
	 */
	private function store_success_event( string $event_id, array $metadata, int $amount = 4900 ): int {
		$response = $this->post_webhook( $this->event( $event_id, 'payment_intent.succeeded', array( 'metadata' => $metadata, 'amount_received' => $amount ) ) );
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$rows = $this->inbox();

		return (int) end( $rows )['id'];
	}

	/**
	 * A license with a scheduled renewal and its unpaid Stripe renewal order.
	 *
	 * @return array{license_id:int,renewal_id:int,order_id:int,paid_until:string,period_end:string}
	 */
	private function renewal_fixture(): array {
		global $wpdb;
		$tables     = $this->tables();
		$customer   = self::factory()->user->create( array( 'role' => 'customer' ) );
		$paid_until = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
		$period_end = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS + YEAR_IN_SECONDS );
		$license_id = $this->seed_license(
			array(
				'customer_id'          => $customer,
				'paid_until'           => $paid_until,
				'next_payment_at'      => $paid_until,
				'renewal_enabled'      => 1,
				'payment_gateway_id'   => 'stripe',
				'renewal_amount_minor' => 4900,
				'renewal_currency'     => get_woocommerce_currency(),
			)
		)['id'];

		$order = $this->create_order( array( array( $this->create_simple_product( '49.00' ), 1 ) ), $customer );
		$order->set_payment_method( 'stripe' );
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert(
			$tables['renewals'],
			array(
				'license_id'       => $license_id,
				'period_start'     => $paid_until,
				'period_end'       => $period_end,
				'renewal_order_id' => $order->get_id(),
				'idempotency_key'  => hash( 'sha256', 'renewal-fixture-' . $license_id ),
				'status'           => 'scheduled',
				'created_at'       => $now,
				'updated_at'       => $now,
			)
		);
		$renewal_id = (int) $wpdb->insert_id;
		$order->update_meta_data( '_wplc_renewal_id', $renewal_id );
		$order->update_meta_data( '_wplc_renewal_for_license', $license_id );
		$order->save();

		return array(
			'license_id' => $license_id,
			'renewal_id' => $renewal_id,
			'order_id'   => $order->get_id(),
			'paid_until' => $paid_until,
			'period_end' => $period_end,
		);
	}

	/**
	 * Inbox row by ID.
	 *
	 * @param int $inbox_id Inbox ID.
	 * @return array<string,mixed>
	 */
	private function inbox_row( int $inbox_id ): array {
		global $wpdb;
		$tables = $this->tables();

		return (array) $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['event_inbox']} WHERE id = %d", $inbox_id ), ARRAY_A );
	}

	public function test_event_for_an_unknown_renewal_is_parked_and_changes_no_license(): void {
		$fixture  = $this->renewal_fixture();
		$inbox_id = $this->store_success_event( 'evt_unknown_renewal', $this->renewal_metadata( 999999, $fixture['license_id'], $fixture['order_id'] ) );

		RenewalProcessor::process_stripe_event( $inbox_id );

		$row = $this->inbox_row( $inbox_id );
		$this->assertNotSame( 'pending_reconciliation', $row['outcome'], 'A wrongly linked event must not stay pending forever.' );
		$this->assertNotSame( 'processed', $row['outcome'] );
		$this->assertNotNull( $row['processed_at'] );
		$this->assertSame( $fixture['paid_until'], $this->license( $fixture['license_id'] )['paid_until'] );
	}

	public function test_event_linking_a_renewal_to_a_foreign_license_or_order_does_not_renew(): void {
		$fixture = $this->renewal_fixture();
		$other   = $this->renewal_fixture();

		$wrong_license = $this->store_success_event( 'evt_wrong_license', $this->renewal_metadata( $fixture['renewal_id'], $other['license_id'], $fixture['order_id'] ) );
		$wrong_order   = $this->store_success_event( 'evt_wrong_order', $this->renewal_metadata( $fixture['renewal_id'], $fixture['license_id'], $other['order_id'] ) );
		RenewalProcessor::process_stripe_event( $wrong_license );
		RenewalProcessor::process_stripe_event( $wrong_order );

		foreach ( array( $wrong_license, $wrong_order ) as $inbox_id ) {
			$this->assertNotContains( $this->inbox_row( $inbox_id )['outcome'], array( 'pending_reconciliation', 'processed' ) );
		}
		$this->assertSame( $fixture['paid_until'], $this->license( $fixture['license_id'] )['paid_until'] );
		$this->assertSame( $other['paid_until'], $this->license( $other['license_id'] )['paid_until'] );
		$this->assertFalse( wc_get_order( $fixture['order_id'] )->is_paid() );
		$this->assertFalse( wc_get_order( $other['order_id'] )->is_paid() );
	}

	public function test_late_success_event_for_a_revoked_license_does_not_reactivate_it(): void {
		global $wpdb;
		$tables  = $this->tables();
		$fixture = $this->renewal_fixture();
		$wpdb->update( $tables['licenses'], array( 'status' => 'revoked' ), array( 'id' => $fixture['license_id'] ) );
		$inbox_id = $this->store_success_event( 'evt_late', $this->renewal_metadata( $fixture['renewal_id'], $fixture['license_id'], $fixture['order_id'] ) );

		RenewalProcessor::process_stripe_event( $inbox_id );

		$license = $this->license( $fixture['license_id'] );
		$this->assertSame( 'revoked', $license['status'] );
		$this->assertSame( $fixture['paid_until'], $license['paid_until'] );
		$this->assertNotContains( $this->inbox_row( $inbox_id )['outcome'], array( 'pending_reconciliation', 'processed' ) );
	}

	public function test_matching_success_event_renews_exactly_once(): void {
		if ( ! class_exists( 'WC_Stripe_Helper' ) ) {
			// The WooCommerce Stripe gateway is not part of the test matrix. The
			// reconciler only needs its amount conversion, so provide that one method.
			require_once __DIR__ . '/helpers/StripeHelperStub.php';
		}
		$fixture  = $this->renewal_fixture();
		$metadata = $this->renewal_metadata( $fixture['renewal_id'], $fixture['license_id'], $fixture['order_id'] );
		$inbox_id = $this->store_success_event( 'evt_success', $metadata );

		RenewalProcessor::process_stripe_event( $inbox_id );

		$this->assertSame( 'processed', $this->inbox_row( $inbox_id )['outcome'] );
		$license = $this->license( $fixture['license_id'] );
		$this->assertSame( 'active', $license['status'] );
		$this->assertSame( $fixture['period_end'], $license['paid_until'] );
		$this->assertTrue( wc_get_order( $fixture['order_id'] )->is_paid() );
		$this->assertCount( 0, $this->licenses_for_order( $fixture['order_id'] ), 'A renewal order must never issue a new license.' );

		// The same inbox entry processed again, and a second Stripe event for the same payment.
		RenewalProcessor::process_stripe_event( $inbox_id );
		$second = $this->store_success_event( 'evt_success_redelivered_with_new_id', $metadata );
		RenewalProcessor::process_stripe_event( $second );

		$this->assertSame( $fixture['period_end'], $this->license( $fixture['license_id'] )['paid_until'], 'The period must be extended once, not once per event.' );
		$this->assertNotContains( $this->inbox_row( $second )['outcome'], array( 'pending_reconciliation', 'processed' ) );
	}

	public function test_success_event_with_a_wrong_amount_does_not_renew(): void {
		if ( ! class_exists( 'WC_Stripe_Helper' ) ) {
			require_once __DIR__ . '/helpers/StripeHelperStub.php';
		}
		$fixture  = $this->renewal_fixture();
		$inbox_id = $this->store_success_event( 'evt_underpaid', $this->renewal_metadata( $fixture['renewal_id'], $fixture['license_id'], $fixture['order_id'] ), 100 );

		RenewalProcessor::process_stripe_event( $inbox_id );

		$this->assertSame( $fixture['paid_until'], $this->license( $fixture['license_id'] )['paid_until'] );
		$this->assertFalse( wc_get_order( $fixture['order_id'] )->is_paid() );
		$this->assertNotContains( $this->inbox_row( $inbox_id )['outcome'], array( 'pending_reconciliation', 'processed' ) );
	}

	public function test_stuck_pending_inbox_entry_can_be_resumed(): void {
		if ( ! class_exists( 'WC_Stripe_Helper' ) ) {
			require_once __DIR__ . '/helpers/StripeHelperStub.php';
		}
		global $wpdb;
		$tables   = $this->tables();
		$fixture  = $this->renewal_fixture();
		$inbox_id = $this->store_success_event( 'evt_stuck', $this->renewal_metadata( $fixture['renewal_id'], $fixture['license_id'], $fixture['order_id'] ) );

		// The queue lost the job (cleared queue, crashed worker): the row is hours old and still pending.
		$wpdb->update( $tables['event_inbox'], array( 'received_at' => gmdate( 'Y-m-d H:i:s', time() - 6 * HOUR_IN_SECONDS ) ), array( 'id' => $inbox_id ) );
		$this->assertSame( 'pending_reconciliation', $this->inbox_row( $inbox_id )['outcome'] );

		// Resuming is a plain re-run of the documented action.
		do_action( 'wplc_process_stripe_webhook', $inbox_id );

		$this->assertSame( 'processed', $this->inbox_row( $inbox_id )['outcome'] );
		$this->assertSame( $fixture['period_end'], $this->license( $fixture['license_id'] )['paid_until'] );
	}
}
