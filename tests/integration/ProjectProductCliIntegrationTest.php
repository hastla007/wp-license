<?php
/**
 * A project product set up with WP-CLI only (docs/TODO.md, PK-07): catalog file, private
 * WooCommerce product with its plan, license and release, through the functions the commands
 * `wp wplc catalog import`, `wp wplc project-product save`, `wp wplc license issue` and
 * `wp wplc release create` call. No class of the server is called to write anything else.
 *
 * @package WPLC
 */

use WPLC\Catalog\Catalog;
use WPLC\Catalog\CatalogStore;
use WPLC\CLI\CatalogCommand;
use WPLC\CLI\LicenseCommand;
use WPLC\CLI\ProjectProductCommand;
use WPLC\CLI\ReleaseCommand;
use WPLC\License\LicenseKey;

final class ProjectProductCliIntegrationTest extends WPLC_Integration_TestCase {

	/**
	 * Temporary files.
	 *
	 * @var string[]
	 */
	private $files = array();

	/**
	 * Private package directory of this test.
	 *
	 * @var string
	 */
	private $storage_dir = '';

	/**
	 * Previous value of $_SERVER['HTTPS'].
	 *
	 * @var mixed
	 */
	private $https_before;

	public function set_up(): void {
		parent::set_up();
		$this->https_before = $_SERVER['HTTPS'] ?? null;
		$_SERVER['HTTPS']   = 'on';
		$this->storage_dir  = sys_get_temp_dir() . '/wplc-it-' . bin2hex( random_bytes( 6 ) );
		$dir                = $this->storage_dir;
		add_filter(
			'wplc_package_storage_dir',
			static function () use ( $dir ) {
				return $dir;
			}
		);
	}

	public function tear_down(): void {
		if ( null === $this->https_before ) {
			unset( $_SERVER['HTTPS'] );
		} else {
			$_SERVER['HTTPS'] = $this->https_before;
		}
		foreach ( array_merge( $this->files, (array) glob( $this->storage_dir . '/{,.}[!.]*', GLOB_BRACE ) ) as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		if ( is_dir( $this->storage_dir ) ) {
			rmdir( $this->storage_dir );
		}
		CatalogStore::reset_cache();
		parent::tear_down();
	}

	/**
	 * Catalog file of the connector.
	 *
	 * @return string Path.
	 */
	private function catalog_file(): string {
		$path = (string) tempnam( sys_get_temp_dir(), 'wplc-catalog-' );
		file_put_contents(
			$path,
			wp_json_encode(
				array(
					'format'       => 1,
					'product_key'  => 'harbour-connector',
					'prefix'       => 'harbour',
					'name'         => 'Harbour Booking Connector',
					'entitlements' => array(
						array(
							'key'   => 'harbour.sync',
							'label' => 'Booking sync',
						),
						array(
							'key'   => 'harbour.reports',
							'label' => 'Reports',
						),
						array(
							'key'    => 'harbour.later',
							'label'  => 'Planned function',
							'status' => 'planned',
						),
					),
				)
			)
		);
		$this->files[] = $path;

		return $path;
	}

	/**
	 * ZIP of the connector.
	 *
	 * @param string $version Version.
	 * @return string Path.
	 */
	private function connector_zip( string $version ): string {
		$path          = sys_get_temp_dir() . '/wplc-it-' . bin2hex( random_bytes( 6 ) ) . '.zip';
		$this->files[] = $path;
		$zip           = new ZipArchive();
		$this->assertTrue( $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) );
		$zip->addFromString( 'harbour-connector/harbour-connector.php', "<?php\n/**\n * Plugin Name: Harbour Booking Connector\n * Version: {$version}\n * Requires at least: 7.0\n * Requires PHP: 8.5\n */\n" );
		$zip->addFromString( 'harbour-connector/readme.txt', 'Stable tag: ' . $version . "\n" . str_repeat( 'payload ', 500 ) );
		$zip->close();

		return $path;
	}

