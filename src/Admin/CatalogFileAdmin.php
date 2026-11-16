<?php
/**
 * Admin screen for the catalog files (`wplc-catalog.json`) of the license products.
 *
 * @package WPLC
 */

namespace WPLC\Admin;

use WPLC\Catalog\CatalogFile;
use WPLC\Catalog\CatalogStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists the imported products with their entitlements, imports a catalog file, exports and
 * removes a product (T-150). Needs the capability for the license catalog.
 */
final class CatalogFileAdmin {
	const NONCE_ACTION = 'wplc_catalog_file';
	const PAGE         = 'wplc-catalog-files';

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ), 15 );
		add_action( 'admin_post_wplc_import_catalog_file', array( self::class, 'import' ) );
		add_action( 'admin_post_wplc_export_catalog_file', array( self::class, 'export' ) );
		add_action( 'admin_post_wplc_remove_catalog_product', array( self::class, 'remove' ) );
	}

	/**
	 * @return string
	 */
	private static function capability(): string {
		return Capabilities::cap( 'manage_catalog' );
	}

	/**
	 * Add the screen below the license menu.
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		add_submenu_page(
			Capabilities::menu_parent(),
			__( 'License catalog files', 'wp-license' ),
			__( 'License catalog files', 'wp-license' ),
			self::capability(),
			self::PAGE,
			array( self::class, 'render' )
		);
		LicenseAdmin::connect_page( self::PAGE, __( 'License catalog files', 'wp-license' ) );
	}

	/**
	 * Render the list of products and the import form.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to manage license mappings.', 'wp-license' ), '', array( 'response' => 403 ) );
		}

		echo '<div class="wrap wplc-catalog-files"><h1>' . esc_html__( 'License catalog files', 'wp-license' ) . '</h1>';
		self::render_result();
		echo '<p>' . esc_html__( 'A catalog file (wplc-catalog.json) describes one license product: its product key, the prefix of its entitlement keys, and every entitlement with its labels. The add-on of the product ships the file. The product editor offers the entitlements listed here as tick boxes; planned products and planned entitlements are not offered.', 'wp-license' ) . '</p>';

		$products = CatalogStore::all();
		if ( ! $products ) {
			echo '<p><strong>' . esc_html__( 'No catalog file has been imported yet. The product editor offers no entitlements until one is imported; entitlements can still be entered as JSON.', 'wp-license' ) . '</strong></p>';
		} else {
			echo '<table class="widefat striped"><thead><tr>';
			foreach ( array( __( 'Product key', 'wp-license' ), __( 'Name', 'wp-license' ), __( 'Prefix', 'wp-license' ), __( 'Status', 'wp-license' ), __( 'Entitlements', 'wp-license' ), __( 'Imported', 'wp-license' ), __( 'Actions', 'wp-license' ) ) as $heading ) {
				echo '<th scope="col">' . esc_html( $heading ) . '</th>';
			}
			echo '</tr></thead><tbody>';
			foreach ( $products as $product_key => $product ) {
				self::render_row( (string) $product_key, $product );
			}
			echo '</tbody></table>';
		}

		echo '<h2>' . esc_html__( 'Import a catalog file', 'wp-license' ) . '</h2>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="wplc_import_catalog_file">';
		self::nonce_field();
		echo '<p><label for="wplc-catalog-file">' . esc_html__( 'Catalog file (JSON)', 'wp-license' ) . '</label><br>';
		echo '<input type="file" id="wplc-catalog-file" name="catalog_file" accept=".json,application/json" required aria-describedby="wplc-catalog-file-help"></p>';
		echo '<p><button class="button button-primary" type="submit">' . esc_html__( 'Import catalog file', 'wp-license' ) . '</button></p></form>';
		echo '<p id="wplc-catalog-file-help">' . esc_html__( 'The import replaces the whole definition of the product named in the file. An entitlement that is no longer in the file leaves the list; plans and licenses that store it keep it. Importing the same file again changes nothing. Every import is recorded in the audit log.', 'wp-license' ) . '</p></div>';
	}

	/**
	 * One product of the list.
	 *
	 * @param string              $product_key Product key.
	 * @param array<string,mixed> $product     Stored product.
	 * @return void
	 */
	private static function render_row( string $product_key, array $product ): void {
		$statuses = array(
			CatalogFile::STATUS_AVAILABLE => __( 'Available', 'wp-license' ),
			CatalogFile::STATUS_PLANNED   => __( 'Planned', 'wp-license' ),
		);
		$sources  = array(
			CatalogStore::SOURCE_BUNDLED => __( 'shipped with the plugin', 'wp-license' ),
			CatalogStore::SOURCE_ADMIN   => __( 'uploaded', 'wp-license' ),
			CatalogStore::SOURCE_CLI     => __( 'command line', 'wp-license' ),
			CatalogStore::SOURCE_RELEASE => __( 'release package', 'wp-license' ),
		);
		$status   = (string) $product['status'];
		$source   = (string) ( $product['source'] ?? '' );

		echo '<tr><td><code>' . esc_html( $product_key ) . '</code></td>';
		echo '<td>' . esc_html( CatalogStore::text( $product['name'] ?? array() ) ) . '</td>';
		echo '<td><code>' . esc_html( (string) $product['prefix'] ) . '.</code></td>';
		echo '<td>' . esc_html( $statuses[ $status ] ?? $status ) . '</td><td>';
		$entitlements = (array) $product['entitlements'];
		if ( ! $entitlements ) {
			echo '—';
		} else {
			echo '<ul style="margin:0">';
			foreach ( $entitlements as $entitlement ) {
				$planned = CatalogFile::STATUS_PLANNED === (string) $entitlement['status'] || CatalogFile::STATUS_PLANNED === $status;
				echo '<li>' . esc_html( CatalogStore::text( $entitlement['label'] ?? array() ) ) . ' <code>' . esc_html( (string) $entitlement['key'] ) . '</code>';
				if ( $planned ) {
					echo ' <em>(' . esc_html__( 'planned, not offered', 'wp-license' ) . ')</em>';
				}
				echo '</li>';
			}
			echo '</ul>';
		}
		echo '</td><td>' . esc_html( Format::date( (string) ( $product['imported_at'] ?? '' ), true ) );
		echo '<br>' . esc_html( $sources[ $source ] ?? $source ) . '</td><td>';

		echo '<form method="post" class="wplc-inline-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="wplc_export_catalog_file">';
		echo '<input type="hidden" name="product_key" value="' . esc_attr( $product_key ) . '">';
		self::nonce_field();
		echo '<button class="button button-secondary" type="submit">' . esc_html__( 'Export', 'wp-license' ) . '</button></form> ';

		// The confirmation is part of the form and checked by the server: this screen loads no script,
		// and removing must not depend on one.
		$confirm_id = 'wplc-catalog-remove-' . $product_key;
		echo '<form method="post" class="wplc-inline-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="wplc_remove_catalog_product">';
		echo '<input type="hidden" name="product_key" value="' . esc_attr( $product_key ) . '">';
		self::nonce_field();
		echo '<label for="' . esc_attr( $confirm_id ) . '"><input type="checkbox" id="' . esc_attr( $confirm_id ) . '" name="confirm_remove" value="1" required> ';
		echo esc_html__( 'Remove from the catalog (plans and licenses keep their entitlements)', 'wp-license' ) . '</label> ';
		echo '<button class="button button-link-delete" type="submit">' . esc_html__( 'Remove', 'wp-license' ) . '</button></form>';
		echo '</td></tr>';
	}

	/**
	 * Print the stored result of the last action of this user, once.
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

		$errors = isset( $result['errors'] ) && is_array( $result['errors'] ) ? $result['errors'] : array();
		echo '<div class="notice ' . ( $errors ? 'notice-error' : 'notice-success' ) . '"><p>' . esc_html( self::result_message( $result ) ) . '</p>';
		if ( $errors ) {
			echo '<ul class="ul-disc">';
			foreach ( array_slice( $errors, 0, 50 ) as $error ) {
				$path = (string) ( $error['path'] ?? '' );
				echo '<li>' . ( '' !== $path ? '<code>' . esc_html( $path ) . '</code> ' : '' ) . esc_html( self::error_message( (string) ( $error['code'] ?? '' ) ) ) . '</li>';
			}
			echo '</ul>';
		}
		echo '</div>';
	}

	/**
	 * Sentence for the result of an action.
	 *
	 * @param array<string,mixed> $result Stored result.
	 * @return string
	 */
	public static function result_message( array $result ): string {
		$product_key = (string) ( $result['product_key'] ?? '' );
		switch ( (string) ( $result['result'] ?? '' ) ) {
			case CatalogStore::RESULT_CREATED:
				/* translators: 1: product key, 2: number of entitlements. */
				return sprintf( __( 'Product %1$s was added to the catalog with %2$d entitlements.', 'wp-license' ), $product_key, count( (array) ( $result['added'] ?? array() ) ) );
			case CatalogStore::RESULT_UPDATED:
				/* translators: 1: product key, 2: number of added entitlements, 3: number of removed entitlements, 4: number of changed entitlements. */
				return sprintf( __( 'Product %1$s was updated: %2$d entitlements added, %3$d removed, %4$d changed.', 'wp-license' ), $product_key, count( (array) ( $result['added'] ?? array() ) ), count( (array) ( $result['removed'] ?? array() ) ), count( (array) ( $result['changed'] ?? array() ) ) );
			case CatalogStore::RESULT_UNCHANGED:
				/* translators: %s: product key. */
				return sprintf( __( 'Product %s is already in the catalog exactly as in this file. Nothing was changed.', 'wp-license' ), $product_key );
			case 'removed':
				/* translators: %s: product key. */
				return sprintf( __( 'Product %s was removed from the catalog.', 'wp-license' ), $product_key );
		}

		return empty( $result['errors'] ) || 'confirmation_missing' !== (string) ( $result['errors'][0]['code'] ?? '' ) ? __( 'The catalog file was not imported.', 'wp-license' ) : __( 'The catalog was not changed.', 'wp-license' );
	}

	/**
	 * Translated message of a validation error.
	 *
	 * @param string $code Error code of CatalogFile or CatalogStore.
	 * @return string
	 */
	public static function error_message( string $code ): string {
		$messages = array(
			'upload_failed'             => __( 'The file could not be uploaded.', 'wp-license' ),
			'file_too_large'            => __( 'The file is larger than 256 KB.', 'wp-license' ),
			'not_a_json_object'         => __( 'The file is not a JSON object.', 'wp-license' ),
			'format_missing'            => __( 'The field "format" is missing or not a whole number.', 'wp-license' ),
			'format_unsupported'        => __( 'This version reads catalog files of format 1 only.', 'wp-license' ),
			'product_key_invalid'       => __( 'The product key must consist of lowercase letters, digits and hyphens (2 to 100 characters).', 'wp-license' ),
			'prefix_missing'            => __( 'The field "prefix" is required when the file lists no entitlement.', 'wp-license' ),
			'prefix_invalid'            => __( 'The prefix must start with a lowercase letter and contain lowercase letters, digits, hyphens or underscores only.', 'wp-license' ),
			'prefix_in_use'             => __( 'Another product in the catalog already uses this prefix.', 'wp-license' ),
			'status_invalid'            => __( 'The status must be "available" or "planned".', 'wp-license' ),
			'entitlements_missing'      => __( 'The field "entitlements" must be a list (it may be empty).', 'wp-license' ),
			'too_many_entitlements'     => __( 'The file lists more than 500 entitlements.', 'wp-license' ),
			'entitlement_not_an_object' => __( 'An entitlement must be an object.', 'wp-license' ),
			'key_invalid'               => __( 'The entitlement key is missing or contains characters other than letters, digits, dots, hyphens and underscores.', 'wp-license' ),
			'key_without_prefix'        => __( 'The entitlement key does not start with the prefix of the product and a dot.', 'wp-license' ),
			'key_duplicate'             => __( 'The entitlement key is listed twice.', 'wp-license' ),
			'text_missing'              => __( 'A name or label needs at least the English text ("en").', 'wp-license' ),
			'text_invalid'              => __( 'A text is empty, not a string, or contains markup or control characters.', 'wp-license' ),
			'text_too_long'             => __( 'A text is too long (name and label: 190 characters, description: 1000).', 'wp-license' ),
			'locale_invalid'            => __( 'A locale must be "en" or a WordPress locale such as "de_DE" or "de_DE_formal".', 'wp-license' ),
			'not_in_catalog'            => __( 'This product is not in the catalog.', 'wp-license' ),
			'confirmation_missing'      => __( 'Nothing was removed: tick the box next to "Remove" to confirm.', 'wp-license' ),
		);

		return $messages[ $code ] ?? $code;
	}

	/**
	 * Handle the upload.
	 *
	 * @return void
	 */
	public static function import(): void {
		self::authorize();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- nonce verified in authorize(); only the temporary path is used, after is_uploaded_file().
		$file = isset( $_FILES['catalog_file'] ) && is_array( $_FILES['catalog_file'] ) ? $_FILES['catalog_file'] : null;
		if ( ! $file || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			self::finish( self::failure( 'upload_failed' ) );
		}

		self::finish( self::import_path( (string) $file['tmp_name'], (int) get_current_user_id() ) );
	}

	/**
	 * Import a catalog file from disk on behalf of an administrator.
	 *
	 * @param string $path          Readable file.
	 * @param int    $actor_user_id Acting user.
	 * @return array<string,mixed> Result of CatalogStore::import_json().
	 */
	public static function import_path( string $path, int $actor_user_id ): array {
		if ( ! is_readable( $path ) ) {
			return self::failure( 'upload_failed' );
		}
		if ( (int) filesize( $path ) > CatalogFile::MAX_BYTES ) {
			return self::failure( 'file_too_large' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local temporary file of the upload.
		return CatalogStore::import_json( (string) file_get_contents( $path ), CatalogStore::SOURCE_ADMIN, $actor_user_id );
	}

	/**
	 * Download the catalog file of one product.
	 *
	 * @return void
	 */
	public static function export(): void {
		self::authorize();
		$product = CatalogStore::get( self::posted_product_key() );
		if ( ! $product ) {
			self::finish( self::failure( 'not_in_catalog' ) );
			return;
		}
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . CatalogFile::FILE_NAME );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download, not HTML.
		echo CatalogFile::encode( $product );
		exit;
	}

	/**
	 * Remove one product from the catalog.
	 *
	 * @return void
	 */
	public static function remove(): void {
		self::authorize();
		$product_key = self::posted_product_key();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in authorize().
		if ( ! isset( $_POST['confirm_remove'] ) || '1' !== sanitize_text_field( wp_unslash( (string) $_POST['confirm_remove'] ) ) ) {
			self::finish( self::failure( 'confirmation_missing' ) );
		}
		if ( ! CatalogStore::remove( $product_key, (int) get_current_user_id(), CatalogStore::SOURCE_ADMIN ) ) {
			self::finish( self::failure( 'not_in_catalog' ) );
		}
		self::finish(
			array(
				'ok'          => true,
				'errors'      => array(),
				'result'      => 'removed',
				'product_key' => $product_key,
			)
		);
	}

	/**
	 * @return string Product key from the request, or an empty string.
	 */
	private static function posted_product_key(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in authorize().
		$value = isset( $_POST['product_key'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['product_key'] ) ) : '';

		return 1 === preg_match( CatalogFile::PRODUCT_KEY_PATTERN, $value ) ? $value : '';
	}

	/**
	 * @param string $code Error code.
	 * @return array<string,mixed>
	 */
	private static function failure( string $code ): array {
		return array(
			'ok'          => false,
			'errors'      => array(
				array(
					'code' => $code,
					'path' => '',
				),
			),
			'result'      => '',
			'product_key' => '',
		);
	}

	/**
	 * Nonce field shared by the forms of this screen.
	 *
	 * @return void
	 */
	private static function nonce_field(): void {
		echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( self::NONCE_ACTION ) ) . '">';
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
	 * Transient that carries the result of the current user to the next page view.
	 *
	 * @return string
	 */
	private static function result_key(): string {
		return 'wplc_catalog_file_result_' . (int) get_current_user_id();
	}

	/**
	 * Store the result and return to the screen.
	 *
	 * @param array<string,mixed> $result Result.
	 * @return void
	 */
	private static function finish( array $result ): void {
		set_transient( self::result_key(), $result, 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
