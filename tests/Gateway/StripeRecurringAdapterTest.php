<?php
use PHPUnit\Framework\TestCase;
use WPLC\Gateway\StripeRecurringAdapter;

/** T-155: attempt marker in the PaymentIntent metadata of a renewal charge. */
final class StripeRecurringAdapterTest extends TestCase {

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

	/** T-160: the gateway's header filter is renamed in 9.7.0; the old name is deprecated there. */
	public function test_request_headers_filter_follows_the_gateway_version(): void {
		$this->assertSame( 'woocommerce_stripe_request_headers', StripeRecurringAdapter::request_headers_filter( '9.6.0' ) );
		$this->assertSame( 'woocommerce_stripe_request_headers', StripeRecurringAdapter::request_headers_filter( '9.6.1-beta' ) );
		$this->assertSame( 'wc_stripe_request_headers', StripeRecurringAdapter::request_headers_filter( '9.7.0' ) );
		$this->assertSame( 'wc_stripe_request_headers', StripeRecurringAdapter::request_headers_filter( '11.2.0' ) );
		$this->assertSame( 'wc_stripe_request_headers', StripeRecurringAdapter::request_headers_filter( '' ) );
	}

	/** T-160: only the version header changes. */
	public function test_pin_api_version_sets_only_the_version(): void {
		$this->assertSame(
			array( 'Stripe-Version' => StripeRecurringAdapter::STRIPE_API_VERSION, 'Authorization' => 'Basic x' ),
			StripeRecurringAdapter::pin_api_version( array( 'Stripe-Version' => '2024-06-20', 'Authorization' => 'Basic x' ) )
		);
		$this->assertNull( StripeRecurringAdapter::pin_api_version( null ) );
	}
}
