<?php
use PHPUnit\Framework\TestCase;
use WPLC\Api\V1\StripeWebhook;

final class StripeWebhookSignatureTest extends TestCase {
	/** Invoke the private verifier without registering WordPress routes or making network requests. */
	private function verify( string $body, string $header ): bool {
		$method = new ReflectionMethod( StripeWebhook::class, 'verify_signature' );
		$method->setAccessible( true );
		return (bool) $method->invoke( null, $body, $header );
	}

	public function test_accepts_valid_current_v1_signature(): void {
		$body = '{"id":"evt_test","type":"payment_intent.succeeded"}';
		$timestamp = time();
		$signature = hash_hmac( 'sha256', $timestamp . '.' . $body, WPLC_STRIPE_WEBHOOK_SECRET );

		$this->assertTrue( $this->verify( $body, 't=' . $timestamp . ',v1=' . $signature ) );
	}

	public function test_rejects_invalid_signature(): void {
		$body = '{"id":"evt_test"}';

		$this->assertFalse( $this->verify( $body, 't=' . time() . ',v1=' . str_repeat( '0', 64 ) ) );
	}

	public function test_rejects_stale_timestamp(): void {
		$body = '{"id":"evt_test"}';
		$timestamp = time() - 301;
		$signature = hash_hmac( 'sha256', $timestamp . '.' . $body, WPLC_STRIPE_WEBHOOK_SECRET );

		$this->assertFalse( $this->verify( $body, 't=' . $timestamp . ',v1=' . $signature ) );
	}

	public function test_rejects_malformed_signature_header(): void {
		$this->assertFalse( $this->verify( '{"id":"evt_test"}', 'v0=abc,t=not-a-time' ) );
	}
}
