<?php
/**
 * Updates of this plugin from a license server (the server as a licensed product, E8b).
 *
 * @package WPLC
 */

namespace WPLC\Infrastructure;

use WPLC\Client\LicenseManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lets a sold copy of the license server get its own updates through the client library it
 * ships (`src/Client`), from the license server of its vendor.
 *
 * Rules (docs/PRODUCT-PLAN.md, PROD-06):
 *
 * - **Off by default.** Without a configured vendor endpoint nothing is registered: no screen,
 *   no cron event, no option and no request. Product key, server address and public keys of the
 *   sold product are not decided yet (INH-07 to INH-09), so they are configuration only.
 * - **The license gates updates and support, never function.** Nothing in the plugin asks this
 *   class whether the license is valid. Issuing, checking and renewing the licenses of the shop
 *   work the same with a valid, an expired, a missing or an unreachable own license.
 * - **No loop.** A server never updates itself from itself: an endpoint whose host is this
 *   site (or one of its network sites) switches the feature off.
 *
 * Configuration in wp-config.php:
 *
 *     define( 'WPLC_VENDOR_SERVER_URL', 'https://licenses.vendor.example' );
 *     define( 'WPLC_VENDOR_PRODUCT_KEY', 'the-product-key-of-the-server' );
 *     define( 'WPLC_VENDOR_PUBLIC_KEYS', array( 'key-id' => 'base64 public key' ) );
 *     define( 'WPLC_VENDOR_ACCOUNT_URL', 'https://vendor.example/my-account/' ); // optional
 *
 * or the filter `wplc_vendor_license_config` (a build can ship the values).
 */
final class SelfLicense {

	/**
	 * Option, hook and form prefix of the own license (client library).
	 */
	public const PREFIX = 'wplc_vendor';

	public const STATE_OFF     = 'off';
	public const STATE_INVALID = 'invalid';
	public const STATE_LOOP    = 'loop';
	public const STATE_ON      = 'on';

	/**
	 * The manager of this request, false when the feature is off.
	 *
	 * @var LicenseManager|false|null
	 */
	private static $manager = null;

	/**
	 * Wire the own license when a vendor endpoint is configured.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		$manager = self::manager();
		if ( ! $manager ) {
			return;
		}
		// A filter of the client library could point the address back at this site.
		add_filter( self::PREFIX . '_license_server_url', array( self::class, 'guard_server_url' ), PHP_INT_MAX );
		$manager->register();
	}

	/**
	 * The raw configuration: constants, then the filter.
	 *
	 * @return array{server_url:string,product_key:string,public_keys:array<string,string>,account_url:string}
	 */
	public static function raw_config(): array {
		$config = array(
			'server_url'  => defined( 'WPLC_VENDOR_SERVER_URL' ) && is_string( constant( 'WPLC_VENDOR_SERVER_URL' ) ) ? (string) constant( 'WPLC_VENDOR_SERVER_URL' ) : '',
			'product_key' => defined( 'WPLC_VENDOR_PRODUCT_KEY' ) && is_string( constant( 'WPLC_VENDOR_PRODUCT_KEY' ) ) ? (string) constant( 'WPLC_VENDOR_PRODUCT_KEY' ) : '',
			'public_keys' => defined( 'WPLC_VENDOR_PUBLIC_KEYS' ) && is_array( constant( 'WPLC_VENDOR_PUBLIC_KEYS' ) ) ? (array) constant( 'WPLC_VENDOR_PUBLIC_KEYS' ) : array(),
			'account_url' => defined( 'WPLC_VENDOR_ACCOUNT_URL' ) && is_string( constant( 'WPLC_VENDOR_ACCOUNT_URL' ) ) ? (string) constant( 'WPLC_VENDOR_ACCOUNT_URL' ) : '',
		);
		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filters where this plugin gets its own updates from (the license server of its vendor).
			 *
			 * Empty values switch the feature off. The server address must use HTTPS and must not be
			 * this site. The license only gates updates and support; the plugin works without it.
			 *
			 * @param mixed $config Array with `server_url`, `product_key`, `public_keys` (key ID => base64 key) and `account_url`: the values of the constants WPLC_VENDOR_*. What a callback returns is checked field by field.
			 */
			$filtered = apply_filters( 'wplc_vendor_license_config', $config );
			$config   = is_array( $filtered ) ? array_merge( $config, array_intersect_key( $filtered, $config ) ) : $config;
		}
		$keys = array();
		foreach ( is_array( $config['public_keys'] ) ? $config['public_keys'] : array() as $id => $key ) {
			if ( is_string( $id ) && is_string( $key ) && '' !== $id && '' !== $key ) {
				$keys[ $id ] = $key;
			}
		}

