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

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Boots the plugin after WordPress has loaded its active plugins.
 */
final class Plugin {

	/**
	 * Validate activation requirements before enabling the plugin.
	 *
	 * @return void
	 */
	public static function activate(): void {
		// Activation runs after plugins_loaded, so on_plugins_loaded() has not loaded
		// the translations yet; the messages below must not appear in English.
		self::load_textdomain();

		if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
			wp_die( esc_html__( 'WP License Captain requires PHP 7.4 or later.', 'wp-license' ) );
		}

		if ( ! class_exists( '\\WooCommerce' ) ) {
			wp_die( esc_html__( 'Please activate WooCommerce before activating WP License Captain.', 'wp-license' ) );
		}

		Schema::migrate();
		LicenseLifecycle::schedule_daily_event();
		CustomerPortal::register_endpoint();
		flush_rewrite_rules();
	}

	/**
	 * Load the plugin translations.
	 *
	 * Safe to call more than once: WordPress keeps the loaded domain.
	 *
	 * @return void
	 */
	public static function load_textdomain(): void {
		if ( is_textdomain_loaded( 'wp-license' ) ) {
			return;
		}
		load_plugin_textdomain(
			'wp-license',
			false,
			dirname( plugin_basename( WPLC_FILE ) ) . '/languages'
		);
	}

	/**
	 * Deactivation clears scheduled work but preserves license and order data.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'wplc_daily_lifecycle' );
		wp_clear_scheduled_hook( 'wplc_cleanup_security_rows' );
		wp_clear_scheduled_hook( 'wplc_send_reminders' );
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
		self::load_textdomain();

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
