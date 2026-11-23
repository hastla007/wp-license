<?php
/**
 * SET-LIC-02, -03, -16: grace period, retry schedule and manual renewal window as settings.
 *
 * @package WPLC
 */

use WPLC\Admin\Settings;
use WPLC\Billing\ManualRenewal;
use WPLC\Billing\RenewalProcessor;
use WPLC\License\LicenseLifecycle;
use WPLC\License\LicenseStateMachine;

final class RenewalPolicySettingsIntegrationTest extends WPLC_Renewal_TestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION );
		add_filter( 'sanitize_option_' . Settings::OPTION, array( Settings::class, 'sanitize' ) );
	}

	public function tear_down(): void {
		remove_all_filters( 'sanitize_option_' . Settings::OPTION );
		remove_all_filters( 'wplc_renewal_grace_days' );
		remove_all_filters( 'wplc_renewal_retry_days' );
		remove_all_filters( 'wplc_manual_renewal_window_days' );
		delete_option( Settings::OPTION );
		parent::tear_down();
	}

	/** Nothing changes for an installation that never saved a value. */
	public function test_the_defaults_are_the_behaviour_so_far(): void {
		$this->assertSame( 14, LicenseLifecycle::grace_days() );
		$this->assertSame( array( 1, 3, 7 ), RenewalProcessor::retry_days() );
		$this->assertSame( array( 'before' => 30, 'after' => 30 ), LicenseStateMachine::manual_renewal_window() );
	}

	/** SET-LIC-02: setting, then filter; the filter wins. */
	public function test_grace_days_follow_the_setting_and_the_filter_wins(): void {
		update_option( Settings::OPTION, array( 'renewal_grace_days' => '21' ) );
		$this->assertSame( 21, LicenseLifecycle::grace_days() );

		add_filter(
			'wplc_renewal_grace_days',
			static function ( $days ) {
				return (int) $days + 4;
			}
		);
		$this->assertSame( 25, LicenseLifecycle::grace_days(), 'The filter receives the setting and decides last.' );
	}

	/** SET-LIC-02: a renewal that is already scheduled keeps its grace end. */
	public function test_a_changed_grace_period_does_not_move_scheduled_renewals(): void {
		$f      = $this->renewing_license( 5 * DAY_IN_SECONDS );
		$before = $this->renewal( $f['renewal_id'] )['grace_ends_at'];

		update_option( Settings::OPTION, array( 'renewal_grace_days' => '60' ) );

		$this->assertSame( $before, $this->renewal( $f['renewal_id'] )['grace_ends_at'] );
	}

	/** SET-LIC-02: values outside 1 to 90 are refused, the stored value stays. */
	public function test_an_invalid_grace_period_is_refused(): void {
		update_option( Settings::OPTION, array( 'renewal_grace_days' => '21' ) );
		update_option( Settings::OPTION, array( 'renewal_grace_days' => '0' ) );
		$this->assertSame( 21, Settings::get( 'renewal_grace_days' ) );
		update_option( Settings::OPTION, array( 'renewal_grace_days' => '91' ) );
		$this->assertSame( 21, Settings::get( 'renewal_grace_days' ) );
	}

	/** SET-LIC-03: three retries by default. */
	public function test_default_schedule_retries_on_day_one(): void {
		$f                                 = $this->renewing_license();
		WPLC_Test_Recurring_Adapter::$mode = 'decline';

		RenewalProcessor::process( $f['renewal_id'] );

		$renewal = $this->renewal( $f['renewal_id'] );
		$this->assertSame( 'failed', $renewal['status'] );
		$start = strtotime( $renewal['period_start'] . ' UTC' );
		$this->assertEqualsWithDelta( max( $start + DAY_IN_SECONDS, time() + DAY_IN_SECONDS ), strtotime( $renewal['retry_at'] . ' UTC' ), 10 );
	}

	/** SET-LIC-03: one retry on day 5. */
	public function test_one_retry_on_the_configured_day(): void {
		update_option( Settings::OPTION, array( 'renewal_retry_days' => '5' ) );
		$this->assertSame( array( 5 ), RenewalProcessor::retry_days() );
		$f                                 = $this->renewing_license();
		WPLC_Test_Recurring_Adapter::$mode = 'decline';

		RenewalProcessor::process( $f['renewal_id'] );

		$renewal = $this->renewal( $f['renewal_id'] );
		$start   = strtotime( $renewal['period_start'] . ' UTC' );
		$this->assertEqualsWithDelta( max( $start + 5 * DAY_IN_SECONDS, time() + 5 * DAY_IN_SECONDS ), strtotime( $renewal['retry_at'] . ' UTC' ), 10 );
		$this->assertSame( 'past_due', $this->license( $f['license_id'] )['status'] );
	}

	/** SET-LIC-03: an empty list means no retry; the license stays past due until the grace period ends. */
	public function test_no_retry_when_the_list_is_empty(): void {
		update_option( Settings::OPTION, array( 'renewal_retry_days' => '' ) );
		$this->assertSame( '', Settings::get( 'renewal_retry_days' ) );
		$this->assertSame( array(), RenewalProcessor::retry_days() );
		$f                                 = $this->renewing_license();
		WPLC_Test_Recurring_Adapter::$mode = 'decline';

		RenewalProcessor::process( $f['renewal_id'] );
		WPLC_Test_Recurring_Adapter::$mode = 'success';
		RenewalProcessor::process( $f['renewal_id'] );

		$this->assertSame( 1, $this->charges(), 'The first failed charge is final.' );
		$this->assertSame( 'past_due', $this->license( $f['license_id'] )['status'] );
		$this->assertSame( 0, RenewalProcessor::next_retry_at( 1, time(), time(), RenewalProcessor::retry_days() ) );
	}

	/** SET-LIC-03: the filter wins over the setting. */
	public function test_retry_filter_wins(): void {
		update_option( Settings::OPTION, array( 'renewal_retry_days' => '2,4' ) );
		add_filter(
			'wplc_renewal_retry_days',
			static function ( $days ) {
				return array_merge( (array) $days, array( 9 ) );
			}
		);
		$this->assertSame( array( 2, 4, 9 ), RenewalProcessor::retry_days() );
	}

	/** SET-LIC-02/-03: a grace period that is not longer than the last retry day is saved with a warning. */
	public function test_the_form_warns_when_retries_fall_after_the_grace_period(): void {
		$GLOBALS['wp_settings_errors'] = array();
		update_option(
			Settings::OPTION,
			array(
				'renewal_grace_days' => '5',
				'renewal_retry_days' => '1,3,7',
			)
		);
		$this->assertSame( 5, Settings::get( 'renewal_grace_days' ) );
		$errors = get_settings_errors( Settings::OPTION );
		$this->assertContains( 'wplc_grace_before_last_retry', wp_list_pluck( $errors, 'code' ) );
		$this->assertContains( 'warning', wp_list_pluck( $errors, 'type' ) );
		$GLOBALS['wp_settings_errors'] = array();
	}

	/** SET-LIC-16: the window of manual renewal before and after the end. */
	public function test_manual_renewal_window_follows_the_settings(): void {
		$now     = time();
		$license = array(
			'id'         => 1,
			'status'     => 'active',
			'paid_until' => gmdate( 'Y-m-d H:i:s', $now + 20 * DAY_IN_SECONDS ),
		);
		$this->assertTrue( ManualRenewal::in_window( $license, $now ) );

		update_option(
			Settings::OPTION,
			array(
				'manual_renewal_days_before' => '10',
				'manual_renewal_days_after'  => '3',
			)
		);
		$this->assertFalse( ManualRenewal::in_window( $license, $now ), '20 days before the end, window 10.' );
		$this->assertTrue( ManualRenewal::in_window( $license, $now + 11 * DAY_IN_SECONDS ) );

		$paid_until = $now - 5 * DAY_IN_SECONDS;
		$this->assertFalse( LicenseStateMachine::can_apply_manual_renewal( 'expired', $paid_until, $now ), 'Five days after the end, window 3.' );
		$this->assertTrue( LicenseStateMachine::can_apply_manual_renewal( 'expired', $paid_until, $paid_until + 2 * DAY_IN_SECONDS ) );

		add_filter(
			'wplc_manual_renewal_window_days',
			static function ( $window ) {
				$window['after'] = 9999;
				return $window;
			}
		);
		$this->assertSame( array( 'before' => 10, 'after' => 365 ), LicenseStateMachine::manual_renewal_window(), 'The filter wins and is kept inside 0 to 365.' );
		$this->assertTrue( LicenseStateMachine::can_apply_manual_renewal( 'expired', $paid_until, $now ) );
	}

	/** SET-LIC-16: 0 days after the end means an expired license cannot be renewed by the customer. */
	public function test_zero_days_after_the_end_closes_the_window_at_the_end(): void {
		update_option( Settings::OPTION, array( 'manual_renewal_days_after' => '0' ) );
		$now = time();
		$this->assertFalse( LicenseStateMachine::can_apply_manual_renewal( 'expired', $now - 60, $now ) );
		$this->assertFalse(
			ManualRenewal::in_window(
				array(
					'id'         => 1,
					'status'     => 'expired',
					'paid_until' => gmdate( 'Y-m-d H:i:s', $now - 60 ),
				),
				$now
			)
		);
	}
}
