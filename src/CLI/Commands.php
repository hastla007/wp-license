<?php
/**
 * Registration of the support commands.
 *
 * @package WPLC
 */

namespace WPLC\CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the WP-CLI commands a support engineer needs to look at a license and to repeat a stuck step (A-74, B-172).
 */
final class Commands {

	/** @var bool Whether the commands were registered in this process. */
	private static bool $registered = false;

	/**
	 * Register the commands when WP-CLI is active.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( self::$registered || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}
		self::$registered = true;
		\WP_CLI::add_command( 'wplc license', LicenseCommand::class );
		\WP_CLI::add_command( 'wplc activation', ActivationCommand::class );
		\WP_CLI::add_command( 'wplc repair', RepairCommand::class );
	}
}