	public function test_pk07_a_project_product_is_set_up_with_commands_only(): void {
		$client = self::factory()->user->create(
			array(
				'role'       => 'customer',
				'user_email' => 'harbour-' . strtolower( wp_generate_password( 6, false ) ) . '@example.test',
			)
		);

		// 1. wp wplc catalog import harbour-connector/wplc-catalog.json
		$this->assertTrue( CatalogCommand::run_import( $this->catalog_file(), false )['ok'] );

		// 2. wp wplc project-product save --product=harbour-connector --name="Harbour Booking Connector" --sites=2
		$saved = ProjectProductCommand::run_save(
			array(
				'product' => 'harbour-connector',
				'name'    => 'Harbour Booking Connector',
				'sites'   => '2',
			)
		);
		$this->assertIsArray( $saved, is_wp_error( $saved ) ? $saved->get_error_message() : '' );
		$this->assertSame( array( 'product_id', 'plan_id', 'product_key', 'plan_key', 'created', 'entitlements' ), array_keys( $saved ) );
		$this->assertTrue( $saved['created'] );
		$this->assertSame( array( 'harbour-connector', 'project' ), array( $saved['product_key'], $saved['plan_key'] ) );
		$this->assertSame( array( 'harbour.sync', 'harbour.reports' ), $saved['entitlements'], 'Default: every available key of the catalog file, no planned one.' );

		$product = wc_get_product( $saved['product_id'] );
		$this->assertSame( array( 'private', 'hidden', true, 'Harbour Booking Connector' ), array( $product->get_status(), $product->get_catalog_visibility(), $product->is_virtual(), $product->get_name() ) );
		$this->assertFalse( Catalog::is_for_sale( $product ), 'Not sold in the shop.' );
		$this->assertSame( 'yes', $product->get_meta( '_wplc_is_license_product', true ), 'The product editor shows the same values.' );
		$this->assertSame( array( 'harbour-connector', 'project', '2' ), array( $product->get_meta( '_wplc_product_key', true ), $product->get_meta( '_wplc_plan_key', true ), (string) $product->get_meta( '_wplc_live_site_limit', true ) ) );
		$plan = Catalog::find_exact_mapping( $saved['product_id'], 0 );
		$this->assertSame( $saved['plan_id'], (int) $plan['id'] );
		$this->assertSame(
			array(
				'harbour.sync'     => true,
				'harbour.reports'  => true,
				'sites.live_limit' => 2,
				'support.level'    => 'standard',
			),
			json_decode( (string) $plan['entitlements_json'], true )
		);
		$this->assertSame( 'Harbour Booking Connector', Catalog::product_display_name( 'harbour-connector' ) );

		// The same command again updates the product and keeps both IDs.
		$again = ProjectProductCommand::run_save(
			array(
				'product'      => 'harbour-connector',
				'sites'        => '3',
				'entitlements' => 'harbour.sync',
				'support'      => 'priority',
			)
		);
		$this->assertIsArray( $again, is_wp_error( $again ) ? $again->get_error_message() : '' );
		$this->assertSame( array( false, $saved['product_id'], $saved['plan_id'] ), array( $again['created'], $again['product_id'], $again['plan_id'] ) );
		$this->assertSame(
			array(
				'harbour.sync'     => true,
				'sites.live_limit' => 3,
				'support.level'    => 'priority',
			),
			json_decode( (string) Catalog::find_exact_mapping( $saved['product_id'], 0 )['entitlements_json'], true )
		);
		$this->assertCount( 1, wc_get_products( array( 'status' => 'private', 'limit' => -1, 'return' => 'ids' ) ), 'No second WooCommerce product.' );

		// 3. wp wplc license issue --plan-id=<plan_id> --customer=<email> --expires=<date> --skip-email --yes
		$request = LicenseCommand::issue_request(
			array(
				'customer'   => (string) $client,
				'plan-id'    => (string) $again['plan_id'],
				'expires'    => wp_date( 'Y-m-d', time() + 365 * DAY_IN_SECONDS ),
				'reason'     => 'Project contract',
				'skip-email' => true,
			)
		);
		$this->assertIsArray( $request, is_wp_error( $request ) ? $request->get_error_message() : '' );
		$license_id = LicenseCommand::run_issue( $request, 0 );
		$this->assertIsInt( $license_id, is_wp_error( $license_id ) ? $license_id->get_error_message() : '' );
		$license = $this->license( $license_id );
		$this->assertSame( array( 'harbour-connector', 'project', 'active' ), array( $license['product_key'], $license['plan_key'], $license['status'] ) );

		// 4. wp wplc release create --product=harbour-connector --version=1.1.0 --zip=<file> --publish
		$release = ReleaseCommand::run_create(
			array(
				'product' => 'harbour-connector',
				'version' => '1.1.0',
				'zip'     => $this->connector_zip( '1.1.0' ),
				'publish' => true,
			)
		);
		$this->assertIsArray( $release, is_wp_error( $release ) ? $release->get_error_message() : '' );
		$this->assertTrue( $release['published'] );

		// The client's site activates with the key from the account, gets the entitlement of the plan and the update.
		$key  = LicenseKey::for_uuid( (string) $license['license_uuid'], (string) $license['license_key_secret_id'] );
		$this->assertSame( 34, strlen( $key ), 'T-106: wp wplc license issue gives a short key.' );
		$this->assertSame( $key, LicenseKey::verified_for_license( (string) $license['license_uuid'], (string) $license['license_key_secret_id'], (string) $license['license_key_hash'] ) );
		$site = $this->activate_via_api( $key, array( 'product_key' => 'harbour-connector' ) );
		$this->assertSame( 200, $site['response']->get_status(), (string) wp_json_encode( $site['response']->get_data() ) );
		$data = $site['response']->get_data()['data'];
		$this->assertSame( 3, $data['site_limit'] );
		$check = $this->dispatch(
			$this->api_request(
				'POST',
				'/wplc/v1/updates/check',
				array(
					'product_key'     => 'harbour-connector',
					'current_version' => '1.0.0',
				),
				$site
			)
		);
		$this->assertSame( 200, $check->get_status(), (string) wp_json_encode( $check->get_data() ) );
		$this->assertSame( '1.1.0', $check->get_data()['data']['new_version'] );
	}

