<?php
/**
 * Bootstrap the WordPress + WooCommerce integration suite.
 *
 * Environment (see bin/install-test-env.sh):
 * - WP_TESTS_DIR     WordPress test library (default: <tmp>/wordpress-tests-lib).
 * - WP_CORE_DIR      WordPress core (default: <tmp>/wordpress); only used to find WooCommerce.
 * - WPLC_WC_DIR      WooCommerce plugin directory (default: $WP_CORE_DIR/wp-content/plugins/woocommerce).
 * - WPLC_TEST_HPOS   "1" (default) runs with High-Performance Order Storage, "0" with legacy post storage.
 *
 * @package WPLC
 */

$wplc_tmp_dir   = rtrim( getenv( 'TMPDIR' ) ? getenv( 'TMPDIR' ) : sys_get_temp_dir(), '/' );
$wplc_tests_dir = getenv( 'WP_TESTS_DIR' ) ? getenv( 'WP_TESTS_DIR' ) : $wplc_tmp_dir . '/wordpress-tests-lib';
if ( ! file_exists( $wplc_tests_dir . '/includes/bootstrap.php' ) ) {
	throw new RuntimeException( 'Set WP_TESTS_DIR to an installed WordPress test library (run bin/install-test-env.sh).' );
}

$wplc_core_dir = getenv( 'WP_CORE_DIR' ) ? getenv( 'WP_CORE_DIR' ) : $wplc_tmp_dir . '/wordpress';
$wplc_wc_dir   = getenv( 'WPLC_WC_DIR' ) ? getenv( 'WPLC_WC_DIR' ) : rtrim( $wplc_core_dir, '/' ) . '/wp-content/plugins/woocommerce';
$wplc_wc_dir   = rtrim( $wplc_wc_dir, '/' );
if ( ! file_exists( $wplc_wc_dir . '/woocommerce.php' ) ) {
	throw new RuntimeException( 'WooCommerce was not found in ' . $wplc_wc_dir . '. Run bin/install-test-env.sh or set WPLC_WC_DIR.' );
}
if ( ! file_exists( $wplc_wc_dir . '/vendor/autoload_packages.php' ) ) {
	throw new RuntimeException( 'The WooCommerce copy in ' . $wplc_wc_dir . ' is a source checkout without vendor/. Use the release ZIP.' );
}

$wplc_hpos = '0' !== (string) getenv( 'WPLC_TEST_HPOS' );

define( 'WPLC_INSTALLATION_ENCRYPTION_KEYS', array( 'integration-2026' => 'integration-only-encryption-secret' ) );
define( 'WPLC_INSTALLATION_ENCRYPTION_KEY_ID', 'integration-2026' );
define( 'WPLC_LICENSE_KEY_SECRETS', array( 'integration-2026' => 'integration-only-license-secret' ) );
define( 'WPLC_LICENSE_KEY_ID', 'integration-2026' );
define( 'WPLC_STRIPE_WEBHOOK_SECRET', 'whsec_integration_only' );

// WordPress wraps every test in a transaction; WooCommerce must not open its own.
define( 'WC_USE_TRANSACTIONS', false );

if ( ! getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) && ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );
}

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once $wplc_tests_dir . '/includes/functions.php';

// Load WooCommerce first, then this plugin, exactly like two active plugins.
tests_add_filter(
	'muplugins_loaded',
	static function () use ( $wplc_wc_dir ) {
		require_once $wplc_wc_dir . '/woocommerce.php';
		require_once dirname( __DIR__ ) . '/wp-license.php';
	}
);

// Install WooCommerce (tables, roles, options) and select the order storage before any order exists.
tests_add_filter(
	'setup_theme',
	static function () use ( $wplc_hpos ) {
		WC_Install::install();

		$synchronizer_class = 'Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\DataSynchronizer';
		if ( $wplc_hpos ) {
			if ( ! class_exists( $synchronizer_class ) ) {
				throw new RuntimeException( 'This WooCommerce version has no HPOS data synchronizer; run with WPLC_TEST_HPOS=0.' );
			}
			$synchronizer = wc_get_container()->get( $synchronizer_class );
			if ( ! $synchronizer->check_orders_table_exists() ) {
				$synchronizer->create_database_tables();
			}
		}
		update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
		update_option( 'woocommerce_custom_orders_table_enabled', $wplc_hpos ? 'yes' : 'no' );

		// Reload capabilities after the WooCommerce roles were created.
		$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		wp_roles();

		// Create the plugin tables as permanent tables before the per-test transactions start.
		\WPLC\Infrastructure\Database\Schema::migrate();

		echo 'WordPress ' . esc_html( $GLOBALS['wp_version'] )
			. ', WooCommerce ' . esc_html( WC()->version )
			. ', order storage: ' . ( $wplc_hpos ? 'HPOS' : 'legacy posts' )
			. ', PHP ' . esc_html( PHP_VERSION ) . PHP_EOL;
	}
);

require $wplc_tests_dir . '/includes/bootstrap.php';
require __DIR__ . '/integration/helpers/IntegrationTestCase.php';
