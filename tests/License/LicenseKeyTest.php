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
		$this->assertSame( $key, LicenseKey::for_uuid( strtoupper( $uuid ) ) );
		$this->assertMatchesRegularExpression( '/^WPLC-(?:[0-9A-HJKMNP-TV-Z]{5}-){4}[0-9A-HJKMNP-TV-Z]{5}$/D', $key );
		$this->assertSame( 34, strlen( $key ) );
		$this->assertTrue( LicenseKey::is_well_formed( $key ) );
	}

	public function test_legacy_format_is_still_derivable(): void {
		$uuid = '12345678-1234-4234-8234-123456789abc';
		$key  = LicenseKey::for_uuid( $uuid, null, LicenseKey::FORMAT_LONG );

		$this->assertMatchesRegularExpression( '/^WPLC-12345678-1234-4234-8234-123456789ABC-[0-9A-F]{64}$/D', $key );
		$this->assertSame( 106, strlen( $key ) );
		$this->assertTrue( LicenseKey::is_well_formed( $key ) );
		$this->assertNotSame( LicenseKey::hash( $key ), LicenseKey::hash( LicenseKey::for_uuid( $uuid ) ) );
	}

	/**
	 * T-106: the 25 characters carry 125 bits; every one of the 32 symbols turns up, each bit
	 * position flips about half the time, and the key differs from the UUID it is made of.
	 */
	public function test_short_keys_are_spread_over_the_whole_alphabet(): void {
		$alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
		$counts   = array_fill_keys( str_split( $alphabet ), 0 );
		$ones     = array_fill( 0, 125, 0 );
		$keys     = array();
		$samples  = 2000;
		for ( $i = 0; $i < $samples; $i++ ) {
			$uuid = \WPLC\License\LicenseIssuer::new_uuid();
			$key  = LicenseKey::for_uuid( $uuid );
			$body = str_replace( '-', '', substr( $key, 5 ) );
			$this->assertSame( 25, strlen( $body ) );
			$keys[ $key ] = true;
			foreach ( str_split( $body ) as $position => $char ) {
				++$counts[ $char ];
				$value = strpos( $alphabet, $char );
				for ( $bit = 0; $bit < 5; $bit++ ) {
					$ones[ $position * 5 + $bit ] += ( $value >> ( 4 - $bit ) ) & 1;
				}
			}
		}
		$this->assertCount( $samples, $keys, 'no two keys alike' );
		$expected = $samples * 25 / 32;
		foreach ( $counts as $char => $count ) {
			$this->assertGreaterThan( $expected * 0.75, $count, 'symbol ' . $char );
			$this->assertLessThan( $expected * 1.25, $count, 'symbol ' . $char );
		}
		foreach ( $ones as $position => $count ) {
			$this->assertGreaterThan( $samples * 0.4, $count, 'bit ' . $position );
			$this->assertLessThan( $samples * 0.6, $count, 'bit ' . $position );
		}
	}

	/**
	 * T-106: short keys as customers type or paste them.
	 *
	 * @return array<string,array{0:callable(string):string}>
	 */
	public function data_typed_short_keys(): array {
		return array(
			'lower case'            => array( static fn ( string $k ): string => strtolower( $k ) ),
			'no hyphens'            => array( static fn ( string $k ): string => str_replace( '-', '', $k ) ),
			'spaces between groups' => array( static fn ( string $k ): string => str_replace( '-', ' ', $k ) ),
			'misplaced hyphens'     => array( static fn ( string $k ): string => implode( '-', str_split( str_replace( '-', '', $k ), 3 ) ) ),
			'en dashes'             => array( static fn ( string $k ): string => str_replace( '-', "\u{2013}", $k ) ),
			'O for 0, I and L for 1' => array( static fn ( string $k ): string => substr( $k, 0, 5 ) . strtr( substr( $k, 5 ), array( '0' => 'O', '1' => 'I' ) ) ),
			'l for 1, o for 0'      => array( static fn ( string $k ): string => substr( $k, 0, 5 ) . strtr( strtolower( substr( $k, 5 ) ), array( '0' => 'o', '1' => 'l' ) ) ),
			'quoted, line break'    => array( static fn ( string $k ): string => '"' . substr( $k, 0, 17 ) . "\r\n" . substr( $k, 17 ) . '".' ),
		);
	}

	/**
	 * @dataProvider data_typed_short_keys
	 *
	 * @param callable(string):string $mangle Turns the issued key into the typed one.
	 */
	public function test_typed_short_keys_normalize_to_the_issued_key( callable $mangle ): void {
		// Fixed key with 0, 1 and every group, so the mappings are exercised.
		$key = 'WPLC-0A1B2-C3D4E-5F6G7-H8J9K-01MNP';

		$this->assertSame( $key, LicenseKey::normalize( $mangle( $key ) ) );
		$this->assertSame( LicenseKey::hash( $key ), LicenseKey::hash( $mangle( $key ) ) );
	}

	public function test_ambiguous_letters_are_mapped_only_in_short_keys(): void {
		// The prefix keeps its letters (WPLC contains an L); a legacy key is never mapped.
		$this->assertSame( 'WPLC-00000-11111-00000-11111-00000', LicenseKey::normalize( 'WPLC-OOOOO-IIIII-ooooo-lllll-00000' ) );
		$legacy = 'WPLC-0B1C2D3E-4F50-4A61-8B72-9C8D7E6F5A4B-' . str_repeat( 'A1', 32 );
		$this->assertSame( $legacy, LicenseKey::normalize( $legacy ) );
		// U is no Crockford symbol: such a string is no short key and stays as typed.
		$this->assertSame( 'WPLC-UUUUU-00000-00000-00000-00000', LicenseKey::normalize( 'wplc-uuuuu-00000-00000-00000-00000' ) );
		$this->assertFalse( LicenseKey::is_well_formed( 'WPLC-UUUUU-00000-00000-00000-00000' ) );
		// One character too few or too many: not a short key, nothing is regrouped.
		$this->assertSame( 'WPLC0000011111000001111100', LicenseKey::normalize( 'WPLC0000011111000001111100' ) );
		$this->assertFalse( LicenseKey::is_well_formed( 'WPLC-00000-11111-00000-11111-0000' ) );
	}

	public function test_well_formed_accepts_both_formats_only(): void {
		$this->assertTrue( LicenseKey::is_well_formed( 'WPLC-0A1B2-C3D4E-5F6G7-H8J9K-01MNP' ) );
		$this->assertTrue( LicenseKey::is_well_formed( 'WPLC-0B1C2D3E-4F50-4A61-8B72-9C8D7E6F5A4B-' . str_repeat( 'A1', 32 ) ) );
		$this->assertFalse( LicenseKey::is_well_formed( 'WPLC-0B1C2D3E' ) );
		$this->assertFalse( LicenseKey::is_well_formed( 'ACME-0A1B2-C3D4E-5F6G7-H8J9K-01MNP' ) );
		$this->assertFalse( LicenseKey::is_well_formed( 'wplc-0a1b2-c3d4e-5f6g7-h8j9k-01mnp' ), 'expects normalized input' );
	}

	public function test_hash_normalizes_whitespace_and_case(): void {
		$key = LicenseKey::for_uuid( '12345678-1234-4234-8234-123456789abc' );

		$this->assertSame( LicenseKey::hash( $key ), LicenseKey::hash( '  ' . strtolower( $key ) . '  ' ) );
	}

	public function test_stored_verifier_selects_the_format(): void {
		$uuid  = '12345678-1234-4234-8234-123456789abc';
		$short = LicenseKey::for_uuid( $uuid );
		$long  = LicenseKey::for_uuid( $uuid, null, LicenseKey::FORMAT_LONG );
		$id    = LicenseKey::current_key_id();

		$this->assertSame( LicenseKey::FORMAT_SHORT, LicenseKey::stored_format( $uuid, $id, LicenseKey::hash( $short ) ) );
		$this->assertSame( LicenseKey::FORMAT_LONG, LicenseKey::stored_format( $uuid, $id, LicenseKey::hash( $long ) ) );
		$this->assertSame( $short, LicenseKey::verified_for_license( $uuid, $id, LicenseKey::hash( $short ) ) );
		$this->assertSame( $long, LicenseKey::verified_for_license( $uuid, $id, strtoupper( LicenseKey::hash( $long ) ) ) );
		$this->assertTrue( LicenseKey::matches_hash( $uuid, $id, LicenseKey::hash( $long ) ) );
		$this->assertSame( '', LicenseKey::verified_for_license( $uuid, $id, hash( 'sha256', 'other' ) ) );
		$this->assertSame( '', LicenseKey::verified_for_license( $uuid, 'unknown-id', LicenseKey::hash( $short ) ) );
		$this->assertSame( '', LicenseKey::stored_format( $uuid, $id, 'not-a-hash' ) );
	}

	public function test_prefix_contains_only_non_secret_uuid_prefix(): void {
		$prefix = LicenseKey::prefix( '12345678-1234-4234-8234-123456789abc' );

		$this->assertSame( 'WPLC-12345678', $prefix );
		$this->assertStringNotContainsString( '123456789ABC', $prefix );
	}
}
