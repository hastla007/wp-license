<?php
/**
 * Pure helpers of the customer account page.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\Account\CustomerPortal;

require_once dirname( __DIR__ ) . '/Admin/wp-function-stubs.php';

final class CustomerPortalTest extends TestCase {

	public function test_page_count(): void {
		$this->assertSame( 10, CustomerPortal::PER_PAGE );
		$this->assertSame( 1, CustomerPortal::page_count( 0 ) );
		$this->assertSame( 1, CustomerPortal::page_count( 10 ) );
		$this->assertSame( 2, CustomerPortal::page_count( 11 ) );
		$this->assertSame( 3, CustomerPortal::page_count( 21 ) );
		$this->assertSame( 1, CustomerPortal::page_count( -5 ) );
		$this->assertSame( 4, CustomerPortal::page_count( 4, 0 ) );
	}

	public function test_rows_of_one_grouped_query_are_assigned_to_their_license(): void {
		$rows = array(
			array( 'id' => '5', 'license_id' => '2', 'site_url' => 'https://b.example' ),
			array( 'id' => '4', 'license_id' => '1', 'site_url' => 'https://a.example' ),
			array( 'id' => '3', 'license_id' => '2', 'site_url' => 'https://c.example' ),
			array( 'id' => '9' ),
			'broken',
		);

		$grouped = CustomerPortal::group_rows( $rows, 'license_id' );

		$this->assertSame( array( 2, 1 ), array_keys( $grouped ) );
		$this->assertSame( array( '5', '3' ), array_column( $grouped[2], 'id' ) );
		$this->assertSame( array(), CustomerPortal::group_rows( null, 'license_id' ) );
	}

	public function test_customer_notices_use_woocommerce_notice_types(): void {
		foreach ( CustomerPortal::notice_messages() as $code => $notice ) {
			$this->assertMatchesRegularExpression( '/^[a-z_]+$/', $code );
			$this->assertContains( $notice[0], array( 'success', 'error', 'notice' ), $code );
			$this->assertNotSame( '', $notice[1], $code );
		}
		$this->assertArrayHasKey( 'cancel_withdrawn', CustomerPortal::notice_messages() );
	}
}
