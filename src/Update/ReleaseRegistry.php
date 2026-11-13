<?php
/**
 * Release registry: the server-side source of update packages.
 *
 * @package WPLC
 */

namespace WPLC\Update;

use WPLC\Infrastructure\Database\Schema;
use WPLC\License\AuditLog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores releases per product and feeds the update API.
 *
 * Life cycle: draft -> published -> withdrawn (-> published). Version, package
 * and checksum are frozen once a release has been published; a wrong package is
 * corrected by withdrawing the release and publishing a new version.
 */
final class ReleaseRegistry {
	const STATUS_DRAFT     = 'draft';
	const STATUS_PUBLISHED = 'published';
	const STATUS_WITHDRAWN = 'withdrawn';

	const STORAGE_URL   = 'url';
	const STORAGE_LOCAL = 'local';

	const DEFAULT_CHANNEL = 'stable';

	/** Same pattern as tools/create-release-manifest.php. */
	const VERSION_PATTERN = '/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.-]+)?$/';
	const PRODUCT_KEY_PATTERN = '/^[a-z0-9][a-z0-9_-]{1,99}$/';
	const PLATFORM_VERSION_PATTERN = '/^[0-9]+(?:\.[0-9]+){0,2}$/';

	/** Fields that are frozen after the first publication. */
	const IMMUTABLE_FIELDS = array( 'product_key', 'version', 'channel', 'package_url', 'storage', 'sha256' );

	/**
	 * Register the update feed integration.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		// Priority 5: custom code on the default priority can still override the registry.
		add_filter( 'wplc_update_packages', array( self::class, 'filter_update_packages' ), 5, 2 );
	}

	/**
	 * Provide the latest published release in the shape UpdateService expects.
	 *
	 * @param mixed $packages Packages keyed by product key.
	 * @param mixed $product_key Requested product key.
	 * @return mixed
	 */
	public static function filter_update_packages( $packages, $product_key = '' ) {
		if ( ! is_array( $packages ) || ! is_string( $product_key ) || '' === $product_key ) {
			return $packages;
		}
		$release = self::latest_published( $product_key );
		if ( null === $release ) {
			return $packages;
		}
		$packages[ $product_key ] = self::to_update_package( $release, self::delivery_url( $release ) );

		return $packages;
	}

	/**
	 * Map a release row to the `wplc_update_packages` entry.
	 *
	 * @param array<string,mixed> $release Release row.
	 * @param string              $package_url HTTPS URL handed to the update API.
	 * @return array<string,string>
	 */
	public static function to_update_package( array $release, string $package_url ): array {
		return array(
			'version' => (string) ( $release['version'] ?? '' ),
			'package_url' => $package_url,
			'sha256' => strtolower( (string) ( $release['sha256'] ?? '' ) ),
			'requires_php' => (string) ( $release['requires_php'] ?? '' ),
			'requires_wp' => (string) ( $release['requires_wp'] ?? '' ),
			'tested_wp' => (string) ( $release['tested_wp'] ?? '' ),
			'release_notes' => (string) ( $release['release_notes'] ?? '' ),
		);
	}

	/**
	 * URL the update API stores in its download grant for a release.
	 *
	 * Local packages never expose their path: they get a stable placeholder URL
	 * on this site that PackageStream turns into a single-use streaming URL.
	 *
	 * @param array<string,mixed> $release Release row.
	 * @return string
	 */
	public static function delivery_url( array $release ): string {
		if ( self::STORAGE_LOCAL === (string) ( $release['storage'] ?? '' ) ) {
			return PackageStream::release_url( (int) ( $release['id'] ?? 0 ) );
		}

		return (string) ( $release['package_url'] ?? '' );
	}

