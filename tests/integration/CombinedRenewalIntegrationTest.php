<?php
/**
 * B-191: one order and one charge for the automatic renewals of a customer due on the same day.
 *
 * The Stripe adapter runs against the fake gateway API
 * (tests/integration/helpers/StripeApiStub.php); nothing talks to Stripe.
 *
 * @package WPLC
 */

use WPLC\Billing\ManualRenewal;
use WPLC\Billing\RenewalOrder;
use WPLC\Billing\RenewalProcessor;
use WPLC\License\LicenseLifecycle;

require_once __DIR__ . '/helpers/RenewalTestCase.php';
require_once __DIR__ . '/helpers/StripeHelperStub.php';

final class CombinedRenewalIntegrationTest extends WPLC_Renewal_TestCase {

	/** @var array<int,array<string,string>> Emails handed to wp_mail(). */
	private $mails = array();

	public function set_up(): void {
		parent::set_up();
		require_once __DIR__ . '/helpers/StripeApiStub.php';
		WC_Stripe_API::reset();
		update_option( 'woocommerce_currency', 'EUR' );
		$this->mails = array();
		add_filter(
			'wplc_stripe_mode',
			static function () {
				return 'live';
			}
		);
		add_filter(
			'pre_wp_mail',
			function ( $result, $atts ) {
				$this->mails[] = array(
					'subject' => (string) ( $atts['subject'] ?? '' ),
					'message' => (string) ( $atts['message'] ?? '' ),
				);
				return $result;
			},
			5,
			2
		);
	}

	private function combine(): void {
		add_filter( 'wplc_combined_renewals', '__return_true' );
	}

	/**
	 * A Stripe customer with one saved card.
	 *
	 * @return array{customer:int,token:int}
	 */
	private function stripe_customer(): array {
		$customer = $this->customer();
		update_user_option( $customer, '_stripe_customer_id', 'cus_' . $customer );
		$token = new WC_Payment_Token_CC();
		$token->set_token( 'pm_card_' . $customer );
		$token->set_gateway_id( 'stripe' );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2035' );
		$token->set_user_id( $customer );
		$token->save();

		return array( 'customer' => $customer, 'token' => (int) $token->get_id() );
	}

	/**
	 * Buy one license with the card, due $offset seconds from now.
	 *
	 * @return array{license_id:int,renewal_id:int}
	 */
	private function stripe_license( int $customer, string $price = '49.00', int $offset = -60 ): array {
		$order = wc_create_order( array( 'customer_id' => $customer ) );
		$order->add_product( $this->create_license_product( array(), $price ), 1 );
		$order->set_address( array( 'country' => 'DE', 'postcode' => '10115', 'city' => 'Berlin', 'email' => 'customer' . $customer . '@example.test' ), 'billing' );
		$order->set_payment_method( 'stripe' );
		$order->update_meta_data( '_wplc_recurring_consent_at', gmdate( 'Y-m-d H:i:s' ) );
		$order->update_meta_data( '_stripe_source_id', 'pm_card_' . $customer );
		$order->update_meta_data( '_stripe_upe_payment_type', 'card' );
		$order->calculate_totals();
		$order->save();
		$order->payment_complete( 'ch_first_' . $order->get_id() );
		$rows = $this->licenses_for_order( $order->get_id() );
		$this->assertCount( 1, $rows );
		$this->assertSame( '1', (string) $rows[0]['renewal_enabled'], 'Fixture: automatic renewal was not enabled.' );
		$renewal = $this->move_due_date( (int) $rows[0]['id'], $offset );

		return array( 'license_id' => (int) $rows[0]['id'], 'renewal_id' => (int) $renewal['id'] );
	}

	/**
	 * Two licenses of one customer, due a minute apart today.
	 *
	 * @return array{customer:int,a:array<string,int>,b:array<string,int>}
	 */
	private function two_due(): array {
		$c = $this->stripe_customer();
		$a = $this->stripe_license( $c['customer'], '49.00', -120 );
		$b = $this->stripe_license( $c['customer'], '29.00', -60 );
		if ( substr( $this->renewal( $a['renewal_id'] )['period_start'], 0, 10 ) !== substr( $this->renewal( $b['renewal_id'] )['period_start'], 0, 10 ) ) {
			$this->markTestSkipped( 'The two due dates fall on both sides of midnight UTC.' );
		}

		return array( 'customer' => $c['customer'], 'a' => $a, 'b' => $b );
	}

