<?php
/**
 * Streaming helpers of the administrator CSV exports (P-28).
 *
 * @package WPLC
 */

namespace WPLC\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes CSV rows straight to an output stream batch by batch, so an export
 * never holds the whole file (or every customer's user meta) in memory.
 *
 * Cells go through LicenseAdmin::csv_line(), which neutralizes spreadsheet
 * formulas. An export that stops at its row cap ends with a marker line and
 * leaves a notice for the next screen the administrator opens.
 */
final class CsvExport {

	/**
	 * Transient prefix of the "export was truncated" notice (per user).
	 */
	private const TRUNCATED_PREFIX = 'wplc_csv_truncated_';

	/**
	 * Send the download headers and open the output stream.
	 *
	 * @param string $filename File name without path.
	 * @return resource
	 */
	public static function open_download( string $filename ) {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		// Stream instead of buffering the whole file.
		while ( ob_get_level() > 0 ) {
			ob_end_flush();
		}

		$handle = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Output stream of a download, not a file.
		if ( false === $handle ) {
			wp_die( esc_html__( 'The export could not be started.', 'wp-license' ) );
		}

		return $handle;
	}

	/**
	 * Write one row.
	 *
	 * @param resource         $handle Output stream.
	 * @param array<int,mixed> $cells  Cells.
	 * @return void
	 */
	public static function write( $handle, array $cells ): void {
		fwrite( $handle, LicenseAdmin::csv_line( $cells ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Output stream of a download.
	}

	/**
	 * Flush a written batch to the client.
	 *
	 * @param resource $handle Output stream.
	 * @return void
	 */
	public static function flush_batch( $handle ): void {
		fflush( $handle );
		if ( 'php://output' === ( stream_get_meta_data( $handle )['uri'] ?? '' ) ) {
			flush();
		}
	}

	/**
	 * Marker line written as the last row when an export stopped at its cap.
	 *
	 * @param int $limit Row cap.
	 * @return array<int,string>
	 */
	public static function truncated_row( int $limit ): array {
		/* translators: %s: maximum number of rows. */
		return array( '# ' . sprintf( __( 'Export truncated after %s rows. Narrow the filters to export the rest.', 'wp-license' ), number_format_i18n( $limit ) ) );
	}

	/**
	 * Remember for the next screen that an export of this user hit its cap.
	 *
	 * @param int    $user_id User ID.
	 * @param string $kind    Export kind: licenses or audit.
	 * @param int    $limit   Row cap.
	 * @return void
	 */
	public static function remember_truncated( int $user_id, string $kind, int $limit ): void {
		if ( $user_id > 0 ) {
			set_transient( self::TRUNCATED_PREFIX . $user_id, array( 'kind' => sanitize_key( $kind ), 'limit' => $limit ), HOUR_IN_SECONDS );
		}
	}

	/**
	 * Render (once) the notice about a truncated export of this user.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public static function render_truncated_notice( int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}
		$stored = get_transient( self::TRUNCATED_PREFIX . $user_id );
		if ( ! is_array( $stored ) || empty( $stored['limit'] ) ) {
			return;
		}
		delete_transient( self::TRUNCATED_PREFIX . $user_id );
		$limit = (int) $stored['limit'];
		$text  = 'audit' === ( $stored['kind'] ?? '' )
			/* translators: %s: maximum number of rows. */
			? sprintf( _n( 'Your last audit log export stopped at %s entry. Narrow the filters (for example the date range) to export the rest.', 'Your last audit log export stopped at %s entries. Narrow the filters (for example the date range) to export the rest.', $limit, 'wp-license' ), number_format_i18n( $limit ) )
			/* translators: %s: maximum number of rows. */
			: sprintf( _n( 'Your last license export stopped at %s license. Narrow the filters to export the rest.', 'Your last license export stopped at %s licenses. Narrow the filters to export the rest.', $limit, 'wp-license' ), number_format_i18n( $limit ) );
		echo '<div class="notice notice-warning inline wplc-export-truncated"><p>' . esc_html( $text ) . '</p></div>';
	}

	/**
	 * One column (email or login) of users, without loading their meta (no cache_users()).
	 *
	 * @param int[]  $ids   User IDs.
	 * @param string $field user_email or user_login.
	 * @return array<int,string> User ID => value.
	 */
	public static function user_field( array $ids, string $field ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( ! $ids || ! in_array( $field, array( 'user_email', 'user_login' ), true ) ) {
			return array();
		}
		$query = new \WP_User_Query(
			array(
				'include'     => $ids,
				'fields'      => array( 'ID', $field ),
				'blog_id'     => 0,
				'number'      => count( $ids ),
				'count_total' => false,
				'orderby'     => 'ID',
			)
		);
		$out = array();
		foreach ( (array) $query->get_results() as $user ) {
			if ( is_object( $user ) && isset( $user->ID ) ) {
				$out[ (int) $user->ID ] = (string) ( $user->{$field} ?? '' );
			}
		}

		return $out;
	}
}
