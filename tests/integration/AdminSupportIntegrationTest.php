<?php
/**
 * Admin support actions, graded capabilities and multi-admin protection.
 *
 * Covers A-51, A-60, A-61, A-76, B-46 (warning), B-47 (restore), B-49, B-50 (admin side),
 * B-65, B-119, B-133, B-134, B-135, B-137 and B-139.
 *
 * @package WPLC
 */

use WPLC\Admin\AuditLogAdmin;
use WPLC\Admin\CsvExport;
use WPLC\Admin\Capabilities;
use WPLC\Admin\LicenseAdmin;
use WPLC\Admin\LicenseSearchStore;
use WPLC\Admin\LicenseListTable;
use WPLC\Admin\Settings;
use WPLC\Admin\SupportActions;
use WPLC\Billing\ManualRenewal;
use WPLC\License\LicenseKey;

final class AdminSupportIntegrationTest extends WPLC_Integration_TestCase {

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

	/**
	 * Customer.
	 *
	 * @var int
	 */
	private $customer;

	public function set_up(): void {
		parent::set_up();
		$this->admin    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->manager  = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		$this->customer = self::factory()->user->create( array( 'role' => 'customer' ) );
		delete_option( Settings::OPTION );
	}

	public function tear_down(): void {
		remove_role( 'wplc_support_test' );
		remove_all_filters( 'sanitize_option_' . Settings::OPTION );
		parent::tear_down();
	}

