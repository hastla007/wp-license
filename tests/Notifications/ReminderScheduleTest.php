<?php
/**
 * Reminder due-date selection (all dates UTC).
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\Notifications\ReminderSchedule;

final class ReminderScheduleTest extends TestCase {

	/**
	 * 2027-03-10 08:00:00 UTC.
	 */
	private const NOW = 1804665600;

	private function license( array $overrides = array() ): array {
		return array_merge(
			array(
				'id' => 7,
				'status' => 'active',
				'paid_until' => '2027-04-09 12:00:00',
				'next_payment_at' => null,
				'renewal_enabled' => 0,
				'cancel_at_period_end' => 0,
				'billing_interval' => 'year',
			),
			$overrides
		);
	}

	private function due( array $overrides, int $now = self::NOW, array $leads = array( 30, 7 ), int $notice = 7 ): ?array {
		return ReminderSchedule::due_reminder( $this->license( $overrides ), $now, $leads, $notice );
	}

	public function test_fixture_timestamp_is_the_documented_date(): void {
		$this->assertSame( '2027-03-10 08:00:00', gmdate( 'Y-m-d H:i:s', self::NOW ) );
	}

	public function test_parse_lead_days_normalizes_comma_lists(): void {
		$this->assertSame( array( 30, 7 ), ReminderSchedule::parse_lead_days( '7, 30,7' ) );
		$this->assertSame( array( 60, 14, 1 ), ReminderSchedule::parse_lead_days( array( 14, '60', 1 ) ) );
		$this->assertSame( array(), ReminderSchedule::parse_lead_days( '0, -3, abc, 366, 1.5,' ) );
		$this->assertSame( array(), ReminderSchedule::parse_lead_days( null ) );
		$this->assertSame( array( 5 ), ReminderSchedule::parse_lead_days( array( array( 9 ), 5 ) ) );
	}

	public function test_days_until_counts_utc_calendar_days(): void {
		$this->assertSame( 30, ReminderSchedule::days_until( strtotime( '2027-04-09 12:00:00 UTC' ), self::NOW ) );
		$this->assertSame( 0, ReminderSchedule::days_until( strtotime( '2027-03-10 23:59:59 UTC' ), self::NOW ) );
		$this->assertSame( 1, ReminderSchedule::days_until( strtotime( '2027-03-11 00:00:00 UTC' ), self::NOW ) );
		$this->assertSame( -1, ReminderSchedule::days_until( strtotime( '2027-03-09 23:00:00 UTC' ), self::NOW ) );
	}

	public function test_no_reminder_before_the_first_lead_day(): void {
		$this->assertNull( $this->due( array( 'paid_until' => '2027-04-10 00:00:00' ) ) );
	}

	public function test_thirty_day_reminder_on_the_lead_day_regardless_of_the_hour(): void {
		foreach ( array( '2027-04-09 00:00:00', '2027-04-09 12:00:00', '2027-04-09 23:59:59' ) as $paid_until ) {
			$due = $this->due( array( 'paid_until' => $paid_until ) );
			$this->assertNotNull( $due );
			$this->assertSame( ReminderSchedule::TYPE_EXPIRY, $due['type'] );
			$this->assertSame( 30, $due['lead'] );
			$this->assertSame( 'expiry_30', $due['marker'] );
			$this->assertSame( $paid_until, $due['period_end'] );
			$this->assertSame( 30, $due['days_left'] );
		}
	}

	public function test_missed_cron_days_still_send_the_thirty_day_reminder(): void {
		$due = $this->due( array( 'paid_until' => '2027-03-30 09:00:00' ) );
		$this->assertSame( 'expiry_30', $due['marker'] );
		$this->assertSame( 20, $due['days_left'] );

		$due = $this->due( array( 'paid_until' => '2027-03-18 09:00:00' ) );
		$this->assertSame( 'expiry_30', $due['marker'] );
		$this->assertSame( 8, $due['days_left'] );
	}

	public function test_most_urgent_lead_wins_inside_the_last_week(): void {
		$due = $this->due( array( 'paid_until' => '2027-03-17 09:00:00' ) );
		$this->assertSame( 'expiry_7', $due['marker'] );
		$this->assertSame( 7, $due['lead'] );

		$due = $this->due( array( 'paid_until' => '2027-03-10 20:00:00' ) );
		$this->assertSame( 'expiry_7', $due['marker'] );
		$this->assertSame( 0, $due['days_left'] );
	}

	public function test_no_reminder_once_paid_until_has_passed(): void {
		$this->assertNull( $this->due( array( 'paid_until' => '2027-03-10 08:00:00' ) ) );
		$this->assertNull( $this->due( array( 'paid_until' => '2027-03-01 00:00:00' ) ) );
	}

	public function test_only_active_licenses_with_an_end_date_are_reminded(): void {
		foreach ( array( 'pending', 'past_due', 'suspended', 'expired', 'revoked', 'cancelled' ) as $status ) {
			$this->assertNull( $this->due( array( 'status' => $status ) ), $status );
		}
		$this->assertNull( $this->due( array( 'paid_until' => null ) ) );
		$this->assertNull( $this->due( array( 'paid_until' => 'not a date' ) ) );
	}

	public function test_scheduled_cancellation_gets_expiry_reminder_not_a_charge_notice(): void {
		$due = $this->due( array( 'renewal_enabled' => 1, 'cancel_at_period_end' => 1, 'paid_until' => '2027-03-15 00:00:00' ) );
		$this->assertSame( ReminderSchedule::TYPE_EXPIRY, $due['type'] );
		$this->assertSame( 'expiry_7', $due['marker'] );
	}

	public function test_auto_renewing_license_gets_one_notice_within_seven_days(): void {
		$auto = array( 'renewal_enabled' => 1 );

		$this->assertNull( $this->due( $auto + array( 'paid_until' => '2027-04-09 12:00:00' ) ), '30 days out: no expiry reminder for auto-renewal' );
		$this->assertNull( $this->due( $auto + array( 'paid_until' => '2027-03-18 00:00:00' ) ), '8 days out' );

		$due = $this->due( $auto + array( 'paid_until' => '2027-03-17 23:00:00' ) );
		$this->assertSame( ReminderSchedule::TYPE_RENEWAL_NOTICE, $due['type'] );
		$this->assertSame( 'renewal_notice', $due['marker'] );
		$this->assertSame( 7, $due['days_left'] );
		$this->assertSame( '2027-03-17 23:00:00', $due['period_end'] );
		$this->assertSame( strtotime( '2027-03-17 23:00:00 UTC' ), $due['due_at'] );

		$due = $this->due( $auto + array( 'paid_until' => '2027-03-12 01:00:00' ) );
		$this->assertSame( 'renewal_notice', $due['marker'] );
		$this->assertSame( 2, $due['days_left'] );
	}

	public function test_renewal_notice_uses_next_payment_at_as_the_charge_date(): void {
		$license = array( 'renewal_enabled' => 1, 'paid_until' => '2027-03-25 00:00:00', 'next_payment_at' => '2027-03-16 06:00:00' );
		$due     = $this->due( $license );
		$this->assertSame( 'renewal_notice', $due['marker'] );
		$this->assertSame( 6, $due['days_left'] );
		$this->assertSame( '2027-03-25 00:00:00', $due['period_end'] );

		$license['next_payment_at'] = '2027-03-09 06:00:00';
		$this->assertNull( $this->due( $license ), 'charge date already passed' );
	}

	public function test_renewal_notice_can_be_disabled(): void {
		$this->assertNull( $this->due( array( 'renewal_enabled' => 1, 'paid_until' => '2027-03-12 00:00:00' ), self::NOW, array( 30, 7 ), 0 ) );
	}

	public function test_monthly_license_skips_leads_longer_than_half_the_term(): void {
		$monthly = array( 'billing_interval' => 'month' );

		$this->assertNull( $this->due( $monthly + array( 'paid_until' => '2027-04-09 12:00:00' ) ), 'no 30-day reminder right after purchase' );
		$this->assertNull( $this->due( $monthly + array( 'paid_until' => '2027-03-20 12:00:00' ) ) );

		$due = $this->due( $monthly + array( 'paid_until' => '2027-03-16 12:00:00' ) );
		$this->assertSame( 'expiry_7', $due['marker'] );

		$due = $this->due( $monthly + array( 'renewal_enabled' => 1, 'paid_until' => '2027-03-16 12:00:00' ) );
		$this->assertSame( 'renewal_notice', $due['marker'] );
	}

	// B-41: a notice lead time longer than half the term is shortened, never dropped.
	public function test_B41_renewal_notice_of_a_short_term_is_shortened_not_suppressed(): void {
		$monthly = array( 'billing_interval' => 'month', 'renewal_enabled' => 1 );

		$this->assertNull( $this->due( $monthly + array( 'paid_until' => '2027-03-28 12:00:00' ), self::NOW, array( 30, 7 ), 30 ), '18 days out, lead shortened to 15' );
		$due = $this->due( $monthly + array( 'paid_until' => '2027-03-24 12:00:00' ), self::NOW, array( 30, 7 ), 30 );
		$this->assertIsArray( $due, 'Formerly suppressed: 30 days of notice on a monthly term.' );
		$this->assertSame( 'renewal_notice', $due['marker'] );
		$this->assertSame( 15, $due['lead'] );
	}

	public function test_custom_and_empty_lead_days(): void {
		$due = $this->due( array( 'paid_until' => '2027-03-24 00:00:00' ), self::NOW, array( 3, 14, 60 ), 7 );
		$this->assertSame( 'expiry_14', $due['marker'] );

		$due = $this->due( array( 'paid_until' => '2027-05-01 00:00:00' ), self::NOW, array( 3, 14, 60 ), 7 );
		$this->assertSame( 'expiry_60', $due['marker'] );

		$this->assertNull( $this->due( array( 'paid_until' => '2027-03-12 00:00:00' ), self::NOW, array(), 7 ) );
	}

	public function test_max_window_days(): void {
		$this->assertSame( 30, ReminderSchedule::max_window_days( array( 7, 30 ), 7 ) );
		$this->assertSame( 10, ReminderSchedule::max_window_days( array(), 10 ) );
		$this->assertSame( 0, ReminderSchedule::max_window_days( array(), 0 ) );
	}
}
