<?php
/**
 * A-23 (propagate plan changes to existing licenses), B-155 (re-key licenses of a leaked
 * license-key secret) and P-20 (no WooCommerce Subscriptions products as license products).
 *
 * @package WPLC
 */

use WPLC\Catalog\Catalog;
use WPLC\CLI\RekeyLicensesCommand;
use WPLC\CLI\SyncEntitlementsCommand;
use WPLC\License\EntitlementSync;
use WPLC\License\LicenseKey;
use WPLC\License\LicenseKeyRotation;

if ( ! class_exists( 'WPLC_Test_CLI_Stop' ) ) {
	/**
	 * Thrown by the WP_CLI stand-in where WP-CLI would end the command.
	 */
	final class WPLC_Test_CLI_Stop extends RuntimeException {
	}
}

if ( ! class_exists( 'WP_CLI' ) ) {
	/**
	 * Minimal stand-in for WP-CLI: records output, stops on error, halt and an unconfirmed confirm().
	 */
	final class WP_CLI {
		/** @var string[] */
		public static $output = array();

		public static function error( $message ) {
			throw new WPLC_Test_CLI_Stop( 'error: ' . $message );
		}

		public static function confirm( $question, $assoc_args = array() ) {
			self::$output[] = 'confirm: ' . $question;
			if ( empty( $assoc_args['yes'] ) ) {
				throw new WPLC_Test_CLI_Stop( 'declined: ' . $question );
			}
		}

		public static function halt( $code ) {
			throw new WPLC_Test_CLI_Stop( 'halt: ' . $code );
		}

		public static function success( $message ) {
			self::$output[] = 'success: ' . $message;
		}

		public static function warning( $message ) {
			self::$output[] = 'warning: ' . $message;
		}

		public static function log( $message ) {
			self::$output[] = 'log: ' . $message;
		}

		public static function line( $message = '' ) {
			self::$output[] = 'line: ' . $message;
		}
	}
}

if ( ! class_exists( 'WC_Product_Subscription' ) ) {
	/**
	 * Stand-in for the simple subscription product type of WooCommerce Subscriptions.
	 */
	class WC_Product_Subscription extends WC_Product_Simple {
		public function get_type() {
			return 'subscription';
		}
	}
}

final class CatalogKeyMaintenanceIntegrationTest extends WPLC_Integration_TestCase {

	public function set_up(): void {
		parent::set_up();
		WP_CLI::$output = array();
	}

	/**
	 * Run a CLI command; returns the halt/error message or '' when it finished normally.
	 *
	 * @param object              $command    Command instance.
	 * @param array<string,mixed> $assoc_args Options.
	 * @return string
	 */
	private function run_cli( $command, array $assoc_args ): string {
		try {
			$command( array(), $assoc_args );
		} catch ( WPLC_Test_CLI_Stop $stop ) {
			return $stop->getMessage();
		}

		return '';
	}

	/** @return array<string,mixed> */
	private function snapshot( int $license_id ): array {
		$snapshot = EntitlementSync::snapshot( $license_id );
		ksort( $snapshot );

		return $snapshot;
	}

	private function audit_count( int $license_id, string $action ): int {
		global $wpdb;
		$table = $this->tables()['audit_log'];

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE object_type = 'license' AND object_id = %d AND action = %s", $license_id, $action ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema.
	}

	/**
	 * Buy one license of a product.
	 *
	 * @param WC_Product $product License product.
	 * @return int License ID.
	 */
	private function buy( WC_Product $product ): int {
		$order    = $this->create_paid_order( array( array( $product, 1 ) ), self::factory()->user->create() );
		$licenses = $this->licenses_for_order( $order->get_id() );
		$this->assertCount( 1, $licenses );

		return (int) $licenses[0]['id'];
	}

	// ------------------------------------------------------------------
	// A-23.
	// ------------------------------------------------------------------

