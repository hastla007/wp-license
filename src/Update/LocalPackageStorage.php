<?php
/**
 * Private local storage for uploaded release packages.
 *
 * @package WPLC
 */

namespace WPLC\Update;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps release ZIPs in a directory that is never linked or exposed.
 *
 * Protection is layered: Apache gets a deny-all .htaccess, every file name
 * carries 128 random bits (nginx ignores .htaccess), and the path is never
 * sent to a browser - packages are streamed by PackageStream only.
 */
final class LocalPackageStorage {
	const DIRECTORY = 'wplc-packages';
	const DEFAULT_MAX_BYTES = 52428800; // 50 MB.
	// The product part follows Catalog::LEGACY_PRODUCT_KEY_PATTERN (every key Catalog::accepts_product_key()
	// can accept, including existing keys with underscores) minus leading/trailing hyphens (B-174).
	const KEY_PATTERN = '/^[a-z0-9_][a-z0-9_-]{0,99}-[A-Za-z0-9][A-Za-z0-9.-]{0,39}-[a-f0-9]{32}\.zip$/D';
	/** Top-level folder of a package: the WordPress plugin directory. */
	const SLUG_PATTERN = '/^[A-Za-z0-9_][A-Za-z0-9._-]{0,99}$/D';
	const EXPOSURE_TRANSIENT = 'wplc_package_dir_exposure';
	const PROBE_FILE = 'wplc-probe.txt';

	/**
	 * Absolute storage directory without trailing slash.
	 *
	 * @return string
	 */
	public static function base_dir(): string {
		$uploads = wp_upload_dir( null, false );
		$default = rtrim( (string) $uploads['basedir'], '/\\' ) . '/' . self::DIRECTORY;
		/**
		 * Filters the private package directory, e.g. to move it outside the web root.
		 *
		 * @param string $directory Absolute directory path.
		 */
		$directory = apply_filters( 'wplc_package_storage_dir', $default );

		return is_string( $directory ) && '' !== trim( $directory ) ? rtrim( $directory, '/\\' ) : $default;
	}

	/**
	 * Maximum accepted upload size in bytes.
	 *
	 * @return int
	 */
	public static function max_bytes(): int {
		$limit = (int) apply_filters( 'wplc_package_max_upload_bytes', self::DEFAULT_MAX_BYTES );

		return $limit > 0 ? $limit : self::DEFAULT_MAX_BYTES;
	}

