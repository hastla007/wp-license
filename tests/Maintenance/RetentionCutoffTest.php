<?php
/**
 * Retention cutoff computation.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\Maintenance\Retention;
use WPLC\Privacy\PersonalData;

require_once dirname( __DIR__ ) . '/Admin/wp-function-stubs.php';

final class RetentionCutoffTest extends TestCase {

	/**
	 * 2027-03-10 08:00:00 UTC.
	 */
	private const NOW = 1804665600;

	// B-27, B-45: new rules for unresolved inbox rows, installations of licenses in force,
	// installations of ended licenses and the anonymisation (off by default).
	public function test_defaults(): void {
		$this->assertSame(
			array(
				'audit_log' => 730,
				'event_inbox' => 180,
				'event_inbox_open' => 730,
				'activations' => 365,
				'inactive_activations' => 365,
				'ended_license_activations' => 30,
				'reminder_log' => 730,
				// Owner decision of 16 Nov 2026: ten years from the end of the year, like the other plugins.
				'anonymize_licenses' => 3650,
				// Wave 2: consent evidence of ended licenses, ten years.
				'consents' => 3650,
			),
			Retention::DEFAULT_DAYS
		);
		$this->assertSame(
			array(
				'audit_log' => '2025-03-10 08:00:00',
				'event_inbox' => '2026-09-11 08:00:00',
				'event_inbox_open' => '2025-03-10 08:00:00',
				'activations' => '2026-03-10 08:00:00',
				'inactive_activations' => '2026-03-10 08:00:00',
				'ended_license_activations' => '2027-02-08 08:00:00',
				'reminder_log' => '2025-03-10 08:00:00',
				// Year-end rules: ten years back from 2027-03-10 is 2017-03-12, so only years up to 2016 qualify.
				'anonymize_licenses' => '2017-01-01 00:00:00',
				'consents' => '2017-01-01 00:00:00',
			),
			Retention::cutoffs( Retention::DEFAULT_DAYS, self::NOW )
		);
	}

	public function test_anonymisation_runs_from_the_end_of_the_year_and_can_be_switched_off(): void {
		$this->assertSame( '2017-01-01 00:00:00', Retention::cutoffs( array(), self::NOW )['anonymize_licenses'], 'On by default.' );
		$this->assertArrayNotHasKey( 'anonymize_licenses', Retention::cutoffs( array( 'anonymize_licenses' => 0 ), self::NOW ) );
		// 1095 days before 2027-03-10 is in 2024: licenses that ended in 2023 or earlier qualify.
		$this->assertSame( '2024-01-01 00:00:00', Retention::cutoffs( array( 'anonymize_licenses' => 1095 ), self::NOW )['anonymize_licenses'] );
		// A license that ended on 31 Dec 2016 is anonymised in 2027; one that ended on 1 Jan 2017 is not yet.
		$cutoff = Retention::cutoffs( array(), self::NOW )['anonymize_licenses'];
		$this->assertTrue( '2016-12-31 23:59:59' < $cutoff );
		$this->assertFalse( '2017-01-01 00:00:00' < $cutoff );
		// Day-based rules are unchanged.
		$this->assertSame( gmdate( 'Y-m-d H:i:s', self::NOW - 730 * 86400 ), Retention::cutoffs( array(), self::NOW )['audit_log'] );
	}

	public function test_zero_disables_a_rule_and_missing_keys_use_defaults(): void {
		$cutoffs = Retention::cutoffs( array( 'audit_log' => 0, 'event_inbox' => 30 ), self::NOW );

		$this->assertArrayNotHasKey( 'audit_log', $cutoffs );
		$this->assertSame( '2027-02-08 08:00:00', $cutoffs['event_inbox'] );
		$this->assertSame( '2026-03-10 08:00:00', $cutoffs['activations'] );
		$this->assertSame( '2025-03-10 08:00:00', $cutoffs['reminder_log'] );
	}

	public function test_invalid_values_disable_instead_of_deleting_everything(): void {
		$cutoffs = Retention::cutoffs(
			array(
				'audit_log' => -5,
				'event_inbox' => 'soon',
				'event_inbox_open' => array( 1 ),
				'activations' => null,
				'inactive_activations' => true,
				'ended_license_activations' => '-1',
				'reminder_log' => 1.5,
				'anonymize_licenses' => 'yes',
				'consents' => 'never',
				'licenses' => 1,
			),
			self::NOW
		);

		$this->assertSame( array(), $cutoffs );
	}

	public function test_numeric_strings_are_accepted_and_huge_values_capped(): void {
		$cutoffs = Retention::cutoffs( array( 'audit_log' => '90', 'event_inbox' => 999999 ), self::NOW );

		$this->assertSame( '2026-12-10 08:00:00', $cutoffs['audit_log'] );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', self::NOW - 36500 * 86400 ), $cutoffs['event_inbox'] );
	}

	public function test_non_array_filter_result_falls_back_to_defaults(): void {
		$this->assertSame( Retention::cutoffs( Retention::DEFAULT_DAYS, self::NOW ), Retention::cutoffs( false, self::NOW ) );
	}

	public function test_unknown_rules_are_ignored(): void {
		$this->assertSame(
			array( 'audit_log', 'event_inbox', 'event_inbox_open', 'activations', 'inactive_activations', 'ended_license_activations', 'reminder_log', 'anonymize_licenses', 'consents' ),
			array_keys( Retention::cutoffs( array( 'orders' => 1 ), self::NOW ) )
		);
	}

	// B-44: page arithmetic of the exporter phases.
	public function test_export_page_count(): void {
		$this->assertSame( 0, PersonalData::pages( 0, 100 ) );
		$this->assertSame( 1, PersonalData::pages( 1, 100 ) );
		$this->assertSame( 1, PersonalData::pages( 100, 100 ) );
		$this->assertSame( 2, PersonalData::pages( 101, 100 ) );
		$this->assertSame( 0, PersonalData::pages( -5, 100 ) );
	}

	// P-51: yesterday's API error counters (bucket yesterday 00:00 UTC) survive the hourly sweep all day.
	public function test_rate_limit_sweep_keeps_yesterdays_daily_counter_until_the_end_of_today(): void {
		$end_of_today      = gmmktime( 23, 59, 59, 3, 10, 2027 );
		$yesterday_counter = '2027-03-09 00:00:00';

		$this->assertLessThan( $yesterday_counter, Retention::request_limit_cutoff( $end_of_today ) );
		$this->assertLessThan( $yesterday_counter, Retention::request_limit_cutoff( self::NOW ) );
		// The counter of the day before yesterday goes once today has started.
		$this->assertGreaterThan( '2027-03-08 00:00:00', Retention::request_limit_cutoff( gmmktime( 0, 0, 1, 3, 10, 2027 ) ) );
		$this->assertSame( 2, Retention::REQUEST_LIMIT_RETENTION_DAYS );
	}

	public function test_b45_effective_days_show_which_rules_are_off(): void {
		$days = Retention::effective_days( array( 'audit_log' => '90', 'event_inbox' => -1, 'reminder_log' => 'x', 'unknown' => 5 ) );

		$this->assertSame( array_keys( Retention::DEFAULT_DAYS ), array_keys( $days ) );
		$this->assertSame( 90, $days['audit_log'] );
		$this->assertSame( 0, $days['event_inbox'], 'Negative values switch the rule off.' );
		$this->assertSame( 0, $days['reminder_log'], 'Non-numeric values switch the rule off.' );
		$this->assertSame( 3650, $days['anonymize_licenses'], 'Anonymisation is on by default: ten years.' );
		$this->assertSame( Retention::DEFAULT_DAYS['consents'], $days['consents'] );
	}

	public function test_b45_configured_days_apply_the_filter(): void {
		$GLOBALS['wplc_test_filters']['wplc_retention_days'] = array(
			static function ( $days ) {
				$days['anonymize_licenses'] = 1095;
				return $days;
			},
		);
		try {
			$this->assertSame( 1095, Retention::configured_days()['anonymize_licenses'] );
		} finally {
			unset( $GLOBALS['wplc_test_filters']['wplc_retention_days'] );
		}
		$this->assertSame( 3650, Retention::configured_days()['anonymize_licenses'] );
	}
}
