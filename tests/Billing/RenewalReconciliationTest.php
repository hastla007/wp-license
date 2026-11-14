<?php
use PHPUnit\Framework\TestCase;
use WPLC\Billing\RenewalProcessor;

/**
 * T-136: timing rules for renewals whose payment result is unknown.
 */
final class RenewalReconciliationTest extends TestCase {

	private const SINCE = 1790000000;

	public function test_window_stays_well_inside_the_24_hours_stripe_keeps_idempotency_keys(): void {
		$this->assertLessThanOrEqual( 12 * 3600, RenewalProcessor::RESULT_UNKNOWN_WINDOW );
		$this->assertGreaterThan( RenewalProcessor::RESULT_UNKNOWN_CHECK_INTERVAL, RenewalProcessor::RESULT_UNKNOWN_WINDOW );
	}

	public function test_first_check_is_one_interval_after_the_unknown_result(): void {
		$this->assertSame(
			self::SINCE + RenewalProcessor::RESULT_UNKNOWN_CHECK_INTERVAL,
			RenewalProcessor::next_reconciliation_check( self::SINCE, self::SINCE, self::SINCE + 14 * 86400 )
		);
	}

	public function test_last_check_is_capped_at_the_window_end(): void {
		$deadline = self::SINCE + RenewalProcessor::RESULT_UNKNOWN_WINDOW;
		$now      = $deadline - 600;

		$this->assertSame( $deadline, RenewalProcessor::next_reconciliation_check( $now, self::SINCE, 0 ) );
	}

	public function test_no_check_once_the_window_has_passed(): void {
		$deadline = self::SINCE + RenewalProcessor::RESULT_UNKNOWN_WINDOW;

		$this->assertSame( 0, RenewalProcessor::next_reconciliation_check( $deadline, self::SINCE, 0 ) );
		$this->assertSame( 0, RenewalProcessor::next_reconciliation_check( $deadline + 86400, self::SINCE, 0 ) );
	}

	public function test_grace_end_ends_the_wait_early(): void {
		$grace_end = self::SINCE + 1800;

		$this->assertSame( $grace_end, RenewalProcessor::next_reconciliation_check( self::SINCE, self::SINCE, $grace_end ) );
		$this->assertSame( 0, RenewalProcessor::next_reconciliation_check( $grace_end, self::SINCE, $grace_end ) );
	}

	public function test_unknown_start_time_never_allows_a_check(): void {
		$this->assertSame( 0, RenewalProcessor::next_reconciliation_check( self::SINCE, 0, 0 ) );
	}

	public function test_start_time_is_read_only_for_the_same_attempt(): void {
		$this->assertSame( self::SINCE, RenewalProcessor::unknown_since_from_meta( '2|' . self::SINCE, 2 ) );
		$this->assertSame( 0, RenewalProcessor::unknown_since_from_meta( '1|' . self::SINCE, 2 ), 'A start time of an earlier attempt must not shorten or extend the new window.' );
		$this->assertSame( 0, RenewalProcessor::unknown_since_from_meta( '', 0 ) );
		$this->assertSame( 0, RenewalProcessor::unknown_since_from_meta( 'garbage', 0 ) );
		$this->assertSame( 0, RenewalProcessor::unknown_since_from_meta( '0|-5', 0 ) );
	}
}
