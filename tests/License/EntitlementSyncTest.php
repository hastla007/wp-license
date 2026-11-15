<?php
/**
 * A-23: comparing a license's entitlement snapshot with its plan.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\License\EntitlementSync;

final class EntitlementSyncTest extends TestCase {

	public function test_new_feature_and_higher_limit_are_no_reduction(): void {
		$diff = EntitlementSync::diff(
			array( 'feature.export' => true, 'sites.live_limit' => 1, 'support.level' => 'standard' ),
			array( 'feature.export' => true, 'feature.reports' => true, 'sites.live_limit' => 3, 'support.level' => 'priority' )
		);

		$this->assertSame( array( 'feature.reports' ), $diff['added'] );
		$this->assertSame( array( 'sites.live_limit', 'support.level' ), $diff['changed'] );
		$this->assertSame( array(), $diff['removed'] );
		$this->assertSame( array(), $diff['reductions'] );
	}

	public function test_removed_feature_and_lower_values_are_reductions(): void {
		$diff = EntitlementSync::diff(
			array( 'feature.export' => true, 'feature.legacy' => true, 'feature.off' => false, 'sites.live_limit' => 5, 'support.level' => 'priority' ),
			array( 'feature.export' => false, 'sites.live_limit' => 3, 'support.level' => 'standard' )
		);

		$this->assertSame( array( 'feature.legacy', 'feature.off' ), $diff['removed'] );
		$this->assertSame( array( 'feature.export', 'feature.legacy', 'sites.live_limit', 'support.level' ), $diff['reductions'], 'Removing a key that granted nothing (false) is no reduction.' );
	}

	public function test_identical_snapshot_has_no_difference(): void {
		$same = array( 'feature.export' => true, 'sites.live_limit' => 2 );
		$diff = EntitlementSync::diff( $same, $same );

		$this->assertSame( array(), array_merge( $diff['added'], $diff['changed'], $diff['removed'], $diff['reductions'] ) );
	}

	public function test_reduction_rules_per_value_type(): void {
		$this->assertFalse( EntitlementSync::reduces( 'feature.x', false, true ), 'Granting something that was off.' );
		$this->assertFalse( EntitlementSync::reduces( 'feature.x', 0, 3 ) );
		$this->assertTrue( EntitlementSync::reduces( 'feature.x', true, 1 ), 'true becoming a number is not the same grant.' );
		$this->assertTrue( EntitlementSync::reduces( 'feature.limit', 10, '20' ), 'A number becoming a string.' );
		$this->assertFalse( EntitlementSync::reduces( 'feature.limit', 10, 10.5 ) );
		$this->assertTrue( EntitlementSync::reduces( 'feature.label', 'gold', 'silver' ), 'Strings have no order.' );
		$this->assertTrue( EntitlementSync::reduces( 'support.level', 'standard', 'community' ) );
		$this->assertFalse( EntitlementSync::reduces( 'support.level', 'community', 'standard' ) );
		$this->assertTrue( EntitlementSync::reduces( 'support.level', 'standard', 'vip' ), 'Unknown level: treated like any string.' );
	}
}
