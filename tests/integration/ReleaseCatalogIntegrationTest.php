<?php
/**
 * PROD-03: the catalog file of an add-on is imported from its release package.
 *
 * @package WPLC
 */

use WPLC\Catalog\Catalog;
use WPLC\Catalog\CatalogStore;
use WPLC\Catalog\EntitlementCatalog;
use WPLC\Catalog\ReleaseCatalog;
use WPLC\Update\ReleaseRegistry;

final class ReleaseCatalogIntegrationTest extends WPLC_Integration_TestCase {

	/**
	 * @var string
	 */
	private $storage_dir = '';

	/**
	 * @var string[]
	 */
	private $files = array();

	public function set_up(): void {
		parent::set_up();
		global $wpdb;

		CatalogStore::reset_cache();
		ReleaseCatalog::reset();
		$this->storage_dir = sys_get_temp_dir() . '/wplc-rc-' . bin2hex( random_bytes( 6 ) );
		$dir               = $this->storage_dir;
		add_filter(
			'wplc_package_storage_dir',
			static function () use ( $dir ) {
				return $dir;
			}
		);
		$now = gmdate( 'Y-m-d H:i:s' );
		foreach ( array( 'acme-forms-pro', 'other-product' ) as $key ) {
			$wpdb->insert( $this->tables()['products'], array( 'product_key' => $key, 'display_name' => $key, 'active' => 1, 'created_at' => $now, 'updated_at' => $now ) );
		}
	}

