<?php
/**
 * T-136: an automatic renewal whose payment result is unknown (timeout) is not a decline.
 *
 * The license keeps its status and access, no "payment failed" email is triggered,
 * the attempt is only re-checked with the same idempotency key, and after a bounded
 * time the renewal goes to review instead of being charged again.
 *
 * @package WPLC
 */

use WPLC\Billing\ManualRenewal;
use WPLC\Billing\RenewalProcessor;
use WPLC\Billing\RenewalScheduler;
use WPLC\License\LicenseLifecycle;

if ( ! class_exists( 'WPLC_Test_Scripted_Adapter' ) ) {
	/**
	 * Recurring adapter whose results are scripted by the test.
	 */
	final class WPLC_Test_Scripted_Adapter implements \WPLC\Gateway\RecurringGatewayAdapter { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound

		/**
		 * Gateway ID.
		 *
		 * @var string
		 */
		public $gateway = 'wplc_test';

		/**
		 * Results of the next charge calls: success, decline or unknown.
		 *
		 * @var string[]
		 */
		public $results = array();

		/**
		 * Idempotency keys of every charge call.
		 *
		 * @var string[]
		 */
		public $keys = array();

		public function gateway_id(): string {
			return $this->gateway;
		}

		public function supports_checkout(): bool {
			return true;
		}

		public function supports_saved_token( int $token_id, int $customer_id ): bool {
			$token = WC_Payment_Tokens::get( $token_id );

			return $token && $this->gateway === $token->get_gateway_id() && (int) $token->get_user_id() === $customer_id;
		}

		public function charge( $order, int $token_id, string $idempotency_key ) {
			$this->keys[] = $idempotency_key;
			$result       = $this->results ? array_shift( $this->results ) : 'unknown';
			if ( 'decline' === $result ) {
				return new WP_Error( 'card_declined', 'Declined.' );
			}
			if ( 'unknown' === $result ) {
				return new WP_Error( RenewalProcessor::RESULT_UNKNOWN, 'Timeout.' );
			}

			return array(
				'success'        => true,
				'transaction_id' => 'txn_scripted_' . count( $this->keys ),
			);
		}
	}
}

final class RenewalResultUnknownIntegrationTest extends WPLC_Integration_TestCase {

	/**
	 * Scripted adapter.
	 *
	 * @var WPLC_Test_Scripted_Adapter
	 */
	private $adapter;

	/**
	 * License status changes: [old, new, action].
	 *
	 * @var array<int,array{0:string,1:string,2:string}>
	 */
	private $transitions = array();

	/**
	 * Metric keys of wplc/health_alert events.
	 *
	 * @var string[]
	 */
	private $alerts = array();

	public function set_up(): void {
		parent::set_up();
		$this->adapter     = new WPLC_Test_Scripted_Adapter();
		$this->transitions = array();
		$this->alerts      = array();
		// Put the scripted adapter first, so it also wins for the gateway ID "stripe".
		add_filter(
			'wplc_recurring_gateway_adapters',
			function ( $adapters ) {
				return array_merge( array( $this->adapter ), is_array( $adapters ) ? $adapters : array() );
			},
			99
		);
		add_action(
			'wplc/license_status_changed',
			function ( $license_id, $old_status, $new_status, $action ) {
				$this->transitions[] = array( (string) $old_status, (string) $new_status, (string) $action );
			},
			1,
			4
		);
		add_action(
			'wplc/health_alert',
			function ( $metric ) {
				$this->alerts[] = (string) $metric;
			},
			10,
			3
		);
	}

