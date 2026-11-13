<?php
/**
 * Status labels and site-timezone date conversion.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\Admin\Format;

require_once __DIR__ . '/wp-function-stubs.php';

final class FormatTest extends TestCase {

	public function test_every_license_status_has_a_label(): void {
		$labels = Format::license_status_labels();

		$this->assertSame(
			array( 'pending', 'active', 'past_due', 'suspended', 'revoked', 'expired' ),
			array_keys( $labels )
		);
		foreach ( $labels as $status => $label ) {
			$this->assertNotSame( '', $label );
			$this->assertStringNotContainsString( '_', $label, 'Raw status ' . $status . ' must not leak into the label.' );
		}
		$this->assertSame( 'Payment overdue', Format::license_status_label( 'past_due' ) );
	}

	public function test_unknown_status_is_made_readable_instead_of_shown_raw(): void {
		$this->assertSame( 'On hold', Format::license_status_label( 'on_hold' ) );
		$this->assertSame( '—', Format::license_status_label( '' ) );
		$this->assertSame( 'Customer action required', Format::renewal_status_label( 'manual_action_required' ) );
		$this->assertSame( 'Live', Format::environment_label( 'production' ) );
		$this->assertSame( 'Deactivated', Format::activation_status_label( 'deactivated' ) );
	}

	public function test_renewal_mode(): void {
		$this->assertSame( 'manual', Format::renewal_mode( array( 'renewal_enabled' => '0', 'cancel_at_period_end' => '0' ) ) );
		$this->assertSame( 'auto', Format::renewal_mode( array( 'renewal_enabled' => '1', 'cancel_at_period_end' => '0' ) ) );
		$this->assertSame( 'cancelling', Format::renewal_mode( array( 'renewal_enabled' => '1', 'cancel_at_period_end' => '1' ) ) );
		$this->assertSame( 'Cancels at period end', Format::renewal_mode_label( array( 'renewal_enabled' => 1, 'cancel_at_period_end' => 1 ) ) );
	}

	public function test_local_date_becomes_utc_end_of_day(): void {
		$berlin = new DateTimeZone( 'Europe/Berlin' );

		// Summer time: UTC+2.
		$this->assertSame( '2027-07-01 21:59:59', Format::local_date_to_utc_end_of_day( '2027-07-01', $berlin ) );
		// Winter time: UTC+1.
		$this->assertSame( '2027-12-31 22:59:59', Format::local_date_to_utc_end_of_day( '2027-12-31', $berlin ) );
		$this->assertSame( '2027-12-31 23:59:59', Format::local_date_to_utc_end_of_day( '2027-12-31', new DateTimeZone( 'UTC' ) ) );
		// West of UTC the end of the local day is on the next UTC day.
		$this->assertSame( '2028-01-01 04:59:59', Format::local_date_to_utc_end_of_day( '2027-12-31', new DateTimeZone( 'America/New_York' ) ) );
		// Fixed offsets as returned by wp_timezone() for "UTC+5:30".
		$this->assertSame( '2027-03-10 18:29:59', Format::local_date_to_utc_end_of_day( ' 2027-03-10 ', new DateTimeZone( '+05:30' ) ) );
	}

	/**
	 * @dataProvider invalid_dates
	 */
	public function test_invalid_local_dates_are_rejected( string $date ): void {
		$this->assertSame( '', Format::local_date_to_utc_end_of_day( $date, new DateTimeZone( 'Europe/Berlin' ) ) );
	}

	public function invalid_dates(): array {
		return array(
			'empty'            => array( '' ),
			'day overflow'     => array( '2027-02-30' ),
			'month overflow'   => array( '2027-13-01' ),
			'german format'    => array( '31.12.2027' ),
			'with time'        => array( '2027-12-31 10:00:00' ),
			'sql injection'    => array( "2027-12-31' OR 1=1" ),
		);
	}

	public function test_round_trip_keeps_the_calendar_day(): void {
		foreach ( array( 'Europe/Berlin', 'America/Los_Angeles', 'Pacific/Auckland', 'UTC' ) as $zone ) {
			$timezone = new DateTimeZone( $zone );
			$utc      = Format::local_date_to_utc_end_of_day( '2027-03-28', $timezone );
			$this->assertSame( '2027-03-28', Format::utc_to_local_date( $utc, $timezone ), $zone );
		}
	}

	public function test_utc_timestamp_parsing(): void {
		$this->assertSame( 1800000000, Format::utc_timestamp( gmdate( 'Y-m-d H:i:s', 1800000000 ) ) );
		$this->assertNull( Format::utc_timestamp( null ) );
		$this->assertNull( Format::utc_timestamp( '' ) );
		$this->assertNull( Format::utc_timestamp( '0000-00-00 00:00:00' ) );
		$this->assertNull( Format::utc_timestamp( 'tomorrow' ) );
		$this->assertSame( '', Format::utc_to_local_date( null, new DateTimeZone( 'UTC' ) ) );
	}
}
