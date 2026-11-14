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
 * The notice cannot be dismissed: it disappears once the constants exist and the
 * issued keys verify with them. It never prints a real secret, only placeholders.
 */
final class KeyConfigurationNotice {

	/**
	 * Name of the WooCommerce inbox note that mirrors the notice on the React pages.
	 */
	public const NOTE_NAME = 'wplc-key-configuration';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'admin_notices', array( self::class, 'render' ) );
		add_action( 'admin_init', array( self::class, 'sync_inbox_note' ) );
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
	 * Number of recent license keys that the configured secret cannot reproduce.
	 *
	 * @return array{checked:int,failed:int}
	 */
	public static function unverifiable_keys(): array {
		$sample = Diagnostics::key_sample();

		return array(
			'checked' => $sample['checked'],
			'failed'  => max( 0, $sample['checked'] - $sample['verified'] ),
		);
	}

	/**
	 * Whether the notice is due: secrets are missing, or issued keys do not verify.
	 *
	 * Defining the constants alone does not hide it; a wrong secret is as harmful as a missing one (B-64).
	 *
	 * @return bool
	 */
	public static function has_problem(): bool {
		return self::is_fallback_active() || self::unverifiable_keys()['failed'] > 0;
	}

	/**
	 * Render the notice on WooCommerce, plugin, and license screens.
	 *
	 * The wp-config.php instructions are only shown to users who can act on them
	 * (`manage_options`); everyone else working on licenses is told whom to ask.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! self::is_relevant_screen() || ! ( current_user_can( 'manage_options' ) || Capabilities::can( 'view_licenses' ) || Capabilities::can( 'manage_settings' ) ) ) {
			return;
		}
		$fallback = self::is_fallback_active();
		$keys     = self::unverifiable_keys();
		if ( ! $fallback && 0 === $keys['failed'] ) {
			return;
		}

		echo '<div class="notice notice-error">';
		if ( $keys['failed'] > 0 ) {
			echo '<p><strong>';
			echo esc_html__( 'WP License Captain: issued license keys do not match the configured secret.', 'wp-license' );
			echo '</strong></p><p>';
			echo esc_html(
				sprintf(
					/* translators: 1: number of keys that cannot be verified, 2: number of checked keys. */
					__( '%1$d of the %2$d most recent license keys cannot be reproduced. These customers see "not available" instead of their key, and their keys are rejected on activation. The secret in wp-config.php or the WordPress salts were changed.', 'wp-license' ),
					$keys['failed'],
					$keys['checked']
				)
			);
			echo '</p>';
			if ( current_user_can( 'manage_options' ) ) {
				echo '<p>' . esc_html__( 'Add the previous secret as an additional entry to WPLC_LICENSE_KEY_SECRETS in wp-config.php (keep the current one), then run "wp wplc recover-license-key-ids" as a dry run and afterwards with "--apply". This notice stays until the keys verify again.', 'wp-license' ) . '</p>';
			}
		}

		if ( $fallback ) {
			echo '<p><strong>';
			echo esc_html__( 'WP License Captain: secrets are not configured in wp-config.php.', 'wp-license' );
			echo '</strong></p><p>';
			echo esc_html__( 'License keys and installation credentials currently depend on the WordPress security salts. If the salts are changed (for example by a security plugin, a host migration, or a password reset tool), customers can no longer see their license keys and every activated site loses its connection and must be activated again.', 'wp-license' );
			echo '</p>';
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			echo '<p>' . esc_html__( 'Only an administrator with access to wp-config.php can fix this. Please pass this message on.', 'wp-license' ) . '</p></div>';
			return;
		}

		if ( $fallback ) {
			$license_fallback = LicenseKey::uses_fallback_secret();
			$crypto_fallback  = SecretCrypto::uses_fallback_key();
			echo '<p>';
			echo esc_html__( 'Add the following to wp-config.php above the line that loads wp-settings.php. Replace every placeholder; the first entry must be an exact copy of the current salt values so existing data stays valid, the second entry is a new random secret of at least 64 characters.', 'wp-license' );
			echo '</p>';
			echo '<pre style="overflow:auto;padding:12px;background:#f6f7f7;border:1px solid #dcdcde;"><code>' . esc_html( self::example( $license_fallback, $crypto_fallback ) ) . '</code></pre>';
			if ( $crypto_fallback ) {
				echo '<p>';
				echo esc_html__( 'Afterwards re-encrypt the stored credentials with the new key by running "wp wplc rotate-installation-secrets" (dry run) and then with "--apply".', 'wp-license' );
				echo '</p>';
			}
			echo '<p>';
			echo esc_html__( 'Keep these values in a password manager and in your backups. This notice disappears as soon as the constants are defined and the issued keys verify.', 'wp-license' );
			echo '</p>';
		}
		echo '</div>';
	}

	/**
	 * Mirror the problem as a WooCommerce inbox note.
	 *
	 * The React pages of WooCommerce (Home, Analytics) do not show classic admin
	 * notices; the inbox on the home screen does show notes. The note carries no
	 * wp-config.php details because every shop manager can read it.
	 *
	 * @return void
	 */
	public static function sync_inbox_note(): void {
		$notes = '\\Automattic\\WooCommerce\\Admin\\Notes\\Notes';
		$note  = '\\Automattic\\WooCommerce\\Admin\\Notes\\Note';
		if ( wp_doing_ajax() || ! class_exists( $notes ) || ! class_exists( $note ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		try {
			$data_store = \WC_Data_Store::load( 'admin-note' );
			// WC_Data_Store forwards unknown methods to the notes data store.
			$existing = call_user_func( array( $data_store, 'get_notes_with_name' ), self::NOTE_NAME );
			$problem    = self::has_problem();
			if ( ! $problem ) {
				if ( $existing ) {
					call_user_func( array( $notes, 'delete_notes_with_name' ), self::NOTE_NAME );
				}
				return;
			}
			if ( $existing ) {
				return;
			}

			$item = new $note();
			$item->set_name( self::NOTE_NAME );
			$item->set_source( 'wp-license' );
			// The inbox lists info, marketing, survey and warning notes; "error" notes are not displayed there.
			$item->set_type( 'warning' );
			$item->set_title( __( 'WP License Captain: license key secrets need attention', 'wp-license' ) );
			$item->set_content( __( 'The secrets for license keys are not configured in wp-config.php, or issued license keys no longer match the configured secret. Customers may lose access to their keys. An administrator finds the details on the license settings page.', 'wp-license' ) );
			$item->add_action( 'wplc-open-settings', __( 'Open license settings', 'wp-license' ), admin_url( 'admin.php?page=' . Settings::PAGE ) );
			$item->save();
		} catch ( \Throwable $error ) {
			// The inbox is a convenience; the classic notice remains.
			return;
		}
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
		if ( in_array( $id, array( 'plugins', 'plugins-network', 'dashboard' ), true ) || in_array( $post_type, array( 'product', 'shop_order', 'shop_coupon' ), true ) ) {
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
