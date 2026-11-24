<?php
/**
 * Authenticated encryption for stored secrets (installation secrets, signing key, webhook secrets).
 *
 * @package WPLC
 */

namespace WPLC\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encrypts secrets with AES-256-GCM and supports a versioned encryption key ring.
 *
 * Envelope formats (UEB-12):
 * - `v2:<key id>:<base64 nonce|tag|ciphertext>` (current): the additional authenticated data binds the value to
 *   its key ID, its purpose (what kind of secret it is) and its context (the row or option it belongs to). A value
 *   copied into another row, another option or another purpose no longer decrypts.
 * - `v1:<key id>:<…>` and unversioned values: LEGACY FALLBACK. Bound to the key ID only (v1) or to nothing
 *   (unversioned). They still decrypt, so stored values keep working; needs_rotation() reports them, and they are
 *   rewritten as v2 on use, by the hourly batch of SecretMigration and by `wp wplc rotate-installation-secrets`.
 *   The fallback stays while an installation may still hold such values (SecretMigration::legacy_count()).
 */
final class SecretCrypto {

	/** Version prefix of new envelopes. */
	public const FORMAT = 'v2';

	/** Purpose: per-installation request secret (table `activations`, context: installation UUID). */
	public const PURPOSE_INSTALLATION = 'installation-secret';

	/** Purpose: stored Ed25519 response signing key (context: option name). */
	public const PURPOSE_SIGNING_KEY = 'response-signing-key';

	/** Purpose: secret of an outgoing webhook endpoint (table `webhook_endpoints`, context: endpoint ID). */
	public const PURPOSE_WEBHOOK = 'webhook-secret';

	/** Purpose: new webhook secret kept for its one display after the redirect (context: user and endpoint). */
	public const PURPOSE_WEBHOOK_DISPLAY = 'webhook-secret-display';

	/** Envelope pattern: version, key ID, payload. */
	private const ENVELOPE = '/^(v[12]):([a-zA-Z0-9._-]{1,32}):(.+)$/';

