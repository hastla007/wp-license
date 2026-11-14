<?php
/**
 * Audit summary of a catalog CSV import.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\Admin\CatalogCsv;

final class CatalogCsvAuditTest extends TestCase {

	public function test_summary_holds_counts_and_unique_product_ids(): void {
		$summary = CatalogCsv::import_summary( 'completed', 'catalog.csv', 4, 3, 1, array( 12, 15, 12 ) );

		$this->assertSame(
			array(
				'outcome' => 'completed',
				'file' => 'catalog.csv',
				'rows' => 4,
				'imported' => 3,
				'failed' => 1,
				'product_ids' => array( 12, 15 ),
				'product_ids_truncated' => false,
			),
			$summary
		);
	}

	public function test_summary_caps_the_product_id_list(): void {
		$summary = CatalogCsv::import_summary( 'row_limit_reached', str_repeat( 'a', 300 ), 1000, 999, 2, range( 1, 999 ) );

		$this->assertCount( 200, $summary['product_ids'] );
		$this->assertTrue( $summary['product_ids_truncated'] );
		$this->assertSame( 100, strlen( $summary['file'] ) );
	}
}
