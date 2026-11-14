<?php
/**
 * Controlled CSV import/export for WooCommerce license plan mappings.
 *
 * @package WPLC
 */

namespace WPLC\Admin;

use WPLC\Catalog\Catalog;
use WPLC\Infrastructure\Database\Schema;
use WPLC\License\AuditLog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lets users who may manage the license catalog transfer plan mappings without exporting secrets.
 *
 * The capability for the license catalog is the only permission the import
 * needs; it is the same one the product editor asks for before it shows or
 * saves the license fields.
 */
final class CatalogCsv {
	const NONCE_ACTION = 'wplc_catalog_csv';
	const MAX_ROWS     = 1000;

	/**
	 * Largest accepted upload in bytes.
	 */
	const MAX_BYTES = 2097152;

	/**
	 * Row errors kept for the result screen.
	 */
	const MAX_REPORTED_ERRORS = 100;

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ), 15 );
		add_action( 'admin_post_wplc_export_catalog_csv', array( self::class, 'export' ) );
		add_action( 'admin_post_wplc_import_catalog_csv', array( self::class, 'import' ) );
	}

	/**
	 * Capability needed to read and write the license catalog.
	 *
	 * @return string
	 */
	private static function capability(): string {
		return Capabilities::cap( 'manage_catalog' );
	}

	/**
	 * Add the screen below WooCommerce.
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		add_submenu_page(
			Capabilities::menu_parent(),
			__( 'License catalog CSV', 'wp-license' ),
			__( 'License catalog CSV', 'wp-license' ),
			self::capability(),
			'wplc-catalog-csv',
			array( self::class, 'render' )
		);
		LicenseAdmin::connect_page( 'wplc-catalog-csv', __( 'License catalog CSV', 'wp-license' ) );
	}

	/**
	 * Render the export and import forms and the result of the last import.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to manage license mappings.', 'wp-license' ), '', array( 'response' => 403 ) );
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'License catalog CSV', 'wp-license' ) . '</h1>';
		self::render_result();
		echo '<p>' . esc_html__( 'CSV contains product IDs, variation IDs, SKU, plan keys, billing interval, site limit, support level, and entitlements. It never contains license keys or customer data. Import matches both IDs and SKU to avoid applying another shop’s mapping accidentally.', 'wp-license' ) . '</p>';

		echo '<h2>' . esc_html__( 'Export', 'wp-license' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="wplc_export_catalog_csv">';
		self::nonce_field();
		echo '<button class="button button-secondary" type="submit">' . esc_html__( 'Export mapped products', 'wp-license' ) . '</button></form>';

		echo '<h2>' . esc_html__( 'Import', 'wp-license' ) . '</h2>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="wplc_import_catalog_csv">';
		self::nonce_field();
		echo '<p><label for="wplc-catalog-csv-file">' . esc_html__( 'CSV file', 'wp-license' ) . '</label><br>';
		echo '<input type="file" id="wplc-catalog-csv-file" name="catalog_csv" accept=".csv,text/csv" required aria-describedby="wplc-catalog-csv-help"></p>';
		echo '<p><button class="button button-primary" type="submit">' . esc_html__( 'Import mappings', 'wp-license' ) . '</button></p></form>';
		echo '<p id="wplc-catalog-csv-help">' . esc_html__( 'Imports are limited to 1,000 rows and 2 MB per file. Comma or semicolon separated files are accepted (a file saved by Excel works). Product and plan keys may only contain lowercase letters, digits and hyphens. Validate the exported IDs/SKUs in a staging shop before importing.', 'wp-license' ) . '</p></div>';
	}

	/**
	 * Print the stored result of the last import of this user, once.
	 *
	 * @return void
	 */
	private static function render_result(): void {
		$key    = self::result_key();
		$result = get_transient( $key );
		if ( ! is_array( $result ) ) {
			return;
		}
		delete_transient( $key );

		$failed = (int) ( $result['failed'] ?? 0 );
		echo '<div class="notice ' . ( $failed || ! empty( $result['error'] ) ? 'notice-warning' : 'notice-success' ) . '"><p>';
		/* translators: 1: number of imported rows, 2: number of rejected rows. */
		echo esc_html( sprintf( __( 'Imported: %1$d. Skipped or invalid: %2$d.', 'wp-license' ), (int) ( $result['imported'] ?? 0 ), $failed ) );
		echo '</p>';
		if ( ! empty( $result['error'] ) ) {
			echo '<p><strong>' . esc_html( self::reason_message( (string) $result['error'] ) ) . '</strong></p>';
		}
		if ( ! empty( $result['stopped_at'] ) ) {
			echo '<p><strong>';
			/* translators: %d: line number in the CSV file. */
			echo esc_html( sprintf( __( 'The import ran out of time and stopped before line %d. Everything before that line is saved. Import the same file again to finish; rows that are already imported are simply updated.', 'wp-license' ), (int) $result['stopped_at'] ) );
			echo '</strong></p>';
		}
		$errors = isset( $result['errors'] ) && is_array( $result['errors'] ) ? $result['errors'] : array();
		if ( $errors ) {
			echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th scope="col">' . esc_html__( 'Line', 'wp-license' ) . '</th><th scope="col">' . esc_html__( 'Reason', 'wp-license' ) . '</th></tr></thead><tbody>';
			foreach ( $errors as $error ) {
				echo '<tr><td>' . esc_html( (string) (int) ( $error['line'] ?? 0 ) ) . '</td><td>' . esc_html( self::reason_message( (string) ( $error['reason'] ?? '' ) ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
			if ( $failed > count( $errors ) ) {
				/* translators: %d: number of further rejected rows. */
				echo '<p>' . esc_html( sprintf( __( '… and %d more rejected rows.', 'wp-license' ), $failed - count( $errors ) ) ) . '</p>';
			}
		}
		echo '</div>';
	}

	/**
	 * Stream the export.
	 *
	 * @return void
	 */
	public static function export(): void {
		self::authorize();
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=wp-license-catalog.csv' );
		$output = fopen( 'php://output', 'w' );
		fputcsv( $output, self::headers(), ',', '"', '\\' );
		foreach ( self::export_rows() as $row ) {
			fputcsv( $output, $row, ',', '"', '\\' );
		}
		fclose( $output );
		exit;
	}

	/**
	 * Rows of the export, read from the license catalog (not from product meta).
	 *
	 * @return array<int,array<int,string>>
	 */
	public static function export_rows(): array {
		global $wpdb;

		$tables = Schema::table_names();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name comes from Schema::table_names().
		$plans = $wpdb->get_results( "SELECT * FROM {$tables['plans']} WHERE active = 1 ORDER BY woocommerce_product_id, woocommerce_variation_id", ARRAY_A );
		$rows  = array();
		foreach ( (array) $plans as $plan ) {
			$product_id   = absint( $plan['woocommerce_product_id'] );
			$variation_id = absint( $plan['woocommerce_variation_id'] );
			$product      = wc_get_product( $variation_id ? $variation_id : $product_id );
			if ( ! $product ) {
				continue;
			}
			$rows[] = array_map(
				array( self::class, 'escape_cell' ),
				array(
					(string) $product_id,
					(string) $variation_id,
					(string) $product->get_sku( 'edit' ),
					(string) $plan['product_key'],
					(string) $plan['plan_key'],
					(string) $plan['billing_interval'],
					(string) $plan['live_site_limit'],
					(string) $plan['support_level'],
					(string) $plan['entitlements_json'],
				)
			);
		}

		return $rows;
	}

	/**
	 * Stop spreadsheet programs from running a cell as a formula: a value that
	 * starts with =, +, -, @, tab or carriage return gets a leading apostrophe.
	 *
	 * @param string $value Cell value.
	 * @return string
	 */
	public static function escape_cell( string $value ): string {
		return '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) ? "'" . $value : $value;
	}

	/**
	 * Undo escape_cell() for a value read from a file.
	 *
	 * @param string $value Cell value.
	 * @return string
	 */
	public static function unescape_cell( string $value ): string {
		return strlen( $value ) > 1 && "'" === $value[0] && false !== strpos( "=+-@\t\r", $value[1] ) ? substr( $value, 1 ) : $value;
	}

	/**
	 * Handle the upload.
	 *
	 * @return void
	 */
	public static function import(): void {
		self::authorize();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- nonce verified in authorize(); only the temporary path is used, after is_uploaded_file().
		$file = isset( $_FILES['catalog_csv'] ) && is_array( $_FILES['catalog_csv'] ) ? $_FILES['catalog_csv'] : null;
		if ( ! $file || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			$code = $file && in_array( (int) ( $file['error'] ?? 0 ), array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ? 'file_too_large' : 'upload_failed';
			self::finish( self::empty_result( $code ) );
		}

		self::finish( self::import_file( (string) $file['tmp_name'] ) );
	}

	/**
	 * Import a CSV file from disk.
	 *
	 * Accepts comma or semicolon as separator and a UTF-8 byte order mark, as
	 * written by Excel. Every rejected row is reported with its line and reason.
	 * The work stops before PHP's time limit; rows are independent, so the same
	 * file can simply be imported again.
	 *
	 * @param string $path Readable file.
	 * @return array{imported:int,failed:int,errors:array<int,array{line:int,reason:string}>,error:string,stopped_at:int}
	 */
	public static function import_file( string $path ): array {
		$result = self::empty_result( '' );

		if ( ! is_readable( $path ) ) {
			return self::empty_result( 'upload_failed' );
		}
		if ( filesize( $path ) > self::MAX_BYTES ) {
			return self::empty_result( 'file_too_large' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- reads the uploaded temporary file as a stream.
		$handle = fopen( $path, 'r' );
		if ( ! $handle ) {
			return self::empty_result( 'upload_failed' );
		}

		$first = fgets( $handle );
		$first = false === $first ? '' : (string) $first;
		if ( 0 === strncmp( $first, "\xEF\xBB\xBF", 3 ) ) {
			$first = substr( $first, 3 );
		}
		$delimiter = substr_count( $first, ';' ) > substr_count( $first, ',' ) ? ';' : ',';
		$headers   = array_map(
			static function ( $header ): string {
				return strtolower( trim( (string) $header ) );
			},
			str_getcsv( rtrim( $first, "\r\n" ), $delimiter, '"', '\\' )
		);
		if ( self::headers() !== $headers ) {
			fclose( $handle );
			return self::empty_result( 'bad_header' );
		}

		if ( function_exists( 'wc_set_time_limit' ) ) {
			wc_set_time_limit( 120 );
		}
		/**
		 * Filters how many seconds one catalog CSV import may run before it stops and reports where.
		 *
		 * @since 0.2.0
		 *
		 * @param int $seconds Default 90.
		 */
		$deadline = microtime( true ) + max( 0, (int) apply_filters( 'wplc_catalog_csv_time_budget', 90 ) );

		$line = 1;
		$rows = 0;
		// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- the usual fgetcsv() loop.
		while ( false !== ( $values = fgetcsv( $handle, 0, $delimiter, '"', '\\' ) ) ) {
			++$line;
			if ( array( null ) === $values ) {
				continue; // Blank line.
			}
			++$rows;
			if ( $rows > self::MAX_ROWS ) {
				self::add_error( $result, $line, 'too_many_rows' );
				break;
			}
			if ( microtime( true ) > $deadline ) {
				$result['stopped_at'] = $line;
				break;
			}
			if ( count( $values ) !== count( $headers ) ) {
				self::add_error( $result, $line, 'column_count' );
				continue;
			}
			$reason = self::import_row( (array) array_combine( $headers, $values ) );
			if ( '' !== $reason ) {
				self::add_error( $result, $line, $reason );
				continue;
			}
			++$result['imported'];
		}
		fclose( $handle );

		AuditLog::record(
			'catalog_csv_imported',
			'catalog',
			0,
			'',
			null,
			array(
				'imported' => $result['imported'],
				'failed' => $result['failed'],
				'stopped_at' => $result['stopped_at'],
			),
			(int) get_current_user_id()
		);

		return $result;
	}

	/**
	 * Result skeleton.
	 *
	 * @param string $error File-level error code, or empty.
	 * @return array{imported:int,failed:int,errors:array<int,array{line:int,reason:string}>,error:string,stopped_at:int}
	 */
	private static function empty_result( string $error ): array {
		return array(
			'imported' => 0,
			'failed' => '' === $error ? 0 : 1,
			'errors' => array(),
			'error' => $error,
			'stopped_at' => 0,
		);
	}

	/**
	 * Count a rejected row and keep its reason for the result screen.
	 *
	 * @param array<string,mixed> $result Result, changed in place.
	 * @param int                 $line   Line in the file.
	 * @param string              $reason Reason code.
	 * @return void
	 */
	private static function add_error( array &$result, int $line, string $reason ): void {
		++$result['failed'];
		if ( count( $result['errors'] ) < self::MAX_REPORTED_ERRORS ) {
			$result['errors'][] = array(
				'line' => $line,
				'reason' => $reason,
			);
		}
	}

	/**
	 * Import one row.
	 *
	 * @param array<string,mixed> $row Row keyed by column name.
	 * @return string Empty on success, otherwise a reason code (see reason_message()).
	 */
	private static function import_row( array $row ): string {
		$product_id   = absint( $row['product_id'] ?? 0 );
		$variation_id = absint( $row['variation_id'] ?? 0 );
		$target_id    = $variation_id ? $variation_id : $product_id;
		$product      = $target_id ? wc_get_product( $target_id ) : false;
		if ( ! $product_id || ! $product ) {
			return 'product_not_found';
		}
		if ( (string) $product->get_sku( 'edit' ) !== self::unescape_cell( (string) ( $row['sku'] ?? '' ) ) ) {
			return 'sku_mismatch';
		}
		if ( $variation_id && ( ! $product->is_type( 'variation' ) || (int) $product->get_parent_id() !== $product_id ) ) {
			return 'not_a_variation_of_product';
		}
		if ( ! $variation_id && $product->get_parent_id() ) {
			return 'variation_without_variation_id';
		}

		// The same rules as the product editor: keys are validated, never rewritten.
		$product_key  = Catalog::normalize_key( self::unescape_cell( (string) ( $row['product_key'] ?? '' ) ) );
		$plan_key     = Catalog::normalize_key( self::unescape_cell( (string) ( $row['plan_key'] ?? '' ) ) );
		$interval     = Catalog::normalize_key( $row['billing_interval'] ?? '' );
		$support      = Catalog::normalize_key( $row['support_level'] ?? '' );
		$limit_raw    = trim( (string) ( $row['live_site_limit'] ?? '' ) );
		$site_limit   = ctype_digit( $limit_raw ) ? (int) $limit_raw : 0;
		$json         = trim( self::unescape_cell( (string) ( $row['entitlements_json'] ?? '' ) ) );
		$entitlements = '' === $json ? array() : json_decode( $json, true );

		if ( ! Catalog::is_valid_product_key( $product_key ) ) {
			return 'invalid_product_key';
		}
		if ( ! Catalog::is_valid_plan_key( $plan_key ) ) {
			return 'invalid_plan_key';
		}
		if ( ! in_array( $interval, array( 'month', 'year' ), true ) ) {
			return 'invalid_interval';
		}
		if ( ! in_array( $support, array( 'community', 'standard', 'priority' ), true ) ) {
			return 'invalid_support_level';
		}
		if ( $site_limit < 1 || $site_limit > 1000 ) {
			return 'invalid_site_limit';
		}
		if ( ! is_array( $entitlements ) || strlen( $json ) > 4096 || ! self::valid_entitlements( $entitlements ) ) {
			return 'invalid_entitlements';
		}

		// Somebody has this product open in the editor: saving that form would silently undo the import.
		if ( ! function_exists( 'wp_check_post_lock' ) && is_readable( ABSPATH . 'wp-admin/includes/post.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}
		if ( function_exists( 'wp_check_post_lock' ) && wp_check_post_lock( $product_id ) ) {
			return 'product_locked';
		}

		$saved = Catalog::save_mapping(
			$product_id,
			$variation_id,
			array(
				'product_key' => $product_key,
				'plan_key' => $plan_key,
				'billing_interval' => $interval,
				'live_site_limit' => $site_limit,
				'support_level' => $support,
				'entitlements' => $entitlements,
			)
		);
		if ( ! $saved ) {
			return Catalog::last_error();
		}

		// The product meta mirrors the plan for the product editor form.
		$product->update_meta_data( '_wplc_is_license_product', 'yes' );
		$product->update_meta_data( '_wplc_product_key', $product_key );
		$product->update_meta_data( '_wplc_plan_key', $plan_key );
		$product->update_meta_data( '_wplc_billing_interval', $interval );
		$product->update_meta_data( '_wplc_live_site_limit', $site_limit );
		$product->update_meta_data( '_wplc_support_level', $support );
		$product->update_meta_data( '_wplc_entitlements_json', wp_json_encode( $entitlements ) );
		$product->save();

		return '';
	}

	/**
	 * Explain a reason code.
	 *
	 * @param string $reason Reason code.
	 * @return string
	 */
	public static function reason_message( string $reason ): string {
		switch ( $reason ) {
			case 'upload_failed':
				return __( 'The file could not be uploaded or read.', 'wp-license' );
			case 'file_too_large':
				return __( 'The file is larger than 2 MB.', 'wp-license' );
			case 'bad_header':
				return __( 'The first line does not contain the expected column names. Export a file first and keep its header line unchanged.', 'wp-license' );
			case 'too_many_rows':
				return __( 'The file has more than 1,000 rows. The remaining rows were not imported.', 'wp-license' );
			case 'column_count':
				return __( 'The line does not have nine columns.', 'wp-license' );
			case 'sku_mismatch':
				return __( 'The SKU does not match the SKU of this product in the shop.', 'wp-license' );
			case 'not_a_variation_of_product':
				return __( 'The variation ID is not a variation of the product ID.', 'wp-license' );
			case 'variation_without_variation_id':
				return __( 'The product ID is a variation. Put the parent product in product_id and the variation in variation_id.', 'wp-license' );
			case 'invalid_support_level':
				return __( 'The support level must be community, standard or priority.', 'wp-license' );
			case 'invalid_site_limit':
				return __( 'The live site limit must be a whole number from 1 to 1000.', 'wp-license' );
			case 'invalid_entitlements':
				return __( 'The entitlements must be a JSON object with simple values (text, numbers, true/false) and at most 4096 characters.', 'wp-license' );
			case 'product_locked':
				return __( 'The product is being edited by another user right now. Close the product editor and import the row again.', 'wp-license' );
			default:
				return Catalog::error_message( $reason );
		}
	}

	/**
	 * Validate entitlement keys and value types.
	 *
	 * @param array<mixed,mixed> $entitlements Decoded JSON object.
	 * @return bool
	 */
	private static function valid_entitlements( array $entitlements ): bool {
		foreach ( $entitlements as $key => $value ) {
			if (
				! is_string( $key )
				|| ! preg_match( '/^[a-z][a-z0-9_.-]{1,119}$/', $key )
				|| ( ! is_bool( $value ) && ! is_int( $value ) && ! is_float( $value ) && ! is_string( $value ) )
				|| ( is_float( $value ) && ! is_finite( $value ) )
			) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Print the nonce without an `id` attribute: the screen has two forms, and
	 * wp_nonce_field() would give both hidden fields the ID `_wpnonce`.
	 *
	 * @return void
	 */
	private static function nonce_field(): void {
		echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( self::NONCE_ACTION ) ) . '">';
		wp_referer_field();
	}

	/**
	 * Check capability and nonce.
	 *
	 * @return void
	 */
	private static function authorize(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to manage license mappings.', 'wp-license' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE_ACTION );
	}

	/**
	 * Transient that carries the import result of the current user to the next page view.
	 *
	 * @return string
	 */
	private static function result_key(): string {
		return 'wplc_catalog_csv_result_' . (int) get_current_user_id();
	}

	/**
	 * Store the result and return to the screen.
	 *
	 * @param array<string,mixed> $result Import result.
	 * @return void
	 */
	private static function finish( array $result ): void {
		set_transient( self::result_key(), $result, 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => 'wplc-catalog-csv',
					'imported' => (int) $result['imported'],
					'failed' => (int) $result['failed'],
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Column names, in file order.
	 *
	 * @return string[]
	 */
	private static function headers(): array {
		return array( 'product_id', 'variation_id', 'sku', 'product_key', 'plan_key', 'billing_interval', 'live_site_limit', 'support_level', 'entitlements_json' );
	}
}
