<?php
/**
 * T-87/T-146: pins down the rules of SiteUrl::normalize() (docs/API-V1.md, "site_url rules").
 *
 * The normalized URL is the identity of an activation, so every rule here
 * decides whether two URLs share one site slot or use two. Rows marked
 * SURPRISING document remaining behavior that is probably unintended; they are
 * listed in docs/QUALITY-GATES.md and must be changed deliberately, with this test.
 *
 * The IDN fallback (no ext-intl) is tested directly through normalize_with().
 * Run a second time without the intl functions to cover normalize() as well:
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

			// Paths: duplicate slashes collapsed, dot segments resolved, trailing slashes removed.
			'subdirectory'                       => array( 'https://example.com/shop/', 'https://example.com/shop' ),
			'nested subdirectory'                => array( 'https://example.com/a/b/', 'https://example.com/a/b' ),
			'double slash root'                  => array( 'https://example.com//', 'https://example.com' ),
			'path case is preserved'             => array( 'https://example.com/Shop', 'https://example.com/Shop' ),
			'inner double slash collapsed'       => array( 'https://example.com/a//b/', 'https://example.com/a/b' ),
			'leading double slash collapsed'     => array( 'https://example.com//shop', 'https://example.com/shop' ),
			'dot-dot segment resolved'           => array( 'https://example.com/a/../b', 'https://example.com/b' ),
			'dot segment removed'                => array( 'https://example.com/./a/./b/.', 'https://example.com/a/b' ),
			'dot-dot above the root'             => array( 'https://example.com/../../a', 'https://example.com/a' ),
			'dot-dot back to the root'           => array( 'https://example.com/a/..', 'https://example.com' ),
			'percent-encoded dot segments'       => array( 'https://example.com/a/%2E%2e/b/%2e/', 'https://example.com/b' ),
			'dots inside a segment are kept'     => array( 'https://example.com/a..b/.c/...', 'https://example.com/a..b/.c/...' ),
			'SURPRISING percent-encoding kept'   => array( 'https://example.com/%7Euser/', 'https://example.com/%7Euser' ),
			'SURPRISING space in path accepted'  => array( 'https://example.com/a b', 'https://example.com/a b' ),
			'SURPRISING raw UTF-8 path accepted' => array( 'https://example.com/ünï', 'https://example.com/ünï' ),
			'SURPRISING NUL becomes underscore'  => array( "https://example.com/a\0b", 'https://example.com/a_b' ),
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
			'ipv6 longest zero run compressed'   => array( 'http://[2001:db8:0:0:1:0:0:1]', 'http://[2001:db8::1:0:0:1]' ),
			'host with digits but a name label'  => array( 'https://123.example', 'https://123.example' ),
			'numeric label not last'             => array( 'https://1.2.3.example', 'https://1.2.3.example' ),
			'hex-like last label with letters'   => array( 'https://shop.0xzz', 'https://shop.0xzz' ),
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
			'hyphens in position 3 and 4'    => array( 'https://ab--cd.example' ),
			'fake punycode prefix'           => array( 'https://xy--bcher.example' ),

			// Non-canonical IPv4 spellings (the last label is a number).
			'ipv4 leading zeros'             => array( 'http://192.168.001.010' ),
			'ipv4 octal octet'               => array( 'http://0177.0.0.1' ),
			'hex ipv4'                       => array( 'http://0x7f.1' ),
			'hex ipv4 full'                  => array( 'http://0x7f000001' ),
			'bare hex prefix'                => array( 'http://example.0x' ),
			'integer ipv4'                   => array( 'http://2130706433' ),
			'short ipv4'                     => array( 'http://127.1' ),
			'three-part ipv4'                => array( 'http://10.0.1' ),
			'out-of-range ipv4'              => array( 'https://999.1.1.1' ),
			'numeric last label'             => array( 'https://example.123' ),
			'ipv4 with trailing dot'         => array( 'http://192.168.001.010.' ),
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
	 * Internationalized hosts: [ input, result with and without ext-intl ].
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function data_idn(): array {
		return array(
			'umlaut'                       => array( 'https://münchen.de', 'https://xn--mnchen-3ya.de' ),
			'uppercase umlaut and path'    => array( 'https://MÜNCHEN.de/Shop/', 'https://xn--mnchen-3ya.de/Shop' ),
			'japanese'                     => array( 'https://例え.jp', 'https://xn--r8jz45g.jp' ),
			'idn with port'                => array( 'https://münchen.de:8443', 'https://xn--mnchen-3ya.de:8443' ),
			// UTS #46 transitional processing maps "ß" to "ss". Browsers (IDNA2008,
			// non-transitional) resolve straße.de to xn--strae-oqa.de, a different domain.
			'sharp s keeps its own domain' => array( 'https://straße.de', 'https://xn--strae-oqa.de' ),
			'greek capital sigma'          => array( 'https://ΣΟΦΊΑΣ.gr', 'https://xn--kxae4bpdp.gr' ),
			'greek final sigma kept'       => array( 'https://σοφίας.gr', 'https://xn--kxae4bobt.gr' ),
			'cyrillic'                     => array( 'https://ПРИМЕР.рф', 'https://xn--e1afmkfd.xn--p1ai' ),
			'vietnamese'                   => array( 'https://tiếngviệt.vn', 'https://xn--tingvit-5t4cyc.vn' ),
			'korean'                       => array( 'https://한국.kr', 'https://xn--3e0b707e.kr' ),
			'mixed ascii and umlaut label' => array( 'https://Shop-Bücher.example', 'https://xn--shop-bcher-feb.example' ),
		);
	}

	/**
	 * @dataProvider data_idn
	 *
	 * @param string $input    Input URL.
	 * @param string $expected Normalized URL.
	 */
	public function test_idn_hosts_are_the_same_with_and_without_intl( string $input, string $expected ): void {
		$this->assertSame( $expected, SiteUrl::normalize_with( $input, false ), 'fallback' );
		$this->assertSame( $expected, SiteUrl::normalize( $input ) );
		if ( function_exists( 'idn_to_ascii' ) ) {
			$this->assertSame( $expected, SiteUrl::normalize_with( $input, true ), 'intl' );
		}
	}

	/**
	 * Hosts the fallback rejects because it cannot map them exactly like UTS #46.
	 * With ext-intl some of them are accepted; they are never stored differently.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function data_idn_fallback_rejects(): array {
		return array(
			'decomposed umlaut'     => array( "https://mu\u{0308}nchen.de" ),
			'ligature'              => array( 'https://ﬁsh.de' ),
			'fullwidth letters'     => array( 'https://ｅｘａｍｐｌｅ.de' ),
			'ideographic full stop' => array( 'https://例え。jp' ),
			'dotted capital i'      => array( 'https://İstanbul.tr' ),
			'ij digraph'            => array( 'https://ĳssel.nl' ),
			'hebrew (bidi rules)'   => array( 'https://שלום.example' ),
			'symbol'                => array( 'https://♥.example' ),
			'leading hyphen'        => array( 'https://-münchen.de' ),
			'hyphens in 3 and 4'    => array( 'https://mü--nchen.de' ),
			'invalid utf-8'         => array( "https://m\xfcnchen.de" ),
		);
	}

	/**
	 * @dataProvider data_idn_fallback_rejects
	 *
	 * @param string $input Input URL.
	 */
	public function test_idn_fallback_rejects_what_it_cannot_map_exactly( string $input ): void {
		$this->assertSame( '', SiteUrl::normalize_with( $input, false ) );
	}

	/**
	 * The fallback either rejects a label or produces exactly the intl result.
	 * Checks every code point of the Basic Multilingual Plane, alone and between
	 * ASCII letters, plus random multi-character labels.
	 */
	public function test_idn_fallback_never_differs_from_intl(): void {
		if ( ! function_exists( 'idn_to_ascii' ) ) {
			$this->markTestSkipped( 'Needs ext-intl to compare with.' );
		}
		$accepted = array();
		$mismatches = array();
		for ( $cp = 0x80; $cp <= 0xFFFF; $cp++ ) {
			if ( $cp >= 0xD800 && $cp <= 0xDFFF ) {
				continue;
			}
			$char = mb_chr( $cp, 'UTF-8' );
			foreach ( array( $char, 'a' . $char . 'b' ) as $label ) {
				$fallback = SiteUrl::fallback_to_ascii( $label . '.de' );
				if ( '' === $fallback ) {
					continue;
				}
				$accepted[ $char ] = $char;
				$intl = idn_to_ascii( $label . '.de', IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46 );
				if ( false === $intl || strtolower( $intl ) !== $fallback ) {
					$mismatches[ $cp ] = sprintf( 'U+%04X', $cp );
				}
			}
		}
		$this->assertSame( array(), array_values( $mismatches ) );

		$accepted = array_values( $accepted );
		mt_srand( 146 );
		for ( $i = 0; $i < 3000; $i++ ) {
			$label = '';
			$length = mt_rand( 1, 12 );
			for ( $j = 0; $j < $length; $j++ ) {
				$label .= mt_rand( 0, 3 ) ? $accepted[ mt_rand( 0, count( $accepted ) - 1 ) ] : chr( mt_rand( 97, 122 ) );
			}
			$fallback = SiteUrl::fallback_to_ascii( $label . '.de' );
			if ( '' === $fallback ) {
				continue;
			}
			$intl = idn_to_ascii( $label . '.de', IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46 );
			$this->assertSame( strtolower( (string) $intl ), $fallback, bin2hex( $label ) );
		}
	}

	public function test_unicode_and_punycode_spellings_share_one_identity(): void {
		$this->assertSame( SiteUrl::normalize( 'https://xn--mnchen-3ya.de' ), SiteUrl::normalize( 'https://MÜNCHEN.de' ) );
		$this->assertSame( 'https://xn--mnchen-3ya.de', SiteUrl::normalize_with( 'https://MÜNCHEN.de', false ) );
	}

	/**
	 * Old spellings that an activation stored before T-146 may still hold.
	 *
	 * @return array<string,array{0:string,1:string[]}>
	 */
	public function data_identity_candidates(): array {
		return array(
			'unchanged url'     => array( 'https://example.com/shop/', array( 'https://example.com/shop' ) ),
			'double slash'      => array( 'https://example.com/a//b', array( 'https://example.com/a/b', 'https://example.com/a//b' ) ),
			'dot segments'      => array( 'https://example.com/a/../b', array( 'https://example.com/b', 'https://example.com/a/../b' ) ),
			'uncompressed ipv6' => array( 'http://[2001:0db8:0:0:0:0:0:1]', array( 'http://[2001:db8::1]', 'http://[2001:0db8:0:0:0:0:0:1]' ) ),
			'now rejected host' => array( 'http://0x7f.1', array() ),
		);
	}

	/**
	 * @dataProvider data_identity_candidates
	 *
	 * @param string   $input    Input URL.
	 * @param string[] $expected Candidates.
	 */
	public function test_identity_candidates_include_the_legacy_spelling( string $input, array $expected ): void {
		$this->assertSame( $expected, SiteUrl::identity_candidates( $input ) );
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
			'ipv6 spelling'                        => array( 'http://[2001:db8:0:0:0:0:0:1]', 'http://[2001:db8::1]', true ),
			'duplicate slash in path'              => array( 'https://example.com/a//shop', 'https://example.com/a/shop', true ),
			'dot segments in path'                 => array( 'https://example.com/a/./b/../shop', 'https://example.com/a/shop', true ),
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
