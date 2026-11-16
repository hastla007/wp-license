<?php
/**
 * Stores the imported catalog files: license products and their entitlement definitions.
 *
 * @package WPLC
 */

namespace WPLC\Catalog;

use WPLC\License\AuditLog;
use WPLC\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The catalog as data (T-150, owner decisions G2 and E8 of 16 Nov 2026).
 *
 * Storage: one option that is not autoloaded. A catalog is small (a handful of products with a
 * few dozen keys each, a few kilobytes), it is always read as a whole (product editor, pricing
 * table, this screen), an import replaces one product at once, and nothing joins against it:
 * plans and licenses store entitlement keys as JSON, not references. Tables would add schema
 * steps, uninstall and multisite handling without a query that needs them.
 */
final class CatalogStore {

	/**
	 * Option with all imported products (not autoloaded).
	 */
	public const OPTION = 'wplc_entitlement_catalog';

	/**
	 * Where an import came from.
	 */
	public const SOURCE_BUNDLED = 'bundled';
	public const SOURCE_ADMIN   = 'admin';
	public const SOURCE_CLI     = 'cli';

	/**
	 * Results of import().
	 */
	public const RESULT_CREATED   = 'created';
	public const RESULT_UPDATED   = 'updated';
	public const RESULT_UNCHANGED = 'unchanged';

	/**
	 * Products read in this request.
	 *
	 * @var array<string,array<string,mixed>>|null
	 */
	private static $cache = null;

	/**
	 * The store when there is no WordPress to keep it (unit tests, command line tools).
	 *
	 * @var array<string,mixed>
	 */
	private static $memory = array();

	/**
	 * Every imported product, in the order of import.
	 *
	 * @return array<string,array<string,mixed>> Product key => normalized product (see CatalogFile::validate())
	 *         with `source`, `imported_at` (UTC) and `fingerprint`.
	 */
	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$stored   = function_exists( 'update_option' ) && function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : self::$memory;
		$products = array();
		foreach ( is_array( $stored ) && isset( $stored['products'] ) && is_array( $stored['products'] ) ? $stored['products'] : array() as $product_key => $product ) {
			if ( is_string( $product_key ) && is_array( $product ) && isset( $product['prefix'], $product['entitlements'] ) && is_array( $product['entitlements'] ) ) {
				$products[ $product_key ] = $product;
			}
		}
		self::$cache = $products;

