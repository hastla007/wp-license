<?php
/**
 * License catalog CSV: reasons, Excel files, capability, locks, time budget.
 *
 * @package WPLC
 */

use WPLC\Admin\CatalogCsv;
use WPLC\Catalog\Catalog;

final class CatalogCsvIntegrationTest extends WPLC_Integration_TestCase {

	const HEADER = 'product_id,variation_id,sku,product_key,plan_key,billing_interval,live_site_limit,support_level,entitlements_json';

	/**
	 * Temporary files of the test.
	 *
	 * @var string[]
	 */
	private $files = array();

	public function tear_down(): void {
		foreach ( $this->files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		remove_role( 'wplc_support' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function file( string $content ): string {
		$path = tempnam( sys_get_temp_dir(), 'wplc-csv-' );
		file_put_contents( $path, $content );
		$this->files[] = $path;
		return $path;
	}

	private function product_with_sku( string $sku, string $price = '49.00' ): WC_Product_Simple {
		$product = $this->create_simple_product( $price );
		$product->set_sku( $sku );
		$product->save();
		return $product;
	}

	private function row( WC_Product $product, array $overrides = array(), string $delimiter = ',' ): string {
		$values = array_merge(
			array(
				'product_id'        => (string) $product->get_id(),
				'variation_id'      => '0',
				'sku'               => (string) $product->get_sku( 'edit' ),
				'product_key'       => 'voucher-captain',
				'plan_key'          => 'pro',
				'billing_interval'  => 'year',
				'live_site_limit'   => '3',
				'support_level'     => 'standard',
				'entitlements_json' => '{"voucher.reports":true}',
			),
			$overrides
		);
		$handle = fopen( 'php://memory', 'w+' );
		fputcsv( $handle, array_values( $values ), $delimiter, '"', '\\' );
		rewind( $handle );
		$line = stream_get_contents( $handle );
		fclose( $handle );
		return $line;
	}

	/** B-137 (reviewers' test R8): the catalog capability is enough; edit_products is not needed as well. */
	public function test_b137_import_needs_only_the_catalog_capability(): void {
		add_role( 'wplc_support', 'Support', array( 'read' => true, 'manage_woocommerce' => true ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'wplc_support' ) ) );
		$product = $this->product_with_sku( 'CSV-R8' );
		$this->assertFalse( current_user_can( 'edit_post', $product->get_id() ) );

		$result = CatalogCsv::import_file( $this->file( self::HEADER . "\n" . $this->row( $product ) ) );

		$this->assertSame( array( 1, 0, array() ), array( $result['imported'], $result['failed'], $result['errors'] ) );
		$plan = Catalog::find_mapping( $product->get_id() );
		$this->assertSame( array( 'voucher-captain', 'pro', '3' ), array( $plan['product_key'], $plan['plan_key'], (string) $plan['live_site_limit'] ) );
		$this->assertSame( 'yes', wc_get_product( $product->get_id() )->get_meta( '_wplc_is_license_product', true ) );

		// T-143: the import is audited.
		global $wpdb;
		$t = $this->tables();
		$this->assertSame( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['audit_log']} WHERE action = 'catalog_csv_imported'" ) );
	}

	/** A-58: every rejected row names its line and reason; keys follow the rules of the product editor. */
	public function test_a58_rejected_rows_carry_line_and_reason(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		$good = $this->product_with_sku( 'CSV-1' );
		$bad  = $this->product_with_sku( 'CSV-2' );

		$csv = self::HEADER . "\n"
			. $this->row( $good )
			. $this->row( $bad, array( 'sku' => 'OTHER-SHOP' ) )
			. $this->row( $bad, array( 'plan_key' => 'Jährlich' ) )
			. $this->row( $bad, array( 'product_key' => 'under_score' ) )
			. $this->row( $bad, array( 'billing_interval' => 'weekly' ) )
			. $this->row( $bad, array( 'live_site_limit' => '0' ) )
			. $this->row( $bad, array( 'entitlements_json' => '[1,2' ) )
			. $this->row( $bad, array( 'product_id' => '99999999' ) )
			. "1,2,3\n"
			. "\n";
		$result = CatalogCsv::import_file( $this->file( $csv ) );

		$this->assertSame( 1, $result['imported'] );
		$this->assertSame( 8, $result['failed'] );
		$this->assertSame(
			array(
				3  => 'sku_mismatch',
				4  => 'invalid_plan_key',
				5  => 'invalid_product_key',
				6  => 'invalid_interval',
				7  => 'invalid_site_limit',
				8  => 'invalid_entitlements',
				9  => 'product_not_found',
				10 => 'column_count',
			),
			array_column( $result['errors'], 'reason', 'line' )
		);
		$this->assertNull( Catalog::find_mapping( $bad->get_id() ), 'B-123: "Jährlich" is not stored as "jhrlich".' );
		foreach ( $result['errors'] as $error ) {
			$this->assertNotSame( '', CatalogCsv::reason_message( $error['reason'] ) );
		}

		// The result screen shows the reasons.
		set_transient( 'wplc_catalog_csv_result_' . get_current_user_id(), $result, 60 );
		ob_start();
		CatalogCsv::render();
		$html = ob_get_clean();
		$this->assertStringContainsString( CatalogCsv::reason_message( 'sku_mismatch' ), html_entity_decode( $html, ENT_QUOTES ) );
		$this->assertStringContainsString( '<label for="wplc-catalog-csv-file">', $html, 'A-128: the file field has a label.' );
		$this->assertSame( 0, substr_count( $html, 'id="_wpnonce"' ), 'No duplicate IDs from two nonce fields.' );

		$this->assertSame( 'bad_header', CatalogCsv::import_file( $this->file( "id,sku\n1,2\n" ) )['error'] );
	}

	/** A-58: a file saved by a German Excel (semicolons, byte order mark, CRLF) imports. */
	public function test_a58_semicolon_file_with_bom_imports(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		$product = $this->product_with_sku( 'CSV-DE' );
		$csv     = "\xEF\xBB\xBF" . str_replace( ',', ';', self::HEADER ) . "\r\n" . rtrim( $this->row( $product, array( 'plan_key' => 'agency', 'entitlements_json' => '{"voucher.reports":true,"voucher.white_label":true}' ), ';' ), "\n" ) . "\r\n";

		$result = CatalogCsv::import_file( $this->file( $csv ) );

		$this->assertSame( array( 1, 0 ), array( $result['imported'], $result['failed'] ), wp_json_encode( $result ) );
		$plan = Catalog::find_mapping( $product->get_id() );
		$this->assertSame( 'agency', $plan['plan_key'] );
		$this->assertTrue( json_decode( $plan['entitlements_json'], true )['voucher.white_label'] );
	}

	/** A-103: the export does not hand formulas to spreadsheet programs, and its own file imports again. */
	public function test_a103_export_escapes_formulas_and_round_trips(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		$product = $this->product_with_sku( '=HYPERLINK("http://evil.test","x")' );
		// Mapped in the catalog only: the export reads the table, not the product meta (B-21).
		$this->assertTrue( Catalog::save_mapping( $product->get_id(), 0, $this->mapping_data( array( 'plan_key' => 'export' ) ) ) );

		$rows = array_values(
			array_filter(
				CatalogCsv::export_rows(),
				static function ( $row ) use ( $product ) {
					return (string) $product->get_id() === $row[0];
				}
			)
		);
		$this->assertCount( 1, $rows );
		$this->assertSame( "'=HYPERLINK(\"http://evil.test\",\"x\")", $rows[0][2] );
		$this->assertSame( array( 'test-product', 'export', 'year' ), array( $rows[0][3], $rows[0][4], $rows[0][5] ) );
		$this->assertSame( "'-1", CatalogCsv::escape_cell( '-1' ) );
		$this->assertSame( 'plain', CatalogCsv::escape_cell( 'plain' ) );
		$this->assertSame( "it's", CatalogCsv::unescape_cell( "it's" ) );

		$handle = fopen( 'php://memory', 'w+' );
		fputcsv( $handle, explode( ',', self::HEADER ), ',', '"', '\\' );
		fputcsv( $handle, $rows[0], ',', '"', '\\' );
		rewind( $handle );
		$csv = stream_get_contents( $handle );
		fclose( $handle );

		$plan_id = Catalog::find_mapping( $product->get_id() )['id'];
		$result  = CatalogCsv::import_file( $this->file( $csv ) );
		$this->assertSame( array( 1, 0 ), array( $result['imported'], $result['failed'] ), wp_json_encode( $result ) );
		$this->assertSame( $plan_id, Catalog::find_mapping( $product->get_id() )['id'], 'B-51: importing again keeps the plan ID.' );
	}

	/** B-136: a product that is open in the editor is not changed behind the editor's back. */
	public function test_b136_locked_product_is_skipped(): void {
		$other = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		$me    = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		$product = $this->product_with_sku( 'CSV-LOCK' );

		require_once ABSPATH . 'wp-admin/includes/post.php';
		wp_set_current_user( $other );
		wp_set_post_lock( $product->get_id() );
		wp_set_current_user( $me );

		$result = CatalogCsv::import_file( $this->file( self::HEADER . "\n" . $this->row( $product ) ) );
		$this->assertSame( 0, $result['imported'] );
		$this->assertSame( 'product_locked', $result['errors'][0]['reason'] );
		$this->assertNull( Catalog::find_mapping( $product->get_id() ) );

		delete_post_meta( $product->get_id(), '_edit_lock' );
		$result = CatalogCsv::import_file( $this->file( self::HEADER . "\n" . $this->row( $product ) ) );
		$this->assertSame( 1, $result['imported'] );
	}

	/** B-127: the import stops before the time limit and says where. */
	public function test_b127_time_budget_stops_with_a_position(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		$first  = $this->product_with_sku( 'CSV-T1' );
		$second = $this->product_with_sku( 'CSV-T2' );
		$csv    = self::HEADER . "\n" . $this->row( $first ) . $this->row( $second );

		add_filter( 'wplc_catalog_csv_time_budget', '__return_zero' );
		$result = CatalogCsv::import_file( $this->file( $csv ) );
		remove_filter( 'wplc_catalog_csv_time_budget', '__return_zero' );

		$this->assertSame( 0, $result['imported'] );
		$this->assertSame( 2, $result['stopped_at'], 'Stopped before line 2, nothing lost silently.' );

		// The same file again finishes the work.
		$result = CatalogCsv::import_file( $this->file( $csv ) );
		$this->assertSame( array( 2, 0, 0 ), array( $result['imported'], $result['failed'], $result['stopped_at'] ) );
	}
}
