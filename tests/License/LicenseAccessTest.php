<?php
/**
 * Access and grace-period rules.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\License\LicenseLifecycle;

final class LicenseAccessTest extends TestCase {

	private const NOW = 1800000000;

	private function license( array $overrides ): array {
		return array_merge(
			array(
				'status' => 'active',
				'paid_until' => gmdate( 'Y-m-d H:i:s', self::NOW + 86400 ),
				'renewal_enabled' => 0,
				'cancel_at_period_end' => 0,
			),
			$overrides
		);
	}

	private function ago( int $days ): string {
		return gmdate( 'Y-m-d H:i:s', self::NOW - $days * 86400 );
	}

	public function test_paid_active_license_has_access(): void {
		$this->assertTrue( LicenseLifecycle::has_access( $this->license( array() ), self::NOW ) );
	}

	public function test_non_renewing_license_ends_exactly_at_paid_until(): void {
		$this->assertFalse( LicenseLifecycle::has_access( $this->license( array( 'paid_until' => $this->ago( 1 ) ) ), self::NOW ) );
	}

	public function test_cancelled_license_gets_no_grace(): void {
		$license = $this->license( array( 'paid_until' => $this->ago( 1 ), 'renewal_enabled' => 1, 'cancel_at_period_end' => 1 ) );
		$this->assertFalse( LicenseLifecycle::has_access( $license, self::NOW ) );
	}

	public function test_renewing_and_past_due_licenses_keep_access_during_grace(): void {
		$this->assertTrue( LicenseLifecycle::has_access( $this->license( array( 'paid_until' => $this->ago( 1 ), 'renewal_enabled' => 1 ) ), self::NOW ) );
		$this->assertTrue( LicenseLifecycle::has_access( $this->license( array( 'status' => 'past_due', 'paid_until' => $this->ago( 13 ) ) ), self::NOW ) );
	}

	public function test_grace_ends_after_fourteen_days(): void {
		$this->assertFalse( LicenseLifecycle::has_access( $this->license( array( 'status' => 'past_due', 'paid_until' => $this->ago( 14 ) ) ), self::NOW ) );
	}

	public function test_blocked_statuses_never_have_access(): void {
		foreach ( array( 'pending', 'suspended', 'revoked', 'expired' ) as $status ) {
			$this->assertFalse( LicenseLifecycle::has_access( $this->license( array( 'status' => $status ) ), self::NOW ), $status );
		}
	}

	public function test_entitlement_cutoff_moves_before_paid_until_only_in_grace(): void {
		$in_grace = $this->license( array( 'status' => 'past_due', 'paid_until' => $this->ago( 3 ) ) );
		$this->assertLessThan( $in_grace['paid_until'], LicenseLifecycle::entitlement_cutoff( $in_grace, self::NOW ) );

		$expired = $this->license( array( 'paid_until' => $this->ago( 3 ) ) );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', self::NOW ), LicenseLifecycle::entitlement_cutoff( $expired, self::NOW ) );
	}
}