	/**
	 * Create the directory and its protection files.
	 *
	 * @return bool
	 */
	public static function ensure_dir(): bool {
		$dir = self::base_dir();
		if ( ! wp_mkdir_p( $dir ) || ! wp_is_writable( $dir ) ) {
			return false;
		}
		$files = array(
			'.htaccess' => "# WP License Captain: private release packages.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n",
			'index.html' => '',
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
		);
		foreach ( $files as $name => $content ) {
			$path = $dir . '/' . $name;
			if ( ! file_exists( $path ) && false === file_put_contents( $path, $content ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				return false;
			}
		}

		return true;
	}

	/**
	 * Validate and store an uploaded ZIP.
	 *
	 * @param array<string,mixed> $file One entry of $_FILES.
	 * @param string              $product_key Product key.
	 * @param string              $version Release version.
	 * @param string              $expected_slug Required top-level folder, or an empty string when the product has none yet.
	 * @return array{key:string,sha256:string,package:array<string,string>}|\WP_Error
	 */
	public static function store_upload( array $file, string $product_key, string $version, string $expected_slug = '' ) {
		$tmp_name = (string) ( $file['tmp_name'] ?? '' );
		$upload_error = (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );
		if ( UPLOAD_ERR_OK !== $upload_error || '' === $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
			$code = in_array( $upload_error, array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ? 'too_large' : 'upload_failed';
			return new \WP_Error( $code, self::error_message( $code ) );
		}

		return self::store( $tmp_name, (string) ( $file['name'] ?? '' ), $product_key, $version, $expected_slug, true );
	}

	/**
	 * Validate and store a ZIP that already lies on this server (WP-CLI, deployment scripts). B-169.
	 *
	 * The source file is copied and stays where it is.
	 *
	 * @param string $path Readable ZIP file.
	 * @param string $product_key Product key.
	 * @param string $version Release version.
	 * @param string $expected_slug Required top-level folder, or an empty string when the product has none yet.
	 * @return array{key:string,sha256:string,package:array<string,string>}|\WP_Error
	 */
	public static function store_file( string $path, string $product_key, string $version, string $expected_slug = '' ) {
		if ( '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			return new \WP_Error( 'upload_failed', self::error_message( 'upload_failed' ) );
		}

		return self::store( $path, basename( $path ), $product_key, $version, $expected_slug, false );
	}

	/**
	 * Shared implementation of store_upload() and store_file().
	 *
	 * Nothing is written before the archive, its folder layout and its version header are accepted.
	 *
	 * @param string $source Source path.
	 * @param string $original_name Original file name.
	 * @param string $product_key Product key.
	 * @param string $version Release version.
	 * @param string $expected_slug Required top-level folder, or an empty string.
	 * @param bool   $is_upload Whether the source is a PHP upload.
	 * @return array{key:string,sha256:string,package:array<string,string>}|\WP_Error
	 */
	private static function store( string $source, string $original_name, string $product_key, string $version, string $expected_slug, bool $is_upload ) {
		$size = (int) filesize( $source );
		$error = self::validate_zip_file( $source, $original_name, $size, self::max_bytes() );
		if ( '' !== $error ) {
			return new \WP_Error( $error, self::error_message( $error ) );
		}
		$package = self::validate_package( $source, $version, $expected_slug );
		if ( is_wp_error( $package ) ) {
			return $package;
		}
		if ( ! self::ensure_dir() ) {
			return new \WP_Error( 'storage_unavailable', self::error_message( 'storage_unavailable' ) );
		}
		$key = self::build_key( $product_key, $version, bin2hex( random_bytes( 16 ) ) );
		if ( ! self::is_valid_key( $key ) ) {
			return new \WP_Error( 'storage_key_invalid', self::error_message( 'storage_key_invalid' ) );
		}
		$target = self::base_dir() . '/' . $key;
		if ( file_exists( $target ) ) {
			return new \WP_Error( 'storage_unavailable', self::error_message( 'storage_unavailable' ) );
		}
		$stored = $is_upload ? move_uploaded_file( $source, $target ) : copy( $source, $target );
		if ( ! $stored ) {
			return new \WP_Error( 'storage_unavailable', self::error_message( 'storage_unavailable' ) );
		}
		$checksum = hash_file( 'sha256', $target );
		if ( ! is_string( $checksum ) ) {
			unlink( $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			return new \WP_Error( 'storage_unavailable', self::error_message( 'storage_unavailable' ) );
		}

		return array(
			'key' => $key,
			'sha256' => strtolower( $checksum ),
			'package' => $package,
		);
	}

	/**
	 * Read what WordPress will see when it installs the archive. B-53.
	 *
	 * WordPress installs the single top-level folder of a plugin ZIP as the
	 * plugin directory. A different folder name deactivates the plugin on every
	 * site (the plugin file path changes), and a `Version:` header that differs
	 * from the announced version makes WordPress offer the same update forever.
	 *
	 * @param string $path ZIP file.
	 * @return array{slug:string,plugin_file:string,version:string,name:string,requires_php:string,requires_wp:string}|\WP_Error
	 */
	public static function inspect_zip( string $path ) {
		if ( ! class_exists( '\\ZipArchive' ) ) {
			return new \WP_Error( 'zip_unsupported', self::error_message( 'zip_unsupported' ) );
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return new \WP_Error( 'not_a_zip', self::error_message( 'not_a_zip' ) );
		}
		$folders = array();
		$candidates = array();
		$loose_files = false;
		$count = $zip->numFiles; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		for ( $i = 0; $i < $count; $i++ ) {
			$name = str_replace( '\\', '/', (string) $zip->getNameIndex( $i ) );
			if ( '' === $name || '/' === $name[0] || false !== strpos( '/' . $name . '/', '/../' ) ) {
				$zip->close();
				return new \WP_Error( 'zip_unsafe_path', self::error_message( 'zip_unsafe_path' ) );
			}
			$parts = explode( '/', $name );
			if ( 1 === count( $parts ) ) {
				// A file directly in the archive root.
				$loose_files = true;
				continue;
			}
			$folders[ $parts[0] ] = true;
			if ( 2 === count( $parts ) && '' !== $parts[1] && '.php' === strtolower( substr( $parts[1], -4 ) ) ) {
				$candidates[ $i ] = $parts[1];
			}
		}
		if ( $loose_files || 1 !== count( $folders ) ) {
			$zip->close();
			return new \WP_Error( 'zip_folder_layout', self::error_message( 'zip_folder_layout' ) );
		}
		$slug = (string) key( $folders );
		if ( ! preg_match( self::SLUG_PATTERN, $slug ) ) {
			$zip->close();
			return new \WP_Error( 'zip_folder_layout', self::error_message( 'zip_folder_layout' ) );
		}

		$found = null;
		foreach ( $candidates as $index => $file ) {
			// Same window as WordPress' get_file_data().
			$head = $zip->getFromIndex( $index, 8192 );
			if ( ! is_string( $head ) ) {
				continue;
			}
			$headers = self::parse_plugin_headers( $head );
			if ( '' === $headers['name'] ) {
				continue;
			}
			// Prefer {slug}.php when several files carry a plugin header.
			if ( null === $found || strtolower( $file ) === strtolower( $slug ) . '.php' ) {
				$found = array_merge( $headers, array( 'file' => $file ) );
			}
		}
		$zip->close();
		if ( null === $found ) {
			return new \WP_Error( 'zip_no_plugin_header', self::error_message( 'zip_no_plugin_header' ) );
		}

		return array(
			'slug' => $slug,
			'plugin_file' => $slug . '/' . $found['file'],
			'version' => $found['version'],
			'name' => $found['name'],
			'requires_php' => $found['requires_php'],
			'requires_wp' => $found['requires_wp'],
		);
	}

	/**
	 * Extract the plugin headers this class needs. Pure helper.
	 *
	 * @param string $content First bytes of a PHP file.
	 * @return array{name:string,version:string,requires_php:string,requires_wp:string}
	 */
	public static function parse_plugin_headers( string $content ): array {
		$content = str_replace( "\r", "\n", $content );
		$headers = array(
			'name' => 'Plugin Name',
			'version' => 'Version',
			'requires_php' => 'Requires PHP',
			'requires_wp' => 'Requires at least',
		);
		$result = array();
		foreach ( $headers as $field => $label ) {
			$result[ $field ] = '';
			// Same expression as WordPress' get_file_data().
			if ( preg_match( '/^(?:[ \t]*<\?php)?[ \t\/*#@]*' . preg_quote( $label, '/' ) . ':(.*)$/mi', $content, $match ) ) {
				$result[ $field ] = trim( (string) preg_replace( '/\s*(?:\*\/|\?>).*/', '', $match[1] ) );
			}
		}

		return $result;
	}

	/**
	 * Inspect an archive and compare it with the release it belongs to. B-53.
	 *
	 * @param string $path ZIP file.
	 * @param string $version Release version.
	 * @param string $expected_slug Required top-level folder, or an empty string when the product has none yet.
	 * @return array<string,string>|\WP_Error Result of inspect_zip().
	 */
	public static function validate_package( string $path, string $version, string $expected_slug = '' ) {
		$package = self::inspect_zip( $path );
		if ( is_wp_error( $package ) ) {
			return $package;
		}
		if ( '' !== $expected_slug && $package['slug'] !== $expected_slug ) {
			return new \WP_Error(
				'zip_slug_mismatch',
				sprintf(
					/* translators: 1: folder found in the ZIP, 2: folder the product requires. */
					__( 'The ZIP contains the folder "%1$s", but this product is installed in the folder "%2$s". A different folder would deactivate the plugin on every customer site.', 'wp-license' ),
					$package['slug'],
					$expected_slug
				)
			);
		}
		if ( $package['version'] !== $version ) {
			return new \WP_Error(
				'zip_version_mismatch',
				sprintf(
					/* translators: 1: version in the plugin header, 2: release version, 3: plugin file. */
					__( 'The "Version:" header in %3$s is "%1$s", but the release version is "%2$s". WordPress would offer this update again and again.', 'wp-license' ),
					'' === $package['version'] ? '—' : $package['version'],
					$version,
					$package['plugin_file']
				)
			);
		}

		return $package;
	}

	/**
	 * Base64 MD5 of a stored package for the `Content-MD5` header. B-52.
	 *
	 * WordPress' download_url() verifies this header. Packages are immutable,
	 * so the digest is cached per storage key.
	 *
	 * @param string $key Storage key.
	 * @return string Base64 digest, or an empty string when the file is missing.
	 */
	public static function content_md5( string $key ): string {
		$path = self::path_for_key( $key );
		if ( null === $path ) {
			return '';
		}
		$cache_key = 'wplc_pkg_md5_' . md5( $key . '|' . (string) filesize( $path ) . '|' . (string) filemtime( $path ) );
		$cached = get_transient( $cache_key );
		if ( is_string( $cached ) && 24 === strlen( $cached ) ) {
			return $cached;
		}
		$digest = md5_file( $path, true );
		if ( ! is_string( $digest ) ) {
			return '';
		}
		$encoded = base64_encode( $digest ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Content-MD5 is defined as base64.
		set_transient( $cache_key, $encoded, MONTH_IN_SECONDS );

		return $encoded;
	}

	/**
	 * Check over HTTP whether the package directory is reachable from outside. T-135.
	 *
	 * The `.htaccess` file is ignored by nginx. This probe requests a harmless
	 * text file from the directory; an answer with the probe content means
	 * that packages are protected by their random file name only.
	 *
	 * @param bool $force Ignore the cached result.
	 * @return string 'protected', 'exposed', 'outside_webroot' or 'unknown'.
	 */
	public static function exposure_status( bool $force = false ): string {
		$cached = get_transient( self::EXPOSURE_TRANSIENT );
		if ( ! $force && is_array( $cached ) && isset( $cached['dir'], $cached['status'] ) && self::base_dir() === $cached['dir'] ) {
			return (string) $cached['status'];
		}
		$status = self::probe_exposure();
		set_transient(
			self::EXPOSURE_TRANSIENT,
			array(
				'dir' => self::base_dir(),
				'status' => $status,
			),
			'unknown' === $status ? HOUR_IN_SECONDS : DAY_IN_SECONDS
		);

		return $status;
	}

	/**
	 * Public URL of the package directory, or an empty string when it lies outside the uploads directory.
	 *
	 * @return string
	 */
	public static function base_url(): string {
		$uploads = wp_upload_dir( null, false );
		$basedir = rtrim( str_replace( '\\', '/', (string) $uploads['basedir'] ), '/' );
		$dir = str_replace( '\\', '/', self::base_dir() );
		if ( '' === $basedir || 0 !== strpos( $dir . '/', $basedir . '/' ) ) {
			return '';
		}

		return rtrim( (string) $uploads['baseurl'], '/' ) . substr( $dir, strlen( $basedir ) );
	}

	/**
	 * Run the exposure probe.
	 *
	 * @return string
	 */
	private static function probe_exposure(): string {
		$url = self::base_url();
		if ( '' === $url ) {
			// Not below the uploads directory: no URL is known for it.
			return 'outside_webroot';
		}
		if ( ! self::ensure_dir() ) {
			return 'unknown';
		}
		$marker = 'wplc-probe-' . bin2hex( random_bytes( 8 ) );
		$file = self::base_dir() . '/' . self::PROBE_FILE;
		if ( false === file_put_contents( $file, $marker ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return 'unknown';
		}
		$response = wp_remote_get(
			$url . '/' . self::PROBE_FILE,
			array(
				'timeout' => 5,
				'redirection' => 0,
				// Core filter for requests of a site to itself.
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			)
		);
		unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		if ( is_wp_error( $response ) ) {
			return 'unknown';
		}

		return self::classify_probe( (int) wp_remote_retrieve_response_code( $response ), (string) wp_remote_retrieve_body( $response ), $marker );
	}

	/**
	 * Interpret the probe answer. Pure helper.
	 *
	 * @param int    $code HTTP status.
	 * @param string $body Response body.
	 * @param string $marker Content written to the probe file.
	 * @return string
	 */
	public static function classify_probe( int $code, string $body, string $marker ): string {
		if ( 200 === $code && '' !== $marker && false !== strpos( $body, $marker ) ) {
			return 'exposed';
		}
		if ( $code >= 400 && $code < 500 ) {
			return 'protected';
		}

		return 'unknown';
	}

	/**
	 * Check that a file is an acceptable ZIP package. Pure helper.
	 *
	 * @param string $path File to inspect.
	 * @param string $original_name Client-side file name.
	 * @param int    $size File size in bytes.
	 * @param int    $max_bytes Size limit in bytes.
	 * @return string Error code, or an empty string when the file is acceptable.
	 */
	public static function validate_zip_file( string $path, string $original_name, int $size, int $max_bytes ): string {
		if ( ! preg_match( '/\.zip$/i', $original_name ) ) {
			return 'not_a_zip';
		}
		if ( $size <= 0 ) {
			return 'empty_file';
		}
		if ( $size > $max_bytes ) {
			return 'too_large';
		}
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return 'upload_failed';
		}
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			return 'upload_failed';
		}
		$magic = (string) fread( $handle, 4 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		// Local file header of a non-empty archive; "PK\x05\x06" would be an empty archive.
		if ( "PK\x03\x04" !== $magic ) {
			return 'not_a_zip';
		}
		if ( class_exists( '\\ZipArchive' ) ) {
			$zip = new \ZipArchive();
			if ( true !== $zip->open( $path ) ) {
				return 'not_a_zip';
			}
			$entries = $zip->numFiles; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$zip->close();
			if ( $entries < 1 ) {
				return 'not_a_zip';
			}
		}

		return '';
	}

	/**
	 * Build the unguessable file name. Pure helper.
	 *
	 * @param string $product_key Product key.
	 * @param string $version Release version.
	 * @param string $random 32 hexadecimal characters.
	 * @return string
	 */
	public static function build_key( string $product_key, string $version, string $random ): string {
		$product = trim( (string) preg_replace( '/[^a-z0-9_-]+/', '-', strtolower( $product_key ) ), '-' );
		// A key made of hyphens only would otherwise collapse to nothing.
		$product = '' === $product ? '_' : $product;
		$version = trim( (string) preg_replace( '/[^A-Za-z0-9.-]+/', '-', $version ), '-.' );
		$version = (string) preg_replace( '/\.{2,}/', '.', $version );

		return substr( $product, 0, 100 ) . '-' . substr( $version, 0, 40 ) . '-' . strtolower( $random ) . '.zip';
	}

	/**
	 * Whether a stored reference is a plain package file name. Pure helper.
	 *
	 * @param string $key Storage key.
	 * @return bool
	 */
	public static function is_valid_key( string $key ): bool {
		return 1 === preg_match( self::KEY_PATTERN, $key ) && false === strpos( $key, '..' );
	}

	/**
	 * Resolve a storage key to an existing file.
	 *
	 * @param string $key Storage key.
	 * @return string|null Absolute path.
	 */
	public static function path_for_key( string $key ): ?string {
		if ( ! self::is_valid_key( $key ) ) {
			return null;
		}
		$path = self::base_dir() . '/' . $key;

		return is_file( $path ) && is_readable( $path ) ? $path : null;
	}

	/**
	 * Delete a stored package.
	 *
	 * @param string $key Storage key.
	 * @return void
	 */
	public static function delete( string $key ): void {
		$path = self::path_for_key( $key );
		if ( null !== $path ) {
			unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}

	/**
	 * Translate an error code.
	 *
	 * @param string $code Error code.
	 * @return string
	 */
	public static function error_message( string $code ): string {
		switch ( $code ) {
			case 'not_a_zip':
				return __( 'The uploaded file is not a valid ZIP archive.', 'wp-license' );
			case 'empty_file':
				return __( 'The uploaded file is empty.', 'wp-license' );
			case 'too_large':
				return __( 'The uploaded file exceeds the package size limit.', 'wp-license' );
			case 'storage_unavailable':
				return __( 'The private package directory is not writable.', 'wp-license' );
			case 'storage_key_invalid':
				return __( 'No file name can be built from this product key and version.', 'wp-license' );
			case 'zip_unsupported':
				return __( 'The PHP extension "zip" (ZipArchive) is missing on this server. Without it the package cannot be checked and is refused.', 'wp-license' );
			case 'zip_unsafe_path':
				return __( 'The ZIP contains an absolute path or a path with "..".', 'wp-license' );
			case 'zip_folder_layout':
				return __( 'The ZIP must contain exactly one top-level folder (the plugin folder) and nothing next to it. Remove extra entries such as "__MACOSX".', 'wp-license' );
			case 'zip_no_plugin_header':
				return __( 'No PHP file with a "Plugin Name:" header was found directly inside the top-level folder of the ZIP.', 'wp-license' );
			default:
				return __( 'The file upload failed.', 'wp-license' );
		}
	}
}
