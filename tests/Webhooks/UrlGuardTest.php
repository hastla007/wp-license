<?php
/**
 * SET-LIC-46: SSRF protection of the webhook target URLs.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\Webhooks\UrlGuard;

final class UrlGuardTest extends TestCase {

	protected function tearDown(): void {
		UrlGuard::set_resolver( null );
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function syntax_cases(): array {
		return array(
			'public https'             => array( 'https://hooks.example.com/licenses?x=1', UrlGuard::OK ),
			'explicit port 443'        => array( 'https://hooks.example.com:443/in', UrlGuard::OK ),
			'public IPv4'              => array( 'https://93.184.215.14/in', UrlGuard::OK ),
			'public IPv6'              => array( 'https://[2606:4700::6810:84e5]/in', UrlGuard::OK ),
			'http'                     => array( 'http://hooks.example.com/', UrlGuard::NOT_HTTPS ),
			'ftp'                      => array( 'ftp://hooks.example.com/', UrlGuard::NOT_HTTPS ),
			'credentials'              => array( 'https://user:pass@hooks.example.com/', UrlGuard::CREDENTIALS ),
			'other port'               => array( 'https://hooks.example.com:8443/', UrlGuard::PORT ),
			'localhost'                => array( 'https://localhost/', UrlGuard::LOCAL_HOST ),
			'sub of localhost'         => array( 'https://api.localhost/', UrlGuard::LOCAL_HOST ),
			'single label'             => array( 'https://intranet/', UrlGuard::LOCAL_HOST ),
			'.local'                   => array( 'https://printer.local/', UrlGuard::LOCAL_HOST ),
			'.internal'                => array( 'https://metadata.google.internal/', UrlGuard::LOCAL_HOST ),
			'.home.arpa'               => array( 'https://nas.home.arpa/', UrlGuard::LOCAL_HOST ),
			'loopback'                 => array( 'https://127.0.0.1/', UrlGuard::BLOCKED_ADDRESS ),
			'private 10'               => array( 'https://10.1.2.3/', UrlGuard::BLOCKED_ADDRESS ),
			'private 172.16'           => array( 'https://172.20.0.1/', UrlGuard::BLOCKED_ADDRESS ),
			'private 192.168'          => array( 'https://192.168.1.1/', UrlGuard::BLOCKED_ADDRESS ),
			'link-local metadata'      => array( 'https://169.254.169.254/latest/meta-data/', UrlGuard::BLOCKED_ADDRESS ),
			'cgnat'                    => array( 'https://100.64.0.1/', UrlGuard::BLOCKED_ADDRESS ),
			'zero'                     => array( 'https://0.0.0.0/', UrlGuard::BLOCKED_ADDRESS ),
			'ipv6 loopback'            => array( 'https://[::1]/', UrlGuard::BLOCKED_ADDRESS ),
			'ipv6 link-local'          => array( 'https://[fe80::1]/', UrlGuard::BLOCKED_ADDRESS ),
			'ipv6 unique local'        => array( 'https://[fd00::1]/', UrlGuard::BLOCKED_ADDRESS ),
			'ipv4-mapped loopback'     => array( 'https://[::ffff:127.0.0.1]/', UrlGuard::BLOCKED_ADDRESS ),
			'nat64 of private'         => array( 'https://[64:ff9b::a00:1]/', UrlGuard::BLOCKED_ADDRESS ),
			'empty'                    => array( '', UrlGuard::INVALID ),
			'no host'                  => array( 'https:///path', UrlGuard::INVALID ),
			'whitespace'               => array( "https://hooks.example.com/a b", UrlGuard::INVALID ),
			'too long'                 => array( 'https://hooks.example.com/' . str_repeat( 'a', 2000 ), UrlGuard::INVALID ),
			'trailing dot'             => array( 'https://hooks.example.com./in', UrlGuard::TRAILING_DOT ),
			'trailing dot with port'   => array( 'https://hooks.example.com.:443/in', UrlGuard::TRAILING_DOT ),
			'trailing dot local'       => array( 'https://localhost./', UrlGuard::TRAILING_DOT ),
		);
	}

	/**
	 * @dataProvider syntax_cases
	 */
	public function test_the_form_of_a_url_is_checked_without_dns( string $url, string $expected ): void {
		$this->assertSame( $expected, UrlGuard::check_syntax( $url ) );
	}

	public function test_every_resolved_address_must_be_public(): void {
		UrlGuard::set_resolver(
			static function ( string $host ): array {
				$map = array(
					'public.example.com'  => array( '93.184.215.14', '2606:4700::6810:84e5' ),
					'rebind.example.com'  => array( '93.184.215.14', '10.0.0.5' ),
					'private.example.com' => array( '192.168.0.10' ),
					'meta.example.com'    => array( '169.254.169.254' ),
				);
				return $map[ $host ] ?? array();
			}
		);

		$this->assertSame( array( 'error' => UrlGuard::OK, 'ip' => '93.184.215.14' ), UrlGuard::check( 'https://public.example.com/in' ) );
		$this->assertSame( UrlGuard::BLOCKED_ADDRESS, UrlGuard::check( 'https://rebind.example.com/in' )['error'], 'One private address among public ones is enough to refuse.' );
		$this->assertSame( UrlGuard::BLOCKED_ADDRESS, UrlGuard::check( 'https://private.example.com/in' )['error'] );
		$this->assertSame( UrlGuard::BLOCKED_ADDRESS, UrlGuard::check( 'https://meta.example.com/in' )['error'] );
		$this->assertSame( UrlGuard::UNRESOLVED, UrlGuard::check( 'https://nowhere.example.com/in' )['error'] );
		$this->assertSame( UrlGuard::NOT_HTTPS, UrlGuard::check( 'http://public.example.com/in' )['error'], 'The form is checked first.' );
		$this->assertSame( array( 'error' => UrlGuard::OK, 'ip' => '93.184.215.14' ), UrlGuard::check( 'https://93.184.215.14/in' ), 'A literal address needs no lookup.' );
		$this->assertSame( array( 'error' => UrlGuard::TRAILING_DOT, 'ip' => '' ), UrlGuard::check( 'https://public.example.com./in' ), 'cURL would resolve "host." itself, past the pinned address.' );
	}

	public function test_same_ip_compares_addresses_in_any_notation(): void {
		$this->assertTrue( UrlGuard::same_ip( '93.184.215.14', '93.184.215.14' ) );
		$this->assertTrue( UrlGuard::same_ip( '2606:4700:0:0:0:0:6810:84e5', '[2606:4700::6810:84E5]' ) );
		$this->assertFalse( UrlGuard::same_ip( '93.184.215.14', '10.0.0.5' ) );
		$this->assertFalse( UrlGuard::same_ip( '', '' ) );
		$this->assertFalse( UrlGuard::same_ip( 'example.com', 'example.com' ) );
	}

	public function test_public_and_blocked_addresses(): void {
		foreach ( array( '8.8.8.8', '1.1.1.1', '2001:4860:4860::8888', '::ffff:8.8.8.8' ) as $ip ) {
			$this->assertTrue( UrlGuard::is_public_ip( $ip ), $ip );
		}
		foreach ( array( '127.1.2.3', '10.255.255.255', '172.31.0.1', '192.168.255.1', '169.254.1.1', '100.127.0.1', '198.18.0.1', '192.0.2.1', '224.0.0.1', '255.255.255.255', '::', '::1', 'fc00::1', 'fe80::abcd', 'ff02::1', '2001:db8::1', 'not-an-ip' ) as $ip ) {
			$this->assertFalse( UrlGuard::is_public_ip( $ip ), $ip );
		}
		// Edges of a range.
		$this->assertTrue( UrlGuard::is_public_ip( '172.15.255.255' ) );
		$this->assertTrue( UrlGuard::is_public_ip( '172.32.0.0' ) );
		$this->assertTrue( UrlGuard::is_public_ip( '100.128.0.0' ) );
	}

	public function test_the_host_is_lower_case_without_brackets_or_trailing_dot(): void {
		$this->assertSame( 'hooks.example.com', UrlGuard::host( 'https://Hooks.Example.COM./x' ) );
		$this->assertSame( '::1', UrlGuard::host( 'https://[::1]/' ) );
		$this->assertSame( '', UrlGuard::host( 'not a url' ) );
	}
}
