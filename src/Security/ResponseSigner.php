<?php
/**
 * Asymmetric (Ed25519) signatures of REST response envelopes.
 *
 * @package WPLC
 */

namespace WPLC\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Signs response data with the server's Ed25519 key.
 *
 * The HMAC signature of an envelope is keyed by the installation secret, which the client also holds: it proves
 * integrity in transit and of the local cache, but not that the license server produced the data. The Ed25519
 * signature does – a client that pins the server's public key (product builds embed it) accepts no data that the
 * server did not sign, even if somebody can write its options or runs a server of their own.
 *
 * The signed message binds the data to one installation:
 *   "wplc-response-v1\n" . installation UUID . "\n" . JSON of data (JSON_UNESCAPED_SLASHES)
 *
 * Key source, first match wins:
 *   1. constant WPLC_RESPONSE_SIGNING_KEY – base64 of the 32-byte seed or the 64-byte secret key (recommended for
 *      production: keep it out of the database);
 *   2. option wplc_response_signing_key – generated on first use, encrypted with SecretCrypto when an encryption key
 *      ring is configured.
 * `wp wplc signing-key` prints the public key for product builds, prepares the next stored key (option
 * wplc_response_signing_key_next, so its public key can ship in product builds before it is used) and rotates.
 * `wp wplc rotate-installation-secrets` re-encrypts both options when the encryption key ring rotates.
 */
final class ResponseSigner {
	public const OPTION      = 'wplc_response_signing_key';
	public const NEXT_OPTION = 'wplc_response_signing_key_next';
	public const CONTEXT     = 'wplc-response-v1';

	/** @var string|null Binary secret key of this request. */
	private static ?string $secret_key = null;

	/** Whether Ed25519 is available (PHP sodium or the sodium_compat polyfill bundled with WordPress). */
	public static function available(): bool {
		return function_exists( 'sodium_crypto_sign_detached' ) && function_exists( 'sodium_crypto_sign_keypair' );
	}

	/**
	 * Message that is signed for an installation.
	 *
	 * @param string $installation_uuid Installation UUID ('' for responses without installation).
	 * @param string $json              JSON of the data as sent.
	 */
	public static function message( string $installation_uuid, string $json ): string {
		return self::CONTEXT . "\n" . strtolower( $installation_uuid ) . "\n" . $json;
	}

	/**
	 * Detached Ed25519 signature (base64) of a message with a given secret key.
	 *
	 * @param string $message    Message.
	 * @param string $secret_key Binary 64-byte secret key.
	 */
	public static function sign_with( string $message, string $secret_key ): string {
		return base64_encode( sodium_crypto_sign_detached( $message, $secret_key ) );
	}

