<?php
use PHPUnit\Framework\TestCase;
use WPLC\Api\V1\ActivationService;
use WPLC\Api\V1\ClientIp;

final class ClientIpTest extends TestCase {
	public function test_uses_remote_addr_and_ignores_forwarding_headers_without_configuration(): void {
		$server = array(
			'REMOTE_ADDR' => '203.0.113.7',
			'HTTP_X_FORWARDED_FOR' => '198.51.100.1',
			'HTTP_CF_CONNECTING_IP' => '198.51.100.2',
		);

		$this->assertSame( '203.0.113.7', ClientIp::resolve( $server ) );
	}

	public function test_returns_empty_string_for_missing_or_invalid_remote_addr(): void {
		$this->assertSame( '', ClientIp::resolve( array() ) );
		$this->assertSame( '', ClientIp::resolve( array( 'REMOTE_ADDR' => 'not-an-ip' ) ) );
		$this->assertSame( '', ClientIp::resolve( array( 'REMOTE_ADDR' => array( '203.0.113.7' ) ) ) );
	}

	public function test_single_value_header_is_used_only_from_a_trusted_proxy(): void {
		$server = array( 'REMOTE_ADDR' => '173.245.48.10', 'HTTP_CF_CONNECTING_IP' => '198.51.100.2' );

		$this->assertSame( '198.51.100.2', ClientIp::resolve( $server, 'HTTP_CF_CONNECTING_IP', array( '173.245.48.0/20' ) ) );
		$this->assertSame( '198.51.100.2', ClientIp::resolve( $server, 'CF-Connecting-IP', array( '173.245.48.0/20' ) ) );
		$this->assertSame( '173.245.48.10', ClientIp::resolve( $server, 'HTTP_CF_CONNECTING_IP', array( '10.0.0.0/8' ) ) );
		$this->assertSame( '173.245.48.10', ClientIp::resolve( $server, 'HTTP_CF_CONNECTING_IP', array() ) );
	}

	public function test_undefined_proxy_list_trusts_the_header_from_any_peer(): void {
		$server = array( 'REMOTE_ADDR' => '192.0.2.50', 'HTTP_CF_CONNECTING_IP' => '198.51.100.2' );

		$this->assertSame( '198.51.100.2', ClientIp::resolve( $server, 'HTTP_CF_CONNECTING_IP', null ) );
	}

	public function test_forwarded_for_takes_the_right_most_untrusted_address(): void {
		$server = array(
			'REMOTE_ADDR' => '10.0.0.5',
			'HTTP_X_FORWARDED_FOR' => '1.1.1.1, 198.51.100.9, 10.0.0.9, 10.0.0.8',
		);

		// 1.1.1.1 is client-supplied and must not win.
		$this->assertSame( '198.51.100.9', ClientIp::resolve( $server, 'HTTP_X_FORWARDED_FOR', array( '10.0.0.0/8' ) ) );
		// Without a proxy list the address appended by the nearest proxy wins.
		$this->assertSame( '10.0.0.8', ClientIp::resolve( $server, 'HTTP_X_FORWARDED_FOR', null ) );
	}

