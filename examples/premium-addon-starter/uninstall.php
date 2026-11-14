<?php
/**
 * Uninstall: release the activation on the license server, delete the add-on's credentials and cache (B8).
 *
 * @package Vendor\Product\Premium
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/src/autoload.php';

\Vendor\Product\Premium\Uninstaller::run();
