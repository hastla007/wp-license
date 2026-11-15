<?php
/**
 * Access fields of signed license answers (read by the captain-pro client library).
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\Api\V1\EntitlementService;

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

final class AccessFieldsTest extends TestCase {
	private const NOW = 1800000000;

	/** @param array<string,mixed> $overrides */
	private static function license( array $overrides = array() ): array {
		return array_merge(
			array(
				'status'               => 'active',
				'product_key'          => 'voucher-captain-pro',
				'paid_until'           => gmdate( 'Y-m-d H:i:s', self::NOW + 30 * 86400 ),
				'renewal_enabled'      => 0,
				'cancel_at_period_end' => 0,
			),
			$overrides
		);
	}

	private static function utc_at( int $offset ): string {
		return gmdate( 'Y-m-d H:i:s', self::NOW + $offset );
	}

	public function test_an_active_manual_license_has_access_until_the_paid_date(): void {
		$fields = EntitlementService::access_fields( self::license(), self::NOW );

		$this->assertTrue( $fields['access'] );
		$this->assertSame( self::utc_at( 30 * 86400 ), $fields['access_until'] );
		$this->assertNull( $fields['grace_until'] );
		$this->assertSame( 'active', $fields['activation_status'] );
		$this->assertSame( 'voucher-captain-pro', $fields['product_key'] );
		$this->assertSame( self::utc_at( 14 * 86400 ), $fields['cache_expires_at'], 'offline window of 14 days' );
	}

	public function test_a_renewing_license_keeps_access_through_the_grace_period(): void {
		$license = self::license( array( 'renewal_enabled' => 1, 'paid_until' => self::utc_at( -86400 ) ) );
		$fields  = EntitlementService::access_fields( $license, self::NOW );

		$this->assertTrue( $fields['access'] );
		$this->assertSame( self::utc_at( 13 * 86400 ), $fields['grace_until'] );
		$this->assertSame( self::utc_at( 13 * 86400 ), $fields['access_until'] );
		$this->assertSame( self::utc_at( 13 * 86400 ), $fields['cache_expires_at'], 'the cache never outlives access' );
	}

	public function test_an_ended_or_blocked_license_has_no_access(): void {
		foreach ( array(
			self::license( array( 'paid_until' => self::utc_at( -86400 ) ) ),
			self::license( array( 'status' => 'suspended' ) ),
			self::license( array( 'status' => 'revoked' ) ),
			self::license( array( 'status' => 'expired', 'paid_until' => self::utc_at( -40 * 86400 ) ) ),
		) as $license ) {
			$fields = EntitlementService::access_fields( $license, self::NOW );
			$this->assertFalse( $fields['access'], (string) $license['status'] );
			$this->assertNull( $fields['access_until'] );
		}
	}
}
