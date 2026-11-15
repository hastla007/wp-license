#!/usr/bin/env php
<?php
/**
 * Build immutable release metadata for a premium plugin ZIP.
 *
 * Usage:
 * php tools/create-release-manifest.php --zip=dist/product.zip --product-key=product --version=1.2.3 \
 *   --package-url=https://downloads.example.test/product-1.2.3.zip --requires-php=7.4 --requires-wp=6.4 \
 *   --tested-wp=6.8 --changelog=CHANGELOG.md [--requires-core=1.4.0] [--output=dist/manifest.json]
 *
 * The checks mirror the server (WPLC\Update\ReleaseRegistry): versions are x.y.z with an optional
 * -alphaN, -betaN or -rcN suffix; the product key follows WPLC\Catalog\Catalog::PRODUCT_KEY_PATTERN
 * (keys of products created by older versions may also contain underscores).
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$options = getopt( '', array( 'zip:', 'product-key:', 'version:', 'package-url:', 'requires-php:', 'requires-wp:', 'tested-wp:', 'requires-core:', 'changelog:', 'output:', 'help' ) );
$required = array( 'zip', 'product-key', 'version', 'package-url', 'requires-php', 'requires-wp', 'tested-wp', 'changelog' );

if ( isset( $options['help'] ) ) {
	fwrite( STDOUT, "Create a JSON release manifest and SHA-256 checksum for a plugin ZIP. See script header for usage.\n" );
	exit( 0 );
}

foreach ( $required as $key ) {
	if ( empty( $options[ $key ] ) ) {
		fwrite( STDERR, "Missing required option: --{$key}\n" );
		exit( 2 );
	}
}

$zip = $options['zip'];
$package_url = filter_var( $options['package-url'], FILTER_VALIDATE_URL );
if ( ! is_file( $zip ) || ! is_readable( $zip ) ) {
	fwrite( STDERR, "ZIP file does not exist or is not readable.\n" );
	exit( 2 );
}
if ( false === $package_url || 'https' !== strtolower( (string) parse_url( $package_url, PHP_URL_SCHEME ) ) ) {
	fwrite( STDERR, "Package URL must be a valid HTTPS URL.\n" );
	exit( 2 );
}
// Catalog::PRODUCT_KEY_PATTERN, or the former wider form for products that already exist on the server.
if ( ! preg_match( '/^[a-z0-9][a-z0-9-]{1,99}$/D', $options['product-key'] ) && ! preg_match( '/^[a-z0-9_-]{1,100}$/D', $options['product-key'] ) ) {
	fwrite( STDERR, "Product key must use lowercase letters, digits, and hyphens.\n" );
	exit( 2 );
}
// ReleaseRegistry::VERSION_PATTERN: other suffixes (-pre1, +build) are sorted wrongly by version_compare().
if ( strlen( $options['version'] ) > 40 || ! preg_match( '/^[0-9]+\\.[0-9]+\\.[0-9]+(?:-(?:alpha|beta|rc)[0-9]+)?$/D', $options['version'] ) ) {
	fwrite( STDERR, "Version must be x.y.z, optionally followed by -alphaN, -betaN or -rcN (for example 1.2.3 or 2.0.0-rc1).\n" );
	exit( 2 );
}
if ( isset( $options['requires-core'] ) && '' === $options['requires-core'] ) {
	unset( $options['requires-core'] );
}
foreach ( array( 'requires-php', 'requires-wp', 'tested-wp', 'requires-core' ) as $key ) {
	if ( ! isset( $options[ $key ] ) ) {
		continue;
	}
	if ( strlen( $options[ $key ] ) > 20 || ! preg_match( '/^[0-9]+(?:\\.[0-9]+){0,2}$/', $options[ $key ] ) ) {
		fwrite( STDERR, "Invalid version supplied for --{$key}.\n" );
		exit( 2 );
	}
}
if ( ! is_file( $options['changelog'] ) || ! is_readable( $options['changelog'] ) ) {
	fwrite( STDERR, "Changelog file does not exist or is not readable.\n" );
	exit( 2 );
}

$checksum = hash_file( 'sha256', $zip );
$notes = file_get_contents( $options['changelog'] );
if ( false === $checksum || false === $notes ) {
	fwrite( STDERR, "Could not read release package or changelog.\n" );
	exit( 1 );
}

$manifest = array(
	'product_key' => $options['product-key'],
	'version' => $options['version'],
	'package_url' => $package_url,
	'sha256' => strtolower( $checksum ),
	'requires_php' => $options['requires-php'],
	'requires_wp' => $options['requires-wp'],
	'tested_wp' => $options['tested-wp'],
	'requires_core' => isset( $options['requires-core'] ) ? $options['requires-core'] : '',
	'release_notes' => trim( $notes ),
	'package_file' => basename( $zip ),
	'generated_at_utc' => gmdate( 'c' ),
);
$json = json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
if ( false === $json ) {
	fwrite( STDERR, "Could not encode release manifest.\n" );
	exit( 1 );
}

$output = isset( $options['output'] ) ? $options['output'] : '';
if ( '' === $output ) {
	fwrite( STDOUT, $json . PHP_EOL );
	exit( 0 );
}
if ( false === file_put_contents( $output, $json . PHP_EOL, LOCK_EX ) ) {
	fwrite( STDERR, "Could not write manifest output.\n" );
	exit( 1 );
}
fwrite( STDOUT, "Wrote release manifest: {$output}\n" );
