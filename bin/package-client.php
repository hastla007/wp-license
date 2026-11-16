#!/usr/bin/env php
<?php
/**
 * Builds the license client library package for vendors: dist/license-client-<version>.zip.
 *
 * Usage:
 *   php bin/package-client.php [--out=dist]
 *
 * The ZIP has one top-level folder `license-client-<version>/` with
 * - `src/Client/*.php`             the library, unchanged (namespace WPLC\Client),
 * - `bin/export-client.php`        copies it into an add-on under the add-on's namespace and text domain,
 * - `bin/client-contract-test.php` runs activate → check → update check → deactivate against a server,
 * - `README.md`                    how to embed it, namespace rules, the API v1 compatibility promise,
 * - `CHANGELOG-CLIENT.md`          changes of the library by version.
 * README and changelog come from `packaging/client/`. A `.sha256` file is written next to the ZIP.
 *
 * Checks before writing: every class of `src/Client` has the same `VERSION`, and the changelog has a
 * section `## <version>` for it. Needs PHP only (ZipArchive, or the `zip` command without it).
 * bin/build-zip.sh runs it after the plugin ZIP.
 *
 * @package WPLC
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput, WordPress.PHP.DiscouragedPHPFunctions -- command line script without WordPress.

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$wplc_root    = dirname( __DIR__ );
$wplc_options = getopt( '', array( 'out:' ) );
$wplc_out     = isset( $wplc_options['out'] ) && is_string( $wplc_options['out'] ) ? rtrim( $wplc_options['out'], '/' ) : $wplc_root . '/dist';

/**
 * Print an error and stop.
 *
 * @param string $message Message.
 * @return void
 */
function wplc_package_fail( string $message ): void {
	fwrite( STDERR, $message . "\n" );
	exit( 1 );
}

// One version for the whole library.
$wplc_versions = array();
$wplc_sources  = glob( $wplc_root . '/src/Client/*.php' );
$wplc_sources  = is_array( $wplc_sources ) ? $wplc_sources : array();
foreach ( $wplc_sources as $wplc_file ) {
	if ( 1 === preg_match( "/const VERSION = '([^']+)';/", (string) file_get_contents( $wplc_file ), $wplc_match ) ) {
		$wplc_versions[ basename( $wplc_file ) ] = $wplc_match[1];
	}
}
$wplc_version = (string) reset( $wplc_versions );
if ( '' === $wplc_version || 1 !== count( array_unique( $wplc_versions ) ) ) {
	wplc_package_fail( 'The classes of src/Client do not share one VERSION: ' . json_encode( $wplc_versions ) );
}
if ( 1 !== preg_match( '/^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$/', $wplc_version ) ) {
	wplc_package_fail( "VERSION {$wplc_version} is not of the form 1.2.3." );
}

$wplc_changelog = (string) @file_get_contents( $wplc_root . '/packaging/client/CHANGELOG-CLIENT.md' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- checked below.
if ( 1 !== preg_match( '/^## ' . preg_quote( $wplc_version, '/' ) . '(\s|$)/m', $wplc_changelog ) ) {
	wplc_package_fail( "packaging/client/CHANGELOG-CLIENT.md has no section \"## {$wplc_version}\"." );
}
$wplc_readme = (string) @file_get_contents( $wplc_root . '/packaging/client/README.md' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- checked below.
if ( '' === $wplc_readme ) {
	wplc_package_fail( 'packaging/client/README.md is missing.' );
}

$wplc_name  = 'license-client-' . $wplc_version;
$wplc_files = array(
	'README.md'                    => str_replace( '{{VERSION}}', $wplc_version, $wplc_readme ),
	'CHANGELOG-CLIENT.md'          => $wplc_changelog,
	'bin/export-client.php'        => (string) file_get_contents( $wplc_root . '/bin/export-client.php' ),
	'bin/client-contract-test.php' => (string) file_get_contents( $wplc_root . '/bin/client-contract-test.php' ),
);
foreach ( $wplc_sources as $wplc_file ) {
	$wplc_files[ 'src/Client/' . basename( $wplc_file ) ] = (string) file_get_contents( $wplc_file );
}
ksort( $wplc_files );

if ( ! is_dir( $wplc_out ) && ! mkdir( $wplc_out, 0755, true ) ) {
	wplc_package_fail( "Cannot create {$wplc_out}." );
}
$wplc_zip = $wplc_out . '/' . $wplc_name . '.zip';
if ( file_exists( $wplc_zip ) ) {
	unlink( $wplc_zip );
}

if ( class_exists( 'ZipArchive' ) ) {
	$wplc_archive = new ZipArchive();
	if ( true !== $wplc_archive->open( $wplc_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
		wplc_package_fail( "Cannot write {$wplc_zip}." );
	}
	foreach ( $wplc_files as $wplc_path => $wplc_content ) {
		$wplc_archive->addFromString( $wplc_name . '/' . $wplc_path, $wplc_content );
	}
	$wplc_archive->close();
} else {
	$wplc_stage = sys_get_temp_dir() . '/wplc-client-package-' . bin2hex( random_bytes( 6 ) );
	foreach ( $wplc_files as $wplc_path => $wplc_content ) {
		$wplc_target = $wplc_stage . '/' . $wplc_name . '/' . $wplc_path;
		if ( ! is_dir( dirname( $wplc_target ) ) ) {
			mkdir( dirname( $wplc_target ), 0755, true );
		}
		file_put_contents( $wplc_target, $wplc_content );
	}
	$wplc_command = 'cd ' . escapeshellarg( $wplc_stage ) . ' && zip -r -q -X ' . escapeshellarg( realpath( $wplc_out ) . '/' . $wplc_name . '.zip' ) . ' ' . escapeshellarg( $wplc_name );
	exec( $wplc_command, $wplc_output, $wplc_status );
	exec( 'rm -rf ' . escapeshellarg( $wplc_stage ) );
	if ( 0 !== $wplc_status ) {
		wplc_package_fail( 'zip failed; install the PHP zip extension or the zip command.' );
	}
}

file_put_contents( $wplc_zip . '.sha256', hash_file( 'sha256', $wplc_zip ) . '  ' . basename( $wplc_zip ) . "\n" );
fwrite( STDOUT, sprintf( "Built %s (%d files, library %s).\n", $wplc_zip, count( $wplc_files ), $wplc_version ) );
