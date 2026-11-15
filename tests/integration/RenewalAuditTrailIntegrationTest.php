<?php
/**
 * A-73: every status change of a renewal leaves an audit entry naming the new status.
 *
 * The short-lived claim `processing` (a charge in flight, held under the renewal
 * lock) is the only status without an entry: it always ends in one of the
 * audited results, and the stuck-renewal sweep audits a claim that never ended.
 *
 * @package WPLC
 */

use WPLC\Billing\ManualRenewal;
use WPLC\Billing\RenewalProcessor;
use WPLC\Billing\RenewalScheduler;
use WPLC\License\LicenseLifecycle;

require_once __DIR__ . '/helpers/RenewalTestCase.php';

final class RenewalAuditTrailIntegrationTest extends WPLC_Renewal_TestCase {

	/**
	 * Run a step and assert that a status change of the renewal was audited.
	 *
	 * @param int      $renewal_id Renewal ID.
	 * @param callable $step Step.
	 * @param string   $expected Status expected afterwards.
	 */
	private function step( int $renewal_id, callable $step, string $expected ): void {
		global $wpdb;
		$tables = $this->tables();
		$before = (string) $this->renewal( $renewal_id )['status'];
		$last   = (int) $wpdb->get_var( "SELECT IFNULL(MAX(id), 0) FROM {$tables['audit_log']}" );

		$step();

		$after = (string) $this->renewal( $renewal_id )['status'];
		$this->assertSame( $expected, $after, 'Fixture: unexpected renewal status.' );
		if ( $before === $after ) {
			return;
		}
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT action, reason, after_json FROM {$tables['audit_log']} WHERE object_type = 'renewal' AND object_id = %d AND id > %d ORDER BY id ASC",
				$renewal_id,
				$last
			),
			ARRAY_A
		);
		$statuses = array();
		foreach ( $rows as $row ) {
			$data = json_decode( (string) $row['after_json'], true );
			if ( is_array( $data ) && isset( $data['status'] ) ) {
				$statuses[] = (string) $data['status'];
			}
		}
		$this->assertNotEmpty( $statuses, sprintf( 'The change %s -> %s left no audit entry.', $before, $after ) );
		$this->assertSame( $after, end( $statuses ), sprintf( 'The last audited status of the change %s -> %s is wrong.', $before, $after ) );
	}

	public function test_charge_results_are_audited(): void {
		global $wpdb;
		$f = $this->renewing_license( -2 * DAY_IN_SECONDS );

		WPLC_Test_Recurring_Adapter::$mode = 'decline';
		$this->step( $f['renewal_id'], static function () use ( $f ) { RenewalProcessor::process( $f['renewal_id'] ); }, 'failed' );

		$wpdb->update( $this->tables()['renewals'], array( 'retry_at' => gmdate( 'Y-m-d H:i:s', time() - 10 ) ), array( 'id' => $f['renewal_id'] ) );
		WPLC_Test_Recurring_Adapter::$mode = 'success';
		$this->step( $f['renewal_id'], static function () use ( $f ) { RenewalProcessor::process( $f['renewal_id'] ); }, 'paid' );
	}

	public function test_declines_needing_the_customer_are_audited(): void {
		foreach ( array( 'hard', 'manual' ) as $mode ) {
			$f = $this->renewing_license();
			WPLC_Test_Recurring_Adapter::$mode = $mode;
			$this->step( $f['renewal_id'], static function () use ( $f ) { RenewalProcessor::process( $f['renewal_id'] ); }, 'manual_action_required' );
		}
	}

	public function test_unknown_result_and_review_are_audited(): void {
		global $wpdb;
		$f = $this->renewing_license();
		WPLC_Test_Recurring_Adapter::$mode = 'unknown';
		$this->step( $f['renewal_id'], static function () use ( $f ) { RenewalProcessor::process( $f['renewal_id'] ); }, RenewalProcessor::STATUS_RECONCILING );

		$wpdb->update(
			$this->tables()['renewals'],
			array(
				'retry_at'        => gmdate( 'Y-m-d H:i:s', time() - 10 ),
				'last_attempt_at' => gmdate( 'Y-m-d H:i:s', time() - RenewalProcessor::UNKNOWN_RESULT_WINDOW - 60 ),
			),
			array( 'id' => $f['renewal_id'] )
		);
		$this->step( $f['renewal_id'], static function () use ( $f ) { RenewalProcessor::process( $f['renewal_id'] ); }, RenewalProcessor::STATUS_REVIEW );
	}

	public function test_switching_renewal_off_and_on_again_is_audited(): void {
		global $wpdb;
		$f = $this->renewing_license( 5 * DAY_IN_SECONDS );

		$this->step( $f['renewal_id'], static function () use ( $f ) { LicenseLifecycle::disable_renewal( $f['license_id'], 'test', 0 ); }, 'cancelled' );
		$this->assertContains( 'renewal_cancelled', $this->audit_actions( 'renewal', $f['renewal_id'] ) );

		// Switched on again for the same period: the cancelled row becomes an automatic renewal again.
		$wpdb->update( $this->tables()['licenses'], array( 'renewal_enabled' => 1 ), array( 'id' => $f['license_id'] ) );
		$this->step( $f['renewal_id'], static function () use ( $f ) { RenewalScheduler::schedule_next( $f['license_id'] ); }, 'scheduled' );
		$this->assertContains( 'renewal_rescheduled', $this->audit_actions( 'renewal', $f['renewal_id'] ) );
	}

	public function test_end_of_grace_is_audited(): void {
		$f = $this->renewing_license( -20 * DAY_IN_SECONDS );
		WPLC_Test_Recurring_Adapter::$mode = 'decline';
		RenewalProcessor::process( $f['renewal_id'] );

		$this->step( $f['renewal_id'], static function () use ( $f ) { RenewalProcessor::expire_after_grace( $f['license_id'] ); }, 'cancelled' );
	}

	public function test_manual_renewal_payment_is_audited(): void {
		$customer = $this->customer();
		$license  = $this->buy( $this->create_license_product(), $customer, false );
		$this->move_due_date( (int) $license['id'], 5 * DAY_IN_SECONDS );
		$order = ManualRenewal::start( (int) $license['id'], $customer );
		$this->assertInstanceOf( WC_Order::class, $order, is_wp_error( $order ) ? $order->get_error_message() : '' );
		$renewal_id = (int) $order->get_meta( '_wplc_renewal_id', true );

		$this->step( $renewal_id, static function () use ( $order ) { $order->payment_complete( 'manual_tx' ); }, 'paid' );
	}
}
