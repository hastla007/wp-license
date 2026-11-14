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
	const CHANNEL_BETA    = 'beta';

	/**
	 * Three numeric components and at most one of the suffixes version_compare()
	 * orders below the plain version: 2.0.0-alpha1 < 2.0.0-beta1 < 2.0.0-rc1 < 2.0.0.
	 * Anything else (-pre1, -hotfix1, +build) is sorted wrongly by PHP and WordPress (B-54).
	 * tools/create-release-manifest.php must accept the same versions.
	 */
	const VERSION_PATTERN = '/^[0-9]+\.[0-9]+\.[0-9]+(?:-(?:alpha|beta|rc)[0-9]+)?$/D';
	/** Everything sanitize_key() can produce for a catalog product key (B-174). */
	const PRODUCT_KEY_PATTERN = '/^[a-z0-9_-]{1,100}$/D';
	const PLATFORM_VERSION_PATTERN = '/^[0-9]+(?:\.[0-9]+){0,2}$/D';
	/** Column width of requires_php, requires_wp, tested_wp and requires_core (A-112). */
	const PLATFORM_VERSION_MAX_LENGTH = 20;
	const PLUGIN_FILE_PATTERN = '/^[A-Za-z0-9_][A-Za-z0-9._-]{0,99}\/[A-Za-z0-9._-]{1,80}\.php$/D';
	const REASON_MAX_LENGTH = 255;

	/** Option: top-level ZIP folder per product key. (The products table row is replaced on every catalog save.) */
	const SLUG_OPTION = 'wplc_release_product_slugs';
	const CACHE_GROUP = 'wplc_releases';
	const CACHE_TTL   = 300;

	/** Fields that are frozen after the first publication. */
	const IMMUTABLE_FIELDS = array( 'product_key', 'version', 'channel', 'package_url', 'storage', 'sha256' );

	/**
	 * Register the update feed integration.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		// Priority 5: custom code on the default priority can still override the registry.
		add_filter( 'wplc_update_packages', array( self::class, 'filter_update_packages' ), 5, 3 );
	}

	/**
	 * Provide the release for one installation in the shape UpdateService expects.
	 *
	 * @param mixed $packages Packages keyed by product key.
	 * @param mixed $product_key Requested product key.
	 * @param mixed $context Optional request context: channel, current_version, target_version, wp_version, php_version, core_version.
	 * @return mixed
	 */
	public static function filter_update_packages( $packages, $product_key = '', $context = array() ) {
		if ( ! is_array( $packages ) || ! is_string( $product_key ) || '' === $product_key ) {
			return $packages;
		}
		$context = is_array( $context ) ? $context : array();
		$selection = self::select_for_client( $product_key, $context );
		$release = $selection['release'];
		if ( null === $release ) {
			if ( null !== $selection['blocked'] ) {
				// No version and no package_url: UpdateService reports "no update" and passes the reason on.
				$packages[ $product_key ] = array( 'blocked' => $selection['blocked'] );
			}
			return $packages;
		}
		$package = self::to_update_package( $release, self::delivery_url( $release ) );
		$package['changelog'] = self::changelog(
			$product_key,
			self::normalize_channel( (string) ( $context['channel'] ?? self::DEFAULT_CHANNEL ) ),
			(string) ( $context['current_version'] ?? '' ),
			(string) $release['version']
		);
		if ( null !== $selection['blocked'] ) {
			$package['blocked'] = $selection['blocked'];
		}
		$packages[ $product_key ] = $package;

		return $packages;
	}

	/**
	 * Map a release row to the `wplc_update_packages` entry.
	 *
	 * @param array<string,mixed> $release Release row.
	 * @param string              $package_url HTTPS URL handed to the update API.
	 * @return array<string,mixed>
	 */
	public static function to_update_package( array $release, string $package_url ): array {
		$plugin_file = (string) ( $release['plugin_file'] ?? '' );
		$slug = function_exists( 'get_option' ) ? self::product_slug( (string) ( $release['product_key'] ?? '' ) ) : '';
		if ( '' === $slug && '' !== $plugin_file ) {
			$slug = (string) strtok( $plugin_file, '/' );
		}

		return array(
			'release_id' => (int) ( $release['id'] ?? 0 ),
			'version' => (string) ( $release['version'] ?? '' ),
			'channel' => (string) ( $release['channel'] ?? self::DEFAULT_CHANNEL ),
			'package_url' => $package_url,
			'sha256' => strtolower( (string) ( $release['sha256'] ?? '' ) ),
			'requires_php' => (string) ( $release['requires_php'] ?? '' ),
			'requires_wp' => (string) ( $release['requires_wp'] ?? '' ),
			'tested_wp' => (string) ( $release['tested_wp'] ?? '' ),
			'requires_core' => (string) ( $release['requires_core'] ?? '' ),
			'slug' => $slug,
			'plugin' => $plugin_file,
			'last_updated' => (string) ( $release['published_at'] ?? '' ),
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
	 * Channels a client on the given channel may receive. Pure helper.
	 *
	 * A beta installation also gets stable releases, so it is never stuck on a pre-release.
	 *
	 * @param string $channel Requested channel.
	 * @return string[]
	 */
	public static function channels_for( string $channel ): array {
		return self::DEFAULT_CHANNEL === $channel ? array( self::DEFAULT_CHANNEL ) : array( self::DEFAULT_CHANNEL, $channel );
	}

	/**
	 * Reduce a requested channel to a known one. Pure helper.
	 *
	 * @param string $channel Requested channel.
	 * @return string
	 */
	public static function normalize_channel( string $channel ): string {
		$channel = strtolower( trim( $channel ) );

		return self::CHANNEL_BETA === $channel ? self::CHANNEL_BETA : self::DEFAULT_CHANNEL;
	}

	/**
	 * Small rows of all published releases of a product: no notes, no package (B-26).
	 *
	 * Cached per product and channel; create(), update(), publish(), withdraw()
	 * and delete_draft() drop the entry. Without a persistent object cache this
	 * is one narrow indexed query per request.
	 *
	 * @param string $product_key Product key.
	 * @param string $channel Release channel.
	 * @return array<int,array<string,mixed>>
	 */
	public static function published_index( string $product_key, string $channel = self::DEFAULT_CHANNEL ): array {
		global $wpdb;

		if ( ! preg_match( self::PRODUCT_KEY_PATTERN, $product_key ) ) {
			return array();
		}
		$cache_key = self::cache_key( $product_key, $channel );
		$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$tables = Schema::table_names();
		$channels = self::channels_for( $channel );
		$placeholders = implode( ',', array_fill( 0, count( $channels ), '%s' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- table name and a generated list of %s placeholders.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, version, channel, status, requires_php, requires_wp, requires_core, published_at FROM {$tables['releases']} WHERE product_key = %s AND status = %s AND channel IN ({$placeholders})",
				array_merge( array( $product_key, self::STATUS_PUBLISHED ), $channels )
			),
			ARRAY_A
		);
		// phpcs:enable
		$rows = is_array( $rows ) ? $rows : array();
		wp_cache_set( $cache_key, $rows, self::CACHE_GROUP, self::CACHE_TTL );

		return $rows;
	}

	/**
	 * Drop the cached index of a product (all channels).
	 *
	 * @param string $product_key Product key.
	 * @param string $channel Additional channel to drop.
	 * @return void
	 */
	public static function flush_cache( string $product_key, string $channel = self::DEFAULT_CHANNEL ): void {
		foreach ( array_unique( array( self::DEFAULT_CHANNEL, self::CHANNEL_BETA, $channel ) ) as $name ) {
			wp_cache_delete( self::cache_key( $product_key, $name ), self::CACHE_GROUP );
		}
	}

	/**
	 * Requirements of a release the client does not meet. Pure helper.
	 *
	 * Only versions the client actually reported are compared; an installation
	 * that reports nothing is treated as compatible (A-116, B-142).
	 *
	 * @param array<string,mixed> $release Release or index row.
	 * @param array<string,mixed> $context wp_version, php_version, core_version.
	 * @return string[] Names of the unmet fields: requires_php, requires_wp, requires_core.
	 */
	public static function unmet_requirements( array $release, array $context ): array {
		$unmet = array();
		$pairs = array(
			'requires_php' => 'php_version',
			'requires_wp' => 'wp_version',
			'requires_core' => 'core_version',
		);
		foreach ( $pairs as $field => $context_key ) {
			$required = trim( (string) ( $release[ $field ] ?? '' ) );
			$actual = $context[ $context_key ] ?? '';
			$actual = is_scalar( $actual ) ? trim( (string) $actual ) : '';
			if ( '' === $required || ! preg_match( '/^[0-9]+(?:\.[0-9]+)*/', $actual, $match ) ) {
				continue;
			}
			// "8.2.18-1+deb12u1" or "6.6-beta2": compare the leading numeric part only.
			if ( version_compare( $match[0], $required, '<' ) ) {
				$unmet[] = $field;
			}
		}

		return $unmet;
	}

	/**
	 * Pick the release for one installation.
	 *
	 * The highest published version that the installation can run and whose
	 * package is present. A higher version that is skipped because of its
	 * requirements is reported as `blocked`, so the client can explain why no
	 * (or only an older) update is offered.
	 *
	 * @param string              $product_key Product key.
	 * @param array<string,mixed> $context channel, target_version, wp_version, php_version, core_version.
	 * @return array{release:array<string,mixed>|null,blocked:array<string,mixed>|null}
	 */
	public static function select_for_client( string $product_key, array $context = array() ): array {
		$channel = self::normalize_channel( (string) ( $context['channel'] ?? self::DEFAULT_CHANNEL ) );
		$target = $context['target_version'] ?? '';
		$target = is_scalar( $target ) ? trim( (string) $target ) : '';
		$blocked = null;
		foreach ( self::sort_by_version_desc( self::published_index( $product_key, $channel ) ) as $row ) {
			if ( '' !== $target && (string) $row['version'] !== $target ) {
				continue;
			}
			$unmet = self::unmet_requirements( $row, $context );
			if ( $unmet ) {
				if ( null === $blocked ) {
					$blocked = array(
						'version' => (string) $row['version'],
						'unmet' => $unmet,
						'requires_php' => (string) $row['requires_php'],
						'requires_wp' => (string) $row['requires_wp'],
						'requires_core' => (string) $row['requires_core'],
					);
				}
				continue;
			}
			$release = self::get( (int) $row['id'] );
			// The index may be a few minutes old.
			if ( null === $release || self::STATUS_PUBLISHED !== $release['status'] ) {
				continue;
			}
			if ( self::STORAGE_LOCAL === $release['storage'] && null === LocalPackageStorage::path_for_key( (string) $release['package_url'] ) ) {
				/**
				 * Fires when a published release cannot be offered because its file is missing on this server (A-111).
				 *
				 * @param array<string,mixed> $release Release row.
				 */
				do_action( 'wplc_release_package_missing', $release );
				continue;
			}

			return array(
				'release' => $release,
				'blocked' => $blocked,
			);
		}

		return array(
			'release' => null,
			'blocked' => $blocked,
		);
	}

	/**
	 * Published versions of a product, highest first, without notes.
	 *
	 * @param string $product_key Product key.
	 * @param string $channel Release channel.
	 * @param int    $limit Maximum number of versions.
	 * @param string $published_until Only releases published at or before this UTC datetime; empty for all.
	 * @return array<int,array<string,mixed>>
	 */
	public static function published_versions( string $product_key, string $channel = self::DEFAULT_CHANNEL, int $limit = 10, string $published_until = '' ): array {
		$rows = array();
		foreach ( self::sort_by_version_desc( self::published_index( $product_key, $channel ) ) as $row ) {
			if ( '' !== $published_until && ( empty( $row['published_at'] ) || (string) $row['published_at'] > $published_until ) ) {
				continue;
			}
			$rows[] = $row;
			if ( count( $rows ) >= $limit ) {
				break;
			}
		}

		return $rows;
	}

	/**
	 * Find one published release by version.
	 *
	 * @param string $product_key Product key.
	 * @param string $version Version.
	 * @param string $channel Release channel.
	 * @return array<string,mixed>|null
	 */
	public static function published_version( string $product_key, string $version, string $channel = self::DEFAULT_CHANNEL ): ?array {
		foreach ( self::published_index( $product_key, $channel ) as $row ) {
			if ( (string) $row['version'] === $version ) {
				$release = self::get( (int) $row['id'] );
				return null !== $release && self::STATUS_PUBLISHED === $release['status'] ? $release : null;
			}
		}

		return null;
	}

	/**
	 * Release notes of the versions an installation skips (B-141).
	 *
	 * Versions above $newer_than up to and including $up_to, highest first. An
	 * installation that is already up to date gets the notes of the most recent
	 * versions instead, so the details view is never empty.
	 *
	 * @param string $product_key Product key.
	 * @param string $channel Release channel.
	 * @param string $newer_than Installed version, or an empty string.
	 * @param string $up_to Offered version.
	 * @param int    $limit Maximum number of entries.
	 * @return array<int,array{version:string,date:string,notes:string}>
	 */
	public static function changelog( string $product_key, string $channel, string $newer_than, string $up_to, int $limit = 20 ): array {
		global $wpdb;

		$ids = self::changelog_ids( self::published_index( $product_key, $channel ), $newer_than, $up_to, $limit );
		if ( ! $ids ) {
			return array();
		}
		$tables = Schema::table_names();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- table name and a generated list of %d placeholders.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, version, published_at, release_notes FROM {$tables['releases']} WHERE id IN ({$placeholders})",
				$ids
			),
			ARRAY_A
		);
		// phpcs:enable
		$entries = array();
		foreach ( self::sort_by_version_desc( is_array( $rows ) ? $rows : array() ) as $row ) {
			$entries[] = array(
				'version' => (string) $row['version'],
				'date' => (string) $row['published_at'],
				'notes' => (string) $row['release_notes'],
			);
		}

		return $entries;
	}

	/**
	 * Choose the releases whose notes belong into a changelog. Pure helper.
	 *
	 * @param array<int,array<string,mixed>> $index Published index rows.
	 * @param string                         $newer_than Installed version, or an empty string.
	 * @param string                         $up_to Offered version, or an empty string.
	 * @param int                            $limit Maximum number of entries.
	 * @return int[] Release IDs.
	 */
	public static function changelog_ids( array $index, string $newer_than, string $up_to, int $limit = 20 ): array {
		$ids = array();
		$fallback = array();
		$newer_than = preg_match( self::VERSION_PATTERN, $newer_than ) ? $newer_than : '';
		foreach ( self::sort_by_version_desc( $index ) as $row ) {
			$version = (string) $row['version'];
			if ( '' !== $up_to && version_compare( $version, $up_to, '>' ) ) {
				continue;
			}
			if ( count( $fallback ) < 5 ) {
				$fallback[] = (int) $row['id'];
			}
			if ( '' !== $newer_than && ! version_compare( $version, $newer_than, '>' ) ) {
				continue;
			}
			if ( count( $ids ) < $limit ) {
				$ids[] = (int) $row['id'];
			}
		}
		if ( '' === $newer_than ) {
			// Unknown installed version: do not send the whole history.
			return $fallback;
		}

		return $ids ? $ids : $fallback;
	}

	/**
	 * Highest published release of a product (by version, not by insertion order).
	 *
	 * @param string $product_key Product key.
	 * @param string $channel Release channel.
	 * @return array<string,mixed>|null
	 */
	public static function latest_published( string $product_key, string $channel = self::DEFAULT_CHANNEL ): ?array {
		$latest = self::select_latest( self::published_index( $product_key, $channel ) );
		if ( null === $latest ) {
			return null;
		}
		$release = self::get( (int) $latest['id'] );

		return null !== $release && self::STATUS_PUBLISHED === $release['status'] ? $release : null;
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
			'requires_core' => trim( (string) ( $input['requires_core'] ?? '' ) ),
			'plugin_file' => trim( (string) ( $input['plugin_file'] ?? '' ) ),
			'release_notes' => trim( (string) ( $input['release_notes'] ?? '' ) ),
		);

		if ( ! preg_match( self::PRODUCT_KEY_PATTERN, $data['product_key'] ) ) {
			$errors[] = 'invalid_product_key';
		}
		if ( strlen( $data['version'] ) > 40 || ! preg_match( self::VERSION_PATTERN, $data['version'] ) ) {
			$errors[] = 'invalid_version';
		}
		if ( ! preg_match( '/^[a-z][a-z0-9-]{0,19}$/D', $data['channel'] ) ) {
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
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $data['sha256'] ) ) {
			$errors[] = 'invalid_sha256';
		}
		foreach ( array( 'requires_php', 'requires_wp', 'tested_wp', 'requires_core' ) as $field ) {
			if ( '' !== $data[ $field ] && ( strlen( $data[ $field ] ) > self::PLATFORM_VERSION_MAX_LENGTH || ! preg_match( self::PLATFORM_VERSION_PATTERN, $data[ $field ] ) ) ) {
				$errors[] = 'invalid_' . $field;
			}
		}
		if ( '' !== $data['plugin_file'] && ! preg_match( self::PLUGIN_FILE_PATTERN, $data['plugin_file'] ) ) {
			$errors[] = 'invalid_plugin_file';
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
		foreach ( array( 'product_key', 'version', 'package_url', 'sha256', 'requires_php', 'requires_wp', 'tested_wp', 'requires_core', 'release_notes' ) as $field ) {
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
	 * Whether publishing a version would not move the feed forward. Pure helper.
	 *
	 * Installations only update upwards: a version that is not above the highest
	 * published one reaches nobody who is already on that version.
	 *
	 * @param string                         $version Version about to be published.
	 * @param array<int,array<string,mixed>> $published Published rows of the same product.
	 * @param int                            $own_id ID of the release itself (ignored in $published).
	 * @return string Highest published version when it is not below $version, otherwise an empty string.
	 */
	public static function not_newer_than( string $version, array $published, int $own_id = 0 ): string {
		$others = array();
		foreach ( $published as $row ) {
			if ( (int) ( $row['id'] ?? 0 ) !== $own_id ) {
				$others[] = $row;
			}
		}
		$highest = self::select_latest( $others );
		if ( null === $highest || version_compare( $version, (string) $highest['version'], '>' ) ) {
			return '';
		}

		return (string) $highest['version'];
	}

	/**
	 * Warning for a release that is about to be (or was just) published.
	 *
	 * @param int $release_id Release ID.
	 * @return string Translated warning, or an empty string.
	 */
	public static function publish_warning( int $release_id ): string {
		$release = self::get( $release_id );
		if ( null === $release ) {
			return '';
		}
		// A beta release is compared with stable and beta, a stable release with stable only.
		$highest = self::not_newer_than( (string) $release['version'], self::published_index( (string) $release['product_key'], (string) $release['channel'] ), $release_id );
		if ( '' === $highest ) {
			return '';
		}

		return sprintf(
			/* translators: 1: version of this release, 2: highest published version. */
			__( 'Version %1$s is not higher than the highest published version %2$s. Installations on %2$s will not be offered this release; WordPress only updates upwards.', 'wp-license' ),
			(string) $release['version'],
			$highest
		);
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
				'requires_core' => $data['requires_core'],
				'plugin_file' => $data['plugin_file'],
				'release_notes' => $data['release_notes'],
				'status' => self::STATUS_DRAFT,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
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
	 * Create a draft release from a ZIP: an upload or a file on this server (B-169).
	 *
	 * Everything else is validated first, the archive is inspected (one folder,
	 * matching `Version:` header, B-53) and nothing stays behind when the release
	 * cannot be created. Empty PHP/WordPress requirements are taken from the
	 * plugin header.
	 *
	 * @param array<string,mixed>        $input Release fields; storage, package_url and sha256 are set here.
	 * @param array<string,mixed>|string $source One entry of $_FILES, or the path of a readable ZIP.
	 * @param int                        $actor_user_id Acting user.
	 * @return int|\WP_Error Release ID.
	 */
	public static function create_with_package( array $input, $source, int $actor_user_id = 0 ) {
		$probe = self::validate(
			array_merge(
				$input,
				array(
					'storage' => self::STORAGE_URL,
					'package_url' => 'https://placeholder.invalid/package.zip',
					'sha256' => str_repeat( '0', 64 ),
				)
			)
		);
		if ( $probe['errors'] ) {
			return self::error( $probe['errors'][0] );
		}
		$data = $probe['data'];
		if ( ! self::product_exists( $data['product_key'] ) ) {
			return self::error( 'unknown_product' );
		}
		if ( null !== self::find( $data['product_key'], $data['version'], $data['channel'] ) ) {
			return self::error( 'duplicate_release' );
		}
		$slug = self::product_slug( $data['product_key'] );
		$stored = is_array( $source )
			? LocalPackageStorage::store_upload( $source, $data['product_key'], $data['version'], $slug )
			: LocalPackageStorage::store_file( (string) $source, $data['product_key'], $data['version'], $slug );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}
		$package = $stored['package'];
		$input['storage'] = self::STORAGE_LOCAL;
		$input['package_url'] = $stored['key'];
		$input['sha256'] = $stored['sha256'];
		$input['plugin_file'] = $package['plugin_file'];
		foreach ( array( 'requires_php', 'requires_wp' ) as $field ) {
			$header = (string) $package[ $field ];
			if ( '' === trim( (string) ( $input[ $field ] ?? '' ) ) && strlen( $header ) <= self::PLATFORM_VERSION_MAX_LENGTH && preg_match( self::PLATFORM_VERSION_PATTERN, $header ) ) {
				$input[ $field ] = $header;
			}
		}

		$result = self::create( $input, $actor_user_id );
		if ( is_wp_error( $result ) ) {
			LocalPackageStorage::delete( $stored['key'] );
		}

		return $result;
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
		$editable = array( 'product_key', 'version', 'channel', 'package_url', 'storage', 'sha256', 'requires_php', 'requires_wp', 'tested_wp', 'requires_core', 'release_notes' );
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
		self::flush_cache( (string) $existing['product_key'], (string) $existing['channel'] );
		self::flush_cache( $data['product_key'], $data['channel'] );
		AuditLog::record( 'release_updated', 'release', $release_id, '', self::summary( $existing ), self::summary( array_merge( $existing, $data ) ), $actor_user_id );

		return true;
	}

	/**
	 * Publish a draft or withdrawn release.
	 *
	 * The package is checked every time (B-53, B-143): an uploaded file must
	 * exist and match its checksum; a package URL must be on an allowed host and
	 * is downloaded once to compare its SHA-256. In both cases the ZIP must
	 * contain exactly the product's folder and a `Version:` header equal to the
	 * release version. The first published release defines the product's folder.
	 *
	 * @param int    $release_id Release ID.
	 * @param int    $actor_user_id Acting user.
	 * @param string $reason Reason shown in the audit log.
	 * @return true|\WP_Error
	 */
	public static function publish( int $release_id, int $actor_user_id = 0, string $reason = '' ) {
		global $wpdb;

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
		$slug = self::product_slug( (string) $release['product_key'] );
		if ( self::STORAGE_LOCAL === $release['storage'] ) {
			$path = LocalPackageStorage::path_for_key( (string) $release['package_url'] );
			if ( null === $path ) {
				return self::error( 'package_missing' );
			}
			$checksum = hash_file( 'sha256', $path );
			if ( ! is_string( $checksum ) || ! hash_equals( strtolower( (string) $release['sha256'] ), $checksum ) ) {
				return self::error( 'checksum_mismatch' );
			}
			$package = LocalPackageStorage::validate_package( $path, (string) $release['version'], $slug );
		} elseif ( ! PackageStream::is_trusted_url( (string) $release['package_url'] ) ) {
			return self::error( 'untrusted_package_host' );
		} else {
			$package = self::verify_remote_package( $release, $slug );
		}
		if ( is_wp_error( $package ) ) {
			return $package;
		}
		if ( is_array( $package ) && (string) $release['plugin_file'] !== $package['plugin_file'] ) {
			$tables = Schema::table_names();
			$wpdb->update( $tables['releases'], array( 'plugin_file' => $package['plugin_file'] ), array( 'id' => $release_id ), array( '%s' ), array( '%d' ) );
		}

		$published = self::set_status( $release, self::STATUS_PUBLISHED, 'release_published', self::normalize_reason( $reason ), $actor_user_id );
		if ( true === $published && is_array( $package ) && '' === $slug ) {
			self::set_product_slug( (string) $release['product_key'], $package['slug'] );
		}

		return $published;
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

		return self::set_status( $release, self::STATUS_WITHDRAWN, 'release_withdrawn', self::normalize_reason( $reason ), $actor_user_id );
	}

	/**
	 * Delete a release that was never published, including its local package.
	 *
	 * @param int    $release_id Release ID.
	 * @param int    $actor_user_id Acting user.
	 * @param string $reason Reason shown in the audit log.
	 * @return true|\WP_Error
	 */
	public static function delete_draft( int $release_id, int $actor_user_id = 0, string $reason = '' ) {
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
		self::flush_cache( (string) $release['product_key'], (string) $release['channel'] );
		AuditLog::record( 'release_deleted', 'release', $release_id, self::normalize_reason( $reason ), self::summary( $release ), null, $actor_user_id );

		return true;
	}

	/**
	 * Trim a reason to one line of at most 255 characters. Pure helper.
	 *
	 * @param string $reason Raw reason.
	 * @return string
	 */
	public static function normalize_reason( string $reason ): string {
		$reason = trim( (string) preg_replace( '/\s+/', ' ', $reason ) );

		return function_exists( 'mb_substr' ) ? mb_substr( $reason, 0, self::REASON_MAX_LENGTH ) : substr( $reason, 0, self::REASON_MAX_LENGTH );
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
			"SELECT id,product_key,version,channel,package_url,storage,sha256,requires_php,requires_wp,tested_wp,requires_core,plugin_file,status,published_at,created_at,updated_at FROM {$tables['releases']} ORDER BY product_key ASC, id DESC LIMIT 2000",
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
	 * Top-level ZIP folder (WordPress plugin slug) of a product.
	 *
	 * Set by the first published release; every later package must use the same
	 * folder, otherwise the update would move the plugin and deactivate it.
	 *
	 * @param string $product_key Product key.
	 * @return string Folder name, or an empty string when no release was published yet.
	 */
	public static function product_slug( string $product_key ): string {
		$slugs = get_option( self::SLUG_OPTION, array() );
		$slug = is_array( $slugs ) && isset( $slugs[ $product_key ] ) && is_string( $slugs[ $product_key ] ) ? $slugs[ $product_key ] : '';

		/**
		 * Filters the plugin folder a product's packages must contain.
		 *
		 * @param mixed  $slug Stored folder name (string), or an empty string. Anything but a string is ignored.
		 * @param string $product_key Product key.
		 */
		$slug = apply_filters( 'wplc_release_product_slug', $slug, $product_key );

		return is_string( $slug ) ? $slug : '';
	}

	/**
	 * Store or clear the plugin folder of a product.
	 *
	 * Changing it after customers installed the product moves the plugin on
	 * their sites with the next update. An empty string clears it; the next
	 * published release then defines it again.
	 *
	 * @param string $product_key Product key.
	 * @param string $slug Folder name.
	 * @param int    $actor_user_id Acting user.
	 * @return bool
	 */
	public static function set_product_slug( string $product_key, string $slug, int $actor_user_id = 0 ): bool {
		if ( ! preg_match( self::PRODUCT_KEY_PATTERN, $product_key ) || ( '' !== $slug && ! preg_match( LocalPackageStorage::SLUG_PATTERN, $slug ) ) ) {
			return false;
		}
		$slugs = get_option( self::SLUG_OPTION, array() );
		$slugs = is_array( $slugs ) ? $slugs : array();
		$before = isset( $slugs[ $product_key ] ) ? (string) $slugs[ $product_key ] : '';
		if ( $before === $slug ) {
			return true;
		}
		if ( '' === $slug ) {
			unset( $slugs[ $product_key ] );
		} else {
			$slugs[ $product_key ] = $slug;
		}
		update_option( self::SLUG_OPTION, $slugs, false );
		AuditLog::record(
			'release_product_slug_changed',
			'release',
			0,
			'',
			array(
				'product_key' => $product_key,
				'slug' => $before,
			),
			array(
				'product_key' => $product_key,
				'slug' => $slug,
			),
			$actor_user_id
		);

		return true;
	}

	/**
	 * Name, homepage and icons of a product for the update details view (B-141).
	 *
	 * @param string $product_key Product key.
	 * @return array{name:string,homepage:string,icons:array<string,string>}
	 */
	public static function product_info( string $product_key ): array {
		global $wpdb;

		$cache_key = 'info:' . $product_key;
		$info = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( ! is_array( $info ) ) {
			$tables = Schema::table_names();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names().
			$name = $wpdb->get_var( $wpdb->prepare( "SELECT display_name FROM {$tables['products']} WHERE product_key = %s LIMIT 1", $product_key ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names().
			$wc_product_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT woocommerce_product_id FROM {$tables['plans']} WHERE product_key = %s AND woocommerce_product_id > 0 ORDER BY id ASC LIMIT 1", $product_key ) );
			$permalink = $wc_product_id > 0 ? get_permalink( $wc_product_id ) : '';
			$info = array(
				'name' => is_string( $name ) ? $name : '',
				'homepage' => is_string( $permalink ) ? $permalink : '',
			);
			wp_cache_set( $cache_key, $info, self::CACHE_GROUP, self::CACHE_TTL );
		}

		/**
		 * Filters the homepage shown for a product in the WordPress update details.
		 *
		 * @param mixed  $homepage URL (string). Default: permalink of the mapped WooCommerce product.
		 * @param string $product_key Product key.
		 */
		$homepage = apply_filters( 'wplc_update_product_homepage', $info['homepage'], $product_key );
		/**
		 * Filters the icons of a product, in the shape WordPress expects: array( '1x' => url, '2x' => url, 'svg' => url ).
		 *
		 * @param mixed  $icons Array of size => HTTPS URL. Default: none. Other values are dropped.
		 * @param string $product_key Product key.
		 */
		$icons = apply_filters( 'wplc_update_product_icons', array(), $product_key );
		$clean_icons = array();
		foreach ( is_array( $icons ) ? $icons : array() as $size => $url ) {
			$url = is_string( $url ) ? esc_url_raw( $url, array( 'https' ) ) : '';
			if ( is_string( $size ) && '' !== $url ) {
				$clean_icons[ sanitize_key( $size ) ] = $url;
			}
		}

		return array(
			'name' => (string) $info['name'],
			'homepage' => is_string( $homepage ) ? esc_url_raw( $homepage, array( 'http', 'https' ) ) : '',
			'icons' => $clean_icons,
		);
	}

	/**
	 * Whether the package behind a download grant may still be delivered (B-143, A-111).
	 *
	 * A grant only stores a URL. For a release of the registry this checks that
	 * the release is still published (and, for local storage, that its file is
	 * present). URLs that belong to no release, e.g. from a custom
	 * `wplc_update_packages` filter, are not restricted here.
	 *
	 * @param string $package_url URL stored in the grant.
	 * @param string $product_key Product key of the license.
	 * @return bool
	 */
	public static function grant_is_deliverable( string $package_url, string $product_key ): bool {
		global $wpdb;

		$release_id = PackageStream::placeholder_release_id( $package_url );
		if ( $release_id > 0 ) {
			$release = self::get( $release_id );

			return null !== $release
				&& self::STATUS_PUBLISHED === $release['status']
				&& self::STORAGE_LOCAL === $release['storage']
				&& (string) $release['product_key'] === $product_key
				&& null !== LocalPackageStorage::path_for_key( (string) $release['package_url'] );
		}
		$tables = Schema::table_names();
		$statuses = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names().
				"SELECT status FROM {$tables['releases']} WHERE product_key = %s AND storage = %s AND package_url = %s",
				$product_key,
				self::STORAGE_URL,
				$package_url
			)
		);
		if ( ! $statuses ) {
			return true;
		}

		return in_array( self::STATUS_PUBLISHED, $statuses, true );
	}

	/**
	 * Problems that stop published releases from being delivered.
	 *
	 * Meant for the releases screen and the diagnostics page:
	 * - `package_missing`: a published upload is not on this server. Typical for
	 *   several web nodes without a shared package directory (B-25) or a restore
	 *   without the directory.
	 * - `untrusted_package_host`: a published package URL is no longer on an
	 *   allowed host, e.g. after the shop moved to another domain (B-102).
	 * - `package_dir_exposed`: the package directory answers over HTTP (T-135).
	 *
	 * @param bool $probe Run the HTTP probe of the package directory when no result is cached.
	 * @return array<int,array{code:string,message:string,release_id:int}>
	 */
	public static function problems( bool $probe = false ): array {
		global $wpdb;

		$problems = array();
		$tables = Schema::table_names();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names().
				"SELECT id, product_key, version, storage, package_url FROM {$tables['releases']} WHERE status = %s ORDER BY id DESC LIMIT 500",
				self::STATUS_PUBLISHED
			),
			ARRAY_A
		);
		$has_local = false;
		foreach ( (array) $rows as $row ) {
			$label = (string) $row['product_key'] . ' ' . (string) $row['version'];
			if ( self::STORAGE_LOCAL === $row['storage'] ) {
				$has_local = true;
				if ( null === LocalPackageStorage::path_for_key( (string) $row['package_url'] ) ) {
					$problems[] = array(
						'code' => 'package_missing',
						/* translators: %s: product key and version. */
						'message' => sprintf( __( 'The uploaded package of %s is missing on this server. It is not offered as an update. With several web servers the package directory must be shared between them.', 'wp-license' ), $label ),
						'release_id' => (int) $row['id'],
					);
				}
			} elseif ( ! PackageStream::is_trusted_url( (string) $row['package_url'] ) ) {
				$problems[] = array(
					'code' => 'untrusted_package_host',
					'message' => sprintf(
						/* translators: 1: product key and version, 2: host name. */
						__( 'The package URL of %1$s points to "%2$s", which is not an allowed package host (any more). Update checks for this product fail until the host is added under License settings or the release is replaced. This happens after the shop moved to another domain.', 'wp-license' ),
						$label,
						(string) wp_parse_url( (string) $row['package_url'], PHP_URL_HOST )
					),
					'release_id' => (int) $row['id'],
				);
			}
		}
		$exposure = '';
		if ( $probe && $has_local ) {
			$exposure = LocalPackageStorage::exposure_status();
		} else {
			$cached = get_transient( LocalPackageStorage::EXPOSURE_TRANSIENT );
			$exposure = is_array( $cached ) && isset( $cached['status'], $cached['dir'] ) && LocalPackageStorage::base_dir() === $cached['dir'] ? (string) $cached['status'] : '';
		}
		if ( 'exposed' === $exposure ) {
			$problems[] = array(
				'code' => 'package_dir_exposed',
				'message' => __( 'The package directory can be read over HTTP: this web server ignores the .htaccess protection (nginx). Packages are only protected by their random file name. Add a deny rule for the directory or move it outside the web root with the wplc_package_storage_dir filter (see docs/UPDATE-STORAGE.md).', 'wp-license' ),
				'release_id' => 0,
			);
		}

		return $problems;
	}

	/**
	 * Latest audit entries of releases, newest first.
	 *
	 * @param int $limit Maximum number of entries.
	 * @return array<int,array<string,mixed>>
	 */
	public static function audit_entries( int $limit = 50 ): array {
		global $wpdb;

		$tables = Schema::table_names();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names().
				"SELECT id, actor_user_id, action, object_id, reason, before_json, after_json, created_at FROM {$tables['audit_log']} WHERE object_type = %s ORDER BY id DESC LIMIT %d",
				'release',
				max( 1, min( 500, $limit ) )
			),
			ARRAY_A
		);
		$entries = array();
		foreach ( (array) $rows as $row ) {
			$after = json_decode( (string) $row['after_json'], true );
			$before = json_decode( (string) $row['before_json'], true );
			$state = is_array( $after ) ? $after : ( is_array( $before ) ? $before : array() );
			$entries[] = array(
				'id' => (int) $row['id'],
				'release_id' => (int) $row['object_id'],
				'actor_user_id' => (int) $row['actor_user_id'],
				'action' => (string) $row['action'],
				'reason' => (string) $row['reason'],
				'product_key' => (string) ( $state['product_key'] ?? '' ),
				'version' => (string) ( $state['version'] ?? '' ),
				'created_at' => (string) $row['created_at'],
			);
		}

		return $entries;
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
			'invalid_version' => __( 'The version must have three numeric parts, optionally followed by -alpha, -beta or -rc and a number: 1.2.3, 1.2.3-beta1, 1.2.3-rc2. Other suffixes are sorted wrongly by WordPress.', 'wp-license' ),
			'invalid_channel' => __( 'The release channel is invalid.', 'wp-license' ),
			'invalid_storage' => __( 'The package storage type is invalid.', 'wp-license' ),
			'invalid_package_url' => __( 'The package URL must be a valid HTTPS URL without credentials.', 'wp-license' ),
			'invalid_package_key' => __( 'The stored package file reference is invalid.', 'wp-license' ),
			'invalid_sha256' => __( 'The SHA-256 checksum must consist of exactly 64 hexadecimal characters.', 'wp-license' ),
			'invalid_requires_php' => __( 'The required PHP version is invalid.', 'wp-license' ),
			'invalid_requires_wp' => __( 'The required WordPress version is invalid.', 'wp-license' ),
			'invalid_tested_wp' => __( 'The tested WordPress version is invalid.', 'wp-license' ),
			'invalid_requires_core' => __( 'The required version of the free core plugin is invalid.', 'wp-license' ),
			'invalid_plugin_file' => __( 'The plugin file of the package is invalid.', 'wp-license' ),
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
			'checksum_mismatch_remote' => __( 'The file at the package URL does not match the SHA-256 checksum of the release.', 'wp-license' ),
			'untrusted_package_host' => __( 'The host of the package URL is not allowed. Add it under WooCommerce → License settings → "Additional trusted package hosts". The URL must use HTTPS on the standard port.', 'wp-license' ),
			'reason_required' => __( 'Please enter a reason. It is stored in the release log.', 'wp-license' ),
			'database_error' => __( 'The release could not be saved.', 'wp-license' ),
		);

		return isset( $messages[ $code ] ) ? $messages[ $code ] : __( 'The release data is invalid.', 'wp-license' );
	}

	/**
	 * Build a WP_Error for an error code.
	 *
	 * @param string $code Error code.
	 * @return \WP_Error
	 */
	public static function error( string $code ): \WP_Error {
		return new \WP_Error( $code, self::error_message( $code ) );
	}

	/**
	 * Download a URL package once and compare it with the release (B-143).
	 *
	 * @param array<string,mixed> $release Release row with storage "url".
	 * @param string              $slug Required plugin folder, or an empty string.
	 * @return array<string,string>|\WP_Error|null Package details, or null when the check is switched off.
	 */
	private static function verify_remote_package( array $release, string $slug ) {
		/**
		 * Filters whether a URL package is downloaded and verified when its release is published.
		 *
		 * Return false only when the publishing process verifies the package elsewhere.
		 *
		 * @param bool                $verify Default true.
		 * @param array<string,mixed> $release Release row.
		 */
		if ( ! apply_filters( 'wplc_verify_url_release_on_publish', true, $release ) ) {
			return null;
		}
		// License ID 0: this is the server itself, not a customer download.
		$url = apply_filters( 'wplc_resolve_update_package_url', (string) $release['package_url'], 0 );
		if ( ! is_string( $url ) || ! PackageStream::is_trusted_url( $url ) ) {
			return self::error( 'untrusted_package_host' );
		}
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$file = download_url( $url, 300 );
		if ( is_wp_error( $file ) ) {
			return new \WP_Error(
				'package_download_failed',
				sprintf(
					/* translators: %s: error message of the download. */
					__( 'The package could not be downloaded from its URL to verify it: %s', 'wp-license' ),
					$file->get_error_message()
				)
			);
		}
		$checksum = hash_file( 'sha256', $file );
		if ( ! is_string( $checksum ) || ! hash_equals( strtolower( (string) $release['sha256'] ), $checksum ) ) {
			$result = self::error( 'checksum_mismatch_remote' );
		} else {
			$result = LocalPackageStorage::validate_package( $file, (string) $release['version'], $slug );
		}
		if ( is_file( $file ) ) {
			unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}

		return $result;
	}

	/**
	 * Cache key of a published index.
	 *
	 * @param string $product_key Product key.
	 * @param string $channel Channel.
	 * @return string
	 */
	private static function cache_key( string $product_key, string $channel ): string {
		return 'idx:' . $product_key . ':' . $channel;
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
		// Also after a lost race: the other request changed the feed.
		self::flush_cache( (string) $release['product_key'], (string) $release['channel'] );
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
			'requires_core' => (string) ( $release['requires_core'] ?? '' ),
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
}
