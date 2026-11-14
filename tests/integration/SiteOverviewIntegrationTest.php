<?php
/**
 * Customer account "Sites": all activations of a customer on one page.
 *
 * @package WPLC
 */

use WPLC\Account\SiteOverview;

/**
 * @group account
 */
final class SiteOverviewIntegrationTest extends WPLC_Integration_TestCase {

	/** @var int */
	private $customer_a;

	/** @var int */
	private $customer_b;

	public function set_up(): void {
		parent::set_up();
		$this->customer_a = self::factory()->user->create( array( 'role' => 'customer' ) );
		$this->customer_b = self::factory()->user->create( array( 'role' => 'customer' ) );

		global $wpdb;
		$tables = $this->tables();
		$now    = gmdate( 'Y-m-d H:i:s' );
		foreach ( array(
			'voucher-captain-pro' => 'Voucher Captain Pro',
			'pass-captain-pro' => 'Pass Captain Pro',
		) as $key => $name ) {
			$wpdb->replace(
				$tables['products'],
				array(
					'product_key'  => $key,
					'display_name' => $name,
					'active'       => 1,
					'created_at'   => $now,
					'updated_at'   => $now,
				)
			);
		}
	}

	private function publish_release( string $product_key, string $version ): void {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$this->assertSame(
			1,
			$wpdb->insert(
				$this->tables()['releases'],
				array(
					'product_key'   => $product_key,
					'version'       => $version,
					'channel'       => 'stable',
					'package_url'   => 'https://downloads.example.test/' . $product_key . '-' . $version . '.zip',
					'sha256'        => str_repeat( 'a', 64 ),
					'release_notes' => '',
					'status'        => 'published',
					'published_at'  => $now,
					'created_at'    => $now,
					'updated_at'    => $now,
				)
			),
			'Fixture: release insert failed: ' . $wpdb->last_error
		);
	}

	private function render_page( array $get = array(), $page = '' ): string {
		$_GET = $get;
		ob_start();
		SiteOverview::render( $page );
		$html = (string) ob_get_clean();
		$_GET = array();

		return $html;
	}

	/**
	 * Agency A: two products on a shared site, one more site per product.
	 *
	 * @return array<string,int> Activation IDs by name.
	 */
	private function seed_agency(): array {
		$voucher = $this->seed_license(
			array(
				'customer_id' => $this->customer_a,
				'product_key'     => 'voucher-captain-pro',
				'plan_key'        => 'business',
				'renewal_enabled' => 1,
			),
			array( 'sites.live_limit' => 5 )
		)['id'];
		$pass    = $this->seed_license(
			array(
				'customer_id' => $this->customer_a,
				'product_key' => 'pass-captain-pro',
				'plan_key'    => 'single',
				'status'      => 'expired',
			),
			array( 'sites.live_limit' => 1 )
		)['id'];

		return array(
			'shared_voucher' => $this->seed_activation(
				$voucher,
				array(
					'site_url'        => 'https://kunde-eins.example.test',
					'product_version' => '1.0.0',
				)
			)['id'],
			'shared_pass'    => $this->seed_activation(
				$pass,
				array(
					'site_url'        => 'https://kunde-eins.example.test',
					'product_version' => '1.4.0',
				)
			)['id'],
			'voucher_only'   => $this->seed_activation(
				$voucher,
				array(
					'site_url'        => 'https://kunde-zwei.example.test',
					'product_version' => '1.1.0',
				)
			)['id'],
			'staging_stale'  => $this->seed_activation(
				$voucher,
				array(
					'site_url'        => 'https://staging.kunde-drei.example.test',
					'environment'     => 'staging',
					'product_version' => '1.1.0',
					'last_seen_at'    => gmdate( 'Y-m-d H:i:s', time() - 20 * DAY_IN_SECONDS ),
				)
			)['id'],
			'released'       => $this->seed_activation(
				$voucher,
				array(
					'site_url' => 'https://alt.example.test',
					'status'   => 'deactivated',
				)
			)['id'],
		);
	}

