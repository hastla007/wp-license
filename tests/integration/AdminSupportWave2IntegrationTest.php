<?php
/**
 * Admin support actions added after the 14 November review.
 *
 * Covers A-11 (renewal amount), A-21 (automatic renewal on behalf of the customer),
 * A-121 (getting started), A-122 (audit action names, timezone), A-123 (order search,
 * order box, kept form entries, optional email, confirmations) and A-132 (switched-off emails).
 *
 * @package WPLC
 */

use WPLC\Admin\Diagnostics;
use WPLC\Admin\LicenseAdmin;
use WPLC\Admin\LicenseListTable;
use WPLC\Admin\OrderLicensesBox;
use WPLC\Admin\Settings;
use WPLC\Admin\SupportActions;
use WPLC\Billing\RenewalOrder;

final class AdminSupportWave2IntegrationTest extends WPLC_Integration_TestCase {

	/**
	 * Shop manager.
	 *
	 * @var int
	 */
	private $manager;

	/**
	 * Customer.
	 *
	 * @var int
	 */
	private $customer;

	public function set_up(): void {
		parent::set_up();
		$this->manager  = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		$this->customer = self::factory()->user->create( array( 'role' => 'customer' ) );
	}

	public function tear_down(): void {
		remove_all_filters( 'wplc_admin_can_raise_renewal_amount' );
		$_GET = array();
		parent::tear_down();
	}

	/**
	 * Audit rows of a license, oldest first.
	 *
	 * @param int $license_id License ID.
	 * @return array<int,array<string,mixed>>
	 */
	private function audit_rows( int $license_id ): array {
		global $wpdb;
		$tables = $this->tables();

		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE object_type = %s AND object_id = %d ORDER BY id ASC', $tables['audit_log'], 'license', $license_id ), ARRAY_A );
	}