	/**
	 * Verify a detached signature (base64) with a public key (base64).
	 *
	 * @param string $message    Message.
	 * @param string $signature  Base64 signature.
	 * @param string $public_key Base64 public key.
	 */
	public static function verify( string $message, string $signature, string $public_key ): bool {
		$sig = base64_decode( $signature, true );
		$key = base64_decode( $public_key, true );
		if ( false === $sig || false === $key || 64 !== strlen( $sig ) || 32 !== strlen( $key ) ) {
			return false;
		}
		try {
			return sodium_crypto_sign_verify_detached( $sig, $message, $key );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/** Short identifier of a public key (base64), shown next to signatures and in diagnostics. */
	public static function key_id_of( string $public_key ): string {
		$key = base64_decode( $public_key, true );
		return false === $key ? '' : substr( hash( 'sha256', $key ), 0, 16 );
	}

	/**
	 * Secret key from a configured value: base64 of a 32-byte seed or a 64-byte secret key.
	 *
	 * @param string $encoded Base64 value.
	 * @return string|null Binary secret key.
	 */
	public static function secret_key_from( string $encoded ): ?string {
		$raw = base64_decode( trim( $encoded ), true );
		if ( false === $raw ) {
			return null;
		}
		if ( 32 === strlen( $raw ) ) {
			return sodium_crypto_sign_secretkey( sodium_crypto_sign_seed_keypair( $raw ) );
		}
		return 64 === strlen( $raw ) ? $raw : null;
	}

	/** Base64 public key belonging to a binary secret key. */
	public static function public_key_of( string $secret_key ): string {
		return base64_encode( sodium_crypto_sign_publickey_from_secretkey( $secret_key ) );
	}

	/**
	 * The server's secret key (binary), generated and stored on first use when no constant is configured.
	 *
	 * @return string|null Null when Ed25519 is not available or the key cannot be stored.
	 */
	public static function secret_key(): ?string {
		if ( null !== self::$secret_key ) {
			return self::$secret_key;
		}
		if ( ! self::available() ) {
			return null;
		}
		if ( self::uses_constant() ) {
			self::$secret_key = self::secret_key_from( (string) WPLC_RESPONSE_SIGNING_KEY );
			return self::$secret_key;
		}
		if ( ! function_exists( 'get_option' ) ) {
			return null;
		}
		$stored = (string) get_option( self::OPTION, '' );
		if ( '' !== $stored ) {
			self::$secret_key = self::stored_secret_key( $stored );
			if ( null !== self::$secret_key ) {
				// Move a key encrypted with a previous installation encryption key to the current one, so the
				// previous key can leave the ring (the same lazy rotation the Authenticator does for installations).
				self::reencrypt_option( self::OPTION, true );
			}
			return self::$secret_key;
		}
		return self::rotate();
	}

	/**
	 * Binary secret key from a stored option value (an encrypted envelope or plain base64).
	 *
	 * @param string $stored Option value.
	 * @return string|null Null when the value cannot be decrypted, e.g. its encryption key left the key ring.
	 */
	public static function stored_secret_key( string $stored ): ?string {
		if ( '' === $stored ) {
			return null;
		}
		$plain = 0 === strpos( $stored, 'v1:' ) ? SecretCrypto::decrypt( $stored ) : $stored;
		return is_string( $plain ) ? self::secret_key_from( $plain ) : null;
	}

	/**
	 * Re-encryption of a stored key value with the current installation encryption key.
	 *
	 * Statuses: 'empty' (nothing stored), 'current' (already encrypted with the current key), 'rotate' (value holds the
	 * re-encrypted envelope; the stored value was encrypted with a previous key or stored unencrypted) and
	 * 'undecryptable' (the stored value cannot be decrypted with the configured key ring).
	 *
	 * @param string $stored Option value.
	 * @return array{status:string,value:string}
	 */
	public static function reencrypted( string $stored ): array {
		if ( '' === $stored ) {
			return array( 'status' => 'empty', 'value' => '' );
		}
		if ( 0 === strpos( $stored, 'v1:' ) && ! SecretCrypto::needs_rotation( $stored ) ) {
			return array( 'status' => 'current', 'value' => $stored );
		}
		$secret = self::stored_secret_key( $stored );
		if ( null === $secret ) {
			return array( 'status' => 'undecryptable', 'value' => $stored );
		}
		$encrypted = SecretCrypto::encrypt( base64_encode( $secret ) );
		if ( false === $encrypted ) {
			// No usable encryption key: keep the stored value rather than lose the key.
			return array( 'status' => 0 === strpos( $stored, 'v1:' ) ? 'undecryptable' : 'current', 'value' => $stored );
		}
		return array( 'status' => 'rotate', 'value' => $encrypted );
	}

	/**
	 * Re-encrypt a stored key option (current or prepared key) with the current installation encryption key.
	 *
	 * @param string $option Option name (self::OPTION or self::NEXT_OPTION).
	 * @param bool   $apply  Write the re-encrypted value; false only reports.
	 * @return string Status of reencrypted(), 'rotated' after a successful write, or 'constant' when the key comes
	 *                from WPLC_RESPONSE_SIGNING_KEY (nothing is stored).
	 */
	public static function reencrypt_option( string $option, bool $apply ): string {
		if ( self::OPTION === $option && self::uses_constant() ) {
			return 'constant';
		}
		if ( ! function_exists( 'get_option' ) ) {
			return 'empty';
		}
		$stored = (string) get_option( $option, '' );
		$result = self::reencrypted( $stored );
		if ( 'rotate' !== $result['status'] || ! $apply ) {
			return $result['status'];
		}
		// Rewrite only the value that was read; a concurrent rotation wins.
		if ( (string) get_option( $option, '' ) !== $stored || ! update_option( $option, $result['value'], false ) ) {
			return 'rotate';
		}
		return 'rotated';
	}

	/**
	 * Create (or return) the prepared next key without using it, so product builds can pin its public key before
	 * `rotate()` switches to it. Only for the option-based key.
	 *
	 * @return string|null Binary secret key of the prepared key.
	 */
	public static function prepare_next(): ?string {
		if ( ! self::available() || ! function_exists( 'update_option' ) || self::uses_constant() ) {
			return null;
		}
		$existing = self::next_secret_key();
		if ( null !== $existing ) {
			return $existing;
		}
		if ( '' !== (string) get_option( self::NEXT_OPTION, '' ) ) {
			return null; // A prepared key exists but cannot be decrypted: do not silently replace a key clients may pin.
		}
		$secret = sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() );
		if ( ! self::store( self::NEXT_OPTION, $secret ) ) {
			return null;
		}
		return $secret;
	}

	/**
	 * Binary secret key of the prepared next key.
	 *
	 * @return string|null Null when no key is prepared.
	 */
	public static function next_secret_key(): ?string {
		if ( ! self::available() || ! function_exists( 'get_option' ) ) {
			return null;
		}
		return self::stored_secret_key( (string) get_option( self::NEXT_OPTION, '' ) );
	}

	/**
	 * Switch to a new stored key (only for the option-based key; a constant is rotated in wp-config.php).
	 *
	 * Uses the key created by prepare_next() when there is one, otherwise creates a new key.
	 *
	 * @return string|null Binary secret key.
	 */
	public static function rotate(): ?string {
		if ( ! self::available() || ! function_exists( 'update_option' ) || self::uses_constant() ) {
			return null;
		}
		$secret = self::next_secret_key();
		if ( null === $secret ) {
			if ( '' !== (string) get_option( self::NEXT_OPTION, '' ) ) {
				return null; // The prepared key cannot be decrypted; restore its encryption key instead of replacing it.
			}
			$secret = sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() );
		}
		if ( ! self::store( self::OPTION, $secret ) ) {
			return null;
		}
		delete_option( self::NEXT_OPTION );
		self::$secret_key = $secret;
		return $secret;
	}

