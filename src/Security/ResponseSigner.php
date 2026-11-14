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
 * A second signature covers the exact `payload` text, which also carries the request nonce, so a recorded answer
 * cannot be replayed against a later request (A-30):
 *   "wplc-response-v2\n" . installation UUID . "\n" . payload
 *
 * Key source, first match wins:
 *   1. constant WPLC_RESPONSE_SIGNING_KEY – base64 of the 32-byte seed or the 64-byte secret key (required for
 *      production: keep it out of the database). A malformed value disables the Ed25519 signatures and is reported
 *      by status(); it never falls back to another key (B-67).
 *   2. option wplc_response_signing_key – created once, under a database lock, and only stored encrypted with
 *      SecretCrypto (B-107, B-156). When the stored key is lost or can no longer be decrypted (changed salts or
 *      encryption keys) it is NOT replaced silently: clients pin the public key, so a new key would lock them out.
 *      status() reports the problem and `wp wplc signing-key --rotate` creates a new key deliberately (A-31).
 * `wp wplc signing-key` prints the public key for product builds and rotates the stored key.
 */
final class ResponseSigner {

	public const OPTION  = 'wplc_response_signing_key';
	public const CONTEXT = 'wplc-response-v1';
	public const PAYLOAD_CONTEXT = 'wplc-response-v2';

	/** Option that remembers the key ID of the stored key, so a lost key is detected instead of replaced. */
	public const KEY_ID_OPTION = 'wplc_response_signing_key_id';

	/** @var string|null Binary secret key of this request. */
	private static ?string $secret_key = null;

	/** @var string State of the key of this request: see status(). */
	private static string $state = '';

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
	 * Message that is signed for the payload text of an envelope (binds the request nonce).
	 *
	 * @param string $installation_uuid Installation UUID.
	 * @param string $payload           The `payload` member exactly as sent.
	 */
	public static function payload_message( string $installation_uuid, string $payload ): string {
		return self::PAYLOAD_CONTEXT . "\n" . strtolower( $installation_uuid ) . "\n" . $payload;
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
	 * A 64-byte value must be a real Ed25519 secret key (seed followed by the
	 * matching public key); the result is checked by signing and verifying a
	 * probe (B-67).
	 *
	 * @param string $encoded Base64 value.
	 * @return string|null Binary secret key, null when the value is not a usable key.
	 */
	public static function secret_key_from( string $encoded ): ?string {
		$raw = base64_decode( trim( $encoded ), true );
		if ( false === $raw || ! in_array( strlen( $raw ), array( 32, 64 ), true ) ) {
			return null;
		}
		try {
			$secret = sodium_crypto_sign_secretkey( sodium_crypto_sign_seed_keypair( substr( $raw, 0, 32 ) ) );
			if ( 64 === strlen( $raw ) && ! hash_equals( $secret, $raw ) ) {
				return null;
			}
			$probe  = 'wplc-signing-key-self-test';
			$public = sodium_crypto_sign_publickey_from_secretkey( $secret );
			if ( ! sodium_crypto_sign_verify_detached( sodium_crypto_sign_detached( $probe, $secret ), $probe, $public ) ) {
				return null;
			}
		} catch ( \Throwable $e ) {
			return null;
		}

		return $secret;
	}

	/** Base64 public key belonging to a binary secret key. */
	public static function public_key_of( string $secret_key ): string {
		return base64_encode( sodium_crypto_sign_publickey_from_secretkey( $secret_key ) );
	}

	/** Whether the key is taken from the constant in wp-config.php. */
	public static function uses_constant(): bool {
		return defined( 'WPLC_RESPONSE_SIGNING_KEY' ) && '' !== (string) constant( 'WPLC_RESPONSE_SIGNING_KEY' );
	}

	/**
	 * The server's secret key (binary).
	 *
	 * Without the constant the key is created once and stored encrypted; see the class comment for when it is
	 * deliberately not created.
	 *
	 * @return string|null Null when no usable key exists; status() tells why.
	 */
	public static function secret_key(): ?string {
		if ( null !== self::$secret_key ) {
			return self::$secret_key;
		}
		if ( '' !== self::$state ) {
			// Already failed in this request; do not repeat the work for every response.
			return null;
		}
		if ( ! self::available() ) {
			return self::fail( 'sodium_unavailable' );
		}
		if ( self::uses_constant() ) {
			$secret = self::secret_key_from( (string) constant( 'WPLC_RESPONSE_SIGNING_KEY' ) );
			if ( null === $secret ) {
				return self::fail( 'constant_invalid' );
			}
			self::$state      = 'ok';
			self::$secret_key = $secret;

			return $secret;
		}
		if ( ! function_exists( 'get_option' ) ) {
			return null;
		}

		$stored = (string) get_option( self::OPTION, '' );
		if ( '' === $stored ) {
			return self::create_stored_key();
		}

		return self::load_stored_key( $stored );
	}

	/**
	 * Create and store a new key deliberately (only for the option-based key; a constant is rotated in wp-config.php).
	 *
	 * Clients that pin the old public key reject the server afterwards.
	 *
	 * @return string|null Binary secret key, null when it could not be stored encrypted.
	 */
	public static function rotate(): ?string {
		if ( ! self::available() || ! function_exists( 'update_option' ) || self::uses_constant() ) {
			return null;
		}
		$secret    = sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() );
		$encrypted = SecretCrypto::encrypt( base64_encode( $secret ) );
		if ( false === $encrypted ) {
			// Never store the private key in plaintext (B-156).
			self::reset();

			return self::fail( 'encryption_unavailable' );
		}
		update_option( self::OPTION, $encrypted, false );
		if ( (string) get_option( self::OPTION, '' ) !== $encrypted ) {
			self::reset();

			return self::fail( 'storage_failed' );
		}
		update_option( self::KEY_ID_OPTION, self::key_id_of( self::public_key_of( $secret ) ), false );
		self::$secret_key = $secret;
		self::$state      = 'ok';

		return $secret;
	}

