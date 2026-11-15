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
				'anonymize_licenses' => 0,
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
				'consents' => '2017-03-12 08:00:00',
			),
			Retention::cutoffs( Retention::DEFAULT_DAYS, self::NOW ),
			'The anonymisation of ended licenses is off unless a period is configured.'
		);
	}

	public function test_anonymisation_runs_only_with_a_configured_period(): void {
		$this->assertArrayNotHasKey( 'anonymize_licenses', Retention::cutoffs( array(), self::NOW ) );
		$this->assertSame(
			gmdate( 'Y-m-d H:i:s', self::NOW - 1095 * 86400 ),
			Retention::cutoffs( array( 'anonymize_licenses' => 1095 ), self::NOW )['anonymize_licenses']
		);
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
			array( 'audit_log', 'event_inbox', 'event_inbox_open', 'activations', 'inactive_activations', 'ended_license_activations', 'reminder_log', 'consents' ),
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
		$this->assertSame( 0, $days['anonymize_licenses'], 'Anonymisation stays off unless the operator sets a period.' );
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
		$this->assertSame( 0, Retention::configured_days()['anonymize_licenses'] );
	}
}
