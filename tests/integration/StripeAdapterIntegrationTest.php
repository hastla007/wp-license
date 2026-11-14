<?php
/**
 * Stripe renewal adapter against a fake of the gateway's API class.
 *
 * Nothing here talks to Stripe: `WC_Stripe_API` is a test double whose
 * answers the test chooses (tests/integration/helpers/StripeApiStub.php). The
 * tests prove what the plugin does with an answer, not that Stripe gives it.
 *
 * @package WPLC
 */

use WPLC\Billing\RenewalProcessor;
use WPLC\Gateway\PaymentReference;
use WPLC\Gateway\RenewalOrderEmails;
use WPLC\Gateway\StripeRecurringAdapter;

require_once __DIR__ . '/helpers/RenewalTestCase.php';
require_once __DIR__ . '/helpers/StripeHelperStub.php';

final class StripeAdapterIntegrationTest extends WPLC_Renewal_TestCase {

	/** @var string Mode the fake gateway runs in. */
	private $mode = 'live';

	public function set_up(): void {
		parent::set_up();
		require_once __DIR__ . '/helpers/StripeApiStub.php';
		WC_Stripe_API::reset();
		$this->mode = 'live';
		add_filter(
			'wplc_stripe_mode',
			function () {
				return $this->mode;
			}
		);
	}

	// ------------------------------------------------------------------
	// Fixtures.
	// ------------------------------------------------------------------

	private function stripe_customer(): int {
		$customer = $this->customer();
		update_user_option( $customer, '_stripe_customer_id', 'cus_' . $customer );

		return $customer;
	}

	private function card( int $customer, string $payment_method, bool $default = false ): int {
		$token = new WC_Payment_Token_CC();
		$token->set_token( $payment_method );
		$token->set_gateway_id( 'stripe' );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2035' );
		$token->set_user_id( $customer );
		$token->set_default( $default );
		$token->save();

		return (int) $token->get_id();
	}

	/**
	 * Buy a license with the Stripe gateway and consent.
	 *
	 * @return array<string,mixed> License row.
	 */
	private function buy_with_stripe( int $customer, string $paid_with, string $payment_type = 'card' ): array {
		$order = wc_create_order( array( 'customer_id' => $customer ) );
		$order->add_product( $this->create_license_product( array(), '49.00' ), 1 );
		$order->set_address(
			array(
				'country'  => 'DE',
				'postcode' => '10115',
				'city'     => 'Berlin',
				'email'    => 'customer' . $customer . '@example.test',
			),
			'billing'
		);
		$order->set_payment_method( 'stripe' );
		$order->update_meta_data( '_wplc_recurring_consent_at', gmdate( 'Y-m-d H:i:s' ) );
		$order->update_meta_data( '_stripe_source_id', $paid_with );
		$order->update_meta_data( '_stripe_upe_payment_type', $payment_type );
		$order->calculate_totals();
		$order->save();
		$order->payment_complete( 'ch_first_' . $order->get_id() );
		$rows = $this->licenses_for_order( $order->get_id() );
		$this->assertCount( 1, $rows, 'Fixture: no license was issued.' );

		return $rows[0];
	}

	/**
	 * A due renewal of a license bought with a Stripe card.
	 *
	 * @return array{license_id:int,renewal_id:int,customer:int,token:int,period_end:string}
	 */
	private function due_renewal( string $payment_method = 'pm_card_1' ): array {
		$customer = $this->stripe_customer();
		$token    = $this->card( $customer, $payment_method );
		$license  = $this->buy_with_stripe( $customer, $payment_method );
		$this->assertSame( '1', (string) $license['renewal_enabled'], 'Fixture: automatic renewal was not enabled.' );
		$renewal = $this->move_due_date( (int) $license['id'], -60 );

		return array(
			'license_id' => (int) $license['id'],
			'renewal_id' => (int) $renewal['id'],
			'customer'   => $customer,
			'token'      => $token,
			'period_end' => (string) $renewal['period_end'],
		);
	}

	private function renewal_order( int $renewal_id ): WC_Order {
		$order = wc_get_order( (int) $this->renewal( $renewal_id )['renewal_order_id'] );
		$this->assertInstanceOf( WC_Order::class, $order, 'The renewal has no order.' );

		return $order;
	}

	/**
	 * A PaymentIntent as Stripe would return it.
	 *
	 * @param array<string,mixed> $overrides Field overrides.
	 */
	private function intent( string $status = 'succeeded', array $overrides = array() ): stdClass {
		return (object) array_merge(
			array(
				'id'             => 'pi_new_1',
				'object'         => 'payment_intent',
				'status'         => $status,
				'amount'         => 4900,
				'currency'       => strtolower( get_woocommerce_currency() ),
				'latest_charge'  => 'ch_new_1',
				'payment_method' => 'pm_card_1',
				'created'        => time(),
			),
			$overrides
		);
	}

	/**
	 * A Stripe error answer.
	 *
	 * @param array<string,mixed> $error Error fields.
	 */
	private function error( array $error ): stdClass {
		$error = array_merge(
			array(
				'type'           => 'card_error',
				'code'           => 'card_declined',
				'payment_intent' => (object) array(
					'id'     => 'pi_failed_1',
					'status' => 'requires_payment_method',
				),
			),
			$error
		);

		return (object) array( 'error' => (object) $error );
	}

	/**
	 * Metadata the adapter would have sent for the renewal's current attempt.
	 *
	 * @return stdClass
	 */
	private function own_metadata( int $renewal_id, int $license_id, WC_Order $order ): stdClass {
		return (object) array(
			'order_id'        => (string) $order->get_id(),
			'wplc_renewal_id' => (string) $renewal_id,
			'wplc_license_id' => (string) $license_id,
			'wplc_period'     => (string) $this->renewal( $renewal_id )['period_start'],
			'wplc_site'       => StripeRecurringAdapter::site_id(),
		);
	}

	private function posts(): int {
		return count( WC_Stripe_API::$requests );
	}

	/**
	 * @return string[]
	 */
	private function notes( int $order_id, bool $customer_only = false ): array {
		$args = array( 'order_id' => $order_id );
		if ( $customer_only ) {
			$args['type'] = 'customer';
		}

		return array_map(
			static function ( $note ) {
				return (string) $note->content;
			},
			wc_get_order_notes( $args )
		);
	}

	// ------------------------------------------------------------------
	// B-57, B-78: what the license is renewed with.
	// ------------------------------------------------------------------

	/** B-57: customer, payment method and mode of the purchase are stored on the license. */
	public function test_b57_issue_stores_customer_payment_method_and_mode(): void {
		$customer = $this->stripe_customer();
		$token    = $this->card( $customer, 'pm_card_1' );

		$license = $this->buy_with_stripe( $customer, 'pm_card_1' );

		$this->assertSame( (string) $token, (string) $license['payment_token_id'] );
		$this->assertSame( 'cus_' . $customer, $license['gateway_customer_ref'] );
		$this->assertSame( 'pm_card_1', $license['gateway_method_ref'] );
		$this->assertSame( 'live', $license['gateway_mode'] );
	}

