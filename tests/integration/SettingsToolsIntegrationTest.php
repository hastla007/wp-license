<?php
/**
 * SET-LIC-08, -12, -13, -14, -22, -29: country profiles, export, import, reset, WP-CLI, health alerts, uninstall choice.
 *
 * @package WPLC
 */

use WPLC\Admin\HealthAlertMail;
use WPLC\Admin\Settings;
use WPLC\Admin\SettingsTools;
use WPLC\CLI\SettingsCommand;
use WPLC\Infrastructure\Database\Schema;
use WPLC\Settings\Changes;
use WPLC\Settings\Presets;
use WPLC\Settings\Registry;

final class SettingsToolsIntegrationTest extends WPLC_Integration_TestCase {

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
		add_filter( 'sanitize_option_' . Settings::OPTION, array( Settings::class, 'sanitize' ) );
		wp_set_current_user( $this->admin );
		reset_phpmailer_instance();
	}

	public function tear_down(): void {
		remove_all_filters( 'sanitize_option_' . Settings::OPTION );
		remove_all_filters( 'wplc_settings_country_profiles' );
		remove_all_filters( 'wplc_health_alert_recipients' );
		foreach ( array( $this->admin, $this->manager ) as $user ) {
			delete_transient( 'wplc_settings_pending_' . $user );
			delete_transient( 'wplc_settings_tool_result_' . $user );
		}
		unset( $_POST['option_page'] );
		$_POST = array();
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
	 * Audit actions of the settings, newest first.
	 *
	 * @return string[]
	 */
	private function audit_actions_of_settings(): array {
		global $wpdb;

		return array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT action FROM %i WHERE object_type = %s ORDER BY id DESC', Schema::table_names()['audit_log'], 'settings' ) ) );
	}

	private function post_tool( array $post ): array {
		$post['_wpnonce'] = wp_create_nonce( SettingsTools::NONCE_ACTION );

		return $this->run_admin_post( SettingsTools::ACTION, $post );
	}

	// ------------------------------------------------------------------ export / import

	/** SET-LIC-12: the export holds every exportable setting and nothing else. */
	public function test_export_holds_all_settings_and_no_secret(): void {
		update_option( Settings::OPTION, array( 'renewal_grace_days' => '21', 'sender_note' => 'Help desk' ) );
		$export = Changes::export();

		$this->assertSame( 1, $export['format'] );
		$this->assertSame( 'wp-license', $export['plugin'] );
		$this->assertSame( array_keys( Registry::definitions() ), array_keys( $export['settings'] ) );
		$this->assertSame( 21, $export['settings']['renewal_grace_days'] );
		$json = (string) wp_json_encode( $export );
		foreach ( array( 'WPLC_LICENSE_KEY_SECRET', 'whsec_', 'test-current-license-key', 'signing' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $json );
		}
	}

	/** SET-LIC-12: export, change, import: the round trip restores every value. */
	public function test_round_trip_restores_every_value(): void {
		update_option(
			Settings::OPTION,
			array(
				'renewal_grace_days'      => '21',
				'renewal_retry_days'      => '2,5',
				'package_hosts'           => "cdn.example.org\ndl.example.org",
				'withdrawal_consent_text' => 'Own wording.',
				'retention_audit_log'     => '400',
			)
		);
		$before = Registry::all();
		$json   = (string) wp_json_encode( Changes::export() );

		delete_option( Settings::OPTION );
		$this->assertSame( Registry::defaults(), Registry::all() );

		$parsed = Changes::parse_import( $json );
		$this->assertTrue( $parsed['ok'] );
		$result = Changes::apply( $parsed['values'], 'settings_imported', 'Test.' );

		$this->assertSame( $before, Registry::all() );
		$this->assertEqualsCanonicalizing( array( 'renewal_grace_days', 'renewal_retry_days', 'package_hosts', 'withdrawal_consent_text', 'retention_audit_log' ), $result['applied'] );
		$this->assertContains( 'settings_imported', $this->audit_actions_of_settings() );
		$this->assertSame( array(), Changes::apply( $parsed['values'], 'settings_imported', 'Test.' )['applied'], 'A second import changes nothing.' );
	}

	/** SET-LIC-12: files that are not an export, invalid values and unknown keys. */
	public function test_import_rejects_foreign_files_and_reports_invalid_rows(): void {
		$this->assertSame( 'not_an_export', Changes::parse_import( 'not json' )['error'] );
		$this->assertSame( 'not_an_export', Changes::parse_import( '{"settings":"x"}' )['error'] );
		$this->assertSame( 'other_plugin', Changes::parse_import( '{"plugin":"other","format":1,"settings":{}}' )['error'] );
		$this->assertSame( 'unknown_format', Changes::parse_import( '{"plugin":"wp-license","format":2,"settings":{}}' )['error'] );
		$this->assertSame( 'too_large', Changes::parse_import( str_repeat( ' ', 262145 ) )['error'] );

		$parsed = Changes::parse_import( '{"plugin":"wp-license","format":1,"settings":{"renewal_grace_days":999,"renewal_notice_days":9,"stripe_secret":"whsec_x","sender_note":null}}' );
		$this->assertTrue( $parsed['ok'] );
		$this->assertSame( array( 'stripe_secret', 'sender_note' ), $parsed['ignored'] );
		$states = wp_list_pluck( Changes::preview( $parsed['values'] ), 'state', 'key' );
		$this->assertSame( array( 'renewal_grace_days' => 'invalid', 'renewal_notice_days' => 'change' ), $states );

		$result = Changes::apply( $parsed['values'], 'settings_imported', 'Test.' );
		$this->assertSame( array( 'renewal_notice_days' ), $result['applied'] );
		$this->assertSame( array( 'renewal_grace_days' => 'invalid' ), $result['skipped'] );
		$this->assertSame( 14, Registry::int( 'renewal_grace_days' ) );
	}

	/** SET-LIC-12: a shop manager cannot import other trusted package hosts. */
	public function test_import_changes_package_hosts_only_for_an_administrator(): void {
		wp_set_current_user( $this->manager );
		$values = array( 'package_hosts' => array( 'evil.example.org' ), 'renewal_notice_days' => 9 );
		$this->assertSame( 'forbidden', wp_list_pluck( Changes::preview( $values ), 'state', 'key' )['package_hosts'] );
		$result = Changes::apply( $values, 'settings_imported', 'Test.' );
		$this->assertSame( array( 'renewal_notice_days' ), $result['applied'] );
		$this->assertSame( array(), Settings::get( 'package_hosts' ) );

		wp_set_current_user( $this->admin );
		$this->assertSame( array( 'package_hosts' ), Changes::apply( $values, 'settings_imported', 'Test.' )['applied'] );
		$this->assertSame( array( 'evil.example.org' ), Settings::get( 'package_hosts' ) );
	}

	// ------------------------------------------------------------------ admin flow

	/** The whole flow on the screen: prepare, preview, confirm, apply. Nothing changes before the last step. */
	public function test_import_on_the_screen_needs_preview_ticks_and_confirmation(): void {
		$json = '{"plugin":"wp-license","format":1,"settings":{"renewal_grace_days":21,"renewal_notice_days":9}}';

		$response = $this->post_tool( array( 'tool' => 'import', 'json' => $json ) );
		$this->assertSame( 'redirect', $response['outcome'] );
		$this->assertStringContainsString( 'page=' . Settings::PAGE, $response['location'] );
		$this->assertSame( 14, Registry::int( 'renewal_grace_days' ), 'Preparing a preview changes nothing.' );

		$html = $this->capture( array( SettingsTools::class, 'render' ) );
		$this->assertStringContainsString( 'name="keys[]" value="renewal_grace_days"', $html );
		$this->assertStringContainsString( 'name="confirm"', $html );

		$this->post_tool( array( 'tool' => 'apply', 'keys' => array( 'renewal_grace_days', 'renewal_notice_days' ) ) );
		$this->assertSame( 14, Registry::int( 'renewal_grace_days' ), 'Without the confirmation nothing changes.' );
		$this->assertNotNull( SettingsTools::pending(), 'The preview is still there.' );

		$this->post_tool( array( 'tool' => 'apply', 'keys' => array( 'renewal_grace_days' ), 'confirm' => '1' ) );
		$this->assertSame( 21, Registry::int( 'renewal_grace_days' ) );
		$this->assertSame( 7, Registry::int( 'renewal_notice_days' ), 'A row that was not ticked is not applied.' );
		$this->assertNull( SettingsTools::pending() );
	}

	/** SET-LIC-P4: the tools need the capability and a nonce. */
	public function test_tools_need_capability_and_nonce(): void {
		$customer = self::factory()->user->create( array( 'role' => 'customer' ) );
		wp_set_current_user( $customer );
		$response = $this->post_tool( array( 'tool' => 'reset', 'group' => 'all' ) );
		$this->assertSame( 'die', $response['outcome'] );
		$this->assertSame( 403, $response['status'] );

		wp_set_current_user( $this->admin );
		$response = $this->run_admin_post( SettingsTools::ACTION, array( 'tool' => 'reset', 'group' => 'all', '_wpnonce' => 'wrong' ) );
		$this->assertSame( 'die', $response['outcome'] );
		$this->assertNull( SettingsTools::pending() );
	}

	// ------------------------------------------------------------------ reset

	/** SET-LIC-14: reset per group; package hosts are never part of it. */
	public function test_reset_of_a_group_leaves_other_groups_and_the_package_hosts(): void {
		update_option(
			Settings::OPTION,
			array(
				'renewal_grace_days'  => '21',
				'renewal_notice_days' => '9',
				'package_hosts'       => 'cdn.example.org',
			)
		);
		$this->assertSame( '', SettingsTools::prepare_reset( 'renewals' ) );
		$pending = SettingsTools::pending();
		$this->assertSame( Registry::keys_of_group( 'renewals' ), array_keys( $pending['values'] ) );

		SettingsTools::apply_pending( array_keys( $pending['values'] ), true );
		$this->assertSame( 14, Registry::int( 'renewal_grace_days' ) );
		$this->assertSame( 9, Registry::int( 'renewal_notice_days' ), 'Another group is untouched.' );

		SettingsTools::prepare_reset( 'all' );
		$all = SettingsTools::pending();
		$this->assertArrayNotHasKey( 'package_hosts', $all['values'] );
		SettingsTools::apply_pending( array_keys( $all['values'] ), true );
		$this->assertSame( 7, Registry::int( 'renewal_notice_days' ) );
		$this->assertSame( array( 'cdn.example.org' ), Settings::get( 'package_hosts' ) );
		$this->assertContains( 'settings_reset', $this->audit_actions_of_settings() );
		$this->assertNotSame( '', SettingsTools::prepare_reset( 'nonsense' ) );
	}

	// ------------------------------------------------------------------ country profiles

	/** SET-LIC-08 / S8: five profiles, suggestions only, nothing invented, everything unchecked. */
	public function test_profiles_suggest_only_and_invent_no_value(): void {
		$profiles = Presets::all();
		$this->assertSame( Presets::KEYS, array_keys( $profiles ) );
		$this->assertSame( array( 'DE', 'AT', 'CH', 'EU', 'OTHER' ), array_keys( Presets::labels() ) );
		$legal = Presets::keys_with_legal_weight();
		$this->assertContains( 'retention_anonymize_licenses', $legal );
		$this->assertContains( 'withdrawal_consent_text', $legal );

		foreach ( $profiles as $code => $profile ) {
			$this->assertSame( '', $profile['reviewed'], "$code is unchecked until a legal review releases it." );
			$this->assertSame( $legal, array_keys( $profile['values'] ), "$code speaks about every setting with legal weight." );
			foreach ( $profile['values'] as $key => $value ) {
				if ( 'DE' === $code ) {
					$this->assertSame( Registry::defaults()[ $key ], $value, "DE suggests the built-in default of $key." );
				} else {
					$this->assertNull( $value, "$code/$key: please decide, no invented value." );
				}
			}
		}

		// On a fresh installation the German profile changes nothing; the others ask the shop to decide.
		$this->assertSame( array( 'same' ), array_values( array_unique( wp_list_pluck( Presets::suggestion( 'DE' ), 'state' ) ) ) );
		$this->assertSame( array( 'undecided' ), array_values( array_unique( wp_list_pluck( Presets::suggestion( 'AT' ), 'state' ) ) ) );
		$this->assertSame( array(), Presets::suggestion( 'XX' ) );
	}

	/** S8: a profile never overwrites silently: preview, rows unticked, confirmation. */
	public function test_a_profile_is_shown_before_and_after_and_applied_only_as_chosen(): void {
		update_option(
			Settings::OPTION,
			array(
				'retention_audit_log'          => '400',
				'retention_anonymize_licenses' => '0',
			)
		);
		$this->post_tool( array( 'tool' => 'preset', 'profile' => 'de' ) );
		$this->assertSame( 400, Registry::int( 'retention_audit_log' ), 'Choosing a profile changes nothing.' );

		$html = $this->capture( array( SettingsTools::class, 'render' ) );
		$this->assertStringContainsString( 'Unchecked: no value of this profile has been through a legal review', $html );
		$this->assertStringContainsString( 'name="keys[]" value="retention_audit_log"', $html );
		$this->assertStringNotContainsString( "checked='checked'", $html, 'No row of a profile is ticked in advance.' );
		$this->assertStringNotContainsString( 'name="keys[]" value="retention_consents"', $html, 'A row without a difference has no box.' );
		$this->assertMatchesRegularExpression( '/<td>400<\/td><td>730<\/td>/', $html, 'Before and after.' );

		$this->post_tool( array( 'tool' => 'apply', 'keys' => array( 'retention_audit_log' ), 'confirm' => '1' ) );
		$this->assertSame( 730, Registry::int( 'retention_audit_log' ) );
		$this->assertSame( 0, Registry::int( 'retention_anonymize_licenses' ), 'Not ticked, not changed.' );
		$this->assertContains( 'settings_preset_applied', $this->audit_actions_of_settings() );

		// A profile without values offers nothing to apply.
		$this->post_tool( array( 'tool' => 'preset', 'profile' => 'CH' ) );
		$html = $this->capture( array( SettingsTools::class, 'render' ) );
		$this->assertStringContainsString( 'please decide', $html );
		$this->assertStringNotContainsString( 'name="keys[]"', $html );
		$this->assertStringContainsString( 'There is nothing to apply.', $html );
	}

	/** A law firm can supply checked values; they are still validated and only suggested. */
	public function test_supplied_profile_values_are_validated(): void {
		add_filter(
			'wplc_settings_country_profiles',
			static function ( $profiles ) {
				$profiles['AT'] = array(
					'values'   => array(
						'retention_anonymize_licenses' => 2555,
						'retention_consents'           => 999999,
						'no_such_setting'              => 1,
					),
					'reviewed' => '2026-12-01',
				);
				return $profiles;
			}
		);
		$states = wp_list_pluck( Presets::suggestion( 'AT' ), 'state', 'key' );
		$this->assertSame( array( 'retention_anonymize_licenses' => 'change', 'retention_consents' => 'invalid' ), $states );
		$this->assertSame( '2026-12-01', Presets::all()['AT']['reviewed'] );
	}

	// ------------------------------------------------------------------ WP-CLI

	/** SET-LIC-13: the command uses the same checks; an invalid value changes nothing. */
	public function test_cli_set_validates_and_audits(): void {
		$result = SettingsCommand::change( array( 'renewal_grace_days' => '21' ), 'settings_changed', 'Test.' );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( array( 'renewal_grace_days: 14 -> 21' ), $result['lines'] );
		$this->assertSame( 21, Registry::int( 'renewal_grace_days' ) );
		$this->assertContains( 'settings_changed', $this->audit_actions_of_settings() );

		$bad = SettingsCommand::change( array( 'renewal_grace_days' => '0', 'renewal_notice_days' => '9' ), 'settings_changed', 'Test.' );
		$this->assertFalse( $bad['ok'] );
		$this->assertSame( 7, Registry::int( 'renewal_notice_days' ), 'One invalid value stops the whole change.' );
		$this->assertFalse( SettingsCommand::change( array( 'no_such_setting' => '1' ), 'settings_changed', 'Test.' )['ok'] );

		$rows = wp_list_pluck( SettingsCommand::rows(), 'value', 'key' );
		$this->assertSame( array_keys( Registry::definitions() ), array_keys( $rows ) );
		$this->assertSame( '21', $rows['renewal_grace_days'] );
		$this->assertCount( count( Registry::keys_of_group( 'legal' ) ), SettingsCommand::rows( 'legal' ) );
	}

	/** SET-LIC-13: without WordPress admin the option is still written in the checked form. */
	public function test_changes_are_valid_without_the_sanitize_callback_of_the_page(): void {
		remove_all_filters( 'sanitize_option_' . Settings::OPTION );
		SettingsCommand::change( array( 'renewal_retry_days' => ' 5 , 2 ' ), 'settings_changed', 'Test.' );
		$this->assertSame( '2,5', get_option( Settings::OPTION )['renewal_retry_days'] );
	}

	// ------------------------------------------------------------------ uninstall choice (S10)

	/** S10: default keep; delete needs an administrator and a confirmation. */
	public function test_uninstall_choice_defaults_to_keep_and_needs_a_confirmation(): void {
		$this->assertSame( 'keep', Registry::string( 'uninstall_data' ) );

		$_POST['option_page'] = Settings::OPTION;
		$GLOBALS['wp_settings_errors'] = array();
		update_option( Settings::OPTION, array( 'uninstall_data' => 'delete' ) );
		$this->assertSame( 'keep', Registry::string( 'uninstall_data' ), 'Without the confirmation the choice stays.' );
		$this->assertContains( 'wplc_uninstall_not_confirmed', wp_list_pluck( get_settings_errors( Settings::OPTION ), 'code' ) );

		wp_set_current_user( $this->manager );
		update_option( Settings::OPTION, array( 'uninstall_data' => 'delete', 'uninstall_data_confirm' => '1' ) );
		$this->assertSame( 'keep', Registry::string( 'uninstall_data' ), 'A shop manager cannot choose to delete.' );

		wp_set_current_user( $this->admin );
		update_option( Settings::OPTION, array( 'uninstall_data' => 'delete', 'uninstall_data_confirm' => '1' ) );
		$this->assertSame( 'delete', Registry::string( 'uninstall_data' ) );
		update_option( Settings::OPTION, array( 'uninstall_data' => 'keep' ) );
		$this->assertSame( 'keep', Registry::string( 'uninstall_data' ), 'Back to keep needs no confirmation.' );
		unset( $_POST['option_page'] );
		$GLOBALS['wp_settings_errors'] = array();

		$this->assertFalse( SettingsCommand::change( array( 'uninstall_data' => 'delete' ), 'settings_changed', 'Test.' )['ok'], 'WP-CLI needs --yes.' );
		$this->assertTrue( SettingsCommand::change( array( 'uninstall_data' => 'delete' ), 'settings_changed', 'Test.', true )['ok'] );
	}

	// ------------------------------------------------------------------ health alerts

	/** SET-LIC-22: no recipient, no email; one collected email per day. */
	public function test_health_alerts_are_mailed_once_a_day_to_the_recipients(): void {
		do_action( 'wplc/health_alert', 'failed_jobs', 3, 1, 'https://example.org/wp-admin/admin.php?page=x' );
		$this->assertFalse( HealthAlertMail::send() );
		$this->assertFalse( tests_retrieve_phpmailer_instance()->get_sent() );

		update_option( Settings::OPTION, array( 'health_alert_recipients' => 'ops@shop.example, second@shop.example' ) );
		$this->assertSame( array( 'ops@shop.example', 'second@shop.example' ), HealthAlertMail::recipients() );
		do_action( 'wplc/health_alert', 'failed_jobs', 3, 1, 'https://example.org/wp-admin/admin.php?page=x' );
		do_action( 'wplc/health_alert', 'rejected_webhooks', 7, 5, '' );
		$this->assertTrue( HealthAlertMail::send() );

		$mail = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertStringContainsString( 'failed_jobs: 3', $mail->body );
		$this->assertStringContainsString( 'rejected_webhooks: 7', $mail->body );
		$this->assertCount( 2, $mail->to );

		do_action( 'wplc/health_alert', 'failed_jobs', 4, 1, '' );
		$this->assertFalse( HealthAlertMail::send(), 'At most one email per day.' );
		do_action( 'wplc/health_alert', 'failed_jobs', 4, 1, '' );
		$this->assertTrue( HealthAlertMail::send( true ), 'A test message is always sent.' );

		$this->assertSame( 'not_an_email', Registry::validate( 'health_alert_recipients', 'ops@shop.example, nonsense' )['code'] );
	}
}
