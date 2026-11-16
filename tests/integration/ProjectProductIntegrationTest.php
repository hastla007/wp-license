<?php
/**
 * Project products (decision 17, docs/TODO.md section 15): a WooCommerce product
 * that is not for sale, licensed by hand.
 *
 * PK-02: a private product with an active plan is offered in "Add license
 * manually" and by `wp wplc license issue`, but nowhere in the shop front.
 * PK-05 (term part): the manual license is extended with "Change term", and the
 * expiry reminder names no purchase or renewal link for it.
 *
 * @package WPLC
 */

use WPLC\Account\PricingTable;
use WPLC\Billing\ManualRenewal;
use WPLC\Admin\LicenseAdmin;
use WPLC\Catalog\Catalog;
use WPLC\CLI\LicenseCommand;
use WPLC\Notifications\Notifications;

require_once __DIR__ . '/helpers/PortalTestCase.php';

final class ProjectProductIntegrationTest extends WPLC_Portal_TestCase {

	/**
	 * Administrator.
	 *
	 * @var int
	 */
	private $admin;

	/**
	 * Client of the project.
	 *
	 * @var int
	 */
	private $client;

	/**
	 * Any other customer of the shop.
	 *
	 * @var int
	 */
	private $stranger;

	public function set_up(): void {
		parent::set_up();
		$this->admin    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->client   = self::factory()->user->create(
			array(
				'role'       => 'customer',
				'user_email' => 'project-client-' . wp_generate_password( 6, false ) . '@example.test',
			)
		);
		$this->stranger = self::factory()->user->create( array( 'role' => 'customer' ) );
		delete_option( 'wplc_reminder_cursor' );
	}

	public function tear_down(): void {
		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * A private WooCommerce product with an active license plan.
	 *
	 * @return array{product:WC_Product_Simple,plan_id:int}
	 */
	private function project_product(): array {
		global $wpdb;
		$product = new WC_Product_Simple();
		$product->set_name( 'Harbour Booking Connector' );
		$product->set_status( 'private' );
		$product->set_regular_price( '0' );
		$product->set_virtual( true );
		$product->save();
		$this->assertTrue(
			Catalog::save_mapping(
				$product->get_id(),
				0,
				$this->mapping_data(
					array(
						'product_key'  => 'harbour-connector',
						'plan_key'     => 'project',
						'entitlements' => array( 'harbour.sync' => true ),
					)
				)
			),
			Catalog::last_error()
		);
		$plan_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->tables()['plans']} WHERE woocommerce_product_id = %d AND active = 1", $product->get_id() ) );
		$this->assertGreaterThan( 0, $plan_id, 'A private product keeps an active plan.' );

		return array(
			'product' => $product,
			'plan_id' => $plan_id,
		);
	}

	/**
	 * Rendered "Add license manually" form.
	 *
	 * @return string
	 */
	private function add_form(): string {
		wp_set_current_user( $this->admin );
		$_GET = array(
			'page' => 'wplc-licenses',
			'view' => 'add',
		);
		ob_start();
		try {
			LicenseAdmin::render();
		} finally {
			$html = (string) ob_get_clean();
			$_GET = array();
		}

		return $html;
	}

	/**
	 * Issue a license through the admin form.
	 *
	 * @param int    $plan_id Plan ID.
	 * @param string $date    Local end date.
	 * @return int License ID.
	 */
	private function issue_in_admin( int $plan_id, string $date ): int {
		wp_set_current_user( $this->admin );
		$result = $this->run_admin_post(
			'wplc_admin_add_license',
			array(
				'customer'   => (string) $this->client,
				'plan_id'    => $plan_id,
				'paid_until' => $date,
				'reason'     => 'Project contract',
				'skip_email' => '1',
				'_wpnonce'   => wp_create_nonce( 'wplc_admin_add_license' ),
			)
		);
		$this->assertStringContainsString( 'wplc_notice=license_created', $result['location'] );
		parse_str( (string) wp_parse_url( $result['location'], PHP_URL_QUERY ), $query );

		return (int) $query['license'];
	}

	/**
	 * Load an empty cart.
	 *
	 * @return void
	 */
	private function load_cart(): void {
		if ( ! WC()->cart ) {
			WC()->frontend_includes();
			wc_load_cart();
		}
		WC()->cart->empty_cart();
	}

	/**
	 * Local date in N days, as the date fields expect it.
	 *
	 * @param int $days Days from today.
	 * @return string
	 */
	private function local_date( int $days ): string {
		return ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( '+' . $days . ' days' )->format( 'Y-m-d' );
	}

	// ------------------------------------------------------------------
	// PK-02: option (b), a private WooCommerce product.
	// ------------------------------------------------------------------

