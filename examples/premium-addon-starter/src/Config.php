<?php
/**
 * Build-time configuration of the add-on.
 *
 * @package Vendor\Product\Premium
 */

namespace Vendor\Product\Premium;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every value a product has to fill in lives here (see README.md, "What each product fills in").
 *
 * The constants are the values of the release build. A test shop can override the server URL and the pinned keys in
 * wp-config.php (PREMIUM_ADDON_STARTER_SERVER_URL, PREMIUM_ADDON_STARTER_SERVER_PUBLIC_KEYS) or with the filters
 * documented on each method; release builds should not rely on that. The key filter only works while
 * PREMIUM_ADDON_STARTER_LICENSE_DEV is defined and true, so another plugin cannot pin a key of its own.
 */
final class Config {
	/** Plugin slug = folder name = text domain. */
	public const SLUG = 'premium-addon-starter';

	/** Prefix of options, hooks, cron events and transients. */
	public const PREFIX = 'premium_addon_starter';

	/** Product key exactly as in the license server catalog (WooCommerce product → License product key). */
	public const PRODUCT_KEY = 'product-key';

	/** Prefix of every entitlement key of this product (voucher., pass., affiliate.). */
	public const ENTITLEMENT_PREFIX = 'product.';

	/** HTTPS site URL of the license server (WP License Captain shop). */
	public const SERVER_URL = 'https://shop.example';

	/**
	 * Pinned Ed25519 public key(s) of the license server, base64.
	 *
	 * Get it on the license server with `wp wplc signing-key --field=public_key`. During a key rotation list the new
	 * and the old key, drop the old one in a later release. While this is empty the add-on refuses to activate and
	 * every premium feature stays off (fail closed).
	 */
	public const SERVER_PUBLIC_KEYS = array();

	/** Name of the free core plugin (shown in the "core missing" notice). */
	public const CORE_NAME = 'Core Plugin';

	/** Constant the core plugin defines with its version, e.g. VCAP_VERSION, WPCAP_VERSION, WPAC_VERSION. */
	public const CORE_VERSION_CONSTANT = 'CORE_PLUGIN_VERSION';

	/** Lowest core plugin version this add-on works with. */
	public const CORE_MIN_VERSION = '1.0.0';

	/** Lowest PHP version (keep in sync with "Requires PHP" in the plugin header). */
	public const MIN_PHP = '7.4';

	/** Maximum age of the signed offline cache (B6: 14 days). The server's renewal grace is the server's job. */
	public const CACHE_MAX_AGE = 1209600;

	/** Largest update ZIP the updater accepts, in bytes. */
	public const MAX_PACKAGE_BYTES = 52428800;

	/** Name of the option with the installation credentials (written by LicenseClient). */
	public static function credentials_option(): string {
		return self::PREFIX . '_license';
	}

	/** Name of the option with the signed entitlement cache and status metadata. */
	public static function state_option(): string {
		return self::PREFIX . '_license_state';
	}

	/** Name of the option with the last update check. */
	public static function update_option(): string {
		return self::PREFIX . '_update';
	}

	/** Daily WP-Cron event (entitlement refresh and update check). */
	public static function cron_hook(): string {
		return self::PREFIX . '_daily';
	}

	/** License server URL (filter: PREFIX_server_url). */
	public static function server_url(): string {
		$url = defined( 'PREMIUM_ADDON_STARTER_SERVER_URL' ) ? (string) constant( 'PREMIUM_ADDON_STARTER_SERVER_URL' ) : self::SERVER_URL;
		return (string) apply_filters( self::PREFIX . '_server_url', $url );
	}

	/**
	 * Pinned server keys (filter: PREFIX_server_public_keys, development builds only).
	 *
	 * The filter is applied only while the constant PREMIUM_ADDON_STARTER_LICENSE_DEV is defined and true: in a
	 * release build no other plugin can add a key and sign rights for this add-on.
	 *
	 * @return string[]
	 */
	public static function server_public_keys(): array {
		$keys = defined( 'PREMIUM_ADDON_STARTER_SERVER_PUBLIC_KEYS' ) ? (array) constant( 'PREMIUM_ADDON_STARTER_SERVER_PUBLIC_KEYS' ) : self::SERVER_PUBLIC_KEYS;
		if ( defined( 'PREMIUM_ADDON_STARTER_LICENSE_DEV' ) && true === (bool) constant( 'PREMIUM_ADDON_STARTER_LICENSE_DEV' ) ) {
			$keys = (array) apply_filters( self::PREFIX . '_server_public_keys', $keys );
		}
		return array_values( array_filter( array_map( 'strval', $keys ), 'strlen' ) );
	}

	/**
	 * Hosts the updater may download from (filter: PREFIX_update_hosts). Default: the license server host.
	 *
	 * The one-time package URL redirects either to the server itself (local storage) or to the storage host the shop
	 * configured; add that host here when the shop uses remote storage.
	 *
	 * @return string[]
	 */
	public static function update_hosts(): array {
		$host  = (string) wp_parse_url( self::server_url(), PHP_URL_HOST );
		$hosts = (array) apply_filters( self::PREFIX . '_update_hosts', '' === $host ? array() : array( $host ) );
		return array_values( array_unique( array_map( 'strtolower', array_map( 'strval', $hosts ) ) ) );
	}
}
