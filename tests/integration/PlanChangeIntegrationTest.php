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
