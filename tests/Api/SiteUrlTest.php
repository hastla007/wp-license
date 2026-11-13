<?php
use PHPUnit\Framework\TestCase;
use WPLC\Api\V1\SiteUrl;

final class SiteUrlTest extends TestCase {
	public function test_normalizes_scheme_host_default_port_and_trailing_path_slash(): void {
		$this->assertSame( 'https://example.com/shop', SiteUrl::normalize( 'HTTPS://Example.COM.:443/shop/' ) );
	}

	public function test_preserves_non_default_port_and_brackets_ipv6(): void {
		$this->assertSame( 'http://[2001:db8::1]:8080/wp', SiteUrl::normalize( 'http://[2001:db8::1]:8080/wp/' ) );
	}

	public function test_rejects_credentials_query_fragment_and_non_http_schemes(): void {
		$this->assertSame( '', SiteUrl::normalize( 'https://user:pass@example.com' ) );
		$this->assertSame( '', SiteUrl::normalize( 'https://example.com/?key=value' ) );
		$this->assertSame( '', SiteUrl::normalize( 'https://example.com/#fragment' ) );
		$this->assertSame( '', SiteUrl::normalize( 'ftp://example.com' ) );
		$this->assertSame( '', SiteUrl::normalize( 'https://example.com:0' ) );
		$this->assertSame( '', SiteUrl::normalize( 'https://example.com\\admin' ) );
	}

	public function test_same_site_ignores_scheme_www_case_and_trailing_slash(): void {
		$this->assertTrue( SiteUrl::same_site( 'https://shop.example', 'http://WWW.shop.example/' ) );
		$this->assertTrue( SiteUrl::same_site( 'https://www.shop.example/de', 'https://shop.example:443/de/' ) );
	}

	public function test_same_site_requires_host_port_and_path(): void {
		$this->assertFalse( SiteUrl::same_site( 'https://shop.example', 'https://copy.example' ) );
		$this->assertFalse( SiteUrl::same_site( 'https://shop.example', 'https://staging.shop.example' ) );
		$this->assertFalse( SiteUrl::same_site( 'https://shop.example', 'https://shop.example:8443' ) );
		$this->assertFalse( SiteUrl::same_site( 'https://shop.example/de', 'https://shop.example/at' ) );
		$this->assertFalse( SiteUrl::same_site( 'https://shop.example', 'not a url' ) );
		$this->assertFalse( SiteUrl::same_site( '', '' ) );
	}

	public function test_requests_without_site_url_are_not_checked(): void {
		$this->assertTrue( \WPLC\Api\V1\Authenticator::check_site( 'https://shop.example', null ) );
		$this->assertTrue( \WPLC\Api\V1\Authenticator::check_site( 'https://shop.example', '' ) );
		$this->assertTrue( \WPLC\Api\V1\Authenticator::check_site( 'https://shop.example', 'https://www.shop.example/' ) );
	}
}
