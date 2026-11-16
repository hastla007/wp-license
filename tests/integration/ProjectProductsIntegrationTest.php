<?php
/**
 * Decision 17 (project products): the connectors of customer projects are products of their own,
 * each with its own prefix (PK-01), and their catalog file arrives with their first release (PK-04).
 *
 * @package WPLC
 */

use WPLC\Catalog\Catalog;
use WPLC\Catalog\CatalogStore;
use WPLC\Catalog\EntitlementCatalog;
use WPLC\Catalog\ProductEditor;
use WPLC\Catalog\ReleaseCatalog;
use WPLC\Update\ReleaseRegistry;

final class ProjectProductsIntegrationTest extends WPLC_Integration_TestCase {

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
		CatalogStore::reset_cache();
		ReleaseCatalog::reset();
		$this->storage_dir = sys_get_temp_dir() . '/wplc-pk-' . bin2hex( random_bytes( 6 ) );
		$dir               = $this->storage_dir;
		add_filter(
			'wplc_package_storage_dir',
			static function () use ( $dir ) {
				return $dir;
			}
		);
		require_once WC()->plugin_path() . '/includes/admin/wc-meta-box-functions.php';
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
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
		unset( $GLOBALS['post'] );
		wp_set_current_user( 0 );
		parent::tear_down();
		CatalogStore::reset_cache();
		ReleaseCatalog::reset();
	}

	/** Catalog file of a connector: one prefix per connector. */
	private function catalog( string $product_key, string $prefix, string $status = 'available' ): string {
		return (string) wp_json_encode(
			array(
				'format'       => 1,
				'product_key'  => $product_key,
				'prefix'       => $prefix,
				'name'         => ucfirst( str_replace( '-', ' ', $product_key ) ),
				'status'       => $status,
				'entitlements' => array(
					array( 'key' => $prefix . '.sync', 'label' => array( 'en' => 'Sync', 'de_DE' => 'Abgleich', 'de_DE_formal' => 'Abgleich' ) ),
					array( 'key' => $prefix . '.field_mapping', 'label' => 'Field mapping' ),
				),
			)
		);
	}

	/** A license product that is not for sale: private, so neither the shop nor the pricing table shows it. */
	private function project_product( string $product_key ): WC_Product_Simple {
		$product = $this->create_license_product( array( 'product_key' => $product_key, 'entitlements' => array() ) );
		$product->set_status( 'private' );
		$product->save();

		return $product;
	}

	/**
	 * Entitlement groups of the rendered product editor and whether each is shown.
	 *
	 * @return array{groups:array<string,bool>,hint:bool,map:array<string,string>}
	 */
	private function editor( WC_Product $product ): array {
		$GLOBALS['post'] = get_post( $product->get_id() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		ob_start();
		ProductEditor::render_product_fields();
		$html = (string) ob_get_clean();

		preg_match_all( '/data-wplc-entitlement-group="([^"]+)"( hidden)?>/', $html, $matches, PREG_SET_ORDER );
		$groups = array();
		foreach ( $matches as $match ) {
			$groups[ $match[1] ] = empty( $match[2] );
		}
		$this->assertSame( 1, preg_match( '/data-wplc-product-groups="([^"]*)"/', $html, $map ) );
		$this->assertSame( 1, preg_match( '/<p class="description" data-wplc-entitlement-none( hidden)?>/', $html, $hint ) );

		return array(
			'groups' => $groups,
			'hint'   => empty( $hint[1] ),
			'map'    => (array) json_decode( html_entity_decode( $map[1], ENT_QUOTES ), true ),
		);
	}

	/** @return string[] The groups the editor shows. */
	private function shown( WC_Product $product ): array {
		return array_keys( array_filter( $this->editor( $product )['groups'] ) );
	}

	/**
	 * Create a draft release from a connector package; the catalog file lies in the plugin folder.
	 *
	 * @return int|WP_Error
	 */
	private function release( string $product_key, string $version, ?string $catalog ) {
		$path          = sys_get_temp_dir() . '/wplc-pk-' . bin2hex( random_bytes( 6 ) ) . '.zip';
		$this->files[] = $path;
		$zip           = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString( $product_key . '/' . $product_key . '.php', "<?php\n/**\n * Plugin Name: Connector\n * Version: {$version}\n */\n" );
		if ( null !== $catalog ) {
			$zip->addFromString( $product_key . '/wplc-catalog.json', $catalog );
		}
		$zip->close();

		return ReleaseRegistry::create_with_package( array( 'product_key' => $product_key, 'version' => $version, 'release_notes' => 'First release' ), $path, get_current_user_id() );
	}

	/**
	 * PK-01 (2) and (3): the editor offers a product key only the group the catalog maps it to.
	 */
	public function test_pk01_the_product_editor_offers_a_project_product_only_its_own_rights(): void {
		$this->assertTrue( CatalogStore::import_json( $this->catalog( 'learnboating-connector', 'learnboating' ), CatalogStore::SOURCE_ADMIN )['ok'] );
		$voucher = $this->project_product( 'learnboating-connector' );
		$form    = $this->project_product( 'learnboating-form-connector' );
		$pass    = $this->create_license_product( array( 'product_key' => 'pass-captain-pro', 'entitlements' => array() ) );

		// Before the catalog file of the form connector is imported: nothing is offered, and the editor says why.
		$editor = $this->editor( $form );
		$this->assertArrayHasKey( 'learnboating', $editor['groups'] );
		$this->assertSame( array(), array_keys( array_filter( $editor['groups'] ) ), '"learnboating-form-connector" contains "learnboating" but is not offered its rights.' );
		$this->assertTrue( $editor['hint'] );
		$this->assertSame( 'learnboating', $editor['map']['learnboating-connector'] );
		$this->assertArrayNotHasKey( 'learnboating-form-connector', $editor['map'] );

		$this->assertTrue( CatalogStore::import_json( $this->catalog( 'learnboating-form-connector', 'learnboating_form' ), CatalogStore::SOURCE_ADMIN )['ok'] );
		$this->assertTrue( CatalogStore::import_json( $this->catalog( 'boatingschool-form-connector', 'boatingschool_form' ), CatalogStore::SOURCE_ADMIN )['ok'] );
		$editor = $this->editor( $form );
		$this->assertSame( array( 'learnboating_form' ), array_keys( array_filter( $editor['groups'] ) ) );
		$this->assertFalse( $editor['hint'] );
		$this->assertSame( array( 'learnboating' ), $this->shown( $voucher ) );
		$this->assertSame( array( 'pass' ), $this->shown( $pass ), 'No project right is suggested for another product.' );

		// A ticked right of another group stays visible, so saving never drops it silently.
		Catalog::save_mapping( $form->get_id(), 0, $this->mapping_data( array( 'product_key' => 'learnboating-form-connector', 'entitlements' => array( 'learnboating.sync' => true ) ) ) );
		$this->assertSame( array( 'learnboating', 'learnboating_form' ), $this->shown( $form ) );
	}

	/**
	 * PK-04: the first release of a connector that has no catalog entry yet brings its catalog file.
	 * The required order: the license product exists first (it puts the key into the product
	 * selection of the release screen); then the first release is uploaded.
	 */
	public function test_pk04_the_first_release_of_a_connector_brings_product_and_rights_into_the_catalog(): void {
		// Without an MU plugin: no code adds a catalog or product keys.
		$this->assertFalse( has_filter( 'wplc_entitlement_catalog' ) );
		$this->assertFalse( has_filter( 'wplc_known_product_keys' ) );
		$this->assertFalse( function_exists( 'wplc_e2e_mails' ) );

		// Without a license product the key is unknown to the release screen.
		$this->assertArrayNotHasKey( 'learnboating-form-connector', ReleaseRegistry::products() );
		$early = $this->release( 'learnboating-form-connector', '1.0.0', $this->catalog( 'learnboating-form-connector', 'learnboating_form' ) );
		$this->assertWPError( $early );
		$this->assertSame( 'unknown_product', $early->get_error_code() );
		$this->assertNull( CatalogStore::get( 'learnboating-form-connector' ) );

		// A license product that is not sold: private, no rights yet, no catalog entry.
		$product = $this->project_product( 'learnboating-form-connector' );
		$this->assertSame( 'private', get_post_status( $product->get_id() ) );
		$this->assertArrayHasKey( 'learnboating-form-connector', ReleaseRegistry::products() );
		$this->assertNull( CatalogStore::get( 'learnboating-form-connector' ) );
		$this->assertSame( array(), EntitlementCatalog::groups_for_product_key( 'learnboating-form-connector' ) );

		$id = $this->release( 'learnboating-form-connector', '1.0.0', $this->catalog( 'learnboating-form-connector', 'learnboating_form' ) );
		$this->assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_code() : '' );
		$result = ReleaseCatalog::last_result();
		$this->assertSame( ReleaseCatalog::STATUS_IMPORTED, $result['status'], (string) wp_json_encode( $result ) );
		$this->assertSame( array( 'learnboating_form.sync', 'learnboating_form.field_mapping' ), $result['added'] );
		$this->assertNotSame( '', ReleaseCatalog::message( $result ) );

		// Product and rights are in the catalog; "available" is accepted although the product is not sold.
		$stored = CatalogStore::get( 'learnboating-form-connector' );
		$this->assertSame( CatalogStore::SOURCE_RELEASE, $stored['source'] );
		$this->assertSame( 'available', $stored['status'] );
		$this->assertSame( 'learnboating_form', $stored['prefix'] );
		$this->assertSame( 'learnboating_form', EntitlementCatalog::known_product_keys()['learnboating-form-connector'], 'The editor suggests the key: only the owner sees it.' );
		$this->assertSame( array( 'learnboating_form' ), EntitlementCatalog::groups_for_product_key( 'learnboating-form-connector' ) );
		$this->assertContains( 'learnboating_form.sync', EntitlementCatalog::known_keys() );
		$this->assertSame( array( 'learnboating_form' ), $this->shown( $product ) );
		$this->assertCount( 0, (array) $GLOBALS['wpdb']->get_results( $GLOBALS['wpdb']->prepare( 'SELECT id FROM %i WHERE action = %s', $this->tables()['audit_log'], 'catalog_release_import_refused' ) ) );

		// The owner ticks the rights; the plan stores them.
		$this->assertTrue( Catalog::save_mapping( $product->get_id(), 0, $this->mapping_data( array( 'product_key' => 'learnboating-form-connector', 'entitlements' => EntitlementCatalog::merge( array( 'learnboating_form.sync' ), array() ) ) ) ) );
		$plan = Catalog::find_exact_mapping( $product->get_id(), 0 );
		$this->assertTrue( json_decode( (string) $plan['entitlements_json'], true )['learnboating_form.sync'] );
		$this->assertSame( 'private', get_post_status( $product->get_id() ), 'The product stays off the shop.' );
	}
}
