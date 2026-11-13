<?php
use PHPUnit\Framework\TestCase;
use WPLC\Billing\RenewalScheduler;

final class RenewalPeriodTest extends TestCase {
	/** Exercise the calendar calculation without WordPress or database state. */
	private function period_end( string $start, string $interval ): string {
		return gmdate( 'Y-m-d H:i:s', RenewalScheduler::period_end( strtotime( $start . ' UTC' ), $interval ) );
	}

	public function test_monthly_period_clamps_january_31_to_february_end(): void {
		$this->assertSame( '2025-02-28 10:15:00', $this->period_end( '2025-01-31 10:15:00', 'month' ) );
	}

	public function test_monthly_period_preserves_leap_day_when_available(): void {
		$this->assertSame( '2024-02-29 10:15:00', $this->period_end( '2024-01-31 10:15:00', 'month' ) );
	}

	public function test_yearly_period_clamps_leap_day_in_non_leap_year(): void {
		$this->assertSame( '2025-02-28 10:15:00', $this->period_end( '2024-02-29 10:15:00', 'year' ) );
	}

	public function test_regular_dates_keep_day_and_time(): void {
		$this->assertSame( '2026-12-13 23:59:59', $this->period_end( '2026-11-13 23:59:59', 'month' ) );
		$this->assertSame( '2027-11-13 00:00:00', $this->period_end( '2026-11-13 00:00:00', 'year' ) );
		$this->assertSame( '2027-01-31 08:00:00', $this->period_end( '2026-12-31 08:00:00', 'month' ) );
	}

	public function test_renewal_key_is_stable_per_license_and_period(): void {
		$key = RenewalScheduler::idempotency_key( 7, '2026-12-01 00:00:00' );

		$this->assertSame( hash( 'sha256', 'wplc:7:2026-12-01 00:00:00' ), $key );
		$this->assertSame( $key, RenewalScheduler::idempotency_key( 7, '2026-12-01 00:00:00' ) );
		$this->assertNotSame( $key, RenewalScheduler::idempotency_key( 8, '2026-12-01 00:00:00' ) );
		$this->assertNotSame( $key, RenewalScheduler::idempotency_key( 7, '2027-12-01 00:00:00' ) );
	}

	public function test_attempt_key_changes_only_with_the_recorded_attempt_count(): void {
		$renewal_key = RenewalScheduler::idempotency_key( 7, '2026-12-01 00:00:00' );

		// Re-running the same attempt (count unchanged) must reuse the key.
		$this->assertSame(
			RenewalScheduler::attempt_idempotency_key( $renewal_key, 0 ),
			RenewalScheduler::attempt_idempotency_key( $renewal_key, 0 )
		);
		$this->assertSame( $renewal_key . ':0', RenewalScheduler::attempt_idempotency_key( $renewal_key, 0 ) );
		// A recorded definite failure advances the count and therefore the key.
		$this->assertSame( $renewal_key . ':1', RenewalScheduler::attempt_idempotency_key( $renewal_key, 1 ) );
		$this->assertNotSame(
			RenewalScheduler::attempt_idempotency_key( $renewal_key, 1 ),
			RenewalScheduler::attempt_idempotency_key( $renewal_key, 2 )
		);
		$this->assertSame( $renewal_key . ':0', RenewalScheduler::attempt_idempotency_key( $renewal_key, -3 ) );
		// Stripe accepts idempotency keys of up to 255 characters.
		$this->assertLessThanOrEqual( 255, strlen( RenewalScheduler::attempt_idempotency_key( $renewal_key, 65535 ) ) );
	}
}
