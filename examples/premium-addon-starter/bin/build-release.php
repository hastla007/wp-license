#!/usr/bin/env php
<?php
/**
 * Build the release ZIP, its SHA-256 and a release manifest for the license server (B11).
 *
 * Usage (from the add-on root):
 *   php bin/build-release.php [--out=dist] [--package-url=https://downloads.example.com/slug-1.2.3.zip]
 *
 * Writes dist/<slug>-<version>.zip (one top-level folder <slug>/), dist/<slug>-<version>.zip.sha256 and, with
 * --package-url, dist/<slug>-<version>.manifest.json for "WooCommerce → License releases → Import manifest".
 * Without a package URL upload the ZIP in the release form instead; the server computes the checksum itself, compare
 * it with the .sha256 file.
 *
 * Only runtime files are packed: the main file, uninstall.php, src/, languages/, assets/, templates/, PRIVACY.md,
 * readme.txt, LICENSE.
 *
 * @package Vendor\Product\Premium
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$premium_addon_starter_opts = getopt( '', array( 'out:', 'package-url:' ) );
$premium_addon_starter_root = dirname( __DIR__ );
$premium_addon_starter_main = glob( $premium_addon_starter_root . '/*.php' );
$premium_addon_starter_file = '';
$premium_addon_starter_head = '';
foreach ( (array) $premium_addon_starter_main as $premium_addon_starter_candidate ) {
	$premium_addon_starter_text = (string) file_get_contents( $premium_addon_starter_candidate, false, null, 0, 8192 );
	if ( false !== strpos( $premium_addon_starter_text, 'Plugin Name:' ) ) {
		$premium_addon_starter_file = $premium_addon_starter_candidate;
		$premium_addon_starter_head = $premium_addon_starter_text;
		break;
	}
}
$premium_addon_starter_header = static function ( string $field ) use ( $premium_addon_starter_head ): string {
	return preg_match( '/^[ \t\/*#@]*' . preg_quote( $field, '/' ) . ':(.*)$/mi', $premium_addon_starter_head, $m ) ? trim( $m[1] ) : '';
};
$premium_addon_starter_version = $premium_addon_starter_header( 'Version' );
$premium_addon_starter_slug    = basename( $premium_addon_starter_file, '.php' );
if ( '' === $premium_addon_starter_file || ! preg_match( '/^\d+\.\d+\.\d+([.-][0-9A-Za-z.-]+)?$/', $premium_addon_starter_version ) ) {
	fwrite( STDERR, "Main plugin file or a valid Version header not found.\n" );
	exit( 2 );
}
if ( ! preg_match( "/_VERSION', '" . preg_quote( $premium_addon_starter_version, '/' ) . "'/", (string) file_get_contents( $premium_addon_starter_file ) ) ) {
	fwrite( STDERR, "The version constant in {$premium_addon_starter_slug}.php does not match the Version header ({$premium_addon_starter_version}).\n" );
	exit( 2 );
}

$premium_addon_starter_out = rtrim( $premium_addon_starter_opts['out'] ?? $premium_addon_starter_root . '/dist', '/' );
if ( ! is_dir( $premium_addon_starter_out ) && ! mkdir( $premium_addon_starter_out, 0755, true ) ) {
	fwrite( STDERR, "Cannot create {$premium_addon_starter_out}\n" );
	exit( 2 );
}
$premium_addon_starter_zip_path = $premium_addon_starter_out . '/' . $premium_addon_starter_slug . '-' . $premium_addon_starter_version . '.zip';
$premium_addon_starter_zip      = new ZipArchive();
if ( true !== $premium_addon_starter_zip->open( $premium_addon_starter_zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	fwrite( STDERR, "Cannot write {$premium_addon_starter_zip_path}\n" );
	exit( 2 );
}
$premium_addon_starter_entries = array( basename( $premium_addon_starter_file ), 'uninstall.php', 'PRIVACY.md', 'readme.txt', 'LICENSE', 'src', 'languages', 'assets', 'templates' );
$premium_addon_starter_count   = 0;
foreach ( $premium_addon_starter_entries as $premium_addon_starter_entry ) {
	$premium_addon_starter_path = $premium_addon_starter_root . '/' . $premium_addon_starter_entry;
	$premium_addon_starter_list = array();
	if ( is_file( $premium_addon_starter_path ) ) {
		$premium_addon_starter_list[] = $premium_addon_starter_path;
	} elseif ( is_dir( $premium_addon_starter_path ) ) {
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $premium_addon_starter_path, FilesystemIterator::SKIP_DOTS ) ) as $premium_addon_starter_item ) {
			$premium_addon_starter_list[] = (string) $premium_addon_starter_item->getPathname();
		}
	}
	sort( $premium_addon_starter_list );
	foreach ( $premium_addon_starter_list as $premium_addon_starter_item ) {
		$premium_addon_starter_zip->addFile( $premium_addon_starter_item, $premium_addon_starter_slug . '/' . substr( $premium_addon_starter_item, strlen( $premium_addon_starter_root ) + 1 ) );
		++$premium_addon_starter_count;
	}
}
$premium_addon_starter_zip->close();

$premium_addon_starter_sha = (string) hash_file( 'sha256', $premium_addon_starter_zip_path );
file_put_contents( $premium_addon_starter_zip_path . '.sha256', $premium_addon_starter_sha . '  ' . basename( $premium_addon_starter_zip_path ) . "\n" );
if ( ! empty( $premium_addon_starter_opts['package-url'] ) ) {
	$premium_addon_starter_manifest = array(
		'product_key'  => '',
		'version'      => $premium_addon_starter_version,
		'package_url'  => (string) $premium_addon_starter_opts['package-url'],
		'sha256'       => $premium_addon_starter_sha,
		'requires_php' => $premium_addon_starter_header( 'Requires PHP' ),
		'requires_wp'  => $premium_addon_starter_header( 'Requires at least' ),
		'tested_wp'    => $premium_addon_starter_header( 'Tested up to' ),
	);
	$premium_addon_starter_config = (string) @file_get_contents( $premium_addon_starter_root . '/src/Config.php' );
	if ( preg_match( "/PRODUCT_KEY = '([^']+)'/", $premium_addon_starter_config, $premium_addon_starter_m ) ) {
		$premium_addon_starter_manifest['product_key'] = $premium_addon_starter_m[1];
	}
	file_put_contents( $premium_addon_starter_out . '/' . $premium_addon_starter_slug . '-' . $premium_addon_starter_version . '.manifest.json', json_encode( $premium_addon_starter_manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
}
fwrite( STDOUT, "{$premium_addon_starter_zip_path}\nversion {$premium_addon_starter_version}, {$premium_addon_starter_count} files, " . filesize( $premium_addon_starter_zip_path ) . " bytes\nsha256 {$premium_addon_starter_sha}\n" );
