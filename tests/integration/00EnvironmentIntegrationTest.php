<?php
/**
 * Proves the suite runs against the intended WordPress/WooCommerce/order-storage combination.
 *
 * @package WPLC
 */

final class EnvironmentIntegrationTest extends WPLC_Integration_TestCase {
	public function test_woocommerce_and_the_plugin_are_loaded(): void {
		$this->assertTrue( class_exists( 'WooCommerce' ) );
		$this->assertTrue( defined( 'WPLC_VERSION' ) );
		$this->assertSame( 1, did_action( 'wplc/loaded' ), 'The plugin did not finish booting behind WooCommerce.' );
		$this->assertNotFalse( has_action( 'woocommerce_payment_complete', array( \WPLC\License\LicenseIssuer::class, 'maybe_issue_order' ) ) );
	}

	public function test_versions_match_the_requested_matrix_cell(): void {
		$expected_wc = (string) getenv( 'WPLC_EXPECT_WC_VERSION' );
		if ( '' !== $expected_wc ) {
			$this->assertSame( $expected_wc, WC()->version, 'The suite is not running against the WooCommerce version of this matrix cell.' );
		}
		$expected_wp = (string) getenv( 'WPLC_EXPECT_WP_VERSION' );
		if ( '' !== $expected_wp && 'latest' !== $expected_wp ) {
			$this->assertSame( 0, strpos( $GLOBALS['wp_version'], $expected_wp ), 'Running WordPress ' . $GLOBALS['wp_version'] . ', expected ' . $expected_wp );
		}
		$this->assertTrue( version_compare( $GLOBALS['wp_version'], '6.5', '>=' ), 'The plugin declares WordPress 6.5 as its minimum.' );
	}

	public function test_order_storage_matches_the_requested_mode(): void {
		global $wpdb;

		$expected_hpos = '0' !== (string) getenv( 'WPLC_TEST_HPOS' );
		$this->assertSame( $expected_hpos, $this->hpos_enabled() );

		$order     = $this->create_order( array( array( $this->create_simple_product(), 1 ) ) );
		$post_type = (string) get_post_type( $order->get_id() );

		if ( $expected_hpos ) {
			$in_hpos_table = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE id = %d", $order->get_id() ) );
			$this->assertSame( 1, $in_hpos_table, 'The order was not written to the HPOS table.' );
			$this->assertNotSame( 'shop_order', $post_type, 'An HPOS run must not store the order as a shop_order post.' );
		} else {
			$this->assertSame( 'shop_order', $post_type );
		}
	}

	public function test_plugin_tables_are_permanent_tables(): void {
		global $wpdb;

		foreach ( $this->tables() as $table ) {
			$count = $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s', $table )
			);
			$this->assertSame( '1', (string) $count, 'Missing table ' . $table );
		}
	}
}
