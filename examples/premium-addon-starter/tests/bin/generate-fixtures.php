#!/usr/bin/env php
<?php
/**
 * Write signed client fixtures with the license server's own code (Response::envelope + ResponseSigner).
 *
 * Usage: php tests/bin/generate-fixtures.php [--server=/path/to/wp-license]
 * Default server: $WPLC_SERVER_DIR, else the license server repository this starter lives in (../../).
 *
 * Everything is deterministic (fixed keys, secret, nonce and times; Ed25519 and HMAC are deterministic), so the
 * committed files only change when the server's envelope format changes. tests/Unit/ServerFixturesTest.php
 * regenerates them in memory when the server code is available and fails on a difference.
 *
 * TEST KEYS ONLY. The server seed below is public; never use it for a real server.
 *
 * @package Vendor\Product\Premium
 */

/** Fixed fixture parameters. */
const PREMIUM_ADDON_STARTER_FIXTURE = array(
	'installation_id'     => '6f1c2a3b-4d5e-4f60-8a7b-9c0d1e2f3a4b',
	'other_installation'  => '6f1c2a3b-4d5e-4f60-8a7b-000000000000',
	'activation_id'       => 4711,
	'nonce'               => 'fixture-nonce-0123456789abcdef',
	'server_time'         => '2026-11-01 00:00:00',
	'cache_expires_at'    => '2026-11-15 00:00:00',
	'product_key'         => 'starter-product',
	'entitlement_prefix'  => 'starter.',
	'server_seed_byte'    => 17,
	'forger_seed_byte'    => 34,
	'package_bytes_b64'   => 'UEsFBgAAAAAAAAAAAAAAAAAAAAAAAA==',
	'server_url'          => 'https://shop.example',
	'installation_secret' => '0f1e2d3c4b5a69788796a5b4c3d2e1f00f1e2d3c4b5a69788796a5b4c3d2e1f0',
);

/**
 * Load the server classes the fixtures are built with.
 *
 * @param string $server License server repository root.
 * @throws RuntimeException When the classes are missing.
 */
function premium_addon_starter_load_server( string $server ): void {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', sys_get_temp_dir() . '/' );
	}
	if ( ! function_exists( 'wp_json_encode' ) ) {
		/**
		 * Stub.
		 *
		 * @param mixed $value   Value.
		 * @param int   $options Flags.
		 * @param int   $depth   Depth.
		 * @return string|false
		 */
		function wp_json_encode( $value, $options = 0, $depth = 512 ) {
			return json_encode( $value, $options, $depth );
		}
	}
	foreach ( array( 'Security/SecretCrypto', 'Security/ResponseSigner', 'Api/V1/Response' ) as $class ) {
		$file = rtrim( $server, '/' ) . '/src/' . $class . '.php';
		if ( ! is_file( $file ) ) {
			throw new RuntimeException( 'License server class not found: ' . $file );
		}
		require_once $file;
	}
}

/**
 * Build every fixture.
 *
 * @return array<string,mixed> File name => decoded JSON (or raw bytes for package.zip).
 */
