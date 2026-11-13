<?php
use PHPUnit\Framework\TestCase;
use WPLC\Security\ResponseSigner;

// Option storage of the unit tests (no WordPress loaded): an in-memory array.
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['wplc_test_options'] ?? array() ) ? $GLOBALS['wplc_test_options'][ $name ] : $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['wplc_test_options'][ $name ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $name ) {
		unset( $GLOBALS['wplc_test_options'][ $name ] );
		return true;
	}
}

final class ResponseSignerTest extends TestCase {
	/** Next key of the documented rotation vector: seed 32 × 0x02. */
	private const NEXT_PUBLIC = 'gTl3Dqh9F19Wo1Rmw0x+zMuNipG07jeiXfYPW4/Js5Q=';

	protected function setUp(): void {
		$GLOBALS['wplc_test_options'] = array();
		ResponseSigner::reset();
	}

	protected function tearDown(): void {
		$GLOBALS['wplc_test_options'] = array();
		ResponseSigner::reset();
	}

	private function keys(): array {
		$secret = ResponseSigner::secret_key_from( base64_encode( str_repeat( "\x01", 32 ) ) );
		return array( $secret, ResponseSigner::public_key_of( $secret ) );
	}

	public function test_a_signed_message_verifies_with_the_public_key(): void {
		list( $secret, $public ) = $this->keys();
		$message   = ResponseSigner::message( 'A1B2C3D4-0000-4000-8000-000000000001', '{"license_status":"active"}' );
		$signature = ResponseSigner::sign_with( $message, $secret );

		$this->assertTrue( ResponseSigner::verify( $message, $signature, $public ) );
		$this->assertSame( 16, strlen( ResponseSigner::key_id_of( $public ) ) );
	}

	public function test_the_signature_binds_data_and_installation(): void {
		list( $secret, $public ) = $this->keys();
		$json      = '{"license_status":"active"}';
		$signature = ResponseSigner::sign_with( ResponseSigner::message( 'a1b2c3d4-0000-4000-8000-000000000001', $json ), $secret );

		$this->assertFalse( ResponseSigner::verify( ResponseSigner::message( 'a1b2c3d4-0000-4000-8000-000000000002', $json ), $signature, $public ), 'another installation' );
		$this->assertFalse( ResponseSigner::verify( ResponseSigner::message( 'a1b2c3d4-0000-4000-8000-000000000001', '{"license_status":"suspended"}' ), $signature, $public ), 'other data' );
		$this->assertSame( ResponseSigner::message( 'A1B2C3D4-0000-4000-8000-000000000001', $json ), ResponseSigner::message( 'a1b2c3d4-0000-4000-8000-000000000001', $json ), 'UUID case does not matter' );
	}

	public function test_another_key_or_garbage_is_rejected(): void {
		list( $secret ) = $this->keys();
		$other   = ResponseSigner::public_key_of( ResponseSigner::secret_key_from( base64_encode( str_repeat( "\x02", 32 ) ) ) );
		$message = ResponseSigner::message( '', '{}' );
		$sig     = ResponseSigner::sign_with( $message, $secret );

		$this->assertFalse( ResponseSigner::verify( $message, $sig, $other ) );
		$this->assertFalse( ResponseSigner::verify( $message, 'not-base64!', $other ) );
		$this->assertFalse( ResponseSigner::verify( $message, $sig, base64_encode( 'short' ) ) );
	}

	public function test_a_seed_and_the_full_secret_key_give_the_same_key(): void {
		list( $secret, $public ) = $this->keys();
		$this->assertSame( $public, ResponseSigner::public_key_of( ResponseSigner::secret_key_from( base64_encode( $secret ) ) ) );
		$this->assertNull( ResponseSigner::secret_key_from( base64_encode( 'wrong length' ) ) );
	}

