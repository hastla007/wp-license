<?php
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'WPLC_LICENSE_KEY_SECRET', 'unit-test-only-stable-secret' );
define( 'WPLC_STRIPE_WEBHOOK_SECRET', 'whsec_unit_test_only' );

require dirname( __DIR__ ) . '/vendor/autoload.php';
