<?php
/**
 * Router for `php -S`: serves the e2e site on plain HTTP at 127.0.0.1 but presents
 * every request to WordPress as https://shop.test (the plugin requires HTTPS).
 *
 * @package WPLC
 */

$_SERVER['HTTPS']       = 'on';
$_SERVER['SERVER_PORT'] = '443';
$_SERVER['HTTP_HOST']   = 'shop.test';
$_SERVER['SERVER_NAME'] = 'shop.test';

$wplc_e2e_root = $_SERVER['DOCUMENT_ROOT'];
$wplc_e2e_path = (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$wplc_e2e_file = realpath( $wplc_e2e_root . $wplc_e2e_path );

if ( $wplc_e2e_file && 0 === strpos( $wplc_e2e_file, realpath( $wplc_e2e_root ) ) && is_file( $wplc_e2e_file ) ) {
	if ( '.php' !== substr( $wplc_e2e_file, -4 ) ) {
		return false; // Static file.
	}
	$_SERVER['SCRIPT_FILENAME'] = $wplc_e2e_file;
	$_SERVER['SCRIPT_NAME']     = $wplc_e2e_path;
	$_SERVER['PHP_SELF']        = $wplc_e2e_path;
	chdir( dirname( $wplc_e2e_file ) );
	require $wplc_e2e_file;
	return true;
}

if ( $wplc_e2e_file && is_dir( $wplc_e2e_file ) && is_file( $wplc_e2e_file . '/index.php' ) && '/' !== $wplc_e2e_path ) {
	$_SERVER['SCRIPT_FILENAME'] = $wplc_e2e_file . '/index.php';
	$_SERVER['SCRIPT_NAME']     = rtrim( $wplc_e2e_path, '/' ) . '/index.php';
	$_SERVER['PHP_SELF']        = $_SERVER['SCRIPT_NAME'];
	chdir( $wplc_e2e_file );
	require $wplc_e2e_file . '/index.php';
	return true;
}

$_SERVER['SCRIPT_FILENAME'] = $wplc_e2e_root . '/index.php';
$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['PHP_SELF']        = '/index.php';
chdir( $wplc_e2e_root );
require $wplc_e2e_root . '/index.php';