	/**
	 * A renewing license whose paid period ended an hour ago, with a saved token and a due renewal.
	 *
	 * @param array<string,mixed> $overrides License column overrides.
	 * @return array{license_id:int,renewal_id:int,customer:int,paid_until:string}
	 */
	private function fixture( array $overrides = array() ): array {
		$customer = self::factory()->user->create( array( 'role' => 'customer' ) );
		$source   = $this->create_order( array( array( $this->create_simple_product( '49.00' ), 1 ) ), $customer );

		$token = new WC_Payment_Token_CC();
		$token->set_token( 'tok_' . wp_generate_password( 10, false ) );
		$token->set_gateway_id( $this->adapter->gateway );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( (string) ( (int) gmdate( 'Y' ) + 2 ) );
		$token->set_user_id( $customer );
		$this->assertGreaterThan( 0, $token->save() );

		$paid_until = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
		$license_id = $this->seed_license(
			array_merge(
				array(
					'customer_id'          => $customer,
					'paid_until'           => $paid_until,
					'next_payment_at'      => $paid_until,
					'renewal_enabled'      => 1,
					'payment_gateway_id'   => $this->adapter->gateway,
					'payment_token_id'     => $token->get_id(),
					'renewal_amount_minor' => 4900,
					'renewal_currency'     => get_woocommerce_currency(),
					'source_order_id'      => $source->get_id(),
					'source_order_item_id' => $this->first_item( $source )->get_id(),
				),
				$overrides
			)
		)['id'];
		$renewal_id = RenewalScheduler::schedule_next( $license_id );
		$this->assertGreaterThan( 0, $renewal_id, 'Fixture: no renewal row was scheduled.' );

		return array(
			'license_id' => $license_id,
			'renewal_id' => $renewal_id,
			'customer'   => $customer,
			'paid_until' => $paid_until,
		);
	}

	/**
	 * One renewal row.
	 *
	 * @param int $renewal_id Renewal ID.
	 * @return array<string,mixed>
	 */
	private function renewal( int $renewal_id ): array {
		global $wpdb;
		$tables = $this->tables();
		$row    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['renewals']} WHERE id = %d", $renewal_id ), ARRAY_A );
		$this->assertIsArray( $row );

