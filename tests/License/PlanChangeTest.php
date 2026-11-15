<?php
/**
 * T-109/T-110: proration of upgrades and plan resolution rules.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\Billing\RenewalOrder;
use WPLC\License\PlanChange;

final class PlanChangeTest extends TestCase {

	private function ts( string $utc ): int {
		return (int) strtotime( $utc . ' UTC' );
	}

	public function test_period_start_is_one_interval_before_paid_until(): void {
		$this->assertSame( $this->ts( '2026-01-15 10:00:00' ), PlanChange::period_start_before( $this->ts( '2027-01-15 10:00:00' ), 'year' ) );
		$this->assertSame( $this->ts( '2026-10-15 10:00:00' ), PlanChange::period_start_before( $this->ts( '2026-11-15 10:00:00' ), 'month' ) );
		// End of month is clamped like RenewalScheduler::period_end().
		$this->assertSame( $this->ts( '2026-02-28 00:00:00' ), PlanChange::period_start_before( $this->ts( '2026-03-31 00:00:00' ), 'month' ) );
		$this->assertSame( $this->ts( '2023-02-28 12:00:00' ), PlanChange::period_start_before( $this->ts( '2024-02-29 12:00:00' ), 'year' ) );
	}

	public function test_upgrade_price_is_the_prorated_difference_for_the_remaining_time(): void {
		$paid_until = $this->ts( '2027-01-01 00:00:00' );
		$start      = PlanChange::period_start_before( $paid_until, 'year' );
		$half       = $start + intdiv( $paid_until - $start, 2 );

		// Solo 49.00 -> Business 149.00, half of the year left: 50.00.
		$this->assertSame( 5000, PlanChange::upgrade_amount( 4900, 14900, $half, $paid_until, 'year' ) );
		// Right at the period start: the full difference.
		$this->assertSame( 10000, PlanChange::upgrade_amount( 4900, 14900, $start, $paid_until, 'year' ) );
		// 73 of 365 days left: 100.00 * 73/365 = 20.00.
		$this->assertSame( 2000, PlanChange::upgrade_amount( 4900, 14900, $paid_until - 73 * 86400, $paid_until, 'year' ) );
	}

	public function test_upgrade_price_is_rounded_half_up_in_minor_units(): void {
		$paid_until = $this->ts( '2026-12-01 00:00:00' );
		$total      = $paid_until - PlanChange::period_start_before( $paid_until, 'month' ); // 30 days.
		// 1.00 difference, 1/3 of the period left: 33.33... -> 33; 2/3 -> 66.66... -> 67.
		$this->assertSame( 33, PlanChange::upgrade_amount( 100, 200, $paid_until - intdiv( $total, 3 ), $paid_until, 'month' ) );
		$this->assertSame( 67, PlanChange::upgrade_amount( 100, 200, $paid_until - intdiv( 2 * $total, 3 ), $paid_until, 'month' ) );
	}

	public function test_upgrade_price_is_never_negative(): void {
		$paid_until = $this->ts( '2027-01-01 00:00:00' );
		$now        = $paid_until - 100 * 86400;
		$this->assertSame( 0, PlanChange::upgrade_amount( 14900, 4900, $now, $paid_until, 'year' ), 'A downgrade refunds nothing.' );
		$this->assertSame( 0, PlanChange::upgrade_amount( 4900, 4900, $now, $paid_until, 'year' ) );
		$this->assertSame( 0, PlanChange::upgrade_amount( 4900, 14900, $paid_until, $paid_until, 'year' ), 'Nothing left of the period.' );
		$this->assertSame( 0, PlanChange::upgrade_amount( 4900, 14900, $paid_until + 10, $paid_until, 'year' ) );
		// More than one period left (paid ahead): capped at the full difference.
		$this->assertSame( 10000, PlanChange::upgrade_amount( 4900, 14900, $paid_until - 800 * 86400, $paid_until, 'year' ) );
	}

	public function test_upgrade_price_reports_overflow(): void {
		$paid_until = $this->ts( '2027-01-01 00:00:00' );
		$this->assertFalse( PlanChange::upgrade_amount( 0, PHP_INT_MAX, $paid_until - 86400 * 100, $paid_until, 'year' ) );
	}

	public function test_kind_follows_the_regular_prices(): void {
		$this->assertSame( PlanChange::KIND_UPGRADE, PlanChange::kind( 4900, 14900 ) );
		$this->assertSame( PlanChange::KIND_DOWNGRADE, PlanChange::kind( 14900, 4900 ) );
		$this->assertSame( PlanChange::KIND_DOWNGRADE, PlanChange::kind( 4900, 4900 ), 'Equal price: no charge, at the next renewal.' );
	}

	public function test_automatic_renewal_amount_rises_only_with_consent(): void {
		// Customer upgrade with consent: the new price.
		$this->assertSame( 14900, PlanChange::next_renewal_amount( 4900, 14900, true, true ) );
		// Administrator upgrade without consent: the old automatic amount stays.
		$this->assertSame( 4900, PlanChange::next_renewal_amount( 4900, 14900, true, false ) );
		// Downgrade: the lower price, consent or not.
		$this->assertSame( 4900, PlanChange::next_renewal_amount( 14900, 4900, true, false ) );
		// No automatic renewal: always the target price.
		$this->assertSame( 14900, PlanChange::next_renewal_amount( 4900, 14900, false, false ) );
		// No price for the target: keep what is stored.
		$this->assertSame( 4900, PlanChange::next_renewal_amount( 4900, 0, true, true ) );
	}

	public function test_tax_basis_conversion_between_gross_and_net(): void {
		// P-04/P-06: 149.00 gross at 19 % is 125.21 net, and back.
		$this->assertSame( 12521, PlanChange::convert_tax_basis( 14900, true, false, 19.0 ) );
		$this->assertSame( 14900, PlanChange::convert_tax_basis( 12521, false, true, 19.0 ) );
		// Same basis, unknown basis, no tax: unchanged.
		$this->assertSame( 14900, PlanChange::convert_tax_basis( 14900, true, true, 19.0 ) );
		$this->assertSame( 14900, PlanChange::convert_tax_basis( 14900, null, false, 19.0 ) );
		$this->assertSame( 14900, PlanChange::convert_tax_basis( 14900, true, false, 0.0 ) );
		$this->assertSame( 0, PlanChange::convert_tax_basis( 0, true, false, 19.0 ) );
	}

	public function test_stored_tax_flag_is_read_as_gross_net_or_unknown(): void {
		$this->assertTrue( PlanChange::stored_includes_tax( array( 'renewal_amount_includes_tax' => '1' ) ) );
		$this->assertFalse( PlanChange::stored_includes_tax( array( 'renewal_amount_includes_tax' => '0' ) ) );
		$this->assertNull( PlanChange::stored_includes_tax( array( 'renewal_amount_includes_tax' => null ) ) );
		$this->assertNull( PlanChange::stored_includes_tax( array() ) );
	}

	public function test_contract_price_is_the_stored_amount_not_the_catalog_price(): void {
		// P-06: no WooCommerce here, so the catalog cannot be read; the stored amount is the contract price.
		$license = array(
			'id' => 1,
			'paid_until' => '2027-01-01 00:00:00',
			'plan_order_id' => 0,
			'renewal_product_id' => 0,
			'renewal_amount_minor' => 4900,
			'renewal_amount_includes_tax' => 0,
			'renewal_currency_decimals' => 2,
			'renewal_currency' => 'eur',
		);
		$this->assertSame( array( 'amount_minor' => 4900, 'decimals' => 2, 'currency' => 'EUR' ), PlanChange::contract_price( $license ) );
	}

	public function test_renewal_terms_after_a_change_keep_the_flag_of_the_amount_they_keep(): void {
		// No WooCommerce: the shop basis is net and there is no tax rate.
		$license = array(
			'renewal_amount_minor' => 4900,
			'renewal_amount_includes_tax' => 1,
			'renewal_enabled' => 1,
			'renewal_product_id' => 0,
		);
		// Automatic, more expensive target, no consent: the old amount stays with its old flag.
		$this->assertSame( array( 'amount_minor' => 4900, 'includes_tax' => true ), PlanChange::renewal_terms_after_change( $license, 14900, false ) );
		// Cheaper target: the target price, read as the shop enters prices now (P-04).
		$this->assertSame( array( 'amount_minor' => 1900, 'includes_tax' => false ), PlanChange::renewal_terms_after_change( $license, 1900, false ) );
		// Equal price: nothing changes.
		$this->assertSame( array( 'amount_minor' => 4900, 'includes_tax' => true ), PlanChange::renewal_terms_after_change( $license, 4900, false ) );
		// Consent or manual renewal: the target price with the current flag.
		$this->assertSame( array( 'amount_minor' => 14900, 'includes_tax' => false ), PlanChange::renewal_terms_after_change( $license, 14900, true ) );
		$license['renewal_enabled'] = 0;
		$this->assertSame( array( 'amount_minor' => 14900, 'includes_tax' => false ), PlanChange::renewal_terms_after_change( $license, 14900, false ) );
		// No target price: the stored amount and flag stay.
		$this->assertSame( array( 'amount_minor' => 4900, 'includes_tax' => true ), PlanChange::renewal_terms_after_change( $license, 0, false ) );
	}

	public function test_pending_plan_price_for_manual_and_automatic_renewals(): void {
		$base = array( 'amount_minor' => 14900, 'decimals' => 2, 'currency' => 'EUR' );
		$down = array( 'amount_minor' => 4900, 'decimals' => 2, 'currency' => 'eur' );

		$this->assertSame( array( 'amount_minor' => 4900, 'decimals' => 2, 'currency' => 'EUR' ), RenewalOrder::choose_pending_price( $base, $down, true ) );
		$this->assertSame( array( 'amount_minor' => 4900, 'decimals' => 2, 'currency' => 'EUR' ), RenewalOrder::choose_pending_price( $base, $down, false ) );

		// The pending plan became more expensive than the consented amount: automatic renewals stay capped.
		$up = array( 'amount_minor' => 19900, 'decimals' => 2, 'currency' => 'EUR' );
		$this->assertSame( 14900, RenewalOrder::choose_pending_price( $base, $up, false )['amount_minor'] );
		$this->assertSame( 19900, RenewalOrder::choose_pending_price( $base, $up, true )['amount_minor'] );

		// Not billable: the renewal keeps the current plan.
		$this->assertNull( RenewalOrder::choose_pending_price( $base, null, true ) );
		$this->assertNull( RenewalOrder::choose_pending_price( $base, array( 'amount_minor' => 4900, 'decimals' => 2, 'currency' => 'USD' ), true ) );
		$this->assertNull( RenewalOrder::choose_pending_price( $base, array( 'amount_minor' => 49, 'decimals' => 0, 'currency' => 'EUR' ), true ) );
		$this->assertNull( RenewalOrder::choose_pending_price( $base, array( 'amount_minor' => 0, 'decimals' => 2, 'currency' => 'EUR' ), true ) );
	}

	public function test_site_limit_of_a_catalog_plan(): void {
		$this->assertSame( 5, PlanChange::site_limit_from_plan( array( 'entitlements_json' => '{"sites.live_limit":5}', 'live_site_limit' => 1 ) ) );
		$this->assertSame( 3, PlanChange::site_limit_from_plan( array( 'entitlements_json' => '{}', 'live_site_limit' => 3 ) ) );
		$this->assertNull( PlanChange::site_limit_from_plan( array( 'entitlements_json' => '' ) ) );
	}
}
