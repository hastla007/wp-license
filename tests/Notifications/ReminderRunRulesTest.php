<?php
/**
 * Pure rules of the reminder run: calendar days in the site timezone, cursor, time budget, retries.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\Notifications\Notifications;
use WPLC\Notifications\PurchaseLocale;
use WPLC\Notifications\ReminderSchedule;

require_once dirname( __DIR__ ) . '/Admin/wp-function-stubs.php';

final class ReminderRunRulesTest extends TestCase {

	private function ts( string $utc ): int {
		return (int) strtotime( $utc . ' UTC' );
	}

	private function license( array $overrides = array() ): array {
		return array_merge(
			array(
				'id' => 7,
				'status' => 'active',
				'paid_until' => '2027-04-09 22:30:00',
				'next_payment_at' => null,
				'renewal_enabled' => 0,
				'cancel_at_period_end' => 0,
				'billing_interval' => 'year',
			),
			$overrides
		);
	}

	// B-121: "in N days" is counted in calendar days of the site timezone, like the date printed next to it.
	public function test_days_are_counted_in_the_given_timezone(): void {
		$berlin = new DateTimeZone( 'Europe/Berlin' );
		// 2027-04-09 22:30 UTC is 2027-04-10 00:30 in Berlin (CEST).
		$target = $this->ts( '2027-04-09 22:30:00' );
		// 2027-04-09 21:00 UTC is 23:00 on the 9th in Berlin: the license ends "tomorrow" there, "today" in UTC.
		$now = $this->ts( '2027-04-09 21:00:00' );

		$this->assertSame( 0, ReminderSchedule::days_until( $target, $now ) );
		$this->assertSame( 1, ReminderSchedule::days_until( $target, $now, $berlin ) );
		// One hour later it is the 10th in Berlin as well: the end is today.
		$this->assertSame( 0, ReminderSchedule::days_until( $target, $this->ts( '2027-04-09 22:10:00' ), $berlin ) );
		$this->assertSame( 0, ReminderSchedule::days_until( $target, $this->ts( '2027-04-09 22:10:00' ), new DateTimeZone( 'UTC' ) ) );
	}

	// B-121: a daylight-saving change between now and the target does not shift the day count.
	public function test_day_count_is_not_shifted_by_daylight_saving(): void {
		$berlin = new DateTimeZone( 'Europe/Berlin' );
		// Clocks go forward on 2027-03-28. From noon on the 27th to 00:30 on the 29th (local) are two calendar days.
		$this->assertSame( 2, ReminderSchedule::days_until( $this->ts( '2027-03-28 22:30:00' ), $this->ts( '2027-03-27 11:00:00' ), $berlin ) );
		$this->assertSame( -2, ReminderSchedule::days_until( $this->ts( '2027-03-27 11:00:00' ), $this->ts( '2027-03-28 22:30:00' ), $berlin ) );
	}

	// B-121: on the last day the reminder reports zero days ("today"), not "in 1 day".
	public function test_reminder_on_the_last_day_reports_zero_days_left(): void {
		$berlin = new DateTimeZone( 'Europe/Berlin' );
		$due    = ReminderSchedule::due_reminder( $this->license(), $this->ts( '2027-04-09 22:10:00' ), array( 30, 7 ), 7, $berlin );

		$this->assertIsArray( $due );
		$this->assertSame( 'expiry_7', $due['marker'] );
		$this->assertSame( 0, $due['days_left'] );
	}

	public function test_lead_day_is_reached_by_local_calendar_days(): void {
		$berlin = new DateTimeZone( 'Europe/Berlin' );
		// Local end date: 10 April. Local "now": 2 April 23:30 -> 8 days; 3 April 00:30 -> 7 days.
		$this->assertNull( ReminderSchedule::due_reminder( $this->license(), $this->ts( '2027-04-02 21:30:00' ), array( 7 ), 7, $berlin ) );
		$due = ReminderSchedule::due_reminder( $this->license(), $this->ts( '2027-04-02 22:30:00' ), array( 7 ), 7, $berlin );
		$this->assertSame( 7, $due['days_left'] );
	}

	// B-125: a cursor left behind by an unfinished run of an earlier day is discarded.
	public function test_stale_cursor_is_discarded(): void {
		$this->assertSame( 0, Notifications::read_cursor( 0, '2027-04-09' ) );
		$this->assertSame( 0, Notifications::read_cursor( 812, '2027-04-09' ), 'A bare license ID from an older plugin version has no date and is dropped.' );
		$this->assertSame( 0, Notifications::read_cursor( array( 'day' => '2027-04-08', 'id' => 812 ), '2027-04-09' ) );
		$this->assertSame( 812, Notifications::read_cursor( array( 'day' => '2027-04-09', 'id' => 812 ), '2027-04-09' ) );
		$this->assertSame( 0, Notifications::read_cursor( array( 'day' => '2027-04-09', 'id' => -5 ), '2027-04-09' ) );
		$this->assertSame( 0, Notifications::read_cursor( array( 'day' => '2027-04-09' ), '2027-04-09' ) );
	}

	// B-125: the budget is a per-email check, so it must already trip one second after the limit.
	public function test_time_budget(): void {
		$this->assertFalse( Notifications::budget_used( 1000, 1000 ) );
		$this->assertFalse( Notifications::budget_used( 1000, 1019 ) );
		$this->assertTrue( Notifications::budget_used( 1000, 1020 ) );
		$this->assertTrue( Notifications::budget_used( 1000, 5000 ) );
	}

	// A-78: a retried email is only sent while it still describes the license.
	public function test_retry_only_while_the_email_still_applies(): void {
		$period = '2027-04-09 22:30:00';
		$this->assertTrue( Notifications::still_applies( 'issued', $this->license(), $period ) );
		$this->assertFalse( Notifications::still_applies( 'issued', $this->license( array( 'status' => 'revoked' ) ), $period ) );
		$this->assertTrue( Notifications::still_applies( 'payment_failed', $this->license( array( 'status' => 'past_due' ) ), $period ) );
		$this->assertFalse( Notifications::still_applies( 'payment_failed', $this->license(), $period ), 'Paid in the meantime: no "payment failed" email.' );
		$this->assertTrue( Notifications::still_applies( 'expired', $this->license( array( 'status' => 'expired' ) ), $period ) );
		$this->assertFalse( Notifications::still_applies( 'expired', $this->license(), $period ) );
		$this->assertTrue( Notifications::still_applies( 'renewed', $this->license(), $period ) );
		$this->assertFalse( Notifications::still_applies( 'renewed', $this->license(), '2026-04-09 22:30:00' ) );
		$this->assertTrue( Notifications::still_applies( 'suspended', $this->license( array( 'status' => 'suspended' ) ), $period ) );
		$this->assertFalse( Notifications::still_applies( 'suspended', $this->license(), $period ) );
		$this->assertTrue( Notifications::still_applies( 'cancelled', $this->license( array( 'cancel_at_period_end' => 1 ) ), '' ) );
		$this->assertFalse( Notifications::still_applies( 'cancelled', $this->license(), '' ), 'Cancellation withdrawn in the meantime.' );
		$this->assertFalse( Notifications::still_applies( 'unknown', $this->license(), $period ) );
	}

	// B-122: only well-formed locales are stored and used.
	public function test_locale_values_are_validated(): void {
		$this->assertSame( 'de_DE', PurchaseLocale::sanitize( 'de_DE' ) );
		$this->assertSame( 'de_DE_formal', PurchaseLocale::sanitize( ' de_DE_formal ' ) );
		$this->assertSame( 'fr', PurchaseLocale::sanitize( 'fr' ) );
		$this->assertSame( '', PurchaseLocale::sanitize( '../../etc' ) );
		$this->assertSame( '', PurchaseLocale::sanitize( 'de-DE' ) );
		$this->assertSame( '', PurchaseLocale::sanitize( '' ) );
	}
}
