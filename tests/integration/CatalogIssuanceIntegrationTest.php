<?php
/**
 * Catalog, checkout rules and license issuing after the audit of 13.11.2026.
 *
 * Every test names the task it belongs to (docs/TODO.md, sections 2, 7 and 8).
 *
 * @package WPLC
 */

use WPLC\Billing\BlockCheckoutConsent;
use WPLC\Billing\RecurringConsent;
use WPLC\Catalog\Catalog;
use WPLC\Catalog\ProductEditor;
use WPLC\Catalog\TermDisplay;
use WPLC\Gateway\RecurringGatewayAdapter;
use WPLC\License\LicenseIssuer;

/**
 * Renewal adapter that accepts every token of the customer.
 */
final class WPLC_Test_Issuance_Adapter implements RecurringGatewayAdapter {
	public function gateway_id(): string {
		return 'wplc_test_gateway';
	}
	public function supports_checkout(): bool {
		return true;
	}
	public function supports_saved_token( int $token_id, int $customer_id ): bool {
		return $token_id > 0 && $customer_id > 0;
	}
	public function charge( $order, int $token_id, string $idempotency_key ) {
		return null;
	}
}

final class CatalogIssuanceIntegrationTest extends WPLC_Integration_TestCase {

	/**
	 * Captured emails.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private $mails = array();

	public function set_up(): void {
		parent::set_up();
		$this->mails = array();
		add_filter(
			'pre_wp_mail',
			function ( $short_circuit, $atts ) {
				$this->mails[] = $atts;
				return true;
			},
			10,
			2
		);
	}

	public function tear_down(): void {
		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function load_cart(): void {
		if ( ! WC()->cart ) {
			WC()->frontend_includes();
			wc_load_cart();
		}
		WC()->cart->empty_cart();
	}

	private function plan_row( int $product_id, int $variation_id = 0 ): ?array {
		global $wpdb;
		$t   = $this->tables();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t['plans']} WHERE woocommerce_product_id = %d AND woocommerce_variation_id = %d", $product_id, $variation_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	private function editor_post( array $fields ): void {
		$_POST = wp_slash(
			array_merge(
				array(
					'woocommerce_meta_nonce'   => wp_create_nonce( 'woocommerce_save_data' ),
					ProductEditor::PRESENT_FIELD => '1',
					'_wplc_is_license_product' => 'yes',
					'_wplc_product_key'        => 'voucher-captain',
					'_wplc_plan_key'           => 'pro',
					'_wplc_billing_interval'   => 'year',
					'_wplc_live_site_limit'    => '3',
					'_wplc_support_level'      => 'standard',
					'_wplc_entitlements_json'  => '',
				),
				$fields
			)
		);
	}

	private function as_shop_manager(): int {
		$user = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		wp_set_current_user( $user );
		return $user;
	}

	private function as_product_editor(): int {
		add_role( 'wplc_product_editor', 'Product editor', array( 'read' => true, 'edit_products' => true, 'edit_published_products' => true, 'edit_others_products' => true, 'publish_products' => true, 'edit_private_products' => true ) );
		$user = self::factory()->user->create( array( 'role' => 'wplc_product_editor' ) );
		wp_set_current_user( $user );
		$this->assertFalse( current_user_can( 'manage_woocommerce' ) );
		return $user;
	}

	private function meta_box_errors(): string {
		return class_exists( 'WC_Admin_Meta_Boxes' ) ? implode( "\n", (array) WC_Admin_Meta_Boxes::$meta_box_errors ) : '';
	}

	// ------------------------------------------------------------------
	// A-01, A-02: one rule for "license product"; new customers can buy.
	// ------------------------------------------------------------------

	/** A-01: a variation of a product mapped at parent level is a license product in cart, order and issuer. */
	public function test_a01_parent_level_mapping_counts_for_cart_order_and_issuer(): void {
		list( $parent, $variations ) = $this->create_variable_product( array( '49.00', '149.00' ) );
		$this->assertTrue( Catalog::save_mapping( $parent->get_id(), 0, $this->mapping_data( array( 'plan_key' => 'parent-level' ) ) ) );
		// Nothing is stored on the variation itself: neither a plan nor the legacy meta flag.
		$this->assertNull( Catalog::find_exact_mapping( $parent->get_id(), $variations[1]->get_id() ) );
		$this->assertSame( '', (string) $variations[1]->get_meta( '_wplc_is_license_product', true ) );

		$this->load_cart();
		$this->assertFalse( RecurringConsent::cart_contains_license() );
		$this->assertFalse( RecurringConsent::require_account( false ) );
		WC()->cart->add_to_cart( $parent->get_id(), 1, $variations[1]->get_id(), array( 'attribute_plan' => '1' ) );
		$this->assertCount( 1, WC()->cart->get_cart() );
		$this->assertTrue( RecurringConsent::cart_contains_license(), 'The cart must see the license through the parent mapping.' );
		$this->assertTrue( RecurringConsent::require_account( false ) );

		$customer = self::factory()->user->create( array( 'role' => 'customer' ) );
		$order    = $this->create_paid_order( array( array( wc_get_product( $variations[1]->get_id() ), 1 ) ), $customer );
		$this->assertTrue( RecurringConsent::order_contains_license( $order ) );

		$licenses = $this->licenses_for_order( $order->get_id() );
		$this->assertCount( 1, $licenses );
		$this->assertSame( $customer, (int) $licenses[0]['customer_id'] );
		$this->assertSame( 'parent-level', $licenses[0]['plan_key'] );
		// T-132: the renewal costs what the purchased variation costs, not the cheapest variation.
		$this->assertSame( 14900, (int) $licenses[0]['renewal_amount_minor'] );
	}

	/** A-01: the block checkout guard rejects a guest order with a variation of a parent-mapped product. */
	public function test_a01_block_checkout_guard_rejects_guest_for_parent_mapped_variation(): void {
		list( $parent, $variations ) = $this->create_variable_product( array( '49.00' ) );
		$this->assertTrue( Catalog::save_mapping( $parent->get_id(), 0, $this->mapping_data() ) );
		$order = $this->create_order( array( array( wc_get_product( $variations[0]->get_id() ), 1 ) ), 0 );

		$thrown = null;
		try {
			BlockCheckoutConsent::after_order_processed( $order );
		} catch ( \Throwable $error ) {
			$thrown = $error;
		}
		$this->assertNotNull( $thrown, 'A guest must not get through the block checkout with a license product.' );
	}

