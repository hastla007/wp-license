<?php
/**
 * Checkout consents, order confirmations, visible item meta and storefront blocks.
 *
 * Every test names the task it belongs to (docs/TODO.md: A-118, B-39, B-42, B-114, B-117).
 *
 * @package WPLC
 */

use WPLC\Account\Blocks;
use WPLC\Billing\BlockCheckoutConsent;
use WPLC\Billing\RecurringConsent;
use WPLC\Billing\WithdrawalConsent;
use WPLC\License\LicenseIssuer;
use WPLC\Update\ReleaseRegistry;

final class CheckoutLegalIntegrationTest extends WPLC_Integration_TestCase {

	public function tear_down(): void {
		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function cart_with( WC_Product $product ): void {
		if ( ! WC()->cart ) {
			WC()->frontend_includes();
			wc_load_cart();
		}
		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $product->get_id(), 1 );
	}

	private function store_api_post( array $fields ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
		$request->set_param( 'additional_fields', $fields );
		return $request;
	}

	// ------------------------------------------------------------------
	// A-118: the classic checkout keeps the boxes ticked across refreshes.
	// ------------------------------------------------------------------

	/** A-118: a refresh of the payment section (update_order_review) renders the ticked box ticked again. */
	public function test_a118_checkbox_state_survives_update_order_review(): void {
		$this->cart_with( $this->create_license_product() );

		$this->assertFalse( RecurringConsent::checkbox_posted( WithdrawalConsent::FIELD_NAME ) );
		ob_start();
		WithdrawalConsent::render_checkbox();
		$fresh = (string) ob_get_clean();
		$this->assertStringContainsString( 'name="' . WithdrawalConsent::FIELD_NAME . '"', $fresh );
		$this->assertStringNotContainsString( 'checked', $fresh, 'Never preselected.' );

		// What WooCommerce's checkout script sends on update_order_review.
		$_POST['post_data'] = wp_slash( http_build_query( array( 'billing_email' => 'a@example.test', WithdrawalConsent::FIELD_NAME => '1', 'wplc_recurring_consent' => '1' ) ) );
		$this->assertTrue( RecurringConsent::checkbox_posted( WithdrawalConsent::FIELD_NAME ) );
		$this->assertTrue( RecurringConsent::checkbox_posted( 'wplc_recurring_consent' ) );
		ob_start();
		WithdrawalConsent::render_checkbox();
		$this->assertStringContainsString( "checked='checked'", (string) ob_get_clean() );

		// Unticked in the form: stays unticked; odd values never count.
		$_POST['post_data'] = 'billing_email=a%40example.test&wplc_recurring_consent[]=1';
		$this->assertFalse( RecurringConsent::checkbox_posted( 'wplc_recurring_consent' ) );
		$this->assertFalse( RecurringConsent::checkbox_posted( WithdrawalConsent::FIELD_NAME ) );
	}

	// ------------------------------------------------------------------
	// B-39: consent to the immediate start (withdrawal right).
	// ------------------------------------------------------------------

	/** B-39: the classic checkout refuses a license without the consent and records it with time and wording. */
	public function test_b39_classic_checkout_requires_and_records_the_consent(): void {
		$product = $this->create_license_product();
		$this->cart_with( $product );

		$errors = new WP_Error();
		WithdrawalConsent::validate_classic( array(), $errors );
		$this->assertSame( array( 'wplc_withdrawal_consent' ), $errors->get_error_codes() );

		$_POST[ WithdrawalConsent::FIELD_NAME ] = '1';
		$errors = new WP_Error();
		WithdrawalConsent::validate_classic( array(), $errors );
		$this->assertFalse( $errors->has_errors() );

		$customer = self::factory()->user->create( array( 'role' => 'customer' ) );
		$order    = $this->create_order( array( array( $product, 1 ) ), $customer );
		WithdrawalConsent::save_classic( $order, array() );
		$order->save();
		$order = wc_get_order( $order->get_id() );

		$at = (string) $order->get_meta( WithdrawalConsent::META_CONSENT_AT, true );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $at );
		$evidence = $order->get_meta( WithdrawalConsent::META_EVIDENCE, true );
		$this->assertSame( WithdrawalConsent::label(), $evidence['text'] );
		$this->assertSame( hash( 'sha256', $evidence['text'] ), $evidence['hash'] );
		$this->assertSame( 'classic', $evidence['source'] );
		$this->assertSame( determine_locale(), $evidence['locale'] );