function premium_addon_starter_build_fixtures(): array {
	$f       = PREMIUM_ADDON_STARTER_FIXTURE;
	$signer  = '\\WPLC\\Security\\ResponseSigner';
	$server  = $signer::secret_key_from( base64_encode( str_repeat( chr( $f['server_seed_byte'] ), 32 ) ) );
	$forger  = $signer::secret_key_from( base64_encode( str_repeat( chr( $f['forger_seed_byte'] ), 32 ) ) );
	$secret  = $f['installation_secret'];
	$package = (string) base64_decode( $f['package_bytes_b64'], true );

	// The server signs with its own key; give it this test key for the duration of the build.
	$sign = static function ( array $data, string $installation, $key ) use ( $f, $secret, $signer ): array {
		$envelope = \WPLC\Api\V1\Response::envelope( $data, $secret, $f['nonce'] );
		$json     = (string) wp_json_encode( $data, JSON_UNESCAPED_SLASHES );
		return array_merge(
			$envelope,
			array(
				'signature_ed25519'         => $signer::sign_with( $signer::message( $installation, $json ), $key ),
				'payload_signature_ed25519' => $signer::sign_with( $signer::payload_message( $installation, (string) $envelope['payload'] ), $key ),
				'key_id'                    => $signer::key_id_of( $signer::public_key_of( $key ) ),
			)
		);
	};
	$entitlement_data = static function ( string $status, array $entitlements, string $product_key = '' ) use ( $f ): array {
		$access = in_array( $status, array( 'active', 'past_due' ), true );
		return array(
			'activation_id'    => $f['activation_id'],
			'installation_id'  => $f['installation_id'],
			'activation_status' => 'active',
			'product_key'      => '' === $product_key ? $f['product_key'] : $product_key,
			'license_status'   => $status,
			'access'           => $access,
			'paid_until'       => '2027-11-01 00:00:00',
			'access_until'     => $access ? '2027-11-15 00:00:00' : null,
			'grace_until'      => $access ? '2027-11-15 00:00:00' : null,
			'site_limit'       => 5,
			'site_count'       => 1,
			'environment'      => 'production',
			'entitlements'     => $entitlements,
			'server_time'      => $f['server_time'],
			'cache_expires_at' => $f['cache_expires_at'],
		);
	};
	$granted = array(
		'starter.pdf_templates' => true,
		'starter.reports'       => true,
		'starter.disabled'      => false,
		'starter.monthly_limit' => 500,
		'other.feature'         => true,
	);
	$id       = $f['installation_id'];
	$fixtures = array();

	$fixtures['keys.json'] = array(
		'_comment'            => 'TEST KEYS ONLY - generated by tests/bin/generate-fixtures.php',
		'installation_id'     => $id,
		'installation_secret' => $secret,
		'activation_id'       => $f['activation_id'],
		'server_public_key'   => $signer::public_key_of( $server ),
		'forger_public_key'   => $signer::public_key_of( $forger ),
		'server_seed_byte'    => $f['server_seed_byte'],
		'nonce'               => $f['nonce'],
		'server_time'         => $f['server_time'],
		'product_key'         => $f['product_key'],
		'entitlement_prefix'  => $f['entitlement_prefix'],
		'server_url'          => $f['server_url'],
	);
	$fixtures['entitlements-active.json']   = $sign( $entitlement_data( 'active', $granted ), $id, $server );
	$fixtures['entitlements-past-due.json'] = $sign( $entitlement_data( 'past_due', $granted ), $id, $server );
	// The server sends no entitlements without access; the client must not rely on that alone.
	$fixtures['entitlements-expired.json']         = $sign( $entitlement_data( 'expired', array() ), $id, $server );
	$fixtures['entitlements-revoked.json']         = $sign( $entitlement_data( 'revoked', array() ), $id, $server );
	$fixtures['entitlements-revoked-leaky.json']   = $sign( $entitlement_data( 'revoked', $granted ), $id, $server );
	$fixtures['entitlements-foreign-product.json'] = $sign( $entitlement_data( 'active', array( 'other.pdf_templates' => true, 'otherstarter.reports' => true ) ), $id, $server );
	$fixtures['entitlements-other-installation.json'] = $sign( $entitlement_data( 'active', $granted ), $f['other_installation'], $server );
	$fixtures['entitlements-forged-key.json']         = $sign( $entitlement_data( 'active', $granted ), $id, $forger );

	// Tampered cache: data changed and both HMACs recomputed with the installation secret (which the site holds),
	// the server's Ed25519 signature kept from the original. Only the pinned key can detect this.
	$tampered_data = $entitlement_data( 'active', array_merge( $granted, array( 'starter.white_label' => true ) ) );
	$original      = $sign( $entitlement_data( 'expired', array() ), $id, $server );
	$tampered      = \WPLC\Api\V1\Response::envelope( $tampered_data, $secret, $f['nonce'] );
	$tampered['signature_ed25519']        = $original['signature_ed25519'];
	$tampered['payload_signature_ed25519'] = $original['payload_signature_ed25519'];
	$tampered['key_id']                   = $original['key_id'];
	$fixtures['entitlements-tampered.json'] = $tampered;

	$unsigned = \WPLC\Api\V1\Response::envelope( $entitlement_data( 'active', $granted ), $secret, $f['nonce'] );
	$fixtures['entitlements-unsigned.json'] = $unsigned;

	$fixtures['update-available.json'] = $sign(
		array(
			'update_available'    => true,
			'product_key'         => $f['product_key'],
			'version'             => '9.9.9',
			'package_url'         => $f['server_url'] . '/wp-json/wplc/v1/updates/download/' . str_repeat( 'a1', 32 ),
			'sha256'              => hash( 'sha256', $package ),
			'requires_php'        => '7.4',
			'requires_wp'         => '6.5',
			'tested_wp'           => '6.8',
			'release_notes'       => '<p>Fixes &amp; improvements.</p>',
			'download_expires_at' => '2026-11-01 00:10:00',
		),
		$id,
		$server
	);
	$fixtures['error-product-mismatch.json'] = array(
		'code'    => 'product_mismatch',
		'message' => 'This license belongs to a different product.',
		'data'    => array( 'status' => 403 ),
	);
	$fixtures['package.zip'] = $package;
	return $fixtures;
}

/**
 * Encode one fixture as written to disk.
 *
 * @param string $name  File name.
 * @param mixed  $value Fixture.
 */
function premium_addon_starter_encode_fixture( string $name, $value ): string {
	return 'package.zip' === $name ? (string) $value : json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
}

if ( 'cli' === PHP_SAPI && isset( $argv[0] ) && realpath( $argv[0] ) === __FILE__ ) {
	$premium_addon_starter_opts   = getopt( '', array( 'server:' ) );
	$premium_addon_starter_server = $premium_addon_starter_opts['server'] ?? ( getenv( 'WPLC_SERVER_DIR' ) ? (string) getenv( 'WPLC_SERVER_DIR' ) : dirname( __DIR__, 4 ) );
	try {
		premium_addon_starter_load_server( $premium_addon_starter_server );
	} catch ( RuntimeException $e ) {
		fwrite( STDERR, $e->getMessage() . " (pass --server=/path/to/wp-license)\n" );
		exit( 2 );
	}
	$premium_addon_starter_dir = dirname( __DIR__ ) . '/fixtures';
	foreach ( premium_addon_starter_build_fixtures() as $premium_addon_starter_name => $premium_addon_starter_value ) {
		file_put_contents( $premium_addon_starter_dir . '/' . $premium_addon_starter_name, premium_addon_starter_encode_fixture( $premium_addon_starter_name, $premium_addon_starter_value ) );
		fwrite( STDOUT, "wrote tests/fixtures/{$premium_addon_starter_name}\n" );
	}
}
