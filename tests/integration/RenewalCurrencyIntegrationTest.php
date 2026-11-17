<?php
/**
 * B-189: a license renews in the currency of its order, never converted.
 *
 * The shop currency is switched after the purchase, a multi-currency plugin is
 * imitated with a filter on `woocommerce_currency`, and the Stripe adapter runs
 * against the fake gateway API (tests/integration/helpers/StripeApiStub.php).
 *
 * @package WPLC
 */

use WPLC\Billing\ManualRenewal;
use WPLC\Billing\PlanUpgrade;
use WPLC\Billing\PriceChange;
use WPLC\Billing\RenewalProcessor;
use WPLC\License\PlanChange;
use WPLC\Notifications\Notifications;
use WPLC\Notifications\RenewalTerms;

require_once __DIR__ . '/helpers/RenewalTestCase.php';
require_once __DIR__ . '/helpers/StripeHelperStub.php';

final class RenewalCurrencyIntegrationTest extends WPLC_Renewal_TestCase {

	public function set_up(): void {
		parent::set_up();
		require_once __DIR__ . '/helpers/StripeApiStub.php';
		WC_Stripe_API::reset();
		update_option( 'woocommerce_currency', 'EUR' );
		add_filter(
			'wplc_stripe_mode',
			static function () {
				return 'live';
			}
		);
	}

	public function tear_down(): void {
		update_option( 'woocommerce_currency', 'EUR' );
		parent::tear_down();
	}

	/** The shop switches its currency after the purchase. */
	private function switch_shop_to_usd(): void {
		update_option( 'woocommerce_currency', 'USD' );
	}

	/** A multi-currency plugin shows the visitor another currency. */
	private function visitor_currency( string $currency ): void {
		add_filter(
			'woocommerce_currency',
			static function () use ( $currency ) {
				return $currency;
			},
			999
		);
	}

	/**
	 * A license bought with a Stripe card, due now.
	 *
	 * @return array{license_id:int,renewal_id:int,customer:int}
	 */
	private function stripe_license(): array {
		$customer = $this->customer();
		update_user_option( $customer, '_stripe_customer_id', 'cus_' . $customer );
		$token = new WC_Payment_Token_CC();
		$token->set_token( 'pm_card_1' );
		$token->set_gateway_id( 'stripe' );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2035' );
		$token->set_user_id( $customer );
		$token->save();

		$order = wc_create_order( array( 'customer_id' => $customer ) );
		$order->add_product( $this->create_license_product( array(), '49.00' ), 1 );
		$order->set_address( array( 'country' => 'DE', 'postcode' => '10115', 'city' => 'Berlin', 'email' => 'customer' . $customer . '@example.test' ), 'billing' );
		$order->set_payment_method( 'stripe' );
		$order->update_meta_data( '_wplc_recurring_consent_at', gmdate( 'Y-m-d H:i:s' ) );
		$order->update_meta_data( '_stripe_source_id', 'pm_card_1' );
		$order->update_meta_data( '_stripe_upe_payment_type', 'card' );
		$order->calculate_totals();
		$order->save();
		$order->payment_complete( 'ch_first_' . $order->get_id() );
		$rows = $this->licenses_for_order( $order->get_id() );
		$this->assertCount( 1, $rows );
		$this->assertSame( '1', (string) $rows[0]['renewal_enabled'], 'Fixture: automatic renewal was not enabled.' );
		$renewal = $this->move_due_date( (int) $rows[0]['id'], -60 );

		return array(
			'license_id' => (int) $rows[0]['id'],
			'renewal_id' => (int) $renewal['id'],
			'customer'   => $customer,
		);
	}

	private function renewal_order( int $renewal_id ): WC_Order {
		$order = wc_get_order( (int) $this->renewal( $renewal_id )['renewal_order_id'] );
		$this->assertInstanceOf( WC_Order::class, $order, 'The renewal has no order.' );

		return $order;
	}

	public function test_license_bought_in_eur_renews_manually_in_eur_after_the_shop_switched_to_usd(): void {
		$f = $this->renewing_license( 3 * DAY_IN_SECONDS );
		$this->assertSame( 'EUR', $this->license( $f['license_id'] )['renewal_currency'] );
		$this->switch_shop_to_usd();
		$this->visitor_currency( 'GBP' );

		$order = ManualRenewal::start( $f['license_id'], $f['customer'] );

		$this->assertInstanceOf( WC_Order::class, $order, is_wp_error( $order ) ? $order->get_error_code() : '' );
		$this->assertSame( 'EUR', $order->get_currency() );
		$this->assertSame( '49.00', wc_format_decimal( $order->get_total(), 2 ) );
	}