	/**
	 * Re-encrypt the stored key with the current encryption key (called by `wp wplc rotate-installation-secrets`).
	 *
	 * @param bool $apply False only reports what would happen.
	 * @return string `none` (constant or no stored key), `current`, `rotated`, `pending` (dry run) or `failed`.
	 */
	public static function reencrypt_stored_key( bool $apply ): string {
		if ( self::uses_constant() || ! function_exists( 'get_option' ) ) {
			return 'none';
		}
		$stored = (string) get_option( self::OPTION, '' );
		if ( '' === $stored ) {
			return 'none';
		}
		$encrypted = 0 === strpos( $stored, 'v1:' );
		if ( $encrypted && ! SecretCrypto::needs_rotation( $stored ) ) {
			return 'current';
		}
		$plain = $encrypted ? SecretCrypto::decrypt( $stored ) : $stored;
		if ( ! is_string( $plain ) || null === self::secret_key_from( $plain ) ) {
			return 'failed';
		}
		if ( ! $apply ) {
			return 'pending';
		}
		$fresh = SecretCrypto::encrypt( $plain );
		if ( false === $fresh ) {
			return 'failed';
		}
		update_option( self::OPTION, $fresh, false );

		return (string) get_option( self::OPTION, '' ) === $fresh ? 'rotated' : 'failed';
	}

	/**
	 * State of the signing key for diagnostics, notices and health alerts.
	 *
	 * `state` is one of: `ok`; `sodium_unavailable`; `constant_invalid` (WPLC_RESPONSE_SIGNING_KEY is not a base64
	 * seed or secret key); `undecryptable` (the stored key cannot be decrypted with the configured keys or salts);
	 * `corrupt`; `missing` (a key existed and its option is gone); `encryption_unavailable` (the key could not be
	 * encrypted and is therefore not stored); `storage_failed`; `busy` (another request is creating the key);
	 * `unavailable` (no option storage). In every state but `ok` responses carry no Ed25519 signature.
	 *
	 * @return array{state:string,source:string,key_id:string,public_key:string}
	 */
	public static function status(): array {
		$secret = self::secret_key();
		$public = null === $secret ? '' : self::public_key_of( $secret );
		$state  = '' === self::$state ? 'unavailable' : self::$state;

		return array(
			'state'      => null === $secret ? $state : 'ok',
			'source'     => self::uses_constant() ? 'constant' : 'option',
			'key_id'     => '' === $public ? '' : self::key_id_of( $public ),
			'public_key' => $public,
		);
	}

	/** Base64 public key of the server ('' when not available). */
	public static function public_key(): string {
		$secret = self::secret_key();
		return null === $secret ? '' : self::public_key_of( $secret );
	}

	/**
	 * Signature fields for an envelope, or nothing when no signing key is usable.
	 *
	 * - `signature_ed25519`: signature of message() over the JSON of the data (kept for compatibility; it is not
	 *   bound to a request).
	 * - `payload_signature_ed25519`: signature of payload_message() over the payload text, which includes the
	 *   request nonce (A-30). Present when a payload is given.
	 * - `key_id`: identifier of the signing key.
	 *
	 * @param string $installation_uuid Installation UUID.
	 * @param string $json              JSON of the data.
	 * @param string $payload           The payload text of the envelope ('' to omit its signature).
	 * @return array<string,string>
	 */
	public static function envelope_fields( string $installation_uuid, string $json, string $payload = '' ): array {
		$secret = self::secret_key();
		if ( null === $secret ) {
			return array();
		}
		$fields = array(
			'signature_ed25519' => self::sign_with( self::message( $installation_uuid, $json ), $secret ),
		);
		if ( '' !== $payload ) {
			$fields['payload_signature_ed25519'] = self::sign_with( self::payload_message( $installation_uuid, $payload ), $secret );
		}
		$fields['key_id'] = self::key_id_of( self::public_key_of( $secret ) );

		return $fields;
	}

