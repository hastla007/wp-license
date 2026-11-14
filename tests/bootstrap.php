<?php
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'WPLC_LICENSE_KEY_SECRET', 'unit-test-only-stable-secret' );
define( 'WPLC_LICENSE_KEY_SECRETS', array( 'test-2026' => 'test-current-license-key', 'test-2025' => 'test-previous-license-key' ) );
define( 'WPLC_LICENSE_KEY_ID', 'test-2026' );
define( 'WPLC_STRIPE_WEBHOOK_SECRET', 'whsec_unit_test_only' );
define( 'WPLC_INSTALLATION_ENCRYPTION_KEYS', array( 'test-2026' => 'test-current-encryption-key', 'test-2025' => 'test-previous-encryption-key' ) );
define( 'WPLC_INSTALLATION_ENCRYPTION_KEY_ID', 'test-2026' );

require dirname( __DIR__ ) . '/vendor/autoload.php';

// Load WPLC classes from this checkout even when vendor/ is shared (a symlink
// from a git worktree points the Composer autoloader at another checkout).
spl_autoload_register(
	static function ( string $class ): void {
		if ( 0 !== strpos( $class, 'WPLC\\' ) ) {
			return;
		}
		$file = dirname( __DIR__ ) . '/src/' . str_replace( '\\', '/', substr( $class, 5 ) ) . '.php';
		if ( is_file( $file ) ) {
			require $file;
		}
	},
	true,
	true
);
