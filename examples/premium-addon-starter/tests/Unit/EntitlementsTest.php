<?php
/**
 * Entitlement decisions from the signed offline cache (B5, B6, B10).
 *
 * @package Vendor\Product\Premium
 */

use PHPUnit\Framework\TestCase;
use Vendor\Product\Premium\Client\LicenseClient;
use Vendor\Product\Premium\License\Entitlements;
use Vendor\Product\Premium\Tests\Support\Fixtures;

final class EntitlementsTest extends TestCase {
	protected function setUp(): void {
		wp_stub_reset();
		Entitlements::use_service( null );
		Fixtures::store_credentials();
	}

	public function test_without_service_or_cache_everything_is_off(): void {
		$this->assertFalse( Entitlements::has( 'starter.pdf_templates' ), 'before boot' );
		Entitlements::use_service( Fixtures::service( Fixtures::time( 60 ) ) );
		$this->assertFalse( Entitlements::has( 'starter.pdf_templates' ), 'no cache yet' );
		$this->assertSame( 0, Entitlements::limit( 'starter.monthly_limit' ) );
	}

	public function test_a_fresh_signed_active_cache_unlocks_exactly_the_granted_keys(): void {
		Fixtures::cache( 'entitlements-active.json' );
		Entitlements::use_service( Fixtures::service( Fixtures::time( 3600 ) ) );

		$this->assertTrue( Entitlements::has( 'starter.pdf_templates' ) );
		$this->assertTrue( Entitlements::has( 'starter.reports' ) );
		$this->assertFalse( Entitlements::has( 'starter.disabled' ), 'false value' );
		$this->assertFalse( Entitlements::has( 'starter.white_label' ), 'absent key' );
		$this->assertTrue( Entitlements::has( 'starter.monthly_limit' ), 'positive limit counts as granted' );
		$this->assertSame( 500, Entitlements::limit( 'starter.monthly_limit' ) );
		$this->assertFalse( Entitlements::has( 'other.feature' ), 'a key of another product is never honoured, even when signed' );
		$this->assertFalse( Entitlements::has( '' ) );
	}

	public function test_past_due_within_server_grace_keeps_features(): void {
		Fixtures::cache( 'entitlements-past-due.json' );
		Entitlements::use_service( Fixtures::service( Fixtures::time( 60 ) ) );
		$this->assertTrue( Entitlements::has( 'starter.pdf_templates' ) );
	}

	/**
	 * @dataProvider provide_no_access
	 */
	public function test_expired_revoked_and_foreign_licenses_switch_premium_off( string $fixture ): void {
		Fixtures::cache( $fixture );
		$service = Fixtures::service( Fixtures::time( 60 ) );
		Entitlements::use_service( $service );

		$this->assertIsArray( $service->snapshot(), 'the answer itself is valid and signed' );
		$this->assertFalse( Entitlements::has( 'starter.pdf_templates' ) );
		$this->assertFalse( Entitlements::has( 'starter.reports' ) );
		$this->assertSame( 0, Entitlements::limit( 'starter.monthly_limit' ) );
	}

	/** @return array<string,array{0:string}> */
	public function provide_no_access(): array {
		return array(
			'expired'                                  => array( 'entitlements-expired.json' ),
			'revoked'                                  => array( 'entitlements-revoked.json' ),
			'revoked, server still lists entitlements' => array( 'entitlements-revoked-leaky.json' ),
			'license of another product'               => array( 'entitlements-foreign-product.json' ),
		);
	}

	public function test_tampered_cache_with_valid_hmac_but_invalid_server_signature_is_rejected(): void {
		$keys     = Fixtures::json( 'keys.json' );
		$tampered = Fixtures::json( 'entitlements-tampered.json' );
		// Premise: whoever can read the options can recompute the HMAC; the HMAC check alone accepts the forgery.
		$this->assertIsArray( LicenseClient::verified_data( $tampered, (string) $keys['installation_secret'], '', true ) );

		Fixtures::cache( 'entitlements-tampered.json' );
		$service = Fixtures::service( Fixtures::time( 60 ) );
		Entitlements::use_service( $service );
		$this->assertNull( $service->snapshot() );
		$this->assertSame( 'invalid', $service->cache_problem() );
		$this->assertFalse( Entitlements::has( 'starter.white_label' ) );
		$this->assertFalse( Entitlements::has( 'starter.pdf_templates' ) );
	}

	/**
	 * @dataProvider provide_invalid_signatures
	 */
	public function test_caches_not_signed_by_the_pinned_server_for_this_installation_are_rejected( string $fixture ): void {
		Fixtures::cache( $fixture );
		$service = Fixtures::service( Fixtures::time( 60 ) );
		$this->assertNull( $service->snapshot() );
		$this->assertFalse( $service->has( 'starter.pdf_templates' ) );
	}

	/** @return array<string,array{0:string}> */
	public function provide_invalid_signatures(): array {
		return array(
			'signed for another installation' => array( 'entitlements-other-installation.json' ),
			'signed by another key'           => array( 'entitlements-forged-key.json' ),
			'no server signature'             => array( 'entitlements-unsigned.json' ),
		);
	}

	public function test_cache_is_valid_for_14_days_after_the_server_issued_it(): void {
		Fixtures::cache( 'entitlements-active.json' );
		$this->assertTrue( Fixtures::service( Fixtures::time( 14 * 86400 - 1 ) )->has( 'starter.pdf_templates' ), '14 days minus a second' );

		$stale = Fixtures::service( Fixtures::time( 14 * 86400 + 1 ) );
		$this->assertFalse( $stale->has( 'starter.pdf_templates' ), 'older than 14 days' );
		$this->assertSame( 'stale', $stale->cache_problem() );
	}

	public function test_garbage_in_the_cache_option_fails_closed(): void {
		$service = Fixtures::service( Fixtures::time( 60 ) );
		foreach ( array( 'string', array( 'envelope' => 'x' ), array( 'envelope' => array( 'data' => array( 'license_status' => 'active' ) ) ) ) as $value ) {
			update_option( Fixtures::STATE, $value );
			$fresh = Fixtures::service( Fixtures::time( 60 ) );
			$this->assertFalse( $fresh->has( 'starter.pdf_templates' ), wp_json_encode( $value ) );
		}
		$this->assertFalse( $service->has( 'starter.pdf_templates' ) );
	}

	public function test_without_credentials_the_cache_is_not_used(): void {
		Fixtures::cache( 'entitlements-active.json' );
		delete_option( Fixtures::CREDENTIALS );
		$this->assertFalse( Fixtures::service( Fixtures::time( 60 ) )->has( 'starter.pdf_templates' ) );
	}

	public function test_a_build_without_pinned_server_key_unlocks_nothing(): void {
		Fixtures::cache( 'entitlements-active.json' );
		$service = Fixtures::service( Fixtures::time( 60 ), Fixtures::client( array() ) );
		$this->assertFalse( $service->is_configured() );
		$this->assertFalse( $service->has( 'starter.pdf_templates' ) );
		$this->assertSame( 'license_not_configured', $service->refresh()->get_error_code() );
		$this->assertSame( 'license_not_configured', $service->activate( 'KEY' )->get_error_code() );
	}

	public function test_during_a_key_rotation_old_and_new_pinned_keys_work(): void {
		$keys = Fixtures::json( 'keys.json' );
		Fixtures::cache( 'entitlements-active.json' );
		$service = Fixtures::service( Fixtures::time( 60 ), Fixtures::client( array( (string) $keys['forger_public_key'], (string) $keys['server_public_key'] ) ) );
		$this->assertTrue( $service->has( 'starter.pdf_templates' ) );
	}
}
