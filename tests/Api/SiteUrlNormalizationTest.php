<?php
/**
 * T-87: pins down the current behavior of SiteUrl::normalize().
 *
 * The normalized URL is the identity of an activation, so every rule here
 * decides whether two URLs share one site slot or use two. Rows marked
 * SURPRISING document behavior that is probably unintended; they are listed
 * in docs/QUALITY-GATES.md and must be changed deliberately, with this test.
 *
 * Run a second time without the intl functions to cover the fallback branch:
 * php -d disable_functions=idn_to_ascii vendor/bin/phpunit --filter SiteUrl
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
			'SURPRISING leading dot is dropped'  => array( 'https://.example.com', 'https://example.com' ),

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
			'SURPRISING inner double slash kept' => array( 'https://example.com/a//b/', 'https://example.com/a//b' ),
			'SURPRISING dot segments kept'       => array( 'https://example.com/a/../b', 'https://example.com/a/../b' ),
			'SURPRISING percent-encoding kept'   => array( 'https://example.com/%7Euser/', 'https://example.com/%7Euser' ),
			'SURPRISING space in path accepted'  => array( 'https://example.com/a b', 'https://example.com/a b' ),
			'SURPRISING raw UTF-8 path accepted' => array( 'https://example.com/ünï', 'https://example.com/ünï' ),
			'SURPRISING NUL becomes underscore'  => array( "https://example.com/a\0b", 'https://example.com/a_b' ),
			'SURPRISING no length limit'         => array( 'https://example.com/' . str_repeat( 'p', 300 ), 'https://example.com/' . str_repeat( 'p', 300 ) ),

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
			'SURPRISING ipv6 is not compressed'  => array( 'http://[2001:0db8:0000:0000:0000:0000:0000:0001]', 'http://[2001:0db8:0000:0000:0000:0000:0000:0001]' ),
			'SURPRISING ipv4 leading zeros'      => array( 'http://192.168.001.010', 'http://192.168.001.010' ),
			'SURPRISING hex ipv4 is a hostname'  => array( 'http://0x7f.1', 'http://0x7f.1' ),
			'SURPRISING integer ipv4 hostname'   => array( 'http://2130706433', 'http://2130706433' ),
			'SURPRISING out-of-range ipv4'       => array( 'https://999.1.1.1', 'https://999.1.1.1' ),
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
			'SURPRISING sharp s becomes ss'      => array( 'https://straße.de', 'https://strasse.de' ),
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

		// SURPRISING: without ext-intl the same URL is rejected, so whether an IDN
		// shop can activate depends on a PHP extension of the license server.
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
			'http versus https'                    => array( 'http://example.com', 'https://example.com', false ),
			'www versus apex'                      => array( 'https://www.example.com', 'https://example.com', false ),
			'SURPRISING path case'                 => array( 'https://example.com/Shop', 'https://example.com/shop', false ),
			'SURPRISING ipv6 spelling'             => array( 'http://[2001:db8:0:0:0:0:0:1]', 'http://[2001:db8::1]', false ),
			'SURPRISING percent-encoded path'      => array( 'https://example.com/%7Euser', 'https://example.com/~user', false ),
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
}