	private function intent( int $amount, string $id = 'pi_combined_1' ): stdClass {
		return (object) array(
			'id'             => $id,
			'object'         => 'payment_intent',
			'status'         => 'succeeded',
			'amount'         => $amount,
			'currency'       => 'eur',
			'latest_charge'  => 'ch_' . $id,
			'payment_method' => 'pm_card_1',
			'created'        => time(),
		);
	}

	private function decline(): stdClass {
		return (object) array(
			'error' => (object) array(
				'type'           => 'card_error',
				'code'           => 'card_declined',
				'payment_intent' => (object) array( 'id' => 'pi_declined_1', 'status' => 'requires_payment_method' ),
			),
		);
	}

	private function order_of( int $renewal_id ): WC_Order {
		$order = wc_get_order( (int) $this->renewal( $renewal_id )['renewal_order_id'] );
		$this->assertInstanceOf( WC_Order::class, $order, 'The renewal has no order.' );

		return $order;
	}

	private function due_now( int $renewal_id ): void {
		global $wpdb;
		$wpdb->update( $this->tables()['renewals'], array( 'retry_at' => gmdate( 'Y-m-d H:i:s', time() - 5 ) ), array( 'id' => $renewal_id ) );
	}

	/** @return string[] Subjects of the "renewed" emails. */
	private function renewed_mails(): array {
		return array_values(
			array_filter(
				$this->mails,
				static function ( $mail ) {
					return false !== stripos( $mail['message'], 'has been renewed' );
				}
			)
		);
	}

	// ------------------------------------------------------------------

	public function test_off_by_default_every_license_is_charged_on_its_own(): void {
		$f = $this->two_due();
		WC_Stripe_API::$responses[] = $this->intent( 4900, 'pi_a' );
		WC_Stripe_API::$responses[] = $this->intent( 2900, 'pi_b' );

		RenewalProcessor::process( $f['a']['renewal_id'] );
		RenewalProcessor::process( $f['b']['renewal_id'] );

		$this->assertCount( 2, WC_Stripe_API::$requests );
		$this->assertSame( 4900, WC_Stripe_API::$requests[0]['request']['amount'] );
		$this->assertSame( 2900, WC_Stripe_API::$requests[1]['request']['amount'] );
		$this->assertNotSame( $this->renewal( $f['a']['renewal_id'] )['renewal_order_id'], $this->renewal( $f['b']['renewal_id'] )['renewal_order_id'] );
		$this->assertFalse( RenewalOrder::is_combined( $this->order_of( $f['a']['renewal_id'] ) ) );
		$this->assertCount( 2, $this->renewed_mails(), 'One email per license, as before.' );
	}

	public function test_success_one_order_one_charge_one_email(): void {
		$this->combine();
		$f = $this->two_due();
		WC_Stripe_API::$responses[] = $this->intent( 7800 );

		RenewalProcessor::process( $f['a']['renewal_id'] );
		RenewalProcessor::process( $f['b']['renewal_id'] );

		$this->assertCount( 1, WC_Stripe_API::$requests, 'One charge for both licenses.' );
		$this->assertSame( 7800, WC_Stripe_API::$requests[0]['request']['amount'] );
		$order = $this->order_of( $f['a']['renewal_id'] );
		$this->assertTrue( RenewalOrder::is_combined( $order ) );
		$this->assertCount( 2, $order->get_items( 'line_item' ) );
		$this->assertSame( (int) $order->get_id(), (int) $this->renewal( $f['b']['renewal_id'] )['renewal_order_id'] );
		$this->assertSame( array( $f['a']['renewal_id'] => $f['a']['license_id'], $f['b']['renewal_id'] => $f['b']['license_id'] ), RenewalOrder::links( $order ) );
		$this->assertTrue( RenewalOrder::net_matches( $order ) );
		$this->assertTrue( $order->is_paid() );
		foreach ( array( 'a', 'b' ) as $key ) {
			$renewal = $this->renewal( $f[ $key ]['renewal_id'] );
			$this->assertSame( 'paid', $renewal['status'] );
			$this->assertSame( $renewal['period_end'], $this->license( $f[ $key ]['license_id'] )['paid_until'] );
			$this->assertCount( 2, $this->renewals_of( $f[ $key ]['license_id'] ), 'The next period of each license is scheduled on its own.' );
		}
		$renewed = $this->renewed_mails();
		$this->assertCount( 1, $renewed, 'One renewal email for the combined payment.' );
		$this->assertStringContainsString( 'also renewed these licenses', $renewed[0]['message'] );

		// A repeated paid-order reconciliation books nothing twice.
		RenewalProcessor::reconcile_paid_renewal_order( (int) $order->get_id() );
		$this->assertCount( 1, WC_Stripe_API::$requests );
		$this->assertSame( 'paid', $this->renewal( $f['b']['renewal_id'] )['status'] );
	}