	public function tear_down(): void {
		remove_all_filters( 'wplc_catalog_auto_import' );
		foreach ( $this->files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		foreach ( (array) glob( $this->storage_dir . '/{,.}[!.]*', GLOB_BRACE ) as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		if ( is_dir( $this->storage_dir ) ) {
			rmdir( $this->storage_dir );
		}
		parent::tear_down();
		CatalogStore::reset_cache();
		ReleaseCatalog::reset();
	}

	/** @param string[] $keys Entitlement keys (suffix after "acme."). */
	private function catalog( array $keys, string $product_key = 'acme-forms-pro' ): string {
		$entitlements = array();
		foreach ( $keys as $key ) {
			$entitlements[] = array( 'key' => 'acme.' . $key, 'label' => array( 'en' => ucfirst( $key ), 'de_DE' => ucfirst( $key ) . ' (de)' ) );
		}

		return (string) wp_json_encode(
			array(
				'format'       => 1,
				'product_key'  => $product_key,
				'prefix'       => 'acme',
				'name'         => 'Acme Forms Pro',
				'generator'    => 'acme build',
				'entitlements' => $entitlements,
			)
		);
	}

	/**
	 * Create a draft release from a package; the catalog file, when given, lies in the plugin folder.
	 *
	 * @return int|WP_Error
	 */
	private function release( string $version, ?string $catalog, string $product = 'acme-forms-pro', int $actor = 0 ) {
		$path          = sys_get_temp_dir() . '/wplc-rc-' . bin2hex( random_bytes( 6 ) ) . '.zip';
		$this->files[] = $path;
		$zip           = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString( $product . '/' . $product . '.php', "<?php\n/**\n * Plugin Name: Acme\n * Version: {$version}\n */\n" );
		if ( null !== $catalog ) {
			$zip->addFromString( $product . '/wplc-catalog.json', $catalog );
		}
		$zip->close();

		return ReleaseRegistry::create_with_package( array( 'product_key' => $product, 'version' => $version, 'release_notes' => 'x' ), $path, $actor );
	}

	private function audit( string $action ): array {
		global $wpdb;

		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE action = %s ORDER BY id ASC', $this->tables()['audit_log'], $action ), ARRAY_A );
	}

	public function test_a_release_package_brings_its_catalog(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->assertNull( CatalogStore::get( 'acme-forms-pro' ) );

		$id = $this->release( '1.0.0', $this->catalog( array( 'pdf_export', 'webhooks' ) ), 'acme-forms-pro', $admin );
		$this->assertIsInt( $id );
		$result = ReleaseCatalog::last_result();
		$this->assertSame( ReleaseCatalog::STATUS_IMPORTED, $result['status'] );
		$this->assertSame( array( 'acme.pdf_export', 'acme.webhooks' ), $result['added'] );
		$stored = CatalogStore::get( 'acme-forms-pro' );
		$this->assertSame( CatalogStore::SOURCE_RELEASE, $stored['source'] );
		$this->assertContains( 'acme.webhooks', EntitlementCatalog::known_keys(), 'The product editor offers the keys of the package.' );
		$this->assertStringContainsString( '2 entitlements added', ReleaseCatalog::message( $result ) );
		$imported = $this->audit( 'catalog_file_imported' );
		$this->assertCount( 1, $imported );
		$this->assertSame( (string) $admin, (string) $imported[0]['actor_user_id'] );
		$this->assertSame( 'release', json_decode( (string) $imported[0]['after_json'], true )['source'] );

		// The same catalog in the next version: nothing changes, nothing is logged.
		$this->assertIsInt( $this->release( '1.0.1', $this->catalog( array( 'pdf_export', 'webhooks' ) ) ) );
		$this->assertSame( ReleaseCatalog::STATUS_UNCHANGED, ReleaseCatalog::last_result()['status'] );
		$this->assertCount( 1, $this->audit( 'catalog_file_imported' ) );
		$this->assertSame( '', ReleaseCatalog::message( ReleaseCatalog::last_result() ) );

		// A package without the file changes nothing.
		$this->assertIsInt( $this->release( '1.0.2', null ) );
		$this->assertSame( ReleaseCatalog::STATUS_NO_FILE, ReleaseCatalog::last_result()['status'] );
		$this->assertCount( 2, CatalogStore::get( 'acme-forms-pro' )['entitlements'] );

		// An older package uploaded later does not turn the catalog back.
		$this->assertIsInt( $this->release( '0.9.0', $this->catalog( array( 'pdf_export' ) ) ) );
		$this->assertSame( ReleaseCatalog::STATUS_OLDER, ReleaseCatalog::last_result()['status'] );
		$this->assertCount( 2, CatalogStore::get( 'acme-forms-pro' )['entitlements'] );
	}

	public function test_a_wrong_or_invalid_catalog_file_is_refused_and_the_release_stays(): void {
		$this->release( '1.0.0', $this->catalog( array( 'pdf_export' ) ) );
		$before = CatalogStore::get( 'acme-forms-pro' );

		// The file names another product: a package must not redefine someone else's catalog.
		$id = $this->release( '1.1.0', $this->catalog( array( 'evil' ), 'other-product' ) );
		$this->assertIsInt( $id, 'The release is created all the same.' );
		$this->assertNotNull( ReleaseRegistry::get( $id ) );
		$result = ReleaseCatalog::last_result();
		$this->assertSame( array( ReleaseCatalog::STATUS_REFUSED, 'product_mismatch' ), array( $result['status'], $result['reason'] ) );
		$this->assertSame( $before, CatalogStore::get( 'acme-forms-pro' ) );
		$this->assertNull( CatalogStore::get( 'other-product' ) );
		$this->assertStringContainsString( 'NOT imported', ReleaseCatalog::message( $result ) );
		$refused = $this->audit( 'catalog_release_import_refused' );
		$this->assertCount( 1, $refused );
		$this->assertSame( array( 'release', (string) $id, 'product_mismatch' ), array( $refused[0]['object_type'], (string) $refused[0]['object_id'], $refused[0]['reason'] ) );
		$this->assertSame( 'other-product', json_decode( (string) $refused[0]['after_json'], true )['file_product_key'] );

		// Broken JSON, a key of another prefix, a file that is too large.
		foreach ( array( '{not json', (string) wp_json_encode( array( 'format' => 1, 'product_key' => 'acme-forms-pro', 'prefix' => 'acme', 'name' => 'x', 'entitlements' => array( array( 'key' => 'pass.waiting_room', 'label' => 'x' ) ) ) ), str_repeat( ' ', 300000 ) ) as $index => $content ) {
			$id = $this->release( '1.2.' . $index, $content );
			$this->assertIsInt( $id );
			$this->assertSame( ReleaseCatalog::STATUS_REFUSED, ReleaseCatalog::last_result()['status'], (string) $index );
			$this->assertSame( $before, CatalogStore::get( 'acme-forms-pro' ) );
		}
		$this->assertSame( 'file_too_large', ReleaseCatalog::last_result()['reason'] );
		$this->assertCount( 4, $this->audit( 'catalog_release_import_refused' ) );
		$known = EntitlementCatalog::known_keys();
		$this->assertCount( 22, $known, 'The 21 shipped keys and the one of the first package; nothing from the refused files.' );
		$this->assertContains( 'acme.pdf_export', $known );
		$this->assertNotContains( 'acme.evil', $known );
	}

	public function test_removing_keys_that_plans_still_grant_is_carried_out_with_a_warning(): void {
		global $wpdb;
		$this->release( '1.0.0', $this->catalog( array( 'pdf_export', 'webhooks', 'unused' ) ) );
		$product = $this->create_license_product( array( 'product_key' => 'acme-forms-pro', 'plan_key' => 'single', 'entitlements' => array( 'acme.pdf_export' => true, 'acme.webhooks' => true ) ) );
		$order   = $this->create_paid_order( array( array( $product, 1 ) ), self::factory()->user->create() );
		$license = $this->licenses_for_order( $order->get_id() )[0];
		$plan    = static function () use ( $wpdb, $product ): string {
			return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT entitlements_json FROM %i WHERE woocommerce_product_id = %d', \WPLC\Infrastructure\Database\Schema::table_names()['plans'], $product->get_id() ) );
		};
		$granted = function () use ( $wpdb, $license ): array {
			return (array) $wpdb->get_col( $wpdb->prepare( 'SELECT entitlement_key FROM %i WHERE license_id = %d ORDER BY entitlement_key', $this->tables()['entitlements'], (int) $license['id'] ) );
		};
		$plan_before    = $plan();
		$granted_before = $granted();

		// Version 2 drops "webhooks" (granted by the plan) and "unused" (granted by nobody).
		$id     = $this->release( '2.0.0', $this->catalog( array( 'pdf_export' ) ) );
		$result = ReleaseCatalog::last_result();
		$this->assertSame( ReleaseCatalog::STATUS_IMPORTED, $result['status'] );
		$this->assertSame( array( 'acme.webhooks', 'acme.unused' ), $result['removed'] );
		$this->assertSame( array( 'acme.webhooks' ), $result['removed_in_use'] );
		$this->assertStringContainsString( 'acme.webhooks', ReleaseCatalog::message( $result ) );
		$this->assertStringNotContainsString( 'acme.unused.', ReleaseCatalog::message( $result ) . '.' );
		$warned = $this->audit( 'catalog_keys_removed_in_use' );
		$this->assertCount( 1, $warned );
		$this->assertSame( (string) $id, (string) $warned[0]['object_id'] );

		$this->assertSame( $plan_before, $plan(), 'The plan keeps its entitlements.' );
		$this->assertSame( $granted_before, $granted(), 'The license keeps its entitlements.' );
		$this->assertContains( 'acme.webhooks', $granted_before );
		$this->assertNotContains( 'acme.webhooks', EntitlementCatalog::known_keys() );
	}

	public function test_the_import_can_be_switched_off(): void {
		$settings                        = (array) get_option( 'wplc_settings', array() );
		$settings['catalog_auto_import'] = 0;
		update_option( 'wplc_settings', $settings );
		$this->assertFalse( ReleaseCatalog::enabled() );
		$this->assertIsInt( $this->release( '1.0.0', $this->catalog( array( 'pdf_export' ) ) ) );
		$this->assertSame( ReleaseCatalog::STATUS_OFF, ReleaseCatalog::last_result()['status'] );
		$this->assertNull( CatalogStore::get( 'acme-forms-pro' ) );

		$settings['catalog_auto_import'] = 1;
		update_option( 'wplc_settings', $settings );
		$this->assertTrue( ReleaseCatalog::enabled() );
		add_filter( 'wplc_catalog_auto_import', '__return_false' );
		$this->assertFalse( ReleaseCatalog::enabled() );
		remove_filter( 'wplc_catalog_auto_import', '__return_false' );
		$this->assertIsInt( $this->release( '1.0.1', $this->catalog( array( 'pdf_export' ) ) ) );
		$this->assertNotNull( CatalogStore::get( 'acme-forms-pro' ) );
	}

	/**
	 * PROD-02 / PROD-12: this is how the copies shipped under catalogs/ go away.
	 */
	public function test_a_release_package_replaces_the_shipped_copy_of_its_product(): void {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert( $this->tables()['products'], array( 'product_key' => 'pass-captain-pro', 'display_name' => 'Pass', 'active' => 1, 'created_at' => $now, 'updated_at' => $now ) );
		$this->assertSame( CatalogStore::SOURCE_BUNDLED, CatalogStore::get( 'pass-captain-pro' )['source'] );

		$file = (string) file_get_contents( dirname( __DIR__, 2 ) . '/catalogs/pass-captain-pro.json' );
		$data = json_decode( $file, true );
		$data['entitlements'][] = array( 'key' => 'pass.season_tickets', 'label' => 'Season tickets', 'status' => 'planned' );
		$this->assertIsInt( $this->release( '1.0.0', (string) wp_json_encode( $data ), 'pass-captain-pro' ) );

		$stored = CatalogStore::get( 'pass-captain-pro' );
		$this->assertSame( CatalogStore::SOURCE_RELEASE, $stored['source'] );
		$this->assertCount( 6, $stored['entitlements'] );
		// From now on the shipped file no longer counts for this product: the upgrade step leaves it alone.
		$this->assertTrue( \WPLC\Infrastructure\Database\Schema::run_registered_step( '24' ) );
		$this->assertSame( CatalogStore::SOURCE_RELEASE, CatalogStore::get( 'pass-captain-pro' )['source'] );
		$this->assertCount( 6, CatalogStore::get( 'pass-captain-pro' )['entitlements'] );
	}

	/**
	 * PROD-02: the real add-on packages of captain-pro bring their catalog.
	 *
	 * Needs the built ZIPs: set WPLC_CAPTAIN_PRO_DIST to the dist/ folder of a captain-pro build
	 * (`affiliate-captain-pro-<version>.zip` and so on). Skipped without it, so CI of this
	 * repository does not depend on another repository.
	 */
	public function test_the_real_captain_pro_packages_bring_their_catalog(): void {
		global $wpdb;

		$dist = (string) getenv( 'WPLC_CAPTAIN_PRO_DIST' );
		if ( '' === $dist || ! is_dir( $dist ) ) {
			$this->markTestSkipped( 'Set WPLC_CAPTAIN_PRO_DIST to the dist/ folder of a captain-pro build.' );
		}
		$now      = gmdate( 'Y-m-d H:i:s' );
		$expected = array(
			'pass-captain-pro'      => array( 'available', 5, 0 ),
			'affiliate-captain-pro' => array( 'available', 4, 5 ),
			'voucher-captain-pro'   => array( 'planned', 0, 4 ),
			'form-captain-pro'      => array( 'available', 6, 0 ),
			'review-captain-pro'    => array( 'available', 6, 0 ),
		);
		foreach ( $expected as $product => $want ) {
			$zips = (array) glob( rtrim( $dist, '/' ) . '/' . $product . '-*.zip' );
			$this->assertCount( 1, $zips, $product . ': exactly one built package' );
			$this->assertSame( 1, preg_match( '/-(\d+\.\d+\.\d+)\.zip$/', (string) $zips[0], $match ) );
			// The release needs its own copy: the storage moves nothing out of the build folder.
			$copy          = sys_get_temp_dir() . '/wplc-rc-' . bin2hex( random_bytes( 6 ) ) . '.zip';
			$this->files[] = $copy;
			copy( (string) $zips[0], $copy );
			$wpdb->insert( $this->tables()['products'], array( 'product_key' => $product, 'display_name' => $product, 'active' => 1, 'created_at' => $now, 'updated_at' => $now ) );

			$id = ReleaseRegistry::create_with_package( array( 'product_key' => $product, 'version' => $match[1], 'release_notes' => 'x' ), $copy, 1 );
			$this->assertIsInt( $id, $product . ': ' . ( is_wp_error( $id ) ? $id->get_error_code() . ' ' . $id->get_error_message() : '' ) );
			$result = ReleaseCatalog::last_result();
			$this->assertSame( ReleaseCatalog::STATUS_IMPORTED, $result['status'], $product . ': ' . wp_json_encode( $result ) );

			$stored = CatalogStore::get( $product );
			$this->assertSame( CatalogStore::SOURCE_RELEASE, $stored['source'], $product );
			$this->assertSame( $want[0], $stored['status'], $product );
			$statuses = array_count_values( array_column( $stored['entitlements'], 'status' ) );
			$this->assertSame( $want[1], (int) ( $statuses['available'] ?? 0 ), $product . ' available keys' );
			$this->assertSame( $want[2], (int) ( $statuses['planned'] ?? 0 ), $product . ' planned keys' );
			foreach ( $stored['entitlements'] as $entitlement ) {
				$this->assertSame( array( 'en', 'de_DE', 'de_DE_formal' ), array_keys( $entitlement['label'] ), $entitlement['key'] );
			}
			$this->assertArrayNotHasKey( 'generator', $stored, 'Extra fields of the build are not stored.' );
		}

		// What the product editor offers afterwards.
		$this->assertSame(
			array( 'affiliate.webhooks', 'affiliate.automated_payouts', 'affiliate.white_label_portal', 'affiliate.extended_api', 'form.webhooks', 'form.advanced_logic', 'form.pdf', 'form.uploads', 'form.multi_step', 'form.woocommerce_questionnaires', 'pass.e_invoicing', 'pass.card_terminal', 'pass.white_label', 'pass.waiting_room', 'pass.write_api', 'review.platform_sync', 'review.review_requests', 'review.advanced_widgets', 'review.analytics', 'review.suite_integrations', 'review.custom_connectors' ),
			EntitlementCatalog::known_keys()
		);
		$this->assertSame( array( 'affiliate-captain-pro', 'form-captain-pro', 'pass-captain-pro', 'review-captain-pro' ), array_keys( EntitlementCatalog::known_product_keys() ), 'Voucher Captain Pro is planned in its package: not suggested.' );
		$this->assertArrayHasKey( 'voucher-captain-pro', EntitlementCatalog::planned_product_keys() );
		$this->assertCount( 0, $this->audit( 'catalog_release_import_refused' ) );
	}
}