	/** Whether the key comes from the WPLC_RESPONSE_SIGNING_KEY constant. */
	public static function uses_constant(): bool {
		return defined( 'WPLC_RESPONSE_SIGNING_KEY' ) && '' !== (string) WPLC_RESPONSE_SIGNING_KEY;
	}

	/**
	 * Store a binary secret key in an option, encrypted when possible.
	 *
	 * @param string $option Option name.
	 * @param string $secret Binary secret key.
	 */
	private static function store( string $option, string $secret ): bool {
		$encoded   = base64_encode( $secret );
		$encrypted = SecretCrypto::encrypt( $encoded );
		$value     = false !== $encrypted ? $encrypted : $encoded;
		update_option( $option, $value, false );
		return (string) get_option( $option, '' ) === $value;
	}

	/** Base64 public key of the server ('' when not available). */
	public static function public_key(): string {
		$secret = self::secret_key();
		return null === $secret ? '' : self::public_key_of( $secret );
	}

	/**
	 * Signature fields for an envelope: signature_ed25519 and key_id, or nothing when Ed25519 is not available.
	 *
	 * @param string $installation_uuid Installation UUID.
	 * @param string $json              JSON of the data.
	 * @return array<string,string>
	 */
	public static function envelope_fields( string $installation_uuid, string $json ): array {
		$secret = self::secret_key();
		if ( null === $secret ) {
			return array();
		}
		return array(
			'signature_ed25519' => self::sign_with( self::message( $installation_uuid, $json ), $secret ),
			'key_id'            => self::key_id_of( self::public_key_of( $secret ) ),
		);
	}

	/** Forget the cached key (tests, rotation). */
	public static function reset(): void {
		self::$secret_key = null;
	}
}
