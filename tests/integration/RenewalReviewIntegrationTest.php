<?php
/**
 * T-154, T-137: renewals that need an administrator are listed and resolved.
 *
 * Covers the three ways into review (unknown payment result, paid while the
 * license changed, late manual payment), the three actions with double
 * submission, the expiry deferral and the review screen.
 *
 * @package WPLC
 */

use WPLC\Admin\RenewalReviewAdmin;
use WPLC\Billing\ManualRenewal;
use WPLC\Billing\RenewalOrder;
use WPLC\Billing\RenewalProcessor;
use WPLC\Billing\RenewalReview;
use WPLC\Billing\RenewalScheduler;
use WPLC\License\AuditReason;
use WPLC\License\LicenseLifecycle;

// The scripted adapter lives in this file; PHPUnit loads test files with include_once.
require_once __DIR__ . '/RenewalResultUnknownIntegrationTest.php';

final class RenewalReviewIntegrationTest extends WPLC_Integration_TestCase {

	/**
	 * Scripted adapter.
	 *
	 * @var WPLC_Test_Scripted_Adapter
	 */
	private $adapter;

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private $admin;

	public function set_up(): void {
		parent::set_up();
		$this->adapter = new WPLC_Test_Scripted_Adapter();
		add_filter(
			'wplc_recurring_gateway_adapters',
			function ( $adapters ) {
				return array_merge( array( $this->adapter ), is_array( $adapters ) ? $adapters : array() );
			},
			99
		);
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * A renewing license with a saved token whose automatic renewal went to review after an unknown result.
	 *
	 * @return array{license_id:int,renewal_id:int,order_id:int,customer:int}
	 */
	private function unknown_result_case(): array {
		$customer = self::factory()->user->create( array( 'role' => 'customer' ) );
		$source   = $this->create_order( array( array( $this->create_simple_product( '49.00' ), 1 ) ), $customer );
		$token    = new WC_Payment_Token_CC();
		$token->set_token( 'tok_' . wp_generate_password( 10, false ) );
		$token->set_gateway_id( $this->adapter->gateway );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( (string) ( (int) gmdate( 'Y' ) + 2 ) );
		$token->set_user_id( $customer );
		$token->save();

		$paid_until = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
		$license_id = $this->seed_license(
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
			)
		)['id'];
		$renewal_id = RenewalScheduler::schedule_next( $license_id );
		$this->adapter->results = array( 'unknown' );
		RenewalProcessor::process( $renewal_id );
		$order_id = (int) $this->renewal( $renewal_id )['renewal_order_id'];
		$order    = wc_get_order( $order_id );
		$order->update_meta_data( RenewalProcessor::META_RESULT_UNKNOWN_SINCE, '0|' . ( time() - RenewalProcessor::RESULT_UNKNOWN_WINDOW - 60 ) );
		$order->save();
		RenewalProcessor::process( $renewal_id );
		$this->assertSame( RenewalProcessor::STATUS_REVIEW, $this->renewal( $renewal_id )['status'], 'Fixture: the renewal did not go to review.' );

		return array( 'license_id' => $license_id, 'renewal_id' => $renewal_id, 'order_id' => $order_id, 'customer' => $customer );
	}

	/**
	 * A paid manual renewal that arrives after the renewal window (late bank transfer).
	 *
	 * @return array{license_id:int,renewal_id:int,order_id:int,customer:int}
	 */
	private function late_manual_payment_case(): array {
		global $wpdb;
		$customer = self::factory()->user->create();
		$product  = $this->create_license_product( array(), '49.00' );
		$purchase = $this->create_paid_order( array( array( $product, 1 ) ), $customer );
		$license_id = (int) $this->licenses_for_order( $purchase->get_id() )[0]['id'];
		$tables     = $this->tables();
		$wpdb->update( $tables['licenses'], array( 'paid_until' => gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS ) ), array( 'id' => $license_id ) );

