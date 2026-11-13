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
 *
 * Rotation with overlap: an optional NEXT key (constant WPLC_RESPONSE_SIGNING_KEY_NEXT, or option
 * wplc_response_signing_key_next created by `wp wplc signing-key --next`). While it is set, every envelope carries a
 * `signatures` list with the current and the next key's signature (current first); signature_ed25519/key_id stay
 * those of the current key for clients that know only one key. Product builds pin both public keys; once the customers
 * run such a build, `wp wplc signing-key --promote` makes the next key the current one and removes the old key.
 */
final class ResponseSigner {
	public const OPTION      = 'wplc_response_signing_key';
	public const OPTION_NEXT = 'wplc_response_signing_key_next';
	public const CONTEXT     = 'wplc-response-v1';

	/** @var string|null Binary secret key of this request. */
	private static ?string $secret_key = null;

	/** @var string|null Binary next secret key of this request (null = none or not loaded yet). */
	private static ?string $next_secret_key = null;

	/** @var bool Whether the next key was looked up in this request. */
	private static bool $next_loaded = false;

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
	 * @param bool $create Create and store a key when none exists yet (false: read only).
	 * @return string|null Null when Ed25519 is not available or the key cannot be stored.
	 */
	public static function secret_key( bool $create = true ): ?string {
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
			self::$secret_key = self::stored_secret_key( $stored );
			return self::$secret_key;
		}
		return $create ? self::rotate() : null;
	}

	/**
	 * Secret key from a stored option value (encrypted "v1:…" envelope or plain base64).
	 *
	 * @param string $stored Option value.
	 * @return string|null Binary secret key.
	 */
	private static function stored_secret_key( string $stored ): ?string {
		$plain = 0 === strpos( $stored, 'v1:' ) ? SecretCrypto::decrypt( $stored ) : $stored;
		return is_string( $plain ) ? self::secret_key_from( $plain ) : null;
	}

	/** Option value for a new binary secret key: encrypted when an encryption key ring is configured. */
	private static function storable( string $secret ): string {
		$encoded   = base64_encode( $secret );
		$encrypted = SecretCrypto::encrypt( $encoded );
		return false !== $encrypted ? $encrypted : $encoded;
	}

	/** Whether the current key comes from the constant WPLC_RESPONSE_SIGNING_KEY. */
	public static function current_from_constant(): bool {
		return defined( 'WPLC_RESPONSE_SIGNING_KEY' ) && '' !== (string) WPLC_RESPONSE_SIGNING_KEY;
	}

	/** Whether the next key comes from the constant WPLC_RESPONSE_SIGNING_KEY_NEXT. */
	public static function next_from_constant(): bool {
		return defined( 'WPLC_RESPONSE_SIGNING_KEY_NEXT' ) && '' !== (string) WPLC_RESPONSE_SIGNING_KEY_NEXT;
	}

	/**
	 * The next secret key (binary) during a rotation: constant WPLC_RESPONSE_SIGNING_KEY_NEXT, else the option
	 * wplc_response_signing_key_next. Never created implicitly.
	 *
	 * @return string|null Null when no next key is configured (or it is malformed).
	 */
	public static function next_secret_key(): ?string {
		if ( self::$next_loaded ) {
			return self::$next_secret_key;
		}
		self::$next_loaded     = true;
		self::$next_secret_key = null;
		if ( ! self::available() ) {
			return null;
		}
		if ( self::next_from_constant() ) {
			self::$next_secret_key = self::secret_key_from( (string) WPLC_RESPONSE_SIGNING_KEY_NEXT );
		} elseif ( function_exists( 'get_option' ) ) {
			$stored = (string) get_option( self::OPTION_NEXT, '' );
			self::$next_secret_key = '' === $stored ? null : self::stored_secret_key( $stored );
		}
		return self::$next_secret_key;
	}

	/** Base64 public key of the next key ('' when there is none). */
	public static function next_public_key(): string {
		$secret = self::next_secret_key();
		return null === $secret ? '' : self::public_key_of( $secret );
	}

	/**
	 * Create and store a next key unless one exists already (option only; a constant is set in wp-config.php).
	 *
	 * @return string|null Binary next secret key (existing or new), null when it cannot be stored.
	 */
	public static function create_next(): ?string {
		$existing = self::next_secret_key();
		if ( null !== $existing ) {
			return $existing;
		}
		if ( ! self::available() || ! function_exists( 'update_option' ) || self::next_from_constant() ) {
			return null;
		}
		$secret = sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() );
		update_option( self::OPTION_NEXT, self::storable( $secret ), false );
		self::$next_loaded = false;
		return self::next_secret_key();
	}

	/**
	 * Make the next key the current one and remove the old current key (both stored as options).
	 *
	 * Keys from wp-config.php constants are promoted there: WPLC_RESPONSE_SIGNING_KEY takes the value of
	 * WPLC_RESPONSE_SIGNING_KEY_NEXT, which is then removed.
	 *
	 * @return string|null Binary new current secret key, null when there is no stored next key or a constant is used.
	 */
	public static function promote(): ?string {
		if ( ! self::available() || ! function_exists( 'update_option' ) || ! function_exists( 'delete_option' ) || self::current_from_constant() || self::next_from_constant() ) {
			return null;
		}
		$stored = (string) get_option( self::OPTION_NEXT, '' );
		$next   = '' === $stored ? null : self::stored_secret_key( $stored );
		if ( null === $next ) {
			return null;
		}
		update_option( self::OPTION, $stored, false );
		self::reset();
		if ( self::secret_key( false ) !== $next ) {
			return null;
		}
		delete_option( self::OPTION_NEXT );
		self::reset();
		return self::secret_key( false );
	}

	/**
	 * Create and store a new key (only for the option-based key; a constant is rotated in wp-config.php).
	 *
	 * @return string|null Binary secret key.
	 */
	public static function rotate(): ?string {
		if ( ! self::available() || ! function_exists( 'update_option' ) || self::current_from_constant() ) {
			return null;
		}
		$secret = sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() );
		update_option( self::OPTION, self::storable( $secret ), false );
		if ( '' === (string) get_option( self::OPTION, '' ) ) {
			return null;
		}
		self::$secret_key = $secret;
		return $secret;
	}

	/**
	 * Base64 public key of the server ('' when not available).
	 *
	 * @param bool $create Create the key when none exists yet (false: read only).
	 */
	public static function public_key( bool $create = true ): string {
		$secret = self::secret_key( $create );
		return null === $secret ? '' : self::public_key_of( $secret );
	}

	/**
	 * Signature fields for an envelope: signature_ed25519 and key_id of the current key, plus – while a next key is
	 * configured – `signatures` with both keys (current first). Nothing when Ed25519 is not available.
	 *
	 * @param string $installation_uuid Installation UUID.
	 * @param string $json              JSON of the data.
	 * @return array<string,mixed>
	 */
	public static function envelope_fields( string $installation_uuid, string $json ): array {
		$secret = self::secret_key();
		if ( null === $secret ) {
			return array();
		}
		return self::fields_for( $installation_uuid, $json, $secret, self::next_secret_key() );
	}

	/**
	 * Signature fields made with the given keys (see envelope_fields()).
	 *
	 * @param string      $installation_uuid Installation UUID.
	 * @param string      $json              JSON of the data.
	 * @param string      $current           Binary current secret key.
	 * @param string|null $next              Binary next secret key or null.
	 * @return array<string,mixed>
	 */
	public static function fields_for( string $installation_uuid, string $json, string $current, ?string $next ): array {
		$message = self::message( $installation_uuid, $json );
		$fields  = array(
			'signature_ed25519' => self::sign_with( $message, $current ),
			'key_id'            => self::key_id_of( self::public_key_of( $current ) ),
		);
		if ( null !== $next && $next !== $current ) {
			$fields['signatures'] = array(
				array(
					'key_id'            => $fields['key_id'],
					'signature_ed25519' => $fields['signature_ed25519'],
				),
				array(
					'key_id'            => self::key_id_of( self::public_key_of( $next ) ),
					'signature_ed25519' => self::sign_with( $message, $next ),
				),
			);
		}
		return $fields;
	}

	/**
	 * Public keys for product builds and release checks (GET /wplc/v1/signing-keys): never creates a key.
	 *
	 * @return array{context:string,current:array<string,string>|null,next:array<string,string>|null}
	 */
	public static function public_keys(): array {
		$describe = static function ( string $public ): ?array {
			return '' === $public ? null : array(
				'public_key' => $public,
				'key_id'     => self::key_id_of( $public ),
			);
		};
		$current = self::available() ? self::public_key( false ) : '';
		$next    = self::available() ? self::next_public_key() : '';
		return array(
			'context' => self::CONTEXT,
			'current' => $describe( $current ),
			'next'    => $next === $current ? null : $describe( $next ),
		);
	}

	/** Forget the cached keys (tests, rotation). */
	public static function reset(): void {
		self::$secret_key      = null;
		self::$next_secret_key = null;
		self::$next_loaded     = false;
	}
}