	public function test_pk07_wrong_input_changes_nothing(): void {
		$count = static function (): int {
			return count( wc_get_products( array( 'status' => array( 'private', 'publish', 'draft' ), 'limit' => -1, 'return' => 'ids' ) ) );
		};
		$before = $count();
		$run    = static function ( array $args ): string {
			$result = ProjectProductCommand::run_save( $args );
			return is_wp_error( $result ) ? $result->get_error_code() : 'ok';
		};

		$this->assertSame( 'invalid_product_key', $run( array( 'product' => 'Bad Key', 'name' => 'X' ) ) );
		$this->assertSame( 'invalid_plan_key', $run( array( 'product' => 'quay-connector', 'name' => 'X', 'plan' => 'Bad Plan!' ) ) );
		$this->assertSame( 'invalid_sites', $run( array( 'product' => 'quay-connector', 'name' => 'X', 'sites' => '0' ) ) );
		$this->assertSame( 'invalid_sites', $run( array( 'product' => 'quay-connector', 'name' => 'X', 'sites' => 'many' ) ) );
		$this->assertSame( 'invalid_support', $run( array( 'product' => 'quay-connector', 'name' => 'X', 'support' => 'gold' ) ) );
		$this->assertSame( 'name_missing', $run( array( 'product' => 'quay-connector' ) ) );
		$this->assertSame( 'catalog_missing', $run( array( 'product' => 'quay-connector', 'name' => 'X', 'entitlements' => 'quay.sync' ) ), 'Entitlements come from the catalog file.' );
		$this->assertTrue( CatalogCommand::run_import( $this->catalog_file(), false )['ok'] );
		$this->assertSame( 'unknown_entitlements', $run( array( 'product' => 'harbour-connector', 'name' => 'X', 'entitlements' => 'harbour.sync,harbour.typo' ) ) );
		$this->assertSame( $before, $count(), 'No WooCommerce product was created.' );

		// Without a catalog file a product gets a plan without entitlements.
		$this->assertSame( 'ok', $run( array( 'product' => 'quay-connector', 'name' => 'Quay Connector' ) ) );
		$this->assertSame( $before + 1, $count() );

		// A product that is sold in the shop is never changed by this command.
		$sold = $this->create_license_product(
			array(
				'product_key' => 'sold-product',
				'plan_key'    => 'single',
			)
		);
		$this->assertSame( 'not_a_project_product', $run( array( 'product' => 'sold-product', 'plan' => 'single', 'sites' => '9' ) ) );
		$this->assertSame( 1, (int) Catalog::find_exact_mapping( $sold->get_id(), 0 )['live_site_limit'] );
	}
}