	/** B-57: the gateway recreated the token row; the renewal finds the card by its pm_ ID. */
	public function test_b57_recreated_token_row_is_found_by_payment_method(): void {
		$f = $this->due_renewal();
		WC_Payment_Tokens::delete( $f['token'] );
		global $wpdb;
		// Whatever other listeners did on deletion: the license still renews and points at the old row.
		$wpdb->update( $this->tables()['licenses'], array( 'renewal_enabled' => 1, 'payment_token_id' => $f['token'] ), array( 'id' => $f['license_id'] ) );
		$this->move_due_date( $f['license_id'], -60 );
		$renewal_id = (int) $this->renewals_of( $f['license_id'] )[0]['id'];
		$new_token  = $this->card( $f['customer'], 'pm_card_1' );
		WC_Stripe_API::$responses[] = $this->intent();

		RenewalProcessor::process( $renewal_id );

		$this->assertSame( 1, $this->posts() );
		$this->assertSame( 'pm_card_1', WC_Stripe_API::$requests[0]['request']['payment_method'] );
		$this->assertSame( 'paid', $this->renewal( $renewal_id )['status'] );
		$this->assertSame( (string) $new_token, (string) $this->license( $f['license_id'] )['payment_token_id'] );
		$this->assertContains( 'payment_method_relinked', $this->audit_actions( 'license', $f['license_id'] ) );
	}

	/** B-57: the gateway's own token clean-up does not switch automatic renewal off. */
	public function test_b57_gateway_token_sync_keeps_renewal_enabled(): void {
		$f = $this->due_renewal();
		$sync = static function ( $tokens ) use ( $f ) {
			// The gateway deletes rows Stripe does not list, inside this filter.
			remove_all_filters( 'woocommerce_get_customer_payment_tokens' );
			WC_Payment_Tokens::delete( $f['token'] );
			return array();
		};
		add_filter( 'woocommerce_get_customer_payment_tokens', $sync, 10, 1 );

		WC_Payment_Tokens::get_customer_tokens( $f['customer'], 'stripe' );

		$license = $this->license( $f['license_id'] );
		$this->assertSame( '1', (string) $license['renewal_enabled'], 'A token row removed by the gateway must not end automatic renewal.' );
		$this->assertSame( '0', (string) $license['payment_token_id'] );
		$this->assertSame( 'pm_card_1', $license['gateway_method_ref'] );
	}

	/** B-57: a license bought in live mode is not charged while the gateway runs in test mode. */
	public function test_b57_changed_gateway_mode_refuses_the_charge_without_counting_an_attempt(): void {
		$f          = $this->due_renewal();
		$this->mode = 'test';

		RenewalProcessor::process( $f['renewal_id'] );

		$this->assertSame( 0, $this->posts() );
		$this->assertSame( array(), WC_Stripe_API::$retrieved, 'Stripe must not even be asked in the wrong mode.' );
		$renewal = $this->renewal( $f['renewal_id'] );
		$this->assertSame( 'scheduled', $renewal['status'] );
		$this->assertSame( '0', (string) $renewal['attempt_count'] );
		$this->assertSame( 'renewal_gateway_mode_mismatch', $renewal['last_error'] );
		$this->assertSame( 'active', $this->license( $f['license_id'] )['status'] );
		$this->assertContains( 'renewal_blocked', $this->audit_actions( 'renewal', $f['renewal_id'] ) );
	}

	/** B-78: a missing payment method is a configuration problem; the default card is not used instead. */
	public function test_b78_missing_payment_method_is_not_replaced_by_the_default_card(): void {
		$f = $this->due_renewal();
		global $wpdb;
		WC_Payment_Tokens::delete( $f['token'] );
		$wpdb->update( $this->tables()['licenses'], array( 'renewal_enabled' => 1, 'payment_token_id' => $f['token'] ), array( 'id' => $f['license_id'] ) );
		$this->move_due_date( $f['license_id'], -60 );
		$renewal_id = (int) $this->renewals_of( $f['license_id'] )[0]['id'];
		$this->card( $f['customer'], 'pm_other_default', true );
		$announced = array();
		add_action(
			'wplc/renewal_payment_method_missing',
			static function ( $license_id, $renewal, $customer, $first ) use ( &$announced ) {
				$announced[] = array( (int) $license_id, (int) $renewal, (int) $customer, (bool) $first );
			},
			10,
			4
		);

		RenewalProcessor::process( $renewal_id );
		RenewalProcessor::process( $renewal_id );

		$this->assertSame( 0, $this->posts(), 'No card may be charged.' );
		$renewal = $this->renewal( $renewal_id );
		$this->assertSame( 'scheduled', $renewal['status'] );
		$this->assertSame( '0', (string) $renewal['attempt_count'], 'A missing payment method is not a failed attempt.' );
		$this->assertSame( 'renewal_payment_method_missing', $renewal['last_error'] );
		$this->assertSame( 'active', $this->license( $f['license_id'] )['status'] );
		$this->assertSame( array( $f['license_id'], $renewal_id, $f['customer'], true ), $announced[0] );
		$source_order = (int) $this->license( $f['license_id'] )['source_order_id'];
		$this->assertNotEmpty(
			array_filter(
				$this->notes( $source_order ),
				static function ( $note ) {
					return false !== strpos( $note, 'was not charged' );
				}
			)
		);
	}

	/** B-57: the references can be removed again (for erasure and for ending renewal for good). */
	public function test_b57_references_can_be_cleared(): void {
		$customer = $this->stripe_customer();
		$this->card( $customer, 'pm_card_1' );
		$license = $this->buy_with_stripe( $customer, 'pm_card_1' );

		PaymentReference::clear( (int) $license['id'] );

		$license = $this->license( (int) $license['id'] );
		$this->assertNull( $license['gateway_customer_ref'] );
		$this->assertNull( $license['gateway_method_ref'] );
		$this->assertNull( $license['gateway_mode'] );
	}

	/** B-57 (pure): the decision table of the token lookup. */
	public function test_b57_decision_table(): void {
		$this->assertSame( 'mode_mismatch', PaymentReference::decide( 'live', 'test', true, 'pm_1', 5 ) );
		$this->assertSame( 'stored_token', PaymentReference::decide( 'live', 'live', true, 'pm_1', 0 ) );
		$this->assertSame( 'stored_token', PaymentReference::decide( '', 'test', true, '', 0 ) );
		$this->assertSame( 'token_by_reference', PaymentReference::decide( 'live', 'live', false, 'pm_1', 7 ) );
		$this->assertSame( 'missing', PaymentReference::decide( 'live', 'live', false, 'pm_1', 0 ) );
		$this->assertSame( 'missing', PaymentReference::decide( 'live', '', false, '', 7 ) );
	}

	// ------------------------------------------------------------------
	// B-61: purchase with consent but without a card.
	// ------------------------------------------------------------------

