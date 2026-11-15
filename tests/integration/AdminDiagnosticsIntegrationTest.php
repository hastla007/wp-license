<?php
/**
 * Diagnostics, heartbeat and the key-configuration notice.
 *
 * Covers A-52, A-72 (list), A-75, B-34 (alarm), B-35, B-64 (sample), B-108, B-116 (note) and B-137 (notice text).
 *
 * @package WPLC
 */

use WPLC\Admin\Diagnostics;
use WPLC\Admin\KeyConfigurationNotice;
use WPLC\Admin\Settings;
use WPLC\Catalog\Catalog;
use WPLC\Infrastructure\Database\Schema;

final class AdminDiagnosticsIntegrationTest extends WPLC_Integration_TestCase {

	/**
	 * Administrator.
	 *
	 * @var int
	 */
	private $admin;

	/**
	 * Shop manager.
	 *
	 * @var int
	 */
	private $manager;

	public function set_up(): void {
		parent::set_up();
		$this->admin   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->manager = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		delete_option( Diagnostics::LAST_RUN_OPTION );
		delete_transient( 'wplc_key_sample' );
	}

	public function tear_down(): void {
		delete_transient( 'wplc_key_sample' );
		remove_all_actions( 'wplc/health_alert' );
		parent::tear_down();
	}

	/**
	 * Capture the output of a callback.
	 *
	 * @param callable $callback Callback that prints.
	 * @return string
	 */
	private function capture( callable $callback ): string {
		ob_start();
		try {
			$callback();
		} finally {
			$html = (string) ob_get_clean();
		}

		return $html;
	}

	/**
	 * Insert a renewal row.
	 *
	 * @param int                 $license_id License ID.
	 * @param array<string,mixed> $overrides  Column overrides.
	 * @return int
	 */
	private function seed_renewal( int $license_id, array $overrides = array() ): int {
		global $wpdb;
		$tables = $this->tables();
		$now    = gmdate( 'Y-m-d H:i:s' );
		$row    = array_merge(
			array(
				'license_id'      => $license_id,
				'period_start'    => $now,
				'period_end'      => gmdate( 'Y-m-d H:i:s', time() + 365 * DAY_IN_SECONDS ),
				'idempotency_key' => hash( 'sha256', 'diag' . $license_id . wp_rand() ),
				'status'          => 'scheduled',
				'created_at'      => $now,
				'updated_at'      => $now,
			),
			$overrides
		);
		$this->assertSame( 1, $wpdb->insert( $tables['renewals'], $row ), $wpdb->last_error );

		return (int) $wpdb->insert_id;
	}

	// ---------------------------------------------------------------------
	// B-35: heartbeat.
	// ---------------------------------------------------------------------

	public function test_b35_mark_run_stores_utc_timestamps_per_job(): void {
		$this->assertSame( 'wplc_last_run', Diagnostics::LAST_RUN_OPTION );
		$this->assertSame( 0, Diagnostics::last_run( 'renewals' ) );

		Diagnostics::mark_run( 'renewals' );
		Diagnostics::mark_run( 'Reminders!' );
		Diagnostics::mark_run( '' );

		$stored = get_option( 'wplc_last_run' );
		$this->assertSame( array( 'renewals', 'reminders' ), array_keys( $stored ) );
		$this->assertEqualsWithDelta( time(), $stored['renewals'], 5 );
		$this->assertEqualsWithDelta( time(), Diagnostics::last_run( 'reminders' ), 5 );
	}