	public function test_automatic_renewal_charges_eur_through_stripe_after_the_shop_switched_to_usd(): void {
		$f = $this->stripe_license();
		$this->switch_shop_to_usd();
		$this->visitor_currency( 'GBP' );
		WC_Stripe_API::$responses[] = (object) array(
			'id'             => 'pi_eur_1',
			'object'         => 'payment_intent',
			'status'         => 'succeeded',
			'amount'         => 4900,
			'currency'       => 'eur',
			'latest_charge'  => 'ch_eur_1',
			'payment_method' => 'pm_card_1',
			'created'        => time(),
		);

		RenewalProcessor::process( $f['renewal_id'] );

		$this->assertCount( 1, WC_Stripe_API::$requests );
		$this->assertSame( 'eur', WC_Stripe_API::$requests[0]['request']['currency'] );
		$this->assertSame( 4900, WC_Stripe_API::$requests[0]['request']['amount'] );
		$this->assertSame( 'EUR', $this->renewal_order( $f['renewal_id'] )->get_currency() );
		$this->assertSame( 'paid', $this->renewal( $f['renewal_id'] )['status'] );
	}

	public function test_automatic_renewal_with_the_test_adapter_keeps_eur(): void {
		$f = $this->renewing_license();
		$this->switch_shop_to_usd();

		RenewalProcessor::process( $f['renewal_id'] );

		$this->assertSame( 1, $this->charges() );
		$this->assertSame( 'EUR', $this->renewal_order( $f['renewal_id'] )->get_currency() );
		$this->assertSame( 'paid', $this->renewal( $f['renewal_id'] )['status'] );
	}

	public function test_order_in_another_currency_stores_that_currency_and_the_paid_unit_price(): void {
		$customer = $this->customer();
		$this->token( $customer );
		$product = $this->create_license_product( array(), '49.00' );
		$order   = wc_create_order( array( 'customer_id' => $customer ) );
		$order->set_currency( 'USD' );
		$order->add_product( $product, 2, array( 'subtotal' => '104.00', 'total' => '94.00' ) );
		$order->set_address( array( 'country' => 'DE', 'postcode' => '10115', 'city' => 'Berlin', 'email' => 'c' . $customer . '@example.test' ), 'billing' );
		$order->set_payment_method( WPLC_Test_Recurring_Adapter::ID );
		$order->update_meta_data( '_wplc_recurring_consent_at', gmdate( 'c' ) );
		$order->calculate_totals( false );
		$order->save();
		$order->payment_complete( 'first_usd' );

		$licenses = $this->licenses_for_order( $order->get_id() );
		$this->assertCount( 2, $licenses );
		foreach ( $licenses as $license ) {
			$this->assertSame( 'USD', $license['renewal_currency'], 'The license renews in the order currency.' );
			$this->assertSame( '5200', (string) $license['renewal_amount_minor'], 'Unit price before coupons, not the EUR catalog price.' );
			$this->assertSame( '0', (string) $license['renewal_amount_includes_tax'], 'Line subtotals are net.' );
		}

		$renewal = $this->move_due_date( (int) $licenses[0]['id'], -60 );
		RenewalProcessor::process( (int) $renewal['id'] );

		$renewal_order = $this->renewal_order( (int) $renewal['id'] );
		$this->assertSame( 'USD', $renewal_order->get_currency() );
		$this->assertSame( '52.00', wc_format_decimal( $renewal_order->get_total(), 2 ) );
		$this->assertSame( 'paid', $this->renewal( (int) $renewal['id'] )['status'] );
	}

	public function test_an_integration_can_supply_the_regular_price_in_the_order_currency(): void {
		add_filter(
			'wplc_renewal_price_in_order_currency',
			static function ( $price ) {
				return array( 'amount_minor' => 5500, 'decimals' => 2, 'currency' => 'USD', 'includes_tax' => false );
			}
		);
		$customer = $this->customer();
		$order    = wc_create_order( array( 'customer_id' => $customer ) );
		$order->set_currency( 'USD' );
		$order->add_product( $this->create_license_product( array(), '49.00' ), 1, array( 'subtotal' => '45.00', 'total' => '45.00' ) );
		$order->calculate_totals( false );
		$order->save();
		$order->payment_complete( 'first_usd_sale' );

		$license = $this->licenses_for_order( $order->get_id() )[0];
		$this->assertSame( 'USD', $license['renewal_currency'] );
		$this->assertSame( '5500', (string) $license['renewal_amount_minor'] );
	}

