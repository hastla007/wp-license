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
 * Encrypts secrets using AES-256-GCM with a WordPress-derived key.
 */
final class SecretCrypto {

	/**
	 * Encrypt a secret for database storage.
	 *
	 * @param string $plaintext Secret.
	 * @return string|false Base64 encoded nonce, tag and ciphertext.
	 */
	public static function encrypt( string $plaintext ) {
		if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'random_bytes' ) ) {
			return false;
		}

		try {
			$nonce = random_bytes( 12 );
		} catch ( \Exception $exception ) {
			return false;
		}

		$tag = '';
		$key = hash( 'sha256', wp_salt( 'secure_auth' ), true );
		$ciphertext = openssl_encrypt( $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, '', 16 );
		if ( false === $ciphertext ) {
			return false;
		}

		return base64_encode( $nonce . $tag . $ciphertext );
	}

	/**
	 * Decrypt a stored secret.
	 *
	 * @param string $encoded Encoded value.
	 * @return string|false
	 */
	public static function decrypt( string $encoded ) {
		$raw = base64_decode( $encoded, true );
		if ( false === $raw || strlen( $raw ) < 29 || ! function_exists( 'openssl_decrypt' ) ) {
			return false;
		}

		$nonce = substr( $raw, 0, 12 );
		$tag = substr( $raw, 12, 16 );
		$ciphertext = substr( $raw, 28 );
		$key = hash( 'sha256', wp_salt( 'secure_auth' ), true );

		return openssl_decrypt( $ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, '' );
	}
}
