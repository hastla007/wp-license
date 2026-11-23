#!/usr/bin/env php
<?php
/**
 * Copies the license client library into an add-on under the add-on's own namespace.
 *
 * Usage:
 *   php bin/export-client.php --target=/path/to/addon/includes/licensing \
 *       --namespace='AcmeReportsPro\Licensing' --text-domain=acme-reports-pro
 *
 * What it changes in the copies:
 * - `namespace WPLC\Client;` becomes the given namespace, so several add-ons
 *   can be active at the same time, each with its own version of the library;
 * - the text domain literal `wp-license` becomes the given text domain, so
 *   `wp i18n make-pot` of the add-on picks the strings up;
 * - `@package WPLC` becomes the first segment of the namespace;
 * - since client 2.4.0, the brand values of the vendor go into `LicenseManager::BUILD_BRAND`
 *   (the line marked `// wplc-export:brand`): `--support-email=` (support address),
 *   `--support-url=` (http(s) address of the support page) and `--key-placeholder=`
 *   (placeholder of the key field, default `WPLC-…`, at most 80 characters). Without them the
 *   line stays empty. The setting `brand` of the add-on still overrides each value.
 *
 * Usage with brand values:
 *   php bin/export-client.php --target=DIR --namespace='Vendor\Licensing' --text-domain=my-addon \
 *       --support-email=support@vendor.example --support-url=https://vendor.example/support \
 *       --key-placeholder='VEND-…'
 *
 * It also writes `wplc-client-source.json` next to the copies (PK-06): version of the library, the
 * commit of the license server repository the copies were made from, the checksum of the seven
 * source files (the same value per version as in `packaging/client/checksums.json`) and the
 * checksum of every exported file. A receiver reads the origin from this file instead of writing
 * it down by hand. The commit comes from `--source-commit=`, else from `SOURCE.json` of the library
 * package (`license-client-<version>.zip`), else from Git when the source is `src/Client` of a
 * checkout of the license server; otherwise it is `unknown`.
 *
 * Run it in the build step of the add-on (or once, and commit the result).
 * The script needs PHP only, no WordPress. The exported library (client 2.2.0 and later)
 * needs PHP 8.5, so the add-on declares at least `Requires PHP: 8.5`.
 *
 * @package WPLC
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions -- command line script without WordPress.

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$wplc_options = getopt( '', array( 'target:', 'namespace:', 'text-domain:', 'source::', 'source-commit::', 'support-email::', 'support-url::', 'key-placeholder::' ) );
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

// Brand values of the vendor (client 2.4.0 and later): option name => key in LicenseManager::BUILD_BRAND.
$wplc_brand_options = array(
	'support-email'   => 'support_email',
	'support-url'     => 'support_url',
	'key-placeholder' => 'key_placeholder',
);
$wplc_brand         = array();
foreach ( $wplc_brand_options as $wplc_option => $wplc_key ) {
	$wplc_value = isset( $wplc_options[ $wplc_option ] ) && is_string( $wplc_options[ $wplc_option ] ) ? trim( $wplc_options[ $wplc_option ] ) : '';
	if ( '' !== $wplc_value ) {
		$wplc_brand[ $wplc_key ] = $wplc_value;
	}
}
if ( isset( $wplc_brand['support_email'] ) && false === filter_var( $wplc_brand['support_email'], FILTER_VALIDATE_EMAIL ) ) {
	fwrite( STDERR, "--support-email must be an e-mail address.\n" );
	exit( 2 );
}
if ( isset( $wplc_brand['support_url'] ) && 1 !== preg_match( '#^https?://[^\s/?\#]+\S*$#i', $wplc_brand['support_url'] ) ) {
	fwrite( STDERR, "--support-url must be an http(s) address.\n" );
	exit( 2 );
}
if ( isset( $wplc_brand['key_placeholder'] ) && ( mb_strlen( $wplc_brand['key_placeholder'] ) > 80 || 1 === preg_match( '/[\x00-\x1F\x7F]/', $wplc_brand['key_placeholder'] ) ) ) {
	fwrite( STDERR, "--key-placeholder must be one line of at most 80 characters.\n" );
	exit( 2 );
}

if ( ! is_dir( $wplc_target ) && ! mkdir( $wplc_target, 0755, true ) ) {
	fwrite( STDERR, "Cannot create {$wplc_target}.\n" );
	exit( 1 );
}

/**
 * Checksum of the library content: one line `<file name>\0<sha256 of the file>` per file, sorted
 * by name, hashed again. bin/package-client.php and tests/Client/ClientSourceTest.php compute the same.
 *
 * @param array<string,string> $hashes File name => sha256 of the unchanged source file.
 * @return string
 */
function wplc_export_content_sha256( array $hashes ): string {
	ksort( $hashes, SORT_STRING );
	$lines = '';
	foreach ( $hashes as $name => $hash ) {
		$lines .= $name . "\0" . $hash . "\n";
	}

	return hash( 'sha256', $lines );
}

/**
 * Run a Git command in the source directory.
 *
 * @param string $dir     Directory.
 * @param string $command Arguments after `git`.
 * @return string|null Trimmed output, null on failure.
 */
function wplc_export_git( string $dir, string $command ): ?string {
	if ( ! function_exists( 'exec' ) ) {
		return null;
	}
	$output = array();
	$status = 1;
	exec( 'git -C ' . escapeshellarg( $dir ) . ' ' . $command . ' 2>/dev/null', $output, $status );

	return 0 === $status ? trim( implode( "\n", $output ) ) : null;
}

