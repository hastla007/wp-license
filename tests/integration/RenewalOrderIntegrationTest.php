<?php
/**
 * The WooCommerce order of a renewal: price, tax, addresses, order data.
 *
 * @package WPLC
 */

use WPLC\Billing\ManualRenewal;
use WPLC\Billing\RenewalOrder;
use WPLC\Billing\RenewalProcessor;
use WPLC\Catalog\Catalog;

require_once __DIR__ . '/helpers/RenewalTestCase.php';

final class RenewalOrderIntegrationTest extends WPLC_Renewal_TestCase {

	private function automatic_order( array $f ): WC_Order {
		$order = RenewalOrder::get_or_create( $this->renewal( $f['renewal_id'] ), $this->license( $f['license_id'] ), false );
		$this->assertInstanceOf( WC_Order::class, $order, is_wp_error( $order ) ? $order->get_error_code() : '' );

		return wc_get_order( $order->get_id() );
	}

	/** A-03, INH-01: the manual renewal bills the amount agreed at purchase, with the license's own plan, not the plan saved last. */
	public function test_a03_manual_renewal_uses_the_plan_of_the_purchased_product(): void {
		$yearly  = $this->create_license_product( array( 'billing_interval' => 'year' ), '100.00' );
		$monthly = $this->create_license_product( array( 'billing_interval' => 'month' ), '10.00' );
		$this->assertNotSame( $yearly->get_id(), $monthly->get_id() );
		$customer = $this->customer();
		$license  = $this->buy( $yearly, $customer, false );
		// The yearly price was raised in the catalog; the monthly plan stays the one saved last.
		$yearly->set_regular_price( '120.00' );
		$yearly->save();
		$this->assertTrue( Catalog::save_mapping( $yearly->get_id(), 0, $this->mapping_data( array( 'billing_interval' => 'year' ) ) ) );
		$this->assertTrue( Catalog::save_mapping( $monthly->get_id(), 0, $this->mapping_data( array( 'billing_interval' => 'month' ) ) ) );

		$plan  = RenewalOrder::plan_for_license( $license );
		$price = RenewalOrder::price( $license, true );

		$this->assertSame( $yearly->get_id(), (int) $plan['woocommerce_product_id'] );
		$this->assertSame( 10000, $price['amount_minor'], 'The price of the purchase day, neither the raised yearly price nor the monthly price.' );
		$this->assertSame( RenewalOrder::price( $license, false )['amount_minor'], $price['amount_minor'], 'Manual and automatic renewal cost the same.' );
		// A license without a stored amount is priced from the catalog: the product it was bought as.
		$this->assertSame( 12000, RenewalOrder::price( array_merge( $license, array( 'renewal_amount_minor' => 0 ) ), true )['amount_minor'] );

		$monthly_license = $this->buy( $monthly, $this->customer(), false );
		$this->assertSame( 1000, RenewalOrder::price( $monthly_license, true )['amount_minor'] );
	}

	/** A-03, INH-01: the agreed amount is billed; without one and without a source order the plan is matched by interval. */
	public function test_a03_license_without_source_order_is_matched_by_interval(): void {
		$this->create_license_product( array( 'billing_interval' => 'year' ), '100.00' );
		$this->create_license_product( array( 'billing_interval' => 'month' ), '10.00' );
		$license = $this->license(
			$this->seed_license(
				array(
					'billing_interval'     => 'year',
					'renewal_amount_minor' => 9000,
				)
			)['id']
		);

		$this->assertSame( 9000, RenewalOrder::price( $license, true )['amount_minor'] );
		$unpriced = array_merge( $license, array( 'renewal_amount_minor' => 0 ) );
		$this->assertSame( 10000, RenewalOrder::price( $unpriced, true )['amount_minor'], 'Without an agreed amount: the yearly plan, not the monthly one.' );

		// Two yearly plans with different prices: no guess, the amount agreed at purchase is used.
		$this->create_license_product( array( 'billing_interval' => 'year' ), '70.00' );
		$plan = RenewalOrder::plan_for_license( $license );
		$this->assertTrue( ! empty( $plan['_ambiguous'] ) );
		$this->assertSame( 9000, RenewalOrder::price( $license, true )['amount_minor'] );
		$this->assertSame( 0, RenewalOrder::price( $unpriced, true )['amount_minor'], 'An ambiguous plan never sets a price.' );
	}

