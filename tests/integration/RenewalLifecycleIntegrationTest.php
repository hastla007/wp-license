<?php
/**
 * License lifecycle around renewals: administrator actions, refunds, expiry, order status changes.
 *
 * @package WPLC
 */

use WPLC\Billing\ManualRenewal;
use WPLC\Billing\RenewalProcessor;
use WPLC\License\LicenseLifecycle;

require_once __DIR__ . '/helpers/RenewalTestCase.php';

final class RenewalLifecycleIntegrationTest extends WPLC_Renewal_TestCase {

	private function future( int $days ): string {
		return gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS );
	}

	/** B-50: the term cannot be changed while an automatic charge is in flight. */
	public function test_b50_admin_extend_is_refused_while_a_charge_is_in_flight(): void {
		global $wpdb;
		$f = $this->renewing_license();
		$wpdb->update( $this->tables()['renewals'], array( 'status' => 'processing' ), array( 'id' => $f['renewal_id'] ) );

		$this->assertFalse( LicenseLifecycle::admin_extend( $f['license_id'], $this->future( 800 ), 'Goodwill', 1 ) );

		$this->assertSame( 'renewal_in_progress', LicenseLifecycle::last_refusal() );
		$this->assertSame( $f['paid_until'], $this->license( $f['license_id'] )['paid_until'] );
		$this->assertSame( 'processing', $this->renewal( $f['renewal_id'] )['status'] );
	}

	/** B-50: a renewal stuck in processing after a crash does not block the administrator for good. */
	public function test_b50_admin_extend_resolves_a_stuck_renewal_first(): void {
		global $wpdb;
		$f = $this->renewing_license();
		$wpdb->update(
			$this->tables()['renewals'],
			array(
				'status'     => 'processing',
				'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 2 * RenewalProcessor::STUCK_AFTER_SECONDS ),
			),
			array( 'id' => $f['renewal_id'] )
		);
		$goodwill = $this->future( 800 );

		$this->assertTrue( LicenseLifecycle::admin_extend( $f['license_id'], $goodwill, 'Goodwill', 1 ) );

		$this->assertSame( $goodwill, $this->license( $f['license_id'] )['paid_until'] );
		$rows = $this->renewals_of( $f['license_id'] );
		$this->assertSame( 'cancelled', $rows[0]['status'], 'The renewal of the old period is closed.' );
		$this->assertSame( 'scheduled', $rows[1]['status'] );
		$this->assertSame( $goodwill, $rows[1]['period_start'] );

		// The old row can never charge.
		RenewalProcessor::process( $f['renewal_id'] );
		$this->assertSame( 0, $this->charges() );
	}

	/** B-46: changing the term closes the customer's open manual renewal order. */
	public function test_b46_admin_extend_closes_the_open_manual_renewal_order(): void {
		$customer = $this->customer();
		$license  = $this->buy( $this->create_license_product(), $customer, false );
		$this->move_due_date( (int) $license['id'], 10 * DAY_IN_SECONDS );
		$order = ManualRenewal::start( (int) $license['id'], $customer );
		$this->assertInstanceOf( WC_Order::class, $order, is_wp_error( $order ) ? $order->get_error_message() : '' );
		$this->assertTrue( $order->needs_payment() );
		$renewal_id = (int) $order->get_meta( '_wplc_renewal_id', true );

		$goodwill = $this->future( 40 );
		$this->assertTrue( LicenseLifecycle::admin_extend( (int) $license['id'], $goodwill, 'One month goodwill', 1 ) );

		$order = wc_get_order( $order->get_id() );
		$this->assertFalse( $order->needs_payment(), 'The order must not stay payable without effect.' );
		$this->assertSame( 'cancelled', $order->get_status() );
		$renewal = $this->renewal( $renewal_id );
		$this->assertSame( 'cancelled', $renewal['status'] );
		$this->assertSame( 'term_changed', $renewal['last_error'] );
		$this->assertSame( $goodwill, $this->license( (int) $license['id'] )['paid_until'] );
	}

	/** B-133: a stale "change term" form does not overwrite a newer term. */
	public function test_b133_admin_extend_checks_the_term_the_admin_saw(): void {
		$license = $this->seed_license()['id'];
		$seen    = $this->license( $license )['paid_until'];
		$this->assertTrue( LicenseLifecycle::admin_extend( $license, $this->future( 400 ), 'first admin', 1, $seen ) );

		$this->assertFalse( LicenseLifecycle::admin_extend( $license, $this->future( 60 ), 'second admin, stale form', 2, $seen ) );

		$this->assertSame( 'term_changed', LicenseLifecycle::last_refusal() );
		$this->assertSame( $this->license( $license )['paid_until'], $this->license( $license )['paid_until'] );
		$this->assertGreaterThan( $this->future( 399 ), $this->license( $license )['paid_until'] );
	}

	/** B-47: a revocation by refund names the acting user and the order, and can be taken back. */
	public function test_b47_refund_revocation_is_attributed_and_can_be_restored(): void {
		$customer = $this->customer();
		$license  = $this->buy( $this->create_license_product(), $customer, false );
		$order_id = (int) $license['source_order_id'];
		$clerk    = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		wp_set_current_user( $clerk );

		wc_get_order( $order_id )->update_status( 'refunded', 'clicked the wrong status' );

		$this->assertSame( 'revoked', $this->license( (int) $license['id'] )['status'] );
		$rows = $this->audit_rows( 'license', (int) $license['id'] );
		$last = end( $rows );
		$this->assertSame( 'order_refund', $last['action'] );
		$this->assertSame( $clerk, (int) $last['actor_user_id'], 'The refund must not be attributed to "System".' );
		$this->assertStringContainsString( '#' . $order_id, $last['reason'] );

		// A normal status change still cannot undo it ...
		$this->assertFalse( LicenseLifecycle::admin_set_status( (int) $license['id'], 'active', 'undo', $clerk ) );
		// ... the dedicated restore can, with a reason and an actor.
		$this->assertFalse( LicenseLifecycle::admin_restore( (int) $license['id'], '', $clerk ) );
		$this->assertFalse( LicenseLifecycle::admin_restore( (int) $license['id'], 'undo wrong refund', 0 ) );
		$this->assertTrue( LicenseLifecycle::admin_restore( (int) $license['id'], 'undo wrong refund', $clerk ) );

		$restored = $this->license( (int) $license['id'] );
		$this->assertSame( 'active', $restored['status'] );
		$this->assertNull( $restored['revoked_at'] );
		$this->assertSame( array( 'active@' . $restored['paid_until'] ), $this->entitlement_states( (int) $license['id'] ) );
		$rows = $this->audit_rows( 'license', (int) $license['id'] );
		$last = end( $rows );
		$this->assertSame( 'admin_restore', $last['action'] );
		$this->assertSame( $clerk, (int) $last['actor_user_id'] );
	}

	/** B-176: a reason longer than the column does not make the status change fail. */
	public function test_b176_long_reason_does_not_block_the_chargeback_suspension(): void {
		$license = $this->seed_license()['id'];

		do_action( 'wplc/chargeback', $license, str_repeat( 'Dispute opened by the card issuer. ', 20 ) );

		$row = $this->license( $license );
		$this->assertSame( 'suspended', $row['status'] );
		$this->assertSame( 255, strlen( $row['status_reason'] ) );
	}

	/** B-190: entitlements of a suspended license become revoked with the license. */
	public function test_b190_entitlements_follow_a_revocation_after_suspension(): void {
		$license = $this->seed_license()['id'];
		LicenseLifecycle::suspend_for_chargeback( $license, 'dispute' );
		$this->assertSame( 'suspended', substr( $this->entitlement_states( $license )[0], 0, 9 ) );

		$this->assertTrue( LicenseLifecycle::admin_set_status( $license, 'revoked', 'dispute lost', 1 ) );

		foreach ( $this->entitlement_states( $license ) as $state ) {
			$this->assertStringStartsWith( 'revoked@', $state );
		}
	}

	/** A-51: a suspended license whose term ended can leave `suspended` and get a new term. */
	public function test_a51_suspended_license_with_ended_term_can_be_reactivated(): void {
		global $wpdb;
		$license = $this->seed_license()['id'];
		LicenseLifecycle::suspend_for_chargeback( $license, 'dispute' );
		$wpdb->update( $this->tables()['licenses'], array( 'paid_until' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ), array( 'id' => $license ) );

		$this->assertTrue( LicenseLifecycle::admin_set_status( $license, 'active', 'dispute won', 1 ) );
		$this->assertSame( 'expired', $this->license( $license )['status'], 'The term is over, so the license is expired, not active.' );

		$new_term = $this->future( 90 );
		$this->assertTrue( LicenseLifecycle::admin_extend( $license, $new_term, 'make up for the dispute', 1 ) );
		$this->assertSame( 'active', $this->license( $license )['status'] );
		$this->assertSame( array( 'active@' . $new_term ), $this->entitlement_states( $license ) );
	}

	/** B-36: a renewal order that fails after it was booked suspends the renewed license. */
	public function test_b36_failed_paid_renewal_order_suspends_the_license(): void {
		$f = $this->renewing_license();
		RenewalProcessor::process( $f['renewal_id'] );
		$this->assertSame( 'active', $this->license( $f['license_id'] )['status'] );
		$needs_review = array();
		add_action(
			'wplc/license_needs_review',
			static function ( $license_id ) use ( &$needs_review ) {
				$needs_review[] = (int) $license_id;
			}
		);

		wc_get_order( (int) $this->renewal( $f['renewal_id'] )['renewal_order_id'] )->update_status( 'failed', 'debit returned' );

		$this->assertSame( 'suspended', $this->license( $f['license_id'] )['status'] );
		$this->assertSame( array( $f['license_id'] ), $needs_review );
	}

	/** B-36: the failed order of a declined charge (never paid) changes nothing. */
	public function test_b36_failed_charge_attempt_does_not_suspend(): void {
		$f = $this->renewing_license();
		WPLC_Test_Recurring_Adapter::$mode = 'decline';

		RenewalProcessor::process( $f['renewal_id'] );

		$this->assertSame( 'failed', wc_get_order( (int) $this->renewal( $f['renewal_id'] )['renewal_order_id'] )->get_status() );
		$this->assertSame( 'past_due', $this->license( $f['license_id'] )['status'] );
	}

	/** T-50: a full refund of a renewal order takes the renewed period back. */
	public function test_t50_full_refund_of_a_renewal_rolls_the_period_back(): void {
		$f = $this->renewing_license();
		RenewalProcessor::process( $f['renewal_id'] );
		$this->assertSame( $f['period_end'], $this->license( $f['license_id'] )['paid_until'] );
		$order_id = (int) $this->renewal( $f['renewal_id'] )['renewal_order_id'];

		wc_get_order( $order_id )->update_status( 'refunded' );
		// A duplicate hook changes nothing.
		LicenseLifecycle::handle_full_refund( $order_id );

		$license = $this->license( $f['license_id'] );
		$this->assertSame( 'active', $license['status'], 'A refunded renewal does not revoke the license.' );
		$this->assertSame( $f['paid_until'], $license['paid_until'] );
		$this->assertSame( '0', (string) $license['renewal_enabled'] );
		$this->assertSame( array( 'active@' . $f['paid_until'] ), $this->entitlement_states( $f['license_id'] ) );
		$rows = $this->renewals_of( $f['license_id'] );
		$this->assertSame( 'refunded', $rows[0]['status'] );
		$this->assertSame( 'cancelled', $rows[1]['status'], 'The next period must not be charged.' );
		$this->assertCount( 1, array_keys( $this->audit_actions( 'license', $f['license_id'] ), 'renewal_refunded', true ) );
	}

	/** B-183: after a goodwill extension the refunded period is still taken back, by its duration. */
	public function test_b183_refund_after_goodwill_extension_rolls_back_by_the_period_length(): void {
		$f = $this->renewing_license();
		RenewalProcessor::process( $f['renewal_id'] );
		$goodwill = gmdate( 'Y-m-d H:i:s', strtotime( $f['period_end'] . ' UTC' ) + 30 * DAY_IN_SECONDS );
		$this->assertTrue( LicenseLifecycle::admin_extend( $f['license_id'], $goodwill, '30 days goodwill', 1 ) );
		$order_id = (int) $this->renewal( $f['renewal_id'] )['renewal_order_id'];

		wc_get_order( $order_id )->update_status( 'refunded' );

		$expected = gmdate( 'Y-m-d H:i:s', strtotime( $f['paid_until'] . ' UTC' ) + 30 * DAY_IN_SECONDS );
		$this->assertSame( $expected, $this->license( $f['license_id'] )['paid_until'], 'The refunded year stayed on the license.' );
		$this->assertSame( array( 'active@' . $expected ), $this->entitlement_states( $f['license_id'] ) );
	}

	/** B-183: when the term was shortened below the refunded period nothing is rolled back, with a note. */
	public function test_b183_refund_without_rollback_leaves_an_order_note(): void {
		global $wpdb;
		$f = $this->renewing_license();
		RenewalProcessor::process( $f['renewal_id'] );
		$shortened = $this->future( 20 );
		$wpdb->update( $this->tables()['licenses'], array( 'paid_until' => $shortened ), array( 'id' => $f['license_id'] ) );
		$order_id = (int) $this->renewal( $f['renewal_id'] )['renewal_order_id'];

		wc_get_order( $order_id )->update_status( 'refunded' );

		$this->assertSame( $shortened, $this->license( $f['license_id'] )['paid_until'] );
		$notes = wc_get_order_notes( array( 'order_id' => $order_id ) );
		$this->assertNotEmpty(
			array_filter(
				$notes,
				static function ( $note ) {
					return false !== strpos( $note->content, 'NOT rolled back' );
				}
			)
		);
	}

	/** B-33: a license paid or extended while the expiry run is working is not expired. */
	public function test_b33_license_extended_during_the_expiry_run_stays_active(): void {
		global $wpdb;
		$tables = $this->tables();
		$past   = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$first  = $this->seed_license( array( 'paid_until' => $past ) )['id'];
		$second = $this->seed_license( array( 'paid_until' => $past ) )['id'];
		$future = $this->future( 365 );
		add_action(
			'wplc/license_status_changed',
			static function ( $license_id ) use ( $first, $second, $future, $wpdb, $tables ) {
				if ( (int) $license_id === $first ) {
					// The customer pays the second license while the run is busy with the first.
					$wpdb->update( $tables['licenses'], array( 'paid_until' => $future ), array( 'id' => $second ) );
				}
			}
		);

		LicenseLifecycle::expire_due_licenses();

		$this->assertSame( 'expired', $this->license( $first )['status'] );
		$this->assertSame( 'active', $this->license( $second )['status'] );
	}

	/** A-80: the expiry run stops at its time budget and continues in a follow-up run. */
	public function test_a80_expiry_run_hands_over_when_the_time_budget_is_used(): void {
		$past = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$ids  = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$ids[] = $this->seed_license( array( 'paid_until' => $past ) )['id'];
		}
		add_filter(
			'wplc_expiry_time_budget',
			static function () {
				return -1;
			}
		);

		LicenseLifecycle::expire_due_licenses();

		$this->assertSame( 'expired', $this->license( $ids[0] )['status'] );
		$this->assertSame( 'active', $this->license( $ids[1] )['status'] );
		$this->assertNotFalse( as_next_scheduled_action( 'wplc_expire_due_licenses', array(), 'wplc' ), 'No follow-up run was scheduled.' );

		do_action( 'wplc_expire_due_licenses' );
		do_action( 'wplc_expire_due_licenses' );
		$this->assertSame( 'expired', $this->license( $ids[2] )['status'] );
	}

	/** A-25: when the customer account is deleted nothing is charged any more. */
	public function test_a25_deleting_the_customer_switches_automatic_renewal_off(): void {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$f = $this->renewing_license( 10 * DAY_IN_SECONDS );

		wp_delete_user( $f['customer'] );

		$license = $this->license( $f['license_id'] );
		$this->assertSame( '0', (string) $license['renewal_enabled'] );
		$this->assertNull( $license['next_payment_at'] );
		$this->assertSame( 'cancelled', $this->renewal( $f['renewal_id'] )['status'] );
		$this->assertContains( 'renewal_disabled', $this->audit_actions( 'license', $f['license_id'] ) );
	}

	/** B-185: a manual renewal paid late but inside grace continues from the due date. */
	public function test_b185_manual_renewal_paid_in_grace_continues_from_the_due_date(): void {
		$customer = $this->customer();
		$license  = $this->buy( $this->create_license_product(), $customer, false );
		$this->move_due_date( (int) $license['id'], -5 * DAY_IN_SECONDS );
		$due = $this->license( (int) $license['id'] )['paid_until'];
		global $wpdb;
		// Automatic renewal on keeps the license in grace; the customer pays by hand.
		$wpdb->update( $this->tables()['licenses'], array( 'renewal_enabled' => 1 ), array( 'id' => (int) $license['id'] ) );

		$order = ManualRenewal::start( (int) $license['id'], $customer );
		$this->assertInstanceOf( WC_Order::class, $order, is_wp_error( $order ) ? $order->get_error_message() : '' );
		$order->set_payment_method( 'bacs' );
		$order->save();
		$order->payment_complete( 'manual_tx' );

		$expected = gmdate( 'Y-m-d H:i:s', \WPLC\Billing\RenewalScheduler::period_end( strtotime( $due . ' UTC' ), 'year' ) );
		$row      = $this->license( (int) $license['id'] );
		$this->assertSame( 'active', $row['status'] );
		$this->assertSame( $expected, $row['paid_until'], 'Paying five days late must not gift five days.' );
		$this->assertSame( array( 'active@' . $expected ), $this->entitlement_states( (int) $license['id'] ) );
		$this->assertSame( 'paid', $this->renewal( (int) $order->get_meta( '_wplc_renewal_id', true ) )['status'] );

		// A duplicate hook books nothing twice.
		RenewalProcessor::reconcile_paid_renewal_order( (int) $order->get_id() );
		$this->assertSame( $expected, $this->license( (int) $license['id'] )['paid_until'] );
	}
}