$wplc_package = explode( '\\', $wplc_ns )[0];
$wplc_count   = 0;
$wplc_origin  = array();
$wplc_copies  = array();
$wplc_version = '';
foreach ( (array) glob( $wplc_source . '/*.php' ) as $wplc_file ) {
	$wplc_code = (string) file_get_contents( $wplc_file );
	$wplc_origin[ basename( $wplc_file ) ] = hash( 'sha256', $wplc_code );
	if ( 'LicenseManager.php' === basename( $wplc_file ) && 1 === preg_match( "/const VERSION = '([^']+)';/", $wplc_code, $wplc_match ) ) {
		$wplc_version = $wplc_match[1];
	}
	$wplc_code = str_replace(
		array( 'namespace WPLC\\Client;', "'wp-license'", '@package WPLC' ),
		array( 'namespace ' . $wplc_ns . ';', "'" . $wplc_domain . "'", '@package ' . $wplc_package ),
		$wplc_code
	);
	if ( 'LicenseManager.php' === basename( $wplc_file ) && ! empty( $wplc_brand ) ) {
		$wplc_marker = 'const BUILD_BRAND = array(); // wplc-export:brand';
		if ( 1 !== substr_count( $wplc_code, $wplc_marker ) ) {
			fwrite( STDERR, "LicenseManager.php has no line for brand values (client 2.4.0 or later needed for --support-email, --support-url, --key-placeholder).\n" );
			exit( 1 );
		}
		$wplc_items = array();
		foreach ( $wplc_brand as $wplc_key => $wplc_value ) {
			$wplc_items[] = var_export( $wplc_key, true ) . ' => ' . var_export( $wplc_value, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- writes PHP source.
		}
		$wplc_code = str_replace( $wplc_marker, 'const BUILD_BRAND = array( ' . implode( ', ', $wplc_items ) . ' ); // wplc-export:brand', $wplc_code );
	}
	if ( false !== strpos( $wplc_code, 'WPLC\\Client' ) ) {
		fwrite( STDERR, basename( $wplc_file ) . " still refers to WPLC\\Client; the export would not be self-contained.\n" );
		exit( 1 );
	}
	if ( false === file_put_contents( $wplc_target . '/' . basename( $wplc_file ), $wplc_code ) ) {
		fwrite( STDERR, 'Cannot write ' . $wplc_target . '/' . basename( $wplc_file ) . ".\n" );
		exit( 1 );
	}
	$wplc_copies[ basename( $wplc_file ) ] = hash( 'sha256', $wplc_code );
	++$wplc_count;
}

// Origin of the copies (PK-06).
$wplc_checksum = wplc_export_content_sha256( $wplc_origin );
$wplc_commit   = isset( $wplc_options['source-commit'] ) && is_string( $wplc_options['source-commit'] ) ? strtolower( trim( $wplc_options['source-commit'] ) ) : '';
$wplc_modified = false;
if ( '' !== $wplc_commit && 1 !== preg_match( '/^[0-9a-f]{7,64}$/', $wplc_commit ) ) {
	fwrite( STDERR, "--source-commit must be a Git commit hash.\n" );
	exit( 2 );
}
if ( '' === $wplc_commit ) {
	$wplc_package_source = dirname( $wplc_source, 2 ) . '/SOURCE.json';
	$wplc_package_data   = is_file( $wplc_package_source ) ? json_decode( (string) file_get_contents( $wplc_package_source ), true ) : null;
	if ( is_array( $wplc_package_data ) && isset( $wplc_package_data['content_sha256'], $wplc_package_data['source_commit'] ) ) {
		// Library package: the commit is the one the package was built from, as long as the files are unchanged.
		$wplc_commit   = (string) $wplc_package_data['source_commit'];
		$wplc_modified = ! empty( $wplc_package_data['source_modified'] ) || $wplc_checksum !== (string) $wplc_package_data['content_sha256'];
	} elseif ( 'src/Client/' === wplc_export_git( $wplc_source, 'rev-parse --show-prefix' ) && is_file( dirname( $wplc_source, 2 ) . '/wp-license.php' ) ) {
		// Checkout of the license server.
		$wplc_commit   = (string) wplc_export_git( $wplc_source, 'rev-parse HEAD' );
		$wplc_modified = '' !== (string) wplc_export_git( $wplc_source, 'status --porcelain -- .' );
	}
}
if ( 1 !== preg_match( '/^[0-9a-f]{7,64}$/', $wplc_commit ) ) {
	$wplc_commit = 'unknown';
}
$wplc_record = array(
	'library'         => 'WP License Captain license client (src/Client)',
	'version'         => $wplc_version,
	'source_commit'   => $wplc_commit,
	'source_modified' => $wplc_modified,
	'content_sha256'  => $wplc_checksum,
	'namespace'       => $wplc_ns,
	'text_domain'     => $wplc_domain,
	'files'           => $wplc_copies,
);
if ( ! empty( $wplc_brand ) ) {
	// Brand values written into LicenseManager::BUILD_BRAND (client 2.4.0 and later).
	$wplc_record['brand'] = $wplc_brand;
}
if ( false === file_put_contents( $wplc_target . '/wplc-client-source.json', json_encode( $wplc_record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" ) ) {
	fwrite( STDERR, 'Cannot write ' . $wplc_target . "/wplc-client-source.json.\n" );
	exit( 1 );
}
if ( 'unknown' === $wplc_commit ) {
	fwrite( STDERR, "Note: the source commit is unknown (no SOURCE.json of the library package, no Git checkout of the license server). Pass --source-commit=<hash>.\n" );
} elseif ( $wplc_modified ) {
	fwrite( STDERR, "Note: the source files differ from commit {$wplc_commit}; wplc-client-source.json says so (source_modified).\n" );
}

fwrite( STDOUT, "Exported {$wplc_count} files to {$wplc_target} (namespace {$wplc_ns}, text domain {$wplc_domain}; library {$wplc_version}, source commit {$wplc_commit}).\n" );
