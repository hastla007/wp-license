<?php
/**
 * T-102: bulk actions and new filters of the admin license list.
 *
 * @package WPLC
 */

use WPLC\Admin\LicenseBulkActions;
use WPLC\Admin\LicenseListTable;
use WPLC\Admin\LicenseSearchStore;

final class LicenseBulkActionsIntegrationTest extends WPLC_Integration_TestCase {

	/**
	 * Shop manager running the actions.
	 *
	 * @var int
	 */
	private $manager;

	public function set_up(): void {
		parent::set_up();
		$this->manager = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		wp_set_current_user( $this->manager );
		LicenseSearchStore::reset_cache();
	}

	/**
	 * POST a batch.
	 *
	 * @param string              $action Bulk action.
	 * @param int[]               $ids    License IDs.
	 * @param string              $reason Reason.
	 * @param string              $token  Batch token.
	 * @param array<string,mixed> $extra  Further fields.
	 * @return array{outcome:string,status:int,message:string,location:string}
	 */
	private function bulk( string $action, array $ids, string $reason, string $token, array $extra = array() ): array {
		return $this->run_admin_post(
			LicenseBulkActions::ACTION,
			array_merge(
				array(
					'bulk_action' => $action,
					'bulk_reason' => $reason,
					'bulk_token'  => $token,
					'license_ids' => array_map( 'strval', $ids ),
					'_wpnonce'    => wp_create_nonce( LicenseBulkActions::ACTION ),
				),
				$extra
			)
		);
	}

	/**
	 * Query args of a URL.
	 *
	 * @param string $url URL.
	 * @return array<string,string>
	 */
	private function query( string $url ): array {
		$query = array();
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		return $query;
	}

	/**
	 * Audit actions of a license, oldest first.
	 *
	 * @param int $license_id License ID.
	 * @return string[]
	 */
	private function audit_actions( int $license_id ): array {
		global $wpdb;
		return $wpdb->get_col( $wpdb->prepare( "SELECT action FROM {$this->tables()['audit_log']} WHERE object_type = 'license' AND object_id = %d ORDER BY id", $license_id ) );
	}

