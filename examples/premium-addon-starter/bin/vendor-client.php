#!/usr/bin/env php
<?php
/**
 * Copy LicenseClient from the license server into this add-on under the add-on's namespace.
 *
 * Usage (from the add-on root):
 *   php bin/vendor-client.php --source=/path/to/wp-license/src/Client/LicenseClient.php
 *   php bin/vendor-client.php --source=… --check        # exit 1 when the copy is out of date (CI)
 *
 * Options:
 *   --source       LicenseClient.php of the license server. Default: $WPLC_SERVER_DIR/src/Client/LicenseClient.php,
 *                  else ../../src/Client/LicenseClient.php (the starter inside the license server repository).
 *   --target       Default: src/Client/LicenseClient.php of this add-on.
 *   --namespace    Default: the add-on namespace + \Client.
 *   --text-domain  Default: the add-on text domain.
 *   --check        Do not write; compare and exit 1 on a difference.
 *
 * The copy is never edited by hand: fixes go into the license server first, then every add-on re-vendors.
 *
 * @package Vendor\Product\Premium
 */

const PREMIUM_ADDON_STARTER_CLIENT_NAMESPACE   = 'Vendor\Product\Premium\Client';
const PREMIUM_ADDON_STARTER_CLIENT_TEXT_DOMAIN = 'premium-addon-starter';

/**
 * Rewrite the server's LicenseClient source for an add-on.
 *
 * @param string $source      Content of the server's src/Client/LicenseClient.php.
 * @param string $namespace   Target namespace, e.g. Vendor\Product\Premium\Client.
 * @param string $text_domain Target text domain.
 * @return string Rewritten source.
 * @throws RuntimeException When the source does not look like the expected file.
 */
function premium_addon_starter_vendor_client( string $source, string $namespace, string $text_domain ): string {
	if ( ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $namespace ) ) {
		throw new RuntimeException( 'Invalid namespace: ' . $namespace );
	}
	if ( ! preg_match( '/^[a-z0-9-]+$/', $text_domain ) ) {
		throw new RuntimeException( 'Invalid text domain: ' . $text_domain );
	}
	if ( 1 !== substr_count( $source, "namespace WPLC\\Client;\n" ) || false === strpos( $source, 'final class LicenseClient' ) ) {
		throw new RuntimeException( 'The source is not WP License Captain\'s src/Client/LicenseClient.php.' );
	}
	$checksum = hash( 'sha256', $source );
	$out      = str_replace( "namespace WPLC\\Client;\n", 'namespace ' . $namespace . ";\n", $source );
	$out      = preg_replace( "/,(\\s*)'wp-license'(\\s*)\\)/", ",\$1'" . $text_domain . "'\$2)", $out );
	$out      = str_replace( ' * @package WPLC' . "\n", ' * @package ' . $namespace . "\n", (string) $out );
	$marker   = " * Vendored by bin/vendor-client.php from WP License Captain src/Client/LicenseClient.php\n"
		. ' * (source sha256 ' . $checksum . "). Do not edit: change the server copy and re-vendor.\n *\n";
	$out      = preg_replace( '/^<\?php\n\/\*\*\n/', "<?php\n/**\n" . $marker, $out, 1, $count );
	if ( 1 !== $count || false !== strpos( (string) $out, "'wp-license'" ) ) {
		throw new RuntimeException( 'Could not rewrite the client source.' );
	}
	return (string) $out;
}

if ( 'cli' === PHP_SAPI && isset( $argv[0] ) && realpath( $argv[0] ) === __FILE__ ) {
	$premium_addon_starter_opts = getopt( '', array( 'source:', 'target:', 'namespace:', 'text-domain:', 'check', 'help' ) );
	if ( isset( $premium_addon_starter_opts['help'] ) ) {
		fwrite( STDOUT, "Copy LicenseClient.php from the license server under the add-on namespace. See the header of this file.\n" );
		exit( 0 );
	}
	$premium_addon_starter_root   = dirname( __DIR__ );
	$premium_addon_starter_server = getenv( 'WPLC_SERVER_DIR' ) ? rtrim( (string) getenv( 'WPLC_SERVER_DIR' ), '/' ) : dirname( $premium_addon_starter_root, 2 );
	$premium_addon_starter_source = $premium_addon_starter_opts['source'] ?? $premium_addon_starter_server . '/src/Client/LicenseClient.php';
	$premium_addon_starter_target = $premium_addon_starter_opts['target'] ?? $premium_addon_starter_root . '/src/Client/LicenseClient.php';
	if ( ! is_file( $premium_addon_starter_source ) ) {
		fwrite( STDERR, "Source not found: {$premium_addon_starter_source} (pass --source or set WPLC_SERVER_DIR)\n" );
		exit( 2 );
	}
	try {
		$premium_addon_starter_code = premium_addon_starter_vendor_client(
			(string) file_get_contents( $premium_addon_starter_source ),
			$premium_addon_starter_opts['namespace'] ?? PREMIUM_ADDON_STARTER_CLIENT_NAMESPACE,
			$premium_addon_starter_opts['text-domain'] ?? PREMIUM_ADDON_STARTER_CLIENT_TEXT_DOMAIN
		);
	} catch ( RuntimeException $e ) {
		fwrite( STDERR, $e->getMessage() . "\n" );
		exit( 2 );
	}
	$premium_addon_starter_current = is_file( $premium_addon_starter_target ) ? (string) file_get_contents( $premium_addon_starter_target ) : '';
	if ( isset( $premium_addon_starter_opts['check'] ) ) {
		if ( $premium_addon_starter_current !== $premium_addon_starter_code ) {
			fwrite( STDERR, "{$premium_addon_starter_target} is out of date. Run php bin/vendor-client.php.\n" );
			exit( 1 );
		}
		fwrite( STDOUT, "LicenseClient copy is up to date.\n" );
		exit( 0 );
	}
	if ( false === file_put_contents( $premium_addon_starter_target, $premium_addon_starter_code ) ) {
		fwrite( STDERR, "Could not write {$premium_addon_starter_target}\n" );
		exit( 2 );
	}
	fwrite( STDOUT, ( $premium_addon_starter_current === $premium_addon_starter_code ? 'Unchanged: ' : 'Written: ' ) . $premium_addon_starter_target . "\n" );
}