	public function test_pk02_private_product_is_licensed_by_hand_but_never_offered_in_the_shop(): void {
		$project = $this->project_product();
		$public  = $this->create_license_product( array( 'product_key' => 'harbour-public', 'plan_key' => 'single' ) );

		// Offered in "Add license manually".
		$form = $this->add_form();
		$this->assertStringContainsString( '<option value="' . $project['plan_id'] . '"', $form );
		$this->assertStringContainsString( 'Harbour Booking Connector', $form );

		// Not in the pricing table, neither of its own product key nor of another one.
		wp_set_current_user( 0 );
		$this->assertSame( '', PricingTable::html( 'harbour-connector' ), 'The pricing table of the project product is empty.' );
		$this->assertStringNotContainsString( 'Harbour Booking Connector', do_shortcode( '[wplc_pricing product="harbour-connector"]' ) );
		$this->assertStringNotContainsString( 'add-to-cart=' . $project['product']->get_id(), PricingTable::html( 'harbour-public' ) );
		$this->assertStringContainsString( 'add-to-cart=' . $public->get_id(), PricingTable::html( 'harbour-public' ) );
		// Not even for the administrator, who could otherwise buy a private product.
		wp_set_current_user( $this->admin );
		$this->assertSame( '', PricingTable::html( 'harbour-connector' ) );
		$this->assertFalse( Catalog::is_for_sale( $project['product'] ) );
		$this->assertTrue( Catalog::is_for_sale( $public ) );

		// The license is issued by hand.
		$license_id = $this->issue_in_admin( $project['plan_id'], $this->local_date( 365 ) );
		$license    = $this->license( $license_id );
		$this->assertSame( array( 'harbour-connector', 'project', 'active', (string) $this->client, '0' ), array( $license['product_key'], $license['plan_key'], $license['status'], (string) $license['customer_id'], (string) $license['renewal_enabled'] ) );
		$this->assertSame( (string) $project['product']->get_id(), (string) $license['renewal_product_id'] );

		// The client sees it in their account, nobody else does.
		$this->assertStringContainsString( 'Harbour Booking Connector', $this->render_portal( $this->client ) );
		$other = $this->render_portal( $this->stranger );
		$this->assertStringNotContainsString( 'Harbour Booking Connector', $other );
		$this->assertStringNotContainsString( 'harbour-connector', $other );

		// Another customer cannot put the product into the cart (and neither can a guest).
		$this->load_cart();
		wp_set_current_user( $this->stranger );
		$this->assertFalse( wc_get_product( $project['product']->get_id() )->is_purchasable() );
		$this->assertFalse( WC()->cart->add_to_cart( $project['product']->get_id(), 1 ) );
		$this->assertSame( 0, WC()->cart->get_cart_contents_count() );
		wp_set_current_user( 0 );
		$this->assertFalse( WC()->cart->add_to_cart( $project['product']->get_id(), 1 ) );
		$this->assertSame( 0, WC()->cart->get_cart_contents_count() );
		// The public product still can.
		$this->assertNotFalse( WC()->cart->add_to_cart( $public->get_id(), 1 ) );
	}

	public function test_pk02_cli_issues_a_license_for_a_project_product(): void {
		$project = $this->project_product();
		$email   = (string) get_userdata( $this->client )->user_email;
		$before  = $this->license_count();

		// Refusals: no customer, no plan, both plan options, a past date.
		$this->assertSame( 'unknown_customer', LicenseCommand::issue_request( array( 'customer' => 'nobody@example.test', 'product-id' => $project['product']->get_id(), 'expires' => $this->local_date( 30 ) ) )->get_error_code() );
		$this->assertSame( 'plan_missing', LicenseCommand::issue_request( array( 'customer' => $email, 'expires' => $this->local_date( 30 ) ) )->get_error_code() );
		$this->assertSame( 'plan_missing', LicenseCommand::issue_request( array( 'customer' => $email, 'product-id' => 1, 'plan-id' => 1, 'expires' => $this->local_date( 30 ) ) )->get_error_code() );
		$this->assertSame( 'unknown_plan', LicenseCommand::issue_request( array( 'customer' => $email, 'product-id' => $project['product']->get_id(), 'plan' => 'other', 'expires' => $this->local_date( 30 ) ) )->get_error_code() );
		$this->assertSame( 'invalid_date', LicenseCommand::issue_request( array( 'customer' => $email, 'product-id' => $project['product']->get_id(), 'expires' => '2020-01-01' ) )->get_error_code() );
		$this->assertSame( 'invalid_date', LicenseCommand::issue_request( array( 'customer' => $email, 'product-id' => $project['product']->get_id(), 'expires' => 'tomorrow' ) )->get_error_code() );

		// By product ID and email address, without the key email.
		$request = LicenseCommand::issue_request(
			array(
				'customer'   => $email,
				'product-id' => (string) $project['product']->get_id(),
				'expires'    => $this->local_date( 365 ),
				'reason'     => 'Project contract 2027',
				'skip-email' => true,
			)
		);
		$this->assertIsArray( $request );
		$this->assertSame( array( $this->client, $project['plan_id'], false ), array( $request['customer_id'], (int) $request['plan']['id'], $request['send_email'] ) );
		$license_id = LicenseCommand::run_issue( $request, 0 );
		$this->assertIsInt( $license_id );
		$license = $this->license( $license_id );
		$this->assertSame( array( 'harbour-connector', 'project', (string) $this->client ), array( $license['product_key'], $license['plan_key'], (string) $license['customer_id'] ) );
		$this->assertSame( array(), $this->mails(), '--skip-email sends nothing.' );
		$this->assertTrue( has_action( 'wplc/license_issued', array( 'WPLC\\Notifications\\Notifications', 'on_license_issued' ) ) !== false, 'The email hook is restored.' );
		$this->assertContains( 'license_issued_manually', $this->audit_actions( 'license', $license_id ) );

		// By plan ID and user ID, with the key email.
		$second = LicenseCommand::run_issue( LicenseCommand::issue_request( array( 'customer' => (string) $this->client, 'plan-id' => $project['plan_id'], 'expires' => $this->local_date( 30 ) ) ), 0 );
		$this->assertIsInt( $second );
		$this->assertSame( $before + 2, $this->license_count() );
		$this->assertCount( 1, $this->mails() );
		$this->assertSame( $email, $this->mails()[0]['to'] );
	}

