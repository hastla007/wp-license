<?php
/**
 * Settings for the sale, part 2 (SET-LIC-11, -22, -23, -27, -29, -37, -38, -41, -42): defaults, limits and readers.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\Admin\Settings;
use WPLC\Settings\Definitions;
use WPLC\Settings\Registry;
use WPLC\Settings\Texts;

require_once dirname( __DIR__ ) . '/Admin/wp-function-stubs.php';

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

final class SalesSettingsTest extends TestCase {

	protected function tearDown(): void {
		foreach ( array( 'wplc_new_activations_per_hour', 'wplc_offline_cache_days', 'wplc_health_alert_thresholds', 'wplc_log_debug', 'wplc_support_url' ) as $hook ) {
			unset( $GLOBALS['wplc_test_filters'][ $hook ] );
		}
	}

	/** Every new setting starts with the value the code used before, so an update changes nothing. */
	public function test_new_settings_default_to_the_behaviour_so_far(): void {
		$defaults = Registry::defaults();
		$this->assertSame( \WPLC\Api\V1\EntitlementService::OFFLINE_DAYS, $defaults['offline_cache_days'] );
		$this->assertSame( \WPLC\Api\V1\ActivationService::NEW_ACTIVATIONS_PER_HOUR, $defaults['new_activations_per_hour'] );
		$this->assertSame( \WPLC\Billing\CombinedRenewal::MAX_LINES, $defaults['combined_renewal_max_lines'] );
		$this->assertSame( \WPLC\License\LicenseLifecycle::REVIEW_EXPIRY_DEFERRAL_DAYS, $defaults['review_deferral_days'] );
		$this->assertSame( \WPLC\Billing\PlanUpgrade::QUOTE_TTL, $defaults['upgrade_quote_hours'] * 3600 );
		$this->assertSame( '', $defaults['support_email'] );
		$this->assertSame( '', $defaults['support_url'] );
		$this->assertSame( 'off', $defaults['debug_log'] );
		foreach ( Definitions::ALERT_THRESHOLDS as $metric => $threshold ) {
			$this->assertSame( $threshold, $defaults[ 'alert_' . $metric ], $metric );
			$this->assertSame( 'wplc_health_alert_thresholds', Registry::definition( 'alert_' . $metric )['filter'], $metric );
		}
	}

	public function test_limits_groups_and_capabilities(): void {
		// SET-LIC-11: 1 to 90 days; a rule for every customer site, so administrators only (like the package hosts).
		$this->assertSame( 'out_of_range', Registry::validate( 'offline_cache_days', '0' )['code'] );
		$this->assertSame( 'out_of_range', Registry::validate( 'offline_cache_days', '91' )['code'] );
		$this->assertSame( 90, Registry::validate( 'offline_cache_days', '90' )['value'] );
		$this->assertSame( 'manage_package_hosts', Registry::definition( 'offline_cache_days' )['capability'] );
		$this->assertSame( 'sites', Registry::definition( 'offline_cache_days' )['group'] );
		$this->assertStringContainsString( 'never reaches beyond the end of access', Texts::help( 'offline_cache_days' ) );

		$this->assertSame( 0, Registry::validate( 'new_activations_per_hour', '0' )['value'], '0 = no limit.' );
		$this->assertSame( 'out_of_range', Registry::validate( 'combined_renewal_max_lines', '1' )['code'], 'A combined order has at least two licenses.' );
		$this->assertSame( 'out_of_range', Registry::validate( 'upgrade_quote_hours', '169' )['code'] );
		$this->assertSame( 'out_of_range', Registry::validate( 'review_deferral_days', '0' )['code'] );

		// SET-LIC-23.
		$this->assertSame( 'not_an_email', Registry::validate( 'support_email', 'help desk' )['code'] );
		$this->assertSame( 'not_a_url', Registry::validate( 'support_url', 'javascript:alert(1)' )['code'] );
		$this->assertSame( 'https://shop.example/support', Registry::validate( 'support_url', ' https://shop.example/support ' )['value'] );
		$this->assertSame( 'texts', Registry::definition( 'support_email' )['group'] );

		// SET-LIC-29: the verbose log belongs to this server; an export never switches it on elsewhere.
		$this->assertFalse( Registry::definition( 'debug_log' )['exportable'] );
		$this->assertSame( 'not_a_choice', Registry::validate( 'debug_log', 'forever' )['code'] );
		$this->assertSame( array( 'off', '24h', '7d' ), array_keys( Texts::choices( 'debug_log' ) ) );
		$this->assertSame( array_keys( \WPLC\Infrastructure\Log::VERBOSE_PERIODS ), array_slice( Registry::definition( 'debug_log' )['choices'], 1 ) );
	}

	/** SET-LIC-22: one threshold setting per figure that the diagnostics page can alert on, named after it. */
	public function test_every_alert_figure_has_a_named_threshold_setting(): void {
		$this->assertSame( array_keys( Definitions::ALERT_THRESHOLDS ), array_keys( Texts::alert_metrics() ) );
		foreach ( Texts::alert_metrics() as $metric => $name ) {
			$this->assertStringContainsString( $name, Texts::label( 'alert_' . $metric ) );
			$this->assertStringContainsString( '0 = never alert', Texts::help( 'alert_' . $metric ) );
		}
		$this->assertSame( Definitions::ALERT_THRESHOLDS, \WPLC\Admin\Diagnostics::thresholds(), 'Without a stored value: the thresholds so far.' );

		$GLOBALS['wplc_test_filters']['wplc_health_alert_thresholds'][] = static function ( $thresholds ) {
			$thresholds['failed_jobs'] = 9;
			unset( $thresholds['release_problems'] );
			return $thresholds;
		};
		$thresholds = \WPLC\Admin\Diagnostics::thresholds();
		$this->assertSame( 9, $thresholds['failed_jobs'], 'The filter wins.' );
		$this->assertArrayNotHasKey( 'release_problems', $thresholds );
	}

	/** SET-LIC-11, -27, -41, -42: the classes read the settings; the filters still decide last. */
	public function test_readers_use_the_settings_and_the_filters_win(): void {
		$license = array( 'id' => 1 );
		$this->assertSame( 10, \WPLC\Api\V1\ActivationService::new_activations_per_hour( $license, 3 ) );
		$this->assertSame( 16, \WPLC\Api\V1\ActivationService::new_activations_per_hour( $license, 8 ), 'At least twice the live-site limit.' );
		$GLOBALS['wplc_test_filters']['wplc_new_activations_per_hour'][] = static function () {
			return 2;
		};
		$this->assertSame( 2, \WPLC\Api\V1\ActivationService::new_activations_per_hour( $license, 8 ) );

		$now    = 1800000000;
		$fields = \WPLC\Api\V1\EntitlementService::access_fields( array( 'status' => 'active', 'paid_until' => null ), $now );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $now + 14 * 86400 ), $fields['cache_expires_at'] );
		$GLOBALS['wplc_test_filters']['wplc_offline_cache_days'][] = static function () {
			return 3;
		};
		$fields = \WPLC\Api\V1\EntitlementService::access_fields( array( 'status' => 'active', 'paid_until' => null ), $now );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $now + 3 * 86400 ), $fields['cache_expires_at'] );

		$this->assertSame( 20, \WPLC\Billing\CombinedRenewal::max_lines() );
		$this->assertSame( 30, \WPLC\License\LicenseLifecycle::review_deferral_days() );
		$this->assertSame( 86400, \WPLC\Billing\PlanUpgrade::quote_ttl() );
	}

	/** SET-LIC-37: list entries that are left out are named, repeated ones are not. */
	public function test_discarded_entries_of_a_list_are_reported(): void {
		$this->assertSame( array( 'abc', '0' ), Registry::discarded( 'reminder_days', array( '14', '7', 'abc', '0', '', '' ), '14,7' ) );
		$this->assertSame( array(), Registry::discarded( 'reminder_days', array( '30', '7', '7', '' ), '30,7' ) );
		$this->assertSame( array( '400' ), Registry::discarded( 'reminder_days', '30, 400', '30' ) );
		$this->assertSame( array( '0' ), Registry::discarded( 'reminder_days', '0', '30,7' ), 'Replaced by the default: the 0 was not kept.' );
		$this->assertSame( array( '2' ), Registry::discarded( 'reminder_days', '60,50,40,30,20,10,2', '60,50,40,30,20,10' ), 'Beyond the largest number of entries.' );
		$this->assertSame( array(), Registry::discarded( 'renewal_notice_days', '999', 60 ), 'Only lists.' );
		$this->assertSame( '14,7', Registry::validate( 'reminder_days', array( '14', '', '7', '', '', '' ) )['value'], 'Empty number fields are ignored.' );
	}

	/** SET-LIC-38: the state of a status row is a word as well as a colour. */
	public function test_status_states_have_a_text(): void {
		$this->assertSame( 'OK:', Settings::state_text( 'ok' ) );
		$this->assertSame( 'Attention:', Settings::state_text( 'warning' ) );
		$this->assertSame( '', Settings::state_text( '' ) );
	}

	/** SET-LIC-29: without the constant, the setting or the filter, no debug lines. */
	public function test_debug_lines_follow_the_constant_the_setting_and_the_filter(): void {
		$this->assertSame( 0, \WPLC\Infrastructure\Log::verbose_until() );
		$this->assertFalse( \WPLC\Infrastructure\Log::debug_enabled() );
		$GLOBALS['wplc_test_filters']['wplc_log_debug'][] = static function () {
			return true;
		};
		$this->assertTrue( \WPLC\Infrastructure\Log::debug_enabled(), 'The filter wins.' );
	}

	/** SET-LIC-27: the technical limits stay constants and are listed for the diagnostics page. */
	public function test_technical_limits_are_listed(): void {
		$limits = \WPLC\Admin\Diagnostics::technical_limits();
		$this->assertSame( array( 'REQUESTS_PER_INSTALLATION', 'AUTH_FAILURES_PER_IP', 'ATTEMPTS_PER_IP', 'ATTEMPTS_PER_KEY', 'TIMESTAMP_WINDOW' ), array_keys( $limits ) );
		$this->assertStringContainsString( (string) \WPLC\Api\V1\Authenticator::REQUESTS_PER_INSTALLATION, $limits['REQUESTS_PER_INSTALLATION'] );
		$this->assertStringContainsString( (string) \WPLC\Api\V1\Authenticator::TIMESTAMP_WINDOW, $limits['TIMESTAMP_WINDOW'] );
		$this->assertStringContainsString( (string) \WPLC\Api\V1\ActivationService::ATTEMPTS_PER_KEY, $limits['ATTEMPTS_PER_KEY'] );
	}
}