	public function test_forwarded_for_falls_back_to_remote_addr_when_unusable(): void {
		$proxies = array( '10.0.0.0/8' );

		$this->assertSame( '10.0.0.5', ClientIp::resolve( array( 'REMOTE_ADDR' => '10.0.0.5' ), 'HTTP_X_FORWARDED_FOR', $proxies ) );
		$this->assertSame( '10.0.0.5', ClientIp::resolve( array( 'REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '10.0.0.9, 10.0.0.8' ), 'HTTP_X_FORWARDED_FOR', $proxies ) );
		$this->assertSame( '10.0.0.5', ClientIp::resolve( array( 'REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '198.51.100.9, garbage' ), 'HTTP_X_FORWARDED_FOR', $proxies ) );
		$this->assertSame( '10.0.0.5', ClientIp::resolve( array( 'REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '198.51.100.9' ), 'bad header!', $proxies ) );
	}

	public function test_ipv6_peers_proxies_and_forwarded_addresses(): void {
		$server = array(
			'REMOTE_ADDR' => '2400:cb00::1234',
			'HTTP_X_FORWARDED_FOR' => '2001:db8:aaaa::1, [2001:db8:bbbb::2]:443, 2400:cb00:0:1::9',
		);

		$this->assertSame( '2001:db8:bbbb::2', ClientIp::resolve( $server, 'X-Forwarded-For', array( '2400:cb00::/32' ) ) );
		$this->assertSame( '2400:cb00::1234', ClientIp::resolve( $server, 'X-Forwarded-For', array( '2a06:98c0::/29', '10.0.0.0/8' ) ) );
	}

	public function test_normalizes_ports_brackets_and_ipv4_mapped_ipv6(): void {
		$this->assertSame( '198.51.100.9', ClientIp::normalize( ' 198.51.100.9:51234 ' ) );
		$this->assertSame( '198.51.100.9', ClientIp::normalize( '::ffff:198.51.100.9' ) );
		$this->assertSame( '2001:db8::1', ClientIp::normalize( '[2001:0DB8:0000:0000:0000:0000:0000:0001]' ) );
		$this->assertSame( '', ClientIp::normalize( '999.1.1.1' ) );
		$this->assertSame( '', ClientIp::normalize( 'unknown' ) );
		$this->assertSame( '', ClientIp::normalize( '' ) );
	}

	public function test_cidr_matching_for_ipv4(): void {
		$this->assertTrue( ClientIp::in_range( '10.1.2.3', '10.0.0.0/8' ) );
		$this->assertFalse( ClientIp::in_range( '11.0.0.1', '10.0.0.0/8' ) );
		$this->assertTrue( ClientIp::in_range( '192.168.1.130', '192.168.1.128/25' ) );
		$this->assertFalse( ClientIp::in_range( '192.168.1.127', '192.168.1.128/25' ) );
		$this->assertTrue( ClientIp::in_range( '203.0.113.7', '203.0.113.7' ) );
		$this->assertTrue( ClientIp::in_range( '203.0.113.7', '203.0.113.7/32' ) );
		$this->assertFalse( ClientIp::in_range( '203.0.113.8', '203.0.113.7/32' ) );
		$this->assertTrue( ClientIp::in_range( '203.0.113.8', '0.0.0.0/0' ) );
		$this->assertTrue( ClientIp::in_range( '::ffff:10.1.2.3', '10.0.0.0/8' ) );
	}

	public function test_cidr_matching_for_ipv6(): void {
		$this->assertTrue( ClientIp::in_range( '2400:cb00:1::1', '2400:cb00::/32' ) );
		$this->assertFalse( ClientIp::in_range( '2400:cb01::1', '2400:cb00::/32' ) );
		$this->assertTrue( ClientIp::in_range( '2a06:98c7::1', '2a06:98c0::/29' ) );
		$this->assertFalse( ClientIp::in_range( '2a06:98c8::1', '2a06:98c0::/29' ) );
		$this->assertTrue( ClientIp::in_range( '::1', '::1' ) );
		$this->assertTrue( ClientIp::in_range( '2001:db8::1', '2001:db8::1/128' ) );
		$this->assertFalse( ClientIp::in_range( '2001:db8::2', '2001:db8::1/128' ) );
	}

	public function test_cidr_matching_rejects_mixed_families_and_malformed_ranges(): void {
		$this->assertFalse( ClientIp::in_range( '10.0.0.1', '2400:cb00::/32' ) );
		$this->assertFalse( ClientIp::in_range( '2400:cb00::1', '10.0.0.0/8' ) );
		$this->assertFalse( ClientIp::in_range( '10.0.0.1', '10.0.0.0/33' ) );
		$this->assertFalse( ClientIp::in_range( '10.0.0.1', '10.0.0.0/-1' ) );
		$this->assertFalse( ClientIp::in_range( '10.0.0.1', '10.0.0.0/abc' ) );
		$this->assertFalse( ClientIp::in_range( '10.0.0.1', 'example.com/8' ) );
		$this->assertFalse( ClientIp::in_range( '10.0.0.1', '' ) );
		$this->assertFalse( ClientIp::in_range( 'garbage', '10.0.0.0/8' ) );
	}

	public function test_rate_limit_bucket_groups_ipv6_by_64_and_keeps_ipv4_addresses(): void {
		$this->assertSame( '203.0.113.7', ClientIp::rate_limit_bucket( '203.0.113.7' ) );
		$this->assertSame( '2001:db8:1:2::/64', ClientIp::rate_limit_bucket( '2001:db8:1:2:aaaa:bbbb:cccc:dddd' ) );
		$this->assertSame( ClientIp::rate_limit_bucket( '2001:db8:1:2::1' ), ClientIp::rate_limit_bucket( '2001:db8:1:2:ffff::9' ) );
		$this->assertNotSame( ClientIp::rate_limit_bucket( '2001:db8:1:2::1' ), ClientIp::rate_limit_bucket( '2001:db8:1:3::1' ) );
		$this->assertSame( '', ClientIp::rate_limit_bucket( 'garbage' ) );
	}

	public function test_current_reads_the_request_without_proxy_constants(): void {
		$previous = $_SERVER;
		try {
			$_SERVER['REMOTE_ADDR'] = '203.0.113.77';
			$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1';
			$this->assertSame( '203.0.113.77', ClientIp::current() );

			unset( $_SERVER['REMOTE_ADDR'] );
			$this->assertSame( '', ClientIp::current() );
		} finally {
			$_SERVER = $previous;
		}
	}

	public function test_key_limiter_uses_only_the_first_thirteen_normalized_characters(): void {
		$first = ActivationService::key_limiter_hash( 'WPLC-1A2B3C4D-AAAA-BBBB' );

		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $first );
		$this->assertSame( $first, ActivationService::key_limiter_hash( '  wplc-1a2b3c4d-ffff-0000 ' ) );
		$this->assertNotSame( $first, ActivationService::key_limiter_hash( 'WPLC-1A2B3C4E-AAAA-BBBB' ) );
		$this->assertSame( hash( 'sha256', 'activation-key:WPLC-1A2B3C4D' ), $first );
	}
}