	// ------------------------------------------------------------------
	// PK-05: term of a manual license, reminders without a purchase link.
	// ------------------------------------------------------------------

	public function test_pk05_manual_license_is_extended_with_change_term(): void {
		$project    = $this->project_product();
		$license_id = $this->issue_in_admin( $project['plan_id'], $this->local_date( 20 ) );
		$before     = $this->license( $license_id )['paid_until'];

		wp_set_current_user( $this->admin );
		$result = $this->run_admin_post(
			'wplc_admin_extend_license',
			array(
				'license_id'          => $license_id,
				'paid_until'          => $this->local_date( 385 ),
				'expected_paid_until' => $before,
				'reason'              => 'Project extended by one year',
				'_wpnonce'            => wp_create_nonce( 'wplc_admin_extend_license_' . $license_id ),
			)
		);
		$this->assertStringContainsString( 'wplc_notice=term_extended', $result['location'] );
		$after = $this->license( $license_id );
		$this->assertGreaterThan( $before, $after['paid_until'] );
		$this->assertSame( 'active', $after['status'] );
		$this->assertSame( '0', (string) $after['renewal_enabled'], 'Still no automatic renewal.' );
		$this->assertGreaterThanOrEqual( strtotime( '+384 days' ), strtotime( $after['paid_until'] . ' UTC' ) );
		$this->assertContains( 'admin_extend', $this->audit_actions( 'license', $license_id ) );
	}

	public function test_pk05_reminder_for_a_product_that_is_not_for_sale_names_no_renewal_link(): void {
		global $wpdb;
		$project = $this->project_product();
		$public  = $this->create_license_product( array( 'product_key' => 'harbour-public' ) );
		$public_plan = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->tables()['plans']} WHERE woocommerce_product_id = %d", $public->get_id() ) );

		$project_license = $this->issue_in_admin( $project['plan_id'], $this->local_date( 5 ) );
		$public_license  = $this->issue_in_admin( $public_plan, $this->local_date( 5 ) );
		$this->assertSame( '', Notifications::view( $this->license( $project_license ) )['sold_in_shop'] );
		$this->assertSame( '1', Notifications::view( $this->license( $public_license ) )['sold_in_shop'] );

		reset_phpmailer_instance();
		Notifications::send_due_reminders();
		$mails = $this->mails();
		$this->assertCount( 2, $mails );
		$project_mail = '';
		$public_mail  = '';
		foreach ( $mails as $mail ) {
			if ( false !== strpos( $mail['subject'], 'Harbour Booking Connector' ) ) {
				$project_mail = $mail['body'];
			} else {
				$public_mail = $mail['body'];
			}
		}
		$this->assertNotSame( '', $project_mail );
		$this->assertNotSame( '', $public_mail );

		// The project product: no purchase, no "Renew now", but who to ask.
		$this->assertStringContainsString( 'Please contact us before that date', $project_mail );
		$this->assertStringNotContainsString( 'Renew now', $project_mail );
		$this->assertStringNotContainsString( 'renew the license in your account', $project_mail );
		$this->assertStringNotContainsString( 'Renew it in time', $project_mail );
		$this->assertStringNotContainsString( 'add-to-cart', $project_mail );
		$this->assertStringNotContainsString( (string) get_permalink( $project['product']->get_id() ), $project_mail );
		$this->assertStringContainsString( 'View license in your account', $project_mail );