	/** SET-LIC-41: the most licenses per combined order is a setting. */
	public function test_the_largest_number_of_lines_follows_the_setting(): void {
		$this->combine();
		update_option( \WPLC\Admin\Settings::OPTION, array( 'combined_renewal_max_lines' => '2' ) );
		$f = $this->two_due();
		$c = $this->stripe_license( $f['customer'], '19.00', -30 );
		if ( substr( $this->renewal( $c['renewal_id'] )['period_start'], 0, 10 ) !== substr( $this->renewal( $f['a']['renewal_id'] )['period_start'], 0, 10 ) ) {
			$this->markTestSkipped( 'The due dates fall on both sides of midnight UTC.' );
		}
		$this->assertSame( 2, \WPLC\Billing\CombinedRenewal::max_lines() );
		WC_Stripe_API::$responses[] = $this->intent( 7800 );
		WC_Stripe_API::$responses[] = $this->intent( 4800, 'pi_combined_2' );
		WC_Stripe_API::$responses[] = $this->intent( 1900, 'pi_combined_3' );

		RenewalProcessor::process( $f['a']['renewal_id'] );
		$order = $this->order_of( $f['a']['renewal_id'] );
		$this->assertTrue( RenewalOrder::is_combined( $order ) );
		$this->assertCount( 2, $order->get_items( 'line_item' ), 'Two lines, as set; the third license waits for its own charge.' );
		$this->assertCount( 1, WC_Stripe_API::$requests );
		delete_option( \WPLC\Admin\Settings::OPTION );
	}

	public function test_different_card_currency_or_day_is_never_combined(): void {
		$this->combine();
		$f     = $this->two_due();
		$other = $this->stripe_license( $f['customer'], '19.00', DAY_IN_SECONDS + 3600 );
		global $wpdb;
		$wpdb->update( $this->tables()['licenses'], array( 'renewal_currency' => 'USD' ), array( 'id' => $f['b']['license_id'] ) );
		WC_Stripe_API::$responses[] = $this->intent( 4900 );

		RenewalProcessor::process( $f['a']['renewal_id'] );

		$this->assertCount( 1, WC_Stripe_API::$requests );
		$this->assertSame( 4900, WC_Stripe_API::$requests[0]['request']['amount'], 'Only the license itself.' );
		$this->assertFalse( RenewalOrder::is_combined( $this->order_of( $f['a']['renewal_id'] ) ) );
		$this->assertSame( 'scheduled', $this->renewal( $f['b']['renewal_id'] )['status'] );
		$this->assertSame( 'scheduled', $this->renewal( $other['renewal_id'] )['status'] );
	}

	public function test_decline_counts_on_every_license_and_the_retries_run_per_license(): void {
		$this->combine();
		$f = $this->two_due();
		WC_Stripe_API::$responses[] = $this->decline();

		RenewalProcessor::process( $f['a']['renewal_id'] );

		$this->assertCount( 1, WC_Stripe_API::$requests );
		$combined = wc_get_order( (int) WC_Stripe_API::$requests[0]['request']['metadata']['order_id'] );
		$this->assertTrue( RenewalOrder::is_combined( $combined ) );
		$this->assertSame( 'cancelled', $combined->get_status(), 'The declined combined order is closed; nothing can pay it any more.' );
		foreach ( array( 'a', 'b' ) as $key ) {
			$renewal = $this->renewal( $f[ $key ]['renewal_id'] );
			$this->assertSame( 'failed', $renewal['status'] );
			$this->assertSame( '1', (string) $renewal['attempt_count'] );
			$this->assertEmpty( $renewal['renewal_order_id'] );
			$this->assertSame( 'past_due', $this->license( $f[ $key ]['license_id'] )['status'] );
		}
		$failed = array_values(
			array_filter(
				$this->mails,
				static function ( $mail ) {
					return false !== stripos( $mail['message'], 'could not collect the renewal payment' );
				}
			)
		);
		$this->assertCount( 1, $failed, 'One "payment failed" email for the combined payment.' );
		$this->assertStringContainsString( 'affected in the same way', $failed[0]['message'] );

		// The retry of one license charges that license alone, with an order of its own.
		$this->due_now( $f['b']['renewal_id'] );
		WC_Stripe_API::$responses[] = $this->intent( 2900, 'pi_retry_b' );
		RenewalProcessor::process( $f['b']['renewal_id'] );

		$this->assertCount( 2, WC_Stripe_API::$requests );
		$this->assertSame( 2900, WC_Stripe_API::$requests[1]['request']['amount'] );
		$this->assertNotSame( WC_Stripe_API::$requests[0]['key'], WC_Stripe_API::$requests[1]['key'] );
		$this->assertSame( 'paid', $this->renewal( $f['b']['renewal_id'] )['status'] );
		$this->assertFalse( RenewalOrder::is_combined( $this->order_of( $f['b']['renewal_id'] ) ) );
		$this->assertSame( 'failed', $this->renewal( $f['a']['renewal_id'] )['status'] );
	}