	public function test_a23_plan_gains_are_synced_only_on_request_and_reductions_need_consent(): void {
		$product    = $this->create_license_product( array( 'entitlements' => array( 'feature.export' => true ) ) );
		$license_id = $this->buy( $product );
		$before     = $this->snapshot( $license_id );

		// The plan gains a feature and a higher site limit: the license keeps its snapshot.
		$this->assertTrue( Catalog::save_mapping( $product->get_id(), 0, $this->mapping_data( array( 'live_site_limit' => 3, 'entitlements' => array( 'feature.export' => true, 'feature.reports' => true ) ) ) ) );
		$this->assertSame( $before, $this->snapshot( $license_id ), 'Saving a plan never changes existing licenses by itself.' );

		$dry = EntitlementSync::sync( $license_id, false, false );
		$this->assertSame( EntitlementSync::RESULT_WOULD_SYNC, $dry['result'] );
		$this->assertSame( array( 'feature.reports' ), $dry['diff']['added'] );
		$this->assertSame( array( 'sites.live_limit' ), $dry['diff']['changed'] );
		$this->assertSame( $before, $this->snapshot( $license_id ), 'A dry run changes nothing.' );

		$fired = did_action( 'wplc/license_entitlements_synced' );
		$this->assertSame( EntitlementSync::RESULT_SYNCED, EntitlementSync::sync( $license_id, true, false )['result'] );
		$after = $this->snapshot( $license_id );
		$this->assertTrue( $after['feature.reports'] );
		$this->assertSame( 3, $after['sites.live_limit'] );
		$this->assertSame( 1, $this->audit_count( $license_id, 'license_entitlements_synced' ) );
		$this->assertSame( $fired + 1, did_action( 'wplc/license_entitlements_synced' ) );
		$this->assertSame( EntitlementSync::RESULT_UNCHANGED, EntitlementSync::sync( $license_id, true, false )['result'] );

		// The plan loses a feature: skipped without consent, applied with it.
		$this->assertTrue( Catalog::save_mapping( $product->get_id(), 0, $this->mapping_data( array( 'live_site_limit' => 3, 'entitlements' => array( 'feature.export' => true ) ) ) ) );
		$skipped = EntitlementSync::sync( $license_id, true, false );
		$this->assertSame( EntitlementSync::RESULT_REDUCTION_SKIPPED, $skipped['result'] );
		$this->assertSame( array( 'feature.reports' ), $skipped['diff']['reductions'] );
		$this->assertArrayHasKey( 'feature.reports', $this->snapshot( $license_id ) );
		$this->assertSame( EntitlementSync::RESULT_SYNCED, EntitlementSync::sync( $license_id, true, true )['result'] );
		$this->assertArrayNotHasKey( 'feature.reports', $this->snapshot( $license_id ) );
	}

	public function test_a23_inactive_licenses_and_licenses_without_a_plan_are_left_alone(): void {
		$product    = $this->create_license_product( array( 'entitlements' => array( 'feature.export' => true ) ) );
		$license_id = $this->buy( $product );
		$this->assertTrue( Catalog::save_mapping( $product->get_id(), 0, $this->mapping_data( array( 'entitlements' => array( 'feature.export' => true, 'feature.reports' => true ) ) ) ) );

		global $wpdb;
		$wpdb->update( $this->tables()['licenses'], array( 'status' => 'expired' ), array( 'id' => $license_id ) );
		$this->assertSame( EntitlementSync::RESULT_NOT_ACTIVE, EntitlementSync::sync( $license_id, true, true )['result'] );

		$wpdb->update( $this->tables()['licenses'], array( 'status' => 'active', 'plan_key' => 'other-plan' ), array( 'id' => $license_id ) );
		$this->assertSame( EntitlementSync::RESULT_NO_PLAN, EntitlementSync::sync( $license_id, true, true )['result'], 'The renewal product no longer maps to the license plan.' );
	}

