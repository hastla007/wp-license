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
	 * : Print only "public_key" or "key_id".
	 *
	 * [--rotate]
	 * : Create a new stored key. Clients pinned to the old public key reject the server until they are updated –
	 * rotate only together with a product release. Not possible while the key comes from the constant.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wplc signing-key
	 *     wp wplc signing-key --field=public_key
	 *
	 * @param array<int,string>   $args       Positional arguments.
	 * @param array<string,mixed> $assoc_args Options.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		if ( ! ResponseSigner::available() ) {
			\WP_CLI::error( 'Ed25519 (sodium) is not available on this server.' );
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
		$fields = array(
			'public_key' => $public,
			'key_id'     => ResponseSigner::key_id_of( $public ),
			'source'     => defined( 'WPLC_RESPONSE_SIGNING_KEY' ) && '' !== (string) WPLC_RESPONSE_SIGNING_KEY ? 'constant' : 'option',
		);
		$field = isset( $assoc_args['field'] ) ? (string) $assoc_args['field'] : '';
		if ( '' !== $field ) {
			if ( ! isset( $fields[ $field ] ) ) {
				\WP_CLI::error( 'Unknown field.' );
			}
			\WP_CLI::line( $fields[ $field ] );
			return;
		}
		foreach ( $fields as $name => $value ) {
			\WP_CLI::line( $name . ': ' . $value );
		}
	}
}
