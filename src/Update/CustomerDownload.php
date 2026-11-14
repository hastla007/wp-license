<?php
/**
 * First delivery of a premium package to the license owner.
 *
 * @package WPLC
 */

namespace WPLC\Update;

use WPLC\Infrastructure\Database\Schema;
use WPLC\License\AuditLog;
use WPLC\License\LicenseLifecycle;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lets a logged-in customer download a release of a licensed product.
 *
 * The update feed needs an activated installation, so the very first ZIP is
 * delivered here: from the customer account, to the owner of the license,
 * through the same short-lived stream as updates.
 *
 * - A license with access can download the latest and older published versions (B-145).
 * - A license whose paid period has ended keeps the versions that were published
 *   while it was paid (A-116); revoked, suspended and pending licenses get nothing.
 */
final class CustomerDownload {
	const ACTION = 'wplc_download_package';
	const DEFAULT_HOURLY_LIMIT = 10;
	const DEFAULT_VERSION_CHOICES = 10;

	/**
	 * Register the download handler.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		// The account form posts to the front end: wp-admin is often locked down
		// (Basic Auth, IP allow-list, WAF) on shops (B-17). admin-post.php keeps
		// working for links and integrations that still use it.
		add_action( 'template_redirect', array( self::class, 'maybe_handle_frontend' ), 1 );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( self::class, 'handle_logged_out' ) );
	}

	/**
	 * Nonce action for one license.
	 *
	 * @param int $license_id License ID.
	 * @return string
	 */
	public static function nonce_action( int $license_id ): string {
		return self::ACTION . '_' . $license_id;
	}

	/**
	 * Which releases a license may download.
	 *
	 * @param array<string,mixed> $license License row.
	 * @return string|null '' = every published release; a UTC datetime = releases
	 *                     published up to then; null = no download.
	 */
	public static function download_scope( array $license ): ?string {
		if ( LicenseLifecycle::has_access( $license ) ) {
			return '';
		}
		$status = (string) ( $license['status'] ?? '' );
		$paid_until = (string) ( $license['paid_until'] ?? '' );
		// Revoked (refund), suspended (chargeback) and pending licenses never paid for a version they may keep.
		if ( '' === $paid_until || ! in_array( $status, array( 'active', 'past_due', 'expired' ), true ) ) {
			return null;
		}
		/**
		 * Filters whether a license whose paid period ended may still download
		 * the versions that were published during that period (A-116).
		 *
		 * @param bool                $allowed Default true.
		 * @param array<string,mixed> $license License row.
		 */
		if ( ! apply_filters( 'wplc_expired_license_download', true, $license ) ) {
			return null;
		}

		return $paid_until;
	}

	/**
	 * Whether a license may download anything from the customer account.
	 *
	 * @param array<string,mixed> $license License row.
	 * @return bool
	 */
	public static function license_may_download( array $license ): bool {
		return null !== self::download_scope( $license );
	}