	public function test_a23_cli_is_a_dry_run_by_default_and_filters_by_product_and_plan(): void {
		$product = $this->create_license_product( array( 'entitlements' => array( 'feature.export' => true ) ) );
		$first   = $this->buy( $product );
		$second  = $this->buy( $product );
		$this->assertTrue( Catalog::save_mapping( $product->get_id(), 0, $this->mapping_data( array( 'entitlements' => array( 'feature.export' => true, 'feature.reports' => true ) ) ) ) );

		$this->assertStringStartsWith( 'error:', $this->run_cli( new SyncEntitlementsCommand(), array() ), 'Refuses to run without a selection.' );

		$this->assertSame( 'halt: 1', $this->run_cli( new SyncEntitlementsCommand(), array( 'product' => 'test-product', 'plan' => 'single' ) ) );
		$this->assertContains( 'log: License ' . $first . ': would_sync; added: feature.reports; changed: -; removed: -; reductions: -', WP_CLI::$output );
		$this->assertArrayNotHasKey( 'feature.reports', $this->snapshot( $first ) );

		$this->assertSame( '', $this->run_cli( new SyncEntitlementsCommand(), array( 'product' => 'other-product' ) ), 'No license of another product.' );

		WP_CLI::$output = array();
		$this->assertSame( '', $this->run_cli( new SyncEntitlementsCommand(), array( 'product' => 'test-product', 'apply' => true ) ) );
		$this->assertTrue( $this->snapshot( $first )['feature.reports'] );
		$this->assertTrue( $this->snapshot( $second )['feature.reports'] );
		$summary = preg_grep( '/^log: Mode: apply; inspected: \d+; synced: 2;/', WP_CLI::$output );
		$this->assertCount( 1, $summary, implode( "\n", WP_CLI::$output ) );
	}

	// ------------------------------------------------------------------
	// B-155.
	// ------------------------------------------------------------------

	/**
	 * Seed a license whose key was issued under a secret that has since been retired.
	 *
	 * @return array{id:int,key:string}
	 */
	private function leaked_license( array $overrides = array() ): array {
		$leaked_key = 'WPLC-' . strtoupper( wp_generate_uuid4() ) . '-' . strtoupper( hash( 'sha256', 'derived-with-the-leaked-secret' . wp_generate_password( 8, false ) ) );
		$license    = $this->seed_license(
			array_merge(
				array(
					'license_key_hash'      => LicenseKey::hash( $leaked_key ),
					'license_key_secret_id' => 'leaked-2025',
				),
				$overrides
			)
		);

		return array( 'id' => $license['id'], 'key' => $leaked_key );
	}

	private function license_by_key( string $key ): ?array {
		global $wpdb;
		$table = $this->tables()['licenses'];
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE license_key_hash = %s", LicenseKey::hash( $key ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema.

		return is_array( $row ) ? $row : null;
	}

	public function test_b155_rekey_replaces_keys_of_the_leaked_key_id_and_keeps_activations(): void {
		$leaked = $this->leaked_license();
		$other  = $this->seed_license();
		$site   = $this->seed_activation( $leaked['id'] );
		$this->assertNotNull( $this->license_by_key( $leaked['key'] ), 'Fixture: the leaked key still finds the license.' );

		$this->assertSame( LicenseKeyRotation::RESULT_OTHER_KEY, LicenseKeyRotation::rekey( $other['id'], 'leaked-2025' ) );
		$this->assertSame( LicenseKeyRotation::RESULT_REKEYED, LicenseKeyRotation::rekey( $leaked['id'], 'leaked-2025' ) );

		$this->assertNull( $this->license_by_key( $leaked['key'] ), 'The leaked key stops working at once.' );
		$row = $this->license( $leaked['id'] );
		$this->assertSame( LicenseKey::current_key_id(), $row['license_key_secret_id'] );
		$this->assertNotSame( '', LicenseKey::verified_for_license( $row['license_uuid'], $row['license_key_secret_id'], $row['license_key_hash'] ), 'The customer sees the new key.' );
		$this->assertSame( 'active', $this->activation( (int) $site['id'] )['status'], 'Activated sites keep working.' );
		$this->assertSame( 1, $this->audit_count( $leaked['id'], 'license_key_reissued' ) );
		$this->assertNotNull( $this->license_by_key( $other['key'] ), 'Other key IDs are untouched.' );
	}

