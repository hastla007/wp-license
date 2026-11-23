<?php
/**
 * UEB-10 (owner decision of 23 Nov 2026): the shop decides whether a license that stayed
 * suspended for a number of days counts as ended for the privacy eraser and for retention.
 * Setting `suspended_ends_after_days`, default 0 = never; filter
 * `wplc_suspended_license_ends_after_days`; time of the suspension in `licenses.suspended_at`.
 *
 * @package WPLC
 */

use WPLC\Admin\Settings;
use WPLC\Infrastructure\Database\Schema;
use WPLC\License\EndedLicenses;
use WPLC\License\LicenseLifecycle;
use WPLC\Maintenance\Retention;
use WPLC\Privacy\PersonalData;

final class SuspendedEndedIntegrationTest extends WPLC_Integration_TestCase {

	public function tear_down(): void {
		delete_option( Settings::OPTION );
		remove_all_filters( 'wplc_suspended_license_ends_after_days' );
		parent::tear_down();
	}

	/**
	 * Store the setting the way the settings form does.
	 *
	 * @param int $days Days.
	 */
	private function set_days( int $days ): void {
		update_option( Settings::OPTION, Settings::sanitize( array( 'suspended_ends_after_days' => (string) $days ) ) );
	}

	/**
	 * A suspended license of a customer with one active site, suspended the given number of days ago.
	 *
	 * @param int $customer Customer.
	 * @param int $days_ago Days since the suspension.
	 * @return array{license:int,activation:int}
	 */
	private function suspended( int $customer, int $days_ago ): array {
		global $wpdb;
		$license    = $this->seed_license( array( 'customer_id' => $customer ) );
		$activation = $this->seed_activation( $license['id'] );
		$this->assertTrue( LicenseLifecycle::admin_set_status( $license['id'], 'suspended', 'Dispute', 0 ) );
		$wpdb->update( $this->tables()['licenses'], array( 'suspended_at' => gmdate( 'Y-m-d H:i:s', time() - $days_ago * DAY_IN_SECONDS ) ), array( 'id' => $license['id'] ) );

		return array(
			'license'    => (int) $license['id'],
			'activation' => (int) $activation['id'],
		);
	}

	public function test_the_time_of_the_suspension_is_stored_and_starts_again_with_every_suspension(): void {
		global $wpdb;
		$license = $this->seed_license();
		$this->assertNull( $this->license( $license['id'] )['suspended_at'] );

		$this->assertTrue( LicenseLifecycle::admin_set_status( $license['id'], 'suspended', 'Dispute', 0 ) );
		$first = (string) $this->license( $license['id'] )['suspended_at'];
		$this->assertLessThanOrEqual( 5, abs( time() - strtotime( $first . ' UTC' ) ) );

		// Long ago, then reactivated: the time is gone, and the license is never "ended" while it is active.
		$wpdb->update( $this->tables()['licenses'], array( 'suspended_at' => gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ) ), array( 'id' => $license['id'] ) );
		$this->set_days( 30 );
		$this->assertTrue( EndedLicenses::has_ended( $this->license( $license['id'] ) ) );
		$this->assertTrue( LicenseLifecycle::admin_restore( $license['id'], 'Dispute settled', self::factory()->user->create( array( 'role' => 'administrator' ) ) ) );
		$restored = $this->license( $license['id'] );
		$this->assertSame( 'active', $restored['status'] );
		$this->assertNull( $restored['suspended_at'] );
		$this->assertFalse( EndedLicenses::has_ended( $restored ) );

		// Suspended again: counts from now, not from the first suspension.
		$this->assertTrue( LicenseLifecycle::admin_set_status( $license['id'], 'suspended', 'Second dispute', 0 ) );
		$again = $this->license( $license['id'] );
		$this->assertLessThanOrEqual( 5, abs( time() - strtotime( (string) $again['suspended_at'] . ' UTC' ) ) );
		$this->assertFalse( EndedLicenses::has_ended( $again ), 'Thirty days start again.' );