	public function test_b35_daily_run_is_bracketed_by_heartbeats_and_the_alarm_runs_last(): void {
		// One wrapper (Maintenance\DailyRunner) brackets the run and writes the heartbeats;
		// Diagnostics no longer registers brackets of its own.
		$this->assertFalse( has_action( 'wplc_daily_lifecycle', array( Diagnostics::class, 'mark_daily_started' ) ) );
		$this->assertSame( PHP_INT_MIN, has_action( 'wplc_daily_lifecycle', array( \WPLC\Maintenance\DailyRunner::class, 'begin' ) ) );
		$this->assertSame( PHP_INT_MAX, has_action( 'wplc_daily_lifecycle', array( \WPLC\Maintenance\DailyRunner::class, 'finish' ) ) );

		$order = array();
		add_action(
			'wplc/health_alert',
			static function () use ( &$order ) {
				$order[] = 'alert';
			}
		);
		add_filter(
			'wplc_health_alert_thresholds',
			static function () {
				return array( 'pending_webhooks' => 0 );
			}
		);
		add_action(
			'wplc_daily_lifecycle',
			static function () use ( &$order ) {
				$order[] = 'late-job';
			},
			5000
		);
		do_action( 'wplc_daily_lifecycle' );

		$this->assertSame( array( 'late-job', 'alert' ), $order, 'The alarm runs after the work it reports on.' );
		$runs = Diagnostics::last_runs();
		$this->assertArrayHasKey( Diagnostics::JOB_DAILY_STARTED, $runs );
		$this->assertArrayHasKey( Diagnostics::JOB_DAILY, $runs );
		$this->assertArrayHasKey( 'license_lifecycle_expire_due_licenses', $runs, 'Every job of the run leaves its own heartbeat.' );
		$this->assertArrayHasKey( 'renewal_processor_reconcile_unbooked_paid_orders', $runs, 'Long job names are kept in full.' );
		$this->assertSame( 'ok', Diagnostics::daily_run_state()['state'] );
		$this->assertSame( $runs[ Diagnostics::JOB_DAILY ], \WPLC\Maintenance\DailyRunner::last_run(), 'One store for the heartbeat and the gap detection.' );
		$this->assertFalse( get_option( 'wplc_job_heartbeats' ), 'The second heartbeat option is gone.' );
	}

	public function test_b35_daily_run_state_detects_missing_and_broken_runs(): void {
		$now = time();
		$this->assertSame( 'unknown', Diagnostics::daily_run_state( $now )['state'], 'A fresh installation is not reported.' );

		update_option( Diagnostics::LAST_RUN_OPTION, array( Diagnostics::JOB_FIRST_SEEN => $now - 27 * HOUR_IN_SECONDS ) );
		$this->assertSame( 'missing', Diagnostics::daily_run_state( $now )['state'], 'Never ran although the plugin is active for more than 26 hours.' );

		update_option(
			Diagnostics::LAST_RUN_OPTION,
			array(
				Diagnostics::JOB_DAILY_STARTED => $now - 25 * HOUR_IN_SECONDS,
				Diagnostics::JOB_DAILY         => $now - 25 * HOUR_IN_SECONDS,
			)
		);
		$this->assertSame( 'ok', Diagnostics::daily_run_state( $now )['state'] );

		update_option(
			Diagnostics::LAST_RUN_OPTION,
			array(
				Diagnostics::JOB_DAILY_STARTED => $now - 27 * HOUR_IN_SECONDS,
				Diagnostics::JOB_DAILY         => $now - 27 * HOUR_IN_SECONDS,
			)
		);
		$this->assertSame( 'missing', Diagnostics::daily_run_state( $now )['state'] );

		// Started an hour ago, the last task never ran: a task of the chain broke the run.
		update_option(
			Diagnostics::LAST_RUN_OPTION,
			array(
				Diagnostics::JOB_DAILY_STARTED => $now - HOUR_IN_SECONDS,
				Diagnostics::JOB_DAILY         => $now - 49 * HOUR_IN_SECONDS,
			)
		);
		$this->assertSame( 'incomplete', Diagnostics::daily_run_state( $now )['state'] );

		// Still running.
		update_option( Diagnostics::LAST_RUN_OPTION, array( Diagnostics::JOB_DAILY_STARTED => $now - 30 ) );
		$this->assertSame( 'unknown', Diagnostics::daily_run_state( $now )['state'] );
	}

