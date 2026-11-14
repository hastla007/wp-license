<?php
/**
 * T-50: refunds of a renewal order.
 *
 * Full refund: the renewal is rolled back (paid_until back to the period start, automatic renewal off), the license
 * is not revoked. Partial refund: the term is unchanged, the refund is recorded in the audit history and the order.
 *
 * @package WPLC
 */

use WPLC\Billing\ManualRenewal;

final class RenewalRefundIntegrationTest extends WPLC_Integration_TestCase {

	/**
	 * Buy a license, move it into the renewal window and pay a manual renewal.
	 *
	 * @return array{license_id:int,order:WC_Order,old_paid_until:string}
	 */
	private function renewed_license(): array {
		global $wpdb;
		$customer = self::factory()->user->create();
		$product  = $this->create_license_product( array(), '49.00' );
		$purchase = $this->create_paid_order( array( array( $product, 1 ) ), $customer );
		$licenses = $this->licenses_for_order( $purchase->get_id() );
		$this->assertCount( 1, $licenses );
		$license_id = (int) $licenses[0]['id'];

		$old_paid_until = gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS );
		$wpdb->update( $this->tables()['licenses'], array( 'paid_until' => $old_paid_until, 'renewal_enabled' => 1 ), array( 'id' => $license_id ) );

		$order = ManualRenewal::start( $license_id, $customer );
		$this->assertInstanceOf( WC_Order::class, $order, is_wp_error( $order ) ? $order->get_error_code() : '' );
		$this->assertTrue( $order->payment_complete( 'txn_renewal_' . $order->get_id() ) );

		$license = $this->license( $license_id );
		$this->assertGreaterThan( $old_paid_until, $license['paid_until'], 'Fixture: the manual renewal was not booked.' );

		return array( 'license_id' => $license_id, 'order' => wc_get_order( $order->get_id() ), 'old_paid_until' => $old_paid_until );
	}

	private function refund( WC_Order $order, string $amount ): WC_Order_Refund {
		$refund = wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => $amount, 'reason' => 'Integration test' ) );
		$this->assertInstanceOf( WC_Order_Refund::class, $refund, is_wp_error( $refund ) ? $refund->get_error_message() : '' );
		return $refund;
	}

	private function audit_count( int $license_id, string $action ): int {
		global $wpdb;
		$table = $this->tables()['audit_log'];
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE object_type = 'license' AND object_id = %d AND action = %s", $license_id, $action ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema.
	}

	public function test_full_refund_of_a_renewal_order_rolls_the_renewal_back(): void {
		$fixture = $this->renewed_license();
		$license_id = $fixture['license_id'];

		$this->refund( $fixture['order'], (string) $fixture['order']->get_total() );

		$license = $this->license( $license_id );
		$this->assertSame( $fixture['old_paid_until'], $license['paid_until'] );
		$this->assertSame( 0, (int) $license['renewal_enabled'] );
		$this->assertSame( 'active', $license['status'], 'The license is not revoked, it runs out at the old end date.' );
		$this->assertSame( 1, $this->audit_count( $license_id, 'renewal_refunded' ) );

		// A repeated hook changes nothing.
		\WPLC\License\LicenseLifecycle::handle_full_refund( $fixture['order']->get_id() );
		$this->assertSame( 1, $this->audit_count( $license_id, 'renewal_refunded' ) );
	}

	public function test_partial_refund_of_a_renewal_order_keeps_the_term_and_is_recorded(): void {
		$fixture = $this->renewed_license();
		$license_id = $fixture['license_id'];
		$before = $this->license( $license_id );

		$refund = $this->refund( $fixture['order'], '10.00' );

		$after = $this->license( $license_id );
		$this->assertSame( $before['paid_until'], $after['paid_until'] );
		$this->assertSame( $before['status'], $after['status'] );
		$this->assertSame( 1, $this->audit_count( $license_id, 'renewal_partially_refunded' ) );
		$this->assertSame( 0, $this->audit_count( $license_id, 'renewal_refunded' ) );

		do_action( 'woocommerce_order_partially_refunded', $fixture['order']->get_id(), $refund->get_id() );
		$this->assertSame( 1, $this->audit_count( $license_id, 'renewal_partially_refunded' ), 'recorded once per refund' );

		$notes = wc_get_order_notes( array( 'order_id' => $fixture['order']->get_id() ) );
		$this->assertNotEmpty( array_filter( $notes, static function ( $note ) use ( $license_id ) {
			return false !== strpos( $note->content, '#' . $license_id ) && false !== strpos( $note->content, 'Change term' );
		} ) );
	}

	public function test_partial_refunds_that_add_up_to_the_total_roll_the_renewal_back(): void {
		$fixture = $this->renewed_license();
		$total = (float) $fixture['order']->get_total();

		$this->refund( $fixture['order'], wc_format_decimal( 10, 2 ) );
		$this->refund( $fixture['order'], wc_format_decimal( $total - 10, 2 ) );

		$this->assertSame( $fixture['old_paid_until'], $this->license( $fixture['license_id'] )['paid_until'] );
	}
}