	/**
	 * Encrypt a secret for database storage using the configured current key.
	 *
	 * Configure WPLC_INSTALLATION_ENCRYPTION_KEYS as an associative key-id => secret
	 * map and WPLC_INSTALLATION_ENCRYPTION_KEY_ID as the active id in wp-config.php.
	 *
	 * @param string $plaintext Secret.
	 * @param string $purpose   What the value is: one of the PURPOSE_* constants.
	 * @param string $context   Where it is stored: row identifier or option name; must stay the same for the value's life.
	 * @return string|false Versioned key-id envelope or false on failure.
	 */
	public static function encrypt( string $plaintext, string $purpose, string $context ) {
		if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'random_bytes' ) || '' === $purpose ) {
			return false;
		}

		$keys = self::configured_keys();
		$key_id = self::current_key_id( $keys );
		if ( '' === $key_id || ! isset( $keys[ $key_id ] ) || '' === (string) $keys[ $key_id ] ) {
			return false;
		}

		try {
			$nonce = random_bytes( 12 );
		} catch ( \Exception $exception ) {
			return false;
		}

		$tag = '';
		$key = hash( 'sha256', (string) $keys[ $key_id ], true );
		$ciphertext = openssl_encrypt( $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, self::aad( $key_id, $purpose, $context ), 16 );
		if ( false === $ciphertext ) {
			return false;
		}

		return self::FORMAT . ':' . $key_id . ':' . base64_encode( $nonce . $tag . $ciphertext );
	}

	/**
	 * Whether a stored value should be rewritten: it uses a legacy format (v1 or unversioned) or a key other than
	 * the current one.
	 *
	 * @param string $encoded Stored ciphertext.
	 * @return bool
	 */
	public static function needs_rotation( string $encoded ): bool {
		if ( ! preg_match( self::ENVELOPE, $encoded, $matches ) || self::FORMAT !== $matches[1] ) {
			return true;
		}

		return $matches[2] !== self::current_key_id( self::configured_keys() );
	}

	/**
	 * Whether a value is a versioned envelope (v1 or v2), as opposed to plaintext or an unversioned legacy value.
	 *
	 * @param string $value Stored value.
	 * @return bool
	 */
	public static function is_envelope( string $value ): bool {
		return 1 === preg_match( self::ENVELOPE, $value );
	}

	/**
	 * Whether a value uses a legacy format (v1 or unversioned) that is not bound to purpose and context.
	 *
	 * @param string $value Stored value.
	 * @return bool
	 */
	public static function is_legacy( string $value ): bool {
		return ! preg_match( self::ENVELOPE, $value, $matches ) || self::FORMAT !== $matches[1];
	}

	/**
	 * Prefix of values in the current format with the current key (for SQL `LIKE` counts).
	 *
	 * @return string
	 */
	public static function current_prefix(): string {
		return self::FORMAT . ':' . self::active_key_id() . ':';
	}

	/**
	 * Decrypt an envelope.
	 *
	 * A v2 envelope decrypts only with the purpose and context it was encrypted for. LEGACY FALLBACK: v1 envelopes
	 * and unversioned values ignore purpose and context; unversioned values are tried against the configured keys
	 * and the historical WordPress secure_auth salt so installations can migrate without downtime.
	 *
	 * @param string $encoded Encoded ciphertext.
	 * @param string $purpose What the value is: one of the PURPOSE_* constants.
	 * @param string $context Where it is stored: row identifier or option name.
	 * @return string|false
	 */
	public static function decrypt( string $encoded, string $purpose, string $context ) {
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return false;
		}

		$version = '';
		$key_id = '';
		$payload = $encoded;
		if ( preg_match( self::ENVELOPE, $encoded, $matches ) ) {
			$version = $matches[1];
			$key_id = $matches[2];
			$payload = $matches[3];
		}

		$raw = base64_decode( $payload, true );
		if ( false === $raw || strlen( $raw ) < 29 ) {
			return false;
		}

		$nonce = substr( $raw, 0, 12 );
		$tag = substr( $raw, 12, 16 );
		$ciphertext = substr( $raw, 28 );
		$keys = self::configured_keys();
		if ( '' !== $version ) {
			if ( ! isset( $keys[ $key_id ] ) ) {
				return false;
			}
			$keys = array( $key_id => $keys[ $key_id ] );
		} else {
			$keys['legacy-wp-secure-auth'] = wp_salt( 'secure_auth' );
		}

		if ( self::FORMAT === $version ) {
			$aad = self::aad( $key_id, $purpose, $context );
		} elseif ( 'v1' === $version ) {
			$aad = 'wplc-secret-v1:' . $key_id; // Legacy fallback: bound to the key ID only.
		} else {
			$aad = ''; // Legacy fallback: unversioned, no additional data.
		}

		foreach ( $keys as $secret ) {
			if ( '' === (string) $secret ) {
				continue;
			}
			$key = hash( 'sha256', (string) $secret, true );
			$plaintext = openssl_decrypt( $ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad );
			if ( false !== $plaintext ) {
				return $plaintext;
			}
		}

		return false;
	}

	/**
	 * ID of the key that new values are encrypted with.
	 *
	 * @return string
	 */
	public static function active_key_id(): string {
		return self::current_key_id( self::configured_keys() );
	}

	/**
	 * Fingerprint of the key new values are encrypted with, for the key age (SET-LIC-28).
	 *
	 * Key ID and the first 16 hex characters of an HMAC of a fixed text. '' while the WordPress salt stands in.
	 *
	 * @return string
	 */
	public static function fingerprint(): string {
		if ( self::uses_fallback_key() ) {
			return '';
		}
		$keys   = self::configured_keys();
		$key_id = self::current_key_id( $keys );
		if ( ! isset( $keys[ $key_id ] ) || '' === $keys[ $key_id ] ) {
			return '';
		}

		return $key_id . ':' . substr( hash_hmac( 'sha256', 'wplc-key-age', $keys[ $key_id ] ), 0, 16 );
	}

	/**
	 * Whether installation credentials are encrypted with the WordPress
	 * secure_auth salt because no dedicated key is configured in wp-config.php.
	 *
	 * Must mirror the precedence in configured_keys().
	 *
	 * @return bool
	 */
	public static function uses_fallback_key(): bool {
		if ( defined( 'WPLC_INSTALLATION_ENCRYPTION_KEYS' ) && is_array( WPLC_INSTALLATION_ENCRYPTION_KEYS ) ) {
			return false;
		}

		return ! ( defined( 'WPLC_INSTALLATION_ENCRYPTION_KEY' ) && '' !== (string) WPLC_INSTALLATION_ENCRYPTION_KEY );
	}

	/**
	 * Additional authenticated data of a v2 envelope. The lengths keep the fields apart, so two different
	 * purpose/context pairs never give the same data.
	 *
	 * @param string $key_id  Key ID.
	 * @param string $purpose Purpose.
	 * @param string $context Context.
	 * @return string
	 */
	private static function aad( string $key_id, string $purpose, string $context ): string {
		return 'wplc-secret-v2:' . $key_id . ':' . strlen( $purpose ) . ':' . $purpose . ':' . strlen( $context ) . ':' . $context;
	}

	/**
	 * Return configured decryption keys, preserving old keys during rotation.
	 *
	 * @return array<string,string>
	 */
	private static function configured_keys(): array {
		if ( defined( 'WPLC_INSTALLATION_ENCRYPTION_KEYS' ) && is_array( WPLC_INSTALLATION_ENCRYPTION_KEYS ) ) {
			$keys = array();
			foreach ( WPLC_INSTALLATION_ENCRYPTION_KEYS as $key_id => $secret ) {
				$key_id = (string) $key_id;
				if ( preg_match( '/^[a-zA-Z0-9._-]{1,32}$/', $key_id ) && is_string( $secret ) && '' !== $secret ) {
					$keys[ $key_id ] = $secret;
				}
			}
			return $keys;
		}

		if ( defined( 'WPLC_INSTALLATION_ENCRYPTION_KEY' ) && '' !== (string) WPLC_INSTALLATION_ENCRYPTION_KEY ) {
			return array( 'default' => (string) WPLC_INSTALLATION_ENCRYPTION_KEY );
		}

		return array( 'wp-salt' => (string) wp_salt( 'secure_auth' ) );
	}

	/**
	 * Resolve the configured active key id.
	 *
	 * @param array<string,string> $keys Available keys.
	 * @return string
	 */
	private static function current_key_id( array $keys ): string {
		if ( defined( 'WPLC_INSTALLATION_ENCRYPTION_KEY_ID' ) ) {
			return (string) WPLC_INSTALLATION_ENCRYPTION_KEY_ID;
		}

		return (string) key( $keys );
	}
}
