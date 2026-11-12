<?php
use PHPUnit\Framework\TestCase;
use WPLC\Api\V1\Authenticator;

final class WPLC_RateLimitDb_Double {
	public $prefix = 'wp_';
	public $hits = 0;
	public $last_query = '';

	public function prepare( string $query, ...$args ): string {
		return $query;
	}

	public function query( string $query ): int {
		$this->last_query = $query;
		++$this->hits;
		return 1;
	}

	public function get_var( string $query ) {
		return $this->hits;
	}
}

final class AuthenticatorRateLimitTest extends TestCase {
	private $previous_db;

	protected function setUp(): void {
		global $wpdb;
		$this->previous_db = $wpdb ?? null;
		$wpdb = new WPLC_RateLimitDb_Double();
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb = $this->previous_db;
	}

	public function test_rate_limit_uses_an_atomic_unique_minute_bucket(): void {
		global $wpdb;

		$this->assertSame( 1, Authenticator::increment_rate_limit( str_repeat( 'a', 64 ) ) );
		$this->assertStringContainsString( 'wp_wplc_request_limits', $wpdb->last_query );
		$this->assertStringContainsString( 'ON DUPLICATE KEY UPDATE hits = hits + 1', $wpdb->last_query );
	}

	public function test_each_attempt_reads_back_the_incremented_count(): void {
		$this->assertSame( 1, Authenticator::increment_rate_limit( str_repeat( 'b', 64 ) ) );
		$this->assertSame( 2, Authenticator::increment_rate_limit( str_repeat( 'b', 64 ) ) );
	}
}