	public function test_b35_admin_notice_when_the_daily_run_is_older_than_26_hours(): void {
		set_current_screen( 'woocommerce_page_wplc-licenses' );
		wp_set_current_user( $this->manager );

		$this->assertSame( '', $this->capture( array( Diagnostics::class, 'render_daily_run_notice' ) ) );
		$this->assertGreaterThan( 0, Diagnostics::last_run( Diagnostics::JOB_FIRST_SEEN ), 'The first view starts the clock.' );

		update_option( Diagnostics::LAST_RUN_OPTION, array( Diagnostics::JOB_DAILY => time() - 30 * HOUR_IN_SECONDS, Diagnostics::JOB_FIRST_SEEN => time() - 90 * DAY_IN_SECONDS ) );
		$notice = $this->capture( array( Diagnostics::class, 'render_daily_run_notice' ) );
		$this->assertStringContainsString( 'notice-error', $notice );
		$this->assertStringContainsString( 'daily background run is not working', $notice );
		$this->assertStringContainsString( 'more than 26 hours ago', $notice );
		$this->assertStringContainsString( 'page=wplc-diagnostics', $notice );

		Diagnostics::mark_daily_finished();
		$this->assertSame( '', $this->capture( array( Diagnostics::class, 'render_daily_run_notice' ) ) );

		// Not for customers, and not on unrelated screens.
		update_option( Diagnostics::LAST_RUN_OPTION, array( Diagnostics::JOB_DAILY => time() - 30 * HOUR_IN_SECONDS ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );
		$this->assertSame( '', $this->capture( array( Diagnostics::class, 'render_daily_run_notice' ) ) );
		wp_set_current_user( $this->manager );
		set_current_screen( 'edit-post' );
		$this->assertSame( '', $this->capture( array( Diagnostics::class, 'render_daily_run_notice' ) ) );
	}

	// ---------------------------------------------------------------------
	// B-35 / A-75: queue metrics.
	// ---------------------------------------------------------------------

	public function test_b35_metrics_count_stuck_unscheduled_and_unbooked_renewals(): void {
		global $wpdb;
		$tables = $this->tables();
		$before = Diagnostics::metrics();
		$old    = gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS );
		$until  = gmdate( 'Y-m-d H:i:s', time() + 20 * DAY_IN_SECONDS );

		// 1. A charge that never reported back.
		$stuck = $this->seed_license( array( 'renewal_enabled' => 1, 'updated_at' => $old ) )['id'];
		$this->seed_renewal( $stuck, array( 'status' => 'processing', 'updated_at' => $old ) );
		// A charge that started a minute ago is not stuck.
		$running = $this->seed_license( array( 'renewal_enabled' => 1, 'updated_at' => $old ) )['id'];
		$this->seed_renewal( $running, array( 'status' => 'processing' ) );

		// 2. Automatic renewal without any open renewal row.
		$this->seed_license( array( 'renewal_enabled' => 1, 'updated_at' => $old ) );
		// Cancelled to the end of the term: no renewal expected.
		$this->seed_license( array( 'renewal_enabled' => 1, 'cancel_at_period_end' => 1, 'updated_at' => $old ) );

		// 3. Paid renewal, but the license does not run until the end of the paid period.
		$unbooked = $this->seed_license( array( 'paid_until' => $until ) )['id'];
		$this->seed_renewal( $unbooked, array( 'status' => 'paid', 'period_start' => $until, 'period_end' => gmdate( 'Y-m-d H:i:s', time() + 385 * DAY_IN_SECONDS ) ) );
		// Paid and booked.
		$booked = $this->seed_license( array( 'paid_until' => $until ) )['id'];
		$this->seed_renewal( $booked, array( 'status' => 'paid', 'period_start' => gmdate( 'Y-m-d H:i:s', time() - 345 * DAY_IN_SECONDS ), 'period_end' => $until ) );

		$after = Diagnostics::metrics();
		$this->assertSame( $before['stuck_processing_renewals'] + 1, $after['stuck_processing_renewals'] );
		$this->assertSame( $before['renewing_without_renewal'] + 1, $after['renewing_without_renewal'] );
		$this->assertSame( $before['paid_without_extension'] + 1, $after['paid_without_extension'] );
		$this->assertArrayHasKey( 'overdue_renewals', $after );
		$this->assertSame( 2, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['renewals']} WHERE status = 'processing'" ) );
	}

