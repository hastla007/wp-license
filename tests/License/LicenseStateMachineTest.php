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

	public function test_automatic_renewal_never_reactivates_an_expired_license(): void {
		$this->assertFalse( LicenseStateMachine::allows( 'expired', 'active', 'renewal_succeeded' ) );
		$this->assertFalse( LicenseStateMachine::can_apply_renewal_payment( 'expired' ) );
	}

	public function test_manual_renewal_reactivates_expired_license_only_inside_the_window(): void {
		$paid_until = strtotime( '2026-12-01 12:00:00 UTC' );
		$day = 86400;

		$this->assertSame( 30, LicenseStateMachine::MANUAL_RENEWAL_WINDOW_DAYS );
		$this->assertTrue( LicenseStateMachine::can_apply_manual_renewal( 'expired', $paid_until, $paid_until + 1 ) );
		$this->assertTrue( LicenseStateMachine::can_apply_manual_renewal( 'expired', $paid_until, $paid_until + 30 * $day ) );
		$this->assertFalse( LicenseStateMachine::can_apply_manual_renewal( 'expired', $paid_until, $paid_until + 30 * $day + 1 ) );
		$this->assertFalse( LicenseStateMachine::can_apply_manual_renewal( 'expired', 0, $paid_until ) );
	}

	public function test_manual_renewal_follows_the_automatic_rules_for_other_states(): void {
		$paid_until = strtotime( '2026-12-01 12:00:00 UTC' );

		$this->assertTrue( LicenseStateMachine::can_apply_manual_renewal( 'active', $paid_until, $paid_until - 86400 ) );
		$this->assertTrue( LicenseStateMachine::can_apply_manual_renewal( 'past_due', $paid_until, $paid_until + 86400 ) );
		$this->assertFalse( LicenseStateMachine::can_apply_manual_renewal( 'suspended', $paid_until, $paid_until ) );
		$this->assertFalse( LicenseStateMachine::can_apply_manual_renewal( 'revoked', $paid_until, $paid_until ) );
		$this->assertFalse( LicenseStateMachine::can_apply_manual_renewal( 'pending', $paid_until, $paid_until ) );
	}

	public function test_admin_extend_reactivates_expired_but_not_revoked_or_suspended(): void {
		$this->assertTrue( LicenseStateMachine::allows( 'active', 'active', 'admin_extend' ) );
		$this->assertTrue( LicenseStateMachine::allows( 'past_due', 'active', 'admin_extend' ) );
		$this->assertTrue( LicenseStateMachine::allows( 'expired', 'active', 'admin_extend' ) );
		$this->assertFalse( LicenseStateMachine::allows( 'revoked', 'active', 'admin_extend' ) );
		$this->assertFalse( LicenseStateMachine::allows( 'suspended', 'active', 'admin_extend' ) );
	}
}