	/** B-61: Link with consent gets no renewal, an order note and a hint for the customer; no other card is taken. */
	public function test_b61_link_purchase_with_consent_is_explained_and_not_renewed_with_another_card(): void {
		$customer = $this->stripe_customer();
		$this->card( $customer, 'pm_old_default', true );

		$license = $this->buy_with_stripe( $customer, 'link_pm_not_a_saved_card', 'link' );

		$this->assertSame( '0', (string) $license['renewal_enabled'] );
		$this->assertSame( '0', (string) $license['payment_token_id'], 'The old default card must not be taken (B-78).' );
		$order_id = (int) $license['source_order_id'];
		$this->assertNotEmpty(
			array_filter(
				$this->notes( $order_id ),
				static function ( $note ) {
					return false !== strpos( $note, '(link)' );
				}
			),
			'The shop must see why the license does not renew.'
		);
		$this->assertCount( 1, $this->notes( $order_id, true ), 'The customer gets exactly one hint.' );
		$this->assertSame( '1', (string) wc_get_order( $order_id )->get_meta( '_wplc_renewal_unavailable_noted', true ) );
	}

	// ------------------------------------------------------------------
	// Charge: request, success, gateway metas.
	// ------------------------------------------------------------------

	/** B-147, B-29, B-149, B-60, A-20: what is sent, and what a success leaves on the order. */
	public function test_successful_charge_sends_gateway_metadata_and_writes_gateway_metas(): void {
		$f = $this->due_renewal();
		WC_Stripe_API::$responses[] = $this->intent();

		RenewalProcessor::process( $f['renewal_id'] );

		$this->assertSame( 1, $this->posts() );
		$sent  = WC_Stripe_API::$requests[0];
		$order = $this->renewal_order( $f['renewal_id'] );
		$this->assertSame( 'payment_intents', $sent['api'] );
		$this->assertSame( 4900, $sent['request']['amount'] );
		$this->assertSame( 'cus_' . $f['customer'], $sent['request']['customer'] );
		$this->assertSame( 'true', $sent['request']['off_session'] );
		$this->assertSame( array( 'card' ), $sent['request']['payment_method_types'] );
		$meta = $sent['request']['metadata'];
		$this->assertSame( (string) $order->get_id(), $meta['order_id'] );
		$this->assertSame( $order->get_order_key(), $meta['order_key'] );
		$this->assertSame( 'recurring', $meta['payment_type'] );
		$this->assertSame( esc_url_raw( get_site_url() ), $meta['site_url'] );
		$this->assertSame(
			sprintf( '%d:%s', $order->get_id(), md5( implode( '-', array( $order->get_id(), $order->get_order_key(), $f['customer'], 4900 ) ) ) ),
			$meta['signature'],
			'Same format as the gateway\'s get_order_signature().'
		);
		$this->assertSame( StripeRecurringAdapter::site_id(), $meta['wplc_site'] );
		$this->assertSame( (string) $f['renewal_id'], $meta['wplc_renewal_id'] );
		$this->assertSame( (string) $this->renewal( $f['renewal_id'] )['period_start'], $meta['wplc_period'] );
		$this->assertStringContainsString( (string) $order->get_order_number(), $sent['request']['description'] );
		$this->assertStringNotContainsString( 'WP License Captain renewal order', $sent['request']['description'] );
		$this->assertNotSame( 'generated-by-gateway', $sent['key'] );
		$this->assertStringStartsWith( (string) $meta['wplc_attempt'] . ':', (string) $sent['key'] );

		$this->assertTrue( $order->is_paid() );
		$this->assertSame( 'ch_new_1', $order->get_transaction_id(), 'The transaction ID is the charge, never the PaymentIntent.' );
		$this->assertSame( 'pi_new_1', $order->get_meta( '_stripe_intent_id', true ) );
		$this->assertSame( 'yes', $order->get_meta( '_stripe_charge_captured', true ) );
		$this->assertSame( 'cus_' . $f['customer'], $order->get_meta( '_stripe_customer_id', true ) );
		$this->assertSame( 'pm_card_1', $order->get_meta( '_stripe_source_id', true ) );
		$this->assertSame( '', (string) $order->get_meta( '_stripe_lock_payment', true ), 'The gateway lock is released after the request.' );
		$this->assertSame( 'ch_new_1', $this->renewal( $f['renewal_id'] )['gateway_attempt_id'] );
		$this->assertSame( $f['period_end'], $this->license( $f['license_id'] )['paid_until'] );
	}

	/** B-60: with an older API version the charge comes in the `charges` list. */
	public function test_b60_charge_id_is_read_from_the_charges_list(): void {
		$intent = (object) array(
			'latest_charge' => null,
			'charges'       => (object) array( 'data' => array( (object) array( 'id' => 'ch_old_api' ) ) ),
		);

		$this->assertSame( 'ch_old_api', StripeRecurringAdapter::charge_id( $intent ) );
		$this->assertSame( 'ch_obj', StripeRecurringAdapter::charge_id( (object) array( 'latest_charge' => (object) array( 'id' => 'ch_obj' ) ) ) );
		$this->assertSame( '', StripeRecurringAdapter::charge_id( (object) array() ) );
	}

	// ------------------------------------------------------------------
	// B-62, A-15, A-16, B-146: errors.
	// ------------------------------------------------------------------

	/**
	 * @return array<string,array{0:array<string,string>,1:string,2:string,3:bool}>
	 */
	public function data_error_classes(): array {
		return array(
			'insufficient funds'  => array( array( 'type' => 'card_error', 'code' => 'card_declined', 'decline_code' => 'insufficient_funds' ), 'declined', 'stripe_insufficient_funds', true ),
			'generic decline'     => array( array( 'type' => 'card_error', 'code' => 'card_declined', 'decline_code' => 'generic_decline' ), 'declined', 'stripe_generic_decline', true ),
			'expired card'        => array( array( 'type' => 'card_error', 'code' => 'expired_card' ), 'declined', 'stripe_expired_card', true ),
			'lost card'           => array( array( 'type' => 'card_error', 'code' => 'card_declined', 'decline_code' => 'lost_card' ), 'declined', 'stripe_lost_card', false ),
			'stolen card'         => array( array( 'type' => 'card_error', 'code' => 'card_declined', 'decline_code' => 'stolen_card' ), 'declined', 'stripe_stolen_card', false ),
			'fraudulent'          => array( array( 'type' => 'card_error', 'code' => 'card_declined', 'decline_code' => 'fraudulent' ), 'declined', 'stripe_fraudulent', false ),
			'pickup card'         => array( array( 'type' => 'card_error', 'code' => 'card_declined', 'decline_code' => 'pickup_card' ), 'declined', 'stripe_pickup_card', false ),
			'do not try again'    => array( array( 'type' => 'card_error', 'code' => 'card_declined', 'decline_code' => 'do_not_honor', 'advice_code' => 'do_not_try_again' ), 'declined', 'stripe_do_not_honor', false ),
			'radar block'         => array( array( 'type' => 'card_error', 'code' => 'card_declined', 'outcome_type' => 'blocked' ), 'declined', 'stripe_blocked', false ),
			'authentication'      => array( array( 'type' => 'card_error', 'code' => 'authentication_required' ), 'authentication', 'renewal_manual_action_required', false ),
			'idempotency error'   => array( array( 'type' => 'idempotency_error' ), 'configuration', 'stripe_idempotency_error', false ),
			'key in use'          => array( array( 'type' => 'invalid_request_error', 'code' => 'idempotency_key_in_use' ), 'unknown', 'renewal_result_unknown', true ),
			'rate limit'          => array( array( 'type' => 'invalid_request_error', 'code' => 'rate_limit' ), 'rate_limited', 'stripe_rate_limited', true ),
			'customer missing'    => array( array( 'type' => 'invalid_request_error', 'code' => 'resource_missing' ), 'configuration', 'stripe_resource_missing', false ),
			'invalid key'         => array( array( 'type' => 'authentication_error' ), 'configuration', 'stripe_authentication_error', false ),
			'api error'           => array( array( 'type' => 'api_error' ), 'unknown', 'renewal_result_unknown', true ),
			'no type at all'      => array( array(), 'unknown', 'renewal_result_unknown', true ),
		);
	}

