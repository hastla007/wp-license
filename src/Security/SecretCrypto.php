<?php
/**
 * Authenticated encryption for per-installation request secrets.
 *
 * @package WPLC
 */

namespace WPLC\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encrypts secrets with AES-256-GCM and supports a versioned encryption key ring.
 */
final class SecretCrypto {
	/**
	 * Encrypt a secret for database storage using the configured current key.
	 *
	 * Configure WPLC_INSTALLATION_ENCRYPTION_KEYS as an associative key-id => secret
	 * map and WPLC_INSTALLATION_ENCRYPTION_KEY_ID as the active id in wp-config.php.
	 *
	 * @param string $plaintext Secret.
	 * @return string|false Versioned key-id envelope or false on failure.
	 */
	public static function encrypt( string $plaintext ) {
		if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'random_bytes' ) ) {
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
		$aad = 'wplc-secret-v1:' . $key_id;
		$key = hash( 'sha256', (string) $keys[ $key_id ], true );
		$ciphertext = openssl_encrypt( $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad, 16 );
		if ( false === $ciphertext ) {
			return false;
		}

		return 'v1:' . $key_id . ':' . base64_encode( $nonce . $tag . $ciphertext );
	}

	/**
	 * Whether an authenticated legacy/retained-key envelope should be rewritten.
	 *
	 * @param string $encoded Stored ciphertext.
	 * @return bool
	 */
	public static function needs_rotation( string $encoded ): bool {
		if ( ! preg_match( '/^v1:([a-zA-Z0-9._-]{1,32}):(.+)$/', $encoded, $matches ) ) {
			return true;
		}

		return $matches[1] !== self::current_key_id( self::configured_keys() );
	}

	/**
	 * Decrypt a versioned envelope or a legacy unversioned value.
	 *
	 * Legacy values are tried against the configured keys and the historical
	 * WordPress secure_auth salt so installations can migrate without downtime.
	 *
	 * @param string $encoded Encoded ciphertext.
	 * @return string|false
	 */
	public static function decrypt( string $encoded ) {
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return false;
		}

		$version = '';
		$key_id = '';
		$payload = $encoded;
		if ( preg_match( '/^v1:([a-zA-Z0-9._-]{1,32}):(.+)$/', $encoded, $matches ) ) {
			$version = 'v1';
			$key_id = $matches[1];
			$payload = $matches[2];
		}

		$raw = base64_decode( $payload, true );
		if ( false === $raw || strlen( $raw ) < 29 ) {
			return false;
		}

		$nonce = substr( $raw, 0, 12 );
		$tag = substr( $raw, 12, 16 );
		$ciphertext = substr( $raw, 28 );
		$keys = self::configured_keys();
		if ( 'v1' === $version ) {
			if ( ! isset( $keys[ $key_id ] ) ) {
				return false;
			}
			$keys = array( $key_id => $keys[ $key_id ] );
		} else {
			$keys['legacy-wp-secure-auth'] = wp_salt( 'secure_auth' );
		}

		foreach ( $keys as $candidate_id => $secret ) {
			if ( '' === (string) $secret ) {
				continue;
			}
			$key = hash( 'sha256', (string) $secret, true );
			$aad = 'v1' === $version ? 'wplc-secret-v1:' . $key_id : '';
			$plaintext = openssl_decrypt( $ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad );
			if ( false !== $plaintext ) {
				return $plaintext;
			}
		}

		return false;
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
