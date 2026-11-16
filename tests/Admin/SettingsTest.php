<?php
/**
 * Settings sanitization.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\Admin\Settings;

require_once __DIR__ . '/wp-function-stubs.php';

final class SettingsTest extends TestCase {

	public function test_hosts_are_split_normalized_and_deduplicated(): void {
		$result = Settings::parse_hosts( "Downloads.Example.com\r\n\r\n cdn.example.org \ndownloads.example.com\nxn--bcher-kva.example" );

		$this->assertSame( array( 'downloads.example.com', 'cdn.example.org', 'xn--bcher-kva.example' ), $result['valid'] );
		$this->assertSame( array(), $result['invalid'] );
	}

	/**
	 * @dataProvider invalid_hosts
	 */
	public function test_invalid_hosts_are_rejected( string $host ): void {
		$result = Settings::parse_hosts( $host );

		$this->assertSame( array(), $result['valid'] );
		$this->assertCount( 1, $result['invalid'] );
	}

	public function invalid_hosts(): array {
		return array(
			'url with scheme'  => array( 'https://downloads.example.com' ),
			'path'             => array( 'downloads.example.com/releases' ),
			'port'             => array( 'downloads.example.com:8443' ),
			'wildcard'         => array( '*.example.com' ),
			'ip address'       => array( '203.0.113.10' ),
			'single label'     => array( 'localhost' ),
			'credentials'      => array( 'user@example.com' ),
			'leading hyphen'   => array( '-bad.example.com' ),
			'trailing dot dot' => array( 'bad..example.com' ),
			'space inside'     => array( 'bad host.example.com' ),
			'too long label'   => array( str_repeat( 'a', 64 ) . '.example.com' ),
		);
	}

	public function test_reminder_days_are_normalized(): void {
		$this->assertSame( '30,7', Settings::sanitize_reminder_days( '30,7' ) );
		$this->assertSame( '30,14,7', Settings::sanitize_reminder_days( ' 7; 30 ,14,7 ' ) );
		$this->assertSame( '60,1', Settings::sanitize_reminder_days( "1\n60" ) );
	}

	public function test_invalid_reminder_days_fall_back_to_the_default(): void {
		$this->assertSame( '30,7', Settings::sanitize_reminder_days( '' ) );
		$this->assertSame( '30,7', Settings::sanitize_reminder_days( 'soon, -3, 0, 366, 2.5' ) );
		$this->assertSame( '10', Settings::sanitize_reminder_days( '10, 9999, abc' ) );
	}

	public function test_sanitize_never_keeps_unknown_keys_or_markup(): void {
		$clean = Settings::sanitize(
			array(
				'package_hosts' => "cdn.example.org\nhttps://evil.example/x",
				'reminder_days' => '14',
				'sender_note'   => '<b>Support</b>: help',
				'stripe_secret' => 'whsec_should_never_be_stored',
			)
		);

		$this->assertSame( array( 'package_hosts', 'reminder_days', 'renewal_notice_days', 'price_change_notice_days', 'catalog_auto_import', 'sender_note' ), array_keys( $clean ) );
		// PROD-03: on by default, off only when the form says so.
		$this->assertSame( 1, $clean['catalog_auto_import'] );
		$this->assertSame( 0, Settings::sanitize( array( 'catalog_auto_import' => '0' ) )['catalog_auto_import'] );
		$this->assertSame( 1, Settings::sanitize( array( 'catalog_auto_import' => '1' ) )['catalog_auto_import'] );
		$this->assertSame( 60, $clean['price_change_notice_days'], 'INH-05: the default notice period.' );
		// The floor of 30 days cannot be undercut in the settings, the ceiling is a year.
		$this->assertSame( 30, Settings::sanitize( array( 'price_change_notice_days' => '5' ) )['price_change_notice_days'] );
		$this->assertSame( 365, Settings::sanitize( array( 'price_change_notice_days' => '9999' ) )['price_change_notice_days'] );
		$this->assertSame( 90, Settings::sanitize( array( 'price_change_notice_days' => '90' ) )['price_change_notice_days'] );
		$this->assertSame( array( 'cdn.example.org' ), $clean['package_hosts'] );
		$this->assertSame( '14', $clean['reminder_days'] );
		$this->assertStringNotContainsString( '<', $clean['sender_note'] );
	}

	public function test_sanitize_is_idempotent_for_already_stored_values(): void {
		$once  = Settings::sanitize( array( 'package_hosts' => "cdn.example.org\ndl.example.org", 'reminder_days' => '30,7', 'sender_note' => 'x' ) );
		$twice = Settings::sanitize( $once );

		$this->assertSame( $once, $twice );
		$this->assertSame( Settings::defaults()['reminder_days'], Settings::sanitize( 'not an array' )['reminder_days'] );
	}

	public function test_audit_diff_lists_changed_keys_and_only_safe_values(): void {
		$old = array( 'package_hosts' => array( 'a.example.com' ), 'reminder_days' => '30,7', 'sender_note' => 'Old note' );
		$new = array( 'package_hosts' => array( 'a.example.com', 'b.example.com' ), 'reminder_days' => '30,7', 'sender_note' => 'New note' );

		$diff = Settings::settings_diff( $old, $new );

		$this->assertSame( array( 'package_hosts', 'sender_note' ), $diff['changed'] );
		$this->assertSame( array( 'package_hosts' => array( 'a.example.com' ) ), $diff['before'] );
		$this->assertSame( array( 'package_hosts' => array( 'a.example.com', 'b.example.com' ) ), $diff['after'] );
		$this->assertStringNotContainsString( 'note', (string) json_encode( $diff['before'] + $diff['after'] ) );
	}

	public function test_audit_diff_treats_missing_keys_as_defaults(): void {
		$this->assertSame( array(), Settings::settings_diff( array(), Settings::defaults() )['changed'] );
		$this->assertSame( array( 'reminder_days' ), Settings::settings_diff( array(), array( 'reminder_days' => '14' ) )['changed'] );
	}

	/** PROD-09: the onboarding steps of a new vendor, in the order they are needed. */
	public function test_setup_steps_follow_the_onboarding_order_of_a_new_vendor(): void {
		$steps = Settings::setup_steps_for( array() );

		$this->assertSame( array( 'secrets', 'public_key', 'catalog', 'product', 'release', 'test', 'webhook' ), array_column( $steps, 'key' ) );
		$this->assertSame( Settings::SETUP_STEPS, array_column( $steps, 'key' ) );
		$this->assertSame( array_fill( 0, 7, false ), array_column( $steps, 'done' ), 'An empty state has every step open.' );
		foreach ( $steps as $step ) {
			$this->assertNotSame( '', $step['title'], $step['key'] );
			$this->assertNotSame( '', $step['text'], $step['key'] );
			$this->assertSame( '' === $step['link'], '' === $step['link_text'], $step['key'] );
			$this->assertStringNotContainsString( '://', $step['link'], 'Links are relative to wp-admin/.' );
		}
	}

	/** PROD-09: each step is done exactly when its flag is set; the order of finishing does not matter. */
	public function test_each_setup_step_is_done_only_by_its_own_state(): void {
		$state = array();
		foreach ( array_reverse( Settings::SETUP_STEPS ) as $key ) {
			$state[ $key ] = true;
			$done          = array();
			foreach ( Settings::setup_steps_for( $state ) as $step ) {
				if ( $step['done'] ) {
					$done[] = $step['key'];
				}
			}
			$this->assertEqualsCanonicalizing( array_keys( $state ), $done );
		}
		$this->assertSame( array( false ), array_values( array_unique( array_column( Settings::setup_steps_for( array( 'catalog' => false, 'unknown' => true ) ), 'done' ) ) ) );
	}

	/** PROD-09: the steps link to the screen where the work is done and name what the vendor configures. */
	public function test_setup_steps_link_to_the_right_screens(): void {
		$steps = Settings::setup_steps_for( array() );
		$links = array_column( $steps, 'link', 'key' );

		$this->assertSame( 'admin.php?page=wplc-catalog-files', $links['catalog'] );
		$this->assertSame( 'edit.php?post_type=product', $links['product'] );
		$this->assertSame( 'admin.php?page=wplc-releases', $links['release'] );
		$this->assertSame( 'admin.php?page=wplc-licenses', $links['test'] );
		$this->assertSame( 'admin.php?page=wplc-diagnostics', $links['public_key'] );
		$texts = implode( ' ', array_column( $steps, 'text' ) );
		$this->assertStringContainsString( 'WPLC_RESPONSE_SIGNING_KEY', $texts );
		$this->assertStringContainsString( 'wplc-catalog.json', $texts );
		$this->assertStringContainsString( 'WPLC_STRIPE_WEBHOOK_SECRET', $texts );
	}
}
