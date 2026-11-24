<?php
/**
 * T-110/T-109: plan change of an existing license.
 *
 * Upgrade: prorated order, plan switched when paid (same key and period), idempotent hooks, review when the
 * license changed, full refund reverts. Downgrade: pending until the next renewal, billed with the new plan.
 * Administrator change: now or at the next renewal, no charge, automatic amount never raised without consent.
 *
 * @package WPLC
 */

use WPLC\Account\CustomerPortal;
use WPLC\Api\V1\ActivationService;
use WPLC\Billing\ManualRenewal;
use WPLC\Billing\PlanUpgrade;
use WPLC\Billing\RenewalOrder;
use WPLC\Billing\RenewalProcessor;
use WPLC\License\LicenseKey;
use WPLC\License\LicenseLifecycle;
use WPLC\License\PlanChange;

final class PlanChangeIntegrationTest extends WPLC_Integration_TestCase {

	/** @var array<string,WC_Product_Simple> */
	private $plans = array();

	/** @var int */
	private $customer = 0;

	public function set_up(): void {
		parent::set_up();
		$this->customer = self::factory()->user->create();
		$this->plans    = array(
			'starter'  => $this->create_license_product( array( 'plan_key' => 'starter', 'live_site_limit' => 1, 'entitlements' => array() ), '19.00' ),
			'solo'     => $this->create_license_product( array( 'plan_key' => 'solo', 'live_site_limit' => 1, 'entitlements' => array( 'feature.export' => true ) ), '49.00' ),
			'business' => $this->create_license_product( array( 'plan_key' => 'business', 'live_site_limit' => 5, 'entitlements' => array( 'feature.export' => true, 'feature.white_label' => true ) ), '149.00' ),
		);
	}

	/**
	 * Buy one license of a plan.
	 *
	 * @param string $plan Plan key.
	 * @return int License ID.
	 */
	private function buy( string $plan ): int {
		$order    = $this->create_paid_order( array( array( $this->plans[ $plan ], 1 ) ), $this->customer );
		$licenses = $this->licenses_for_order( $order->get_id() );
		$this->assertCount( 1, $licenses );

		return (int) $licenses[0]['id'];
	}

	/** @return array<string,mixed> */
	private function entitlements( int $license_id ): array {
		global $wpdb;
		$table = $this->tables()['entitlements'];
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT entitlement_key, value_json, ends_at, status FROM {$table} WHERE license_id = %d", $license_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema.
		$out   = array();
		foreach ( $rows as $row ) {
			$out[ $row['entitlement_key'] ] = array( json_decode( $row['value_json'], true ), $row['ends_at'], $row['status'] );
		}
		ksort( $out );

		return $out;
	}

	private function audit_count( int $license_id, string $action ): int {
		global $wpdb;
		$table = $this->tables()['audit_log'];
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE object_type = 'license' AND object_id = %d AND action = %s", $license_id, $action ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema.
	}

	private function set_license( int $license_id, array $data ): void {
		global $wpdb;
		$wpdb->update( $this->tables()['licenses'], $data, array( 'id' => $license_id ) );
	}

	private function product_id( string $plan ): int {
		return $this->plans[ $plan ]->get_id();
	}

	private function start_upgrade( int $license_id, string $plan = 'business', bool $consent = false ): WC_Order {
		$order = PlanUpgrade::start( $license_id, $this->customer, $this->product_id( $plan ), $consent );
		$this->assertInstanceOf( WC_Order::class, $order, is_wp_error( $order ) ? $order->get_error_code() : '' );

		return $order;
	}

