<?php
use PHPUnit\Framework\TestCase;
use WPLC\Gateway\StripeRecurringAdapter;

/** T-155: attempt marker in the PaymentIntent metadata of a renewal charge. */
final class StripeRecurringAdapterTest extends TestCase {

	public function test_metadata_names_order_renewal_license_and_attempt(): void {
		$this->assertSame(
			array(
				'order_id'        => '33',
				'wplc_renewal_id' => '11',
				'wplc_license_id' => '22',
				'wplc_attempt'    => '0',
			),
			StripeRecurringAdapter::request_metadata( 33, 11, 22, 0 )
		);
		$this->assertSame( '2', StripeRecurringAdapter::request_metadata( 33, 11, 22, 2 )['wplc_attempt'] );
	}

	public function test_metadata_without_attempt_has_no_marker(): void {
		$this->assertArrayNotHasKey( 'wplc_attempt', StripeRecurringAdapter::request_metadata( 33, 11, 22, null ) );
	}

	public function test_first_request_of_an_attempt_carries_the_marker(): void {
		$this->assertTrue( StripeRecurringAdapter::attempt_marker_allowed( '', '', 0 ) );
		// An unknown result recorded for an earlier attempt says nothing about this one.
		$this->assertTrue( StripeRecurringAdapter::attempt_marker_allowed( '0|1700000000', '', 1 ) );
	}

	/** P-61: the renewal order is part of the Stripe key; zero keeps the earlier format. */
	public function test_stripe_key_depends_on_the_renewal_order(): void {
		$legacy = StripeRecurringAdapter::stripe_idempotency_key( 'renewal:0', 'cus_1', 'pm_1', 4900, 'eur' );

		$this->assertSame( $legacy, StripeRecurringAdapter::stripe_idempotency_key( 'renewal:0', 'cus_1', 'pm_1', 4900, 'eur', 0, 0 ) );
		$this->assertNotSame( $legacy, StripeRecurringAdapter::stripe_idempotency_key( 'renewal:0', 'cus_1', 'pm_1', 4900, 'eur', 0, 41 ) );
		$this->assertNotSame(
			StripeRecurringAdapter::stripe_idempotency_key( 'renewal:0', 'cus_1', 'pm_1', 4900, 'eur', 0, 41 ),
			StripeRecurringAdapter::stripe_idempotency_key( 'renewal:0', 'cus_1', 'pm_1', 4900, 'eur', 0, 42 )
		);
	}

	/** P-03: the attempt counter is read from the end of the attempt key. */
	public function test_attempt_number_of_an_attempt_key(): void {
		$this->assertSame( 0, StripeRecurringAdapter::attempt_number( str_repeat( 'a', 64 ) . ':0' ) );
		$this->assertSame( 12, StripeRecurringAdapter::attempt_number( 'abc:12' ) );
		$this->assertSame( -1, StripeRecurringAdapter::attempt_number( 'abc' ) );
	}

	public function test_repeated_request_keeps_the_parameters_of_the_first_one(): void {
		// The first request of attempt 1 carried the marker: the repeat carries it too.
		$this->assertTrue( StripeRecurringAdapter::attempt_marker_allowed( '1|1700000000', '1', 1 ) );
		// Sent before the marker existed (no marker recorded for this attempt): the repeat must not add it,
		// or Stripe rejects the reused idempotency key for changed parameters.
		$this->assertFalse( StripeRecurringAdapter::attempt_marker_allowed( '1|1700000000', '', 1 ) );
		$this->assertFalse( StripeRecurringAdapter::attempt_marker_allowed( '1|1700000000', '0', 1 ) );
	}
}
