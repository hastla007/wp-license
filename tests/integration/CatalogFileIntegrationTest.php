<?php
/**
 * The catalog as data (T-150): migration from the former hard-coded lists, import in the admin and on the command line, audit.
 *
 * @package WPLC
 */

use WPLC\Admin\CatalogFileAdmin;
use WPLC\Catalog\CatalogFile;
use WPLC\Catalog\CatalogStore;
use WPLC\Catalog\EntitlementCatalog;
use WPLC\CLI\CatalogCommand;
use WPLC\Infrastructure\Database\Schema;

final class CatalogFileIntegrationTest extends WPLC_Integration_TestCase {

	/**
	 * Temporary files of the test.
	 *
	 * @var string[]
	 */
	private $files = array();

	public function set_up(): void {
		parent::set_up();
		CatalogStore::reset_cache();
	}

	public function tear_down(): void {
		foreach ( $this->files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		remove_all_filters( 'determine_locale' );
		wp_set_current_user( 0 );
		parent::tear_down();
		// The test transaction is rolled back; forget what this request read.
		CatalogStore::reset_cache();
	}

	private function file( array $data ): string {
		$path = tempnam( sys_get_temp_dir(), 'wplc-catalog-' );
		file_put_contents( $path, wp_json_encode( $data ) );
		$this->files[] = $path;

		return $path;
	}

	private function acme( array $entitlements ): array {
		return array(
			'format'       => 1,
			'product_key'  => 'acme-forms-pro',
			'prefix'       => 'acme',
			'name'         => 'Acme Forms Pro',
			'entitlements' => $entitlements,
		);
	}

	private function audit( string $action ): array {
		global $wpdb;
		$tables = Schema::table_names();

		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tables['audit_log']} WHERE object_type = %s AND action = %s AND CONCAT(COALESCE(before_json, ''), COALESCE(after_json, '')) LIKE %s ORDER BY id ASC", 'catalog', $action, '%acme-forms-pro%' ), ARRAY_A );
	}