	/**
	 * B-62, A-15, A-16, B-146 (pure): how a Stripe error is classified.
	 *
	 * @dataProvider data_error_classes
	 *
	 * @param array<string,string> $error Stripe error fields.
	 */
	public function test_error_classification( array $error, string $kind, string $code, bool $retryable ): void {
		$this->assertSame(
			array(
				'kind'      => $kind,
				'code'      => $code,
				'retryable' => $retryable,
			),
			StripeRecurringAdapter::classify_error( $error )
		);
	}

	/** B-62: a stolen card is not tried again; the decline details are kept. */
	public function test_b62_hard_decline_stops_retries_and_keeps_the_codes(): void {
		$f = $this->due_renewal();
		WC_Stripe_API::$responses[] = $this->error(
			array(
				'decline_code'   => 'stolen_card',
				'advice_code'    => 'do_not_try_again',
				'payment_intent' => (object) array(
					'id'            => 'pi_failed_1',
					'status'        => 'requires_payment_method',
					'latest_charge' => (object) array( 'id' => 'ch_f', 'outcome' => (object) array( 'type' => 'issuer_declined' ) ),
				),
			)
		);

		RenewalProcessor::process( $f['renewal_id'] );

		$renewal = $this->renewal( $f['renewal_id'] );
		$this->assertSame( 'manual_action_required', $renewal['status'] );
		$this->assertNull( $renewal['retry_at'] );
		$this->assertSame( 'stripe_stolen_card', $renewal['last_error'] );
		$this->assertSame( 'past_due', $this->license( $f['license_id'] )['status'] );
		$order = $this->renewal_order( $f['renewal_id'] );
		$this->assertSame( 'stolen_card', $order->get_meta( '_wplc_stripe_decline_code', true ) );
		$this->assertSame( 'do_not_try_again', $order->get_meta( '_wplc_stripe_advice_code', true ) );
		$this->assertSame( 'issuer_declined', $order->get_meta( '_wplc_stripe_outcome_type', true ) );
		$this->assertSame( 'pi_failed_1', $order->get_meta( '_wplc_payment_intent_id', true ) );
		$this->assertSame( '', (string) $order->get_meta( '_stripe_intent_id', true ), 'A failed attempt must not look like the gateway\'s own payment.' );
	}

	/** B-62: a soft decline keeps the retry schedule. */
	public function test_b62_soft_decline_is_retried(): void {
		$f = $this->due_renewal();
		WC_Stripe_API::$responses[] = $this->error( array( 'decline_code' => 'insufficient_funds' ) );

		RenewalProcessor::process( $f['renewal_id'] );

		$renewal = $this->renewal( $f['renewal_id'] );
		$this->assertSame( 'failed', $renewal['status'] );
		$this->assertSame( '1', (string) $renewal['attempt_count'] );
		$this->assertNotNull( $renewal['retry_at'] );
		$this->assertSame( 'stripe_insufficient_funds', $renewal['last_error'] );
	}

	/** A-16: Stripe refuses the request (customer unknown in this account): not a declined card. */
	public function test_a16_request_refused_by_stripe_is_a_configuration_problem(): void {
		$f = $this->due_renewal();
		WC_Stripe_API::$responses[] = (object) array( 'error' => (object) array( 'type' => 'invalid_request_error', 'code' => 'resource_missing' ) );
		WC_Stripe_API::$responses[] = $this->intent();

		RenewalProcessor::process( $f['renewal_id'] );

		$renewal = $this->renewal( $f['renewal_id'] );
		$this->assertSame( 'scheduled', $renewal['status'] );
		$this->assertSame( '0', (string) $renewal['attempt_count'] );
		$this->assertSame( 'stripe_resource_missing', $renewal['last_error'] );
		$this->assertSame( 'active', $this->license( $f['license_id'] )['status'], 'The customer is not put in arrears for a shop problem.' );
		$this->assertNotSame( 'failed', $this->renewal_order( $f['renewal_id'] )->get_status() );

		// The shop fixed it: the corrected request must not be answered with Stripe's stored refusal.
		global $wpdb;
		$wpdb->update( $this->tables()['renewals'], array( 'retry_at' => gmdate( 'Y-m-d H:i:s', time() - 5 ) ), array( 'id' => $f['renewal_id'] ) );
		RenewalProcessor::process( $f['renewal_id'] );

		$this->assertSame( 2, $this->posts() );
		$this->assertNotSame( WC_Stripe_API::$requests[0]['key'], WC_Stripe_API::$requests[1]['key'] );
		$this->assertSame( 'paid', $this->renewal( $f['renewal_id'] )['status'] );
	}

	/** B-146: `idempotency_error` can never be solved by repeating; it is reported to the shop. */
	public function test_b146_idempotency_error_is_a_configuration_problem_with_an_alert(): void {
		$f = $this->due_renewal();
		WC_Stripe_API::$responses[] = (object) array( 'error' => (object) array( 'type' => 'idempotency_error' ) );
		$blocked = array();
		add_action(
			'wplc/renewal_blocked',
			static function ( $renewal_id, $code ) use ( &$blocked ) {
				$blocked[] = $code;
			},
			10,
			2
		);

		RenewalProcessor::process( $f['renewal_id'] );

		$this->assertSame( array( 'stripe_idempotency_error' ), $blocked );
		$renewal = $this->renewal( $f['renewal_id'] );
		$this->assertSame( '0', (string) $renewal['attempt_count'] );
		$this->assertSame( 'stripe_idempotency_error', $renewal['last_error'] );
	}

