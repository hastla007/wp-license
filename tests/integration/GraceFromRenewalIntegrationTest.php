<?php
/**
 * P2-I3: access, the signed grace end and the emails follow the grace period of the open renewal.
 *
 * Retries and the expiry of a renewal run until its stored `grace_ends_at`. Shortening the setting
 * "Grace period after the paid period" while a license is in its grace period must not cut off its
 * access while it is still charged.
 *
 * @package WPLC
 */

use WPLC\Api\V1\EntitlementService;
use WPLC\License\LicenseLifecycle;
use WPLC\Notifications\Notifications;
use WPLC\Settings\Registry;

final class GraceFromRenewalIntegrationTest extends WPLC_Integration_TestCase {

	public function set_up(): void {
		parent::set_up();
		LicenseLifecycle::reset_grace_cache();
	}

	public function tear_down(): void {
		LicenseLifecycle::reset_grace_cache();
		parent::tear_down();
	}

	private function renewal( int $license_id, string $period_start, ?string $grace_ends_at, string $status = 'failed' ): void {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$this->assertSame(
			1,
			$wpdb->insert(
				$this->tables()['renewals'],
				array(
					'license_id' => $license_id,
					'period_start' => $period_start,
					'period_end' => gmdate( 'Y-m-d H:i:s', strtotime( $period_start . ' UTC' ) + 365 * DAY_IN_SECONDS ),
					'idempotency_key' => hash( 'sha256', $license_id . '|' . $period_start . '|' . $status ),
					'status' => $status,
					'grace_ends_at' => $grace_ends_at,
					'attempt_count' => 1,
					'created_at' => $now,
					'updated_at' => $now,
				)
			)
		);
	}

	public function test_access_and_grace_end_come_from_the_open_renewal(): void {
		$paid_until = gmdate( 'Y-m-d H:i:s', time() - 5 * DAY_IN_SECONDS );
		$grace_end  = gmdate( 'Y-m-d H:i:s', strtotime( $paid_until . ' UTC' ) + 14 * DAY_IN_SECONDS );
		$seeded     = $this->seed_license( array( 'status' => 'past_due', 'renewal_enabled' => 1, 'paid_until' => $paid_until, 'next_payment_at' => $paid_until ) );
		$this->renewal( $seeded['id'], $paid_until, $grace_end );

		// The shop shortens the grace period to three days while the license is in its grace period.
		update_option( Registry::OPTION, array( 'renewal_grace_days' => '3' ) );
		$this->assertSame( 3, LicenseLifecycle::grace_days() );
		$license = $this->license( $seeded['id'] );

		$this->assertSame( strtotime( $grace_end . ' UTC' ), LicenseLifecycle::grace_end( $license ) );
		$this->assertTrue( LicenseLifecycle::has_access( $license ), 'The renewal is still retried, so access stays.' );
		$fields = EntitlementService::access_fields( $license );
		$this->assertTrue( $fields['access'] );
		$this->assertSame( $grace_end, $fields['grace_until'] );
		$this->assertSame( $grace_end, $fields['access_until'] );
		$this->assertFalse( LicenseLifecycle::has_access( $license, strtotime( $grace_end . ' UTC' ) ), 'Access ends with the stored grace period.' );
		$view = Notifications::view( $license );
		$this->assertSame( Notifications::format_date( strtotime( $grace_end . ' UTC' ) ), $view['grace_until'] );

		// A longer setting does not extend a renewal that was scheduled with a shorter grace period either.
		update_option( Registry::OPTION, array( 'renewal_grace_days' => '30' ) );
		$this->assertSame( $grace_end, EntitlementService::access_fields( $license )['grace_until'] );
	}

	public function test_without_an_open_renewal_the_setting_applies(): void {
		$paid_until = gmdate( 'Y-m-d H:i:s', time() - 5 * DAY_IN_SECONDS );
		$seeded     = $this->seed_license( array( 'status' => 'past_due', 'renewal_enabled' => 1, 'paid_until' => $paid_until, 'next_payment_at' => $paid_until ) );
		// A cancelled renewal and one of another period do not count.
		$this->renewal( $seeded['id'], $paid_until, gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ), 'cancelled' );
		$this->renewal( $seeded['id'], gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ), gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ) );
		update_option( Registry::OPTION, array( 'renewal_grace_days' => '3' ) );
		$license = $this->license( $seeded['id'] );

		$this->assertSame( strtotime( $paid_until . ' UTC' ) + 3 * DAY_IN_SECONDS, LicenseLifecycle::grace_end( $license ) );
		$this->assertFalse( LicenseLifecycle::has_access( $license ) );
		$this->assertNull( EntitlementService::access_fields( $license )['access_until'] );
	}
}
