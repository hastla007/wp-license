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
		$this->assertSame( '', SiteUrl::normalize( 'https://example.com\\\\admin' ) );
	}
}
