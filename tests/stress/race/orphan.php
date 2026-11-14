<?php
putenv( 'QA_WORKER=1' ); require __DIR__ . '/boot.php'; require __DIR__ . '/qa-adapter.php';
global $wpdb; $wpdb->suppress_errors( true );
\WPLC\Billing\RenewalProcessor::process( 2 );
echo "done\n";
