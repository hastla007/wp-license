<?php
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'WPLC_LICENSE_KEY_SECRET', 'unit-test-only-stable-secret' );
define( 'WPLC_STRIPE_WEBHOOK_SECRET', 'whsec_unit_test_only' );
define( 'WPLC_INSTALLATION_ENCRYPTION_KEYS', array( 'test-2026' => 'test-current-encryption-key', 'test-2025' => 'test-previous-encryption-key' ) );
define( 'WPLC_INSTALLATION_ENCRYPTION_KEY_ID', 'test-2026' );

require dirname( __DIR__ ) . '/vendor/autoload.php';
