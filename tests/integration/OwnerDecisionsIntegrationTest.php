<?php
/**
 * Settings for the sale, part 6: owner decisions of 23.11.2026 in WordPress
 * (SET-LIC-20 beta per plan, SET-LIC-17 refunds and disputes, E-08 plan changes, E-04 key prefix).
 *
 * @package WPLC
 */

use WPLC\Account\PublicCancellation;
use WPLC\Admin\CatalogCsv;
use WPLC\Admin\LicenseSearch;
use WPLC\Admin\Settings;
use WPLC\Api\V1\EntitlementService;
use WPLC\Billing\PlanUpgrade;
use WPLC\Catalog\Catalog;
use WPLC\Catalog\ProductEditor;
use WPLC\Gateway\StripeEventHandler;
use WPLC\License\AuditLog;
use WPLC\License\AuditReason;
use WPLC\License\LicenseKey;
use WPLC\License\LicenseLifecycle;
use WPLC\License\PlanChange;
use WPLC\Security\ResponseSigner;
use WPLC\Settings\Changes;
use WPLC\Settings\Registry;
use WPLC\Update\UpdateChannels;

final class OwnerDecisionsIntegrationTest extends WPLC_Integration_TestCase {

	/**
	 * Administrator.
	 *
	 * @var int
	 */
	private $admin;

	public function set_up(): void {
		parent::set_up();
		ResponseSigner::reset();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		delete_option( Settings::OPTION );
		add_filter( 'sanitize_option_' . Settings::OPTION, array( Settings::class, 'sanitize' ) );
		wp_set_current_user( $this->admin );
		$GLOBALS['wp_settings_errors'] = array();
	}

	public function tear_down(): void {
		remove_all_filters( 'sanitize_option_' . Settings::OPTION );
		foreach ( array( 'wplc_update_packages', 'wplc_license_update_channels', 'wplc/license_needs_review', 'wplc_partial_refund_review' ) as $hook ) {
			remove_all_filters( $hook );
		}
		$_POST                         = array();
		$GLOBALS['wp_settings_errors'] = array();
		delete_option( Settings::OPTION );
		ResponseSigner::reset();
		parent::tear_down();
	}

	/**
	 * Save settings as the settings form does.
	 *
	 * @param array<string,mixed> $values Submitted fields.
	 * @return void
	 */
	private function submit( array $values ): void {
		$_POST['option_page'] = Settings::OPTION;
		update_option( Settings::OPTION, array_merge( Registry::stored(), $values ) );
		$_POST = array();
	}

	private function capture( callable $callback ): string {
		ob_start();
		try {
			$callback();
		} finally {
			$html = (string) ob_get_clean();
		}

		return $html;
	}

	/**
	 * Audit actions of one object.
	 *
	 * @param string $type Object type.
	 * @param int    $id   Object ID.
	 * @return string[]
	 */
	private function audit_actions( string $type, int $id ): array {
		global $wpdb;

		return array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT action FROM {$this->tables()['audit_log']} WHERE object_type = %s AND object_id = %d ORDER BY id ASC", $type, $id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Buy one license of a product.
	 *
	 * @param WC_Product $product Product.
	 * @param int        $quantity Quantity.
	 * @return array{order:WC_Order,licenses:array<int,array<string,mixed>>}
	 */
	private function buy( WC_Product $product, int $quantity = 1 ): array {
		$order = $this->create_paid_order( array( array( $product, $quantity ) ), self::factory()->user->create( array( 'role' => 'customer' ) ) );

		return array(
			'order'    => $order,
			'licenses' => $this->licenses_for_order( $order->get_id() ),
		);
	}

	/**
	 * Offer a stable and a beta package through the update filter, by the channel of the request.
	 *
	 * @return void
	 */
	private function offer_stable_and_beta(): void {
		add_filter(
			'wplc_update_packages',
			static function ( $packages, $product_key, $context ) {
				$beta                     = 'beta' === ( $context['channel'] ?? '' );
				$version                  = $beta ? '2.0.0-beta1' : '1.5.0';
				$packages['test-product'] = array(
					'version'     => $version,
					'package_url' => 'https://' . wp_parse_url( home_url(), PHP_URL_HOST ) . '/downloads/test-product-' . $version . '.zip',
					'sha256'      => str_repeat( 'ab', 32 ),
				);
				return $packages;
			},
			10,
			3
		);
	}

	/**
	 * Signed data of an update check.
	 *
	 * @param array{id:int,uuid:string,secret:string} $site    Activation.
	 * @param string                                  $channel Requested channel.
	 * @return array<string,mixed>
	 */
	private function update_check( array $site, string $channel ): array {
		$response = $this->dispatch( $this->api_request( 'POST', '/wplc/v1/updates/check', array( 'product_key' => 'test-product', 'current_version' => '1.0.0', 'channel' => $channel ), $site ) );
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );

		return $response->get_data()['data'];
	}

