<?php
/**
 * P-07: every admin handler checks the graded wplc_* capabilities, never a
 * hard-coded manage_woocommerce.
 *
 * @package WPLC
 */

use WPLC\Admin\AuditLogAdmin;
use WPLC\Admin\Capabilities;
use WPLC\Admin\LicenseBulkActions;
use WPLC\Admin\LicenseListTable;
use WPLC\Admin\RenewalReviewAdmin;
use WPLC\Billing\RenewalReview;

final class AdminCapabilitiesIntegrationTest extends WPLC_Integration_TestCase {

	/**
	 * Roles created by a test.
	 *
	 * @var string[]
	 */
	private $roles = array();

	public function tear_down(): void {
		foreach ( $this->roles as $role ) {
			remove_role( $role );
		}
		$this->roles = array();
		$_GET        = array();
		$_REQUEST    = array();
		parent::tear_down();
	}

	/**
	 * Create a role and a user with it, and make the user current.
	 *
	 * @param array<string,bool> $caps Capabilities (false = explicitly denied).
	 * @return int User ID.
	 */
	private function user_with( array $caps ): int {
		$role = 'wplc_p07_' . count( $this->roles ) . '_' . strtolower( wp_generate_password( 4, false ) );
		add_role( $role, 'P-07 test role', $caps );
		$this->roles[] = $role;
		$user          = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $user );