	/** Documented test vector (docs/API-V1.md): seed 32 × 0x01, installation and data below. */
	public function test_documented_vector(): void {
		list( $secret, $public ) = $this->keys();
		$message = ResponseSigner::message( 'a1b2c3d4-0000-4000-8000-000000000001', '{"license_status":"active"}' );
		$this->assertSame( 'iojj3XQJ8ZX9UtstPLpdcspnCb8dlBIb83SIAbQPb1w=', $public );
		$this->assertSame( '4qfhfiKz3F2K45FnfKU7bEeAoreDXyf6tuAtlPXlCSvVRxzcoqVFOubr0bb+TtmU65fpRAc2KRVZ4KdooQMBDA==', ResponseSigner::sign_with( $message, $secret ) );
	}

	public function test_without_a_next_key_the_envelope_has_one_signature(): void {
		list( $secret, $public ) = $this->keys();
		$fields = ResponseSigner::fields_for( 'a1b2c3d4-0000-4000-8000-000000000001', '{"license_status":"active"}', $secret, null );

		$this->assertSame( array( 'signature_ed25519', 'key_id' ), array_keys( $fields ) );
		$this->assertSame( '34750f98bd59fcfc', $fields['key_id'] );
		$this->assertSame( ResponseSigner::key_id_of( $public ), $fields['key_id'] );
		$this->assertSame( $fields, ResponseSigner::fields_for( 'a1b2c3d4-0000-4000-8000-000000000001', '{"license_status":"active"}', $secret, $secret ), 'a next key equal to the current one adds nothing' );
	}

	/** Documented rotation vector (docs/API-V1.md): current seed 32 × 0x01, next seed 32 × 0x02. */
	public function test_with_a_next_key_both_keys_sign_current_first(): void {
		list( $secret, $public ) = $this->keys();
		$next    = ResponseSigner::secret_key_from( base64_encode( str_repeat( "\x02", 32 ) ) );
		$message = ResponseSigner::message( 'a1b2c3d4-0000-4000-8000-000000000001', '{"license_status":"active"}' );
		$fields  = ResponseSigner::fields_for( 'a1b2c3d4-0000-4000-8000-000000000001', '{"license_status":"active"}', $secret, $next );

		$this->assertSame( self::NEXT_PUBLIC, ResponseSigner::public_key_of( $next ) );
		$this->assertSame( '4qfhfiKz3F2K45FnfKU7bEeAoreDXyf6tuAtlPXlCSvVRxzcoqVFOubr0bb+TtmU65fpRAc2KRVZ4KdooQMBDA==', $fields['signature_ed25519'], 'old clients keep the current signature' );
		$this->assertSame( '34750f98bd59fcfc', $fields['key_id'] );
		$this->assertCount( 2, $fields['signatures'] );
		$this->assertSame( array( 'key_id' => '34750f98bd59fcfc', 'signature_ed25519' => $fields['signature_ed25519'] ), $fields['signatures'][0] );
		$this->assertSame( '6a3803d5f059902a', $fields['signatures'][1]['key_id'] );
		$this->assertSame( '9Um7aM0cVvZ6q7AIumlECZV5Q/nnSsP2KkpoLZSrLrWv/IITibWK0VOffli+3tHBZKxqOEICsz62PTTpTSx4AQ==', $fields['signatures'][1]['signature_ed25519'] );
		$this->assertTrue( ResponseSigner::verify( $message, $fields['signatures'][1]['signature_ed25519'], self::NEXT_PUBLIC ) );
		$this->assertFalse( ResponseSigner::verify( $message, $fields['signatures'][1]['signature_ed25519'], $public ), 'each signature belongs to its own key' );
	}

	public function test_envelope_fields_sign_with_the_stored_current_and_next_key(): void {
		$GLOBALS['wplc_test_options'][ ResponseSigner::OPTION ]      = base64_encode( str_repeat( "\x01", 32 ) );
		$GLOBALS['wplc_test_options'][ ResponseSigner::OPTION_NEXT ] = base64_encode( str_repeat( "\x02", 32 ) );
		$fields = ResponseSigner::envelope_fields( 'a1b2c3d4-0000-4000-8000-000000000001', '{"license_status":"active"}' );

		$this->assertSame( '34750f98bd59fcfc', $fields['key_id'] );
		$this->assertSame( array( '34750f98bd59fcfc', '6a3803d5f059902a' ), array_column( $fields['signatures'], 'key_id' ) );
		$this->assertSame(
			array(
				'context' => 'wplc-response-v1',
				'current' => array( 'public_key' => 'iojj3XQJ8ZX9UtstPLpdcspnCb8dlBIb83SIAbQPb1w=', 'key_id' => '34750f98bd59fcfc' ),
				'next'    => array( 'public_key' => self::NEXT_PUBLIC, 'key_id' => '6a3803d5f059902a' ),
			),
			ResponseSigner::public_keys()
		);
	}

