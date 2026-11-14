<?php
use PHPUnit\Framework\TestCase;
use WPLC\License\LicenseKey;

final class LicenseKeyTest extends TestCase {
	/**
	 * B-173: keys as customers paste them.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function data_pasted_keys(): array {
		$key = 'WPLC-0B1C2D3E-4F50-4A61-8B72-9C8D7E6F5A4B-' . str_repeat( 'A1', 32 );

		return array(
			'lower case'            => array( strtolower( $key ) ),
			'surrounding spaces'    => array( '  ' . $key . '  ' ),
			'line break at the end' => array( $key . "\r\n" ),
			'wrapped by a mail app' => array( substr( $key, 0, 40 ) . "\n" . substr( $key, 40 ) ),
			'non-breaking space'    => array( "\u{00A0}" . $key ),
			'zero-width space'      => array( $key . "\u{200B}" ),
			'en dashes'             => array( str_replace( '-', "\u{2013}", $key ) ),
			'em dashes'             => array( str_replace( '-', "\u{2014}", $key ) ),
			'non-breaking hyphens'  => array( str_replace( '-', "\u{2011}", $key ) ),
			'minus signs'           => array( str_replace( '-', "\u{2212}", $key ) ),
			'quoted'                => array( '"' . $key . '"' ),
			'sentence end'          => array( $key . '.' ),
			'spaces in groups'      => array( implode( ' ', str_split( $key, 10 ) ) ),
		);
	}

	/**
	 * @dataProvider data_pasted_keys
	 *
	 * @param string $pasted Key as entered.
	 */
	public function test_pasted_keys_normalize_to_the_issued_key( string $pasted ): void {
		$key = 'WPLC-0B1C2D3E-4F50-4A61-8B72-9C8D7E6F5A4B-' . str_repeat( 'A1', 32 );

		$this->assertSame( $key, LicenseKey::normalize( $pasted ) );
		$this->assertSame( LicenseKey::hash( $key ), LicenseKey::hash( $pasted ) );
	}

	public function test_normalize_keeps_only_key_characters(): void {
		$this->assertSame( '', LicenseKey::normalize( " \t\n" ) );
		$this->assertSame( 'WPLC-AB12', LicenseKey::normalize( "wplc-ab12\xff\xfe" ) );
		$this->assertSame( 'A-B', LicenseKey::normalize( 'a_-b!' ) );
	}

	public function test_license_key_is_deterministic_and_uses_expected_format(): void {
		$uuid = '12345678-1234-4234-8234-123456789abc';
		$key = LicenseKey::for_uuid( $uuid );

		$this->assertSame( $key, LicenseKey::for_uuid( $uuid ) );
		$this->assertMatchesRegularExpression( '/^WPLC-[0-9A-F-]+-[0-9A-F]{64}$/', $key );
	}

	public function test_hash_normalizes_whitespace_and_case(): void {
		$key = LicenseKey::for_uuid( '12345678-1234-4234-8234-123456789abc' );

		$this->assertSame( LicenseKey::hash( $key ), LicenseKey::hash( '  ' . strtolower( $key ) . '  ' ) );
	}

	public function test_prefix_contains_only_non_secret_uuid_prefix(): void {
		$prefix = LicenseKey::prefix( '12345678-1234-4234-8234-123456789abc' );

		$this->assertSame( 'WPLC-12345678', $prefix );
		$this->assertStringNotContainsString( '123456789ABC', $prefix );
	}
}