		// Confirmation on a durable medium: customer emails and the order received page, not admin emails.
		ob_start();
		do_action( 'woocommerce_email_after_order_table', $order, false, true, null );
		$email = (string) ob_get_clean();
		$this->assertStringContainsString( substr( $at, 0, 16 ), $email );
		$this->assertStringContainsString( 'lose my right of withdrawal', $email );
		ob_start();
		WithdrawalConsent::render_email_confirmation( $order, true, false );
		$this->assertSame( '', (string) ob_get_clean() );
		ob_start();
		do_action( 'woocommerce_thankyou', $order->get_id() );
		$this->assertStringContainsString( 'wplc-withdrawal-consent-confirmation', (string) ob_get_clean() );

		// The personal data export names the consent of the license's order.
		$order->payment_complete( 'txn_b39' );
		$this->assertCount( 1, $this->licenses_for_order( $order->get_id() ) );
		$exported = array();
		foreach ( \WPLC\Privacy\PersonalData::export_personal_data( get_userdata( $customer )->user_email, 1 )['data'] as $group ) {
			foreach ( $group['data'] as $pair ) {
				$exported[ $pair['name'] ] = $pair['value'];
			}
		}
		$this->assertSame( $at, $exported['Consent to the immediate start (withdrawal right) given at (UTC)'] ?? null );
		// P3-A5: also the wording and the language, not only the time.
		$this->assertSame( $evidence['text'], $exported['Text of the consent to the immediate start'] ?? null );
		$this->assertStringContainsString( 'lose my right of withdrawal', (string) ( $exported['Text of the consent to the immediate start'] ?? '' ) );
		$this->assertSame( $evidence['locale'], $exported['Language of the consent to the immediate start'] ?? null );

		// A cart without a license is not asked.
		$this->cart_with( $this->create_simple_product( '5.00' ) );
		unset( $_POST[ WithdrawalConsent::FIELD_NAME ] );
		$errors = new WP_Error();
		WithdrawalConsent::validate_classic( array(), $errors );
		$this->assertFalse( $errors->has_errors() );
		ob_start();
		WithdrawalConsent::render_checkbox();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	/** B-39: the block checkout records the field and refuses a license order without it before the payment. */
	public function test_b39_block_checkout_requires_and_records_the_consent(): void {
		$customer = self::factory()->user->create( array( 'role' => 'customer' ) );
		$order    = $this->create_order( array( array( $this->create_license_product(), 1 ) ), $customer );

		WithdrawalConsent::save_block( $order, $this->store_api_post( array( WithdrawalConsent::FIELD_ID => false ) ) );
		$thrown = null;
		try {
			WithdrawalConsent::validate_block_order( $order );
		} catch ( \Throwable $error ) {
			$thrown = $error;
		}
		$this->assertNotNull( $thrown, 'A license order without the consent must not be paid.' );
		$this->assertStringContainsString( 'immediate start', $thrown->getMessage() );

		WithdrawalConsent::save_block( $order, $this->store_api_post( array( WithdrawalConsent::FIELD_ID => true ) ) );
		WithdrawalConsent::validate_block_order( $order );
		$this->assertSame( 'block', $order->get_meta( WithdrawalConsent::META_EVIDENCE, true )['source'] );

		// The draft order is reused: unticking removes the earlier consent.
		WithdrawalConsent::save_block( $order, $this->store_api_post( array() ) );
		$this->assertSame( '', (string) $order->get_meta( WithdrawalConsent::META_CONSENT_AT, true ) );

		// Orders without a license are never refused.
		$plain = $this->create_order( array( array( $this->create_simple_product( '5.00' ), 1 ) ), $customer );
		WithdrawalConsent::validate_block_order( $plain );
		$this->assertSame( '', (string) $plain->get_meta( WithdrawalConsent::META_CONSENT_AT, true ) );
	}

	/** B-39: the block field is shown and required only for carts with a license (WooCommerce 9.9+; optional and enforced at payment before). */
	public function test_b39_block_field_is_registered_with_a_visibility_rule(): void {
		$product = $this->create_license_product();
		$fields  = \Automattic\WooCommerce\Blocks\Package::container()->get( \Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields::class );
		WithdrawalConsent::register_field();
		try {
			$field = $fields->get_additional_fields()[ WithdrawalConsent::FIELD_ID ] ?? null;
			$this->assertIsArray( $field, 'WooCommerce accepted the field.' );
			$this->assertSame( 'checkbox', $field['type'] );
			$this->assertFalse( $field['show_in_order_confirmation'] );
			if ( \WPLC\Compatibility\Requirements::supports_conditional_checkout_fields() ) {
				$this->assertTrue( $field['required'] );
				$this->assertContains( $product->get_id(), $field['hidden']['cart']['properties']['items']['not']['contains']['enum'] );
			} else {
				// Before WooCommerce 9.9 the field can't be hidden: optional for WooCommerce, enforced by validate_block_order().
				$this->assertFalse( $field['required'] );
				$this->assertArrayNotHasKey( 'cart', (array) ( $field['hidden'] ?? array() ) );
			}
		} finally {
			$fields->deregister_checkout_field( WithdrawalConsent::FIELD_ID );
		}
	}

