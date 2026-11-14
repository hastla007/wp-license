<?php
/**
 * Automatic renewal: charging, retries, booking and the daily repair jobs.
 *
 * @package WPLC
 */

use WPLC\Billing\ManualRenewal;
use WPLC\Billing\RenewalOrder;
use WPLC\Billing\RenewalProcessor;
use WPLC\Billing\RenewalScheduler;
use WPLC\License\LicenseLifecycle;

require_once __DIR__ . '/helpers/RenewalTestCase.php';

final class RenewalProcessorIntegrationTest extends WPLC_Renewal_TestCase {

	private function finish_paid( array $renewal, array $license, WC_Order $order ): bool {
		$method = new ReflectionMethod( RenewalProcessor::class, 'finish_paid' );
		$method->setAccessible( true );

		return (bool) $method->invoke( null, $renewal, $license, $order, 'renewal_paid', 'test' );
	}

	public function test_successful_charge_renews_once_and_schedules_the_next_period(): void {
		$f = $this->renewing_license();

		RenewalProcessor::process( $f['renewal_id'] );
		RenewalProcessor::process( $f['renewal_id'] );

		$this->assertSame( 1, $this->charges() );
		$license = $this->license( $f['license_id'] );
		$this->assertSame( 'active', $license['status'] );
		$this->assertSame( $f['period_end'], $license['paid_until'] );
		$this->assertSame( array( 'active@' . $f['period_end'] ), $this->entitlement_states( $f['license_id'] ) );
		$renewal = $this->renewal( $f['renewal_id'] );
		$this->assertSame( 'paid', $renewal['status'] );
		// A-16 (processor side): the gateway reference is stored on the renewal.
		$this->assertSame( 'test_tx_1', $renewal['gateway_attempt_id'] );
		$rows = $this->renewals_of( $f['license_id'] );
		$this->assertCount( 2, $rows );
		$this->assertSame( 'scheduled', $rows[1]['status'] );
		$this->assertSame( $f['period_end'], $rows[1]['period_start'] );
	}

	/** B-02: a cancellation survives the payment of the open automatic renewal order. */
	public function test_b02_cancellation_survives_payment_of_the_open_renewal_order(): void {
		$f = $this->renewing_license();
		WPLC_Test_Recurring_Adapter::$mode = 'decline';
		RenewalProcessor::process( $f['renewal_id'] );
		$this->assertSame( 'past_due', $this->license( $f['license_id'] )['status'] );

		$this->assertTrue( LicenseLifecycle::schedule_cancellation( $f['license_id'], 'do not renew', $f['customer'] ) );

		// The customer pays the failed order through the pay link.
		$order = wc_get_order( (int) $this->renewal( $f['renewal_id'] )['renewal_order_id'] );
		$order->set_payment_method( 'bacs' );
		$order->save();
		$order->payment_complete( 'customer_tx' );
		RenewalProcessor::reconcile_paid_renewal_order( (int) $order->get_id() );

		$license = $this->license( $f['license_id'] );
		$this->assertSame( 'active', $license['status'] );
		$this->assertSame( $f['period_end'], $license['paid_until'], 'The paid period is granted.' );
		$this->assertSame( '1', (string) $license['cancel_at_period_end'], 'The cancellation must not be erased by the payment.' );
		$this->assertNull( $license['next_payment_at'] );
		$this->assertCount( 1, $this->renewals_of( $f['license_id'] ), 'No further period may be scheduled for a cancelled license.' );
		$this->assertSame( 0, RenewalScheduler::schedule_next( $f['license_id'] ) );
	}

	/** B-02: a cancellation during a successful automatic charge is kept as well. */
	public function test_b02_cancellation_during_the_charge_is_kept(): void {
		$f = $this->renewing_license();
		WPLC_Test_Recurring_Adapter::$during_charge = function () use ( $f ) {
			global $wpdb;
			// What CustomerPortal does; the renewal lock is held by the charge, so the flag is set regardless.
			$wpdb->update( $this->tables()['licenses'], array( 'cancel_at_period_end' => 1 ), array( 'id' => $f['license_id'] ) );
		};

		RenewalProcessor::process( $f['renewal_id'] );

		$license = $this->license( $f['license_id'] );
		$this->assertSame( $f['period_end'], $license['paid_until'] );
		$this->assertSame( '1', (string) $license['cancel_at_period_end'] );
		$this->assertCount( 1, $this->renewals_of( $f['license_id'] ) );
	}

