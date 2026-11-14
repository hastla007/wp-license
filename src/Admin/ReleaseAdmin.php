<?php
/**
 * Admin page for product releases.
 *
 * @package WPLC
 */

namespace WPLC\Admin;

use WPLC\Api\V1\UpdateService;
use WPLC\Update\LocalPackageStorage;
use WPLC\Update\PackageStream;
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
		add_action( 'admin_menu', array( self::class, 'register_menu' ), 15 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
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
			Capabilities::menu_parent(),
			__( 'License releases', 'wp-license' ),
			__( 'License releases', 'wp-license' ),
			self::capability(),
			self::PAGE,
			array( self::class, 'render' )
		);
		LicenseAdmin::connect_page( self::PAGE, __( 'License releases', 'wp-license' ) );
	}

	/**
	 * Load the confirmation script on the releases screen (A-59).
	 *
	 * @return void
	 */
	public static function enqueue_assets(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Screen detection only.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::PAGE !== $page ) {
			return;
		}
		wp_enqueue_style( 'wplc-admin', plugins_url( 'assets/css/wplc-admin.css', WPLC_FILE ), array(), \WPLC\Account\CustomerPortal::asset_version( 'assets/css/wplc-admin.css' ) );
		wp_enqueue_script( 'wplc-ui', plugins_url( 'assets/js/wplc-ui.js', WPLC_FILE ), array(), \WPLC\Account\CustomerPortal::asset_version( 'assets/js/wplc-ui.js' ), true );
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to manage releases.', 'wp-license' ), '', array( 'response' => 403 ) );
		}
		$products = ReleaseRegistry::products();
		$releases = ReleaseRegistry::all_by_product();
		$notice = self::take_notice();

		echo '<div class="wrap"><h1>' . esc_html__( 'License releases', 'wp-license' ) . '</h1>';
		self::render_notice( $notice );
		self::render_problems( $releases );
		echo '<p>' . esc_html__( 'Licensed installations receive the highest published version of a product as an update, and customers can download it from their account. Version, package and checksum are frozen once a release is published: withdraw it and publish a new version to correct a package.', 'wp-license' ) . '</p>';

		if ( ! $releases ) {
			echo '<p><em>' . esc_html__( 'No releases yet.', 'wp-license' ) . '</em></p>';
		}
		foreach ( $releases as $product_key => $rows ) {
			$product_key = (string) $product_key;
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
			$slug = ReleaseRegistry::product_slug( $product_key );
			echo '<p class="description">';
			if ( '' !== $slug ) {
				/* translators: %s: folder name. */
				echo esc_html( sprintf( __( 'Plugin folder: %s. Every package of this product must contain exactly this top-level folder.', 'wp-license' ), $slug ) );
			} else {
				echo esc_html__( 'Plugin folder: not set yet. The first published package defines it.', 'wp-license' );
			}
			echo '</p>';
			echo '<table class="widefat striped"><thead><tr>';
			foreach ( array(
				__( 'Version', 'wp-license' ),
				__( 'Status', 'wp-license' ),
				__( 'Package', 'wp-license' ),
				__( 'SHA-256', 'wp-license' ),
				__( 'Requires', 'wp-license' ),
				__( 'Published', 'wp-license' ),
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

		self::render_create_form( $products, isset( $notice['form'] ) && is_array( $notice['form'] ) ? $notice['form'] : array() );
		self::render_import_form();
		self::render_log( $products );
		echo '</div>';
	}

	/**
	 * Create a draft release from the form.
	 *
	 * @return void
	 */
	public static function handle_create(): void {
		self::require_capability();
		// A-57: a request above post_max_size arrives without any fields (and without the nonce). Nothing is changed here.
		$content_length = isset( $_SERVER['CONTENT_LENGTH'] ) ? absint( wp_unslash( $_SERVER['CONTENT_LENGTH'] ) ) : 0;
		if ( empty( $_POST ) && empty( $_FILES ) && $content_length > 0 ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nothing to verify: PHP discarded the request body.
			self::finish(
				false,
				sprintf(
					/* translators: 1: size of the upload, 2: maximum size. */
					__( 'The upload (%1$s) is larger than this server accepts (%2$s). Nothing was saved. Raise upload_max_filesize and post_max_size, or register the package by URL.', 'wp-license' ),
					size_format( $content_length ),
					size_format( (int) wp_max_upload_size() )
				)
			);
		}
		check_admin_referer( self::NONCE_CREATE );

		$channel = self::post_text( 'channel' );
		$input = array(
			'product_key' => self::post_text( 'product_key' ),
			'version' => self::post_text( 'version' ),
			'channel' => ReleaseRegistry::CHANNEL_BETA === $channel ? ReleaseRegistry::CHANNEL_BETA : ReleaseRegistry::DEFAULT_CHANNEL,
			'package_url' => isset( $_POST['package_url'] ) ? esc_url_raw( trim( (string) wp_unslash( $_POST['package_url'] ) ), array( 'https' ) ) : '',
			'storage' => ReleaseRegistry::STORAGE_URL,
			'sha256' => self::post_text( 'sha256' ),
			'requires_php' => self::post_text( 'requires_php' ),
			'requires_wp' => self::post_text( 'requires_wp' ),
			'tested_wp' => self::post_text( 'tested_wp' ),
			'requires_core' => self::post_text( 'requires_core' ),
			'release_notes' => isset( $_POST['release_notes'] ) ? wp_kses_post( (string) wp_unslash( $_POST['release_notes'] ) ) : '',
		);

		$file = isset( $_FILES['package_zip'] ) && is_array( $_FILES['package_zip'] ) ? $_FILES['package_zip'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by LocalPackageStorage.
		$has_upload = null !== $file && UPLOAD_ERR_NO_FILE !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );
		$result = $has_upload
			? ReleaseRegistry::create_with_package( $input, $file, get_current_user_id() )
			: ReleaseRegistry::create( $input, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			// The form is shown again with what was entered; only the file has to be chosen again.
			self::finish( false, $result->get_error_message(), $input );
		}
		$message = __( 'Draft release created. Publish it to make it available.', 'wp-license' );
		if ( ! $has_upload && ! PackageStream::is_trusted_url( $input['package_url'] ) ) {
			// A-119: say it now, not only when the release is published.
			$message .= ' ' . ReleaseRegistry::error_message( 'untrusted_package_host' );
		}
		self::finish( true, $message );
	}

	/**
	 * Create a draft release from a manifest JSON.
	 *
	 * @return void
	 */
	public static function handle_import(): void {
		self::require_capability();
		check_admin_referer( self::NONCE_IMPORT );
		$json = '';
		$file = isset( $_FILES['manifest_file'] ) && is_array( $_FILES['manifest_file'] ) ? $_FILES['manifest_file'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only tmp_name, error and size are used.
		if ( null !== $file && UPLOAD_ERR_NO_FILE !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			$tmp_name = (string) ( $file['tmp_name'] ?? '' );
			if ( UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $tmp_name ) || filesize( $tmp_name ) > self::MAX_MANIFEST_BYTES ) {
				self::finish( false, __( 'The manifest upload failed or the file is larger than 1 MB.', 'wp-license' ) );
			}
			$json = (string) file_get_contents( $tmp_name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local upload.
		} elseif ( isset( $_POST['manifest_json'] ) ) {
			// JSON is decoded and every field validated; sanitizing the raw text would corrupt it.
			$json = (string) wp_unslash( $_POST['manifest_json'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
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
		$message = __( 'Manifest imported as a draft release. Publish it to make it available.', 'wp-license' );
		if ( ! PackageStream::is_trusted_url( (string) $result['data']['package_url'] ) ) {
			$message .= ' ' . ReleaseRegistry::error_message( 'untrusted_package_host' );
		}
		self::finish( true, $message );
	}

	/**
	 * Publish a release.
	 *
	 * @return void
	 */
	public static function handle_publish(): void {
		$release_id = self::authorize_release_action( 'publish' );
		$reason = self::required_reason();
		// Computed before the status changes: afterwards the release itself is the highest one.
		$warning = ReleaseRegistry::publish_warning( $release_id );
		$result = ReleaseRegistry::publish( $release_id, get_current_user_id(), $reason );
		if ( is_wp_error( $result ) ) {
			self::finish( false, $result->get_error_message() );
		}
		self::finish( true, trim( __( 'Release published.', 'wp-license' ) . ' ' . $warning ), array(), '' !== $warning );
	}

	/**
	 * Withdraw a release.
	 *
	 * @return void
	 */
	public static function handle_withdraw(): void {
		$release_id = self::authorize_release_action( 'withdraw' );
		$result = ReleaseRegistry::withdraw( $release_id, self::required_reason(), get_current_user_id() );
		self::finish( ! is_wp_error( $result ), is_wp_error( $result ) ? $result->get_error_message() : __( 'Release withdrawn. Installations are now offered the next highest published version.', 'wp-license' ) );
	}

	/**
	 * Delete a draft release.
	 *
	 * @return void
	 */
	public static function handle_delete(): void {
		$release_id = self::authorize_release_action( 'delete' );
		$result = ReleaseRegistry::delete_draft( $release_id, get_current_user_id(), self::required_reason() );
		self::finish( ! is_wp_error( $result ), is_wp_error( $result ) ? $result->get_error_message() : __( 'Draft release deleted.', 'wp-license' ) );
	}

	/**
	 * Capability needed to see the screen and to create, publish, withdraw and delete releases.
	 *
	 * A published release is code that runs on every customer site, so this is
	 * deliberately a hook point of its own (A-35, B-49).
	 *
	 * @return string
	 */
	private static function capability(): string {
		return Capabilities::cap( 'publish_releases' );
	}

	/**
	 * Stop unless the current user may manage releases.
	 *
	 * @return void
	 */
	private static function require_capability(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to manage releases.', 'wp-license' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Show what currently stops releases from being delivered safely.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $releases Releases by product.
	 * @return void
	 */
	private static function render_problems( array $releases ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only re-check of a cached probe.
		if ( isset( $_GET['wplc_recheck'] ) ) {
			LocalPackageStorage::exposure_status( true );
		}
		foreach ( ReleaseRegistry::problems( true ) as $problem ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $problem['message'] );
			if ( 'package_dir_exposed' === $problem['code'] ) {
				echo ' <a href="' . esc_url( add_query_arg( 'wplc_recheck', '1', admin_url( 'admin.php?page=' . self::PAGE ) ) ) . '">' . esc_html__( 'Check again', 'wp-license' ) . '</a>';
			}
			echo '</p></div>';
		}
		$has_published_url = false;
		foreach ( $releases as $rows ) {
			foreach ( $rows as $row ) {
				if ( ReleaseRegistry::STORAGE_URL === $row['storage'] && ReleaseRegistry::STATUS_PUBLISHED === $row['status'] ) {
					$has_published_url = true;
				}
			}
		}
		if ( $has_published_url && ! UpdateService::has_package_resolver() ) {
			// A-32.
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Releases with a package URL are delivered by redirecting licensed installations to that URL. Without a resolver that returns a short-lived signed URL, every licensed customer learns the permanent address and can keep using it after the license has ended. Upload the ZIP instead, or add a resolver (see docs/UPDATE-STORAGE.md).', 'wp-license' ) . '</p></div>';
		}
	}

	/**
	 * Render one release row.
	 *
	 * @param array<string,mixed> $row Release row.
	 * @param bool                $is_current Whether this release is the one currently delivered.
	 * @return void
	 */
	private static function render_row( array $row, bool $is_current ): void {
		$statuses = self::status_labels();
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
		if ( '' !== (string) ( $row['requires_core'] ?? '' ) ) {
			/* translators: %s: version of the free core plugin. */
			$requires[] = sprintf( __( 'core plugin %s', 'wp-license' ), (string) $row['requires_core'] );
		}

		echo '<tr><td><strong>' . esc_html( (string) $row['version'] ) . '</strong>';
		if ( ReleaseRegistry::DEFAULT_CHANNEL !== (string) $row['channel'] ) {
			echo ' <span class="description">(' . esc_html( (string) $row['channel'] ) . ')</span>';
		}
		if ( $is_current ) {
			echo ' <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> ' . esc_html__( 'current', 'wp-license' );
		}
		echo '</td><td>' . esc_html( isset( $statuses[ $status ] ) ? $statuses[ $status ] : $status ) . '</td><td>';
		if ( ReleaseRegistry::STORAGE_LOCAL === $row['storage'] ) {
			// The file name is part of the protection and is not shown.
			echo esc_html__( 'Uploaded file (private storage)', 'wp-license' );
		} else {
			echo '<code>' . esc_html( (string) $row['package_url'] ) . '</code>';
			if ( ! PackageStream::is_trusted_url( (string) $row['package_url'] ) ) {
				echo '<br><span class="description">' . esc_html__( 'Host not allowed: add it under License settings before publishing.', 'wp-license' ) . '</span>';
			}
		}
		echo '</td><td><code title="' . esc_attr( (string) $row['sha256'] ) . '">' . esc_html( substr( (string) $row['sha256'], 0, 12 ) ) . '&hellip;</code></td>';
		echo '<td>' . esc_html( implode( ', ', $requires ) ) . '</td>';
		// Shown in the site's time zone and date format (A-119, A-131).
		echo '<td>' . esc_html( Format::date( $row['published_at'], true ) ) . '</td><td>';

		$release_id = (int) $row['id'];
		$name = (string) $row['product_key'] . ' ' . (string) $row['version'];
		if ( ReleaseRegistry::can_transition( $status, ReleaseRegistry::STATUS_PUBLISHED ) ) {
			self::render_action_form(
				'publish',
				$release_id,
				ReleaseRegistry::STATUS_WITHDRAWN === $status ? __( 'Publish again', 'wp-license' ) : __( 'Publish', 'wp-license' ),
				'button-primary',
				/* translators: %s: product key and version. */
				sprintf( __( 'Publish %s? Licensed installations will be offered this package as an update.', 'wp-license' ), $name )
			);
		}
		if ( ReleaseRegistry::can_transition( $status, ReleaseRegistry::STATUS_WITHDRAWN ) ) {
			self::render_action_form(
				'withdraw',
				$release_id,
				__( 'Withdraw', 'wp-license' ),
				'button-secondary',
				/* translators: %s: product key and version. */
				sprintf( __( 'Withdraw %s? It is no longer offered; installations that already have it keep it.', 'wp-license' ), $name )
			);
		}
		if ( ReleaseRegistry::STATUS_DRAFT === $status && empty( $row['published_at'] ) ) {
			self::render_action_form(
				'delete',
				$release_id,
				__( 'Delete draft', 'wp-license' ),
				'button-link-delete',
				/* translators: %s: product key and version. */
				sprintf( __( 'Delete the draft %s and its uploaded file? This cannot be undone.', 'wp-license' ), $name )
			);
		}
		echo '</td></tr>';
	}

	/**
	 * Render a POST form for a release action: reason, button, confirmation (A-59, B-137).
	 *
	 * @param string $action publish, withdraw or delete.
	 * @param int    $release_id Release ID.
	 * @param string $label Button label.
	 * @param string $button_class Button CSS class.
	 * @param string $question Confirmation question.
	 * @return void
	 */
	private static function render_action_form( string $action, int $release_id, string $label, string $button_class, string $question ): void {
		$reason_id = 'wplc-reason-' . $action . '-' . $release_id;
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 6px 4px 0;" data-wplc-confirm="' . esc_attr( $question ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( 'wplc_release_' . $action ) . '">';
		echo '<input type="hidden" name="release_id" value="' . esc_attr( (string) $release_id ) . '">';
		self::nonce_field( self::release_nonce( $action, $release_id ) );
		echo '<label class="screen-reader-text" for="' . esc_attr( $reason_id ) . '">' . esc_html__( 'Reason', 'wp-license' ) . '</label>';
		echo '<input type="text" id="' . esc_attr( $reason_id ) . '" name="reason" maxlength="' . esc_attr( (string) ReleaseRegistry::REASON_MAX_LENGTH ) . '" required placeholder="' . esc_attr__( 'Reason (required)', 'wp-license' ) . '"> ';
		echo '<button type="submit" class="button ' . esc_attr( $button_class ) . '">' . esc_html( $label ) . '</button></form>';
	}

	/**
	 * Render the "add release" form.
	 *
	 * @param array<string,string> $products Product names keyed by product key.
	 * @param array<string,mixed>  $old Values of a rejected submission.
	 * @return void
	 */
	private static function render_create_form( array $products, array $old ): void {
		echo '<hr><h2>' . esc_html__( 'Add release', 'wp-license' ) . '</h2>';
		if ( ! $products ) {
			echo '<p>' . esc_html__( 'No licensed products exist yet. Map a WooCommerce product to a license plan first.', 'wp-license' ) . '</p>';
			return;
		}
		$value = static function ( string $name ) use ( $old ): string {
			return isset( $old[ $name ] ) && is_scalar( $old[ $name ] ) ? (string) $old[ $name ] : '';
		};
		// The action is part of the URL: when the upload exceeds post_max_size, PHP drops every field and
		// admin-post.php would otherwise show an empty page (A-57).
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( add_query_arg( 'action', 'wplc_release_create', admin_url( 'admin-post.php' ) ) ) . '">';
		echo '<input type="hidden" name="action" value="wplc_release_create">';
		self::nonce_field( self::NONCE_CREATE );
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="wplc-release-product">' . esc_html__( 'Product', 'wp-license' ) . '</label></th><td><select id="wplc-release-product" name="product_key" required>';
		foreach ( $products as $product_key => $name ) {
			echo '<option value="' . esc_attr( (string) $product_key ) . '"' . selected( $value( 'product_key' ), (string) $product_key, false ) . '>' . esc_html( $name . ' (' . $product_key . ')' ) . '</option>';
		}
		echo '</select></td></tr>';

		self::render_text_row( 'version', __( 'Version', 'wp-license' ), '1.2.3', true, __( 'Three numbers, optionally followed by -alpha, -beta or -rc and a number: 1.2.3, 1.2.3-beta1, 1.2.3-rc2. It must equal the "Version:" header of the plugin in the ZIP.', 'wp-license' ), 'text', $value( 'version' ) );

		echo '<tr><th scope="row"><label for="wplc-release-channel">' . esc_html__( 'Channel', 'wp-license' ) . '</label></th><td><select id="wplc-release-channel" name="channel">';
		echo '<option value="' . esc_attr( ReleaseRegistry::DEFAULT_CHANNEL ) . '"' . selected( $value( 'channel' ), ReleaseRegistry::DEFAULT_CHANNEL, false ) . '>' . esc_html__( 'Stable: all installations', 'wp-license' ) . '</option>';
		echo '<option value="' . esc_attr( ReleaseRegistry::CHANNEL_BETA ) . '"' . selected( $value( 'channel' ), ReleaseRegistry::CHANNEL_BETA, false ) . '>' . esc_html__( 'Beta: only installations that asked for pre-releases', 'wp-license' ) . '</option>';
		echo '</select></td></tr>';

		echo '<tr><th scope="row"><label for="wplc-release-package_zip">' . esc_html__( 'Package ZIP', 'wp-license' ) . '</label></th><td>';
		echo '<input type="file" id="wplc-release-package_zip" name="package_zip" accept=".zip,application/zip">';
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: %s: maximum file size, for example "50 MB". */
				__( 'Stored in a private directory and delivered through short-lived links. The ZIP must contain exactly one top-level folder (the plugin folder). The SHA-256 checksum is computed automatically. Maximum size: %s.', 'wp-license' ),
				size_format( min( LocalPackageStorage::max_bytes(), (int) wp_max_upload_size() ) )
			)
		) . '</p></td></tr>';

		self::render_text_row( 'package_url', __( 'or package URL', 'wp-license' ), 'https://downloads.example.com/product-1.2.3.zip', false, __( 'HTTPS URL on an allowed package host (License settings → Additional trusted package hosts). Ignored when a ZIP is uploaded. The file is downloaded once and checked when the release is published.', 'wp-license' ), 'url', $value( 'package_url' ) );
		self::render_text_row( 'sha256', __( 'SHA-256', 'wp-license' ), '', false, __( 'Required for a package URL: 64 hexadecimal characters. Compute it with "sha256sum file.zip" (Linux), "shasum -a 256 file.zip" (macOS) or "Get-FileHash file.zip" (Windows PowerShell).', 'wp-license' ), 'text', $value( 'sha256' ) );
		self::render_text_row( 'requires_php', __( 'Requires PHP', 'wp-license' ), '7.4', false, __( 'Empty: taken from the plugin header of an uploaded ZIP.', 'wp-license' ), 'text', $value( 'requires_php' ) );
		self::render_text_row( 'requires_wp', __( 'Requires WordPress', 'wp-license' ), '6.5', false, __( 'Empty: taken from the plugin header of an uploaded ZIP.', 'wp-license' ), 'text', $value( 'requires_wp' ) );
		self::render_text_row( 'tested_wp', __( 'Tested up to WordPress', 'wp-license' ), '6.8', false, '', 'text', $value( 'tested_wp' ) );
		self::render_text_row( 'requires_core', __( 'Requires core plugin', 'wp-license' ), '2.1.0', false, __( 'Minimum version of the free core plugin this add-on release needs. Installations that report an older core plugin are not offered this release.', 'wp-license' ), 'text', $value( 'requires_core' ) );

		echo '<tr><th scope="row"><label for="wplc-release-notes">' . esc_html__( 'Release notes', 'wp-license' ) . '</label></th><td>';
		echo '<textarea id="wplc-release-notes" name="release_notes" rows="6" class="large-text">' . esc_textarea( $value( 'release_notes' ) ) . '</textarea></td></tr>';
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
		self::nonce_field( self::NONCE_IMPORT );
		echo '<p><label for="wplc-manifest-file">' . esc_html__( 'Manifest file', 'wp-license' ) . '</label><br>';
		echo '<input type="file" id="wplc-manifest-file" name="manifest_file" accept=".json,application/json"></p>';
		echo '<p><label for="wplc-manifest-json">' . esc_html__( 'or paste the manifest JSON', 'wp-license' ) . '</label><br>';
		echo '<textarea id="wplc-manifest-json" name="manifest_json" rows="6" class="large-text code"></textarea></p>';
		echo '<p><button type="submit" class="button button-secondary">' . esc_html__( 'Import manifest', 'wp-license' ) . '</button></p></form>';
	}

	/**
	 * Render who did what to which release, and why.
	 *
	 * @param array<string,string> $products Product names keyed by product key.
	 * @return void
	 */
	private static function render_log( array $products ): void {
		$entries = ReleaseRegistry::audit_entries( 50 );
		if ( ! $entries ) {
			return;
		}
		$actions = array(
			'release_created' => __( 'Draft created', 'wp-license' ),
			'release_updated' => __( 'Changed', 'wp-license' ),
			'release_published' => __( 'Published', 'wp-license' ),
			'release_withdrawn' => __( 'Withdrawn', 'wp-license' ),
			'release_deleted' => __( 'Draft deleted', 'wp-license' ),
			'release_product_slug_changed' => __( 'Plugin folder set', 'wp-license' ),
		);
		echo '<hr><h2>' . esc_html__( 'Release log', 'wp-license' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'The last 50 changes to releases.', 'wp-license' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array(
			__( 'Date', 'wp-license' ),
			__( 'User', 'wp-license' ),
			__( 'Release', 'wp-license' ),
			__( 'Action', 'wp-license' ),
			__( 'Reason', 'wp-license' ),
		) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $entries as $entry ) {
			$user = $entry['actor_user_id'] > 0 ? get_userdata( $entry['actor_user_id'] ) : false;
			/* translators: %d: user ID. */
			$user_label = $user ? $user->display_name : ( $entry['actor_user_id'] > 0 ? sprintf( __( 'User #%d', 'wp-license' ), $entry['actor_user_id'] ) : __( 'System / WP-CLI', 'wp-license' ) );
			$product = isset( $products[ $entry['product_key'] ] ) ? $products[ $entry['product_key'] ] : $entry['product_key'];
			echo '<tr><td>' . esc_html( Format::date( $entry['created_at'], true ) ) . '</td>';
			echo '<td>' . esc_html( $user_label ) . '</td>';
			echo '<td>' . esc_html( trim( $product . ' ' . $entry['version'] ) ) . '</td>';
			echo '<td>' . esc_html( isset( $actions[ $entry['action'] ] ) ? $actions[ $entry['action'] ] : $entry['action'] ) . '</td>';
			echo '<td>' . esc_html( '' !== $entry['reason'] ? $entry['reason'] : '—' ) . '</td></tr>';
		}
		echo '</tbody></table>';
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
	 * @param string $value Current value.
	 * @return void
	 */
	private static function render_text_row( string $name, string $label, string $placeholder, bool $required, string $description, string $type = 'text', string $value = '' ): void {
		$id = 'wplc-release-' . $name;
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input type="' . esc_attr( $type ) . '" class="regular-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $placeholder ) . '"' . ( $required ? ' required' : '' ) . '>';
		if ( '' !== $description ) {
			echo '<p class="description">' . esc_html( $description ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * Translated release statuses.
	 *
	 * @return array<string,string>
	 */
	private static function status_labels(): array {
		return array(
			ReleaseRegistry::STATUS_DRAFT => __( 'Draft', 'wp-license' ),
			ReleaseRegistry::STATUS_PUBLISHED => __( 'Published', 'wp-license' ),
			ReleaseRegistry::STATUS_WITHDRAWN => __( 'Withdrawn', 'wp-license' ),
		);
	}

	/**
	 * Read and clear the result of the last action.
	 *
	 * @return array<string,mixed>
	 */
	private static function take_notice(): array {
		$key = self::notice_key();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return array();
		}
		delete_transient( $key );

		return $notice;
	}

	/**
	 * Show the result of the last action.
	 *
	 * @param array<string,mixed> $notice Result of take_notice().
	 * @return void
	 */
	private static function render_notice( array $notice ): void {
		if ( ! $notice ) {
			return;
		}
		$class = empty( $notice['success'] ) ? 'notice-error' : ( empty( $notice['warning'] ) ? 'notice-success' : 'notice-warning' );
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( (string) $notice['message'] ) . '</p></div>';
	}

	/**
	 * Print a nonce field without an id attribute.
	 *
	 * The core helper wp_nonce_field() prints id="_wpnonce" every time, which
	 * is invalid HTML as soon as a page has more than one form (A-127).
	 *
	 * @param string $action Nonce action.
	 * @return void
	 */
	private static function nonce_field( string $action ): void {
		echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( $action ) ) . '">';
	}

	/**
	 * Capability and per-release nonce check for publish, withdraw and delete.
	 *
	 * @param string $action publish, withdraw or delete.
	 * @return int Release ID.
	 */
	private static function authorize_release_action( string $action ): int {
		self::require_capability();
		$release_id = isset( $_POST['release_id'] ) ? absint( wp_unslash( $_POST['release_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified on the next line.
		check_admin_referer( self::release_nonce( $action, $release_id ) );

		return $release_id;
	}

	/**
	 * Read the mandatory reason of a release action (B-137).
	 *
	 * @return string
	 */
	private static function required_reason(): string {
		$reason = ReleaseRegistry::normalize_reason( self::post_text( 'reason' ) );
		if ( '' === $reason ) {
			self::finish( false, ReleaseRegistry::error_message( 'reason_required' ) );
		}

		return $reason;
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
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified by the calling handler; sanitized two lines below.
		$value = isset( $_POST[ $name ] ) ? wp_unslash( $_POST[ $name ] ) : '';

		return is_scalar( $value ) ? trim( sanitize_text_field( (string) $value ) ) : '';
	}

	/**
	 * Store the result for the current user and return to the page.
	 *
	 * @param bool                $success Whether the action succeeded.
	 * @param string              $message Message shown as admin notice.
	 * @param array<string,mixed> $form Form values to show again after an error.
	 * @param bool                $warning Whether a successful action carries a warning.
	 * @return void
	 */
	private static function finish( bool $success, string $message, array $form = array(), bool $warning = false ): void {
		unset( $form['storage'] );
		set_transient(
			self::notice_key(),
			array(
				'success' => $success,
				'warning' => $warning,
				'message' => $message,
				'form' => $success ? array() : $form,
			),
			$form ? 10 * MINUTE_IN_SECONDS : MINUTE_IN_SECONDS
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
