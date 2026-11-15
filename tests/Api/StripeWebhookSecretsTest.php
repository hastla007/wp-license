<?php
use PHPUnit\Framework\TestCase;
use WPLC\Api\V1\StripeWebhook;

/**
 * B-148, B-29: which signing secret an event was made with. Pure functions, no WordPress.
 */
final class StripeWebhookSecretsTest extends TestCase {

	private function header( string $body, string $secret, int $time ): string {
		return 't=' . $time . ',v1=' . hash_hmac( 'sha256', $time . '.' . $body, $secret );
	}

	public function test_single_secret_is_accepted_for_both_modes(): void {
		$list = StripeWebhook::secret_list( 'whsec_only', '' );

		$this->assertSame( array( array( 'secret' => 'whsec_only', 'mode' => '' ) ), $list );
		$this->assertSame( '', StripeWebhook::matching_secret_mode( '{}', $this->header( '{}', 'whsec_only', 1000 ), $list, 1000 ) );
	}

	public function test_two_secrets_tell_the_mode(): void {
		$list = StripeWebhook::secret_list( 'whsec_live', 'whsec_test' );

		$this->assertSame( 'live', StripeWebhook::matching_secret_mode( '{}', $this->header( '{}', 'whsec_live', 1000 ), $list, 1000 ) );
		$this->assertSame( 'test', StripeWebhook::matching_secret_mode( '{}', $this->header( '{}', 'whsec_test', 1000 ), $list, 1000 ) );
		$this->assertNull( StripeWebhook::matching_secret_mode( '{}', $this->header( '{}', 'whsec_other', 1000 ), $list, 1000 ) );
	}

	public function test_test_secret_alone_only_accepts_test_events(): void {
		$list = StripeWebhook::secret_list( '', 'whsec_test' );

		$this->assertSame( array( array( 'secret' => 'whsec_test', 'mode' => 'test' ) ), $list );
	}

	public function test_no_secret_accepts_nothing(): void {
		$this->assertSame( array(), StripeWebhook::secret_list( '', '' ) );
		$this->assertNull( StripeWebhook::matching_secret_mode( '{}', $this->header( '{}', '', 1000 ), array(), 1000 ) );
		$this->assertNull( StripeWebhook::matching_secret_mode( '{}', $this->header( '{}', '', 1000 ), array( array( 'secret' => '', 'mode' => '' ) ), 1000 ), 'An empty secret must never verify anything.' );
	}

	public function test_tolerance_applies_with_every_secret(): void {
		$list = StripeWebhook::secret_list( 'whsec_live', 'whsec_test' );

		$this->assertNull( StripeWebhook::matching_secret_mode( '{}', $this->header( '{}', 'whsec_test', 1000 ), $list, 1301 ) );
		$this->assertSame( 'test', StripeWebhook::matching_secret_mode( '{}', $this->header( '{}', 'whsec_test', 1000 ), $list, 1300 ) );
	}
}
