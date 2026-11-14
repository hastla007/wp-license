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
	 * Print the public response signing key (base64) and its key id.
	 *
	 * Creates the key on first use when WPLC_RESPONSE_SIGNING_KEY is not set in wp-config.php.
	 *
	 * ## OPTIONS
	 *
	 * [--field=<field>]
	 * : Print only "public_key", "key_id", "source", "next_public_key" or "next_key_id".
	 *
	 * [--prepare]
	 * : Create the next stored key without using it and print its public key (next_public_key). Ship product
	 * releases that pin the current and the next public key, then run --rotate. Repeated calls keep the same
	 * prepared key. Not possible while the key comes from the constant.
	 *
	 * [--rotate]
	 * : Switch to the prepared next key (or, without one, to a new key). Clients that do not pin the new public
	 * key reject the server until they are updated. Not possible while the key comes from the constant.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wplc signing-key
	 *     wp wplc signing-key --field=public_key
	 *     wp wplc signing-key --prepare --field=next_public_key
	 *     wp wplc signing-key --rotate
	 *
	 * @param array<int,string>   $args       Positional arguments.
	 * @param array<string,mixed> $assoc_args Options.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		if ( ! ResponseSigner::available() ) {
			\WP_CLI::error( __( 'Ed25519 (sodium) is not available on this server.', 'wp-license' ) );
		}
		if ( ! empty( $assoc_args['prepare'] ) && ! empty( $assoc_args['rotate'] ) ) {
			\WP_CLI::error( __( 'Use --prepare and --rotate in separate steps, with a product release in between.', 'wp-license' ) );
		}
		if ( ! empty( $assoc_args['prepare'] ) && null === ResponseSigner::prepare_next() ) {
			\WP_CLI::error( __( 'The key comes from WPLC_RESPONSE_SIGNING_KEY, or the prepared key could not be stored or decrypted.', 'wp-license' ) );
		}
		if ( ! empty( $assoc_args['rotate'] ) ) {
			$old = ResponseSigner::public_key();
			if ( '' === $old && '' !== (string) get_option( ResponseSigner::OPTION, '' ) ) {
				\WP_CLI::error( __( 'The stored signing key cannot be decrypted. Restore the previous encryption key in WPLC_INSTALLATION_ENCRYPTION_KEYS instead of replacing the key that clients pin.', 'wp-license' ) );
			}
			ResponseSigner::reset();
			if ( null === ResponseSigner::rotate() ) {
				\WP_CLI::error( __( 'The key comes from WPLC_RESPONSE_SIGNING_KEY, or the new key could not be stored (or the prepared key cannot be decrypted); rotate a constant in wp-config.php.', 'wp-license' ) );
			}
			AuditLog::record( 'response_signing_key_rotated', 'system', 0, \WPLC\License\AuditReason::SIGNING_KEY_ROTATED, array( 'key_id' => ResponseSigner::key_id_of( $old ) ), array( 'key_id' => ResponseSigner::key_id_of( ResponseSigner::public_key() ) ) );
		}
		$public = ResponseSigner::public_key();
		if ( '' === $public ) {
			\WP_CLI::error( '' !== (string) get_option( ResponseSigner::OPTION, '' ) ? __( 'The stored signing key cannot be decrypted with WPLC_INSTALLATION_ENCRYPTION_KEYS; responses carry no Ed25519 signature. Restore the previous encryption key.', 'wp-license' ) : __( 'No response signing key could be created.', 'wp-license' ) );
		}
		$fields = array(
			'public_key' => $public,
			'key_id'     => ResponseSigner::key_id_of( $public ),
			'source'     => ResponseSigner::uses_constant() ? 'constant' : 'option',
		);
		$next = ResponseSigner::next_secret_key();
		if ( null !== $next ) {
			$fields['next_public_key'] = ResponseSigner::public_key_of( $next );
			$fields['next_key_id']     = ResponseSigner::key_id_of( $fields['next_public_key'] );
		}
		$field = isset( $assoc_args['field'] ) ? (string) $assoc_args['field'] : '';
		if ( '' !== $field ) {
			if ( ! isset( $fields[ $field ] ) ) {
				\WP_CLI::error( __( 'Unknown field.', 'wp-license' ) );
			}
			\WP_CLI::line( $fields[ $field ] );
			return;
		}
		foreach ( $fields as $name => $value ) {
			\WP_CLI::line( $name . ': ' . $value );
		}
	}
}
