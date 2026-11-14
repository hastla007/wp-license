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
 * Protection is layered: the directory can live outside the web root
 * (WPLC_PACKAGE_STORAGE_DIR), Apache gets a deny-all .htaccess, every file
 * name carries 128 random bits (nginx ignores .htaccess), PackageStorageCheck
 * probes whether a web-reachable directory is publicly readable, and the path
 * is never sent to a browser - packages are streamed by PackageStream only.
 */
final class LocalPackageStorage {
	const DIRECTORY = 'wplc-packages';
	const DEFAULT_MAX_BYTES = 52428800; // 50 MB.
	const KEY_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,99}-[A-Za-z0-9][A-Za-z0-9.-]{0,39}-[a-f0-9]{32}\.zip$/D';

	/**
	 * Absolute storage directory without trailing slash.
	 *
	 * Order: the WPLC_PACKAGE_STORAGE_DIR constant (when it passes
	 * validate_custom_dir()), otherwise the uploads directory; the
	 * wplc_package_storage_dir filter has the last word.
	 *
	 * @return string
	 */
	public static function base_dir(): string {
		return self::resolve()['dir'];
	}

	/**
	 * Resolve the storage directory and where it came from.
	 *
	 * @return array{dir:string,source:string,constant_error:string}
	 */
	public static function resolve(): array {
		$uploads = wp_upload_dir( null, false );
		$default = rtrim( (string) $uploads['basedir'], '/\\' ) . '/' . self::DIRECTORY;
		$constant = defined( 'WPLC_PACKAGE_STORAGE_DIR' ) ? (string) constant( 'WPLC_PACKAGE_STORAGE_DIR' ) : null;
		$resolved = self::resolve_dir( $constant, $default, null === $constant ? array() : self::web_reachable_roots() );
		/**
		 * Filters the private package directory, e.g. to move it outside the web root.
		 *
		 * Prefer the WPLC_PACKAGE_STORAGE_DIR constant in wp-config.php: it is
		 * validated, while a filtered value is used as returned.
		 *
		 * @param string $directory Absolute directory path.
		 */
		$filtered = apply_filters( 'wplc_package_storage_dir', $resolved['dir'] );
		if ( is_string( $filtered ) && '' !== trim( $filtered ) && rtrim( $filtered, '/\\' ) !== $resolved['dir'] ) {
			$resolved['dir'] = rtrim( $filtered, '/\\' );
			$resolved['source'] = 'filter';
		}

		return $resolved;
	}

	/**
	 * Choose between the constant and the default directory. Pure helper.
	 *
	 * An invalid constant is ignored (the protected default stays in use) and
	 * reported, so that a typo never sends packages to an unexpected place.
	 *
	 * @param string|null $constant Value of WPLC_PACKAGE_STORAGE_DIR, null when undefined.
	 * @param string      $default_dir Default directory inside uploads.
	 * @param string[]    $web_roots Directories that a web server may serve.
	 * @return array{dir:string,source:string,constant_error:string}
	 */
	public static function resolve_dir( ?string $constant, string $default_dir, array $web_roots ): array {
		$default_dir = rtrim( $default_dir, '/\\' );
		if ( null === $constant ) {
			return array(
				'dir' => $default_dir,
				'source' => 'default',
				'constant_error' => '',
			);
		}
		$error = self::validate_custom_dir( $constant, $web_roots );
		if ( '' !== $error ) {
			return array(
				'dir' => $default_dir,
				'source' => 'default',
				'constant_error' => $error,
			);
		}

		return array(
			'dir' => self::normalize_path( trim( $constant ) ),
			'source' => 'constant',
			'constant_error' => '',
		);
	}

	/**
	 * Validate a configured storage directory. Pure helper.
	 *
	 * Writability is checked separately (ensure_dir() and the diagnostics),
	 * because the directory may not exist yet.
	 *
	 * @param string   $dir Configured directory.
	 * @param string[] $web_roots Directories that a web server may serve.
	 * @return string Error code (relative, traversal, root, web_reachable), or '' when acceptable.
	 */
	public static function validate_custom_dir( string $dir, array $web_roots ): string {
		$path = self::normalize_path( trim( $dir ) );
		if ( '' === $path || ! preg_match( '#^(/|[A-Za-z]:/)#', $path ) ) {
			return 'relative';
		}
		$segments = explode( '/', $path );
		if ( in_array( '..', $segments, true ) || in_array( '.', $segments, true ) ) {
			return 'traversal';
		}
		if ( preg_match( '#^(/|[A-Za-z]:/?)$#', $path ) ) {
			return 'root';
		}
		if ( self::is_inside_any( $path, $web_roots ) ) {
			return 'web_reachable';
		}
		$real = realpath( $path );
		if ( false !== $real && self::is_inside_any( $real, $web_roots ) ) {
			return 'web_reachable';
		}

		return '';
	}

	/**
	 * Directories a web server is expected to serve: ABSPATH, wp-content,
	 * uploads and the document root, each also as its resolved real path.
	 *
	 * @return string[]
	 */
	public static function web_reachable_roots(): array {
		$roots = array();
		if ( defined( 'ABSPATH' ) ) {
			$roots[] = (string) ABSPATH;
		}
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$roots[] = (string) WP_CONTENT_DIR;
		}
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['basedir'] ) ) {
			$roots[] = (string) $uploads['basedir'];
		}
		$document_root = isset( $_SERVER['DOCUMENT_ROOT'] ) ? (string) wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared as a path, never output.
		if ( '' !== trim( $document_root ) ) {
			$roots[] = $document_root;
		}
		foreach ( $roots as $root ) {
			$real = realpath( $root );
			if ( false !== $real ) {
				$roots[] = $real;
			}
		}

		return array_values( array_unique( array_filter( $roots, static function ( string $root ): bool { return '' !== $root; } ) ) );
	}

	/**
	 * Unify separators and drop duplicate and trailing slashes. Pure helper.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public static function normalize_path( string $path ): string {
		$path = (string) preg_replace( '#/{2,}#', '/', str_replace( '\\', '/', $path ) );

		if ( '/' === $path || preg_match( '#^[A-Za-z]:/$#', $path ) ) {
			return $path; // File system root.
		}

		return rtrim( $path, '/' );
	}

	/**
	 * Whether a path is one of the bases or lies below one. Pure helper.
	 *
	 * @param string   $path Path.
	 * @param string[] $bases Base directories.
	 * @return bool
	 */
	public static function is_inside_any( string $path, array $bases ): bool {
		$path = self::normalize_path( $path );
		foreach ( $bases as $base ) {
			$base = self::normalize_path( (string) $base );
			if ( '' === $base ) {
				continue;
			}
			if ( '/' === $base || $path === $base || 0 === strpos( $path, $base . '/' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Public URL of a directory below a served base, or null. Pure helper.
	 *
	 * @param string               $dir Directory.
	 * @param array<string,string> $url_map Served directory => its URL, most specific first.
	 * @return string|null
	 */
	public static function public_url_for_dir( string $dir, array $url_map ): ?string {
		$dir = self::normalize_path( $dir );
		foreach ( $url_map as $base => $url ) {
			$base = self::normalize_path( (string) $base );
			if ( '' === $base || '' === $url ) {
				continue;
			}
			if ( $dir === $base ) {
				return rtrim( $url, '/' );
			}
			if ( 0 === strpos( $dir, $base . '/' ) ) {
				$relative = substr( $dir, strlen( $base ) + 1 );
				return rtrim( $url, '/' ) . '/' . implode( '/', array_map( 'rawurlencode', explode( '/', $relative ) ) );
			}
		}

		return null;
	}

	/**
	 * Explain a validate_custom_dir() error code.
	 *
	 * @param string $code Error code.
	 * @return string
	 */
	public static function dir_error_message( string $code ): string {
		switch ( $code ) {
			case 'relative':
				return __( 'WPLC_PACKAGE_STORAGE_DIR must be an absolute path.', 'wp-license' );
			case 'traversal':
				return __( 'WPLC_PACKAGE_STORAGE_DIR must not contain "." or ".." segments.', 'wp-license' );
			case 'root':
				return __( 'WPLC_PACKAGE_STORAGE_DIR must not be a file system root.', 'wp-license' );
			case 'web_reachable':
				return __( 'WPLC_PACKAGE_STORAGE_DIR lies inside the WordPress directory, wp-content, uploads or the document root. Choose a directory outside the web root.', 'wp-license' );
			default:
				return __( 'WPLC_PACKAGE_STORAGE_DIR is invalid.', 'wp-license' );
		}
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
			'index.php' => "<?php\n// Silence is golden.\n",
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
