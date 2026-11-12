<?php
use PHPUnit\\Framework\\TestCase;
use WPLC\\Billing\\RenewalScheduler;

final class RenewalPeriodTest extends TestCase {
	/** Exercise the calendar calculation without WordPress or database state. */
	private function period_end( string $start, string $interval ): string {
		$method = new ReflectionMethod( RenewalScheduler::class, 'period_end' );
		$method->setAccessible( true );
		$timestamp = strtotime( $start . ' UTC' );
		return gmdate( 'Y-m-d H:i:s', $method->invoke( null, $timestamp, $interval ) );
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
}
