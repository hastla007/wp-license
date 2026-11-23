<?php
/**
 * SET-LIC-04, -05, -06: retention periods and consent wording as settings.
 *
 * @package WPLC
 */

use WPLC\Admin\Settings;
use WPLC\Billing\RecurringConsent;
use WPLC\Billing\WithdrawalConsent;
use WPLC\Maintenance\Retention;

final class LegalSettingsIntegrationTest extends WPLC_Integration_TestCase {

	private const FILTERS = array( 'wplc_retention_days', 'wplc_withdrawal_consent_enabled', 'wplc_withdrawal_consent_required', 'wplc_withdrawal_consent_text', 'wplc_recurring_consent_text' );

	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION );
		add_filter( 'sanitize_option_' . Settings::OPTION, array( Settings::class, 'sanitize' ) );
	}

	public function tear_down(): void {
		remove_all_filters( 'sanitize_option_' . Settings::OPTION );
		foreach ( self::FILTERS as $filter ) {
			remove_all_filters( $filter );
		}
		delete_option( Settings::OPTION );
		parent::tear_down();
	}

	/** S9: nothing changes for an existing installation. */
	public function test_defaults_are_the_behaviour_so_far(): void {
		$this->assertSame( Retention::DEFAULT_DAYS, Retention::configured_days() );
		$this->assertSame( ! WithdrawalConsent::legal_plugin_active(), WithdrawalConsent::enabled() );
		$this->assertTrue( WithdrawalConsent::required() );
		$this->assertStringContainsString( 'I expressly agree', WithdrawalConsent::label() );
		$this->assertStringContainsString( 'Renew automatically: I authorize', RecurringConsent::label() );
	}

	/** SET-LIC-04: setting, then filter; 0 switches a rule off. */
	public function test_retention_follows_the_settings_and_the_filter_wins(): void {
		update_option(
			Settings::OPTION,
			array(
				'retention_audit_log'          => '400',
				'retention_anonymize_licenses' => '0',
			)
		);
		$days = Retention::configured_days();
		$this->assertSame( 400, $days['audit_log'] );
		$this->assertSame( 0, $days['anonymize_licenses'] );
		$this->assertSame( Retention::DEFAULT_DAYS['consents'], $days['consents'], 'Other rules keep their default.' );

		add_filter(
			'wplc_retention_days',
			static function ( $days ) {
				$days['audit_log'] = (int) $days['audit_log'] + 1;
				return $days;
			}
		);
		$this->assertSame( 401, Retention::configured_days()['audit_log'], 'The filter receives the setting and decides last.' );
		$this->assertSame( 401, \WPLC\Settings\Effective::value( 'retention_audit_log' ) );
		$this->assertTrue( \WPLC\Settings\Effective::is_overridden( 'retention_audit_log' ) );
	}

	/** SET-LIC-04: the retention run uses the setting. */
	public function test_the_retention_run_deletes_by_the_configured_period(): void {
		global $wpdb;
		$table = $this->tables()['audit_log'];
		$wpdb->insert(
			$table,
			array(
				'action'      => 'settings_test_old',
				'object_type' => 'settings',
				'reason'      => '',
				'created_at'  => gmdate( 'Y-m-d H:i:s', time() - 500 * DAY_IN_SECONDS ),
			)
		);
		$id = (int) $wpdb->insert_id;

		Retention::run();
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE id = %d', $table, $id ) ), 'Default 730 days: a row of 500 days stays.' );

		update_option( Settings::OPTION, array( 'retention_audit_log' => '400' ) );
		Retention::run();
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE id = %d', $table, $id ) ), 'Setting 400 days: the row is deleted.' );
	}

	/** SET-LIC-05: on, off, required and wording. */
	public function test_withdrawal_consent_follows_the_settings(): void {
		update_option( Settings::OPTION, array( 'withdrawal_consent_mode' => 'off' ) );
		$this->assertFalse( WithdrawalConsent::enabled() );
		update_option( Settings::OPTION, array( 'withdrawal_consent_mode' => 'on' ) );
		$this->assertTrue( WithdrawalConsent::enabled() );
		add_filter( 'wplc_withdrawal_consent_enabled', '__return_false' );
		$this->assertFalse( WithdrawalConsent::enabled(), 'The filter wins.' );

		update_option( Settings::OPTION, array( 'withdrawal_consent_required' => '0' ) );
		$this->assertFalse( WithdrawalConsent::required() );
		add_filter( 'wplc_withdrawal_consent_required', '__return_true' );
		$this->assertTrue( WithdrawalConsent::required(), 'The filter wins.' );

		update_option( Settings::OPTION, array( 'withdrawal_consent_text' => 'Our own wording of the shop.' ) );
		$this->assertSame( 'Our own wording of the shop.', WithdrawalConsent::label() );
		add_filter(
			'wplc_withdrawal_consent_text',
			static function ( $text ) {
				return $text . ' (filtered)';
			}
		);
		$this->assertSame( 'Our own wording of the shop. (filtered)', WithdrawalConsent::label() );
	}

	/** SET-LIC-06: the wording of the consent to automatic renewal, in both checkouts and in the evidence. */
	public function test_recurring_consent_wording_follows_the_setting_and_is_the_evidence(): void {
		update_option( Settings::OPTION, array( 'recurring_consent_text' => 'Yes, renew my license every year until I cancel.' ) );
		$this->assertSame( 'Yes, renew my license every year until I cancel.', RecurringConsent::label() );

		$product  = $this->create_license_product();
		$order    = $this->create_order( array( array( $product, 1 ) ) );
		$evidence = RecurringConsent::evidence( $order, 'classic' );
		$this->assertStringStartsWith( 'Yes, renew my license every year until I cancel.', $evidence['text'] );
		$this->assertSame( hash( 'sha256', $evidence['text'] ), $evidence['hash'] );

		add_filter(
			'wplc_recurring_consent_text',
			static function () {
				return 'Wording from code.';
			}
		);
		$this->assertSame( 'Wording from code.', RecurringConsent::label(), 'The filter wins.' );
		add_filter( 'wplc_recurring_consent_text', '__return_empty_string', 20 );
		$this->assertStringContainsString( 'Renew automatically', RecurringConsent::label(), 'An empty result never removes the wording.' );
	}

	/** A text with markup is stored as plain text. */
	public function test_wording_is_stored_without_markup(): void {
		update_option( Settings::OPTION, array( 'withdrawal_consent_text' => "I agree.<script>alert(1)</script>\nSecond line." ) );
		$this->assertStringNotContainsString( '<', (string) Settings::get( 'withdrawal_consent_text' ) );
		$this->assertStringContainsString( 'Second line.', (string) Settings::get( 'withdrawal_consent_text' ) );
	}
}
