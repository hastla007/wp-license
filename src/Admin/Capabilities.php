<?php
/**
 * Graded capabilities for the license administration.
 *
 * @package WPLC
 */

namespace WPLC\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps the plugin's own capabilities onto WordPress roles.
 *
 * No capability is written to the database. A user holds a `wplc_*` capability when
 *
 * - a role or the user grants or denies it explicitly (e.g. a custom support role), or
 * - nothing is stored and the user holds the default capability from defaults().
 *
 * By default administrators and shop managers keep their previous access, except
 * for publishing releases, changing the trusted package hosts and restoring revoked
 * licenses: these deliver code or access to customers and need `manage_options`.
 *
 * Other packages ask `apply_filters( 'wplc_capability', $default_cap, $action_slug )`;
 * this class answers with the matching `wplc_*` capability.
 */
final class Capabilities {

	public const VIEW_LICENSES        = 'wplc_view_licenses';
	public const MANAGE_LICENSES      = 'wplc_manage_licenses';
	public const GRANT_LICENSES       = 'wplc_grant_licenses';
	public const RESTORE_LICENSES     = 'wplc_restore_licenses';
	public const MANAGE_CATALOG       = 'wplc_manage_catalog';
	public const PUBLISH_RELEASES     = 'wplc_publish_releases';
	public const VIEW_DIAGNOSTICS     = 'wplc_view_diagnostics';
	public const MANAGE_SETTINGS      = 'wplc_manage_settings';
	public const MANAGE_PACKAGE_HOSTS = 'wplc_manage_package_hosts';

	/**
	 * Register the capability filters.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		if ( false === has_filter( 'user_has_cap', array( self::class, 'grant_defaults' ) ) ) {
			add_filter( 'user_has_cap', array( self::class, 'grant_defaults' ), 10, 2 );
			// Early, so a site owner's own callback at the default priority has the last word.
			add_filter( 'wplc_capability', array( self::class, 'filter_capability' ), 5, 2 );
		}
	}

	/**
	 * Plugin capability => capability that grants it by default.
	 *
	 * @return array<string,string>
	 */
	public static function defaults(): array {
		return array(
			self::VIEW_LICENSES        => 'manage_woocommerce',
			self::MANAGE_LICENSES      => 'manage_woocommerce',
			self::GRANT_LICENSES       => 'manage_woocommerce',
			self::MANAGE_CATALOG       => 'manage_woocommerce',
			self::VIEW_DIAGNOSTICS     => 'manage_woocommerce',
			self::MANAGE_SETTINGS      => 'manage_woocommerce',
			self::RESTORE_LICENSES     => 'manage_options',
			self::PUBLISH_RELEASES     => 'manage_options',
			self::MANAGE_PACKAGE_HOSTS => 'manage_options',
		);
	}

	/**
	 * Resolve an action slug ("manage_licenses") or capability name to the capability to check.
	 *
	 * @param string $slug Action slug with or without the wplc_ prefix.
	 * @return string
	 */
	public static function cap( string $slug ): string {
		$slug = self::slug( $slug );
		$name = 'wplc_' . $slug;
		$cap  = isset( self::defaults()[ $name ] ) ? $name : 'manage_woocommerce';

		/**
		 * Filters the capability required for a license administration action.
		 *
		 * @since 0.1.0
		 *
		 * @param string $cap  Capability to check.
		 * @param string $slug Action slug, e.g. view_licenses, manage_licenses, grant_licenses,
		 *                     restore_licenses, manage_catalog, publish_releases, view_diagnostics,
		 *                     manage_settings, manage_package_hosts.
		 */
		$filtered = (string) apply_filters( 'wplc_capability', $cap, $slug );

		return '' !== $filtered ? $filtered : $cap;
	}

	/**
	 * Whether the current user may perform an action.
	 *
	 * @param string $slug Action slug or capability name.
	 * @return bool
	 */
	public static function can( string $slug ): bool {
		return current_user_can( self::cap( $slug ) );
	}

	/**
	 * Answer `wplc_capability` for the known action slugs.
	 *
	 * Callers of other packages pass their own default (usually `manage_woocommerce`);
	 * for a known slug it is replaced by the graded capability.
	 *
	 * @param mixed $cap  Capability proposed by the caller.
	 * @param mixed $slug Action slug.
	 * @return mixed
	 */
	public static function filter_capability( $cap, $slug = '' ) {
		if ( ! is_string( $slug ) || '' === $slug ) {
			return $cap;
		}
		$name = 'wplc_' . self::slug( $slug );

		return isset( self::defaults()[ $name ] ) ? $name : $cap;
	}

	/**
	 * Grant the plugin capabilities that are not stored on a role from their defaults.
	 *
	 * @param mixed $allcaps Capabilities of the user (capability => bool).
	 * @param mixed $caps    Primitive capabilities being checked.
	 * @return mixed
	 */
	public static function grant_defaults( $allcaps, $caps = array() ) {
		if ( ! is_array( $allcaps ) || ! is_array( $caps ) ) {
			return $allcaps;
		}
		$defaults = self::defaults();
		foreach ( $caps as $cap ) {
			if ( ! is_string( $cap ) || ! isset( $defaults[ $cap ] ) || array_key_exists( $cap, $allcaps ) ) {
				// An explicit grant or denial on a role wins.
				continue;
			}
			$allcaps[ $cap ] = ! empty( $allcaps[ $defaults[ $cap ] ] );
		}

		return $allcaps;
	}

	/**
	 * Menu under which the plugin pages are registered for the current user.
	 *
	 * The WooCommerce menu is only visible with `manage_woocommerce`; a support role
	 * without it gets the license list as its own top-level menu.
	 *
	 * @return string Parent menu slug.
	 */
	public static function menu_parent(): string {
		return current_user_can( 'manage_woocommerce' ) ? 'woocommerce' : LicenseAdmin::PAGE;
	}

	/**
	 * End an unauthorized request. Only this and an invalid nonce may end in wp_die().
	 *
	 * @param string $slug    Action slug.
	 * @param string $message Translated message; a generic one when empty.
	 * @return void
	 */
	public static function require_cap( string $slug, string $message = '' ): void {
		if ( self::can( $slug ) ) {
			return;
		}
		wp_die(
			esc_html( '' !== $message ? $message : __( 'You do not have permission to do this.', 'wp-license' ) ),
			'',
			array( 'response' => 403 )
		);
	}

	/**
	 * Normalize an action slug.
	 *
	 * @param string $slug Slug or capability name.
	 * @return string
	 */
	private static function slug( string $slug ): string {
		$slug = strtolower( trim( $slug ) );
		$slug = (string) preg_replace( '/[^a-z0-9_]/', '', $slug );

		return 0 === strpos( $slug, 'wplc_' ) ? substr( $slug, 5 ) : $slug;
	}
}
