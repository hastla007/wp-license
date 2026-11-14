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
			// Until Plugin::boot() calls Commands::register() itself, the support commands register here.
			Commands::register();
		}
	}

	/**
	 * Print the public response signing key (base64) and its key id.
	 *
	 * Creates the key on first use when WPLC_RESPONSE_SIGNING_KEY is not set in wp-config.php. A stored key that
	 * was lost or cannot be decrypted any more is never replaced on its own: the command then ends with an error
	 * that names the reason, and --rotate creates a new key deliberately.
	 *
	 * ## OPTIONS
	 *
	 * [--field=<field>]
	 * : Print only "public_key", "key_id", "source" or "state".
	 *
	 * [--rotate]
	 * : Create a new stored key. Clients pinned to the old public key reject the server until they are updated –
	 * rotate only together with a product release. Not possible while the key comes from the constant.
	 *
	 * [--generate]
	 * : Print a new random value for WPLC_RESPONSE_SIGNING_KEY and its public key without storing anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wplc signing-key
	 *     wp wplc signing-key --field=public_key
	 *     wp wplc signing-key --generate
	 *
	 * @param array<int,string>   $args       Positional arguments.
	 * @param array<string,mixed> $assoc_args Options.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		if ( ! ResponseSigner::available() ) {
			\WP_CLI::error( 'Ed25519 (sodium) is not available on this server.' );
		}

		if ( ! empty( $assoc_args['generate'] ) ) {
			$seed = base64_encode( random_bytes( 32 ) );
			$secret = ResponseSigner::secret_key_from( $seed );
			\WP_CLI::line( "define( 'WPLC_RESPONSE_SIGNING_KEY', '" . $seed . "' );" );
			\WP_CLI::line( 'public_key: ' . ResponseSigner::public_key_of( (string) $secret ) );
			\WP_CLI::line( 'key_id: ' . ResponseSigner::key_id_of( ResponseSigner::public_key_of( (string) $secret ) ) );
			return;
		}

		if ( ! empty( $assoc_args['rotate'] ) ) {
			$old = ResponseSigner::status();
			ResponseSigner::reset();
			if ( null === ResponseSigner::rotate() ) {
				\WP_CLI::error( 'The key comes from WPLC_RESPONSE_SIGNING_KEY or could not be stored encrypted; rotate it in wp-config.php.' );
			}
			AuditLog::record( 'response_signing_key_rotated', 'system', 0, 'Response signing key rotated', array( 'key_id' => $old['key_id'] ), array( 'key_id' => ResponseSigner::key_id_of( ResponseSigner::public_key() ) ) );
		}

		$status = ResponseSigner::status();
		$field = isset( $assoc_args['field'] ) ? (string) $assoc_args['field'] : '';
		if ( 'ok' !== $status['state'] && 'state' !== $field ) {
			\WP_CLI::error( self::state_message( $status['state'] ) );
		}

		$fields = array(
			'public_key' => $status['public_key'],
			'key_id'     => $status['key_id'],
			'source'     => $status['source'],
			'state'      => $status['state'],
		);
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

	/**
	 * Explain why no signing key is usable.
	 *
	 * @param string $state State from ResponseSigner::status().
	 * @return string
	 */
	public static function state_message( string $state ): string {
		$messages = array(
			'constant_invalid' => 'WPLC_RESPONSE_SIGNING_KEY is not a valid key: it must be the base64 of a 32-byte seed or of a 64-byte Ed25519 secret key. Responses are sent without a server signature until it is corrected.',
			'undecryptable' => 'The stored signing key cannot be decrypted with the configured encryption keys or salts. Restore the previous key ring, or create a new key with --rotate (clients that pin the old public key then reject this server).',
			'corrupt' => 'The stored signing key is damaged. Restore it from a backup, or create a new key with --rotate.',
			'missing' => 'The stored signing key is gone although this server had one. Restore the option wplc_response_signing_key from a backup, or create a new key with --rotate.',
			'encryption_unavailable' => 'The signing key cannot be encrypted (no usable encryption key or no OpenSSL) and is therefore not stored. Configure WPLC_INSTALLATION_ENCRYPTION_KEYS or set WPLC_RESPONSE_SIGNING_KEY.',
			'storage_failed' => 'The signing key could not be written to the options table.',
			'busy' => 'Another request is creating the signing key. Try again.',
		);

		return $messages[ $state ] ?? 'No response signing key is available (' . $state . ').';
	}
}