	/** Forget the cached key and state (tests, rotation). */
	public static function reset(): void {
		self::$secret_key = null;
		self::$state      = '';
	}

	/**
	 * Read a stored key; plaintext values of older versions are encrypted in place.
	 *
	 * @param string $stored Option value.
	 * @return string|null
	 */
	private static function load_stored_key( string $stored ): ?string {
		$encrypted = 0 === strpos( $stored, 'v1:' );
		$plain     = $encrypted ? SecretCrypto::decrypt( $stored ) : $stored;
		if ( ! is_string( $plain ) ) {
			return self::fail( 'undecryptable' );
		}
		$secret = self::secret_key_from( $plain );
		if ( null === $secret ) {
			return self::fail( 'corrupt' );
		}
		if ( ! $encrypted && function_exists( 'update_option' ) ) {
			$fresh = SecretCrypto::encrypt( $plain );
			if ( false !== $fresh ) {
				update_option( self::OPTION, $fresh, false );
			}
		}
		if ( function_exists( 'update_option' ) && '' === (string) get_option( self::KEY_ID_OPTION, '' ) ) {
			update_option( self::KEY_ID_OPTION, self::key_id_of( self::public_key_of( $secret ) ), false );
		}
		self::$state      = 'ok';
		self::$secret_key = $secret;

		return $secret;
	}

	/**
	 * Create the stored key on first use.
	 *
	 * Serialized by a named database lock and re-read from the database, so
	 * two parallel first responses cannot sign with different keys (B-107).
	 *
	 * @return string|null
	 */
	private static function create_stored_key(): ?string {
		global $wpdb;

		if ( ! function_exists( 'add_option' ) || ! is_object( $wpdb ) ) {
			return null;
		}
		if ( '' !== (string) get_option( self::KEY_ID_OPTION, '' ) ) {
			// A key existed before. Creating a new one silently would lock out every client that pins the old one.
			return self::fail( 'missing' );
		}

		$lock_name = 'wplc_' . substr( hash( 'sha256', \WPLC\Infrastructure\Database\Schema::lock_scope() . '|response-signing-key' ), 0, 48 );
		$locked    = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name ) );
		if ( '1' !== (string) $locked ) {
			return self::fail( 'busy' );
		}

		try {
			// Another request may have stored the key while this one waited; bypass the option cache.
			$current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", self::OPTION ) );
			if ( is_string( $current ) && '' !== $current ) {
				wp_cache_delete( self::OPTION, 'options' );
				wp_cache_delete( 'notoptions', 'options' );

				return self::load_stored_key( $current );
			}

			$secret    = sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() );
			$encrypted = SecretCrypto::encrypt( base64_encode( $secret ) );
			if ( false === $encrypted ) {
				// Never store the private key in plaintext (B-156).
				return self::fail( 'encryption_unavailable' );
			}
			delete_option( self::OPTION );
			if ( ! add_option( self::OPTION, $encrypted, '', false ) ) {
				return self::fail( 'storage_failed' );
			}
			$key_id = self::key_id_of( self::public_key_of( $secret ) );
			update_option( self::KEY_ID_OPTION, $key_id, false );
			self::$state      = 'ok';
			self::$secret_key = $secret;

			\WPLC\License\AuditLog::record( 'response_signing_key_created', 'system', 0, 'Response signing key created on first use', null, array( 'key_id' => $key_id ) );

			/**
			 * Fires once when the server creates its Ed25519 response signing key.
			 *
			 * Production servers should set WPLC_RESPONSE_SIGNING_KEY instead.
			 *
			 * @param string $key_id Key ID of the new key.
			 */
			do_action( 'wplc/response_signing_key_created', $key_id );

			return $secret;
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}

	/**
	 * Remember why no key is available and tell listeners once per request.
	 *
	 * @param string $state State, see status().
	 * @return null
	 */
	private static function fail( string $state ) {
		self::$state = $state;
		if ( function_exists( 'do_action' ) && ! in_array( $state, array( 'busy', 'sodium_unavailable' ), true ) ) {
			/**
			 * Fires when responses cannot be signed with the server key.
			 *
			 * Clients that pin the public key reject unsigned responses, so
			 * this should raise an alert.
			 *
			 * @param string $state Reason, see ResponseSigner::status().
			 */
			do_action( 'wplc/response_signing_key_unavailable', $state );
		}

		return null;
	}
}
