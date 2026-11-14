<?php
/**
 * T-141: the admin license search is POSTed and never reflected into URLs.
 *
 * @package WPLC
 */

use WPLC\Admin\LicenseAdmin;
use WPLC\Admin\LicenseListTable;
use WPLC\Admin\LicenseSearchStore;
use WPLC\License\LicenseKey;

final class LicenseSearchIntegrationTest extends WPLC_Integration_TestCase {

	/**
	 * Shop manager running the searches.
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
	 * POST a search and return the redirect target.
	 *
	 * @param string              $term  Search term.
	 * @param array<string,mixed> $extra Further POST fields.
	 * @return string
	 */
	private function search( string $term, array $extra = array() ): string {
		$result = $this->run_admin_post(
			'wplc_admin_license_search',
			array_merge(
				array(
					's'        => $term,
					'_wpnonce' => wp_create_nonce( 'wplc_admin_license_search' ),
				),
				$extra
			)
		);
		$this->assertSame( 'redirect', $result['outcome'], $result['message'] );

		return $result['location'];
	}

	/**
	 * Query the list table the way the admin screen does for a redirect target.
	 *
	 * @param string $location Redirect target of the search.
	 * @return int[] License IDs on the first page.
	 */
	private function listed_ids( string $location ): array {
		$query = array();
		parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );
		$_GET     = $query;
		$_REQUEST = $query;
		LicenseSearchStore::reset_cache();

		if ( ! class_exists( 'WP_Screen' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		}
		if ( ! function_exists( 'set_current_screen' ) ) {
			require_once ABSPATH . 'wp-admin/includes/screen.php';
		}
		if ( ! class_exists( 'WP_List_Table' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		}
		set_current_screen( 'woocommerce_page_wplc-licenses' );
		$table = new LicenseListTable();
		$table->prepare_items();

		return array_map( 'intval', wp_list_pluck( $table->items, 'id' ) );
	}

	public function test_a_pasted_key_finds_its_license_without_appearing_in_the_url_or_storage(): void {
		global $wpdb;
		$license = $this->seed_license();
		$this->seed_license();

		$location = $this->search( strtolower( $license['key'] ), array( 'status' => 'active', 'orderby' => 'paid_until', 'order' => 'asc' ) );

		$this->assertStringNotContainsStringIgnoringCase( substr( $license['key'], 14 ), $location );
		$this->assertStringNotContainsString( LicenseKey::hash( $license['key'] ), $location );
		$query = array();
		parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );
		$this->assertArrayNotHasKey( 's', $query );
		$this->assertStringContainsString( 'status=active', $location );
		$this->assertStringContainsString( 'orderby=paid_until', $location );
		$this->assertSame( array( $license['id'] ), $this->listed_ids( $location ) );

		$stored = $wpdb->get_col( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_wplc\\_lsearch\\_%'" );
		$this->assertCount( 1, $stored );
		$this->assertStringNotContainsStringIgnoringCase( substr( $license['key'], 14 ), $stored[0] );
	}

	public function test_email_number_and_prefix_searches_still_work(): void {
		$customer = self::factory()->user->create( array( 'user_email' => 'jane.search@example.com' ) );
		$by_email = $this->seed_license( array( 'customer_id' => $customer ) );
		$other    = $this->seed_license();

		$this->assertSame( array( $by_email['id'] ), $this->listed_ids( $this->search( 'Jane.Search@' ) ) );
		$this->assertSame( array( $other['id'] ), $this->listed_ids( $this->search( '#' . $other['id'] ) ) );

		$prefix = LicenseKey::prefix( $other['uuid'] );
		$this->assertSame( array( $other['id'] ), $this->listed_ids( $this->search( $prefix ) ) );
		// A cut-off key is reduced to its public prefix and still finds the license.
		$this->assertSame( array( $other['id'] ), $this->listed_ids( $this->search( substr( $other['key'], 0, 60 ) ) ) );
	}

	public function test_an_empty_search_redirects_to_the_unfiltered_list(): void {
		$location = $this->search( '   ' );

		$this->assertStringNotContainsString( LicenseSearchStore::QUERY_ARG, $location );
	}

	public function test_a_token_of_another_administrator_or_a_legacy_term_in_the_url_filters_nothing(): void {
		$license  = $this->seed_license();
		$other    = $this->seed_license();
		$location = $this->search( $license['key'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		$listed = $this->listed_ids( $location );
		$this->assertContains( $license['id'], $listed );
		$this->assertContains( $other['id'], $listed );
		$this->assertTrue( LicenseListTable::request_filters()['search_expired'] );

		wp_set_current_user( $this->manager );
		$this->assertContains( $other['id'], $this->listed_ids( add_query_arg( 's', rawurlencode( $license['key'] ), LicenseAdmin::list_url() ) ) );
	}

	public function test_search_requires_capability_and_nonce(): void {
		$missing = $this->run_admin_post( 'wplc_admin_license_search', array( 's' => 'x' ) );
		$this->assertSame( array( 'die', 403 ), array( $missing['outcome'], $missing['status'] ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );
		$denied = $this->run_admin_post(
			'wplc_admin_license_search',
			array(
				's'        => 'x',
				'_wpnonce' => wp_create_nonce( 'wplc_admin_license_search' ),
			)
		);
		$this->assertSame( array( 'die', 403 ), array( $denied['outcome'], $denied['status'] ) );
	}
}