		// A product that is for sale keeps the renewal wording.
		$this->assertStringContainsString( 'Renew now', $public_mail );
		$this->assertStringContainsString( 'renew the license in your account', $public_mail );

		// The plain-text version follows the same rule.
		$email = $this->email_object( 'expiry_reminder' );
		$plain = wc_get_template_html(
			'emails/plain/wplc-expiry-reminder.php',
			array(
				'view'               => Notifications::view( $this->license( $project_license ), array( 'days_left' => 5 ) ),
				'email_heading'      => 'Heading',
				'additional_content' => '',
				'sender_note'        => '',
				'email'              => $email,
			),
			'wp-license/',
			WPLC_PATH . 'templates/'
		);
		$this->assertStringContainsString( 'Please contact us before that date', $plain );
		$this->assertStringNotContainsString( 'Renew now', $plain );
	}

	public function test_pk05_customer_renewal_of_a_product_not_for_sale_is_off_by_default(): void {
		global $wpdb;
		$project     = $this->project_product();
		$public      = $this->create_license_product( array( 'product_key' => 'harbour-public' ) );
		$public_plan = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->tables()['plans']} WHERE woocommerce_product_id = %d", $public->get_id() ) );

		$project_license = $this->issue_in_admin( $project['plan_id'], $this->local_date( 5 ) );
		$public_license  = $this->issue_in_admin( $public_plan, $this->local_date( 5 ) );
		$customer        = (int) $this->license( $project_license )['customer_id'];
		$this->assertTrue( ManualRenewal::in_window( $this->license( $project_license ), time() ), 'Fixture: both licenses are in the renewal window.' );

		// Default (owner decision of 16 Nov 2026): no "Renew now" and no renewal order for the product that is not for sale.
		$this->assertTrue( ManualRenewal::blocked_as_not_for_sale( $this->license( $project_license ) ) );
		$this->assertFalse( ManualRenewal::can_renew( $this->license( $project_license ) ) );
		$refused = ManualRenewal::start( $project_license, $customer );
		$this->assertWPError( $refused );
		$this->assertSame( 'manual_renewal_unavailable', $refused->get_error_code() );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->tables()['renewals']} WHERE license_id = %d", $project_license ) ), 'No renewal row was created.' );

		// A product that is for sale is never affected.
		$this->assertFalse( ManualRenewal::blocked_as_not_for_sale( $this->license( $public_license ) ) );
		$this->assertTrue( ManualRenewal::can_renew( $this->license( $public_license ) ) );

		// The filter allows the customer renewal again.
		add_filter( 'wplc_manual_renewal_for_unsold_products', '__return_true' );
		try {
			$this->assertFalse( ManualRenewal::blocked_as_not_for_sale( $this->license( $project_license ) ) );
			$this->assertTrue( ManualRenewal::can_renew( $this->license( $project_license ) ) );
		} finally {
			remove_filter( 'wplc_manual_renewal_for_unsold_products', '__return_true' );
		}
	}

	public function test_cli_issue_names_why_a_license_was_not_issued(): void {
		$project = $this->project_product();
		$request = LicenseCommand::issue_request( array( 'customer' => (string) $this->client, 'plan-id' => $project['plan_id'], 'expires' => $this->local_date( 30 ), 'skip-email' => true ) );
		$this->assertIsArray( $request );

		// Refused input: the generic text, and the issuer says why.
		$refused = LicenseCommand::run_issue( array_merge( $request, array( 'reason' => '' ) ), 0 );
		$this->assertWPError( $refused );
		$this->assertSame( \WPLC\License\LicenseIssuer::FAILED_INPUT, \WPLC\License\LicenseIssuer::last_failure() );
		$this->assertSame( 'The license could not be issued.', $refused->get_error_message() );

		// A success clears the reason.
		$this->assertIsInt( LicenseCommand::run_issue( $request, 0 ) );
		$this->assertSame( '', \WPLC\License\LicenseIssuer::last_failure() );

		// The key ID typo and a failed insert get their own text, without the key ID's value or any secret.
		$key_id = LicenseCommand::issue_failure_message( \WPLC\License\LicenseIssuer::FAILED_KEY_ID );
		$this->assertStringContainsString( 'WPLC_LICENSE_KEY_ID', $key_id );
		$this->assertStringContainsString( 'WPLC_LICENSE_KEY_SECRETS', $key_id );
		$this->assertStringContainsString( 'database', LicenseCommand::issue_failure_message( \WPLC\License\LicenseIssuer::FAILED_STORE ) );
		$this->assertSame( 'The license could not be issued.', LicenseCommand::issue_failure_message( 'anything else' ) );
	}
}
