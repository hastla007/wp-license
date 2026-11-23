<?php
/**
 * UEB-11 (owner decision of 23 Nov 2026): the largest quantity of a license product in one
 * order line is a setting of the shop (default 100, 0 = no limit), enforced on the server in
 * the classic cart and checkout and in the Store API; an order created by the shop owner is
 * noted, never blocked. The filter `wplc_max_license_quantity_per_line` wins over the setting.
 *
 * @package WPLC
 */

use WPLC\Admin\Settings;
use WPLC\Catalog\QuantityLimit;

final class QuantityLimitIntegrationTest extends WPLC_Integration_TestCase {

	public function set_up(): void {
		parent::set_up();
		if ( ! WC()->cart ) {
			WC()->frontend_includes();
			wc_load_cart();
		}
		WC()->cart->empty_cart();
		wc_clear_notices();
	}

	public function tear_down(): void {
		WC()->cart->empty_cart();
		wc_clear_notices();
		delete_option( Settings::OPTION );
		remove_all_filters( 'wplc_max_license_quantity_per_line' );
		remove_all_filters( 'woocommerce_store_api_disable_nonce_check' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Store the setting the way the settings form does.
	 *
	 * @param int $max Largest quantity.
	 */
	private function set_max( int $max ): void {
		update_option( Settings::OPTION, Settings::sanitize( array( 'max_quantity_per_line' => (string) $max ) ) );
	}

	/**
	 * Error notices of this request.
	 *
	 * @return string[]
	 */
	private function errors(): array {
		return array_map( 'wp_strip_all_tags', array_column( wc_get_notices( 'error' ), 'notice' ) );
	}

	public function test_default_is_one_hundred_and_only_license_products_are_limited(): void {
		$this->assertSame( 100, Settings::defaults()['max_quantity_per_line'] );
		$license = $this->create_license_product();
		$plain   = $this->create_simple_product();

		$this->assertSame( 100, QuantityLimit::limit_for( $license ) );
		$this->assertSame( 0, QuantityLimit::limit_for( $plain ), 'A product without a license plan has no limit of ours.' );

		// The quantity field (classic) and the Store API carry the maximum.
		$this->assertSame( 100, apply_filters( 'woocommerce_quantity_input_args', array( 'max_value' => -1 ), $license )['max_value'] );
		$this->assertSame( 7, apply_filters( 'woocommerce_quantity_input_args', array( 'max_value' => 7 ), $license )['max_value'], 'A smaller maximum of the shop (stock) stays.' );
		$this->assertSame( -1, apply_filters( 'woocommerce_quantity_input_args', array( 'max_value' => -1 ), $plain )['max_value'] );
		$this->assertSame( 100, apply_filters( 'woocommerce_store_api_product_quantity_maximum', 9999, $license, null ) );
		$this->assertSame( 9999, apply_filters( 'woocommerce_store_api_product_quantity_maximum', 9999, $plain, null ) );
	}

	public function test_classic_cart_and_checkout_refuse_more_than_the_limit(): void {
		$this->set_max( 3 );
		$product = $this->create_license_product();

		// Adding: 3 pass, a fourth unit is refused with a message; what is in the cart counts.
		$this->assertTrue( apply_filters( 'woocommerce_add_to_cart_validation', true, $product->get_id(), 3, 0 ) );
		WC()->cart->add_to_cart( $product->get_id(), 3 );
		$this->assertFalse( apply_filters( 'woocommerce_add_to_cart_validation', true, $product->get_id(), 1, 0 ) );
		$this->assertSame( array( 'You can order at most 3 units of "' . $product->get_name() . '" in one order. Please reduce the quantity.' ), $this->errors() );
		wc_clear_notices();

		// Cart and checkout: a cart within the limit passes ...
		do_action( 'woocommerce_check_cart_items' );
		$this->assertSame( array(), $this->errors() );

		// ... and one above it (quantity changed some other way) blocks the checkout.
		$key = array_key_first( WC()->cart->get_cart() );
		WC()->cart->set_quantity( $key, 4 );
		do_action( 'woocommerce_check_cart_items' );
		$this->assertCount( 1, $this->errors() );
		$this->assertStringContainsString( 'at most 3 units', $this->errors()[0] );
		wc_clear_notices();

		// One unit: singular.
		$this->set_max( 1 );
		$this->assertSame( 'You can order at most 1 unit of "X" in one order. Please reduce the quantity.', QuantityLimit::message( 'X', 1 ) );
	}

	public function test_store_api_refuses_more_than_the_limit(): void {
		$this->set_max( 3 );
		$product = $this->create_license_product();
		add_filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );
		do_action( 'rest_api_init' );

		$add = static function ( int $quantity ) use ( $product ): WP_REST_Response {
			$request = new WP_REST_Request( 'POST', '/wc/store/v1/cart/add-item' );
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( array( 'id' => $product->get_id(), 'quantity' => $quantity ) ) );

			return rest_get_server()->dispatch( $request );
		};

		$too_many = $add( 4 );
		$this->assertGreaterThanOrEqual( 400, $too_many->get_status(), (string) wp_json_encode( $too_many->get_data() ) );
		$this->assertSame( 0, WC()->cart->get_cart_contents_count(), 'Nothing was added.' );

		$ok = $add( 3 );
		$this->assertSame( 201, $ok->get_status(), (string) wp_json_encode( $ok->get_data() ) );
		$item = $ok->get_data()['items'][0];
		$this->assertSame( 3, $item['quantity'] );
		$this->assertSame( 3, $item['quantity_limits']->maximum, 'The block cart shows the same maximum.' );

		// The limit is lowered while the cart holds three: the Store API brings the cart down to the new maximum.
		$this->set_max( 2 );
		$cart = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/wc/store/v1/cart' ) );
		$this->assertSame( 200, $cart->get_status() );
		$this->assertSame( 2, $cart->get_data()['items'][0]['quantity'] );
		$this->assertSame( 2, $cart->get_data()['items'][0]['quantity_limits']->maximum );

		// And a cart that is above the limit all the same is refused when the order is placed
		// (the checkout of the Store API runs the same check as the classic one).
		WC()->cart->set_quantity( array_key_first( WC()->cart->get_cart() ), 5, false );
		$controller = new \Automattic\WooCommerce\StoreApi\Utilities\CartController();
		$errors     = $controller->get_cart_errors();
		$this->assertTrue( $errors->has_errors() );
		$this->assertStringContainsString( 'at most 2 units', wp_strip_all_tags( implode( ' ', $errors->get_error_messages() ) ) );
	}