	// ------------------------------------------------------------------ SET-LIC-20

	/** SET-LIC-20: the signed answers list the channels; a client without the right gets stable; plan and filter decide. */
	public function test_beta_only_for_licenses_whose_plan_allows_it(): void {
		$open    = $this->create_license_product();
		$closed  = $this->create_license_product( array( 'plan_key' => 'agency', 'overrides' => array( 'beta_allowed' => '0' ) ) );
		$this->assertSame( '0', (string) Catalog::find_exact_mapping( $closed->get_id() )['beta_allowed'] );
		$this->assertNull( Catalog::find_exact_mapping( $open->get_id() )['beta_allowed'], 'A new plan uses the global setting.' );
		$with    = $this->buy( $open )['licenses'][0];
		$without = $this->buy( $closed )['licenses'][0];

		// Default (as before): beta for every license whose plan has no rule.
		$this->assertSame( array( 'stable', 'beta' ), UpdateChannels::for_license( $with ) );
		$this->assertSame( array( 'stable' ), UpdateChannels::for_license( $without ) );
		$site = $this->seed_activation( (int) $with['id'] );
		$this->assertSame( array( 'stable', 'beta' ), EntitlementService::snapshot( $with, $this->activation( $site['id'] ) )['update_channels'] );

		// The real endpoints: the entitlement answer carries the list, the update check follows it.
		$response = $this->dispatch( $this->api_request( 'POST', '/wplc/v1/entitlements/check', array(), $site ) );
		$this->assertSame( array( 'stable', 'beta' ), $response->get_data()['data']['update_channels'] );
		$this->offer_stable_and_beta();
		$data = $this->update_check( $site, 'beta' );
		$this->assertSame( array( 'beta', '2.0.0-beta1' ), array( $data['channel'], $data['version'] ) );

		$closed_site = $this->seed_activation( (int) $without['id'] );
		$response    = $this->dispatch( $this->api_request( 'POST', '/wplc/v1/entitlements/check', array(), $closed_site ) );
		$this->assertSame( array( 'stable' ), $response->get_data()['data']['update_channels'] );
		$data = $this->update_check( $closed_site, 'beta' );
		$this->assertSame( array( 'stable', '1.5.0' ), array( $data['channel'], $data['version'] ), 'Asked for beta, served stable.' );
		$this->assertTrue( $data['update_available'] );

		// Global off: only plans that allow beta get it.
		$this->submit( array( 'beta_updates' => '0' ) );
		$this->assertSame( array( 'stable' ), UpdateChannels::for_license( $with ) );
		$this->assertTrue( Catalog::save_mapping( $closed->get_id(), 0, $this->mapping_data( array( 'plan_key' => 'agency', 'overrides' => array( 'beta_allowed' => '1' ) ) ) ) );
		$this->assertSame( array( 'stable', 'beta' ), UpdateChannels::for_license( $this->license( (int) $without['id'] ) ), 'The plan applies to its existing licenses at once.' );
		$this->assertSame( '2.0.0-beta1', $this->update_check( $closed_site, 'beta' )['version'] );

		// The filter decides last.
		add_filter( 'wplc_license_update_channels', static fn() => array( 'stable', 'beta' ) );
		$this->assertSame( array( 'stable', 'beta' ), UpdateChannels::for_license( $with ) );
		remove_all_filters( 'wplc_license_update_channels' );
		add_filter( 'wplc_license_update_channels', static fn() => array( 'beta' ) );
		$this->assertSame( array( 'stable', 'beta' ), UpdateChannels::for_license( $with ), 'Stable is always part of the list.' );

		// A shop manager cannot change the manufacturer rule.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		$this->submit( array( 'beta_updates' => '1' ) );
		$this->assertFalse( Registry::bool( 'beta_updates' ) );
	}

