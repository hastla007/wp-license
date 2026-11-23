<?php
/**
 * Settings for the sale, part 2 (SET-LIC-07, -11, -15, -22, -23, -27, -29, -37, -38): behaviour in WordPress.
 *
 * Combined renewal (SET-LIC-41), review deferral and upgrade offer (SET-LIC-42) are tested next to their
 * features (CombinedRenewalIntegrationTest, RenewalReviewIntegrationTest, PlanChangeIntegrationTest).
 *
 * @package WPLC
 */

use WPLC\Account\CustomerPortal;
use WPLC\Admin\AuditLogAdmin;
use WPLC\Admin\Diagnostics;
use WPLC\Admin\HealthAlertMail;
use WPLC\Admin\Settings;
use WPLC\Admin\SettingsTools;
use WPLC\Api\V1\ActivationService;
use WPLC\Api\V1\EntitlementService;
use WPLC\CLI\SettingsCommand;
use WPLC\Infrastructure\Database\Schema;
use WPLC\Infrastructure\Log;
use WPLC\Notifications\Notifications;
use WPLC\Settings\Changes;
use WPLC\Settings\Registry;

final class SalesSettingsIntegrationTest extends WPLC_Integration_TestCase {

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
		delete_option( Log::VERBOSE_UNTIL_OPTION );
		delete_option( HealthAlertMail::SENT_OPTION );
		add_filter( 'sanitize_option_' . Settings::OPTION, array( Settings::class, 'sanitize' ) );
		wp_set_current_user( $this->admin );
		$GLOBALS['wp_settings_errors'] = array();
		reset_phpmailer_instance();
	}

	public function tear_down(): void {
		remove_all_filters( 'sanitize_option_' . Settings::OPTION );
		foreach ( array( 'wplc_offline_cache_days', 'wplc_support_email', 'wplc_support_url', 'wplc_health_alert_thresholds', 'wplc_log_debug', 'wplc_new_activations_per_hour' ) as $hook ) {
			remove_all_filters( $hook );
		}
		foreach ( array( $this->admin, $this->manager ) as $user ) {
			delete_transient( 'wplc_settings_pending_' . $user );
			delete_transient( 'wplc_settings_tool_result_' . $user );
		}
		$_POST                         = array();
		$GLOBALS['wp_settings_errors'] = array();
		unset( $_GET['tab'] );
		delete_option( Settings::OPTION );
		delete_option( Log::VERBOSE_UNTIL_OPTION );
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

	// ------------------------------------------------------------------ SET-LIC-07

	/** SET-LIC-07: both settings of UEB-10/-11 travel through export, import and WP-CLI. */
	public function test_suspended_period_and_largest_quantity_are_in_export_import_and_cli(): void {
		$result = SettingsCommand::change( array( 'suspended_ends_after_days' => '400', 'max_quantity_per_line' => '25' ), 'settings_changed', 'Test.' );
		$this->assertTrue( $result['ok'] );
		$rows = wp_list_pluck( SettingsCommand::rows( 'rules' ), 'value', 'key' );
		$this->assertSame( '400', $rows['suspended_ends_after_days'] );
		$this->assertSame( '25', $rows['max_quantity_per_line'] );

		$export = Changes::export();
		$this->assertSame( 400, $export['settings']['suspended_ends_after_days'] );
		$this->assertSame( 25, $export['settings']['max_quantity_per_line'] );

		delete_option( Settings::OPTION );
		$parsed = Changes::parse_import( (string) wp_json_encode( $export ) );
		$this->assertSame( array(), $parsed['ignored'] );
		$applied = Changes::apply( $parsed['values'], 'settings_imported', 'Test.' )['applied'];
		$this->assertEqualsCanonicalizing( array( 'suspended_ends_after_days', 'max_quantity_per_line' ), $applied );
		$this->assertSame( 400, \WPLC\License\EndedLicenses::suspended_days() );
		$this->assertSame( 25, Registry::int( 'max_quantity_per_line' ) );
	}

	// ------------------------------------------------------------------ SET-LIC-11

	/** SET-LIC-11: the offline period comes from the setting, never reaches past the end of access, and the filter wins. */
	public function test_offline_period_follows_the_setting_and_ends_with_access(): void {
		$now     = time();
		$license = array( 'status' => 'active', 'paid_until' => gmdate( 'Y-m-d H:i:s', $now + 200 * DAY_IN_SECONDS ), 'renewal_enabled' => 0 );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $now + 14 * DAY_IN_SECONDS ), EntitlementService::access_fields( $license, $now )['cache_expires_at'], 'Default as before.' );

		update_option( Settings::OPTION, array( 'offline_cache_days' => '45' ) );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $now + 45 * DAY_IN_SECONDS ), EntitlementService::access_fields( $license, $now )['cache_expires_at'] );

		$short = array_merge( $license, array( 'paid_until' => gmdate( 'Y-m-d H:i:s', $now + 5 * DAY_IN_SECONDS ) ) );
		$this->assertSame( $short['paid_until'], EntitlementService::access_fields( $short, $now )['cache_expires_at'], 'Never beyond the end of access.' );

		add_filter(
			'wplc_offline_cache_days',
			static function () {
				return 2;
			}
		);
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $now + 2 * DAY_IN_SECONDS ), EntitlementService::access_fields( $license, $now )['cache_expires_at'], 'The filter wins.' );

		// A rule for every customer site: a shop manager cannot change it on the screen.
		wp_set_current_user( $this->manager );
		$_POST['option_page'] = Settings::OPTION;
		update_option( Settings::OPTION, array( 'offline_cache_days' => '90' ) );
		$this->assertSame( 45, Registry::int( 'offline_cache_days' ) );
	}

	// ------------------------------------------------------------------ SET-LIC-15

	/** SET-LIC-15: every way of changing settings lands under "Settings" on the audit page. */
	public function test_the_audit_page_filters_by_settings(): void {
		global $wpdb;
		$table = Schema::table_names()['audit_log'];
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$license = $this->seed_license();
		\WPLC\License\AuditLog::record( 'admin_status_change', 'license', (int) $license['id'], 'Test.', null, null, $this->admin );

		update_option( Settings::OPTION, array( 'renewal_notice_days' => '9' ) );
		SettingsCommand::change( array( 'renewal_grace_days' => '21' ), 'settings_changed', 'Test.' );
		Changes::apply( array( 'sender_note' => 'Help' ), 'settings_imported', 'Test.' );
		SettingsTools::prepare_reset( 'emails' );
		SettingsTools::apply_pending( array( 'renewal_notice_days' ), true );

		$where = AuditLogAdmin::where( array( 'object_type' => 'settings', 'object_id' => '', 'action' => '', 'actor' => '', 'from' => '', 'to' => '' ) );
		$sql   = "SELECT action FROM {$table}{$where['sql']} ORDER BY id ASC";
		$this->assertSame( array( 'settings_updated', 'settings_updated', 'settings_changed', 'settings_updated', 'settings_imported', 'settings_updated', 'settings_reset' ), $wpdb->get_col( $wpdb->prepare( $sql, $where['params'] ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		wp_set_current_user( $this->manager );
		$_GET     = array( 'page' => AuditLogAdmin::PAGE, 'object_type' => 'settings' );
		$_REQUEST = $_GET;
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		set_current_screen( 'woocommerce_page_' . AuditLogAdmin::PAGE );
		$html = $this->capture( array( AuditLogAdmin::class, 'render' ) );
		$_GET = array();
		$this->assertStringContainsString( '<option value="settings" selected=\'selected\'>Settings</option>', $html );
		$this->assertStringContainsString( '<code>settings_reset</code>', $html );
		$this->assertStringNotContainsString( '<code>admin_status_change</code>', $html );

		// The settings page links to exactly this view.
		$tools = $this->capture( array( SettingsTools::class, 'render' ) );
		$this->assertStringContainsString( esc_url( AuditLogAdmin::url( array( 'object_type' => 'settings' ) ) ), $tools );
	}

	// ------------------------------------------------------------------ SET-LIC-22

	/** SET-LIC-22: thresholds per figure are settings; 0 silences a figure; the filter wins. */
	public function test_alert_thresholds_are_settings_and_the_filter_wins(): void {
		$this->assertSame( \WPLC\Settings\Definitions::ALERT_THRESHOLDS, Diagnostics::thresholds() );
		update_option( Settings::OPTION, array( 'alert_failed_jobs' => '0', 'alert_pending_webhooks' => '3' ) );
		$thresholds = Diagnostics::thresholds();
		$this->assertArrayNotHasKey( 'failed_jobs', $thresholds, '0 = never alert.' );
		$this->assertSame( 3, $thresholds['pending_webhooks'] );

		$alerts = array();
		$listen = static function ( $metric, $value ) use ( &$alerts ) {
			$alerts[] = $metric . ':' . $value;
		};
		add_action( 'wplc/health_alert', $listen, 10, 2 );
		Diagnostics::emit_alert( 'pending_webhooks', 2 );
		Diagnostics::emit_alert( 'pending_webhooks', 3 );
		Diagnostics::emit_alert( 'failed_jobs', 50 );
		$this->assertSame( array( 'pending_webhooks:3' ), $alerts, 'The hook is unchanged; only the thresholds come from the settings.' );

		add_filter(
			'wplc_health_alert_thresholds',
			static function ( $thresholds ) {
				$thresholds['failed_jobs'] = 1;
				return $thresholds;
			}
		);
		Diagnostics::emit_alert( 'failed_jobs', 1 );
		remove_action( 'wplc/health_alert', $listen, 10 );
		$this->assertSame( array( 'pending_webhooks:3', 'failed_jobs:1' ), $alerts, 'The filter wins.' );
	}

	/** SET-LIC-22: the test button on the settings page needs capability and nonce and reports the result. */
	public function test_the_settings_page_sends_a_test_alert(): void {
		$html = $this->capture( array( SettingsTools::class, 'render' ) );
		$this->assertStringContainsString( 'name="tool" value="test_alert"', $html );

		$post = array( 'tool' => 'test_alert', '_wpnonce' => wp_create_nonce( SettingsTools::NONCE_ACTION ) );
		$this->run_admin_post( SettingsTools::ACTION, $post );
		$this->assertFalse( tests_retrieve_phpmailer_instance()->get_sent(), 'No recipient, no email.' );
		$this->assertStringContainsString( 'No test message was sent', $this->capture( array( SettingsTools::class, 'render' ) ) );

		update_option( Settings::OPTION, array( 'health_alert_recipients' => 'ops@shop.example' ) );
		update_option( HealthAlertMail::SENT_OPTION, gmdate( 'Y-m-d' ) );
		$this->run_admin_post( SettingsTools::ACTION, $post );
		$mail = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertNotFalse( $mail, 'A test is sent although an alert email left today.' );
		$this->assertStringContainsString( 'test_message', $mail->body );
		$this->assertSame( 'ops@shop.example', $mail->to[0][0] );
		$this->assertStringContainsString( 'The test message was handed over', $this->capture( array( SettingsTools::class, 'render' ) ) );

		reset_phpmailer_instance();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );
		$this->assertSame( 403, $this->run_admin_post( SettingsTools::ACTION, $post )['status'] );
		wp_set_current_user( $this->admin );
		$this->assertSame( 'die', $this->run_admin_post( SettingsTools::ACTION, array( 'tool' => 'test_alert', '_wpnonce' => 'wrong' ) )['outcome'] );
		$this->assertFalse( tests_retrieve_phpmailer_instance()->get_sent() );
	}

	// ------------------------------------------------------------------ SET-LIC-23

	/** SET-LIC-23: support address and page; empty = the shop sender as before; the filters win. */
	public function test_support_contact_comes_from_the_settings(): void {
		update_option( 'woocommerce_email_from_address', 'shop@shop.example' );
		$this->assertSame( 'shop@shop.example', Notifications::support_email(), 'Empty setting: the shop sender as before.' );
		$this->assertSame( '', Notifications::support_url() );

		update_option( Settings::OPTION, array( 'support_email' => 'help@shop.example', 'support_url' => 'https://shop.example/support' ) );
		$this->assertSame( 'help@shop.example', Notifications::support_email() );
		$this->assertSame( 'https://shop.example/support', Notifications::support_url() );
		$this->assertStringContainsString( 'help@shop.example', CustomerPortal::status_help( array( 'status' => 'suspended' ), false, false ) );

		add_filter(
			'wplc_support_email',
			static function () {
				return '';
			}
		);
		$this->assertStringContainsString( 'https://shop.example/support', CustomerPortal::status_help( array( 'status' => 'suspended' ), false, false ), 'Without an address the page is named.' );
		add_filter(
			'wplc_support_url',
			static function () {
				return 'javascript:alert(1)';
			}
		);
		$this->assertSame( '', Notifications::support_url(), 'Only http(s) addresses.' );
	}

	// ------------------------------------------------------------------ SET-LIC-27

	/** SET-LIC-27: new sites per hour from the setting (0 = no limit); technical limits on the diagnostics page. */
	public function test_new_activations_per_hour_and_technical_limits(): void {
		$license = $this->seed_license();
		$this->assertSame( 10, ActivationService::new_activations_per_hour( $license, 1 ) );
		update_option( Settings::OPTION, array( 'new_activations_per_hour' => '25' ) );
		$this->assertSame( 25, ActivationService::new_activations_per_hour( $license, 1 ) );
		$this->assertSame( 40, ActivationService::new_activations_per_hour( $license, 20 ) );
		update_option( Settings::OPTION, array( 'new_activations_per_hour' => '0' ) );
		$this->assertSame( 0, ActivationService::new_activations_per_hour( $license, 20 ) );

		$rows = wp_list_pluck( Diagnostics::rows(), 'value', 'label' );
		$this->assertArrayHasKey( 'Technical limits of the license API', $rows );
		$this->assertStringContainsString( '120 requests per installation and minute', $rows['Technical limits of the license API'] );
		$this->assertStringContainsString( '300 seconds', $rows['Technical limits of the license API'] );
	}

	// ------------------------------------------------------------------ SET-LIC-29

	/** SET-LIC-29: the verbose log switches itself off; the constant and the filter keep winning. */
	public function test_verbose_log_runs_out(): void {
		$this->assertFalse( Log::debug_enabled() );
		SettingsCommand::change( array( 'debug_log' => '24h' ), 'settings_changed', 'Test.' );
		$until = (int) get_option( Log::VERBOSE_UNTIL_OPTION );
		$this->assertEqualsWithDelta( time() + DAY_IN_SECONDS, $until, 5 );
		$this->assertTrue( Log::debug_enabled() );
		$this->assertTrue( Log::write( 'debug', 'Verbose test line.' ) );

		SettingsCommand::change( array( 'debug_log' => '7d' ), 'settings_changed', 'Test.' );
		$this->assertEqualsWithDelta( time() + 7 * DAY_IN_SECONDS, (int) get_option( Log::VERBOSE_UNTIL_OPTION ), 5 );

		// The time runs out: no more debug lines, and the hourly job switches the setting off.
		update_option( Log::VERBOSE_UNTIL_OPTION, time() - 1 );
		$this->assertFalse( Log::debug_enabled() );
		Settings::register_settings();
		$this->assertStringContainsString( 'The time has run out', $this->capture( array( Settings::class, 'render_debug_log_field' ) ) );
		Settings::expire_debug_log();
		$this->assertSame( 'off', Registry::string( 'debug_log' ) );
		$this->assertFalse( get_option( Log::VERBOSE_UNTIL_OPTION ) );
		global $wpdb;
		$this->assertStringContainsString( 'ran out', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT reason FROM %i WHERE object_type = %s ORDER BY id DESC LIMIT 1', Schema::table_names()['audit_log'], 'settings' ) ) );

		add_filter( 'wplc_log_debug', '__return_true' );
		$this->assertTrue( Log::debug_enabled(), 'The filter wins.' );
		$this->assertArrayNotHasKey( 'debug_log', Changes::export()['settings'], 'Not exported.' );
	}

	// ------------------------------------------------------------------ SET-LIC-37, -38

	/** SET-LIC-37: one number field per reminder; dropped entries are reported. */
	public function test_reminder_days_are_number_fields_and_dropped_values_are_reported(): void {
		Settings::register_settings();
		$field = $this->capture( array( Settings::class, 'render_reminder_days_field' ) );
		$this->assertSame( 6, substr_count( $field, 'type="number" id="wplc_reminder_days_' ) );
		$this->assertStringContainsString( 'name="wplc_settings[reminder_days][0]" value="30"', $field );
		$this->assertStringContainsString( 'name="wplc_settings[reminder_days][1]" value="7"', $field );
		$this->assertStringContainsString( 'name="wplc_settings[reminder_days][2]" value=""', $field );

		$_POST['option_page'] = Settings::OPTION;
		update_option( Settings::OPTION, array( 'reminder_days' => array( '14', '7', 'abc', '0', '', '' ) ) );
		$this->assertSame( '14,7', Registry::get( 'reminder_days' ) );
		$errors = wp_list_pluck( get_settings_errors( Settings::OPTION ), 'message', 'code' );
		$this->assertArrayHasKey( 'wplc_discarded_reminder_days', $errors );
		$this->assertStringContainsString( 'not kept: abc, 0', $errors['wplc_discarded_reminder_days'] );

		$GLOBALS['wp_settings_errors'] = array();
		update_option( Settings::OPTION, array( 'reminder_days' => array( '60', '', '3', '', '', '' ) ) );
		$this->assertSame( '60,3', Registry::get( 'reminder_days' ) );
		$this->assertArrayNotHasKey( 'wplc_discarded_reminder_days', wp_list_pluck( get_settings_errors( Settings::OPTION ), 'message', 'code' ), 'Empty fields are not reported.' );
	}

	/** SET-LIC-38: the status table writes the state out. */
	public function test_status_table_writes_the_state_out(): void {
		require_once ABSPATH . 'wp-admin/includes/template.php';
		set_current_screen( 'woocommerce_page_wplc-settings' );
		Settings::register_settings();
		$page = $this->capture( array( Settings::class, 'render' ) );
		$this->assertMatchesRegularExpression( '/dashicons-yes-alt[^>]*><\/span><span class="screen-reader-text">OK:<\/span> /', $page );
		$this->assertMatchesRegularExpression( '/dashicons-warning[^>]*><\/span><span class="screen-reader-text">Attention:<\/span> /', $page );
	}
}