	public function test_order_currency_changed_by_another_plugin_is_never_charged(): void {
		$f = $this->renewing_license();
		add_action(
			'woocommerce_before_order_object_save',
			static function ( $order ) {
				if ( $order->get_meta( '_wplc_renewal_id' ) ) {
					$order->set_currency( 'GBP' );
				}
			}
		);

		RenewalProcessor::process( $f['renewal_id'] );

		$this->assertSame( 0, $this->charges() );
		$renewal = $this->renewal( $f['renewal_id'] );
		$this->assertSame( 'scheduled', $renewal['status'] );
		$this->assertSame( '0', (string) $renewal['attempt_count'], 'Not a failed payment.' );
		$this->assertSame( 'renewal_currency_mismatch', $renewal['last_error'] );
		$this->assertSame( 'active', $this->license( $f['license_id'] )['status'] );
		$this->assertContains( 'renewal_blocked', $this->audit_actions( 'renewal', $f['renewal_id'] ) );
	}

	public function test_gateway_without_the_license_currency_refuses_with_a_reason(): void {
		$f = $this->renewing_license();
		$blocked = array();
		add_action(
			'wplc/renewal_blocked',
			static function ( $renewal_id, $code ) use ( &$blocked ) {
				$blocked[] = $code;
			},
			10,
			2
		);
		add_filter(
			'wplc_renewal_currency_supported',
			static function ( $supported, $currency, $gateway ) {
				return 'EUR' === $currency && WPLC_Test_Recurring_Adapter::ID === $gateway ? false : $supported;
			},
			10,
			3
		);

		RenewalProcessor::process( $f['renewal_id'] );

		$this->assertSame( 0, $this->charges(), 'Never charged in another currency.' );
		$renewal = $this->renewal( $f['renewal_id'] );
		$this->assertSame( 'renewal_currency_unsupported', $renewal['last_error'] );
		$this->assertSame( '0', (string) $renewal['attempt_count'] );
		$this->assertSame( array( 'renewal_currency_unsupported' ), $blocked );
		$notes = wp_list_pluck( wc_get_order_notes( array( 'order_id' => (int) $renewal['renewal_order_id'] ) ), 'content' );
		$this->assertNotEmpty( preg_grep( '/does not accept the currency/', $notes ) );
	}

	public function test_plan_upgrade_is_billed_in_the_license_currency(): void {
		$customer = self::factory()->user->create();
		$solo     = $this->create_license_product( array( 'plan_key' => 'solo' ), '49.00' );
		$business = $this->create_license_product( array( 'plan_key' => 'business', 'live_site_limit' => 5 ), '149.00' );
		$order    = $this->create_paid_order( array( array( $solo, 1 ) ), $customer );
		$license  = $this->licenses_for_order( $order->get_id() )[0];
		$this->visitor_currency( 'GBP' );

		$upgrade = PlanUpgrade::start( (int) $license['id'], $customer, $business->get_id(), false );

		$this->assertInstanceOf( WC_Order::class, $upgrade, is_wp_error( $upgrade ) ? $upgrade->get_error_code() : '' );
		$this->assertSame( 'EUR', $upgrade->get_currency() );

		// After a switch of the shop currency the plans are priced in USD: no upgrade is offered, nothing is converted.
		$this->switch_shop_to_usd();
		$this->assertSame( array(), PlanChange::targets( $this->license( (int) $license['id'] ) ) );
		$this->assertTrue( is_wp_error( PlanUpgrade::start( (int) $license['id'], $customer, $business->get_id(), false ) ) );
	}

	public function test_reminder_and_price_change_emails_name_the_license_currency(): void {
		$f = $this->renewing_license( 5 * DAY_IN_SECONDS );
		$this->switch_shop_to_usd();
		$this->visitor_currency( 'GBP' );
		$license = $this->license( $f['license_id'] );

		$this->assertSame( 'EUR', RenewalTerms::gross( $license )['currency'] );
		$view = Notifications::view( $license );
		$this->assertSame( 'EUR', $view['currency'] );
		$this->assertStringContainsString( '€', html_entity_decode( $view['amount'] ) );
		$this->assertStringNotContainsString( '$', $view['amount'] );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->assertSame( PriceChange::RESULT_OK, PriceChange::announce( $f['license_id'], 5900, time() + 70 * DAY_IN_SECONDS, 'Price list', $admin ) );
		$license = $this->license( $f['license_id'] );
		$view    = Notifications::view( $license, array( 'price_change' => PriceChange::pending( $license ) ) );
		$this->assertStringContainsString( '€', html_entity_decode( $view['new_amount'] ) );
		$this->assertStringContainsString( '59', $view['new_amount'] );
	}
}
