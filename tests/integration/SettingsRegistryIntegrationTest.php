<?php
/**
 * Settings for the sale (SET-LIC): the register behind the settings page, in WordPress.
 *
 * @package WPLC
 */

use WPLC\Admin\Settings;
use WPLC\Infrastructure\Database\Schema;
use WPLC\Settings\Effective;
use WPLC\Settings\Registry;

final class SettingsRegistryIntegrationTest extends WPLC_Integration_TestCase {

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
		add_filter( 'sanitize_option_' . Settings::OPTION, array( Settings::class, 'sanitize' ) );
		$GLOBALS['wp_settings_errors'] = array();
	}

	public function tear_down(): void {
		remove_all_filters( 'sanitize_option_' . Settings::OPTION );
		unset( $_POST['option_page'], $_GET['tab'] );
		$GLOBALS['wp_settings_errors'] = array();
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
	 * Latest audit entry of the settings.
	 *
	 * @return array<string,mixed>
	 */
	private function last_audit(): array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE action = %s ORDER BY id DESC LIMIT 1', Schema::table_names()['audit_log'], 'settings_updated' ), ARRAY_A );

		return is_array( $row ) ? $row : array();
	}

	/** SET-LIC-01: an installation that never saved the settings behaves as before. */
	public function test_an_installation_without_stored_settings_reads_the_defaults(): void {
		$this->assertFalse( get_option( Settings::OPTION, false ) );
		$this->assertSame( Registry::defaults(), Registry::all() );
		foreach ( Registry::defaults() as $key => $default ) {
			$this->assertSame( $default, Settings::get( $key ), $key );
		}
		$this->assertFalse( get_option( Settings::OPTION, false ), 'Reading never writes.' );
	}

	/** SET-LIC-01: unknown keys in the option are ignored; a value of the wrong kind falls back when read. */
	public function test_a_hand_edited_option_cannot_inject_keys_or_break_typed_readers(): void {
		remove_all_filters( 'sanitize_option_' . Settings::OPTION );
		update_option(
			Settings::OPTION,
			array(
				'renewal_notice_days' => 'many',
				'stripe_secret'       => 'whsec_x',
				'reminder_days'       => array( 'x' ),
			)
		);

		$this->assertArrayNotHasKey( 'stripe_secret', Registry::all() );
		$this->assertSame( 7, Registry::int( 'renewal_notice_days' ) );
		$this->assertSame( array( 30, 7 ), Registry::list( 'reminder_days' ) );
		$this->assertSame( 7, \WPLC\Notifications\Notifications::notice_days() );
	}

	/** SET-LIC-01: the form reports a value it cannot accept and keeps what is stored. */
	public function test_the_form_reports_a_rejected_value_and_keeps_the_stored_one(): void {
		wp_set_current_user( $this->manager );
		update_option( Settings::OPTION, array( 'renewal_notice_days' => '9' ) );
		$_POST['option_page'] = Settings::OPTION;

		update_option(
			Settings::OPTION,
			array(
				'renewal_notice_days'   => 'soon',
				'max_quantity_per_line' => '40',
			)
		);

		$this->assertSame( 9, Settings::get( 'renewal_notice_days' ) );
		$this->assertSame( 40, Settings::get( 'max_quantity_per_line' ) );
		$codes = wp_list_pluck( get_settings_errors( Settings::OPTION ), 'code' );
		$this->assertContains( 'wplc_invalid_renewal_notice_days', $codes );
		$this->assertNotContains( 'wplc_invalid_max_quantity_per_line', $codes );
	}

	/** SET-LIC-15: the change log holds the old and the new value of every changed setting. */
	public function test_a_save_is_logged_with_old_and_new_values(): void {
		wp_set_current_user( $this->admin );
		update_option(
			Settings::OPTION,
			array(
				'renewal_notice_days' => '9',
				'sender_note'         => 'Questions? Use the help desk.',
			)
		);
		$entry = $this->last_audit();

		$this->assertSame( $this->admin, (int) $entry['actor_user_id'] );
		$this->assertSame( 'Changed: renewal_notice_days, sender_note', $entry['reason'] );
		$this->assertSame( array( 'renewal_notice_days' => 7, 'sender_note' => '' ), json_decode( (string) $entry['before_json'], true ) );
		$this->assertSame( array( 'renewal_notice_days' => 9, 'sender_note' => 'Questions? Use the help desk.' ), json_decode( (string) $entry['after_json'], true ) );

		$before = (int) $entry['id'];
		update_option( Settings::OPTION, array( 'renewal_notice_days' => '9' ) );
		$this->assertSame( $before, (int) $this->last_audit()['id'], 'A save without a change writes nothing.' );

		// The audit log never stores an email address in clear text, also not one from a setting.
		update_option( Settings::OPTION, array( 'sender_note' => 'Write to help@shop.example' ) );
		$this->assertStringNotContainsString( 'help@shop.example', (string) $this->last_audit()['after_json'] );
	}

	/** SET-LIC-32: tabs; a tab shows and posts only the fields of its group. */
	public function test_tabs_show_one_group_and_all_settings_show_every_field(): void {
		wp_set_current_user( $this->admin );
		Settings::register_settings();

		$all = $this->capture( array( Settings::class, 'render' ) );
		foreach ( array_keys( Registry::definitions() ) as $key ) {
			$this->assertStringContainsString( 'id="wplc_' . $key . '"', $all, $key );
			$this->assertStringContainsString( 'id="wplc_' . $key . '_help"', $all, "$key has a help text on the page." );
		}
		$this->assertStringContainsString( 'nav-tab-wrapper', $all );

		$_GET['tab'] = 'emails';
		$tab         = $this->capture( array( Settings::class, 'render' ) );
		foreach ( Registry::definitions() as $key => $definition ) {
			$needle = 'id="wplc_' . $key . '"';
			if ( 'emails' === $definition['group'] ) {
				$this->assertStringContainsString( $needle, $tab, $key );
			} else {
				$this->assertStringNotContainsString( $needle, $tab, $key );
			}
		}
		$this->assertSame( 'emails', Settings::current_tab() );
		$_GET['tab'] = 'no-such-tab';
		$this->assertSame( '', Settings::current_tab() );
	}

	/** SET-LIC-32: the page says when code of the shop changes a stored value. */
	public function test_the_page_says_when_a_filter_overrides_a_setting(): void {
		wp_set_current_user( $this->admin );
		update_option( Settings::OPTION, array( 'suspended_ends_after_days' => '30' ) );
		$this->assertFalse( Effective::is_overridden( 'suspended_ends_after_days' ) );

		$filter = static function () {
			return 90;
		};
		add_filter( 'wplc_suspended_license_ends_after_days', $filter );
		$this->assertTrue( Effective::is_overridden( 'suspended_ends_after_days' ) );
		$this->assertSame( 90, Effective::value( 'suspended_ends_after_days' ) );
		$this->assertSame( 30, Settings::get( 'suspended_ends_after_days' ), 'The stored value is untouched.' );
		remove_filter( 'wplc_suspended_license_ends_after_days', $filter );
	}
}
