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

	/** Sites handled per get_sites() query on a network. */
	public const BATCH = 100;

	/** Seconds during which the license server is asked; afterwards only local data is deleted. */
	public const SERVER_BUDGET = 20;

	/**
	 * Clean up this site, or every site of a network (in batches; the server is only asked during the first
	 * SERVER_BUDGET seconds, so an unreachable server cannot push the uninstall past max_execution_time).
	 *
	 * @param callable|null $clock Returns the current Unix time (tests).
	 */
	public static function run( ?callable $clock = null ): void {
		$clock   = null === $clock ? 'time' : $clock;
		$started = (int) call_user_func( $clock );
		if ( is_multisite() && function_exists( 'get_sites' ) ) {
			$offset = 0;
			do {
				$sites = get_sites(
					array(
						'fields'                 => 'ids',
						'number'                 => self::BATCH,
						'offset'                 => $offset,
						'orderby'                => 'id',
						'order'                  => 'ASC',
						'no_found_rows'          => true,
						'update_site_meta_cache' => false,
					)
				);
				foreach ( $sites as $site_id ) {
					switch_to_blog( (int) $site_id );
					self::cleanup_site( Plugin::client(), (int) call_user_func( $clock ) - $started < self::SERVER_BUDGET );
					restore_current_blog();
				}
				$offset += self::BATCH;
			} while ( count( $sites ) === self::BATCH );
			return;
		}
		self::cleanup_site( Plugin::client() );
	}

	/**
	 * Clean up the current site.
	 *
	 * @param LicenseClient $client     Client for this site.
	 * @param bool          $ask_server Whether the activation may still be released on the server (time budget).
	 * @return bool Whether the server confirmed the deactivation (false also when nothing was activated).
	 */
	public static function cleanup_site( LicenseClient $client, bool $ask_server = true ): bool {
		$released    = false;
		$credentials = get_option( Config::credentials_option(), array() );
		if ( $ask_server && is_array( $credentials ) && ! empty( $credentials['activation_id'] ) ) {
			// Best effort: free the site slot. Local data is deleted either way.
			$released = ! is_wp_error( $client->deactivate() );
		}
		// Credentials and a pending activation (it holds an installation secret).
		$client->forget();
		delete_option( Config::credentials_option() );
		delete_option( Config::state_option() );
		delete_option( Config::update_option() );
		wp_clear_scheduled_hook( Config::cron_hook() );
		return $released;
	}
}