	public function test_zero_switches_the_limit_off_and_the_filter_wins_over_the_setting(): void {
		$product = $this->create_license_product();

		$this->set_max( 0 );
		$this->assertSame( 0, QuantityLimit::limit_for( $product ) );
		$this->assertTrue( apply_filters( 'woocommerce_add_to_cart_validation', true, $product->get_id(), 5000, 0 ) );
		$this->assertSame( -1, apply_filters( 'woocommerce_quantity_input_args', array( 'max_value' => -1 ), $product )['max_value'] );

		// Per product, by code of the shop: the filter gets the product and decides last.
		$this->set_max( 50 );
		$id = $product->get_id();
		add_filter(
			'wplc_max_license_quantity_per_line',
			static function ( $max, $for ) use ( $id ) {
				return $for instanceof WC_Product && $for->get_id() === $id ? 5 : $max;
			},
			10,
			2
		);
		$this->assertSame( 5, QuantityLimit::limit_for( $product ) );
		$this->assertSame( 50, QuantityLimit::limit_for( $this->create_license_product( array( 'product_key' => 'other-product' ) ) ) );

		$this->assertSame( QuantityLimit::MAX_SETTING, Settings::sanitize( array( 'max_quantity_per_line' => '99999999' ) )['max_quantity_per_line'] );
		$this->assertSame( 50, Settings::sanitize( array( 'max_quantity_per_line' => 'many' ) )['max_quantity_per_line'], 'Not a whole number: the stored value stays.' );
	}

	public function test_an_order_of_the_shop_owner_above_the_limit_is_noted_not_blocked(): void {
		global $wpdb;
		$this->set_max( 2 );
		$admin    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$customer = self::factory()->user->create( array( 'role' => 'customer' ) );
		wp_set_current_user( $admin );

		// Created in the admin: three units of a license product, paid.
		$order = $this->create_paid_order( array( array( $this->create_license_product(), 3 ) ), $customer );

		$this->assertCount( 3, $this->licenses_for_order( $order->get_id() ), 'Every unit got its license.' );
		$notes = array_map(
			static function ( $note ): string {
				return (string) $note->content;
			},
			wc_get_order_notes( array( 'order_id' => $order->get_id() ) )
		);
		$about = array_values( array_filter( $notes, static fn( string $note ): bool => false !== strpos( $note, 'customers can order at most 2' ) ) );
		$this->assertCount( 1, $about, implode( ' | ', $notes ) );
		$audit = $wpdb->get_results( $wpdb->prepare( "SELECT actor_user_id, after_json FROM {$this->tables()['audit_log']} WHERE object_type = %s AND object_id = %d AND action = %s", 'order', $order->get_id(), 'order_quantity_above_limit' ), ARRAY_A );
		$this->assertCount( 1, $audit );
		$this->assertSame( 3, json_decode( (string) $audit[0]['after_json'], true )['lines'][0]['quantity'] );

		// Once per order, also when the order is looked at again.
		$this->assertFalse( QuantityLimit::note_order( wc_get_order( $order->get_id() ) ) );

		// An order within the limit gets no note.
		$small = $this->create_paid_order( array( array( $this->create_license_product(), 2 ) ), $customer );
		$this->assertSame( '', (string) wc_get_order( $small->get_id() )->get_meta( QuantityLimit::META_NOTED, true ) );
	}
}
