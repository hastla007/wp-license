<?php
/**
 * License emails and reminders: content, recipient, language, send-once bookkeeping.
 *
 * Emails are captured by the mailer of the WordPress test suite; nothing is
 * opened in a mail program.
 *
 * @package WPLC
 */

use WPLC\License\LicenseLifecycle;
use WPLC\Notifications\Notifications;
use WPLC\Notifications\PurchaseLocale;
use WPLC\Notifications\ReminderLog;

require_once __DIR__ . '/helpers/PortalTestCase.php';

final class LicenseEmailIntegrationTest extends WPLC_Portal_TestCase {

	/**
	 * Customer.
	 *
	 * @var int
	 */
	private $customer;

	public function set_up(): void {
		parent::set_up();
		$this->customer = self::factory()->user->create(
			array(
				'role'       => 'customer',
				'user_email' => 'account-' . wp_generate_password( 6, false ) . '@example.test',
			)
		);
		delete_option( 'wplc_reminder_cursor' );
	}

	/**
	 * Emails whose subject contains a text.
	 *
	 * @param string $needle Text.
	 * @return array<int,array{to:string,subject:string,body:string}>
	 */
	private function mails_with( string $needle ): array {
		return array_values(
			array_filter(
				$this->mails(),
				static function ( $mail ) use ( $needle ) {
					return false !== strpos( $mail['subject'], $needle );
				}
			)
		);
	}

	/**
	 * Order with one line of a fresh product, owned by the test customer.
	 *
	 * @param string $country Billing country.
	 * @return WC_Order
	 */
	private function order( string $country = 'DE' ): WC_Order {
		$order = $this->create_order( array( array( $this->create_simple_product( '49.00' ), 1 ) ), $this->customer );
		$order->set_billing_country( $country );
		$order->save();

		return wc_get_order( $order->get_id() );
	}

	/**
	 * Seed a license bought with an order; optional automatic renewal.
	 *
	 * @param array<string,mixed> $overrides License overrides.
	 * @param bool                $auto      Whether it renews automatically.
	 * @return array{id:int,key:string,uuid:string,order:WC_Order}
	 */
	private function seed_ordered_license( array $overrides = array(), bool $auto = false ): array {
		$order   = $this->order();
		$license = $this->seed_license(
			array_merge(
				array(
					'customer_id'               => $this->customer,
					'renewal_enabled'           => $auto ? 1 : 0,
					'renewal_amount_minor'      => 4900,
					'renewal_currency'          => 'EUR',
					'renewal_currency_decimals' => 2,
					'source_order_id'           => $order->get_id(),
					'source_order_item_id'      => $this->first_item( $order )->get_id(),
				),
				$overrides
			)
		);
		$license['order'] = $order;

		return $license;
	}