	/** A-15: Stripe's rate limit is neither a failed payment nor something the shop has to fix. */
	public function test_a15_rate_limit_waits_without_counting_an_attempt(): void {
		$f = $this->due_renewal();
		WC_Stripe_API::$responses[] = (object) array( 'error' => (object) array( 'type' => 'invalid_request_error', 'code' => 'rate_limit' ) );

		RenewalProcessor::process( $f['renewal_id'] );

		$renewal = $this->renewal( $f['renewal_id'] );
		$this->assertSame( 'scheduled', $renewal['status'] );
		$this->assertSame( '0', (string) $renewal['attempt_count'] );
		$this->assertSame( 'renewal_gateway_rate_limited', $renewal['last_error'] );
		$this->assertSame( 'active', $this->license( $f['license_id'] )['status'] );
		$this->assertContains( 'renewal_waiting', $this->audit_actions( 'renewal', $f['renewal_id'] ) );
	}

	/** B-146 (pure): a changed card or amount gets its own Stripe key; the same request keeps it. */
	public function test_b146_idempotency_key_is_bound_to_what_is_charged(): void {
		$base = StripeRecurringAdapter::stripe_idempotency_key( 'renewal:0', 'cus_1', 'pm_1', 4900, 'EUR' );

		$this->assertSame( $base, StripeRecurringAdapter::stripe_idempotency_key( 'renewal:0', 'cus_1', 'pm_1', 4900, 'eur' ) );
		$this->assertNotSame( $base, StripeRecurringAdapter::stripe_idempotency_key( 'renewal:0', 'cus_1', 'pm_2', 4900, 'eur' ) );
		$this->assertNotSame( $base, StripeRecurringAdapter::stripe_idempotency_key( 'renewal:0', 'cus_1', 'pm_1', 5900, 'eur' ) );
		$this->assertNotSame( $base, StripeRecurringAdapter::stripe_idempotency_key( 'renewal:0', 'cus_1', 'pm_1', 4900, 'eur', 1 ) );
		$this->assertNotSame( $base, StripeRecurringAdapter::stripe_idempotency_key( 'renewal:1', 'cus_1', 'pm_1', 4900, 'eur' ) );
		$this->assertStringStartsWith( 'renewal:0:', $base );
	}

	// ------------------------------------------------------------------
	// A-114, B-10, B-146: look the payment up before charging.
	// ------------------------------------------------------------------

	/** A-114, B-146: after a timeout the payment is looked up; a payment that went through is booked, not repeated. */
	public function test_a114_unknown_result_is_resolved_by_lookup_instead_of_a_second_charge(): void {
		$f = $this->due_renewal();
		WC_Stripe_API::$responses[] = new RuntimeException( 'timeout' );

		RenewalProcessor::process( $f['renewal_id'] );

		$renewal = $this->renewal( $f['renewal_id'] );
		$this->assertSame( 'failed', $renewal['status'] );
		$this->assertSame( 'renewal_result_unknown', $renewal['last_error'] );
		$this->assertSame( '0', (string) $renewal['attempt_count'] );
		$this->assertSame( 'active', $this->license( $f['license_id'] )['status'] );
		$this->assertSame( '', (string) $this->renewal_order( $f['renewal_id'] )->get_meta( '_stripe_lock_payment', true ), 'The lock is released after a timeout as well.' );

		// The charge had gone through. Stripe finds it by the renewal ID in the metadata.
		$order = $this->renewal_order( $f['renewal_id'] );
		$found = $this->intent( 'succeeded', array( 'id' => 'pi_lost_1', 'latest_charge' => 'ch_lost_1', 'metadata' => $this->own_metadata( $f['renewal_id'], $f['license_id'], $order ) ) );
		WC_Stripe_API::$on_retrieve = static function ( $api ) use ( $found ) {
			return 0 === strpos( $api, 'payment_intents/search' ) ? (object) array( 'data' => array( $found ) ) : (object) array( 'error' => (object) array( 'code' => 'resource_missing' ) );
		};
		global $wpdb;
		$wpdb->update( $this->tables()['renewals'], array( 'retry_at' => gmdate( 'Y-m-d H:i:s', time() - 5 ) ), array( 'id' => $f['renewal_id'] ) );

		RenewalProcessor::process( $f['renewal_id'] );

		$this->assertSame( 1, $this->posts(), 'No second charge request.' );
		$this->assertSame( 'paid', $this->renewal( $f['renewal_id'] )['status'] );
		$order = $this->renewal_order( $f['renewal_id'] );
		$this->assertSame( 'ch_lost_1', $order->get_transaction_id() );
		$this->assertSame( 'pi_lost_1', $order->get_meta( '_wplc_payment_intent_id', true ) );
		$this->assertSame( $f['period_end'], $this->license( $f['license_id'] )['paid_until'] );
	}

	/** A-114: when Stripe has no payment for the attempt, it is repeated with the same key. */
	public function test_a114_unknown_result_without_a_payment_is_repeated_with_the_same_key(): void {
		$f = $this->due_renewal();
		WC_Stripe_API::$responses[] = new RuntimeException( 'timeout' );
		WC_Stripe_API::$responses[] = $this->intent();

		RenewalProcessor::process( $f['renewal_id'] );
		global $wpdb;
		$wpdb->update( $this->tables()['renewals'], array( 'retry_at' => gmdate( 'Y-m-d H:i:s', time() - 5 ) ), array( 'id' => $f['renewal_id'] ) );
		RenewalProcessor::process( $f['renewal_id'] );

		$this->assertSame( 2, $this->posts() );
		$this->assertSame( WC_Stripe_API::$requests[0]['key'], WC_Stripe_API::$requests[1]['key'] );
		$this->assertSame( 'paid', $this->renewal( $f['renewal_id'] )['status'] );
	}

	/** B-10: before the first charge of a period Stripe is asked; a payment unknown to a restored database is booked. */
	public function test_b10_payment_unknown_to_the_database_is_found_before_the_first_charge(): void {
		$f = $this->due_renewal();
		$asked = array();
		$self  = $this;
		WC_Stripe_API::$on_retrieve = static function ( $api ) use ( &$asked, $f, $self ) {
			$asked[] = $api;
			if ( 0 !== strpos( $api, 'payment_intents/search' ) ) {
				return (object) array( 'error' => (object) array( 'code' => 'resource_missing' ) );
			}
			$order = wc_get_order( (int) $self->renewal_row( $f['renewal_id'] )['renewal_order_id'] );

			return (object) array(
				'data' => array(
					(object) array(
						'id'            => 'pi_before_restore',
						'status'        => 'succeeded',
						'amount'        => 4900,
						'currency'      => strtolower( get_woocommerce_currency() ),
						'latest_charge' => 'ch_before_restore',
						'created'       => time() - 3 * DAY_IN_SECONDS,
						'metadata'      => (object) array(
							'order_id'        => '999999',
							'wplc_renewal_id' => (string) $f['renewal_id'],
							'wplc_license_id' => (string) $f['license_id'],
							'wplc_period'     => (string) $self->renewal_row( $f['renewal_id'] )['period_start'],
							'wplc_site'       => StripeRecurringAdapter::site_id(),
						),
					),
				),
			);
		};

		RenewalProcessor::process( $f['renewal_id'] );

		$this->assertSame( 0, $this->posts(), 'The period was paid already; nothing may be charged.' );
		$this->assertCount( 1, $asked );
		$this->assertStringContainsString( rawurlencode( "metadata['wplc_renewal_id']:'" . $f['renewal_id'] . "'" ), $asked[0] );
		$this->assertSame( 'paid', $this->renewal( $f['renewal_id'] )['status'] );
		$this->assertSame( 'ch_before_restore', $this->renewal_order( $f['renewal_id'] )->get_transaction_id() );
	}