	public function test_customer_sees_all_own_sites_across_licenses_and_nothing_else(): void {
		$this->seed_agency();
		$foreign = $this->seed_license(
			array(
				'customer_id' => $this->customer_b,
				'product_key' => 'voucher-captain-pro',
			)
		)['id'];
		$this->seed_activation( $foreign, array( 'site_url' => 'https://fremd.example.test' ) );
		$this->publish_release( 'voucher-captain-pro', '1.1.0' );

		wp_set_current_user( $this->customer_a );
		$html = $this->render_page();

		$this->assertStringContainsString( 'https://kunde-eins.example.test', $html );
		$this->assertStringContainsString( 'https://kunde-zwei.example.test', $html );
		$this->assertStringContainsString( 'https://staging.kunde-drei.example.test', $html );
		$this->assertStringContainsString( 'Voucher Captain Pro — business', $html );
		$this->assertStringContainsString( 'Pass Captain Pro — single', $html );
		$this->assertStringNotContainsString( 'https://fremd.example.test', $html, 'Another customer\'s site leaked into the overview.' );
		$this->assertStringNotContainsString( 'https://alt.example.test', $html, 'A released activation is still listed.' );
		$this->assertStringContainsString( 'Sites: 3 (live: 2). Need attention: 2. Update available: 1.', $html );
	}

	public function test_warnings_name_what_to_do(): void {
		$this->seed_agency();
		$this->publish_release( 'voucher-captain-pro', '1.1.0' );

		wp_set_current_user( $this->customer_a );
		$html = $this->render_page();

		$this->assertStringContainsString( 'Version 1.1.0 is available.', $html, 'Site with 1.0.0 must offer the update.' );
		$this->assertStringContainsString( 'License is not active', $html, 'Expired Pass license must be flagged.' );
		$this->assertStringContainsString( 'No contact for more than 14 days.', $html );
		$this->assertStringContainsString( 'Nothing to do', $html, 'The healthy site must say so.' );
	}

	public function test_license_that_ends_without_renewal_is_flagged(): void {
		$license = $this->seed_license(
			array(
				'customer_id'     => $this->customer_a,
				'renewal_enabled' => 0,
				'paid_until'      => gmdate( 'Y-m-d H:i:s', time() + 10 * DAY_IN_SECONDS ),
			)
		)['id'];
		$this->seed_activation( $license, array( 'site_url' => 'https://endet.example.test' ) );
		wp_set_current_user( $this->customer_a );

		$this->assertStringContainsString( 'and does not renew.', $this->render_page() );
	}

	public function test_filter_and_search(): void {
		$this->seed_agency();
		$this->publish_release( 'voucher-captain-pro', '1.1.0' );
		wp_set_current_user( $this->customer_a );

		$updates = $this->render_page( array( 'wplc_filter' => 'updates' ) );
		$this->assertStringContainsString( 'https://kunde-eins.example.test', $updates );
		$this->assertStringNotContainsString( 'https://kunde-zwei.example.test', $updates );

		$attention = $this->render_page( array( 'wplc_filter' => 'attention' ) );
		$this->assertStringContainsString( 'https://staging.kunde-drei.example.test', $attention );
		$this->assertStringNotContainsString( 'https://kunde-zwei.example.test', $attention );

		$search = $this->render_page( array( 'wplc_site' => 'ZWEI' ) );
		$this->assertStringContainsString( 'https://kunde-zwei.example.test', $search );
		$this->assertStringNotContainsString( 'https://kunde-eins.example.test', $search );

		$bogus = $this->render_page( array( 'wplc_filter' => '<script>' ) );
		$this->assertStringContainsString( 'https://kunde-zwei.example.test', $bogus, 'An unknown filter falls back to "all".' );
		$this->assertStringNotContainsString( '<script>', $bogus );
	}

	public function test_customer_without_activations_and_guest(): void {
		$this->seed_license( array( 'customer_id' => $this->customer_b ) );
		wp_set_current_user( $this->customer_b );
		$this->assertStringContainsString( 'No site is activated with your licenses yet.', $this->render_page() );

		wp_set_current_user( 0 );
		$this->assertSame( 'Please sign in to view your sites.', $this->render_page() );
	}