	/** SET-LIC-20: the product editor offers the beta right as a choice; the catalog CSV carries it; older files still import. */
	public function test_product_editor_and_catalog_csv_carry_the_beta_right(): void {
		$product = $this->create_license_product();
		require_once WC()->plugin_path() . '/includes/admin/wc-meta-box-functions.php';
		$html = $this->capture(
			static function () use ( $product ) {
				global $post, $thepostid;
				$post      = get_post( $product->get_id() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
				$thepostid = $product->get_id(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
				ProductEditor::render_product_fields();
			}
		);
		$this->assertMatchesRegularExpression( '/<select[^>]+name="_wplc_beta_allowed"/', $html );
		$this->assertStringContainsString( 'Global setting (allowed)', $html );

		$_POST = wp_slash(
			array(
				'woocommerce_meta_nonce'     => wp_create_nonce( 'woocommerce_save_data' ),
				ProductEditor::PRESENT_FIELD => '1',
				'_wplc_is_license_product'   => 'yes',
				'_wplc_product_key'          => 'test-product',
				'_wplc_plan_key'             => 'single',
				'_wplc_billing_interval'     => 'year',
				'_wplc_live_site_limit'      => '1',
				'_wplc_support_level'        => 'standard',
				'_wplc_entitlements_json'    => '',
				'_wplc_beta_allowed'         => '0',
			)
		);
		ProductEditor::save_product_fields( $product->get_id() );
		$_POST = array();
		$this->assertSame( '0', (string) Catalog::find_exact_mapping( $product->get_id() )['beta_allowed'] );

		$product->set_sku( 'BETA-SKU' );
		$product->save();
		$row = null;
		foreach ( CatalogCsv::export_rows() as $candidate ) {
			if ( (string) $product->get_id() === $candidate[0] ) {
				$row = $candidate;
			}
		}
		$this->assertIsArray( $row );
		$this->assertCount( 15, $row );
		$this->assertSame( '0', $row[14] );

		$legacy = 'product_id,variation_id,sku,product_key,plan_key,billing_interval,live_site_limit,support_level,entitlements_json,staging_site_limit,development_site_limit,offline_cache_days,max_quantity_per_line,co_user_limit';
		$line   = $product->get_id() . ',0,BETA-SKU,test-product,single,year,1,standard,,,,,,';
		$path   = (string) tempnam( sys_get_temp_dir(), 'wplc-csv-' );
		file_put_contents( $path, $legacy . "\n" . $line . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$result = CatalogCsv::import_file( $path );
		$this->assertSame( 1, $result['imported'], (string) wp_json_encode( $result ) );
		$this->assertSame( '0', (string) Catalog::find_exact_mapping( $product->get_id() )['beta_allowed'], 'A file of schema 28 keeps the beta right.' );

		file_put_contents( $path, $legacy . ",beta_allowed\n" . $line . ",1\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->assertSame( 1, CatalogCsv::import_file( $path )['imported'] );
		$this->assertSame( '1', (string) Catalog::find_exact_mapping( $product->get_id() )['beta_allowed'] );
		file_put_contents( $path, $legacy . ",beta_allowed\n" . $line . ",2\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->assertSame( 'invalid_override', CatalogCsv::import_file( $path )['errors'][0]['reason'] );
		unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	}

	// ------------------------------------------------------------------ SET-LIC-17

	/** SET-LIC-17: cancellation and removed lines suspend by default; the settings switch it off; the filters still win. */
	public function test_cancelled_orders_and_removed_lines_follow_the_settings(): void {
		$product = $this->create_license_product();
		$first   = $this->buy( $product );
		$first['order']->update_status( 'cancelled' );
		$this->assertSame( 'suspended', $this->license( (int) $first['licenses'][0]['id'] )['status'], 'Default as before.' );

		$this->submit( array( 'suspend_on_order_cancelled' => '0', 'suspend_on_order_item_change' => '0' ) );
		$second = $this->buy( $product );
		$second['order']->update_status( 'cancelled' );
		$this->assertSame( 'active', $this->license( (int) $second['licenses'][0]['id'] )['status'] );

		$third = $this->buy( $product, 2 );
		$item  = $this->first_item( $third['order'] );
		$item->set_quantity( 1 );
		$item->save();
		$this->assertSame( array( 'active', 'active' ), array_values( wp_list_pluck( $this->licenses_for_order( $third['order']->get_id() ), 'status' ) ) );

		add_filter( 'wplc_suspend_license_on_order_status', '__return_true' );
		$fourth = $this->buy( $product );
		$fourth['order']->update_status( 'cancelled' );
		remove_all_filters( 'wplc_suspend_license_on_order_status' );
		$this->assertSame( 'suspended', $this->license( (int) $fourth['licenses'][0]['id'] )['status'], 'The filter wins.' );
	}

	/** SET-LIC-17 (E-07): a partial refund by amount changes no license but asks for a review; off = as before. */
	public function test_a_partial_refund_by_amount_asks_for_a_review(): void {
		$product = $this->create_license_product();
		$bought  = $this->buy( $product, 2 );
		$order   = $bought['order'];
		$ids     = array_map( 'intval', wp_list_pluck( $bought['licenses'], 'id' ) );
		$seen    = array();
		add_action(
			'wplc/license_needs_review',
			static function ( $license_id, $reason ) use ( &$seen ) {
				$seen[ (int) $license_id ] = (string) $reason;
			},
			10,
			2
		);

		$refund = wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => '5.00', 'reason' => 'Goodwill' ) );
		$this->assertInstanceOf( WC_Order_Refund::class, $refund );
		$this->assertSame( array( 'active', 'active' ), array_values( wp_list_pluck( $this->licenses_for_order( $order->get_id() ), 'status' ) ), 'No license changes.' );
		$this->assertSame( $ids, array_keys( $seen ) );
		$this->assertStringContainsString( '5.00', $seen[ $ids[0] ] );
		foreach ( $ids as $id ) {
			$this->assertContains( 'partial_refund_review', $this->audit_actions( 'license', $id ) );
		}
		$notes = wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' );
		$this->assertNotEmpty( preg_grep( '/partial refund of 5\.00/', $notes ) );

		// The hook again for the same refund: no second notice.
		$seen = array();
		do_action( 'woocommerce_order_partially_refunded', $order->get_id(), $refund->get_id() );
		$this->assertSame( array(), $seen );

		// A unit refund revokes (handled elsewhere) and is no "partial refund by amount".
		$item = $this->first_item( wc_get_order( $order->get_id() ) );
		wc_create_refund(
			array(
				'order_id'   => $order->get_id(),
				'amount'     => wc_format_decimal( (float) $item->get_total() / 2, wc_get_price_decimals() ),
				'line_items' => array(
					$item->get_id() => array(
						'qty'          => 1,
						'refund_total' => wc_format_decimal( (float) $item->get_total() / 2, wc_get_price_decimals() ),
					),
				),
			)
		);
		$this->assertSame( array(), $seen, 'A unit refund raises no review notice.' );

		// Off: as before, no notice.
		$this->submit( array( 'partial_refund_review' => '0' ) );
		$other = $this->buy( $product );
		wc_create_refund( array( 'order_id' => $other['order']->get_id(), 'amount' => '1.00', 'reason' => 'Goodwill' ) );
		$this->assertSame( array(), $seen );
		$this->assertNotContains( 'partial_refund_review', $this->audit_actions( 'license', (int) $other['licenses'][0]['id'] ) );
	}

	/** SET-LIC-17: a won dispute reactivates only with the setting, only the licenses it suspended. */
	public function test_a_won_dispute_reactivates_only_with_the_setting(): void {
		$product = $this->create_license_product();
		$bought  = $this->buy( $product );
		$order   = $bought['order'];
		$id      = (int) $bought['licenses'][0]['id'];
		LicenseLifecycle::chargeback_for_order( $order->get_id(), AuditReason::encode( AuditReason::STRIPE_DISPUTE, array( 'dispute' => 'dp_1', 'reason' => 'fraudulent' ) ) );
		$this->assertSame( 'suspended', $this->license( $id )['status'] );
		$payload = array( 'order_id' => $order->get_id(), 'dispute_id' => 'dp_1', 'dispute_status' => 'won' );

		// Default (as before): stays suspended.
		$this->assertSame( 'processed', StripeEventHandler::handle( 'charge.dispute.closed', $payload ) );
		$this->assertSame( 'suspended', $this->license( $id )['status'] );

		$this->submit( array( 'restore_after_won_dispute' => '1' ) );
		// Another dispute does not lift this suspension.
		StripeEventHandler::handle( 'charge.dispute.closed', array( 'dispute_id' => 'dp_2' ) + $payload );
		$this->assertSame( 'suspended', $this->license( $id )['status'] );

		StripeEventHandler::handle( 'charge.dispute.closed', $payload );
		$license = $this->license( $id );
		$this->assertSame( 'active', $license['status'] );
		$this->assertSame( (string) $bought['licenses'][0]['paid_until'], (string) $license['paid_until'], 'The paid period stays.' );
		$this->assertContains( 'admin_restore', $this->audit_actions( 'license', $id ) );
		$notes = wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' );
		$this->assertNotEmpty( preg_grep( '/reactivated automatically/', $notes ) );
	}

	// ------------------------------------------------------------------ E-08

	/** E-08: plan changes stay prorated by day; switched off, the offer disappears and the request is refused. */
	public function test_upgrades_and_downgrades_can_be_switched_off(): void {
		$customer = self::factory()->user->create( array( 'role' => 'customer' ) );
		$cheap    = $this->create_license_product( array( 'plan_key' => 'starter' ), '19.00' );
		$middle   = $this->create_license_product( array( 'plan_key' => 'solo' ), '49.00' );
		$dear     = $this->create_license_product( array( 'plan_key' => 'business' ), '149.00' );
		unset( $cheap, $dear );
		$order      = $this->create_paid_order( array( array( $middle, 1 ) ), $customer );
		$license_id = (int) $this->licenses_for_order( $order->get_id() )[0]['id'];
		$targets    = PlanChange::targets( $this->license( $license_id ) );
		$kinds      = array();
		foreach ( $targets as $product_id => $target ) {
			$kinds[ $target['kind'] ] = (int) $product_id;
		}
		wp_set_current_user( $customer );
		$render = function () use ( $license_id ): string {
			return $this->capture(
				function () use ( $license_id ) {
					PlanUpgrade::render_account_section( $this->license( $license_id ) );
				}
			);
		};
		$html = $render();
		$this->assertStringContainsString( 'wplc_start_upgrade', $html, 'Default: both offers as before.' );
		$this->assertStringContainsString( 'wplc_schedule_downgrade', $html );

		wp_set_current_user( $this->admin );
		$this->submit( array( 'plan_upgrades_enabled' => '0', 'plan_downgrades_enabled' => '0' ) );
		wp_set_current_user( $customer );
		$this->assertSame( '', trim( wp_strip_all_tags( $render() ) ), 'No offer at all.' );
		$refused = PlanUpgrade::start( $license_id, $customer, $kinds[ PlanChange::KIND_UPGRADE ], true );
		$this->assertWPError( $refused );
		$this->assertSame( 'upgrade_unavailable', $refused->get_error_code() );
		$this->assertSame( 'plan_change_unavailable', PlanChange::schedule_pending( $license_id, $kinds[ PlanChange::KIND_DOWNGRADE ], $customer, AuditReason::PLAN_DOWNGRADE_REQUESTED, false ) );
		$this->assertSame( 'plan_change_scheduled', PlanChange::schedule_pending( $license_id, $kinds[ PlanChange::KIND_DOWNGRADE ], $this->admin, 'Support', true ), 'Administrators are not limited.' );

		// Upgrades back on: the offer returns, still prorated by day.
		wp_set_current_user( $this->admin );
		$this->submit( array( 'plan_upgrades_enabled' => '1' ) );
		wp_set_current_user( $customer );
		$html = $render();
		$this->assertStringContainsString( 'wplc_start_upgrade', $html );
		$this->assertStringNotContainsString( 'wplc_schedule_downgrade', $html );
		$this->assertInstanceOf( WC_Order::class, PlanUpgrade::start( $license_id, $customer, $kinds[ PlanChange::KIND_UPGRADE ], true ) );
	}

	// ------------------------------------------------------------------ E-04

	/** E-04: the key prefix can be chosen before the first license; keys, references and searches follow it; then it is fixed. */
	public function test_key_prefix_only_before_the_first_license(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$this->tables()['licenses']}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- rolled back with the test.
		$this->assertFalse( LicenseKey::prefix_locked() );
		$this->submit( array( 'license_key_prefix' => 'acme' ) );
		$this->assertSame( 'ACME', LicenseKey::key_prefix() );

		$license = $this->seed_license( array( 'renewal_enabled' => 1, 'customer_id' => self::factory()->user->create( array( 'user_email' => 'prefix@example.test' ) ) ) );
		$row     = $this->license( $license['id'] );
		$this->assertStringStartsWith( 'ACME-', $license['key'] );
		$this->assertSame( 34, strlen( $license['key'] ), 'The length of the key stays (T-106: 34 characters).' );
		$this->assertSame( 13, strlen( (string) $row['key_prefix'] ) );
		$this->assertStringStartsWith( 'ACME-', (string) $row['key_prefix'] );
		$this->assertTrue( LicenseKey::matches_hash( (string) $row['license_uuid'], (string) $row['license_key_secret_id'], (string) $row['license_key_hash'] ) );
		$this->assertTrue( LicenseSearch::is_full_license_key( $license['key'] ) );
		// T-106: a fragment of a short key is kept only as a hash (its start is not the reference).
		$this->assertSame( LicenseSearch::TYPE_LICENSE_KEY_HASH, LicenseSearch::criteria( $license['key'] . 'X' )['type'] );
		$this->assertCount( 1, PublicCancellation::find_licenses( 'prefix@example.test', strtolower( str_replace( '-', '', $license['key'] ) ) ) );
		$site = $this->activate_via_api( $license['key'] );
		$this->assertSame( 200, $site['response']->get_status(), 'A key with the own prefix activates.' );
		$this->assertCount( 1, PublicCancellation::find_licenses( 'prefix@example.test', (string) $row['key_prefix'] ) );
		AuditLog::record( 'test_redaction', 'license', $license['id'], 'Customer sent ' . $license['key'] );
		$stored = (string) $wpdb->get_var( $wpdb->prepare( "SELECT reason FROM {$this->tables()['audit_log']} WHERE action = %s ORDER BY id DESC LIMIT 1", 'test_redaction' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertStringNotContainsString( $license['key'], $stored );
		$this->assertStringContainsString( '[REDACTED-LICENSE-KEY]', $stored );

		// From now on the prefix is fixed: form, import and WP-CLI keep it.
		$this->assertTrue( LicenseKey::prefix_locked() );
		$this->submit( array( 'license_key_prefix' => 'BETA' ) );
		$this->assertSame( 'ACME', LicenseKey::key_prefix() );
		$this->assertContains( 'wplc_locked_license_key_prefix', wp_list_pluck( get_settings_errors( Settings::OPTION ), 'code' ) );
		$this->assertSame( array( 'license_key_prefix' => 'locked' ), Changes::apply( array( 'license_key_prefix' => 'BETA' ), 'settings_imported', 'Test.', false )['skipped'] );
		$this->assertSame( 'ACME', LicenseKey::key_prefix() );
		$field = $this->capture(
			static function () {
				Settings::render_field( array( 'key' => 'license_key_prefix' ) );
			}
		);
		$this->assertStringContainsString( ' readonly', $field );
		$this->assertStringNotContainsString( 'name="wplc_settings[license_key_prefix]"', $field );
		$this->assertStringContainsString( 'Fixed: licenses were already issued', $field );
	}
}

