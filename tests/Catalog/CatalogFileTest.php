<?php
/**
 * The catalog as data (T-150): catalog files, their validation, the store and what the product editor reads from it.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\Catalog\CatalogFile;
use WPLC\Catalog\CatalogStore;
use WPLC\Catalog\EntitlementCatalog;

require_once dirname( __DIR__ ) . '/Admin/wp-function-stubs.php';

final class CatalogFileTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		foreach ( array_keys( CatalogStore::all() ) as $product_key ) {
			CatalogStore::remove( (string) $product_key );
		}
	}

	protected function tearDown(): void {
		foreach ( array_keys( CatalogStore::all() ) as $product_key ) {
			CatalogStore::remove( (string) $product_key );
		}
		unset( $GLOBALS['wplc_test_filters']['wplc_offer_planned_entitlements'] );
		parent::tearDown();
	}

	/** A catalog of a vendor that has nothing to do with the Captain products. */
	private function acme( array $overrides = array() ): array {
		return array_merge(
			array(
				'format'       => 1,
				'product_key'  => 'acme-forms-pro',
				'prefix'       => 'acme',
				'name'         => 'Acme Forms Pro',
				'entitlements' => array(
					array(
						'key'    => 'acme.pdf_export',
						'label'  => array(
							'en'           => 'PDF export',
							'de_DE'        => 'PDF-Export (du)',
							'de_DE_formal' => 'PDF-Export (Sie)',
						),
						'status' => 'available',
					),
					array(
						'key'    => 'acme.signatures',
						'label'  => 'Signatures',
						'status' => 'planned',
					),
				),
			),
			$overrides
		);
	}

	private function json( array $data ): string {
		return (string) json_encode( $data );
	}

	/** @return string[] Error codes. */
	private function codes( array $data ): array {
		$result = CatalogFile::validate( $data );
		$this->assertFalse( $result['ok'] );
		$this->assertNull( $result['catalog'] );

		return array_column( $result['errors'], 'code' );
	}

	public function test_a_valid_file_is_normalized(): void {
		$result = CatalogFile::parse( $this->json( $this->acme() ) );
		$this->assertTrue( $result['ok'], (string) json_encode( $result['errors'] ) );
		$catalog = $result['catalog'];

		$this->assertSame( 'acme-forms-pro', $catalog['product_key'] );
		$this->assertSame( 'acme', $catalog['prefix'] );
		$this->assertSame( array( 'en' => 'Acme Forms Pro' ), $catalog['name'], 'A plain string is the English text.' );
		$this->assertSame( 'available', $catalog['status'], 'The default status of a product.' );
		$this->assertSame( array( 'acme.pdf_export', 'acme.signatures' ), array_column( $catalog['entitlements'], 'key' ) );
		$this->assertSame( array( 'en' => 'Signatures' ), $catalog['entitlements'][1]['label'] );
		$this->assertSame( array(), $catalog['entitlements'][0]['description'] );

		// Encoding the normalized product gives a file that reads back to the same product.
		$again = CatalogFile::parse( CatalogFile::encode( $catalog ) );
		$this->assertSame( $catalog, $again['catalog'] );
		$this->assertSame( CatalogFile::fingerprint( $catalog ), CatalogFile::fingerprint( $again['catalog'] ) );
	}

	public function test_the_prefix_is_read_from_the_keys_when_the_file_leaves_it_out(): void {
		$data = $this->acme();
		unset( $data['prefix'] );
		$this->assertSame( 'acme', CatalogFile::validate( $data )['catalog']['prefix'] );

		// Without keys there is nothing to read it from.
		$data['entitlements'] = array();
		$this->assertSame( array( 'prefix_missing' ), $this->codes( $data ) );
	}

	public function test_unknown_fields_are_ignored_and_a_byte_order_mark_is_accepted(): void {
		// What the build of captain-pro adds to its files: `generator` and `addon` (the add-on version).
		$data                               = $this->acme( array( 'generator' => 'acme-build 3.1', 'addon' => '0.1.0', 'x-internal' => array( 'any' => array( 'thing' ) ) ) );
		$data['entitlements'][0]['since']   = '2.0.0';
		$result                             = CatalogFile::parse( "\xEF\xBB\xBF" . $this->json( $data ) );
		$this->assertTrue( $result['ok'] );
		$this->assertArrayNotHasKey( 'generator', $result['catalog'] );
		$this->assertArrayNotHasKey( 'addon', $result['catalog'] );
		$this->assertSame( CatalogFile::fingerprint( CatalogFile::validate( $this->acme() )['catalog'] ), CatalogFile::fingerprint( $result['catalog'] ), 'Extra fields do not make a file a different catalog.' );
		$this->assertArrayNotHasKey( 'since', $result['catalog']['entitlements'][0] );
	}

	public function test_invalid_files_are_rejected_with_the_field_and_a_reason(): void {
		$this->assertSame( array( 'not_a_json_object' ), array_column( CatalogFile::parse( 'not json' )['errors'], 'code' ) );
		$this->assertSame( array( 'not_a_json_object' ), array_column( CatalogFile::parse( '[1,2]' )['errors'], 'code' ) );
		$this->assertSame( array( 'file_too_large' ), array_column( CatalogFile::parse( str_repeat( ' ', CatalogFile::MAX_BYTES + 1 ) )['errors'], 'code' ) );

		$this->assertSame( array( 'format_unsupported' ), $this->codes( $this->acme( array( 'format' => 2 ) ) ), 'A newer format is not guessed at.' );
		$this->assertContains( 'format_missing', $this->codes( $this->acme( array( 'format' => '1' ) ) ) );
		$this->assertSame( array( 'product_key_invalid' ), $this->codes( $this->acme( array( 'product_key' => 'Acme Forms' ) ) ) );
		$this->assertSame( array( 'prefix_invalid' ), $this->codes( $this->acme( array( 'prefix' => 'Acme!' ) ) ) );
		$this->assertSame( array( 'status_invalid' ), $this->codes( $this->acme( array( 'status' => 'beta' ) ) ) );
		$this->assertSame( array( 'entitlements_missing' ), $this->codes( $this->acme( array( 'entitlements' => 'none' ) ) ) );
		$this->assertSame( array( 'text_missing' ), $this->codes( $this->acme( array( 'name' => array( 'de_DE' => 'Nur deutsch' ) ) ) ), 'English is the fallback and therefore required.' );
		$this->assertSame( array( 'locale_invalid' ), $this->codes( $this->acme( array( 'name' => array( 'en' => 'Acme', 'german' => 'Acme' ) ) ) ) );
		$this->assertSame( array( 'text_invalid' ), $this->codes( $this->acme( array( 'name' => '<b>Acme</b>' ) ) ), 'Names and labels are plain text.' );
		$this->assertSame( array( 'text_too_long' ), $this->codes( $this->acme( array( 'name' => str_repeat( 'a', 191 ) ) ) ) );

		$entry = static function ( array $entitlement ): array {
			return array( 'entitlements' => array( $entitlement ) );
		};
		$this->assertSame( array( 'key_invalid' ), $this->codes( $this->acme( $entry( array( 'key' => 'acme.pdf export', 'label' => 'x' ) ) ) ) );
		$this->assertSame( array( 'key_without_prefix' ), $this->codes( $this->acme( $entry( array( 'key' => 'other.pdf_export', 'label' => 'x' ) ) ) ), 'A file cannot define keys of another product.' );
		$this->assertSame( array( 'text_missing' ), $this->codes( $this->acme( $entry( array( 'key' => 'acme.pdf_export' ) ) ) ) );
		$this->assertSame( array( 'status_invalid' ), $this->codes( $this->acme( $entry( array( 'key' => 'acme.pdf_export', 'label' => 'x', 'status' => 'soon' ) ) ) ) );
		$this->assertSame( array( 'entitlement_not_an_object' ), $this->codes( $this->acme( array( 'entitlements' => array( 'acme.pdf_export' ) ) ) ) );

		$twice = $this->acme();
		$twice['entitlements'][1]['key'] = 'acme.pdf_export';
		$result = CatalogFile::validate( $twice );
		$this->assertSame( array( array( 'code' => 'key_duplicate', 'path' => 'entitlements[1].key' ) ), $result['errors'], 'The error names the field.' );

		foreach ( array( 'key_duplicate', 'format_unsupported', 'text_missing' ) as $code ) {
			$this->assertNotSame( $code, CatalogFile::describe( $code ), 'Every code has a plain description.' );
		}
	}

	public function test_import_is_idempotent_and_reports_what_changed(): void {
		$first = CatalogStore::import_json( $this->json( $this->acme() ), CatalogStore::SOURCE_CLI );
		$this->assertTrue( $first['ok'] );
		$this->assertSame( CatalogStore::RESULT_CREATED, $first['result'] );
		$this->assertSame( array( 'acme.pdf_export', 'acme.signatures' ), $first['added'] );

		$again = CatalogStore::import_json( $this->json( $this->acme() ), CatalogStore::SOURCE_CLI );
		$this->assertSame( CatalogStore::RESULT_UNCHANGED, $again['result'] );
		$this->assertSame( array( array(), array(), array() ), array( $again['added'], $again['removed'], $again['changed'] ) );
		$imported_at = CatalogStore::get( 'acme-forms-pro' )['imported_at'];

		// A new version of the add-on: one key released, one gone, one new.
		$next                                  = $this->acme();
		$next['entitlements'][1]['status']     = 'available';
		$next['entitlements'][0]               = array( 'key' => 'acme.webhooks', 'label' => 'Webhooks' );
		$dry                                   = CatalogStore::import_json( $this->json( $next ), CatalogStore::SOURCE_CLI, 0, true );
		$this->assertSame( CatalogStore::RESULT_UPDATED, $dry['result'] );
		$this->assertSame( array( 'acme.pdf_export', 'acme.signatures' ), array_column( CatalogStore::get( 'acme-forms-pro' )['entitlements'], 'key' ), 'A dry run writes nothing.' );

		$update = CatalogStore::import_json( $this->json( $next ), CatalogStore::SOURCE_ADMIN, 7 );
		$this->assertSame( CatalogStore::RESULT_UPDATED, $update['result'] );
		$this->assertSame( array( 'acme.webhooks' ), $update['added'] );
		$this->assertSame( array( 'acme.pdf_export' ), $update['removed'] );
		$this->assertSame( array( 'acme.signatures' ), $update['changed'] );
		$stored = CatalogStore::get( 'acme-forms-pro' );
		$this->assertSame( CatalogStore::SOURCE_ADMIN, $stored['source'] );
		$this->assertSame( CatalogFile::fingerprint( CatalogFile::validate( $next )['catalog'] ), $stored['fingerprint'] );
		$this->assertGreaterThanOrEqual( $imported_at, $stored['imported_at'] );

		$this->assertFalse( CatalogStore::import_json( '{"format":1}', CatalogStore::SOURCE_CLI )['ok'] );
		$this->assertTrue( CatalogStore::remove( 'acme-forms-pro' ) );
		$this->assertFalse( CatalogStore::remove( 'acme-forms-pro' ) );
		$this->assertNull( CatalogStore::get( 'acme-forms-pro' ) );
	}

	public function test_a_prefix_belongs_to_one_product(): void {
		$this->assertTrue( CatalogStore::import_json( $this->json( $this->acme() ), CatalogStore::SOURCE_CLI )['ok'] );
		$other = CatalogStore::import_json( $this->json( $this->acme( array( 'product_key' => 'acme-forms-lite' ) ) ), CatalogStore::SOURCE_CLI );
		$this->assertFalse( $other['ok'] );
		$this->assertSame( 'prefix_in_use', $other['errors'][0]['code'] );
		$this->assertNull( CatalogStore::get( 'acme-forms-lite' ) );
	}

	/** A connector of a customer project (PK-01): its own product key and prefix. */
	private function connector( string $product_key, string $prefix, string $suffix = 'sync' ): array {
		return array(
			'format'       => 1,
			'product_key'  => $product_key,
			'prefix'       => $prefix,
			'name'         => $product_key,
			'entitlements' => array( array( 'key' => $prefix . '.' . $suffix, 'label' => 'Sync' ) ),
		);
	}

	/**
	 * PK-01: one prefix per connector; prefixes with an underscore live side by side, a prefix that
	 * contains another one does not make a product key resolve to the other group.
	 */
	public function test_project_connectors_with_underscore_prefixes_live_side_by_side(): void {
		// The form connector is uploaded before the voucher connector: nothing is offered for it yet.
		$this->assertSame( array(), EntitlementCatalog::groups_for_product_key( 'learnboating-form-connector' ) );
		$this->assertTrue( CatalogStore::import_json( $this->json( $this->connector( 'learnboating-connector', 'learnboating' ) ), CatalogStore::SOURCE_CLI )['ok'] );
		$this->assertSame( array(), EntitlementCatalog::groups_for_product_key( 'learnboating-form-connector' ), 'No substring match on "learnboating".' );

		foreach ( array( 'learnboating-form-connector' => 'learnboating_form', 'boatingschool-form-connector' => 'boatingschool_form' ) as $product_key => $prefix ) {
			$import = CatalogStore::import_json( $this->json( $this->connector( $product_key, $prefix ) ), CatalogStore::SOURCE_CLI );
			$this->assertTrue( $import['ok'], (string) json_encode( $import['errors'] ) );
			$this->assertSame( array( $prefix . '.sync' ), $import['added'] );
		}
		$this->assertSame(
			array( 'learnboating-connector' => 'learnboating', 'learnboating-form-connector' => 'learnboating_form', 'boatingschool-form-connector' => 'boatingschool_form' ),
			EntitlementCatalog::known_product_keys()
		);
		$this->assertSame( array( 'learnboating', 'learnboating_form', 'boatingschool_form' ), array_keys( EntitlementCatalog::all() ) );
		$this->assertSame( array( 'learnboating.sync', 'learnboating_form.sync', 'boatingschool_form.sync' ), EntitlementCatalog::known_keys() );
		foreach ( EntitlementCatalog::known_product_keys() as $product_key => $prefix ) {
			$this->assertSame( array( $prefix ), EntitlementCatalog::groups_for_product_key( $product_key ), $product_key );
		}
		// No project right is offered for another product, a key of another vendor or an unknown key.
		foreach ( array( 'voucher-captain-pro', 'learnboating', 'learnboating-form', 'boatingschool-connector', 'learnboating-form-connector-2' ) as $other ) {
			$this->assertSame( array(), EntitlementCatalog::groups_for_product_key( $other ), $other );
		}

		// A second file with the prefix of the voucher connector is still refused.
		$second = CatalogStore::import_json( $this->json( $this->connector( 'learnboating-voucher-connector', 'learnboating' ) ), CatalogStore::SOURCE_CLI );
		$this->assertFalse( $second['ok'] );
		$this->assertSame( 'prefix_in_use', $second['errors'][0]['code'] );
		$this->assertNull( CatalogStore::get( 'learnboating-voucher-connector' ) );
		// A file cannot define keys of the other prefix either: "learnboating_form.x" is not "learnboating.x".
		$foreign = array_merge( $this->connector( 'learnboating-form-connector', 'learnboating_form' ), array( 'entitlements' => array( array( 'key' => 'learnboating.sync', 'label' => 'x' ) ) ) );
		$this->assertSame( array( 'key_without_prefix' ), $this->codes( $foreign ) );
	}

	/**
	 * The product editor of another vendor: nothing in the server code names this product.
	 */
	public function test_the_product_editor_reads_only_the_catalog(): void {
		$this->assertSame( array(), EntitlementCatalog::all(), 'An empty catalog offers nothing.' );
		$this->assertSame( array(), EntitlementCatalog::known_product_keys() );
		$this->assertSame( array(), EntitlementCatalog::known_keys() );
		// Stored entitlements survive an empty catalog in the advanced JSON field.
		$split = EntitlementCatalog::split( array( 'pass.waiting_room' => true ) );
		$this->assertSame( array( array(), array( 'pass.waiting_room' => true ) ), array( $split['checked'], $split['advanced'] ) );

		CatalogStore::import_json( $this->json( $this->acme() ), CatalogStore::SOURCE_CLI );
		$this->assertSame( array( 'acme' => array( array( 'key' => 'acme.pdf_export', 'label' => 'PDF export' ) ) ), EntitlementCatalog::all() );
		$this->assertSame( array( 'acme' => array( array( 'key' => 'acme.signatures', 'label' => 'Signatures' ) ) ), EntitlementCatalog::planned() );
		$this->assertSame( array( 'acme-forms-pro' => 'acme' ), EntitlementCatalog::known_product_keys() );
		$this->assertSame( array(), EntitlementCatalog::planned_product_keys() );
		$this->assertSame( array( 'acme' ), EntitlementCatalog::groups_for_product_key( 'acme-forms-pro' ) );
		$this->assertSame( 'Acme Forms Pro', EntitlementCatalog::group_label( 'acme' ) );
		$this->assertSame( array( 'acme.pdf_export' => true ), EntitlementCatalog::merge( array( 'acme.pdf_export', 'acme.signatures' ), array() ), 'A planned key cannot be ticked.' );

		$GLOBALS['wplc_test_filters']['wplc_offer_planned_entitlements'][] = static function () {
			return true;
		};
		$this->assertSame( array( 'acme.pdf_export', 'acme.signatures' ), EntitlementCatalog::known_keys() );
		unset( $GLOBALS['wplc_test_filters']['wplc_offer_planned_entitlements'] );

		// A planned product: its key resolves, nothing is offered, the editor does not suggest it.
		CatalogStore::import_json( $this->json( $this->acme( array( 'status' => 'planned' ) ) ), CatalogStore::SOURCE_CLI );
		$this->assertSame( array( 'acme' => array() ), EntitlementCatalog::all() );
		$this->assertSame( array( 'acme.pdf_export', 'acme.signatures' ), array_column( EntitlementCatalog::planned()['acme'], 'key' ) );
		$this->assertSame( array(), EntitlementCatalog::known_product_keys() );
		$this->assertSame( array( 'acme-forms-pro' => 'acme' ), EntitlementCatalog::planned_product_keys() );
		$this->assertSame( array( 'acme' ), EntitlementCatalog::groups_for_product_key( 'acme-forms-pro' ) );
	}

	public function test_a_key_that_leaves_the_catalog_stays_on_a_stored_plan(): void {
		CatalogStore::import_json( $this->json( $this->acme() ), CatalogStore::SOURCE_CLI );
		$stored = array( 'acme.pdf_export' => true, 'sites.live_limit' => 5 );
		$this->assertSame( array( 'acme.pdf_export' ), EntitlementCatalog::split( $stored )['checked'] );

		$next = $this->acme();
		array_shift( $next['entitlements'] );
		CatalogStore::import_json( $this->json( $next ), CatalogStore::SOURCE_CLI );
		$split = EntitlementCatalog::split( $stored );
		$this->assertSame( array(), $split['checked'] );
		$this->assertSame( $stored, EntitlementCatalog::merge( $split['checked'], $split['advanced'] ), 'Saving the product editor again stores the same map.' );
	}

	public function test_labels_follow_the_locale_with_english_as_fallback(): void {
		$label = array( 'en' => 'PDF export', 'de_DE' => 'PDF-Export (du)', 'de_DE_formal' => 'PDF-Export (Sie)' );
		$this->assertSame( 'PDF-Export (du)', CatalogStore::text( $label, 'de_DE' ) );
		$this->assertSame( 'PDF-Export (Sie)', CatalogStore::text( $label, 'de_DE_formal' ) );
		// Variants without their own text use the shipped fallback of the plugin (Plugin::fallback_locale()).
		$this->assertSame( 'PDF-Export (Sie)', CatalogStore::text( $label, 'de_AT' ) );
		$this->assertSame( 'PDF-Export (du)', CatalogStore::text( $label, 'de_CH_informal' ) );
		$this->assertSame( 'PDF export', CatalogStore::text( $label, 'fr_FR' ) );
		$this->assertSame( 'PDF export', CatalogStore::text( $label, 'en_US' ) );
		$this->assertSame( 'PDF export', CatalogStore::text( $label ), 'Without WordPress the locale is en_US.' );
		$this->assertSame( 'Nur Englisch', CatalogStore::text( array( 'en' => 'Nur Englisch' ), 'de_DE' ) );
		$this->assertSame( '', CatalogStore::text( array(), 'de_DE' ) );
	}

	public function test_the_shipped_catalog_files_are_valid_and_imported_once(): void {
		$files = (array) glob( dirname( __DIR__, 2 ) . '/catalogs/*.json' );
		$this->assertCount( 7, $files, 'The seven Captain products, the first tenant of the catalog.' );
		foreach ( $files as $file ) {
			$result = CatalogFile::parse( (string) file_get_contents( $file ) );
			$this->assertTrue( $result['ok'], basename( $file ) . ': ' . json_encode( $result['errors'] ) );
			$this->assertSame( basename( $file, '.json' ), $result['catalog']['product_key'], 'The file is named after its product key.' );
			foreach ( $result['catalog']['entitlements'] as $entitlement ) {
				$this->assertSame( array( 'en', 'de_DE', 'de_DE_formal' ), array_keys( $entitlement['label'] ), $entitlement['key'] . ' has the German label in both forms.' );
			}
		}

		$report = CatalogStore::import_bundled();
		$this->assertSame( array(), $report['failed'] );
		$this->assertCount( 7, $report['imported'] );
		$this->assertSame( array(), $report['skipped'] );
		$again = CatalogStore::import_bundled();
		$this->assertSame( array( array(), 7 ), array( $again['imported'], count( $again['skipped'] ) ), 'The migration step can run again.' );
		$this->assertSame( CatalogStore::SOURCE_BUNDLED, CatalogStore::get( 'pass-captain-pro' )['source'] );

		// The vendor's own file wins: a product imported by hand is not replaced by the shipped file.
		$own                 = CatalogFile::parse( (string) file_get_contents( dirname( __DIR__, 2 ) . '/catalogs/pass-captain-pro.json' ) )['catalog'];
		$own['entitlements'] = array_slice( $own['entitlements'], 0, 2 );
		CatalogStore::import( $own, CatalogStore::SOURCE_ADMIN, 1 );
		$report = CatalogStore::import_bundled();
		$this->assertContains( 'pass-captain-pro', $report['skipped'] );
		$this->assertCount( 2, CatalogStore::get( 'pass-captain-pro' )['entitlements'] );
	}

	public function test_the_json_schema_describes_the_format_the_validator_reads(): void {
		$schema = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/docs/catalog-file.schema.json' ), true );
		$this->assertIsArray( $schema );
		$this->assertSame( array( 'format', 'product_key', 'name', 'entitlements' ), $schema['required'] );
		$this->assertSame( CatalogFile::FORMAT, $schema['properties']['format']['const'] );
		$this->assertSame( trim( CatalogFile::PRODUCT_KEY_PATTERN, '/D' ), $schema['properties']['product_key']['pattern'] );
		$this->assertSame( trim( CatalogFile::PREFIX_PATTERN, '/D' ), $schema['properties']['prefix']['pattern'] );
		$this->assertSame( array( CatalogFile::STATUS_AVAILABLE, CatalogFile::STATUS_PLANNED ), $schema['properties']['status']['enum'] );
		$this->assertSame( CatalogFile::MAX_ENTITLEMENTS, $schema['properties']['entitlements']['maxItems'] );
		$this->assertSame( array( 'key', 'label' ), $schema['$defs']['entitlement']['required'] );
		$this->assertSame( CatalogFile::MAX_LABEL_LENGTH, $schema['$defs']['label']['oneOf'][0]['maxLength'] );
		$this->assertSame( CatalogFile::MAX_DESCRIPTION_LENGTH, $schema['$defs']['description']['oneOf'][0]['maxLength'] );
		$this->assertSame( array( 'en' ), $schema['$defs']['label']['oneOf'][1]['required'] );

		// The example of the documentation is a valid file.
		$markdown = (string) file_get_contents( dirname( __DIR__, 2 ) . '/docs/CATALOG-FILE.md' );
		$this->assertSame( 1, preg_match( '/```json\n(\{.*?\n\})\n```/s', $markdown, $match ) );
		$example = CatalogFile::parse( $match[1] );
		$this->assertTrue( $example['ok'], (string) json_encode( $example['errors'] ) );
	}
}
