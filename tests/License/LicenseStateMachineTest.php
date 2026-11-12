<?php
use PHPUnit\Framework\TestCase;
use WPLC\License\LicenseStateMachine;

final class LicenseStateMachineTest extends TestCase {
	public function test_only_configured_lifecycle_transitions_are_allowed(): void {
		$this->assertTrue( LicenseStateMachine::allows( 'active', 'past_due', 'renewal_failed' ) );
		$this->assertTrue( LicenseStateMachine::allows( 'past_due', 'expired', 'license_expired' ) );
		$this->assertTrue( LicenseStateMachine::allows( 'active', 'revoked', 'order_refund' ) );
		$this->assertTrue( LicenseStateMachine::allows( 'past_due', 'suspended', 'chargeback' ) );

		$this->assertFalse( LicenseStateMachine::allows( 'revoked', 'active', 'admin_status_change' ) );
		$this->assertFalse( LicenseStateMachine::allows( 'expired', 'past_due', 'renewal_failed' ) );
		$this->assertFalse( LicenseStateMachine::allows( 'active', 'expired', 'chargeback' ) );
		$this->assertFalse( LicenseStateMachine::allows( 'unknown', 'active', 'renewal_succeeded' ) );
	}

	public function test_successful_renewal_cannot_restore_suspended_or_revoked_licenses(): void {
		$this->assertTrue( LicenseStateMachine::can_apply_renewal_payment( 'active' ) );
		$this->assertTrue( LicenseStateMachine::can_apply_renewal_payment( 'past_due' ) );
		$this->assertFalse( LicenseStateMachine::can_apply_renewal_payment( 'suspended' ) );
		$this->assertFalse( LicenseStateMachine::can_apply_renewal_payment( 'revoked' ) );
		$this->assertFalse( LicenseStateMachine::can_apply_renewal_payment( 'expired' ) );
	}
}
