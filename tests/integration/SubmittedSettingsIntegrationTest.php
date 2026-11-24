<?php
/**
 * Review findings of 24.11.2026 on the settings: one set of checks for every path, the form,
 * messages at the field, the tools below the form and WP-CLI.
 *
 * @package WPLC
 */

use WPLC\Admin\HealthAlertMail;
use WPLC\Admin\Settings;
use WPLC\Admin\SettingsTools;
use WPLC\CLI\SettingsCommand;
use WPLC\Settings\Changes;
use WPLC\Settings\Registry;

final class SubmittedSettingsIntegrationTest extends WPLC_Integration_TestCase {

	/**
	 * Administrator.
	 *
	 * @var int
	 */
	private $admin;

	/**
	 * Shop manager.
	 *
	 * @var int
	 */
	private $manager;

	public function set_up(): void {
		parent::set_up();
		$this->admin   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->manager = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		delete_option( Settings::OPTION );
		delete_option( HealthAlertMail::SENT_OPTION );
		Settings::reset_request_state();
		$GLOBALS['wp_settings_errors'] = array();
		wp_set_current_user( $this->admin );
		reset_phpmailer_instance();
	}

	public function tear_down(): void {
		remove_all_filters( 'sanitize_option_' . Settings::OPTION );
		remove_all_filters( 'wplc_settings_country_profiles' );
		remove_all_filters( 'wplc_health_alert_recipients' );
		remove_all_filters( 'wplc_combined_renewals' );
		remove_all_filters( 'wplc_renewal_notice_lead_days' );
		foreach ( array( $this->admin, $this->manager ) as $user ) {
			delete_transient( 'wplc_settings_pending_' . $user );
			delete_transient( 'wplc_settings_tool_result_' . $user );
			delete_transient( 'wplc_settings_rejected_' . $user );
			delete_transient( 'wplc_test_alerts_' . $user );
		}
		delete_transient( 'settings_errors' );
		delete_site_transient( 'available_translations' );
		$_POST = array();
		unset( $_GET['settings-updated'], $_GET['tab'] );
		$GLOBALS['wp_settings_errors'] = array();
		Settings::reset_request_state();
		delete_option( Settings::OPTION );
		parent::tear_down();
	}

	private function capture( callable $callback ): string {
		ob_start();
		try {
			$callback();
		} finally {
			$html = (string) ob_get_clean();
		}

		return $html;
	}

	/**
	 * Save like options.php does: the sanitize callback of the page with the form marker.
	 *
	 * @param array<string,mixed> $input Submitted values.
	 * @return void
	 */
	private function submit_form( array $input ): void {
		add_filter( 'sanitize_option_' . Settings::OPTION, array( Settings::class, 'sanitize' ) );
		$_POST['option_page'] = Settings::OPTION;
		update_option( Settings::OPTION, $input );
		unset( $_POST['option_page'] );
	}

	/**
	 * Codes of the settings errors of this request.
	 *
	 * @return string[]
	 */
	private function error_codes(): array {
		return array_map( 'strval', wp_list_pluck( get_settings_errors( Settings::OPTION ), 'code' ) );
	}

	// ------------------------------------------------------------------ P1-I2

	/** P1-I2: WP-CLI (no sanitize callback of the page) keeps the account addresses apart, also from WooCommerce pages. */
	public function test_cli_refuses_account_addresses_in_conflict(): void {
		$this->assertFalse( has_filter( 'sanitize_option_' . Settings::OPTION ), 'WP-CLI: admin_init never ran.' );
		$licenses = Registry::string( 'account_licenses_slug' );

		$result = SettingsCommand::change( array( 'account_sites_slug' => $licenses ), 'settings_changed', 'Test.' );
		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'already the address of another page', $result['message'] );
		$this->assertNotSame( $licenses, Registry::string( 'account_sites_slug' ) );

		$result = SettingsCommand::change( array( 'account_licenses_slug' => 'orders' ), 'settings_changed', 'Test.' );
		$this->assertFalse( $result['ok'], 'The WooCommerce page "orders" keeps its address.' );
		$this->assertSame( $licenses, Registry::string( 'account_licenses_slug' ) );

