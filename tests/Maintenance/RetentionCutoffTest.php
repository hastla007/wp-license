<?php
/**
 * Retention cutoff computation.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\Maintenance\Retention;
use WPLC\Privacy\PersonalData;

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
			array( 'audit_log', 'event_inbox', 'event_inbox_open', 'activations', 'inactive_activations', 'ended_license_activations', 'reminder_log' ),
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
}