	/** B-39: shops with their own legal plugin (or another wording/optional box) decide through filters. */
	public function test_b39_filters_switch_off_the_box_make_it_optional_and_change_the_wording(): void {
		$this->assertFalse( WithdrawalConsent::legal_plugin_active(), 'Neither Germanized nor German Market is loaded in the tests.' );
		$this->assertTrue( WithdrawalConsent::enabled() );

		$product = $this->create_license_product();
		$this->cart_with( $product );

		add_filter( 'wplc_withdrawal_consent_text', static function () {
			return 'Owner wording.';
		} );
		$this->assertSame( 'Owner wording.', WithdrawalConsent::label() );

		add_filter( 'wplc_withdrawal_consent_required', '__return_false' );
		$errors = new WP_Error();
		WithdrawalConsent::validate_classic( array(), $errors );
		$this->assertFalse( $errors->has_errors(), 'Optional: the purchase goes through and the customer keeps the right of withdrawal.' );

		add_filter( 'wplc_withdrawal_consent_enabled', '__return_false' );
		ob_start();
		WithdrawalConsent::render_checkbox();
		$this->assertSame( '', (string) ob_get_clean() );
		$order = $this->create_order( array( array( $product, 1 ) ), self::factory()->user->create( array( 'role' => 'customer' ) ) );
		WithdrawalConsent::validate_block_order( $order );
		$this->assertSame( '', (string) $order->get_meta( WithdrawalConsent::META_CONSENT_AT, true ) );
	}

	// ------------------------------------------------------------------
	// B-42: first orders show license and service period on the line.
	// ------------------------------------------------------------------

	/** B-42: a first purchase carries license reference and service period as visible, translated item meta. */
	public function test_b42_first_order_line_shows_license_and_service_period(): void {
		global $wpdb;
		$customer = self::factory()->user->create( array( 'role' => 'customer' ) );
		$order    = $this->create_paid_order( array( array( $this->create_license_product(), 2 ) ), $customer );
		$licenses = $this->licenses_for_order( $order->get_id() );
		$this->assertCount( 2, $licenses );

		$item = $this->first_item( wc_get_order( $order->get_id() ) );
		$this->assertSame(
			sprintf( '#%d %s, #%d %s', $licenses[0]['id'], $licenses[0]['key_prefix'], $licenses[1]['id'], $licenses[1]['key_prefix'] ),
			$item->get_meta( LicenseIssuer::ITEM_META_LICENSE, true )
		);
		$period = substr( (string) $licenses[0]['starts_at'], 0, 10 ) . ' – ' . substr( (string) $licenses[0]['paid_until'], 0, 10 );
		$this->assertSame( $period, $item->get_meta( LicenseIssuer::ITEM_META_PERIOD, true ) );

		// What emails, the order screen and invoice plugins print.
		$shown = array();
		foreach ( $item->get_formatted_meta_data() as $meta ) {
			$shown[ wp_strip_all_tags( $meta->display_key ) ] = wp_strip_all_tags( $meta->display_value );
		}
		$this->assertSame( $period, trim( $shown['Service period (UTC)'] ?? '' ) );
		$this->assertStringContainsString( (string) $licenses[0]['key_prefix'], $shown['License'] ?? '' );
		$this->assertArrayNotHasKey( '_wplc_license_ids', $shown );

		// A later renewal moves paid_until; the invoice line of the first purchase keeps its period.
		$t = $this->tables();
		$wpdb->update( $t['licenses'], array( 'paid_until' => '2099-01-01 00:00:00' ), array( 'id' => $licenses[0]['id'] ) );
		LicenseIssuer::describe_item( $item, wp_list_pluck( $licenses, 'id' ) );
		$this->assertSame( $period, $item->get_meta( LicenseIssuer::ITEM_META_PERIOD, true ) );
	}

	// ------------------------------------------------------------------
	// B-114: short confirmations instead of the long label.
	// ------------------------------------------------------------------