	/**
	 * Echo the download form, or a short hint when nothing can be downloaded yet.
	 *
	 * @param array<string,mixed> $license_row License row.
	 * @return void
	 */
	public static function render_button( array $license_row ): void {
		$license_id = (int) ( $license_row['id'] ?? 0 );
		if ( $license_id <= 0 || ! is_user_logged_in() || ! self::is_owner( $license_row, get_current_user_id() ) ) {
			return;
		}
		$scope = self::download_scope( $license_row );
		if ( null === $scope ) {
			return;
		}
		$versions = self::downloadable_versions( $license_row );
		if ( ! $versions ) {
			// A-55: say why there is no button instead of showing nothing.
			echo '<p class="wplc-download-package wplc-download-package--none">';
			echo esc_html(
				'' === $scope
					? __( 'No download is available for this product yet. You will find it here as soon as a version has been released.', 'wp-license' )
					: __( 'No version was released while this license was paid, so there is nothing to download.', 'wp-license' )
			);
			echo '</p>';
			return;
		}

		echo '<form method="post" class="wplc-download-package" action="' . esc_url( self::form_url() ) . '">';
		echo '<input type="hidden" name="wplc_action" value="' . esc_attr( self::ACTION ) . '">';
		echo '<input type="hidden" name="license_id" value="' . esc_attr( (string) $license_id ) . '">';
		// Printed without an id: several forms on one page must not repeat id="_wpnonce" (A-127).
		echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( self::nonce_action( $license_id ) ) ) . '">';
		$latest = (string) $versions[0]['version'];
		if ( count( $versions ) > 1 ) {
			$select_id = 'wplc-download-version-' . $license_id;
			echo '<label class="screen-reader-text" for="' . esc_attr( $select_id ) . '">' . esc_html__( 'Version', 'wp-license' ) . '</label>';
			echo '<select name="version" id="' . esc_attr( $select_id ) . '" class="wplc-download-package__version">';
			foreach ( $versions as $index => $row ) {
				$label = 0 === $index
					/* translators: %s: release version number. */
					? sprintf( __( '%s (latest)', 'wp-license' ), (string) $row['version'] )
					: (string) $row['version'];
				echo '<option value="' . esc_attr( (string) $row['version'] ) . '"' . selected( 0, $index, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select> ';
			echo '<button type="submit" class="button wplc-download-package__button">' . esc_html__( 'Download', 'wp-license' ) . '</button>';
		} else {
			echo '<input type="hidden" name="version" value="' . esc_attr( $latest ) . '">';
			echo '<button type="submit" class="button wplc-download-package__button">';
			/* translators: %s: release version number. */
			echo esc_html( sprintf( __( 'Download version %s', 'wp-license' ), $latest ) );
			echo '</button>';
		}
		if ( '' !== $scope ) {
			echo '<p class="wplc-download-package__note">' . esc_html__( 'This license has ended. You can still download the versions that were released while it was paid; newer versions need a renewal.', 'wp-license' ) . '</p>';
		}
		echo '</form>';
	}

	/**
	 * Published versions the license may download, highest first.
	 *
	 * @param array<string,mixed> $license License row.
	 * @return array<int,array<string,mixed>>
	 */
	public static function downloadable_versions( array $license ): array {
		$scope = self::download_scope( $license );
		if ( null === $scope ) {
			return array();
		}
		/**
		 * Filters how many versions the customer can choose from.
		 *
		 * @param int $choices Default 10; 1 offers the latest version only.
		 */
		$choices = (int) apply_filters( 'wplc_customer_download_version_choices', self::DEFAULT_VERSION_CHOICES );

		return ReleaseRegistry::published_versions( (string) ( $license['product_key'] ?? '' ), ReleaseRegistry::DEFAULT_CHANNEL, max( 1, min( 50, $choices ) ), $scope );
	}

	/**
	 * Send logged-out visitors to the login form.
	 *
	 * @return void
	 */
	public static function handle_logged_out(): void {
		$account_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : wp_login_url();
		wp_safe_redirect( $account_url ? $account_url : wp_login_url() );
		exit;
	}

	/**
	 * Handle the account form when it is posted to a front-end URL.
	 *
	 * @return void
	 */
	public static function maybe_handle_frontend(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- routing only; handle() verifies the nonce.
		$action = isset( $_POST['wplc_action'] ) ? sanitize_key( wp_unslash( $_POST['wplc_action'] ) ) : '';
		if ( self::ACTION !== $action ) {
			return;
		}
		if ( ! is_user_logged_in() ) {
			self::handle_logged_out();
		}
		self::handle();
	}

	/**
	 * Validate the request and deliver the package.
	 *
	 * @return void
	 */
	public static function handle(): void {
		global $wpdb;

		PackageStream::forbid_page_cache();
		if ( 'POST' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) {
			self::fail( __( 'Invalid request.', 'wp-license' ), 405 );
		}
		$license_id = isset( $_POST['license_id'] ) ? absint( wp_unslash( $_POST['license_id'] ) ) : 0;
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if ( $license_id <= 0 || ! wp_verify_nonce( $nonce, self::nonce_action( $license_id ) ) ) {
			self::fail( __( 'This download link has expired. Please reload the page and try again.', 'wp-license' ), 403 );
		}
		$requested_version = isset( $_POST['version'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['version'] ) ) ) : '';
		$user_id = get_current_user_id();
		$tables = Schema::table_names();
		$license = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['licenses']} WHERE id = %d LIMIT 1", $license_id ), ARRAY_A );
		// Same answer for "not found" and "not yours" so license IDs cannot be probed.
		if ( ! is_array( $license ) || $user_id <= 0 || ! self::is_owner( $license, $user_id ) ) {
			self::fail( __( 'This license does not belong to your account.', 'wp-license' ), 403 );
		}
		$scope = self::download_scope( $license );
		if ( null === $scope ) {
			self::fail( __( 'An active license is required to download this product.', 'wp-license' ), 403 );
		}
		$release = self::release_for_download( $license, $requested_version, $scope );
		if ( null === $release ) {
			self::fail(
				'' === $requested_version
					? __( 'No download is available for this product yet.', 'wp-license' )
					: __( 'This version is not available for download with this license.', 'wp-license' ),
				404
			);
		}

		if ( ReleaseRegistry::STORAGE_LOCAL === $release['storage'] ) {
			$token = PackageStream::issue( $release, $license_id, PackageStream::SOURCE_CUSTOMER );
			$target = '' === $token ? '' : PackageStream::stream_url( $token );
			$token_hash = '' === $token ? '' : hash( 'sha256', $token );
		} else {
			$target = apply_filters( 'wplc_resolve_update_package_url', (string) $release['package_url'], $license_id );
			$target = is_string( $target ) && PackageStream::is_trusted_url( $target ) && \WPLC\Api\V1\UpdateService::may_redirect_to( $target, (string) $release['package_url'] ) ? $target : '';
			// Recorded as an already consumed grant: it only feeds the hourly limit.
			$token_hash = '' === $target ? '' : self::record_external_download( (int) $release['id'], $license_id );
		}
		if ( '' === $target || '' === $token_hash ) {
			self::fail( __( 'The download could not be prepared. Please try again later.', 'wp-license' ), 503 );
		}

