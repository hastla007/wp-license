<?php
/**
 * Customer account: endpoint, rendered page, frontend form handling.
 *
 * No browser is involved: the page is rendered and its markup inspected, forms
 * are posted to the handler with the fields the page printed.
 *
 * @package WPLC
 */

use WPLC\Account\CustomerPortal;

require_once __DIR__ . '/helpers/PortalTestCase.php';

final class CustomerPortalIntegrationTest extends WPLC_Portal_TestCase {

	/**
	 * Customer.
	 *
	 * @var int
	 */
	private $customer;

	public function set_up(): void {
		parent::set_up();
		$this->customer = self::factory()->user->create( array( 'role' => 'customer' ) );
	}

	/**
	 * Seed a license that renews automatically, bought with a real order.
	 *
	 * @param array<string,mixed> $overrides License column overrides.
	 * @param string              $country   Billing country of the order.
	 * @return array{id:int,key:string,uuid:string}
	 */
	private function seed_auto_license( array $overrides = array(), string $country = 'DE' ): array {
		$product = $this->create_simple_product( '49.00' );
		$order   = $this->create_order( array( array( $product, 1 ) ), $this->customer );
		$order->set_billing_country( $country );
		$order->save();

		return $this->seed_license(
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
	}

	/**
	 * Switch taxes on with one standard rate for Germany, prices entered net.
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

	// ------------------------------------------------------------------
	// B-110: endpoint registered through WooCommerce.
	// ------------------------------------------------------------------

	public function test_B110_endpoint_is_a_woocommerce_query_var_with_title_and_filterable_slug(): void {
		$vars = WC()->query->get_query_vars();
		$this->assertArrayHasKey( 'wplc-licenses', $vars );
		$this->assertSame( 'wplc-licenses', $vars['wplc-licenses'] );
		$this->assertSame( 'Licenses', WC()->query->get_endpoint_title( 'wplc-licenses' ) );

		add_filter(
			'wplc_account_endpoint_slug',
			static function () {
				return 'Meine Lizenzen';
			}
		);
		$vars = WC()->query->get_query_vars();
		$this->assertSame( 'meine-lizenzen', $vars['wplc-licenses'], 'The slug filter is sanitized and reaches WooCommerce.' );
		$this->assertStringContainsString( 'meine-lizenzen', wc_get_account_endpoint_url( 'wplc-licenses' ) );
		$this->assertStringNotContainsString( 'wplc-licenses', wc_get_account_endpoint_url( 'wplc-licenses' ) );
	}

	public function test_B110_rewrite_endpoint_is_registered_once_for_pages_only(): void {
		global $wp_rewrite;

		$wp_rewrite->endpoints = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		WC()->query->add_endpoints();
		$ours = array();
		foreach ( (array) $wp_rewrite->endpoints as $endpoint ) {
			if ( 'wplc-licenses' === $endpoint[1] ) {
				$ours[] = $endpoint;
			}
		}

		$this->assertCount( 1, $ours, 'The endpoint must be registered exactly once (by WooCommerce).' );
		$this->assertSame( EP_PAGES, $ours[0][0], 'No EP_ROOT: /wplc-licenses/ on the site root must not exist as a duplicate.' );
		$this->assertFalse( has_action( 'init', array( CustomerPortal::class, 'register_endpoint' ) ), 'The endpoint is no longer registered a second time on init.' );
	}

	public function test_B110_activation_helper_registers_the_endpoint_with_the_woocommerce_mask(): void {
		global $wp_rewrite;

		$wp_rewrite->endpoints = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		CustomerPortal::register_endpoint();
		$this->assertSame( array( array( WC()->query->get_endpoints_mask(), 'wplc-licenses', 'wplc-licenses' ) ), $wp_rewrite->endpoints );
	}

	// ------------------------------------------------------------------
	// B-106 / B-111: no caching, template, hooks, assets from render().
	// ------------------------------------------------------------------

	public function test_B106_render_forbids_page_caching(): void {
		$this->seed_license( array( 'customer_id' => $this->customer ) );
		$this->render_portal( $this->customer );

		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE, 'render() must define DONOTCACHEPAGE.' );
	}

	public function test_B111_render_enqueues_assets_and_fires_template_hooks(): void {
		$license = $this->seed_license( array( 'customer_id' => $this->customer ) );
		wp_dequeue_style( 'wplc-account' );
		wp_dequeue_script( 'wplc-ui' );

		$seen = array();
		foreach ( array( 'wplc_before_account_licenses', 'wplc_after_account_licenses', 'wplc_before_account_license', 'wplc_after_account_license', 'wplc_account_license_actions', 'wplc_account_license_renewal' ) as $hook ) {
			add_action(
				$hook,
				static function () use ( &$seen, $hook ) {
					$seen[] = $hook;
					echo '<!-- hook:' . esc_html( $hook ) . ' -->';
				}
			);
		}
		$html = $this->render_portal( $this->customer );

		$this->assertTrue( wp_style_is( 'wplc-account', 'enqueued' ), 'The stylesheet is enqueued by render(), independent of is_account_page().' );
		$this->assertTrue( wp_script_is( 'wplc-ui', 'enqueued' ), 'The copy/confirm script is enqueued by render().' );
		$this->assertSame(
			array( 'wplc_before_account_licenses', 'wplc_before_account_license', 'wplc_account_license_renewal', 'wplc_account_license_actions', 'wplc_after_account_license', 'wplc_after_account_licenses' ),
			$seen
		);
		$this->assertLessThan( strpos( $html, $license['key'] ), strpos( $html, 'hook:wplc_before_account_licenses' ) );
		$this->assertGreaterThan( strpos( $html, $license['key'] ), strpos( $html, 'hook:wplc_after_account_licenses' ) );
	}

	public function test_B111_output_comes_from_an_overridable_template(): void {
		$this->seed_license( array( 'customer_id' => $this->customer, 'product_key' => 'overridden-product' ) );
		$file = trailingslashit( get_temp_dir() ) . 'wplc-license-override-' . wp_generate_password( 8, false ) . '.php';
		file_put_contents( $file, '<p class="custom-license"><?php echo esc_html( $view["title"] . "|" . $view["reference"] ); ?></p>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$override = static function ( $template, $template_name ) use ( $file ) {
			return 'myaccount/wplc-license.php' === $template_name ? $file : $template;
		};
		add_filter( 'wc_get_template', $override, 10, 2 );
		$html = $this->render_portal( $this->customer );
		remove_filter( 'wc_get_template', $override, 10 );
		unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

		$this->assertMatchesRegularExpression( '/<p class="custom-license">overridden-product\|WPLC-[0-9A-F]{8}<\/p>/', $html );
		$this->assertStringNotContainsString( 'wplc-license-key', $html, 'The default license markup is replaced by the override.' );
		$this->assertStringContainsString( 'Your licenses', $html, 'The list template around it is still the default.' );
	}

	public function test_asset_version_changes_with_the_file(): void {
		// B-124: the version is not the constant plugin version alone.
		$version = CustomerPortal::asset_version( 'assets/js/wplc-ui.js' );
		$this->assertStringStartsWith( WPLC_VERSION . '.', $version );
		$this->assertSame( (string) filemtime( WPLC_PATH . 'assets/js/wplc-ui.js' ), substr( $version, strlen( WPLC_VERSION ) + 1 ) );
		$this->assertSame( WPLC_VERSION, CustomerPortal::asset_version( 'assets/js/does-not-exist.js' ) );
	}

	// ------------------------------------------------------------------
	// B-64: the key is only shown when it reproduces the stored verifier.
	// ------------------------------------------------------------------

	public function test_B64_key_is_shown_only_when_it_matches_the_stored_hash(): void {
		$good = $this->seed_license( array( 'customer_id' => $this->customer, 'product_key' => 'good-product' ) );
		// Same secret, but the stored verifier belongs to another key: what a changed salt or a wrong secret looks like.
		$bad = $this->seed_license(
			array(
				'customer_id'      => $this->customer,
				'product_key'      => 'bad-product',
				'license_key_hash' => hash( 'sha256', 'some other key' ),
			)
		);

		$html = $this->render_portal( $this->customer );

		$this->assertStringContainsString( $good['key'], $html );
		$this->assertStringNotContainsString( $bad['key'], $html, 'A key that does not match the stored hash would be a wrong key and must not be shown.' );
		$this->assertSame( 1, substr_count( $html, 'Currently unavailable. Please contact support.' ) );
	}

	// ------------------------------------------------------------------
	// T-139 / B-151 / A-55: what the page tells the customer.
	// ------------------------------------------------------------------

	public function test_T139_heading_shows_the_product_and_a_readable_plan_name(): void {
		$this->seed_license( array( 'customer_id' => $this->customer, 'product_key' => 'my-product', 'plan_key' => 'single-site_pro' ) );
		$html = $this->render_portal( $this->customer );

		$this->assertMatchesRegularExpression( '/<h3[^>]*>my-product<\/h3>/', $html, 'The heading is the product name without the internal plan key.' );
		$this->assertStringNotContainsString( 'single-site_pro', $html );
		$this->assertStringContainsString( 'Single Site Pro', $html );
	}

	public function test_B151_account_lists_three_install_steps_for_a_usable_license(): void {
		$this->seed_license( array( 'customer_id' => $this->customer ) );
		$html = $this->render_portal( $this->customer );

		$this->assertStringContainsString( 'How to install and activate', $html );
		$this->assertSame( 1, preg_match( '/<details class="wplc-install-steps">.*?<\/details>/s', $html, $block ) );
		$this->assertSame( 3, substr_count( $block[0], '<li>' ) );
		$this->assertStringContainsString( 'Upload Plugin', $block[0] );
		$this->assertStringContainsString( 'enter your license key', $block[0] );
	}

	public function test_A55_download_hint_when_no_release_is_published(): void {
		$this->seed_license( array( 'customer_id' => $this->customer ) );
		$html = $this->render_portal( $this->customer );

		// Printed once, by the download button itself.
		$this->assertSame( 1, substr_count( $html, 'No download is available for this product yet.' ) );
	}

	/**
	 * A-55: every status that is not simply "works" explains itself.
	 *
	 * @dataProvider data_status_help
	 *
	 * @param array<string,mixed> $overrides License overrides.
	 * @param string              $expected  Text the page must contain.
	 */
	public function test_A55_status_is_explained( array $overrides, string $expected ): void {
		$this->seed_license( array_merge( array( 'customer_id' => $this->customer ), $overrides ) );
		$html = $this->render_portal( $this->customer );

		$this->assertStringContainsString( 'wplc-license-status-help', $html );
		$this->assertStringContainsString( $expected, $html );
		if ( in_array( $overrides['status'], array( 'expired', 'suspended', 'revoked' ), true ) ) {
			$this->assertStringNotContainsString( 'How to install and activate', $html, 'No install steps for a license that cannot be used.' );
			$this->assertStringNotContainsString( 'No download is available', $html );
		}
	}

	/**
	 * @return array<string,array{0:array<string,mixed>,1:string}>
	 */
	public function data_status_help(): array {
		$past   = gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS );
		$longer = gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS );

