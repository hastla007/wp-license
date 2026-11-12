<?php
use WPLC\Api\V1\Routes;
use WPLC\Infrastructure\Database\Schema;
use WPLC\Security\SecretCrypto;

final class ApiSecurityIntegrationTest extends WP_UnitTestCase {
	private const LICENSE_UUID = '10000000-0000-4000-8000-000000000001';
	private const INSTALLATION_UUID = '20000000-0000-4000-8000-000000000002';
	private const INSTALLATION_SECRET = 'integration-installation-secret-32-bytes';

	public function set_up(): void {
		parent::set_up();
		global $wpdb, $wp_rest_server;

		$wp_rest_server = null;
		add_action( 'rest_api_init', array( Routes::class, 'register_routes' ) );
		rest_get_server();
		Schema::migrate();
		$tables = Schema::table_names();
		foreach ( array( 'request_nonces', 'request_limits', 'activations', 'entitlements', 'licenses', 'plans', 'products' ) as $table ) {
			$wpdb->query( "DELETE FROM {$tables[ $table ]}" );
		}
		$this->seed_active_activation( $tables );
	}

	public function test_rest_route_rejects_bad_signature_without_consuming_nonce_then_accepts_and_rejects_replay(): void {
		global $wpdb;
		$tables = Schema::table_names();
		$request = $this->signed_request( 'integration-nonce-00000001' );
		$request->set_header( 'X-WPLC-Signature', str_repeat( '0', 64 ) );

		$invalid = rest_get_server()->dispatch( $request );
		$this->assertSame( 401, $invalid->get_status() );
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['request_nonces']}" ) );

		$valid = $this->signed_request( 'integration-nonce-00000001' );
		$accepted = rest_get_server()->dispatch( $valid );
		$this->assertSame( 200, $accepted->get_status() );
		$this->assertArrayHasKey( 'signature', $accepted->get_data() );
		$this->assertArrayHasKey( 'entitlements', $accepted->get_data()['data'] );

		$replay = rest_get_server()->dispatch( $this->signed_request( 'integration-nonce-00000001' ) );
		$this->assertSame( 409, $replay->get_status() );
		$this->assertSame( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['request_nonces']}" ) );
	}

	public function test_valid_signature_with_expired_timestamp_is_rejected_before_nonce_storage(): void {
		global $wpdb;
		$tables = Schema::table_names();
		$old_timestamp = (string) ( time() - 301 );
		$response = rest_get_server()->dispatch( $this->signed_request( 'integration-nonce-expired0001', $old_timestamp ) );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['request_nonces']}" ) );
	}

	public function test_real_rest_requests_are_limited_after_120_authenticated_calls_per_minute(): void {
		for ( $index = 1; $index <= 121; ++$index ) {
			$nonce = 'integration-nonce-' . str_pad( (string) $index, 8, '0', STR_PAD_LEFT );
			$response = rest_get_server()->dispatch( $this->signed_request( $nonce ) );
			$this->assertSame( 121 === $index ? 429 : 200, $response->get_status(), 'Unexpected response at signed request ' . $index );
		}
	}

	private function signed_request( string $nonce, ?string $timestamp = null ): WP_REST_Request {
		$timestamp = null === $timestamp ? (string) time() : $timestamp;
		$body = '{"product_version":"1.0.0"}';
		$request = new WP_REST_Request( 'POST', '/wplc/v1/entitlements/check' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WPLC-Installation-Id', self::INSTALLATION_UUID );
		$request->set_header( 'X-WPLC-Timestamp', $timestamp );
		$request->set_header( 'X-WPLC-Nonce', $nonce );
		$request->set_body( $body );
		$request->set_header(
			'X-WPLC-Signature',
			\WPLC\Api\V1\Authenticator::signature_for( self::INSTALLATION_SECRET, 'POST', '/wplc/v1/entitlements/check', $timestamp, $nonce, $body )
		);

		return $request;
	}

	private function seed_active_activation( array $tables ): void {
		global $wpdb;

		$now = gmdate( 'Y-m-d H:i:s' );
		$paid_until = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
		$wpdb->insert(
			$tables['products'],
			array(
				'product_key' => 'integration-product',
				'display_name' => 'Integration Product',
				'active' => 1,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		$wpdb->insert(
			$tables['plans'],
			array(
				'product_key' => 'integration-product',
				'plan_key' => 'test-plan',
				'woocommerce_product_id' => 900001,
				'woocommerce_variation_id' => 0,
				'billing_interval' => 'year',
				'price_minor' => 1000,
				'price_decimals' => 2,
				'currency' => 'EUR',
				'live_site_limit' => 2,
				'support_level' => 'standard',
				'entitlements_json' => '{"sites.live_limit":2,"feature.test":true}',
				'active' => 1,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		$wpdb->insert(
			$tables['licenses'],
			array(
				'license_uuid' => self::LICENSE_UUID,
				'license_key_hash' => str_repeat( 'a', 64 ),
				'license_key_secret_id' => 'integration-2026',
				'key_prefix' => 'WPLC-10000000',
				'customer_id' => 1,
				'product_key' => 'integration-product',
				'plan_key' => 'test-plan',
				'status' => 'active',
				'billing_interval' => 'year',
				'starts_at' => $now,
				'paid_until' => $paid_until,
				'next_payment_at' => $paid_until,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		$license_id = (int) $wpdb->insert_id;
		$wpdb->insert(
			$tables['entitlements'],
			array(
				'license_id' => $license_id,
				'entitlement_key' => 'feature.test',
				'value_json' => 'true',
				'source_order_item_id' => null,
				'starts_at' => $now,
				'ends_at' => $paid_until,
				'status' => 'active',
			)
		);
		$encrypted = SecretCrypto::encrypt( self::INSTALLATION_SECRET );
		$this->assertIsString( $encrypted );
		$wpdb->insert(
			$tables['activations'],
			array(
				'license_id' => $license_id,
				'installation_uuid' => self::INSTALLATION_UUID,
				'installation_secret_hash' => hash( 'sha256', self::INSTALLATION_SECRET ),
				'installation_secret_encrypted' => $encrypted,
				'site_url' => 'https://shop.example.test',
				'environment' => 'production',
				'staging_parent_id' => null,
				'product_version' => '1.0.0',
				'wp_version' => '6.8',
				'php_version' => PHP_VERSION,
				'status' => 'active',
				'activated_at' => $now,
				'last_seen_at' => $now,
				'deactivated_at' => null,
			)
		);
		$this->assertSame( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['activations']}" ) );
	}
}
