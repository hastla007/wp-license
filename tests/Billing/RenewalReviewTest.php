<?php
/**
 * Pure rules of the renewal review (T-154, T-137).
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\Billing\RenewalReview;
use WPLC\License\LicenseLifecycle;

final class RenewalReviewTest extends TestCase {

	private function ts( string $datetime ): int {
		return strtotime( $datetime . ' UTC' );
	}

	public function test_kind_follows_the_order(): void {
		$this->assertSame( RenewalReview::KIND_NO_ORDER, RenewalReview::kind( false, false, false ) );
		$this->assertSame( RenewalReview::KIND_RESULT_UNKNOWN, RenewalReview::kind( true, false, false ) );
		$this->assertSame( RenewalReview::KIND_PAID_NOT_BOOKED, RenewalReview::kind( true, false, true ) );
		$this->assertSame( RenewalReview::KIND_MANUAL_PAYMENT, RenewalReview::kind( true, true, true ) );
		// A manual order that is not paid has nothing to book.
		$this->assertSame( RenewalReview::KIND_NO_ORDER, RenewalReview::kind( true, true, false ) );
	}

	public function test_not_paid_is_only_offered_for_an_unknown_result(): void {
		$this->assertSame( array( 'book', 'not_paid', 'close' ), RenewalReview::actions( RenewalReview::KIND_RESULT_UNKNOWN ) );
		$this->assertSame( array( 'book', 'close' ), RenewalReview::actions( RenewalReview::KIND_PAID_NOT_BOOKED ) );
		$this->assertSame( array( 'book', 'close' ), RenewalReview::actions( RenewalReview::KIND_MANUAL_PAYMENT ) );
		$this->assertSame( array( 'close' ), RenewalReview::actions( RenewalReview::KIND_NO_ORDER ) );
		$this->assertSame( array( 'close' ), RenewalReview::actions( 'something_else' ) );
	}

	public function test_booking_extends_active_past_due_and_expired_licenses_only(): void {
		foreach ( array( 'active', 'past_due', 'expired' ) as $status ) {
			$this->assertTrue( RenewalReview::license_allows_booking( $status ), $status );
		}
		foreach ( array( 'suspended', 'revoked', 'pending', '' ) as $status ) {
			$this->assertFalse( RenewalReview::license_allows_booking( $status ), $status );
		}
	}

	public function test_unknown_result_is_booked_as_if_the_charge_had_succeeded(): void {
		$renewal = array( 'period_start' => '2026-11-10 12:00:00', 'period_end' => '2027-11-10 12:00:00' );
		$license = array( 'paid_until' => '2026-11-10 12:00:00', 'billing_interval' => 'year' );

		// Four days into the grace period: no gap, the paid period ends where the charge would have ended it.
		$this->assertSame( '2027-11-10 12:00:00', RenewalReview::booking_paid_until( RenewalReview::KIND_RESULT_UNKNOWN, $renewal, $license, $this->ts( '2026-11-14 09:00:00' ) ) );
		$this->assertSame( '2027-11-10 12:00:00', RenewalReview::booking_paid_until( RenewalReview::KIND_PAID_NOT_BOOKED, $renewal, $license, $this->ts( '2026-11-14 09:00:00' ) ) );
	}

	public function test_late_manual_payment_is_booked_from_now(): void {
		$renewal = array( 'period_start' => '2026-09-01 12:00:00', 'period_end' => '2027-09-01 12:00:00' );
		$license = array( 'paid_until' => '2026-09-01 12:00:00', 'billing_interval' => 'year' );

		$this->assertSame( '2027-11-14 09:00:00', RenewalReview::booking_paid_until( RenewalReview::KIND_MANUAL_PAYMENT, $renewal, $license, $this->ts( '2026-11-14 09:00:00' ) ) );
	}

	public function test_early_manual_payment_loses_no_days(): void {
		// A price mismatch inside the window: paid_until is still ahead, the period starts there.
		$renewal = array( 'period_start' => '2026-12-01 00:00:00', 'period_end' => '2027-12-01 00:00:00' );
		$license = array( 'paid_until' => '2026-12-01 00:00:00', 'billing_interval' => 'month' );

		$this->assertSame( '2027-01-01 00:00:00', RenewalReview::booking_paid_until( RenewalReview::KIND_MANUAL_PAYMENT, $renewal, $license, $this->ts( '2026-11-14 09:00:00' ) ) );
	}

	public function test_changed_period_is_booked_from_the_current_paid_until(): void {
		// An administrator moved the term after the charge: the old period no longer applies.
		$renewal = array( 'period_start' => '2026-11-10 12:00:00', 'period_end' => '2027-11-10 12:00:00' );
		$license = array( 'paid_until' => '2027-01-31 23:59:59', 'billing_interval' => 'year' );

		$this->assertSame( '2028-01-31 23:59:59', RenewalReview::booking_paid_until( RenewalReview::KIND_RESULT_UNKNOWN, $renewal, $license, $this->ts( '2026-11-14 09:00:00' ) ) );
	}

	public function test_period_that_already_ended_is_booked_from_now(): void {
		// A monthly charge reviewed after more than a month: never book a term that is already over.
		$renewal = array( 'period_start' => '2026-09-10 12:00:00', 'period_end' => '2026-10-10 12:00:00' );
		$license = array( 'paid_until' => '2026-09-10 12:00:00', 'billing_interval' => 'month' );

		$this->assertSame( '2026-12-14 09:00:00', RenewalReview::booking_paid_until( RenewalReview::KIND_RESULT_UNKNOWN, $renewal, $license, $this->ts( '2026-11-14 09:00:00' ) ) );
	}

	public function test_expiry_deferral_is_capped(): void {
		$this->assertSame( 30, LicenseLifecycle::REVIEW_EXPIRY_DEFERRAL_DAYS );
		$this->assertSame( '2026-10-15 09:00:00', LicenseLifecycle::review_deferral_cutoff( $this->ts( '2026-11-14 09:00:00' ) ) );
	}
}
