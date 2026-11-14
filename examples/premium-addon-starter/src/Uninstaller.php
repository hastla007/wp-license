<?php
/**
 * Uninstall cleanup (B8).
 *
 * @package Vendor\Product\Premium
 */

namespace Vendor\Product\Premium;

use Vendor\Product\Premium\Client\LicenseClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Releases the activation on the license server and deletes the add-on's own options. Never touches options,
 * tables or files of the core plugin.
 */
final class Uninstaller {

	/** Clean up this site, or every site of a network. */
	public static function run(): void {
		if ( is_multisite() && function_exists( 'get_sites' ) ) {
			foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::cleanup_site( Plugin::client() );
				restore_current_blog();
			}
			return;
		}
		self::cleanup_site( Plugin::client() );
	}

	/**
	 * Clean up the current site.
	 *
	 * @param LicenseClient $client Client for this site.
	 * @return bool Whether the server confirmed the deactivation (false also when nothing was activated).
	 */
	public static function cleanup_site( LicenseClient $client ): bool {
		$released    = false;
		$credentials = get_option( Config::credentials_option(), array() );
		if ( is_array( $credentials ) && ! empty( $credentials['activation_id'] ) ) {
			// Best effort: free the site slot. Local data is deleted either way.
			$released = ! is_wp_error( $client->deactivate() );
		}
		delete_option( Config::credentials_option() );
		delete_option( Config::state_option() );
		delete_option( Config::update_option() );
		wp_clear_scheduled_hook( Config::cron_hook() );
		return $released;
	}
}