	/** Used by closures that need a renewal row. */
	public function renewal_row( int $renewal_id ): array {
		return $this->renewal( $renewal_id );
	}

	/**
	 * B-10, B-29: a payment of another site, another period or another amount is not booked for this renewal.
	 *
	 * @dataProvider data_foreign_intents
	 *
	 * @param array<string,string> $metadata Metadata overrides.
	 */
	public function test_b10_foreign_payment_is_not_adopted( array $metadata, int $amount, string $expected_error ): void {
		$f = $this->due_renewal();
		$self = $this;
		WC_Stripe_API::$on_retrieve = static function ( $api ) use ( $f, $self, $metadata, $amount ) {
			if ( 0 !== strpos( $api, 'payment_intents/search' ) ) {
				return (object) array( 'error' => (object) array( 'code' => 'resource_missing' ) );
			}

			return (object) array(
				'data' => array(
					(object) array(
						'id'            => 'pi_foreign',
						'status'        => 'succeeded',
						'amount'        => $amount,
						'currency'      => strtolower( get_woocommerce_currency() ),
						'latest_charge' => 'ch_foreign',
						'created'       => time(),
						'metadata'      => (object) array_merge(
							array(
								'wplc_renewal_id' => (string) $f['renewal_id'],
								'wplc_license_id' => (string) $f['license_id'],
								'wplc_period'     => (string) $self->renewal_row( $f['renewal_id'] )['period_start'],
								'wplc_site'       => StripeRecurringAdapter::site_id(),
							),
							$metadata
						),
					),
				),
			);
		};
		WC_Stripe_API::$responses[] = $this->intent();

		RenewalProcessor::process( $f['renewal_id'] );

		$order = $this->renewal_order( $f['renewal_id'] );
		$this->assertNotSame( 'ch_foreign', $order->get_transaction_id() );
		if ( '' === $expected_error ) {
			$this->assertSame( 1, $this->posts(), 'The foreign payment says nothing about this renewal; it is charged.' );
			$this->assertSame( 'paid', $this->renewal( $f['renewal_id'] )['status'] );
		} else {
			$this->assertSame( 0, $this->posts() );
			$this->assertSame( $expected_error, $this->renewal( $f['renewal_id'] )['last_error'] );
			$this->assertSame( '0', (string) $this->renewal( $f['renewal_id'] )['attempt_count'] );
		}
	}

	/**
	 * @return array<string,array{0:array<string,string>,1:int,2:string}>
	 */
	public function data_foreign_intents(): array {
		return array(
			'another site'   => array( array( 'wplc_site' => 'ffffffffffffffff' ), 4900, '' ),
			'another period' => array( array( 'wplc_period' => '2001-01-01 00:00:00' ), 4900, '' ),
			'another amount' => array( array(), 1200, 'renewal_amount_mismatch' ),
		);
	}

	/** A-114: when Stripe cannot be asked, nothing is charged and the outcome stays open. */
	public function test_a114_no_charge_when_stripe_cannot_be_asked(): void {
		$f = $this->due_renewal();
		WC_Stripe_API::$on_retrieve = static function () {
			return new WP_Error( 'stripe_error', 'no connection' );
		};
		WC_Stripe_API::$responses[] = $this->intent();

		RenewalProcessor::process( $f['renewal_id'] );

		$this->assertSame( 0, $this->posts() );
		$renewal = $this->renewal( $f['renewal_id'] );
		$this->assertSame( 'renewal_result_unknown', $renewal['last_error'] );
		$this->assertSame( '0', (string) $renewal['attempt_count'] );
		$this->assertSame( 'active', $this->license( $f['license_id'] )['status'] );
	}

	// ------------------------------------------------------------------
	// B-59: the gateway's pay page.
	// ------------------------------------------------------------------

	/**
	 * A renewal whose order exists and failed once, so the customer can pay it.
	 *
	 * @return array{license_id:int,renewal_id:int,customer:int,token:int,period_end:string}
	 */
	private function renewal_with_open_order(): array {
		$f = $this->due_renewal();
		WC_Stripe_API::$responses[] = $this->error( array( 'decline_code' => 'insufficient_funds' ) );
		RenewalProcessor::process( $f['renewal_id'] );
		$this->assertSame( 'failed', $this->renewal( $f['renewal_id'] )['status'], 'Fixture: the first attempt did not fail.' );
		global $wpdb;
		$wpdb->update( $this->tables()['renewals'], array( 'retry_at' => gmdate( 'Y-m-d H:i:s', time() - 5 ) ), array( 'id' => $f['renewal_id'] ) );
		// The quiet period of B-184 is about the order's modification time; move it into the past.
		$order = $this->renewal_order( $f['renewal_id'] );
		$this->age_order( $order );
		WC_Stripe_API::reset();

		return $f;
	}

