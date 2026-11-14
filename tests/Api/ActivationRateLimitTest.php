<?php
/**
 * T-142: activation attempts are limited by failures; a valid key behind a
 * throttled address still gets through, within a bounded budget.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\Api\V1\ActivationRateLimit;
use WPLC\Api\V1\Authenticator;

/**
 * Keeps one counter per limiter hash; prepare() passes the arguments through.
 */
final class WPLC_ActivationLimitDb_Double {
	public $prefix     = 'wp_';
	public $last_error = '';
	public $counts     = array();
	public $fail       = false;

	public function prepare( string $query, ...$args ): string {
		return (string) json_encode( array( $query, $args ) );
	}

	public function query( string $prepared ) {
		if ( $this->fail ) {
			return false;
		}
		list( , $args ) = json_decode( $prepared, true );
		$this->counts[ $args[0] ] = ( $this->counts[ $args[0] ] ?? 0 ) + 1;
		return 1;
	}

	public function get_var( string $prepared ) {
		if ( $this->fail ) {
			$this->last_error = 'gone away';
			return null;
		}
		list( , $args ) = json_decode( $prepared, true );
		return isset( $this->counts[ $args[0] ] ) ? (string) $this->counts[ $args[0] ] : null;
	}
}

final class ActivationRateLimitTest extends TestCase {
	private $previous_db;

	/** @var WPLC_ActivationLimitDb_Double */
	private $db;

	protected function setUp(): void {
		global $wpdb;
		$this->previous_db = $wpdb ?? null;
		$this->db          = new WPLC_ActivationLimitDb_Double();
		$wpdb              = $this->db;
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb = $this->previous_db;
	}

	private function ip(): string {
		return str_repeat( 'a', 64 );
	}

	public function test_successful_attempts_are_not_counted(): void {
		$limiters = array( $this->ip() => 3 );
		for ( $i = 0; $i < 10; $i++ ) {
			$this->assertSame( ActivationRateLimit::OK, ActivationRateLimit::check( $limiters ) );
		}
		$this->assertSame( array(), $this->db->counts );
		$this->assertSame( 0, Authenticator::current_rate_limit( $this->ip() ) );
	}

	public function test_failures_throttle_and_then_block_within_twice_the_limit(): void {
		$limiters = array( $this->ip() => 3 );
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertSame( ActivationRateLimit::OK, ActivationRateLimit::check( $limiters ) );
			$this->assertTrue( ActivationRateLimit::record_failure( $limiters ) );
		}

		// Throttled: the key is still checked (unknown keys get 429, valid keys proceed) ...
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertSame( ActivationRateLimit::THROTTLED, ActivationRateLimit::check( $limiters ), 'Throttled request ' . ( $i + 1 ) );
		}
		// ... but only up to the ceiling; then nothing is answered from the key at all.
		$this->assertSame( ActivationRateLimit::BLOCKED, ActivationRateLimit::check( $limiters ) );
		$this->assertSame( ActivationRateLimit::BLOCKED, ActivationRateLimit::check( $limiters ) );
		$this->assertSame( 3, Authenticator::current_rate_limit( $this->ip() ) );
	}

	public function test_any_exhausted_limiter_throttles(): void {
		$key = str_repeat( 'b', 64 );
		ActivationRateLimit::record_failure( array( $key => 1 ) );

		$this->assertSame( ActivationRateLimit::THROTTLED, ActivationRateLimit::check( array( $this->ip() => 30, $key => 1 ) ) );
		$this->assertSame( ActivationRateLimit::BLOCKED, ActivationRateLimit::check( array( $this->ip() => 30, $key => 1 ) ) );
		// The address itself is not affected by the key limiter.
		$this->assertSame( ActivationRateLimit::OK, ActivationRateLimit::check( array( $this->ip() => 30 ) ) );
	}

	public function test_a_failing_store_fails_closed(): void {
		$this->db->fail = true;

		$this->assertSame( ActivationRateLimit::UNAVAILABLE, ActivationRateLimit::check( array( $this->ip() => 3 ) ) );
		$this->assertFalse( ActivationRateLimit::record_failure( array( $this->ip() => 3 ) ) );
		$this->assertFalse( Authenticator::current_rate_limit( $this->ip() ) );
	}

	public function test_throttled_requests_use_a_separate_bucket(): void {
		$this->assertNotSame( $this->ip(), ActivationRateLimit::throttled_hash( $this->ip() ) );
		$this->assertSame( 64, strlen( ActivationRateLimit::throttled_hash( $this->ip() ) ) );
	}
}