	/** T-132: a variation bought under a plan mapped on the parent renews at the variation's price. */
	public function test_t132_variation_under_a_parent_mapping_renews_at_its_own_price(): void {
		list( $parent, $variations ) = $this->create_variable_product( array( '59.00', '79.00' ) );
		// The parent-level plan stores the lowest variation price (what the product editor saves).
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert(
			$this->tables()['plans'],
			array(
				'product_key'              => 'test-product',
				'plan_key'                 => 'single',
				'woocommerce_product_id'   => $parent->get_id(),
				'woocommerce_variation_id' => 0,
				'billing_interval'         => 'year',
				'price_minor'              => 5900,
				'entitlements_json'        => '{"sites.live_limit":1}',
				'active'                   => 1,
				'created_at'               => $now,
				'updated_at'               => $now,
			)
		);
		$license = $this->buy( wc_get_product( $variations[1]->get_id() ), $this->customer(), false );

		$this->assertSame( 7900, RenewalOrder::price( $license, true )['amount_minor'] );
	}

	/** A-04: the VAT exemption and VAT ID of the first order are carried to the renewal order. */
	public function test_a04_reverse_charge_customer_is_not_charged_vat_on_renewal(): void {
		$this->set_tax( false, array( 'DE' => '19', 'AT' => '20' ) );
		$f      = $this->renewing_license( -60, '49.00', 'AT' );
		$source = wc_get_order( (int) $this->license( $f['license_id'] )['source_order_id'] );
		$source->update_meta_data( 'is_vat_exempt', 'yes' );
		$source->update_meta_data( '_billing_vat_number', 'ATU12345678' );
		$source->save();

		$order = $this->automatic_order( $f );

		$this->assertSame( 'yes', $order->get_meta( 'is_vat_exempt', true ) );
		$this->assertSame( 'ATU12345678', $order->get_meta( '_billing_vat_number', true ) );
		$this->assertSame( '0.00', wc_format_decimal( $order->get_total_tax(), 2 ) );
		$this->assertSame( '49.00', wc_format_decimal( $order->get_total(), 2 ) );
	}

	/** A-04: without the exemption the same customer pays the tax of the billing country. */
	public function test_a04_customer_without_exemption_pays_vat(): void {
		$this->set_tax( false, array( 'DE' => '19', 'AT' => '20' ) );
		$f = $this->renewing_license( -60, '49.00', 'AT' );

		$order = $this->automatic_order( $f );

		$this->assertSame( '58.80', wc_format_decimal( $order->get_total(), 2 ) );
	}

	/** B-77: the renewal is taxed by the billing address, never by a saved shipping address. */
	public function test_b77_saved_shipping_address_does_not_change_the_tax(): void {
		$this->set_tax( false, array( 'DE' => '19', 'HU' => '27' ), 'shipping' );
		$f = $this->renewing_license();
		update_user_meta( $f['customer'], 'shipping_country', 'HU' );
		update_user_meta( $f['customer'], 'shipping_postcode', '1011' );
		update_user_meta( $f['customer'], 'shipping_city', 'Budapest' );

		$order = $this->automatic_order( $f );

		$this->assertSame( '', $order->get_shipping_country() );
		$this->assertSame( 'DE', $order->get_billing_country() );
		$this->assertSame( '58.31', wc_format_decimal( $order->get_total(), 2 ), '49.00 + 19 % (DE), not 27 % (HU).' );
	}

	/** B-76: an amount stored as gross stays the charged total after the shop switched to net prices. */
	public function test_b76_gross_amount_survives_a_switch_of_the_price_setting(): void {
		global $wpdb;
		$this->set_tax( false );
		$f = $this->renewing_license( -60, '119.00' );
		$wpdb->update( $this->tables()['licenses'], array( 'renewal_amount_includes_tax' => 1 ), array( 'id' => $f['license_id'] ) );

		$order = $this->automatic_order( $f );

		$this->assertSame( '119.00', wc_format_decimal( $order->get_total(), 2 ) );
		$this->assertSame( '10000', $order->get_meta( RenewalOrder::META_NET_MINOR, true ) );
	}

