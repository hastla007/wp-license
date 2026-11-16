<?php
/**
 * Entitlement catalog and the product editor's merge/split of checklist and JSON.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\Catalog\CatalogStore;
use WPLC\Catalog\EntitlementCatalog;

require_once __DIR__ . '/wp-function-stubs.php';

final class EntitlementCatalogTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		// The catalog is data: the files under catalogs/ are what schema step 24 imports.
		$report = CatalogStore::import_bundled();
		$this->assertSame( array(), $report['failed'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wplc_test_filters']['wplc_offer_planned_entitlements'], $GLOBALS['wplc_test_filters']['wplc_known_product_keys'] );
		parent::tearDown();
	}

	/**
	 * Keys named in one section of docs/ENTITLEMENTS.md.
	 *
	 * @param string $from Heading the section starts with.
	 * @param string $to   Heading of the next section.
	 * @return string[]
	 */
	private function documented_keys( string $from, string $to ): array {
		$markdown = (string) file_get_contents( dirname( __DIR__, 2 ) . '/docs/ENTITLEMENTS.md' );
		$start    = strpos( $markdown, $from );
		$end      = strpos( $markdown, $to );
		$this->assertNotFalse( $start, $from );
		$this->assertNotFalse( $end, $to );
		preg_match_all( '/`((?:affiliate|voucher|pass|workflow|form|review|course)\.[a-z_]+)`/', substr( $markdown, (int) $start, (int) $end - (int) $start ), $matches );
		$documented = array_values( array_unique( $matches[1] ) );
		sort( $documented );

		return $documented;
	}

	public function test_catalog_matches_the_documented_keys(): void {
		$catalog = EntitlementCatalog::all();
		$this->assertSame( array( 'affiliate', 'course', 'form', 'pass', 'review', 'voucher', 'workflow' ), array_keys( $catalog ) );

		$known = EntitlementCatalog::known_keys();
		sort( $known );

		$this->assertSame( $this->documented_keys( '## Plans and entitlements', '## Planned, not deliverable yet' ), $known );
	}

	public function test_planned_keys_are_documented_and_not_offered_by_default(): void {
		$planned = array();
		foreach ( EntitlementCatalog::planned() as $group => $entitlements ) {
			foreach ( $entitlements as $entitlement ) {
				$this->assertStringStartsWith( $group . '.', $entitlement['key'] );
				$planned[] = $entitlement['key'];
			}
		}
		sort( $planned );
		$this->assertSame(
			array( 'affiliate.leaderboard', 'affiliate.multi_tier', 'affiliate.multiple_programs', 'affiliate.store_credit', 'affiliate.stripe_connect', 'voucher.white_label', 'workflow.advanced_logic', 'workflow.event_correlation', 'workflow.extended_logs', 'workflow.recipe_templates', 'workflow.suite_integrations', 'workflow.webhooks' ),
			$planned
		);
		$this->assertSame( $this->documented_keys( '## Planned, not deliverable yet', '## Contract examples' ), $planned );

		// Not offered as tick boxes: a ticked planned key is ignored, a stored one stays in the JSON field.
		$this->assertSame( array(), array_intersect( $planned, EntitlementCatalog::known_keys() ) );
		$this->assertSame( array(), EntitlementCatalog::merge( $planned, array() ) );
		$split = EntitlementCatalog::split( array( 'affiliate.store_credit' => true ) );
		$this->assertSame( array(), $split['checked'] );
		$this->assertSame( array( 'affiliate.store_credit' => true ), $split['advanced'] );

		// A shop that sells a planned function early opts in with the filter.
		$GLOBALS['wplc_test_filters']['wplc_offer_planned_entitlements'][] = static function () {
			return true;
		};
		$this->assertSame( $planned, array_values( array_intersect( $planned, EntitlementCatalog::known_keys() ) ) );
		$this->assertSame( array( 'voucher.white_label' => true ), EntitlementCatalog::merge( array( 'voucher.white_label' ), array() ) );
	}

	/**
	 * R-28: an entitlement key named as an example in the client library exists in the catalog.
	 */
	public function test_r28_examples_in_the_client_library_name_catalogued_keys(): void {
		$known = EntitlementCatalog::known_keys();
		$found = array();
		foreach ( (array) glob( dirname( __DIR__, 2 ) . '/src/Client/*.php' ) as $file ) {
			preg_match_all( '/`((?:affiliate|voucher|pass|workflow|form|review)\.[a-z0-9_]+)`/', (string) file_get_contents( $file ), $matches );
			foreach ( $matches[1] as $key ) {
				$found[] = $key;
				$this->assertContains( $key, $known, basename( $file ) . ' names ' . $key );
			}
		}
		$this->assertContains( 'pass.waiting_room', $found, 'The scan finds the examples.' );
		$this->assertNotContains( 'voucher.qr_redemption', $found );
	}

	/**
	 * R-07, R-08, R-24: keys that named functions of the free core plugins, and the three support keys, are gone from the catalog.
	 *
	 * @return string[]
	 */
	private function removed_core_keys(): array {
		return array(
			'affiliate.partner_portal',
			'affiliate.coupon_attribution',
			'affiliate.order_refund_handling',
			'affiliate.basic_commissions',
			'affiliate.manual_payout_csv',
			'affiliate.partner_groups',
			'affiliate.product_category_rates',
			'affiliate.recurring_commissions',
			'voucher.custom_branding',
			'voucher.pdf_templates',
			'voucher.automatic_delivery',
			'voucher.qr_redemption',
			'voucher.partial_balance',
			'voucher.staff_roles',
			'voucher.scheduled_delivery',
			'voucher.bulk_import_export',
			'voucher.reports',
			// R-24 (owner decision G1): support is the plan attribute support.level only.
			'affiliate.priority_support',
			'voucher.priority_support',
			'pass.priority_support',
		);
	}

	public function test_core_functions_are_not_offered_as_entitlements(): void {
		$this->assertSame( array(), array_values( array_intersect( $this->removed_core_keys(), EntitlementCatalog::known_keys() ) ) );
		// Ticking one of them (a stale form, a forged request) grants nothing.
		$this->assertSame( array(), EntitlementCatalog::merge( $this->removed_core_keys(), array() ) );
	}

	public function test_a_stored_plan_with_removed_keys_keeps_them_through_load_and_save(): void {
		$stored = array_fill_keys( $this->removed_core_keys(), true ) + array(
			'affiliate.webhooks' => true,
			'sites.live_limit'   => 5,
		);

		$split = EntitlementCatalog::split( $stored );
		$this->assertSame( array( 'affiliate.webhooks' ), $split['checked'] );
		foreach ( $this->removed_core_keys() as $key ) {
			$this->assertTrue( $split['advanced'][ $key ], $key . ' stays in the advanced JSON field' );
		}

		// Saving the product editor again without touching anything stores the same map.
		$merged = EntitlementCatalog::merge( $split['checked'], $split['advanced'] );
		ksort( $stored );
		ksort( $merged );
		$this->assertSame( $stored, $merged );
	}

	public function test_every_entry_is_prefixed_by_its_group_and_labelled(): void {
		foreach ( EntitlementCatalog::all() as $group => $entitlements ) {
			if ( isset( array_flip( EntitlementCatalog::planned_product_keys() )[ $group ] ) ) {
				$this->assertSame( array(), $entitlements, 'No entitlement is offered for a planned product.' );
				continue;
			}
			if ( 'voucher' === $group ) {
				$this->assertSame( array(), $entitlements, 'Voucher Captain Pro has no built premium function; the support key is gone (R-24).' );
				continue;
			}
			$this->assertNotEmpty( $entitlements );
			foreach ( $entitlements as $entitlement ) {
				$this->assertStringStartsWith( $group . '.', $entitlement['key'] );
				$this->assertMatchesRegularExpression( EntitlementCatalog::KEY_PATTERN, $entitlement['key'] );
				$this->assertNotSame( '', $entitlement['label'] );
				$this->assertNotSame( $entitlement['key'], $entitlement['label'] );
			}
		}
	}

	public function test_planned_product_keys_resolve_to_their_group_without_being_suggested_or_sold(): void {
		// FR-LIC-06: Course Captain is the fourth planned product.
		$this->assertSame(
			array( 'course-captain-pro' => 'course', 'form-captain-pro' => 'form', 'review-captain-pro' => 'review', 'workflow-captain-pro' => 'workflow' ),
			EntitlementCatalog::planned_product_keys()
		);
		foreach ( EntitlementCatalog::planned_product_keys() as $product_key => $group ) {
			$this->assertTrue( \WPLC\Catalog\Catalog::is_valid_product_key( $product_key ), $product_key );
			$this->assertSame( array( $group ), EntitlementCatalog::groups_for_product_key( $product_key ) );
			$this->assertSame( array(), EntitlementCatalog::all()[ $group ] );
			$this->assertArrayNotHasKey( $product_key, EntitlementCatalog::known_product_keys(), 'Not suggested in the product editor.' );
			$this->assertNotSame( ucfirst( $group ), EntitlementCatalog::group_label( $group ) );
			$this->assertStringNotContainsString( 'WP ', EntitlementCatalog::group_label( $group ) );
		}
		$this->assertSame( 'Pass Captain Pro', EntitlementCatalog::group_label( 'pass' ) );
		$this->assertSame( 'Unknown', EntitlementCatalog::group_label( 'unknown' ), 'A group without a product in the catalog shows its prefix.' );
		// An empty group never matches by substring: "platform" contains "form", "preview" contains "review".
		$this->assertSame( array(), EntitlementCatalog::groups_for_product_key( 'my-platform' ) );
		$this->assertSame( array(), EntitlementCatalog::groups_for_product_key( 'preview-tool' ) );
		$this->assertSame( array( 'pass' ), EntitlementCatalog::groups_for_product_key( 'pass-platform' ) );
	}

	public function test_groups_are_found_from_the_license_product_key(): void {
		// Voucher Captain Pro has no key yet (R-24 removed its only one), so only its exact product key resolves.
		$this->assertSame( array(), EntitlementCatalog::groups_for_product_key( 'voucher-captain' ) );
		$this->assertSame( array( 'affiliate' ), EntitlementCatalog::groups_for_product_key( 'Affiliate-Captain' ) );
		$this->assertSame( array( 'pass' ), EntitlementCatalog::groups_for_product_key( 'wp-pass-captain' ) );
		$this->assertSame( array(), EntitlementCatalog::groups_for_product_key( 'something-else' ) );
		// The three add-on product keys (decision of 14 Nov 2026) each select exactly their feature group.
		$this->assertSame( array( 'voucher' ), EntitlementCatalog::groups_for_product_key( 'voucher-captain-pro' ) );
		$this->assertSame( array( 'pass' ), EntitlementCatalog::groups_for_product_key( 'pass-captain-pro' ) );
		$this->assertSame( array( 'affiliate' ), EntitlementCatalog::groups_for_product_key( 'affiliate-captain-pro' ) );
		foreach ( array( 'voucher-captain-pro', 'pass-captain-pro', 'affiliate-captain-pro' ) as $addon_key ) {
			$this->assertTrue( \WPLC\Catalog\Catalog::is_valid_product_key( $addon_key ), $addon_key );
		}
		$this->assertSame( array(), EntitlementCatalog::groups_for_product_key( '' ) );
	}

	public function test_the_decided_product_keys_map_to_their_entitlement_prefix(): void {
		$this->assertSame(
			array( 'affiliate-captain-pro' => 'affiliate', 'pass-captain-pro' => 'pass', 'voucher-captain-pro' => 'voucher' ),
			EntitlementCatalog::known_product_keys()
		);
		foreach ( EntitlementCatalog::known_product_keys() as $product_key => $group ) {
			$this->assertSame( array( $group ), EntitlementCatalog::groups_for_product_key( $product_key ) );
			$this->assertArrayHasKey( $group, EntitlementCatalog::all() );
		}
	}

	public function test_split_ticks_known_true_keys_and_keeps_the_rest_as_json(): void {
		$split = EntitlementCatalog::split(
			array(
				'affiliate.webhooks'      => true,
				'pass.write_api'          => false,
				'pass.waiting_room'       => 1,
				'voucher.location_limit'  => 3,
				'voucher.multi_location'  => true,
				'affiliate.program_limit' => 3,
				'pass.card_terminal'      => true,
			)
		);

		$this->assertSame( array( 'affiliate.webhooks', 'pass.card_terminal' ), $split['checked'] );
		$this->assertSame(
			array(
				'pass.write_api'          => false,
				'pass.waiting_room'       => 1,
				'voucher.location_limit'  => 3,
				'voucher.multi_location'  => true,
				'affiliate.program_limit' => 3,
			),
			$split['advanced']
		);
	}

	public function test_merge_combines_checklist_and_advanced_json(): void {
		$merged = EntitlementCatalog::merge(
			array( 'pass.card_terminal', 'pass.write_api' ),
			array(
				'voucher.location_limit' => 3,
				'pass.write_api'         => false,
			)
		);

		$this->assertSame(
			array(
				'voucher.location_limit' => 3,
				'pass.write_api'         => true,
				'pass.card_terminal'     => true,
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
			'affiliate.extended_api'       => true,
			'affiliate.automated_payouts'  => true,
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
		$split   = EntitlementCatalog::split( array( 'pass.card_terminal' => true, 'pass.seating' => true ) );
		$checked = array_values( array_diff( $split['checked'], array( 'pass.card_terminal' ) ) );

		$this->assertSame( array( 'pass.seating' => true ), EntitlementCatalog::merge( $checked, $split['advanced'] ) );
	}
}