		return $user;
	}

	/**
	 * Shop manager whose role denies some plugin capabilities.
	 *
	 * @param string[] $denied Plugin capabilities stored as denied.
	 * @return int User ID.
	 */
	private function restricted_manager( array $denied ): int {
		$caps = get_role( 'shop_manager' )->capabilities;
		foreach ( $denied as $cap ) {
			$caps[ $cap ] = false;
		}

		return $this->user_with( $caps );
	}

	/**
	 * Support role from docs/SUPPORT-ACTIONS.md, without manage_woocommerce.
	 *
	 * @param array<string,bool> $extra Further capabilities.
	 * @return int User ID.
	 */
	private function support_user( array $extra = array() ): int {
		return $this->user_with(
			array_merge(
				array(
					'read'                 => true,
					'wplc_view_licenses'   => true,
					'wplc_manage_licenses' => true,
				),
				$extra
			)
		);
	}

	/**
	 * Output of a callback.
	 *
	 * @param callable $callback Renderer.
	 * @return string
	 */
	private function output( callable $callback ): string {
		ob_start();
		$callback();
		return (string) ob_get_clean();
	}

	/**
	 * POST a bulk action.
	 *
	 * @param int $license_id License ID.
	 * @return array{outcome:string,status:int,message:string,location:string}
	 */
	private function bulk_revoke( int $license_id ): array {
		return $this->run_admin_post(
			LicenseBulkActions::ACTION,
			array(
				'bulk_action' => 'revoke',
				'bulk_reason' => 'P-07',
				'bulk_token'  => LicenseBulkActions::new_token(),
				'license_ids' => array( (string) $license_id ),
				'_wpnonce'    => wp_create_nonce( LicenseBulkActions::ACTION ),
			)
		);
	}

	public function test_bulk_actions_respect_a_denied_manage_capability(): void {
		$license = $this->seed_license();
		$this->restricted_manager( array( Capabilities::MANAGE_LICENSES ) );
		$this->assertTrue( current_user_can( 'manage_woocommerce' ) );

		$result = $this->bulk_revoke( $license['id'] );
		$this->assertSame( 'die', $result['outcome'] );
		$this->assertSame( 403, $result['status'] );
		$this->assertSame( 'active', $this->license( $license['id'] )['status'], 'A read-only support user must not revoke in bulk.' );

		$this->assertSame( '', $this->output( array( LicenseBulkActions::class, 'render_controls' ) ) );
		$this->assertSame( '', $this->output( static function () { LicenseBulkActions::render_form( array() ); } ) );
		if ( ! class_exists( 'WP_List_Table' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		}
		set_current_screen( 'woocommerce_page_wplc-licenses' );
		$this->assertArrayNotHasKey( 'cb', ( new LicenseListTable() )->get_columns() );
	}

	public function test_support_role_without_shop_access_runs_bulk_actions_and_search(): void {
		$license = $this->seed_license();
		$this->support_user();
		$this->assertFalse( current_user_can( 'manage_woocommerce' ) );

		$this->assertStringContainsString( 'id="wplc-bulk-form"', $this->output( static function () { LicenseBulkActions::render_form( array() ); } ) );
		$result = $this->bulk_revoke( $license['id'] );
		$this->assertSame( 'redirect', $result['outcome'], $result['message'] );
		$this->assertStringContainsString( 'bulk_done', $result['location'] );
		$this->assertSame( 'revoked', $this->license( $license['id'] )['status'] );

		$search = $this->run_admin_post(
			'wplc_admin_license_search',
			array(
				's'        => 'example',
				'_wpnonce' => wp_create_nonce( 'wplc_admin_license_search' ),
			)
		);
		$this->assertSame( 'redirect', $search['outcome'], 'A user who may view licenses may search them.' );
	}

	public function test_search_needs_the_view_capability(): void {
		$this->restricted_manager( array( Capabilities::VIEW_LICENSES ) );
		$search = $this->run_admin_post(
			'wplc_admin_license_search',
			array(
				's'        => 'example',
				'_wpnonce' => wp_create_nonce( 'wplc_admin_license_search' ),
			)
		);
		$this->assertSame( 'die', $search['outcome'] );
		$this->assertSame( 403, $search['status'] );
	}

	public function test_booking_a_review_needs_the_grant_capability(): void {
		$this->support_user();
		$this->assertTrue( RenewalReviewAdmin::may_run( RenewalReview::ACTION_CLOSE ) );
		$this->assertFalse( RenewalReviewAdmin::may_run( RenewalReview::ACTION_BOOK ), 'Booking extends the license: it needs wplc_grant_licenses.' );

		$renewal_id = 424242;
		$book       = $this->run_admin_post(
			'wplc_admin_renewal_review',
			array(
				'renewal_id'    => $renewal_id,
				'review_action' => RenewalReview::ACTION_BOOK,
				'reason'        => 'Paid',
				'_wpnonce'      => wp_create_nonce( RenewalReviewAdmin::nonce_action( $renewal_id ) ),
			)
		);
		$this->assertSame( 'die', $book['outcome'] );
		$this->assertSame( 403, $book['status'] );

		// The review screen itself is open to the support role.
		$html = $this->output( array( RenewalReviewAdmin::class, 'render' ) );
		$this->assertStringContainsString( 'Renewals to review', $html );

		$this->support_user( array( Capabilities::GRANT_LICENSES => true ) );
		$this->assertTrue( RenewalReviewAdmin::may_run( RenewalReview::ACTION_BOOK ) );
	}

	public function test_review_actions_respect_a_denied_manage_capability(): void {
		$this->restricted_manager( array( Capabilities::MANAGE_LICENSES ) );
		$renewal_id = 434343;
		$close      = $this->run_admin_post(
			'wplc_admin_renewal_review',
			array(
				'renewal_id'    => $renewal_id,
				'review_action' => RenewalReview::ACTION_CLOSE,
				'reason'        => 'Refunded',
				'_wpnonce'      => wp_create_nonce( RenewalReviewAdmin::nonce_action( $renewal_id ) ),
			)
		);
		$this->assertSame( 'die', $close['outcome'] );
		$this->assertSame( 403, $close['status'] );
		$this->expectException( WPDieException::class );
		RenewalReviewAdmin::render();
	}

	public function test_audit_log_and_diagnostics_follow_the_diagnostics_capability(): void {
		$this->restricted_manager( array( Capabilities::VIEW_DIAGNOSTICS ) );
		$recheck = $this->run_admin_post( 'wplc_storage_recheck', array( '_wpnonce' => wp_create_nonce( 'wplc_storage_recheck' ) ) );
		$this->assertSame( 'die', $recheck['outcome'] );
		$this->assertSame( 403, $recheck['status'] );
		try {
			AuditLogAdmin::render();
			$this->fail( 'The audit log must not open without wplc_view_diagnostics.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 403, (int) $e->getCode() );
		}

		// A support role with the diagnostics capability but without shop access.
		$this->support_user( array( Capabilities::VIEW_DIAGNOSTICS => true ) );
		$recheck = $this->run_admin_post( 'wplc_storage_recheck', array( '_wpnonce' => wp_create_nonce( 'wplc_storage_recheck' ) ) );
		$this->assertSame( 'redirect', $recheck['outcome'] );
		set_current_screen( 'woocommerce_page_' . AuditLogAdmin::PAGE );
		$_GET = array( 'page' => AuditLogAdmin::PAGE );
		$html = $this->output( array( AuditLogAdmin::class, 'render' ) );
		$this->assertStringContainsString( 'License audit log', $html );
	}
}