		$states = wp_list_pluck( Changes::preview( array( 'account_licenses_slug' => 'orders' ), false ), 'state', 'key' );
		$this->assertSame( array( 'account_licenses_slug' => 'conflict' ), $states, 'Import and reset see the same check.' );
		$this->assertSame( array( 'account_licenses_slug' => 'conflict' ), Changes::apply( array( 'account_licenses_slug' => 'orders' ), 'settings_imported', 'Test.', false )['skipped'] );
	}

	/** P1-I2: a constant wins for every path, and a combination warning is reported on the command line too. */
	public function test_cli_respects_constants_and_reports_combination_warnings(): void {
		// The same constant and value as ShopSetupSettingsIntegrationTest, which runs before this class
		// (alphabetical order) and needs it undefined in its earlier tests.
		if ( ! defined( 'WPLC_S3_PATH_STYLE' ) ) {
			define( 'WPLC_S3_PATH_STYLE', true );
		}
		$result = SettingsCommand::change( array( 's3_path_style' => '1' ), 'settings_changed', 'Test.' );
		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'WPLC_S3_PATH_STYLE', $result['message'] );
		$this->assertSame( 0, (int) Registry::get( 's3_path_style' ), 'The stored value stays.' );

		$result = SettingsCommand::change( array( 'renewal_grace_days' => '5' ), 'settings_changed', 'Test.' );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 5, Registry::int( 'renewal_grace_days' ), 'Saved all the same.' );
		$this->assertCount( 1, $result['warnings'] );
		$this->assertStringContainsString( 'last retry day', $result['warnings'][0] );
	}

	// ------------------------------------------------------------------ P1-M5, P1-M8

	/** P1-M5: "set" on a fixed setting fails with the reason instead of reporting success. */
	public function test_cli_set_on_a_locked_setting_fails_with_the_reason(): void {
		$this->seed_license();
		$this->assertTrue( Registry::is_locked( 'license_key_prefix' ) );
		$result = SettingsCommand::change( array( 'license_key_prefix' => 'ACME' ), 'settings_changed', 'Test.' );
		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'license_key_prefix: Fixed', $result['message'] );

		// In an import with other rows, the fixed row is reported and the others are applied.
		$mixed = SettingsCommand::change( array( 'license_key_prefix' => 'ACME', 'renewal_notice_days' => '9' ), 'settings_imported', 'Test.' );
		$this->assertTrue( $mixed['ok'] );
		$this->assertSame( array( 'renewal_notice_days' ), $mixed['applied'] );
		$this->assertContains( 'Not changed: license_key_prefix: Fixed: licenses were already issued with this value, and their keys contain it.', $mixed['lines'] );
	}

	/** P1-M8: the help of --group names every group. */
	public function test_cli_help_names_every_group(): void {
		foreach ( array( 'list_', 'reset' ) as $method ) {
			$doc = (string) ( new ReflectionMethod( SettingsCommand::class, $method ) )->getDocComment();
			foreach ( Registry::GROUPS as $group ) {
				$this->assertMatchesRegularExpression( '/--group=<group>\]\s+\*\s+:[^\n]*\b' . $group . '\b/', $doc, "$method: $group" );
			}
		}
	}

	// ------------------------------------------------------------------ P4-L3 / P1-I3

	/** P4-L3: an import cannot switch to deleting all data. */
	public function test_import_cannot_choose_to_delete_all_data(): void {
		$this->assertFalse( Registry::definition( 'uninstall_data' )['exportable'] );
		$this->assertArrayNotHasKey( 'uninstall_data', Changes::export()['settings'] );
		$parsed = Changes::parse_import( '{"plugin":"wp-license","format":1,"settings":{"uninstall_data":"delete","renewal_notice_days":9}}' );
		$this->assertContains( 'uninstall_data', $parsed['ignored'] );
		$this->assertArrayNotHasKey( 'uninstall_data', $parsed['values'] );

		// Not even a country profile supplied by code.
		add_filter(
			'wplc_settings_country_profiles',
			static function ( $profiles ) {
				$profiles['AT']['values']['uninstall_data'] = 'delete';
				return $profiles;
			}
		);
		SettingsTools::prepare_preset( 'AT' );
		SettingsTools::apply_pending( array( 'uninstall_data' ), true );
		$this->assertSame( 'keep', Registry::string( 'uninstall_data' ) );
	}

	// ------------------------------------------------------------------ P1-M4

	/** P1-M4: the first save (option not stored yet) keeps a confirmed "delete" and reports each problem once. */
	public function test_first_save_runs_the_checks_once(): void {
		$this->assertFalse( get_option( Settings::OPTION ) );
		$this->submit_form(
			array(
				'uninstall_data'         => 'delete',
				'uninstall_data_confirm' => '1',
				'renewal_grace_days'     => '0',
				'renewal_retry_days'     => '1,3,7',
			)
		);
		$this->assertSame( 'delete', Registry::string( 'uninstall_data' ) );
		$codes = $this->error_codes();
		$this->assertSame( 1, count( array_keys( $codes, 'wplc_invalid_renewal_grace_days', true ) ), 'Reported once.' );
		$this->assertNotContains( 'wplc_uninstall_not_confirmed', $codes );
	}

	// ------------------------------------------------------------------ P1-M6

	/** P1-M6: fields with a renderer of their own name the filter and show the value in effect. */
	public function test_own_renderers_show_the_filter_and_the_value_in_effect(): void {
		add_filter( 'wplc_combined_renewals', '__return_true' );
		add_filter(
			'wplc_renewal_notice_lead_days',
			static function () {
				return 21;
			}
		);
		$switch = $this->capture( array( Settings::class, 'render_combined_renewals_field' ) );
		$this->assertStringContainsString( 'wplc_combined_renewals', $switch );
		$this->assertStringContainsString( 'In effect:', $switch );
		$number = $this->capture( array( Settings::class, 'render_renewal_notice_days_field' ) );
		$this->assertStringContainsString( 'the filter wplc_renewal_notice_lead_days', $number );
		$this->assertStringContainsString( 'In effect: 21', $number );
		foreach ( array( 'render_catalog_auto_import_field', 'render_price_change_notice_days_field', 'render_suspended_ends_after_days_field' ) as $renderer ) {
			$this->assertStringContainsString( 'Code of the shop can change the value with the filter', $this->capture( array( Settings::class, $renderer ) ), $renderer );
		}
	}

	// ------------------------------------------------------------------ P1-M7

	/** P1-M7: more than 50 package hosts are rejected and reported, never cut silently. */
	public function test_too_many_package_hosts_are_reported(): void {
		$hosts = array();
		for ( $i = 1; $i <= 51; $i++ ) {
			$hosts[] = 'cdn' . $i . '.example.org';
		}
		$this->assertCount( 51, Registry::parse_hosts( implode( "\n", $hosts ) )['valid'] );
		$this->assertSame( 'too_many', Registry::validate( 'package_hosts', $hosts )['code'] );
		$this->assertTrue( Registry::validate( 'package_hosts', array_slice( $hosts, 0, 50 ) )['ok'] );

		$this->submit_form(
			array(
				'package_hosts'      => implode( "\n", $hosts ),
				'package_hosts_seen' => Settings::hosts_fingerprint( array() ),
			)
		);
		$this->assertSame( array(), Settings::get( 'package_hosts' ), 'The stored list stays.' );
		$this->assertContains( 'wplc_invalid_package_hosts', $this->error_codes() );
		$this->assertStringContainsString( 'at most 50 entries', implode( ' ', wp_list_pluck( get_settings_errors( Settings::OPTION ), 'message' ) ) );
	}

	// ------------------------------------------------------------------ P4-L4

	/** P4-L4: test health alerts are limited per user and hour like test emails. */
	public function test_test_alerts_are_limited_per_hour(): void {
		update_option( Settings::OPTION, array( 'health_alert_recipients' => 'ops@shop.example' ) );
		for ( $i = 0; $i < SettingsTools::TEST_EMAILS_PER_HOUR; $i++ ) {
			$this->assertStringContainsString( 'was handed over for sending', SettingsTools::send_test_alert() );
		}
		reset_phpmailer_instance();
		$this->assertStringContainsString( 'within the last hour', SettingsTools::send_test_alert() );
		$this->assertFalse( tests_retrieve_phpmailer_instance()->get_sent(), 'Nothing was sent.' );

		wp_set_current_user( $this->manager );
		$this->assertStringContainsString( 'was handed over for sending', SettingsTools::send_test_alert(), 'Per user.' );
	}

	// ------------------------------------------------------------------ P5-I1

	/** P5-I1: "Settings saved." after a clean save, once. */
	public function test_a_clean_save_says_settings_saved_once(): void {
		$_GET['settings-updated'] = 'true';
		set_transient(
			'settings_errors',
			array(
				array(
					'setting' => 'general',
					'code'    => 'settings_updated',
					'message' => 'Settings saved.',
					'type'    => 'success',
				),
			),
			30
		);
		$first = $this->capture( array( Settings::class, 'render_notices' ) );
		$this->assertSame( 1, substr_count( $first, 'Settings saved.' ) );
		$this->assertStringContainsString( 'notice-success', $first );
		$this->assertStringNotContainsString( 'Settings saved.', $this->capture( array( Settings::class, 'render_notices' ) ), 'Only once.' );

		unset( $_GET['settings-updated'] );
		$this->assertSame( '', $this->capture( array( Settings::class, 'render_notices' ) ) );
	}

	// ------------------------------------------------------------------ P5-I2

	/** P5-I2: a rejected value is marked at its field, linked from the message and shown again for correction. */
	public function test_a_rejected_value_is_shown_at_its_field(): void {
		$this->submit_form(
			array(
				'renewal_grace_days' => '0',
				'support_email'      => 'not-an-address',
			)
		);
		$this->assertSame( 14, Registry::int( 'renewal_grace_days' ), 'The stored value stays.' );
		$messages = implode( ' ', wp_list_pluck( get_settings_errors( Settings::OPTION ), 'message' ) );
		$this->assertStringContainsString( '<a href="#wplc_support_email">', $messages );
		$this->assertStringContainsString( '<a href="#wplc_renewal_grace_days">', $messages );

		// The next page view of the same user.
		Settings::reset_request_state();
		$field = $this->capture(
			static function () {
				Settings::render_field( array( 'key' => 'support_email' ) );
			}
		);
		$this->assertStringContainsString( 'aria-invalid="true"', $field );
		$this->assertStringContainsString( 'aria-describedby="wplc_support_email_error wplc_support_email_help"', $field );
		$this->assertStringContainsString( 'id="wplc_support_email_error"', $field );
		$this->assertStringContainsString( 'value="not-an-address"', $field, 'The submitted value can be corrected.' );
		$grace = $this->capture(
			static function () {
				Settings::render_field( array( 'key' => 'renewal_grace_days' ) );
			}
		);
		$this->assertStringContainsString( 'value="0"', $grace );
		$ok = $this->capture(
			static function () {
				Settings::render_field( array( 'key' => 'renewal_notice_days' ) );
			}
		);
		$this->assertStringNotContainsString( 'aria-invalid', $ok );

		// Read once: a later page view shows the stored values.
		Settings::reset_request_state();
		$again = $this->capture(
			static function () {
				Settings::render_field( array( 'key' => 'support_email' ) );
			}
		);
		$this->assertStringNotContainsString( 'aria-invalid', $again );

		// Another user never sees them.
		$this->submit_form( array( 'support_email' => 'still-wrong' ) );
		Settings::reset_request_state();
		wp_set_current_user( $this->manager );
		$other = $this->capture(
			static function () {
				Settings::render_field( array( 'key' => 'support_email' ) );
			}
		);
		$this->assertStringNotContainsString( 'still-wrong', $other );
	}

	// ------------------------------------------------------------------ P5-M12

	/** P5-M12: read-only switches and choices are text fields: reachable with the keyboard, still without a name. */
	public function test_read_only_switches_and_choices_are_reachable(): void {
		wp_set_current_user( $this->manager );
		foreach ( array( 'uninstall_data' => 'Keep all data', 'beta_updates' => '' ) as $key => $shown ) {
			$field = $this->capture(
				static function () use ( $key ) {
					Settings::render_field( array( 'key' => $key ) );
				}
			);
			$this->assertStringNotContainsString( 'disabled', $field, $key );
			$this->assertStringNotContainsString( 'name="wplc_settings[' . $key . ']"', $field, "$key: no name, the stored value is kept." );
			$this->assertStringContainsString( 'readonly', $field, $key );
			$this->assertStringContainsString( 'aria-describedby="wplc_' . $key . '_help"', $field, "$key: the reason is read with the field." );
			if ( '' !== $shown ) {
				$this->assertStringContainsString( 'value="' . $shown . '"', $field );
			}
		}
	}

	// ------------------------------------------------------------------ P5-M13 .. M16, P5-M8

	/** P5-M13, P5-M16: the tools have unique button ids and language names. */
	public function test_tools_have_unique_ids_and_language_names(): void {
		set_site_transient( 'available_translations', array( 'de_DE_formal' => array( 'native_name' => 'Deutsch (Sie)' ) ) );
		$this->assertSame( 'Deutsch (Sie) (de_DE_formal)', SettingsTools::locale_name( 'de_DE_formal' ) );
		$this->assertSame( 'English (United States) (en_US)', SettingsTools::locale_name( 'en_US' ) );

		$html = $this->capture( array( SettingsTools::class, 'render' ) );
		$this->assertStringNotContainsString( 'id="submit"', $html );
		preg_match_all( '/<input type="submit"[^>]*id="([^"]+)"/', $html, $ids );
		$this->assertNotEmpty( $ids[1] );
		$this->assertSame( $ids[1], array_unique( $ids[1] ) );
		$this->assertStringContainsString( '>English (United States) (en_US)</option>', $html );

		SettingsTools::prepare_reset( 'renewals' );
		update_option( Settings::OPTION, array( 'renewal_notice_days' => '9' ) );
		$preview = $this->capture( array( SettingsTools::class, 'render' ) );
		$this->assertStringNotContainsString( 'id="submit"', $preview );
		$this->assertSame( substr_count( $preview, 'name="keys[]"' ), substr_count( $preview, '<label for="wplc-apply-' ), 'A label only where a box is.' );
	}

	/** P5-M15: the tools return to the tab they were sent from. */
	public function test_tools_return_to_the_tab(): void {
		$_POST['tab'] = 'legal';
		$this->assertStringContainsString( 'tab=legal', SettingsTools::return_url() );
		$_POST['tab'] = 'no-such-tab';
		$this->assertStringNotContainsString( 'tab=', SettingsTools::return_url() );

		$_GET['tab'] = 'renewals';
		$html        = $this->capture( array( SettingsTools::class, 'render' ) );
		$this->assertStringContainsString( '<input type="hidden" name="tab" value="renewals">', $html );
	}

	/** P5-M14, P5-M16, P5-M8: the page structure. */
	public function test_page_structure(): void {
		Settings::register_settings();
		$html = $this->capture( array( Settings::class, 'render' ) );
		$this->assertStringNotContainsString( '<summary><h2', $html, 'No heading inside <summary>.' );
		$this->assertStringContainsString( '<h2 id="wplc-setup-title">', $html );
		$this->assertStringContainsString( '<table class="widefat striped"><tbody>', $html, 'The status table is a data table.' );
		$this->assertStringContainsString( 'aria-describedby="wplc-guided-renewal_grace_days-help"', $html );
		$this->assertStringContainsString( 'id="wplc-guided-renewal_grace_days-help"', $html );
		$this->assertStringContainsString( '<th scope="row" style="width:260px">Grace period after the paid period</th>', $html );
		$this->assertStringNotContainsString( 'Renewal grace period', $html );
	}

	// ------------------------------------------------------------------ P3-A7, P3-A8

	/** P3-A7: a value changed after the preview was prepared is not overwritten. */
	public function test_apply_refuses_values_changed_after_the_preview(): void {
		update_option( Settings::OPTION, array( 'retention_audit_log' => '400', 'retention_event_inbox' => '90' ) );
		SettingsTools::prepare_preset( 'DE' );
		update_option( Settings::OPTION, array( 'retention_audit_log' => '500', 'retention_event_inbox' => '90' ) );

		$this->assertSame( array( 'retention_audit_log' ), SettingsTools::changed_since_preview( SettingsTools::pending() ) );
		$html = $this->capture( array( SettingsTools::class, 'render' ) );
		$this->assertStringNotContainsString( 'name="keys[]" value="retention_audit_log"', $html );
		$this->assertStringContainsString( 'changed after this preview was prepared', $html );

		$message = SettingsTools::apply_pending( array( 'retention_audit_log', 'retention_event_inbox' ), true );
		$this->assertSame( 500, Registry::int( 'retention_audit_log' ), 'The newer value stays.' );
		$this->assertSame( 180, Registry::int( 'retention_event_inbox' ), 'The unchanged row is applied.' );
		$this->assertStringContainsString( 'was changed after the preview was prepared', $message );
	}

	/** P3-A8: import and reset never tick a row with legal weight in advance; an empty consent text is explained. */
	public function test_legal_rows_are_not_ticked_and_empty_texts_are_explained(): void {
		update_option( Settings::OPTION, array( 'withdrawal_consent_text' => 'Own wording.', 'renewal_notice_days' => '9' ) );
		SettingsTools::prepare_import( '{"plugin":"wp-license","format":1,"settings":{"withdrawal_consent_text":"","renewal_notice_days":7}}' );
		$html = $this->capture( array( SettingsTools::class, 'render' ) );
		$this->assertMatchesRegularExpression( '/name="keys\[\]" value="renewal_notice_days"\s*checked/', $html, 'A row without legal weight is ticked.' );
		$this->assertDoesNotMatchRegularExpression( '/name="keys\[\]" value="withdrawal_consent_text"\s*checked/', $html );
		$this->assertMatchesRegularExpression( '/would change · unchecked/', $html );
		$this->assertStringContainsString( 'empty: the built-in wording in the language of the customer', $html );
	}
}