	public function test_the_public_key_list_never_creates_a_key(): void {
		$this->assertSame( array( 'context' => 'wplc-response-v1', 'current' => null, 'next' => null ), ResponseSigner::public_keys() );
		$this->assertSame( array(), $GLOBALS['wplc_test_options'] );
	}

	public function test_a_malformed_next_key_is_ignored(): void {
		$GLOBALS['wplc_test_options'][ ResponseSigner::OPTION ]      = base64_encode( str_repeat( "\x01", 32 ) );
		$GLOBALS['wplc_test_options'][ ResponseSigner::OPTION_NEXT ] = base64_encode( 'too short' );

		$this->assertNull( ResponseSigner::next_secret_key() );
		$this->assertArrayNotHasKey( 'signatures', ResponseSigner::envelope_fields( '', '{}' ) );
	}

	public function test_create_next_stores_one_next_key_and_keeps_it(): void {
		$GLOBALS['wplc_test_options'][ ResponseSigner::OPTION ] = base64_encode( str_repeat( "\x01", 32 ) );
		$next = ResponseSigner::create_next();

		$this->assertNotNull( $next );
		$this->assertNotSame( ResponseSigner::secret_key(), $next );
		$this->assertArrayHasKey( ResponseSigner::OPTION_NEXT, $GLOBALS['wplc_test_options'] );
		ResponseSigner::reset();
		$this->assertSame( $next, ResponseSigner::create_next(), 'an existing next key is shown, not replaced' );
		$this->assertSame( 'iojj3XQJ8ZX9UtstPLpdcspnCb8dlBIb83SIAbQPb1w=', ResponseSigner::public_key(), 'the current key is unchanged' );
	}

	public function test_promote_makes_the_next_key_current_and_removes_the_old_one(): void {
		$GLOBALS['wplc_test_options'][ ResponseSigner::OPTION ]      = base64_encode( str_repeat( "\x01", 32 ) );
		$GLOBALS['wplc_test_options'][ ResponseSigner::OPTION_NEXT ] = base64_encode( str_repeat( "\x02", 32 ) );

		$this->assertNotNull( ResponseSigner::promote() );
		$this->assertSame( self::NEXT_PUBLIC, ResponseSigner::public_key( false ) );
		$this->assertArrayNotHasKey( ResponseSigner::OPTION_NEXT, $GLOBALS['wplc_test_options'] );
		$this->assertSame( '', ResponseSigner::next_public_key() );
		$fields = ResponseSigner::envelope_fields( 'a1b2c3d4-0000-4000-8000-000000000001', '{"license_status":"active"}' );
		$this->assertSame( '6a3803d5f059902a', $fields['key_id'] );
		$this->assertSame( '9Um7aM0cVvZ6q7AIumlECZV5Q/nnSsP2KkpoLZSrLrWv/IITibWK0VOffli+3tHBZKxqOEICsz62PTTpTSx4AQ==', $fields['signature_ed25519'] );
		$this->assertArrayNotHasKey( 'signatures', $fields, 'one key again after the promotion' );
	}

	public function test_promote_without_a_next_key_changes_nothing(): void {
		$GLOBALS['wplc_test_options'][ ResponseSigner::OPTION ] = base64_encode( str_repeat( "\x01", 32 ) );

		$this->assertNull( ResponseSigner::promote() );
		$this->assertSame( 'iojj3XQJ8ZX9UtstPLpdcspnCb8dlBIb83SIAbQPb1w=', ResponseSigner::public_key( false ) );
	}
}
