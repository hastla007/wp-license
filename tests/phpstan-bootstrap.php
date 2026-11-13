<?php
/**
 * Constants PHPStan needs to resolve while analysing the plugin without loading it.
 *
 * Not used by PHPUnit.
 *
 * @package WPLC
 */

define( 'WPLC_VERSION', '0.0.0' );
define( 'WPLC_FILE', dirname( __DIR__ ) . '/wp-license.php' );
define( 'WPLC_PATH', dirname( __DIR__ ) . '/' );

// Optional wp-config.php constants (declared as dynamic in phpstan.neon.dist).
define( 'WPLC_INSTALLATION_ENCRYPTION_KEY', '' );
define( 'WPLC_INSTALLATION_ENCRYPTION_KEYS', array() );
define( 'WPLC_INSTALLATION_ENCRYPTION_KEY_ID', '' );
define( 'WPLC_LICENSE_KEY_ID', '' );
define( 'WPLC_LICENSE_KEY_SECRET', '' );
define( 'WPLC_LICENSE_KEY_SECRETS', array() );
define( 'WPLC_STRIPE_WEBHOOK_SECRET', '' );
define( 'WP_CLI', false );
