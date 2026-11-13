<?php
/**
 * Admin warning while secrets fall back to WordPress salts.
 *
 * @package WPLC
 */

namespace WPLC\Admin;

use WPLC\License\LicenseKey;
use WPLC\Security\SecretCrypto;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Explains how to configure dedicated secrets in wp-config.php.
 *
 * The notice cannot be dismissed: it disappears once the constants exist.
 * It never prints a real secret, only placeholders.
 */
final class KeyConfigurationNotice {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'admin_notices', array( self::class, 'render' ) );
	}

	/**
	 * Whether any secret still falls back to a WordPress salt.
	 *
	 * @return bool
	 */
	public static function is_fallback_active(): bool {
		return LicenseKey::uses_fallback_secret() || SecretCrypto::uses_fallback_key();
	}

	/**
	 * Render the notice on WooCommerce, plugin, and license screens.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! self::is_fallback_active() || ! current_user_can( 'manage_woocommerce' ) || ! self::is_relevant_screen() ) {
			return;
		}

		$license_fallback = LicenseKey::uses_fallback_secret();
		$crypto_fallback = SecretCrypto::uses_fallback_key();

		echo '<div class="notice notice-error"><p><strong>';
		echo esc_html__( 'WP License Captain: secrets are not configured in wp-config.php.', 'wp-license' );
		echo '</strong></p><p>';
		echo esc_html__( 'License keys and installation credentials currently depend on the WordPress security salts. If the salts are changed (for example by a security plugin, a host migration, or a password reset tool), customers can no longer see their license keys and every activated site loses its connection and must be activated again.', 'wp-license' );
		echo '</p><p>';
		echo esc_html__( 'Add the following to wp-config.php above the line that loads wp-settings.php. Replace every placeholder; the first entry must be an exact copy of the current salt values so existing data stays valid, the second entry is a new random secret of at least 64 characters.', 'wp-license' );
		echo '</p>';
		echo '<pre style="overflow:auto;padding:12px;background:#f6f7f7;border:1px solid #dcdcde;"><code>' . esc_html( self::example( $license_fallback, $crypto_fallback ) ) . '</code></pre>';
		if ( $crypto_fallback ) {
			echo '<p>';
			echo esc_html__( 'Afterwards re-encrypt the stored credentials with the new key by running "wp wplc rotate-installation-secrets" (dry run) and then with "--apply".', 'wp-license' );
			echo '</p>';
		}
		echo '<p>';
		echo esc_html__( 'Keep these values in a password manager and in your backups. This notice disappears as soon as the constants are defined.', 'wp-license' );
		echo '</p></div>';
	}

	/**
	 * Build the copy-ready wp-config.php example with placeholder values.
	 *
	 * @param bool $license_fallback Whether the license-key secret is missing.
	 * @param bool $crypto_fallback Whether the encryption key is missing.
	 * @param string $new_key_id Key ID suggested for the new secret; defaults to the current year and month.
	 * @return string
	 */
	public static function example( bool $license_fallback, bool $crypto_fallback, string $new_key_id = '' ): string {
		if ( ! preg_match( '/^[a-zA-Z0-9._-]{1,32}$/', $new_key_id ) ) {
			$new_key_id = gmdate( 'Y-m' );
		}

		$lines = array();
		if ( $license_fallback ) {
			$lines[] = "define( 'WPLC_LICENSE_KEY_SECRETS', array(";
			$lines[] = "\t'legacy' => 'PASTE-CURRENT-AUTH_KEY-FOLLOWED-BY-AUTH_SALT',";
			$lines[] = "\t'" . $new_key_id . "' => 'REPLACE-WITH-NEW-RANDOM-SECRET-1',";
			$lines[] = ') );';
			$lines[] = "define( 'WPLC_LICENSE_KEY_ID', '" . $new_key_id . "' );";
		}
		if ( $license_fallback && $crypto_fallback ) {
			$lines[] = '';
		}
		if ( $crypto_fallback ) {
			$lines[] = "define( 'WPLC_INSTALLATION_ENCRYPTION_KEYS', array(";
			$lines[] = "\t'wp-salt' => 'PASTE-CURRENT-SECURE_AUTH_KEY-FOLLOWED-BY-SECURE_AUTH_SALT',";
			$lines[] = "\t'" . $new_key_id . "' => 'REPLACE-WITH-NEW-RANDOM-SECRET-2',";
			$lines[] = ') );';
			$lines[] = "define( 'WPLC_INSTALLATION_ENCRYPTION_KEY_ID', '" . $new_key_id . "' );";
		}

		return implode( "\n", $lines );
	}

	/**
	 * Limit the notice to screens where a shop manager works on the shop.
	 *
	 * @return bool
	 */
	private static function is_relevant_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! is_object( $screen ) || empty( $screen->id ) ) {
			return false;
		}

		$id = (string) $screen->id;
		$post_type = isset( $screen->post_type ) ? (string) $screen->post_type : '';
		if ( in_array( $id, array( 'plugins', 'plugins-network' ), true ) || in_array( $post_type, array( 'product', 'shop_order', 'shop_coupon' ), true ) ) {
			return true;
		}
		foreach ( array( 'woocommerce', 'wc-', 'wplc', 'wp-license' ) as $needle ) {
			if ( false !== strpos( $id, $needle ) ) {
				return true;
			}
		}

		return false;
	}
}
