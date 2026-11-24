<?php
/**
 * Overrides per plan: limits, values of older versions, capabilities (P2-M5, P4-M1).
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\Catalog\PlanOverrides;
use WPLC\Settings\Registry;

final class PlanOverridesTest extends TestCase {

	/** P2-M5: the offline period is at least 2 days, for the setting and the plan; a stored 1 counts as 2, a 0 as global. */
	public function test_offline_period_is_at_least_two_days(): void {
		$this->assertSame( 2, PlanOverrides::FIELDS['offline_cache_days'][0] );
		$this->assertSame( 2, (int) Registry::definition( 'offline_cache_days' )['min'] );
		$this->assertFalse( PlanOverrides::normalize( 'offline_cache_days', '1' )['ok'] );
		$this->assertSame( 2, PlanOverrides::normalize( 'offline_cache_days', '2' )['value'] );
		$this->assertSame( 2, PlanOverrides::of_plan( array( 'offline_cache_days' => '1' ), 'offline_cache_days' ), 'Stored by an older version.' );
		$this->assertNull( PlanOverrides::of_plan( array( 'offline_cache_days' => '0' ), 'offline_cache_days' ) );
		$this->assertNull( PlanOverrides::of_plan( array( 'offline_cache_days' => '91' ), 'offline_cache_days' ) );
		$this->assertSame( 0, PlanOverrides::of_plan( array( 'staging_site_limit' => '0' ), 'staging_site_limit' ) );
	}

	/** P4-M1: the manufacturer rules need the capability of their setting; the largest quantity stays with the catalog. */
	public function test_capability_of_each_override(): void {
		$expected = array(
			'staging_site_limit'     => 'manage_policy',
			'development_site_limit' => 'manage_policy',
			'offline_cache_days'     => 'manage_policy',
			'max_quantity_per_line'  => '',
			'co_user_limit'          => 'manage_policy',
			'beta_allowed'           => 'manage_policy',
		);
		foreach ( array_keys( PlanOverrides::FIELDS ) as $field ) {
			$this->assertSame( $expected[ $field ], PlanOverrides::capability( $field ), $field );
		}
		$this->assertSame( '', PlanOverrides::capability( 'unknown' ) );
	}
}