	private function age_order( WC_Order $order ): void {
		global $wpdb;
		$old = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		if ( $this->hpos_enabled() ) {
			$wpdb->update( $wpdb->prefix . 'wc_orders', array( 'date_updated_gmt' => $old ), array( 'id' => $order->get_id() ) );
		} else {
			$wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => $old, 'post_modified' => $old ), array( 'ID' => $order->get_id() ) );
		}
		clean_post_cache( $order->get_id() );
		wp_cache_flush();
	}

	/** B-59: while the gateway holds its payment lock on the order, the automatic charge waits. */
	public function test_b59_gateway_lock_on_the_order_postpones_the_charge(): void {
		$f     = $this->renewal_with_open_order();
		$order = $this->renewal_order( $f['renewal_id'] );
		$order->update_meta_data( '_stripe_lock_payment', (string) ( time() + 200 ) );
		$order->save();
		$this->age_order( $order );
		WC_Stripe_API::$responses[] = $this->intent();

		RenewalProcessor::process( $f['renewal_id'] );

		$this->assertSame( 0, $this->posts(), 'The customer is in the gateway\'s payment flow.' );
		$renewal = $this->renewal( $f['renewal_id'] );
		$this->assertSame( 'failed', $renewal['status'] );
		$this->assertSame( '1', (string) $renewal['attempt_count'], 'Waiting is not another failed attempt.' );
		$this->assertSame( 'renewal_order_in_use', $renewal['last_error'] );
		$this->assertNotSame( '', (string) $this->renewal_order( $f['renewal_id'] )->get_meta( '_stripe_lock_payment', true ), 'The gateway\'s lock is not removed.' );
	}

	/** B-59: an expired lock does not block. */
	public function test_b59_expired_gateway_lock_does_not_block(): void {
		$f     = $this->renewal_with_open_order();
		$order = $this->renewal_order( $f['renewal_id'] );
		$order->update_meta_data( '_stripe_lock_payment', ( time() - 10 ) . '|pi_gateway_old' );
		$order->save();
		$this->age_order( $order );
		WC_Stripe_API::$responses[] = $this->intent();

		RenewalProcessor::process( $f['renewal_id'] );

		$this->assertSame( 1, $this->posts() );
		$this->assertSame( 'paid', $this->renewal( $f['renewal_id'] )['status'] );
	}

	/**
	 * B-59: the customer's own PaymentIntent on the order decides whether the automatic charge may run.
	 *
	 * @dataProvider data_customer_intent_states
	 */
	public function test_b59_open_customer_intent_postpones_the_charge( string $status, bool $charged ): void {
		$f     = $this->renewal_with_open_order();
		$order = $this->renewal_order( $f['renewal_id'] );
		$order->update_meta_data( '_stripe_intent_id', 'pi_customer_1' );
		$order->save();
		$this->age_order( $order );
		WC_Stripe_API::$on_retrieve = static function ( $api ) use ( $status ) {
			if ( 'payment_intents/pi_customer_1' === $api ) {
				return (object) array( 'id' => 'pi_customer_1', 'status' => $status );
			}
			if ( 0 === strpos( $api, 'payment_intents/search' ) ) {
				return (object) array( 'data' => array() );
			}

			return (object) array( 'id' => 'pi_failed_1', 'status' => 'requires_payment_method', 'metadata' => (object) array() );
		};
		WC_Stripe_API::$responses[] = $this->intent();

		RenewalProcessor::process( $f['renewal_id'] );

		$this->assertSame( $charged ? 1 : 0, $this->posts() );
		$this->assertSame( $charged ? 'paid' : 'failed', $this->renewal( $f['renewal_id'] )['status'] );
		if ( ! $charged ) {
			$this->assertSame( 'renewal_order_in_use', $this->renewal( $f['renewal_id'] )['last_error'] );
		}
	}

	/**
	 * @return array<string,array{0:string,1:bool}>
	 */
	public function data_customer_intent_states(): array {
		return array(
			'3DS open'            => array( 'requires_action', false ),
			'processing'          => array( 'processing', false ),
			'succeeded, not booked yet' => array( 'succeeded', false ),
			'abandoned'           => array( 'requires_payment_method', true ),
			'cancelled'           => array( 'canceled', true ),
		);
	}

	// ------------------------------------------------------------------
	// B-63: `processing` is not "the customer must authenticate".
	// ------------------------------------------------------------------

	/** B-63: a payment Stripe is still processing waits; nobody is asked to authenticate. */
	public function test_b63_processing_payment_waits_and_is_booked_when_it_succeeds(): void {
		$f = $this->due_renewal();
		WC_Stripe_API::$responses[] = $this->intent( 'processing', array( 'id' => 'pi_slow_1', 'latest_charge' => 'py_slow_1' ) );

		RenewalProcessor::process( $f['renewal_id'] );

		$renewal = $this->renewal( $f['renewal_id'] );
		$this->assertSame( 'scheduled', $renewal['status'], 'Not manual_action_required.' );
		$this->assertSame( 'renewal_payment_processing', $renewal['last_error'] );
		$this->assertSame( '0', (string) $renewal['attempt_count'] );
		$this->assertSame( 'active', $this->license( $f['license_id'] )['status'] );
		$this->assertNotContains( 'renewal_manual_action_required', $this->audit_actions( 'renewal', $f['renewal_id'] ) );

		// Next look: Stripe reports the same intent as succeeded.
		$order = $this->renewal_order( $f['renewal_id'] );
		$this->age_order( $order );
		$done = $this->intent( 'succeeded', array( 'id' => 'pi_slow_1', 'latest_charge' => 'py_slow_1', 'metadata' => $this->own_metadata( $f['renewal_id'], $f['license_id'], $order ) ) );
		WC_Stripe_API::$on_retrieve = static function ( $api ) use ( $done ) {
			return 'payment_intents/pi_slow_1' === $api ? $done : (object) array( 'data' => array() );
		};
		global $wpdb;
		$wpdb->update( $this->tables()['renewals'], array( 'retry_at' => gmdate( 'Y-m-d H:i:s', time() - 5 ) ), array( 'id' => $f['renewal_id'] ) );
		RenewalProcessor::process( $f['renewal_id'] );

		$this->assertSame( 1, $this->posts() );
		$this->assertSame( 'paid', $this->renewal( $f['renewal_id'] )['status'] );
		$this->assertSame( 'py_slow_1', $this->renewal_order( $f['renewal_id'] )->get_transaction_id() );
	}

	/** T-12: `requires_action` still stops the automatic attempts. */
	public function test_requires_action_needs_the_customer(): void {
		$f = $this->due_renewal();
		WC_Stripe_API::$responses[] = $this->intent( 'requires_action' );

		RenewalProcessor::process( $f['renewal_id'] );

		$this->assertSame( 'manual_action_required', $this->renewal( $f['renewal_id'] )['status'] );
	}

	// ------------------------------------------------------------------
	// B-01: pause directly before the charge.
	// ------------------------------------------------------------------

	/** B-01: a pause set after the job started still stops the charge request. */
	public function test_b01_pause_is_checked_directly_before_the_charge_request(): void {
		$f = $this->due_renewal();
		WC_Stripe_API::$responses[] = $this->intent();
		$calls = 0;
		add_filter(
			'wplc_billing_paused',
			static function ( $paused ) use ( &$calls ) {
				++$calls;
				// Not paused when the job starts (one pause check: SiteGuard::billing_paused()), paused when the adapter asks.
				return $calls > 1 ? true : $paused;
			}
		);

		RenewalProcessor::process( $f['renewal_id'] );

		$this->assertSame( 2, $calls );
		$this->assertSame( 0, $this->posts() );
		$this->assertSame( array(), WC_Stripe_API::$retrieved );
		$renewal = $this->renewal( $f['renewal_id'] );
		$this->assertSame( 'renewal_billing_paused', $renewal['last_error'] );
		$this->assertSame( '0', (string) $renewal['attempt_count'] );
	}

	// ------------------------------------------------------------------
	// B-58: recovery payment on the pay page.
	// ------------------------------------------------------------------

	/** B-58: the gateway is told to save the card of a renewal order of a renewing license. */
	public function test_b58_card_of_a_renewal_order_is_saved_without_the_checkbox(): void {
		$f     = $this->renewal_with_open_order();
		$order = $this->renewal_order( $f['renewal_id'] );

		$this->assertTrue( apply_filters( 'wc_stripe_force_save_payment_method', false, $order->get_id() ) );

		$other = $this->create_paid_order( array( array( $this->create_simple_product(), 1 ) ), $f['customer'] );
		$this->assertFalse( apply_filters( 'wc_stripe_force_save_payment_method', false, $other->get_id() ), 'Ordinary orders are not touched.' );

		global $wpdb;
		$wpdb->update( $this->tables()['licenses'], array( 'renewal_enabled' => 0 ), array( 'id' => $f['license_id'] ) );
		$this->assertFalse( apply_filters( 'wc_stripe_force_save_payment_method', false, $order->get_id() ), 'Not without automatic renewal.' );
	}

	/** B-58: the card the customer paid the renewal order with becomes the card of future renewals. */
	public function test_b58_card_of_the_recovery_payment_is_used_for_future_renewals(): void {
		$f         = $this->renewal_with_open_order();
		$new_token = $this->card( $f['customer'], 'pm_card_new' );
		$order     = $this->renewal_order( $f['renewal_id'] );
		// What the gateway leaves on the order after a payment on the pay page.
		$order->update_meta_data( '_stripe_source_id', 'pm_card_new' );
		$order->update_meta_data( '_stripe_intent_id', 'pi_customer_1' );
		$order->save();

		$order->payment_complete( 'ch_customer_1' );

		$license = $this->license( $f['license_id'] );
		$this->assertSame( (string) $new_token, (string) $license['payment_token_id'] );
		$this->assertSame( 'pm_card_new', $license['gateway_method_ref'] );
		$this->assertSame( '1', (string) $license['renewal_enabled'] );
		$this->assertContains( 'payment_method_adopted', $this->audit_actions( 'license', $f['license_id'] ) );
	}

	/** B-58: a payment made by the adapter itself does not "adopt" anything. */
	public function test_b58_adapter_payment_does_not_adopt(): void {
		$f = $this->due_renewal();
		WC_Stripe_API::$responses[] = $this->intent();

		RenewalProcessor::process( $f['renewal_id'] );

		$this->assertNotContains( 'payment_method_adopted', $this->audit_actions( 'license', $f['license_id'] ) );
		$this->assertSame( (string) $f['token'], (string) $this->license( $f['license_id'] )['payment_token_id'] );
	}

	// ------------------------------------------------------------------
	// B-60: paid renewal order cancelled.
	// ------------------------------------------------------------------

	/** B-60: a paid renewal order that is cancelled is announced once, to be handled like a refund. */
	public function test_b60_cancelled_paid_renewal_order_fires_the_voided_action_once(): void {
		$f = $this->due_renewal();
		WC_Stripe_API::$responses[] = $this->intent();
		RenewalProcessor::process( $f['renewal_id'] );
		$order  = $this->renewal_order( $f['renewal_id'] );
		$voided = array();
		add_action(
			'wplc/renewal_order_voided',
			static function ( $order_id, $renewal_id, $license_id ) use ( &$voided ) {
				$voided[] = array( (int) $order_id, (int) $renewal_id, (int) $license_id );
			},
			10,
			3
		);

		$order->update_status( 'cancelled' );
		do_action( 'woocommerce_order_status_cancelled', $order->get_id() );

		$this->assertSame( array( array( $order->get_id(), $f['renewal_id'], $f['license_id'] ) ), $voided );
		$this->assertContains( 'renewal_order_voided', $this->audit_actions( 'renewal', $f['renewal_id'] ) );
	}

	/** B-60: an unpaid renewal order that is closed is not a void. */
	public function test_b60_cancelled_unpaid_renewal_order_is_not_announced(): void {
		$f      = $this->renewal_with_open_order();
		$voided = 0;
		add_action(
			'wplc/renewal_order_voided',
			static function () use ( &$voided ) {
				++$voided;
			}
		);

		$this->renewal_order( $f['renewal_id'] )->update_status( 'cancelled' );

		$this->assertSame( 0, $voided );
	}

	// ------------------------------------------------------------------
	// B-149: WooCommerce mails for automatic renewal orders.
	// ------------------------------------------------------------------

	/** B-149: the customer status mails of WooCommerce are off for automatic renewal orders only. */
	public function test_b149_customer_status_mails_are_suppressed_for_automatic_renewal_orders(): void {
		$f = $this->due_renewal();
		WC_Stripe_API::$responses[] = $this->intent();
		RenewalProcessor::process( $f['renewal_id'] );
		$order = $this->renewal_order( $f['renewal_id'] );
		$email = (object) array( 'id' => 'customer_completed_order' );

		foreach ( RenewalOrderEmails::CUSTOMER_EMAIL_IDS as $id ) {
			$this->assertFalse( apply_filters( 'woocommerce_email_enabled_' . $id, true, $order, $email ), $id );
		}
		// Mails to the shop are not filtered at all.
		foreach ( array( 'new_order', 'failed_order', 'cancelled_order' ) as $id ) {
			$this->assertTrue( apply_filters( 'woocommerce_email_enabled_' . $id, true, $order, $email ), $id );
		}
		// An ordinary order and a renewal the customer placed by hand keep their mails.
		$other = $this->create_paid_order( array( array( $this->create_simple_product(), 1 ) ), $f['customer'] );
		$this->assertTrue( apply_filters( 'woocommerce_email_enabled_customer_completed_order', true, $other, $email ) );
		$order->update_meta_data( '_wplc_manual_renewal', 'yes' );
		$this->assertTrue( apply_filters( 'woocommerce_email_enabled_customer_completed_order', true, $order, $email ) );
		$order->delete_meta_data( '_wplc_manual_renewal' );
		// The shop can keep a mail.
		add_filter( 'wplc_suppress_renewal_order_email', '__return_false' );
		$this->assertTrue( apply_filters( 'woocommerce_email_enabled_customer_completed_order', true, $order, $email ) );
	}

	// ------------------------------------------------------------------
	// B-147: the gateway's own webhook.
	// ------------------------------------------------------------------

	/** B-147: the gateway's webhook does not process the adapter's PaymentIntents, but the customer's. */
	public function test_b147_gateway_webhook_skips_adapter_intents_only(): void {
		$f        = $this->renewal_with_open_order();
		$order    = $this->renewal_order( $f['renewal_id'] );
		$statuses = array( 'pending', 'failed' );
		$this->assertSame( 'pi_failed_1', $order->get_meta( '_wplc_payment_intent_id', true ), 'Fixture: the failed attempt left its intent.' );

		$this->assertSame( $statuses, apply_filters( 'wc_stripe_allowed_payment_processing_statuses', $statuses, $order ), 'Outside the gateway\'s webhook request nothing changes.' );

		$inside = null;
		$target = $order->get_id();
		$probe  = static function () use ( &$inside, &$target, $statuses ) {
			$inside = apply_filters( 'wc_stripe_allowed_payment_processing_statuses', $statuses, wc_get_order( (int) $target ) );
		};
		add_action( 'woocommerce_api_wc_stripe', $probe );
		do_action( 'woocommerce_api_wc_stripe' );
		$this->assertSame( array(), $inside );

		// The customer started paying on the pay page: the gateway stored its own intent.
		$order->update_meta_data( '_stripe_intent_id', 'pi_customer_1' );
		$order->save();
		do_action( 'woocommerce_api_wc_stripe' );
		$this->assertSame( $statuses, $inside );

		$ordinary = $this->create_order( array( array( $this->create_simple_product(), 1 ) ), $f['customer'] );
		$target   = $ordinary->get_id();
		do_action( 'woocommerce_api_wc_stripe' );
		$this->assertSame( $statuses, $inside );
	}
}
