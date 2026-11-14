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
		return array_map( 'intval', wp_list_pluck( $this->list_table( $location )->items, 'id' ) );
	}

	/**
	 * Prepared list table for a list URL.
	 *
	 * @param string $location List URL.
	 * @return LicenseListTable
	 */
	private function list_table( string $location ): LicenseListTable {
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

		return $table;
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

	public function test_rewritten_text_and_number_searches_match_like_the_plain_like_filters(): void {
		$day   = static function ( int $days ): string {
			return gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		};
		$alpha = $this->seed_license( array( 'key_prefix' => 'WPLC-AB12CD34', 'product_key' => 't89-alpha', 'plan_key' => 'gold', 'created_at' => $day( 3 ) ) );
		$beta  = $this->seed_license( array( 'key_prefix' => 'WPLC-0012AB99', 'product_key' => 't89-beta', 'plan_key' => 'silver-12', 'created_at' => $day( 2 ) ) );
		$gamma = $this->seed_license( array( 'key_prefix' => 'WPLC-99999999', 'product_key' => 't89-beta', 'plan_key' => 'single', 'created_at' => $day( 1 ) ) );

		// Key prefix fragments: inside the hex part, anchored at "WPLC-" (any case), impossible characters.
		$this->assertSame( array( $alpha['id'] ), $this->listed_ids( $this->search( '12CD' ) ) );
		$this->assertSame( array( $beta['id'], $alpha['id'] ), $this->listed_ids( $this->search( 'ab' ) ) );
		$this->assertSame( array( $alpha['id'] ), $this->listed_ids( $this->search( 'wplc-ab12' ) ) );
		$this->assertSame( array(), $this->listed_ids( $this->search( 'zzz-no-match' ) ) );
		// A number matches license ID, customer ID or a key prefix fragment.
		$this->assertSame( array( $beta['id'] ), $this->listed_ids( $this->search( '0012' ) ) );
		$this->assertSame( array( $gamma['id'] ), $this->listed_ids( $this->search( '9999' ) ) );
		// Product and plan keys, case-insensitive; newest first.
		$this->assertSame( array( $gamma['id'], $beta['id'] ), $this->listed_ids( $this->search( 'T89-BETA' ) ) );
		$this->assertSame( array( $alpha['id'] ), $this->listed_ids( $this->search( 'gold' ) ) );
		$this->assertSame( array( $beta['id'] ), $this->listed_ids( $this->search( 'silver-1' ) ) );
		// Oldest first: the deferred join keeps the order of the IDs.
		$this->assertSame( array( $alpha['id'], $beta['id'], $gamma['id'] ), $this->listed_ids( $this->search( 't89-', array( 'orderby' => 'created', 'order' => 'asc' ) ) ) );
	}

	public function test_total_count_with_and_without_count_query(): void {
		for ( $i = 0; $i < LicenseListTable::PER_PAGE + 2; $i++ ) {
			$this->seed_license( array( 'status' => 'suspended', 'created_at' => gmdate( 'Y-m-d H:i:s', time() - $i ) ) );
		}
		$this->seed_license( array( 'status' => 'revoked' ) );
		$list = add_query_arg( 'status', 'suspended', LicenseAdmin::list_url() );

		$first = $this->list_table( $list );
		$this->assertCount( LicenseListTable::PER_PAGE, $first->items );
		$this->assertSame( LicenseListTable::PER_PAGE + 2, (int) $first->get_pagination_arg( 'total_items' ) );
		$this->assertSame( 2, (int) $first->get_pagination_arg( 'total_pages' ) );

		$second = $this->list_table( add_query_arg( 'paged', 2, $list ) );
		$this->assertCount( 2, $second->items );
		$this->assertSame( LicenseListTable::PER_PAGE + 2, (int) $second->get_pagination_arg( 'total_items' ) );

		$revoked = $this->list_table( add_query_arg( 'status', 'revoked', LicenseAdmin::list_url() ) );
		$this->assertCount( 1, $revoked->items );
		$this->assertSame( 1, (int) $revoked->get_pagination_arg( 'total_items' ) );
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
