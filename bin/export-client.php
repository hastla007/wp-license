#!/usr/bin/env php
<?php
/**
 * Copies the license client library into an add-on under the add-on's own namespace.
 *
 * Usage:
 *   php bin/export-client.php --target=/path/to/addon/includes/licensing \
 *       --namespace='VoucherCaptainPro\Licensing' --text-domain=voucher-captain-pro
 *
 * What it changes in the copies:
 * - `namespace WPLC\Client;` becomes the given namespace, so several add-ons
 *   can be active at the same time, each with its own version of the library;
 * - the text domain literal `wp-license` becomes the given text domain, so
 *   `wp i18n make-pot` of the add-on picks the strings up;
 * - `@package WPLC` becomes the first segment of the namespace.
 *
 * Run it in the build step of the add-on (or once, and commit the result).
 * The script needs PHP only, no WordPress.
 *
 * @package WPLC
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- command line script without WordPress.

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$wplc_options = getopt( '', array( 'target:', 'namespace:', 'text-domain:', 'source::' ) );
$wplc_target  = isset( $wplc_options['target'] ) ? rtrim( (string) $wplc_options['target'], '/' ) : '';
$wplc_ns      = isset( $wplc_options['namespace'] ) ? trim( (string) $wplc_options['namespace'], '\\' ) : '';
$wplc_domain  = isset( $wplc_options['text-domain'] ) ? (string) $wplc_options['text-domain'] : '';
$wplc_source  = isset( $wplc_options['source'] ) && is_string( $wplc_options['source'] ) ? rtrim( $wplc_options['source'], '/' ) : dirname( __DIR__ ) . '/src/Client';

if ( '' === $wplc_target
	|| 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $wplc_ns )
	|| 1 !== preg_match( '/^[a-z0-9-]+$/', $wplc_domain )
) {
	fwrite( STDERR, "Usage: php bin/export-client.php --target=DIR --namespace='Vendor\\Licensing' --text-domain=my-addon\n" );
	exit( 2 );
}
if ( 0 === strcasecmp( $wplc_ns, 'WPLC\\Client' ) ) {
	fwrite( STDERR, "Choose a namespace of the add-on, not WPLC\\Client.\n" );
	exit( 2 );
}
if ( ! is_dir( $wplc_target ) && ! mkdir( $wplc_target, 0755, true ) ) {
	fwrite( STDERR, "Cannot create {$wplc_target}.\n" );
	exit( 1 );
}

$wplc_package = explode( '\\', $wplc_ns )[0];
$wplc_count   = 0;
foreach ( (array) glob( $wplc_source . '/*.php' ) as $wplc_file ) {
	$wplc_code = (string) file_get_contents( $wplc_file );
	$wplc_code = str_replace(
		array( 'namespace WPLC\\Client;', "'wp-license'", '@package WPLC' ),
		array( 'namespace ' . $wplc_ns . ';', "'" . $wplc_domain . "'", '@package ' . $wplc_package ),
		$wplc_code
	);
	if ( false !== strpos( $wplc_code, 'WPLC\\Client' ) ) {
		fwrite( STDERR, basename( $wplc_file ) . " still refers to WPLC\\Client; the export would not be self-contained.\n" );
		exit( 1 );
	}
	if ( false === file_put_contents( $wplc_target . '/' . basename( $wplc_file ), $wplc_code ) ) {
		fwrite( STDERR, 'Cannot write ' . $wplc_target . '/' . basename( $wplc_file ) . ".\n" );
		exit( 1 );
	}
	++$wplc_count;
}

fwrite( STDOUT, "Exported {$wplc_count} files to {$wplc_target} (namespace {$wplc_ns}, text domain {$wplc_domain}).\n" );