	/**
	 * Taxes on, 19 % for Germany, prices entered net.
	 *
	 * @return void
	 */
	private function enable_german_vat(): void {
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_prices_include_tax', 'no' );
		update_option( 'woocommerce_tax_based_on', 'billing' );
		WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => 'DE',
				'tax_rate_state'    => '',
				'tax_rate'          => '19.0000',
				'tax_rate_name'     => 'VAT',
				'tax_rate_priority' => 1,
				'tax_rate_compound' => 0,
				'tax_rate_shipping' => 1,
				'tax_rate_order'    => 1,
				'tax_rate_class'    => '',
			)
		);
	}

	/**
	 * Status of a reminder log row, or an empty string when there is none.
	 *
	 * @param int    $license_id License ID.
	 * @param string $marker     Marker.
	 * @return string
	 */
	private function log_status( int $license_id, string $marker ): string {
		global $wpdb;

		return (string) $wpdb->get_var(
			$wpdb->prepare( 'SELECT status FROM %i WHERE license_id = %d AND reminder_type = %s', ReminderLog::table_name(), $license_id, $marker )
		);
	}

	/**
	 * Make the mailer refuse every message.
	 *
	 * @return callable The filter, for remove_filter().
	 */
	private function break_mailer(): callable {
		$filter = static function () {
			return false;
		};
		add_filter( 'pre_wp_mail', $filter );

		return $filter;
	}

	// ------------------------------------------------------------------
	// A-137: recipient.
	// ------------------------------------------------------------------

	public function test_A137_purchase_email_goes_to_the_billing_address_of_the_order(): void {
		$product = $this->create_license_product();
		$order   = $this->create_paid_order( array( array( $product, 1 ) ), $this->customer );

		$issued = $this->mails_with( 'license is ready' );
		$this->assertCount( 1, $issued );
		$this->assertSame( $order->get_billing_email(), $issued[0]['to'] );
		$this->assertNotSame( get_userdata( $this->customer )->user_email, $issued[0]['to'] );
	}

	public function test_A137_license_without_an_order_uses_the_account_address(): void {
		$license = $this->seed_license( array( 'customer_id' => $this->customer ) );
		$row     = $this->license( $license['id'] );

		$this->assertSame( get_userdata( $this->customer )->user_email, \WPLC\Notifications\Emails\LicenseEmail::recipient_for( $row ) );

		// An order of somebody else is never used as the source of the address.
		$foreign = $this->create_order( array( array( $this->create_simple_product(), 1 ) ), self::factory()->user->create() );
		$row['source_order_id'] = $foreign->get_id();
		$this->assertSame( get_userdata( $this->customer )->user_email, \WPLC\Notifications\Emails\LicenseEmail::recipient_for( $row ) );
	}

	// ------------------------------------------------------------------
	// B-151 / B-38: content of the "license issued" email.
	// ------------------------------------------------------------------

	public function test_B151_issued_email_mentions_the_download_and_three_install_steps(): void {
		$license = $this->seed_ordered_license();
		Notifications::on_license_issued( $license['id'], $license['key'], $license['order']->get_id() );

		$mails = $this->mails_with( 'license is ready' );
		$this->assertCount( 1, $mails );
		$body = $mails[0]['body'];
		$this->assertStringContainsString( 'You can download the plugin in your account under "Licenses"', $body );
		$this->assertStringContainsString( 'How to get started', $body );
		$this->assertSame( 1, preg_match( '/<ol\b.*?<\/ol>/s', $body, $list ) );
		$this->assertSame( 3, substr_count( $list[0], '<li' ) );
		$this->assertStringContainsString( 'Upload Plugin', $list[0] );
		$this->assertStringContainsString( 'enter your license key', $list[0] );
		$this->assertStringNotContainsString( $license['key'], $body, 'The full key stays out of the email unless the shop owner enables it.' );
		// B-38: no renewal terms without automatic renewal.
		$this->assertStringNotContainsString( 'Automatic renewal', $body );
	}

	public function test_B38_issued_email_states_the_renewal_terms_when_automatic_renewal_is_on(): void {
		$this->enable_german_vat();
		$paid_until = gmdate( 'Y-m-d H:i:s', time() + 365 * DAY_IN_SECONDS );
		$license    = $this->seed_ordered_license( array( 'paid_until' => $paid_until, 'next_payment_at' => $paid_until ), true );
		Notifications::on_license_issued( $license['id'], $license['key'], $license['order']->get_id() );

		$mails = $this->mails_with( 'license is ready' );
		$this->assertCount( 1, $mails );
		$text = html_entity_decode( wp_strip_all_tags( $mails[0]['body'] ), ENT_QUOTES );
		$this->assertStringContainsString( 'Automatic renewal', $text );
		$this->assertStringContainsString( 'Next renewal', $text );
		$this->assertStringContainsString( wp_date( get_option( 'date_format' ), strtotime( $paid_until . ' UTC' ) ), $text );
		$this->assertStringContainsString( '58.31', $text, 'Amount including 19 % VAT.' );
		$this->assertStringContainsString( 'incl. tax', $text );
		$this->assertStringContainsString( 'every year', $text );
		$this->assertStringContainsString( 'You can cancel the automatic renewal at any time before the renewal date', $text );
	}

	public function test_B38_order_email_lists_the_renewal_terms_below_the_order_table(): void {
		$this->enable_german_vat();
		$auto   = $this->seed_ordered_license( array(), true );
		$manual = $this->seed_ordered_license();

		ob_start();
		do_action( 'woocommerce_email_after_order_table', $auto['order'], false, false, null );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Automatic renewal', $html );
		$this->assertStringContainsString( 'Next renewal', $html );
		$this->assertStringContainsString( '58.31', html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES ) );
		$this->assertStringContainsString( 'every year', $html );
		$this->assertStringContainsString( 'You can cancel the automatic renewal', $html );
		$this->assertStringContainsString( $this->license( $auto['id'] )['key_prefix'], $html );

		ob_start();
		do_action( 'woocommerce_email_after_order_table', $auto['order'], false, true, null );
		$plain = (string) ob_get_clean();
		$this->assertStringContainsString( 'Next renewal: ', $plain );
		$this->assertStringNotContainsString( '<', $plain );

		ob_start();
		do_action( 'woocommerce_email_after_order_table', $auto['order'], true, false, null );
		$this->assertStringNotContainsString( 'Automatic renewal', (string) ob_get_clean(), 'Not in the admin copy.' );

		ob_start();
		do_action( 'woocommerce_email_after_order_table', $manual['order'], false, false, null );
		$this->assertStringNotContainsString( 'Automatic renewal', (string) ob_get_clean(), 'Not without automatic renewal.' );
	}

	// ------------------------------------------------------------------
	// A-129 / A-130 / T-140: templates.
	// ------------------------------------------------------------------

	public function test_A130_plain_text_email_keeps_apostrophes(): void {
		global $wpdb;
		$license = $this->seed_ordered_license( array( 'product_key' => 'captains-pass' ) );
		$now     = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert(
			$this->tables()['products'],
			array(
				'product_key'  => 'captains-pass',
				'display_name' => "Captain's Pass & More",
				'active'       => 1,
				'created_at'   => $now,
				'updated_at'   => $now,
			)
		);

		foreach ( array( 'issued', 'cancelled', 'expiry_reminder', 'suspended' ) as $key ) {
			$email             = $this->email_object( $key );
			$email->email_type = 'plain';
			reset_phpmailer_instance();
			try {
				$this->assertTrue( $email->trigger( $this->license( $license['id'] ), array( 'days_left' => 3, 'cancelled_at' => time() ) ), $key );
			} finally {
				$email->email_type = 'html';
			}
			$mails = $this->mails();
			$this->assertCount( 1, $mails, $key );
			$this->assertStringContainsString( "Captain's Pass & More", $mails[0]['body'], 'Plain-text body of "' . $key . '".' );
			$this->assertStringContainsString( "Captain's Pass", $mails[0]['subject'], $key );
			$this->assertStringNotContainsString( '&#', $mails[0]['body'], $key );
			$this->assertStringNotContainsString( '<', $mails[0]['body'], $key );
		}
	}

	public function test_A129_html_email_wraps_long_keys_keeps_the_button_size_and_follows_the_reading_direction(): void {
		$license = $this->seed_ordered_license();
		$email   = $this->email_object( 'issued' );

		$this->assertTrue( $email->trigger( $this->license( $license['id'] ), array() ) );
		$body = $this->mails()[0]['body'];
		$this->assertStringContainsString( 'overflow-wrap: anywhere', $body, 'A key without spaces must wrap inside its cell.' );
		$this->assertStringContainsString( 'table-layout: fixed', $body );
		$this->assertMatchesRegularExpression( '/<td bgcolor="#2271b1" style="padding: 10px 18px;[^"]*">\s*<a class="button"/', $body, 'The padding is on the table cell, where Outlook respects it.' );
		$this->assertStringContainsString( 'text-align: left', $body );
		$this->assertStringNotContainsString( 'text-align: right', $body );

		$GLOBALS['wp_locale']->text_direction = 'rtl';
		reset_phpmailer_instance();
		try {
			$this->assertTrue( $email->trigger( $this->license( $license['id'] ), array() ) );
		} finally {
			$GLOBALS['wp_locale']->text_direction = 'ltr';
		}
		$body = $this->mails()[0]['body'];
		$this->assertStringContainsString( 'text-align: right', $body );
		$this->assertStringNotContainsString( 'text-align: left;', $body );
	}

	public function test_T140_sender_note_of_the_settings_is_printed_in_license_emails(): void {
		update_option( 'wplc_settings', array( 'sender_note' => 'Questions? Write to help@shop.example or call 030 1234.' ) );
		$license = $this->seed_ordered_license();

		foreach ( array( 'issued', 'renewed', 'expired' ) as $key ) {
			reset_phpmailer_instance();
			$this->assertTrue( $this->email_object( $key )->trigger( $this->license( $license['id'] ), array() ) );
			$this->assertStringContainsString( 'Questions? Write to help@shop.example or call 030 1234.', $this->mails()[0]['body'], $key );
		}

		update_option( 'wplc_settings', array( 'sender_note' => '' ) );
		reset_phpmailer_instance();
		$this->email_object( 'issued' )->trigger( $this->license( $license['id'] ), array() );
		$this->assertStringNotContainsString( 'Questions?', $this->mails()[0]['body'] );
	}

	// ------------------------------------------------------------------
	// A-21: cancellation confirmation. B-152: suspension and revocation.
	// ------------------------------------------------------------------

	public function test_A21_cancellation_is_confirmed_with_time_and_end_date(): void {
		$paid_until = gmdate( 'Y-m-d H:i:s', time() + 100 * DAY_IN_SECONDS );
		$license    = $this->seed_ordered_license( array( 'paid_until' => $paid_until ), true );
		$received   = time() - 90;

		// Not cancelled: nothing to confirm.
		do_action( 'wplc/cancellation_scheduled', $license['id'], $received );
		$this->assertSame( array(), $this->mails() );

		$this->assertTrue( LicenseLifecycle::schedule_cancellation( $license['id'], 'test', $this->customer ) );
		do_action( 'wplc/cancellation_scheduled', $license['id'], $received );
		// A second announcement in the same request (e.g. by the lifecycle itself) does not send twice.
		do_action( 'wplc/cancellation_scheduled', $license['id'], $received );

		$mails = $this->mails();
		$this->assertCount( 1, $mails );
		$this->assertSame( $license['order']->get_billing_email(), $mails[0]['to'] );
		$this->assertStringContainsString( 'Confirmation: automatic renewal of', $mails[0]['subject'] );
		$text = html_entity_decode( wp_strip_all_tags( $mails[0]['body'] ), ENT_QUOTES );
		$this->assertStringContainsString( 'Cancellation received', $text );
		$this->assertStringContainsString( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $received ), $text, 'The time the cancellation was received.' );
		$this->assertStringContainsString( 'License ends on', $text );
		$this->assertStringContainsString( wp_date( get_option( 'date_format' ), strtotime( $paid_until . ' UTC' ) ), $text, 'The end date.' );
		$this->assertStringContainsString( 'will not be charged again', $text );
	}

	public function test_B152_suspension_and_revocation_are_announced_with_the_support_contact(): void {
		update_option( 'woocommerce_email_from_address', 'support@shop.example' );
		$admin   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$license = $this->seed_ordered_license();

		$this->assertTrue( LicenseLifecycle::admin_set_status( $license['id'], 'suspended', 'Payment disputed', $admin ) );
		$mails = $this->mails();
		$this->assertCount( 1, $mails );
		$this->assertStringContainsString( 'has been suspended', $mails[0]['subject'] );
		$this->assertStringContainsString( 'Your license has been suspended.', $mails[0]['body'] );
		$this->assertStringContainsString( 'support@shop.example', $mails[0]['body'] );

		reset_phpmailer_instance();
		$this->assertTrue( LicenseLifecycle::admin_set_status( $license['id'], 'revoked', 'Refunded', $admin ) );
		$mails = $this->mails();
		$this->assertCount( 1, $mails );
		$this->assertStringContainsString( 'has been revoked', $mails[0]['subject'] );
		$this->assertStringContainsString( 'Your license has been revoked.', $mails[0]['body'] );
		$this->assertStringContainsString( 'support@shop.example', $mails[0]['body'] );
	}

	public function test_B152_the_two_emails_can_be_switched_off(): void {
		$this->assertSame( 'wplc_license_suspended', $this->email_object( 'suspended' )->id );
		$this->assertSame( 'wplc_license_revoked', $this->email_object( 'revoked' )->id );
		$this->assertSame( 'wplc_cancellation_confirmed', $this->email_object( 'cancelled' )->id );

		$email          = $this->email_object( 'suspended' );
		$email->enabled = 'no';
		try {
			$license = $this->seed_ordered_license();
			$this->assertTrue( LicenseLifecycle::admin_set_status( $license['id'], 'suspended', 'Payment disputed', 1 ) );
			$this->assertSame( array(), $this->mails() );
		} finally {
			$email->enabled = 'yes';
		}
	}

	// ------------------------------------------------------------------
	// A-10 / B-121 / A-53: reminder texts.
	// ------------------------------------------------------------------

	public function test_A10_renewal_notice_names_the_amount_including_tax(): void {
		$this->enable_german_vat();
		$license = $this->seed_ordered_license( array(), true );

		$this->assertTrue( $this->email_object( 'renewal_notice' )->trigger( $this->license( $license['id'] ), array( 'days_left' => 7 ) ) );
		$text = html_entity_decode( wp_strip_all_tags( $this->mails()[0]['body'] ), ENT_QUOTES );
		$this->assertStringContainsString( '58.31', $text );
		$this->assertStringContainsString( 'incl. tax', $text );
		$this->assertStringContainsString( 'The amount includes tax', $text );
		$this->assertStringNotContainsString( 'Taxes may be added', $text );
		$this->assertStringNotContainsString( '49.00', $text );
	}

	public function test_B121_reminder_says_today_on_the_last_day(): void {
		$license = $this->seed_ordered_license();
		$email   = $this->email_object( 'expiry_reminder' );

		$email->trigger( $this->license( $license['id'] ), array( 'days_left' => 0 ) );
		$body = $this->mails()[0]['body'];
		$this->assertStringContainsString( 'Your license ends today, ', $body );
		$this->assertStringNotContainsString( 'in 1 day', $body );

		reset_phpmailer_instance();
		$email->trigger( $this->license( $license['id'] ), array( 'days_left' => 1 ) );
		$this->assertStringContainsString( 'Your license ends in 1 day, on ', $this->mails()[0]['body'] );

		reset_phpmailer_instance();
		$email->trigger( $this->license( $license['id'] ), array( 'days_left' => 7 ) );
		$this->assertStringContainsString( 'Your license ends in 7 days, on ', $this->mails()[0]['body'] );
	}

	public function test_A53_reminder_only_promises_the_renew_button_once_the_account_offers_it(): void {
		$email = $this->email_object( 'expiry_reminder' );

		// 40 days left: the account offers "Renew now" from 30 days before the end.
		$early = $this->seed_ordered_license( array( 'paid_until' => gmdate( 'Y-m-d H:i:s', time() + 40 * DAY_IN_SECONDS ) ) );
		$email->trigger( $this->license( $early['id'] ), array( 'days_left' => 40 ) );
		$body = $this->mails()[0]['body'];
		$this->assertStringNotContainsString( '>Renew now<', $body );
		$this->assertStringContainsString( '>View license in your account<', $body );
		$this->assertStringContainsString( 'from ' . wp_date( get_option( 'date_format' ), time() + 10 * DAY_IN_SECONDS ), html_entity_decode( $body, ENT_QUOTES ) );
		$this->assertFalse( \WPLC\Billing\ManualRenewal::in_window( $this->license( $early['id'] ), time() ), 'Fixture: the account has no renew button yet.' );

		reset_phpmailer_instance();
		$late = $this->seed_ordered_license( array( 'paid_until' => gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS ) ) );
		$email->trigger( $this->license( $late['id'] ), array( 'days_left' => 7 ) );
		$body = $this->mails()[0]['body'];
		$this->assertStringContainsString( '>Renew now<', $body );
		$this->assertTrue( \WPLC\Billing\ManualRenewal::in_window( $this->license( $late['id'] ), time() ) );
	}

	// ------------------------------------------------------------------
	// B-122: language of the purchase.
	// ------------------------------------------------------------------

	public function test_B122_checkout_stores_the_locale_and_later_emails_use_it(): void {
		$order = $this->order();
		$this->assertSame( '', (string) $order->get_meta( PurchaseLocale::META, true ) );

		$set_locale = static function () {
			return 'de_DE';
		};
		add_filter( 'pre_determine_locale', $set_locale );
		do_action( 'woocommerce_checkout_create_order', $order, array() );
		remove_filter( 'pre_determine_locale', $set_locale );
		$order->save();
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'de_DE', $order->get_meta( PurchaseLocale::META, true ), 'The locale is written through the order CRUD API.' );

		$license = $this->seed_license(
			array(
				'customer_id'          => $this->customer,
				'source_order_id'      => $order->get_id(),
				'source_order_item_id' => $this->first_item( $order )->get_id(),
			)
		);
		$row = $this->license( $license['id'] );
		$this->assertSame( 'de_DE', PurchaseLocale::for_license( $row ) );

		$during = array();
		$probe  = static function ( $args ) use ( &$during ) {
			$during[] = determine_locale();
			return $args;
		};
		add_filter( 'wp_mail', $probe );
		$this->email_object( 'renewed' )->trigger( $row, array() );
		remove_filter( 'wp_mail', $probe );

		$this->assertSame( array( 'de_DE' ), $during, 'The email is built in the language of the purchase.' );
		$this->assertSame( 'en_US', determine_locale(), 'The locale is restored afterwards.' );
	}

	public function test_B122_falls_back_to_the_profile_language_without_a_stored_locale(): void {
		update_user_meta( $this->customer, 'locale', 'fr_FR' );
		$license = $this->seed_ordered_license();
		$this->assertSame( 'fr_FR', PurchaseLocale::for_license( $this->license( $license['id'] ) ) );

		$license['order']->update_meta_data( PurchaseLocale::META, '../evil' );
		$license['order']->save();
		$this->assertSame( 'fr_FR', PurchaseLocale::for_license( $this->license( $license['id'] ) ), 'A malformed stored value is ignored.' );
	}

	// ------------------------------------------------------------------
	// B-115: email preview.
	// ------------------------------------------------------------------

	public function test_B115_preview_shows_sample_data_for_every_license_email(): void {
		foreach ( array( 'issued', 'expiry_reminder', 'renewal_notice', 'payment_failed', 'expired', 'renewed', 'cancelled', 'suspended', 'revoked' ) as $key ) {
			$email = $this->email_object( $key );
			// What WooCommerce does before the filter: a dummy order as the object.
			$email->set_object( new WC_Order() );
			$email = apply_filters( 'woocommerce_prepare_email_for_preview', $email );

			$html = $email->get_content_html();
			$this->assertStringContainsString( 'Sample product', $html, $key );
			$this->assertStringContainsString( 'WPLC-1A2B3C4D', $html, $key );
			$this->assertStringContainsString( 'Sample product', $email->get_subject() . $email->get_content_plain(), $key );
			$this->assertDoesNotMatchRegularExpression( '/\{[a-z_]+\}/', $email->get_subject() . $email->get_heading(), $key );
		}
		$this->assertStringContainsString( 'How to get started', $this->email_object( 'issued' )->get_content_html() );
		$this->assertStringContainsString( 'Automatic renewal', $this->email_object( 'issued' )->get_content_html() );
		$this->assertStringContainsString( 'has been revoked', $this->email_object( 'revoked' )->get_content_html() );

		// Other emails pass through untouched.
		$other = new stdClass();
		$this->assertSame( $other, apply_filters( 'woocommerce_prepare_email_for_preview', $other ) );
	}

	// ------------------------------------------------------------------
	// A-78: "sent" is only recorded after the mailer accepted the message.
	// ------------------------------------------------------------------

	public function test_A78_refused_email_is_not_recorded_as_sent_and_is_retried(): void {
		$license = $this->seed_ordered_license();
		$broken  = $this->break_mailer();
		Notifications::on_license_issued( $license['id'], $license['key'], $license['order']->get_id() );
		remove_filter( 'pre_wp_mail', $broken );

		$this->assertSame( array(), $this->mails() );
		$this->assertSame( '', $this->log_status( $license['id'], 'issued' ), 'A refused email must not leave a "sent" marker behind.' );

		// A retry is queued, and it does not carry the license key.
		$actions = as_get_scheduled_actions(
			array(
				'hook'   => Notifications::RETRY_HOOK,
				'status' => ActionScheduler_Store::STATUS_PENDING,
				'group'  => 'wplc',
			)
		);
		$mine = array();
		foreach ( $actions as $action ) {
			$args = $action->get_args();
			if ( (int) $args[1] === (int) $license['id'] ) {
				$mine[] = $args;
			}
		}
		$this->assertCount( 1, $mine, 'Exactly one retry is scheduled.' );
		$this->assertSame( 'issued', $mine[0][0] );
		$this->assertSame( 1, $mine[0][3] );
		$this->assertStringNotContainsString( $license['key'], wp_json_encode( $mine[0] ) );

		// The retry sends the email and records it; running it again sends nothing.
		do_action_ref_array( Notifications::RETRY_HOOK, $mine[0] );
		$this->assertCount( 1, $this->mails_with( 'license is ready' ) );
		$this->assertSame( 'sent', $this->log_status( $license['id'], 'issued' ) );

		do_action_ref_array( Notifications::RETRY_HOOK, $mine[0] );
		$this->assertCount( 1, $this->mails_with( 'license is ready' ) );
	}

	public function test_reissued_or_resent_key_email_always_goes_out(): void {
		$license = $this->seed_ordered_license();
		Notifications::on_license_issued( $license['id'], $license['key'], $license['order']->get_id() );
		$this->assertCount( 1, $this->mails_with( 'license is ready' ) );

		// Hooks of the admin package: not blocked by the "issued" marker of the first email.
		do_action( 'wplc/license_key_resend_requested', $license['id'], $license['key'], 1 );
		do_action( 'wplc/license_key_reissued', $license['id'], $license['key'], 1 );
		$this->assertCount( 3, $this->mails_with( 'license is ready' ) );

		// A refused resend is retried without the marker, too.
		$broken = $this->break_mailer();
		do_action( 'wplc/license_key_resend_requested', $license['id'], $license['key'], 1 );
		remove_filter( 'pre_wp_mail', $broken );
		$this->assertCount( 3, $this->mails_with( 'license is ready' ) );
		Notifications::retry_email( 'issued', $license['id'], '', 1, array() );
		$this->assertCount( 4, $this->mails_with( 'license is ready' ) );
	}

	public function test_A78_retry_is_dropped_when_the_email_no_longer_applies(): void {
		$license = $this->seed_ordered_license( array( 'status' => 'active' ) );
		$period  = $this->license( $license['id'] )['paid_until'];

		// "Payment failed" was refused, the customer paid in the meantime: no email.
		Notifications::retry_email( 'payment_failed', $license['id'], $period, 1, array() );
		$this->assertSame( array(), $this->mails() );
	}

	public function test_A78_reminder_is_marked_sent_only_after_a_successful_send(): void {
		$license = $this->seed_license(
			array(
				'customer_id' => $this->customer,
				'paid_until'  => gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS ),
			)
		);

		$broken = $this->break_mailer();
		Notifications::send_due_reminders();
		remove_filter( 'pre_wp_mail', $broken );
		$this->assertSame( array(), $this->mails() );
		$this->assertSame( '', $this->log_status( $license['id'], 'expiry_7' ), 'The refused reminder must stay due.' );

		// Next run (mail server is back): sent and recorded.
		Notifications::send_due_reminders();
		$this->assertCount( 1, $this->mails_with( 'license ends on' ) );
		$this->assertSame( 'sent', $this->log_status( $license['id'], 'expiry_7' ) );

		// And never a second time.
		Notifications::send_due_reminders();
		$this->assertCount( 1, $this->mails_with( 'license ends on' ) );
	}

	public function test_A78_claims_are_pending_until_confirmed_and_abandoned_claims_are_taken_over(): void {
		global $wpdb;
		$license = $this->seed_license( array( 'customer_id' => $this->customer ) );
		$period  = '2030-01-01 00:00:00';

		$this->assertSame( ReminderLog::CLAIMED, ReminderLog::claim( $license['id'], 'expiry_7', $period ) );
		$this->assertSame( 'pending', $this->log_status( $license['id'], 'expiry_7' ) );
		$this->assertFalse( ReminderLog::was_sent( $license['id'], 'expiry_7', $period ) );
		$this->assertSame( ReminderLog::DUPLICATE, ReminderLog::claim( $license['id'], 'expiry_7', $period ), 'Another process is sending right now.' );

		// The sender died: after the timeout the claim can be taken over, exactly once.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET sent_at = %s WHERE license_id = %d', ReminderLog::table_name(), gmdate( 'Y-m-d H:i:s', time() - ReminderLog::STALE_CLAIM_SECONDS - 60 ), $license['id'] ) );
		$this->assertSame( ReminderLog::CLAIMED, ReminderLog::claim( $license['id'], 'expiry_7', $period ) );
		$this->assertSame( ReminderLog::DUPLICATE, ReminderLog::claim( $license['id'], 'expiry_7', $period ) );

		$this->assertTrue( ReminderLog::confirm( $license['id'], 'expiry_7', $period ) );
		$this->assertTrue( ReminderLog::was_sent( $license['id'], 'expiry_7', $period ) );
		// A sent entry is final, however old it is.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET sent_at = %s WHERE license_id = %d', ReminderLog::table_name(), '2020-01-01 00:00:00', $license['id'] ) );
		$this->assertSame( ReminderLog::DUPLICATE, ReminderLog::claim( $license['id'], 'expiry_7', $period ) );
		$this->assertTrue( ReminderLog::release( $license['id'], 'expiry_7', $period ) );
		$this->assertTrue( ReminderLog::was_sent( $license['id'], 'expiry_7', $period ), 'release() never removes a sent entry.' );

		// A released claim can be claimed again.
		$this->assertSame( ReminderLog::CLAIMED, ReminderLog::claim( $license['id'], 'expiry_30', $period ) );
		$this->assertTrue( ReminderLog::release( $license['id'], 'expiry_30', $period ) );
		$this->assertSame( ReminderLog::CLAIMED, ReminderLog::claim( $license['id'], 'expiry_30', $period ) );
	}

	public function test_B41_renewal_notice_evidence_is_available_to_the_renewal_processor(): void {
		$paid_until = gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS );
		$license    = $this->seed_ordered_license( array( 'paid_until' => $paid_until, 'next_payment_at' => $paid_until ), true );

		$this->assertFalse( Notifications::renewal_notice_sent( $this->license( $license['id'] ) ) );
		Notifications::send_due_reminders();
		$this->assertCount( 1, $this->mails_with( 'license renews on' ) );
		$this->assertTrue( Notifications::renewal_notice_sent( $this->license( $license['id'] ) ) );
	}

	// ------------------------------------------------------------------
	// A-79: the version option alone does not prove that the table exists.
	// ------------------------------------------------------------------

	public function test_A79_missing_table_is_detected_although_the_option_says_installed(): void {
		global $wpdb;
		$this->assertSame( ReminderLog::VERSION, (string) get_option( ReminderLog::VERSION_OPTION ) );

		$created    = array();
		$spy        = static function ( $query ) use ( &$created ) {
			if ( preg_match( '/^\s*CREATE (TEMPORARY )?TABLE\s+(\S+)/i', (string) $query, $match ) ) {
				$created[] = $match[2];
			}
			return $query;
		};
		$old_prefix = $wpdb->prefix;
		add_filter( 'query', $spy, 1 );
		$wpdb->prefix = 'wplcgone_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A prefix under which no plugin table exists.
		ReminderLog::reset();
		try {
			$available = ReminderLog::maybe_install();
			$claim     = ReminderLog::claim( 1, 'expiry_7', '2030-01-01 00:00:00' );
		} finally {
			$wpdb->prefix = $old_prefix; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			remove_filter( 'query', $spy, 1 );
			ReminderLog::reset();
		}

		$this->assertContains( 'wplcgone_wplc_reminder_log', $created, 'The installer must try to create the missing table instead of trusting the option.' );
		// Inside a test WordPress creates temporary tables only, which the installer cannot verify:
		// it reports "not available" and reminders are not sent blindly.
		$this->assertFalse( $available );
		$this->assertSame( ReminderLog::FAILED, $claim );
		$this->assertTrue( ReminderLog::maybe_install(), 'The real table is found again.' );
	}

	// ------------------------------------------------------------------
	// B-125: time budget per email, stale cursor.
	// ------------------------------------------------------------------

	public function test_B125_stale_cursor_of_an_earlier_day_does_not_skip_licenses(): void {
		$license = $this->seed_license(
			array(
				'customer_id' => $this->customer,
				'paid_until'  => gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS ),
			)
		);

		// Left behind by a run that never finished (plugin deactivated, cron broken).
		update_option( 'wplc_reminder_cursor', array( 'day' => gmdate( 'Y-m-d', time() - 3 * DAY_IN_SECONDS ), 'id' => $license['id'] + 1000 ), false );
		Notifications::send_due_reminders();

		$this->assertCount( 1, $this->mails_with( 'license ends on' ), 'The license before the stale cursor still gets its reminder.' );
		$this->assertFalse( get_option( 'wplc_reminder_cursor' ), 'A finished run leaves no cursor.' );
	}

	public function test_B125_cursor_of_the_same_day_continues_the_run(): void {
		$license = $this->seed_license(
			array(
				'customer_id' => $this->customer,
				'paid_until'  => gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS ),
			)
		);
		update_option( 'wplc_reminder_cursor', array( 'day' => gmdate( 'Y-m-d' ), 'id' => $license['id'] ), false );
		Notifications::send_due_reminders();

		$this->assertSame( array(), $this->mails(), 'Licenses up to today\'s cursor were handled by the earlier part of the run.' );
	}

	public function test_B125_time_budget_is_checked_before_every_email(): void {
		$paid_until = gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS );
		$first      = $this->seed_license( array( 'customer_id' => $this->customer, 'paid_until' => $paid_until ) );
		$second     = $this->seed_license( array( 'customer_id' => $this->customer, 'paid_until' => $paid_until ) );

		$budget = static function () {
			return 1;
		};
		// A slow mail server: the first email alone uses up the budget of one second.
		$slow = static function ( $args ) {
			usleep( 1200000 );
			return $args;
		};
		add_filter( 'wplc_reminder_time_budget', $budget );
		add_filter( 'wp_mail', $slow );
		Notifications::send_due_reminders();
		remove_filter( 'wp_mail', $slow );
		remove_filter( 'wplc_reminder_time_budget', $budget );

		$this->assertCount( 1, $this->mails_with( 'license ends on' ), 'The run stops after the email that exceeded the budget, not after the batch of 200.' );
		$this->assertSame( 'sent', $this->log_status( $first['id'], 'expiry_7' ) );
		$this->assertSame( '', $this->log_status( $second['id'], 'expiry_7' ) );
		$this->assertSame( array( 'day' => gmdate( 'Y-m-d' ), 'id' => $first['id'] ), get_option( 'wplc_reminder_cursor' ), 'The cursor carries the day and the last handled license.' );

		// The continued run picks up exactly the rest.
		Notifications::send_due_reminders();
		$this->assertCount( 2, $this->mails_with( 'license ends on' ) );
		$this->assertSame( 'sent', $this->log_status( $second['id'], 'expiry_7' ) );
		$this->assertFalse( get_option( 'wplc_reminder_cursor' ) );
	}
}
