<?php
/**
 * T-87, T-146, B-161, B-177: the rules of SiteUrl::normalize() and SiteUrl::comparison_key().
 *
 * normalize() is the stored form of a site address; comparison_key() decides
 * whether two addresses share one site slot. The rows that were marked
 * SURPRISING before T-146 now state the decided rule.
 *
 * Without ext-intl the encoder bundled with WordPress converts IDN hosts; that
 * branch is covered by the integration suite (SiteUrl::to_ascii( $host, false )).
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\Api\V1\SiteUrl;

final class SiteUrlNormalizationTest extends TestCase {

	/**
	 * URLs that normalize identically with and without ext-intl.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function data_environment_independent(): array {
		return array(
			// Scheme and host case, surrounding whitespace.
			'plain'                              => array( 'https://example.com', 'https://example.com' ),
			'trailing slash'                     => array( 'https://example.com/', 'https://example.com' ),
			'uppercase scheme and host'          => array( 'HTTPS://EXAMPLE.COM', 'https://example.com' ),
			'surrounding whitespace'             => array( "  https://example.com \n", 'https://example.com' ),
			'http stays http'                    => array( 'http://example.com', 'http://example.com' ),
			'www is a different host'            => array( 'https://www.example.com', 'https://www.example.com' ),
			'localhost'                          => array( 'http://localhost', 'http://localhost' ),
			'localhost with port and path'       => array( 'http://localhost:8888/site/', 'http://localhost:8888/site' ),
			'single label host'                  => array( 'https://intranet', 'https://intranet' ),

			// Trailing dots.
			'one trailing dot'                   => array( 'https://example.com.', 'https://example.com' ),
			'one trailing dot with path'         => array( 'https://example.com./shop', 'https://example.com/shop' ),
			'several trailing dots'              => array( 'https://example.com../', 'https://example.com' ),

			// Ports.
			'default https port'                 => array( 'https://example.com:443', 'https://example.com' ),
			'default http port'                  => array( 'http://example.com:80/', 'http://example.com' ),
			'https on port 80 is kept'           => array( 'https://example.com:80', 'https://example.com:80' ),
			'http on port 443 is kept'           => array( 'http://example.com:443', 'http://example.com:443' ),
			'custom port'                        => array( 'https://example.com:8443/', 'https://example.com:8443' ),
			'highest port'                       => array( 'https://example.com:65535', 'https://example.com:65535' ),
			'SURPRISING empty port is accepted'  => array( 'https://example.com:', 'https://example.com' ),

			// Paths: only trailing slashes are removed; everything else is kept verbatim.
			'subdirectory'                       => array( 'https://example.com/shop/', 'https://example.com/shop' ),
			'nested subdirectory'                => array( 'https://example.com/a/b/', 'https://example.com/a/b' ),
			'double slash root'                  => array( 'https://example.com//', 'https://example.com' ),
			'path case is preserved'             => array( 'https://example.com/Shop', 'https://example.com/Shop' ),
			'inner double slash collapsed'       => array( 'https://example.com/a//b/', 'https://example.com/a/b' ),
			'unreserved escape decoded'          => array( 'https://example.com/%7Euser/', 'https://example.com/~user' ),
			'reserved escape in upper case'      => array( 'https://example.com/a%2fb', 'https://example.com/a%2Fb' ),
			'raw UTF-8 path is percent-encoded'  => array( 'https://example.com/ünï', 'https://example.com/%C3%BCn%C3%AF' ),
			'longer than the 255 char column'    => array( 'https://example.com/' . str_repeat( 'p', 300 ), '' ),

			// Punycode input is ASCII and works everywhere.
			'punycode'                           => array( 'https://xn--mnchen-3ya.de', 'https://xn--mnchen-3ya.de' ),
			'uppercase punycode'                 => array( 'https://XN--MNCHEN-3YA.de/', 'https://xn--mnchen-3ya.de' ),

			// IP literals.
			'ipv4'                               => array( 'http://192.168.1.10', 'http://192.168.1.10' ),
			'ipv4 with port'                     => array( 'http://127.0.0.1:8080', 'http://127.0.0.1:8080' ),
			'ipv6'                               => array( 'http://[2001:db8::1]', 'http://[2001:db8::1]' ),
			'ipv6 with port and path'            => array( 'http://[2001:db8::1]:8080/wp/', 'http://[2001:db8::1]:8080/wp' ),
			'ipv6 with default port'             => array( 'http://[::1]:80', 'http://[::1]' ),
			'ipv6 uppercase'                     => array( 'http://[2001:DB8::1]', 'http://[2001:db8::1]' ),
			'ipv4-mapped ipv6'                   => array( 'http://[::ffff:192.0.2.1]', 'http://[::ffff:192.0.2.1]' ),
			'ipv6 is compressed'                 => array( 'http://[2001:0db8:0000:0000:0000:0000:0000:0001]', 'http://[2001:db8::1]' ),
		);
	}

	/**
	 * @dataProvider data_environment_independent
	 *
	 * @param string $input    Input URL.
	 * @param string $expected Normalized URL.
	 */
	public function test_normalizes( string $input, string $expected ): void {
		$this->assertSame( $expected, SiteUrl::normalize( $input ) );
	}

	/**
	 * URLs that must be rejected (empty string) in every environment.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function data_rejected(): array {
		return array(
			'empty'                          => array( '' ),
			'whitespace only'                => array( '   ' ),
			'no scheme'                      => array( 'example.com' ),
			'protocol-relative'              => array( '//example.com' ),
			'scheme without slashes'         => array( 'https:example.com' ),
			'ftp'                            => array( 'ftp://example.com' ),
			'javascript'                     => array( 'javascript://example.com/%0aalert(1)' ),
			'file'                           => array( 'file:///etc/passwd' ),

			// Userinfo.
			'user'                           => array( 'https://user@example.com' ),
			'user and password'              => array( 'https://user:pass@example.com' ),
			'password only'                  => array( 'https://:pass@example.com' ),
			'userinfo host confusion'        => array( 'https://example.com@evil.example' ),

			// Query and fragment.
			'query'                          => array( 'https://example.com?x=1' ),
			'fragment'                       => array( 'https://example.com#frag' ),

			// Backslashes.
			'backslash instead of slash'     => array( 'https://example.com\\admin' ),
			'backslash before userinfo'      => array( 'https://example.com\\@evil.example' ),
			'backslashes after scheme'       => array( 'https:\\\\example.com' ),
			'backslash in path'              => array( 'https://example.com/a\\b' ),

			// Ports.
			'port zero'                      => array( 'https://example.com:0' ),
			'port above range'               => array( 'https://example.com:65536' ),
			'non-numeric port'               => array( 'https://example.com:https' ),

			// Hosts.
			'empty label'                    => array( 'https://example..com' ),
			'underscore'                     => array( 'https://exa_mple.com' ),
			'leading hyphen'                 => array( 'https://-example.com' ),
			'trailing hyphen'                => array( 'https://example-.com' ),
			'label longer than 63'           => array( 'https://' . str_repeat( 'a', 64 ) . '.com' ),
			'host longer than 253'           => array( 'https://' . str_repeat( 'a.', 127 ) . 'com' ),
			'newline in host'                => array( "https://exa\nmple.com" ),
			'space in host'                  => array( 'https://exa mple.com' ),
			'ipv6 without brackets'          => array( 'http://2001:db8::1' ),
			'only dots'                      => array( 'https://...' ),
			'leading dot'                    => array( 'https://.example.com' ),
			'stray bracket'                  => array( 'https://0]' ),
			'ipv6 zone id'                   => array( 'http://[fe80::1%25eth0]/' ),

			// T-146: hosts that end in a number are IPv4 spellings a browser would rewrite.
			'ipv4 with leading zeros'        => array( 'http://192.168.001.010' ),
			'hex ipv4'                       => array( 'http://0x7f.1' ),
			'integer ipv4'                   => array( 'http://2130706433' ),
			'out-of-range ipv4'              => array( 'https://999.1.1.1' ),

			// T-146, B-177: paths.
			'dot segments'                   => array( 'https://example.com/a/../b' ),
			'single dot segment'             => array( 'https://example.com/./shop' ),
			'encoded dot segments'           => array( 'https://example.com/%2e%2e/shop' ),
			'space in path'                  => array( 'https://example.com/a b' ),
			'trailing space in path'         => array( 'https://example.com/x /' ),
			'tab in path'                    => array( "https://example.com/a\tb" ),
			'control character in path'      => array( "https://example.com/a\x01b" ),
			'NUL in path'                    => array( "https://example.com/a\0b" ),
			'malformed percent escape'       => array( 'https://example.com/a%zzb' ),
			'invalid UTF-8'                  => array( "https://example.com/a\xffb" ),
		);
	}

	/**
	 * @dataProvider data_rejected
	 *
	 * @param string $input Input URL.
	 */
	public function test_rejects( string $input ): void {
		$this->assertSame( '', SiteUrl::normalize( $input ) );
	}

	/**
	 * Empty delimiters: parse_url() only reports an empty query or fragment since PHP 8.0.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function data_empty_delimiters(): array {
		return array(
			'empty query'    => array( 'https://example.com/?' ),
			'empty fragment' => array( 'https://example.com/#' ),
			'empty userinfo' => array( 'https://@example.com' ),
		);
	}

	/**
	 * @dataProvider data_empty_delimiters
	 *
	 * @param string $input Input URL.
	 */
	public function test_empty_delimiters_depend_on_the_php_version( string $input ): void {
		if ( PHP_VERSION_ID >= 80000 ) {
			$this->assertSame( '', SiteUrl::normalize( $input ) );
			return;
		}

		// SURPRISING: PHP 7.4 cannot see the empty component, so the URL is either
		// rejected or accepted as the bare origin depending on the delimiter.
		$this->assertContains( SiteUrl::normalize( $input ), array( '', 'https://example.com' ) );
	}

	/**
	 * Internationalized hosts: [ input, result with ext-intl ].
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function data_idn(): array {
		return array(
			'umlaut'                             => array( 'https://münchen.de', 'https://xn--mnchen-3ya.de' ),
			'uppercase umlaut and path'          => array( 'https://MÜNCHEN.de/Shop/', 'https://xn--mnchen-3ya.de/Shop' ),
			'japanese'                           => array( 'https://例え.jp', 'https://xn--r8jz45g.jp' ),
			'idn with port'                      => array( 'https://münchen.de:8443', 'https://xn--mnchen-3ya.de:8443' ),
			// UTS #46 transitional processing maps "ß" to "ss". Browsers (IDNA2008,
			// non-transitional) resolve straße.de to xn--strae-oqa.de, a different domain.
			'sharp s keeps its own domain'       => array( 'https://straße.de', 'https://xn--strae-oqa.de' ),
		);
	}

	/**
	 * @dataProvider data_idn
	 *
	 * @param string $input     Input URL.
	 * @param string $with_intl Normalized URL when idn_to_ascii() exists.
	 */
	public function test_idn_hosts_depend_on_the_intl_extension( string $input, string $with_intl ): void {
		if ( function_exists( 'idn_to_ascii' ) ) {
			$this->assertSame( $with_intl, SiteUrl::normalize( $input ) );
			return;
		}

		// Without ext-intl and without WordPress (this unit suite) there is no
		// encoder; inside WordPress the bundled one is used (integration test).
		$this->assertSame( '', SiteUrl::normalize( $input ) );
	}

	public function test_unicode_and_punycode_spellings_share_one_identity_with_intl(): void {
		if ( ! function_exists( 'idn_to_ascii' ) ) {
			$this->markTestSkipped( 'Needs ext-intl; without it Unicode hosts are rejected (see test_idn_hosts_depend_on_the_intl_extension).' );
		}

		$this->assertSame( SiteUrl::normalize( 'https://xn--mnchen-3ya.de' ), SiteUrl::normalize( 'https://MÜNCHEN.de' ) );
	}

	/**
	 * Pairs a shop owner would call "the same site". true = one identity (one slot).
	 *
	 * @return array<string,array{0:string,1:string,2:bool}>
	 */
	public function data_same_site_pairs(): array {
		return array(
			'case of host'                         => array( 'https://Example.com', 'https://example.com', true ),
			'trailing slash'                       => array( 'https://example.com/shop/', 'https://example.com/shop', true ),
			'default port'                         => array( 'https://example.com:443', 'https://example.com', true ),
			'trailing dot'                         => array( 'https://example.com.', 'https://example.com', true ),
			// Domain or protocol changes produce a new identity: the customer must
			// deactivate the old URL (or the activation is updated in place when the
			// installation ID stays the same).
			'ipv6 spelling'                        => array( 'http://[2001:db8:0:0:0:0:0:1]', 'http://[2001:db8::1]', true ),
			'percent-encoded path'                 => array( 'https://example.com/%7Euser', 'https://example.com/~user', true ),
			'encoded and raw UTF-8 path'           => array( 'https://example.com/%c3%bcn', 'https://example.com/ün', true ),
			'duplicate slashes'                    => array( 'https://example.com//shop', 'https://example.com/shop', true ),
			// The stored form keeps scheme, www and path case; comparison_key() ignores them (see below).
			'http versus https'                    => array( 'http://example.com', 'https://example.com', false ),
			'www versus apex'                      => array( 'https://www.example.com', 'https://example.com', false ),
			'path case'                            => array( 'https://example.com/Shop', 'https://example.com/shop', false ),
		);
	}

	/**
	 * @dataProvider data_same_site_pairs
	 *
	 * @param string $first  First spelling.
	 * @param string $second Second spelling.
	 * @param bool   $same   Whether both normalize to the same identity.
	 */
	public function test_identity_of_alternative_spellings( string $first, string $second, bool $same ): void {
		$this->assertNotSame( '', SiteUrl::normalize( $first ) );
		$this->assertNotSame( '', SiteUrl::normalize( $second ) );
		$this->assertSame( $same, SiteUrl::normalize( $first ) === SiteUrl::normalize( $second ) );
	}

	public function test_normalization_is_idempotent(): void {
		foreach ( $this->data_environment_independent() as $label => $case ) {
			$this->assertSame( $case[1], SiteUrl::normalize( $case[1] ), $label );
		}
	}

	/** B-177: every accepted input of a broad matrix normalizes to a fixed point. */
	public function test_normalization_is_idempotent_for_a_matrix_of_odd_inputs(): void {
		$hosts = array( 'example.com', 'EXAMPLE.com.', 'www.example.com', '[::1]', '[2001:DB8::0:1]', '127.0.0.1', 'localhost', '0]', 'münchen.de', 'a.b.c.example.co.uk', '[::ffff:192.0.2.1]' );
		$ports = array( '', ':80', ':443', ':8080', ':' );
		$paths = array( '', '/', '//', '/a', '/a/', '/a//b', '/a /', '/ a', "/a\tb", '/%7e', '/%41%2f', '/ä', '/A/B/', '/a/./b', '/a%', '/%E4', '/a b/ ' );
		$checked = 0;
		foreach ( array( 'http://', 'https://', 'HTTPS://' ) as $scheme ) {
			foreach ( $hosts as $host ) {
				foreach ( $ports as $port ) {
					foreach ( $paths as $path ) {
						$once = SiteUrl::normalize( $scheme . $host . $port . $path );
						if ( '' === $once ) {
							continue;
						}
						++$checked;
						$this->assertSame( $once, SiteUrl::normalize( $once ), $scheme . $host . $port . $path );
						$this->assertLessThanOrEqual( 255, strlen( $once ) );
						$this->assertSame( SiteUrl::comparison_key( $once ), SiteUrl::comparison_key( $scheme . $host . $port . $path ) );
					}
				}
			}
		}
		$this->assertGreaterThan( 300, $checked );
	}

	/**
	 * B-161: what counts as the same site for the site limit.
	 *
	 * @return array<string,array{0:string,1:string,2:bool}>
	 */
	public function data_comparison_keys(): array {
		return array(
			'http and https'            => array( 'http://example.com', 'https://example.com', true ),
			'www and apex'              => array( 'https://www.example.com', 'https://example.com', true ),
			'www, http, slash and case' => array( 'HTTP://WWW.Example.com/Shop/', 'https://example.com/shop', true ),
			'path case'                 => array( 'https://example.com/Shop', 'https://example.com/shop', true ),
			'duplicate slashes'         => array( 'https://example.com//shop', 'https://example.com/shop', true ),
			'encoded path'              => array( 'https://example.com/%73hop', 'https://example.com/shop', true ),
			'default ports'             => array( 'http://example.com:80', 'https://example.com:443', true ),
			'other subdomain'           => array( 'https://shop.example.com', 'https://example.com', false ),
			'other path'                => array( 'https://example.com/a', 'https://example.com/b', false ),
			'other port'                => array( 'https://example.com:8443', 'https://example.com', false ),
			'www is the whole host'     => array( 'https://www.com', 'https://com', false ),
			'wwwshop is not www.'       => array( 'https://wwwshop.example.com', 'https://shop.example.com', false ),
		);
	}

	/**
	 * @dataProvider data_comparison_keys
	 *
	 * @param string $first  First spelling.
	 * @param string $second Second spelling.
	 * @param bool   $same   Whether both are the same site.
	 */
	public function test_comparison_key_ignores_scheme_www_and_path_case( string $first, string $second, bool $same ): void {
		$this->assertNotSame( '', SiteUrl::comparison_key( $first ) );
		$this->assertSame( $same, SiteUrl::comparison_key( $first ) === SiteUrl::comparison_key( $second ) );
	}

	public function test_comparison_key_of_an_invalid_url_is_empty(): void {
		$this->assertSame( '', SiteUrl::comparison_key( 'ftp://example.com' ) );
		$this->assertSame( 'example.com/shop', SiteUrl::comparison_key( 'https://www.example.com/Shop/' ) );
		$this->assertSame( '[::1]:8888', SiteUrl::comparison_key( 'http://[::1]:8888' ) );
	}

	/**
	 * B-162: hosts that can only be a development machine.
	 *
	 * @return array<string,array{0:string,1:bool}>
	 */
	public function data_local_hosts(): array {
		return array(
			'localhost'          => array( 'localhost', true ),
			'sub.localhost'      => array( 'shop.localhost', true ),
			'.test'              => array( 'shop.test', true ),
			'.local'             => array( 'shop.local', true ),
			'.internal'          => array( 'shop.internal', true ),
			'ddev'               => array( 'shop.ddev.site', true ),
			'lando'              => array( 'shop.lndo.site', true ),
			'single label'       => array( 'intranet', true ),
			'loopback'           => array( '127.0.0.1', true ),
			'loopback range'     => array( '127.8.9.10', true ),
			'10/8'               => array( '10.1.2.3', true ),
			'172.16/12'          => array( '172.20.0.5', true ),
			'192.168/16'         => array( '192.168.1.10', true ),
			'link local'         => array( '169.254.10.10', true ),
			'ipv6 loopback'      => array( '::1', true ),
			'ipv6 unique local'  => array( 'fd12:3456::1', true ),
			'ipv6 link local'    => array( 'fe80::1', true ),
			'mapped private'     => array( '::ffff:192.168.1.1', true ),
			'public ipv4'        => array( '93.184.216.34', false ),
			'172.32 is public'   => array( '172.32.0.1', false ),
			'public ipv6'        => array( '2001:4860:4860::8888', false ),
			'public domain'      => array( 'example.com', false ),
			'local in the name'  => array( 'local.example.com', false ),
			'test as a label'    => array( 'test.example.com', false ),
			'ddev look-alike'    => array( 'ddev.site.example.com', false ),
			'empty'              => array( '', false ),
		);
	}

	/**
	 * @dataProvider data_local_hosts
	 *
	 * @param string $host  Host.
	 * @param bool   $local Expected result.
	 */
	public function test_local_hosts( string $host, bool $local ): void {
		$this->assertSame( $local, SiteUrl::is_local_host( $host ) );
	}

	/** B-157: registrable domain without a public-suffix list. */
	public function test_registrable_domain(): void {
		$this->assertSame( 'example.com', SiteUrl::registrable_domain( 'staging.shop.example.com' ) );
		$this->assertSame( 'example.com', SiteUrl::registrable_domain( 'EXAMPLE.com.' ) );
		$this->assertSame( 'example.co.uk', SiteUrl::registrable_domain( 'shop.example.co.uk' ) );
		$this->assertSame( 'example.com.au', SiteUrl::registrable_domain( 'www.example.com.au' ) );
		$this->assertSame( 'co.uk', SiteUrl::registrable_domain( 'co.uk' ) );
		$this->assertSame( 'localhost', SiteUrl::registrable_domain( 'localhost' ) );
		$this->assertSame( '192.168.1.1', SiteUrl::registrable_domain( '192.168.1.1' ) );
	}

	/**
	 * B-157: which hosts are accepted as the staging copy of a live site.
	 *
	 * @return array<string,array{0:string,1:string,2:bool}>
	 */
	public function data_staging_hosts(): array {
		return array(
			'subdomain of the live site'   => array( 'https://staging.example.com', 'https://www.example.com', true ),
			'other subdomain, same domain' => array( 'https://new.example.com', 'https://shop.example.com', true ),
			'subdirectory of the live site' => array( 'https://example.com/staging', 'https://example.com', true ),
			'same country-code domain'     => array( 'https://stage.example.co.uk', 'https://example.co.uk', true ),
			'local copy'                   => array( 'http://shop.local', 'https://example.com', true ),
			'private address'              => array( 'http://192.168.1.20', 'https://example.com', true ),
			'staging label elsewhere'      => array( 'https://staging.agency.example', 'https://example.com', true ),
			'dev label elsewhere'          => array( 'https://shop.dev.agency.example', 'https://example.com', true ),
			'hosting staging domain'       => array( 'https://myshop.wpengine.com', 'https://example.com', true ),
			'kinsta'                       => array( 'https://stg-myshop.kinsta.cloud', 'https://example.com', true ),
			'second live shop'             => array( 'https://other-shop.com', 'https://example.com', false ),
			'second shop, same tld'        => array( 'https://example.org', 'https://example.com', false ),
			'label only contains staging'  => array( 'https://stagingshop.com', 'https://example.com', false ),
			'sibling under co.uk'          => array( 'https://other.co.uk', 'https://example.co.uk', false ),
			'public address'               => array( 'http://93.184.216.34', 'https://example.com', false ),
		);
	}

	/**
	 * @dataProvider data_staging_hosts
	 *
	 * @param string $staging Staging URL.
	 * @param string $live    Live URL.
	 * @param bool   $allowed Expected result.
	 */
	public function test_staging_host_rule( string $staging, string $live, bool $allowed ): void {
		$this->assertSame( $allowed, SiteUrl::is_staging_of( $staging, $live ) );
	}
}