	/**
	 * Highest published release of a product (by version, not by insertion order).
	 *
	 * @param string $product_key Product key.
	 * @param string $channel Release channel.
	 * @return array<string,mixed>|null
	 */
	public static function latest_published( string $product_key, string $channel = self::DEFAULT_CHANNEL ): ?array {
		global $wpdb;

		if ( ! preg_match( self::PRODUCT_KEY_PATTERN, $product_key ) ) {
			return null;
		}
		$tables = Schema::table_names();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$tables['releases']} WHERE product_key = %s AND channel = %s AND status = %s",
				$product_key,
				$channel,
				self::STATUS_PUBLISHED
			),
			ARRAY_A
		);

		return self::select_latest( is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Pick the row with the highest version. Pure helper.
	 *
	 * Uses version_compare(), so 1.10.0 > 1.9.0 and 2.0.0 > 2.0.0-rc1 > 2.0.0-beta2.
	 * Rows that are not published are ignored; equal versions fall back to the higher id.
	 *
	 * @param array<int,array<string,mixed>> $rows Release rows.
	 * @return array<string,mixed>|null
	 */
	public static function select_latest( array $rows ): ?array {
		$latest = null;
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || empty( $row['version'] ) ) {
				continue;
			}
			if ( isset( $row['status'] ) && self::STATUS_PUBLISHED !== $row['status'] ) {
				continue;
			}
			if ( null === $latest ) {
				$latest = $row;
				continue;
			}
			$order = version_compare( (string) $row['version'], (string) $latest['version'] );
			if ( $order > 0 || ( 0 === $order && (int) ( $row['id'] ?? 0 ) > (int) ( $latest['id'] ?? 0 ) ) ) {
				$latest = $row;
			}
		}

		return $latest;
	}

	/**
	 * Sort release rows from the highest to the lowest version. Pure helper.
	 *
	 * @param array<int,array<string,mixed>> $rows Release rows.
	 * @return array<int,array<string,mixed>>
	 */
	public static function sort_by_version_desc( array $rows ): array {
		usort(
			$rows,
			static function ( $a, $b ) {
				$order = version_compare( (string) ( $b['version'] ?? '0' ), (string) ( $a['version'] ?? '0' ) );
				return 0 !== $order ? $order : ( (int) ( $b['id'] ?? 0 ) <=> (int) ( $a['id'] ?? 0 ) );
			}
		);

		return $rows;
	}

	/**
	 * Validate and normalize release input. Pure helper (no WordPress calls).
	 *
	 * @param array<string,mixed> $input Raw release fields.
	 * @return array{errors:string[],data:array<string,string>}
	 */
	public static function validate( array $input ): array {
		$errors = array();
		$data = array(
			'product_key' => trim( (string) ( $input['product_key'] ?? '' ) ),
			'version' => trim( (string) ( $input['version'] ?? '' ) ),
			'channel' => strtolower( trim( (string) ( $input['channel'] ?? self::DEFAULT_CHANNEL ) ) ),
			'package_url' => trim( (string) ( $input['package_url'] ?? '' ) ),
			'storage' => (string) ( $input['storage'] ?? self::STORAGE_URL ),
			'sha256' => strtolower( trim( (string) ( $input['sha256'] ?? '' ) ) ),
			'requires_php' => trim( (string) ( $input['requires_php'] ?? '' ) ),
			'requires_wp' => trim( (string) ( $input['requires_wp'] ?? '' ) ),
			'tested_wp' => trim( (string) ( $input['tested_wp'] ?? '' ) ),
			'release_notes' => trim( (string) ( $input['release_notes'] ?? '' ) ),
		);

		if ( ! preg_match( self::PRODUCT_KEY_PATTERN, $data['product_key'] ) ) {
			$errors[] = 'invalid_product_key';
		}
		if ( strlen( $data['version'] ) > 40 || ! preg_match( self::VERSION_PATTERN, $data['version'] ) ) {
			$errors[] = 'invalid_version';
		}
		if ( ! preg_match( '/^[a-z][a-z0-9-]{0,19}$/', $data['channel'] ) ) {
			$errors[] = 'invalid_channel';
		}
		if ( ! in_array( $data['storage'], array( self::STORAGE_URL, self::STORAGE_LOCAL ), true ) ) {
			$errors[] = 'invalid_storage';
		} elseif ( self::STORAGE_URL === $data['storage'] ) {
			if ( ! self::is_https_url( $data['package_url'] ) ) {
				$errors[] = 'invalid_package_url';
			}
		} elseif ( ! LocalPackageStorage::is_valid_key( $data['package_url'] ) ) {
			$errors[] = 'invalid_package_key';
		}
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $data['sha256'] ) ) {
			$errors[] = 'invalid_sha256';
		}
		foreach ( array( 'requires_php', 'requires_wp', 'tested_wp' ) as $field ) {
			if ( '' !== $data[ $field ] && ! preg_match( self::PLATFORM_VERSION_PATTERN, $data[ $field ] ) ) {
				$errors[] = 'invalid_' . $field;
			}
		}

		return array(
			'errors' => $errors,
			'data' => $data,
		);
	}

	/**
	 * Validate a manifest written by tools/create-release-manifest.php. Pure helper.
	 *
	 * A manifest always describes a package at an HTTPS URL. Unknown keys such as
	 * `package_file` and `generated_at_utc` are ignored.
	 *
	 * @param mixed $manifest Decoded manifest JSON.
	 * @return array{errors:string[],data:array<string,string>}
	 */
	public static function validate_manifest( $manifest ): array {
		if ( ! is_array( $manifest ) ) {
			return array(
				'errors' => array( 'invalid_manifest' ),
				'data' => array(),
			);
		}
		$input = array( 'storage' => self::STORAGE_URL );
		$errors = array();
		foreach ( array( 'product_key', 'version', 'package_url', 'sha256', 'requires_php', 'requires_wp', 'tested_wp', 'release_notes' ) as $field ) {
			$value = $manifest[ $field ] ?? '';
			if ( ! is_string( $value ) ) {
				$errors[] = 'invalid_' . $field;
				$value = '';
			}
			$input[ $field ] = $value;
		}
		foreach ( array( 'product_key', 'version', 'package_url', 'sha256' ) as $field ) {
			if ( '' === trim( $input[ $field ] ) ) {
				$errors[] = 'missing_' . $field;
			}
		}
		$result = self::validate( $input );
		foreach ( $result['errors'] as $error ) {
			$field = substr( $error, 8 );
			if ( ! in_array( 'missing_' . $field, $errors, true ) && ! in_array( $error, $errors, true ) ) {
				$errors[] = $error;
			}
		}

		return array(
			'errors' => $errors,
			'data' => $result['data'],
		);
	}

	/**
	 * Return the frozen fields a change would modify. Pure helper.
	 *
	 * A release is frozen as soon as it has left the draft status, including
	 * after it has been withdrawn (clients may already have installed it).
	 *
	 * @param array<string,mixed> $existing Stored release row.
	 * @param array<string,mixed> $changes Requested field changes.
	 * @return string[] Names of the violated fields; empty when the change is allowed.
	 */
	public static function immutable_violations( array $existing, array $changes ): array {
		$status = (string) ( $existing['status'] ?? self::STATUS_DRAFT );
		if ( self::STATUS_DRAFT === $status && empty( $existing['published_at'] ) ) {
			return array();
		}
		$violations = array();
		foreach ( self::IMMUTABLE_FIELDS as $field ) {
			if ( ! array_key_exists( $field, $changes ) ) {
				continue;
			}
			$old = (string) ( $existing[ $field ] ?? '' );
			$new = (string) $changes[ $field ];
			if ( 'sha256' === $field ) {
				$old = strtolower( $old );
				$new = strtolower( $new );
			}
			if ( $old !== $new ) {
				$violations[] = $field;
			}
		}

		return $violations;
	}

	/**
	 * Whether a status change is allowed. Pure helper.
	 *
	 * @param string $from Current status.
	 * @param string $to Requested status.
	 * @return bool
	 */
	public static function can_transition( string $from, string $to ): bool {
		$allowed = array(
			self::STATUS_DRAFT => array( self::STATUS_PUBLISHED ),
			self::STATUS_PUBLISHED => array( self::STATUS_WITHDRAWN ),
			self::STATUS_WITHDRAWN => array( self::STATUS_PUBLISHED ),
		);

		return isset( $allowed[ $from ] ) && in_array( $to, $allowed[ $from ], true );
	}

	/**
	 * Create a draft release.
	 *
	 * @param array<string,mixed> $input Release fields.
	 * @param int                 $actor_user_id Acting user.
	 * @return int|\WP_Error Release ID.
	 */
	public static function create( array $input, int $actor_user_id = 0 ) {
		global $wpdb;

		$result = self::validate( $input );
		if ( $result['errors'] ) {
			return self::error( $result['errors'][0] );
		}
		$data = $result['data'];
		$data['release_notes'] = wp_kses_post( $data['release_notes'] );
		if ( ! self::product_exists( $data['product_key'] ) ) {
			return self::error( 'unknown_product' );
		}
		if ( null !== self::find( $data['product_key'], $data['version'], $data['channel'] ) ) {
			return self::error( 'duplicate_release' );
		}

		$tables = Schema::table_names();
		$now = gmdate( 'Y-m-d H:i:s' );
		$inserted = $wpdb->insert(
			$tables['releases'],
			array(
				'product_key' => $data['product_key'],
				'version' => $data['version'],
				'channel' => $data['channel'],
				'package_url' => $data['package_url'],
				'storage' => $data['storage'],
				'sha256' => $data['sha256'],
				'requires_php' => $data['requires_php'],
				'requires_wp' => $data['requires_wp'],
				'tested_wp' => $data['tested_wp'],
				'release_notes' => $data['release_notes'],
				'status' => self::STATUS_DRAFT,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( 1 !== (int) $inserted ) {
			// The unique key also rejects a concurrent duplicate.
			return self::error( 'database_error' );
		}
		$release_id = (int) $wpdb->insert_id;
		AuditLog::record( 'release_created', 'release', $release_id, '', null, self::summary( array_merge( $data, array( 'status' => self::STATUS_DRAFT ) ) ), $actor_user_id );

		return $release_id;
	}

	/**
	 * Update a release. Frozen fields of a published release are rejected.
	 *
	 * @param int                 $release_id Release ID.
	 * @param array<string,mixed> $changes Fields to change.
	 * @param int                 $actor_user_id Acting user.
	 * @return true|\WP_Error
	 */
	public static function update( int $release_id, array $changes, int $actor_user_id = 0 ) {
		global $wpdb;

		$existing = self::get( $release_id );
		if ( null === $existing ) {
			return self::error( 'not_found' );
		}
		$editable = array( 'product_key', 'version', 'channel', 'package_url', 'storage', 'sha256', 'requires_php', 'requires_wp', 'tested_wp', 'release_notes' );
		$changes = array_intersect_key( $changes, array_flip( $editable ) );
		if ( self::immutable_violations( $existing, $changes ) ) {
			return self::error( 'release_immutable' );
		}
		$result = self::validate( array_merge( $existing, $changes ) );
		if ( $result['errors'] ) {
			return self::error( $result['errors'][0] );
		}
		$data = $result['data'];
		$data['release_notes'] = wp_kses_post( $data['release_notes'] );
		$duplicate = self::find( $data['product_key'], $data['version'], $data['channel'] );
		if ( null !== $duplicate && (int) $duplicate['id'] !== $release_id ) {
			return self::error( 'duplicate_release' );
		}
		if ( $data['product_key'] !== $existing['product_key'] && ! self::product_exists( $data['product_key'] ) ) {
			return self::error( 'unknown_product' );
		}

		$tables = Schema::table_names();
		$data['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		$updated = $wpdb->update( $tables['releases'], $data, array( 'id' => $release_id ), null, array( '%d' ) );
		if ( false === $updated ) {
			return self::error( 'database_error' );
		}
		AuditLog::record( 'release_updated', 'release', $release_id, '', self::summary( $existing ), self::summary( array_merge( $existing, $data ) ), $actor_user_id );

		return true;
	}

	/**
	 * Publish a draft or withdrawn release.
	 *
	 * @param int $release_id Release ID.
	 * @param int $actor_user_id Acting user.
	 * @return true|\WP_Error
	 */
	public static function publish( int $release_id, int $actor_user_id = 0 ) {
		$release = self::get( $release_id );
		if ( null === $release ) {
			return self::error( 'not_found' );
		}
		if ( ! self::can_transition( (string) $release['status'], self::STATUS_PUBLISHED ) ) {
			return self::error( 'invalid_transition' );
		}
		$result = self::validate( $release );
		if ( $result['errors'] ) {
			return self::error( $result['errors'][0] );
		}
		if ( self::STORAGE_LOCAL === $release['storage'] ) {
			$path = LocalPackageStorage::path_for_key( (string) $release['package_url'] );
			if ( null === $path ) {
				return self::error( 'package_missing' );
			}
			$checksum = hash_file( 'sha256', $path );
			if ( ! is_string( $checksum ) || ! hash_equals( strtolower( (string) $release['sha256'] ), $checksum ) ) {
				return self::error( 'checksum_mismatch' );
			}
		} elseif ( ! PackageStream::is_trusted_url( (string) $release['package_url'] ) ) {
			return self::error( 'untrusted_package_host' );
		}

		return self::set_status( $release, self::STATUS_PUBLISHED, 'release_published', '', $actor_user_id );
	}

	/**
	 * Withdraw a published release. The update feed falls back to the next highest published version.
	 *
	 * @param int    $release_id Release ID.
	 * @param string $reason Reason shown in the audit log.
	 * @param int    $actor_user_id Acting user.
	 * @return true|\WP_Error
	 */
	public static function withdraw( int $release_id, string $reason = '', int $actor_user_id = 0 ) {
		$release = self::get( $release_id );
		if ( null === $release ) {
			return self::error( 'not_found' );
		}
		if ( ! self::can_transition( (string) $release['status'], self::STATUS_WITHDRAWN ) ) {
			return self::error( 'invalid_transition' );
		}

		return self::set_status( $release, self::STATUS_WITHDRAWN, 'release_withdrawn', $reason, $actor_user_id );
	}

	/**
	 * Delete a release that was never published, including its local package.
	 *
	 * @param int $release_id Release ID.
	 * @param int $actor_user_id Acting user.
	 * @return true|\WP_Error
	 */
	public static function delete_draft( int $release_id, int $actor_user_id = 0 ) {
		global $wpdb;

		$release = self::get( $release_id );
		if ( null === $release ) {
			return self::error( 'not_found' );
		}
		if ( self::STATUS_DRAFT !== $release['status'] || ! empty( $release['published_at'] ) ) {
			return self::error( 'release_immutable' );
		}
		$tables = Schema::table_names();
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$tables['releases']} WHERE id = %d AND status = %s AND published_at IS NULL",
				$release_id,
				self::STATUS_DRAFT
			)
		);
		if ( 1 !== (int) $deleted ) {
			return self::error( 'database_error' );
		}
		if ( self::STORAGE_LOCAL === $release['storage'] ) {
			LocalPackageStorage::delete( (string) $release['package_url'] );
		}
		AuditLog::record( 'release_deleted', 'release', $release_id, '', self::summary( $release ), null, $actor_user_id );

		return true;
	}

	/**
	 * Load one release.
	 *
	 * @param int $release_id Release ID.
	 * @return array<string,mixed>|null
	 */
	public static function get( int $release_id ): ?array {
		global $wpdb;

		if ( $release_id <= 0 ) {
			return null;
		}
		$tables = Schema::table_names();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['releases']} WHERE id = %d LIMIT 1", $release_id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * All releases grouped by product key, highest version first.
	 *
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	public static function all_by_product(): array {
		global $wpdb;

		$tables = Schema::table_names();
		$rows = $wpdb->get_results(
			"SELECT id,product_key,version,channel,package_url,storage,sha256,requires_php,requires_wp,tested_wp,status,published_at,created_at,updated_at FROM {$tables['releases']} ORDER BY product_key ASC, id DESC LIMIT 2000",
			ARRAY_A
		);
		$grouped = array();
		foreach ( (array) $rows as $row ) {
			$grouped[ (string) $row['product_key'] ][] = $row;
		}
		foreach ( $grouped as $product_key => $product_rows ) {
			$grouped[ $product_key ] = self::sort_by_version_desc( $product_rows );
		}

		return $grouped;
	}

	/**
	 * Product keys and names that releases can be created for.
	 *
	 * @return array<string,string>
	 */
	public static function products(): array {
		global $wpdb;

		$tables = Schema::table_names();
		$rows = $wpdb->get_results( "SELECT product_key, display_name FROM {$tables['products']} ORDER BY display_name ASC LIMIT 500", ARRAY_A );
		$products = array();
		foreach ( (array) $rows as $row ) {
			$products[ (string) $row['product_key'] ] = (string) $row['display_name'];
		}

		return $products;
	}

	/**
	 * Translate an error code.
	 *
	 * @param string $code Error code.
	 * @return string
	 */
	public static function error_message( string $code ): string {
		$messages = array(
			'invalid_product_key' => __( 'The product key is invalid.', 'wp-license' ),
			'invalid_version' => __( 'The version must use three numeric components, for example 1.2.3 or 1.2.3-beta1.', 'wp-license' ),
			'invalid_channel' => __( 'The release channel is invalid.', 'wp-license' ),
			'invalid_storage' => __( 'The package storage type is invalid.', 'wp-license' ),
			'invalid_package_url' => __( 'The package URL must be a valid HTTPS URL without credentials.', 'wp-license' ),
			'invalid_package_key' => __( 'The stored package file reference is invalid.', 'wp-license' ),
			'invalid_sha256' => __( 'The SHA-256 checksum must consist of exactly 64 hexadecimal characters.', 'wp-license' ),
			'invalid_requires_php' => __( 'The required PHP version is invalid.', 'wp-license' ),
			'invalid_requires_wp' => __( 'The required WordPress version is invalid.', 'wp-license' ),
			'invalid_tested_wp' => __( 'The tested WordPress version is invalid.', 'wp-license' ),
			'invalid_release_notes' => __( 'The release notes are invalid.', 'wp-license' ),
			'invalid_manifest' => __( 'The manifest is not a valid release manifest JSON object.', 'wp-license' ),
			'missing_product_key' => __( 'The manifest does not contain a product key.', 'wp-license' ),
			'missing_version' => __( 'The manifest does not contain a version.', 'wp-license' ),
			'missing_package_url' => __( 'The manifest does not contain a package URL.', 'wp-license' ),
			'missing_sha256' => __( 'The manifest does not contain a SHA-256 checksum.', 'wp-license' ),
			'unknown_product' => __( 'This product key is not registered. Map a WooCommerce product to it first.', 'wp-license' ),
			'duplicate_release' => __( 'A release with this version already exists for the product.', 'wp-license' ),
			'not_found' => __( 'The release does not exist.', 'wp-license' ),
			'release_immutable' => __( 'Version, package and checksum of a published release cannot be changed. Withdraw it and publish a new version instead.', 'wp-license' ),
			'invalid_transition' => __( 'The release cannot change to this status from its current status.', 'wp-license' ),
			'package_missing' => __( 'The stored package file is missing.', 'wp-license' ),
			'checksum_mismatch' => __( 'The stored package file does not match its SHA-256 checksum.', 'wp-license' ),
			'untrusted_package_host' => __( 'The package URL host is not allowed. Add it with the wplc_update_package_hosts filter and use HTTPS on the standard port.', 'wp-license' ),
			'database_error' => __( 'The release could not be saved.', 'wp-license' ),
		);

		return isset( $messages[ $code ] ) ? $messages[ $code ] : __( 'The release data is invalid.', 'wp-license' );
	}

	/**
	 * Find a release by its unique key.
	 *
	 * @param string $product_key Product key.
	 * @param string $version Version.
	 * @param string $channel Channel.
	 * @return array<string,mixed>|null
	 */
	private static function find( string $product_key, string $version, string $channel ): ?array {
		global $wpdb;

		$tables = Schema::table_names();
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$tables['releases']} WHERE product_key = %s AND version = %s AND channel = %s LIMIT 1",
				$product_key,
				$version,
				$channel
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Whether the product key is registered.
	 *
	 * @param string $product_key Product key.
	 * @return bool
	 */
	private static function product_exists( string $product_key ): bool {
		global $wpdb;

		$tables = Schema::table_names();

		return 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['products']} WHERE product_key = %s", $product_key ) );
	}

	/**
	 * Conditionally change the status so concurrent requests cannot both succeed.
	 *
	 * @param array<string,mixed> $release Current release row.
	 * @param string              $status New status.
	 * @param string              $action Audit action.
	 * @param string              $reason Audit reason.
	 * @param int                 $actor_user_id Acting user.
	 * @return true|\WP_Error
	 */
	private static function set_status( array $release, string $status, string $action, string $reason, int $actor_user_id ) {
		global $wpdb;

		$tables = Schema::table_names();
		$now = gmdate( 'Y-m-d H:i:s' );
		if ( self::STATUS_PUBLISHED === $status ) {
			$changed = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$tables['releases']} SET status = %s, published_at = COALESCE(published_at, %s), updated_at = %s WHERE id = %d AND status = %s",
					$status,
					$now,
					$now,
					(int) $release['id'],
					(string) $release['status']
				)
			);
		} else {
			$changed = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$tables['releases']} SET status = %s, updated_at = %s WHERE id = %d AND status = %s",
					$status,
					$now,
					(int) $release['id'],
					(string) $release['status']
				)
			);
		}
		if ( 1 !== (int) $changed ) {
			return self::error( 'database_error' );
		}
		AuditLog::record( $action, 'release', (int) $release['id'], $reason, self::summary( $release ), self::summary( array_merge( $release, array( 'status' => $status ) ) ), $actor_user_id );

		return true;
	}

	/**
	 * Audit summary without release notes and without the storage key.
	 *
	 * @param array<string,mixed> $release Release fields.
	 * @return array<string,string>
	 */
	private static function summary( array $release ): array {
		$local = self::STORAGE_LOCAL === (string) ( $release['storage'] ?? '' );

		return array(
			'product_key' => (string) ( $release['product_key'] ?? '' ),
			'version' => (string) ( $release['version'] ?? '' ),
			'channel' => (string) ( $release['channel'] ?? '' ),
			'storage' => (string) ( $release['storage'] ?? '' ),
			'package' => $local ? '[local file]' : (string) ( $release['package_url'] ?? '' ),
			'sha256' => (string) ( $release['sha256'] ?? '' ),
			'status' => (string) ( $release['status'] ?? '' ),
		);
	}

	/**
	 * Basic HTTPS URL check without WordPress functions.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private static function is_https_url( string $url ): bool {
		if ( '' === $url || strlen( $url ) > 2000 || preg_match( '/[\s<>"\']/', $url ) ) {
			return false;
		}
		$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure helper, PHP >= 7.4.
		if ( ! is_array( $parts ) ) {
			return false;
		}

		return 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) )
			&& '' !== (string) ( $parts['host'] ?? '' )
			&& ! isset( $parts['user'] )
			&& ! isset( $parts['pass'] )
			&& ! isset( $parts['fragment'] );
	}

	/**
	 * Build a WP_Error for an error code.
	 *
	 * @param string $code Error code.
	 * @return \WP_Error
	 */
	private static function error( string $code ): \WP_Error {
		return new \WP_Error( $code, self::error_message( $code ) );
	}
}
