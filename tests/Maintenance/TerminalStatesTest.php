<?php
/**
 * Security audit of 14 Nov 2026 (SA-11): the license states that count as ended for the privacy
 * eraser and for retention are states a license can really have.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\License\LicenseStateMachine;
use WPLC\Maintenance\Retention;
use WPLC\Privacy\PersonalData;

final class TerminalStatesTest extends TestCase {

	/**
	 * Every state the state machine can move a license to.
	 *
	 * @return string[]
	 */
	private static function reachable_states(): array {
		$transitions = ( new ReflectionClass( LicenseStateMachine::class ) )->getConstant( 'TRANSITIONS' );
		$states      = array();
		foreach ( $transitions as $by_state ) {
			foreach ( $by_state as $from => $targets ) {
				$states[] = (string) $from;
				$states   = array_merge( $states, $targets );
			}
		}

		return array_values( array_unique( $states ) );
	}

	public function test_ended_states_exist_and_are_the_same_for_erasure_and_retention(): void {
		$eraser    = ( new ReflectionClass( PersonalData::class ) )->getConstant( 'TERMINAL_STATES' );
		$retention = ( new ReflectionClass( Retention::class ) )->getConstant( 'TERMINAL_STATES' );

		$this->assertSame( array( 'expired', 'revoked' ), $eraser );
		$this->assertSame( $eraser, $retention );
		$this->assertSame( array(), array_values( array_diff( $eraser, self::reachable_states() ) ), 'A state no license can have (such as the renewal state "cancelled") is not listed.' );
		// A suspended license can become active again (admin_restore), so it is not treated as ended.
		$this->assertNotContains( 'suspended', $eraser );
		$this->assertContains( 'suspended', self::reachable_states() );
		$this->assertNotContains( 'cancelled', self::reachable_states() );
	}
}
