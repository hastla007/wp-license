<?php
/**
 * Activation, refresh, outage and deactivation against an in-process license server (B6, B8, B10).
 *
 * @package Vendor\Product\Premium
 */

use PHPUnit\Framework\TestCase;
use Vendor\Product\Premium\Tests\Support\FakeServer;
use Vendor\Product\Premium\Tests\Support\Fixtures;

final class LicenseServiceTest extends TestCase {
	/** @var FakeServer */
	private $server;

	protected function setUp(): void {
		wp_stub_reset();
		$this->server = new FakeServer();
		$this->server->install();
	}

	public function test_activation_stores_credentials_and_a_verified_cache(): void {
		$service = Fixtures::service( Fixtures::time( 60 ) );
		$result  = $service->activate( ' starter-key-abcd ' );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'POST /wplc/v1/activations', 'POST /wplc/v1/entitlements/check' ), $this->server->seen );
		$this->assertTrue( $service->is_activated() );
		$this->assertTrue( wp_is_uuid( $service->installation_id() ) );
		$this->assertTrue( $service->has( 'starter.pdf_templates' ) );
		$this->assertSame( 'ABCD', $service->state()['key_hint'] );
		$this->assertArrayNotHasKey( 'envelope', $service->state(), 'state() never hands out the envelope' );
		$stored = get_option( Fixtures::CREDENTIALS );
		$this->assertSame( hash( 'sha256', 'STARTER-KEY-ABCD' ), $stored['license_key_hash'] );
		$this->assertStringNotContainsString( 'STARTER-KEY-ABCD', (string) wp_json_encode( $GLOBALS['wp_stub']['options'] ), 'the key itself is not stored' );
	}

	public function test_a_license_of_another_product_is_refused_and_nothing_is_stored(): void {
		$this->server->errors['POST /wplc/v1/activations'] = array( 403, 'product_mismatch' );
		$service = Fixtures::service( Fixtures::time( 60 ) );
		$result  = $service->activate( 'OTHER-PRODUCT-KEY' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'product_mismatch', $result->get_error_code() );
		$this->assertFalse( $service->is_activated() );
		$this->assertFalse( $service->has( 'starter.pdf_templates' ) );
		$this->assertStringContainsString( 'product_mismatch', $service->state()['last_error'] );
	}

	public function test_staging_needs_the_live_installation_id_before_any_request(): void {
		$service = Fixtures::service( Fixtures::time( 60 ) );
		$this->assertSame( 'staging_parent_missing', $service->activate( 'KEY', 'staging', 'not-a-uuid' )->get_error_code() );
		$this->assertSame( 'environment_invalid', $service->activate( 'KEY', 'qa' )->get_error_code() );
		$this->assertSame( array(), $this->server->seen );
	}

	public function test_refresh_replaces_the_cache_and_a_revocation_switches_features_off_at_once(): void {
		Fixtures::store_credentials();
		$service = Fixtures::service( Fixtures::time( 60 ) );
		$this->assertIsArray( $service->refresh() );
		$this->assertTrue( $service->has( 'starter.pdf_templates' ) );

		$this->server->status = 'revoked';
		$data = $service->refresh();
		$this->assertSame( 'revoked', $data['license_status'] );
		$this->assertFalse( $service->has( 'starter.pdf_templates' ) );
	}

	public function test_server_errors_keep_the_cache_within_its_age(): void {
		Fixtures::store_credentials();
		$this->assertIsArray( Fixtures::service( Fixtures::time( 60 ) )->refresh() );

		// Client 2.0 reports a transport failure as wplc_connection_failed.
		foreach ( array( array( 0, 'wplc_connection_failed' ), array( 503, 'replay_store_unavailable' ), array( 429, 'rate_limited' ) ) as $error ) {
			$this->server->errors['POST /wplc/v1/entitlements/check'] = $error;
			$service = Fixtures::service( Fixtures::time( 10 * 86400 ) );
			$this->assertInstanceOf( WP_Error::class, $service->refresh() );
			$this->assertTrue( $service->has( 'starter.pdf_templates' ), 'cache still used after ' . $error[1] );
			$this->assertStringContainsString( $error[1], $service->state()['last_error'] );
		}
		$this->assertFalse( Fixtures::service( Fixtures::time( 15 * 86400 ) )->has( 'starter.pdf_templates' ), 'but not after 14 days' );
	}

	public function test_an_answer_signed_for_another_installation_does_not_replace_the_cache(): void {
		Fixtures::store_credentials();
		$service = Fixtures::service( Fixtures::time( 60 ) );
		$service->refresh();
		$cached = get_option( Fixtures::STATE )['envelope'];

		$this->server->sign_for = '6f1c2a3b-4d5e-4f60-8a7b-000000000000';
		$this->server->status   = 'revoked';
		$result = $service->refresh();
		$this->assertSame( 'wplc_server_signature_invalid', $result->get_error_code() );
		$this->assertSame( $cached, get_option( Fixtures::STATE )['envelope'] );
		$this->assertTrue( $service->has( 'starter.pdf_templates' ) );
	}

	public function test_an_activation_that_is_gone_on_the_server_drops_the_cache(): void {
		Fixtures::store_credentials();
		$service = Fixtures::service( Fixtures::time( 60 ) );
		$service->refresh();
		$this->server->errors['POST /wplc/v1/entitlements/check'] = array( 401, 'activation_not_found' );
		$service->refresh();
		$this->assertFalse( $service->has( 'starter.pdf_templates' ) );
		$this->assertSame( 'missing', $service->cache_problem() );
	}

	public function test_a_new_answer_that_cannot_be_stored_drops_the_old_cache(): void {
		Fixtures::store_credentials();
		$service = Fixtures::service( Fixtures::time( 60 ) );
		$service->refresh();
		$this->server->status                  = 'revoked';
		$this->server->server_time             = '2026-11-02 00:00:00';
		$GLOBALS['wp_stub']['fail_update'][] = Fixtures::STATE;
		$this->assertSame( 'license_state_not_saved', $service->refresh()->get_error_code() );
		$this->assertFalse( get_option( Fixtures::STATE ) );
		$this->assertFalse( $service->has( 'starter.pdf_templates' ) );
	}

	public function test_deactivation_releases_the_site_and_deletes_local_data(): void {
		Fixtures::store_credentials();
		$service = Fixtures::service( Fixtures::time( 60 ) );
		$service->refresh();
		$this->assertIsArray( $service->deactivate() );
		$this->assertContains( 'DELETE /wplc/v1/activations/{id}', $this->server->seen );
		$this->assertFalse( get_option( Fixtures::CREDENTIALS ) );
		$this->assertFalse( get_option( Fixtures::STATE ) );
		$this->assertFalse( $service->has( 'starter.pdf_templates' ) );
	}

	public function test_deactivation_keeps_credentials_when_the_server_is_unreachable(): void {
		Fixtures::store_credentials();
		$this->server->errors['DELETE /wplc/v1/activations/{id}'] = array( 0, 'http_request_failed' );
		$service = Fixtures::service( Fixtures::time( 60 ) );
		$this->assertInstanceOf( WP_Error::class, $service->deactivate() );
		$this->assertTrue( $service->is_activated(), 'kept, so the slot can still be released later' );
	}

	public function test_an_unsigned_activation_not_found_keeps_the_credentials(): void {
		Fixtures::store_credentials();
		$this->server->errors['DELETE /wplc/v1/activations/{id}'] = array( 404, 'activation_not_found' );
		$service = Fixtures::service( Fixtures::time( 60 ) );
		$this->assertInstanceOf( WP_Error::class, $service->deactivate() );
		$this->assertTrue( $service->is_activated(), 'an unsigned refusal could be forged' );
	}

	public function test_another_key_releases_the_old_activation_first(): void {
		$service = Fixtures::service( Fixtures::time( 60 ) );
		$service->activate( 'FIRST-KEY-0001' );
		$first = $service->installation_id();
		$this->server->seen = array();
		$service->activate( 'SECOND-KEY-0002' );
		$this->assertSame( array( 'DELETE /wplc/v1/activations/{id}', 'POST /wplc/v1/activations', 'POST /wplc/v1/entitlements/check' ), $this->server->seen );
		$this->assertNotSame( $first, $service->installation_id() );
	}
}
