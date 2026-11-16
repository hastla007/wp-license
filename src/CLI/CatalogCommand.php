<?php
/**
 * Catalog files from the command line.
 *
 * @package WPLC
 */

namespace WPLC\CLI;

use WPLC\Admin\CatalogFileAdmin;
use WPLC\Catalog\CatalogFile;
use WPLC\Catalog\CatalogStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `wp wplc catalog`: import, check, list, export and remove the catalog files (`wplc-catalog.json`)
 * of the license products (T-150, docs/CATALOG-FILE.md).
 */
final class CatalogCommand {

	/**
	 * Import one or more catalog files.
	 *
	 * Each file describes one license product. The import replaces the whole definition of that
	 * product; importing an unchanged file changes nothing. Plans and licenses are not touched.
	 *
	 * ## OPTIONS
	 *
	 * <file>...
	 * : Path of a catalog file (wplc-catalog.json).
	 *
	 * [--dry-run]
	 * : Validate the files and show what would change, without writing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wplc catalog import my-plugin-pro/wplc-catalog.json
	 *     wp wplc catalog import build/*.json --dry-run
	 *
	 * @param array<int,string>   $args       File paths.
	 * @param array<string,mixed> $assoc_args Options.
	 * @return void
	 */
	public function import( array $args, array $assoc_args ): void {
		$dry_run = ! empty( $assoc_args['dry-run'] );
		$failed  = 0;
		foreach ( $args as $path ) {
			$result = self::run_import( (string) $path, $dry_run );
			if ( ! $result['ok'] ) {
				++$failed;
				foreach ( $result['errors'] as $error ) {
					\WP_CLI::warning( $path . ': ' . ( '' !== $error['path'] ? $error['path'] . ': ' : '' ) . CatalogFileAdmin::error_message( $error['code'] ) );
				}
				continue;
			}
			\WP_CLI::log( $path . ': ' . ( $dry_run ? self::dry_run_message( $result ) : CatalogFileAdmin::result_message( $result ) ) );
		}
		if ( $failed ) {
			\WP_CLI::error( __( 'At least one catalog file was not imported.', 'wp-license' ) );
		}
		\WP_CLI::success( $dry_run ? __( 'Every file is valid. Nothing was written.', 'wp-license' ) : __( 'Catalog files imported.', 'wp-license' ) );
	}

	/**
	 * Check catalog files without importing them.
	 *
	 * ## OPTIONS
	 *
	 * <file>...
	 * : Path of a catalog file.
	 *
	 * @param array<int,string> $args File paths.
	 * @return void
	 */
	public function validate( array $args ): void {
		$this->import( $args, array( 'dry-run' => true ) );
	}

	/**
	 * List the products of the catalog.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, json or csv. Default: table.
	 *
	 * @subcommand list
	 * @param array<int,string>   $args       Unused.
	 * @param array<string,mixed> $assoc_args Options.
	 * @return void
	 */
	public function list_( array $args, array $assoc_args ): void {
		$rows = array();
		foreach ( CatalogStore::all() as $product_key => $product ) {
			$planned = 0;
			foreach ( (array) $product['entitlements'] as $entitlement ) {
				if ( CatalogFile::STATUS_PLANNED === (string) $entitlement['status'] ) {
					++$planned;
				}
			}
			$rows[] = array(
				'product_key'  => (string) $product_key,
				'prefix'       => (string) $product['prefix'],
				'status'       => (string) $product['status'],
				'entitlements' => count( (array) $product['entitlements'] ),
				'planned'      => $planned,
				'source'       => (string) ( $product['source'] ?? '' ),
				'imported_at'  => (string) ( $product['imported_at'] ?? '' ),
			);
		}
		\WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, array( 'product_key', 'prefix', 'status', 'entitlements', 'planned', 'source', 'imported_at' ) );
	}

	/**
	 * Print the catalog file of one product.
	 *
	 * ## OPTIONS
	 *
	 * <product-key>
	 * : Product key.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wplc catalog export my-plugin-pro > wplc-catalog.json
	 *
	 * @param array<int,string> $args Product key.
	 * @return void
	 */
	public function export( array $args ): void {
		$product = CatalogStore::get( (string) ( $args[0] ?? '' ) );
		if ( ! $product ) {
			\WP_CLI::error( CatalogFileAdmin::error_message( 'not_in_catalog' ) );
			return;
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON for a file, not HTML.
		echo CatalogFile::encode( $product );
	}

	/**
	 * Remove a product from the catalog. Plans and licenses keep their entitlements.
	 *
	 * ## OPTIONS
	 *
	 * <product-key>
	 * : Product key.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * @param array<int,string>   $args       Product key.
	 * @param array<string,mixed> $assoc_args Options.
	 * @return void
	 */
	public function remove( array $args, array $assoc_args ): void {
		$product_key = (string) ( $args[0] ?? '' );
		if ( ! CatalogStore::get( $product_key ) ) {
			\WP_CLI::error( CatalogFileAdmin::error_message( 'not_in_catalog' ) );
		}
		/* translators: %s: product key. */
		\WP_CLI::confirm( sprintf( __( 'Remove product %s from the catalog?', 'wp-license' ), $product_key ), $assoc_args );
		CatalogStore::remove( $product_key, (int) get_current_user_id(), CatalogStore::SOURCE_CLI );
		\WP_CLI::success( CatalogFileAdmin::result_message( array( 'result' => 'removed', 'product_key' => $product_key ) ) );
	}

	/**
	 * Import or check one file (also used by tests).
	 *
	 * @param string $path    File path.
	 * @param bool   $dry_run Validate and compare only.
	 * @return array{ok:bool,errors:array<int,array{code:string,path:string}>,result:string,product_key:string,added:string[],removed:string[],changed:string[]}
	 */
	public static function run_import( string $path, bool $dry_run ): array {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return array(
				'ok'          => false,
				'errors'      => array(
					array(
						'code' => 'upload_failed',
						'path' => '',
					),
				),
				'result'      => '',
				'product_key' => '',
				'added'       => array(),
				'removed'     => array(),
				'changed'     => array(),
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file named on the command line.
		return CatalogStore::import_json( (string) file_get_contents( $path ), CatalogStore::SOURCE_CLI, function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0, $dry_run );
	}

	/**
	 * @param array<string,mixed> $result Result of a dry run.
	 * @return string
	 */
	private static function dry_run_message( array $result ): string {
		return sprintf(
			/* translators: 1: product key, 2: result (created, updated, unchanged), 3: number of added keys, 4: number of removed keys, 5: number of changed keys. */
			__( 'valid; product %1$s would be %2$s (%3$d added, %4$d removed, %5$d changed).', 'wp-license' ),
			(string) $result['product_key'],
			(string) $result['result'],
			count( (array) $result['added'] ),
			count( (array) $result['removed'] ),
			count( (array) $result['changed'] )
		);
	}
}