	public function test_unknown_result_waits_and_only_the_leader_repeats_the_same_request(): void {
		$this->combine();
		$f = $this->two_due();
		WC_Stripe_API::$responses[] = new RuntimeException( 'timeout' );

		RenewalProcessor::process( $f['a']['renewal_id'] );

		foreach ( array( 'a', 'b' ) as $key ) {
			$renewal = $this->renewal( $f[ $key ]['renewal_id'] );
			$this->assertSame( RenewalProcessor::STATUS_RECONCILING, $renewal['status'] );
			$this->assertSame( '0', (string) $renewal['attempt_count'] );
			$this->assertSame( 'active', $this->license( $f[ $key ]['license_id'] )['status'] );
		}
		$order_id = (int) $this->renewal( $f['a']['renewal_id'] )['renewal_order_id'];
		$this->assertSame( $order_id, (int) $this->renewal( $f['b']['renewal_id'] )['renewal_order_id'] );

		// The member's own job never charges, and the customer cannot pay the member twice.
		$this->due_now( $f['b']['renewal_id'] );
		RenewalProcessor::process( $f['b']['renewal_id'] );
		$this->assertCount( 1, WC_Stripe_API::$requests );
		$this->assertTrue( is_wp_error( ManualRenewal::start( $f['b']['license_id'], $f['customer'] ) ) );

		// The leader repeats the same request; Stripe answers it now.
		WC_Stripe_API::$responses[] = $this->intent( 7800 );
		$this->due_now( $f['a']['renewal_id'] );
		RenewalProcessor::process( $f['a']['renewal_id'] );

		$this->assertCount( 2, WC_Stripe_API::$requests );
		$this->assertSame( WC_Stripe_API::$requests[0]['key'], WC_Stripe_API::$requests[1]['key'], 'Same idempotency key: Stripe cannot take a second payment.' );
		$this->assertSame( 7800, WC_Stripe_API::$requests[1]['request']['amount'] );
		$this->assertSame( 'paid', $this->renewal( $f['a']['renewal_id'] )['status'] );
		$this->assertSame( 'paid', $this->renewal( $f['b']['renewal_id'] )['status'] );
	}

	public function test_stuck_member_follows_the_leader_and_the_paid_order_books_it(): void {
		$this->combine();
		$f = $this->two_due();
		WC_Stripe_API::$responses[] = new RuntimeException( 'timeout' );
		RenewalProcessor::process( $f['a']['renewal_id'] );
		$order = $this->order_of( $f['a']['renewal_id'] );

		// The payment arrives by another way (Stripe event, administrator): the order is paid.
		$order->payment_complete( 'ch_found_later' );
		RenewalProcessor::reconcile_paid_renewal_order( (int) $order->get_id() );

		$this->assertCount( 1, WC_Stripe_API::$requests );
		$this->assertSame( 'paid', $this->renewal( $f['a']['renewal_id'] )['status'] );
		$this->assertSame( 'paid', $this->renewal( $f['b']['renewal_id'] )['status'] );
		$this->assertSame( $this->renewal( $f['b']['renewal_id'] )['period_end'], $this->license( $f['b']['license_id'] )['paid_until'] );
	}