		return $products;
	}

	/**
	 * One product.
	 *
	 * @param string $product_key Product key.
	 * @return array<string,mixed>|null
	 */
	public static function get( string $product_key ): ?array {
		$all = self::all();

		return $all[ $product_key ] ?? null;
	}

	/**
	 * Forget what this request read (tests, and after another process wrote).
	 *
	 * @return void
	 */
	public static function reset_cache(): void {
		self::$cache = null;
	}

	/**
	 * Import the content of a catalog file.
	 *
	 * Importing replaces the whole definition of that product: a key that is no longer in the
	 * file leaves the catalog. Plans and licenses that store such a key keep it (the product
	 * editor shows it in the advanced JSON field). Importing the same file again changes nothing.
	 *
	 * @param string $json          File content.
	 * @param string $source        One of the SOURCE_* constants.
	 * @param int    $actor_user_id Acting user, 0 for none.
	 * @param bool   $dry_run       Validate and compare only.
	 * @return array{ok:bool,errors:array<int,array{code:string,path:string}>,result:string,product_key:string,added:string[],removed:string[],changed:string[]}
	 */
	public static function import_json( string $json, string $source, int $actor_user_id = 0, bool $dry_run = false ): array {
		$parsed = CatalogFile::parse( $json );
		if ( ! $parsed['ok'] || ! is_array( $parsed['catalog'] ) ) {
			return array(
				'ok'          => false,
				'errors'      => $parsed['errors'],
				'result'      => '',
				'product_key' => '',
				'added'       => array(),
				'removed'     => array(),
				'changed'     => array(),
			);
		}

		return self::import( $parsed['catalog'], $source, $actor_user_id, $dry_run );
	}

	/**
	 * Store a validated product.
	 *
	 * @param array<string,mixed> $catalog       Normalized product from CatalogFile.
	 * @param string              $source        One of the SOURCE_* constants.
	 * @param int                 $actor_user_id Acting user, 0 for none.
	 * @param bool                $dry_run       Compare only.
	 * @return array{ok:bool,errors:array<int,array{code:string,path:string}>,result:string,product_key:string,added:string[],removed:string[],changed:string[]}
	 */
	public static function import( array $catalog, string $source, int $actor_user_id = 0, bool $dry_run = false ): array {
		self::reset_cache();
		$products    = self::all();
		$product_key = (string) $catalog['product_key'];
		$fingerprint = CatalogFile::fingerprint( $catalog );
		$existing    = $products[ $product_key ] ?? null;
		$report      = array(
			'ok'          => true,
			'errors'      => array(),
			'result'      => self::RESULT_CREATED,
			'product_key' => $product_key,
			'added'       => array(),
			'removed'     => array(),
			'changed'     => array(),
		);

		// One prefix belongs to one product: two products sharing a prefix could redefine each other's keys.
		foreach ( $products as $other_key => $other ) {
			if ( $other_key !== $product_key && (string) $other['prefix'] === (string) $catalog['prefix'] ) {
				$report['ok']     = false;
				$report['result'] = '';
				$report['errors'] = array(
					array(
						'code' => 'prefix_in_use',
						'path' => 'prefix',
					),
				);
				return $report;
			}
		}

		$new = self::by_key( (array) $catalog['entitlements'] );
		$old = $existing ? self::by_key( (array) $existing['entitlements'] ) : array();
		$report['added']   = array_values( array_diff( array_keys( $new ), array_keys( $old ) ) );
		$report['removed'] = array_values( array_diff( array_keys( $old ), array_keys( $new ) ) );
		foreach ( $new as $key => $entitlement ) {
			if ( isset( $old[ $key ] ) && $old[ $key ] !== $entitlement ) {
				$report['changed'][] = $key;
			}
		}
		if ( $existing ) {
			$report['result'] = (string) ( $existing['fingerprint'] ?? '' ) === $fingerprint ? self::RESULT_UNCHANGED : self::RESULT_UPDATED;
		}
		if ( $dry_run || self::RESULT_UNCHANGED === $report['result'] ) {
			return $report;
		}

		$catalog['source']        = $source;
		$catalog['imported_at']   = gmdate( 'Y-m-d H:i:s' );
		$catalog['fingerprint']   = $fingerprint;
		$products[ $product_key ] = $catalog;
		self::write( $products );

		self::audit(
			'catalog_file_imported',
			$source,
			$existing ? self::summary( $existing ) : null,
			self::summary( $catalog ) + array(
				'added'   => $report['added'],
				'removed' => $report['removed'],
				'changed' => $report['changed'],
			),
			$actor_user_id
		);

		return $report;
	}

	/**
	 * Remove a product from the catalog. Plans and licenses are not touched.
	 *
	 * @param string $product_key   Product key.
	 * @param int    $actor_user_id Acting user.
	 * @param string $source        One of the SOURCE_* constants.
	 * @return bool False when the product is not in the catalog.
	 */
	public static function remove( string $product_key, int $actor_user_id = 0, string $source = self::SOURCE_ADMIN ): bool {
		self::reset_cache();
		$products = self::all();
		if ( ! isset( $products[ $product_key ] ) ) {
			return false;
		}
		$removed = $products[ $product_key ];
		unset( $products[ $product_key ] );
		self::write( $products );
		self::audit( 'catalog_product_removed', $source, self::summary( $removed ), null, $actor_user_id );

		return true;
	}

	/**
	 * Import the catalog files shipped with this plugin (folder `catalogs/`).
	 *
	 * A product that an administrator or the command line imported is never replaced: the
	 * vendor's own file wins over the bundled one. Idempotent.
	 *
	 * @param string|null $directory Folder with `*.json` files; default: `catalogs/` of this plugin.
	 * @return array{imported:string[],skipped:string[],failed:string[]} Product keys or file names.
	 */
	public static function import_bundled( ?string $directory = null ): array {
		$directory = null === $directory ? dirname( __DIR__, 2 ) . '/catalogs' : $directory;
		$report    = array(
			'imported' => array(),
			'skipped'  => array(),
			'failed'   => array(),
		);
		$files     = glob( rtrim( $directory, '/' ) . '/*.json' );
		$files     = is_array( $files ) ? $files : array();
		sort( $files );
		foreach ( $files as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file shipped with the plugin.
			$parsed = CatalogFile::parse( (string) file_get_contents( $file ) );
			if ( ! $parsed['ok'] || ! is_array( $parsed['catalog'] ) ) {
				$report['failed'][] = basename( $file );
				continue;
			}
			$existing = self::get( (string) $parsed['catalog']['product_key'] );
			if ( $existing && self::SOURCE_BUNDLED !== (string) ( $existing['source'] ?? '' ) ) {
				$report['skipped'][] = (string) $parsed['catalog']['product_key'];
				continue;
			}
			$result = self::import( $parsed['catalog'], self::SOURCE_BUNDLED );
			if ( ! $result['ok'] ) {
				$report['failed'][] = basename( $file );
			} elseif ( self::RESULT_UNCHANGED === $result['result'] ) {
				$report['skipped'][] = $result['product_key'];
			} else {
				$report['imported'][] = $result['product_key'];
			}
		}

		return $report;
	}

	/**
	 * The text of a name, label or description in a locale.
	 *
	 * Order: the locale itself, the shipped fallback of that locale (`de_AT` reads `de_DE_formal`,
	 * see Plugin::fallback_locale()), English.
	 *
	 * @param mixed       $texts  Locale => text.
	 * @param string|null $locale WordPress locale; default: the locale of the current request.
	 * @return string Empty when there is no text.
	 */
	public static function text( $texts, ?string $locale = null ): string {
		if ( ! is_array( $texts ) ) {
			return is_string( $texts ) ? $texts : '';
		}
		if ( null === $locale ) {
			$locale = function_exists( 'determine_locale' ) ? (string) determine_locale() : 'en_US';
		}
		$candidates = array( $locale );
		$fallback   = Plugin::fallback_locale( $locale );
		if ( '' !== $fallback ) {
			$candidates[] = $fallback;
		}
		$candidates[] = 'en';
		foreach ( $candidates as $candidate ) {
			if ( isset( $texts[ $candidate ] ) && is_string( $texts[ $candidate ] ) && '' !== $texts[ $candidate ] ) {
				return $texts[ $candidate ];
			}
		}

		return '';
	}

	/**
	 * @param array<int|string,mixed> $entitlements List of entitlements as stored (the option content is not trusted).
	 * @return array<string,array<string,mixed>> Key => entitlement.
	 */
	private static function by_key( array $entitlements ): array {
		$map = array();
		foreach ( $entitlements as $entitlement ) {
			if ( is_array( $entitlement ) && isset( $entitlement['key'] ) ) {
				$map[ (string) $entitlement['key'] ] = $entitlement;
			}
		}

		return $map;
	}

	/**
	 * Short description of a product for the audit log.
	 *
	 * @param array<string,mixed> $product Product.
	 * @return array<string,mixed>
	 */
	private static function summary( array $product ): array {
		$planned = 0;
		foreach ( (array) $product['entitlements'] as $entitlement ) {
			if ( CatalogFile::STATUS_PLANNED === ( $entitlement['status'] ?? '' ) ) {
				++$planned;
			}
		}

		return array(
			'product_key'  => (string) $product['product_key'],
			'prefix'       => (string) $product['prefix'],
			'status'       => (string) $product['status'],
			'entitlements' => count( (array) $product['entitlements'] ),
			'planned'      => $planned,
			'fingerprint'  => (string) ( $product['fingerprint'] ?? '' ),
		);
	}

	/**
	 * @param array<string,array<string,mixed>> $products Products.
	 * @return void
	 */
	private static function write( array $products ): void {
		$value = array(
			'format'   => CatalogFile::FORMAT,
			'products' => $products,
		);
		if ( function_exists( 'update_option' ) ) {
			update_option( self::OPTION, $value, false );
		} else {
			self::$memory = $value;
		}
		self::$cache = $products;
	}

	/**
	 * @param string                   $action        Audit action.
	 * @param string                   $source        Source of the change.
	 * @param array<string,mixed>|null $before        State before.
	 * @param array<string,mixed>|null $after         State after.
	 * @param int                      $actor_user_id Acting user.
	 * @return void
	 */
	private static function audit( string $action, string $source, ?array $before, ?array $after, int $actor_user_id ): void {
		// The files shipped with the plugin are part of an upgrade, not an act of a person: schema step 24
		// imports them on every installation, and the audit log records what people and jobs change.
		if ( self::SOURCE_BUNDLED === $source ) {
			return;
		}
		// Unit tests and very early calls have no database.
		if ( ! isset( $GLOBALS['wpdb'] ) || ! is_object( $GLOBALS['wpdb'] ) || ! function_exists( 'sanitize_key' ) ) {
			return;
		}
		AuditLog::record( $action, 'catalog', 0, '', $before, ( null === $after ? array() : $after ) + array( 'source' => $source ), $actor_user_id );
	}
}
