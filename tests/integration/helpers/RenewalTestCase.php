<?php
/**
 * Shared fixtures for the renewal integration tests: a recurring gateway
 * adapter whose result the test chooses, customers with a saved card, and
 * licenses bought with automatic renewal.
 *
 * @package WPLC
 */

use WPLC\Billing\RenewalScheduler;

if ( ! class_exists( 'WPLC_Test_Recurring_Adapter' ) ) {
	/**
	 * Gateway adapter under test control.
	 */
	final class WPLC_Test_Recurring_Adapter implements \WPLC\Gateway\RecurringGatewayAdapter {

		public const ID = 'wplctest';

		/** @var string success|decline|hard|manual|unknown */
		public static $mode = 'success';

		/** @var array<int,array<string,mixed>> */
		public static $charges = array();

		/** @var callable|null Called with the order while the charge is "in flight". */
		public static $during_charge = null;

		public function gateway_id(): string {
			return self::ID;
		}

		public function supports_checkout(): bool {
			return true;
		}

		public function supports_saved_token( int $token_id, int $customer_id ): bool {
			$token = WC_Payment_Tokens::get( $token_id );
			return $token && self::ID === $token->get_gateway_id() && (int) $token->get_user_id() === $customer_id;
		}

		public function charge( $order, int $token_id, string $idempotency_key ) {
			self::$charges[] = array(
				'order' => (int) $order->get_id(),
				'total' => (string) $order->get_total(),
				'key'   => $idempotency_key,
			);
			if ( self::$during_charge ) {
				call_user_func( self::$during_charge, $order );
			}
			switch ( self::$mode ) {
				case 'decline':
					return new WP_Error( 'card_declined', 'declined' );
				case 'hard':
					return new WP_Error( 'card_declined', 'stolen card', array( 'retryable' => false ) );
				case 'manual':
					return new WP_Error( 'renewal_manual_action_required', 'authentication required' );
				case 'unknown':
					return new WP_Error( 'renewal_result_unknown', 'timeout' );
			}
			return array(
				'success'        => true,
				'transaction_id' => 'test_tx_' . count( self::$charges ),
			);
		}
	}
}

/**
 * Base class of the renewal integration tests.
 */
abstract class WPLC_Renewal_TestCase extends WPLC_Integration_TestCase {

	public function set_up(): void {
		parent::set_up();
		WPLC_Test_Recurring_Adapter::$mode          = 'success';
		WPLC_Test_Recurring_Adapter::$charges       = array();
		WPLC_Test_Recurring_Adapter::$during_charge = null;
		add_filter(
			'wplc_recurring_gateway_adapters',
			static function ( $adapters ) {
				$adapters[] = new WPLC_Test_Recurring_Adapter();
				return $adapters;
			}
		);
		add_filter(
			'wplc_payment_token_id_for_order',
			static function ( $token_id, $order ) {
				if ( $token_id || WPLC_Test_Recurring_Adapter::ID !== $order->get_payment_method() ) {
					return $token_id;
				}
				foreach ( WC_Payment_Tokens::get_customer_tokens( (int) $order->get_customer_id(), WPLC_Test_Recurring_Adapter::ID ) as $token ) {
					return (int) $token->get_id();
				}
				return $token_id;
			},
			10,
			2
		);
		// No mail leaves the test.
		add_filter( 'pre_wp_mail', '__return_true' );
	}

	protected function charges(): int {
		return count( WPLC_Test_Recurring_Adapter::$charges );
	}

	protected function customer( string $country = 'DE' ): int {
		$id = self::factory()->user->create( array( 'role' => 'customer' ) );
		update_user_meta( $id, 'billing_country', $country );
		update_user_meta( $id, 'billing_postcode', '10115' );
		update_user_meta( $id, 'billing_city', 'Berlin' );
		update_user_meta( $id, 'billing_email', 'customer' . $id . '@example.test' );

		return $id;
	}

	protected function token( int $customer ): int {
		$token = new WC_Payment_Token_CC();
		$token->set_token( 'tok_' . wp_generate_password( 8, false ) );
		$token->set_gateway_id( WPLC_Test_Recurring_Adapter::ID );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2035' );
		$token->set_user_id( $customer );
		$token->save();

		return (int) $token->get_id();
	}

	/**
	 * Buy a license product. With $auto the order carries the consent and a saved card.
	 *
	 * @return array<string,mixed> License row.
	 */
	protected function buy( WC_Product $product, int $customer, bool $auto = true ): array {
		if ( $auto ) {
			$this->token( $customer );
		}
		$order = wc_create_order( array( 'customer_id' => $customer ) );
		$order->add_product( $product, 1 );
		$order->set_address(
			array(
				'country'  => (string) get_user_meta( $customer, 'billing_country', true ),
				'postcode' => '10115',
				'city'     => 'Berlin',
				'email'    => 'customer' . $customer . '@example.test',
			),
			'billing'
		);
		$order->set_payment_method( $auto ? WPLC_Test_Recurring_Adapter::ID : 'bacs' );
		if ( $auto ) {
			$order->update_meta_data( '_wplc_recurring_consent_at', gmdate( 'c' ) );
		}
		$order->calculate_totals();
		$order->save();
		$order->payment_complete( 'first_' . $order->get_id() );
		$rows = $this->licenses_for_order( $order->get_id() );
		$this->assertCount( 1, $rows, 'Fixture: no license was issued.' );

		return $rows[0];
	}