		$order = ManualRenewal::start( $license_id, $customer );
		$this->assertInstanceOf( WC_Order::class, $order, is_wp_error( $order ) ? $order->get_error_code() : '' );
		$renewal_id = (int) $order->get_meta( RenewalOrder::META_RENEWAL, true );

		// The customer waited: the license expired 40 days ago, outside the 30-day window.
		$late = gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS );
		$wpdb->update( $tables['licenses'], array( 'paid_until' => $late, 'status' => 'expired' ), array( 'id' => $license_id ) );
		$wpdb->update( $tables['renewals'], array( 'period_start' => $late ), array( 'id' => $renewal_id ) );
		$this->assertTrue( $order->payment_complete( 'bank_transfer_late' ) );

		return array( 'license_id' => $license_id, 'renewal_id' => $renewal_id, 'order_id' => (int) $order->get_id(), 'customer' => $customer );
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

		return (array) $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['renewals']} WHERE id = %d", $renewal_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema.
	}

	/**
	 * Audit rows of one object and action.
	 *
	 * @param string $type Object type.
	 * @param int    $id Object ID.
	 * @param string $action Action.
	 * @return array<int,array<string,mixed>>
	 */
	private function audit( string $type, int $id, string $action ): array {
		global $wpdb;
		$tables = $this->tables();

		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tables['audit_log']} WHERE object_type = %s AND object_id = %d AND action = %s ORDER BY id ASC", $type, $id, $action ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema.
	}

	public function test_unknown_result_opens_a_case_with_reason_and_start(): void {
		$case    = $this->unknown_result_case();
		$renewal = $this->renewal( $case['renewal_id'] );

		$this->assertSame( 'result_unknown_timeout', $renewal['review_reason'] );
		$this->assertNotEmpty( $renewal['review_at'] );
		$this->assertSame( 1, RenewalReview::open_count() );
		$this->assertSame( array( $case['renewal_id'] ), array_map( 'intval', array_column( RenewalReview::open_cases(), 'id' ) ) );
		$this->assertSame( RenewalReview::KIND_RESULT_UNKNOWN, RenewalReview::kind_of( $renewal, $this->license( $case['license_id'] ) ) );
	}

	public function test_book_after_unknown_result_extends_as_if_paid_and_is_idempotent(): void {
		$case    = $this->unknown_result_case();
		$before  = $this->renewal( $case['renewal_id'] );

		$this->assertSame( 'review_booked', RenewalReviewAdmin::run_action( $case['renewal_id'], 'book', 'Found pi_123 succeeded in Stripe', $this->admin ) );
		$this->assertSame( 'review_not_open', RenewalReviewAdmin::run_action( $case['renewal_id'], 'book', 'Double click', $this->admin ) );

		$license = $this->license( $case['license_id'] );
		$renewal = $this->renewal( $case['renewal_id'] );
		$this->assertSame( 'paid', $renewal['status'] );
		$this->assertSame( 'active', $license['status'] );
		$this->assertSame( $before['period_end'], $license['paid_until'], 'Booked as if the charge had succeeded: no gap, one period.' );
		$this->assertTrue( wc_get_order( $case['order_id'] )->is_paid(), 'The administrator confirmed the payment, so the order is paid.' );
		$booked = $this->audit( 'renewal', $case['renewal_id'], 'renewal_review_booked' );
		$this->assertCount( 1, $booked );
		$this->assertSame( (string) $this->admin, (string) $booked[0]['actor_user_id'] );
		$this->assertSame( 'Renewal booked by an administrator after review: Found pi_123 succeeded in Stripe', AuditReason::label( (string) $booked[0]['reason'] ) );
		$this->assertCount( 1, $this->audit( 'license', $case['license_id'], 'renewal_booked_after_review' ) );
		$this->assertCount( 1, $this->adapter->keys, 'Nothing was charged again.' );
		$this->assertSame( 0, RenewalReview::open_count() );

		// The paid-order hooks that follow find the renewal booked and change nothing.
		RenewalProcessor::reconcile_paid_renewal_order( $case['order_id'] );
		$this->assertSame( $before['period_end'], $this->license( $case['license_id'] )['paid_until'] );
		// The next period is scheduled.
		$this->assertGreaterThan( 0, RenewalScheduler::find_or_create_row( $this->license( $case['license_id'] ) ) );
	}

	public function test_mark_not_paid_records_a_decline_once(): void {
		$case = $this->unknown_result_case();

		$this->assertSame( 'review_marked_not_paid', RenewalReview::mark_not_paid( $case['renewal_id'], 'No charge in Stripe', $this->admin ) );
		$this->assertSame( 'review_not_open', RenewalReview::mark_not_paid( $case['renewal_id'], 'No charge in Stripe', $this->admin ) );

		$renewal = $this->renewal( $case['renewal_id'] );
		$this->assertSame( 'failed', $renewal['status'] );
		$this->assertSame( '1', (string) $renewal['attempt_count'], 'One decline is counted; the next attempt gets a new key.' );
		$this->assertSame( 'failed', wc_get_order( $case['order_id'] )->get_status() );
		$this->assertSame( 'past_due', $this->license( $case['license_id'] )['status'] );
		$resolved = $this->audit( 'renewal', $case['renewal_id'], 'renewal_result_resolved' );
		$this->assertCount( 1, $resolved );
		$this->assertSame( (string) $this->admin, (string) $resolved[0]['actor_user_id'] );
		$this->assertStringStartsWith( AuditReason::REVIEW_NOT_PAID, (string) $resolved[0]['reason'] );

		// The queued order-status resolution finds nothing left to do.
		RenewalProcessor::resolve_failed_renewal_order( $case['order_id'] );
		$this->assertSame( '1', (string) $this->renewal( $case['renewal_id'] )['attempt_count'] );
	}

	public function test_close_leaves_the_license_and_a_later_payment_reopens_the_case(): void {
		$case       = $this->unknown_result_case();
		$paid_until = $this->license( $case['license_id'] )['paid_until'];

		$this->assertSame( 'review_missing_reason', RenewalReview::close( $case['renewal_id'], '  ', $this->admin ) );
		$this->assertSame( 'review_closed', RenewalReview::close( $case['renewal_id'], 'Refunded in Stripe', $this->admin ) );
		$this->assertSame( 'review_not_open', RenewalReview::close( $case['renewal_id'], 'Refunded in Stripe', $this->admin ) );

		$this->assertSame( RenewalReview::STATUS_CLOSED, $this->renewal( $case['renewal_id'] )['status'] );
		$this->assertSame( $paid_until, $this->license( $case['license_id'] )['paid_until'] );
		$this->assertCount( 1, $this->audit( 'renewal', $case['renewal_id'], 'renewal_review_closed' ) );
		$this->assertSame( 'unpaid', wc_get_order( $case['order_id'] )->get_meta( RenewalReview::META_CLOSED, true ) );
		// The hourly job leaves a closed case alone.
		RenewalProcessor::process( $case['renewal_id'] );
		$this->assertCount( 1, $this->adapter->keys );

		// The order is paid afterwards: never booked silently, back to review.
		wc_get_order( $case['order_id'] )->payment_complete( 'txn_late' );
		RenewalProcessor::reconcile_paid_renewal_order( $case['order_id'] );
		$renewal = $this->renewal( $case['renewal_id'] );
		$this->assertSame( RenewalProcessor::STATUS_REVIEW, $renewal['status'] );
		$this->assertSame( AuditReason::PAID_AFTER_REVIEW_CLOSED, $renewal['review_reason'] );
		$this->assertSame( $paid_until, $this->license( $case['license_id'] )['paid_until'] );
	}

	public function test_paid_while_expired_is_booked_by_reactivating_the_license(): void {
		global $wpdb;
		$case = $this->unknown_result_case();
		// The license expired; then the administrator finds the payment and marks the order paid.
		$wpdb->update( $this->tables()['licenses'], array( 'status' => 'expired' ), array( 'id' => $case['license_id'] ) );
		wc_get_order( $case['order_id'] )->payment_complete( 'txn_found' );
		RenewalProcessor::reconcile_paid_renewal_order( $case['order_id'] );
		$renewal = $this->renewal( $case['renewal_id'] );
		$this->assertSame( RenewalProcessor::STATUS_REVIEW, $renewal['status'] );
		$this->assertSame( AuditReason::LICENSE_CHANGED_DURING_CHARGE, $renewal['review_reason'] );
		$this->assertSame( RenewalReview::KIND_PAID_NOT_BOOKED, RenewalReview::kind_of( $renewal, $this->license( $case['license_id'] ) ) );

		$this->assertSame( 'review_action_not_allowed', RenewalReview::mark_not_paid( $case['renewal_id'], 'x', $this->admin ), 'A paid order cannot be marked as not paid.' );
		$this->assertSame( 'review_booked', RenewalReview::book( $case['renewal_id'], 'Customer keeps the license', $this->admin ) );

		$license = $this->license( $case['license_id'] );
		$this->assertSame( 'active', $license['status'] );
		$this->assertSame( $renewal['period_end'], $license['paid_until'] );
	}

	public function test_suspended_license_is_not_extended_by_booking(): void {
		$case = $this->unknown_result_case();
		$this->assertTrue( LicenseLifecycle::admin_set_status( $case['license_id'], 'suspended', 'Fraud check', 1 ) );
		$paid_until = $this->license( $case['license_id'] )['paid_until'];

		$this->assertSame( 'review_license_status', RenewalReview::book( $case['renewal_id'], 'Paid', $this->admin ) );
		$this->assertSame( RenewalProcessor::STATUS_REVIEW, $this->renewal( $case['renewal_id'] )['status'], 'A refused booking leaves the case open.' );
		$this->assertSame( array( 'suspended', $paid_until ), array( $this->license( $case['license_id'] )['status'], $this->license( $case['license_id'] )['paid_until'] ) );
	}

	public function test_late_manual_payment_becomes_a_case_and_is_booked_from_now(): void {
		$case    = $this->late_manual_payment_case();
		$renewal = $this->renewal( $case['renewal_id'] );

		$this->assertSame( RenewalProcessor::STATUS_REVIEW, $renewal['status'], 'A late payment is listed instead of being only an order note.' );
		$this->assertSame( AuditReason::MANUAL_RENEWAL_NOT_ALLOWED, $renewal['review_reason'] );
		$this->assertSame( 'expired', $this->license( $case['license_id'] )['status'] );
		$this->assertSame( RenewalReview::KIND_MANUAL_PAYMENT, RenewalReview::kind_of( $renewal, $this->license( $case['license_id'] ) ) );
		// A repeated paid hook keeps one case and one note.
		RenewalProcessor::reconcile_paid_renewal_order( $case['order_id'] );
		$this->assertCount( 1, $this->audit( 'renewal', $case['renewal_id'], 'manual_renewal_rejected' ) );

		$now = time();
		$this->assertSame( 'review_booked', RenewalReview::book( $case['renewal_id'], 'Bank transfer arrived late', $this->admin ) );
		$this->assertSame( 'review_not_open', RenewalReview::book( $case['renewal_id'], 'Bank transfer arrived late', $this->admin ) );

		$license = $this->license( $case['license_id'] );
		$this->assertSame( 'active', $license['status'], 'The expired license is reactivated.' );
		$expected = RenewalScheduler::period_end( $now, 'year' );
		$this->assertEqualsWithDelta( $expected, strtotime( $license['paid_until'] . ' UTC' ), 5, 'One period from the booking, not from the old end.' );
		$this->assertSame( 'paid', $this->renewal( $case['renewal_id'] )['status'] );
		$this->assertCount( 1, $this->audit( 'license', $case['license_id'], 'renewal_booked_after_review' ) );
	}

	public function test_late_manual_payment_closed_after_refund_is_not_reopened(): void {
		$case = $this->late_manual_payment_case();

		$this->assertSame( 'review_action_not_allowed', RenewalReview::mark_not_paid( $case['renewal_id'], 'x', $this->admin ) );
		$this->assertSame( 'review_closed', RenewalReview::close( $case['renewal_id'], 'Refunded the transfer', $this->admin ) );
		$this->assertSame( 'paid', wc_get_order( $case['order_id'] )->get_meta( RenewalReview::META_CLOSED, true ) );

		// Duplicate paid hooks (e.g. the order is set to Completed later) do not reopen or book it.
		RenewalProcessor::reconcile_paid_renewal_order( $case['order_id'] );
		$this->assertSame( RenewalReview::STATUS_CLOSED, $this->renewal( $case['renewal_id'] )['status'] );
		$this->assertSame( 'expired', $this->license( $case['license_id'] )['status'] );
	}

	public function test_open_case_defers_expiry_for_at_most_thirty_days(): void {
		global $wpdb;
		$case   = $this->unknown_result_case();
		$tables = $this->tables();
		// Long after the grace period, the daily job would expire this active license.
		$wpdb->update( $tables['licenses'], array( 'paid_until' => gmdate( 'Y-m-d H:i:s', time() - 20 * DAY_IN_SECONDS ) ), array( 'id' => $case['license_id'] ) );

		LicenseLifecycle::expire_due_licenses();
		LicenseLifecycle::expire_after_grace( $case['license_id'] );
		$this->assertSame( 'active', $this->license( $case['license_id'] )['status'], 'An open review defers the expiry.' );
		$this->assertTrue( LicenseLifecycle::expiry_deferred_by_review( $case['license_id'] ) );

		$wpdb->update( $tables['renewals'], array( 'review_at' => gmdate( 'Y-m-d H:i:s', time() - 31 * DAY_IN_SECONDS ) ), array( 'id' => $case['renewal_id'] ) );
		LicenseLifecycle::expire_due_licenses();
		$this->assertSame( 'expired', $this->license( $case['license_id'] )['status'], 'The deferral is capped.' );
		$this->assertSame( RenewalProcessor::STATUS_REVIEW, $this->renewal( $case['renewal_id'] )['status'], 'The case stays open after the expiry.' );
	}

	public function test_revoking_during_reconciliation_records_the_reason(): void {
		$customer = self::factory()->user->create( array( 'role' => 'customer' ) );
		$license_id = $this->seed_license( array( 'customer_id' => $customer ) )['id'];
		$renewal_id = RenewalScheduler::find_or_create_row( $this->license( $license_id ) );
		global $wpdb;
		$wpdb->update( $this->tables()['renewals'], array( 'status' => RenewalProcessor::STATUS_RECONCILING ), array( 'id' => $renewal_id ) );

		$this->assertTrue( LicenseLifecycle::admin_set_status( $license_id, 'revoked', 'test', 1 ) );

		$renewal = $this->renewal( $renewal_id );
		$this->assertSame( RenewalProcessor::STATUS_REVIEW, $renewal['status'] );
		$this->assertSame( AuditReason::LICENSE_CHANGED_WHILE_UNKNOWN, $renewal['review_reason'] );
		$this->assertCount( 1, $this->audit( 'renewal', $renewal_id, 'renewal_review_required' ) );
	}

	public function test_review_screen_lists_the_case_with_its_actions(): void {
		$case = $this->unknown_result_case();
		wp_set_current_user( $this->admin );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			get_role( 'administrator' )->add_cap( 'manage_woocommerce' );
		}

		ob_start();
		RenewalReviewAdmin::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'wplc-review-' . $case['renewal_id'], $html );
		$this->assertStringContainsString( 'The payment result stayed unknown for too long.', $html );
		$this->assertStringContainsString( 'name="review_action" value="not_paid"', $html );
		$this->assertStringContainsString( 'Order #' . $case['order_id'], $html );
		$this->assertStringContainsString( 'pending-count">1<', RenewalReviewAdmin::menu_title( 'Renewals to review', RenewalReview::open_count() ) );
		$this->assertSame( 'Renewals to review', RenewalReviewAdmin::menu_title( 'Renewals to review', 0 ) );
	}
}