	public function test_member_cancelled_while_the_result_is_unknown_sends_the_order_to_review(): void {
		$this->combine();
		$f = $this->two_due();
		WC_Stripe_API::$responses[] = new RuntimeException( 'timeout' );
		RenewalProcessor::process( $f['a']['renewal_id'] );
		global $wpdb;
		$wpdb->update( $this->tables()['licenses'], array( 'cancel_at_period_end' => 1 ), array( 'id' => $f['b']['license_id'] ) );

		$this->due_now( $f['a']['renewal_id'] );
		RenewalProcessor::process( $f['a']['renewal_id'] );

		$this->assertCount( 1, WC_Stripe_API::$requests, 'Repeating the request could charge the cancelled license.' );
		$this->assertSame( RenewalProcessor::STATUS_REVIEW, $this->renewal( $f['a']['renewal_id'] )['status'] );
		$this->assertSame( RenewalProcessor::STATUS_REVIEW, $this->renewal( $f['b']['renewal_id'] )['status'] );
	}

	public function test_full_refund_takes_back_every_license(): void {
		$this->combine();
		$f = $this->two_due();
		WC_Stripe_API::$responses[] = $this->intent( 7800 );
		RenewalProcessor::process( $f['a']['renewal_id'] );
		$order = $this->order_of( $f['a']['renewal_id'] );

		wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => $order->get_total(), 'reason' => 'test' ) );

		foreach ( array( 'a', 'b' ) as $key ) {
			$renewal = $this->renewal( $f[ $key ]['renewal_id'] );
			$this->assertSame( 'refunded', $renewal['status'] );
			$license = $this->license( $f[ $key ]['license_id'] );
			$this->assertSame( $renewal['period_start'], $license['paid_until'] );
			$this->assertSame( '0', (string) $license['renewal_enabled'] );
		}
	}

	public function test_partial_refund_of_one_line_takes_back_only_that_license(): void {
		$this->combine();
		$f = $this->two_due();
		WC_Stripe_API::$responses[] = $this->intent( 7800 );
		RenewalProcessor::process( $f['a']['renewal_id'] );
		$order = $this->order_of( $f['a']['renewal_id'] );
		$line  = null;
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			if ( (int) $item->get_meta( RenewalOrder::ITEM_META_RENEWAL, true ) === $f['b']['renewal_id'] ) {
				$line = array( $item_id, $item );
			}
		}
		$this->assertNotNull( $line );

		wc_create_refund(
			array(
				'order_id'   => $order->get_id(),
				'amount'     => $line[1]->get_total(),
				'reason'     => 'one license',
				'line_items' => array(
					$line[0] => array(
						'qty'          => 1,
						'refund_total' => $line[1]->get_total(),
					),
				),
			)
		);

		$this->assertSame( 'refunded', $this->renewal( $f['b']['renewal_id'] )['status'] );
		$this->assertSame( $this->renewal( $f['b']['renewal_id'] )['period_start'], $this->license( $f['b']['license_id'] )['paid_until'] );
		$this->assertSame( 'paid', $this->renewal( $f['a']['renewal_id'] )['status'] );
		$this->assertSame( $this->renewal( $f['a']['renewal_id'] )['period_end'], $this->license( $f['a']['license_id'] )['paid_until'] );
		$this->assertSame( '1', (string) $this->license( $f['a']['license_id'] )['renewal_enabled'] );

		// An amount-only partial refund changes no term.
		wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => '1.00', 'reason' => 'goodwill' ) );
		$this->assertSame( 'paid', $this->renewal( $f['a']['renewal_id'] )['status'] );
		$this->assertContains( 'renewal_partially_refunded', $this->audit_actions( 'license', $f['a']['license_id'] ) );
	}

	public function test_dispute_concerns_every_license_of_the_order(): void {
		$this->combine();
		$f = $this->two_due();
		WC_Stripe_API::$responses[] = $this->intent( 7800 );
		RenewalProcessor::process( $f['a']['renewal_id'] );
		$order = $this->order_of( $f['a']['renewal_id'] );

		$ids = LicenseLifecycle::license_ids_for_order( (int) $order->get_id() );
		sort( $ids );
		$expected = array( $f['a']['license_id'], $f['b']['license_id'] );
		sort( $expected );

		$this->assertSame( $expected, $ids );
	}

	public function test_manual_renewal_stays_per_license(): void {
		$this->combine();
		$f = $this->two_due();
		WC_Stripe_API::$responses[] = $this->decline();
		RenewalProcessor::process( $f['a']['renewal_id'] );

		$order = ManualRenewal::start( $f['b']['license_id'], $f['customer'] );

		$this->assertInstanceOf( WC_Order::class, $order, is_wp_error( $order ) ? $order->get_error_code() : '' );
		$this->assertFalse( RenewalOrder::is_combined( $order ) );
		$this->assertCount( 1, $order->get_items( 'line_item' ) );
		$this->assertSame( '29.00', wc_format_decimal( $order->get_total(), 2 ) );
	}

	public function test_member_with_a_busy_lock_is_left_out(): void {
		$this->combine();
		$f = $this->two_due();
		// Another connection holds the member's lock (for example its own job right now).
		$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', RenewalProcessor::renewal_lock_name( $f['b']['renewal_id'] ) ) );
		WC_Stripe_API::$responses[] = $this->intent( 4900 );

		RenewalProcessor::process( $f['a']['renewal_id'] );
		$other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', RenewalProcessor::renewal_lock_name( $f['b']['renewal_id'] ) ) );
		$other->close();

		$this->assertSame( 4900, WC_Stripe_API::$requests[0]['request']['amount'] );
		$this->assertSame( 'paid', $this->renewal( $f['a']['renewal_id'] )['status'] );
		$this->assertSame( 'scheduled', $this->renewal( $f['b']['renewal_id'] )['status'] );
	}

	public function test_verified_stripe_success_event_books_every_license_of_the_order(): void {
		global $wpdb;
		$this->combine();
		$f = $this->two_due();
		WC_Stripe_API::$responses[] = new RuntimeException( 'timeout' );
		RenewalProcessor::process( $f['a']['renewal_id'] );
		$order   = $this->order_of( $f['a']['renewal_id'] );
		$payload = array(
			'payment_intent_id' => 'pi_event_1',
			'payment_status'    => 'succeeded',
			'amount_received'   => 7800,
			'currency'          => 'eur',
			'renewal_id'        => $f['a']['renewal_id'],
			'license_id'        => $f['a']['license_id'],
			'order_id'          => (int) $order->get_id(),
			'amount'            => 7800,
			'attempt'           => 0,
			'charge_id'         => 'ch_event_1',
		);
		$json = (string) wp_json_encode( $payload );
		$wpdb->insert(
			$this->tables()['event_inbox'],
			array(
				'source'          => 'stripe',
				'source_event_id' => 'evt_combined_1',
				'event_type'      => 'payment_intent.succeeded',
				'payload_hash'    => hash( 'sha256', $json ),
				'payload_json'    => $json,
				'received_at'     => gmdate( 'Y-m-d H:i:s' ),
				'outcome'         => 'pending_reconciliation',
			)
		);

		RenewalProcessor::process_stripe_event( (int) $wpdb->insert_id );

		$this->assertCount( 1, WC_Stripe_API::$requests, 'The event books; nothing is charged again.' );
		$this->assertSame( 'paid', $this->renewal( $f['a']['renewal_id'] )['status'] );
		$this->assertSame( 'paid', $this->renewal( $f['b']['renewal_id'] )['status'] );
		$this->assertSame( 'ch_event_1', wc_get_order( $order->get_id() )->get_transaction_id() );
		$this->assertCount( 1, $this->renewed_mails() );
	}

	public function test_decline_reported_while_the_result_is_unknown_is_followed_by_every_license(): void {
		$this->combine();
		$f = $this->two_due();
		WC_Stripe_API::$responses[] = new RuntimeException( 'timeout' );
		RenewalProcessor::process( $f['a']['renewal_id'] );
		$order = $this->order_of( $f['a']['renewal_id'] );

		// An administrator checked Stripe: the payment was not taken.
		$order->update_status( 'failed' );
		RenewalProcessor::resolve_failed_renewal_order( (int) $order->get_id() );
		$this->assertSame( 'failed', $this->renewal( $f['a']['renewal_id'] )['status'] );

		$this->due_now( $f['b']['renewal_id'] );
		RenewalProcessor::process( $f['b']['renewal_id'] );

		$member = $this->renewal( $f['b']['renewal_id'] );
		$this->assertSame( 'failed', $member['status'] );
		$this->assertSame( '1', (string) $member['attempt_count'] );
		$this->assertEmpty( $member['renewal_order_id'] );
		$this->assertSame( 'past_due', $this->license( $f['b']['license_id'] )['status'] );
		$this->assertCount( 1, WC_Stripe_API::$requests, 'Following a decline never charges.' );
	}
}
