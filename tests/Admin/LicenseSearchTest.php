<?php
/**
 * Search-term classification of the admin license list.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\Admin\LicenseSearch;
use WPLC\License\LicenseKey;

final class LicenseSearchTest extends TestCase {

	public function test_empty_term_searches_nothing(): void {
		$this->assertSame( LicenseSearch::TYPE_NONE, LicenseSearch::classify( '   ' )['type'] );
	}

	public function test_full_license_key_is_recognized_and_normalized(): void {
		$key    = LicenseKey::for_uuid( '0f8fad5b-d9cb-469f-a165-70867728950e' );
		$result = LicenseSearch::classify( "  " . strtolower( $key ) . "\n" );

		$this->assertSame( 106, strlen( $key ) );
		$this->assertSame( LicenseSearch::TYPE_LICENSE_KEY, $result['type'] );
		$this->assertSame( $key, $result['value'] );
		// The list table matches license_key_hash with exactly this hash.
		$this->assertSame( LicenseKey::hash( $key ), LicenseKey::hash( $result['value'] ) );
	}

	public function test_key_with_whitespace_from_an_email_line_break_still_matches(): void {
		$key    = LicenseKey::for_uuid( '0f8fad5b-d9cb-469f-a165-70867728950e' );
		$broken = substr( $key, 0, 50 ) . "\r\n " . substr( $key, 50 );

		$this->assertSame( $key, LicenseSearch::classify( $broken )['value'] );
	}

	public function test_prefix_and_truncated_keys_are_plain_text(): void {
		$key = LicenseKey::for_uuid( '0f8fad5b-d9cb-469f-a165-70867728950e' );

		$this->assertSame( LicenseSearch::TYPE_TEXT, LicenseSearch::classify( 'WPLC-0F8FAD5B' )['type'] );
		$this->assertSame( LicenseSearch::TYPE_TEXT, LicenseSearch::classify( substr( $key, 0, 105 ) )['type'] );
		$this->assertFalse( LicenseSearch::is_full_license_key( $key . 'A' ) );
	}

	public function test_numbers_and_license_ids(): void {
		$this->assertSame( array( 'type' => LicenseSearch::TYPE_NUMBER, 'value' => '42' ), LicenseSearch::classify( '42' ) );
		$this->assertSame( array( 'type' => LicenseSearch::TYPE_LICENSE_ID, 'value' => '42' ), LicenseSearch::classify( '#0042' ) );
		$this->assertSame( LicenseSearch::TYPE_TEXT, LicenseSearch::classify( '#abc' )['type'] );
	}

	public function test_email_addresses_and_fragments(): void {
		$this->assertSame( array( 'type' => LicenseSearch::TYPE_EMAIL, 'value' => 'jane@example.com' ), LicenseSearch::classify( 'Jane@Example.com' ) );
		$this->assertSame( LicenseSearch::TYPE_EMAIL, LicenseSearch::classify( '@example.com' )['type'] );
	}

	public function test_other_text_is_a_text_search(): void {
		$this->assertSame( array( 'type' => LicenseSearch::TYPE_TEXT, 'value' => 'voucher-captain' ), LicenseSearch::classify( ' voucher-captain ' ) );
	}
}
