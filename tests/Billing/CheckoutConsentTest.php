<?php
use PHPUnit\Framework\TestCase;
use WPLC\Billing\BlockCheckoutConsent;
use WPLC\Billing\RecurringConsent;

final class CheckoutConsentTest extends TestCase {

	public function test_consent_is_granted_only_with_request_license_and_capable_gateway(): void {
		$this->assertSame( 'granted', RecurringConsent::decide( true, true, true ) );
		$this->assertSame( 'unsupported', RecurringConsent::decide( true, true, false ) );
	}

	public function test_no_consent_without_request_or_without_license(): void {
		$this->assertSame( 'none', RecurringConsent::decide( false, true, true ) );
		$this->assertSame( 'none', RecurringConsent::decide( false, true, false ) );
		$this->assertSame( 'none', RecurringConsent::decide( true, false, true ) );
		$this->assertSame( 'none', RecurringConsent::decide( true, false, false ) );
	}

	public function test_block_field_value_parsing(): void {
		foreach ( array( true, 1, '1', 'true' ) as $checked ) {
			$this->assertTrue( BlockCheckoutConsent::is_checked( $checked ) );
		}
		foreach ( array( false, 0, '0', '', 'false', null, array( '1' ), 'maybe' ) as $unchecked ) {
			$this->assertFalse( BlockCheckoutConsent::is_checked( $unchecked ) );
		}
	}

	public function test_field_meta_key_uses_the_woocommerce_group_prefix(): void {
		$this->assertSame( '_wc_other/' . BlockCheckoutConsent::FIELD_ID, BlockCheckoutConsent::FIELD_META_KEY );
		$this->assertCount( 2, explode( '/', BlockCheckoutConsent::FIELD_ID ) );
	}

	public function test_hidden_rule_hides_the_field_unless_a_license_product_is_in_the_cart(): void {
		$rule = BlockCheckoutConsent::hidden_rule( array( '12', 7, 12, 0 ) );

		$this->assertSame( array( 'cart' ), array_keys( $rule ) );
		$this->assertSame( array( 12, 7 ), $rule['cart']['properties']['items']['not']['contains']['enum'] );
		// The enum must encode as a JSON list, not an object.
		$this->assertSame( '[12,7]', json_encode( $rule['cart']['properties']['items']['not']['contains']['enum'] ) );
	}
}
