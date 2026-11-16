<?php
/**
 * T-132: renewal price of licenses for variations of a parent-mapped variable product.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\Billing\RenewalOrder;
use WPLC\Catalog\Catalog;
use WPLC\License\LicenseIssuer;

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
}

final class RenewalPriceTestProduct {
	private $type;
	private $regular;
	private $price;

	public function __construct( string $type, string $regular, string $price ) {
		$this->type    = $type;
		$this->regular = $regular;
		$this->price   = $price;
	}

	public function is_type( $types ) { return in_array( $this->type, (array) $types, true ); }
	public function get_regular_price( $context = 'view' ) { return $this->regular; }
	public function get_price( $context = 'view' ) { return $this->price; }
}

final class RenewalPriceTest extends TestCase {

	/** Parent-level plan: the catalog stored the lowest variation price, 59.00. */
	private function parent_plan(): array {
		return array(
			'price_minor'    => 5900,
			'price_decimals' => 2,
			'currency'       => 'EUR',
		);
	}

	private function license( array $overrides = array() ): array {
		return array_merge(
			array(
				'renewal_amount_minor'      => 7900,
				'renewal_currency_decimals' => 2,
				'renewal_currency'          => 'EUR',
				'renewal_product_id'        => 42,
			),
			$overrides
		);
	}

	/** Minor units in the shop precision (another test may stub wc_get_price_decimals()). */
	private function shop_minor( string $amount ): int {
		$decimals = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;
		return (int) \WPLC\Billing\Money::to_minor_units( $amount, $decimals );
	}

	private function eur( int $minor ): array {
		return array( 'amount_minor' => $minor, 'decimals' => 2, 'currency' => 'EUR' );
	}

	public function test_issued_license_stores_the_purchased_variation_price_not_the_plan_price(): void {
		$this->assertSame( $this->eur( 7900 ), LicenseIssuer::renewal_price( $this->parent_plan(), $this->eur( 7900 ) ) );
	}

	public function test_issued_license_falls_back_to_the_plan_price_without_a_product_price(): void {
		$this->assertSame( $this->eur( 5900 ), LicenseIssuer::renewal_price( $this->parent_plan(), null ) );
		$this->assertSame( $this->eur( 5900 ), LicenseIssuer::renewal_price( $this->parent_plan(), $this->eur( 0 ) ) );
	}

	public function test_automatic_renewal_charges_the_stored_amount(): void {
		// Neither the plan price nor a later price change of the variation changes a consented off-session charge.
		$this->assertSame( $this->eur( 7900 ), RenewalOrder::choose_price( $this->license(), $this->parent_plan(), $this->eur( 8900 ), false ) );
		$this->assertSame( $this->eur( 7900 ), RenewalOrder::choose_price( $this->license(), $this->parent_plan(), null, false ) );
	}

	/**
	 * INH-01 (owner decision A4): the manual renewal bills the amount agreed at purchase, like the automatic one.
	 */
	public function test_inh01_manual_renewal_keeps_the_price_of_the_purchase_day(): void {
		// The shop raised and lowered the price of the variation since the purchase: neither reaches the license.
		$this->assertSame( $this->eur( 7900 ), RenewalOrder::choose_price( $this->license(), $this->parent_plan(), $this->eur( 8900 ), true ) );
		$this->assertSame( $this->eur( 7900 ), RenewalOrder::choose_price( $this->license(), $this->parent_plan(), $this->eur( 6900 ), true ) );
		$this->assertSame( $this->eur( 7900 ), RenewalOrder::choose_price( $this->license(), $this->parent_plan(), null, true ) );
		// Manual and automatic renewals of one license cost the same.
		$this->assertSame(
			RenewalOrder::choose_price( $this->license(), $this->parent_plan(), $this->eur( 8900 ), false ),
			RenewalOrder::choose_price( $this->license(), $this->parent_plan(), $this->eur( 8900 ), true )
		);
	}

	public function test_inh01_legacy_license_with_a_stored_amount_keeps_it(): void {
		$legacy = $this->license( array( 'renewal_product_id' => 0, 'renewal_amount_minor' => 5900 ) );
		$plan   = array( 'price_minor' => 6900, 'price_decimals' => 2, 'currency' => 'EUR' );

		$this->assertSame( $this->eur( 5900 ), RenewalOrder::choose_price( $legacy, $plan, $this->eur( 7900 ), true ) );
		$this->assertSame( $this->eur( 5900 ), RenewalOrder::choose_price( $legacy, $plan, null, true ) );
	}

	public function test_inh01_manual_renewal_without_a_stored_amount_is_priced_from_the_catalog(): void {
		$unpriced = $this->license( array( 'renewal_amount_minor' => 0 ) );
		$plan     = array( 'price_minor' => 6900, 'price_decimals' => 2, 'currency' => 'EUR' );

		// The purchased product still has a price: that price.
		$this->assertSame( $this->eur( 8900 ), RenewalOrder::choose_price( $unpriced, $plan, $this->eur( 8900 ), true ) );
		// A recorded product that is gone: no plan price (for parent-level mappings it is the lowest variation price).
		$this->assertSame( $this->eur( 0 ), RenewalOrder::choose_price( $unpriced, $plan, null, true ) );
		// A license that never recorded its product: the plan price, else nothing.
		$legacy = $this->license( array( 'renewal_amount_minor' => 0, 'renewal_product_id' => 0 ) );
		$this->assertSame( $this->eur( 6900 ), RenewalOrder::choose_price( $legacy, $plan, null, true ) );
		$this->assertSame( $this->eur( 0 ), RenewalOrder::choose_price( $legacy, null, null, true ) );
		// An automatic renewal never takes a catalog price.
		$this->assertSame( $this->eur( 0 ), RenewalOrder::choose_price( $unpriced, $plan, $this->eur( 8900 ), false ) );
	}

	public function test_product_regular_price_ignores_a_running_sale(): void {
		$price = Catalog::product_regular_price( new RenewalPriceTestProduct( 'variation', '79.00', '49.00' ) );
		$this->assertNotNull( $price );
		$this->assertSame( $this->shop_minor( '79.00' ), $price['amount_minor'] );
	}

	public function test_product_regular_price_falls_back_to_the_price_without_a_regular_price(): void {
		$price = Catalog::product_regular_price( new RenewalPriceTestProduct( 'simple', '', '49.00' ) );
		$this->assertNotNull( $price );
		$this->assertSame( $this->shop_minor( '49.00' ), $price['amount_minor'] );
	}

	public function test_variable_parent_and_unpriced_products_have_no_renewal_price(): void {
		// A variable parent reports its lowest variation price; that is exactly the T-132 bug.
		$this->assertNull( Catalog::product_regular_price( new RenewalPriceTestProduct( 'variable', '', '59.00' ) ) );
		$this->assertNull( Catalog::product_regular_price( new RenewalPriceTestProduct( 'simple', '', '' ) ) );
		$this->assertNull( Catalog::product_regular_price( new RenewalPriceTestProduct( 'simple', '0', '0' ) ) );
		$this->assertNull( Catalog::product_regular_price( false ) );
	}
}
