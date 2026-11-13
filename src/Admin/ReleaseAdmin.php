<?php
/**
 * Admin page for product releases.
 *
 * @package WPLC
 */

namespace WPLC\Admin;

use WPLC\Update\LocalPackageStorage;
use WPLC\Update\ReleaseRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce -> License releases: list, add, publish, withdraw, import.
 */
final class ReleaseAdmin {
	const PAGE = 'wplc-releases';
	const NONCE_CREATE = 'wplc_release_create';
	const NONCE_IMPORT = 'wplc_release_import';
	const MAX_MANIFEST_BYTES = 1048576;

	/**
	 * Register menu and form handlers.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ) );
		add_action( 'admin_post_wplc_release_create', array( self::class, 'handle_create' ) );
		add_action( 'admin_post_wplc_release_import', array( self::class, 'handle_import' ) );
		add_action( 'admin_post_wplc_release_publish', array( self::class, 'handle_publish' ) );
		add_action( 'admin_post_wplc_release_withdraw', array( self::class, 'handle_withdraw' ) );
		add_action( 'admin_post_wplc_release_delete', array( self::class, 'handle_delete' ) );
	}

	/**
	 * Add the submenu page.
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'License releases', 'wp-license' ),
			__( 'License releases', 'wp-license' ),
			'manage_woocommerce',
			self::PAGE,
			array( self::class, 'render' )
		);
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage releases.', 'wp-license' ), '', array( 'response' => 403 ) );
		}
		$products = ReleaseRegistry::products();
		$releases = ReleaseRegistry::all_by_product();

		echo '<div class="wrap"><h1>' . esc_html__( 'License releases', 'wp-license' ) . '</h1>';
		self::render_notice();
		echo '<p>' . esc_html__( 'Licensed installations receive the highest published version of a product as an update, and customers can download it from their account. Version, package and checksum are frozen once a release is published: withdraw it and publish a new version to correct a package.', 'wp-license' ) . '</p>';

		if ( ! $releases ) {
			echo '<p><em>' . esc_html__( 'No releases yet.', 'wp-license' ) . '</em></p>';
		}
		foreach ( $releases as $product_key => $rows ) {
			$label = isset( $products[ $product_key ] ) ? $products[ $product_key ] . ' (' . $product_key . ')' : $product_key;
			$current = ReleaseRegistry::select_latest(
				array_values(
					array_filter(
						$rows,
						static function ( $row ) {
							return ReleaseRegistry::DEFAULT_CHANNEL === $row['channel'];
						}
					)
				)
			);
			echo '<h2>' . esc_html( $label ) . '</h2>';
			echo '<table class="widefat striped"><thead><tr>';
			foreach ( array(
				__( 'Version', 'wp-license' ),
				__( 'Status', 'wp-license' ),
				__( 'Package', 'wp-license' ),
				__( 'SHA-256', 'wp-license' ),
				__( 'Requires', 'wp-license' ),
				__( 'Published (UTC)', 'wp-license' ),
				__( 'Actions', 'wp-license' ),
			) as $heading ) {
				echo '<th scope="col">' . esc_html( $heading ) . '</th>';
			}
			echo '</tr></thead><tbody>';
			foreach ( $rows as $row ) {
				self::render_row( $row, null !== $current && (int) $current['id'] === (int) $row['id'] );
			}
			echo '</tbody></table>';
		}

		self::render_create_form( $products );
		self::render_import_form();
		echo '</div>';
	}

	/**
	 * Create a draft release from the form.
	 *
	 * @return void
	 */
	public static function handle_create(): void {
		self::authorize( self::NONCE_CREATE );
		$input = array(
			'product_key' => self::post_text( 'product_key' ),
			'version' => self::post_text( 'version' ),
			'channel' => ReleaseRegistry::DEFAULT_CHANNEL,
			'package_url' => isset( $_POST['package_url'] ) ? esc_url_raw( trim( (string) wp_unslash( $_POST['package_url'] ) ), array( 'https' ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorize().
			'storage' => ReleaseRegistry::STORAGE_URL,
			'sha256' => self::post_text( 'sha256' ),
			'requires_php' => self::post_text( 'requires_php' ),
			'requires_wp' => self::post_text( 'requires_wp' ),
			'tested_wp' => self::post_text( 'tested_wp' ),
			'release_notes' => isset( $_POST['release_notes'] ) ? wp_kses_post( (string) wp_unslash( $_POST['release_notes'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorize().
		);

		$file = isset( $_FILES['package_zip'] ) && is_array( $_FILES['package_zip'] ) ? $_FILES['package_zip'] : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified in authorize(); validated by LocalPackageStorage.
		$has_upload = null !== $file && UPLOAD_ERR_NO_FILE !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );
		$stored_key = '';
		if ( $has_upload ) {
			// Validate everything else first so a rejected form does not leave a file behind.
			$probe = ReleaseRegistry::validate(
				array_merge(
					$input,
					array(
						'package_url' => 'https://placeholder.invalid/package.zip',
						'sha256' => str_repeat( '0', 64 ),
					)
				)
			);
			if ( $probe['errors'] ) {
				self::finish( false, ReleaseRegistry::error_message( $probe['errors'][0] ) );
			}
			$stored = LocalPackageStorage::store_upload( $file, $input['product_key'], $input['version'] );
			if ( is_wp_error( $stored ) ) {
				self::finish( false, $stored->get_error_message() );
			}
			$stored_key = $stored['key'];
			$input['storage'] = ReleaseRegistry::STORAGE_LOCAL;
			$input['package_url'] = $stored['key'];
			$input['sha256'] = $stored['sha256'];
		}

		$result = ReleaseRegistry::create( $input, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			if ( '' !== $stored_key ) {
				LocalPackageStorage::delete( $stored_key );
			}
			self::finish( false, $result->get_error_message() );
		}
		self::finish( true, __( 'Draft release created. Publish it to make it available.', 'wp-license' ) );
	}

	/**
	 * Create a draft release from a manifest JSON.
	 *
	 * @return void
	 */
	public static function handle_import(): void {
		self::authorize( self::NONCE_IMPORT );
		$json = '';
		$file = isset( $_FILES['manifest_file'] ) && is_array( $_FILES['manifest_file'] ) ? $_FILES['manifest_file'] : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified in authorize(); only tmp_name, error and size are used.
		if ( null !== $file && UPLOAD_ERR_NO_FILE !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			$tmp_name = (string) ( $file['tmp_name'] ?? '' );
			if ( UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $tmp_name ) || filesize( $tmp_name ) > self::MAX_MANIFEST_BYTES ) {
				self::finish( false, __( 'The manifest upload failed or the file is larger than 1 MB.', 'wp-license' ) );
			}
			$json = (string) file_get_contents( $tmp_name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local upload.
		} elseif ( isset( $_POST['manifest_json'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorize().
			// JSON is decoded and every field validated; sanitizing the raw text would corrupt it.
			$json = (string) wp_unslash( $_POST['manifest_json'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}
		if ( '' === trim( $json ) || strlen( $json ) > self::MAX_MANIFEST_BYTES ) {
			self::finish( false, __( 'Upload a manifest file or paste the manifest JSON.', 'wp-license' ) );
		}

		$result = ReleaseRegistry::validate_manifest( json_decode( $json, true ) );
		if ( $result['errors'] ) {
			self::finish( false, ReleaseRegistry::error_message( $result['errors'][0] ) );
		}
		$created = ReleaseRegistry::create( $result['data'], get_current_user_id() );
		if ( is_wp_error( $created ) ) {
			self::finish( false, $created->get_error_message() );
		}
		self::finish( true, __( 'Manifest imported as a draft release. Publish it to make it available.', 'wp-license' ) );
	}

	/**
	 * Publish a release.
	 *
	 * @return void
	 */
	public static function handle_publish(): void {
		$release_id = self::authorize_release_action( 'publish' );
		$result = ReleaseRegistry::publish( $release_id, get_current_user_id() );
		self::finish( ! is_wp_error( $result ), is_wp_error( $result ) ? $result->get_error_message() : __( 'Release published.', 'wp-license' ) );
	}

	/**
	 * Withdraw a release.
	 *
	 * @return void
	 */
	public static function handle_withdraw(): void {
		$release_id = self::authorize_release_action( 'withdraw' );
		$result = ReleaseRegistry::withdraw( $release_id, self::post_text( 'reason' ), get_current_user_id() );
		self::finish( ! is_wp_error( $result ), is_wp_error( $result ) ? $result->get_error_message() : __( 'Release withdrawn. Installations are now offered the next highest published version.', 'wp-license' ) );
	}

	/**
	 * Delete a draft release.
	 *
	 * @return void
	 */
	public static function handle_delete(): void {
		$release_id = self::authorize_release_action( 'delete' );
		$result = ReleaseRegistry::delete_draft( $release_id, get_current_user_id() );
		self::finish( ! is_wp_error( $result ), is_wp_error( $result ) ? $result->get_error_message() : __( 'Draft release deleted.', 'wp-license' ) );
	}

	/**
	 * Render one release row.
	 *
	 * @param array<string,mixed> $row Release row.
	 * @param bool                $is_current Whether this release is the one currently delivered.
	 * @return void
	 */
	private static function render_row( array $row, bool $is_current ): void {
		$statuses = array(
			ReleaseRegistry::STATUS_DRAFT => __( 'Draft', 'wp-license' ),
			ReleaseRegistry::STATUS_PUBLISHED => __( 'Published', 'wp-license' ),
			ReleaseRegistry::STATUS_WITHDRAWN => __( 'Withdrawn', 'wp-license' ),
		);
		$status = (string) $row['status'];
		$requires = array();
		if ( '' !== (string) $row['requires_php'] ) {
			/* translators: %s: PHP version. */
			$requires[] = sprintf( __( 'PHP %s', 'wp-license' ), (string) $row['requires_php'] );
		}
		if ( '' !== (string) $row['requires_wp'] ) {
			/* translators: %s: WordPress version. */
			$requires[] = sprintf( __( 'WP %s', 'wp-license' ), (string) $row['requires_wp'] );
		}
		if ( '' !== (string) $row['tested_wp'] ) {
			/* translators: %s: WordPress version. */
			$requires[] = sprintf( __( 'tested up to WP %s', 'wp-license' ), (string) $row['tested_wp'] );
		}

		echo '<tr><td><strong>' . esc_html( (string) $row['version'] ) . '</strong>';
		if ( $is_current ) {
			echo ' <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> ' . esc_html__( 'current', 'wp-license' );
		}
		echo '</td><td>' . esc_html( isset( $statuses[ $status ] ) ? $statuses[ $status ] : $status ) . '</td><td>';
		if ( ReleaseRegistry::STORAGE_LOCAL === $row['storage'] ) {
			// The file name is part of the protection and is not shown.
			echo esc_html__( 'Uploaded file (private storage)', 'wp-license' );
		} else {
			echo '<code>' . esc_html( (string) $row['package_url'] ) . '</code>';
		}
		echo '</td><td><code title="' . esc_attr( (string) $row['sha256'] ) . '">' . esc_html( substr( (string) $row['sha256'], 0, 12 ) ) . '&hellip;</code></td>';
		echo '<td>' . esc_html( implode( ', ', $requires ) ) . '</td>';
		echo '<td>' . esc_html( (string) ( $row['published_at'] ? $row['published_at'] : '—' ) ) . '</td><td>';

		$release_id = (int) $row['id'];
		if ( ReleaseRegistry::can_transition( $status, ReleaseRegistry::STATUS_PUBLISHED ) ) {
			self::render_action_form( 'publish', $release_id, ReleaseRegistry::STATUS_WITHDRAWN === $status ? __( 'Publish again', 'wp-license' ) : __( 'Publish', 'wp-license' ), 'button-primary', false );
		}
		if ( ReleaseRegistry::can_transition( $status, ReleaseRegistry::STATUS_WITHDRAWN ) ) {
			self::render_action_form( 'withdraw', $release_id, __( 'Withdraw', 'wp-license' ), 'button-secondary', true );
		}
		if ( ReleaseRegistry::STATUS_DRAFT === $status && empty( $row['published_at'] ) ) {
			self::render_action_form( 'delete', $release_id, __( 'Delete draft', 'wp-license' ), 'button-link-delete', false );
		}
		echo '</td></tr>';
	}

	/**
	 * Render a single-button POST form for a release action.
	 *
	 * @param string $action publish, withdraw or delete.
	 * @param int    $release_id Release ID.
	 * @param string $label Button label.
	 * @param string $button_class Button CSS class.
	 * @param bool   $with_reason Whether to ask for a reason.
	 * @return void
	 */
	private static function render_action_form( string $action, int $release_id, string $label, string $button_class, bool $with_reason ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 6px 4px 0;">';
		echo '<input type="hidden" name="action" value="' . esc_attr( 'wplc_release_' . $action ) . '">';
		echo '<input type="hidden" name="release_id" value="' . esc_attr( (string) $release_id ) . '">';
		wp_nonce_field( self::release_nonce( $action, $release_id ) );
		if ( $with_reason ) {
			echo '<label class="screen-reader-text" for="' . esc_attr( 'wplc-reason-' . $release_id ) . '">' . esc_html__( 'Reason', 'wp-license' ) . '</label>';
			echo '<input type="text" id="' . esc_attr( 'wplc-reason-' . $release_id ) . '" name="reason" maxlength="200" placeholder="' . esc_attr__( 'Reason', 'wp-license' ) . '"> ';
		}
		echo '<button type="submit" class="button ' . esc_attr( $button_class ) . '">' . esc_html( $label ) . '</button></form>';
	}

	/**
	 * Render the "add release" form.
	 *
	 * @param array<string,string> $products Product names keyed by product key.
	 * @return void
	 */
	private static function render_create_form( array $products ): void {
		echo '<hr><h2>' . esc_html__( 'Add release', 'wp-license' ) . '</h2>';
		if ( ! $products ) {
			echo '<p>' . esc_html__( 'No licensed products exist yet. Map a WooCommerce product to a license plan first.', 'wp-license' ) . '</p>';
			return;
		}
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="wplc_release_create">';
		wp_nonce_field( self::NONCE_CREATE );
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="wplc-release-product">' . esc_html__( 'Product', 'wp-license' ) . '</label></th><td><select id="wplc-release-product" name="product_key" required>';
		foreach ( $products as $product_key => $name ) {
			echo '<option value="' . esc_attr( $product_key ) . '">' . esc_html( $name . ' (' . $product_key . ')' ) . '</option>';
		}
		echo '</select></td></tr>';

		self::render_text_row( 'version', __( 'Version', 'wp-license' ), '1.2.3', true, __( 'Three numeric components, optionally with a suffix such as 1.2.3-beta1.', 'wp-license' ) );

		echo '<tr><th scope="row"><label for="wplc-release-package_zip">' . esc_html__( 'Package ZIP', 'wp-license' ) . '</label></th><td>';
		echo '<input type="file" id="wplc-release-package_zip" name="package_zip" accept=".zip,application/zip">';
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: %s: maximum file size, for example "50 MB". */
				__( 'Stored in a private directory and delivered through single-use links. The SHA-256 checksum is computed automatically. Maximum size: %s.', 'wp-license' ),
				size_format( min( LocalPackageStorage::max_bytes(), (int) wp_max_upload_size() ) )
			)
		) . '</p></td></tr>';

		self::render_text_row( 'package_url', __( 'or package URL', 'wp-license' ), 'https://downloads.example.com/product-1.2.3.zip', false, __( 'HTTPS URL on an allowed package host. Ignored when a ZIP is uploaded.', 'wp-license' ), 'url' );
		self::render_text_row( 'sha256', __( 'SHA-256', 'wp-license' ), '', false, __( 'Required for a package URL: 64 hexadecimal characters.', 'wp-license' ) );
		self::render_text_row( 'requires_php', __( 'Requires PHP', 'wp-license' ), '7.4', false, '' );
		self::render_text_row( 'requires_wp', __( 'Requires WordPress', 'wp-license' ), '6.5', false, '' );
		self::render_text_row( 'tested_wp', __( 'Tested up to WordPress', 'wp-license' ), '6.8', false, '' );

		echo '<tr><th scope="row"><label for="wplc-release-notes">' . esc_html__( 'Release notes', 'wp-license' ) . '</label></th><td>';
		echo '<textarea id="wplc-release-notes" name="release_notes" rows="6" class="large-text"></textarea></td></tr>';
		echo '</tbody></table>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Create draft release', 'wp-license' ) . '</button></p></form>';
	}

	/**
	 * Render the manifest import form.
	 *
	 * @return void
	 */
	private static function render_import_form(): void {
		echo '<hr><h2>' . esc_html__( 'Import release manifest', 'wp-license' ) . '</h2>';
		echo '<p>' . esc_html__( 'Import a manifest JSON created by tools/create-release-manifest.php. The package stays at its HTTPS URL; the release is created as a draft.', 'wp-license' ) . '</p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="wplc_release_import">';
		wp_nonce_field( self::NONCE_IMPORT );
		echo '<p><label for="wplc-manifest-file">' . esc_html__( 'Manifest file', 'wp-license' ) . '</label><br>';
		echo '<input type="file" id="wplc-manifest-file" name="manifest_file" accept=".json,application/json"></p>';
		echo '<p><label for="wplc-manifest-json">' . esc_html__( 'or paste the manifest JSON', 'wp-license' ) . '</label><br>';
		echo '<textarea id="wplc-manifest-json" name="manifest_json" rows="6" class="large-text code"></textarea></p>';
		echo '<p><button type="submit" class="button button-secondary">' . esc_html__( 'Import manifest', 'wp-license' ) . '</button></p></form>';
	}

	/**
	 * Render one text input row.
	 *
	 * @param string $name Field name.
	 * @param string $label Field label.
	 * @param string $placeholder Placeholder.
	 * @param bool   $required Whether the field is required.
	 * @param string $description Help text.
	 * @param string $type Input type.
	 * @return void
	 */
	private static function render_text_row( string $name, string $label, string $placeholder, bool $required, string $description, string $type = 'text' ): void {
		$id = 'wplc-release-' . $name;
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input type="' . esc_attr( $type ) . '" class="regular-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" placeholder="' . esc_attr( $placeholder ) . '"' . ( $required ? ' required' : '' ) . '>';
		if ( '' !== $description ) {
			echo '<p class="description">' . esc_html( $description ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * Show and clear the result of the last action.
	 *
	 * @return void
	 */
	private static function render_notice(): void {
		$key = self::notice_key();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}
		delete_transient( $key );
		$class = ! empty( $notice['success'] ) ? 'notice-success' : 'notice-error';
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( (string) $notice['message'] ) . '</p></div>';
	}

	/**
	 * Capability and nonce check for the create and import forms.
	 *
	 * @param string $nonce_action Nonce action.
	 * @return void
	 */
	private static function authorize( string $nonce_action ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage releases.', 'wp-license' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $nonce_action );
	}

	/**
	 * Capability and per-release nonce check for publish, withdraw and delete.
	 *
	 * @param string $action publish, withdraw or delete.
	 * @return int Release ID.
	 */
	private static function authorize_release_action( string $action ): int {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage releases.', 'wp-license' ), '', array( 'response' => 403 ) );
		}
		$release_id = isset( $_POST['release_id'] ) ? absint( wp_unslash( $_POST['release_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified on the next line.
		check_admin_referer( self::release_nonce( $action, $release_id ) );

		return $release_id;
	}

	/**
	 * Nonce action bound to one release and one action.
	 *
	 * @param string $action publish, withdraw or delete.
	 * @param int    $release_id Release ID.
	 * @return string
	 */
	private static function release_nonce( string $action, int $release_id ): string {
		return 'wplc_release_' . $action . '_' . $release_id;
	}

	/**
	 * Read a sanitized single-line POST field. Callers verify the nonce first.
	 *
	 * @param string $name Field name.
	 * @return string
	 */
	private static function post_text( string $name ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by authorize() / authorize_release_action().
		return isset( $_POST[ $name ] ) ? trim( sanitize_text_field( (string) wp_unslash( $_POST[ $name ] ) ) ) : '';
	}

	/**
	 * Store the result for the current user and return to the page.
	 *
	 * @param bool   $success Whether the action succeeded.
	 * @param string $message Message shown as admin notice.
	 * @return void
	 */
	private static function finish( bool $success, string $message ): void {
		set_transient(
			self::notice_key(),
			array(
				'success' => $success,
				'message' => $message,
			),
			MINUTE_IN_SECONDS
		);
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
		exit;
	}

	/**
	 * Per-user notice key.
	 *
	 * @return string
	 */
	private static function notice_key(): string {
		return 'wplc_release_notice_' . get_current_user_id();
	}
}
