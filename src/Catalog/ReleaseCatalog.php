<?php
/**
 * Imports the catalog file of an add-on from its release package (PROD-03).
 *
 * @package WPLC
 */

namespace WPLC\Catalog;

use WPLC\Infrastructure\Database\Schema;
use WPLC\License\AuditLog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * When a release is created from a ZIP, the file `wplc-catalog.json` in the plugin folder of the
 * package is read and imported (owner decision PROD-Q8 of 16 Nov 2026).
 *
 * - The file is read from the archive in memory, never extracted to disk, from exactly one path
 *   (`{plugin folder}/wplc-catalog.json`), and never more than CatalogFile::MAX_BYTES of it.
 * - The release is never refused because of its catalog file. An invalid file, a file for another
 *   product or a package older than an existing release leaves the catalog as it is; the reason
 *   is shown to the person who uploaded the package and, for a refusal, written to the audit log.
 * - A package without the file changes nothing.
 * - An import never changes plans or licenses. When it removes keys that active plans still
 *   grant, it is carried out and the keys are named in a warning.
 */
final class ReleaseCatalog {

	public const STATUS_OFF       = 'off';
	public const STATUS_NO_FILE   = 'no_file';
	public const STATUS_IMPORTED  = 'imported';
	public const STATUS_UNCHANGED = 'unchanged';
	public const STATUS_REFUSED   = 'refused';
	public const STATUS_OLDER     = 'older_release';

	/**
	 * Result of the last import of this request.
	 *
	 * @var array<string,mixed>|null
	 */
	private static $last = null;

