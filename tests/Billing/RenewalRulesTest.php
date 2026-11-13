<?php
use PHPUnit\Framework\TestCase;
use WPLC\Billing\ManualRenewal;
use WPLC\Billing\RenewalProcessor;
use WPLC\Billing\RenewalScheduler;
use WPLC\License\LicenseStateMachine;

/**
 * Pure rules of the renewal package (no WordPress).
 */
final class RenewalRulesTest extends TestCase {

	private function ts( string $utc ): int {
		return (int) strtotime( $utc . ' UTC' );
	}

	private function utc( int $timestamp ): string {
		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/** B-11: on schedule the retries run on day 1, 3 and 7 after the period start. */
	public function test_b11_retries_follow_the_schedule_when_attempts_run_on_time(): void {
		$start = $this->ts( '2026-11-01 10:00:00' );

		$this->assertSame( '2026-11-02 10:00:05', $this->utc( RenewalProcessor::next_retry_at( 1, $start, $start + 5 ) ) );
		$this->assertSame( '2026-11-04 10:00:05', $this->utc( RenewalProcessor::next_retry_at( 2, $start, $this->ts( '2026-11-02 10:00:05' ) ) ) );
		$this->assertSame( '2026-11-08 10:00:10', $this->utc( RenewalProcessor::next_retry_at( 3, $start, $this->ts( '2026-11-04 10:00:10' ) ) ) );
		$this->assertSame( 0, RenewalProcessor::next_retry_at( 4, $start, $this->ts( '2026-11-08 10:00:00' ) ) );
	}

	/** B-11: after a backlog the retries keep their distance from the last attempt. */
	public function test_b11_retries_after_a_backlog_are_spaced_from_the_last_attempt(): void {
		$start = $this->ts( '2026-11-01 10:00:00' );
		$now   = $this->ts( '2026-11-10 09:00:00' ); // Nine days late: every scheduled retry day is in the past.

		$first = RenewalProcessor::next_retry_at( 1, $start, $now );
		$this->assertSame( '2026-11-11 09:00:00', $this->utc( $first ) );
		$second = RenewalProcessor::next_retry_at( 2, $start, $first );
		$this->assertSame( '2026-11-13 09:00:00', $this->utc( $second ) );
		$third = RenewalProcessor::next_retry_at( 3, $start, $second );
		$this->assertSame( '2026-11-17 09:00:00', $this->utc( $third ) );
	}

	/** B-185: paying inside the grace period continues from the old due date. */
	public function test_b185_payment_within_grace_continues_from_the_due_date(): void {
		$due = '2026-12-01 12:00:00';

		$this->assertSame( '2027-12-01 12:00:00', ManualRenewal::next_paid_until( $due, 'year', $this->ts( '2026-12-14 08:00:00' ) ) );
		$this->assertSame( '2027-12-01 12:00:00', ManualRenewal::next_paid_until( $due, 'year', $this->ts( '2026-12-15 12:00:00' ) ) );
		// After the grace period the gap is not billed.
		$this->assertSame( '2027-12-15 12:00:01', ManualRenewal::next_paid_until( $due, 'year', $this->ts( '2026-12-15 12:00:01' ) ) );
	}

	/** A-115: the billing day returns to the anchor day after a short month. */
	public function test_a115_billing_day_returns_to_the_anchor_after_a_short_month(): void {
		$feb = RenewalScheduler::period_end( $this->ts( '2027-01-31 08:00:00' ), 'month', 31 );
		$this->assertSame( '2027-02-28 08:00:00', $this->utc( $feb ) );
		$mar = RenewalScheduler::period_end( $feb, 'month', 31 );
		$this->assertSame( '2027-03-31 08:00:00', $this->utc( $mar ) );
		$apr = RenewalScheduler::period_end( $mar, 'month', 31 );
		$this->assertSame( '2027-04-30 08:00:00', $this->utc( $apr ) );

		// Yearly license bought on 29 February.
		$this->assertSame( '2029-02-28 08:00:00', $this->utc( RenewalScheduler::period_end( $this->ts( '2028-02-29 08:00:00' ), 'year', 29 ) ) );
		$this->assertSame( '2032-02-29 08:00:00', $this->utc( RenewalScheduler::period_end( $this->ts( '2031-02-28 08:00:00' ), 'year', 29 ) ) );
	}

	/** A-115: a date that is not a clamped month end is never moved by the anchor. */
	public function test_a115_anchor_does_not_touch_other_dates(): void {
		$this->assertSame( '2027-04-20 08:00:00', $this->utc( RenewalScheduler::period_end( $this->ts( '2027-03-20 08:00:00' ), 'month', 31 ) ) );
		$this->assertSame( '2027-04-15 08:00:00', $this->utc( RenewalScheduler::period_end( $this->ts( '2027-03-15 08:00:00' ), 'month', 10 ) ) );
		$this->assertSame( 31, RenewalScheduler::anchor_day( array( 'starts_at' => '2026-12-31 08:00:00' ) ) );
		$this->assertSame( 0, RenewalScheduler::anchor_day( array() ) );
	}

	/** B-36, B-47, A-51: new transitions of the state machine. */
	public function test_new_lifecycle_transitions(): void {
		$this->assertTrue( LicenseStateMachine::allows( 'active', 'suspended', 'order_cancelled' ) );
		$this->assertFalse( LicenseStateMachine::allows( 'expired', 'suspended', 'order_cancelled' ) );
		$this->assertTrue( LicenseStateMachine::allows( 'revoked', 'active', 'admin_restore' ) );
		$this->assertFalse( LicenseStateMachine::allows( 'revoked', 'active', 'admin_status_change' ) );
		$this->assertTrue( LicenseStateMachine::allows( 'suspended', 'expired', 'admin_status_change' ) );
	}
}
