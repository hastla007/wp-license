<?php
/**
 * Public cancellation page [wplc_cancel] and the payment method of the automatic renewal.
 *
 * @package WPLC
 */

use WPLC\Account\CustomerPortal;
use WPLC\Account\PaymentMethods;
use WPLC\Account\PublicCancellation;

require_once __DIR__ . '/helpers/PortalTestCase.php';

final class PublicCancellationIntegrationTest extends WPLC_Portal_TestCase {

	/**
	 * Customer.
	 *
	 * @var int
	 */
	private $customer;

	/**
	 * Account email of the customer.
	 *
	 * @var string
	 */
	private $account_email;

	public function set_up(): void {
		parent::set_up();
		$this->account_email = 'owner-' . strtolower( wp_generate_password( 8, false ) ) . '@example.test';
		$this->customer      = self::factory()->user->create(
			array(
				'role'       => 'customer',
				'user_email' => $this->account_email,
			)
		);
		$_SERVER['REMOTE_ADDR'] = '203.0.113.' . wp_rand( 1, 250 );
	}

	/**
	 * Seed a license with automatic renewal that was bought with an order.
	 *
	 * @param array<string,mixed> $overrides License overrides.
	 * @return array{id:int,key:string,uuid:string,order:WC_Order,prefix:string}
	 */
	private function seed_auto_license( array $overrides = array() ): array {
		$order   = $this->create_order( array( array( $this->create_simple_product( '49.00' ), 1 ) ), $this->customer );
		$license = $this->seed_license(
			array_merge(
				array(
					'customer_id'               => $this->customer,
					'renewal_enabled'           => 1,
					'renewal_amount_minor'      => 4900,
					'renewal_currency'          => 'EUR',
					'renewal_currency_decimals' => 2,
					'source_order_id'           => $order->get_id(),
					'source_order_item_id'      => $this->first_item( $order )->get_id(),
				),
				$overrides
			)
		);
		$license['order']  = wc_get_order( $order->get_id() );
		$license['prefix'] = $this->license( $license['id'] )['key_prefix'];

		return $license;
	}

	/**
	 * Run both form steps like a visitor: fill the form, then press the button on the confirmation page.
	 *
	 * @param string $email     E-mail address.
	 * @param string $reference Reference as typed.
	 * @return array{review:array<string,mixed>,done:array<string,mixed>,html:string}
	 */
	private function cancel_through_the_page( string $email, string $reference ): array {
		$review = PublicCancellation::process( 'review', sanitize_email( $email ), PublicCancellation::normalize_reference( $reference ) );
		$done   = PublicCancellation::process( 'confirm', (string) $review['email'], (string) $review['reference'], '', (int) $review['expires'], (string) $review['signature'] );

		return array(
			'review' => $review,
			'done'   => $done,
		);
	}

	/**
	 * Render the page for a state.
	 *
	 * @param array<string,mixed> $post POST fields, empty for a plain page view.
	 * @return string
	 */
	private function page( array $post = array() ): string {
		$_POST                     = wp_slash( $post );
		$_SERVER['REQUEST_METHOD'] = $post ? 'POST' : 'GET';
		$state                     = new ReflectionProperty( PublicCancellation::class, 'state' );
		$state->setAccessible( true );
		$state->setValue( null, null );
		PublicCancellation::handle_post();
		$html                      = do_shortcode( '[wplc_cancel]' );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST                     = array();
		$state->setValue( null, null );

		return $html;
	}

	// ------------------------------------------------------------------
	// B-40: public cancellation.
	// ------------------------------------------------------------------