	/** A-02: with WooCommerce defaults a new customer can create the required account in the checkout. */
	public function test_a02_registration_is_offered_when_a_license_is_in_the_cart(): void {
		update_option( 'woocommerce_enable_signup_and_login_from_checkout', 'no' );
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );
		$product = $this->create_license_product();
		$plain   = $this->create_simple_product( '5.00' );

		$this->load_cart();
		WC()->cart->add_to_cart( $plain->get_id(), 1 );
		$this->assertFalse( WC()->checkout()->is_registration_enabled(), 'Shops without licenses in the cart keep their setting.' );
		$this->assertFalse( WC()->checkout()->is_registration_required() );

		WC()->cart->add_to_cart( $product->get_id(), 1 );
		$this->assertTrue( WC()->checkout()->is_registration_required() );
		$this->assertTrue( WC()->checkout()->is_registration_enabled(), 'An account is required, so the checkout must offer to create one.' );
	}

	/** A-01, A-70: a paid order without a customer is not issued silently; it is flagged and recovered. */
	public function test_a01_a70_order_without_customer_is_flagged_and_recovered(): void {
		$product = $this->create_license_product();
		$failed  = array();
		add_action(
			'wplc/license_issuance_failed',
			static function ( $order_id, $problems ) use ( &$failed ) {
				$failed[] = array( $order_id, $problems );
			},
			10,
			2
		);

		$order = $this->create_paid_order( array( array( $product, 1 ) ), 0 );
		$this->assertCount( 0, $this->licenses_for_order( $order->get_id() ), 'No ownerless license.' );
		$this->assertSame( 'no_customer', (string) $order->get_meta( LicenseIssuer::META_PROBLEM, true ) );
		$this->assertSame( array( array( $order->get_id(), array( 'no_customer' ) ) ), $failed );
		$notes = wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' );
		$this->assertStringContainsString( 'NOT received all its licenses', implode( "\n", $notes ) );

		// A second hook does not repeat note and event.
		LicenseIssuer::maybe_issue_order( $order->get_id() );
		$this->assertCount( 1, $failed );

		// The shop assigns a customer; the daily run finds the flagged order (in both order storages).
		$customer = self::factory()->user->create( array( 'role' => 'customer' ) );
		$order    = wc_get_order( $order->get_id() );
		$order->set_customer_id( $customer );
		$order->save();
		LicenseIssuer::recover_unfinished();

		$licenses = $this->licenses_for_order( $order->get_id() );
		$this->assertCount( 1, $licenses );
		$this->assertSame( $customer, (int) $licenses[0]['customer_id'] );
		$this->assertSame( '', (string) wc_get_order( $order->get_id() )->get_meta( LicenseIssuer::META_PROBLEM, true ) );
	}

	/** A-01: an order without customer goes to the account that uses the billing email address. */
	public function test_a01_order_without_customer_uses_the_account_of_the_billing_email(): void {
		$product  = $this->create_license_product();
		$customer = self::factory()->user->create( array( 'role' => 'customer', 'user_email' => 'known-buyer@example.test' ) );
		$order    = $this->create_order( array( array( $product, 1 ) ), 0 );
		$order->set_billing_email( 'known-buyer@example.test' );
		$order->save();
		$order->payment_complete( 'txn' );

		$licenses = $this->licenses_for_order( $order->get_id() );
		$this->assertCount( 1, $licenses );
		$this->assertSame( $customer, (int) $licenses[0]['customer_id'] );
		$this->assertSame( 0, (int) $licenses[0]['renewal_enabled'] );
	}

	/** A-70: a license that cannot be created leaves a trace and is retried. */
	public function test_a70_failed_issue_is_flagged_and_retried(): void {
		global $wpdb;
		$t        = $this->tables();
		$product  = $this->create_license_product();
		$customer = self::factory()->user->create( array( 'role' => 'customer' ) );
		$break    = static function ( $query ) use ( $t ) {
			return 0 === strpos( ltrim( $query ), 'INSERT INTO `' . $t['licenses'] . '`' ) ? 'INSERT INTO wplc_table_that_does_not_exist VALUES (1)' : $query;
		};
		add_filter( 'query', $break );
		$suppressed = $wpdb->suppress_errors( true );
		$order      = $this->create_paid_order( array( array( $product, 1 ) ), $customer );
		$wpdb->suppress_errors( $suppressed );
		remove_filter( 'query', $break );

		$this->assertCount( 0, $this->licenses_for_order( $order->get_id() ) );
		$this->assertSame( 'issue_failed', (string) $order->get_meta( LicenseIssuer::META_PROBLEM, true ) );

		LicenseIssuer::recover_unfinished();
		$this->assertCount( 1, $this->licenses_for_order( $order->get_id() ) );
		$this->assertSame( '', (string) wc_get_order( $order->get_id() )->get_meta( LicenseIssuer::META_PROBLEM, true ) );
	}

	/** A-24: cash on delivery is "processing" before any money arrived; the license comes with "completed". */
	public function test_a24_cash_on_delivery_gets_the_license_when_completed(): void {
		$product  = $this->create_license_product();
		$customer = self::factory()->user->create( array( 'role' => 'customer' ) );
		$order    = $this->create_order( array( array( $product, 1 ) ), $customer );
		$order->set_payment_method( 'cod' );
		$order->save();

		$order->update_status( 'processing' );
		$this->assertCount( 0, $this->licenses_for_order( $order->get_id() ) );
		$order->update_status( 'completed' );
		$this->assertCount( 1, $this->licenses_for_order( $order->get_id() ) );
	}

	/** B-39: a shop can hold back issuing through wplc_can_issue_for_order. */
	public function test_b39_filter_can_hold_back_issuing(): void {
		$product  = $this->create_license_product();
		$customer = self::factory()->user->create( array( 'role' => 'customer' ) );
		add_filter( 'wplc_can_issue_for_order', '__return_false' );
		$order = $this->create_paid_order( array( array( $product, 1 ) ), $customer );
		$this->assertCount( 0, $this->licenses_for_order( $order->get_id() ) );
		remove_filter( 'wplc_can_issue_for_order', '__return_false' );
		LicenseIssuer::maybe_issue_order( $order->get_id() );
		$this->assertCount( 1, $this->licenses_for_order( $order->get_id() ) );
	}

	// ------------------------------------------------------------------
	// B-74, B-126: interrupted requests.
	// ------------------------------------------------------------------

	/** B-74: a pending row of an aborted request no longer blocks the unit forever. */
	public function test_b74_stale_pending_row_is_replaced(): void {
		global $wpdb;
		$t        = $this->tables();
		$customer = self::factory()->user->create( array( 'role' => 'customer' ) );
		$product  = $this->create_license_product();
		$order    = $this->create_order( array( array( $product, 2 ) ), $customer );
		$item     = $this->first_item( $order );

		// A request died between the INSERT (pending) and the activation.
		$orphan = $this->seed_license(
			array(
				'status'               => 'pending',
				'customer_id'          => $customer,
				'source_order_id'      => $order->get_id(),
				'source_order_item_id' => $item->get_id(),
				'unit_index'           => 0,
			),
			array( 'feature.export' => true )
		);

		$order->payment_complete( 'txn' );
		// While the row is fresh another request may still be working: unit 0 waits, unit 1 is issued.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, unit_index, status FROM {$t['licenses']} WHERE source_order_id = %d ORDER BY unit_index", $order->get_id() ), ARRAY_A );
		$this->assertSame( array( 'pending', 'active' ), wp_list_pluck( $rows, 'status' ) );
		$this->assertSame( 'in_progress', (string) wc_get_order( $order->get_id() )->get_meta( LicenseIssuer::META_PROBLEM, true ) );
		$this->assertTrue( as_has_scheduled_action( LicenseIssuer::RETRY_HOOK, array( $order->get_id() ), 'wplc' ), 'A retry is scheduled for the unit that is in progress.' );

		// The row is older than the grace period: the request is dead.
		$wpdb->update( $t['licenses'], array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - LicenseIssuer::PENDING_GRACE - 60 ) ), array( 'id' => $orphan['id'] ) );
		LicenseIssuer::recover_unfinished();

		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, unit_index, status FROM {$t['licenses']} WHERE source_order_id = %d ORDER BY unit_index", $order->get_id() ), ARRAY_A );
		$this->assertSame( array( 'active', 'active' ), wp_list_pluck( $rows, 'status' ) );
		$this->assertNotSame( $orphan['id'], (int) $rows[0]['id'], 'The orphan was discarded and the unit issued again.' );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t['entitlements']} WHERE license_id = %d", $orphan['id'] ) ) );
		$this->assertSame( '', (string) wc_get_order( $order->get_id() )->get_meta( LicenseIssuer::META_PROBLEM, true ) );
		$this->assertCount( 2, (array) wc_get_order( $order->get_id() )->get_item( $item->get_id() )->get_meta( '_wplc_license_ids', true ) );
	}

	/** B-126: the key email no longer depends on the one hook of the checkout request. */
	public function test_b126_followup_job_sends_the_key_email_once(): void {
		$product  = $this->create_license_product();
		$customer = self::factory()->user->create( array( 'role' => 'customer', 'user_email' => 'followup@example.test' ) );

		// The WordPress test suite creates tables as temporary tables, which the notification log
		// does not find in information_schema; it then reports "not available" and duplicate
		// protection is off. Mark the (temporary) table as ready for this test.
		\WPLC\Notifications\ReminderLog::maybe_install();
		$ready = new ReflectionProperty( \WPLC\Notifications\ReminderLog::class, 'ready' );
		$ready->setAccessible( true );
		$was_ready = $ready->getValue();
		$ready->setValue( null, true );

		// The checkout request dies before the key email: nobody listens to the one-shot hook.
		$removed = remove_action( 'wplc/license_issued', array( \WPLC\Notifications\Notifications::class, 'on_license_issued' ), 20 );
		$this->assertTrue( $removed );
		$order    = $this->create_paid_order( array( array( $product, 1 ) ), $customer );
		$licenses = $this->licenses_for_order( $order->get_id() );
		add_action( 'wplc/license_issued', array( \WPLC\Notifications\Notifications::class, 'on_license_issued' ), 20, 3 );
		$this->assertCount( 1, $licenses );
		$license_id = (int) $licenses[0]['id'];

		$to_customer = function (): array {
			return array_values(
				array_filter(
					$this->mails,
					static function ( $mail ) {
						// License emails go to the billing address of the source order (A-137).
						return (bool) preg_grep( '/^(followup|buyer-\d+)@example\.test$/', (array) $mail['to'] ) && false !== strpos( (string) $mail['message'], 'WPLC-' );
					}
				)
			);
		};
		$this->assertCount( 0, $to_customer(), 'Precondition: the checkout request sent no key email.' );
		$this->assertTrue( as_has_scheduled_action( LicenseIssuer::FOLLOWUP_HOOK, array( $license_id ), 'wplc' ), 'The follow-up job is queued with the license.' );

		$followups = 0;
		add_action(
			'wplc/license_issued_followup',
			static function () use ( &$followups ) {
				++$followups;
			}
		);
		do_action( LicenseIssuer::FOLLOWUP_HOOK, $license_id );
		$this->assertCount( 1, $to_customer(), 'The job sends the key email.' );

		// The daily run leaves a license alone whose key email is on record, even after the term
		// changed (the notification log keys its duplicate check by the paid-until date).
		global $wpdb;
		$t = $this->tables();
		$wpdb->update( $t['licenses'], array( 'paid_until' => gmdate( 'Y-m-d H:i:s', time() + 500 * DAY_IN_SECONDS ) ), array( 'id' => $license_id ) );
		LicenseIssuer::recover_unfinished();
		$this->assertCount( 1, $to_customer() );
		$this->assertSame( 1, $followups );

		// A license whose email was never sent (the job was lost as well) is caught by the daily run.
		remove_action( 'wplc/license_issued', array( \WPLC\Notifications\Notifications::class, 'on_license_issued' ), 20 );
		$second = $this->create_paid_order( array( array( $product, 1 ) ), $customer );
		add_action( 'wplc/license_issued', array( \WPLC\Notifications\Notifications::class, 'on_license_issued' ), 20, 3 );
		$this->assertCount( 1, $to_customer() );
		LicenseIssuer::recover_unfinished();
		$this->assertCount( 2, $to_customer() );
		$this->assertSame( 2, $followups );
		$this->assertCount( 1, $this->licenses_for_order( $second->get_id() ) );
		$ready->setValue( null, $was_ready );
	}

	// ------------------------------------------------------------------
	// B-51, A-54, A-11, B-21: catalog rows.
	// ------------------------------------------------------------------

	/** B-51: saving a plan again keeps its ID and creation date. */
	public function test_b51_plan_id_is_stable_across_saves(): void {
		global $wpdb;
		$t       = $this->tables();
		$product = $this->create_license_product();
		$before  = $this->plan_row( $product->get_id() );
		$wpdb->update( $t['plans'], array( 'created_at' => '2025-01-01 00:00:00' ), array( 'id' => $before['id'] ) );
		$wpdb->update( $t['products'], array( 'created_at' => '2025-01-01 00:00:00' ), array( 'product_key' => 'test-product' ) );

		$this->assertTrue( Catalog::save_mapping( $product->get_id(), 0, $this->mapping_data( array( 'live_site_limit' => 5 ) ) ) );
		$after = $this->plan_row( $product->get_id() );

		$this->assertSame( $before['id'], $after['id'] );
		$this->assertSame( '5', (string) $after['live_site_limit'] );
		$this->assertSame( '2025-01-01 00:00:00', $after['created_at'] );
		$this->assertSame( '2025-01-01 00:00:00', $wpdb->get_var( "SELECT created_at FROM {$t['products']} WHERE product_key = 'test-product'" ) );
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t['plans']} WHERE woocommerce_product_id = %d", $product->get_id() ) ) );
		// A-77: the change is audited.
		$this->assertGreaterThanOrEqual( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t['audit_log']} WHERE action = 'catalog_plan_changed' AND object_id = %d", $after['id'] ) ) );
	}

	/** A-54, A-112: the customer-facing product name is set once and only changed on purpose. */
	public function test_a54_display_name_is_not_overwritten_by_later_saves(): void {
		$first = $this->create_simple_product();
		$first->set_name( 'Voucher Captain Pro' );
		$first->save();
		$this->assertTrue( Catalog::save_mapping( $first->get_id(), 0, $this->mapping_data( array( 'product_key' => 'name-test' ) ) ) );
		$this->assertSame( 'Voucher Captain Pro', Catalog::product_display_name( 'name-test' ) );

		$second = $this->create_simple_product();
		$second->set_name( 'Voucher Captain Pro - Agency (monthly, special offer)' );
		$second->save();
		$this->assertTrue( Catalog::save_mapping( $second->get_id(), 0, $this->mapping_data( array( 'product_key' => 'name-test', 'plan_key' => 'agency', 'display_name' => $second->get_name() ) ) ) );
		$this->assertSame( 'Voucher Captain Pro', Catalog::product_display_name( 'name-test' ) );

		$long = str_repeat( 'ä', 250 );
		$this->assertTrue( Catalog::save_mapping( $second->get_id(), 0, $this->mapping_data( array( 'product_key' => 'name-test', 'plan_key' => 'agency', 'display_name' => $long, 'update_display_name' => true ) ) ) );
		$this->assertSame( str_repeat( 'ä', 190 ), Catalog::product_display_name( 'name-test' ) );

		// The editor field renames on purpose, for the key the form was loaded with.
		$this->as_shop_manager();
		$this->editor_post( array( '_wplc_product_key' => 'name-test', '_wplc_plan_key' => 'agency', '_wplc_product_display_name' => 'Voucher Captain' ) );
		ProductEditor::save_product_fields( $second->get_id() );
		$this->assertSame( 'Voucher Captain', Catalog::product_display_name( 'name-test' ) );

		// Switching a product to another existing key must not carry the old name over to that key.
		$other = $this->create_license_product( array( 'product_key' => 'other-product', 'display_name' => 'Other Product' ) );
		$this->editor_post( array( '_wplc_product_key' => 'name-test', '_wplc_plan_key' => 'moved', '_wplc_product_display_name' => 'Other Product' ) );
		ProductEditor::save_product_fields( $other->get_id() );
		$this->assertSame( 'name-test', Catalog::find_mapping( $other->get_id() )['product_key'] );
		$this->assertSame( 'Voucher Captain', Catalog::product_display_name( 'name-test' ) );
	}

	/** A-11: the plan price follows the product price wherever the product is saved. */
	public function test_a11_plan_price_follows_product_saves_outside_the_editor(): void {
		$product = $this->create_license_product( array(), '49.00' );
		$this->assertSame( 4900, (int) $this->plan_row( $product->get_id() )['price_minor'] );

		// Quick edit, bulk edit, REST and the importer all end in WC_Product::save().
		$product = wc_get_product( $product->get_id() );
		$product->set_regular_price( '79.00' );
		$product->set_sale_price( '59.00' );
		$product->save();

		$this->assertSame( 7900, (int) $this->plan_row( $product->get_id() )['price_minor'], 'The regular price, not the sale price.' );
	}

	/** B-21: a duplicate made by a catalog manager is a license product; one made by a product editor is not. */
	public function test_b21_duplicate_follows_the_catalog_capability(): void {
		require_once WC()->plugin_path() . '/includes/admin/class-wc-admin-duplicate-product.php';
		$this->as_shop_manager();
		$original = $this->create_simple_product();
		$this->editor_post( array() );
		ProductEditor::save_product_fields( $original->get_id() );
		$_POST = array();
		$this->assertIsArray( Catalog::find_mapping( $original->get_id() ) );
		$this->assertSame( 'yes', wc_get_product( $original->get_id() )->get_meta( '_wplc_is_license_product', true ) );

		$duplicator = new WC_Admin_Duplicate_Product();
		$copy       = $duplicator->product_duplicate( wc_get_product( $original->get_id() ) );
		$copy_plan  = Catalog::find_mapping( $copy->get_id() );
		$this->assertIsArray( $copy_plan, 'The meta was copied; the plan must follow, or the customer pays and gets nothing.' );
		$this->assertSame( array( 'voucher-captain', 'pro' ), array( $copy_plan['product_key'], $copy_plan['plan_key'] ) );

		// B-48: a role that may only edit products cannot create a plan through copied meta.
		$this->as_product_editor();
		$copy2 = $duplicator->product_duplicate( wc_get_product( $original->get_id() ) );
		$this->assertNull( Catalog::find_mapping( $copy2->get_id() ) );
		$this->assertSame( 'no', get_post_meta( $copy2->get_id(), '_wplc_is_license_product', true ), 'Meta and catalog must not disagree.' );
		remove_role( 'wplc_product_editor' );
	}

	/** B-21: meta without a plan is reported; trashed and deleted products lose their plan. */
	public function test_b21_flagged_products_and_deleted_products(): void {
		$stray = $this->create_simple_product();
		update_post_meta( $stray->get_id(), '_wplc_is_license_product', 'yes' ); // A copy that bypassed WooCommerce.
		$this->assertContains( $stray->get_id(), Catalog::flagged_without_mapping() );

		$product = $this->create_license_product();
		$this->assertNotContains( $product->get_id(), Catalog::flagged_without_mapping() );
		$this->assertContains( $product->get_id(), Catalog::license_product_ids() );

		wp_trash_post( $product->get_id() );
		$this->assertNull( Catalog::find_mapping( $product->get_id() ), 'A trashed product has no active plan.' );
		$this->assertNotContains( $product->get_id(), Catalog::license_product_ids() );
		wp_untrash_post( $product->get_id() );
		$this->assertIsArray( Catalog::find_mapping( $product->get_id() ) );

		wp_delete_post( $product->get_id(), true );
		$this->assertNull( $this->plan_row( $product->get_id() ), 'A deleted product leaves no plan behind.' );
	}

	// ------------------------------------------------------------------
	// A-05, A-50, B-48, B-123: product editor.
	// ------------------------------------------------------------------

	/** A-05: "Save changes" on the Variations tab (AJAX, own nonce, no meta box nonce) saves the license fields. */
	public function test_a05_ajax_variation_save_keeps_the_license_fields(): void {
		$this->as_shop_manager();
		list( $parent, $variations ) = $this->create_variable_product( array( '99.00', '19.90' ) );

		// What admin-ajax.php?action=woocommerce_save_variations receives: the rows and `security`.
		$_POST = wp_slash(
			array(
				'action'                            => 'woocommerce_save_variations',
				'security'                          => wp_create_nonce( 'save-variations' ),
				'product_id'                        => (string) $parent->get_id(),
				ProductEditor::VARIATION_PRESENT_FIELD => array( '1', '1' ),
				'_wplc_variation_is_license_product' => array( 'yes', 'yes' ),
				'_wplc_variation_product_key'       => array( 'affiliate-captain', 'affiliate-captain' ),
				'_wplc_variation_plan_key'          => array( 'business', 'agency-monthly' ),
				'_wplc_variation_billing_interval'  => array( 'year', 'month' ),
				'_wplc_variation_live_site_limit'   => array( '2', '10' ),
				'_wplc_variation_support_level'     => array( 'standard', 'priority' ),
				'_wplc_variation_entitlements_json' => array( '', '{"affiliate.program_limit":3}' ),
			)
		);
		$this->assertArrayNotHasKey( 'woocommerce_meta_nonce', $_POST );
		ProductEditor::save_variation_fields( $variations[0]->get_id(), 0 );
		ProductEditor::save_variation_fields( $variations[1]->get_id(), 1 );

		$business = Catalog::find_exact_mapping( $parent->get_id(), $variations[0]->get_id() );
		$agency   = Catalog::find_exact_mapping( $parent->get_id(), $variations[1]->get_id() );
		$this->assertIsArray( $business );
		$this->assertIsArray( $agency );
		$this->assertSame( array( 'business', 'year', '9900' ), array( $business['plan_key'], $business['billing_interval'], (string) $business['price_minor'] ) );
		$this->assertSame( array( 'agency-monthly', 'month', '1990' ), array( $agency['plan_key'], $agency['billing_interval'], (string) $agency['price_minor'] ) );

		// A wrong nonce saves nothing.
		$_POST['security']                    = 'invalid';
		$_POST['_wplc_variation_plan_key'][0] = 'changed';
		ProductEditor::save_variation_fields( $variations[0]->get_id(), 0 );
		$this->assertSame( 'business', Catalog::find_exact_mapping( $parent->get_id(), $variations[0]->get_id() )['plan_key'] );

		// A request that saves the variation without the license fields (no marker) must not delete the plan.
		$_POST = wp_slash( array( 'security' => wp_create_nonce( 'save-variations' ), 'product_id' => (string) $parent->get_id() ) );
		ProductEditor::save_variation_fields( $variations[0]->get_id(), 0 );
		$this->assertIsArray( Catalog::find_exact_mapping( $parent->get_id(), $variations[0]->get_id() ) );
	}

	/** A-50, B-123: invalid input is reported through WooCommerce's meta box errors and changes nothing. */
	public function test_a50_b123_invalid_input_is_reported_and_keeps_the_existing_plan(): void {
		$this->as_shop_manager();
		$product = $this->create_simple_product();
		$this->editor_post( array() );
		ProductEditor::save_product_fields( $product->get_id() );
		$this->assertSame( 'pro', Catalog::find_mapping( $product->get_id() )['plan_key'] );

		// B-123: "Jährlich" used to become "jhrlich".
		WC_Admin_Meta_Boxes::$meta_box_errors = array();
		$this->editor_post( array( '_wplc_plan_key' => 'Jährlich' ) );
		ProductEditor::save_product_fields( $product->get_id() );
		$this->assertSame( 'pro', Catalog::find_mapping( $product->get_id() )['plan_key'], 'Not rewritten, not saved.' );
		$this->assertStringContainsString( 'license plan key', $this->meta_box_errors() );

		// A-50: a missing plan key used to delete the mapping without a word.
		WC_Admin_Meta_Boxes::$meta_box_errors = array();
		$this->editor_post( array( '_wplc_plan_key' => '' ) );
		ProductEditor::save_product_fields( $product->get_id() );
		$this->assertIsArray( Catalog::find_mapping( $product->get_id() ), 'The existing plan stays.' );
		$this->assertStringContainsString( 'were not saved', $this->meta_box_errors() );

		WC_Admin_Meta_Boxes::$meta_box_errors = array();
		$this->editor_post( array( '_wplc_entitlements_json' => '{broken' ) );
		ProductEditor::save_product_fields( $product->get_id() );
		$this->assertStringContainsString( 'valid JSON object', $this->meta_box_errors() );

		// Capitals and blanks around a key are normalized; that is the only change.
		WC_Admin_Meta_Boxes::$meta_box_errors = array();
		$this->editor_post( array( '_wplc_plan_key' => ' Agency ' ) );
		ProductEditor::save_product_fields( $product->get_id() );
		$this->assertSame( 'agency', Catalog::find_mapping( $product->get_id() )['plan_key'] );
		$this->assertSame( '', $this->meta_box_errors() );

		// Unticking the box removes the plan (with the fields present).
		$this->editor_post( array( '_wplc_is_license_product' => '' ) );
		ProductEditor::save_product_fields( $product->get_id() );
		$this->assertNull( Catalog::find_mapping( $product->get_id() ) );
		$this->assertSame( 'no', wc_get_product( $product->get_id() )->get_meta( '_wplc_is_license_product', true ) );
		WC_Admin_Meta_Boxes::$meta_box_errors = array();
	}

	/** B-48 (reviewers' test R7): editing products is not enough to write the license catalog. */
	public function test_b48_edit_products_role_cannot_write_the_license_catalog(): void {
		global $wpdb;
		$t        = $this->tables();
		$official = $this->create_license_product( array( 'product_key' => 'passmaster-pro', 'plan_key' => 'single' ), '99.00' );
		$before   = $wpdb->get_var( "SELECT display_name FROM {$t['products']} WHERE product_key = 'passmaster-pro'" );

		$this->as_product_editor();
		$cheap = $this->create_simple_product( '0.00' );
		$cheap->set_name( 'Totally free thing' );
		$cheap->save();
		$this->assertTrue( current_user_can( 'edit_post', $cheap->get_id() ) );

		$this->editor_post(
			array(
				'_wplc_product_key'       => 'passmaster-pro',
				'_wplc_plan_key'          => 'agency',
				'_wplc_live_site_limit'   => '1000',
				'_wplc_support_level'     => 'priority',
				'_wplc_entitlements_json' => '{"feature.everything":true}',
				'_wplc_product_display_name' => 'Hijacked',
			)
		);
		ProductEditor::save_product_fields( $cheap->get_id() );
		$_POST = array();

		$this->assertNull( Catalog::find_mapping( $cheap->get_id() ), 'No plan for a role without the catalog capability.' );
		$this->assertSame( $before, $wpdb->get_var( "SELECT display_name FROM {$t['products']} WHERE product_key = 'passmaster-pro'" ) );

		// The same role writes the meta through another door (REST meta_data, import) and saves the product.
		$cheap = wc_get_product( $cheap->get_id() );
		$cheap->update_meta_data( '_wplc_is_license_product', 'yes' );
		$cheap->update_meta_data( '_wplc_product_key', 'passmaster-pro' );
		$cheap->update_meta_data( '_wplc_plan_key', 'agency' );
		$cheap->update_meta_data( '_wplc_billing_interval', 'year' );
		$cheap->save();
		$this->assertNull( Catalog::find_mapping( $cheap->get_id() ) );
		$this->assertSame( 'no', get_post_meta( $cheap->get_id(), '_wplc_is_license_product', true ) );

		// A free order for the "hijack" product issues nothing.
		$buyer = self::factory()->user->create( array( 'role' => 'customer' ) );
		$order = $this->create_paid_order( array( array( wc_get_product( $cheap->get_id() ), 1 ) ), $buyer );
		$this->assertCount( 0, $this->licenses_for_order( $order->get_id() ) );

		// The fields are not rendered for this role; an existing plan is shown read-only.
		require_once WC()->plugin_path() . '/includes/admin/wc-meta-box-functions.php';
		$GLOBALS['post'] = get_post( $official->get_id() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		ob_start();
		ProductEditor::render_product_fields();
		$html = ob_get_clean();
		$this->assertStringNotContainsString( '<input', $html );
		$this->assertStringContainsString( 'passmaster-pro', $html );

		// The filter hands the catalog to another capability.
		$grant = static function ( $capability, $area ) {
			return 'manage_catalog' === $area ? 'edit_products' : $capability;
		};
		add_filter( 'wplc_capability', $grant, 10, 2 );
		ob_start();
		ProductEditor::render_product_fields();
		$html = ob_get_clean();
		remove_filter( 'wplc_capability', $grant, 10 );
		// B-112: hidden for external and grouped products; the marker field is part of the form.
		$this->assertStringContainsString( 'hide_if_external hide_if_grouped', $html );
		$this->assertStringContainsString( 'name="' . ProductEditor::PRESENT_FIELD . '"', $html );
		$this->assertStringContainsString( 'name="_wplc_product_key"', $html );
		unset( $GLOBALS['post'] );
		remove_role( 'wplc_product_editor' );
	}

	/** B-112: the block-based product editor is announced instead of silently missing the fields. */
	public function test_b112_block_product_editor_notice(): void {
		$this->as_shop_manager();
		set_current_screen( 'edit-product' );

		// WooCommerce 11 has no block product editor any more; 8.9 to 10.x report it through the feature flag.
		add_filter( 'wplc_block_product_editor_enabled', '__return_false' );
		ob_start();
		ProductEditor::render_block_editor_notice();
		$without = ob_get_clean();
		remove_filter( 'wplc_block_product_editor_enabled', '__return_false' );

		add_filter( 'wplc_block_product_editor_enabled', '__return_true' );
		ob_start();
		ProductEditor::render_block_editor_notice();
		$with = ob_get_clean();

		// Not for users who cannot manage the catalog anyway.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		ob_start();
		ProductEditor::render_block_editor_notice();
		$other = ob_get_clean();
		remove_filter( 'wplc_block_product_editor_enabled', '__return_true' );
		set_current_screen( 'front' );

		$this->assertSame( '', $without );
		$this->assertStringContainsString( 'classic product editor', $with );
		$this->assertSame( '', $other );
	}

	// ------------------------------------------------------------------
	// B-30, A-10, A-22, B-37, A-56, B-113, B-105, B-114: checkout display and consent.
	// ------------------------------------------------------------------

	/** B-30, A-10, A-22: term and renewal price are visible before the purchase. */
	public function test_b30_a10_term_and_renewal_price_are_shown(): void {
		$adapter = static function ( $adapters ) {
			$adapters[] = new WPLC_Test_Issuance_Adapter();
			return $adapters;
		};
		add_filter( 'wplc_recurring_gateway_adapters', $adapter );

		$product = $this->create_license_product( array( 'billing_interval' => 'month' ), '59.00' );
		$product = wc_get_product( $product->get_id() );
		$product->set_sale_price( '39.00' );
		$product->save();
		$plain = $this->create_simple_product( '5.00' );

		$this->assertStringContainsString( 'for 1 month', wc_get_product( $product->get_id() )->get_price_html() );
		$this->assertStringNotContainsString( 'wplc-license-term', wc_get_product( $plain->get_id() )->get_price_html() );

		$customer = self::factory()->user->create( array( 'role' => 'customer' ) );
		wp_set_current_user( $customer );
		$this->load_cart();
		$key  = WC()->cart->add_to_cart( $product->get_id(), 1 );
		$data = TermDisplay::cart_item_data( array(), WC()->cart->get_cart_item( $key ) );
		$text = wp_json_encode( $data, JSON_UNESCAPED_UNICODE );
		$this->assertStringContainsString( '1 month', $text );
		// A-10: the renewal is the regular price (59), not the sale price the customer pays today (39).
		$this->assertStringContainsString( '59', $text );
		$this->assertStringNotContainsString( '39', $text );
		$this->assertStringContainsString( 'per month', $text );
		$this->assertStringNotContainsString( 'already have a license', $text );
		$this->assertSame( array(), TermDisplay::cart_item_data( array(), array( 'product_id' => $plain->get_id(), 'variation_id' => 0, 'data' => $plain ) ) );

		// A-22: a customer who owns a license for the product is told that this is a new license.
		$this->seed_license( array( 'customer_id' => $customer, 'product_key' => 'test-product' ) );
		$text = wp_json_encode( TermDisplay::cart_item_data( array(), WC()->cart->get_cart_item( $key ) ) );
		$this->assertStringContainsString( 'already have a license', $text );

		// The wording of the opt-in no longer promises "the displayed price".
		$this->assertStringNotContainsString( 'displayed', RecurringConsent::label() );
		remove_filter( 'wplc_recurring_gateway_adapters', $adapter );
	}

	/** B-37: every license with automatic renewal gets its own consent record. */
	public function test_b37_consent_evidence_is_stored_per_license(): void {
		global $wpdb;
		$t       = $this->tables();
		$adapter = static function ( $adapters ) {
			$adapters[] = new WPLC_Test_Issuance_Adapter();
			return $adapters;
		};
		add_filter( 'wplc_recurring_gateway_adapters', $adapter );

		$customer = self::factory()->user->create( array( 'role' => 'customer' ) );
		$token    = new WC_Payment_Token_CC();
		$token->set_token( 'pm_test_' . wp_generate_password( 8, false ) );
		$token->set_gateway_id( 'wplc_test_gateway' );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( (string) ( (int) gmdate( 'Y' ) + 2 ) );
		$token->set_user_id( $customer );
		$token->save();
		$token_filter = static function () use ( $token ) {
			return $token->get_id();
		};
		add_filter( 'wplc_payment_token_id_for_order', $token_filter );

		$yearly  = $this->create_license_product( array( 'plan_key' => 'yearly' ), '59.00' );
		$monthly = $this->create_license_product( array( 'plan_key' => 'monthly', 'billing_interval' => 'month' ), '9.90' );
		$order   = $this->create_order( array( array( $yearly, 1 ), array( $monthly, 2 ) ), $customer );
		$order->set_payment_method( 'wplc_test_gateway' );
		RecurringConsent::apply_decision( $order, RecurringConsent::decide( true, RecurringConsent::order_contains_license( $order ), true ), 'classic' );
		$order->save();

		$evidence = $order->get_meta( RecurringConsent::META_EVIDENCE, true );
		$this->assertIsArray( $evidence );
		$this->assertStringContainsString( RecurringConsent::label(), $evidence['text'] );
		$this->assertStringContainsString( 'per year', $evidence['text'] );
		$this->assertStringContainsString( 'per month', $evidence['text'] );
		$this->assertSame( hash( 'sha256', $evidence['text'] ), $evidence['hash'] );
		$this->assertSame( determine_locale(), $evidence['locale'] );

		$order->payment_complete( 'txn' );
		$licenses = $this->licenses_for_order( $order->get_id() );
		$this->assertCount( 3, $licenses );
		$this->assertSame( array( '1', '1', '1' ), array_map( 'strval', wp_list_pluck( $licenses, 'renewal_enabled' ) ) );

		$consents = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t['consents']} WHERE order_id = %d ORDER BY license_id", $order->get_id() ), ARRAY_A );
		$this->assertCount( 3, $consents, 'One record per license, not one tick for the whole order.' );
		$this->assertSame( wp_list_pluck( $licenses, 'id' ), wp_list_pluck( $consents, 'license_id' ) );
		$by_interval = array();
		foreach ( $consents as $consent ) {
			$by_interval[ $consent['billing_interval'] ][] = (int) $consent['amount_minor'];
			$this->assertSame( $evidence['hash'], $consent['text_hash'] );
			$this->assertSame( $evidence['text'], $consent['consent_text'] );
			$this->assertSame( 'classic', $consent['source'] );
			$this->assertSame( determine_locale(), $consent['locale'] );
			$this->assertSame( (string) $customer, (string) $consent['customer_id'] );
			$this->assertSame( (string) $order->get_meta( RecurringConsent::META_CONSENT_AT, true ), $consent['consent_at'] );
		}
		$this->assertSame( array( 5900 ), $by_interval['year'] );
		$this->assertSame( array( 990, 990 ), $by_interval['month'] );

		// Without consent: no record, and a withdrawn decision removes the evidence from the order.
		$plain_order = $this->create_order( array( array( $yearly, 1 ) ), $customer );
		RecurringConsent::apply_decision( $plain_order, 'granted', 'block' );
		RecurringConsent::apply_decision( $plain_order, 'none', 'block' );
		$this->assertSame( '', (string) $plain_order->get_meta( RecurringConsent::META_EVIDENCE, true ) );

		remove_filter( 'wplc_payment_token_id_for_order', $token_filter );
		remove_filter( 'wplc_recurring_gateway_adapters', $adapter );
	}

	/** A-56: the customer is told when the requested automatic renewal is not possible. */
	public function test_a56_customer_is_told_about_an_unsupported_opt_in(): void {
		$product  = $this->create_license_product();
		$customer = self::factory()->user->create( array( 'role' => 'customer' ) );
		$order    = $this->create_order( array( array( $product, 1 ) ), $customer );
		RecurringConsent::apply_decision( $order, RecurringConsent::decide( true, true, false ), 'classic' );
		$order->save();
		RecurringConsent::add_unsupported_note( $order );

		ob_start();
		RecurringConsent::render_email_hint( wc_get_order( $order->get_id() ), false, false );
		$email = ob_get_clean();
		ob_start();
		RecurringConsent::render_thankyou_hint( $order->get_id() );
		$thankyou = ob_get_clean();
		ob_start();
		RecurringConsent::render_email_hint( wc_get_order( $order->get_id() ), true, false );
		$admin = ob_get_clean();

		$this->assertStringContainsString( 'will not renew automatically', $email );
		$this->assertStringContainsString( 'will not renew automatically', $thankyou );
		$this->assertSame( '', $admin );

		$other = $this->create_order( array( array( $product, 1 ) ), $customer );
		ob_start();
		RecurringConsent::render_thankyou_hint( $other->get_id() );
		$this->assertSame( '', ob_get_clean() );
	}

	/** B-113: express payment buttons are hidden for guests when a license is involved. */
	public function test_b113_express_buttons_are_hidden_for_guests(): void {
		$product = $this->create_license_product();
		$plain   = $this->create_simple_product( '5.00' );
		wp_set_current_user( 0 );

		$this->assertTrue( apply_filters( 'wc_stripe_hide_payment_request_on_product_page', false, get_post( $product->get_id() ) ) );
		$this->assertFalse( apply_filters( 'wc_stripe_hide_payment_request_on_product_page', false, get_post( $plain->get_id() ) ) );

		$this->load_cart();
		WC()->cart->add_to_cart( $plain->get_id(), 1 );
		$this->assertTrue( apply_filters( 'wc_stripe_show_payment_request_on_cart', true ) );
		WC()->cart->add_to_cart( $product->get_id(), 1 );
		$this->assertFalse( apply_filters( 'wc_stripe_show_payment_request_on_cart', true ) );
		$this->assertFalse( apply_filters( 'wc_stripe_show_payment_request_on_checkout', true, null ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );
		$this->assertTrue( apply_filters( 'wc_stripe_show_payment_request_on_cart', true ) );
		$this->assertFalse( apply_filters( 'wc_stripe_hide_payment_request_on_product_page', false, get_post( $product->get_id() ) ) );
	}

	/** B-105, A-104, B-21: the ID list for the block checkout comes from the catalog, without a query per request. */
	public function test_b105_a104_license_product_ids_come_from_the_catalog(): void {
		list( $parent, $variations ) = $this->create_variable_product( array( '49.00', '149.00' ) );
		$this->assertTrue( Catalog::save_mapping( $parent->get_id(), 0, $this->mapping_data() ) );

		// A product scheduled for the future is in the list before it goes live.
		$future = $this->create_simple_product();
		wp_update_post( array( 'ID' => $future->get_id(), 'post_status' => 'future', 'post_date' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ), 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) ) );
		$this->assertTrue( Catalog::save_mapping( $future->get_id(), 0, $this->mapping_data( array( 'plan_key' => 'future' ) ) ) );

		$ids = BlockCheckoutConsent::license_product_ids();
		$this->assertContains( $parent->get_id(), $ids );
		$this->assertContains( $variations[0]->get_id(), $ids, 'Variations of a parent-mapped product are what the cart contains.' );
		$this->assertContains( $variations[1]->get_id(), $ids );
		$this->assertContains( $future->get_id(), $ids );

		// A-104: the list is an autoloaded option; reading it again costs no query.
		$this->assertSame( $ids, get_option( Catalog::IDS_OPTION ) );
		$this->assertArrayHasKey( Catalog::IDS_OPTION, wp_load_alloptions() );
		global $wpdb;
		$queries = $wpdb->num_queries;
		$this->assertSame( $ids, BlockCheckoutConsent::license_product_ids() );
		$this->assertSame( $queries, $wpdb->num_queries );

		// B-105: a status change (scheduled publication) drops the cache.
		wp_publish_post( $future->get_id() );
		$this->assertFalse( get_option( Catalog::IDS_OPTION, false ) );

		// A product whose meta is set but that has no plan is not a license product (B-21).
		$stray = $this->create_simple_product();
		update_post_meta( $stray->get_id(), '_wplc_is_license_product', 'yes' );
		$this->assertNotContains( $stray->get_id(), BlockCheckoutConsent::license_product_ids() );
	}
}