	/**
	 * Render an admin screen of the license list as the current user.
	 *
	 * @param array<string,string> $get Query arguments.
	 * @return string
	 */
	private function render_licenses( array $get ): string {
		set_current_screen( 'woocommerce_page_wplc-licenses' );
		$_GET = array_merge( array( 'page' => 'wplc-licenses' ), $get );
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
	 * A license with automatic renewal of 49.00 EUR.
	 *
	 * @return int
	 */
	private function renewing_license(): int {
		return $this->seed_license(
			array(
				'customer_id'               => $this->customer,
				'renewal_enabled'           => 1,
				'renewal_amount_minor'      => 4900,
				'renewal_currency_decimals' => 2,
				'renewal_currency'          => 'EUR',
				'payment_gateway_id'        => 'stripe',
			)
		)['id'];
	}

	// ---------------------------------------------------------------------
	// A-21: automatic renewal on behalf of the customer.
	// ---------------------------------------------------------------------

	public function test_a21_support_cancels_and_withdraws_the_cancellation_with_reason_and_email(): void {
		$license   = $this->renewing_license();
		$announced = 0;
		add_action(
			'wplc/cancellation_scheduled',
			static function () use ( &$announced ) {
				++$announced;
			}
		);
		wp_set_current_user( $this->manager );

		$html = $this->render_licenses( array( 'license' => (string) $license ) );
		$this->assertStringContainsString( 'value="wplc_admin_cancel_renewal"', $html );
		$this->assertStringNotContainsString( 'value="wplc_admin_withdraw_cancellation"', $html );

		$result = $this->run_admin_post(
			'wplc_admin_cancel_renewal',
			array(
				'license_id' => $license,
				'reason'     => 'Customer called',
				'_wpnonce'   => wp_create_nonce( 'wplc_admin_cancel_renewal_' . $license ),
			)
		);
		$this->assertStringContainsString( 'wplc_notice=renewal_cancelled_by_admin', $result['location'] );
		$row = $this->license( $license );
		$this->assertSame( '1', (string) $row['cancel_at_period_end'] );
		$this->assertSame( 'active', $row['status'], 'The license runs until the end of the paid period.' );
		$this->assertSame( 1, $announced, 'The confirmation email is triggered.' );
		$audit = array_values( array_filter( $this->audit_rows( $license ), static function ( $entry ) { return 'cancellation_scheduled' === $entry['action']; } ) );
		$this->assertCount( 1, $audit );
		$this->assertSame( 'Customer called', $audit[0]['reason'] );
		$this->assertSame( $this->manager, (int) $audit[0]['actor_user_id'] );

		// A second, stale form is refused.
		$again = $this->run_admin_post(
			'wplc_admin_cancel_renewal',
			array(
				'license_id' => $license,
				'reason'     => 'Again',
				'_wpnonce'   => wp_create_nonce( 'wplc_admin_cancel_renewal_' . $license ),
			)
		);
		$this->assertStringContainsString( 'wplc_notice=renewal_already_cancelled', $again['location'] );

		$this->assertStringContainsString( 'value="wplc_admin_withdraw_cancellation"', $this->render_licenses( array( 'license' => (string) $license ) ) );
		$withdraw = $this->run_admin_post(
			'wplc_admin_withdraw_cancellation',
			array(
				'license_id' => $license,
				'reason'     => 'Customer changed their mind',
				'_wpnonce'   => wp_create_nonce( 'wplc_admin_withdraw_cancellation_' . $license ),
			)
		);
		$this->assertStringContainsString( 'wplc_notice=cancellation_withdrawn_by_admin', $withdraw['location'] );
		$this->assertSame( '0', (string) $this->license( $license )['cancel_at_period_end'] );
		$audit = array_values( array_filter( $this->audit_rows( $license ), static function ( $entry ) { return 'cancellation_withdrawn' === $entry['action']; } ) );
		$this->assertSame( 'Customer changed their mind', $audit[0]['reason'] );
	}

	public function test_a21_no_renewal_form_without_automatic_renewal_and_no_switching_it_on(): void {
		$license = $this->seed_license( array( 'customer_id' => $this->customer, 'renewal_enabled' => 0 ) )['id'];
		wp_set_current_user( $this->manager );

		$this->assertStringNotContainsString( 'wplc_admin_cancel_renewal', $this->render_licenses( array( 'license' => (string) $license ) ) );
		$this->assertSame( SupportActions::RESULT_FAILED, SupportActions::cancel_renewal( $license, 'x', $this->manager ) );
		$this->assertSame( SupportActions::RESULT_STALE, SupportActions::withdraw_cancellation( $license, 'x', $this->manager ) );
		$this->assertSame( '0', (string) $this->license( $license )['renewal_enabled'] );
	}

	public function test_a73_owner_change_writes_one_audit_entry_per_cancelled_renewal(): void {
		global $wpdb;
		$tables  = $this->tables();
		$license = $this->renewing_license();
		$now     = gmdate( 'Y-m-d H:i:s' );
		$ids     = array();
		foreach ( array( 'scheduled', 'failed', 'paid' ) as $index => $status ) {
			$wpdb->insert(
				$tables['renewals'],
				array(
					'license_id'      => $license,
					'period_start'    => gmdate( 'Y-m-d H:i:s', time() + ( $index + 1 ) * DAY_IN_SECONDS ),
					'period_end'      => gmdate( 'Y-m-d H:i:s', time() + 400 * DAY_IN_SECONDS ),
					'idempotency_key' => hash( 'sha256', 'a73' . $license . $status ),
					'status'          => $status,
					'attempt_count'   => 0,
					'created_at'      => $now,
					'updated_at'      => $now,
				)
			);
			$ids[ $status ] = (int) $wpdb->insert_id;
		}
		$new_owner = self::factory()->user->create( array( 'role' => 'customer' ) );

		$this->assertSame( SupportActions::RESULT_OK, SupportActions::change_owner( $license, $new_owner, 'Agency hand-over', $this->manager, $this->customer ) );

		foreach ( array( 'scheduled', 'failed' ) as $status ) {
			$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE object_type = %s AND object_id = %d', $tables['audit_log'], 'renewal', $ids[ $status ] ), ARRAY_A );
			$this->assertCount( 1, $rows, $status );
			$this->assertSame( 'renewal_cancelled', $rows[0]['action'] );
			$this->assertSame( 'license_owner_changed', $rows[0]['reason'] );
			$this->assertSame( array( 'status' => $status ), json_decode( $rows[0]['before_json'], true ) );
			$this->assertSame( array( 'status' => 'cancelled' ), json_decode( $rows[0]['after_json'], true ) );
			$this->assertSame( $this->manager, (int) $rows[0]['actor_user_id'] );
			$this->assertSame( 'cancelled', $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE id = %d', $tables['renewals'], $ids[ $status ] ) ) );
		}
		$this->assertSame( '0', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE object_type = %s AND object_id = %d', $tables['audit_log'], 'renewal', $ids['paid'] ) ) );
		$this->assertSame( 'paid', $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE id = %d', $tables['renewals'], $ids['paid'] ) ) );
	}

	// ---------------------------------------------------------------------
	// A-11: amount of the next automatic renewals.
	// ---------------------------------------------------------------------

	public function test_a11_renewal_amount_can_be_lowered_but_not_raised_without_consent(): void {
		$license = $this->renewing_license();
		wp_set_current_user( $this->manager );

		$html = $this->render_licenses( array( 'license' => (string) $license ) );
		$this->assertStringContainsString( 'name="expected_minor" value="4900"', $html );
		$this->assertStringContainsString( 'id="wplc-renewal-amount" name="amount" required size="10" value="49.00"', $html );

		$post   = static function ( string $amount, int $expected ) use ( $license ): array {
			return array(
				'license_id'     => $license,
				'amount'         => $amount,
				'expected_minor' => $expected,
				'reason'         => 'Loyalty discount',
				'_wpnonce'       => wp_create_nonce( 'wplc_admin_renewal_amount_' . $license ),
			);
		};
		$lowered = $this->run_admin_post( 'wplc_admin_renewal_amount', $post( '39.50', 4900 ) );
		$this->assertStringContainsString( 'wplc_notice=renewal_amount_changed', $lowered['location'] );
		$this->assertSame( '3950', (string) $this->license( $license )['renewal_amount_minor'] );
		$audit = array_values( array_filter( $this->audit_rows( $license ), static function ( $entry ) { return 'admin_renewal_amount_changed' === $entry['action']; } ) );
		$this->assertCount( 1, $audit );
		$this->assertSame( 4900, json_decode( $audit[0]['before_json'], true )['renewal_amount_minor'] );
		$this->assertSame( 3950, json_decode( $audit[0]['after_json'], true )['renewal_amount_minor'] );

		// The form of before the change is stale.
		$stale = $this->run_admin_post( 'wplc_admin_renewal_amount', $post( '30', 4900 ) );
		$this->assertStringContainsString( 'wplc_notice=renewal_amount_stale', $stale['location'] );

		// Raising needs the customer's consent.
		$raised = $this->run_admin_post( 'wplc_admin_renewal_amount', $post( '59', 3950 ) );
		$this->assertStringContainsString( 'wplc_notice=renewal_amount_increase_refused', $raised['location'] );
		$this->assertSame( '3950', (string) $this->license( $license )['renewal_amount_minor'] );

		add_filter( 'wplc_admin_can_raise_renewal_amount', '__return_true' );
		$this->assertSame( SupportActions::RESULT_OK, SupportActions::change_renewal_amount( $license, 5900, 3950, 'Consent by email', $this->manager ) );
		$this->assertSame( '5900', (string) $this->license( $license )['renewal_amount_minor'] );

		$invalid = $this->run_admin_post( 'wplc_admin_renewal_amount', $post( 'abc', 5900 ) );
		$this->assertStringContainsString( 'wplc_notice=renewal_amount_missing_input', $invalid['location'] );
	}

	// ---------------------------------------------------------------------
	// A-123: order search, order box, kept form entries, optional email.
	// ---------------------------------------------------------------------

	public function test_a123_search_by_order_finds_issued_and_renewed_licenses_and_the_order_links_back(): void {
		global $wpdb;
		$tables  = $this->tables();
		$product = $this->create_license_product();
		$order   = $this->create_paid_order( array( array( $product, 1 ) ), $this->customer );
		$issued  = (int) $this->licenses_for_order( $order->get_id() )[0]['id'];
		$other   = $this->seed_license( array( 'customer_id' => $this->customer ) )['id'];

		$renewal_order = wc_create_order( array( 'customer_id' => $this->customer ) );
		$renewal_order->update_meta_data( RenewalOrder::META_LICENSE, $other );
		$renewal_order->save();

		$find = static function ( string $term ) use ( $tables ): array {
			global $wpdb;
			$query = LicenseListTable::query_parts( array( 'search' => $term ) );

			return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$tables['licenses']} WHERE {$query['where']} ORDER BY id ASC", $query['params'] ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Test query.
		};
		$this->assertContains( $issued, $find( (string) $order->get_id() ) );
		$this->assertNotContains( $other, $find( (string) $order->get_id() ) );
		$this->assertContains( $other, $find( (string) $renewal_order->get_id() ) );

		$this->assertSame( array( $issued ), array_map( 'intval', array_column( OrderLicensesBox::licenses_for( $order ), 'id' ) ) );
		$renewed = OrderLicensesBox::licenses_for( wc_get_order( $renewal_order->get_id() ) );
		$this->assertSame( $other, (int) $renewed[0]['id'] );
		$this->assertSame( 'renewed', $renewed[0]['relation'] );

		wp_set_current_user( $this->manager );
		ob_start();
		OrderLicensesBox::render( $order );
		$box = (string) ob_get_clean();
		$this->assertStringContainsString( esc_url( LicenseAdmin::detail_url( $issued ) ), $box );
		$this->assertStringContainsString( 'issued for this order', $box );
		unset( $wpdb );
	}

	public function test_a123_refused_add_form_keeps_the_entries_and_the_email_can_be_skipped(): void {
		global $wpdb;
		$tables  = $this->tables();
		$product = $this->create_license_product();
		$plan_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE woocommerce_product_id = %d', $tables['plans'], $product->get_id() ) );
		wp_set_current_user( $this->manager );

		$refused = $this->run_admin_post(
			'wplc_admin_add_license',
			array(
				'customer'   => 'nobody-here@example.test',
				'plan_id'    => $plan_id,
				'paid_until' => gmdate( 'Y-m-d', time() + 90 * DAY_IN_SECONDS ),
				'reason'     => 'Migration from the old shop',
				'skip_email' => '1',
				'_wpnonce'   => wp_create_nonce( 'wplc_admin_add_license' ),
			)
		);
		$this->assertStringContainsString( 'wplc_notice=create_unknown_customer', $refused['location'] );

		$form = $this->render_licenses( array( 'view' => 'add' ) );
		$this->assertStringContainsString( 'value="nobody-here@example.test"', $form );
		$this->assertStringContainsString( 'value="Migration from the old shop"', $form );
		$this->assertMatchesRegularExpression( '/<option value="' . $plan_id . '" selected/', $form );
		$this->assertMatchesRegularExpression( '/name="skip_email" value="1" checked/', $form );
		// Read once: the next visit shows an empty form.
		$this->assertStringNotContainsString( 'nobody-here@example.test', $this->render_licenses( array( 'view' => 'add' ) ) );

		$mails = 0;
		add_filter(
			'pre_wp_mail',
			static function ( $short ) use ( &$mails ) {
				++$mails;
				return $short;
			}
		);
		$created = $this->run_admin_post(
			'wplc_admin_add_license',
			array(
				'customer'   => (string) $this->customer,
				'plan_id'    => $plan_id,
				'paid_until' => gmdate( 'Y-m-d', time() + 90 * DAY_IN_SECONDS ),
				'reason'     => 'Migration from the old shop',
				'skip_email' => '1',
				'_wpnonce'   => wp_create_nonce( 'wplc_admin_add_license' ),
			)
		);
		$this->assertStringContainsString( 'wplc_notice=license_created_no_email', $created['location'] );
		$this->assertSame( 0, $mails, 'No license email for a skipped email.' );
		$this->assertNotFalse( has_action( 'wplc/license_issued', array( 'WPLC\\Notifications\\Notifications', 'on_license_issued' ) ), 'The email listener is registered again.' );
	}

	public function test_a123_suspend_and_release_ask_for_confirmation(): void {
		$license = $this->seed_license( array( 'customer_id' => $this->customer ) )['id'];
		$this->seed_activation( $license, array( 'site_url' => 'https://confirm-me.example' ) );
		wp_set_current_user( $this->manager );

		$html = $this->render_licenses( array( 'license' => (string) $license ) );
		$this->assertMatchesRegularExpression( '/data-wplc-confirm="Suspend this license\?[^"]*">\s*<input type="hidden" name="action" value="wplc_admin_license_status">\s*<input type="hidden" name="license_id" value="' . $license . '">\s*<input type="hidden" name="status" value="suspended">/', $html );
		$this->assertMatchesRegularExpression( '/data-wplc-confirm="Release https:\/\/confirm-me\.example\?[^"]*"><input type="hidden" name="action" value="wplc_admin_release_activation">/', $html );
	}

	// ---------------------------------------------------------------------
	// A-122: audit action names and the timezone.
	// ---------------------------------------------------------------------

	public function test_a122_audit_history_names_actions_and_the_timezone(): void {
		update_option( 'timezone_string', 'Europe/Berlin' );
		$license = $this->renewing_license();
		SupportActions::change_renewal_amount( $license, 4000, 4900, 'Discount', $this->manager );
		wp_set_current_user( $this->manager );

		$html = $this->render_licenses( array( 'license' => (string) $license ) );
		$this->assertStringContainsString( '<th scope="col">Date (Europe/Berlin)</th>', $html );
		$this->assertStringContainsString( 'Renewal amount changed by support<br><code>admin_renewal_amount_changed</code>', $html );
	}

	// ---------------------------------------------------------------------
	// A-121: getting started.
	// ---------------------------------------------------------------------

	public function test_a121_settings_page_lists_the_setup_steps_in_order(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$steps = Settings::setup_steps();
		$this->assertSame( array( 'secrets', 'product', 'webhook', 'release', 'test' ), array_column( $steps, 'key' ) );
		$this->assertFalse( $steps[1]['done'], 'No license product exists yet.' );

		$this->create_license_product();
		$this->seed_license( array( 'customer_id' => $this->customer ) );
		$steps = Settings::setup_steps();
		$this->assertTrue( $steps[1]['done'] );
		$this->assertTrue( $steps[4]['done'] );

		set_current_screen( 'woocommerce_page_wplc-settings' );
		ob_start();
		Settings::render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Getting started', $html );
		$this->assertStringContainsString( 'data-wplc-step="webhook"', $html );

		$links = Settings::plugin_action_links( array( 'deactivate' => 'Deactivate' ) );
		$this->assertStringContainsString( 'page=wplc-settings', (string) $links[0] );
	}

	// ---------------------------------------------------------------------
	// A-132: switched-off emails.
	// ---------------------------------------------------------------------

	public function test_a132_switched_off_license_email_is_named_in_diagnostics(): void {
		$emails = WC()->mailer()->get_emails();
		$this->assertArrayHasKey( 'WPLC_Email_Issued', $emails );
		$this->assertSame( array(), Diagnostics::disabled_license_emails() );

		$email = $emails['WPLC_Email_Issued'];
		$email->enabled = 'no';
		try {
			$this->assertSame( array( wp_strip_all_tags( $email->get_title() ) ), Diagnostics::disabled_license_emails() );
		} finally {
			$email->enabled = 'yes';
		}
	}
}
