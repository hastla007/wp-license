<?php
/**
 * Show or rotate the Ed25519 response signing key.
 *
 * @package WPLC
 */

namespace WPLC\CLI;

use WPLC\License\AuditLog;
use WPLC\Security\ResponseSigner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-CLI command for the public key that product builds pin (e.g. VCAP_LICENSE_PUBLIC_KEY in Voucher Captain).
 */
final class SigningKeyCommand {
	/**
	 * Register the command when WP-CLI is active.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'wplc signing-key', self::class );
		}
	}

	/**
	 * Print the public response signing key (base64) and its key id; prepare and finish a key rotation.
	 *
	 * Creates the current key on first use when WPLC_RESPONSE_SIGNING_KEY is not set in wp-config.php.
	 *
	 * Rotation with overlap (docs/API-V1.md): `--next` creates the next key (or shows it) – from then on every response
	 * is signed with both keys; pin both public keys in the product builds and release them; once the customers run
	 * those builds, `--promote` makes the next key the current one and removes the old key.
	 *
	 * ## OPTIONS
	 *
	 * [--field=<field>]
	 * : Print only "public_key", "key_id" or "source".
	 *
	 * [--next]
	 * : Show the next key; creates and stores it when none exists (not while it comes from
	 * WPLC_RESPONSE_SIGNING_KEY_NEXT). The server then signs every response with the current and the next key.
	 *
	 * [--promote]
	 * : The stored next key becomes the current key; the old current key is deleted. Only after the product builds that
	 * pin the next key have reached the customers. Keys from wp-config.php constants are promoted there.
	 *
	 * [--rotate]
	 * : Emergency only: replace the stored current key at once. Clients pinned to the old public key reject the server
	 * until they are updated – prefer --next/--promote. Not possible while the key comes from the constant.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wplc signing-key
	 *     wp wplc signing-key --field=public_key
	 *     wp wplc signing-key --next --field=public_key
	 *     wp wplc signing-key --promote
	 *
	 * @param array<int,string>   $args       Positional arguments.
	 * @param array<string,mixed> $assoc_args Options.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		if ( ! ResponseSigner::available() ) {
			\WP_CLI::error( 'Ed25519 (sodium) is not available on this server.' );
		}
		$actions = array_intersect( array( 'next', 'promote', 'rotate' ), array_keys( array_filter( $assoc_args ) ) );
		if ( count( $actions ) > 1 ) {
			\WP_CLI::error( 'Use only one of --next, --promote and --rotate.' );
		}
		$field = isset( $assoc_args['field'] ) ? (string) $assoc_args['field'] : '';
		if ( ! empty( $assoc_args['next'] ) ) {
			$this->next( $field );
			return;
		}
		if ( ! empty( $assoc_args['promote'] ) ) {
			$old = ResponseSigner::public_key( false );
			if ( ResponseSigner::current_from_constant() || ResponseSigner::next_from_constant() ) {
				\WP_CLI::error( 'The keys come from wp-config.php: set WPLC_RESPONSE_SIGNING_KEY to the value of WPLC_RESPONSE_SIGNING_KEY_NEXT and remove WPLC_RESPONSE_SIGNING_KEY_NEXT.' );
			}
			if ( null === ResponseSigner::promote() ) {
				\WP_CLI::error( 'There is no stored next key to promote (create one with --next).' );
			}
			AuditLog::record( 'response_signing_key_promoted', 'system', 0, 'Next response signing key promoted', array( 'key_id' => ResponseSigner::key_id_of( $old ) ), array( 'key_id' => ResponseSigner::key_id_of( ResponseSigner::public_key( false ) ) ) );
		}
		if ( ! empty( $assoc_args['rotate'] ) ) {
			$old = ResponseSigner::public_key();
			ResponseSigner::reset();
			if ( null === ResponseSigner::rotate() ) {
				\WP_CLI::error( 'The key comes from WPLC_RESPONSE_SIGNING_KEY or could not be stored; rotate it in wp-config.php.' );
			}
			AuditLog::record( 'response_signing_key_rotated', 'system', 0, 'Response signing key rotated', array( 'key_id' => ResponseSigner::key_id_of( $old ) ), array( 'key_id' => ResponseSigner::key_id_of( ResponseSigner::public_key() ) ) );
		}
		$public = ResponseSigner::public_key();
		if ( '' === $public ) {
			\WP_CLI::error( 'No response signing key could be created.' );
		}
		$next = ResponseSigner::next_public_key();
		$this->output(
			array(
				'public_key' => $public,
				'key_id'     => ResponseSigner::key_id_of( $public ),
				'source'     => ResponseSigner::current_from_constant() ? 'constant' : 'option',
			),
			$field,
			'' === $next ? array() : array( 'next_key_id' => ResponseSigner::key_id_of( $next ) )
		);
	}

	/**
	 * Show (or create) the next key.
	 *
	 * @param string $field Field to print alone ('' = all).
	 * @return void
	 */
	private function next( string $field ): void {
		if ( ResponseSigner::next_from_constant() && null === ResponseSigner::next_secret_key() ) {
			\WP_CLI::error( 'WPLC_RESPONSE_SIGNING_KEY_NEXT is not a base64 Ed25519 seed or secret key.' );
		}
		$created = null === ResponseSigner::next_secret_key();
		if ( null === ResponseSigner::create_next() ) {
			\WP_CLI::error( 'The next key could not be stored.' );
		}
		$public = ResponseSigner::next_public_key();
		if ( $created ) {
			AuditLog::record( 'response_signing_key_next_created', 'system', 0, 'Next response signing key created', array(), array( 'key_id' => ResponseSigner::key_id_of( $public ) ) );
		}
		$this->output(
			array(
				'public_key' => $public,
				'key_id'     => ResponseSigner::key_id_of( $public ),
				'source'     => ResponseSigner::next_from_constant() ? 'constant' : 'option',
			),
			$field,
			array()
		);
	}

	/**
	 * Print all fields or one.
	 *
	 * @param array<string,string> $fields Fields.
	 * @param string               $field  Field to print alone ('' = all).
	 * @param array<string,string> $extra  Fields printed only in the full listing.
	 * @return void
	 */
	private function output( array $fields, string $field, array $extra ): void {
		if ( '' !== $field ) {
			if ( ! isset( $fields[ $field ] ) ) {
				\WP_CLI::error( 'Unknown field.' );
			}
			\WP_CLI::line( $fields[ $field ] );
			return;
		}
		foreach ( $fields + $extra as $name => $value ) {
			\WP_CLI::line( $name . ': ' . $value );
		}
	}
}
