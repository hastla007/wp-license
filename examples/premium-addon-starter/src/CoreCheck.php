<?php
/**
 * Minimum version check of the free core plugin.
 *
 * @package Vendor\Product\Premium
 */

namespace Vendor\Product\Premium;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decides whether the add-on may load. "Requires Plugins" in the header only makes sure the core plugin is active;
 * the version has to be checked at runtime.
 */
final class CoreCheck {

	/**
	 * Whether the add-on may load (no translation, safe before init).
	 *
	 * @param string $constant    Constant with the core plugin's version.
	 * @param string $min_version Lowest supported core version.
	 * @param string $php_version PHP version to check; the running one by default (tests pass another one).
	 */
	public static function passes( string $constant = Config::CORE_VERSION_CONSTANT, string $min_version = Config::CORE_MIN_VERSION, string $php_version = PHP_VERSION ): bool {
		return version_compare( $php_version, Config::MIN_PHP, '>=' ) && defined( $constant ) && version_compare( (string) constant( $constant ), $min_version, '>=' );
	}

	/**
	 * Why the add-on cannot load, or '' when it can. Translated: call it at init or later.
	 *
	 * @param string $constant    Constant with the core plugin's version.
	 * @param string $min_version Lowest supported core version.
	 * @param string $core_name   Core plugin name for the message.
	 * @param string $php_version PHP version to check; the running one by default (tests pass another one).
	 */
	public static function problem( string $constant = Config::CORE_VERSION_CONSTANT, string $min_version = Config::CORE_MIN_VERSION, string $core_name = Config::CORE_NAME, string $php_version = PHP_VERSION ): string {
		if ( version_compare( $php_version, Config::MIN_PHP, '<' ) ) {
			/* translators: 1: required PHP version, 2: running PHP version. */
			return sprintf( __( 'This add-on needs PHP %1$s or newer; this site runs PHP %2$s. Premium features are off.', 'premium-addon-starter' ), Config::MIN_PHP, $php_version );
		}
		if ( ! defined( $constant ) ) {
			/* translators: %s: core plugin name. */
			return sprintf( __( 'This add-on needs the free plugin %s. Install and activate it; premium features are off until then.', 'premium-addon-starter' ), $core_name );
		}
		$version = (string) constant( $constant );
		if ( version_compare( $version, $min_version, '<' ) ) {
			/* translators: 1: core plugin name, 2: required version, 3: installed version. */
			return sprintf( __( 'This add-on needs %1$s %2$s or newer (installed: %3$s). Update it; premium features are off until then.', 'premium-addon-starter' ), $core_name, $min_version, $version );
		}
		return '';
	}
}
