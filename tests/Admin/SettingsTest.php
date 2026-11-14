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

		$this->assertSame( array( 'package_hosts', 'reminder_days', 'renewal_notice_days', 'sender_note' ), array_keys( $clean ) );
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
}