	public function test_b35_failed_scheduled_actions_of_the_plugin_group_are_counted(): void {
		$before = Diagnostics::failed_scheduled_actions();
		if ( $before < 0 || ! class_exists( 'ActionScheduler' ) ) {
			$this->markTestSkipped( 'Action Scheduler is not initialised in this environment.' );
		}
		$mine  = as_schedule_single_action( time() - 60, 'wplc_test_failed_action', array( 1 ), 'wplc' );
		$other = as_schedule_single_action( time() - 60, 'wplc_test_failed_action', array( 2 ), 'another-plugin' );
		ActionScheduler::store()->mark_failure( $mine );
		ActionScheduler::store()->mark_failure( $other );

		$this->assertSame( $before + 1, Diagnostics::failed_scheduled_actions() );
		$this->assertSame( $before + 1, Diagnostics::metrics()['failed_scheduled_actions'] );
	}

	public function test_a75_ticked_license_product_without_plan_is_counted(): void {
		$before = Diagnostics::unmapped_license_products();

		// Written past the WooCommerce hooks (as a translation plugin or a raw import would do):
		// a save through the product object now either creates the plan or resets the flag (B-21).
		$orphan = $this->create_simple_product();
		update_post_meta( $orphan->get_id(), '_wplc_is_license_product', 'yes' );

		$mapped = $this->create_license_product( array( 'product_key' => 'diag-product' ) );
		$mapped->update_meta_data( '_wplc_is_license_product', 'yes' );
		$mapped->save();

		$plain = $this->create_simple_product();
		$plain->update_meta_data( '_wplc_is_license_product', 'no' );
		$plain->save();

		// A variation of a product that is mapped on the parent is covered by the parent's plan.
		list( $parent, $variations ) = $this->create_variable_product( array( '10.00', '20.00' ) );
		WC_Product_Variable::sync( $parent->get_id() );
		$this->assertTrue( Catalog::save_mapping( $parent->get_id(), 0, $this->mapping_data( array( 'product_key' => 'diag-variable' ) ) ) );
		$variations[0]->update_meta_data( '_wplc_is_license_product', 'yes' );
		$variations[0]->save();

		$this->assertSame( $before + 1, Diagnostics::unmapped_license_products() );

		wp_trash_post( $orphan->get_id() );
		$this->assertSame( $before, Diagnostics::unmapped_license_products() );
	}

	// ---------------------------------------------------------------------
	// A-75 / B-108: page rows.
	// ---------------------------------------------------------------------

	public function test_a75_lock_self_test_and_client_address(): void {
		$this->assertTrue( Diagnostics::lock_self_test() );

		$proxied = Diagnostics::client_address( array( 'REMOTE_ADDR' => '10.0.0.5' ) );
		$this->assertSame( '10.0.0.5', $proxied['remote'] );
		$this->assertSame( ! ( defined( 'WPLC_TRUSTED_PROXIES' ) && defined( 'WPLC_TRUSTED_PROXY_HEADER' ) ), $proxied['warning'] );

		$direct = Diagnostics::client_address( array( 'REMOTE_ADDR' => '203.0.113.9' ) );
		$this->assertFalse( $direct['warning'] );
	}

