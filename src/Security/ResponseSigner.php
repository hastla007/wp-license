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
 * `wp wplc signing-key` prints the public key for product builds and rotates the stored key.
 */
final class ResponseSigner {
	public const OPTION  = 'wplc_response_signing_key';
	public const CONTEXT = 'wplc-response-v1';

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
		if ( defined( 'WPLC_RESPONSE_SIGNING_KEY' ) && '' !== (string) WPLC_RESPONSE_SIGNING_KEY ) {
			self::$secret_key = self::secret_key_from( (string) WPLC_RESPONSE_SIGNING_KEY );
			return self::$secret_key;
		}
		if ( ! function_exists( 'get_option' ) ) {
			return null;
		}
		$stored = (string) get_option( self::OPTION, '' );
		if ( '' !== $stored ) {
			$plain = 0 === strpos( $stored, 'v1:' ) ? SecretCrypto::decrypt( $stored ) : $stored;
			self::$secret_key = is_string( $plain ) ? self::secret_key_from( $plain ) : null;
			return self::$secret_key;
		}
		return self::rotate();
	}

	/**
	 * Create and store a new key (only for the option-based key; a constant is rotated in wp-config.php).
	 *
	 * @return string|null Binary secret key.
	 */
	public static function rotate(): ?string {
		if ( ! self::available() || ! function_exists( 'update_option' ) || ( defined( 'WPLC_RESPONSE_SIGNING_KEY' ) && '' !== (string) WPLC_RESPONSE_SIGNING_KEY ) ) {
			return null;
		}
		$secret    = sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() );
		$encoded   = base64_encode( $secret );
		$encrypted = SecretCrypto::encrypt( $encoded );
		update_option( self::OPTION, false !== $encrypted ? $encrypted : $encoded, false );
		if ( '' === (string) get_option( self::OPTION, '' ) ) {
			return null;
		}
		self::$secret_key = $secret;
		return $secret;
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