		// Counted after the insert, so concurrent requests cannot exceed the limit together.
		if ( self::downloads_in_last_hour( $license_id ) > self::hourly_limit() ) {
			$wpdb->delete( $tables['package_streams'], array( 'token_hash' => $token_hash ), array( '%s' ) );
			self::fail( __( 'Too many downloads for this license. Please try again in an hour.', 'wp-license' ), 429 );
		}

		AuditLog::record(
			'package_downloaded',
			'license',
			$license_id,
			'',
			null,
			array(
				'release_id' => (int) $release['id'],
				'product_key' => (string) $release['product_key'],
				'version' => (string) $release['version'],
				'storage' => (string) $release['storage'],
			),
			$user_id
		);

		nocache_headers();
		// The target is either this site's streaming route or a host from the explicit allow-list.
		wp_redirect( $target, 302, 'WP License Captain' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	/**
	 * Downloads per license and hour.
	 *
	 * @return int
	 */
	public static function hourly_limit(): int {
		$limit = (int) apply_filters( 'wplc_customer_download_hourly_limit', self::DEFAULT_HOURLY_LIMIT );

		return max( 1, min( 1000, $limit ) );
	}

	/**
	 * Pick the release for a download request.
	 *
	 * @param array<string,mixed> $license License row.
	 * @param string              $requested_version Version chosen in the form, or an empty string for the latest.
	 * @param string              $scope Result of download_scope().
	 * @return array<string,mixed>|null
	 */
	private static function release_for_download( array $license, string $requested_version, string $scope ): ?array {
		$product_key = (string) $license['product_key'];
		if ( '' === $requested_version ) {
			$versions = ReleaseRegistry::published_versions( $product_key, ReleaseRegistry::DEFAULT_CHANNEL, 1, $scope );
			$requested_version = $versions ? (string) $versions[0]['version'] : '';
		}
		if ( '' === $requested_version || ! preg_match( ReleaseRegistry::VERSION_PATTERN, $requested_version ) ) {
			return null;
		}
		$release = ReleaseRegistry::published_version( $product_key, $requested_version );
		if ( null === $release ) {
			return null;
		}
		if ( '' !== $scope && ( empty( $release['published_at'] ) || (string) $release['published_at'] > $scope ) ) {
			return null;
		}

		return $release;
	}

	/**
	 * URL the account form posts to: the customer account, never wp-admin.
	 *
	 * @return string
	 */
	private static function form_url(): string {
		// The page the license list is rendered on (account endpoint or a page with the shortcode),
		// so a refused download shows its message where the customer clicked.
		$url = \WPLC\Account\CustomerPortal::form_url();
		if ( '' !== $url ) {
			return $url;
		}
		$url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : '';

		return '' !== $url ? $url : home_url( '/' );
	}

	/**
	 * Whether the user owns the license.
	 *
	 * @param array<string,mixed> $license License row.
	 * @param int                 $user_id User ID.
	 * @return bool
	 */
	private static function is_owner( array $license, int $user_id ): bool {
		return $user_id > 0 && (int) ( $license['customer_id'] ?? 0 ) === $user_id;
	}

	/**
	 * Count customer downloads of the last hour, including the current one.
	 *
	 * @param int $license_id License ID.
	 * @return int
	 */
	private static function downloads_in_last_hour( int $license_id ): int {
		global $wpdb;

		$tables = Schema::table_names();

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$tables['package_streams']} WHERE license_id = %d AND source = %s AND created_at > %s",
				$license_id,
				PackageStream::SOURCE_CUSTOMER,
				gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS )
			)
		);
	}

	/**
	 * Record a download that is served by an external host.
	 *
	 * @param int $release_id Release ID.
	 * @param int $license_id License ID.
	 * @return string Row token hash, or an empty string on failure.
	 */
	private static function record_external_download( int $release_id, int $license_id ): string {
		global $wpdb;

		$tables = Schema::table_names();
		$now = gmdate( 'Y-m-d H:i:s' );
		// Already expired and used long ago: this row can never be redeemed, not even in the reuse window.
		$past = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$token_hash = hash( 'sha256', bin2hex( random_bytes( 32 ) ) );
		$inserted = $wpdb->insert(
			$tables['package_streams'],
			array(
				'token_hash' => $token_hash,
				'release_id' => $release_id,
				'license_id' => $license_id,
				'source' => PackageStream::SOURCE_CUSTOMER,
				'expires_at' => $now,
				'consumed_at' => $past,
				'created_at' => $now,
			),
			array( '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		return 1 === (int) $inserted ? $token_hash : '';
	}

	/**
	 * Stop with a translated message.
	 *
	 * @param string $message Message.
	 * @param int    $status HTTP status.
	 * @return void
	 */
	private static function fail( string $message, int $status ): void {
		wp_die(
			esc_html( $message ),
			esc_html__( 'Download unavailable', 'wp-license' ),
			array(
				'response' => (int) $status,
				'back_link' => true,
			)
		);
	}
}
