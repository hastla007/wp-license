#!/usr/bin/env php
<?php
/**
 * Create a product add-on from this starter: copy it and replace every placeholder.
 *
 * Usage (from the starter directory):
 *   php bin/init-addon.php --target=../voucher-captain-pro \
 *     --name="Voucher Captain Pro" --slug=voucher-captain-pro --namespace='VCAP\Pro' --prefix=vcap_pro \
 *     --product-key=voucher-captain-pro --entitlement-prefix=voucher. \
 *     --core-name="Voucher Captain" --core-slug=voucher-captain --core-constant=VCAP_VERSION --core-min=1.1.0 \
 *     --requires-php=8.5 --server-url=https://shop.example.com --public-key=BASE64 [--public-key=OLD_BASE64] \
 *     [--version=1.0.0] [--author="Vendor"]
 *
 * The target directory must not exist or be empty. tests/fixtures is copied unchanged (the signed fixtures must
 * not be rewritten). Run the add-on's tests afterwards: composer install && vendor/bin/phpunit.
 *
 * @package Vendor\Product\Premium
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$premium_addon_starter_opts = getopt(
	'',
	array( 'target:', 'name:', 'slug:', 'namespace:', 'prefix:', 'product-key:', 'entitlement-prefix:', 'core-name:', 'core-slug:', 'core-constant:', 'core-min:', 'requires-php:', 'server-url:', 'public-key:', 'version:', 'author:', 'help' )
);
$premium_addon_starter_required = array( 'target', 'name', 'slug', 'namespace', 'prefix', 'product-key', 'entitlement-prefix', 'core-name', 'core-slug', 'core-constant', 'core-min', 'requires-php', 'server-url' );
if ( isset( $premium_addon_starter_opts['help'] ) ) {
	fwrite( STDOUT, "Create a product add-on from the starter. See the header of this file.\n" );
	exit( 0 );
}
foreach ( $premium_addon_starter_required as $premium_addon_starter_key ) {
	if ( empty( $premium_addon_starter_opts[ $premium_addon_starter_key ] ) || ! is_string( $premium_addon_starter_opts[ $premium_addon_starter_key ] ) ) {
		fwrite( STDERR, "Missing option --{$premium_addon_starter_key}\n" );
		exit( 2 );
	}
}
$o = $premium_addon_starter_opts; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
$checks = array( // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
	'slug'               => '/^[a-z0-9]+(-[a-z0-9]+)*$/',
	'namespace'          => '/^[A-Z][A-Za-z0-9]*(\\\\[A-Z][A-Za-z0-9]*)*$/',
	'prefix'             => '/^[a-z][a-z0-9_]*$/',
	'product-key'        => '/^[a-z0-9_-]+$/',
	'entitlement-prefix' => '/^[a-z][a-z0-9_]*\.$/',
	'core-slug'          => '/^[a-z0-9-]+$/',
	'core-constant'      => '/^[A-Z][A-Z0-9_]*$/',
	'core-min'           => '/^\d+\.\d+(\.\d+)?$/',
	'requires-php'       => '/^\d+\.\d+$/',
	'server-url'         => '#^https://[a-z0-9.-]+(:\d+)?(/[A-Za-z0-9._~/-]*)?$#',
	'version'            => '/^\d+\.\d+\.\d+([.-][0-9A-Za-z.-]+)?$/',
);
foreach ( $checks as $premium_addon_starter_key => $premium_addon_starter_pattern ) {
	if ( isset( $o[ $premium_addon_starter_key ] ) && ! preg_match( $premium_addon_starter_pattern, (string) $o[ $premium_addon_starter_key ] ) ) {
		fwrite( STDERR, "Invalid --{$premium_addon_starter_key}: {$o[ $premium_addon_starter_key ]}\n" );
		exit( 2 );
	}
}
$premium_addon_starter_keys = array_values( array_filter( (array) ( $o['public-key'] ?? array() ) ) );
foreach ( $premium_addon_starter_keys as $premium_addon_starter_key ) {
	$premium_addon_starter_raw = base64_decode( (string) $premium_addon_starter_key, true );
	if ( false === $premium_addon_starter_raw || 32 !== strlen( $premium_addon_starter_raw ) ) {
		fwrite( STDERR, "Invalid --public-key (expected base64 of 32 bytes from `wp wplc signing-key --field=public_key`)\n" );
		exit( 2 );
	}
}
if ( array() === $premium_addon_starter_keys ) {
	fwrite( STDERR, "Warning: no --public-key given. The add-on refuses to activate until Config::SERVER_PUBLIC_KEYS is filled in.\n" );
}

$premium_addon_starter_source = dirname( __DIR__ );
$premium_addon_starter_target = rtrim( (string) $o['target'], '/' );
if ( is_dir( $premium_addon_starter_target ) && count( (array) scandir( $premium_addon_starter_target ) ) > 2 ) {
	fwrite( STDERR, "Target {$premium_addon_starter_target} is not empty.\n" );
	exit( 2 );
}
$premium_addon_starter_server = rtrim( (string) $o['server-url'], '/' );
$premium_addon_starter_ns     = (string) $o['namespace'];
$premium_addon_starter_const  = strtoupper( (string) $o['prefix'] );

// Placeholders used everywhere (order matters: longer first).
$premium_addon_starter_everywhere = array(
	'Vendor\\\\Product\\\\Premium'  => str_replace( '\\', '\\\\', $premium_addon_starter_ns ),
	'Vendor\\Product\\Premium'      => $premium_addon_starter_ns,
	'Premium Add-on Starter'        => (string) $o['name'],
	'PREMIUM_ADDON_STARTER'         => $premium_addon_starter_const,
	'premium_addon_starter'         => (string) $o['prefix'],
	'premium-addon-starter'         => (string) $o['slug'],
);
$premium_addon_starter_php_const = static function ( string $code, string $name, string $value ): string {
	return (string) preg_replace( '/(public const ' . $name . ' = )[^;]+;/', '${1}' . $value . ';', $code, 1 );
};
$premium_addon_starter_keys_php = array() === $premium_addon_starter_keys ? 'array()' : "array(\n\t\t'" . implode( "',\n\t\t'", $premium_addon_starter_keys ) . "',\n\t)";

$premium_addon_starter_files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $premium_addon_starter_source, FilesystemIterator::SKIP_DOTS ) );
$premium_addon_starter_count = 0;
foreach ( $premium_addon_starter_files as $premium_addon_starter_file ) {
	$premium_addon_starter_rel = substr( (string) $premium_addon_starter_file->getPathname(), strlen( $premium_addon_starter_source ) + 1 );
	// The init script itself is not part of a product repository (its placeholders would be rewritten).
	if ( preg_match( '#^(vendor|dist|node_modules)/|^\.phpunit|^composer\.lock$|^bin/init-addon\.php$#', $premium_addon_starter_rel ) ) {
		continue;
	}
	$premium_addon_starter_out_rel = 'premium-addon-starter.php' === $premium_addon_starter_rel ? $o['slug'] . '.php' : $premium_addon_starter_rel;
	$premium_addon_starter_out     = $premium_addon_starter_target . '/' . $premium_addon_starter_out_rel;
	if ( ! is_dir( dirname( $premium_addon_starter_out ) ) ) {
		mkdir( dirname( $premium_addon_starter_out ), 0755, true );
	}
	$premium_addon_starter_code = (string) file_get_contents( (string) $premium_addon_starter_file->getPathname() );
	if ( 0 !== strpos( $premium_addon_starter_rel, 'tests/fixtures/' ) ) {
		$premium_addon_starter_code = strtr( $premium_addon_starter_code, $premium_addon_starter_everywhere );
	}
	if ( 'src/Config.php' === $premium_addon_starter_rel ) {
		$premium_addon_starter_code = $premium_addon_starter_php_const( $premium_addon_starter_code, 'PRODUCT_KEY', var_export( (string) $o['product-key'], true ) );
		$premium_addon_starter_code = $premium_addon_starter_php_const( $premium_addon_starter_code, 'ENTITLEMENT_PREFIX', var_export( (string) $o['entitlement-prefix'], true ) );
		$premium_addon_starter_code = $premium_addon_starter_php_const( $premium_addon_starter_code, 'SERVER_URL', var_export( $premium_addon_starter_server, true ) );
		$premium_addon_starter_code = $premium_addon_starter_php_const( $premium_addon_starter_code, 'SERVER_PUBLIC_KEYS', $premium_addon_starter_keys_php );
		$premium_addon_starter_code = $premium_addon_starter_php_const( $premium_addon_starter_code, 'CORE_NAME', var_export( (string) $o['core-name'], true ) );
		$premium_addon_starter_code = $premium_addon_starter_php_const( $premium_addon_starter_code, 'CORE_VERSION_CONSTANT', var_export( (string) $o['core-constant'], true ) );
		$premium_addon_starter_code = $premium_addon_starter_php_const( $premium_addon_starter_code, 'CORE_MIN_VERSION', var_export( (string) $o['core-min'], true ) );
		$premium_addon_starter_code = $premium_addon_starter_php_const( $premium_addon_starter_code, 'MIN_PHP', var_export( (string) $o['requires-php'], true ) );
	}
	if ( 'premium-addon-starter.php' === $premium_addon_starter_rel ) {
		$premium_addon_starter_version = (string) ( $o['version'] ?? '1.0.0' );
		$premium_addon_starter_code    = strtr(
			$premium_addon_starter_code,
			array(
				'Core Plugin'                  => (string) $o['core-name'],
				'core-plugin-slug'             => (string) $o['core-slug'],
				'https://shop.example'         => $premium_addon_starter_server,
				'Author:            Vendor'    => 'Author:            ' . (string) ( $o['author'] ?? 'Vendor' ),
				"'8.5'"                        => var_export( (string) $o['requires-php'], true ),
				'Requires PHP:      8.5'       => 'Requires PHP:      ' . (string) $o['requires-php'],
				'Version:           1.0.0'     => 'Version:           ' . $premium_addon_starter_version,
				"_VERSION', '1.0.0'"           => "_VERSION', '" . $premium_addon_starter_version . "'",
			)
		);
	}
	file_put_contents( $premium_addon_starter_out, $premium_addon_starter_code );
	++$premium_addon_starter_count;
}
fwrite( STDOUT, "Created {$o['name']} in {$premium_addon_starter_target} ({$premium_addon_starter_count} files).\n" );