		return array(
			'past due'            => array( array( 'status' => 'past_due', 'paid_until' => $past, 'renewal_enabled' => 1 ), 'We could not collect the renewal payment. The license keeps working until' ),
			'expired, renewable'  => array( array( 'status' => 'expired', 'paid_until' => $past ), 'You can still renew it until' ),
			'expired for good'    => array( array( 'status' => 'expired', 'paid_until' => $longer ), 'can no longer be renewed' ),
			'suspended'           => array( array( 'status' => 'suspended' ), 'This license is suspended.' ),
			'revoked'             => array( array( 'status' => 'revoked' ), 'This license was revoked' ),
		);
	}

	public function test_A55_active_license_needs_no_explanation(): void {
		$this->seed_license( array( 'customer_id' => $this->customer ) );
		$this->assertStringNotContainsString( 'wplc-license-status-help', $this->render_portal( $this->customer ) );
	}

	// ------------------------------------------------------------------
	// B-128: next charge with amount including tax; confirmation on "undo cancellation".
	// ------------------------------------------------------------------

	public function test_B128_account_shows_date_and_gross_amount_of_the_next_charge(): void {
		$this->enable_german_vat();
		$paid_until = gmdate( 'Y-m-d H:i:s', time() + 200 * DAY_IN_SECONDS );
		$this->seed_auto_license( array( 'paid_until' => $paid_until, 'next_payment_at' => $paid_until ) );

		$html = $this->render_portal( $this->customer );

		$this->assertSame( 1, preg_match( '/<dl class="wplc-renewal-terms">.*?<\/dl>/s', $html, $block ), 'The renewal terms block is missing.' );
		$text = html_entity_decode( wp_strip_all_tags( $block[0] ), ENT_QUOTES );
		$this->assertStringContainsString( 'Next renewal', $text );
		$this->assertStringContainsString( wp_date( get_option( 'date_format' ), strtotime( $paid_until . ' UTC' ) ), $text );
		$this->assertStringContainsString( '58.31', $text, '49.00 net plus 19 % VAT for the German billing address.' );
		$this->assertStringContainsString( 'incl. tax', $text );
		$this->assertStringNotContainsString( '49.00', $text, 'The net amount is not what the customer pays.' );
		$this->assertStringContainsString( 'every year', $text );
	}

	public function test_B128_amount_without_tax_when_the_shop_charges_none(): void {
		update_option( 'woocommerce_calc_taxes', 'no' );
		$this->seed_auto_license();
		$html = $this->render_portal( $this->customer );

		$this->assertSame( 1, preg_match( '/<dl class="wplc-renewal-terms">.*?<\/dl>/s', $html, $block ) );
		$this->assertStringContainsString( '49.00', $block[0] );
		$this->assertStringNotContainsString( 'incl. tax', $block[0] );
	}

	public function test_B128_no_charge_information_for_a_license_that_does_not_renew(): void {
		$this->seed_license( array( 'customer_id' => $this->customer ) );
		$this->seed_auto_license( array( 'cancel_at_period_end' => 1, 'product_key' => 'cancelled-product' ) );

		$this->assertStringNotContainsString( 'wplc-renewal-terms', $this->render_portal( $this->customer ) );
	}

	public function test_B128_undo_cancellation_asks_with_amount_and_date(): void {
		$this->enable_german_vat();
		$paid_until = gmdate( 'Y-m-d H:i:s', time() + 200 * DAY_IN_SECONDS );
		$license    = $this->seed_auto_license( array( 'cancel_at_period_end' => 1, 'paid_until' => $paid_until, 'next_payment_at' => $paid_until ) );

		$form = $this->find_form( $this->render_portal( $this->customer ), array( 'value="wplc_resume_license"', 'name="license_id" value="' . $license['id'] . '"' ) );
		$this->assertNotSame( '', $form, 'The "undo cancellation" form is missing.' );
		$this->assertSame( 1, preg_match( '/data-wplc-confirm="([^"]*)"/', $form, $match ), 'Undoing a cancellation re-activates the payment authorization and must ask first.' );
		$question = html_entity_decode( $match[1], ENT_QUOTES );
		$this->assertStringContainsString( '58.31', $question );
		$this->assertStringContainsString( 'incl. tax', $question );
		$this->assertStringContainsString( wp_date( get_option( 'date_format' ), strtotime( $paid_until . ' UTC' ) ), $question );
		$this->assertStringContainsString( 'every year', $question );
	}

	// ------------------------------------------------------------------
	// B-153 / B-132: buttons.
	// ------------------------------------------------------------------

	public function test_B153_renew_now_is_hidden_while_automatic_renewal_is_active(): void {
		$soon   = gmdate( 'Y-m-d H:i:s', time() + 10 * DAY_IN_SECONDS );
		$auto   = $this->seed_auto_license( array( 'paid_until' => $soon, 'next_payment_at' => $soon ) );
		$manual = $this->seed_license( array( 'customer_id' => $this->customer, 'paid_until' => $soon, 'product_key' => 'manual-product' ) );
		$gone   = $this->seed_auto_license( array( 'paid_until' => $soon, 'next_payment_at' => $soon, 'cancel_at_period_end' => 1, 'product_key' => 'cancelled-product' ) );
		$failed = $this->seed_auto_license( array( 'status' => 'past_due', 'paid_until' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ), 'product_key' => 'pastdue-product' ) );

		$html = $this->render_portal( $this->customer );

		$this->assertSame( '', $this->find_form( $html, array( 'value="wplc_start_renewal"', 'name="license_id" value="' . $auto['id'] . '"' ) ), 'With automatic renewal active there is no "Renew now".' );
		$this->assertNotSame( '', $this->find_form( $html, array( 'value="wplc_start_renewal"', 'name="license_id" value="' . $manual['id'] . '"' ) ) );
		$this->assertNotSame( '', $this->find_form( $html, array( 'value="wplc_start_renewal"', 'name="license_id" value="' . $gone['id'] . '"' ) ), 'A cancelled license can be renewed by hand.' );
		$this->assertNotSame( '', $this->find_form( $html, array( 'value="wplc_start_renewal"', 'name="license_id" value="' . $failed['id'] . '"' ) ), 'After a failed automatic charge the customer can pay by hand.' );
	}

	public function test_B132_buttons_carry_the_license_reference_in_their_accessible_name(): void {
		$soon   = gmdate( 'Y-m-d H:i:s', time() + 10 * DAY_IN_SECONDS );
		$manual = $this->seed_license( array( 'customer_id' => $this->customer, 'paid_until' => $soon, 'product_key' => 'manual-product' ) );
		$auto   = $this->seed_auto_license( array( 'product_key' => 'auto-product' ) );
		$gone   = $this->seed_auto_license( array( 'cancel_at_period_end' => 1, 'product_key' => 'cancelled-product' ) );
		$html   = $this->render_portal( $this->customer );

		$expect = array(
			array( 'wplc_start_renewal', $manual, 'Renew now', 'manual-product' ),
			array( 'wplc_cancel_license', $auto, 'Cancel at period end', 'auto-product' ),
			array( 'wplc_resume_license', $gone, 'Undo cancellation', 'cancelled-product' ),
		);
		foreach ( $expect as $case ) {
			$form   = $this->find_form( $html, array( 'value="' . $case[0] . '"', 'name="license_id" value="' . $case[1]['id'] . '"' ) );
			$prefix = $this->license( $case[1]['id'] )['key_prefix'];
			$this->assertStringContainsString( 'aria-label="' . $case[2] . ': ' . $case[3] . ', license ' . $prefix . '"', $form, $case[0] );
			$this->assertStringContainsString( '>' . $case[2] . '</button>', $form, 'The visible label stays short.' );
		}
	}

	// ------------------------------------------------------------------
	// B-17 / A-120: forms are handled on the frontend.
	// ------------------------------------------------------------------

	public function test_B17_no_customer_form_posts_to_wp_admin(): void {
		$soon       = gmdate( 'Y-m-d H:i:s', time() + 10 * DAY_IN_SECONDS );
		$manual     = $this->seed_license( array( 'customer_id' => $this->customer, 'paid_until' => $soon ) );
		$auto       = $this->seed_auto_license();
		$cancelled  = $this->seed_auto_license( array( 'cancel_at_period_end' => 1 ) );
		$activation = $this->seed_activation( $manual['id'] );

		$html = $this->render_portal( $this->customer );

		$this->assertSame( 5, preg_match_all( '/<form\b[^>]*>/', $html, $forms ), 'Expected renew (manual and cancelled license), cancel, undo and deactivate forms.' );
		$this->assertStringNotContainsString( 'admin-post.php', $html );
		$this->assertStringNotContainsString( 'wp-admin', $html );
		foreach ( $forms[0] as $tag ) {
			$this->assertStringContainsString( 'action="' . esc_url( wc_get_account_endpoint_url( 'wplc-licenses' ) ) . '"', $tag );
		}
		$this->assertStringNotContainsString( 'id="_wpnonce"', $html, 'A-127: several forms on one page must not share an id.' );

		$fields = $this->form_fields( $this->find_form( $html, array( 'value="wplc_deactivate_site"' ) ) );
		$this->assertSame( '1', $fields['wplc_portal'] );
		$this->assertSame( (string) $activation['id'], $fields['activation_id'] );
		unset( $auto, $cancelled );
	}

	public function test_B17_cancel_form_as_rendered_cancels_on_the_frontend_and_sends_a_confirmation(): void {
		$license = $this->seed_auto_license();
		$fields  = $this->form_fields( $this->find_form( $this->render_portal( $this->customer ), array( 'value="wplc_cancel_license"' ) ) );

		$result = $this->front_post( $fields );

		$this->assertRedirectWithNotice( 'cancel_scheduled', $result );
		$this->assertStringStartsWith( wc_get_account_endpoint_url( 'wplc-licenses' ), $result['location'], 'The customer returns to the license page, not to wp-admin.' );
		$this->assertSame( 1, (int) $this->license( $license['id'] )['cancel_at_period_end'] );
		$this->assertSame( array( 'cancellation_scheduled' ), $this->audit_actions( 'license', $license['id'] ) );

		// A-21: confirmation by email.
		$mails = $this->mails();
		$this->assertCount( 1, $mails );
		$this->assertStringContainsString( 'cancelled', $mails[0]['subject'] );
	}

	public function test_B17_undo_and_deactivate_work_on_the_frontend(): void {
		$license    = $this->seed_auto_license( array( 'cancel_at_period_end' => 1 ) );
		$activation = $this->seed_activation( $license['id'] );
		$html       = $this->render_portal( $this->customer );

		$result = $this->front_post( $this->form_fields( $this->find_form( $html, array( 'value="wplc_resume_license"' ) ) ) );
		$this->assertRedirectWithNotice( 'cancel_withdrawn', $result );
		$this->assertSame( 0, (int) $this->license( $license['id'] )['cancel_at_period_end'] );

		wc_clear_notices();
		$result = $this->front_post( $this->form_fields( $this->find_form( $html, array( 'value="wplc_deactivate_site"' ) ) ) );
		$this->assertRedirectWithNotice( 'site_deactivated', $result );
		$this->assertSame( 'deactivated', $this->activation( $activation['id'] )['status'] );
	}

	public function test_A120_expired_form_token_returns_to_the_page_with_a_notice(): void {
		$license = $this->seed_auto_license();
		wp_set_current_user( $this->customer );

		foreach ( array( '', 'expired-or-forged', wp_create_nonce( 'wplc_resume_license_' . $license['id'] ) ) as $nonce ) {
			wc_clear_notices();
			$result = $this->front_post(
				array(
					'wplc_portal' => '1',
					'action'      => 'wplc_cancel_license',
					'license_id'  => $license['id'],
					'_wpnonce'    => $nonce,
				)
			);
			$this->assertRedirectWithNotice( 'session_expired', $result );
			$this->assertStringNotContainsString( 'wp-admin', $result['location'] );
		}
		$this->assertSame( 0, (int) $this->license( $license['id'] )['cancel_at_period_end'], 'Nothing may change without a valid token.' );
		$this->assertSame( array(), $this->mails() );
	}

	public function test_A120_signed_out_visitor_is_sent_to_the_account_page(): void {
		$license = $this->seed_auto_license();
		wp_set_current_user( $this->customer );
		$nonce = wp_create_nonce( 'wplc_cancel_license_' . $license['id'] );
		wp_set_current_user( 0 );

		$result = $this->front_post(
			array(
				'wplc_portal' => '1',
				'action'      => 'wplc_cancel_license',
				'license_id'  => $license['id'],
				'_wpnonce'    => $nonce,
				'wplc_return' => 'https://evil.example/phish',
			)
		);

		$this->assertRedirectWithNotice( 'login_required', $result );
		$this->assertStringStartsWith( wc_get_account_endpoint_url( 'wplc-licenses' ), $result['location'] );
		$this->assertSame( 0, (int) $this->license( $license['id'] )['cancel_at_period_end'] );
	}

	public function test_B17_frontend_handler_checks_ownership(): void {
		$other   = self::factory()->user->create( array( 'role' => 'customer' ) );
		$license = $this->seed_auto_license();
		$site    = $this->seed_activation( $license['id'] );

		wp_set_current_user( $other );
		$result = $this->front_post(
			array(
				'wplc_portal' => '1',
				'action'      => 'wplc_cancel_license',
				'license_id'  => $license['id'],
				'_wpnonce'    => wp_create_nonce( 'wplc_cancel_license_' . $license['id'] ),
			)
		);
		$this->assertRedirectWithNotice( 'license_not_found', $result );
		$this->assertSame( 0, (int) $this->license( $license['id'] )['cancel_at_period_end'] );

		wc_clear_notices();
		$result = $this->front_post(
			array(
				'wplc_portal'   => '1',
				'action'        => 'wplc_deactivate_site',
				'activation_id' => $site['id'],
				'_wpnonce'      => wp_create_nonce( 'wplc_deactivate_site_' . $site['id'] ),
			)
		);
		$this->assertRedirectWithNotice( 'site_not_found', $result );
		$this->assertSame( 'active', $this->activation( $site['id'] )['status'] );
		$this->assertSame( array(), $this->mails() );
	}

	public function test_B17_return_address_cannot_leave_the_site(): void {
		$license = $this->seed_auto_license();
		wp_set_current_user( $this->customer );
		$result = $this->front_post(
			array(
				'wplc_portal' => '1',
				'action'      => 'wplc_cancel_license',
				'license_id'  => $license['id'],
				'_wpnonce'    => wp_create_nonce( 'wplc_cancel_license_' . $license['id'] ),
				'wplc_return' => 'https://evil.example/phish',
			)
		);

		$this->assertSame( 'redirect', $result['outcome'] );
		$this->assertStringStartsWith( wc_get_account_endpoint_url( 'wplc-licenses' ), $result['location'] );
	}

	public function test_B17_requests_without_the_form_marker_are_ignored(): void {
		$license = $this->seed_auto_license();
		wp_set_current_user( $this->customer );
		$fields = array(
			'action'     => 'wplc_cancel_license',
			'license_id' => $license['id'],
			'_wpnonce'   => wp_create_nonce( 'wplc_cancel_license_' . $license['id'] ),
		);

		$this->assertSame( 'returned', $this->front_post( $fields )['outcome'], 'Other POST requests of the site are not touched.' );
		$this->assertSame( 'returned', $this->front_post( array_merge( $fields, array( 'wplc_portal' => '1', 'action' => 'something_else' ) ) )['outcome'] );

		// A GET request never changes anything.
		$_POST    = wp_slash( array_merge( $fields, array( 'wplc_portal' => '1' ) ) );
		$_REQUEST = $_POST;
		CustomerPortal::handle_front_post();
		$this->assertSame( 0, (int) $this->license( $license['id'] )['cancel_at_period_end'] );
	}

	public function test_B17_old_admin_post_forms_keep_working_and_signed_out_posts_get_a_way_back(): void {
		$license = $this->seed_auto_license();
		wp_set_current_user( $this->customer );
		$result = $this->run_admin_post(
			'wplc_cancel_license',
			array(
				'license_id' => $license['id'],
				'_wpnonce'   => wp_create_nonce( 'wplc_cancel_license_' . $license['id'] ),
			)
		);
		$this->assertSame( 'redirect', $result['outcome'] );
		$this->assertSame( 1, (int) $this->license( $license['id'] )['cancel_at_period_end'] );
		$this->assertCount( 1, $this->mails(), 'The legacy path confirms the cancellation as well.' );

		// A-120: admin-post.php for a visitor whose session ended used to answer with an empty page.
		foreach ( array( 'wplc_deactivate_site', 'wplc_cancel_license', 'wplc_resume_license' ) as $action ) {
			$this->assertNotFalse( has_action( 'admin_post_nopriv_' . $action ), $action );
		}
		wp_set_current_user( 0 );
		$this->halt_instead_of_exit();
		try {
			do_action( 'admin_post_nopriv_wplc_cancel_license' );
			$this->fail( 'Expected a redirect.' );
		} catch ( WPLC_Test_Halt $halt ) {
			$this->assertStringStartsWith( wc_get_account_endpoint_url( 'wplc-licenses' ), $halt->location );
		}
	}

	public function test_B17_renew_now_form_starts_the_renewal_from_the_frontend(): void {
		$product = $this->create_license_product();
		$order   = $this->create_paid_order( array( array( $product, 1 ) ), $this->customer );
		$license = $this->licenses_for_order( $order->get_id() )[0];
		global $wpdb;
		$soon = gmdate( 'Y-m-d H:i:s', time() + 10 * DAY_IN_SECONDS );
		$wpdb->update( $this->tables()['licenses'], array( 'paid_until' => $soon, 'next_payment_at' => $soon ), array( 'id' => (int) $license['id'] ) );

		$form = $this->find_form( $this->render_portal( $this->customer ), array( 'value="wplc_start_renewal"' ) );
		$this->assertNotSame( '', $form );
		$result = $this->front_post( $this->form_fields( $form ) );

		$this->assertSame( 'redirect', $result['outcome'], $result['message'] );
		$this->assertStringContainsString( 'order-pay', $result['location'], 'The billing handler was reached and sends the customer to the payment page.' );
		$this->assertStringContainsString( 'pay_for_order=true', $result['location'] );
	}

	public function test_B17_download_form_needs_no_retargeting(): void {
		// Wave 2: CustomerDownload::render_button() posts to the portal URL itself (tested in
		// ReleaseUpdateIntegrationTest); the string surgery on its markup is gone.
		$this->assertFalse( method_exists( CustomerPortal::class, 'retarget_form' ) );
		$method = new ReflectionMethod( \WPLC\Update\CustomerDownload::class, 'form_url' );
		$method->setAccessible( true );
		$this->assertSame( CustomerPortal::form_url(), $method->invoke( null ) );
		$this->assertStringNotContainsString( 'wp-admin', (string) $method->invoke( null ) );
	}

	public function test_B17_download_post_with_an_expired_token_does_not_die_in_the_handler(): void {
		$license = $this->seed_license( array( 'customer_id' => $this->customer ) );
		wp_set_current_user( $this->customer );
		$result = $this->front_post(
			array(
				'wplc_portal' => '1',
				'action'      => 'wplc_download_package',
				'license_id'  => $license['id'],
				'_wpnonce'    => 'expired',
			)
		);
		$this->assertRedirectWithNotice( 'session_expired', $result );

		// With a valid token the request reaches the download handler of the update package (no release: 404 there).
		wc_clear_notices();
		$result = $this->front_post(
			array(
				'wplc_portal' => '1',
				'action'      => 'wplc_download_package',
				'license_id'  => $license['id'],
				'_wpnonce'    => wp_create_nonce( \WPLC\Update\CustomerDownload::nonce_action( $license['id'] ) ),
			)
		);
		$this->assertSame( array( 'die', 404 ), array( $result['outcome'], $result['status'] ) );
	}

	// ------------------------------------------------------------------
	// B-117: shortcode and thank-you page.
	// ------------------------------------------------------------------

	public function test_B117_shortcode_renders_the_list_with_forms_that_post_to_its_page(): void {
		$license = $this->seed_auto_license( array( 'product_key' => 'shortcode-product' ) );
		$page_id = self::factory()->post->create( array( 'post_type' => 'page', 'post_content' => '[wplc_licenses]' ) );
		$this->go_to( get_permalink( $page_id ) );
		wp_set_current_user( $this->customer );
		wp_dequeue_style( 'wplc-account' );
		$GLOBALS['post'] = get_post( $page_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$this->assertTrue( shortcode_exists( 'wplc_licenses' ) );
		$html = do_shortcode( '[wplc_licenses]' );

		$this->assertStringContainsString( 'shortcode-product', $html );
		$this->assertStringContainsString( $license['key'], $html );
		$this->assertTrue( wp_style_is( 'wplc-account', 'enqueued' ), 'Builder pages get the assets from the shortcode.' );
		$form   = $this->find_form( $html, array( 'value="wplc_cancel_license"' ) );
		$fields = $this->form_fields( $form );
		$this->assertStringContainsString( 'action="' . esc_url( get_permalink( $page_id ) ) . '"', $form );
		$this->assertSame( get_permalink( $page_id ), $fields['wplc_return'] );

		$result = $this->front_post( $fields );
		$this->assertSame( 'redirect', $result['outcome'] );
		$this->assertStringStartsWith( get_permalink( $page_id ), $result['location'], 'After the action the customer is back on the builder page.' );
		$this->assertSame( 1, (int) $this->license( $license['id'] )['cancel_at_period_end'] );
	}

	public function test_B117_shortcode_asks_signed_out_visitors_to_sign_in(): void {
		$this->seed_auto_license();
		wp_set_current_user( 0 );
		$html = do_shortcode( '[wplc_licenses]' );

		$this->assertStringContainsString( 'Please sign in to view your licenses.', $html );
		$this->assertStringNotContainsString( 'WPLC-', $html );
	}

	public function test_B117_thankyou_page_points_to_the_license_without_showing_the_key(): void {
		$product = $this->create_license_product();
		$paid    = $this->create_paid_order( array( array( $product, 1 ) ), $this->customer );
		$license = $this->licenses_for_order( $paid->get_id() )[0];
		$key     = \WPLC\License\LicenseKey::for_uuid( $license['license_uuid'], $license['license_key_secret_id'] );

		ob_start();
		do_action( 'woocommerce_thankyou', $paid->get_id() );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Your license is ready.', $html );
		$this->assertStringContainsString( esc_url( wc_get_account_endpoint_url( 'wplc-licenses' ) ), $html );
		$this->assertStringNotContainsString( $key, $html, 'The page opens with the order key alone and must not show the license key.' );

		// Not paid yet (bank transfer): the license comes later.
		$product->update_meta_data( '_wplc_is_license_product', 'yes' );
		$product->save();
		$pending = $this->create_order( array( array( $product, 1 ) ), $this->customer );
		ob_start();
		do_action( 'woocommerce_thankyou', $pending->get_id() );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Your license is created as soon as your payment has been confirmed.', $html );

		// An order without a license product gets no hint.
		$plain = $this->create_paid_order( array( array( $this->create_simple_product(), 1 ) ), $this->customer );
		ob_start();
		do_action( 'woocommerce_thankyou', $plain->get_id() );
		$this->assertStringNotContainsString( 'woocommerce-wplc-license-hint', (string) ob_get_clean() );
	}
}
