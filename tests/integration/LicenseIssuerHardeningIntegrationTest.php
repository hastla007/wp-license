<?php
/**
 * P-20 (WooCommerce Subscriptions renewals), P-62 (verified key in the
 * follow-up email) and P-63 (CSPRNG license UUIDs).
 *
 * @package WPLC
 */

use WPLC\Admin\SubscriptionsNotice;
use WPLC\Admin\SupportActions;
use WPLC\License\LicenseIssuer;
use WPLC\License\LicenseKey;

if ( ! function_exists( 'wcs_order_contains_renewal' ) ) {
	/**
	 * Test double of WooCommerce Subscriptions: an order is a renewal when the test marks it.
	 *
	 * @param WC_Order|int $order Order.
	 * @return bool
	 */
	function wcs_order_contains_renewal( $order ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
		return $order instanceof WC_Order && '1' === (string) $order->get_meta( '_wplc_test_wcs_renewal', true );
	}
}

final class LicenseIssuerHardeningIntegrationTest extends WPLC_Integration_TestCase {

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

	public function test_p20_subscriptions_renewal_order_issues_no_second_license(): void {
		$customer = self::factory()->user->create();
		$product  = $this->create_license_product();
		$original = $this->create_paid_order( array( array( $product, 1 ) ), $customer );
		$this->assertCount( 1, $this->licenses_for_order( $original->get_id() ) );
		$count = $this->license_count();

		// A renewal order created by WooCommerce Subscriptions: a new order with the same product.
		$renewal = $this->create_order( array( array( $product, 1 ) ), $customer );
		$renewal->update_meta_data( '_wplc_test_wcs_renewal', '1' );
		$renewal->save();
		$this->assertTrue( LicenseIssuer::is_subscriptions_renewal( $renewal ) );
		$this->assertTrue( $renewal->payment_complete( 'txn_wcs_renewal' ) );
		LicenseIssuer::maybe_issue_order( $renewal->get_id() );

		$this->assertCount( 0, $this->licenses_for_order( $renewal->get_id() ) );
		$this->assertSame( $count, $this->license_count() );
		$this->assertSame( '', (string) wc_get_order( $renewal->get_id() )->get_meta( LicenseIssuer::META_PROBLEM, true ) );

		// A normal order of the same shop still issues.
		$plain = $this->create_paid_order( array( array( $product, 1 ) ), $customer );
		$this->assertFalse( LicenseIssuer::is_subscriptions_renewal( $plain ) );
		$this->assertCount( 1, $this->licenses_for_order( $plain->get_id() ) );
	}

	public function test_p20_admin_notice_names_subscription_products(): void {
		$this->assertTrue( SubscriptionsNotice::subscriptions_active(), 'The test double makes WooCommerce Subscriptions look active.' );
		$info = SubscriptionsNotice::message( array() );
		$this->assertStringContainsString( 'notice-info', $info );
		$this->assertStringContainsString( 'WooCommerce Subscriptions is active', $info );

		$error = SubscriptionsNotice::message( array( 12, 34 ) );
		$this->assertStringContainsString( 'notice-error', $error );
		$this->assertStringContainsString( '2 license products are subscription products (IDs 12, 34)', $error );
		$this->assertStringContainsString( '1 license product is a subscription product (ID 12)', SubscriptionsNotice::message( array( 12 ) ) );

		// Simple products are not subscription products.
		$this->assertSame( array(), SubscriptionsNotice::subscription_products( array( $this->create_license_product()->get_id() ) ) );
	}

	public function test_p62_followup_does_not_mail_a_key_that_fails_the_stored_hash(): void {
		$customer = self::factory()->user->create( array( 'user_email' => 'p62@example.test' ) );
		$seeded   = $this->seed_license( array( 'customer_id' => $customer ) );
		global $wpdb;
		// The stored verifier no longer matches what the configured secret derives (wrong key ID, renamed entry).
		$wpdb->update( $this->tables()['licenses'], array( 'license_key_hash' => hash( 'sha256', 'something else' ) ), array( 'id' => $seeded['id'] ) );
		$derived = LicenseKey::for_uuid( $seeded['uuid'], 'integration-2026' );
		$this->assertNotSame( '', $derived );

		LicenseIssuer::run_followup( $seeded['id'] );

		foreach ( $this->mails as $mail ) {
			$this->assertStringNotContainsString( $derived, (string) $mail['message'], 'An unverified key must never be mailed.' );
		}
		$this->assertSame( array(), $this->mails );

		// With a matching verifier the email goes out.
		$wpdb->update( $this->tables()['licenses'], array( 'license_key_hash' => LicenseKey::hash( $seeded['key'] ) ), array( 'id' => $seeded['id'] ) );
		LicenseIssuer::run_followup( $seeded['id'] );
		$this->assertNotEmpty( $this->mails );
	}

	public function test_p63_issued_and_reissued_uuids_are_random_version_4(): void {
		$pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';
		$seen    = array();
		for ( $i = 0; $i < 200; $i++ ) {
			$uuid = LicenseIssuer::new_uuid();
			$this->assertMatchesRegularExpression( $pattern, $uuid );
			$seen[ $uuid ] = true;
		}
		$this->assertCount( 200, $seen );

		// wp_generate_uuid4() (mt_rand) is no longer used: seeding mt_rand does not repeat UUIDs.
		mt_srand( 42 );
		$first = LicenseIssuer::new_uuid();
		mt_srand( 42 );
		$this->assertNotSame( $first, LicenseIssuer::new_uuid() );

		$customer = self::factory()->user->create();
		$order    = $this->create_paid_order( array( array( $this->create_license_product(), 1 ) ), $customer );
		$license  = $this->licenses_for_order( $order->get_id() )[0];
		$this->assertMatchesRegularExpression( $pattern, (string) $license['license_uuid'] );

		$this->assertTrue( SupportActions::reissue_key( (int) $license['id'], 'Leaked', 1 ) );
		$this->assertMatchesRegularExpression( $pattern, (string) $this->license( (int) $license['id'] )['license_uuid'] );
		mt_srand();
	}
}
