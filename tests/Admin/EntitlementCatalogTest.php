<?php
/**
 * Entitlement catalog and the product editor's merge/split of checklist and JSON.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\Catalog\EntitlementCatalog;

require_once __DIR__ . '/wp-function-stubs.php';

final class EntitlementCatalogTest extends TestCase {

	public function test_catalog_matches_the_documented_keys(): void {
		$catalog = EntitlementCatalog::all();
		$this->assertSame( array( 'affiliate', 'voucher', 'pass' ), array_keys( $catalog ) );

		$documented = array();
		$markdown   = file_get_contents( dirname( __DIR__, 2 ) . '/docs/ENTITLEMENTS.md' );
		$table      = substr( $markdown, strpos( $markdown, '## Proposed plan mapping' ), strpos( $markdown, '## Contract examples' ) - strpos( $markdown, '## Proposed plan mapping' ) );
		preg_match_all( '/`((?:affiliate|voucher|pass)\.[a-z_]+)`/', $table, $matches );
		$documented = array_values( array_unique( $matches[1] ) );
		sort( $documented );

		$known = EntitlementCatalog::known_keys();
		sort( $known );

		$this->assertSame( $documented, $known );
	}

	public function test_every_entry_is_prefixed_by_its_group_and_labelled(): void {
		foreach ( EntitlementCatalog::all() as $group => $entitlements ) {
			$this->assertNotEmpty( $entitlements );
			foreach ( $entitlements as $entitlement ) {
				$this->assertStringStartsWith( $group . '.', $entitlement['key'] );
				$this->assertMatchesRegularExpression( EntitlementCatalog::KEY_PATTERN, $entitlement['key'] );
				$this->assertNotSame( '', $entitlement['label'] );
				$this->assertNotSame( $entitlement['key'], $entitlement['label'] );
			}
		}
	}

	public function test_groups_are_found_from_the_license_product_key(): void {
		$this->assertSame( array( 'voucher' ), EntitlementCatalog::groups_for_product_key( 'voucher-captain' ) );
		$this->assertSame( array( 'affiliate' ), EntitlementCatalog::groups_for_product_key( 'Affiliate-Captain' ) );
		$this->assertSame( array( 'pass' ), EntitlementCatalog::groups_for_product_key( 'wp-pass-captain' ) );
		$this->assertSame( array(), EntitlementCatalog::groups_for_product_key( 'something-else' ) );
		$this->assertSame( array(), EntitlementCatalog::groups_for_product_key( '' ) );
	}

	public function test_split_ticks_known_true_keys_and_keeps_the_rest_as_json(): void {
		$split = EntitlementCatalog::split(
			array(
				'voucher.qr_redemption'   => true,
				'voucher.reports'         => false,
				'voucher.staff_roles'     => 1,
				'voucher.location_limit'  => 3,
				'voucher.multi_location'  => true,
				'affiliate.program_limit' => 3,
				'pass.pos'                => true,
			)
		);

		$this->assertSame( array( 'voucher.qr_redemption', 'pass.pos' ), $split['checked'] );
		$this->assertSame(
			array(
				'voucher.reports'         => false,
				'voucher.staff_roles'     => 1,
				'voucher.location_limit'  => 3,
				'voucher.multi_location'  => true,
				'affiliate.program_limit' => 3,
			),
			$split['advanced']
		);
	}

	public function test_merge_combines_checklist_and_advanced_json(): void {
		$merged = EntitlementCatalog::merge(
			array( 'voucher.qr_redemption', 'voucher.reports' ),
			array(
				'voucher.location_limit' => 3,
				'voucher.reports'        => false,
			)
		);

		$this->assertSame(
			array(
				'voucher.location_limit' => 3,
				'voucher.reports'        => true,
				'voucher.qr_redemption'  => true,
			),
			$merged
		);
	}

	public function test_merge_ignores_unknown_ticked_keys_and_invalid_json_keys(): void {
		$merged = EntitlementCatalog::merge(
			array( 'voucher.not_in_catalog', 'sites.live_limit', array( 'nested' ), 7 ),
			array(
				'bad key!'      => true,
				'custom.flag'   => true,
				0               => 'list entry',
			)
		);

		$this->assertSame( array( 'custom.flag' => true ), $merged );
		$this->assertSame( array(), EntitlementCatalog::merge( 'not-an-array', array() ) );
	}

	public function test_saved_mapping_survives_a_load_and_save_round_trip(): void {
		$saved = array(
			'affiliate.partner_portal'     => true,
			'affiliate.basic_commissions'  => true,
			'affiliate.program_limit'      => 3,
			'affiliate.webhooks'           => false,
			'legacy.custom'                => 'gold',
		);

		$split  = EntitlementCatalog::split( $saved );
		$merged = EntitlementCatalog::merge( $split['checked'], $split['advanced'] );

		ksort( $saved );
		ksort( $merged );
		$this->assertSame( $saved, $merged );
	}

	public function test_unticking_a_box_removes_the_entitlement(): void {
		$split   = EntitlementCatalog::split( array( 'pass.pos' => true, 'pass.seating' => true ) );
		$checked = array_values( array_diff( $split['checked'], array( 'pass.pos' ) ) );

		$this->assertSame( array( 'pass.seating' => true ), EntitlementCatalog::merge( $checked, $split['advanced'] ) );
	}
}