		return array(
			'server_url'  => is_string( $config['server_url'] ) ? trim( $config['server_url'] ) : '',
			'product_key' => is_string( $config['product_key'] ) ? trim( $config['product_key'] ) : '',
			'public_keys' => $keys,
			'account_url' => is_string( $config['account_url'] ) ? trim( $config['account_url'] ) : '',
		);
	}

	/**
	 * State of the feature for a configuration. Pure apart from the list of own hosts.
	 *
	 * @param array{server_url:string,product_key:string,public_keys:array<string,string>,account_url:string} $config    Configuration.
	 * @param string[]                                                                                        $own_hosts Hosts of this installation.
	 * @return string One of the STATE_* constants.
	 */
	public static function state_for( array $config, array $own_hosts ): string {
		if ( '' === $config['server_url'] && '' === $config['product_key'] && array() === $config['public_keys'] ) {
			return self::STATE_OFF;
		}
		$host = self::host( $config['server_url'] );
		if (
			'' === $host
			|| 0 !== stripos( $config['server_url'], 'https://' )
			|| 1 !== preg_match( '/^[a-z0-9][a-z0-9-]{1,99}$/D', $config['product_key'] )
			|| array() === $config['public_keys']
		) {
			return self::STATE_INVALID;
		}
		foreach ( $own_hosts as $own ) {
			if ( '' !== $own && self::same_host( $host, $own ) ) {
				return self::STATE_LOOP;
			}
		}

		return self::STATE_ON;
	}

	/**
	 * State of the feature on this installation.
	 *
	 * @return string One of the STATE_* constants.
	 */
	public static function state(): string {
		return self::state_for( self::raw_config(), self::own_hosts() );
	}

	/**
	 * The license manager of the own license, or null when the feature is off, misconfigured or would loop.
	 *
	 * @return LicenseManager|null
	 */
	public static function manager(): ?LicenseManager {
		if ( null === self::$manager ) {
			$config        = self::raw_config();
			self::$manager = false;
			if ( self::STATE_ON === self::state_for( $config, self::own_hosts() ) && defined( 'WPLC_FILE' ) ) {
				self::$manager = new LicenseManager(
					array(
						'plugin_file'   => (string) WPLC_FILE,
						'product_key'   => $config['product_key'],
						'version'       => defined( 'WPLC_VERSION' ) ? (string) WPLC_VERSION : '0',
						'server_url'    => $config['server_url'],
						'public_keys'   => $config['public_keys'],
						'name'          => 'WP License Captain',
						'text_domain'   => 'wp-license',
						'option_prefix' => self::PREFIX,
						'account_url'   => $config['account_url'],
						// The header of the plugin file; WordPress asks the filter of its host for updates.
						'update_uri'    => 'https://github.com/hastla007/wp-license',
					)
				);
			}
		}

		return self::$manager ? self::$manager : null;
	}

	/**
	 * Whether updates of this plugin come from a license server (then the release check on GitHub is off).
	 *
	 * @return bool
	 */
	public static function active(): bool {
		return null !== self::manager();
	}

	/**
	 * Forget the manager of this request (tests).
	 *
	 * @return void
	 */
	public static function reset(): void {
		if ( self::$manager ) {
			remove_filter( self::PREFIX . '_license_server_url', array( self::class, 'guard_server_url' ), PHP_INT_MAX );
		}
		self::$manager = null;
	}

	/**
	 * Refuse a server address that is this site, whoever set it.
	 *
	 * @param mixed $server_url Address after the filters of the client library.
	 * @return string The address, or an empty string (the client then makes no request).
	 */
	public static function guard_server_url( $server_url ): string {
		$server_url = is_string( $server_url ) ? $server_url : '';
		$host       = self::host( $server_url );
		foreach ( self::own_hosts() as $own ) {
			if ( '' !== $host && self::same_host( $host, $own ) ) {
				return '';
			}
		}

		return $server_url;
	}

	/**
	 * Hosts under which this installation answers.
	 *
	 * @return string[]
	 */
	public static function own_hosts(): array {
		$urls = array();
		foreach ( array( 'home_url', 'site_url', 'network_home_url', 'network_site_url' ) as $function ) {
			if ( function_exists( $function ) ) {
				$urls[] = (string) $function();
			}
		}
		foreach ( array( 'home', 'siteurl' ) as $option ) {
			if ( function_exists( 'get_option' ) ) {
				$urls[] = (string) get_option( $option, '' );
			}
		}
		$hosts = array();
		foreach ( $urls as $url ) {
			$host = self::host( $url );
			if ( '' !== $host ) {
				$hosts[ $host ] = $host;
			}
		}

		return array_values( $hosts );
	}

	/**
	 * Lower-case host of a URL without a trailing dot.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function host( string $url ): string {
		$host = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url, PHP_URL_HOST ) : parse_url( $url, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- fallback without WordPress (unit tests).

		return is_string( $host ) ? rtrim( strtolower( $host ), '.' ) : '';
	}

	/**
	 * Whether two hosts are the same installation: equal, also with or without "www.".
	 *
	 * @param string $a Host.
	 * @param string $b Host.
	 * @return bool
	 */
	public static function same_host( string $a, string $b ): bool {
		$strip = static function ( string $host ): string {
			return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
		};

		return $strip( $a ) === $strip( $b );
	}
}