	public function test_b155_rekey_can_release_sites_and_covers_revoked_licenses_without_notification(): void {
		$revoked = $this->leaked_license( array( 'status' => 'revoked', 'customer_id' => self::factory()->user->create() ) );
		$active  = $this->leaked_license();
		$site    = $this->seed_activation( $active['id'] );
		$sent    = did_action( 'wplc/license_key_reissued' );

		$this->assertSame( LicenseKeyRotation::RESULT_REKEYED, LicenseKeyRotation::rekey( $revoked['id'], 'leaked-2025', false, true ) );
		$this->assertSame( $sent, did_action( 'wplc/license_key_reissued' ), 'The owner of a revoked license is not told.' );
		$this->assertSame( LicenseKeyRotation::RESULT_REKEYED, LicenseKeyRotation::rekey( $active['id'], 'leaked-2025', true ) );
		$this->assertSame( 'deactivated', $this->activation( (int) $site['id'] )['status'] );
	}

	public function test_b155_cli_refuses_the_current_key_id_and_rekeys_everything_with_apply(): void {
		$first  = $this->leaked_license();
		$second = $this->leaked_license();

		$this->assertStringStartsWith( 'error:', $this->run_cli( new RekeyLicensesCommand(), array( 'from' => LicenseKey::current_key_id(), 'apply' => true ) ) );

		$this->assertSame( '', $this->run_cli( new RekeyLicensesCommand(), array() ) );
		$this->assertContains( 'log: Key ID leaked-2025: 2 licenses ', WP_CLI::$output );

		$this->assertSame( 'halt: 1', $this->run_cli( new RekeyLicensesCommand(), array( 'from' => 'leaked-2025' ) ), 'A dry run with matches ends with exit code 1.' );
		$this->assertNotNull( $this->license_by_key( $first['key'] ) );

		WP_CLI::$output = array();
		$this->assertSame( '', $this->run_cli( new RekeyLicensesCommand(), array( 'from' => 'leaked-2025', 'apply' => true ) ) );
		$this->assertContains( 'log: Mode: apply; re-keyed: 2; skipped: 0; still on key ID leaked-2025: 0', WP_CLI::$output );
		$this->assertNull( $this->license_by_key( $first['key'] ) );
		$this->assertNull( $this->license_by_key( $second['key'] ) );
		$this->assertArrayNotHasKey( 'leaked-2025', LicenseKeyRotation::count_by_key_id() );
	}

	// ------------------------------------------------------------------
	// P-20.
	// ------------------------------------------------------------------

	public function test_p20_subscription_products_cannot_be_mapped(): void {
		$product = new WC_Product_Subscription();
		$product->set_name( 'Subscription product' );
		$product->set_status( 'publish' );
		$product->set_regular_price( '49.00' );
		$product->save();
		$this->assertTrue( Catalog::is_subscription_product( wc_get_product( $product->get_id() ) ), 'Fixture: the stand-in is loaded as a subscription product.' );

		$this->assertFalse( Catalog::save_mapping( $product->get_id(), 0, $this->mapping_data() ) );
		$this->assertSame( 'subscription_product', Catalog::last_error() );
		$this->assertStringContainsString( 'Subscriptions', Catalog::error_message( 'subscription_product' ) );
		$this->assertNull( Catalog::find_exact_mapping( $product->get_id(), 0, true ) );

		$this->assertFalse( Catalog::is_subscription_product( $this->create_simple_product() ) );
	}
}