		return $row;
	}

	/**
	 * Audit actions recorded for a renewal.
	 *
	 * @param int $renewal_id Renewal ID.
	 * @return string[]
	 */
	private function audit_actions( int $renewal_id ): array {
		global $wpdb;
		$tables = $this->tables();

		return (array) $wpdb->get_col( $wpdb->prepare( "SELECT action FROM {$tables['audit_log']} WHERE object_type = %s AND object_id = %d ORDER BY id ASC", 'renewal', $renewal_id ) );
	}

	/**
	 * New statuses the license was moved to.
	 *
	 * @return string[]
	 */
	private function new_statuses(): array {
		return array_column( $this->transitions, 1 );
	}

	/**
	 * Run the first attempt with an unknown result.
	 *
	 * @param array<string,mixed> $overrides License column overrides.
	 * @return array{license_id:int,renewal_id:int,customer:int,paid_until:string}
	 */
	private function fixture_with_unknown_result( array $overrides = array() ): array {
		$fixture                = $this->fixture( $overrides );
		$this->adapter->results = array( 'unknown' );
		RenewalProcessor::process( $fixture['renewal_id'] );
		$this->assertSame( RenewalProcessor::STATUS_RECONCILING, $this->renewal( $fixture['renewal_id'] )['status'], 'Fixture: the unknown result was not recorded.' );

		return $fixture;
	}

	/**
	 * Move the start of the unknown result into the past.
	 *
	 * @param int $renewal_id Renewal ID.
	 * @param int $seconds Seconds.
	 * @return void
	 */
	private function age_unknown_result( int $renewal_id, int $seconds ): void {
		$renewal = $this->renewal( $renewal_id );
		$order   = wc_get_order( (int) $renewal['renewal_order_id'] );
		$order->update_meta_data( RenewalProcessor::META_RESULT_UNKNOWN_SINCE, (int) $renewal['attempt_count'] . '|' . ( time() - $seconds ) );
		$order->save();
	}

	public function test_unknown_result_is_not_a_decline_and_keeps_the_license_untouched(): void {
		$fixture = $this->fixture_with_unknown_result();

		$renewal = $this->renewal( $fixture['renewal_id'] );
		$license = $this->license( $fixture['license_id'] );
		$order   = wc_get_order( (int) $renewal['renewal_order_id'] );

		$this->assertSame( '0', (string) $renewal['attempt_count'], 'An unknown result must not count as an attempt.' );
		$this->assertEqualsWithDelta( time() + RenewalProcessor::RESULT_UNKNOWN_CHECK_INTERVAL, strtotime( $renewal['retry_at'] . ' UTC' ), 120 );
		$this->assertSame( 'active', $license['status'], 'The license must not become past_due while the result is unknown.' );
		$this->assertNotContains( 'past_due', $this->new_statuses(), 'No past_due transition, therefore no "payment failed" email.' );
		$this->assertTrue( LicenseLifecycle::has_access( $license ), 'The license keeps access while the result is unknown.' );
		$this->assertNotSame( 'failed', $order->get_status(), 'The order must not be marked failed.' );
		$this->assertSame( $fixture['paid_until'], $license['paid_until'] );
		$this->assertContains( 'renewal_result_unknown', $this->audit_actions( $fixture['renewal_id'] ) );
		$this->assertGreaterThan( 0, RenewalProcessor::unknown_since_from_meta( (string) $order->get_meta( RenewalProcessor::META_RESULT_UNKNOWN_SINCE, true ), 0 ) );
		$this->assertFalse( ManualRenewal::can_renew( $license ), 'No manual renewal while the automatic charge may have gone through.' );
	}

	public function test_reconciliation_check_reuses_the_key_and_books_a_success(): void {
		$fixture                = $this->fixture_with_unknown_result();
		$this->adapter->results = array( 'success' );

		RenewalProcessor::process( $fixture['renewal_id'] );

		$this->assertCount( 2, $this->adapter->keys );
		$this->assertSame( $this->adapter->keys[0], $this->adapter->keys[1], 'A check must never use a new idempotency key.' );
		$this->assertStringEndsWith( ':0', $this->adapter->keys[1] );
		$renewal = $this->renewal( $fixture['renewal_id'] );
		$this->assertSame( 'paid', $renewal['status'] );
		$license = $this->license( $fixture['license_id'] );
		$this->assertSame( 'active', $license['status'] );
		$this->assertSame( $renewal['period_end'], $license['paid_until'] );
		$this->assertNotContains( 'past_due', $this->new_statuses() );
	}

	public function test_check_that_reveals_a_decline_follows_the_normal_failure_path(): void {
		$fixture                = $this->fixture_with_unknown_result();
		$this->adapter->results = array( 'decline', 'success' );

		RenewalProcessor::process( $fixture['renewal_id'] );

		$renewal = $this->renewal( $fixture['renewal_id'] );
		$this->assertSame( $this->adapter->keys[0], $this->adapter->keys[1] );
		$this->assertSame( 'failed', $renewal['status'] );
		$this->assertSame( '1', (string) $renewal['attempt_count'] );
		$this->assertSame( 'past_due', $this->license( $fixture['license_id'] )['status'], 'Only a definite failure makes the license past_due.' );
		$this->assertSame( 'failed', wc_get_order( (int) $renewal['renewal_order_id'] )->get_status() );

		// The next retry, after the definite failure, is a new request.
		RenewalProcessor::process( $fixture['renewal_id'] );
		$this->assertCount( 3, $this->adapter->keys );
		$this->assertStringEndsWith( ':1', $this->adapter->keys[2] );
	}

	public function test_repeated_unknown_results_keep_the_original_window(): void {
		$fixture = $this->fixture_with_unknown_result();
		$this->age_unknown_result( $fixture['renewal_id'], 3 * HOUR_IN_SECONDS );
		$this->adapter->results = array( 'unknown' );

		RenewalProcessor::process( $fixture['renewal_id'] );

		$renewal = $this->renewal( $fixture['renewal_id'] );
		$this->assertSame( RenewalProcessor::STATUS_RECONCILING, $renewal['status'] );
		$this->assertSame( '0', (string) $renewal['attempt_count'] );
		$since = RenewalProcessor::unknown_since_from_meta( (string) wc_get_order( (int) $renewal['renewal_order_id'] )->get_meta( RenewalProcessor::META_RESULT_UNKNOWN_SINCE, true ), 0 );
		$this->assertEqualsWithDelta( time() - 3 * HOUR_IN_SECONDS, $since, 5, 'A further unknown result must not restart the window.' );
		$this->assertSame( 1, count( array_keys( $this->audit_actions( $fixture['renewal_id'] ), 'renewal_result_unknown', true ) ), 'Entering the state is audited once.' );
	}

	public function test_result_still_unknown_after_the_window_goes_to_review_without_a_charge(): void {
		$fixture = $this->fixture_with_unknown_result();
		$this->age_unknown_result( $fixture['renewal_id'], RenewalProcessor::RESULT_UNKNOWN_WINDOW + 60 );
		$this->adapter->results = array( 'success' );

		RenewalProcessor::process( $fixture['renewal_id'] );

		$this->assertCount( 1, $this->adapter->keys, 'Past the window nothing may be sent again, not even with the same key.' );
		$renewal = $this->renewal( $fixture['renewal_id'] );
		$this->assertSame( RenewalProcessor::STATUS_REVIEW, $renewal['status'] );
		$this->assertNull( $renewal['retry_at'] );
		$this->assertContains( 'renewal_review_required', $this->audit_actions( $fixture['renewal_id'] ) );
		$this->assertContains( 'review_required_renewals', $this->alerts );
		$this->assertSame( 'active', $this->license( $fixture['license_id'] )['status'] );
		$this->assertNotContains( 'past_due', $this->new_statuses() );

		// Later jobs do not charge a renewal under review.
		RenewalProcessor::process( $fixture['renewal_id'] );
		RenewalProcessor::requeue_overdue_renewals();
		$this->assertCount( 1, $this->adapter->keys );
	}

	public function test_customer_cancellation_during_reconciliation_goes_to_review_without_a_charge(): void {
		$fixture = $this->fixture_with_unknown_result();
		$this->assertTrue( LicenseLifecycle::schedule_cancellation( $fixture['license_id'], 'test', $fixture['customer'] ) );

		RenewalProcessor::process( $fixture['renewal_id'] );

		$this->assertCount( 1, $this->adapter->keys );
		$this->assertSame( RenewalProcessor::STATUS_REVIEW, $this->renewal( $fixture['renewal_id'] )['status'] );
	}

	public function test_crash_detected_after_the_window_goes_to_review_without_a_charge(): void {
		global $wpdb;
		$tables  = $this->tables();
		$fixture = $this->fixture();
		$order   = \WPLC\Billing\RenewalOrder::get_or_create( $this->renewal( $fixture['renewal_id'] ), $this->license( $fixture['license_id'] ), false );
		$this->assertInstanceOf( WC_Order::class, $order );
		// The request died in `processing` long ago and its stuck check was lost.
		$wpdb->update(
			$tables['renewals'],
			array(
				'status'     => 'processing',
				'updated_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS - HOUR_IN_SECONDS ),
			),
			array( 'id' => $fixture['renewal_id'] )
		);

		RenewalProcessor::reconcile_stuck_renewals();

		$this->assertSame( RenewalProcessor::STATUS_REVIEW, $this->renewal( $fixture['renewal_id'] )['status'] );
		$this->assertCount( 0, $this->adapter->keys );
		$this->assertSame( 'active', $this->license( $fixture['license_id'] )['status'] );
	}

	public function test_recent_crash_is_reconciled_without_touching_the_license(): void {
		global $wpdb;
		$tables  = $this->tables();
		$fixture = $this->fixture();
		$order   = \WPLC\Billing\RenewalOrder::get_or_create( $this->renewal( $fixture['renewal_id'] ), $this->license( $fixture['license_id'] ), false );
		$this->assertInstanceOf( WC_Order::class, $order );
		$wpdb->update(
			$tables['renewals'],
			array(
				'status'     => 'processing',
				'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 20 * MINUTE_IN_SECONDS ),
			),
			array( 'id' => $fixture['renewal_id'] )
		);

		RenewalProcessor::reconcile_stuck_renewal( $fixture['renewal_id'] );

		$this->assertSame( RenewalProcessor::STATUS_RECONCILING, $this->renewal( $fixture['renewal_id'] )['status'] );
		$this->assertSame( 'active', $this->license( $fixture['license_id'] )['status'] );
		$this->assertCount( 0, $this->adapter->keys, 'The stuck check itself never charges.' );
	}

	public function test_admin_marking_the_order_failed_resolves_review_as_a_decline(): void {
		$fixture = $this->fixture_with_unknown_result();
		$this->age_unknown_result( $fixture['renewal_id'], RenewalProcessor::RESULT_UNKNOWN_WINDOW + 60 );
		RenewalProcessor::process( $fixture['renewal_id'] );
		$this->assertSame( RenewalProcessor::STATUS_REVIEW, $this->renewal( $fixture['renewal_id'] )['status'] );
		$order_id = (int) $this->renewal( $fixture['renewal_id'] )['renewal_order_id'];

		wc_get_order( $order_id )->update_status( 'failed', 'Checked in the gateway: not charged.' );
		RenewalProcessor::resolve_failed_renewal_order( $order_id );

		$renewal = $this->renewal( $fixture['renewal_id'] );
		$this->assertSame( 'failed', $renewal['status'] );
		$this->assertSame( '1', (string) $renewal['attempt_count'], 'The confirmed decline counts, so the next attempt gets a new key.' );
		$this->assertNotNull( $renewal['retry_at'] );
		$this->assertSame( 'past_due', $this->license( $fixture['license_id'] )['status'] );
		$this->assertContains( 'renewal_result_resolved', $this->audit_actions( $fixture['renewal_id'] ) );
	}

	public function test_admin_marking_the_order_paid_books_the_renewal_under_review(): void {
		$fixture = $this->fixture_with_unknown_result();
		$this->age_unknown_result( $fixture['renewal_id'], RenewalProcessor::RESULT_UNKNOWN_WINDOW + 60 );
		RenewalProcessor::process( $fixture['renewal_id'] );
		$order_id = (int) $this->renewal( $fixture['renewal_id'] )['renewal_order_id'];

		wc_get_order( $order_id )->payment_complete( 'txn_found_in_gateway' );
		RenewalProcessor::reconcile_paid_renewal_order( $order_id );

		$renewal = $this->renewal( $fixture['renewal_id'] );
		$this->assertSame( 'paid', $renewal['status'] );
		$this->assertSame( $renewal['period_end'], $this->license( $fixture['license_id'] )['paid_until'] );
		$this->assertCount( 1, $this->adapter->keys );
	}

	public function test_revoking_the_license_sends_an_open_reconciliation_to_review(): void {
		$fixture = $this->fixture_with_unknown_result();

		$this->assertTrue( LicenseLifecycle::admin_set_status( $fixture['license_id'], 'revoked', 'test', 1 ) );

		$this->assertSame( RenewalProcessor::STATUS_REVIEW, $this->renewal( $fixture['renewal_id'] )['status'], 'A payment that may have been taken must be checked by hand.' );
	}

	public function test_stripe_success_webhook_resolves_a_reconciling_renewal(): void {
		if ( ! class_exists( 'WC_Stripe_Helper' ) ) {
			require_once __DIR__ . '/helpers/StripeHelperStub.php';
		}
		$this->adapter->gateway = 'stripe';
		$fixture                = $this->fixture_with_unknown_result();
		$renewal                = $this->renewal( $fixture['renewal_id'] );
		$order                  = wc_get_order( (int) $renewal['renewal_order_id'] );
		$this->assertSame( 'stripe', $order->get_payment_method() );

		$body      = (string) wp_json_encode(
			array(
				'id'   => 'evt_t136_success',
				'type' => 'payment_intent.succeeded',
				'data' => array(
					'object' => array(
						'id'              => 'pi_t136',
						'status'          => 'succeeded',
						'amount_received' => WC_Stripe_Helper::get_stripe_amount( $order->get_total(), $order->get_currency() ),
						'currency'        => strtolower( $order->get_currency() ),
						'metadata'        => array(
							'wplc_renewal_id' => (string) $fixture['renewal_id'],
							'wplc_license_id' => (string) $fixture['license_id'],
							'order_id'        => (string) $order->get_id(),
						),
					),
				),
			)
		);
		$timestamp = time();
		$request   = new WP_REST_Request( 'POST', '/wplc/v1/webhooks/stripe' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'Stripe-Signature', 't=' . $timestamp . ',v1=' . hash_hmac( 'sha256', $timestamp . '.' . $body, WPLC_STRIPE_WEBHOOK_SECRET ) );
		$request->set_body( $body );
		$response = $this->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );

		global $wpdb;
		$tables   = $this->tables();
		$inbox_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$tables['event_inbox']} WHERE source_event_id = %s", 'evt_t136_success' ) );
		RenewalProcessor::process_stripe_event( $inbox_id );

		$this->assertSame( 'processed', $wpdb->get_var( $wpdb->prepare( "SELECT outcome FROM {$tables['event_inbox']} WHERE id = %d", $inbox_id ) ) );
		$renewal = $this->renewal( $fixture['renewal_id'] );
		$this->assertSame( 'paid', $renewal['status'] );
		$this->assertSame( $renewal['period_end'], $this->license( $fixture['license_id'] )['paid_until'] );

		// The queued reconciliation check finds the renewal paid and sends nothing.
		RenewalProcessor::process( $fixture['renewal_id'] );
		$this->assertCount( 1, $this->adapter->keys );
	}
}
