<?php
/**
 * Staging guard, job isolation, gap flag and gap reconciliation (B-01, B-14, B-34, B-35, B-125).
 *
 * @package WPLC
 */

use WPLC\Infrastructure\Database\Schema;
use WPLC\License\LicenseIssuer;
use WPLC\License\LicenseLifecycle;
use WPLC\Maintenance\DailyRunner;
use WPLC\Maintenance\GapGuard;
use WPLC\Maintenance\GapReconciler;
use WPLC\Maintenance\IsolatedJob;
use WPLC\Maintenance\SiteGuard;
use WPLC\Plugin;

final class SiteGuardIntegrationTest extends WPLC_Integration_TestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( SiteGuard::OPTION );
		delete_option( SiteGuard::KEEP_OPTION );
		delete_option( SiteGuard::PAUSED_SINCE_OPTION );
		delete_option( GapGuard::OPTION );
		delete_option( DailyRunner::LAST_RUN_OPTION );
		delete_option( DailyRunner::FAILURES_OPTION );
		SiteGuard::remember();
	}

	/**
	 * Make the stored fingerprint look like it came from another installation.
	 */
	private function simulate_database_copy(): void {
		$foreign              = SiteGuard::fingerprint( 'https://live-shop.example', 'live_db', 'wp_' );
		$foreign['stored_at'] = gmdate( 'Y-m-d H:i:s' );
		update_option( SiteGuard::OPTION, $foreign, true );
	}

	private function audit_actions(): array {
		global $wpdb;
		$tables = $this->tables();

		return (array) $wpdb->get_col( "SELECT action FROM {$tables['audit_log']} ORDER BY id ASC" );
	}

	private function render_notice(): string {
		ob_start();
		SiteGuard::render_notice();

		return (string) ob_get_clean();
	}

	// B-01: first run stores the fingerprint, a copy of the database pauses billing.
	public function test_database_copy_pauses_billing(): void {
		$stored = get_option( SiteGuard::OPTION );
		$this->assertSame( SiteGuard::current()['hash'], $stored['hash'] );
		$this->assertFalse( SiteGuard::billing_paused() );
		$this->assertSame( '', SiteGuard::pause_reason() );

		$this->simulate_database_copy();

		$this->assertTrue( SiteGuard::billing_paused() );
		$this->assertSame( 'fingerprint', SiteGuard::pause_reason() );

		// The difference is never accepted automatically.
		SiteGuard::remember();
		$this->assertTrue( SiteGuard::billing_paused() );
	}

	// B-01: filter and the decision reason.
	public function test_filter_can_pause_and_release(): void {
		add_filter( 'wplc_billing_paused', '__return_true' );
		$this->assertTrue( SiteGuard::billing_paused() );
		remove_filter( 'wplc_billing_paused', '__return_true' );

		$this->simulate_database_copy();
		$reasons = array();
		$release = static function ( $paused, $reason ) use ( &$reasons ) {
			$reasons[] = $reason;
			return false;
		};
		add_filter( 'wplc_billing_paused', $release, 10, 2 );
		$this->assertFalse( SiteGuard::billing_paused() );
		$this->assertSame( array( 'fingerprint' ), $reasons );
	}

	// B-01: the notice is for administrators only and carries both answers behind a nonce.
	public function test_notice_asks_administrators_only(): void {
		$this->simulate_database_copy();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		$this->assertSame( '', $this->render_notice() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$html = $this->render_notice();
		$this->assertStringContainsString( 'live-shop.example', $html );
		$this->assertStringContainsString( 'value="resume"', $html );
		$this->assertStringContainsString( 'value="keep"', $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
		$this->assertStringContainsString( 'value="' . SiteGuard::ACTION . '"', $html );

		// Nothing to ask on the confirmed installation.
		SiteGuard::apply_decision( 'resume', get_current_user_id() );
		$this->assertSame( '', $this->render_notice() );
	}

	// B-01: "resume" needs the capability and the nonce, stores the new fingerprint and is audited.
	public function test_resume_requires_capability_and_nonce_and_is_audited(): void {
		$this->simulate_database_copy();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		$result = $this->run_admin_post( SiteGuard::ACTION, array( 'decision' => 'resume', '_wpnonce' => wp_create_nonce( SiteGuard::ACTION ) ) );
		$this->assertSame( 'die', $result['outcome'] );
		$this->assertTrue( SiteGuard::billing_paused() );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$result = $this->run_admin_post( SiteGuard::ACTION, array( 'decision' => 'resume', '_wpnonce' => 'wrong' ) );
		$this->assertSame( 'die', $result['outcome'] );
		$this->assertTrue( SiteGuard::billing_paused() );

		$result = $this->run_admin_post( SiteGuard::ACTION, array( 'decision' => 'resume', '_wpnonce' => wp_create_nonce( SiteGuard::ACTION ) ) );
		$this->assertSame( 'redirect', $result['outcome'] );
		$this->assertFalse( SiteGuard::billing_paused() );
		$stored = get_option( SiteGuard::OPTION );
		$this->assertSame( SiteGuard::current()['hash'], $stored['hash'] );
		$this->assertSame( $admin, (int) $stored['confirmed_by'] );
		$this->assertContains( 'billing_resumed', $this->audit_actions() );
	}

	// B-01: "keep paused" stays paused, is audited and quietens the notice outside the plugin screens.
	public function test_keep_paused_is_audited_and_stays_paused(): void {
		$this->simulate_database_copy();
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$result = $this->run_admin_post( SiteGuard::ACTION, array( 'decision' => 'keep', '_wpnonce' => wp_create_nonce( SiteGuard::ACTION ) ) );

		$this->assertSame( 'redirect', $result['outcome'] );
		$this->assertTrue( SiteGuard::billing_paused() );
		$this->assertContains( 'billing_pause_kept', $this->audit_actions() );
		$this->assertSame( 'foreign', get_option( SiteGuard::OPTION )['hash'] === SiteGuard::current()['hash'] ? 'own' : 'foreign' );

		set_current_screen( 'edit-post' );
		$this->assertSame( '', $this->render_notice() );
		set_current_screen( 'plugins' );
		$html = $this->render_notice();
		$this->assertStringContainsString( 'value="resume"', $html );
		$this->assertStringNotContainsString( 'value="keep"', $html );
		set_current_screen( 'front' );

		$this->assertFalse( SiteGuard::apply_decision( 'something-else', $admin ) );
	}

	// B-01: a pause that ends becomes a gap, so renewals due meanwhile are not expired at once.
	public function test_resume_records_the_pause_as_a_gap(): void {
		$this->simulate_database_copy();
		SiteGuard::track_pause( time() - 3 * DAY_IN_SECONDS );
		$this->assertGreaterThan( 0, (int) get_option( SiteGuard::PAUSED_SINCE_OPTION ) );
		$this->assertNull( GapGuard::window() );

		SiteGuard::apply_decision( 'resume', 0 );

		$window = GapGuard::window();
		$this->assertIsArray( $window );
		$this->assertSame( 'billing_paused', $window['source'] );
		$this->assertTrue( GapGuard::protects( gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) );
		$this->assertFalse( get_option( SiteGuard::PAUSED_SINCE_OPTION ) );
	}

	// B-14: the flag the expiry run reads.
	public function test_gap_guard_protects_due_dates_inside_the_gap_for_a_limited_time(): void {
		$now = time();
		$this->assertFalse( GapGuard::record( $now - 600, $now, 'test' ), 'A few minutes are not a gap.' );
		$this->assertNull( GapGuard::window() );
		$this->assertNull( GapGuard::protected_range() );
		$this->assertFalse( GapGuard::protects( gmdate( 'Y-m-d H:i:s', $now - 60 ) ) );

		$this->assertTrue( GapGuard::record( $now - 5 * DAY_IN_SECONDS, $now, 'test' ) );
		$window = GapGuard::window();
		$this->assertSame( $now + 5 * DAY_IN_SECONDS, $window['hold_until'] );
		$this->assertTrue( GapGuard::protects( gmdate( 'Y-m-d H:i:s', $now - 2 * DAY_IN_SECONDS ) ), 'Due during the gap.' );
		$this->assertTrue( GapGuard::protects( gmdate( 'Y-m-d H:i:s', $now - 15 * DAY_IN_SECONDS ) ), 'Was in its retry and grace period when the gap began.' );
		$this->assertFalse( GapGuard::protects( gmdate( 'Y-m-d H:i:s', $now - 40 * DAY_IN_SECONDS ) ) );
		$this->assertFalse( GapGuard::protects( gmdate( 'Y-m-d H:i:s', $now + DAY_IN_SECONDS ) ), 'Due after the gap.' );
		$this->assertFalse( GapGuard::protects( null ) );
		$this->assertSame(
			array( gmdate( 'Y-m-d H:i:s', $now - 19 * DAY_IN_SECONDS ), gmdate( 'Y-m-d H:i:s', $now ) ),
			GapGuard::protected_range()
		);
		$this->assertSame( $now + 5 * DAY_IN_SECONDS, GapGuard::active_until() );

		// After the hold the licenses expire through the normal rules again.
		$later = $now + 5 * DAY_IN_SECONDS + 1;
		$this->assertNull( GapGuard::window( $later ) );
		$this->assertFalse( GapGuard::protects( gmdate( 'Y-m-d H:i:s', $now - 2 * DAY_IN_SECONDS ), $later ) );
	}

	// B-34: an exception in one daily callback does not stop the others; alerts run last; B-35: heartbeat.
	public function test_daily_chain_survives_a_throwing_callback(): void {
		global $wp_filter;

		$ran = array();
		add_action(
			DailyRunner::HOOK,
			static function () {
				throw new RuntimeException( 'receiver exploded' );
			},
			1
		);
		add_action(
			DailyRunner::HOOK,
			static function () use ( &$ran ) {
				$ran[] = 'after-exception';
			},
			2
		);
		add_action(
			'wplc/health_alert',
			static function () {
				throw new RuntimeException( 'alert receiver exploded' );
			}
		);
		add_filter(
			'wplc_health_alert_thresholds',
			static function () {
				return array( 'pending_webhooks' => 0 );
			}
		);
		add_action(
			DailyRunner::HOOK,
			static function () use ( &$ran ) {
				$ran[] = 'late-job';
			},
			900
		);
		// An ended license: the expiry job (priority 10) still has to run.
		$license = $this->seed_license(
			array(
				'paid_until'      => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
				'next_payment_at' => null,
			)
		);

		do_action( DailyRunner::HOOK );

		$this->assertSame( array( 'after-exception', 'late-job' ), $ran );
		$this->assertSame( 'expired', $this->license( $license['id'] )['status'] );
		$failures = DailyRunner::failures();
		$this->assertArrayHasKey( 'closure', $failures );
		$this->assertArrayHasKey( 'diagnostics_emit_alerts', $failures, 'The alert receiver threw inside the alert job.' );
		$this->assertStringContainsString( 'alert receiver exploded', $failures['diagnostics_emit_alerts']['message'] );
		$this->assertGreaterThan( time() - 60, DailyRunner::last_run(), 'The run completes and writes its heartbeat.' );

		// Alerts were moved behind every other job.
		$alert_priority = null;
		foreach ( $wp_filter[ DailyRunner::HOOK ]->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $entry ) {
				if ( $entry['function'] instanceof IsolatedJob && 'diagnostics_emit_alerts' === $entry['function']->name() ) {
					$alert_priority = $priority;
				}
			}
		}
		$this->assertGreaterThanOrEqual( DailyRunner::ALERT_PRIORITY, $alert_priority );

		// A second run in the same request works on the already wrapped callbacks.
		$ran = array();
		do_action( DailyRunner::HOOK );
		$this->assertSame( array( 'after-exception', 'late-job' ), $ran );
	}

	// B-01: while paused, expiry, reminders and reconciliation do not run; cleanup still does.
	public function test_paused_daily_run_skips_billing_jobs_but_cleans_up(): void {
		global $wpdb;
		$tables  = $this->tables();
		$license = $this->seed_license(
			array(
				'paid_until'      => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
				'next_payment_at' => null,
			)
		);
		$wpdb->insert(
			$tables['audit_log'],
			array(
				'action'      => 'old_entry',
				'object_type' => 'license',
				'object_id'   => $license['id'],
				'reason'      => '',
				'created_at'  => gmdate( 'Y-m-d H:i:s', time() - 800 * DAY_IN_SECONDS ),
			)
		);
		$old_audit_id = (int) $wpdb->insert_id;
		$this->simulate_database_copy();

		do_action( DailyRunner::HOOK );

		$this->assertSame( 'active', $this->license( $license['id'] )['status'], 'No license expires on a paused copy.' );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['audit_log']} WHERE id = %d", $old_audit_id ) ), 'Retention still runs.' );
		$this->assertGreaterThan( 0, (int) get_option( SiteGuard::PAUSED_SINCE_OPTION ), 'The start of the pause is remembered.' );

		SiteGuard::apply_decision( 'resume', 0 );
		do_action( DailyRunner::HOOK );
		$this->assertSame( 'expired', $this->license( $license['id'] )['status'] );
	}

	// B-01: queued charge jobs and customer mails are gated as well.
	public function test_paused_site_does_not_process_renewals_or_send_status_mails(): void {
		global $wpdb;
		$tables   = $this->tables();
		$customer = self::factory()->user->create( array( 'role' => 'customer' ) );
		$license  = $this->seed_license(
			array(
				'customer_id'     => $customer,
				'renewal_enabled' => 1,
				'paid_until'      => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
			)
		);
		$now      = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert(
			$tables['renewals'],
			array(
				'license_id'      => $license['id'],
				'period_start'    => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
				'period_end'      => gmdate( 'Y-m-d H:i:s', time() + YEAR_IN_SECONDS ),
				'idempotency_key' => hash( 'sha256', 'gate-test-' . $license['id'] ),
				'status'          => 'scheduled',
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);
		$renewal_id = (int) $wpdb->insert_id;
		$snapshot   = function () use ( $wpdb, $tables, $renewal_id, $license ) {
			return array(
				$wpdb->get_row( $wpdb->prepare( "SELECT status, attempt_count, renewal_order_id FROM {$tables['renewals']} WHERE id = %d", $renewal_id ), ARRAY_A ),
				$this->license( $license['id'] )['status'],
			);
		};
		$before     = $snapshot();
		$mails      = 0;
		add_filter(
			'pre_wp_mail',
			static function () use ( &$mails ) {
				++$mails;
				return true;
			}
		);

		$this->simulate_database_copy();
		do_action( 'wplc_process_renewal', $renewal_id );
		do_action( 'wplc/license_status_changed', $license['id'], 'active', 'expired', 'license_expired' );
		$this->assertSame( $before, $snapshot(), 'The renewal job must not touch anything on a paused copy.' );
		$this->assertSame( 0, $mails, 'No customer mail from a paused copy.' );

		SiteGuard::apply_decision( 'resume', 0 );
		do_action( 'wplc_process_renewal', $renewal_id );
		do_action( 'wplc/license_status_changed', $license['id'], 'active', 'expired', 'license_expired' );
		$this->assertNotSame( $before, $snapshot(), 'After the confirmation the same job is processed.' );
		$this->assertGreaterThan( 0, $mails );
	}

	// B-14: an order paid while the plugin was off gets its licenses afterwards, exactly once.
	public function test_reconciliation_issues_licenses_for_orders_paid_during_the_gap(): void {
		$product = $this->create_license_product();
		foreach ( array( 'woocommerce_payment_complete', 'woocommerce_order_status_processing', 'woocommerce_order_status_completed' ) as $hook ) {
			remove_action( $hook, array( LicenseIssuer::class, 'maybe_issue_order' ), 20 );
		}
		$customer = self::factory()->user->create( array( 'role' => 'customer' ) );
		$order    = $this->create_paid_order( array( array( $product, 2 ) ), $customer );
		$plain    = $this->create_paid_order( array( array( $this->create_simple_product(), 1 ) ), $customer );
		$this->assertCount( 0, $this->licenses_for_order( $order->get_id() ), 'Fixture: the issuer hook still ran.' );
		$this->assertSame( 2, GapReconciler::missing_license_units( $order ) );
		$this->assertSame( 0, GapReconciler::missing_license_units( $plain ) );

		$summary = GapReconciler::run();

		$this->assertSame( 1, $summary['orders_issued'] );
		$this->assertTrue( $summary['complete'] );
		$this->assertCount( 2, $this->licenses_for_order( $order->get_id() ) );
		$this->assertCount( 0, $this->licenses_for_order( $plain->get_id() ) );
		$this->assertContains( 'gap_order_reconciled', $this->audit_actions() );

		$again = GapReconciler::run();
		$this->assertSame( 0, $again['orders_issued'] );
		$this->assertCount( 2, $this->licenses_for_order( $order->get_id() ) );
	}

	// B-14: orders older than the look-back window are left alone; the window is filterable.
	public function test_reconciliation_only_looks_back_the_configured_days(): void {
		$product = $this->create_license_product();
		foreach ( array( 'woocommerce_payment_complete', 'woocommerce_order_status_processing', 'woocommerce_order_status_completed' ) as $hook ) {
			remove_action( $hook, array( LicenseIssuer::class, 'maybe_issue_order' ), 20 );
		}
		$order = $this->create_paid_order( array( array( $product, 1 ) ), self::factory()->user->create( array( 'role' => 'customer' ) ) );
		$order->set_date_paid( time() - 40 * DAY_IN_SECONDS );
		$order->save();

		$this->assertSame( 0, GapReconciler::run()['orders_issued'] );
		$this->assertCount( 0, $this->licenses_for_order( $order->get_id() ) );

		add_filter(
			'wplc_gap_reconcile_days',
			static function () {
				return 60;
			}
		);
		$this->assertSame( 1, GapReconciler::run()['orders_issued'] );
		$this->assertCount( 1, $this->licenses_for_order( $order->get_id() ) );
		$this->assertIsArray( GapGuard::window(), 'A paid order without license is evidence of a gap.' );
	}

	// B-14: a refund made while the plugin was off is applied afterwards, exactly once.
	public function test_reconciliation_processes_refunds_made_during_the_gap(): void {
		$product = $this->create_license_product();
		$order   = $this->create_paid_order( array( array( $product, 2 ) ), self::factory()->user->create( array( 'role' => 'customer' ) ) );
		$this->assertCount( 2, $this->licenses_for_order( $order->get_id() ) );

		remove_action( 'woocommerce_order_refunded', array( LicenseLifecycle::class, 'handle_refund' ), 20 );
		remove_action( 'woocommerce_order_fully_refunded', array( LicenseLifecycle::class, 'handle_full_refund' ), 20 );
		remove_action( 'woocommerce_order_status_refunded', array( LicenseLifecycle::class, 'handle_full_refund' ), 20 );
		$item   = $this->first_item( $order );
		$amount = wc_format_decimal( (float) $item->get_total() / 2, wc_get_price_decimals() );
		$refund = wc_create_refund(
			array(
				'order_id'   => $order->get_id(),
				'amount'     => $amount,
				'line_items' => array(
					$item->get_id() => array(
						'qty'          => 1,
						'refund_total' => $amount,
					),
				),
			)
		);
		$this->assertInstanceOf( WC_Order_Refund::class, $refund );
		$statuses = function () use ( $order ) {
			return array_column( $this->licenses_for_order( $order->get_id() ), 'status' );
		};
		$this->assertSame( array( 'active', 'active' ), $statuses(), 'Fixture: the refund hook still ran.' );

		$summary = GapReconciler::run();
		$this->assertSame( 1, $summary['refunds'] );
		$after = $statuses();
		sort( $after );
		$this->assertSame( array( 'active', 'revoked' ), $after );

		$this->assertSame( 0, GapReconciler::run()['refunds'] );
		$again = $statuses();
		sort( $again );
		$this->assertSame( array( 'active', 'revoked' ), $again, 'A second run must not revoke another unit.' );

		// The rest of the order is refunded by setting the status, again without hooks.
		$order = wc_get_order( $order->get_id() );
		$order->set_status( 'refunded' );
		$order->save();
		$summary = GapReconciler::run();
		$this->assertSame( 1, $summary['full_refunds'] );
		$this->assertSame( array( 'revoked', 'revoked' ), $statuses() );
		$this->assertSame( 0, GapReconciler::run()['full_refunds'] );
	}

	// B-14: nothing is reconciled (no licenses, no mails) on a paused copy.
	public function test_reconciliation_does_nothing_while_billing_is_paused(): void {
		$product = $this->create_license_product();
		foreach ( array( 'woocommerce_payment_complete', 'woocommerce_order_status_processing', 'woocommerce_order_status_completed' ) as $hook ) {
			remove_action( $hook, array( LicenseIssuer::class, 'maybe_issue_order' ), 20 );
		}
		$order = $this->create_paid_order( array( array( $product, 1 ) ), self::factory()->user->create( array( 'role' => 'customer' ) ) );
		$this->simulate_database_copy();

		$summary = GapReconciler::run();

		$this->assertSame( 0, $summary['orders_checked'] );
		$this->assertCount( 0, $this->licenses_for_order( $order->get_id() ) );
	}

	// B-14, B-125: deactivation leaves a timestamp and no reminder cursor; activation records the gap and queues the catch-up.
	public function test_deactivation_and_activation_bridge_the_gap(): void {
		update_option( 'wplc_reminder_cursor', 4711, false );
		LicenseLifecycle::schedule_daily_event();
		$this->assertNotFalse( wp_next_scheduled( 'wplc_daily_lifecycle' ) );

		Plugin::deactivate();

		$this->assertFalse( get_option( 'wplc_reminder_cursor' ), 'B-125: the reminder cursor is deleted.' );
		$this->assertFalse( wp_next_scheduled( 'wplc_daily_lifecycle' ) );
		$this->assertGreaterThan( time() - 60, (int) get_option( Plugin::DEACTIVATED_OPTION ) );

		update_option( Plugin::DEACTIVATED_OPTION, time() - 20 * DAY_IN_SECONDS, false );
		Plugin::activate();

		$this->assertFalse( get_option( Plugin::DEACTIVATED_OPTION ) );
		$this->assertNotFalse( wp_next_scheduled( 'wplc_daily_lifecycle' ) );
		$window = GapGuard::window();
		$this->assertIsArray( $window );
		$this->assertSame( 'deactivation', $window['source'] );
		$this->assertSame( $window['to'] + GapGuard::DEFAULT_HOLD_DAYS * DAY_IN_SECONDS, $window['hold_until'] );
		$this->assertTrue( GapGuard::protects( gmdate( 'Y-m-d H:i:s', time() - 16 * DAY_IN_SECONDS ) ), 'A renewal that became due during the deactivation.' );
		if ( function_exists( 'as_next_scheduled_action' ) ) {
			$this->assertNotFalse( as_next_scheduled_action( GapReconciler::HOOK, array(), 'wplc' ), 'The catch-up run is queued.' );
		}
		$this->assertSame( Schema::VERSION, (string) get_option( Schema::VERSION_OPTION ) );
	}

	// B-14: a daily run that did not fire for days is a gap as well.
	public function test_missed_daily_runs_are_recorded_as_a_gap(): void {
		update_option( DailyRunner::LAST_RUN_OPTION, time() - 6 * DAY_IN_SECONDS, false );

		do_action( DailyRunner::HOOK );

		$window = GapGuard::window();
		$this->assertIsArray( $window );
		$this->assertSame( 'daily_run_missed', $window['source'] );
		$this->assertGreaterThan( time() - 60, DailyRunner::last_run() );
	}
}