		// Revoked: no suspension time.
		$this->assertTrue( LicenseLifecycle::admin_set_status( $license['id'], 'revoked', 'Fraud', 0 ) );
		$this->assertNull( $this->license( $license['id'] )['suspended_at'] );
	}

	public function test_schema_step_27_counts_existing_suspensions_from_the_upgrade(): void {
		global $wpdb;
		$old   = $this->seed_license( array( 'status' => 'suspended', 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 500 * DAY_IN_SECONDS ) ) );
		$known = $this->seed_license( array( 'status' => 'suspended' ) );
		$other = $this->seed_license();
		$wpdb->update( $this->tables()['licenses'], array( 'suspended_at' => '2026-01-01 00:00:00' ), array( 'id' => $known['id'] ) );

		$this->assertTrue( Schema::run_registered_step( '27' ) );

		$this->assertLessThanOrEqual( 5, abs( time() - strtotime( (string) $this->license( $old['id'] )['suspended_at'] . ' UTC' ) ), 'Unknown start: counts from the upgrade.' );
		$this->assertSame( '2026-01-01 00:00:00', $this->license( $known['id'] )['suspended_at'], 'A known time is kept; the step can run again.' );
		$this->assertNull( $this->license( $other['id'] )['suspended_at'] );
	}

	public function test_by_default_a_suspended_license_never_counts_as_ended(): void {
		$this->assertSame( 0, Settings::defaults()['suspended_ends_after_days'] );
		$this->assertSame( 0, EndedLicenses::suspended_days() );

		$email    = 'suspended-default@example.test';
		$customer = self::factory()->user->create( array( 'user_email' => $email ) );
		$case     = $this->suspended( $customer, 4000 );

		$result = PersonalData::erase_personal_data( $email, 1 );
		$this->assertTrue( $result['items_retained'] );
		$this->assertSame( $customer, (int) $this->license( $case['license'] )['customer_id'], 'The customer stays on the license.' );

		Retention::run();
		$this->assertSame( 'active', $this->activation( $case['activation'] )['status'], 'Retention leaves the site alone.' );
		$this->assertSame( $customer, (int) $this->license( $case['license'] )['customer_id'] );
	}

	public function test_with_a_period_only_licenses_suspended_that_long_are_erased_like_ended_ones(): void {
		$this->set_days( 90 );
		$this->assertSame( 90, EndedLicenses::suspended_days() );

		$email    = 'suspended-period@example.test';
		$customer = self::factory()->user->create( array( 'user_email' => $email ) );
		$recent   = $this->suspended( $customer, 89 );
		$long     = $this->suspended( $customer, 91 );
		$active   = $this->seed_license( array( 'customer_id' => $customer ) );

		$result = PersonalData::erase_personal_data( $email, 1 );

		$this->assertTrue( $result['items_removed'] );
		$this->assertTrue( $result['items_retained'], 'The active and the recently suspended license stay.' );
		$this->assertSame( 0, (int) $this->license( $long['license'] )['customer_id'], 'Suspended for 91 days: detached like an expired license.' );
		$this->assertNotSame( 'active', (string) ( $this->activation_or_null( $long['activation'] )['status'] ?? 'gone' ), 'Its site is released.' );
		$this->assertSame( $customer, (int) $this->license( $recent['license'] )['customer_id'], 'Suspended for 89 days: untouched.' );
		$this->assertSame( 'active', $this->activation( $recent['activation'] )['status'] );
		$this->assertSame( $customer, (int) $this->license( $active['id'] )['customer_id'] );
		$this->assertSame( 'suspended', $this->license( $long['license'] )['status'], 'The status itself is not changed.' );

		// The erasure is in the audit log like for every ended license.
		global $wpdb;
		$actions = $wpdb->get_col( $wpdb->prepare( "SELECT action FROM {$this->tables()['audit_log']} WHERE object_type = %s AND object_id = %d", 'license', $long['license'] ) );
		$this->assertContains( 'license_erased', $actions );
	}

	public function test_with_a_period_retention_releases_the_sites_of_long_suspended_licenses(): void {
		$this->set_days( 30 );
		$customer = self::factory()->user->create();
		$recent   = $this->suspended( $customer, 29 );
		$long     = $this->suspended( $customer, 31 );

		$processed = Retention::run();

		$this->assertSame( 'deactivated', $this->activation( $long['activation'] )['status'] );
		$this->assertSame( 'active', $this->activation( $recent['activation'] )['status'] );
		$this->assertGreaterThanOrEqual( 1, $processed['ended_license_activations'] );
		$this->assertSame( $customer, (int) $this->license( $long['license'] )['customer_id'], 'Retention anonymises only after its own period.' );

		// A license that was reactivated before the run keeps everything, although the setting is on.
		$back = $this->suspended( $customer, 400 );
		$this->assertTrue( LicenseLifecycle::admin_restore( $back['license'], 'Paid after all', self::factory()->user->create( array( 'role' => 'administrator' ) ) ) );
		Retention::run();
		$this->assertSame( 'active', $this->activation( $back['activation'] )['status'] );
	}

	public function test_the_filter_wins_over_the_setting_and_the_value_is_bounded(): void {
		$this->set_days( 30 );
		add_filter(
			'wplc_suspended_license_ends_after_days',
			static function ( $days ) {
				return 0;
			}
		);
		$this->assertSame( 0, EndedLicenses::suspended_days() );
		$this->assertSame( '( 1 = 0 )', EndedLicenses::suspended_condition( 'l' ) );
		remove_all_filters( 'wplc_suspended_license_ends_after_days' );

		$this->assertSame( EndedLicenses::MAX_DAYS, Settings::sanitize( array( 'suspended_ends_after_days' => '999999' ) )['suspended_ends_after_days'] );
		$this->assertSame( 30, Settings::sanitize( array( 'suspended_ends_after_days' => '-5' ) )['suspended_ends_after_days'], 'Not a whole number: the stored value stays.' );
		$this->assertSame( 30, Settings::sanitize( array( 'reminder_days' => '30,7' ) )['suspended_ends_after_days'], 'A form without the field keeps the stored value.' );
		$this->assertStringContainsString( "l.status = 'suspended' AND l.suspended_at IS NOT NULL AND l.suspended_at <= '", EndedLicenses::condition( 'l' ) );
	}

	/**
	 * Activation row or null when it was deleted.
	 *
	 * @param int $id Activation ID.
	 * @return array<string,mixed>|null
	 */
	private function activation_or_null( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->tables()['activations']} WHERE id = %d", $id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}
}