	public function test_menu_entry_only_for_customers_with_a_license(): void {
		$menu = array(
			'dashboard'       => 'Dashboard',
			'wplc-licenses'   => 'Licenses',
			'customer-logout' => 'Log out',
		);

		wp_set_current_user( $this->customer_b );
		$this->assertArrayNotHasKey( SiteOverview::ENDPOINT, SiteOverview::add_menu_item( $menu ) );

		$this->seed_license( array( 'customer_id' => $this->customer_a ) );
		wp_set_current_user( $this->customer_a );
		$this->assertSame( array( 'dashboard', 'wplc-licenses', SiteOverview::ENDPOINT, 'customer-logout' ), array_keys( SiteOverview::add_menu_item( $menu ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_account_' . SiteOverview::ENDPOINT . '_endpoint' ), 'The endpoint has no renderer.' );
	}

	public function test_csv_contains_the_same_rows_for_the_owner_only(): void {
		$this->seed_agency();
		$foreign = $this->seed_license( array( 'customer_id' => $this->customer_b ) )['id'];
		$this->seed_activation( $foreign, array( 'site_url' => 'https://fremd.example.test' ) );
		$this->publish_release( 'voucher-captain-pro', '1.1.0' );

		$csv = SiteOverview::csv_for_customer( $this->customer_a, time() );

		$this->assertStringStartsWith( "\xEF\xBB\xBF\"Site\",\"Product\",\"Environment\",\"Version\",\"Last contact\",\"License\",\"Needs attention\",\"Latest version\"\r\n", $csv );
		$this->assertSame( 5, substr_count( $csv, "\r\n" ), 'Header plus four active activations.' );
		$this->assertStringContainsString( '"https://kunde-eins.example.test","Voucher Captain Pro — business"', $csv );
		$this->assertStringContainsString( 'Version 1.1.0 is available.', $csv );
		$this->assertStringNotContainsString( 'fremd.example.test', $csv );
	}

	public function test_csv_download_requires_login_and_nonce(): void {
		wp_set_current_user( 0 );
		$this->assertSame( 403, $this->run_admin_post( 'wplc_sites_csv', array() )['status'] );

		wp_set_current_user( $this->customer_a );
		$result = $this->run_admin_post( 'wplc_sites_csv', array( '_wpnonce' => 'wrong' ) );
		$this->assertSame( 'die', $result['outcome'], 'A wrong nonce must stop the download.' );
	}

	public function test_deactivate_button_uses_the_audited_handler_of_the_license_page(): void {
		$ids = $this->seed_agency();
		wp_set_current_user( $this->customer_a );
		$html = $this->render_page();

		$id = $ids['voucher_only'];
		$this->assertMatchesRegularExpression( '/name="activation_id" value="' . $id . '"/', $html );
		$this->assertMatchesRegularExpression( '/name="action" value="wplc_deactivate_site"/', $html );

		$result = $this->run_admin_post(
			'wplc_deactivate_site',
			array(
				'activation_id' => $id,
				'_wpnonce'      => wp_create_nonce( 'wplc_deactivate_site_' . $id ),
			)
		);

		$this->assertSame( 'redirect', $result['outcome'] );
		$this->assertSame( 'deactivated', $this->activation( $id )['status'] );
		$this->assertStringNotContainsString( 'https://kunde-zwei.example.test', $this->render_page(), 'A deactivated site is still listed.' );
	}

	public function test_another_customer_cannot_deactivate_through_the_overview_form(): void {
		$ids = $this->seed_agency();
		wp_set_current_user( $this->customer_b );
		$id = $ids['voucher_only'];

		$this->run_admin_post(
			'wplc_deactivate_site',
			array(
				'activation_id' => $id,
				'_wpnonce'      => wp_create_nonce( 'wplc_deactivate_site_' . $id ),
			)
		);

		$this->assertSame( 'active', $this->activation( $id )['status'] );
	}
}
