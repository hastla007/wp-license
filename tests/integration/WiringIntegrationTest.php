<?php
/**
 * Call sites that connect the packages: bootstrap, expiry and gap guard,
 * advance notice before a charge, cancellation event, issuer flags, plan
 * lookup, diagnostics rows and alerts.
 *
 * @package WPLC
 */

use WPLC\Admin\Diagnostics;
use WPLC\Admin\Format;
use WPLC\Api\V1\ActivationService;
use WPLC\Billing\RenewalProcessor;
use WPLC\Catalog\Catalog;
use WPLC\Catalog\TermDisplay;
use WPLC\License\LicenseLifecycle;
use WPLC\Maintenance\GapGuard;
use WPLC\Notifications\Notifications;
use WPLC\Notifications\ReminderLog;
use WPLC\Notifications\ReminderSchedule;
use WPLC\Plugin;

final class WiringIntegrationTest extends WPLC_Renewal_TestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( GapGuard::OPTION );
	}

	public function tear_down(): void {
		delete_option( GapGuard::OPTION );
		parent::tear_down();
	}

	// Wave 2: the display hooks and the CLI commands are registered by the plugin loader.
	public function test_plugin_loader_registers_term_display_and_cli_classes(): void {
		$this->assertSame( 20, has_filter( 'woocommerce_get_price_html', array( TermDisplay::class, 'price_html' ) ) );
		$this->assertSame( 10, has_filter( 'woocommerce_get_item_data', array( TermDisplay::class, 'cart_item_data' ) ) );
		$this->assertTrue( method_exists( \WPLC\CLI\ReleaseCommand::class, 'register' ) );
		$this->assertTrue( method_exists( \WPLC\CLI\Commands::class, 'register' ) );
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Plugin.php' );
		$this->assertStringContainsString( 'ReleaseCommand::register();', $source );
		$this->assertStringContainsString( 'CLI\\Commands::register();', $source );
	}

	// Wave 2: deactivation withdraws continuation runs and mail retries, and the reminder cursor.
	public function test_deactivation_withdraws_reminder_and_mail_retry_jobs(): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			$this->markTestSkipped( 'Action Scheduler is not available.' );
		}
		as_schedule_single_action( time() + HOUR_IN_SECONDS, 'wplc_send_reminders', array(), 'wplc' );
		as_schedule_single_action( time() + HOUR_IN_SECONDS, 'wplc_retry_license_email', array( 'issued', 1, '', 1, array() ), 'wplc' );
		as_schedule_single_action( time() + HOUR_IN_SECONDS, 'wplc_process_renewal', array( 987654 ), 'wplc' );
		update_option( 'wplc_reminder_cursor', array( 'day' => gmdate( 'Y-m-d' ), 'id' => 5 ), false );

		Plugin::deactivate_site();

		$this->assertFalse( as_next_scheduled_action( 'wplc_send_reminders' ) );
		$this->assertFalse( as_next_scheduled_action( 'wplc_retry_license_email' ) );
		$this->assertNotFalse( as_next_scheduled_action( 'wplc_process_renewal', array( 987654 ), 'wplc' ), 'Renewal jobs stay queued; the daily run repairs what is used up meanwhile.' );
		$this->assertFalse( get_option( 'wplc_reminder_cursor' ) );

		as_unschedule_all_actions( 'wplc_process_renewal', array( 987654 ), 'wplc' );
		delete_option( Plugin::DEACTIVATED_OPTION );
		LicenseLifecycle::schedule_daily_event();
	}

	// B-14: a license that became due during a processing gap is not expired before the hold ends.
	public function test_b14_expiry_run_leaves_licenses_due_during_a_gap(): void {
		$now       = time();
		$in_gap    = $this->seed_license( array( 'paid_until' => gmdate( 'Y-m-d H:i:s', $now - 2 * DAY_IN_SECONDS ), 'next_payment_at' => null ) );
		$before    = $this->seed_license( array( 'paid_until' => gmdate( 'Y-m-d H:i:s', $now - 90 * DAY_IN_SECONDS ), 'next_payment_at' => null ) );
		$past_due  = $this->seed_license(
			array(
				'status'          => 'past_due',
				'renewal_enabled' => 1,
				'paid_until'      => gmdate( 'Y-m-d H:i:s', $now - 20 * DAY_IN_SECONDS ),
			)
		);
		$this->assertTrue( GapGuard::record( $now - 25 * DAY_IN_SECONDS, $now - 60, 'deactivation' ) );
		$this->assertTrue( GapGuard::protects( $this->license( $in_gap['id'] )['paid_until'] ) );

		LicenseLifecycle::expire_due_licenses();
		LicenseLifecycle::expire_after_grace( $past_due['id'] );

		$this->assertSame( 'active', $this->license( $in_gap['id'] )['status'], 'Due during the gap: held back.' );
		$this->assertSame( 'past_due', $this->license( $past_due['id'] )['status'], 'The grace end during the gap is held back as well.' );
		$this->assertSame( 'expired', $this->license( $before['id'] )['status'], 'Due long before the gap: expires as usual.' );

		// After the hold period the same run expires them.
		delete_option( GapGuard::OPTION );
		LicenseLifecycle::expire_due_licenses();
		LicenseLifecycle::expire_after_grace( $past_due['id'] );
		$this->assertSame( 'expired', $this->license( $in_gap['id'] )['status'] );
		$this->assertSame( 'expired', $this->license( $past_due['id'] )['status'] );
	}

	// B-41: no automatic charge without the logged advance notice; the notice is caught up and the charge follows later.
	public function test_b41_charge_waits_for_the_advance_notice(): void {
		global $wpdb;
		remove_filter( 'wplc_renewal_requires_advance_notice', '__return_false' );
		$this->assertSame( 10, has_filter( 'wplc_renewal_charge_allowed', array( Notifications::class, 'charge_allowed' ) ) );
		$blocked = array();
		add_action(
			'wplc/renewal_blocked',
			static function ( $renewal_id, $code ) use ( &$blocked ) {
				$blocked[] = $code;
			},
			10,
			2
		);
		$f = $this->renewing_license();
		$this->assertFalse( Notifications::renewal_notice_sent( $this->license( $f['license_id'] ) ) );

		RenewalProcessor::process( $f['renewal_id'] );

		$this->assertSame( 0, $this->charges(), 'Nothing is charged without the announcement.' );
		$renewal = $this->renewal( $f['renewal_id'] );
		$this->assertSame( 'scheduled', $renewal['status'] );
		$this->assertSame( 'renewal_notice_not_sent', $renewal['last_error'] );
		$this->assertSame( '0', (string) $renewal['attempt_count'], 'A postponed charge is not a failed payment.' );
		$this->assertSame( array( 'renewal_notice_not_sent' ), $blocked );
		$this->assertContains( 'renewal_blocked', $this->audit_actions( 'renewal', $f['renewal_id'] ) );
		$this->assertSame( 'active', $this->license( $f['license_id'] )['status'] );
		// The missing notice was sent now (the test mailer accepts it) and is on record.
		$this->assertTrue( Notifications::renewal_notice_sent( $this->license( $f['license_id'] ) ) );

		// The next attempt goes through.
		$wpdb->update( $this->tables()['renewals'], array( 'retry_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => $f['renewal_id'] ) );
		RenewalProcessor::process( $f['renewal_id'] );
		$this->assertSame( 1, $this->charges() );
		$this->assertSame( 'paid', $this->renewal( $f['renewal_id'] )['status'] );
	}

	// B-41: with the notice on record the charge is not delayed; a refused notice keeps the charge postponed.
	public function test_b41_logged_notice_allows_the_charge_and_a_refused_notice_does_not(): void {
		remove_filter( 'wplc_renewal_requires_advance_notice', '__return_false' );
		$f       = $this->renewing_license();
		$license = $this->license( $f['license_id'] );
		$this->assertSame( ReminderLog::CLAIMED, ReminderLog::claim( $f['license_id'], ReminderSchedule::TYPE_RENEWAL_NOTICE, $license['paid_until'] ) );
		// Claimed but not confirmed (the mailer has not accepted it yet): not sent.
		$this->assertInstanceOf( WP_Error::class, apply_filters( 'wplc_renewal_charge_allowed', true, $this->renewal( $f['renewal_id'] ), $license ) );
		$this->assertTrue( ReminderLog::confirm( $f['license_id'], ReminderSchedule::TYPE_RENEWAL_NOTICE, $license['paid_until'] ) );
		$this->assertTrue( apply_filters( 'wplc_renewal_charge_allowed', true, $this->renewal( $f['renewal_id'] ), $license ) );

		RenewalProcessor::process( $f['renewal_id'] );
		$this->assertSame( 1, $this->charges() );

		// Another license, mailer down: stays postponed, nothing is recorded as sent.
		$g = $this->renewing_license();
		remove_filter( 'pre_wp_mail', '__return_true' );
		add_filter( 'pre_wp_mail', '__return_false' );
		RenewalProcessor::process( $g['renewal_id'] );
		$this->assertSame( 1, $this->charges() );
		$this->assertFalse( Notifications::renewal_notice_sent( $this->license( $g['license_id'] ) ) );
		$this->assertSame( 'renewal_notice_not_sent', $this->renewal( $g['renewal_id'] )['last_error'] );

		// The shop can declare another documented announcement.
		add_filter( 'wplc_renewal_requires_advance_notice', '__return_false' );
		$this->assertTrue( apply_filters( 'wplc_renewal_charge_allowed', true, $this->renewal( $g['renewal_id'] ), $this->license( $g['license_id'] ) ) );
	}

	// Wave 2: the lifecycle announces every cancellation once, with the time of receipt.
	public function test_cancellation_event_is_fired_by_the_lifecycle(): void {
		$f     = $this->renewing_license( 30 * DAY_IN_SECONDS );
		$fired = array();
		add_action(
			'wplc/cancellation_scheduled',
			static function ( $license_id, $cancelled_at ) use ( &$fired ) {
				$fired[] = array( (int) $license_id, (int) $cancelled_at );
			},
			10,
			2
		);

		$this->assertTrue( LicenseLifecycle::schedule_cancellation( $f['license_id'], 'test', 0, 1700000000 ) );
		// A repeated request for the cancelled license is accepted and not announced again.
		$this->assertTrue( LicenseLifecycle::schedule_cancellation( $f['license_id'], 'again' ) );

		$this->assertSame( array( array( $f['license_id'], 1700000000 ) ), $fired );
	}

	// B-76: the issuer stores how the renewal amount is to be read.
	public function test_b76_issuer_writes_the_tax_reading_of_the_renewal_amount(): void {
		$this->set_tax( true );
		$gross = $this->buy( $this->create_license_product( array( 'product_key' => 'tax-gross' ) ), $this->customer(), true );
		$this->assertSame( '1', (string) $gross['renewal_amount_includes_tax'] );

		$this->set_tax( false );
		$net = $this->buy( $this->create_license_product( array( 'product_key' => 'tax-net' ) ), $this->customer(), true );
		$this->assertSame( '0', (string) $net['renewal_amount_includes_tax'] );
	}

	// A-03: without a stored entitlement the site limit comes from the plan of the purchased product,
	// not from the plan with the highest ID that shares product key and plan key.
	public function test_a03_site_limit_fallback_uses_the_plan_of_the_purchased_product(): void {
		global $wpdb;
		$yearly  = $this->create_license_product( array( 'product_key' => 'shared-key', 'plan_key' => 'pro', 'live_site_limit' => 5 ) );
		$monthly = $this->create_license_product( array( 'product_key' => 'shared-key', 'plan_key' => 'pro', 'billing_interval' => 'month', 'live_site_limit' => 2 ) );
		$this->assertGreaterThan( $yearly->get_id(), $monthly->get_id() );
		$license = $this->buy( $yearly, $this->customer(), false );
		$wpdb->delete( $this->tables()['entitlements'], array( 'license_id' => (int) $license['id'] ) );

		$this->assertSame( 5, ActivationService::site_limit( (int) $license['id'], $this->license( (int) $license['id'] ) ) );
	}

	// Wave 2: new renewal status has a label, and the diagnostics page shows the rows of the other packages.
	public function test_diagnostics_rows_cover_schema_pause_gap_jobs_signing_and_api(): void {
		$this->assertSame( 'Payment result unclear – check manually', Format::renewal_status_label( 'payment_review' ) );

		GapGuard::record( time() - 3 * DAY_IN_SECONDS, time() - 60, 'deactivation' );
		\WPLC\Maintenance\DailyRunner::record_failure( 'wiring_test_job', new RuntimeException( 'wiring failure' ) );
		$labels = array();
		$values = array();
		foreach ( Diagnostics::rows() as $row ) {
			$labels[]                = $row['label'];
			$values[ $row['label'] ] = $row['value'];
		}

		foreach ( array( 'Table structure', 'Charges, expiry and customer emails', 'Processing gap', 'Last failure: wiring_test_job', 'Response signing key (Ed25519, public)', 'Installation secrets encrypted with an old key', 'Rejected API requests today (UTC)', 'Renewals with an unclear payment result', 'Paid orders without their licenses', 'Database locks (GET_LOCK)' ) as $label ) {
			$this->assertContains( $label, $labels );
		}
		$this->assertSame( 'Matches this plugin version', $values['Table structure'] );
		$this->assertSame( 'Running', $values['Charges, expiry and customer emails'] );
		$this->assertStringContainsString( 'wiring failure', $values['Last failure: wiring_test_job'] );
		$this->assertTrue( Diagnostics::lock_self_test() );

		$metrics = Diagnostics::metrics();
		foreach ( array( 'payment_review_renewals', 'orders_with_issuance_problem', 'failed_jobs', 'release_problems' ) as $metric ) {
			$this->assertArrayHasKey( $metric, $metrics );
		}
		$this->assertGreaterThanOrEqual( 1, $metrics['failed_jobs'] );
		delete_option( \WPLC\Maintenance\DailyRunner::FAILURES_OPTION );
	}

	// Wave 2: events that need a human reach the monitoring hook; a throwing receiver does not break the caller.
	public function test_review_events_are_forwarded_as_health_alerts(): void {
		$seen = array();
		add_action(
			'wplc/health_alert',
			static function ( $metric, $value, $threshold ) use ( &$seen ) {
				$seen[] = array( $metric, $value, $threshold );
				throw new RuntimeException( 'receiver exploded' );
			},
			10,
			3
		);

		do_action( 'wplc/renewal_blocked', 1, 'renewal_gateway_unavailable', 2 );
		do_action( 'wplc/renewal_needs_review', 3, 4, 'amount' );
		do_action( 'wplc/license_needs_review', 5, 'reason' );
		do_action( 'wplc/renewal_lock_unavailable', 6, 5 );
		do_action( 'wplc/response_signing_key_unavailable', 'missing' );

		$this->assertSame(
			array( 'renewal_blocked', 'renewal_needs_review', 'license_needs_review', 'renewal_lock_unavailable', 'response_signing_key_unavailable' ),
			array_column( $seen, 0 )
		);
		$this->assertSame( array( 1, 1 ), array_slice( $seen[0], 1 ) );
		$this->assertFalse( doing_action( 'wplc/health_alert' ), 'The hook stack is clean after a throwing receiver.' );
	}

	// Wave 2: one check for license products without a plan (Catalog), used by diagnostics.
	public function test_unmapped_license_products_metric_uses_the_catalog_check(): void {
		$stray = $this->create_simple_product();
		update_post_meta( $stray->get_id(), '_wplc_is_license_product', 'yes' );

		$this->assertContains( $stray->get_id(), Catalog::flagged_without_mapping() );
		$this->assertSame( count( Catalog::flagged_without_mapping( 500 ) ), Diagnostics::unmapped_license_products() );
	}
}