	public function test_a75_diagnostics_page_shows_version_schema_heartbeat_and_stripe_state(): void {
		set_current_screen( 'woocommerce_page_wplc-diagnostics' );
		wp_set_current_user( $this->manager );
		Diagnostics::mark_run( 'renewals' );

		$rows   = Diagnostics::rows();
		$by     = array();
		foreach ( $rows as $row ) {
			$by[ $row['label'] ] = $row;
		}
		$this->assertSame( WPLC_VERSION, $by['Plugin version']['value'] );
		$this->assertSame( Schema::VERSION . ' / ' . Schema::VERSION, $by['License schema (installed / expected)']['value'] );
		$this->assertSame( 'ok', $by['License schema (installed / expected)']['state'] );
		$this->assertSame( 'ok', $by['License tables']['state'] );
		$this->assertSame( 'ok', $by['Database locks (GET_LOCK)']['state'] );
		$this->assertArrayHasKey( 'Last daily run', $by );
		$this->assertArrayHasKey( 'Last run: renewals', $by );
		$this->assertArrayHasKey( 'Server time (UTC) / site time', $by );
		$this->assertArrayHasKey( 'Last valid Stripe event', $by );
		$this->assertArrayHasKey( 'Client address of this request', $by );
		$this->assertArrayHasKey( 'Proxy constants', $by );
		$this->assertSame( 'warning', $by['Automatic renewal (Stripe)']['state'] );
		$this->assertStringContainsString( 'Stripe', $by['Automatic renewal (Stripe)']['value'] );
		$this->assertArrayNotHasKey( 'Update package provider', $by, 'The row that always said "Registered" is gone.' );

		// A schema that is behind the code is a warning with instructions.
		update_option( Schema::VERSION_OPTION, '1' );
		$stale = array();
		foreach ( Diagnostics::rows() as $row ) {
			$stale[ $row['label'] ] = $row;
		}
		update_option( Schema::VERSION_OPTION, Schema::VERSION );
		$this->assertSame( 'warning', $stale['License schema (installed / expected)']['state'] );
		$this->assertNotSame( '', $stale['License schema (installed / expected)']['help'] );

		$page = $this->capture( array( Diagnostics::class, 'render' ) );
		$this->assertStringContainsString( 'WP License Captain diagnostics', $page );
		$this->assertStringContainsString( 'Stripe events that need attention', $page );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );
		$this->expectException( WPDieException::class );
		Diagnostics::render();
	}

	public function test_a72_events_needing_review_are_listed_without_payload(): void {
		global $wpdb;
		$tables = $this->tables();
		$wpdb->insert(
			$tables['event_inbox'],
			array(
				'source'          => 'stripe',
				'source_event_id' => 'evt_review_me',
				'event_type'      => 'payment_intent.succeeded',
				'payload_hash'    => hash( 'sha256', 'x' ),
				'payload_json'    => '{"secret_customer":"cus_do_not_show"}',
				'received_at'     => gmdate( 'Y-m-d H:i:s' ),
				'processed_at'    => gmdate( 'Y-m-d H:i:s' ),
				'outcome'         => 'manual_review',
			)
		);
		wp_set_current_user( $this->manager );
		$page = $this->capture( array( Diagnostics::class, 'render' ) );

		$this->assertStringContainsString( 'evt_review_me', $page );
		$this->assertStringContainsString( 'manual_review', $page );
		$this->assertStringNotContainsString( 'cus_do_not_show', $page );
	}

	public function test_a52_settings_page_says_why_automatic_renewal_is_off(): void {
		require_once ABSPATH . 'wp-admin/includes/template.php';
		set_current_screen( 'woocommerce_page_wplc-settings' );
		wp_set_current_user( $this->manager );
		$forced = static function () {
			return array(
				'ok'     => false,
				'reason' => 'Saved payment methods are switched off in the Stripe settings, so there is no card to renew with.',
			);
		};
		add_filter( 'wplc_stripe_adapter_status', $forced );
		Settings::register_settings();
		$page = $this->capture( array( Settings::class, 'render' ) );
		remove_filter( 'wplc_stripe_adapter_status', $forced );

		$this->assertStringContainsString( 'Automatic renewal (Stripe)', $page );
		$this->assertStringContainsString( 'Switched off. Saved payment methods are switched off in the Stripe settings', $page );
		$this->assertStringContainsString( 'renew manually in their account', $page );
	}

	// ---------------------------------------------------------------------
	// B-34: alarm.
	// ---------------------------------------------------------------------

	public function test_b34_a_throwing_alert_receiver_does_not_stop_the_other_alerts(): void {
		$old   = gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS );
		$stuck = $this->seed_license( array( 'renewal_enabled' => 1, 'updated_at' => $old ) )['id'];
		$this->seed_renewal( $stuck, array( 'status' => 'processing', 'updated_at' => $old ) );
		$this->seed_license( array( 'renewal_enabled' => 1, 'updated_at' => $old ) );

		$thresholds = static function () {
			return array(
				'stuck_processing_renewals' => 1,
				'renewing_without_renewal'  => 1,
			);
		};
		add_filter( 'wplc_health_alert_thresholds', $thresholds );
		$seen = array();
		add_action(
			'wplc/health_alert',
			static function ( $metric ) use ( &$seen ) {
				$seen[] = $metric;
				if ( 'stuck_processing_renewals' === $metric ) {
					throw new RuntimeException( 'Receiver is broken' );
				}
			}
		);

		Diagnostics::emit_alerts();
		remove_filter( 'wplc_health_alert_thresholds', $thresholds );

		$this->assertSame( array( 'stuck_processing_renewals', 'renewing_without_renewal' ), $seen );
		$this->assertFalse( doing_action( 'wplc/health_alert' ) );
	}

	// ---------------------------------------------------------------------
	// B-64 / B-137 / B-116: key notice.
	// ---------------------------------------------------------------------

	public function test_b64_key_sample_and_notice_react_to_keys_that_do_not_verify(): void {
		set_current_screen( 'woocommerce_page_wplc-licenses' );
		$this->seed_license();
		$this->seed_license();

		$this->assertSame( array( 'checked' => 2, 'verified' => 2 ), Diagnostics::key_sample( true ) );
		$this->assertFalse( KeyConfigurationNotice::is_fallback_active() );
		$this->assertFalse( KeyConfigurationNotice::has_problem() );
		wp_set_current_user( $this->admin );
		$this->assertSame( '', $this->capture( array( KeyConfigurationNotice::class, 'render' ) ) );

		// A license whose key was issued with a secret that is no longer configured.
		$this->seed_license( array( 'license_key_hash' => hash( 'sha256', 'issued-with-another-secret' ) ) );
		$this->assertSame( array( 'checked' => 3, 'verified' => 2 ), Diagnostics::key_sample( true ) );
		$this->assertTrue( KeyConfigurationNotice::has_problem(), 'Defined constants alone must not hide the notice.' );

		$admin_notice = $this->capture( array( KeyConfigurationNotice::class, 'render' ) );
		$this->assertStringContainsString( 'issued license keys do not match the configured secret', $admin_notice );
		$this->assertStringContainsString( '1 of the 3 most recent license keys', $admin_notice );
		$this->assertStringContainsString( 'WPLC_LICENSE_KEY_SECRETS', $admin_notice );

		// B-137: a shop manager cannot edit wp-config.php and gets no instructions for it.
		wp_set_current_user( $this->manager );
		$manager_notice = $this->capture( array( KeyConfigurationNotice::class, 'render' ) );
		$this->assertStringContainsString( 'issued license keys do not match the configured secret', $manager_notice );
		$this->assertStringContainsString( 'Only an administrator', $manager_notice );
		$this->assertStringNotContainsString( 'WPLC_LICENSE_KEY_SECRETS', $manager_notice );
		$this->assertStringNotContainsString( '<pre', $manager_notice );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );
		$this->assertSame( '', $this->capture( array( KeyConfigurationNotice::class, 'render' ) ) );
	}

	public function test_b116_problem_is_mirrored_as_a_woocommerce_inbox_note_and_removed_again(): void {
		global $wpdb;
		$tables = $this->tables();
		if ( ! class_exists( '\\Automattic\\WooCommerce\\Admin\\Notes\\Notes' ) ) {
			$this->markTestSkipped( 'WooCommerce Admin notes are not available.' );
		}
		$store = WC_Data_Store::load( 'admin-note' );
		wp_set_current_user( $this->manager );

		KeyConfigurationNotice::sync_inbox_note();
		$this->assertCount( 0, $store->get_notes_with_name( KeyConfigurationNotice::NOTE_NAME ) );

		$broken = $this->seed_license( array( 'license_key_hash' => hash( 'sha256', 'issued-with-another-secret' ) ) )['id'];
		delete_transient( 'wplc_key_sample' );
		KeyConfigurationNotice::sync_inbox_note();
		KeyConfigurationNotice::sync_inbox_note();
		$ids = $store->get_notes_with_name( KeyConfigurationNotice::NOTE_NAME );
		$this->assertCount( 1, $ids, 'Exactly one note, also after several admin requests.' );
		$note = new \Automattic\WooCommerce\Admin\Notes\Note( (int) $ids[0] );
		$this->assertSame( 'warning', $note->get_type() );
		$this->assertStringNotContainsString( 'define(', $note->get_content() );

		$wpdb->delete( $tables['licenses'], array( 'id' => $broken ) );
		delete_transient( 'wplc_key_sample' );
		KeyConfigurationNotice::sync_inbox_note();
		$this->assertCount( 0, $store->get_notes_with_name( KeyConfigurationNotice::NOTE_NAME ) );
	}
}
