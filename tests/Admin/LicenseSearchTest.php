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

	/**
	 * T-106: both key formats.
	 *
	 * @return array<string,array{0:string,1:int}>
	 */
	public function data_formats(): array {
		return array(
			'short (since T-106)' => array( LicenseKey::FORMAT_SHORT, 34 ),
			'long (before T-106)' => array( LicenseKey::FORMAT_LONG, 106 ),
		);
	}

	/**
	 * @dataProvider data_formats
	 *
	 * @param string $format Key format.
	 * @param int    $length Expected key length.
	 */
	public function test_full_license_key_is_recognized_and_normalized( string $format, int $length ): void {
		$key    = LicenseKey::for_uuid( '0f8fad5b-d9cb-469f-a165-70867728950e', null, $format );
		$result = LicenseSearch::classify( "  " . strtolower( $key ) . "\n" );

		$this->assertSame( $length, strlen( $key ) );
		$this->assertSame( LicenseSearch::TYPE_LICENSE_KEY, $result['type'] );
		$this->assertSame( $key, $result['value'] );
		// The list table matches license_key_hash with exactly this hash.
		$this->assertSame( LicenseKey::hash( $key ), LicenseKey::hash( $result['value'] ) );
	}

	/**
	 * @dataProvider data_formats
	 *
	 * @param string $format Key format.
	 */
	public function test_key_with_whitespace_from_an_email_line_break_still_matches( string $format ): void {
		$key    = LicenseKey::for_uuid( '0f8fad5b-d9cb-469f-a165-70867728950e', null, $format );
		$broken = substr( $key, 0, 20 ) . "\r\n " . substr( $key, 20 );

		$this->assertSame( $key, LicenseSearch::classify( $broken )['value'] );
	}

	public function test_short_key_without_hyphens_or_with_ambiguous_letters_is_a_full_key(): void {
		$key = LicenseKey::for_uuid( '0f8fad5b-d9cb-469f-a165-70867728950e' );

		$this->assertSame( $key, LicenseSearch::classify( str_replace( '-', '', $key ) )['value'] );
		$this->assertSame( $key, LicenseSearch::classify( 'wplc ' . strtr( strtolower( substr( $key, 5 ) ), array( '0' => 'o', '1' => 'l' ) ) )['value'] );
	}

	/**
	 * @dataProvider data_formats
	 *
	 * @param string $format Key format.
	 */
	public function test_prefix_and_truncated_keys_are_not_full_keys( string $format ): void {
		$key = LicenseKey::for_uuid( '0f8fad5b-d9cb-469f-a165-70867728950e', null, $format );

		$this->assertSame( LicenseSearch::TYPE_TEXT, LicenseSearch::classify( 'WPLC-0F8FAD5B' )['type'] );
		$this->assertSame( LicenseSearch::TYPE_TEXT, LicenseSearch::classify( substr( $key, 0, -1 ) )['type'] );
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

	/**
	 * @dataProvider data_formats
	 *
	 * @param string $format Key format.
	 */
	public function test_criteria_keep_a_full_key_only_as_its_hash( string $format ): void {
		$key      = LicenseKey::for_uuid( '0f8fad5b-d9cb-469f-a165-70867728950e', null, $format );
		$criteria = LicenseSearch::criteria( ' ' . strtolower( $key ) . ' ' );

		$this->assertSame(
			array( 'type' => LicenseSearch::TYPE_LICENSE_KEY_HASH, 'value' => LicenseKey::hash( $key ), 'label' => '' ),
			$criteria
		);
		$this->assertStringNotContainsStringIgnoringCase( substr( $key, 14 ), serialize( $criteria ) );
	}

	public function test_criteria_reduce_a_longer_key_fragment_to_the_public_prefix(): void {
		$key = LicenseKey::for_uuid( '0f8fad5b-d9cb-469f-a165-70867728950e', null, LicenseKey::FORMAT_LONG );

		foreach ( array( substr( $key, 0, 105 ), strtolower( substr( $key, 0, 30 ) ), 'WPLC-0F8FAD5B-D9CB' ) as $fragment ) {
			$this->assertSame(
				array( 'type' => LicenseSearch::TYPE_TEXT, 'value' => 'WPLC-0F8FAD5B', 'label' => 'WPLC-0F8FAD5B' ),
				LicenseSearch::criteria( $fragment ),
				$fragment
			);
		}
		$this->assertSame( 'WPLC-0F8F', LicenseSearch::criteria( 'WPLC-0F8F' )['value'] );
	}

	public function test_criteria_keep_a_short_key_fragment_only_as_its_hash(): void {
		$key = LicenseKey::for_uuid( '0f8fad5b-d9cb-469f-a165-70867728950e' );

		foreach ( array( substr( $key, 0, -1 ), substr( $key, 0, 20 ), $key . 'X' ) as $fragment ) {
			$criteria = LicenseSearch::criteria( $fragment );
			$this->assertSame( LicenseSearch::TYPE_LICENSE_KEY_HASH, $criteria['type'], $fragment );
			$this->assertSame( '', $criteria['label'] );
			// No part of the secret body is kept (the reference is not the start of a short key).
			$this->assertStringNotContainsString( substr( $key, 5, 5 ), serialize( $criteria ) );
			$this->assertNotSame( LicenseKey::hash( $key ), $criteria['value'] );
		}
	}

	public function test_criteria_of_other_terms_keep_value_and_label(): void {
		$this->assertSame( array( 'type' => LicenseSearch::TYPE_EMAIL, 'value' => 'jane@example.com', 'label' => 'Jane@Example.com' ), LicenseSearch::criteria( ' Jane@Example.com ' ) );
		$this->assertSame( array( 'type' => LicenseSearch::TYPE_LICENSE_ID, 'value' => '42', 'label' => '#0042' ), LicenseSearch::criteria( '#0042' ) );
		$this->assertSame( array( 'type' => LicenseSearch::TYPE_NUMBER, 'value' => '42', 'label' => '42' ), LicenseSearch::criteria( '42' ) );
		$this->assertSame( array( 'type' => LicenseSearch::TYPE_TEXT, 'value' => 'voucher-captain', 'label' => 'voucher-captain' ), LicenseSearch::criteria( 'voucher-captain' ) );
		$this->assertSame( LicenseSearch::TYPE_NONE, LicenseSearch::criteria( '  ' )['type'] );
	}

	public function test_stored_criteria_are_validated(): void {
		$valid = LicenseSearch::criteria( 'jane@example.com' );
		$this->assertSame( $valid, LicenseSearch::sanitize_criteria( $valid ) );

		$hash = LicenseSearch::criteria( LicenseKey::for_uuid( '0f8fad5b-d9cb-469f-a165-70867728950e' ) );
		$this->assertSame( $hash, LicenseSearch::sanitize_criteria( array_merge( $hash, array( 'label' => 'leak' ) ) ) );

		$invalid = array(
			false,
			'x',
			array(),
			array( 'type' => 'none', 'value' => 'x' ),
			array( 'type' => 'license_key', 'value' => 'WPLC-X' ),
			array( 'type' => 'license_key_hash', 'value' => 'not-a-hash' ),
			array( 'type' => 'text', 'value' => '' ),
			array( 'type' => 'text', 'value' => 5 ),
		);
		foreach ( $invalid as $candidate ) {
			$this->assertNull( LicenseSearch::sanitize_criteria( $candidate ), var_export( $candidate, true ) );
		}
	}
}
