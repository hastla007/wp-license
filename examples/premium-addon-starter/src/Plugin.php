<?php
/**
 * Bootstrap and service wiring.
 *
 * @package Vendor\Product\Premium
 */

namespace Vendor\Product\Premium;

use Vendor\Product\Premium\Admin\SettingsPage;
use Vendor\Product\Premium\Client\LicenseClient;
use Vendor\Product\Premium\License\Entitlements;
use Vendor\Product\Premium\License\LicenseService;
use Vendor\Product\Premium\Update\Updater;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads the add-on after all plugins are loaded, or shows why it cannot.
 */
final class Plugin {
	/** @var Plugin|null */
	private static $instance = null;

	/** @var string Main plugin file. */
	private $file;
	/** @var string */
	private $version;
	/** @var LicenseService|null */
	private $license = null;
	/** @var Updater|null */
	private $updater = null;

	/**
	 * @param string $file    Main plugin file.
	 * @param string $version Installed version.
	 */
	private function __construct( string $file, string $version ) {
		$this->file    = $file;
		$this->version = $version;
	}

	/**
	 * Called from the main plugin file.
	 *
	 * @param string $file    Main plugin file.
	 * @param string $version Installed version.
	 */
	public static function boot( string $file, string $version ): void {
		if ( null !== self::$instance ) {
			return;
		}
		self::$instance = new self( $file, $version );
		add_action( 'plugins_loaded', array( self::$instance, 'load' ), 20 );
		register_deactivation_hook( $file, array( self::class, 'deactivate' ) );
	}

	/** The running instance (null before boot()). */
	public static function instance(): ?self {
		return self::$instance;
	}

	/** plugins_loaded: check the core plugin, then wire everything. Nothing is translated before init. */
	public function load(): void {
		$file = $this->file;
		add_action(
			'init',
			static function () use ( $file ): void {
				load_plugin_textdomain( Config::SLUG, false, dirname( plugin_basename( $file ) ) . '/languages' );
			}
		);
		if ( ! CoreCheck::passes() ) {
			// Clean bail-out: nothing else of the add-on is loaded, the core plugin is untouched.
			add_action(
				'admin_notices',
				static function (): void {
					if ( current_user_can( 'activate_plugins' ) ) {
						echo '<div class="notice notice-error"><p>' . esc_html( CoreCheck::problem() ) . '</p></div>';
					}
				}
			);
			return;
		}
		Entitlements::use_service( $this->license() );
		$this->updater()->register();
		( new SettingsPage( $this->license(), Config::PREFIX, 'Premium Add-on Starter' ) )->register();
		add_action( Config::cron_hook(), array( $this, 'daily' ) );
		add_action( 'init', array( $this, 'schedule' ) );
		add_action( 'admin_init', array( $this, 'catch_up' ) );

		/**
		 * The add-on is ready: register premium features here, each behind Entitlements::has( '<prefix>.<feature>' ).
		 */
		do_action( Config::PREFIX . '_loaded', $this );
	}

	/** Make sure the daily event exists. */
	public function schedule(): void {
		if ( ! wp_next_scheduled( Config::cron_hook() ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Config::cron_hook() );
		}
	}

	/** Daily cron: refresh the signed entitlements, then check for an update. No user needed. */
	public function daily(): void {
		if ( ! $this->license()->is_activated() ) {
			return;
		}
		$this->license()->refresh();
		$this->updater()->check();
	}

	/**
	 * admin_init: catch up a missed daily check (hosts with DISABLE_WP_CRON and no system cron).
	 *
	 * Runs for administrators only, not in AJAX requests, at most every 36 hours after a success and every hour
	 * after a failure (LicenseService::catch_up_due()). Without it the signed cache would run out after 14 days.
	 */
	public function catch_up(): void {
		if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) || ! $this->license()->catch_up_due() ) {
			return;
		}
		$this->license()->refresh();
	}

	/** Deactivation keeps the license (an uninstall releases it) and removes the cron event. */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( Config::cron_hook() );
	}

	/**
	 * Client configured from Config.
	 *
	 * The client sends its requests with wp_safe_remote_request(); a development build
	 * (PREMIUM_ADDON_STARTER_LICENSE_DEV) may reach a license server on a local or private address.
	 */
	public static function client( string $version = '' ): LicenseClient {
		$dev = defined( 'PREMIUM_ADDON_STARTER_LICENSE_DEV' ) && true === (bool) constant( 'PREMIUM_ADDON_STARTER_LICENSE_DEV' );
		return new LicenseClient(
			Config::server_url(),
			Config::PRODUCT_KEY,
			$version,
			Config::credentials_option(),
			array(
				'public_keys'        => Config::server_public_keys(),
				'text_domain'        => 'premium-addon-starter',
				'allow_local_server' => $dev,
			)
		);
	}

	/** License service. */
	public function license(): LicenseService {
		if ( null === $this->license ) {
			$this->license = new LicenseService( self::client( $this->version ), Config::credentials_option(), Config::state_option(), Config::ENTITLEMENT_PREFIX, Config::CACHE_MAX_AGE );
		}
		return $this->license;
	}

	/** Updater. */
	public function updater(): Updater {
		if ( null === $this->updater ) {
			$this->updater = new Updater(
				self::client( $this->version ),
				plugin_basename( $this->file ),
				Config::SLUG,
				Config::PRODUCT_KEY,
				$this->version,
				Config::update_option(),
				Config::server_url(),
				Config::update_hosts(),
				(int) apply_filters( Config::PREFIX . '_max_package_bytes', Config::MAX_PACKAGE_BYTES )
			);
		}
		return $this->updater;
	}
}