	/**
	 * A renewing license whose period ends $offset seconds from now, with its renewal row.
	 *
	 * @return array{license_id:int,renewal_id:int,customer:int,paid_until:string,period_end:string}
	 */
	protected function renewing_license( int $offset = -60, string $price = '49.00', string $country = 'DE' ): array {
		$customer = $this->customer( $country );
		$license  = $this->buy( $this->create_license_product( array(), $price ), $customer, true );
		$this->assertSame( '1', (string) $license['renewal_enabled'], 'Fixture: automatic renewal was not enabled.' );
		$renewal = $this->move_due_date( (int) $license['id'], $offset );

		return array(
			'license_id' => (int) $license['id'],
			'renewal_id' => (int) $renewal['id'],
			'customer'   => $customer,
			'paid_until' => (string) $renewal['period_start'],
			'period_end' => (string) $renewal['period_end'],
		);
	}

	/**
	 * Move paid_until to now + $offset and create the renewal row of that period.
	 *
	 * @return array<string,mixed> Renewal row (empty when the license does not renew automatically).
	 */
	protected function move_due_date( int $license_id, int $offset ): array {
		global $wpdb;
		$tables     = $this->tables();
		$paid_until = gmdate( 'Y-m-d H:i:s', time() + $offset );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$tables['renewals']} WHERE license_id = %d", $license_id ) );
		$wpdb->update(
			$tables['licenses'],
			array(
				'paid_until'      => $paid_until,
				'next_payment_at' => $paid_until,
			),
			array( 'id' => $license_id )
		);
		$wpdb->update( $tables['entitlements'], array( 'ends_at' => $paid_until ), array( 'license_id' => $license_id ) );
		$renewal_id = RenewalScheduler::schedule_next( $license_id );

		return $renewal_id ? $this->renewal( $renewal_id ) : array();
	}

	/**
	 * @return array<string,mixed>
	 */
	protected function renewal( int $renewal_id ): array {
		global $wpdb;
		$tables = $this->tables();
		$row    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['renewals']} WHERE id = %d", $renewal_id ), ARRAY_A );
		$this->assertIsArray( $row, 'Renewal ' . $renewal_id . ' does not exist.' );

		return $row;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	protected function renewals_of( int $license_id ): array {
		global $wpdb;
		$tables = $this->tables();

		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tables['renewals']} WHERE license_id = %d ORDER BY id ASC", $license_id ), ARRAY_A );
	}

	/**
	 * @return string[] Audit actions recorded for an object, oldest first.
	 */
	protected function audit_actions( string $type, int $id ): array {
		global $wpdb;
		$tables = $this->tables();

		return (array) $wpdb->get_col( $wpdb->prepare( "SELECT action FROM {$tables['audit_log']} WHERE object_type = %s AND object_id = %d ORDER BY id ASC", $type, $id ) );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	protected function audit_rows( string $type, int $id ): array {
		global $wpdb;
		$tables = $this->tables();

		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tables['audit_log']} WHERE object_type = %s AND object_id = %d ORDER BY id ASC", $type, $id ), ARRAY_A );
	}

	/**
	 * @return string[] Distinct "status@ends_at" of the license's entitlements.
	 */
	protected function entitlement_states( int $license_id ): array {
		global $wpdb;
		$tables = $this->tables();

		return (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT CONCAT(status, '@', IFNULL(ends_at, 'NULL')) FROM {$tables['entitlements']} WHERE license_id = %d", $license_id ) );
	}

	/**
	 * One tax rate per country; $inclusive = shop enters prices including tax.
	 *
	 * @param array<string,string> $rates Country => rate.
	 */
	protected function set_tax( bool $inclusive, array $rates = array( 'DE' => '19' ), string $based_on = 'billing' ): void {
		global $wpdb;
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_prices_include_tax', $inclusive ? 'yes' : 'no' );
		update_option( 'woocommerce_default_country', 'DE' );
		update_option( 'woocommerce_tax_based_on', $based_on );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_tax_rates" );
		foreach ( $rates as $country => $rate ) {
			WC_Tax::_insert_tax_rate(
				array(
					'tax_rate_country'  => $country,
					'tax_rate'          => (string) $rate,
					'tax_rate_name'     => 'VAT',
					'tax_rate_priority' => 1,
					'tax_rate_compound' => 0,
					'tax_rate_shipping' => 1,
					'tax_rate_order'    => 1,
					'tax_rate_class'    => '',
				)
			);
		}
		WC_Cache_Helper::invalidate_cache_group( 'taxes' );
		WC_Cache_Helper::get_transient_version( 'taxes', true );
		wp_cache_flush();
	}
}