	/** B-50: a payment for a period the administrator moved away is not applied and not reported as paid. */
	public function test_b50_charge_booked_after_an_admin_extension_does_not_move_the_term_back(): void {
		global $wpdb;
		$f       = $this->renewing_license();
		$renewal = $this->renewal( $f['renewal_id'] );
		$license = $this->license( $f['license_id'] );
		$order   = RenewalOrder::get_or_create( $renewal, $license, false );
		$this->assertInstanceOf( WC_Order::class, $order );

		// The term changes while the charge is in flight (rows above were read before).
		$goodwill = gmdate( 'Y-m-d H:i:s', time() + 800 * DAY_IN_SECONDS );
		$wpdb->update( $this->tables()['licenses'], array( 'paid_until' => $goodwill ), array( 'id' => $f['license_id'] ) );
		$order->payment_complete( 'tx' );

		$this->assertFalse( $this->finish_paid( $renewal, $license, wc_get_order( $order->get_id() ) ) );

		$this->assertSame( $goodwill, $this->license( $f['license_id'] )['paid_until'], 'The goodwill term must stay.' );
		$this->assertSame( RenewalProcessor::STATUS_REVIEW, $this->renewal( $f['renewal_id'] )['status'] );
		$this->assertSame( 'yes', wc_get_order( $order->get_id() )->get_meta( RenewalOrder::META_REVIEW, true ) );
		$this->assertContains( 'renewal_paid_not_applied', $this->audit_actions( 'renewal', $f['renewal_id'] ) );
		$this->assertCount( 1, $this->renewals_of( $f['license_id'] ), 'No orphaned follow-up period.' );
	}

	/** B-50: a scheduled row of a period that no longer is the license's current one is never charged. */
	public function test_b50_orphaned_renewal_of_a_moved_period_is_cancelled_not_charged(): void {
		global $wpdb;
		$f = $this->renewing_license();
		$wpdb->update( $this->tables()['licenses'], array( 'paid_until' => gmdate( 'Y-m-d H:i:s', time() + 100 * DAY_IN_SECONDS ) ), array( 'id' => $f['license_id'] ) );

		RenewalProcessor::process( $f['renewal_id'] );

		$this->assertSame( 0, $this->charges() );
		$renewal = $this->renewal( $f['renewal_id'] );
		$this->assertSame( 'cancelled', $renewal['status'] );
		$this->assertSame( 'period_changed', $renewal['last_error'] );
	}

	/** A-13: a payment for a license that may not be renewed is not booked as a successful renewal. */
	public function test_a13_payment_for_a_suspended_license_goes_to_review(): void {
		$f       = $this->renewing_license();
		$renewal = $this->renewal( $f['renewal_id'] );
		$license = $this->license( $f['license_id'] );
		$order   = RenewalOrder::get_or_create( $renewal, $license, false );
		LicenseLifecycle::suspend_for_chargeback( $f['license_id'], 'dispute' );
		$order->payment_complete( 'tx' );

		$this->assertFalse( $this->finish_paid( $renewal, $license, wc_get_order( $order->get_id() ) ) );

		$this->assertSame( 'suspended', $this->license( $f['license_id'] )['status'] );
		$this->assertSame( $f['paid_until'], $this->license( $f['license_id'] )['paid_until'] );
		$this->assertSame( RenewalProcessor::STATUS_REVIEW, $this->renewal( $f['renewal_id'] )['status'] );
		$this->assertNotContains( 'renewal_paid', $this->audit_actions( 'renewal', $f['renewal_id'] ) );
	}

	/** B-32: a run that died after extending the license is finished without extending twice. */
	public function test_b32_interrupted_booking_is_completed_idempotently(): void {
		global $wpdb;
		$f       = $this->renewing_license();
		$tables  = $this->tables();
		$renewal = $this->renewal( $f['renewal_id'] );
		$license = $this->license( $f['license_id'] );
		$order   = RenewalOrder::get_or_create( $renewal, $license, false );
		$order->payment_complete( 'tx' );

		// State after the crash: license extended, entitlements not, renewal still processing.
		$this->assertNotFalse( LicenseLifecycle::apply_automatic_renewal( $f['license_id'], $f['paid_until'], $f['period_end'], (int) $order->get_id() ) );
		$wpdb->update( $tables['entitlements'], array( 'ends_at' => $f['paid_until'] ), array( 'license_id' => $f['license_id'] ) );
		$wpdb->update(
			$tables['renewals'],
			array(
				'status'     => 'processing',
				'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 2 * RenewalProcessor::STUCK_AFTER_SECONDS ),
			),
			array( 'id' => $f['renewal_id'] )
		);

		RenewalProcessor::reconcile_stuck_renewals();

		$this->assertSame( 'paid', $this->renewal( $f['renewal_id'] )['status'] );
		$this->assertSame( $f['period_end'], $this->license( $f['license_id'] )['paid_until'] );
		$this->assertSame( array( 'active@' . $f['period_end'] ), $this->entitlement_states( $f['license_id'] ) );
		$this->assertCount( 2, $this->renewals_of( $f['license_id'] ), 'The next period is scheduled.' );
		$this->assertSame( 0, $this->charges() );
	}

