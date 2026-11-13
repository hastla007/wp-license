<?php
/**
 * Shared fixtures for the WordPress + WooCommerce integration suite.
 *
 * @package WPLC
 */

use WPLC\Api\V1\Authenticator;
use WPLC\Catalog\Catalog;
use WPLC\Infrastructure\Database\Schema;
use WPLC\License\LicenseKey;
use WPLC\Security\SecretCrypto;

/**
 * Thrown instead of letting a handler send a redirect/status header and call exit.
 */
final class WPLC_Test_Halt extends Exception {
	/**
	 * Either "redirect" or "status".
	 *
	 * @var string
	 */
	public $kind = '';

	/**
	 * Redirect location, empty for a bare status header.
	 *
	 * @var string
	 */
	public $location = '';

	/**
	 * HTTP status.
	 *
	 * @var int
	 */
	public $status = 0;
}

/**
 * Base class: real WooCommerce objects, real plugin hooks, real REST server.
 */
abstract class WPLC_Integration_TestCase extends WP_UnitTestCase {

	/**
	 * Counter that keeps generated nonces unique inside one test.
	 *
	 * @var int
	 */
	private $nonce_counter = 0;

	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;

		// A fresh REST server per test; the plugin registers its routes on rest_api_init.
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		rest_get_server();
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
	}

	public function tear_down(): void {
		global $wp_rest_server;

		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		parent::tear_down();
	}

	/**
	 * Plugin table names.
	 *
	 * @return array<string,string>
	 */
	protected function tables(): array {
		return Schema::table_names();
	}

	/**
	 * Whether this run uses High-Performance Order Storage.
	 *
	 * @return bool
	 */
	protected function hpos_enabled(): bool {
		return class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	// ---------------------------------------------------------------------
	// WooCommerce fixtures.
	// ---------------------------------------------------------------------

	/**
	 * Create a published simple product.
	 *
	 * @param string $price Regular price.
	 * @return WC_Product_Simple
	 */
	protected function create_simple_product( string $price = '49.00' ): WC_Product_Simple {
		$product = new WC_Product_Simple();
		$product->set_name( 'License product ' . wp_generate_password( 6, false ) );
		$product->set_status( 'publish' );
		$product->set_regular_price( $price );
		$product->set_virtual( true );
		$product->save();

		return $product;
	}

	/**
	 * Create a simple product that is mapped to a license plan.
	 *
	 * @param array<string,mixed> $mapping Overrides for Catalog::save_mapping().
	 * @param string              $price   Regular price.
	 * @return WC_Product_Simple
	 */
	protected function create_license_product( array $mapping = array(), string $price = '49.00' ): WC_Product_Simple {
		$product = $this->create_simple_product( $price );
		$saved   = Catalog::save_mapping( $product->get_id(), 0, $this->mapping_data( $mapping ) );
		$this->assertTrue( $saved, 'Fixture: the product mapping could not be saved.' );

		return $product;
	}

	/**
	 * Create a variable product with one variation per given price.
	 *
	 * @param string[] $prices Variation prices.
	 * @return array{0:WC_Product_Variable,1:WC_Product_Variation[]}
	 */
	protected function create_variable_product( array $prices ): array {
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'plan' );
		$attribute->set_options( array_map( 'strval', array_keys( $prices ) ) );
		$attribute->set_visible( true );
		$attribute->set_variation( true );

		$parent = new WC_Product_Variable();
		$parent->set_name( 'Variable license product ' . wp_generate_password( 6, false ) );
		$parent->set_status( 'publish' );
		$parent->set_attributes( array( $attribute ) );
		$parent->save();

		$variations = array();
		foreach ( $prices as $index => $price ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent->get_id() );
			$variation->set_attributes( array( 'plan' => (string) $index ) );
			$variation->set_regular_price( $price );
			$variation->set_status( 'publish' );
			$variation->set_virtual( true );
			$variation->save();
			$variations[] = $variation;
		}

		return array( wc_get_product( $parent->get_id() ), $variations );
	}

	/**
	 * Default mapping values.
	 *
	 * @param array<string,mixed> $overrides Overrides.
	 * @return array<string,mixed>
	 */
	protected function mapping_data( array $overrides = array() ): array {
		return array_merge(
			array(
				'product_key'      => 'test-product',
				'plan_key'         => 'single',
				'billing_interval' => 'year',
				'live_site_limit'  => 1,
				'support_level'    => 'standard',
				'entitlements'     => array( 'feature.export' => true ),
			),
			$overrides
		);
	}

	/**
	 * Create an unpaid (pending) order.
	 *
	 * @param array<int,array{0:WC_Product,1:int}> $lines       Product and quantity pairs.
	 * @param int                                  $customer_id Customer user ID, 0 for a guest.
	 * @return WC_Order
	 */
	protected function create_order( array $lines, int $customer_id = 0 ): WC_Order {
		$order = wc_create_order( array( 'customer_id' => $customer_id ) );
		$this->assertInstanceOf( WC_Order::class, $order );
		foreach ( $lines as $line ) {
			$order->add_product( $line[0], $line[1] );
		}
		$order->set_billing_email( 'buyer-' . $order->get_id() . '@example.test' );
		$order->calculate_totals();
		$order->save();

		return wc_get_order( $order->get_id() );
	}

	/**
	 * Create an order and pay it through WC_Order::payment_complete().
	 *
	 * @param array<int,array{0:WC_Product,1:int}> $lines       Product and quantity pairs.
	 * @param int                                  $customer_id Customer user ID, 0 for a guest.
	 * @return WC_Order
	 */
	protected function create_paid_order( array $lines, int $customer_id = 0 ): WC_Order {
		$order = $this->create_order( $lines, $customer_id );
		$this->assertTrue( $order->payment_complete( 'txn_' . $order->get_id() ), 'Fixture: payment_complete() failed.' );

		return wc_get_order( $order->get_id() );
	}

	/**
	 * First line item of an order.
	 *
	 * @param WC_Order $order Order.
	 * @return WC_Order_Item_Product
	 */
	protected function first_item( WC_Order $order ): WC_Order_Item_Product {
		$items = array_values( $order->get_items( 'line_item' ) );
		$this->assertNotEmpty( $items );

		return $items[0];
	}

	// ---------------------------------------------------------------------
	// License fixtures and queries.
	// ---------------------------------------------------------------------

	/**
	 * License rows created from an order, ordered by item and unit.
	 *
	 * @param int $order_id Order ID.
	 * @return array<int,array<string,mixed>>
	 */
	protected function licenses_for_order( int $order_id ): array {
		global $wpdb;
		$tables = $this->tables();

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$tables['licenses']} WHERE source_order_id = %d ORDER BY source_order_item_id ASC, unit_index ASC",
				$order_id
			),
			ARRAY_A
		);
	}

	/**
	 * One license row.
	 *
	 * @param int $license_id License ID.
	 * @return array<string,mixed>
	 */
	protected function license( int $license_id ): array {
		global $wpdb;
		$tables = $this->tables();
		$row    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['licenses']} WHERE id = %d", $license_id ), ARRAY_A );
		$this->assertIsArray( $row, 'License ' . $license_id . ' does not exist.' );

		return $row;
	}

	/**
	 * One activation row.
	 *
	 * @param int $activation_id Activation ID.
	 * @return array<string,mixed>
	 */
	protected function activation( int $activation_id ): array {
		global $wpdb;
		$tables = $this->tables();
		$row    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['activations']} WHERE id = %d", $activation_id ), ARRAY_A );
		$this->assertIsArray( $row, 'Activation ' . $activation_id . ' does not exist.' );

		return $row;
	}

	/**
	 * Number of licenses in the table.
	 *
	 * @return int
	 */
	protected function license_count(): int {
		global $wpdb;
		$tables = $this->tables();

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['licenses']}" );
	}

	/**
	 * Insert a license with a real, verifiable key and its entitlement snapshot.
	 *
	 * @param array<string,mixed> $overrides    License column overrides.
	 * @param array<string,mixed> $entitlements Entitlement key => value.
	 * @return array{id:int,key:string,uuid:string}
	 */
	protected function seed_license( array $overrides = array(), array $entitlements = array( 'sites.live_limit' => 1, 'feature.export' => true ) ): array {
		global $wpdb;
		$tables     = $this->tables();
		$uuid       = wp_generate_uuid4();
		$key_id     = LicenseKey::current_key_id();
		$key        = LicenseKey::for_uuid( $uuid, $key_id );
		$now        = gmdate( 'Y-m-d H:i:s' );
		$paid_until = gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS );
		$row        = array_merge(
			array(
				'license_uuid'          => $uuid,
				'license_key_hash'      => LicenseKey::hash( $key ),
				'license_key_secret_id' => $key_id,
				'key_prefix'            => LicenseKey::prefix( $uuid ),
				'customer_id'           => 0,
				'product_key'           => 'test-product',
				'plan_key'              => 'single',
				'status'                => 'active',
				'billing_interval'      => 'year',
				'starts_at'             => $now,
				'paid_until'            => $paid_until,
				'next_payment_at'       => $paid_until,
				'created_at'            => $now,
				'updated_at'            => $now,
			),
			$overrides
		);
		$this->assertSame( 1, $wpdb->insert( $tables['licenses'], $row ), 'Fixture: license insert failed: ' . $wpdb->last_error );
		$license_id = (int) $wpdb->insert_id;

		foreach ( $entitlements as $entitlement_key => $value ) {
			$wpdb->insert(
				$tables['entitlements'],
				array(
					'license_id'      => $license_id,
					'entitlement_key' => $entitlement_key,
					'value_json'      => wp_json_encode( $value ),
					'starts_at'       => $row['starts_at'],
					'ends_at'         => $row['paid_until'],
					'status'          => 'active',
				)
			);
		}

		return array(
			'id'   => $license_id,
			'key'  => $key,
			'uuid' => $uuid,
		);
	}

	/**
	 * Insert an activation directly (bypassing the REST API).
	 *
	 * @param int                 $license_id License ID.
	 * @param array<string,mixed> $overrides  Column overrides.
	 * @return array{id:int,uuid:string,secret:string}
	 */
	protected function seed_activation( int $license_id, array $overrides = array() ): array {
		global $wpdb;
		$tables    = $this->tables();
		$secret    = 'seeded-secret-' . wp_generate_password( 32, false );
		$uuid      = wp_generate_uuid4();
		$encrypted = SecretCrypto::encrypt( $secret );
		$this->assertIsString( $encrypted );
		$now = gmdate( 'Y-m-d H:i:s' );
		$row = array_merge(
			array(
				'license_id'                    => $license_id,
				'installation_uuid'             => $uuid,
				'installation_secret_hash'      => hash( 'sha256', $secret ),
				'installation_secret_encrypted' => $encrypted,
				'site_url'                      => 'https://site-' . strtolower( wp_generate_password( 8, false ) ) . '.example.test',
				'environment'                   => 'production',
				'status'                        => 'active',
				'activated_at'                  => $now,
				'last_seen_at'                  => $now,
			),
			$overrides
		);
		$this->assertSame( 1, $wpdb->insert( $tables['activations'], $row ), 'Fixture: activation insert failed: ' . $wpdb->last_error );

		return array(
			'id'     => (int) $wpdb->insert_id,
			'uuid'   => (string) $row['installation_uuid'],
			'secret' => $secret,
		);
	}

	// ---------------------------------------------------------------------
	// REST helpers.
	// ---------------------------------------------------------------------

	/**
	 * A nonce that is unique within this test.
	 *
	 * @return string
	 */
	protected function unique_nonce(): string {
		++$this->nonce_counter;

		return 'itest-nonce-' . str_pad( (string) $this->nonce_counter, 8, '0', STR_PAD_LEFT );
	}

	/**
	 * Build a JSON request, optionally HMAC-signed like a client installation.
	 *
	 * @param string                   $method HTTP method.
	 * @param string                   $route  REST route including /wplc/v1.
	 * @param array<string,mixed>|null $body   JSON body, null for none.
	 * @param array<string,mixed>|null $signer Array with uuid and secret, null for an unsigned request.
	 * @param string|null              $nonce  Nonce, generated when null.
	 * @param string|null              $timestamp Timestamp, now when null.
	 * @return WP_REST_Request
	 */
	protected function api_request( string $method, string $route, ?array $body = null, ?array $signer = null, ?string $nonce = null, ?string $timestamp = null ): WP_REST_Request {
		$request = new WP_REST_Request( $method, $route );
		$raw     = '';
		// WP_REST_Server::serve_request() always sets a string body, also for bodiless requests.
		$request->set_body( '' );
		if ( null !== $body ) {
			$raw = (string) wp_json_encode( $body );
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( $raw );
		}
		if ( null !== $signer ) {
			$nonce     = null === $nonce ? $this->unique_nonce() : $nonce;
			$timestamp = null === $timestamp ? (string) time() : $timestamp;
			$request->set_header( 'X-WPLC-Installation-Id', (string) $signer['uuid'] );
			$request->set_header( 'X-WPLC-Timestamp', $timestamp );
			$request->set_header( 'X-WPLC-Nonce', $nonce );
			$request->set_header(
				'X-WPLC-Signature',
				Authenticator::signature_for( (string) $signer['secret'], $method, $route, $timestamp, $nonce, $raw )
			);
		}

		return $request;
	}

	/**
	 * Dispatch through the real REST server and convert errors like a real HTTP response.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	protected function dispatch( WP_REST_Request $request ): WP_REST_Response {
		$response = rest_get_server()->dispatch( $request );

		return rest_ensure_response( $response );
	}

	/**
	 * Activate an installation through POST /activations.
	 *
	 * @param string              $license_key License key.
	 * @param array<string,mixed> $overrides   Body overrides.
	 * @return array{response:WP_REST_Response,uuid:string,secret:string}
	 */
	protected function activate_via_api( string $license_key, array $overrides = array() ): array {
		$body = array_merge(
			array(
				'license_key'         => $license_key,
				'product_key'         => 'test-product',
				'installation_id'     => wp_generate_uuid4(),
				'installation_secret' => 'api-secret-' . wp_generate_password( 40, false ),
				'site_url'            => 'https://shop-' . strtolower( wp_generate_password( 8, false ) ) . '.example.com',
			),
			$overrides
		);

		return array(
			'response' => $this->dispatch( $this->api_request( 'POST', '/wplc/v1/activations', $body ) ),
			'uuid'     => (string) $body['installation_id'],
			'secret'   => (string) $body['installation_secret'],
		);
	}

	/**
	 * Assert a WordPress REST error body with the given status and code.
	 *
	 * @param int              $status   Expected HTTP status.
	 * @param string           $code     Expected error code.
	 * @param WP_REST_Response $response Response.
	 * @return void
	 */
	protected function assertApiError( int $status, string $code, WP_REST_Response $response ): void {
		$data = $response->get_data();
		$this->assertSame( $status, $response->get_status(), 'Unexpected status; body: ' . wp_json_encode( $data ) );
		$this->assertIsArray( $data );
		foreach ( array( 'code', 'message', 'data' ) as $field ) {
			$this->assertArrayHasKey( $field, $data, 'ApiError schema requires "' . $field . '".' );
		}
		$this->assertSame( $code, $data['code'] );
		$this->assertIsString( $data['message'] );
		$this->assertSame( $status, $data['data']['status'] );
	}

	// ---------------------------------------------------------------------
	// admin-post.php helpers.
	// ---------------------------------------------------------------------

	/**
	 * Convert redirects and bare status headers into WPLC_Test_Halt so that the
	 * `exit` that follows them in production code is never reached.
	 *
	 * @return void
	 */
	protected function halt_instead_of_exit(): void {
		add_filter(
			'wp_redirect',
			static function ( $location, $status = 302 ) {
				$halt           = new WPLC_Test_Halt( 'redirect' );
				$halt->kind     = 'redirect';
				$halt->location = (string) $location;
				$halt->status   = (int) $status;
				throw $halt;
			},
			PHP_INT_MAX,
			2
		);
		add_filter(
			'status_header',
			static function ( $header, $code = 0 ) {
				$halt         = new WPLC_Test_Halt( 'status' );
				$halt->kind   = 'status';
				$halt->status = (int) $code;
				throw $halt;
			},
			PHP_INT_MAX,
			2
		);
	}

	/**
	 * Run an admin_post_{action} handler like admin-post.php would.
	 *
	 * @param string              $action Action name without the admin_post_ prefix.
	 * @param array<string,mixed> $post   POST fields.
	 * @return array{outcome:string,status:int,message:string,location:string}
	 */
	protected function run_admin_post( string $action, array $post ): array {
		$this->halt_instead_of_exit();
		$post['action'] = $action;
		$_POST          = wp_slash( $post );
		$_REQUEST       = $_POST;
		$this->assertNotFalse( has_action( 'admin_post_' . $action ), 'No handler is registered for admin_post_' . $action );

		try {
			do_action( 'admin_post_' . $action );
		} catch ( WPDieException $e ) {
			return array(
				'outcome'  => 'die',
				'status'   => (int) $e->getCode(),
				'message'  => $e->getMessage(),
				'location' => '',
			);
		} catch ( WPLC_Test_Halt $e ) {
			return array(
				'outcome'  => $e->kind,
				'status'   => $e->status,
				'message'  => '',
				'location' => $e->location,
			);
		}

		return array(
			'outcome'  => 'returned',
			'status'   => 0,
			'message'  => '',
			'location' => '',
		);
	}
}
