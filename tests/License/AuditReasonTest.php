<?php
/**
 * Audit reason codes are stored language-neutral and translated when shown.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\License\AuditReason;

require_once dirname( __DIR__ ) . '/Admin/wp-function-stubs.php';

final class AuditReasonTest extends TestCase {

	public function test_code_without_values_is_stored_as_the_bare_code(): void {
		$this->assertSame( 'paid_period_ended', AuditReason::encode( AuditReason::PAID_PERIOD_ENDED ) );
		$this->assertSame( 'paid_period_ended', AuditReason::PAID_PERIOD_ENDED );
	}

	public function test_code_with_values_is_stored_with_a_json_object(): void {
		$stored = AuditReason::encode( AuditReason::RENEWED_FROM_ORDER, array( 'order' => 123 ) );

		$this->assertSame( 'renewed_from_order {"order":123}', $stored );
		$this->assertSame( array( 'code' => 'renewed_from_order', 'params' => array( 'order' => 123 ) ), AuditReason::parse( $stored ) );
	}

	public function test_known_code_is_shown_as_text(): void {
		$this->assertSame( 'The paid period ended.', AuditReason::label( 'paid_period_ended' ) );
		$this->assertSame( 'Deactivated by the license owner.', AuditReason::label( AuditReason::DEACTIVATED_BY_OWNER ) );
	}

	public function test_placeholders_are_filled_from_the_stored_values(): void {
		$this->assertSame(
			'Manually renewed from WooCommerce order #42.',
			AuditReason::label( AuditReason::encode( AuditReason::RENEWED_MANUALLY_FROM_ORDER, array( 'order' => 42 ) ) )
		);
		$this->assertSame(
			'Stripe dispute dp_123 (reason: fraudulent).',
			AuditReason::label( AuditReason::encode( AuditReason::STRIPE_DISPUTE, array( 'dispute' => 'dp_123', 'reason' => 'fraudulent' ) ) )
		);
	}

	public function test_missing_placeholder_values_show_the_stored_text(): void {
		$this->assertSame( 'renewed_from_order', AuditReason::label( 'renewed_from_order' ) );
		$this->assertSame( 'renewed_from_order {"other":1}', AuditReason::label( 'renewed_from_order {"other":1}' ) );
	}

	public function test_unknown_code_is_shown_as_stored(): void {
		$this->assertSame( 'some_future_reason', AuditReason::label( 'some_future_reason' ) );
		$this->assertSame( 'some_future_reason {"x":1}', AuditReason::label( 'some_future_reason {"x":1}' ) );
		$this->assertFalse( AuditReason::is_known( 'some_future_reason' ) );
	}

	public function test_legacy_free_text_and_admin_reasons_are_shown_as_stored(): void {
		$this->assertSame( 'Renewed from WooCommerce order #17', AuditReason::label( 'Renewed from WooCommerce order #17' ) );
		$this->assertSame( 'Chargeback confirmed', AuditReason::label( 'Chargeback confirmed' ) );
		$this->assertNull( AuditReason::parse( 'Paid period ended' ) );
		$this->assertSame( '', AuditReason::label( '' ) );
		$this->assertSame( 'paid_period_ended {broken', AuditReason::label( 'paid_period_ended {broken' ) );
	}

	public function test_failure_codes_written_before_reason_codes_are_now_translated(): void {
		// Renewal failures always stored the error code; it now reads as the error message.
		$this->assertSame( 'Stripe declined the renewal payment.', AuditReason::label( 'stripe_payment_failed' ) );
		$this->assertSame( 'The payment result stayed unknown for too long.', AuditReason::label( 'result_unknown_timeout' ) );
	}

	public function test_every_code_constant_has_a_text(): void {
		$constants = ( new \ReflectionClass( AuditReason::class ) )->getConstants();
		$this->assertNotEmpty( $constants );
		foreach ( $constants as $name => $code ) {
			$this->assertTrue( AuditReason::is_known( $code ), $name . ' has no translatable text.' );
		}
	}

	public function test_values_are_normalized(): void {
		$this->assertSame( 'renewal_order_refunded {"order":"7"}', AuditReason::encode( 'Renewal_Order_Refunded', array( 'order' => '7', 'bad' => array( 1 ) ) ) );
		$this->assertSame( 'Renewal order #7 was refunded in full.', AuditReason::label( 'renewal_order_refunded {"order":"7"}' ) );
	}
}
