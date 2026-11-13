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
	const KEY_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,99}-[A-Za-z0-9][A-Za-z0-9.-]{0,39}-[a-f0-9]{32}\.zip$/D';

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
	 * @return array{key:string,sha256:string}|\WP_Error
	 */
	public static function store_upload( array $file, string $product_key, string $version ) {
		$tmp_name = (string) ( $file['tmp_name'] ?? '' );
		$upload_error = (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );
		if ( UPLOAD_ERR_OK !== $upload_error || '' === $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
			$code = in_array( $upload_error, array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ? 'too_large' : 'upload_failed';
			return new \WP_Error( $code, self::error_message( $code ) );
		}
		$size = (int) filesize( $tmp_name );
		$error = self::validate_zip_file( $tmp_name, (string) ( $file['name'] ?? '' ), $size, self::max_bytes() );
		if ( '' !== $error ) {
			return new \WP_Error( $error, self::error_message( $error ) );
		}
		if ( ! self::ensure_dir() ) {
			return new \WP_Error( 'storage_unavailable', self::error_message( 'storage_unavailable' ) );
		}
		$key = self::build_key( $product_key, $version, bin2hex( random_bytes( 16 ) ) );
		if ( ! self::is_valid_key( $key ) ) {
			return new \WP_Error( 'storage_unavailable', self::error_message( 'storage_unavailable' ) );
		}
		$target = self::base_dir() . '/' . $key;
		if ( file_exists( $target ) || ! move_uploaded_file( $tmp_name, $target ) ) {
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
		);
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
			default:
				return __( 'The file upload failed.', 'wp-license' );
		}
	}
}
