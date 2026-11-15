<?php
/**
 * Optional must-use helper that drops a deleted site's tables (P-32).
 *
 * @package WPLC
 */

namespace WPLC\Infrastructure\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Installs, removes and inspects `wp-content/mu-plugins/wplc-site-cleanup.php`.
 *
 * WordPress does not load the plugin in a request that deletes a site on which only that
 * site had it active, so the plugin's own `wpmu_drop_tables` filter cannot run. The
 * must-use copy of `mu-plugins/wplc-site-cleanup.php` is always loaded and has no other
 * dependency on the plugin. Installing it is an explicit operator decision.
 */
final class SiteCleanupHelper {

	public const FILE_NAME = 'wplc-site-cleanup.php';

	/** Header line that identifies the helper, so remove() never deletes another file. */
	public const MARKER = 'WPLC MU Helper: site-cleanup';

	public const STATUS_MISSING   = 'missing';
	public const STATUS_INSTALLED = 'installed';
	public const STATUS_OUTDATED  = 'outdated';
	public const STATUS_FOREIGN   = 'foreign';

	/**
	 * Path of the helper shipped with the plugin.
	 *
	 * @return string
	 */
	public static function source(): string {
		return dirname( __DIR__, 3 ) . '/mu-plugins/' . self::FILE_NAME;
	}

	/**
	 * Path of the installed must-use copy.
	 *
	 * @return string
	 */
	public static function target(): string {
		$dir = defined( 'WPMU_PLUGIN_DIR' ) ? (string) WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';

		return rtrim( $dir, '/\\' ) . '/' . self::FILE_NAME;
	}

	/**
	 * State of the installed copy compared with the shipped one.
	 *
	 * @param string|null $target Path to inspect (default: target()).
	 * @param string|null $source Shipped file (default: source()).
	 * @return string One of the STATUS_* constants.
	 */
	public static function status( ?string $target = null, ?string $source = null ): string {
		$target = $target ?? self::target();
		$source = $source ?? self::source();
		if ( ! is_file( $target ) ) {
			return self::STATUS_MISSING;
		}
		$installed = (string) file_get_contents( $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		if ( false === strpos( $installed, self::MARKER ) ) {
			return self::STATUS_FOREIGN;
		}
		$shipped = is_readable( $source ) ? (string) file_get_contents( $source ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.

		return $shipped === $installed ? self::STATUS_INSTALLED : self::STATUS_OUTDATED;
	}

	/**
	 * Copy the shipped helper into the must-use plugin directory (replaces an older copy).
	 *
	 * @param string|null $target Destination (default: target()).
	 * @param string|null $source Shipped file (default: source()).
	 * @return string Empty string on success, otherwise an error message.
	 */
	public static function install( ?string $target = null, ?string $source = null ): string {
		$target = $target ?? self::target();
		$source = $source ?? self::source();
		if ( ! is_readable( $source ) ) {
			/* translators: %s: file path. */
			return sprintf( __( 'The helper file %s is missing from the plugin.', 'wp-license' ), $source );
		}
		if ( self::STATUS_FOREIGN === self::status( $target, $source ) ) {
			/* translators: %s: file path. */
			return sprintf( __( '%s exists and is not the WP License Captain helper; it was not replaced.', 'wp-license' ), $target );
		}
		$dir = dirname( $target );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			/* translators: %s: directory path. */
			return sprintf( __( 'Could not create the directory %s.', 'wp-license' ), $dir );
		}
		if ( ! @copy( $source, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the failure is reported to the operator.
			/* translators: %s: file path. */
			return sprintf( __( 'Could not write %s. Check the file permissions or copy the file by hand.', 'wp-license' ), $target );
		}

		return '';
	}

	/**
	 * Delete the installed helper. Never deletes a file without the helper's marker.
	 *
	 * @param string|null $target Path (default: target()).
	 * @return string Empty string on success (also when nothing was installed), otherwise an error message.
	 */
	public static function remove( ?string $target = null ): string {
		$target = $target ?? self::target();
		if ( ! is_file( $target ) ) {
			return '';
		}
		if ( self::STATUS_FOREIGN === self::status( $target ) ) {
			/* translators: %s: file path. */
			return sprintf( __( '%s is not the WP License Captain helper; it was not deleted.', 'wp-license' ), $target );
		}
		if ( ! wp_delete_file_from_directory( $target, dirname( $target ) ) ) {
			/* translators: %s: file path. */
			return sprintf( __( 'Could not delete %s. Check the file permissions or delete the file by hand.', 'wp-license' ), $target );
		}

		return '';
	}
}
