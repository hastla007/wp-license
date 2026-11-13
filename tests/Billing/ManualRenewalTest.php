<?php
use PHPUnit\Framework\TestCase;
use WPLC\Billing\ManualRenewal;

final class ManualRenewalTest extends TestCase {
	private const PAID_UNTIL = '2026-12-01 12:00:00';

	private function license( string $status, ?string $paid_until = self::PAID_UNTIL ): array {
		return array( 'id' => 5, 'status' => $status, 'paid_until' => $paid_until, 'billing_interval' => 'year' );
	}

	private function ts( string $datetime ): int {
		return strtotime( $datetime . ' UTC' );
	}

	public function test_window_opens_30_days_before_paid_until(): void {
		$license = $this->license( 'active' );

		$this->assertFalse( ManualRenewal::in_window( $license, $this->ts( '2026-11-01 11:59:59' ) ) );
		$this->assertTrue( ManualRenewal::in_window( $license, $this->ts( '2026-11-01 12:00:00' ) ) );
		$this->assertTrue( ManualRenewal::in_window( $license, $this->ts( '2026-11-30 00:00:00' ) ) );
	}

	public function test_window_closes_30_days_after_paid_until(): void {
		$license = $this->license( 'expired' );

		$this->assertTrue( ManualRenewal::in_window( $license, $this->ts( '2026-12-02 00:00:00' ) ) );
		$this->assertTrue( ManualRenewal::in_window( $license, $this->ts( '2026-12-31 12:00:00' ) ) );
		$this->assertFalse( ManualRenewal::in_window( $license, $this->ts( '2026-12-31 12:00:01' ) ) );
	}

	public function test_only_active_past_due_and_expired_licenses_can_renew(): void {
		$now = $this->ts( '2026-11-20 00:00:00' );

		$this->assertTrue( ManualRenewal::in_window( $this->license( 'active' ), $now ) );
		$this->assertTrue( ManualRenewal::in_window( $this->license( 'past_due' ), $now ) );
		$this->assertTrue( ManualRenewal::in_window( $this->license( 'expired' ), $now ) );
		$this->assertFalse( ManualRenewal::in_window( $this->license( 'revoked' ), $now ) );
		$this->assertFalse( ManualRenewal::in_window( $this->license( 'suspended' ), $now ) );
		$this->assertFalse( ManualRenewal::in_window( $this->license( 'pending' ), $now ) );
	}

	public function test_license_without_valid_period_or_id_cannot_renew(): void {
		$now = $this->ts( '2026-11-20 00:00:00' );

		$this->assertFalse( ManualRenewal::in_window( $this->license( 'active', null ), $now ) );
		$this->assertFalse( ManualRenewal::in_window( $this->license( 'active', 'not a date' ), $now ) );
		$this->assertFalse( ManualRenewal::in_window( array( 'status' => 'active', 'paid_until' => self::PAID_UNTIL ), $now ) );
	}

	public function test_early_renewal_extends_from_paid_until_and_costs_no_days(): void {
		$this->assertSame(
			'2027-12-01 12:00:00',
			ManualRenewal::next_paid_until( self::PAID_UNTIL, 'year', $this->ts( '2026-11-05 09:00:00' ) )
		);
		$this->assertSame(
			'2027-01-01 12:00:00',
			ManualRenewal::next_paid_until( self::PAID_UNTIL, 'month', $this->ts( '2026-11-05 09:00:00' ) )
		);
	}

	public function test_late_renewal_starts_at_payment_time_and_does_not_bill_the_gap(): void {
		$this->assertSame(
			'2027-12-20 08:30:00',
			ManualRenewal::next_paid_until( self::PAID_UNTIL, 'year', $this->ts( '2026-12-20 08:30:00' ) )
		);
	}

	public function test_late_month_end_renewal_is_clamped(): void {
		$this->assertSame(
			'2027-02-28 10:00:00',
			ManualRenewal::next_paid_until( '2027-01-10 00:00:00', 'month', $this->ts( '2027-01-31 10:00:00' ) )
		);
	}

	public function test_payment_exactly_at_paid_until_continues_seamlessly(): void {
		$this->assertSame(
			'2027-12-01 12:00:00',
			ManualRenewal::next_paid_until( self::PAID_UNTIL, 'year', $this->ts( self::PAID_UNTIL ) )
		);
	}
}