	/** B-76: an amount stored as net stays net after the shop switched to gross prices. */
	public function test_b76_net_amount_survives_a_switch_of_the_price_setting(): void {
		global $wpdb;
		$this->set_tax( true );
		$f = $this->renewing_license( -60, '100.00' );
		$wpdb->update( $this->tables()['licenses'], array( 'renewal_amount_includes_tax' => 0 ), array( 'id' => $f['license_id'] ) );

		$order = $this->automatic_order( $f );

		$this->assertSame( '119.00', wc_format_decimal( $order->get_total(), 2 ) );
	}

	/** B-76: without the flag the amount is read by the current setting (unchanged behavior). */
	public function test_b76_unknown_flag_keeps_the_current_interpretation(): void {
		$this->set_tax( true );
		$f = $this->renewing_license( -60, '119.00' );
		// A license issued before the flag existed (new licenses get it from the issuer).
		$GLOBALS['wpdb']->query( $GLOBALS['wpdb']->prepare( 'UPDATE %i SET renewal_amount_includes_tax = NULL WHERE id = %d', $this->tables()['licenses'], $f['license_id'] ) );
		$this->assertNull( $this->license( $f['license_id'] )['renewal_amount_includes_tax'] );

		$order = $this->automatic_order( $f );

		$this->assertSame( '119.00', wc_format_decimal( $order->get_total(), 2 ) );
	}

	/** B-42 / B-186 / B-187: the renewal order says what it is and behaves like a service order. */
	public function test_b42_b186_b187_renewal_order_data(): void {
		$f     = $this->renewing_license();
		$order = $this->automatic_order( $f );
		$item  = $this->first_item( $order );

		$this->assertSame( RenewalOrder::CREATED_VIA, $order->get_created_via() );
		$this->assertSame( WPLC_Test_Recurring_Adapter::ID, $order->get_payment_method() );
		$this->assertStringContainsString( 'license renewal', $item->get_name() );
		$this->assertStringContainsString( '#' . $f['license_id'], (string) $item->get_meta( 'License', true ) );
		$this->assertSame(
			substr( $f['paid_until'], 0, 10 ) . ' – ' . substr( $f['period_end'], 0, 10 ),
			$item->get_meta( 'Service period (UTC)', true )
		);
		$this->assertTrue( RenewalOrder::net_matches( $order ) );

		$this->assertFalse( apply_filters( 'woocommerce_can_reduce_order_stock', true, $order ) );
		$this->assertTrue( apply_filters( 'woocommerce_pay_order_product_in_stock', false, $item->get_product(), $order ) );
		$this->assertTrue( (bool) $order->get_data_store()->get_download_permissions_granted( $order ) );

		// Other orders are not affected.
		$other = $this->create_order( array( array( $this->create_simple_product(), 1 ) ) );
		$this->assertTrue( apply_filters( 'woocommerce_can_reduce_order_stock', true, $other ) );
		$this->assertFalse( apply_filters( 'woocommerce_pay_order_product_in_stock', false, $item->get_product(), $other ) );
	}