	/** B-114: a granted opt-in is confirmed in one short line with the date, for both checkouts. */
	public function test_b114_granted_opt_in_is_confirmed_briefly(): void {
		$customer = self::factory()->user->create( array( 'role' => 'customer' ) );
		$order    = $this->create_order( array( array( $this->create_license_product(), 1 ) ), $customer );
		$this->assertSame( '', RecurringConsent::granted_text( $order ) );

		RecurringConsent::apply_decision( $order, 'granted', 'block' );
		$order->save();
		$at   = (string) $order->get_meta( RecurringConsent::META_CONSENT_AT, true );
		$text = RecurringConsent::granted_text( $order );
		$this->assertStringStartsWith( RecurringConsent::short_label() . ':', $text );
		$this->assertStringContainsString( substr( $at, 0, 16 ), $text );
		$this->assertStringNotContainsString( RecurringConsent::label(), $text );

		ob_start();
		RecurringConsent::render_thankyou_hint( $order->get_id() );
		$this->assertStringContainsString( 'wplc-renewal-granted', (string) ob_get_clean() );

		// The order emails confirm the choice with the renewal terms (Notifications), not with the long label.
		ob_start();
		do_action( 'woocommerce_email_after_order_table', wc_get_order( $order->get_id() ), false, false, null );
		$email = (string) ob_get_clean();
		$this->assertStringContainsString( 'You chose automatic renewal', $email );
		$this->assertStringNotContainsString( RecurringConsent::label(), $email );
	}

	/** B-114: without a visibility rule (WooCommerce before 9.9) the shop can drop the block opt-in. */
	public function test_b114_block_opt_in_without_rule_follows_the_filter(): void {
		$this->assertTrue( BlockCheckoutConsent::offer_without_rule( true ) );
		$this->assertTrue( BlockCheckoutConsent::offer_without_rule( false ) );
		add_filter( 'wplc_block_checkout_consent_without_rule', '__return_false' );
		$this->assertFalse( BlockCheckoutConsent::offer_without_rule( false ) );
		$this->assertTrue( BlockCheckoutConsent::offer_without_rule( true ), 'With a rule the field is always offered.' );
	}

	// ------------------------------------------------------------------
	// B-117: block "My licenses" and the changelog.
	// ------------------------------------------------------------------

	/** B-117: both blocks are registered server-side; "My licenses" renders the shortcode output. */
	public function test_b117_blocks_are_registered_and_render_on_the_server(): void {
		$registry = WP_Block_Type_Registry::get_instance();
		$this->assertTrue( $registry->is_registered( 'wplc/licenses' ) );
		$this->assertTrue( $registry->is_registered( 'wplc/changelog' ) );
		$this->assertTrue( wp_script_is( 'wplc-blocks', 'registered' ) );

		wp_set_current_user( 0 );
		$html = do_blocks( '<!-- wp:wplc/licenses /-->' );
		$this->assertStringContainsString( 'Please sign in to view your licenses.', $html );
	}

	/** B-117: the changelog lists published stable versions only, highest first. */
	public function test_b117_changelog_shortcode_lists_published_stable_versions(): void {
		global $wpdb;
		$t   = $this->tables();
		$now = gmdate( 'Y-m-d H:i:s' );
		$ids = array();
		$this->create_license_product( array( 'product_key' => 'changelog-product' ) );
		foreach ( array( array( '1.0.0', 'stable', 'published', 'First <strong>release</strong><script>x</script>' ), array( '1.2.0', 'stable', 'published', 'Second release' ), array( '1.3.0-beta1', 'beta', 'published', 'Beta notes' ), array( '1.4.0', 'stable', 'draft', 'Draft notes' ) ) as $row ) {
			$id = ReleaseRegistry::create(
				array(
					'product_key' => 'changelog-product',
					'version' => $row[0],
					'channel' => $row[1],
					'storage' => 'url',
					'package_url' => 'https://example.org/changelog-product-' . $row[0] . '.zip',
					'sha256' => str_repeat( 'a', 64 ),
					'release_notes' => $row[3],
				)
			);
			$this->assertIsInt( $id );
			if ( 'published' === $row[2] ) {
				// Publishing would download the package; the changelog only reads the status.
				$wpdb->update( $t['releases'], array( 'status' => 'published', 'published_at' => $now ), array( 'id' => $id ) );
			}
			$ids[] = $id;
		}
		ReleaseRegistry::flush_cache( 'changelog-product', 'beta' );

		$html = do_shortcode( '[wplc_changelog product="changelog-product"]' );
		$this->assertStringContainsString( 'Second release', $html );
		$this->assertStringContainsString( '<strong>release</strong>', $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( 'Beta notes', $html );
		$this->assertStringNotContainsString( 'Draft notes', $html );
		$this->assertLessThan( strpos( $html, '1.0.0' ), strpos( $html, '1.2.0' ), 'Highest version first.' );

		$this->assertStringNotContainsString( '1.0.0', do_shortcode( '[wplc_changelog product="changelog-product" limit="1"]' ) );
		$this->assertSame( '', do_shortcode( '[wplc_changelog product="unknown-product"]' ) );
		$this->assertSame( '', do_shortcode( '[wplc_changelog]' ) );
		$this->assertStringContainsString( 'Second release', Blocks::render_changelog_block( array( 'product' => 'changelog-product' ) ) );
	}
}
