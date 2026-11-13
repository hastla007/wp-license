<?php
/**
 * Admin result notices: allowlist and redirect target validation.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\Admin\AdminNotices;
use WPLC\Admin\LicenseDetail;

require_once __DIR__ . '/wp-function-stubs.php';

final class AdminNoticesTest extends TestCase {

	private const ADMIN = 'https://shop.example/wp-admin/admin.php';

	public function test_only_allowlisted_codes_have_a_message(): void {
		$this->assertSame( 'success', AdminNotices::message_for( 'status_changed' )[0] );
		$this->assertSame( 'error', AdminNotices::message_for( 'status_not_applied' )[0] );
		$this->assertNull( AdminNotices::message_for( 'unknown_code' ) );
		$this->assertNull( AdminNotices::message_for( '<script>alert(1)</script>' ) );
		$this->assertNull( AdminNotices::message_for( '' ) );
	}

	public function test_every_message_is_typed_and_non_empty(): void {
		foreach ( AdminNotices::messages() as $code => $message ) {
			$this->assertMatchesRegularExpression( '/^[a-z_]+$/', $code );
			$this->assertContains( $message[0], array( 'success', 'error' ), $code );
			$this->assertNotSame( '', $message[1], $code );
		}
	}

	public function test_plugin_screens_are_valid_redirect_targets(): void {
		$this->assertTrue( AdminNotices::is_plugin_screen_url( 'https://shop.example/wp-admin/admin.php?page=wplc-licenses&license=12', self::ADMIN ) );
		$this->assertTrue( AdminNotices::is_plugin_screen_url( '/wp-admin/admin.php?page=wplc-licenses&view=add', self::ADMIN ) );
		$this->assertTrue( AdminNotices::is_plugin_screen_url( '/wp-admin/admin.php?page=wplc-settings', self::ADMIN ) );
	}

	/**
	 * @dataProvider foreign_urls
	 */
	public function test_other_urls_are_not_redirect_targets( string $url ): void {
		$this->assertFalse( AdminNotices::is_plugin_screen_url( $url, self::ADMIN ) );
	}

	public function foreign_urls(): array {
		return array(
			'other host'        => array( 'https://evil.example/wp-admin/admin.php?page=wplc-licenses' ),
			'other admin page'  => array( 'https://shop.example/wp-admin/admin.php?page=wc-orders' ),
			'other admin file'  => array( 'https://shop.example/wp-admin/edit.php?page=wplc-licenses' ),
			'front end'         => array( 'https://shop.example/my-account/?page=wplc-licenses' ),
			'no page'           => array( 'https://shop.example/wp-admin/admin.php' ),
			'page as array'     => array( '/wp-admin/admin.php?page[]=wplc-licenses' ),
			'page with markup'  => array( '/wp-admin/admin.php?page=wplc-<script>' ),
			'empty'             => array( '' ),
		);
	}

	public function test_subdirectory_installs_compare_the_full_admin_path(): void {
		$admin = 'https://example.com/shop/wp-admin/admin.php';

		$this->assertTrue( AdminNotices::is_plugin_screen_url( '/shop/wp-admin/admin.php?page=wplc-licenses', $admin ) );
		$this->assertFalse( AdminNotices::is_plugin_screen_url( '/wp-admin/admin.php?page=wplc-licenses', $admin ) );
	}

	public function test_audit_change_summary_shows_only_changed_fields(): void {
		$this->assertSame(
			'status: active → suspended',
			LicenseDetail::change_summary( '{"status":"active","paid_until":"2027-01-01 00:00:00"}', '{"status":"suspended","paid_until":"2027-01-01 00:00:00"}' )
		);
		$this->assertSame(
			'cancel_at_period_end: 0 → 1; status: active',
			LicenseDetail::change_summary( '{"cancel_at_period_end":0}', '{"status":"active","cancel_at_period_end":1}' )
		);
		$this->assertSame( '—', LicenseDetail::change_summary( null, null ) );
		$this->assertSame( '—', LicenseDetail::change_summary( 'not json', '[]' ) );
	}
}
