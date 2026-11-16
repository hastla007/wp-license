<?php
/**
 * INH-05: when an announced renewal price applies. Pure rules.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\Billing\PriceChange;
use WPLC\Billing\RenewalOrder;

require_once dirname( __DIR__ ) . '/Admin/wp-function-stubs.php';

final class PriceChangeTest extends TestCase {

	private const NOW = 1800000000;

	private function utc( int $time ): string {
		return gmdate( 'Y-m-d H:i:s', $time );
	}

	/** A license at 49.00 with 59.00 announced. */
	private function license( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'                        => 7,
				'status'                    => 'active',
				'renewal_amount_minor'      => 4900,
				'renewal_currency_decimals' => 2,
				'renewal_currency'          => 'EUR',
				'renewal_product_id'        => 42,
				'price_change_amount_minor' => 5900,
				'price_change_effective_at' => $this->utc( self::NOW + 60 * 86400 ),
				'price_change_announced_at' => $this->utc( self::NOW ),
				'price_change_notified_at'  => $this->utc( self::NOW + 10 ),
				'price_change_notice_days'  => 60,
			),
			$overrides
		);
	}

	public function test_a_license_without_an_announcement_has_none(): void {
		$plain = $this->license( array( 'price_change_amount_minor' => null, 'price_change_effective_at' => null ) );
		$this->assertNull( PriceChange::pending( $plain ) );
		$this->assertFalse( PriceChange::applies( $plain, self::NOW + 9999 * 86400 ) );
		$this->assertSame( $plain, PriceChange::license_at( $plain, self::NOW + 9999 * 86400 ) );
		$this->assertNull( PriceChange::pending( array() ) );
	}

	public function test_the_new_amount_applies_from_the_announced_date_not_before(): void {
		$license = $this->license();
		$pending = PriceChange::pending( $license );
		$this->assertSame( 5900, $pending['amount_minor'] );
		$this->assertSame( self::NOW + 60 * 86400 + 10, $pending['applies_at'], 'The notice went out ten seconds after the announcement: the period counts from the notice.' );

		$this->assertFalse( PriceChange::applies( $license, self::NOW ) );
		$this->assertFalse( PriceChange::applies( $license, self::NOW + 60 * 86400 ), 'The announced date alone is not enough when the notice is younger than the period.' );
		$this->assertFalse( PriceChange::applies( $license, $pending['applies_at'] - 1 ) );
		$this->assertTrue( PriceChange::applies( $license, $pending['applies_at'] ) );

		$this->assertSame( 4900, PriceChange::license_at( $license, $pending['applies_at'] - 1 )['renewal_amount_minor'] );
		$this->assertSame( 5900, PriceChange::license_at( $license, $pending['applies_at'] )['renewal_amount_minor'] );
		// Automatic and manual renewals are priced from the same row: the old amount before, the new one after.
		foreach ( array( true, false ) as $manual ) {
			$this->assertSame( 4900, RenewalOrder::choose_price( PriceChange::license_at( $license, self::NOW ), null, null, $manual )['amount_minor'] );
			$this->assertSame( 5900, RenewalOrder::choose_price( PriceChange::license_at( $license, $pending['applies_at'] ), null, null, $manual )['amount_minor'] );
		}
	}

	public function test_without_a_recorded_notice_the_new_amount_never_applies(): void {
		$license = $this->license( array( 'price_change_notified_at' => null ) );
		$this->assertSame( 0, PriceChange::pending( $license )['applies_at'] );
		$this->assertFalse( PriceChange::applies( $license, self::NOW + 5000 * 86400 ) );
		$this->assertSame( 4900, PriceChange::license_at( $license, self::NOW + 5000 * 86400 )['renewal_amount_minor'] );
	}

	public function test_a_late_notice_moves_the_date_by_the_full_period(): void {
		// Announced for day 60, but the mailer only accepted the notice on day 50.
		$license = $this->license( array( 'price_change_notified_at' => $this->utc( self::NOW + 50 * 86400 ) ) );
		$this->assertFalse( PriceChange::applies( $license, self::NOW + 60 * 86400 ) );
		$this->assertFalse( PriceChange::applies( $license, self::NOW + 109 * 86400 ) );
		$this->assertTrue( PriceChange::applies( $license, self::NOW + 110 * 86400 ) );
		// A longer announced date wins over the period.
		$far = $this->license( array( 'price_change_effective_at' => $this->utc( self::NOW + 200 * 86400 ) ) );
		$this->assertFalse( PriceChange::applies( $far, self::NOW + 199 * 86400 ) );
		$this->assertTrue( PriceChange::applies( $far, self::NOW + 200 * 86400 ) );
	}

	public function test_the_period_stored_with_the_announcement_counts_and_is_never_shorter_than_the_floor(): void {
		$this->assertSame( 90, PriceChange::pending( $this->license( array( 'price_change_notice_days' => 90 ) ) )['notice_days'] );
		// A manipulated or missing value never shortens the period.
		$this->assertSame( 30, PriceChange::pending( $this->license( array( 'price_change_notice_days' => 3 ) ) )['notice_days'] );
		$this->assertSame( 365, PriceChange::pending( $this->license( array( 'price_change_notice_days' => null ) ) )['notice_days'] );
		$this->assertSame( 30, PriceChange::clamp_notice_days( 0 ) );
		$this->assertSame( 30, PriceChange::clamp_notice_days( 29 ) );
		$this->assertSame( 60, PriceChange::clamp_notice_days( 60 ) );
		$this->assertSame( 365, PriceChange::clamp_notice_days( 5000 ) );
		$this->assertSame( 60, PriceChange::DEFAULT_NOTICE_DAYS );
		$this->assertSame( 30, PriceChange::MIN_NOTICE_DAYS );
	}

	public function test_what_can_be_announced(): void {
		$plain = $this->license( array( 'price_change_amount_minor' => null, 'price_change_effective_at' => null ) );
		$in_60 = self::NOW + 60 * 86400;

		$this->assertSame( PriceChange::RESULT_OK, PriceChange::check( $plain, 5900, $in_60, self::NOW, 60 ) );
		$this->assertSame( PriceChange::RESULT_NOTICE_TOO_SHORT, PriceChange::check( $plain, 5900, $in_60 - 1, self::NOW, 60 ) );
		$this->assertSame( PriceChange::RESULT_NOTICE_TOO_SHORT, PriceChange::check( $plain, 5900, self::NOW + 29 * 86400, self::NOW, 1 ), 'A period below the floor is raised to 30 days.' );
		// Lowering and "no change" need no announcement: the support action does that at once.
		$this->assertSame( PriceChange::RESULT_NOT_AN_INCREASE, PriceChange::check( $plain, 4900, $in_60, self::NOW, 60 ) );
		$this->assertSame( PriceChange::RESULT_NOT_AN_INCREASE, PriceChange::check( $plain, 3900, $in_60, self::NOW, 60 ) );
		$this->assertSame( PriceChange::RESULT_ALREADY_ANNOUNCED, PriceChange::check( $this->license(), 6900, $in_60, self::NOW, 60 ) );
		foreach ( array( 'suspended', 'expired', 'revoked', 'pending' ) as $status ) {
			$this->assertSame( PriceChange::RESULT_FAILED, PriceChange::check( array_merge( $plain, array( 'status' => $status ) ), 5900, $in_60, self::NOW, 60 ), $status );
		}
		$this->assertSame( PriceChange::RESULT_OK, PriceChange::check( array_merge( $plain, array( 'status' => 'past_due' ) ), 5900, $in_60, self::NOW, 60 ) );
		$this->assertSame( PriceChange::RESULT_FAILED, PriceChange::check( $plain, 0, $in_60, self::NOW, 60 ) );
		$this->assertSame( PriceChange::RESULT_FAILED, PriceChange::check( array_merge( $plain, array( 'renewal_amount_minor' => 0 ) ), 5900, $in_60, self::NOW, 60 ), 'A license without an agreed amount has no price to change.' );
	}

	public function test_a_scheduled_plan_change_is_capped_by_the_amount_that_applies(): void {
		$license = $this->license();
		$applies = PriceChange::pending( $license )['applies_at'];
		$plan    = array( 'amount_minor' => 5500, 'decimals' => 2, 'currency' => 'EUR' );
		$base    = static function ( array $row ): array {
			return RenewalOrder::choose_price( $row, null, null, false );
		};
		// Before the date an automatic renewal into the pending plan pays at most the old amount, after it at most the new one.
		$this->assertSame( 4900, RenewalOrder::choose_pending_price( $base( PriceChange::license_at( $license, self::NOW ) ), $plan, false )['amount_minor'] );
		$this->assertSame( 5500, RenewalOrder::choose_pending_price( $base( PriceChange::license_at( $license, $applies ) ), $plan, false )['amount_minor'] );
	}
}
