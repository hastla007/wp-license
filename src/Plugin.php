<?php
/**
 * Main plugin loader.
 *
 * @package WPLC
 */

namespace WPLC;

use WPLC\CLI\RotateInstallationSecretsCommand;
use WPLC\CLI\SigningKeyCommand;
use WPLC\CLI\RecoverLicenseKeyIdsCommand;
use WPLC\Catalog\ProductEditor;
use WPLC\Infrastructure\Database\Schema;
use WPLC\License\LicenseIssuer;
use WPLC\License\LicenseLifecycle;
use WPLC\Api\V1\Routes;
use WPLC\Account\CustomerPortal;
use WPLC\Admin\LicenseAdmin;
use WPLC\Admin\Diagnostics;
use WPLC\Admin\CatalogCsv;
use WPLC\Admin\KeyConfigurationNotice;
use WPLC\Admin\ReleaseAdmin;
use WPLC\Update\ReleaseRegistry;
use WPLC\Update\PackageStream;
use WPLC\Update\CustomerDownload;
use WPLC\Billing\RecurringConsent;
use WPLC\Billing\BlockCheckoutConsent;
use WPLC\Compatibility\Requirements;
use WPLC\Billing\ManualRenewal;
use WPLC\Billing\RenewalProcessor;
use WPLC\Gateway\StripeRecurringAdapter;
use WPLC\Privacy\PersonalData;
use WPLC\Notifications\Notifications;
use WPLC\Maintenance\Retention;
use WPLC\Maintenance\SiteGuard;
use WPLC\Maintenance\GapGuard;
use WPLC\Maintenance\GapReconciler;
use WPLC\Maintenance\DailyRunner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Boots the plugin after WordPress has loaded its active plugins.
 */
final class Plugin {

	/**
	 * Option with the unix time of the last deactivation (start of a processing gap).
	 */
	public const DEACTIVATED_OPTION = 'wplc_deactivated_at';

	/**
	 * Cron hooks of the plugin.
	 *
	 * @var string[]
	 */
	private const CRON_HOOKS = array( 'wplc_daily_lifecycle', 'wplc_cleanup_security_rows', 'wplc_send_reminders' );

	/**
	 * Largest number of sites handled in one network-wide activation or deactivation.
	 */
	private const MAX_NETWORK_SITES = 200;

	/**
	 * Validate activation requirements before enabling the plugin.
	 *
	 * @param bool $network_wide Whether the plugin is activated for the whole network.
	 * @return void
	 */
	public static function activate( $network_wide = false ): void {
		if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
			wp_die( esc_html__( 'WP License Captain requires PHP 7.4 or later.', 'wp-license' ) );
		}

		if ( ! class_exists( '\\WooCommerce' ) ) {
			wp_die( esc_html__( 'Please activate WooCommerce before activating WP License Captain.', 'wp-license' ) );
		}

