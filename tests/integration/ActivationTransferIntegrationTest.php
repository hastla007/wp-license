<?php
/**
 * A-22: a customer moves sites from an ended license to a new one, and the
 * cart offers "Renew instead" to customers who already own a license.
 *
 * @package WPLC
 */

use WPLC\Account\CustomerPortal;
use WPLC\Billing\RenewInsteadNotice;
use WPLC\License\ActivationTransfer;

require_once __DIR__ . '/helpers/PortalTestCase.php';

final class ActivationTransferIntegrationTest extends WPLC_Portal_TestCase {

	/**
	 * Customer.
	 *
	 * @var int
	 */
	private $customer;

	public function set_up(): void {
		parent::set_up();
		$this->customer = self::factory()->user->create( array( 'role' => 'customer' ) );
		RenewInsteadNotice::reset();
	}

	public function tear_down(): void {
		remove_all_filters( 'wplc_renew_instead_notice' );
		RenewInsteadNotice::reset();
		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}
		parent::tear_down();
	}

	/**
	 * An expired license and a new one of the same product for the customer.
	 *
	 * @return array{0:array{id:int,key:string,uuid:string},1:array{id:int,key:string,uuid:string}}
	 */
	private function old_and_new_license(): array {
		$past = gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS );
		$old  = $this->seed_license( array( 'customer_id' => $this->customer, 'status' => 'expired', 'paid_until' => $past, 'next_payment_at' => null ) );
		$new  = $this->seed_license( array( 'customer_id' => $this->customer, 'paid_until' => gmdate( 'Y-m-d H:i:s', time() + 365 * DAY_IN_SECONDS ) ) );

		return array( $old, $new );
	}

	public function test_a22_customer_moves_a_site_and_the_installation_keeps_working(): void {
		list( $old, $new ) = $this->old_and_new_license();
		$live    = $this->seed_activation( $old['id'], array( 'site_url' => 'https://shop.example.test' ) );
		$staging = $this->seed_activation( $old['id'], array( 'site_url' => 'https://staging.shop.example.test', 'environment' => 'staging', 'staging_parent_id' => $live['id'] ) );

		// Before: the installation reports the expired license.
		$before = $this->dispatch( $this->api_request( 'POST', '/wplc/v1/entitlements/check', array(), $live ) )->get_data();
		$this->assertFalse( $before['data']['access'] );

		// The account offers one button per movable site; the staging site moves with its live site.
		$html = $this->render_portal( $this->customer );
		$form = $this->find_form( $html, array( 'value="wplc_transfer_site"', 'name="activation_id" value="' . $live['id'] . '"' ) );
		$this->assertNotSame( '', $form, 'The expired license offers to move its live site.' );
		$this->assertStringContainsString( 'name="target_license_id" value="' . $new['id'] . '"', $form );
		$this->assertSame( '', $this->find_form( $html, array( 'value="wplc_transfer_site"', 'name="activation_id" value="' . $staging['id'] . '"' ) ) );

		$result = $this->front_post( $this->form_fields( $form ) );
		$this->assertRedirectWithNotice( 'site_transferred', $result );
		$this->assertSame( $new['id'], (int) $this->activation( $live['id'] )['license_id'] );
		$this->assertSame( $new['id'], (int) $this->activation( $staging['id'] )['license_id'], 'The staging site moved with its live site.' );
		$this->assertSame( 'active', $this->activation( $live['id'] )['status'] );
		$this->assertSame( $live['uuid'], $this->activation( $live['id'] )['installation_uuid'], 'Installation ID and secret stay.' );

		// After: the same credentials now answer for the new license.
		$after = $this->dispatch( $this->api_request( 'POST', '/wplc/v1/entitlements/check', array(), $live ) )->get_data();
		$this->assertTrue( $after['data']['access'] );
		$this->assertSame( 1, $after['data']['site_count'] );
		$this->assertSame( 'active', $after['data']['license_status'] );

		// Both licenses and the activations are audited, with the customer as actor.
		$this->assertContains( 'site_transferred_out', $this->audit_actions( 'license', $old['id'] ) );
		$this->assertContains( 'site_transferred_in', $this->audit_actions( 'license', $new['id'] ) );
		$this->assertContains( 'site_transferred', $this->audit_actions( 'activation', $live['id'] ) );
		$this->assertContains( 'site_transferred', $this->audit_actions( 'activation', $staging['id'] ) );

		// The new license is full now (one live site): a second site cannot follow.
		$second = $this->seed_activation( $old['id'], array( 'site_url' => 'https://second.example.test' ) );
		$this->assertSame( 'limit', ActivationTransfer::move( $second['id'], $new['id'], $this->customer ) );
		$this->assertSame( $old['id'], (int) $this->activation( $second['id'] )['license_id'] );
	}

	public function test_a22_moves_are_refused_outside_the_rules(): void {
		list( $old, $new ) = $this->old_and_new_license();
		$site     = $this->seed_activation( $old['id'], array( 'site_url' => 'https://shop.example.test' ) );
		$stranger = self::factory()->user->create( array( 'role' => 'customer' ) );
		$foreign  = $this->seed_license( array( 'customer_id' => $stranger, 'paid_until' => gmdate( 'Y-m-d H:i:s', time() + 365 * DAY_IN_SECONDS ) ) );
		$other    = $this->seed_license( array( 'customer_id' => $this->customer, 'product_key' => 'other-product', 'paid_until' => gmdate( 'Y-m-d H:i:s', time() + 365 * DAY_IN_SECONDS ) ) );

		$this->assertSame( 'not_found', ActivationTransfer::move( $site['id'], $new['id'], $stranger ), 'Only the owner moves a site.' );
		$this->assertSame( 'not_allowed', ActivationTransfer::move( $site['id'], $foreign['id'], $this->customer ), 'Not to a license of someone else.' );
		$this->assertSame( 'not_allowed', ActivationTransfer::move( $site['id'], $other['id'], $this->customer ), 'Not to another product.' );
		$this->assertSame( 'not_allowed', ActivationTransfer::move( $site['id'], $old['id'], $this->customer ) );

		// A license that runs on with manual renewal far ahead keeps its sites.
		$running = $this->seed_license( array( 'customer_id' => $this->customer, 'paid_until' => gmdate( 'Y-m-d H:i:s', time() + 200 * DAY_IN_SECONDS ) ) );
		$keep    = $this->seed_activation( $running['id'] );
		$this->assertSame( 'not_allowed', ActivationTransfer::move( $keep['id'], $new['id'], $this->customer ) );

		// The site already runs on the new license (activated with the new key).
		$this->seed_activation( $new['id'], array( 'site_url' => 'https://www.shop.example.test' ) );
		$this->assertSame( 'same_site', ActivationTransfer::move( $site['id'], $new['id'], $this->customer ) );

		// A staging site alone is not moved.
		$staging = $this->seed_activation( $old['id'], array( 'environment' => 'staging', 'staging_parent_id' => $site['id'], 'site_url' => 'https://staging.shop.example.test' ) );
		$this->assertSame( 'not_movable', ActivationTransfer::move( $staging['id'], $new['id'], $this->customer ) );

		// An expired form token changes nothing.
		wp_set_current_user( $this->customer );
		$result = $this->front_post(
			array(
				CustomerPortal::FORM_MARKER => '1',
				'action'                    => 'wplc_transfer_site',
				'activation_id'             => $site['id'],
				'target_license_id'         => $new['id'],
				'_wpnonce'                  => 'expired',
			)
		);
		$this->assertRedirectWithNotice( 'session_expired', $result );
		$this->assertSame( $old['id'], (int) $this->activation( $site['id'] )['license_id'] );
		$this->assertSame( array(), $this->audit_actions( 'license', $new['id'] ) );
	}

	public function test_a22_cart_offers_renew_instead_with_a_working_link_and_can_be_dismissed(): void {
		global $wpdb;
		$product = $this->create_license_product();
		$order   = $this->create_paid_order( array( array( $product, 1 ) ), $this->customer );
		$license = $this->licenses_for_order( $order->get_id() )[0];
		$soon    = gmdate( 'Y-m-d H:i:s', time() + 10 * DAY_IN_SECONDS );
		$wpdb->update( $this->tables()['licenses'], array( 'paid_until' => $soon, 'next_payment_at' => $soon ), array( 'id' => (int) $license['id'] ) );

		wp_set_current_user( $this->customer );
		if ( ! WC()->cart ) {
			WC()->frontend_includes();
			wc_load_cart();
		}
		WC()->cart->empty_cart();
		$this->assertSame( array(), RenewInsteadNotice::notices_for_cart(), 'Nothing without a license product in the cart.' );
		WC()->cart->add_to_cart( $product->get_id(), 1 );

		$notices = RenewInsteadNotice::notices_for_cart();
		$this->assertCount( 1, $notices );
		$this->assertSame( (int) $license['id'], $notices[0]['license_id'] );
		$this->assertStringContainsString( 'renew the existing license instead', $notices[0]['message'] );
		$this->assertNotSame( '', $notices[0]['renew_url'] );

		ob_start();
		RenewInsteadNotice::print_notices();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Renew instead', $html );
		$this->assertStringContainsString( 'Dismiss', $html );
		// The block checkout gets the same notice once.
		RenewInsteadNotice::reset();
		$block = RenewInsteadNotice::prepend_to_block( '<div class="checkout"></div>', array( 'blockName' => 'woocommerce/checkout' ) );
		$this->assertStringStartsWith( '<div class="woocommerce-info wplc-renew-notice"', $block );
		$this->assertSame( '<p>x</p>', RenewInsteadNotice::prepend_to_block( '<p>x</p>', array( 'blockName' => 'woocommerce/cart' ) ), 'Printed only once per request.' );
		$this->assertSame( '<p>x</p>', RenewInsteadNotice::prepend_to_block( '<p>x</p>', array( 'blockName' => 'core/paragraph' ) ) );

		// The link starts the manual renewal of that license (same handler as "Renew now").
		$query = array();
		parse_str( (string) wp_parse_url( html_entity_decode( $notices[0]['renew_url'] ), PHP_URL_QUERY ), $query );
		$this->assertSame( 'wplc_start_renewal', $query['action'] );
		$this->halt_instead_of_exit();
		$_GET     = $query;
		$_REQUEST = $query;
		try {
			do_action( 'admin_post_wplc_start_renewal' );
			$this->fail( 'Expected a redirect.' );
		} catch ( WPLC_Test_Halt $halt ) {
			$this->assertStringContainsString( 'order-pay', $halt->location );
		} finally {
			$_GET     = array();
			$_REQUEST = array();
		}

		// Filterable.
		add_filter( 'wplc_renew_instead_notice', '__return_false' );
		$this->assertSame( array(), RenewInsteadNotice::notices_for_cart() );
		remove_all_filters( 'wplc_renew_instead_notice' );

		// Dismissable per license: a forged link does nothing, the real one hides the notice.
		$_GET = array( RenewInsteadNotice::DISMISS_ARG => (string) $license['id'], '_wpnonce' => 'forged' );
		try {
			RenewInsteadNotice::handle_dismiss();
			$this->fail( 'Expected a redirect.' );
		} catch ( WPLC_Test_Halt $halt ) {
			$this->assertStringNotContainsString( RenewInsteadNotice::DISMISS_ARG, $halt->location );
		}
		$this->assertCount( 1, RenewInsteadNotice::notices_for_cart() );
		parse_str( (string) wp_parse_url( html_entity_decode( $notices[0]['dismiss_url'] ), PHP_URL_QUERY ), $_GET );
		try {
			RenewInsteadNotice::handle_dismiss();
			$this->fail( 'Expected a redirect.' );
		} catch ( WPLC_Test_Halt $halt ) {
			$this->assertStringNotContainsString( '_wpnonce', $halt->location );
		} finally {
			$_GET = array();
		}
		$this->assertSame( array(), RenewInsteadNotice::notices_for_cart() );
		$this->assertArrayHasKey( (int) $license['id'], RenewInsteadNotice::dismissed( $this->customer, time() ) );
		$this->assertSame( array(), RenewInsteadNotice::dismissed( $this->customer, time() + ( RenewInsteadNotice::DISMISS_DAYS + 1 ) * DAY_IN_SECONDS ), 'A dismissal expires.' );
	}

	public function test_a22_notice_rules_for_expired_auto_renewing_and_old_licenses(): void {
		$now     = time();
		$expired = $this->seed_license( array( 'customer_id' => $this->customer, 'status' => 'expired', 'paid_until' => gmdate( 'Y-m-d H:i:s', $now - 5 * DAY_IN_SECONDS ) ) );
		$notices = RenewInsteadNotice::notices_for( $this->customer, array( 'test-product' ), $now );
		$this->assertCount( 1, $notices, 'A recently expired license.' );
		$this->assertSame( $expired['id'], $notices[0]['license_id'] );
		$this->assertStringContainsString( 'expired on', $notices[0]['message'] );

		$this->assertSame( array(), RenewInsteadNotice::notices_for( $this->customer, array( 'test-product' ), $now + 60 * DAY_IN_SECONDS ), 'Not once the renewal window closed.' );
		$this->assertSame( array(), RenewInsteadNotice::notices_for( $this->customer, array( 'other-product' ), $now ) );
		$this->assertSame( array(), RenewInsteadNotice::notices_for( 0, array( 'test-product' ), $now ), 'Guests see nothing.' );

		// An automatically renewing license: no renewal link, but the hint and a link to the account.
		$auto    = $this->seed_license( array( 'customer_id' => $this->customer, 'product_key' => 'auto-product', 'renewal_enabled' => 1, 'paid_until' => gmdate( 'Y-m-d H:i:s', $now + 10 * DAY_IN_SECONDS ) ) );
		$notices = RenewInsteadNotice::notices_for( $this->customer, array( 'auto-product' ), $now );
		$this->assertCount( 1, $notices );
		$this->assertSame( $auto['id'], $notices[0]['license_id'] );
		$this->assertSame( '', $notices[0]['renew_url'] );
		$this->assertStringContainsString( 'renews automatically', $notices[0]['message'] );
	}
}
