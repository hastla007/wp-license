<?php
/**
 * A-22: which licenses may move their sites, and to which license.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\License\ActivationTransfer;

require_once dirname( __DIR__ ) . '/Admin/wp-function-stubs.php';

final class ActivationTransferTest extends TestCase {

	private const NOW = 1800000000;

	/**
	 * @param array<string,mixed> $overrides Fields.
	 * @return array<string,mixed>
	 */
	private function license( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'                   => 1,
				'customer_id'          => 7,
				'product_key'          => 'pro',
				'status'               => 'active',
				'renewal_enabled'      => 0,
				'cancel_at_period_end' => 0,
				'paid_until'           => gmdate( 'Y-m-d H:i:s', self::NOW + 200 * 86400 ),
			),
			$overrides
		);
	}

	private function days( int $days ): string {
		return gmdate( 'Y-m-d H:i:s', self::NOW + $days * 86400 );
	}

	public function test_ended_and_ending_licenses_may_move_their_sites(): void {
		$this->assertTrue( ActivationTransfer::is_ending( $this->license( array( 'status' => 'expired', 'paid_until' => $this->days( -3 ) ) ), self::NOW ), 'Expired.' );
		$this->assertTrue( ActivationTransfer::is_ending( $this->license( array( 'status' => 'suspended' ) ), self::NOW ), 'Suspended has no access.' );
		$this->assertTrue( ActivationTransfer::is_ending( $this->license( array( 'cancel_at_period_end' => 1, 'renewal_enabled' => 1 ) ), self::NOW ), 'Cancelled for the end of the term.' );
		$this->assertTrue( ActivationTransfer::is_ending( $this->license( array( 'paid_until' => $this->days( 20 ) ) ), self::NOW ), 'Manual renewal, ends within 30 days.' );

		$this->assertFalse( ActivationTransfer::is_ending( $this->license(), self::NOW ), 'Manual renewal, ends in 200 days.' );
		$this->assertFalse( ActivationTransfer::is_ending( $this->license( array( 'renewal_enabled' => 1, 'paid_until' => $this->days( 5 ) ) ), self::NOW ), 'Renews automatically.' );
		$this->assertFalse( ActivationTransfer::is_ending( $this->license( array( 'paid_until' => null ) ), self::NOW ), 'No end.' );
		$this->assertFalse( ActivationTransfer::is_ending( $this->license( array( 'status' => 'pending' ) ), self::NOW ), 'Pending.' );
	}

	public function test_target_is_a_running_license_of_the_same_customer_and_product_that_ends_later(): void {
		$source = $this->license( array( 'status' => 'expired', 'paid_until' => $this->days( -3 ) ) );
		$target = $this->license( array( 'id' => 2, 'paid_until' => $this->days( 365 ) ) );

		$this->assertTrue( ActivationTransfer::is_target( $source, $target, self::NOW ) );
		$this->assertTrue( ActivationTransfer::is_target( $source, array_merge( $target, array( 'paid_until' => null ) ), self::NOW ), 'A license without end.' );
		$this->assertFalse( ActivationTransfer::is_target( $source, $source, self::NOW ), 'Not itself.' );
		$this->assertFalse( ActivationTransfer::is_target( $source, array_merge( $target, array( 'customer_id' => 8 ) ), self::NOW ), 'Another customer.' );
		$this->assertFalse( ActivationTransfer::is_target( $source, array_merge( $target, array( 'product_key' => 'other' ) ), self::NOW ), 'Another product.' );
		$this->assertFalse( ActivationTransfer::is_target( $source, array_merge( $target, array( 'status' => 'suspended' ) ), self::NOW ), 'No access.' );
		$ending = $this->license( array( 'paid_until' => $this->days( 20 ) ) );
		$this->assertFalse( ActivationTransfer::is_target( $ending, $this->license( array( 'id' => 2, 'paid_until' => $this->days( 10 ) ) ), self::NOW ), 'Ends earlier than the source.' );
		$this->assertFalse( ActivationTransfer::is_target( array_merge( $source, array( 'customer_id' => 0 ) ), array_merge( $target, array( 'customer_id' => 0 ) ), self::NOW ), 'Guest licenses have no owner.' );
	}

	public function test_targets_are_listed_only_for_an_ending_license_newest_end_first(): void {
		$source     = $this->license( array( 'paid_until' => $this->days( 10 ) ) );
		$candidates = array(
			$this->license( array( 'id' => 2, 'paid_until' => $this->days( 100 ) ) ),
			$this->license( array( 'id' => 3, 'paid_until' => $this->days( 300 ) ) ),
			$this->license( array( 'id' => 4, 'product_key' => 'other', 'paid_until' => $this->days( 300 ) ) ),
			$source,
		);

		$this->assertSame( array( 3, 2 ), array_map( 'intval', array_column( ActivationTransfer::targets_from( $source, $candidates, self::NOW ), 'id' ) ) );
		$this->assertSame( array(), ActivationTransfer::targets_from( $this->license( array( 'paid_until' => $this->days( 200 ) ) ), $candidates, self::NOW ), 'A license that runs on keeps its sites.' );
	}
}