		self::for_each_site( (bool) $network_wide, array( self::class, 'activate_site' ) );
		CustomerPortal::register_endpoint();
		flush_rewrite_rules();
	}

	/**
	 * Per-site activation: schema, schedule, gap detection.
	 *
	 * @return void
	 */
	public static function activate_site(): void {
		if ( function_exists( 'ms_is_switched' ) && ms_is_switched() && false === get_option( 'wplc_schema_version', false ) ) {
			// Network activation: a site that never used the plugin gets its tables on first use.
			return;
		}
		Schema::migrate();
		LicenseLifecycle::schedule_daily_event();
		SiteGuard::remember();

		// Orders paid and refunds made while the plugin was off are caught up; renewals that
		// became due in that time must not expire before they were attempted.
		$now            = time();
		$deactivated_at = (int) get_option( self::DEACTIVATED_OPTION, 0 );
		$last_daily_run = DailyRunner::last_run();
		if ( $deactivated_at > 0 ) {
			GapGuard::record( $deactivated_at, $now, 'deactivation' );
			delete_option( self::DEACTIVATED_OPTION );
		} elseif ( $last_daily_run > 0 && $now - $last_daily_run >= DailyRunner::MISSED_RUN_SECONDS ) {
			GapGuard::record( $last_daily_run, $now, 'daily_run_missed' );
		}
		GapReconciler::schedule();
	}

	/**
	 * Deactivation clears scheduled work but preserves license and order data.
	 *
	 * @param bool $network_wide Whether the plugin is deactivated for the whole network.
	 * @return void
	 */
	public static function deactivate( $network_wide = false ): void {
		self::for_each_site( (bool) $network_wide, array( self::class, 'deactivate_site' ) );
	}

	/**
	 * Per-site deactivation.
	 *
	 * @return void
	 */
	public static function deactivate_site(): void {
		foreach ( self::CRON_HOOKS as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
		// A cursor left behind by an unfinished reminder run would skip licenses after reactivation.
		delete_option( 'wplc_reminder_cursor' );
		if ( false === get_option( 'wplc_schema_version', false ) ) {
			return;
		}
		update_option( self::DEACTIVATED_OPTION, time(), false );
	}

	/**
	 * Run a callback for this site, or for every site of the network.
	 *
	 * @param bool     $network_wide Whether to loop over the network.
	 * @param callable $callback     Callback without arguments.
	 * @return void
	 */
	private static function for_each_site( bool $network_wide, callable $callback ): void {
		if ( ! $network_wide || ! is_multisite() || ! function_exists( 'get_sites' ) ) {
			call_user_func( $callback );
			return;
		}

		$site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => self::MAX_NETWORK_SITES,
			)
		);
		foreach ( (array) $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );
			try {
				call_user_func( $callback );
			} finally {
				restore_current_blog();
			}
		}
	}

	/**
	 * Boot the plugin once per request.
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_action( 'plugins_loaded', array( self::class, 'on_plugins_loaded' ), 20 );
	}

	/**
	 * Initialize only when WooCommerce is available.
	 *
	 * @return void
	 */
	public static function on_plugins_loaded(): void {
		load_plugin_textdomain(
			'wp-license',
			false,
			dirname( plugin_basename( WPLC_FILE ) ) . '/languages'
		);

		if ( ! class_exists( '\\WooCommerce' ) ) {
			add_action( 'admin_notices', array( self::class, 'render_woocommerce_notice' ) );
			return;
		}

		RotateInstallationSecretsCommand::register();
		RecoverLicenseKeyIdsCommand::register();
		SigningKeyCommand::register();
		Schema::maybe_upgrade();
		Schema::register_hooks();
		ReleaseRegistry::register_hooks();
		PackageStream::register_hooks();
		CustomerDownload::register_hooks();
		ReleaseAdmin::register_hooks();
		ProductEditor::register_hooks();
		LicenseIssuer::register_hooks();
		LicenseLifecycle::register_hooks();
		Routes::register_hooks();
		CustomerPortal::register_hooks();
		LicenseAdmin::register_hooks();
		CatalogCsv::register_hooks();
		Diagnostics::register_hooks();
		KeyConfigurationNotice::register_hooks();
		Requirements::register_hooks();
		RecurringConsent::register_hooks();
		BlockCheckoutConsent::register_hooks();
		RenewalProcessor::register_hooks();
		ManualRenewal::register_hooks();
		StripeRecurringAdapter::register_hooks();
		PersonalData::register_hooks();
		Notifications::register_hooks();
		Retention::register_hooks();
		SiteGuard::register_hooks();
		GapReconciler::register_hooks();
		// Last: wraps the job callbacks registered above (isolation, pause gate, heartbeat).
		DailyRunner::register_hooks();
		SiteGuard::remember();

		/**
		 * Fires after WP License Captain has confirmed its WooCommerce dependency.
		 *
		 * @since 0.1.0
		 */
		do_action( 'wplc/loaded' );
	}

	/**
	 * Explain the missing dependency to administrators.
	 *
	 * @return void
	 */
	public static function render_woocommerce_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>';
		echo esc_html__(
			'WP License Captain requires WooCommerce to be installed and active.',
			'wp-license'
		);
		echo '</p></div>';
	}
}
