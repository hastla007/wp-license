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
 * Lets a logged-in customer download the latest release of a licensed product.
 *
 * The update feed needs an activated installation, so the very first ZIP is
 * delivered here: from the customer account, to the owner of a license with
 * access, through the same single-use stream as updates.
 */
final class CustomerDownload {
	const ACTION = 'wplc_download_package';
	const DEFAULT_HOURLY_LIMIT = 10;

	/**
	 * Register the download handler.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
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
	 * Echo a download form for the latest release, or nothing when unavailable.
	 *
	 * @param array<string,mixed> $license_row License row.
	 * @return void
	 */
	public static function render_button( array $license_row ): void {
		$license_id = (int) ( $license_row['id'] ?? 0 );
		if ( $license_id <= 0 || ! is_user_logged_in() || ! self::is_owner( $license_row, get_current_user_id() ) || ! LicenseLifecycle::has_access( $license_row ) ) {
			return;
		}
		$release = ReleaseRegistry::latest_published( (string) ( $license_row['product_key'] ?? '' ) );
		if ( null === $release ) {
			return;
		}

		echo '<form method="post" class="wplc-download-package" action="' . esc_url( add_query_arg( 'action', self::ACTION, admin_url( 'admin-post.php' ) ) ) . '">';
		echo '<input type="hidden" name="license_id" value="' . esc_attr( (string) $license_id ) . '">';
		wp_nonce_field( self::nonce_action( $license_id ), '_wpnonce', false );
		echo '<button type="submit" class="button wplc-download-package__button">';
		/* translators: %s: release version number. */
		echo esc_html( sprintf( __( 'Download version %s', 'wp-license' ), (string) $release['version'] ) );
		echo '</button></form>';
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
	 * Validate the request and deliver the package.
	 *
	 * @return void
	 */
	public static function handle(): void {
		global $wpdb;

		if ( 'POST' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) {
			self::fail( __( 'Invalid request.', 'wp-license' ), 405 );
		}
		$license_id = isset( $_POST['license_id'] ) ? absint( wp_unslash( $_POST['license_id'] ) ) : 0;
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if ( $license_id <= 0 || ! wp_verify_nonce( $nonce, self::nonce_action( $license_id ) ) ) {
			self::fail( __( 'This download link has expired. Please reload the page and try again.', 'wp-license' ), 403 );
		}
		$user_id = get_current_user_id();
		$tables = Schema::table_names();
		$license = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['licenses']} WHERE id = %d LIMIT 1", $license_id ), ARRAY_A );
		// Same answer for "not found" and "not yours" so license IDs cannot be probed.
		if ( ! is_array( $license ) || $user_id <= 0 || ! self::is_owner( $license, $user_id ) ) {
			self::fail( __( 'This license does not belong to your account.', 'wp-license' ), 403 );
		}
		if ( ! LicenseLifecycle::has_access( $license ) ) {
			self::fail( __( 'An active license is required to download this product.', 'wp-license' ), 403 );
		}
		$release = ReleaseRegistry::latest_published( (string) $license['product_key'] );
		if ( null === $release ) {
			self::fail( __( 'No download is available for this product yet.', 'wp-license' ), 404 );
		}

		if ( ReleaseRegistry::STORAGE_LOCAL === $release['storage'] ) {
			$token = PackageStream::issue( $release, $license_id, PackageStream::SOURCE_CUSTOMER );
			$target = '' === $token ? '' : PackageStream::stream_url( $token );
			$token_hash = '' === $token ? '' : hash( 'sha256', $token );
		} else {
			$target = apply_filters( 'wplc_resolve_update_package_url', (string) $release['package_url'], $license_id );
			$target = is_string( $target ) && PackageStream::is_trusted_url( $target ) ? $target : '';
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
		$token_hash = hash( 'sha256', bin2hex( random_bytes( 32 ) ) );
		$inserted = $wpdb->insert(
			$tables['package_streams'],
			array(
				'token_hash' => $token_hash,
				'release_id' => $release_id,
				'license_id' => $license_id,
				'source' => PackageStream::SOURCE_CUSTOMER,
				'expires_at' => $now,
				'consumed_at' => $now,
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