	/**
	 * Audit rows of one object, oldest first.
	 *
	 * @param string $type Object type.
	 * @param int    $id   Object ID.
	 * @return array<int,array<string,mixed>>
	 */
	private function audit_rows( string $type, int $id ): array {
		global $wpdb;
		$tables = $this->tables();

		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tables['audit_log']} WHERE object_type = %s AND object_id = %d ORDER BY id ASC", $type, $id ), ARRAY_A );
	}

	/**
	 * Render the license detail screen as the current user.
	 *
	 * @param int $license_id License ID.
	 * @return string
	 */
	private function render_detail( int $license_id ): string {
		set_current_screen( 'woocommerce_page_wplc-licenses' );
		$_GET = array(
			'page'    => 'wplc-licenses',
			'license' => (string) $license_id,
		);
		ob_start();
		try {
			LicenseAdmin::render();
		} finally {
			$html = (string) ob_get_clean();
			$_GET = array();
		}

		return $html;
	}

	/**
	 * POST the "change term" form.
	 *
	 * @param int         $license_id License ID.
	 * @param string      $date       New local end date.
	 * @param string|null $expected   Expected paid_until, null to omit the field.
	 * @param string|null $nonce      Nonce, created for the current user when null.
	 * @return array<string,mixed>
	 */
	private function post_extend( int $license_id, string $date, ?string $expected, ?string $nonce = null ): array {
		$post = array(
			'license_id' => $license_id,
			'paid_until' => $date,
			'reason'     => 'Goodwill',
			'_wpnonce'   => null === $nonce ? wp_create_nonce( 'wplc_admin_extend_license_' . $license_id ) : $nonce,
		);
		if ( null !== $expected ) {
			$post['expected_paid_until'] = $expected;
		}

		return $this->run_admin_post( 'wplc_admin_extend_license', $post );
	}

	// ---------------------------------------------------------------------
	// B-49: graded capabilities.
	// ---------------------------------------------------------------------

	public function test_b49_default_mapping_keeps_access_except_releases_hosts_and_restore(): void {
		$shop = array( 'view_licenses', 'manage_licenses', 'grant_licenses', 'manage_catalog', 'view_diagnostics', 'manage_settings' );
		$top  = array( 'publish_releases', 'manage_package_hosts', 'restore_licenses' );

		wp_set_current_user( $this->manager );
		foreach ( $shop as $slug ) {
			$this->assertTrue( Capabilities::can( $slug ), 'Shop manager lost ' . $slug );
			$this->assertTrue( current_user_can( 'wplc_' . $slug ), 'wplc_' . $slug . ' is not resolved through user_has_cap.' );
		}
		foreach ( $top as $slug ) {
			$this->assertFalse( Capabilities::can( $slug ), 'Shop manager must not have ' . $slug );
		}

		wp_set_current_user( $this->admin );
		foreach ( array_merge( $shop, $top ) as $slug ) {
			$this->assertTrue( Capabilities::can( $slug ), 'Administrator lost ' . $slug );
		}

		wp_set_current_user( $this->customer );
		foreach ( array_merge( $shop, $top ) as $slug ) {
			$this->assertFalse( Capabilities::can( $slug ), 'Customer has ' . $slug );
		}
	}

	public function test_b49_filter_answers_the_slugs_other_packages_ask_for(): void {
		// The call other packages make, with their own default.
		$this->assertSame( 'wplc_publish_releases', apply_filters( 'wplc_capability', 'manage_woocommerce', 'publish_releases' ) );
		$this->assertSame( 'wplc_manage_catalog', apply_filters( 'wplc_capability', 'manage_woocommerce', 'manage_catalog' ) );
		$this->assertSame( 'manage_woocommerce', apply_filters( 'wplc_capability', 'manage_woocommerce', 'something_unknown' ) );

		wp_set_current_user( $this->manager );
		$this->assertFalse( current_user_can( apply_filters( 'wplc_capability', 'manage_woocommerce', 'publish_releases' ) ) );
		$this->assertTrue( current_user_can( apply_filters( 'wplc_capability', 'manage_woocommerce', 'manage_catalog' ) ) );

		// A site owner can still override one action.
		$override = static function ( $cap, $slug ) {
			return 'publish_releases' === $slug ? 'manage_woocommerce' : $cap;
		};
		add_filter( 'wplc_capability', $override, 10, 2 );
		$this->assertTrue( Capabilities::can( 'publish_releases' ) );
		remove_filter( 'wplc_capability', $override, 10 );
	}

	public function test_b49_support_role_can_view_and_manage_but_not_grant(): void {
		add_role(
			'wplc_support_test',
			'Support',
			array(
				'read'                 => true,
				'wplc_view_licenses'   => true,
				'wplc_manage_licenses' => true,
			)
		);
		$support = self::factory()->user->create( array( 'role' => 'wplc_support_test' ) );
		$license = $this->seed_license( array( 'customer_id' => $this->customer ) )['id'];
		wp_set_current_user( $support );

		$this->assertFalse( current_user_can( 'manage_woocommerce' ) );
		$this->assertStringContainsString( 'wplc_admin_license_status', $this->render_detail( $license ) );

		$suspend = $this->run_admin_post(
			'wplc_admin_license_status',
			array(
				'license_id' => $license,
				'status'     => 'suspended',
				'reason'     => 'Support role',
				'_wpnonce'   => wp_create_nonce( 'wplc_admin_license_status_' . $license ),
			)
		);
		$this->assertStringContainsString( 'status_changed', $suspend['location'] );

		$add = $this->run_admin_post(
			'wplc_admin_add_license',
			array(
				'customer'   => (string) $this->customer,
				'plan_id'    => 1,
				'paid_until' => gmdate( 'Y-m-d', time() + 90 * DAY_IN_SECONDS ),
				'reason'     => 'Not allowed',
				'_wpnonce'   => wp_create_nonce( 'wplc_admin_add_license' ),
			)
		);
		$this->assertSame( 'die', $add['outcome'] );
		$this->assertSame( 403, $add['status'] );
	}

	public function test_b49_explicit_denial_on_a_role_wins_over_the_default(): void {
		$role = get_role( 'shop_manager' );
		$role->add_cap( 'wplc_grant_licenses', false );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		try {
			$this->assertTrue( current_user_can( 'manage_woocommerce' ) );
			$this->assertFalse( Capabilities::can( 'grant_licenses' ) );
			$this->assertTrue( Capabilities::can( 'manage_licenses' ) );
		} finally {
			$role->remove_cap( 'wplc_grant_licenses' );
		}
	}

	// ---------------------------------------------------------------------
	// B-133 / B-50 / B-46: change term.
	// ---------------------------------------------------------------------

	public function test_b133_stale_change_term_form_is_refused(): void {
		$license = $this->seed_license( array( 'customer_id' => $this->customer ) )['id'];
		$seen    = $this->license( $license )['paid_until'];
		$long    = gmdate( 'Y-m-d', time() + 800 * DAY_IN_SECONDS );
		$short   = gmdate( 'Y-m-d', time() + 40 * DAY_IN_SECONDS );

		// B opened the screen; the form carries the end date B saw.
		wp_set_current_user( $this->manager );
		$this->assertStringContainsString( 'name="expected_paid_until" value="' . $seen . '"', $this->render_detail( $license ) );
		$nonce_b = wp_create_nonce( 'wplc_admin_extend_license_' . $license );

		wp_set_current_user( $this->admin );
		$first = $this->post_extend( $license, $long, $seen );
		$this->assertStringContainsString( 'wplc_notice=term_extended', $first['location'] );
		$after_a = $this->license( $license )['paid_until'];
		$this->assertGreaterThan( $seen, $after_a );

		wp_set_current_user( $this->manager );
		$second = $this->post_extend( $license, $short, $seen, $nonce_b );
		$this->assertStringContainsString( 'wplc_notice=extend_stale', $second['location'] );
		$this->assertSame( $after_a, $this->license( $license )['paid_until'], 'The stale form shortened the term.' );

		// A form from before the update has no expected value at all.
		$old_tab = $this->post_extend( $license, $short, null );
		$this->assertStringContainsString( 'wplc_notice=extend_stale', $old_tab['location'] );
		$this->assertSame( $after_a, $this->license( $license )['paid_until'] );

		// With the current value the change goes through.
		$fresh = $this->post_extend( $license, $short, $after_a );
		$this->assertStringContainsString( 'wplc_notice=term_extended', $fresh['location'] );
		$this->assertLessThan( $after_a, $this->license( $license )['paid_until'] );
	}

	public function test_b50_change_term_is_refused_while_a_charge_is_processing(): void {
		global $wpdb;
		$tables  = $this->tables();
		$start   = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
		$license = $this->seed_license(
			array(
				'customer_id'     => $this->customer,
				'renewal_enabled' => 1,
				'paid_until'      => $start,
				'next_payment_at' => $start,
			)
		)['id'];
		$now     = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert(
			$tables['renewals'],
			array(
				'license_id'      => $license,
				'period_start'    => $start,
				'period_end'      => gmdate( 'Y-m-d H:i:s', time() + 366 * DAY_IN_SECONDS ),
				'idempotency_key' => hash( 'sha256', 'b50' . $license ),
				'status'          => 'processing',
				'attempt_count'   => 0,
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);
		$this->assertTrue( SupportActions::has_processing_renewal( $license ) );

		wp_set_current_user( $this->admin );
		$result = $this->post_extend( $license, gmdate( 'Y-m-d', time() + 800 * DAY_IN_SECONDS ), $start );
		$this->assertStringContainsString( 'wplc_notice=extend_processing', $result['location'] );
		$this->assertSame( $start, $this->license( $license )['paid_until'] );
		$this->assertStringContainsString( 'automatic payment for this license is being processed', $this->render_detail( $license ) );
	}

	public function test_b46_open_renewal_order_is_listed_and_warned_about(): void {
		global $wpdb;
		$tables  = $this->tables();
		$product = $this->create_license_product();
		$order   = $this->create_paid_order( array( array( $product, 1 ) ), $this->customer );
		$license = (int) $this->licenses_for_order( $order->get_id() )[0]['id'];
		$wpdb->update( $tables['licenses'], array( 'paid_until' => gmdate( 'Y-m-d H:i:s', time() + 10 * DAY_IN_SECONDS ) ), array( 'id' => $license ) );

		$this->assertSame( array(), SupportActions::open_renewal_orders( $license ) );

		$renewal_order = ManualRenewal::start( $license, $this->customer );
		$this->assertInstanceOf( WC_Order::class, $renewal_order, is_wp_error( $renewal_order ) ? $renewal_order->get_error_message() : '' );

		$open = SupportActions::open_renewal_orders( $license );
		$this->assertCount( 1, $open );
		$this->assertSame( $renewal_order->get_id(), $open[0]['order_id'] );

		wp_set_current_user( $this->manager );
		$html = $this->render_detail( $license );
		$this->assertStringContainsString( 'unpaid renewal order', $html );
		$this->assertStringContainsString( 'Order #' . $renewal_order->get_id(), $html );
	}

	// ---------------------------------------------------------------------
	// B-65: re-issue the key.
	// ---------------------------------------------------------------------

	public function test_b65_reissue_key_replaces_uuid_hash_and_prefix_and_audits(): void {
		global $wpdb;
		$tables   = $this->tables();
		$seeded   = $this->seed_license(
			array(
				'customer_id'     => $this->customer,
				'renewal_enabled' => 1,
				'source_order_id' => 4711,
			)
		);
		$license  = $seeded['id'];
		$site     = $this->seed_activation( $license )['id'];
		$before   = $this->license( $license );
		$captured = array();
		$listener = static function ( $license_id, $key, $actor ) use ( &$captured ) {
			$captured = array( $license_id, $key, $actor );
		};
		add_action( 'wplc/license_key_reissued', $listener, 10, 3 );

		wp_set_current_user( $this->manager );
		$this->assertStringContainsString( 'wplc_admin_reissue_key', $this->render_detail( $license ) );
		$result = $this->run_admin_post(
			'wplc_admin_reissue_key',
			array(
				'license_id' => $license,
				'reason'     => 'Key was posted in a forum',
				'_wpnonce'   => wp_create_nonce( 'wplc_admin_reissue_key_' . $license ),
			)
		);
		remove_action( 'wplc/license_key_reissued', $listener, 10 );
		$this->assertStringContainsString( 'wplc_notice=key_reissued', $result['location'] );

		$after = $this->license( $license );
		$this->assertNotSame( $before['license_uuid'], $after['license_uuid'] );
		$this->assertNotSame( $before['license_key_hash'], $after['license_key_hash'] );
		$this->assertSame( LicenseKey::prefix( $after['license_uuid'] ), $after['key_prefix'] );
		$this->assertTrue( LicenseKey::matches_hash( $after['license_uuid'], $after['license_key_secret_id'], $after['license_key_hash'] ) );
		// Term, order and renewal survive.
		$this->assertSame( $before['paid_until'], $after['paid_until'] );
		$this->assertSame( '4711', (string) $after['source_order_id'] );
		$this->assertSame( '1', (string) $after['renewal_enabled'] );

		// The old key is gone, the new one is announced to listeners (for the email) but never stored.
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['licenses']} WHERE license_key_hash = %s", LicenseKey::hash( $seeded['key'] ) ) ) );
		$this->assertSame( $license, $captured[0] );
		$this->assertSame( $after['license_key_hash'], LicenseKey::hash( $captured[1] ) );
		$this->assertSame( $this->manager, $captured[2] );

		$rows = $this->audit_rows( 'license', $license );
		$last = end( $rows );
		$this->assertSame( 'license_key_reissued', $last['action'] );
		$this->assertSame( (string) $this->manager, (string) $last['actor_user_id'] );
		$this->assertStringNotContainsString( $captured[1], (string) $last['after_json'] . (string) $last['reason'] );

		// Sites stay connected unless the administrator asks for their release.
		$this->assertSame( 'active', $this->activation( $site )['status'] );
		$this->assertTrue( SupportActions::reissue_key( $license, 'Again, with sites', $this->manager, true ) );
		$this->assertSame( 'deactivated', $this->activation( $site )['status'] );
	}

	public function test_b65_old_key_no_longer_activates_and_new_key_does(): void {
		$seeded  = $this->seed_license( array( 'customer_id' => $this->customer ) );
		$new_key = '';
		$listener = static function ( $license_id, $key ) use ( &$new_key ) {
			$new_key = (string) $key;
		};
		add_action( 'wplc/license_key_reissued', $listener, 10, 2 );
		$this->assertTrue( SupportActions::reissue_key( $seeded['id'], 'Leaked', $this->admin ) );
		remove_action( 'wplc/license_key_reissued', $listener, 10 );

		$old = $this->activate_via_api( $seeded['key'] );
		$this->assertGreaterThanOrEqual( 400, $old['response']->get_status() );
		$new = $this->activate_via_api( $new_key );
		$this->assertContains( $new['response']->get_status(), array( 200, 201 ), wp_json_encode( $new['response']->get_data() ) );
	}

	public function test_b65_reissue_needs_a_reason_and_is_refused_for_revoked_licenses(): void {
		$license = $this->seed_license( array( 'status' => 'revoked' ) )['id'];
		$before  = $this->license( $license );
		$this->assertFalse( SupportActions::reissue_key( $license, 'Reason', $this->admin ) );
		$active = $this->seed_license()['id'];
		$this->assertFalse( SupportActions::reissue_key( $active, '   ', $this->admin ) );
		$this->assertSame( $before['license_uuid'], $this->license( $license )['license_uuid'] );
	}

	public function test_a61_resend_key_fires_the_action_with_the_stored_key(): void {
		$seeded   = $this->seed_license( array( 'customer_id' => $this->customer ) );
		$captured = array();
		$listener = static function ( $license_id, $key ) use ( &$captured ) {
			$captured = array( $license_id, $key );
		};
		add_action( 'wplc/license_key_resend_requested', $listener, 10, 2 );
		wp_set_current_user( $this->manager );
		$result = $this->run_admin_post(
			'wplc_admin_resend_key',
			array(
				'license_id' => $seeded['id'],
				'reason'     => 'Customer lost the email',
				'_wpnonce'   => wp_create_nonce( 'wplc_admin_resend_key_' . $seeded['id'] ),
			)
		);
		remove_action( 'wplc/license_key_resend_requested', $listener, 10 );

		$this->assertStringContainsString( 'wplc_notice=key_resend_requested', $result['location'] );
		$this->assertSame( array( $seeded['id'], $seeded['key'] ), $captured );
	}

	// ---------------------------------------------------------------------
	// A-61 / B-139: change owner.
	// ---------------------------------------------------------------------

	public function test_a61_change_owner_moves_the_license_and_switches_renewal_off(): void {
		global $wpdb;
		$tables  = $this->tables();
		$until   = gmdate( 'Y-m-d H:i:s', time() + 20 * DAY_IN_SECONDS );
		$license = $this->seed_license(
			array(
				'customer_id'        => $this->customer,
				'renewal_enabled'    => 1,
				'payment_gateway_id' => 'stripe',
				'payment_token_id'   => 55,
				'paid_until'         => $until,
				'next_payment_at'    => $until,
			)
		)['id'];
		$now     = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert(
			$tables['renewals'],
			array(
				'license_id'      => $license,
				'period_start'    => $until,
				'period_end'      => gmdate( 'Y-m-d H:i:s', time() + 385 * DAY_IN_SECONDS ),
				'idempotency_key' => hash( 'sha256', 'owner' . $license ),
				'status'          => 'scheduled',
				'retry_at'        => $until,
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);
		$renewal   = (int) $wpdb->insert_id;
		$new_owner = self::factory()->user->create(
			array(
				'role'       => 'customer',
				'user_email' => 'new-owner@example.test',
			)
		);

		wp_set_current_user( $this->manager );
		$post   = array(
			'license_id'        => $license,
			'customer'          => 'new-owner@example.test',
			'expected_customer' => (string) $this->customer,
			'reason'            => 'Agency hands the license to its client',
			'_wpnonce'          => wp_create_nonce( 'wplc_admin_change_owner_' . $license ),
		);
		$result = $this->run_admin_post( 'wplc_admin_change_owner', $post );
		$this->assertStringContainsString( 'wplc_notice=owner_changed', $result['location'] );

		$row = $this->license( $license );
		$this->assertSame( (string) $new_owner, (string) $row['customer_id'] );
		$this->assertSame( '0', (string) $row['renewal_enabled'] );
		$this->assertSame( '0', (string) $row['payment_token_id'] );
		$this->assertNull( $row['next_payment_at'] );
		$this->assertSame( $until, $row['paid_until'] );
		$this->assertSame( 'cancelled', $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$tables['renewals']} WHERE id = %d", $renewal ) ) );

		$rows = $this->audit_rows( 'license', $license );
		$last = end( $rows );
		$this->assertSame( 'license_owner_changed', $last['action'] );
		$this->assertStringContainsString( '"customer_id":' . $new_owner, (string) $last['after_json'] );

		// The same form again is stale: the owner is no longer the one it was rendered with.
		$again = $this->run_admin_post( 'wplc_admin_change_owner', array_merge( $post, array( 'customer' => (string) $this->admin ) ) );
		$this->assertStringContainsString( 'wplc_notice=owner_stale', $again['location'] );
		$this->assertSame( (string) $new_owner, (string) $this->license( $license )['customer_id'] );

		$unknown = $this->run_admin_post(
			'wplc_admin_change_owner',
			array_merge(
				$post,
				array(
					'customer'          => 'nobody@example.test',
					'expected_customer' => (string) $new_owner,
				)
			)
		);
		$this->assertStringContainsString( 'wplc_notice=owner_unknown_customer', $unknown['location'] );
	}

	public function test_p55_count_texts_use_localized_numbers_and_plurals(): void {
		wp_set_current_user( $this->manager );
		$_GET = array(
			'page'        => 'wplc-licenses',
			'wplc_notice' => 'bulk_extended',
			'wplc_n'      => '12345',
			'wplc_m'      => '1',
		);
		ob_start();
		\WPLC\Admin\AdminNotices::render();
		$html = (string) ob_get_clean();
		$_GET = array();
		$this->assertStringContainsString( 'Terms extended: 12,345. Skipped: 1 ', $html );

		$this->assertSame( 'Expires within 30 days', LicenseListTable::expiry_filter_labels()['soon'] );
		$token = \WPLC\Admin\LicenseBulkActions::new_token();
		set_transient( \WPLC\Admin\LicenseBulkActions::transient_name( $this->manager, $token ), array( 'action' => 'suspend', 'done' => array( 5 ), 'skipped' => array() ), 60 );
		ob_start();
		\WPLC\Admin\LicenseBulkActions::render_result( $this->manager, $token );
		$this->assertStringContainsString( '1 done, 0 skipped.', (string) ob_get_clean() );
	}

	public function test_p38_change_owner_can_issue_a_new_key_for_the_new_owner(): void {
		$seeded    = $this->seed_license( array( 'customer_id' => $this->customer ) );
		$license   = $seeded['id'];
		$site      = $this->seed_activation( $license )['id'];
		$new_owner = self::factory()->user->create( array( 'role' => 'customer' ) );
		$fired     = array();
		$listener  = static function ( $license_id, $key ) use ( &$fired ) {
			$fired[] = array( (int) $license_id, (string) $key );
		};
		add_action( 'wplc/license_key_reissued', $listener, 10, 2 );

		wp_set_current_user( $this->manager );
		$this->assertStringContainsString( 'name="new_key" value="1" checked', $this->render_detail( $license ) );
		$result = $this->run_admin_post(
			'wplc_admin_change_owner',
			array(
				'license_id'        => $license,
				'customer'          => (string) $new_owner,
				'expected_customer' => (string) $this->customer,
				'reason'            => 'Sold to the client',
				'new_key'           => '1',
				'release_sites'     => '1',
				'_wpnonce'          => wp_create_nonce( 'wplc_admin_change_owner_' . $license ),
			)
		);
		remove_action( 'wplc/license_key_reissued', $listener, 10 );

		$this->assertStringContainsString( 'wplc_notice=owner_changed_new_key', $result['location'] );
		$row = $this->license( $license );
		$this->assertSame( (string) $new_owner, (string) $row['customer_id'] );
		$this->assertNotSame( $seeded['uuid'], $row['license_uuid'], 'The previous owner\'s key must stop working.' );
		$this->assertNotSame( LicenseKey::hash( $seeded['key'] ), $row['license_key_hash'] );
		$this->assertSame( 'deactivated', $this->activation( $site )['status'] );
		$this->assertCount( 1, $fired );
		$this->assertSame( $license, $fired[0][0] );
		$actions = array_column( $this->audit_rows( 'license', $license ), 'action' );
		$this->assertSame( array( 'license_owner_changed', 'license_key_reissued' ), array_slice( $actions, -2 ) );

		// Without the option the key stays, as before.
		$kept = $this->seed_license( array( 'customer_id' => $this->customer ) );
		$this->assertSame( SupportActions::RESULT_OK, SupportActions::change_owner( $kept['id'], $new_owner, 'Gift', $this->admin ) );
		$this->assertSame( $kept['uuid'], $this->license( $kept['id'] )['license_uuid'] );

		// A revoked license changes owner, but cannot get a new key: the result says so.
		$revoked = $this->seed_license( array( 'customer_id' => $this->customer, 'status' => 'revoked' ) );
		$this->assertSame( SupportActions::RESULT_KEY_KEPT, SupportActions::change_owner( $revoked['id'], $new_owner, 'Gift', $this->admin, -1, true ) );
		$this->assertSame( (string) $new_owner, (string) $this->license( $revoked['id'] )['customer_id'] );
	}

	// ---------------------------------------------------------------------
	// A-51 / B-47: restore.
	// ---------------------------------------------------------------------

	public function test_a51_suspended_license_with_ended_term_can_be_restored(): void {
		$past    = gmdate( 'Y-m-d H:i:s', time() - 5 * DAY_IN_SECONDS );
		$license = $this->seed_license(
			array(
				'customer_id' => $this->customer,
				'status'      => 'suspended',
				'paid_until'  => $past,
			)
		)['id'];

		wp_set_current_user( $this->manager );
		$html = $this->render_detail( $license );
		$this->assertStringContainsString( 'wplc_admin_restore_license', $html );
		$this->assertStringNotContainsString( 'Change the term below to reactivate', $html );

		$result = $this->run_admin_post(
			'wplc_admin_restore_license',
			array(
				'license_id'      => $license,
				'expected_status' => 'suspended',
				'paid_until'      => gmdate( 'Y-m-d', time() + 30 * DAY_IN_SECONDS ),
				'reason'          => 'Chargeback was withdrawn',
				'_wpnonce'        => wp_create_nonce( 'wplc_admin_restore_license_' . $license ),
			)
		);
		$this->assertStringContainsString( 'wplc_notice=license_restored', $result['location'] );
		$row = $this->license( $license );
		$this->assertSame( 'active', $row['status'] );
		$this->assertGreaterThan( gmdate( 'Y-m-d H:i:s' ), $row['paid_until'] );
		$this->assertTrue( \WPLC\License\LicenseLifecycle::has_access( $row ) );

		$rows = $this->audit_rows( 'license', $license );
		$this->assertSame( 'admin_restore', end( $rows )['action'] );
	}

	public function test_b47_revoked_license_is_restored_by_an_administrator_only(): void {
		global $wpdb;
		$tables  = $this->tables();
		$license = $this->seed_license( array( 'customer_id' => $this->customer ) )['id'];
		$this->assertTrue( \WPLC\License\LicenseLifecycle::admin_set_status( $license, 'revoked', 'Wrong click', $this->admin ) );
		$this->assertSame( 'revoked', $this->license( $license )['status'] );

		$post = array(
			'license_id'      => $license,
			'expected_status' => 'revoked',
			'paid_until'      => gmdate( 'Y-m-d', time() + 60 * DAY_IN_SECONDS ),
			'reason'          => 'Order was set to refunded by mistake',
		);

		wp_set_current_user( $this->manager );
		$this->assertStringNotContainsString( 'wplc_admin_restore_license', $this->render_detail( $license ) );
		$denied = $this->run_admin_post( 'wplc_admin_restore_license', array_merge( $post, array( '_wpnonce' => wp_create_nonce( 'wplc_admin_restore_license_' . $license ) ) ) );
		$this->assertStringContainsString( 'wplc_notice=restore_forbidden', $denied['location'] );
		$this->assertSame( 'revoked', $this->license( $license )['status'] );

		wp_set_current_user( $this->admin );
		$this->assertStringContainsString( 'wplc_admin_restore_license', $this->render_detail( $license ) );
		$no_reason = $this->run_admin_post(
			'wplc_admin_restore_license',
			array_merge(
				$post,
				array(
					'reason'   => '',
					'_wpnonce' => wp_create_nonce( 'wplc_admin_restore_license_' . $license ),
				)
			)
		);
		$this->assertStringContainsString( 'wplc_notice=restore_missing_input', $no_reason['location'] );

		$done = $this->run_admin_post( 'wplc_admin_restore_license', array_merge( $post, array( '_wpnonce' => wp_create_nonce( 'wplc_admin_restore_license_' . $license ) ) ) );
		$this->assertStringContainsString( 'wplc_notice=license_restored', $done['location'] );
		$row = $this->license( $license );
		$this->assertSame( 'active', $row['status'] );
		$this->assertNull( $row['revoked_at'] );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['entitlements']} WHERE license_id = %d AND status <> %s", $license, 'active' ) ) );

		// A second submit of the same form finds an active license.
		$this->assertSame( SupportActions::RESULT_STALE, SupportActions::restore( $license, gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ), 'revoked', 'again', $this->admin ) );
	}

	// ---------------------------------------------------------------------
	// A-60: double click on "Add license".
	// ---------------------------------------------------------------------

	public function test_a60_same_form_submitted_twice_creates_one_license(): void {
		global $wpdb;
		$tables  = $this->tables();
		$product = $this->create_license_product();
		$plan_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$tables['plans']} WHERE woocommerce_product_id = %d", $product->get_id() ) );

		wp_set_current_user( $this->manager );
		$_GET = array(
			'page' => 'wplc-licenses',
			'view' => 'add',
		);
		ob_start();
		LicenseAdmin::render();
		$form = (string) ob_get_clean();
		$_GET = array();
		$this->assertSame( 1, preg_match( '/name="request_id" value="([a-z0-9]{24})"/', $form, $match ) );

		$post   = array(
			'customer'   => (string) $this->customer,
			'plan_id'    => $plan_id,
			'paid_until' => gmdate( 'Y-m-d', time() + 90 * DAY_IN_SECONDS ),
			'reason'     => 'Goodwill',
			'request_id' => $match[1],
			'_wpnonce'   => wp_create_nonce( 'wplc_admin_add_license' ),
		);
		$before = $this->license_count();
		$first  = $this->run_admin_post( 'wplc_admin_add_license', $post );
		$second = $this->run_admin_post( 'wplc_admin_add_license', $post );

		$this->assertStringContainsString( 'wplc_notice=license_created', $first['location'] );
		$this->assertStringContainsString( 'wplc_notice=create_duplicate', $second['location'] );
		$this->assertSame( $before + 1, $this->license_count() );
		parse_str( (string) wp_parse_url( $first['location'], PHP_URL_QUERY ), $one );
		parse_str( (string) wp_parse_url( $second['location'], PHP_URL_QUERY ), $two );
		$this->assertSame( $one['license'], $two['license'] );

		// A newly rendered form creates another license.
		$third = $this->run_admin_post( 'wplc_admin_add_license', array_merge( $post, array( 'request_id' => 'anotherformanotherform12' ) ) );
		$this->assertStringContainsString( 'wplc_notice=license_created', $third['location'] );
		$this->assertSame( $before + 2, $this->license_count() );
	}

	// ---------------------------------------------------------------------
	// B-137: reason length.
	// ---------------------------------------------------------------------

	public function test_b137_overlong_reason_is_cut_to_the_stored_length(): void {
		$license = $this->seed_license( array( 'customer_id' => $this->customer ) )['id'];
		wp_set_current_user( $this->manager );
		$result = $this->run_admin_post(
			'wplc_admin_license_status',
			array(
				'license_id' => $license,
				'status'     => 'suspended',
				'reason'     => str_repeat( 'ä', 400 ),
				'_wpnonce'   => wp_create_nonce( 'wplc_admin_license_status_' . $license ),
			)
		);
		$this->assertStringContainsString( 'wplc_notice=status_changed', $result['location'] );
		$row = $this->license( $license );
		$this->assertSame( 'suspended', $row['status'] );
		$this->assertSame( 255, mb_strlen( $row['status_reason'] ) );
	}

	// ---------------------------------------------------------------------
	// A-61: bulk extension, export, reports, missing licenses of an order.
	// ---------------------------------------------------------------------

	public function test_a61_bulk_extend_adds_days_and_skips_suspended_licenses(): void {
		$until     = gmdate( 'Y-m-d H:i:s', time() + 10 * DAY_IN_SECONDS );
		$active    = $this->seed_license( array( 'paid_until' => $until ) )['id'];
		$expired   = $this->seed_license(
			array(
				'status'     => 'expired',
				'paid_until' => gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ),
			)
		)['id'];
		$suspended = $this->seed_license( array( 'status' => 'suspended' ) )['id'];

		wp_set_current_user( $this->manager );
		$result = $this->run_admin_post(
			'wplc_admin_bulk_extend',
			array(
				'license_ids' => array( $active, $expired, $suspended, 999999 ),
				'days'        => '14',
				'reason'      => 'Outage compensation',
				'_wpnonce'    => wp_create_nonce( 'wplc_admin_bulk_extend' ),
			)
		);
		$this->assertStringContainsString( 'wplc_notice=bulk_extended', $result['location'] );
		$this->assertStringContainsString( 'wplc_n=2', $result['location'] );
		$this->assertStringContainsString( 'wplc_m=2', $result['location'] );

		$this->assertSame( gmdate( 'Y-m-d H:i:s', strtotime( $until . ' UTC' ) + 14 * DAY_IN_SECONDS ), $this->license( $active )['paid_until'] );
		$restored = $this->license( $expired );
		$this->assertSame( 'active', $restored['status'] );
		$this->assertEqualsWithDelta( time() + 14 * DAY_IN_SECONDS, strtotime( $restored['paid_until'] . ' UTC' ), 120 );
		$this->assertSame( 'suspended', $this->license( $suspended )['status'] );
	}

	public function test_a61_export_and_reports_use_the_list_filters_and_never_contain_keys(): void {
		$soon     = gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS );
		$renewing = $this->seed_license(
			array(
				'customer_id'     => $this->customer,
				'renewal_enabled' => 1,
				'paid_until'      => $soon,
				'next_payment_at' => $soon,
			)
		);
		$overdue  = $this->seed_license( array( 'status' => 'past_due' ) );
		$cancel   = $this->seed_license( array( 'cancel_at_period_end' => 1 ) );
		$filters  = array(
			'search'  => '',
			'status'  => '',
			'product' => '',
			'report'  => '',
			'orderby' => 'created',
			'order'   => 'desc',
		);

		$all = LicenseAdmin::export_csv( $filters );
		$this->assertStringContainsString( '"license_id","key_prefix","customer_id","customer_email"', $all );
		$this->assertStringContainsString( get_userdata( $this->customer )->user_email, $all );
		foreach ( array( $renewing, $overdue, $cancel ) as $seeded ) {
			$this->assertStringContainsString( '"' . $seeded['id'] . '","' . LicenseKey::prefix( $seeded['uuid'] ) . '"', $all );
			$this->assertStringNotContainsString( $seeded['key'], $all );
			$this->assertStringNotContainsString( $seeded['uuid'], $all );
		}

		$ids = static function ( string $report ) use ( $filters ): array {
			global $wpdb;
			$tables = \WPLC\Infrastructure\Database\Schema::table_names();
			$query  = LicenseListTable::query_parts( array_merge( $filters, array( 'report' => $report ) ) );

			$sql    = "SELECT id FROM {$tables['licenses']} WHERE {$query['where']} ORDER BY id ASC";

			return array_map( 'intval', $wpdb->get_col( $query['params'] ? $wpdb->prepare( $sql, $query['params'] ) : $sql ) );
		};
		if ( ! class_exists( 'WP_List_Table' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		}
		$this->assertSame( array( $renewing['id'] ), $ids( 'upcoming_renewals' ) );
		$this->assertSame( array( $overdue['id'] ), $ids( 'payment_failures' ) );
		$this->assertSame( array( $cancel['id'] ), $ids( 'cancellations' ) );

		$this->assertSame( "\"'=1+1\",\"plain\",\"-5\",\"a\"\"b\"\r\n", LicenseAdmin::csv_line( array( '=1+1', 'plain', -5, 'a"b' ) ) );
	}

	public function test_a74_missing_licenses_of_a_paid_order_can_be_issued_once(): void {
		$product = $this->create_simple_product();
		$order   = $this->create_paid_order( array( array( $product, 1 ) ), $this->customer );
		$this->assertCount( 0, $this->licenses_for_order( $order->get_id() ) );

		// The product was mapped to a plan after the sale.
		$this->assertTrue( \WPLC\Catalog\Catalog::save_mapping( $product->get_id(), 0, $this->mapping_data() ) );

		wp_set_current_user( $this->manager );
		$post   = array(
			'order_id' => $order->get_id(),
			'reason'   => 'Mapping was missing at the time of the sale',
			'_wpnonce' => wp_create_nonce( 'wplc_admin_issue_for_order' ),
		);
		$first  = $this->run_admin_post( 'wplc_admin_issue_for_order', $post );
		$second = $this->run_admin_post( 'wplc_admin_issue_for_order', $post );

		$this->assertStringContainsString( 'wplc_notice=order_licenses_issued', $first['location'] );
		$this->assertStringContainsString( 'wplc_n=1', $first['location'] );
		$this->assertStringContainsString( 'wplc_n=0', $second['location'] );
		$this->assertCount( 1, $this->licenses_for_order( $order->get_id() ) );

		$unpaid = $this->create_order( array( array( $product, 1 ) ), $this->customer );
		$failed = $this->run_admin_post( 'wplc_admin_issue_for_order', array_merge( $post, array( 'order_id' => $unpaid->get_id() ) ) );
		$this->assertStringContainsString( 'wplc_notice=order_issue_failed', $failed['location'] );
	}

	// ---------------------------------------------------------------------
	// B-119 / A-76: search and audit log.
	// ---------------------------------------------------------------------

	public function test_b119_a76_search_by_email_fragment_site_address_and_installation_id(): void {
		global $wpdb;
		$tables = $this->tables();
		if ( ! class_exists( 'WP_List_Table' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		}

		// More than 200 other users match the fragment before the license owner does (B-119).
		$values = array();
		for ( $i = 0; $i < 210; $i++ ) {
			$values[] = $wpdb->prepare( '(%s, %s, %s, %s, %s)', 'bulk' . $i . wp_generate_password( 6, false ), 'x', 'bulk' . $i, 'bulk' . $i . '@fragment-shop.test', gmdate( 'Y-m-d H:i:s' ) );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Values are prepared above.
		$wpdb->query( "INSERT INTO {$wpdb->users} (user_login, user_pass, user_nicename, user_email, user_registered) VALUES " . implode( ',', $values ) );
		$owner   = self::factory()->user->create(
			array(
				'role'       => 'customer',
				'user_email' => 'owner@fragment-shop.test',
			)
		);
		$license = $this->seed_license( array( 'customer_id' => $owner ) );
		$other   = $this->seed_license( array( 'customer_id' => $this->customer ) );
		$site    = $this->seed_activation( $license['id'], array( 'site_url' => 'https://agency-client-one.example.com' ) );

		$find = static function ( string $term ) use ( $tables ): array {
			global $wpdb;
			$query = LicenseListTable::query_parts( array( 'search' => $term ) );

			return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$tables['licenses']} WHERE {$query['where']} ORDER BY id ASC", $query['params'] ) ) );
		};

		$this->assertSame( array( $license['id'] ), $find( 'fragment-shop' ) );
		$this->assertSame( array( $license['id'] ), $find( '@fragment-shop.test' ) );
		$this->assertSame( array( $license['id'] ), $find( 'agency-client-one' ) );
		$this->assertSame( array( $license['id'] ), $find( $site['uuid'] ) );
		$this->assertSame( array( $license['id'] ), $find( strtoupper( $license['uuid'] ) ) );
		// A key pasted with a non-breaking space and typographic dashes (B-173).
		$this->assertSame( array( $other['id'] ), $find( "\xC2\xA0" . str_replace( '-', "\xE2\x80\x93", $other['key'] ) . "\xE2\x80\x8B" ) );
		$this->assertSame( array(), $find( 'no-such-customer-or-site' ) );
	}

	public function test_t141_pasted_license_key_never_reaches_a_url_or_the_page(): void {
		$seeded = $this->seed_license( array( 'customer_id' => $this->customer ) );
		$other  = $this->seed_license( array( 'customer_id' => $this->customer ) );
		$secret = substr( $seeded['key'], -64 );
		wp_set_current_user( $this->manager );

		// Merge of both lines: the search form is posted and answered with a redirect that
		// carries an opaque search token (line A), never the term or the license ID.
		$posted = $this->run_admin_post(
			'wplc_admin_license_search',
			array(
				's'        => '  ' . strtolower( $seeded['key'] ),
				'status'   => 'active',
				'_wpnonce' => wp_create_nonce( 'wplc_admin_license_search' ),
			)
		);
		$this->assertSame( 'redirect', $posted['outcome'] );
		$this->assertStringNotContainsStringIgnoringCase( $secret, $posted['location'] );
		$this->assertStringContainsString( LicenseSearchStore::QUERY_ARG . '=', $posted['location'] );
		$this->assertStringContainsString( 'status=active', $posted['location'] );
		$query = array();
		wp_parse_str( (string) wp_parse_url( $posted['location'], PHP_URL_QUERY ), $query );
		$token = (string) $query[ LicenseSearchStore::QUERY_ARG ];

		$email = $this->run_admin_post(
			'wplc_admin_license_search',
			array(
				's'        => 'alice@customer.test',
				'_wpnonce' => wp_create_nonce( 'wplc_admin_license_search' ),
			)
		);
		$this->assertStringNotContainsString( 'alice', $email['location'] );

		// A key that still arrives in the address (old bookmark) is ignored and not repeated in the page.
		set_current_screen( 'woocommerce_page_wplc-licenses' );
		$_GET     = array(
			'page' => 'wplc-licenses',
			's'    => $seeded['key'],
		);
		$_REQUEST = $_GET;
		ob_start();
		LicenseAdmin::render();
		$html = (string) ob_get_clean();
		$this->assertStringNotContainsStringIgnoringCase( $secret, $html );

		// The token finds the license without the key in the page.
		$_GET     = array(
			'page'                         => 'wplc-licenses',
			LicenseSearchStore::QUERY_ARG => $token,
		);
		$_REQUEST = $_GET;
		ob_start();
		LicenseAdmin::render();
		$html     = (string) ob_get_clean();
		$_GET     = array();
		$_REQUEST = array();

		$this->assertStringNotContainsStringIgnoringCase( $secret, $html );
		$this->assertStringContainsString( 'Search results for the pasted license key', $html );
		$this->assertStringContainsString( LicenseKey::prefix( $seeded['uuid'] ), $html );
		$this->assertStringNotContainsString( LicenseKey::prefix( $other['uuid'] ), $html );
		$this->assertStringContainsString( 'value="wplc_admin_license_search"', $html );
	}

	public function test_a76_b135_audit_log_shows_login_and_id_and_filters_by_license(): void {
		$license = $this->seed_license( array( 'customer_id' => $this->customer ) )['id'];
		$other   = $this->seed_license()['id'];
		$site    = $this->seed_activation( $license )['id'];
		$actor   = self::factory()->user->create(
			array(
				'role'         => 'shop_manager',
				'user_login'   => 'clerk_anna',
				'display_name' => 'System',
			)
		);
		$this->assertTrue( \WPLC\License\LicenseLifecycle::admin_set_status( $license, 'suspended', 'Check', $actor ) );
		$this->assertTrue( \WPLC\License\LicenseLifecycle::admin_set_status( $other, 'suspended', 'Other', $this->admin ) );
		$this->assertTrue( \WPLC\License\ActivationManager::deactivate( $site, 'Released', $actor, 'admin_site_released' ) );

		wp_set_current_user( $this->manager );
		$html = $this->render_detail( $license );
		$this->assertStringContainsString( 'clerk_anna (#' . $actor . ')', $html );
		$this->assertStringNotContainsString( '<td>System</td>', $html );

		// P-27: the one remaining audit screen filters by license (license, its sites and renewals).
		$entries = static function ( array $filters ): array {
			global $wpdb;
			$where = AuditLogAdmin::where( array_merge( array( 'license' => '', 'object_type' => '', 'object_id' => '', 'action' => '', 'actor' => '', 'from' => '', 'to' => '' ), $filters ) );
			$table = \WPLC\Infrastructure\Database\Schema::table_names()['audit_log'];
			$sql   = "SELECT * FROM {$table}{$where['sql']} ORDER BY id DESC";
			return (array) $wpdb->get_results( $where['params'] ? $wpdb->prepare( $sql, $where['params'] ) : $sql, ARRAY_A );
		};
		$mine = $entries( array( 'license' => (string) $license ) );
		$this->assertCount( 2, $mine );
		$this->assertSame( array( 'activation', 'license' ), array_column( $mine, 'object_type' ) );
		$this->assertCount( 1, $entries( array( 'actor' => 'clerk_anna', 'object_type' => 'license' ) ) );
		$this->assertCount( 0, $entries( array( 'actor' => 'no_such_login' ) ) );
		$this->assertCount( 0, $entries( array( 'license' => (string) $license, 'to' => gmdate( 'Y-m-d', time() - 3 * DAY_IN_SECONDS ) ) ) );

		$csv = AuditLogAdmin::export_csv( array( 'license' => (string) $license, 'object_type' => '', 'object_id' => '', 'action' => '', 'actor' => '', 'from' => '', 'to' => '' ) );
		$this->assertStringContainsString( '"admin_status_change","license","' . $license . '","' . $actor . '","clerk_anna","Check"', $csv );

		set_current_screen( 'woocommerce_page_' . AuditLogAdmin::PAGE );
		$_GET = array(
			'page'    => AuditLogAdmin::PAGE,
			'license' => (string) $license,
		);
		ob_start();
		AuditLogAdmin::render();
		$page = (string) ob_get_clean();
		$_GET = array();
		$this->assertStringContainsString( 'clerk_anna (#' . $actor . ')', $page );
		$this->assertStringContainsString( 'admin_site_released', $page );
		$this->assertStringNotContainsString( 'Other', $page, 'Entries of another license are not listed.' );

		// The license detail screen links to the kept screen.
		wp_set_current_user( $this->manager );
		$this->assertStringContainsString( 'page=' . AuditLogAdmin::PAGE . '&#038;license=' . $license, $this->render_detail( $license ) );

		wp_set_current_user( $this->customer );
		$this->expectException( WPDieException::class );
		AuditLogAdmin::render();
	}

	public function test_p27_old_audit_page_urls_redirect_to_the_kept_screen(): void {
		$this->assertNull( AuditLogAdmin::legacy_redirect_url( array( 'page' => AuditLogAdmin::PAGE ) ) );
		$this->assertNull( AuditLogAdmin::legacy_redirect_url( array() ) );

		$target = AuditLogAdmin::legacy_redirect_url(
			array(
				'page'         => 'wplc-audit',
				'license'      => '12',
				'actor'        => '#7',
				'audit_action' => 'admin_extend',
				'from'         => '2026-01-01',
				'evil'         => 'x',
			)
		);
		$query = array();
		parse_str( (string) wp_parse_url( (string) $target, PHP_URL_QUERY ), $query );
		$this->assertSame(
			array(
				'page'         => AuditLogAdmin::PAGE,
				'license'      => '12',
				'audit_action' => 'admin_extend',
				'actor'        => '7',
				'from'         => '2026-01-01',
			),
			$query
		);
		$this->assertStringStartsWith( admin_url( 'admin.php' ), (string) $target );

		// The old page is no longer registered, the export handler moved to the kept screen.
		$this->assertFalse( class_exists( '\\WPLC\\Admin\\AuditLogPage', false ) && is_file( WPLC_PATH . 'src/Admin/AuditLogPage.php' ) );
		$this->assertNotFalse( has_action( 'admin_post_' . AuditLogAdmin::EXPORT_ACTION, array( AuditLogAdmin::class, 'handle_export' ) ) );
	}

	public function test_p27_audit_export_is_redacted_audited_and_capped(): void {
		global $wpdb;
		$license = $this->seed_license( array( 'customer_id' => $this->customer ) )['id'];
		// A row written past AuditLog::record() (an add-on, an old row): raw values in reason and snapshot.
		$wpdb->insert(
			$this->tables()['audit_log'],
			array(
				'actor_user_id' => 0,
				'action'        => 'addon_note',
				'object_type'   => 'license',
				'object_id'     => $license,
				'reason'        => 'Customer jane@example.test sent token=abc123',
				'before_json'   => wp_json_encode( array( 'email' => 'jane@example.test' ) ),
				'after_json'    => wp_json_encode( array( 'license_key' => 'WPLC-SECRET-KEY-1234' ) ),
				'created_at'    => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		for ( $i = 0; $i < 3; $i++ ) {
			\WPLC\License\AuditLog::record( 'admin_extend', 'license', $license, 'Row ' . $i, null, null, $this->admin );
		}
		$filters = array( 'license' => (string) $license, 'object_type' => '', 'object_id' => '', 'action' => '', 'actor' => '', 'from' => '', 'to' => '' );

		$csv = AuditLogAdmin::export_csv( $filters );
		$this->assertStringNotContainsString( 'jane@example.test', $csv );
		$this->assertStringNotContainsString( 'abc123', $csv );
		$this->assertStringNotContainsString( 'WPLC-SECRET-KEY-1234', $csv );
		$this->assertStringContainsString( 'addon_note', $csv );
		$this->assertStringNotContainsString( '# ', $csv );

		// Keyset paging with a cap: the newest rows, then a marker line.
		$capped = AuditLogAdmin::export_csv( $filters, 2 );
		$lines  = array_values( array_filter( explode( "\r\n", $capped ) ) );
		$this->assertCount( 4, $lines, 'Header, two rows and the marker.' );
		$this->assertStringContainsString( 'Row 2', $lines[1] );
		$this->assertStringContainsString( 'Row 1', $lines[2] );
		$this->assertStringContainsString( 'Export truncated after 2 rows', $lines[3] );

		// The download itself is recorded in the audit log.
		wp_set_current_user( $this->manager );
		$_GET     = array_merge( array( '_wpnonce' => wp_create_nonce( AuditLogAdmin::EXPORT_ACTION ), 'license' => (string) $license ) );
		$_REQUEST = $_GET;
		$this->halt_instead_of_exit();
		ob_start();
		try {
			AuditLogAdmin::handle_export();
		} catch ( \PHPUnit\Framework\Error\Warning $e ) {
			// The download headers stop here in the test runner (output already started), after the audit entry.
		} finally {
			ob_end_clean();
			$_GET     = array();
			$_REQUEST = array();
		}
		$rows = $this->audit_rows( 'export', 0 );
		$this->assertNotEmpty( $rows );
		$this->assertSame( 'audit_log_exported', end( $rows )['action'] );
		$this->assertSame( (string) $this->manager, (string) end( $rows )['actor_user_id'] );
		$this->assertStringContainsString( '"license":"' . $license . '"', (string) end( $rows )['after_json'] );
	}

	public function test_p28_license_export_streams_emails_only_and_reports_the_cap(): void {
		$second  = self::factory()->user->create( array( 'role' => 'customer', 'user_email' => 'second-buyer@example.test' ) );
		$first   = $this->seed_license( array( 'customer_id' => $this->customer ) );
		$other   = $this->seed_license( array( 'customer_id' => $second ) );
		$third   = $this->seed_license( array( 'customer_id' => $second ) );
		$filters = array(
			'search'  => '',
			'status'  => '',
			'product' => '',
			'report'  => '',
			'orderby' => 'created',
			'order'   => 'desc',
		);
		if ( ! class_exists( 'WP_List_Table' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		}

		// Only the email column of the users is read: no user meta lands in the cache.
		wp_cache_delete( $second, 'user_meta' );
		$handle = fopen( 'php://temp', 'w+' );
		$result = LicenseAdmin::write_csv( $filters, $handle );
		rewind( $handle );
		$csv = (string) stream_get_contents( $handle );
		fclose( $handle );
		$this->assertFalse( $result['truncated'] );
		$this->assertGreaterThanOrEqual( 3, $result['rows'] );
		$this->assertStringContainsString( '"' . $other['id'] . '","' . LicenseKey::prefix( $other['uuid'] ) . '","' . $second . '","second-buyer@example.test"', $csv );
		$this->assertFalse( wp_cache_get( $second, 'user_meta' ), 'The export must not load the meta of every customer.' );
		$this->assertSame( array( $second => 'second-buyer@example.test' ), CsvExport::user_field( array( $second, $second, 0 ), 'user_email' ) );

		// A capped export ends with a marker line; the next screen shows a notice once.
		$all    = substr_count( LicenseAdmin::export_csv( $filters ), "\r\n" ) - 1;
		$capped = LicenseAdmin::export_csv( $filters, $all - 1 );
		$this->assertSame( $all + 1, substr_count( $capped, "\r\n" ), 'Header, cap rows and the marker.' );
		$this->assertStringContainsString( 'Export truncated after', $capped );
		$this->assertStringNotContainsString( 'Export truncated', LicenseAdmin::export_csv( $filters, $all ), 'An export of exactly the cap is complete.' );

		CsvExport::remember_truncated( $this->manager, 'licenses', 100000 );
		ob_start();
		CsvExport::render_truncated_notice( $this->manager );
		$notice = (string) ob_get_clean();
		$this->assertStringContainsString( 'stopped at 100,000 licenses', $notice );
		ob_start();
		CsvExport::render_truncated_notice( $this->manager );
		$this->assertSame( '', (string) ob_get_clean(), 'The notice is shown once.' );
		unset( $first, $third );
	}

	// ---------------------------------------------------------------------
	// B-134: settings.
	// ---------------------------------------------------------------------

	public function test_b134_partial_write_keeps_the_other_settings(): void {
		add_filter( 'sanitize_option_' . Settings::OPTION, array( Settings::class, 'sanitize' ) );
		update_option(
			Settings::OPTION,
			array(
				'package_hosts' => array( 'downloads.example.com' ),
				'reminder_days' => '30,7',
				'sender_note'   => 'Support: help desk',
			)
		);

		update_option( Settings::OPTION, array( 'reminder_days' => '10' ) );

		$this->assertSame( array( 'downloads.example.com' ), Settings::get( 'package_hosts' ) );
		$this->assertSame( '10', Settings::get( 'reminder_days' ) );
		$this->assertSame( 'Support: help desk', Settings::get( 'sender_note' ) );
	}

	public function test_b134_stale_settings_form_cannot_bring_back_a_removed_host(): void {
		add_filter( 'sanitize_option_' . Settings::OPTION, array( Settings::class, 'sanitize' ) );
		wp_set_current_user( $this->admin );
		update_option(
			Settings::OPTION,
			array(
				'package_hosts' => array( 'old.example.com' ),
				'reminder_days' => '30,7',
				'sender_note'   => '',
			)
		);

		// B renders the form now.
		ob_start();
		Settings::render_package_hosts_field();
		$field = (string) ob_get_clean();
		$this->assertSame( 1, preg_match( '/\[package_hosts_seen\]" value="([a-f0-9]{20})"/', $field, $match ) );
		$stale_form = array(
			'package_hosts'      => 'old.example.com',
			'package_hosts_seen' => $match[1],
			'reminder_days'      => '14',
			'sender_note'        => '',
		);

		// A removes the compromised host and adds the new one, through the form.
		$_POST['option_page'] = Settings::OPTION;
		update_option(
			Settings::OPTION,
			array(
				'package_hosts'      => 'downloads.example.com',
				'package_hosts_seen' => $match[1],
				'reminder_days'      => '30,7',
				'sender_note'        => '',
			)
		);
		$this->assertSame( array( 'downloads.example.com' ), Settings::get( 'package_hosts' ) );

		// B saves the reminder days from the stale tab.
		update_option( Settings::OPTION, $stale_form );
		unset( $_POST['option_page'] );

		$this->assertSame( array( 'downloads.example.com' ), Settings::get( 'package_hosts' ), 'The stale tab restored the removed host.' );
		$this->assertSame( '14', Settings::get( 'reminder_days' ), 'The other fields of the stale tab are still saved.' );
		$this->assertArrayNotHasKey( 'package_hosts_seen', get_option( Settings::OPTION ) );
	}

	// Found by the E2E run: on a fresh installation the option does not exist yet. WordPress then
	// sanitizes twice (update_option falls through to add_option); the second pass has no form
	// fingerprint and used to discard the hosts the first pass had accepted.
	public function test_b134_first_save_of_the_settings_form_keeps_the_package_hosts(): void {
		add_filter( 'sanitize_option_' . Settings::OPTION, array( Settings::class, 'sanitize' ) );
		wp_set_current_user( $this->admin );
		delete_option( Settings::OPTION );
		ob_start();
		Settings::render_package_hosts_field();
		$field = (string) ob_get_clean();
		$this->assertSame( 1, preg_match( '/\[package_hosts_seen\]" value="([a-f0-9]{20})"/', $field, $match ) );

		$_POST['option_page'] = Settings::OPTION;
		update_option(
			Settings::OPTION,
			array(
				'package_hosts'      => "downloads.example.com\nhttps://not-a-host.example/path",
				'package_hosts_seen' => $match[1],
				'reminder_days'      => '14,7',
				'sender_note'        => '',
			)
		);

		$this->assertSame( array( 'downloads.example.com' ), Settings::get( 'package_hosts' ) );

		// An array posted instead of the textarea does not get around the fingerprint.
		update_option(
			Settings::OPTION,
			array(
				'package_hosts' => array( 'evil.example.com' ),
				'reminder_days' => '14,7',
			)
		);
		unset( $_POST['option_page'] );
		$this->assertSame( array( 'downloads.example.com' ), Settings::get( 'package_hosts' ) );
	}

	public function test_b49_b134_shop_manager_cannot_change_package_hosts_and_changes_are_audited(): void {
		add_filter( 'sanitize_option_' . Settings::OPTION, array( Settings::class, 'sanitize' ) );
		wp_set_current_user( $this->admin );
		update_option(
			Settings::OPTION,
			array(
				'package_hosts' => array( 'downloads.example.com' ),
				'reminder_days' => '30,7',
				'sender_note'   => '',
			)
		);
		$audit_before = count( $this->audit_rows( 'settings', 0 ) );

		wp_set_current_user( $this->manager );
		$this->assertTrue( current_user_can( Settings::capability() ) );
		ob_start();
		Settings::render_package_hosts_field();
		$field = (string) ob_get_clean();
		$this->assertStringContainsString( 'readonly', $field );
		$this->assertStringNotContainsString( 'name="', $field );

		// A forged form with the right fingerprint, in both shapes options.php accepts.
		$_POST['option_page'] = Settings::OPTION;
		foreach ( array( 'evil.example.net', array( 'evil.example.net' ) ) as $hosts ) {
			update_option(
				Settings::OPTION,
				array(
					'package_hosts'      => $hosts,
					'package_hosts_seen' => Settings::hosts_fingerprint( array( 'downloads.example.com' ) ),
					'reminder_days'      => '21',
					'sender_note'        => '',
				)
			);
			$this->assertSame( array( 'downloads.example.com' ), Settings::get( 'package_hosts' ) );
		}
		unset( $_POST['option_page'] );
		$this->assertSame( '21', Settings::get( 'reminder_days' ) );
		$this->assertContains( 'downloads.example.com', apply_filters( 'wplc_update_package_hosts', array() ) );
		$this->assertNotContains( 'evil.example.net', apply_filters( 'wplc_update_package_hosts', array() ) );

		$rows = $this->audit_rows( 'settings', 0 );
		$this->assertCount( $audit_before + 1, $rows );
		$last = end( $rows );
		// Merge of both lines: one settings audit entry, line A's `settings_updated`.
		$this->assertSame( 'settings_updated', $last['action'] );
		$this->assertSame( (string) $this->manager, (string) $last['actor_user_id'] );
		$this->assertSame( 'Changed: reminder_days', $last['reason'] );
		$this->assertMatchesRegularExpression( '/"reminder_days":"?21"?/', (string) $last['after_json'] );
	}
}
