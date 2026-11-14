<?php
/**
 * T-141: the admin license search is kept per user behind an opaque token.
 *
 * @package WPLC
 */

use PHPUnit\Framework\TestCase;
use WPLC\Admin\LicenseSearch;
use WPLC\Admin\LicenseSearchStore;
use WPLC\License\LicenseKey;

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $name, $value, $expiration = 0 ) {
		$GLOBALS['wplc_test_transients'][ $name ] = array( $value, $expiration );
		return true;
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $name ) {
		return isset( $GLOBALS['wplc_test_transients'][ $name ] ) ? $GLOBALS['wplc_test_transients'][ $name ][0] : false;
	}
}

final class LicenseSearchStoreTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['wplc_test_transients'] = array();
		LicenseSearchStore::reset_cache();
	}

	public function test_a_full_key_is_stored_only_as_its_hash_behind_a_random_token(): void {
		$key   = LicenseKey::for_uuid( '0f8fad5b-d9cb-469f-a165-70867728950e' );
		$token = LicenseSearchStore::save( 7, $key );

		$this->assertTrue( LicenseSearchStore::is_token( $token ) );
		$this->assertStringNotContainsStringIgnoringCase( $token, $key );
		$stored = $GLOBALS['wplc_test_transients'][ LicenseSearchStore::transient_name( 7, $token ) ];
		$this->assertSame( LicenseSearchStore::TTL, $stored[1] );
		$this->assertSame( LicenseKey::hash( $key ), $stored[0]['value'] );
		$this->assertStringNotContainsString( substr( $key, 14 ), serialize( $GLOBALS['wplc_test_transients'] ) );

		LicenseSearchStore::reset_cache();
		$this->assertSame( LicenseSearch::TYPE_LICENSE_KEY_HASH, LicenseSearchStore::load( 7, $token )['type'] );
	}

	public function test_tokens_are_unique_and_bound_to_their_user(): void {
		$first  = LicenseSearchStore::save( 7, 'jane@example.com' );
		$second = LicenseSearchStore::save( 7, 'jane@example.com' );
		LicenseSearchStore::reset_cache();

		$this->assertNotSame( $first, $second );
		$this->assertSame( 'jane@example.com', LicenseSearchStore::load( 7, $first )['value'] );
		$this->assertNull( LicenseSearchStore::load( 8, $first ) );
		$this->assertNull( LicenseSearchStore::load( 0, $first ) );
	}

	public function test_unknown_malformed_and_tampered_tokens_load_nothing(): void {
		$this->assertNull( LicenseSearchStore::load( 7, str_repeat( 'a', 24 ) ) );
		$this->assertNull( LicenseSearchStore::load( 7, '../../etc' ) );
		$this->assertNull( LicenseSearchStore::load( 7, '' ) );

		$token = str_repeat( 'b', 24 );
		set_transient( LicenseSearchStore::transient_name( 7, $token ), array( 'type' => 'license_key', 'value' => 'WPLC-RAW' ), 60 );
		$this->assertNull( LicenseSearchStore::load( 7, $token ) );
	}

	public function test_empty_terms_and_logged_out_users_get_no_token(): void {
		$this->assertSame( '', LicenseSearchStore::save( 7, '   ' ) );
		$this->assertSame( '', LicenseSearchStore::save( 0, 'jane@example.com' ) );
		$this->assertSame( array(), $GLOBALS['wplc_test_transients'] );
	}

	public function test_use_extends_the_lifetime(): void {
		$token = LicenseSearchStore::save( 7, '42' );
		$name  = LicenseSearchStore::transient_name( 7, $token );

		$GLOBALS['wplc_test_transients'][ $name ][1] = 1;
		LicenseSearchStore::reset_cache();

		LicenseSearchStore::load( 7, $token );
		$this->assertSame( LicenseSearchStore::TTL, $GLOBALS['wplc_test_transients'][ $name ][1] );
	}
}