	/** B-31: an exception in a listener of the status event cannot lose the next period. */
	public function test_b31_listener_exception_does_not_lose_the_next_period(): void {
		$f = $this->renewing_license();
		add_action(
			'wplc/license_status_changed',
			static function ( $license_id, $old, $new, $action ) {
				if ( 'renewal_succeeded' === $action ) {
					throw new RuntimeException( 'listener failed' );
				}
			},
			1,
			4
		);

		try {
			RenewalProcessor::process( $f['renewal_id'] );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'listener failed', $e->getMessage() );
		}

		$this->assertSame( 'paid', $this->renewal( $f['renewal_id'] )['status'] );
		$rows = $this->renewals_of( $f['license_id'] );
		$this->assertCount( 2, $rows );
		$this->assertSame( 'scheduled', $rows[1]['status'] );
	}

	/** B-31: the daily run creates a missing renewal row for a renewing license. */
	public function test_b31_daily_repair_schedules_a_missing_period(): void {
		global $wpdb;
		$f = $this->renewing_license( 40 * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->tables()['renewals']} WHERE license_id = %d", $f['license_id'] ) );

		RenewalProcessor::repair_missing_schedules();

		$rows = $this->renewals_of( $f['license_id'] );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'scheduled', $rows[0]['status'] );
		$this->assertSame( $f['paid_until'], $rows[0]['period_start'] );
		$this->assertContains( 'renewal_schedule_repaired', $this->audit_actions( 'license', $f['license_id'] ) );

		// A cancelled license is left alone.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->tables()['renewals']} WHERE license_id = %d", $f['license_id'] ) );
		$wpdb->update( $this->tables()['licenses'], array( 'cancel_at_period_end' => 1 ), array( 'id' => $f['license_id'] ) );
		RenewalProcessor::repair_missing_schedules();
		$this->assertCount( 0, $this->renewals_of( $f['license_id'] ) );
	}

	/** B-11: after a backlog a failed attempt does not make the next retry due at once. */
	public function test_b11_retry_after_a_late_attempt_is_at_least_a_day_away(): void {
		$f = $this->renewing_license( -10 * DAY_IN_SECONDS );
		WPLC_Test_Recurring_Adapter::$mode = 'decline';

		RenewalProcessor::process( $f['renewal_id'] );
		RenewalProcessor::process( $f['renewal_id'] );
		RenewalProcessor::process( $f['renewal_id'] );

		$renewal = $this->renewal( $f['renewal_id'] );
		$this->assertSame( 1, $this->charges(), 'All attempts were used up in one go.' );
		$this->assertSame( '1', (string) $renewal['attempt_count'] );
		$this->assertGreaterThanOrEqual( time() + DAY_IN_SECONDS - 5, strtotime( $renewal['retry_at'] . ' UTC' ) );
	}

	/** B-188: a duplicate job does not bring the next attempt forward. */
	public function test_b188_duplicate_job_does_not_charge_before_retry_at(): void {
		$f = $this->renewing_license();
		WPLC_Test_Recurring_Adapter::$mode = 'decline';
		RenewalProcessor::process( $f['renewal_id'] );
		$first = $this->renewal( $f['renewal_id'] );
		$this->assertSame( 'failed', $first['status'] );

		WPLC_Test_Recurring_Adapter::$mode = 'success';
		RenewalProcessor::process( $f['renewal_id'] );

		$this->assertSame( 1, $this->charges() );
		$this->assertSame( $first['retry_at'], $this->renewal( $f['renewal_id'] )['retry_at'] );
		$this->assertSame( 'past_due', $this->license( $f['license_id'] )['status'] );
	}

	/** Retries: when retry_at has come the next attempt uses a new gateway key and can succeed. */
	public function test_due_retry_charges_with_a_new_idempotency_key(): void {
		global $wpdb;
		$f = $this->renewing_license( -2 * DAY_IN_SECONDS );
		WPLC_Test_Recurring_Adapter::$mode = 'decline';
		RenewalProcessor::process( $f['renewal_id'] );
		$wpdb->update( $this->tables()['renewals'], array( 'retry_at' => gmdate( 'Y-m-d H:i:s', time() - 10 ) ), array( 'id' => $f['renewal_id'] ) );

		WPLC_Test_Recurring_Adapter::$mode = 'success';
		RenewalProcessor::process( $f['renewal_id'] );

		$this->assertSame( 2, $this->charges() );
		$this->assertNotSame( WPLC_Test_Recurring_Adapter::$charges[0]['key'], WPLC_Test_Recurring_Adapter::$charges[1]['key'] );
		$this->assertSame( WPLC_Test_Recurring_Adapter::$charges[0]['order'], WPLC_Test_Recurring_Adapter::$charges[1]['order'] );
		$this->assertSame( 'active', $this->license( $f['license_id'] )['status'] );
		$this->assertSame( $f['period_end'], $this->license( $f['license_id'] )['paid_until'] );
	}

	/** T-136: an unknown outcome neither sets the license past due nor counts as an attempt. */
	public function test_t136_unknown_result_keeps_the_license_active(): void {
		$f = $this->renewing_license();
		WPLC_Test_Recurring_Adapter::$mode = 'unknown';

		RenewalProcessor::process( $f['renewal_id'] );

		$this->assertSame( 'active', $this->license( $f['license_id'] )['status'] );
		$renewal = $this->renewal( $f['renewal_id'] );
		$this->assertSame( 'failed', $renewal['status'] );
		$this->assertSame( '0', (string) $renewal['attempt_count'] );
		$this->assertSame( RenewalProcessor::RESULT_UNKNOWN, $renewal['last_error'] );
		$this->assertContains( 'renewal_result_unknown', $this->audit_actions( 'renewal', $f['renewal_id'] ) );
		$this->assertNotContains( 'renewal_failed', $this->audit_actions( 'license', $f['license_id'] ) );
	}

	/** A-114: an unknown outcome is not repeated with the same key after the gateway may have forgotten it. */
	public function test_a114_unknown_result_is_handed_to_review_after_the_safe_window(): void {
		global $wpdb;
		$f = $this->renewing_license();
		WPLC_Test_Recurring_Adapter::$mode = 'unknown';
		RenewalProcessor::process( $f['renewal_id'] );
		$first_key = WPLC_Test_Recurring_Adapter::$charges[0]['key'];

		// The hourly repeats kept failing for longer than the window.
		$wpdb->update(
			$this->tables()['renewals'],
			array(
				'retry_at'        => gmdate( 'Y-m-d H:i:s', time() - 10 ),
				'last_attempt_at' => gmdate( 'Y-m-d H:i:s', time() - RenewalProcessor::UNKNOWN_RESULT_WINDOW - 60 ),
			),
			array( 'id' => $f['renewal_id'] )
		);
		RenewalProcessor::process( $f['renewal_id'] );

		$this->assertSame( 2, $this->charges() );
		$this->assertSame( $first_key, WPLC_Test_Recurring_Adapter::$charges[1]['key'], 'A repeat of an unknown attempt reuses the key.' );
		$this->assertSame( RenewalProcessor::STATUS_REVIEW, $this->renewal( $f['renewal_id'] )['status'] );

		RenewalProcessor::process( $f['renewal_id'] );
		RenewalProcessor::requeue_overdue_renewals();
		$this->assertSame( 2, $this->charges(), 'A renewal in review is never charged again automatically.' );
	}

	/** B-62: a decline marked as not retryable stops the retries. */
	public function test_b62_hard_decline_stops_retries(): void {
		$f = $this->renewing_license();
		WPLC_Test_Recurring_Adapter::$mode = 'hard';

		RenewalProcessor::process( $f['renewal_id'] );

		$renewal = $this->renewal( $f['renewal_id'] );
		$this->assertSame( 'manual_action_required', $renewal['status'] );
		$this->assertNull( $renewal['retry_at'] );
		$this->assertSame( 'past_due', $this->license( $f['license_id'] )['status'] );
		RenewalProcessor::process( $f['renewal_id'] );
		$this->assertSame( 1, $this->charges() );
	}

	/** A-15: a missing gateway integration is not a failed payment of the customer. */
	public function test_a15_missing_gateway_blocks_without_counting_an_attempt(): void {
		global $wpdb;
		$f = $this->renewing_license();
		$wpdb->update( $this->tables()['licenses'], array( 'payment_gateway_id' => 'gone' ), array( 'id' => $f['license_id'] ) );
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

		$this->assertSame( 'active', $this->license( $f['license_id'] )['status'] );
		$renewal = $this->renewal( $f['renewal_id'] );
		$this->assertSame( 'scheduled', $renewal['status'] );
		$this->assertSame( '0', (string) $renewal['attempt_count'] );
		$this->assertSame( 'renewal_gateway_unavailable', $renewal['last_error'] );
		$this->assertGreaterThan( time() + HOUR_IN_SECONDS, strtotime( $renewal['retry_at'] . ' UTC' ) );
		$this->assertSame( array( 'renewal_gateway_unavailable' ), $blocked );
		$this->assertContains( 'renewal_blocked', $this->audit_actions( 'renewal', $f['renewal_id'] ) );
	}

	/** B-41: the charge can be postponed through a filter (e.g. advance notice not sent). */
	public function test_b41_charge_can_be_postponed_by_filter(): void {
		$f = $this->renewing_license();
		add_filter( 'wplc_renewal_charge_allowed', '__return_false' );

		RenewalProcessor::process( $f['renewal_id'] );

		$this->assertSame( 0, $this->charges() );
		$this->assertSame( 'active', $this->license( $f['license_id'] )['status'] );
		$this->assertSame( 'renewal_charge_not_allowed', $this->renewal( $f['renewal_id'] )['last_error'] );
	}

	/** B-01: while billing is paused nothing is charged and nothing changes. */
	public function test_b01_paused_billing_does_not_charge_or_expire(): void {
		$f = $this->renewing_license( -20 * DAY_IN_SECONDS );
		add_filter( 'wplc_billing_paused', '__return_true' );
		$before = $this->renewal( $f['renewal_id'] );

		RenewalProcessor::process( $f['renewal_id'] );
		do_action( 'wplc_daily_lifecycle' );
		RenewalProcessor::expire_after_grace( $f['license_id'] );

		$this->assertSame( 0, $this->charges() );
		$this->assertSame( $before, $this->renewal( $f['renewal_id'] ) );
		$this->assertSame( 'active', $this->license( $f['license_id'] )['status'] );
	}

	/** A-14: an offline payment the customer started is not a payment failure on the due date. */
	public function test_a14_waiting_bank_transfer_is_not_a_payment_failure(): void {
		$f     = $this->renewing_license( 5 * DAY_IN_SECONDS );
		$order = ManualRenewal::start( $f['license_id'], $f['customer'] );
		$this->assertInstanceOf( WC_Order::class, $order, is_wp_error( $order ) ? $order->get_error_message() : '' );
		$order->set_payment_method( 'bacs' );
		$order->update_status( 'on-hold' );

		// The due date arrives.
		global $wpdb;
		$due = gmdate( 'Y-m-d H:i:s', time() - 60 );
		$wpdb->update( $this->tables()['licenses'], array( 'paid_until' => $due ), array( 'id' => $f['license_id'] ) );
		$wpdb->update(
			$this->tables()['renewals'],
			array(
				'period_start' => $due,
				'retry_at'     => $due,
			),
			array( 'id' => $f['renewal_id'] )
		);
		RenewalProcessor::process( $f['renewal_id'] );

		$this->assertSame( 0, $this->charges() );
		$this->assertSame( 'active', $this->license( $f['license_id'] )['status'], 'No dunning while the transfer is under way.' );
		$renewal = $this->renewal( $f['renewal_id'] );
		$this->assertSame( '0', (string) $renewal['attempt_count'] );
		$this->assertSame( 'renewal_awaiting_customer_payment', $renewal['last_error'] );
		$this->assertSame( 'on-hold', wc_get_order( $order->get_id() )->get_status() );
	}

	/** A-18: an overdue renewal that was never attempted is charged, not expired unattempted. */
	public function test_a18_backlog_renewal_is_attempted_after_grace(): void {
		$f = $this->renewing_license( -20 * DAY_IN_SECONDS );

		LicenseLifecycle::expire_due_licenses();
		$this->assertSame( 'active', $this->license( $f['license_id'] )['status'], 'Expired without a single charge attempt.' );

		RenewalProcessor::process( $f['renewal_id'] );

		$this->assertSame( 1, $this->charges() );
		$this->assertSame( 'active', $this->license( $f['license_id'] )['status'] );
		$this->assertSame( $f['period_end'], $this->license( $f['license_id'] )['paid_until'] );
	}

	/** A-18: when that late attempt fails the license ends; nothing is retried beyond grace. */
	public function test_a18_failed_late_attempt_ends_the_license(): void {
		$f = $this->renewing_license( -20 * DAY_IN_SECONDS );
		WPLC_Test_Recurring_Adapter::$mode = 'decline';

		RenewalProcessor::process( $f['renewal_id'] );
		$this->assertSame( 'past_due', $this->license( $f['license_id'] )['status'] );
		RenewalProcessor::expire_after_grace( $f['license_id'] );

		$this->assertSame( 'expired', $this->license( $f['license_id'] )['status'] );
		$this->assertSame( 'cancelled', $this->renewal( $f['renewal_id'] )['status'] );
	}

	/** A-18: the daily sweep queues every overdue renewal, not only the first hundred. */
	public function test_a18_requeue_is_not_limited_to_one_hundred(): void {
		global $wpdb;
		$tables = $this->tables();
		$past   = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$now    = gmdate( 'Y-m-d H:i:s' );
		for ( $i = 0; $i < 130; $i++ ) {
			$wpdb->insert(
				$tables['renewals'],
				array(
					'license_id'      => 900000 + $i,
					'period_start'    => $past,
					'period_end'      => $now,
					'idempotency_key' => hash( 'sha256', 'a18-' . $i ),
					'status'          => 'scheduled',
					'retry_at'        => $past,
					'grace_ends_at'   => $now,
					'created_at'      => $now,
					'updated_at'      => $now,
				)
			);
		}
		$last = (int) $wpdb->insert_id;

		RenewalProcessor::requeue_overdue_renewals();

		$this->assertNotFalse( as_next_scheduled_action( 'wplc_process_renewal', array( $last ), 'wplc' ), 'Renewal 130 of the backlog was not queued.' );
	}

	/** A-12: once the license has expired its failed renewal order can no longer be paid. */
	public function test_a12_failed_renewal_order_is_closed_when_the_license_expires(): void {
		global $wpdb;
		$f = $this->renewing_license( -3 * DAY_IN_SECONDS );
		WPLC_Test_Recurring_Adapter::$mode = 'decline';
		RenewalProcessor::process( $f['renewal_id'] );
		$order_id = (int) $this->renewal( $f['renewal_id'] )['renewal_order_id'];
		$this->assertTrue( wc_get_order( $order_id )->needs_payment() );

		$wpdb->update( $this->tables()['licenses'], array( 'paid_until' => gmdate( 'Y-m-d H:i:s', time() - 15 * DAY_IN_SECONDS ) ), array( 'id' => $f['license_id'] ) );
		LicenseLifecycle::expire_due_licenses();

		$this->assertSame( 'expired', $this->license( $f['license_id'] )['status'] );
		$order = wc_get_order( $order_id );
		$this->assertSame( 'cancelled', $order->get_status() );
		$this->assertFalse( $order->needs_payment() );
		$this->assertSame( 'cancelled', $this->renewal( $f['renewal_id'] )['status'] );
	}

	/** A-12: a payment that still arrives for a closed renewal is reported on the order. */
	public function test_a12_payment_for_a_closed_renewal_is_flagged_not_swallowed(): void {
		global $wpdb;
		$f = $this->renewing_license();
		WPLC_Test_Recurring_Adapter::$mode = 'decline';
		RenewalProcessor::process( $f['renewal_id'] );
		$order_id = (int) $this->renewal( $f['renewal_id'] )['renewal_order_id'];
		$wpdb->update( $this->tables()['renewals'], array( 'status' => 'cancelled' ), array( 'id' => $f['renewal_id'] ) );

		$order = wc_get_order( $order_id );
		$order->payment_complete( 'late' );
		RenewalProcessor::reconcile_paid_renewal_order( $order_id );

		$order = wc_get_order( $order_id );
		$this->assertSame( 'yes', $order->get_meta( RenewalOrder::META_REVIEW, true ) );
		$this->assertContains( 'renewal_recovery_not_allowed', $this->audit_actions( 'renewal', $f['renewal_id'] ) );
		$this->assertSame( $f['paid_until'], $this->license( $f['license_id'] )['paid_until'] );
		$notes = wc_get_order_notes( array( 'order_id' => $order_id ) );
		$this->assertNotEmpty( array_filter( $notes, static function ( $note ) {
			return false !== strpos( $note->content, 'could not be applied' );
		} ) );
	}

	/** A-17: withdrawing the cancellation of a past-due license brings its renewal back. */
	public function test_a17_resume_of_a_past_due_license_revives_the_renewal(): void {
		$f = $this->renewing_license();
		WPLC_Test_Recurring_Adapter::$mode = 'decline';
		RenewalProcessor::process( $f['renewal_id'] );
		LicenseLifecycle::schedule_cancellation( $f['license_id'], 'x', $f['customer'] );
		RenewalProcessor::process( $f['renewal_id'] );
		$this->assertSame( 'cancelled', $this->renewal( $f['renewal_id'] )['status'] );

		$this->assertTrue( LicenseLifecycle::resume_renewal( $f['license_id'], $f['customer'] ) );

		$this->assertSame( 'scheduled', $this->renewal( $f['renewal_id'] )['status'] );
		WPLC_Test_Recurring_Adapter::$mode = 'success';
		RenewalProcessor::process( $f['renewal_id'] );
		$this->assertSame( 'active', $this->license( $f['license_id'] )['status'] );
		$this->assertSame( $f['period_end'], $this->license( $f['license_id'] )['paid_until'] );
	}

	/** A-17: a renewal cancelled while the license was suspended is scheduled again on reactivation. */
	public function test_a17_reactivation_after_suspension_schedules_the_renewal_again(): void {
		$f = $this->renewing_license( 3 * DAY_IN_SECONDS );
		LicenseLifecycle::suspend_for_chargeback( $f['license_id'], 'dispute' );
		global $wpdb;
		$wpdb->update( $this->tables()['renewals'], array( 'retry_at' => gmdate( 'Y-m-d H:i:s', time() - 10 ) ), array( 'id' => $f['renewal_id'] ) );
		RenewalProcessor::process( $f['renewal_id'] );
		$this->assertSame( 'cancelled', $this->renewal( $f['renewal_id'] )['status'] );

		$this->assertTrue( LicenseLifecycle::admin_set_status( $f['license_id'], 'active', 'dispute won', 1 ) );

		$this->assertSame( 'scheduled', $this->renewal( $f['renewal_id'] )['status'] );
	}

	/** A-19: a paid renewal order whose booking job was lost is booked by the daily run. */
	public function test_a19_daily_run_books_a_paid_order_whose_job_was_lost(): void {
		$f = $this->renewing_license();
		WPLC_Test_Recurring_Adapter::$mode = 'decline';
		RenewalProcessor::process( $f['renewal_id'] );
		$order = wc_get_order( (int) $this->renewal( $f['renewal_id'] )['renewal_order_id'] );
		$order->payment_complete( 'customer_tx' );
		$this->assertSame( 'past_due', $this->license( $f['license_id'] )['status'], 'Booking is asynchronous.' );

		RenewalProcessor::reconcile_unbooked_paid_orders();

		$this->assertSame( 'active', $this->license( $f['license_id'] )['status'] );
		$this->assertSame( $f['period_end'], $this->license( $f['license_id'] )['paid_until'] );
		$this->assertSame( 'paid', $this->renewal( $f['renewal_id'] )['status'] );
	}

	/** A-19: a verified Stripe event whose job was lost is queued again. */
	public function test_a19_pending_stripe_event_is_requeued(): void {
		global $wpdb;
		$wpdb->insert(
			$this->tables()['event_inbox'],
			array(
				'source'          => 'stripe',
				'source_event_id' => 'evt_a19',
				'event_type'      => 'payment_intent.succeeded',
				'payload_hash'    => hash( 'sha256', 'a19' ),
				'payload_json'    => '{}',
				'received_at'     => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
				'outcome'         => 'pending_reconciliation',
			)
		);
		$inbox_id = (int) $wpdb->insert_id;

		RenewalProcessor::requeue_pending_stripe_events();

		$this->assertNotFalse( as_next_scheduled_action( 'wplc_process_stripe_webhook', array( $inbox_id ), 'wplc' ) );
	}

	/** B-184: an order the customer just opened is not charged by the automatic renewal. */
	public function test_b184_order_the_customer_is_paying_is_not_adopted(): void {
		$f     = $this->renewing_license();
		$order = ManualRenewal::start( $f['license_id'], $f['customer'] );
		$this->assertInstanceOf( WC_Order::class, $order, is_wp_error( $order ) ? $order->get_error_message() : '' );

		RenewalProcessor::process( $f['renewal_id'] );

		$this->assertSame( 0, $this->charges() );
		$renewal = $this->renewal( $f['renewal_id'] );
		$this->assertSame( 'renewal_order_in_use', $renewal['last_error'] );
		$this->assertSame( '0', (string) $renewal['attempt_count'] );
		$this->assertSame( 'active', $this->license( $f['license_id'] )['status'] );
		$this->assertTrue( RenewalOrder::is_manual( wc_get_order( $order->get_id() ) ) );
	}

	/** B-184: a payment by the customer during the automatic charge is reported as a conflict. */
	public function test_b184_payment_during_the_charge_is_reported_and_booked_once(): void {
		$f = $this->renewing_license();
		WPLC_Test_Recurring_Adapter::$during_charge = static function ( $order ) {
			$same = wc_get_order( $order->get_id() );
			$same->payment_complete( 'customer_tx' );
		};

		RenewalProcessor::process( $f['renewal_id'] );

		$renewal = $this->renewal( $f['renewal_id'] );
		$order   = wc_get_order( (int) $renewal['renewal_order_id'] );
		$this->assertSame( 'customer_tx', $order->get_transaction_id(), 'The first payment must not be overwritten.' );
		$this->assertSame( 'test_tx_1', $order->get_meta( '_wplc_duplicate_transaction_id', true ) );
		$this->assertContains( 'renewal_double_payment', $this->audit_actions( 'renewal', $f['renewal_id'] ) );
		$this->assertSame( 'paid', $renewal['status'] );
		$this->assertSame( $f['period_end'], $this->license( $f['license_id'] )['paid_until'] );
		$this->assertCount( 2, $this->renewals_of( $f['license_id'] ) );
	}

	/** B-186 / B-190: stale renewals are closed together with their orders. */
	public function test_b186_cleanup_closes_abandoned_manual_renewals(): void {
		global $wpdb;
		$tables   = $this->tables();
		$customer = $this->customer();
		$license  = $this->buy( $this->create_license_product(), $customer, false );
		$this->move_due_date( (int) $license['id'], 5 * DAY_IN_SECONDS );
		$order = ManualRenewal::start( (int) $license['id'], $customer );
		$this->assertInstanceOf( WC_Order::class, $order, is_wp_error( $order ) ? $order->get_error_message() : '' );
		$this->assertSame( RenewalOrder::CREATED_VIA, $order->get_created_via() );
		$renewal_id = (int) $order->get_meta( RenewalOrder::META_RENEWAL, true );
		$this->assertSame( 'manual', $this->renewal( $renewal_id )['status'] );

		// Still inside the window: nothing happens.
		RenewalProcessor::cleanup_stale_renewals();
		$this->assertSame( 'manual', $this->renewal( $renewal_id )['status'] );

		$old = gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS );
		$wpdb->update( $tables['renewals'], array( 'period_start' => $old ), array( 'id' => $renewal_id ) );
		$wpdb->update(
			$tables['licenses'],
			array(
				'paid_until' => $old,
				'status'     => 'expired',
			),
			array( 'id' => (int) $license['id'] )
		);
		RenewalProcessor::cleanup_stale_renewals();

		$renewal = $this->renewal( $renewal_id );
		$this->assertSame( 'cancelled', $renewal['status'] );
		$this->assertSame( 'renewal_window_ended', $renewal['last_error'] );
		$this->assertSame( 'cancelled', wc_get_order( $order->get_id() )->get_status() );
	}

	/** B-34 / B-35: a failing daily task is contained and every task leaves a heartbeat. */
	public function test_b34_guarded_task_contains_exceptions_and_records_a_heartbeat(): void {
		$ok = LicenseLifecycle::run_guarded(
			'test_job',
			static function () {
				throw new RuntimeException( 'boom' );
			}
		);
		$this->assertFalse( $ok );
		// One wrapper and one store: the failure is kept by DailyRunner, heartbeats by Diagnostics.
		$this->assertStringContainsString( 'boom', \WPLC\Maintenance\DailyRunner::failures()['test_job']['message'] );
		$this->assertSame( 0, \WPLC\Admin\Diagnostics::last_run( 'test_job' ), 'A failed task leaves no heartbeat.' );
		$this->assertTrue( LicenseLifecycle::run_guarded( 'test_job', '__return_true' ) );
		$this->assertGreaterThan( 0, \WPLC\Admin\Diagnostics::last_run( 'test_job' ) );

		// An alert receiver that throws inside a renewal job does not stop the daily chain.
		$f = $this->renewing_license();
		global $wpdb;
		$wpdb->update( $this->tables()['licenses'], array( 'payment_gateway_id' => 'gone' ), array( 'id' => $f['license_id'] ) );
		$wpdb->update( $this->tables()['renewals'], array( 'status' => 'cancelled' ), array( 'id' => $f['renewal_id'] ) );
		add_action(
			'wplc/license_status_changed',
			static function () {
				throw new RuntimeException( 'alert receiver failed' );
			}
		);
		$expiring = $this->seed_license(
			array(
				'customer_id' => $f['customer'],
				'paid_until'  => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
			)
		)['id'];

		do_action( 'wplc_daily_lifecycle' );

		$failures = \WPLC\Maintenance\DailyRunner::failures();
		$this->assertArrayHasKey( 'license_lifecycle_expire_due_licenses', $failures, 'The exception of the listener was caught and recorded.' );
		$this->assertSame( 'expired', $this->license( $expiring )['status'] );
		$this->assertGreaterThan( 0, \WPLC\Admin\Diagnostics::last_run( 'renewal_processor_repair_missing_schedules' ), 'Tasks after the failing one still ran.' );
		$this->assertSame( 'scheduled', $this->renewal( $f['renewal_id'] )['status'], 'The repair task ran after the failing task.' );
	}
}