	/**
	 * Whether catalog files of release packages are imported.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		$settings = function_exists( 'get_option' ) ? get_option( 'wplc_settings', array() ) : array();
		$enabled  = ! ( is_array( $settings ) && isset( $settings['catalog_auto_import'] ) && ! $settings['catalog_auto_import'] );

		/**
		 * Filters whether the catalog file of an uploaded release package is imported.
		 *
		 * @param bool $enabled Setting "Import the catalog file from release packages" (default: yes).
		 */
		return function_exists( 'apply_filters' ) ? (bool) apply_filters( 'wplc_catalog_auto_import', $enabled ) : $enabled;
	}

	/**
	 * Read the catalog file from a package without extracting anything.
	 *
	 * @param string $zip_path ZIP file on this server.
	 * @param string $slug     The one top-level folder of the package.
	 * @return string|null|\WP_Error Content, null when the package has no catalog file, or an error
	 *                               (`catalog_unreadable`, `file_too_large`).
	 */
	public static function read( string $zip_path, string $slug ) {
		if ( ! class_exists( '\\ZipArchive' ) || '' === $slug ) {
			return null;
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path, \ZipArchive::RDONLY ) ) {
			return new \WP_Error( 'catalog_unreadable', 'The package could not be opened to look for a catalog file.' );
		}
		try {
			// Exactly this entry: no other folder, no other spelling, no path tricks.
			$name = $slug . '/' . CatalogFile::FILE_NAME;
			$stat = $zip->statName( $name );
			if ( ! is_array( $stat ) || (string) $stat['name'] !== $name ) {
				return null;
			}
			// The size the archive declares, before anything is inflated.
			if ( (int) $stat['size'] > CatalogFile::MAX_BYTES ) {
				return new \WP_Error( 'file_too_large', 'The catalog file in the package is larger than 256 KB.' );
			}
			// The declared size can lie: never inflate more than the limit plus one byte.
			$stream = $zip->getStream( $name );
			if ( ! is_resource( $stream ) ) {
				return new \WP_Error( 'catalog_unreadable', 'The catalog file in the package could not be read.' );
			}
			$content = stream_get_contents( $stream, CatalogFile::MAX_BYTES + 1 );
			fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- stream of a ZIP entry, not a file.
			if ( ! is_string( $content ) ) {
				return new \WP_Error( 'catalog_unreadable', 'The catalog file in the package could not be read.' );
			}
			if ( strlen( $content ) > CatalogFile::MAX_BYTES ) {
				return new \WP_Error( 'file_too_large', 'The catalog file in the package is larger than 256 KB.' );
			}

			return $content;
		} finally {
			$zip->close();
		}
	}

	/**
	 * Import the catalog file of a release package that was just stored.
	 *
	 * @param string $zip_path      Stored package.
	 * @param string $slug          Plugin folder inside the package.
	 * @param string $product_key   Product key of the release.
	 * @param string $version       Version of the release.
	 * @param int    $release_id    Release ID.
	 * @param int    $actor_user_id Acting user.
	 * @return array{status:string,reason:string,product_key:string,errors:array<int,array{code:string,path:string}>,added:string[],removed:string[],changed:string[],removed_in_use:string[]}
	 */
	public static function import_from_package( string $zip_path, string $slug, string $product_key, string $version, int $release_id, int $actor_user_id = 0 ): array {
		$result = array(
			'status'         => self::STATUS_NO_FILE,
			'reason'         => '',
			'product_key'    => $product_key,
			'errors'         => array(),
			'added'          => array(),
			'removed'        => array(),
			'changed'        => array(),
			'removed_in_use' => array(),
		);
		if ( ! self::enabled() ) {
			$result['status'] = self::STATUS_OFF;
			return self::remember( $result );
		}

		$content = self::read( $zip_path, $slug );
		if ( null === $content ) {
			return self::remember( $result );
		}
		if ( is_wp_error( $content ) ) {
			return self::refuse( $result, (string) $content->get_error_code(), array(), $release_id, $actor_user_id );
		}
		$parsed = CatalogFile::parse( $content );
		if ( ! $parsed['ok'] || ! is_array( $parsed['catalog'] ) ) {
			return self::refuse( $result, 'invalid_file', $parsed['errors'], $release_id, $actor_user_id );
		}
		$catalog = $parsed['catalog'];
		// A package must not redefine the catalog of another product.
		if ( (string) $catalog['product_key'] !== $product_key ) {
			return self::refuse( $result, 'product_mismatch', array(), $release_id, $actor_user_id, (string) $catalog['product_key'] );
		}
		// An older package uploaded later (a patch of a former line) must not turn the catalog back.
		if ( self::newer_release_exists( $product_key, $version, $release_id ) ) {
			$result['status'] = self::STATUS_OLDER;
			return self::remember( $result );
		}

		$import = CatalogStore::import( $catalog, CatalogStore::SOURCE_RELEASE, $actor_user_id );
		if ( ! $import['ok'] ) {
			return self::refuse( $result, 'invalid_file', $import['errors'], $release_id, $actor_user_id );
		}
		$result['status']  = CatalogStore::RESULT_UNCHANGED === $import['result'] ? self::STATUS_UNCHANGED : self::STATUS_IMPORTED;
		$result['added']   = $import['added'];
		$result['removed'] = $import['removed'];
		$result['changed'] = $import['changed'];
		if ( $import['removed'] ) {
			$result['removed_in_use'] = self::keys_in_use( $product_key, $import['removed'] );
			if ( $result['removed_in_use'] ) {
				AuditLog::record( 'catalog_keys_removed_in_use', 'release', $release_id, '', null, array( 'product_key' => $product_key, 'keys' => $result['removed_in_use'] ), $actor_user_id );
			}
		}

		return self::remember( $result );
	}

	/**
	 * Result of the last import of this request, for the screen or command that created the release.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function last_result(): ?array {
		return self::$last;
	}

	/**
	 * Forget the last result (tests).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$last = null;
	}

	/**
	 * Sentence about the result for the person who created the release; empty when there is nothing to say.
	 *
	 * @param array<string,mixed>|null $result Result of import_from_package().
	 * @return string
	 */
	public static function message( ?array $result ): string {
		if ( null === $result ) {
			return '';
		}
		switch ( (string) $result['status'] ) {
			case self::STATUS_IMPORTED:
				$text = sprintf(
					/* translators: 1: number of added entitlements, 2: number of removed entitlements, 3: number of changed entitlements. */
					__( 'The catalog file of the package was imported: %1$d entitlements added, %2$d removed, %3$d changed.', 'wp-license' ),
					count( (array) $result['added'] ),
					count( (array) $result['removed'] ),
					count( (array) $result['changed'] )
				);
				if ( ! empty( $result['removed_in_use'] ) ) {
					/* translators: %s: comma-separated entitlement keys. */
					$text .= ' ' . sprintf( __( 'Active plans still grant entitlements that left the catalog: %s. Plans and licenses keep them; the product editor shows them in the JSON field.', 'wp-license' ), implode( ', ', (array) $result['removed_in_use'] ) );
				}
				return $text;
			case self::STATUS_REFUSED:
				$reasons = array(
					'product_mismatch'   => __( 'it describes another product', 'wp-license' ),
					'file_too_large'     => __( 'it is larger than 256 KB', 'wp-license' ),
					'catalog_unreadable' => __( 'it could not be read', 'wp-license' ),
					'invalid_file'       => __( 'it is not a valid catalog file', 'wp-license' ),
				);
				$reason  = $reasons[ (string) $result['reason'] ] ?? (string) $result['reason'];
				$detail  = '';
				if ( ! empty( $result['errors'][0] ) ) {
					$detail = ' (' . ( '' !== (string) $result['errors'][0]['path'] ? $result['errors'][0]['path'] . ': ' : '' ) . \WPLC\Admin\CatalogFileAdmin::error_message( (string) $result['errors'][0]['code'] ) . ')';
				}
				/* translators: 1: reason, 2: detail in brackets or empty. */
				return sprintf( __( 'The catalog file of the package was NOT imported: %1$s%2$s. The release was created; the license catalog is unchanged.', 'wp-license' ), $reason, $detail );
			case self::STATUS_OLDER:
				return __( 'The catalog file of the package was not imported, because a release with a higher version exists for this product.', 'wp-license' );
		}

		return '';
	}

	/**
	 * Removed keys that an active plan of the product still grants.
	 *
	 * @param string   $product_key Product key.
	 * @param string[] $keys        Keys that left the catalog.
	 * @return string[]
	 */
	public static function keys_in_use( string $product_key, array $keys ): array {
		global $wpdb;

		$tables = Schema::table_names();
		$used   = array();
		$rows   = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT entitlements_json FROM %i WHERE product_key = %s AND active = 1 LIMIT 500', $tables['plans'], $product_key ) );
		foreach ( $rows as $json ) {
			$entitlements = json_decode( (string) $json, true );
			foreach ( is_array( $entitlements ) ? $entitlements : array() as $key => $value ) {
				if ( in_array( $key, $keys, true ) && false !== $value && null !== $value ) {
					$used[ (string) $key ] = (string) $key;
				}
			}
		}

		return array_values( $used );
	}

	/**
	 * Whether the product has another release with a higher version.
	 *
	 * @param string $product_key Product key.
	 * @param string $version     Version of the new release.
	 * @param int    $release_id  The new release.
	 * @return bool
	 */
	private static function newer_release_exists( string $product_key, string $version, int $release_id ): bool {
		global $wpdb;

		$tables = Schema::table_names();
		$others = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT version FROM %i WHERE product_key = %s AND id <> %d', $tables['releases'], $product_key, $release_id ) );
		foreach ( $others as $other ) {
			if ( version_compare( (string) $other, $version, '>' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Record a refusal: the release stays, the catalog is not changed.
	 *
	 * @param array<string,mixed>                       $result        Result so far.
	 * @param string                                    $reason        Reason code.
	 * @param array<int,array{code:string,path:string}> $errors        Validation errors.
	 * @param int                                       $release_id    Release ID.
	 * @param int                                       $actor_user_id Acting user.
	 * @param string                                    $file_product  Product key named in the file.
	 * @return array<string,mixed>
	 */
	private static function refuse( array $result, string $reason, array $errors, int $release_id, int $actor_user_id, string $file_product = '' ): array {
		$result['status'] = self::STATUS_REFUSED;
		$result['reason'] = $reason;
		$result['errors'] = array_slice( $errors, 0, 20 );
		AuditLog::record(
			'catalog_release_import_refused',
			'release',
			$release_id,
			$reason,
			null,
			array(
				'product_key'      => (string) $result['product_key'],
				'file_product_key' => $file_product,
				'errors'           => array_column( $result['errors'], 'code' ),
			),
			$actor_user_id
		);

		return self::remember( $result );
	}

	/**
	 * @param array<string,mixed> $result Result.
	 * @return array<string,mixed>
	 */
	private static function remember( array $result ): array {
		self::$last = $result;

		return $result;
	}
}