	/**
	 * An installation that used the hard-coded lists (schema 23 and older) offers the same after the upgrade.
	 */
	public function test_the_migration_imports_the_shipped_catalog_files(): void {
		global $wpdb;

		$products = CatalogStore::all();
		$this->assertSame(
			array( 'affiliate-captain-pro', 'course-captain-pro', 'form-captain-pro', 'pass-captain-pro', 'review-captain-pro', 'voucher-captain-pro', 'workflow-captain-pro' ),
			array_keys( $products ),
			'Installing the plugin ran schema step 24.'
		);
		$autoload = (string) $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", CatalogStore::OPTION ) );
		$this->assertContains( $autoload, array( 'no', 'off', 'auto-off' ), 'The catalog is read on few screens and is not autoloaded.' );

		// Exactly the keys and product keys of the former lists (after R-24).
		$this->assertSame(
			array( 'affiliate.extended_api', 'affiliate.automated_payouts', 'affiliate.white_label_portal', 'affiliate.webhooks', 'pass.e_invoicing', 'pass.card_terminal', 'pass.waiting_room', 'pass.write_api', 'pass.white_label' ),
			EntitlementCatalog::known_keys()
		);
		$this->assertSame( array( 'affiliate-captain-pro', 'pass-captain-pro', 'voucher-captain-pro' ), array_keys( EntitlementCatalog::known_product_keys() ) );
		$this->assertSame( array( 'course-captain-pro', 'form-captain-pro', 'review-captain-pro', 'workflow-captain-pro' ), array_keys( EntitlementCatalog::planned_product_keys() ) );
		$this->assertCount( 17, array_merge( ...array_values( EntitlementCatalog::planned() ) ) ); // 12 + the three planned Voucher Pro keys of 16 Nov 2026 + the two Workflow keys of F-01.

		// An installation on schema 23: no catalog yet. The step fills it and can run again.
		delete_option( CatalogStore::OPTION );
		CatalogStore::reset_cache();
		$this->assertSame( array(), EntitlementCatalog::known_keys() );
		$this->assertTrue( Schema::run_registered_step( '24' ) );
		$this->assertCount( 9, EntitlementCatalog::known_keys() );
		$before = get_option( CatalogStore::OPTION );
		$this->assertTrue( Schema::run_registered_step( '24' ), 'Step 24 is repeatable.' );
		$this->assertSame( $before, get_option( CatalogStore::OPTION ), 'A second run changes nothing, not even the import time.' );
		$this->assertSame( '0', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE object_type = %s', Schema::table_names()['audit_log'], 'catalog' ) ), 'The upgrade step writes no audit entries; imports by people do.' );
	}

	public function test_labels_come_from_the_file_in_the_language_of_the_request(): void {
		$label = static function (): string {
			foreach ( EntitlementCatalog::all()['pass'] as $entitlement ) {
				if ( 'pass.waiting_room' === $entitlement['key'] ) {
					return $entitlement['label'];
				}
			}
			return '';
		};
		$this->assertSame( 'Virtual waiting room', $label() );
		add_filter(
			'determine_locale',
			static function () {
				return 'de_DE';
			}
		);
		$this->assertSame( 'Virtueller Warteraum', $label() );
		$this->assertSame( 'Pass Captain Pro', EntitlementCatalog::group_label( 'pass' ) );

		// The texts are not in the text domain of the server any more.
		$pot = (string) file_get_contents( dirname( __DIR__, 2 ) . '/languages/wp-license.pot' );
		foreach ( array( 'Virtual waiting room', 'Automated payouts', 'Suite integrations', 'Pass Captain', 'Affiliate Captain' ) as $text ) {
			$this->assertStringNotContainsString( 'msgid "' . $text . '"', $pot, $text );
		}
	}

	public function test_an_administrator_imports_exports_and_removes_a_product_with_audit(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$path = $this->file(
			$this->acme(
				array(
					array( 'key' => 'acme.pdf_export', 'label' => array( 'en' => 'PDF export', 'de_DE' => 'PDF-Export' ) ),
					array( 'key' => 'acme.signatures', 'label' => 'Signatures', 'status' => 'planned' ),
				)
			)
		);

		$result = CatalogFileAdmin::import_path( $path, $admin );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( CatalogStore::RESULT_CREATED, $result['result'] );
		$this->assertStringContainsString( 'acme-forms-pro', CatalogFileAdmin::result_message( $result ) );
		$this->assertContains( 'acme.pdf_export', EntitlementCatalog::known_keys() );
		$this->assertNotContains( 'acme.signatures', EntitlementCatalog::known_keys(), 'Planned keys are not offered.' );
		$this->assertArrayHasKey( 'acme-forms-pro', EntitlementCatalog::known_product_keys() );

		$rows = $this->audit( 'catalog_file_imported' );
		$this->assertCount( 1, $rows );
		$this->assertSame( (string) $admin, (string) $rows[0]['actor_user_id'] );
		$after = json_decode( (string) $rows[0]['after_json'], true );
		$this->assertSame( 'acme-forms-pro', $after['product_key'] );
		$this->assertSame( array( 'acme.pdf_export', 'acme.signatures' ), $after['added'] );
		$this->assertSame( CatalogStore::SOURCE_ADMIN, $after['source'] );

		// The same file again: nothing changes, nothing is logged.
		$again = CatalogFileAdmin::import_path( $path, $admin );
		$this->assertSame( CatalogStore::RESULT_UNCHANGED, $again['result'] );
		$this->assertCount( 1, $this->audit( 'catalog_file_imported' ) );

		// The screen lists the product; the export is a file that imports to the same product.
		ob_start();
		CatalogFileAdmin::render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( '<code>acme-forms-pro</code>', $html );
		$this->assertStringContainsString( '<code>acme.signatures</code>', $html );
		$this->assertStringContainsString( 'wplc_import_catalog_file', $html );
		$exported = CatalogFile::parse( CatalogFile::encode( CatalogStore::get( 'acme-forms-pro' ) ) );
		$this->assertTrue( $exported['ok'] );
		$this->assertSame( CatalogStore::get( 'acme-forms-pro' )['fingerprint'], CatalogFile::fingerprint( $exported['catalog'] ) );

		// An invalid file changes nothing and names the field.
		$bad = CatalogFileAdmin::import_path( $this->file( $this->acme( array( array( 'key' => 'other.key', 'label' => 'x' ) ) ) ), $admin );
		$this->assertFalse( $bad['ok'] );
		$this->assertSame( array( array( 'code' => 'key_without_prefix', 'path' => 'entitlements[0].key' ) ), $bad['errors'] );
		$this->assertNotSame( 'key_without_prefix', CatalogFileAdmin::error_message( 'key_without_prefix' ) );
		$this->assertCount( 2, CatalogStore::get( 'acme-forms-pro' )['entitlements'] );

		$this->assertTrue( CatalogStore::remove( 'acme-forms-pro', $admin ) );
		$this->assertCount( 1, $this->audit( 'catalog_product_removed' ) );
		$this->assertNotContains( 'acme.pdf_export', EntitlementCatalog::known_keys() );
	}

	public function test_the_screen_needs_the_catalog_capability(): void {
		$customer = self::factory()->user->create( array( 'role' => 'customer' ) );
		wp_set_current_user( $customer );
		$this->assertFalse( current_user_can( \WPLC\Admin\Capabilities::cap( 'manage_catalog' ) ) );
		try {
			CatalogFileAdmin::render();
			$this->fail( 'A user without the capability must not see the screen.' );
		} catch ( WPDieException $exception ) {
			$this->assertStringContainsString( 'permission', $exception->getMessage() );
		}
	}

	public function test_the_command_line_validates_and_imports(): void {
		$path = $this->file( $this->acme( array( array( 'key' => 'acme.pdf_export', 'label' => 'PDF export' ) ) ) );

		$dry = CatalogCommand::run_import( $path, true );
		$this->assertTrue( $dry['ok'] );
		$this->assertSame( CatalogStore::RESULT_CREATED, $dry['result'] );
		$this->assertNull( CatalogStore::get( 'acme-forms-pro' ), 'A dry run writes nothing.' );

		$result = CatalogCommand::run_import( $path, false );
		$this->assertSame( CatalogStore::RESULT_CREATED, $result['result'] );
		$this->assertSame( CatalogStore::SOURCE_CLI, CatalogStore::get( 'acme-forms-pro' )['source'] );
		$this->assertFalse( CatalogCommand::run_import( '/nonexistent/wplc-catalog.json', false )['ok'] );

		// A shipped file never replaces what the vendor imported.
		$own                 = CatalogStore::get( 'pass-captain-pro' );
		$own['entitlements'] = array_slice( $own['entitlements'], 0, 1 );
		CatalogStore::import( $own, CatalogStore::SOURCE_CLI );
		$this->assertTrue( Schema::run_registered_step( '24' ) );
		$this->assertCount( 1, CatalogStore::get( 'pass-captain-pro' )['entitlements'] );
	}

	/**
	 * The handler behind "Remove": capability, nonce and a confirmation that needs no script.
	 */
	public function test_removing_a_product_needs_capability_nonce_and_confirmation(): void {
		$admin    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$customer = self::factory()->user->create( array( 'role' => 'customer' ) );
		CatalogStore::import_json( (string) wp_json_encode( $this->acme( array( array( 'key' => 'acme.pdf_export', 'label' => 'PDF export' ) ) ) ), CatalogStore::SOURCE_CLI );
		$post = static function ( array $overrides = array() ): array {
			return array_merge(
				array(
					'product_key'    => 'acme-forms-pro',
					'confirm_remove' => '1',
					'_wpnonce'       => wp_create_nonce( CatalogFileAdmin::NONCE_ACTION ),
				),
				$overrides
			);
		};

		// Without the capability, also with a valid nonce of that user.
		wp_set_current_user( $customer );
		$this->assertSame( 'die', $this->run_admin_post( 'wplc_remove_catalog_product', $post() )['outcome'] );
		$this->assertNotNull( CatalogStore::get( 'acme-forms-pro' ) );

		wp_set_current_user( $admin );
		// Without a nonce and with a wrong one.
		$this->assertSame( 'die', $this->run_admin_post( 'wplc_remove_catalog_product', $post( array( '_wpnonce' => '' ) ) )['outcome'] );
		$this->assertSame( 'die', $this->run_admin_post( 'wplc_remove_catalog_product', $post( array( '_wpnonce' => 'wrong' ) ) )['outcome'] );
		$this->assertNotNull( CatalogStore::get( 'acme-forms-pro' ) );

		// Without the ticked confirmation: back to the screen, nothing removed, nothing logged.
		$unconfirmed = $post();
		unset( $unconfirmed['confirm_remove'] );
		$result = $this->run_admin_post( 'wplc_remove_catalog_product', $unconfirmed );
		$this->assertSame( 'redirect', $result['outcome'] );
		$this->assertStringContainsString( 'page=' . CatalogFileAdmin::PAGE, $result['location'] );
		$this->assertNotNull( CatalogStore::get( 'acme-forms-pro' ) );
		$this->assertCount( 0, $this->audit( 'catalog_product_removed' ) );
		ob_start();
		CatalogFileAdmin::render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'tick the box', $html, 'The screen says why nothing happened.' );
		$this->assertStringContainsString( 'name="confirm_remove" value="1" required', $html, 'The confirmation is a required field of the form, not a script.' );
		$this->assertStringNotContainsString( 'data-wplc-confirm', $html );

		// Confirmed: removed and audited with the acting user.
		$result = $this->run_admin_post( 'wplc_remove_catalog_product', $post() );
		$this->assertSame( 'redirect', $result['outcome'] );
		$this->assertNull( CatalogStore::get( 'acme-forms-pro' ) );
		$rows = $this->audit( 'catalog_product_removed' );
		$this->assertCount( 1, $rows );
		$this->assertSame( (string) $admin, (string) $rows[0]['actor_user_id'] );
		$this->assertSame( 'acme-forms-pro', json_decode( (string) $rows[0]['before_json'], true )['product_key'] );
	}

