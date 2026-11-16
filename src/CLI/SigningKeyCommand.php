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
 * WP-CLI command for the public key that product builds pin (the public_keys option of the LicenseClient in an add-on).
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
	 * : Print only "public_key", "key_id", "source", "state", "next_public_key" or "next_key_id".
	 *
	 * [--prepare]
	 * : Create the next stored key without using it and print its public key (next_public_key). Ship product
	 * releases that pin the current and the next public key, then run --rotate. Repeated calls keep the same
	 * prepared key. Not possible while the key comes from the constant.
	 *
	 * [--rotate]
	 * : Switch to the prepared next key. Clients that do not pin the new public key reject the server until they
	 * are updated – rotate only together with a product release. Asks for confirmation. Without a prepared key the
	 * command stops unless --force is given. Not possible while the key comes from the constant.
	 *
	 * [--force]
	 * : With --rotate: create and use a brand-new key although none was prepared. Every delivered client that pins
	 * the current key rejects all responses until it is updated, and the current private key is overwritten.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * [--generate]
	 * : Print a new random value for WPLC_RESPONSE_SIGNING_KEY and its public key without storing anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wplc signing-key
	 *     wp wplc signing-key --field=public_key
	 *     wp wplc signing-key --prepare --field=next_public_key
	 *     wp wplc signing-key --rotate
	 *     wp wplc signing-key --rotate --yes
	 *     wp wplc signing-key --generate
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
			\WP_CLI::error( __( 'The key comes from WPLC_RESPONSE_SIGNING_KEY, or the prepared key could not be stored encrypted or decrypted.', 'wp-license' ) );
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
			self::confirm_rotation( $old, $assoc_args );
			ResponseSigner::reset();
			if ( null === ResponseSigner::rotate() ) {
				\WP_CLI::error( __( 'The key comes from WPLC_RESPONSE_SIGNING_KEY, or the new key could not be stored encrypted (or the prepared key cannot be decrypted); rotate a constant in wp-config.php.', 'wp-license' ) );
			}
			AuditLog::record( 'response_signing_key_rotated', 'system', 0, \WPLC\License\AuditReason::SIGNING_KEY_ROTATED, array( 'key_id' => $old['key_id'] ), array( 'key_id' => ResponseSigner::key_id_of( ResponseSigner::public_key() ) ) );
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
		$next = ResponseSigner::next_secret_key();
		if ( null !== $next ) {
			$fields['next_public_key'] = ResponseSigner::public_key_of( $next );
			$fields['next_key_id']     = ResponseSigner::key_id_of( $fields['next_public_key'] );
		}
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

	/**
	 * Stop a rotation that would lock out the delivered clients, or ask before it (P-34).
	 *
	 * Ends the command (WP_CLI::error() or a declined confirmation) when the rotation must not run.
	 *
	 * @param array<string,string> $current    Status of the current key.
	 * @param array<string,mixed>  $assoc_args Options (--force, --yes).
	 * @return void
	 */
	private static function confirm_rotation( array $current, array $assoc_args ): void {
		if ( ResponseSigner::uses_constant() ) {
			\WP_CLI::error( __( 'The key comes from WPLC_RESPONSE_SIGNING_KEY; rotate the constant in wp-config.php.', 'wp-license' ) );
		}
		$next = ResponseSigner::next_secret_key();
		if ( null !== $next ) {
			$next_public = ResponseSigner::public_key_of( $next );
			\WP_CLI::confirm(
				sprintf(
					/* translators: 1: key ID of the current signing key (may be empty), 2: key ID of the prepared key. */
					__( 'Switch the response signing key from "%1$s" to the prepared key "%2$s"? Clients that do not pin the prepared public key reject this server until they are updated.', 'wp-license' ),
					(string) $current['key_id'],
					ResponseSigner::key_id_of( $next_public )
				),
				$assoc_args
			);
			return;
		}
		if ( '' !== (string) get_option( ResponseSigner::NEXT_OPTION, '' ) ) {
			// rotate() refuses as well; say why before asking anything.
			\WP_CLI::error( __( 'The prepared signing key cannot be decrypted. Restore the encryption key it was stored with instead of replacing it.', 'wp-license' ) );
		}
		if ( empty( $assoc_args['force'] ) ) {
			\WP_CLI::error( __( 'No next key is prepared. Run "wp wplc signing-key --prepare", ship product releases that pin the printed next_public_key, then run --rotate. To create a brand-new key anyway (every client that pins the current key stops accepting responses), add --force.', 'wp-license' ) );
		}
		\WP_CLI::confirm(
			sprintf(
				/* translators: %s: key ID of the current signing key (may be empty). */
				__( 'No next key is prepared. Replace the response signing key "%s" with a brand-new key? Every delivered client that pins the current public key rejects all responses until it is updated, and the current private key is overwritten.', 'wp-license' ),
				(string) $current['key_id']
			),
			$assoc_args
		);
	}

	/**
	 * Explain why no signing key is usable.
	 *
	 * @param string $state State from ResponseSigner::status().
	 * @return string
	 */
	public static function state_message( string $state ): string {
		$messages = array(
			'constant_invalid'       => __( 'WPLC_RESPONSE_SIGNING_KEY is not a valid key: it must be the base64 of a 32-byte seed or of a 64-byte Ed25519 secret key. Responses are sent without a server signature until it is corrected.', 'wp-license' ),
			'undecryptable'          => __( 'The stored signing key cannot be decrypted with the configured encryption keys or salts. Restore the previous key ring, or create a new key with --rotate --force (clients that pin the old public key then reject this server).', 'wp-license' ),
			'corrupt'                => __( 'The stored signing key is damaged. Restore it from a backup, or create a new key with --rotate --force.', 'wp-license' ),
			'missing'                => __( 'The stored signing key is gone although this server had one. Restore the option wplc_response_signing_key from a backup, or create a new key with --rotate --force.', 'wp-license' ),
			'encryption_unavailable' => __( 'The signing key cannot be encrypted (no usable encryption key or no OpenSSL) and is therefore not stored. Configure WPLC_INSTALLATION_ENCRYPTION_KEYS or set WPLC_RESPONSE_SIGNING_KEY.', 'wp-license' ),
			'storage_failed'         => __( 'The signing key could not be written to the options table.', 'wp-license' ),
			'busy'                   => __( 'Another request is creating the signing key. Try again.', 'wp-license' ),
		);

		return $messages[ $state ] ?? sprintf(
			/* translators: %s: internal state name of the signing key. */
			__( 'No response signing key is available (%s).', 'wp-license' ),
			$state
		);
	}
}