	public function test_targets_are_the_other_plans_of_the_product_with_prorated_upgrade_price(): void {
		$license_id = $this->buy( 'solo' );
		$license    = $this->license( $license_id );
		$targets    = PlanChange::targets( $license );

		$this->assertSame( array( $this->product_id( 'starter' ), $this->product_id( 'business' ) ), array_keys( $targets ) );
		$this->assertSame( PlanChange::KIND_DOWNGRADE, $targets[ $this->product_id( 'starter' ) ]['kind'] );
		$this->assertSame( PlanChange::KIND_UPGRADE, $targets[ $this->product_id( 'business' ) ]['kind'] );

		// Bought just now: almost the whole year is left, so almost the full difference (100.00).
		$quote = PlanUpgrade::quote( $license, $targets[ $this->product_id( 'business' ) ], time() );
		$this->assertGreaterThanOrEqual( 9999, $quote['amount_minor'] );
		$this->assertLessThanOrEqual( 10000, $quote['amount_minor'] );
		$this->assertSame( 14900, $quote['renewal_minor'] );
		$this->assertNull( PlanUpgrade::quote( $license, $targets[ $this->product_id( 'starter' ) ], time() ), 'A downgrade has no upgrade price.' );

		// Half of the period left: half of the difference.
		$paid_until = gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS );
		$this->set_license( $license_id, array( 'paid_until' => $paid_until ) );
		$quote = PlanUpgrade::quote( $this->license( $license_id ), $targets[ $this->product_id( 'business' ) ], time() );
		$this->assertEqualsWithDelta( (int) round( 10000 * 30 / 365 ), $quote['amount_minor'], 1 );
	}

	public function test_paid_upgrade_switches_the_plan_with_the_same_key_and_period(): void {
		$license_id = $this->buy( 'solo' );
		$before     = $this->license( $license_id );
		$count      = $this->license_count();

		$order = $this->start_upgrade( $license_id );
		$this->assertSame( (string) $license_id, (string) $order->get_meta( PlanUpgrade::META_LICENSE ) );
		$this->assertTrue( PlanChange::order_bills_product( $order, $this->product_id( 'business' ) ) );
		$this->assertTrue( RenewalOrder::net_matches( $order ) );
		$this->assertGreaterThan( 99.0, (float) $order->get_total() );
		$this->assertLessThanOrEqual( 100.0, (float) $order->get_total() );
		$this->assertSame( 1, $this->audit_count( $license_id, 'plan_upgrade_started' ) );

		// A second click re-prices the same open order instead of creating another one.
		$again = $this->start_upgrade( $license_id );
		$this->assertSame( $order->get_id(), $again->get_id() );

		$this->assertTrue( $order->payment_complete( 'txn_upgrade' ) );

		$after = $this->license( $license_id );
		$this->assertSame( $count, $this->license_count(), 'The upgrade order issues no new license.' );
		$this->assertSame( 'business', $after['plan_key'] );
		$this->assertSame( $before['license_key_hash'], $after['license_key_hash'] );
		$this->assertSame( $before['paid_until'], $after['paid_until'] );
		$this->assertSame( 14900, (int) $after['renewal_amount_minor'] );
		$this->assertSame( $this->product_id( 'business' ), (int) $after['renewal_product_id'] );
		$this->assertSame( $order->get_id(), (int) $after['plan_order_id'] );
		$entitlements = $this->entitlements( $license_id );
		$this->assertSame( 5, $entitlements['sites.live_limit'][0] );
		$this->assertTrue( $entitlements['feature.white_label'][0] );
		$this->assertSame( $before['paid_until'], $entitlements['feature.white_label'][1] );
		$this->assertSame( 5, ActivationService::site_limit( $license_id, $after ) );
		$this->assertSame( PlanUpgrade::STATE_APPLIED, wc_get_order( $order->get_id() )->get_meta( PlanUpgrade::META_STATE ) );
		$this->assertSame( 1, $this->audit_count( $license_id, 'plan_upgraded' ) );

		// Duplicate paid hooks, also one that runs before the state meta was saved.
		do_action( 'woocommerce_order_status_completed', $order->get_id() );
		$fresh = wc_get_order( $order->get_id() );
		$fresh->delete_meta_data( PlanUpgrade::META_STATE );
		$fresh->save();
		PlanUpgrade::handle_paid_order( $order->get_id() );
		$this->assertSame( 1, $this->audit_count( $license_id, 'plan_upgraded' ) );
		$this->assertSame( PlanUpgrade::STATE_APPLIED, wc_get_order( $order->get_id() )->get_meta( PlanUpgrade::META_STATE ) );
	}

	public function test_upgrade_of_an_automatically_renewing_license_requires_and_records_consent(): void {
		$license_id = $this->buy( 'solo' );
		$this->set_license( $license_id, array( 'renewal_enabled' => 1 ) );

		$refused = PlanUpgrade::start( $license_id, $this->customer, $this->product_id( 'business' ), false );
		$this->assertWPError( $refused );
		$this->assertSame( 'upgrade_consent_required', $refused->get_error_code() );

		$order = $this->start_upgrade( $license_id, 'business', true );
		$this->assertNotSame( '', (string) $order->get_meta( PlanUpgrade::META_CONSENT ) );
		$order->payment_complete( 'txn_upgrade' );

		$license = $this->license( $license_id );
		$this->assertSame( 'business', $license['plan_key'] );
		$this->assertSame( 14900, (int) $license['renewal_amount_minor'], 'The consented amount is charged from the next period.' );
		$this->assertSame( 1, (int) $license['renewal_enabled'] );

		// P3-A3: the evidence holds the wording, its hash, the language and the source, as for the checkout consent,
		// and a row in the consents table keeps it with the retention of the consents.
		$evidence = wc_get_order( $order->get_id() )->get_meta( PlanUpgrade::META_CONSENT_EVIDENCE );
		$this->assertIsArray( $evidence );
		$this->assertStringContainsString( 'I agree that my saved payment method is charged', $evidence['text'] );
		$this->assertStringContainsString( '149', $evidence['text'] );
		$this->assertSame( hash( 'sha256', $evidence['text'] ), $evidence['hash'] );
		$this->assertSame( 'upgrade', $evidence['source'] );
		$this->assertNotSame( '', $evidence['locale'] );
		$this->assertSame( (string) $order->get_meta( PlanUpgrade::META_CONSENT ), $evidence['at'] );
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE license_id = %d AND source = %s', $this->tables()['consents'], $license_id, 'upgrade' ), ARRAY_A );
		$this->assertCount( 1, $rows );
		$this->assertSame( $evidence['text'], $rows[0]['consent_text'] );
		$this->assertSame( $evidence['hash'], $rows[0]['text_hash'] );
		$this->assertSame( $evidence['at'], $rows[0]['consent_at'] );
		$this->assertSame( 14900, (int) $rows[0]['amount_minor'] );
		$this->assertSame( $order->get_id(), (int) $rows[0]['order_id'] );
		$this->assertSame( $this->customer, (int) $rows[0]['customer_id'] );
		// A repeated paid hook adds no second row.
		PlanUpgrade::handle_paid_order( $order->get_id() );
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE license_id = %d AND source = %s', $this->tables()['consents'], $license_id, 'upgrade' ) ) );
	}

	// P2-M7 (decision): switching upgrades off stops new upgrade orders; one started before stays payable
	// while its quote applies and is applied when paid, like a downgrade that was already scheduled.
	public function test_upgrade_order_started_before_upgrades_were_switched_off_stays_payable(): void {
		$license_id = $this->buy( 'solo' );
		$order      = $this->start_upgrade( $license_id );
		add_filter( 'wplc_plan_upgrades_enabled', '__return_false' );
		$this->assertFalse( PlanUpgrade::upgrades_allowed() );
		$this->assertWPError( PlanUpgrade::start( $license_id, $this->customer, $this->product_id( 'business' ), false ), 'No new upgrade order.' );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( '', PlanUpgrade::open_order_problem( $order, time() ) );
		$this->assertTrue( $order->needs_payment(), 'The open order can still be paid.' );
		$this->assertTrue( $order->payment_complete( 'txn_after_switch_off' ) );
		$this->assertSame( 'business', $this->license( $license_id )['plan_key'] );
		remove_filter( 'wplc_plan_upgrades_enabled', '__return_false' );
	}

	public function test_foreign_customer_and_downgrade_target_cannot_start_an_upgrade(): void {
		$license_id = $this->buy( 'solo' );
		$this->assertWPError( PlanUpgrade::start( $license_id, self::factory()->user->create(), $this->product_id( 'business' ), false ) );
		$this->assertWPError( PlanUpgrade::start( $license_id, $this->customer, $this->product_id( 'starter' ), false ) );
		$this->set_license( $license_id, array( 'status' => 'suspended' ) );
		$this->assertWPError( PlanUpgrade::start( $license_id, $this->customer, $this->product_id( 'business' ), false ) );
	}

	public function test_paid_upgrade_is_not_applied_when_the_period_changed_meanwhile(): void {
		$license_id = $this->buy( 'solo' );
		$order      = $this->start_upgrade( $license_id );
		$admin      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->assertTrue( LicenseLifecycle::admin_extend( $license_id, gmdate( 'Y-m-d 23:59:59', time() + 400 * DAY_IN_SECONDS ), 'Goodwill', $admin ) );

		$order->payment_complete( 'txn_upgrade' );

		$this->assertSame( 'solo', $this->license( $license_id )['plan_key'] );
		$this->assertSame( PlanUpgrade::STATE_REVIEW, wc_get_order( $order->get_id() )->get_meta( PlanUpgrade::META_STATE ) );
		$this->assertSame( array( $order->get_id() => 'upgrade_period_changed' ), PlanUpgrade::orders_in_review( $license_id ) );
		$this->assertSame( 1, $this->audit_count( $license_id, 'plan_upgrade_rejected' ) );

		PlanUpgrade::handle_paid_order( $order->get_id() );
		$this->assertSame( 1, $this->audit_count( $license_id, 'plan_upgrade_rejected' ), 'Recorded once.' );
	}

	public function test_full_refund_of_an_upgrade_reverts_the_plan_and_partial_refund_keeps_it(): void {
		$license_id = $this->buy( 'solo' );
		$before     = $this->entitlements( $license_id );
		$order      = $this->start_upgrade( $license_id );
		$order->payment_complete( 'txn_upgrade' );
		$order = wc_get_order( $order->get_id() );

		$partial = wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => '10.00', 'reason' => 'Goodwill' ) );
		$this->assertInstanceOf( WC_Order_Refund::class, $partial );
		$this->assertSame( 'business', $this->license( $license_id )['plan_key'] );
		$this->assertSame( 1, $this->audit_count( $license_id, 'plan_upgrade_partially_refunded' ) );

		$rest = wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => wc_format_decimal( (float) $order->get_total() - 10, 2 ), 'reason' => 'Undo' ) );
		$this->assertInstanceOf( WC_Order_Refund::class, $rest );

		$license = $this->license( $license_id );
		$this->assertSame( 'solo', $license['plan_key'] );
		$this->assertSame( 4900, (int) $license['renewal_amount_minor'] );
		$this->assertSame( $this->product_id( 'solo' ), (int) $license['renewal_product_id'] );
		$this->assertSame( 0, (int) $license['plan_order_id'] );
		$this->assertSame( 'active', $license['status'], 'The license itself is not revoked.' );
		$this->assertSame( array_keys( $before ), array_keys( $this->entitlements( $license_id ) ) );
		$this->assertSame( 1, $this->entitlements( $license_id )['sites.live_limit'][0] );
		$this->assertSame( 1, $this->audit_count( $license_id, 'plan_upgrade_reverted' ) );
		$this->assertSame( 1, $this->audit_count( $license_id, 'plan_upgrade_refunded' ) );

		PlanUpgrade::handle_full_refund( $order->get_id() );
		LicenseLifecycle::handle_full_refund( $order->get_id() );
		$this->assertSame( 1, $this->audit_count( $license_id, 'plan_upgrade_refunded' ) );
		$this->assertSame( 'active', $this->license( $license_id )['status'] );
	}

	public function test_downgrade_takes_effect_with_the_next_manual_renewal(): void {
		$license_id = $this->buy( 'business' );
		$this->assertSame( 'plan_change_unavailable', PlanChange::schedule_pending( $license_id, $this->product_id( 'business' ), $this->customer, 'x', false ) );
		$this->assertSame( 'plan_change_unavailable', PlanChange::schedule_pending( $license_id, $this->product_id( 'solo' ) + 999999, $this->customer, 'x', false ) );

		$this->assertSame( 'plan_change_scheduled', PlanChange::schedule_pending( $license_id, $this->product_id( 'starter' ), $this->customer, 'plan_downgrade_requested', false ) );
		$license = $this->license( $license_id );
		$this->assertSame( 'business', $license['plan_key'], 'The current plan stays until the renewal.' );
		$this->assertSame( 'starter', $license['pending_plan_key'] );
		$this->assertSame( 5, $this->entitlements( $license_id )['sites.live_limit'][0] );

		$old_paid_until = gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS );
		$this->set_license( $license_id, array( 'paid_until' => $old_paid_until ) );
		$renewal = ManualRenewal::start( $license_id, $this->customer );
		$this->assertInstanceOf( WC_Order::class, $renewal );
		$this->assertTrue( PlanChange::order_bills_product( $renewal, $this->product_id( 'starter' ) ) );
		$this->assertSame( '19.00', wc_format_decimal( $renewal->get_total(), 2 ) );
		$renewal->payment_complete( 'txn_renewal' );

		$license = $this->license( $license_id );
		$this->assertGreaterThan( $old_paid_until, $license['paid_until'] );
		$this->assertSame( 'starter', $license['plan_key'] );
		$this->assertSame( '', $license['pending_plan_key'] );
		$this->assertSame( 1900, (int) $license['renewal_amount_minor'] );
		$entitlements = $this->entitlements( $license_id );
		$this->assertSame( 1, $entitlements['sites.live_limit'][0] );
		$this->assertArrayNotHasKey( 'feature.white_label', $entitlements );
		$this->assertSame( $license['paid_until'], $entitlements['sites.live_limit'][1] );
		$this->assertSame( 1, $this->audit_count( $license_id, 'plan_changed_at_renewal' ) );
	}

	public function test_a_scheduled_downgrade_can_be_withdrawn(): void {
		$license_id = $this->buy( 'business' );
		$this->assertSame( 'plan_change_scheduled', PlanChange::schedule_pending( $license_id, $this->product_id( 'solo' ), $this->customer, 'plan_downgrade_requested', false ) );
		$this->assertTrue( PlanChange::cancel_pending( $license_id, $this->customer, 'plan_change_withdrawn' ) );
		$this->assertFalse( PlanChange::cancel_pending( $license_id, $this->customer, 'plan_change_withdrawn' ) );
		$license = $this->license( $license_id );
		$this->assertSame( '', $license['pending_plan_key'] );
		$this->assertSame( 0, (int) $license['pending_product_id'] );
		$this->assertSame( 1, $this->audit_count( $license_id, 'plan_change_cancelled' ) );
	}

	public function test_upgrade_reprices_an_open_manual_renewal_order(): void {
		$license_id = $this->buy( 'solo' );
		$this->set_license( $license_id, array( 'paid_until' => gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS ) ) );
		$renewal = ManualRenewal::start( $license_id, $this->customer );
		$this->assertInstanceOf( WC_Order::class, $renewal );
		$this->assertSame( '49.00', wc_format_decimal( $renewal->get_total(), 2 ) );

		$upgrade = $this->start_upgrade( $license_id );
		$upgrade->payment_complete( 'txn_upgrade' );
		$this->assertSame( 'business', $this->license( $license_id )['plan_key'] );

		$renewal = wc_get_order( $renewal->get_id() );
		$this->assertTrue( PlanChange::order_bills_product( $renewal, $this->product_id( 'business' ) ) );
		$this->assertSame( '149.00', wc_format_decimal( $renewal->get_total(), 2 ) );
		$this->assertTrue( RenewalOrder::net_matches( $renewal ) );
	}

	/**
	 * Security audit of 14 Nov 2026 (SA-02): cash on delivery is "processing" before any money
	 * arrived. A manual renewal and an upgrade paid that way count when the order is completed,
	 * like a first purchase (A-24).
	 */
	public function test_sa02_cash_on_delivery_extends_and_upgrades_only_when_completed(): void {
		$license_id = $this->buy( 'solo' );
		$this->set_license( $license_id, array( 'paid_until' => gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS ) ) );
		$before = (string) $this->license( $license_id )['paid_until'];

		$renewal = ManualRenewal::start( $license_id, $this->customer );
		$this->assertInstanceOf( WC_Order::class, $renewal );
		$renewal->set_payment_method( 'cod' );
		$renewal->save();
		$renewal->update_status( 'processing' );
		$this->assertTrue( wc_get_order( $renewal->get_id() )->is_paid(), 'WooCommerce calls the order paid.' );
		$this->assertSame( $before, (string) $this->license( $license_id )['paid_until'], 'No money yet: the term is unchanged.' );
		RenewalProcessor::reconcile_paid_renewal_order( $renewal->get_id() );
		$this->assertSame( $before, (string) $this->license( $license_id )['paid_until'], 'The repair job does not book it either.' );

		wc_get_order( $renewal->get_id() )->update_status( 'completed' );
		$this->assertGreaterThan( strtotime( $before . ' UTC' ), strtotime( (string) $this->license( $license_id )['paid_until'] . ' UTC' ), 'Completed: the term is extended.' );

		$upgrade = $this->start_upgrade( $license_id );
		$upgrade->set_payment_method( 'cod' );
		$upgrade->save();
		$upgrade->update_status( 'processing' );
		$this->assertSame( 'solo', $this->license( $license_id )['plan_key'] );
		wc_get_order( $upgrade->get_id() )->update_status( 'completed' );
		$this->assertSame( 'business', $this->license( $license_id )['plan_key'] );

		// Another payment method that is "processing" was paid: it counts at once, as before.
		$second = $this->buy( 'solo' );
		$this->set_license( $second, array( 'paid_until' => gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS ) ) );
		$before = (string) $this->license( $second )['paid_until'];
		$order  = ManualRenewal::start( $second, $this->customer );
		$order->set_payment_method( 'bacs' );
		$order->save();
		$order->update_status( 'processing' );
		$this->assertGreaterThan( strtotime( $before . ' UTC' ), strtotime( (string) $this->license( $second )['paid_until'] . ' UTC' ) );
	}

	public function test_lower_site_limit_keeps_existing_sites_and_blocks_new_activations(): void {
		$license_id = $this->buy( 'business' );
		for ( $i = 0; $i < 3; $i++ ) {
			$this->seed_activation( $license_id );
		}
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->assertSame( 'plan_changed', PlanChange::admin_change_now( $license_id, $this->product_id( 'solo' ), 'Customer asked for Solo', $admin ) );

		global $wpdb;
		$active = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->tables()['activations']} WHERE license_id = %d AND status = 'active'", $license_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema.
		$this->assertSame( 3, $active, 'Excess sites are not deactivated.' );
		$license = $this->license( $license_id );
		$this->assertSame( 1, ActivationService::site_limit( $license_id, $license ) );

		$key    = LicenseKey::for_uuid( $license['license_uuid'], $license['license_key_secret_id'] );
		$result = $this->activate_via_api( $key );
		$this->assertApiError( 409, 'site_limit', $result['response'] );
	}

	public function test_admin_changes_the_plan_with_a_reason_without_raising_the_automatic_amount(): void {
		$license_id = $this->buy( 'solo' );
		$this->set_license( $license_id, array( 'renewal_enabled' => 1 ) );
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$missing = $this->run_admin_post( 'wplc_admin_change_plan', array( 'license_id' => $license_id, 'product_id' => $this->product_id( 'business' ), 'when' => 'now', 'reason' => '', '_wpnonce' => wp_create_nonce( 'wplc_admin_change_plan_' . $license_id ) ) );
		$this->assertStringContainsString( 'plan_change_missing_input', $missing['location'] );
		$this->assertSame( 'solo', $this->license( $license_id )['plan_key'] );

		$result = $this->run_admin_post( 'wplc_admin_change_plan', array( 'license_id' => $license_id, 'product_id' => $this->product_id( 'business' ), 'when' => 'now', 'reason' => 'Goodwill upgrade', '_wpnonce' => wp_create_nonce( 'wplc_admin_change_plan_' . $license_id ) ) );
		$this->assertSame( 'redirect', $result['outcome'] );
		$this->assertStringContainsString( 'plan_changed', $result['location'] );
		$license = $this->license( $license_id );
		$this->assertSame( 'business', $license['plan_key'] );
		$this->assertSame( 4900, (int) $license['renewal_amount_minor'], 'No consent for the higher automatic amount.' );
		$this->assertSame( $this->product_id( 'business' ), (int) $license['renewal_product_id'] );
		$this->assertSame( 1, $this->audit_count( $license_id, 'admin_plan_change' ) );

		$scheduled = $this->run_admin_post( 'wplc_admin_change_plan', array( 'license_id' => $license_id, 'product_id' => $this->product_id( 'starter' ), 'when' => 'renewal', 'reason' => 'Downgrade at renewal', '_wpnonce' => wp_create_nonce( 'wplc_admin_change_plan_' . $license_id ) ) );
		$this->assertStringContainsString( 'plan_change_scheduled', $scheduled['location'] );
		$this->assertSame( 'starter', $this->license( $license_id )['pending_plan_key'] );

		$withdrawn = $this->run_admin_post( 'wplc_admin_cancel_plan_change', array( 'license_id' => $license_id, 'reason' => 'Changed mind', '_wpnonce' => wp_create_nonce( 'wplc_admin_cancel_plan_change_' . $license_id ) ) );
		$this->assertStringContainsString( 'plan_change_withdrawn', $withdrawn['location'] );
		$this->assertSame( '', $this->license( $license_id )['pending_plan_key'] );

		$subscriber = self::factory()->user->create( array( 'role' => 'customer' ) );
		wp_set_current_user( $subscriber );
		$denied = $this->run_admin_post( 'wplc_admin_change_plan', array( 'license_id' => $license_id, 'product_id' => $this->product_id( 'solo' ), 'when' => 'now', 'reason' => 'x', '_wpnonce' => wp_create_nonce( 'wplc_admin_change_plan_' . $license_id ) ) );
		$this->assertSame( 'die', $denied['outcome'] );
	}

	/**
	 * Taxes on with one 19 % rate in the base country.
	 *
	 * @param bool $inclusive Shop enters prices including tax.
	 */
	private function set_tax( bool $inclusive ): void {
		global $wpdb;
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_prices_include_tax', $inclusive ? 'yes' : 'no' );
		update_option( 'woocommerce_default_country', 'DE' );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_tax_rates" );
		WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country' => 'DE',
				'tax_rate' => '19',
				'tax_rate_name' => 'VAT',
				'tax_rate_priority' => 1,
				'tax_rate_compound' => 0,
				'tax_rate_shipping' => 1,
				'tax_rate_order' => 1,
				'tax_rate_class' => '',
			)
		);
		WC_Cache_Helper::invalidate_cache_group( 'taxes' );
		WC_Cache_Helper::get_transient_version( 'taxes', true );
		wp_cache_flush();
	}

	private function set_price( string $plan, string $price ): void {
		$product = wc_get_product( $this->product_id( $plan ) );
		$product->set_regular_price( $price );
		$product->save();
	}

	public function test_plan_change_records_how_the_new_renewal_amount_is_to_be_read(): void {
		// P-04: bought while the shop entered net prices (flag 0), then the shop switched to gross prices.
		$this->set_tax( false );
		$license_id = $this->buy( 'solo' );
		$this->assertSame( '0', (string) $this->license( $license_id )['renewal_amount_includes_tax'] );
		$this->set_tax( true );

		$order = $this->start_upgrade( $license_id );
		$this->assertSame( '1', (string) $order->get_meta( PlanUpgrade::META_RENEWAL_INCLUDES_TAX ) );
		$this->assertSame( '0', (string) $order->get_meta( PlanUpgrade::META_FROM_INCLUDES_TAX ) );
		// The credit is the contract price 49.00 net, compared as gross like the target: 58.31.
		$this->assertSame( '5831', (string) $order->get_meta( PlanUpgrade::META_CREDIT_MINOR ) );
		$order->payment_complete( 'txn_upgrade' );

		$license = $this->license( $license_id );
		$this->assertSame( 'business', $license['plan_key'] );
		$this->assertSame( 14900, (int) $license['renewal_amount_minor'] );
		$this->assertSame( '1', (string) $license['renewal_amount_includes_tax'], '149.00 is a gross catalog price: never 149.00 + 19 % = 177.31.' );

		// A full refund restores the old amount together with its old flag.
		$order = wc_get_order( $order->get_id() );
		wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => wc_format_decimal( (float) $order->get_total(), 2 ), 'reason' => 'Undo' ) );
		$license = $this->license( $license_id );
		$this->assertSame( 'solo', $license['plan_key'] );
		$this->assertSame( 4900, (int) $license['renewal_amount_minor'] );
		$this->assertSame( '0', (string) $license['renewal_amount_includes_tax'] );

		// Administrator downgrade: the cheaper catalog price is taken over with the current (gross) flag.
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->assertSame( 'plan_changed', PlanChange::admin_change_now( $license_id, $this->product_id( 'starter' ), 'Customer asked', $admin ) );
		$license = $this->license( $license_id );
		$this->assertSame( 1900, (int) $license['renewal_amount_minor'] );
		$this->assertSame( '1', (string) $license['renewal_amount_includes_tax'] );
	}

	public function test_admin_upgrade_without_consent_keeps_the_amount_and_its_flag(): void {
		$this->set_tax( false );
		$license_id = $this->buy( 'solo' );
		$this->set_license( $license_id, array( 'renewal_enabled' => 1 ) );
		$this->set_tax( true );
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->assertSame( 'plan_changed', PlanChange::admin_change_now( $license_id, $this->product_id( 'business' ), 'Goodwill', $admin ) );
		$license = $this->license( $license_id );
		$this->assertSame( 4900, (int) $license['renewal_amount_minor'] );
		$this->assertSame( '0', (string) $license['renewal_amount_includes_tax'], 'The kept amount is still net.' );
	}

	public function test_upgrade_decision_and_credit_use_the_contract_price_not_todays_catalog_price(): void {
		// P-06: bought for 49.00; the shop now sells Solo for 199.00, Business still costs 149.00.
		$license_id = $this->buy( 'solo' );
		$this->set_price( 'solo', '199.00' );
		$license = $this->license( $license_id );
		$targets = PlanChange::targets( $license );
		$this->assertSame( PlanChange::KIND_UPGRADE, $targets[ $this->product_id( 'business' ) ]['kind'], 'Business is no free "downgrade" for a 49.00 contract.' );
		$this->assertSame( 'plan_change_unavailable', PlanChange::schedule_pending( $license_id, $this->product_id( 'business' ), $this->customer, 'plan_downgrade_requested', false ) );
		$quote = PlanUpgrade::quote( $license, $targets[ $this->product_id( 'business' ) ], time() );
		$this->assertGreaterThanOrEqual( 9999, $quote['amount_minor'] );
		$this->assertLessThanOrEqual( 10000, $quote['amount_minor'] );
		$this->assertSame( 4900, $quote['credit_minor'] );

		// Catalog price lowered to 19.00: the credit is still the 49.00 that was paid.
		$this->set_price( 'solo', '19.00' );
		$quote = PlanUpgrade::quote( $license, PlanChange::targets( $license )[ $this->product_id( 'business' ) ], time() );
		$this->assertLessThanOrEqual( 10000, $quote['amount_minor'], 'Not 149.00 - 19.00.' );
	}

	public function test_contract_price_is_what_the_last_manual_renewal_paid(): void {
		$license_id = $this->buy( 'solo' );
		$this->set_license( $license_id, array( 'paid_until' => gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS ) ) );
		// INH-01: the shop raised the price; the renewal still costs what was agreed at purchase.
		$this->set_price( 'solo', '79.00' );
		$renewal = ManualRenewal::start( $license_id, $this->customer );
		$this->assertInstanceOf( WC_Order::class, $renewal );
		$this->assertSame( '49.00', wc_format_decimal( $renewal->get_total(), 2 ) );
		$renewal->payment_complete( 'txn_renewal' );

		$license = $this->license( $license_id );
		$this->assertSame( 4900, (int) $license['renewal_amount_minor'], 'The stored amount is unchanged by a manual renewal.' );
		// Support lowers the amount of the following renewals: this period was still paid at 49.00.
		$this->set_license( $license_id, array( 'renewal_amount_minor' => 3900 ) );
		$this->assertSame( 4900, PlanChange::contract_price( $this->license( $license_id ) )['amount_minor'], 'This period was paid at 49.00.' );
	}

	/**
	 * INH-01: the documented way to change the agreed amount of one license is the support action; it
	 * reaches the next manual renewal, and the license page offers it without automatic renewal.
	 */
	public function test_inh01_support_changes_the_agreed_amount_of_a_manually_renewed_license(): void {
		$license_id = $this->buy( 'solo' );
		$this->set_license( $license_id, array( 'paid_until' => gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS ), 'renewal_enabled' => 0 ) );
		$this->set_price( 'solo', '79.00' );
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->assertSame( \WPLC\Admin\SupportActions::RESULT_OK, \WPLC\Admin\SupportActions::change_renewal_amount( $license_id, 3900, 4900, 'Goodwill', $admin ) );
		$this->assertSame( \WPLC\Admin\SupportActions::RESULT_INCREASE_REFUSED, \WPLC\Admin\SupportActions::change_renewal_amount( $license_id, 7900, 3900, 'New price list', $admin ), 'Raising needs the customer: the filter wplc_admin_can_raise_renewal_amount.' );

		$renewal = ManualRenewal::start( $license_id, $this->customer );
		$this->assertInstanceOf( WC_Order::class, $renewal );
		$this->assertSame( '39.00', wc_format_decimal( $renewal->get_total(), 2 ), 'Neither the catalog price of today (79.00) nor the old amount.' );

		wp_set_current_user( $admin );
		ob_start();
		\WPLC\Admin\LicenseDetail::render( $license_id );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'name="action" value="wplc_admin_renewal_amount"', $html );
		$this->assertStringNotContainsString( 'wplc_admin_cancel_renewal', $html, 'Nothing to cancel without automatic renewal.' );
	}

	public function test_open_upgrade_order_is_not_payable_once_its_quote_no_longer_applies(): void {
		// P-17: quote older than a day.
		$license_id = $this->buy( 'solo' );
		$order      = $this->start_upgrade( $license_id );
		$this->assertTrue( wc_get_order( $order->get_id() )->needs_payment() );
		$order->update_meta_data( PlanUpgrade::META_QUOTED_AT, time() - 2 * DAY_IN_SECONDS );
		$order->save();
		$this->assertFalse( wc_get_order( $order->get_id() )->needs_payment() );

		// "Upgrade" again re-prices the same order and it is payable again.
		$again = $this->start_upgrade( $license_id );
		$this->assertSame( $order->get_id(), $again->get_id() );
		$this->assertTrue( wc_get_order( $order->get_id() )->needs_payment() );

		// The period changed: not payable, and the daily sweep cancels the order.
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->assertTrue( LicenseLifecycle::admin_extend( $license_id, gmdate( 'Y-m-d 23:59:59', time() + 400 * DAY_IN_SECONDS ), 'Goodwill', $admin ) );
		$this->assertFalse( wc_get_order( $order->get_id() )->needs_payment() );
		PlanUpgrade::reconcile_orders();
		$this->assertSame( 'cancelled', wc_get_order( $order->get_id() )->get_status() );
		$this->assertSame( 'solo', $this->license( $license_id )['plan_key'] );
	}

	/** SET-LIC-42: how long an upgrade offer stays payable is a setting (hours). */
	public function test_upgrade_offer_validity_follows_the_setting(): void {
		$license_id = $this->buy( 'solo' );
		$order      = $this->start_upgrade( $license_id );
		$order->update_meta_data( PlanUpgrade::META_QUOTED_AT, time() - 2 * HOUR_IN_SECONDS );
		$order->save();
		$this->assertTrue( wc_get_order( $order->get_id() )->needs_payment(), 'Default 24 hours.' );

		update_option( \WPLC\Admin\Settings::OPTION, array( 'upgrade_quote_hours' => '1' ) );
		$this->assertSame( HOUR_IN_SECONDS, PlanUpgrade::quote_ttl() );
		$this->assertFalse( wc_get_order( $order->get_id() )->needs_payment() );
		$this->assertSame( \WPLC\License\AuditReason::UPGRADE_QUOTE_EXPIRED, PlanUpgrade::open_order_problem( wc_get_order( $order->get_id() ), time() ) );
		delete_option( \WPLC\Admin\Settings::OPTION );
	}

	public function test_booking_a_renewal_cancels_upgrade_orders_of_the_previous_period(): void {
		$license_id = $this->buy( 'solo' );
		$this->set_license( $license_id, array( 'paid_until' => gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS ) ) );
		$upgrade = $this->start_upgrade( $license_id );
		$renewal = ManualRenewal::start( $license_id, $this->customer );
		$this->assertInstanceOf( WC_Order::class, $renewal );
		$renewal->payment_complete( 'txn_renewal' );

		$this->assertSame( 'cancelled', wc_get_order( $upgrade->get_id() )->get_status() );
		$this->assertSame( 'solo', $this->license( $license_id )['plan_key'] );
	}

	public function test_paid_upgrade_that_fails_afterwards_reverts_the_plan_and_is_applied_again_when_paid(): void {
		// P-17: bounced SEPA debit.
		$license_id = $this->buy( 'solo' );
		$order      = $this->start_upgrade( $license_id );
		$order->payment_complete( 'txn_sepa' );
		$this->assertSame( 'business', $this->license( $license_id )['plan_key'] );

		wc_get_order( $order->get_id() )->update_status( 'failed', 'SEPA debit returned' );
		$license = $this->license( $license_id );
		$this->assertSame( 'solo', $license['plan_key'] );
		$this->assertSame( 4900, (int) $license['renewal_amount_minor'] );
		$this->assertSame( 0, (int) $license['plan_order_id'] );
		$this->assertSame( 'active', $license['status'], 'The license keeps the plan that was paid for.' );
		$this->assertSame( 1, $this->entitlements( $license_id )['sites.live_limit'][0] );
		$this->assertSame( PlanUpgrade::STATE_CANCELLED, wc_get_order( $order->get_id() )->get_meta( PlanUpgrade::META_STATE ) );
		$this->assertSame( 1, $this->audit_count( $license_id, 'plan_upgrade_cancelled' ) );
		$this->assertSame( 1, $this->audit_count( $license_id, 'plan_upgrade_reverted' ) );

		// Duplicate hook: nothing more happens.
		PlanUpgrade::handle_cancelled_order( $order->get_id() );
		$this->assertSame( 1, $this->audit_count( $license_id, 'plan_upgrade_cancelled' ) );

		// The shop receives the money after all and marks the order as paid again.
		wc_get_order( $order->get_id() )->update_status( 'processing', 'Paid by bank transfer' );
		$this->assertSame( 'business', $this->license( $license_id )['plan_key'] );
		$this->assertSame( PlanUpgrade::STATE_APPLIED, wc_get_order( $order->get_id() )->get_meta( PlanUpgrade::META_STATE ) );
	}

	public function test_cancelled_upgrade_that_cannot_be_reverted_is_reported_for_review(): void {
		$license_id = $this->buy( 'solo' );
		$order      = $this->start_upgrade( $license_id );
		$order->payment_complete( 'txn_upgrade' );
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->assertTrue( LicenseLifecycle::admin_extend( $license_id, gmdate( 'Y-m-d 23:59:59', time() + 400 * DAY_IN_SECONDS ), 'Goodwill', $admin ) );
		$reviews = did_action( 'wplc/license_needs_review' );

		wc_get_order( $order->get_id() )->update_status( 'cancelled', 'Cancelled by the shop' );

		$this->assertSame( 'business', $this->license( $license_id )['plan_key'] );
		$this->assertSame( PlanUpgrade::STATE_CANCELLED, wc_get_order( $order->get_id() )->get_meta( PlanUpgrade::META_STATE ) );
		$this->assertSame( $reviews + 1, did_action( 'wplc/license_needs_review' ) );
		$this->assertSame( 0, $this->audit_count( $license_id, 'plan_upgrade_reverted' ) );
	}

	public function test_daily_sweep_applies_paid_upgrades_and_reverts_cancelled_ones_whose_hooks_were_lost(): void {
		$license_id = $this->buy( 'solo' );
		$order      = $this->start_upgrade( $license_id );
		$hooks      = array( 'woocommerce_payment_complete', 'woocommerce_order_status_processing', 'woocommerce_order_status_completed' );
		foreach ( $hooks as $hook ) {
			remove_action( $hook, array( PlanUpgrade::class, 'handle_paid_order' ) );
		}
		$order->payment_complete( 'txn_lost_hook' );
		foreach ( $hooks as $hook ) {
			add_action( $hook, array( PlanUpgrade::class, 'handle_paid_order' ) );
		}
		$this->assertSame( 'solo', $this->license( $license_id )['plan_key'] );

		PlanUpgrade::reconcile_orders();
		$this->assertSame( 'business', $this->license( $license_id )['plan_key'] );

		remove_action( 'woocommerce_order_status_cancelled', array( PlanUpgrade::class, 'handle_cancelled_order' ), 20 );
		wc_get_order( $order->get_id() )->update_status( 'cancelled' );
		add_action( 'woocommerce_order_status_cancelled', array( PlanUpgrade::class, 'handle_cancelled_order' ), 20, 1 );
		$this->assertSame( 'business', $this->license( $license_id )['plan_key'] );

		PlanUpgrade::reconcile_orders();
		$this->assertSame( 'solo', $this->license( $license_id )['plan_key'] );
		$this->assertSame( PlanUpgrade::STATE_CANCELLED, wc_get_order( $order->get_id() )->get_meta( PlanUpgrade::META_STATE ) );
	}

	public function test_account_page_offers_upgrade_and_downgrade_and_customer_forms_work(): void {
		$license_id = $this->buy( 'solo' );
		wp_set_current_user( $this->customer );

		ob_start();
		CustomerPortal::render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'wplc_start_upgrade', $html );
		$this->assertStringContainsString( 'wplc_schedule_downgrade', $html );
		$this->assertStringContainsString( 'value="' . $this->product_id( 'business' ) . '"', $html );

		$result = $this->run_admin_post( 'wplc_start_upgrade', array( 'license_id' => $license_id, 'product_id' => $this->product_id( 'business' ), '_wpnonce' => wp_create_nonce( 'wplc_start_upgrade_' . $license_id ) ) );
		$this->assertSame( 'redirect', $result['outcome'] );
		$this->assertStringContainsString( 'order-pay', $result['location'] );

		$result = $this->run_admin_post( 'wplc_schedule_downgrade', array( 'license_id' => $license_id, 'product_id' => $this->product_id( 'starter' ), '_wpnonce' => wp_create_nonce( 'wplc_schedule_downgrade_' . $license_id ) ) );
		$this->assertSame( 'redirect', $result['outcome'] );
		$this->assertSame( 'starter', $this->license( $license_id )['pending_plan_key'] );

		ob_start();
		CustomerPortal::render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'wplc_cancel_plan_change', $html );

		$result = $this->run_admin_post( 'wplc_cancel_plan_change', array( 'license_id' => $license_id, '_wpnonce' => wp_create_nonce( 'wplc_cancel_plan_change_' . $license_id ) ) );
		$this->assertSame( 'redirect', $result['outcome'] );
		$this->assertSame( '', $this->license( $license_id )['pending_plan_key'] );
	}
}
