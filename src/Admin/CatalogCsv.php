<?php
/**
 * Controlled CSV import/export for WooCommerce license plan mappings.
 *
 * @package WPLC
 */

namespace WPLC\Admin;

use WPLC\Catalog\Catalog;
use WPLC\Infrastructure\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Allows shop managers to transfer plan mappings without exporting secrets. */
final class CatalogCsv {
	const NONCE_ACTION = 'wplc_catalog_csv';
	const MAX_ROWS = 1000;

	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ) );
		add_action( 'admin_post_wplc_export_catalog_csv', array( self::class, 'export' ) );
		add_action( 'admin_post_wplc_import_catalog_csv', array( self::class, 'import' ) );
	}

	public static function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'License catalog CSV', 'wp-license' ),
			__( 'License catalog CSV', 'wp-license' ),
			'manage_woocommerce',
			'wplc-catalog-csv',
			array( self::class, 'render' )
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage license mappings.', 'wp-license' ), '', array( 'response' => 403 ) );
		}
		$imported = isset( $_GET['imported'] ) ? absint( $_GET['imported'] ) : null;
		$failed = isset( $_GET['failed'] ) ? absint( $_GET['failed'] ) : null;
		echo '<div class="wrap"><h1>' . esc_html__( 'License catalog CSV', 'wp-license' ) . '</h1>';
		if ( null !== $imported && null !== $failed ) {
			/* translators: 1: number of imported rows, 2: number of skipped or invalid rows. */
			echo '<div class="notice notice-info"><p>' . esc_html( sprintf( __( 'Imported: %1$d. Skipped or invalid: %2$d.', 'wp-license' ), $imported, $failed ) ) . '</p></div>';
		}
		echo '<p>' . esc_html__( 'CSV contains product IDs, variation IDs, SKU, plan keys, billing interval, site limit, support level, and entitlements. It never contains license keys or customer data. Import matches both IDs and SKU to avoid applying another shop’s mapping accidentally.', 'wp-license' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="wplc_export_catalog_csv">';
		wp_nonce_field( self::NONCE_ACTION );
		echo '<button class="button button-secondary" type="submit">' . esc_html__( 'Export mapped products', 'wp-license' ) . '</button></form>';
		echo '<hr><form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="wplc_import_catalog_csv">';
		wp_nonce_field( self::NONCE_ACTION );
		echo '<input type="file" name="catalog_csv" accept=".csv,text/csv" required>';
		echo '<button class="button button-primary" type="submit">' . esc_html__( 'Import mappings', 'wp-license' ) . '</button></form>';
		echo '<p>' . esc_html__( 'Imports are limited to 1,000 rows per file. Validate the exported IDs/SKUs in a staging shop before importing.', 'wp-license' ) . '</p></div>';
	}

	public static function export(): void {
		self::authorize();
		global $wpdb;
		$tables = Schema::table_names();
		$rows = $wpdb->get_results(
			"SELECT woocommerce_product_id,woocommerce_variation_id FROM {$tables['plans']} WHERE active = 1 ORDER BY woocommerce_product_id,woocommerce_variation_id",
			ARRAY_A
		);
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=wp-license-catalog.csv' );
		$output = fopen( 'php://output', 'w' );
		fputcsv( $output, self::headers() );
		foreach ( (array) $rows as $row ) {
			$product_id = absint( $row['woocommerce_product_id'] );
			$variation_id = absint( $row['woocommerce_variation_id'] );
			$product = wc_get_product( $variation_id ? $variation_id : $product_id );
			if ( ! $product ) {
				continue;
			}
			fputcsv(
				$output,
				array(
					$product_id,
					$variation_id,
					$product->get_sku( 'edit' ),
					$product->get_meta( '_wplc_product_key', true ),
					$product->get_meta( '_wplc_plan_key', true ),
					$product->get_meta( '_wplc_billing_interval', true ),
					$product->get_meta( '_wplc_live_site_limit', true ),
					$product->get_meta( '_wplc_support_level', true ),
					$product->get_meta( '_wplc_entitlements_json', true ),
				)
			);
		}
		fclose( $output );
		exit;
	}

	public static function import(): void {
		self::authorize();
		$file = $_FILES['catalog_csv'] ?? null;
		if ( ! is_array( $file ) || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			self::redirect_with_result( 0, 1 );
		}
		if ( filesize( $file['tmp_name'] ) > 2097152 ) {
			self::redirect_with_result( 0, 1 );
		}
		$handle = fopen( $file['tmp_name'], 'r' );
		if ( ! $handle ) {
			self::redirect_with_result( 0, 1 );
		}
		$headers = fgetcsv( $handle );
		if ( self::headers() !== $headers ) {
			fclose( $handle );
			self::redirect_with_result( 0, 1 );
		}

		$imported = 0;
		$failed = 0;
		$rows = 0;
		while ( false !== ( $values = fgetcsv( $handle ) ) ) {
			++$rows;
			if ( $rows > self::MAX_ROWS ) {
				++$failed;
				break;
			}
			if ( count( $values ) !== count( self::headers() ) ) {
				++$failed;
				continue;
			}
			$data = array_combine( self::headers(), $values );
			if ( ! is_array( $data ) || ! self::import_row( $data ) ) {
				++$failed;
				continue;
			}
			++$imported;
		}
		fclose( $handle );
		self::redirect_with_result( $imported, $failed );
	}

	private static function import_row( array $row ): bool {
		$product_id = absint( $row['product_id'] ?? 0 );
		$variation_id = absint( $row['variation_id'] ?? 0 );
		$target_id = $variation_id ?: $product_id;
		$product = $target_id ? wc_get_product( $target_id ) : false;
		if ( ! $product || ! current_user_can( 'edit_post', $target_id ) || (string) $product->get_sku( 'edit' ) !== (string) ( $row['sku'] ?? '' ) ) {
			return false;
		}
		if ( $variation_id && ( ! $product->is_type( 'variation' ) || (int) $product->get_parent_id() !== $product_id ) ) {
			return false;
		}
		if ( ! $variation_id && $product->get_parent_id() ) {
			return false;
		}

		$product_key = sanitize_key( (string) ( $row['product_key'] ?? '' ) );
		$plan_key = sanitize_key( (string) ( $row['plan_key'] ?? '' ) );
		$interval = sanitize_key( (string) ( $row['billing_interval'] ?? '' ) );
		$support = sanitize_key( (string) ( $row['support_level'] ?? '' ) );
		$site_limit = absint( $row['live_site_limit'] ?? 0 );
		$entitlements = json_decode( (string) ( $row['entitlements_json'] ?? '' ), true );
		if (
			'' === $product_key
			|| '' === $plan_key
			|| ! in_array( $interval, array( 'month', 'year' ), true )
			|| ! in_array( $support, array( 'community', 'standard', 'priority' ), true )
			|| $site_limit < 1
			|| $site_limit > 1000
			|| ! is_array( $entitlements )
			|| strlen( (string) ( $row['entitlements_json'] ?? '' ) ) > 4096
			|| ! self::valid_entitlements( $entitlements )
		) {
			return false;
		}

		$product->update_meta_data( '_wplc_is_license_product', 'yes' );
		$product->update_meta_data( '_wplc_product_key', $product_key );
		$product->update_meta_data( '_wplc_plan_key', $plan_key );
		$product->update_meta_data( '_wplc_billing_interval', $interval );
		$product->update_meta_data( '_wplc_live_site_limit', $site_limit );
		$product->update_meta_data( '_wplc_support_level', $support );
		$product->update_meta_data( '_wplc_entitlements_json', wp_json_encode( $entitlements ) );
		$product->save();
		return Catalog::save_mapping(
			$product_id,
			$variation_id,
			array(
				'product_key' => $product_key,
				'plan_key' => $plan_key,
				'billing_interval' => $interval,
				'live_site_limit' => $site_limit,
				'support_level' => $support,
				'entitlements' => $entitlements,
				'display_name' => $product->get_name(),
			)
		);
	}

	private static function valid_entitlements( array $entitlements ): bool {
		foreach ( $entitlements as $key => $value ) {
			if (
				! is_string( $key )
				|| ! preg_match( '/^[a-z][a-z0-9_.-]{1,119}$/', $key )
				|| ( ! is_bool( $value ) && ! is_int( $value ) && ! is_float( $value ) && ! is_string( $value ) )
			) {
				return false;
			}
		}
		return true;
	}

	private static function authorize(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage license mappings.', 'wp-license' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE_ACTION );
	}

	private static function redirect_with_result( int $imported, int $failed ): void {
		wp_safe_redirect(
			add_query_arg(
				array( 'page' => 'wplc-catalog-csv', 'imported' => $imported, 'failed' => $failed ),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private static function headers(): array {
		return array( 'product_id', 'variation_id', 'sku', 'product_key', 'plan_key', 'billing_interval', 'live_site_limit', 'support_level', 'entitlements_json' );
	}
}
