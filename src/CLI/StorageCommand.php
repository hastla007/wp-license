<?php
/**
 * Check the object storage configuration from the command line.
 *
 * @package WPLC
 */

namespace WPLC\CLI;

use WPLC\Update\S3Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `wp wplc storage`: shows and checks the S3-compatible object storage. Never prints a key or a signed URL.
 */
final class StorageCommand {

	/**
	 * Check the object storage with a signed HEAD request.
	 *
	 * Prints the endpoint host, bucket, region and addressing style, then sends
	 * a HEAD request signed with the configured key, to the bucket or to one
	 * object. Neither the secret nor the signed URL is printed.
	 *
	 * ## OPTIONS
	 *
	 * [--key=<key>]
	 * : Object key to check instead of the bucket. Use it when the access key may only read objects.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wplc storage check
	 *     wp wplc storage check --key=acme-pro/acme-pro-1.4.0.zip
	 *
	 * @param array<int,string>   $args       Positional arguments.
	 * @param array<string,mixed> $assoc_args Options.
	 * @return void
	 */
	public function check( array $args, array $assoc_args ): void {
		$result = self::run_check( (string) ( $assoc_args['key'] ?? '' ) );
		foreach ( $result['lines'] as $line ) {
			\WP_CLI::line( $line );
		}
		if ( ! $result['ok'] ) {
			\WP_CLI::error( $result['message'] );
		}
		\WP_CLI::success( $result['message'] );
	}

	/**
	 * Run the check. Kept free of WP_CLI calls so it can be tested.
	 *
	 * @param string $key Object key, or '' for the bucket.
	 * @return array{ok:bool,code:string,message:string,lines:string[]}
	 */
	public static function run_check( string $key ): array {
		$config = S3Storage::config();
		$lines = array();
		if ( 'configured' === $config['state'] ) {
			/* translators: %s: host name. */
			$lines[] = sprintf( __( 'Endpoint host: %s', 'wp-license' ), $config['endpoint_host'] );
			/* translators: %s: bucket name. */
			$lines[] = sprintf( __( 'Bucket: %s', 'wp-license' ), $config['bucket'] );
			/* translators: %s: region name. */
			$lines[] = sprintf( __( 'Region: %s', 'wp-license' ), $config['region'] );
			$lines[] = $config['path_style'] ? __( 'Addressing: path-style', 'wp-license' ) : __( 'Addressing: virtual-hosted-style', 'wp-license' );
		}
		$check = S3Storage::check( trim( $key ) );

		return array(
			'ok' => $check['ok'],
			'code' => $check['code'],
			'message' => $check['message'],
			'lines' => $lines,
		);
	}
}