	/**
	 * Removing a product from the catalog takes nothing away from what was sold.
	 */
	public function test_removing_a_product_leaves_plans_and_licenses_untouched(): void {
		global $wpdb;
		$tables  = Schema::table_names();
		$product = $this->create_license_product(
			array(
				'product_key'  => 'pass-captain-pro',
				'plan_key'     => 'single',
				'entitlements' => array( 'pass.waiting_room' => true, 'pass.write_api' => true ),
			)
		);
		$order   = $this->create_paid_order( array( array( $product, 1 ) ), self::factory()->user->create() );
		$license = $this->licenses_for_order( $order->get_id() )[0];
		$plan    = static function () use ( $wpdb, $tables, $product ): string {
			return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT entitlements_json FROM %i WHERE woocommerce_product_id = %d', $tables['plans'], $product->get_id() ) );
		};
		$granted = static function () use ( $wpdb, $tables, $license ): array {
			return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT entitlement_key, value_json, status FROM %i WHERE license_id = %d ORDER BY entitlement_key', $tables['entitlements'], (int) $license['id'] ), ARRAY_A );
		};
		$plan_before    = $plan();
		$granted_before = $granted();
		$this->assertStringContainsString( 'pass.waiting_room', $plan_before );
		$this->assertContains( 'pass.waiting_room', array_column( $granted_before, 'entitlement_key' ) );

		$this->assertTrue( CatalogStore::remove( 'pass-captain-pro', 1 ) );

		$this->assertSame( $plan_before, $plan(), 'The plan keeps its entitlements.' );
		$this->assertSame( $granted_before, $granted(), 'The license keeps its entitlements.' );
		$this->assertSame( 'active', $this->license( (int) $license['id'] )['status'] );
		// The product editor no longer offers the keys as tick boxes; it shows them in the JSON field, and saving keeps them.
		$stored = (array) json_decode( $plan_before, true );
		$split  = EntitlementCatalog::split( $stored );
		$this->assertNotContains( 'pass.waiting_room', $split['checked'] );
		$this->assertTrue( $split['advanced']['pass.waiting_room'] );
		$merged = EntitlementCatalog::merge( $split['checked'], $split['advanced'] );
		ksort( $stored );
		ksort( $merged );
		$this->assertSame( $stored, $merged );
		// The product key itself is no longer suggested, but the plan still sells.
		$this->assertArrayNotHasKey( 'pass-captain-pro', EntitlementCatalog::known_product_keys() );
		$again = $this->create_paid_order( array( array( wc_get_product( $product->get_id() ), 1 ) ), self::factory()->user->create() );
		$this->assertCount( 1, $this->licenses_for_order( $again->get_id() ), 'A plan of a removed catalog product still issues licenses.' );
	}
}