	/**
	 * IDs listed by the list table for the given GET args.
	 *
	 * @param array<string,string> $args GET args.
	 * @return int[]
	 */
	private function listed_ids( array $args ): array {
		$_GET     = array_merge( array( 'page' => 'wplc-licenses' ), $args );
		$_REQUEST = $_GET;
		if ( ! function_exists( 'set_current_screen' ) ) {
			require_once ABSPATH . 'wp-admin/includes/screen.php';
		}
		if ( ! class_exists( 'WP_Screen' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		}
		if ( ! class_exists( 'WP_List_Table' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		}
		set_current_screen( 'woocommerce_page_wplc-licenses' );
		$table = new LicenseListTable();
		$table->prepare_items();
		$ids = array_map( 'intval', wp_list_pluck( $table->items, 'id' ) );
		sort( $ids );
		return $ids;
	}

	public function test_suspend_applies_where_allowed_and_reports_every_skip(): void {
		$active    = $this->seed_license();
		$past_due  = $this->seed_license( array( 'status' => 'past_due' ) );
		$suspended = $this->seed_license( array( 'status' => 'suspended' ) );
		$revoked   = $this->seed_license( array( 'status' => 'revoked' ) );
		$token     = LicenseBulkActions::new_token();

		$result = $this->bulk( 'suspend', array( $active['id'], $past_due['id'], $suspended['id'], $revoked['id'], 999999 ), 'Fraud wave', $token, array( 'return_status' => 'active', 'return_paged' => '2' ) );

		$this->assertSame( 'redirect', $result['outcome'], $result['message'] );
		$query = $this->query( $result['location'] );
		$this->assertSame( 'bulk_done', $query['wplc_notice'] );
		$this->assertSame( $token, $query[ LicenseBulkActions::QUERY_ARG ] );
		$this->assertSame( 'active', $query['status'] );
		$this->assertSame( '2', $query['paged'] );

		$this->assertSame( 'suspended', $this->license( $active['id'] )['status'] );
		$this->assertSame( 'suspended', $this->license( $past_due['id'] )['status'] );
		$this->assertSame( 'revoked', $this->license( $revoked['id'] )['status'] );
		$this->assertSame( 'Fraud wave', $this->license( $active['id'] )['status_reason'] );
		$this->assertContains( 'admin_status_change', $this->audit_actions( $active['id'] ) );
		$this->assertContains( 'admin_status_change', $this->audit_actions( $past_due['id'] ) );
		$this->assertNotContains( 'admin_status_change', $this->audit_actions( $suspended['id'] ) );
		$this->assertNotContains( 'admin_status_change', $this->audit_actions( $revoked['id'] ) );

		$stored = LicenseBulkActions::load_result( $this->manager, $token );
		$this->assertNotNull( $stored );
		$this->assertSame( array( $active['id'], $past_due['id'] ), $stored['done'] );
		$codes = array();
		foreach ( $stored['skipped'] as $row ) {
			$codes[ $row['id'] ] = $row['code'];
		}
		$this->assertSame(
			array(
				$suspended['id'] => 'same_status',
				$revoked['id']   => 'transition_not_allowed',
				999999           => 'not_found',
			),
			$codes
		);

		// The result box lists both counts and every skip with its reason.
		ob_start();
		LicenseBulkActions::render_result( $this->manager, $token );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( '2 done, 3 skipped.', $html );
		$this->assertStringContainsString( 'data-wplc-skip="transition_not_allowed"', $html );
		$this->assertStringContainsString( 'The license already has this status.', $html );

		// One summary entry for the batch (system-wide, object ID 0).
		global $wpdb;
		$summary = $wpdb->get_row( "SELECT * FROM {$this->tables()['audit_log']} WHERE action = 'admin_bulk_action' ORDER BY id DESC LIMIT 1", ARRAY_A );
		$this->assertSame( '0', (string) $summary['object_id'] );
		$this->assertSame( (string) $this->manager, (string) $summary['actor_user_id'] );
		$this->assertStringContainsString( '"bulk_action":"suspend"', (string) $summary['after_json'] );

		// Another user cannot read the result.
		$this->assertNull( LicenseBulkActions::load_result( self::factory()->user->create( array( 'role' => 'shop_manager' ) ), $token ) );
	}

	public function test_reactivate_respects_the_paid_period_and_revoke_is_final(): void {
		$suspended = $this->seed_license( array( 'status' => 'suspended' ) );
		$ended     = $this->seed_license(
			array(
				'status'     => 'suspended',
				'paid_until' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
			)
		);
		$active    = $this->seed_license();

		$result = $this->bulk( 'reactivate', array( $suspended['id'], $ended['id'], $active['id'] ), 'Cleared', LicenseBulkActions::new_token() );
		$this->assertSame( 'redirect', $result['outcome'] );
		$this->assertSame( 'active', $this->license( $suspended['id'] )['status'] );
		$this->assertSame( 'suspended', $this->license( $ended['id'] )['status'] );

		$token  = LicenseBulkActions::new_token();
		$this->bulk( 'revoke', array( $suspended['id'], $active['id'] ), 'Chargeback', $token );
		$this->assertSame( 'revoked', $this->license( $suspended['id'] )['status'] );
		$this->assertSame( 'revoked', $this->license( $active['id'] )['status'] );
		$this->assertSame( '0', (string) $this->license( $active['id'] )['renewal_enabled'] );

		$again = LicenseBulkActions::new_token();
		$this->bulk( 'reactivate', array( $active['id'] ), 'Undo', $again );
		$stored = LicenseBulkActions::load_result( $this->manager, $again );
		$this->assertSame( array(), $stored['done'] );
		$this->assertSame( 'transition_not_allowed', $stored['skipped'][0]['code'] );
		$this->assertSame( 'revoked', $stored['skipped'][0]['status'] );

		// Paid period ended is reported as such.
		$first = LicenseBulkActions::new_token();
		$this->bulk( 'reactivate', array( $ended['id'] ), 'Retry', $first );
		$this->assertSame( 'paid_period_ended', LicenseBulkActions::load_result( $this->manager, $first )['skipped'][0]['code'] );
	}

	public function test_a_double_submit_runs_the_batch_once(): void {
		$license = $this->seed_license();
		$token   = LicenseBulkActions::new_token();

		$first = $this->bulk( 'suspend', array( $license['id'] ), 'Abuse', $token );
		$this->assertSame( 'bulk_done', $this->query( $first['location'] )['wplc_notice'] );
		$audit_after_first = count( $this->audit_actions( $license['id'] ) );

		// The administrator reactivates by hand; the resubmitted batch must not suspend again.
		$this->assertTrue( \WPLC\License\LicenseLifecycle::admin_set_status( $license['id'], 'active', 'Mistake', $this->manager ) );
		$second = $this->bulk( 'suspend', array( $license['id'] ), 'Abuse', $token );

		$this->assertSame( 'redirect', $second['outcome'] );
		$query = $this->query( $second['location'] );
		$this->assertSame( 'bulk_already_done', $query['wplc_notice'] );
		$this->assertSame( $token, $query[ LicenseBulkActions::QUERY_ARG ] );
		$this->assertSame( 'active', $this->license( $license['id'] )['status'] );
		$this->assertCount( $audit_after_first + 1, $this->audit_actions( $license['id'] ) );
		$this->assertSame( array( $license['id'] ), LicenseBulkActions::load_result( $this->manager, $token )['done'] );
	}

	public function test_a_batch_whose_lock_is_held_is_not_run(): void {
		global $wpdb;
		$license = $this->seed_license();
		$token   = LicenseBulkActions::new_token();
		$lock    = 'wplc_lbulk_' . substr( hash( 'sha256', \WPLC\Infrastructure\Database\Schema::lock_scope() . '|bulk:' . $this->manager . ':' . $token ), 0, 48 );

		// A second connection holds the batch lock (a concurrent first submit).
		$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$this->assertSame( '1', (string) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) );
		try {
			$result = $this->bulk( 'suspend', array( $license['id'] ), 'Abuse', $token );
		} finally {
			$other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
			$other->close();
		}

		$this->assertSame( 'bulk_in_progress', $this->query( $result['location'] )['wplc_notice'] );
		$this->assertSame( 'active', $this->license( $license['id'] )['status'] );
	}

	public function test_release_sites_releases_live_and_staging_activations(): void {
		$license = $this->seed_license( array(), array( 'sites.live_limit' => 3 ) );
		$live    = $this->seed_activation( $license['id'] );
		$staging = $this->seed_activation(
			$license['id'],
			array(
				'environment'       => 'staging',
				'staging_parent_id' => $live['id'],
			)
		);
		$second  = $this->seed_activation( $license['id'] );
		$empty   = $this->seed_license();
		$token   = LicenseBulkActions::new_token();

		$this->bulk( 'release_sites', array( $license['id'], $empty['id'] ), 'Migration', $token );

		foreach ( array( $live, $staging, $second ) as $activation ) {
			$this->assertSame( 'deactivated', $this->activation( $activation['id'] )['status'] );
		}
		$stored = LicenseBulkActions::load_result( $this->manager, $token );
		$this->assertSame( array( $license['id'] ), $stored['done'] );
		$this->assertSame( 'no_active_sites', $stored['skipped'][0]['code'] );
		$this->assertContains( LicenseBulkActions::AUDIT_SITES_RELEASED, $this->audit_actions( $license['id'] ) );
		$this->assertSame( 'active', $this->license( $license['id'] )['status'] );

		global $wpdb;
		$released = $wpdb->get_col( $wpdb->prepare( "SELECT action FROM {$this->tables()['audit_log']} WHERE object_type = 'activation' AND object_id = %d", $live['id'] ) );
		$this->assertSame( array( 'admin_site_released' ), $released );
	}

	public function test_input_nonce_and_capability_are_enforced(): void {
		$license = $this->seed_license();

		$missing = $this->bulk( 'suspend', array( $license['id'] ), '', LicenseBulkActions::new_token() );
		$this->assertSame( 'bulk_missing_input', $this->query( $missing['location'] )['wplc_notice'] );
		$unknown = $this->bulk( 'extend', array( $license['id'] ), 'x', LicenseBulkActions::new_token() );
		$this->assertSame( 'bulk_missing_input', $this->query( $unknown['location'] )['wplc_notice'] );
		$too_many = $this->bulk( 'suspend', range( 1, LicenseBulkActions::MAX_LICENSES + 1 ), 'x', LicenseBulkActions::new_token() );
		$this->assertSame( 'bulk_too_many', $this->query( $too_many['location'] )['wplc_notice'] );

		$bad_nonce = $this->bulk( 'suspend', array( $license['id'] ), 'x', LicenseBulkActions::new_token(), array( '_wpnonce' => 'nope' ) );
		$this->assertSame( 'die', $bad_nonce['outcome'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );
		$denied = $this->bulk( 'suspend', array( $license['id'] ), 'x', LicenseBulkActions::new_token() );
		$this->assertSame( 'die', $denied['outcome'] );
		$this->assertSame( 403, $denied['status'] );
		$this->assertSame( 'active', $this->license( $license['id'] )['status'] );
	}

	public function test_renewal_mode_expiry_and_renewal_filters(): void {
		global $wpdb;
		$tables  = $this->tables();
		$soon    = gmdate( 'Y-m-d H:i:s', time() + 10 * DAY_IN_SECONDS );
		$later   = gmdate( 'Y-m-d H:i:s', time() + 90 * DAY_IN_SECONDS );
		$past    = gmdate( 'Y-m-d H:i:s', time() - 5 * DAY_IN_SECONDS );
		$auto    = $this->seed_license( array( 'renewal_enabled' => 1, 'paid_until' => $soon ) );
		$manual  = $this->seed_license( array( 'renewal_enabled' => 0, 'paid_until' => $later ) );
		$cancel  = $this->seed_license( array( 'renewal_enabled' => 1, 'cancel_at_period_end' => 1, 'paid_until' => $later ) );
		$expired = $this->seed_license( array( 'status' => 'expired', 'paid_until' => $past ) );
		$overdue = $this->seed_license( array( 'status' => 'past_due', 'renewal_enabled' => 1, 'paid_until' => $past ) );
		$revoked = $this->seed_license( array( 'status' => 'revoked', 'paid_until' => $soon ) );

		$renewal = static function ( int $license_id, string $status ) use ( $wpdb, $tables ): void {
			$now = gmdate( 'Y-m-d H:i:s' );
			$wpdb->insert(
				$tables['renewals'],
				array(
					'license_id'      => $license_id,
					'period_start'    => $now,
					'period_end'      => $now,
					'idempotency_key' => hash( 'sha256', wp_generate_uuid4() ),
					'status'          => $status,
					'created_at'      => $now,
					'updated_at'      => $now,
				)
			);
		};
		$renewal( $auto['id'], 'review_required' );
		$renewal( $overdue['id'], 'failed' );
		$renewal( $manual['id'], 'paid' );

		$this->assertSame( array( $auto['id'], $overdue['id'] ), $this->listed_ids( array( 'mode' => 'auto' ) ) );
		$this->assertSame( array( $manual['id'], $expired['id'], $revoked['id'] ), $this->listed_ids( array( 'mode' => 'manual' ) ) );
		$this->assertSame( array( $cancel['id'] ), $this->listed_ids( array( 'mode' => 'cancelling' ) ) );
		$this->assertSame( array( $auto['id'] ), $this->listed_ids( array( 'expiry' => 'soon' ) ) );
		$this->assertSame( array( $expired['id'], $overdue['id'] ), $this->listed_ids( array( 'expiry' => 'expired' ) ) );
		$this->assertSame( array( $auto['id'] ), $this->listed_ids( array( 'renewal' => 'review_required' ) ) );
		$this->assertSame( array( $overdue['id'] ), $this->listed_ids( array( 'renewal' => 'failed' ) ) );
		$this->assertSame( array( $overdue['id'] ), $this->listed_ids( array( 'renewal' => 'past_due_open' ) ) );
		$this->assertSame( array(), $this->listed_ids( array( 'renewal' => 'reconciling' ) ) );
		// Combined with the status filter; unknown values are ignored.
		$this->assertSame( array( $overdue['id'] ), $this->listed_ids( array( 'mode' => 'auto', 'status' => 'past_due' ) ) );
		$this->assertCount( 6, $this->listed_ids( array( 'mode' => 'bogus', 'expiry' => 'x', 'renewal' => 'y' ) ) );
	}

	public function test_source_order_filter_finds_the_licenses_of_an_order(): void {
		$product = $this->create_license_product();
		$order   = $this->create_paid_order( array( array( $product, 2 ) ) );
		$ids     = array_map( 'intval', wp_list_pluck( $this->licenses_for_order( $order->get_id() ), 'id' ) );
		$this->assertCount( 2, $ids );
		$this->seed_license();
		// A deleted order still matches through the stored column.
		$orphan = $this->seed_license( array( 'source_order_id' => 987654 ) );

		sort( $ids );
		$this->assertSame( $ids, $this->listed_ids( array( 'order_id' => (string) $order->get_id() ) ) );
		$this->assertSame( array( $orphan['id'] ), $this->listed_ids( array( 'order_id' => '987654' ) ) );
	}

	public function test_search_keeps_the_new_filters_and_the_list_renders_bulk_controls(): void {
		$this->seed_license( array( 'renewal_enabled' => 1 ) );
		$result = $this->run_admin_post(
			'wplc_admin_license_search',
			array(
				's'        => 'test-product',
				'mode'     => 'auto',
				'expiry'   => 'soon',
				'renewal'  => 'failed',
				'order_id' => '12',
				'_wpnonce' => wp_create_nonce( 'wplc_admin_license_search' ),
			)
		);
		$query = $this->query( $result['location'] );
		$this->assertSame( array( 'auto', 'soon', 'failed', '12' ), array( $query['mode'], $query['expiry'], $query['renewal'], $query['order_id'] ) );

		$_GET     = array( 'page' => 'wplc-licenses', 'mode' => 'auto' );
		$_REQUEST = $_GET;
		if ( ! function_exists( 'set_current_screen' ) ) {
			require_once ABSPATH . 'wp-admin/includes/screen.php';
		}
		if ( ! class_exists( 'WP_List_Table' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		}
		set_current_screen( 'woocommerce_page_wplc-licenses' );
		ob_start();
		\WPLC\Admin\LicenseAdmin::render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'id="wplc-bulk-form"', $html );
		$this->assertStringContainsString( 'name="license_ids[]"', $html );
		$this->assertStringContainsString( 'name="return_mode" value="auto"', $html );
		$this->assertMatchesRegularExpression( '/name="bulk_token" value="[0-9a-f]{24}"/', $html );
		$this->assertStringContainsString( '<option value="auto" selected', $html );
	}
}