	public function test_B40_form_confirmation_page_cancellation_and_confirmation_email(): void {
		$paid_until = gmdate( 'Y-m-d H:i:s', time() + 120 * DAY_IN_SECONDS );
		$license    = $this->seed_auto_license( array( 'paid_until' => $paid_until ) );
		wp_set_current_user( 0 );
		$this->assertTrue( shortcode_exists( 'wplc_cancel' ) );

		// Step 1: the form. No login, no wp-admin, nothing that expires.
		$form = $this->page();
		$this->assertStringContainsString( 'name="wplc_cancel_email"', $form );
		$this->assertStringContainsString( 'name="wplc_cancel_reference"', $form );
		$this->assertStringContainsString( 'name="wplc_cancel_step" value="review"', $form );
		$this->assertStringNotContainsString( 'wp-admin', $form );
		$this->assertStringNotContainsString( '_wpnonce', $form, 'The public form must work from a cached page.' );

		// Step 2: the confirmation page repeats the details and has the cancellation button. Nothing is cancelled yet.
		$review = $this->page(
			array(
				'wplc_cancel_step'      => 'review',
				'wplc_cancel_email'     => $license['order']->get_billing_email(),
				'wplc_cancel_reference' => strtolower( $license['prefix'] ),
			)
		);
		$this->assertStringContainsString( 'Please confirm your cancellation', $review );
		$this->assertStringContainsString( $license['prefix'], $review );
		$this->assertStringContainsString( '>Cancel now</button>', $review );
		$this->assertSame( 0, (int) $this->license( $license['id'] )['cancel_at_period_end'] );
		$this->assertSame( array(), $this->mails() );

		// Step 3: the button.
		$fields = $this->form_fields( $this->find_form( $review, array( 'name="wplc_cancel_step" value="confirm"' ) ) );
		$before = time();
		$done   = $this->page( $fields );
		$this->assertStringContainsString( 'We received your cancellation', $done );
		$this->assertStringContainsString( 'Your cancellation was received on', $done );
		$this->assertSame( 1, (int) $this->license( $license['id'] )['cancel_at_period_end'] );
		$this->assertSame( 'active', $this->license( $license['id'] )['status'], 'The license stays valid until the end of the paid period.' );
		$this->assertSame( array( 'cancellation_scheduled' ), $this->audit_actions( 'license', $license['id'] ) );

		// Confirmation in text form with time stamp and end date.
		$mails = $this->mails();
		$this->assertCount( 1, $mails );
		$this->assertSame( $license['order']->get_billing_email(), $mails[0]['to'] );
		$text = html_entity_decode( wp_strip_all_tags( $mails[0]['body'] ), ENT_QUOTES );
		$this->assertStringContainsString( 'Cancellation received', $text );
		$this->assertTrue(
			false !== strpos( $text, wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $before ) )
			|| false !== strpos( $text, wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), time() ) ),
			'The email states when the cancellation was received.'
		);
		$this->assertStringContainsString( wp_date( get_option( 'date_format' ), strtotime( $paid_until . ' UTC' ) ), $text, 'The email states the end date.' );
	}

	/**
	 * B-40: the references a customer has at hand all work, with the account or the billing e-mail.
	 *
	 * @dataProvider data_references
	 *
	 * @param string $kind  Which reference to type.
	 * @param string $email Which e-mail address to type.
	 */
	public function test_B40_reference_kinds( string $kind, string $email ): void {
		$license = $this->seed_auto_license();
		$typed   = array(
			'prefix'       => $license['prefix'],
			'short prefix' => ' ' . strtolower( substr( $license['prefix'], 5 ) ) . ' ',
			'full key'     => $license['key'],
			'order number' => '#' . $license['order']->get_id(),
		);
		$address = 'billing' === $email ? strtoupper( $license['order']->get_billing_email() ) : $this->account_email;

		$result = $this->cancel_through_the_page( $address, $typed[ $kind ] );

		$this->assertSame( 'done', $result['done']['view'] );
		$this->assertSame( 1, (int) $this->license( $license['id'] )['cancel_at_period_end'], $kind . ' / ' . $email );
		$this->assertCount( 1, $this->mails() );
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public function data_references(): array {
		return array(
			'license reference, billing e-mail' => array( 'prefix', 'billing' ),
			'reference without WPLC-, account'  => array( 'short prefix', 'account' ),
			'full license key, account'         => array( 'full key', 'account' ),
			'order number, billing e-mail'      => array( 'order number', 'billing' ),
		);
	}

	public function test_B40_unknown_combinations_get_the_same_answer_and_change_nothing(): void {
		$license = $this->seed_auto_license();
		$other   = $this->seed_auto_license();
		$billing = $license['order']->get_billing_email();

		$fields = static function ( array $state ): array {
			return array(
				'wplc_cancel_step'      => 'confirm',
				'wplc_cancel_email'     => $state['email'],
				'wplc_cancel_reference' => $state['reference'],
				'wplc_cancel_expires'   => $state['expires'],
				'wplc_cancel_signature' => $state['signature'],
			);
		};
		$normalize = static function ( string $html ): string {
			// The only parts that may differ are the echoed details and the time.
			return (string) preg_replace( '/received on [^<]*\./', 'received on X.', $html );
		};

		$attempts = array(
			'wrong e-mail'             => array( 'stranger@example.test', $license['prefix'] ),
			'wrong reference'          => array( $billing, 'WPLC-00000000' ),
			'reference of another one' => array( 'stranger@example.test', (string) $other['order']->get_id() ),
			'nonsense'                 => array( $billing, 'not a reference' ),
		);
		$answers  = array();
		foreach ( $attempts as $label => $attempt ) {
			$_SERVER['REMOTE_ADDR'] = '198.51.100.' . ( count( $answers ) + 10 );
			$review                 = PublicCancellation::process( 'review', $attempt[0], PublicCancellation::normalize_reference( $attempt[1] ) );
			$this->assertSame( 'review', $review['view'], $label . ': the confirmation page does not depend on whether the details match.' );
			$answers[ $label ] = $normalize( $this->page( $fields( $review ) ) );
		}
		$this->assertSame( 0, (int) $this->license( $license['id'] )['cancel_at_period_end'] );
		$this->assertSame( 0, (int) $this->license( $other['id'] )['cancel_at_period_end'] );
		$this->assertSame( array(), $this->mails(), 'No email for a combination that does not match.' );

		// The matching combination gets exactly the same page.
		$_SERVER['REMOTE_ADDR'] = '198.51.100.99';
		$review                 = PublicCancellation::process( 'review', $billing, $license['prefix'] );
		$real                   = $normalize( $this->page( $fields( $review ) ) );
		$this->assertSame( 1, (int) $this->license( $license['id'] )['cancel_at_period_end'] );
		foreach ( $answers as $label => $answer ) {
			$this->assertSame( $real, $answer, 'The answer for "' . $label . '" differs from the answer for a real cancellation.' );
		}
	}

	public function test_B40_only_licenses_with_automatic_renewal_are_touched(): void {
		$auto    = $this->seed_auto_license();
		$email   = $auto['order']->get_billing_email();
		$manual  = $this->seed_auto_license( array( 'renewal_enabled' => 0 ) );
		$expired = $this->seed_auto_license( array( 'status' => 'expired' ) );
		$already = $this->seed_auto_license( array( 'cancel_at_period_end' => 1 ) );

		foreach ( array( $manual, $expired, $already ) as $index => $license ) {
			$_SERVER['REMOTE_ADDR'] = '198.51.100.' . ( 120 + $index );
			$this->cancel_through_the_page( $license['order']->get_billing_email(), $license['prefix'] );
			$this->assertSame( array(), $this->audit_actions( 'license', $license['id'] ) );
		}
		$this->assertSame( array(), $this->mails() );
		$this->assertSame( 0, (int) $this->license( $auto['id'] )['cancel_at_period_end'] );
		unset( $email );
	}

	public function test_B40_confirmation_step_needs_the_signature_of_the_confirmation_page(): void {
		$license = $this->seed_auto_license();
		$email   = $license['order']->get_billing_email();
		$review  = PublicCancellation::process( 'review', $email, $license['prefix'] );

		$forged  = PublicCancellation::process( 'confirm', $email, $license['prefix'], '', (int) $review['expires'], 'forged' );
		$expired = PublicCancellation::process( 'confirm', $email, $license['prefix'], '', time() - 10, (string) $review['signature'] );
		$other   = PublicCancellation::process( 'confirm', $email, 'WPLC-FFFFFFFF', '', (int) $review['expires'], (string) $review['signature'] );

		foreach ( array( $forged, $expired, $other ) as $state ) {
			$this->assertSame( 'form', $state['view'] );
			$this->assertNotSame( '', $state['error'] );
		}
		$this->assertSame( 0, (int) $this->license( $license['id'] )['cancel_at_period_end'], 'A direct POST to the last step cancels nothing.' );

		// A filled trap field (bot) leads to a confirmation page that cannot be confirmed.
		$bot = PublicCancellation::process( 'review', $email, $license['prefix'], 'https://spam.example' );
		$this->assertSame( 'review', $bot['view'] );
		$this->assertSame( 'form', PublicCancellation::process( 'confirm', $email, $license['prefix'], '', (int) $bot['expires'], (string) $bot['signature'] )['view'] );
		$this->assertSame( 0, (int) $this->license( $license['id'] )['cancel_at_period_end'] );
	}

	public function test_B40_invalid_input_returns_to_the_form(): void {
		foreach ( array( array( 'not-an-email', 'WPLC-12345678' ), array( 'a@example.test', '' ) ) as $input ) {
			$state = PublicCancellation::process( 'review', sanitize_email( $input[0] ), PublicCancellation::normalize_reference( $input[1] ) );
			$this->assertSame( 'form', $state['view'] );
			$this->assertNotSame( '', $state['error'] );
		}
		$html = $this->page(
			array(
				'wplc_cancel_step'      => 'review',
				'wplc_cancel_email'     => 'not-an-email',
				'wplc_cancel_reference' => 'x',
			)
		);
		$this->assertStringContainsString( 'role="alert"', $html );
		$this->assertStringContainsString( 'name="wplc_cancel_step" value="review"', $html );
	}

	public function test_B40_requests_are_rate_limited_per_client_and_per_address(): void {
		$license = $this->seed_auto_license();
		$email   = $license['order']->get_billing_email();
		$limit   = PublicCancellation::DEFAULT_HOURLY_LIMIT;

		// One client guessing references for many addresses.
		$_SERVER['REMOTE_ADDR'] = '192.0.2.77';
		for ( $attempt = 1; $attempt <= $limit; ++$attempt ) {
			$result = $this->cancel_through_the_page( 'guess' . $attempt . '@example.test', 'WPLC-0000000' . $attempt );
			$this->assertSame( 'done', $result['done']['view'], 'Attempt ' . $attempt . ' is within the limit.' );
		}
		$blocked = $this->cancel_through_the_page( $email, $license['prefix'] );
		$this->assertSame( 'limited', $blocked['done']['view'] );
		$this->assertSame( 0, (int) $this->license( $license['id'] )['cancel_at_period_end'], 'Over the limit even a correct combination is not processed.' );
		$this->assertSame( array(), $this->mails() );

		// Many clients trying one address.
		$victim = 'victim-' . strtolower( wp_generate_password( 6, false ) ) . '@example.test';
		for ( $attempt = 1; $attempt <= $limit; ++$attempt ) {
			$_SERVER['REMOTE_ADDR'] = '192.0.2.' . ( 100 + $attempt );
			$this->assertSame( 'done', $this->cancel_through_the_page( $victim, 'WPLC-1111111' . $attempt )['done']['view'] );
		}
		$_SERVER['REMOTE_ADDR'] = '192.0.2.200';
		$this->assertSame( 'limited', $this->cancel_through_the_page( $victim, 'WPLC-22222222' )['done']['view'] );

		// Another client and address are not affected.
		$_SERVER['REMOTE_ADDR'] = '192.0.2.201';
		$this->assertSame( 'done', $this->cancel_through_the_page( $email, $license['prefix'] )['done']['view'] );
		$this->assertSame( 1, (int) $this->license( $license['id'] )['cancel_at_period_end'] );
	}

	// ------------------------------------------------------------------
	// B-20 / B-43: payment method of the automatic renewal.
	// ------------------------------------------------------------------

	public function test_B20_customer_can_choose_another_saved_payment_method(): void {
		$first   = $this->create_saved_card( $this->customer, '4242' );
		$second  = $this->create_saved_card( $this->customer, '1881' );
		$foreign = $this->create_saved_card( self::factory()->user->create(), '0005' );
		$license = $this->seed_auto_license( array( 'payment_gateway_id' => 'wplc_test_gateway', 'payment_token_id' => $first ) );

		$html = $this->render_portal( $this->customer );
		$this->assertStringContainsString( 'ending in 4242', $html, 'The current payment method is named.' );
		$form = $this->find_form( $html, array( 'value="wplc_set_payment_method"' ) );
		$this->assertNotSame( '', $form );
		$this->assertStringContainsString( '<option value="' . $second . '">', $form );
		$this->assertStringNotContainsString( '<option value="' . $first . '">', $form, 'Only other methods are offered.' );
		$this->assertStringNotContainsString( '<option value="' . $foreign . '">', $form );
		$this->assertStringNotContainsString( 'admin-post.php', $form );

		$result = $this->front_post( array_merge( $this->form_fields( $form ), array( 'payment_token_id' => $second ) ) );
		$this->assertRedirectWithNotice( 'payment_method_saved', $result );
		$this->assertSame( $second, (int) $this->license( $license['id'] )['payment_token_id'] );
		$this->assertSame( array( 'payment_method_changed' ), $this->audit_actions( 'license', $license['id'] ) );

		// A token of another customer, or of nobody, is refused.
		wc_clear_notices();
		$result = $this->front_post( array_merge( $this->form_fields( $form ), array( 'payment_token_id' => $foreign ) ) );
		$this->assertRedirectWithNotice( 'payment_method_failed', $result );
		$this->assertSame( $second, (int) $this->license( $license['id'] )['payment_token_id'] );
		$this->assertFalse( PaymentMethods::set_token( $license['id'], 999999, $this->customer ) );
		$this->assertFalse( PaymentMethods::set_token( $license['id'], $first, self::factory()->user->create() ), 'Only the owner changes the payment method.' );
		$this->assertSame( array( 'payment_method_changed' ), $this->audit_actions( 'license', $license['id'] ) );
	}

	public function test_B20_automatic_renewal_can_be_switched_on_later_with_explicit_consent(): void {
		$token   = $this->create_saved_card( $this->customer, '4242' );
		$license = $this->seed_auto_license( array( 'renewal_enabled' => 0 ) );

		$html = $this->render_portal( $this->customer );
		$form = $this->find_form( $html, array( 'value="wplc_enable_renewal"' ) );
		$this->assertNotSame( '', $form, 'The form to switch automatic renewal on is missing.' );
		$this->assertStringContainsString( 'name="wplc_renewal_consent"', $form );
		$this->assertStringNotContainsString( 'checked', $form, 'The consent is never preselected.' );
		$this->assertStringContainsString( '49.00', $form, 'The consent names the amount.' );
		$this->assertStringContainsString( 'every year', $form );

		// Without the consent nothing happens.
		$fields = array_merge( $this->form_fields( $form ), array( 'payment_token_id' => $token ) );
		unset( $fields['wplc_renewal_consent'] );
		$this->assertRedirectWithNotice( 'renewal_consent_missing', $this->front_post( $fields ) );
		$this->assertSame( 0, (int) $this->license( $license['id'] )['renewal_enabled'] );

		wc_clear_notices();
		$result = $this->front_post( array_merge( $fields, array( 'wplc_renewal_consent' => '1' ) ) );
		$this->assertRedirectWithNotice( 'renewal_enabled', $result );
		$row = $this->license( $license['id'] );
		$this->assertSame( 1, (int) $row['renewal_enabled'] );
		$this->assertSame( $token, (int) $row['payment_token_id'] );
		$this->assertSame( 'wplc_test_gateway', $row['payment_gateway_id'] );
		$this->assertSame( $row['paid_until'], $row['next_payment_at'] );
		$this->assertSame( array( 'renewal_enabled' ), $this->audit_actions( 'license', $license['id'] ) );

		// The consent evidence is in the audit entry.
		global $wpdb;
		$after = json_decode( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT after_json FROM %i WHERE object_id = %d AND action = %s', $this->tables()['audit_log'], $license['id'], 'renewal_enabled' ) ), true );
		$this->assertSame( 4900, $after['amount_minor'] );
		$this->assertSame( 'year', $after['interval'] );
		$this->assertSame( 64, strlen( $after['consent_text_sha256'] ) );

		// The next period is queued for the automatic charge.
		$renewal = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE license_id = %d', $this->tables()['renewals'], $license['id'] ), ARRAY_A );
		$this->assertIsArray( $renewal );
		$this->assertSame( 'scheduled', $renewal['status'] );

		// Not twice, and not for an ended or cancelled license.
		$this->assertFalse( PaymentMethods::enable_renewal( $license['id'], $token, $this->customer ) );
		$ended = $this->seed_auto_license( array( 'renewal_enabled' => 0, 'paid_until' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) );
		$this->assertFalse( PaymentMethods::enable_renewal( $ended['id'], $token, $this->customer ) );
	}

	public function test_B20_no_form_without_a_usable_payment_method(): void {
		$this->seed_auto_license( array( 'renewal_enabled' => 0 ) );
		$html = $this->render_portal( $this->customer );

		$this->assertStringNotContainsString( 'wplc_enable_renewal', $html );
		$this->assertStringNotContainsString( 'wplc_set_payment_method', $html );
	}

	public function test_B43_deleting_the_saved_payment_method_switches_automatic_renewal_off(): void {
		$token     = $this->create_saved_card( $this->customer, '4242' );
		$other     = $this->create_saved_card( $this->customer, '1881' );
		$affected  = $this->seed_auto_license( array( 'payment_gateway_id' => 'wplc_test_gateway', 'payment_token_id' => $token ) );
		$unrelated = $this->seed_auto_license( array( 'payment_gateway_id' => 'wplc_test_gateway', 'payment_token_id' => $other ) );

		// B-57: only the customer's own request counts, not a gateway that re-creates its token rows.
		wp_set_current_user( $this->customer );
		$GLOBALS['wp']->query_vars['delete-payment-method'] = (string) $token;
		WC_Payment_Tokens::delete( $token );
		unset( $GLOBALS['wp']->query_vars['delete-payment-method'] );

		$row = $this->license( $affected['id'] );
		$this->assertSame( 0, (int) $row['renewal_enabled'], 'Without the payment method the license must not run into failed charges.' );
		$this->assertSame( 0, (int) $row['payment_token_id'] );
		$this->assertSame( 'active', $row['status'], 'The license stays valid until the end of the paid period.' );
		$this->assertSame( array( 'renewal_disabled' ), $this->audit_actions( 'license', $affected['id'] ) );
		$this->assertSame( 1, (int) $this->license( $unrelated['id'] )['renewal_enabled'] );
		$this->assertSame( array(), $this->audit_actions( 'license', $unrelated['id'] ) );

		// The customer who deleted the card is told on the spot.
		$notices = function_exists( 'wc_get_notices' ) && WC()->session ? wp_json_encode( wc_get_notices() ) : '';
		if ( '' !== $notices ) {
			$this->assertStringContainsString( 'Automatic renewal is now switched off', $notices );
		}

		// The account says so and offers to switch it on again with the remaining card.
		$html = $this->render_portal( $this->customer );
		$this->assertStringContainsString( 'Does not renew automatically.', $html );
		$this->assertNotSame( '', $this->find_form( $html, array( 'value="wplc_enable_renewal"', 'name="license_id" value="' . $affected['id'] . '"' ) ) );
	}
}
