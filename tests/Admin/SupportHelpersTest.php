<?php
/**
 * Pure helpers of the admin support screens.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\Admin\Capabilities;
use WPLC\Admin\LicenseAdmin;
use WPLC\Admin\LicenseDetail;
use WPLC\Admin\LicenseSearch;
use WPLC\Admin\Settings;
use WPLC\Admin\SupportActions;
use WPLC\License\LicenseKey;

require_once __DIR__ . '/wp-function-stubs.php';

final class SupportHelpersTest extends TestCase {

	/** B-137: the reason is limited on the server, not only by the maxlength attribute. */
	public function test_reason_is_trimmed_and_limited_to_the_column_length(): void {
		$this->assertSame( 'Goodwill', SupportActions::limit_reason( "  Goodwill \n" ) );
		$this->assertSame( '', SupportActions::limit_reason( "   \t" ) );
		$this->assertSame( 255, mb_strlen( SupportActions::limit_reason( str_repeat( 'ü', 400 ) ) ) );
		$this->assertSame( str_repeat( 'a', 255 ), SupportActions::limit_reason( str_repeat( 'a', 255 ) ) );
	}

	/** B-135: the audit log names the login and the ID, never the display name. */
	public function test_actor_label_uses_login_and_id(): void {
		$this->assertSame( 'clerk_anna (#17)', LicenseDetail::actor_label( 17, 'clerk_anna' ) );
		$this->assertSame( 'Deleted user #17', LicenseDetail::actor_label( 17, '' ) );
		$this->assertSame( 'System', LicenseDetail::actor_label( 0, '' ) );
		$this->assertSame( 'System', LicenseDetail::actor_label( 0, 'ignored' ) );
	}

	/** B-49: default mapping and the answers to the wplc_capability filter. */
	public function test_capability_defaults_and_filter_answers(): void {
		$defaults = Capabilities::defaults();
		foreach ( array( 'wplc_view_licenses', 'wplc_manage_licenses', 'wplc_grant_licenses', 'wplc_manage_catalog', 'wplc_view_diagnostics' ) as $cap ) {
			$this->assertSame( 'manage_woocommerce', $defaults[ $cap ], $cap );
		}
		$this->assertSame( 'manage_options', $defaults['wplc_publish_releases'] );
		$this->assertSame( 'manage_options', $defaults['wplc_manage_package_hosts'] );

		$this->assertSame( 'wplc_publish_releases', Capabilities::filter_capability( 'manage_woocommerce', 'publish_releases' ) );
		$this->assertSame( 'wplc_manage_catalog', Capabilities::filter_capability( 'manage_woocommerce', 'wplc_manage_catalog' ) );
		$this->assertSame( 'manage_woocommerce', Capabilities::filter_capability( 'manage_woocommerce', 'unknown' ) );
		$this->assertSame( 'manage_woocommerce', Capabilities::filter_capability( 'manage_woocommerce', '' ) );
		$this->assertSame( 'manage_woocommerce', Capabilities::filter_capability( 'manage_woocommerce', array( 'x' ) ) );
	}

	/** B-49: capabilities are derived per check; an explicit grant or denial on a role wins. */
	public function test_grant_defaults_resolves_only_the_requested_plugin_capabilities(): void {
		$manager = array( 'manage_woocommerce' => true );
		$this->assertTrue( Capabilities::grant_defaults( $manager, array( 'wplc_view_licenses' ) )['wplc_view_licenses'] );
		$this->assertFalse( Capabilities::grant_defaults( $manager, array( 'wplc_publish_releases' ) )['wplc_publish_releases'] );
		$this->assertSame( $manager, Capabilities::grant_defaults( $manager, array( 'edit_posts' ) ) );

		$admin = array(
			'manage_woocommerce' => true,
			'manage_options'     => true,
		);
		$this->assertTrue( Capabilities::grant_defaults( $admin, array( 'wplc_publish_releases' ) )['wplc_publish_releases'] );

		$denied = array(
			'manage_woocommerce'  => true,
			'wplc_grant_licenses' => false,
		);
		$this->assertFalse( Capabilities::grant_defaults( $denied, array( 'wplc_grant_licenses' ) )['wplc_grant_licenses'] );

		$support = array( 'wplc_view_licenses' => true );
		$this->assertTrue( Capabilities::grant_defaults( $support, array( 'wplc_view_licenses' ) )['wplc_view_licenses'] );
		$this->assertFalse( Capabilities::grant_defaults( $support, array( 'wplc_manage_licenses' ) )['wplc_manage_licenses'] );
	}

	/** A-61: exported cells cannot run as spreadsheet formulas. */
	public function test_csv_cells_are_quoted_and_formulas_neutralized(): void {
		$this->assertSame( "\"1\",\"WPLC-ABCD1234\",\"a\"\"b\"\r\n", LicenseAdmin::csv_line( array( 1, 'WPLC-ABCD1234', 'a"b' ) ) );
		$this->assertSame( "\"'=HYPERLINK(1)\",\"'@cmd\",\"'+x\",\"-12\"\r\n", LicenseAdmin::csv_line( array( '=HYPERLINK(1)', '@cmd', '+x', '-12' ) ) );
	}

	/** A-76: a UUID searches the license UUID and the installation ID. */
	public function test_uuid_is_classified_for_license_and_installation_search(): void {
		$this->assertSame(
			array(
				'type'  => LicenseSearch::TYPE_UUID,
				'value' => '0f8fad5b-d9cb-469f-a165-70867728950e',
			),
			LicenseSearch::classify( ' 0F8FAD5B-D9CB-469F-A165-70867728950E ' )
		);
		$this->assertSame( LicenseSearch::TYPE_TEXT, LicenseSearch::classify( '0f8fad5b-d9cb-469f-a165' )['type'] );
	}

	/** B-173: characters a mail client adds to a pasted key do not stop the admin search from recognizing it. */
	public function test_pasted_key_with_typographic_dashes_and_invisible_characters_is_recognized(): void {
		$key    = LicenseKey::for_uuid( '0f8fad5b-d9cb-469f-a165-70867728950e' );
		$pasted = "\xC2\xA0" . str_replace( '-', "\xE2\x80\x91", substr( $key, 0, 60 ) ) . "\xE2\x80\x8B\r\n" . str_replace( '-', "\xE2\x80\x93", substr( $key, 60 ) ) . "\xEF\xBB\xBF";

		$result = LicenseSearch::classify( $pasted );
		$this->assertSame( LicenseSearch::TYPE_LICENSE_KEY, $result['type'] );
		$this->assertSame( $key, $result['value'] );
		$this->assertSame( LicenseSearch::TYPE_EMAIL, LicenseSearch::classify( 'someone@example.com' )['type'] );
	}

	/** B-134: the fingerprint identifies the host list a form was rendered with. */
	public function test_hosts_fingerprint_changes_with_the_list(): void {
		$one = Settings::hosts_fingerprint( array( 'downloads.example.com' ) );
		$this->assertSame( 20, strlen( $one ) );
		$this->assertSame( $one, Settings::hosts_fingerprint( array( 'downloads.example.com' ) ) );
		$this->assertNotSame( $one, Settings::hosts_fingerprint( array( 'downloads.example.com', 'cdn.example.org' ) ) );
		$this->assertNotSame( $one, Settings::hosts_fingerprint( array() ) );
		$this->assertSame( Settings::hosts_fingerprint( array() ), Settings::hosts_fingerprint( 'not an array' ) );
	}

	/** B-134: a key that is missing from the input does not reset the others to something else. */
	public function test_sanitize_fills_missing_keys(): void {
		$clean = Settings::sanitize( array( 'reminder_days' => '10' ) );
		$this->assertSame( array( 'package_hosts', 'reminder_days', 'sender_note' ), array_keys( $clean ) );
		$this->assertSame( '10', $clean['reminder_days'] );
		$this->assertSame( array(), $clean['package_hosts'] );

		$with_token = Settings::sanitize(
			array(
				'package_hosts'      => 'cdn.example.org',
				'package_hosts_seen' => 'abc',
			)
		);
		$this->assertArrayNotHasKey( 'package_hosts_seen', $with_token );
	}
}