	/** B-182: an amount an administrator changed on the order is accepted when the order is paid. */
	public function test_b182_admin_discount_on_the_renewal_order_is_booked(): void {
		$f = $this->renewing_license();
		WPLC_Test_Recurring_Adapter::$mode = 'decline';
		RenewalProcessor::process( $f['renewal_id'] );
		$order = wc_get_order( (int) $this->renewal( $f['renewal_id'] )['renewal_order_id'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		$item = $this->first_item( $order );
		$item->set_subtotal( '30.00' );
		$item->set_total( '30.00' );
		$item->save();
		$order->calculate_totals();
		do_action( 'woocommerce_saved_order_items', $order->get_id(), array() );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( '3000', $order->get_meta( RenewalOrder::META_NET_MINOR, true ) );
		$this->assertContains( 'renewal_amount_overridden', $this->audit_actions( 'renewal', $f['renewal_id'] ) );

		wp_set_current_user( 0 );
		$order->payment_complete( 'customer_tx' );
		RenewalProcessor::reconcile_paid_renewal_order( (int) $order->get_id() );

		$this->assertSame( 'paid', $this->renewal( $f['renewal_id'] )['status'] );
		$this->assertSame( $f['period_end'], $this->license( $f['license_id'] )['paid_until'] );
	}

	/** B-182: a changed amount nobody authorised is not booked, and the order says so. */
	public function test_b182_unexplained_amount_is_flagged_on_the_order(): void {
		$f = $this->renewing_license();
		WPLC_Test_Recurring_Adapter::$mode = 'decline';
		RenewalProcessor::process( $f['renewal_id'] );
		$order = wc_get_order( (int) $this->renewal( $f['renewal_id'] )['renewal_order_id'] );

		$item = $this->first_item( $order );
		$item->set_subtotal( '1.00' );
		$item->set_total( '1.00' );
		$item->save();
		$order->calculate_totals();
		// A customer (or nobody) cannot authorise the change.
		wp_set_current_user( $f['customer'] );
		do_action( 'woocommerce_saved_order_items', $order->get_id(), array() );
		$order = wc_get_order( $order->get_id() );
		$order->payment_complete( 'customer_tx' );
		RenewalProcessor::reconcile_paid_renewal_order( (int) $order->get_id() );

		$this->assertSame( $f['paid_until'], $this->license( $f['license_id'] )['paid_until'] );
		$this->assertSame( 'yes', wc_get_order( $order->get_id() )->get_meta( RenewalOrder::META_REVIEW, true ) );
		$this->assertContains( 'renewal_recovery_amount_mismatch', $this->audit_actions( 'renewal', $f['renewal_id'] ) );
	}

	/** B-182: the automatic path checks the amount too and does not charge an unexplained total. */
	public function test_b182_automatic_charge_refuses_an_unexplained_amount(): void {
		global $wpdb;
		$f     = $this->renewing_license();
		$order = $this->automatic_order( $f );
		$item  = $this->first_item( $order );
		$item->set_subtotal( '1.00' );
		$item->set_total( '1.00' );
		$item->save();
		$order->calculate_totals();

		RenewalProcessor::process( $f['renewal_id'] );

		$this->assertSame( 0, $this->charges() );
		$this->assertSame( 'renewal_amount_mismatch', $this->renewal( $f['renewal_id'] )['last_error'] );
		$this->assertSame( 'active', $this->license( $f['license_id'] )['status'] );
	}

	/** P-46: a total that no longer matches keeps one order, priced again on every attempt, never charged. */
	public function test_p46_amount_mismatch_reuses_one_order_and_never_charges_it(): void {
		global $wpdb;
		$this->set_tax( true );
		$f = $this->renewing_license( -60, '119.00' );
		$wpdb->update( $this->tables()['licenses'], array( 'renewal_amount_includes_tax' => 1 ), array( 'id' => $f['license_id'] ) );
		// The tax rate changed: the order no longer adds up to the agreed gross amount.
		$shift = static function ( $total ) {
			return (string) ( (float) $total + 5 );
		};
		add_filter( 'woocommerce_order_get_total', $shift );

		$order_ids = array();
		for ( $run = 0; $run < 3; ++$run ) {
			$wpdb->update( $this->tables()['renewals'], array( 'retry_at' => null ), array( 'id' => $f['renewal_id'] ) );
			RenewalProcessor::process( $f['renewal_id'] );
			$order_ids[] = (int) $this->renewal( $f['renewal_id'] )['renewal_order_id'];
		}
		remove_filter( 'woocommerce_order_get_total', $shift );

		$this->assertSame( 0, $this->charges() );
		$this->assertSame( 'renewal_amount_mismatch', $this->renewal( $f['renewal_id'] )['last_error'] );
		$this->assertCount( 1, array_unique( $order_ids ), 'One order per renewal, not one per attempt.' );
		$order = wc_get_order( $order_ids[0] );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertSame( 'yes', $order->get_meta( RenewalOrder::META_AMOUNT_MISMATCH, true ) );

		// Once the total matches again the same order is charged.
		$wpdb->update( $this->tables()['renewals'], array( 'retry_at' => null ), array( 'id' => $f['renewal_id'] ) );
		RenewalProcessor::process( $f['renewal_id'] );
		$this->assertSame( 1, $this->charges() );
		$this->assertSame( $order_ids[0], (int) $this->renewal( $f['renewal_id'] )['renewal_order_id'] );
		$this->assertSame( '', wc_get_order( $order_ids[0] )->get_meta( RenewalOrder::META_AMOUNT_MISMATCH, true ) );
		$this->assertSame( 'paid', $this->renewal( $f['renewal_id'] )['status'] );
	}

	/** P-60: an administrator may lower the amount of an automatic renewal order, never raise it. */
	public function test_p60_higher_amount_on_an_automatic_order_is_not_accepted(): void {
		$f     = $this->renewing_license();
		$order = $this->automatic_order( $f );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		$item = $this->first_item( $order );
		$item->set_subtotal( '490.00' );
		$item->set_total( '490.00' );
		$item->save();
		$order->calculate_totals();
		do_action( 'woocommerce_saved_order_items', $order->get_id(), array() );
		wp_set_current_user( 0 );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( '4900', $order->get_meta( RenewalOrder::META_NET_MINOR, true ), 'The agreed amount stays.' );
		$this->assertContains( 'renewal_amount_override_refused', $this->audit_actions( 'renewal', $f['renewal_id'] ) );
		$notes = implode( "\n", wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' ) );
		$this->assertStringContainsString( 'can only be lowered', $notes );

		RenewalProcessor::process( $f['renewal_id'] );
		$this->assertSame( 0, $this->charges(), 'The higher amount is never charged off-session.' );
	}

	/** P-60: no amount change is accepted while the payment result of the order is being checked. */
	public function test_p60_amount_cannot_change_while_the_result_is_unknown(): void {
		global $wpdb;
		$f     = $this->renewing_license();
		$order = $this->automatic_order( $f );
		$wpdb->update( $this->tables()['renewals'], array( 'status' => RenewalProcessor::STATUS_RECONCILING ), array( 'id' => $f['renewal_id'] ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		$item = $this->first_item( $order );
		$item->set_subtotal( '30.00' );
		$item->set_total( '30.00' );
		$item->save();
		$order->calculate_totals();
		do_action( 'woocommerce_saved_order_items', $order->get_id(), array() );
		wp_set_current_user( 0 );

		$this->assertSame( '4900', wc_get_order( $order->get_id() )->get_meta( RenewalOrder::META_NET_MINOR, true ) );
	}

	/** P-60: an order set to Processing by hand, never charged and without a transaction ID, is reviewed, not booked. */
	public function test_p60_order_marked_paid_by_hand_goes_to_review(): void {
		$f     = $this->renewing_license();
		$order = $this->automatic_order( $f );
		$admin = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		wp_set_current_user( $admin );
		wc_get_order( $order->get_id() )->update_status( 'processing', 'Bulk action' );
		wp_set_current_user( 0 );

		RenewalProcessor::reconcile_paid_renewal_order( (int) $order->get_id() );

		$renewal = $this->renewal( $f['renewal_id'] );
		$this->assertSame( RenewalProcessor::STATUS_REVIEW, $renewal['status'] );
		$this->assertSame( \WPLC\License\AuditReason::MARKED_PAID_WITHOUT_PAYMENT, $renewal['review_reason'] );
		$this->assertSame( $f['paid_until'], $this->license( $f['license_id'] )['paid_until'], 'No renewal without a payment.' );
		$this->assertNotContains( 'renewal_customer_recovered', $this->audit_actions( 'renewal', $f['renewal_id'] ) );
		// A repeated hook does not book it either; the administrator decides on the review screen.
		RenewalProcessor::reconcile_paid_renewal_order( (int) $order->get_id() );
		$this->assertSame( $f['paid_until'], $this->license( $f['license_id'] )['paid_until'] );
		$this->assertSame( 'review_booked', \WPLC\Billing\RenewalReview::book( $f['renewal_id'], 'Bank transfer received', $admin ) );
		$this->assertSame( $f['period_end'], $this->license( $f['license_id'] )['paid_until'] );
	}

	/** B-66: a manual renewal without a billing country is not created with wrong tax. */
	public function test_b66_manual_renewal_needs_a_billing_country_when_taxes_are_on(): void {
		$this->set_tax( false );
		$this->create_license_product();
		$customer = self::factory()->user->create( array( 'role' => 'customer' ) );
		$license  = $this->seed_license(
			array(
				'customer_id'          => $customer,
				'paid_until'           => gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS ),
				'renewal_amount_minor' => 4900,
			)
		)['id'];

		$result = ManualRenewal::start( $license, $customer );
		$this->assertWPError( $result );
		$this->assertSame( 'manual_renewal_address_missing', $result->get_error_code() );

		update_user_meta( $customer, 'billing_country', 'DE' );
		$order = ManualRenewal::start( $license, $customer );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( '58.31', wc_format_decimal( $order->get_total(), 2 ) );
	}
}
