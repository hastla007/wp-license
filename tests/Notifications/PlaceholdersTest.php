<?php
/**
 * Email placeholder rendering and event mapping.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\Notifications\Notifications;
use WPLC\Notifications\Placeholders;

final class PlaceholdersTest extends TestCase {

	public function test_renders_known_placeholders(): void {
		$this->assertSame(
			'Your Voucher Captain (pro) license ends on 9 April 2027',
			Placeholders::render(
				'Your {product} ({plan}) license ends on {valid_until}',
				array( 'product' => 'Voucher Captain', 'plan' => 'pro', 'valid_until' => '9 April 2027' )
			)
		);
	}

	public function test_missing_values_become_empty_and_unknown_tokens_stay(): void {
		$this->assertSame( 'A  B {site_title} {unknown}', Placeholders::render( 'A {amount} B {site_title} {unknown}', array() ) );
	}

	public function test_values_are_not_expanded_recursively(): void {
		$this->assertSame(
			'{plan} / pro',
			Placeholders::render( '{product} / {plan}', array( 'product' => '{plan}', 'plan' => 'pro' ) )
		);
	}

	public function test_values_are_reduced_to_single_line_plain_text(): void {
		$map = Placeholders::map(
			array(
				'amount' => '<span class="amount"><bdi>49,00&nbsp;<span>&euro;</span></bdi></span>',
				'product' => "Evil\r\nBcc: someone@example.test",
				'plan' => array( 'not', 'scalar' ),
				'days_left' => 7,
				'license_key' => 'WPLC-SECRET',
			)
		);

		$this->assertSame( '49,00 €', $map['{amount}'] );
		$this->assertSame( 'Evil Bcc: someone@example.test', $map['{product}'] );
		$this->assertSame( '', $map['{plan}'] );
		$this->assertSame( '7', $map['{days_left}'] );
		$this->assertArrayNotHasKey( '{license_key}', $map, 'the full key is never a placeholder' );
		$this->assertSame(
			array( '{product}', '{plan}', '{key_prefix}', '{valid_until}', '{days_left}', '{amount}', '{charge_date}', '{grace_until}' ),
			array_keys( $map )
		);
	}

	public function test_transition_to_email_mapping(): void {
		$this->assertSame( 'payment_failed', Notifications::email_for_transition( 'active', 'past_due', 'renewal_failed' ) );
		$this->assertSame( '', Notifications::email_for_transition( 'past_due', 'past_due', 'renewal_failed' ) );
		$this->assertSame( 'expired', Notifications::email_for_transition( 'active', 'expired', 'license_expired' ) );
		$this->assertSame( 'expired', Notifications::email_for_transition( 'past_due', 'expired', 'license_expired' ) );
		$this->assertSame( 'renewed', Notifications::email_for_transition( 'active', 'active', 'renewal_succeeded' ) );
		$this->assertSame( 'renewed', Notifications::email_for_transition( 'past_due', 'active', 'renewal_succeeded' ) );
		$this->assertSame( 'renewed', Notifications::email_for_transition( 'expired', 'active', 'renewal_succeeded' ) );
		// B-152: suspension and revocation are announced to the customer.
		$this->assertSame( 'revoked', Notifications::email_for_transition( 'active', 'revoked', 'order_refund' ) );
		$this->assertSame( 'suspended', Notifications::email_for_transition( 'active', 'suspended', 'chargeback' ) );
		$this->assertSame( 'revoked', Notifications::email_for_transition( 'suspended', 'revoked', 'admin_status_change' ) );
		$this->assertSame( '', Notifications::email_for_transition( 'suspended', 'suspended', 'admin_status_change' ) );
		$this->assertSame( '', Notifications::email_for_transition( 'suspended', 'active', 'admin_status_change' ) );
	}

	public function test_registry_keys(): void {
		$this->assertSame( 'WPLC_Email_Issued', Notifications::registry_key( 'issued' ) );
		$this->assertSame( 'WPLC_Email_Expiry_Reminder', Notifications::registry_key( 'expiry_reminder' ) );
	}

	public function test_email_classes_are_not_defined_without_woocommerce(): void {
		$this->assertFalse( class_exists( 'WC_Email', false ) );
		$this->assertFalse( class_exists( 'WPLC\\Notifications\\Emails\\LicenseIssuedEmail' ) );
		$this->assertFalse( class_exists( 'WPLC\\Notifications\\Emails\\LicenseEmail' ) );
	}

	public function test_guarded_pure_classes_still_load(): void {
		$this->assertSame( '12.34', WPLC\Billing\Money::to_decimal( 1234, 2 ) );
		$this->assertTrue( WPLC\License\LicenseStateMachine::allows( 'active', 'past_due', 'renewal_failed' ) );
	}
}
