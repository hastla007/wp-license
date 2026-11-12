<?php
/**
 * Bootstrap the isolated WordPress integration suite.
 *
 * @package WPLC
 */

$tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $tests_dir || ! file_exists( $tests_dir . '/includes/bootstrap.php' ) ) {
	throw new RuntimeException( 'Set WP_TESTS_DIR to an installed WordPress test library.' );
}

define( 'WPLC_INSTALLATION_ENCRYPTION_KEYS', array( 'integration-2026' => 'integration-only-encryption-secret' ) );
define( 'WPLC_INSTALLATION_ENCRYPTION_KEY_ID', 'integration-2026' );
define( 'WPLC_LICENSE_KEY_SECRETS', array( 'integration-2026' => 'integration-only-license-secret' ) );
define( 'WPLC_LICENSE_KEY_ID', 'integration-2026' );
define( 'WPLC_STRIPE_WEBHOOK_SECRET', 'whsec_integration_only' );

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once $tests_dir . '/includes/functions.php';
require $tests_dir . '/includes/bootstrap.php';
