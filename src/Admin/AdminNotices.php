<?php
/**
 * Result notices for administrator actions.
 *
 * @package WPLC
 */

namespace WPLC\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Redirects admin handlers back to the plugin screen and renders an allowlisted notice.
 */
final class AdminNotices {

	/**
	 * Query argument carrying the result code.
	 */
	public const QUERY_ARG = 'wplc_notice';

	/**
	 * Register the notice renderer.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'admin_notices', array( self::class, 'render' ) );
		add_filter( 'removable_query_args', array( self::class, 'removable_query_args' ) );
	}

	/**
	 * Allowlisted result codes.
	 *
	 * @return array<string,array{0:string,1:string}> Code => [ type, message ].
	 */
	public static function messages(): array {
		return array(
			'status_changed'          => array( 'success', __( 'The license status was changed.', 'wp-license' ) ),
			'status_missing_input'    => array( 'error', __( 'A license and a reason are required for this action.', 'wp-license' ) ),
			'status_not_applied'      => array( 'error', __( 'The status change was not applied. Check the license status, the paid period and the requested transition. To reactivate a license whose period has ended, change its term first.', 'wp-license' ) ),
			'activation_released'     => array( 'success', __( 'The site activation was released.', 'wp-license' ) ),
			'release_missing_input'   => array( 'error', __( 'A site activation and a reason are required.', 'wp-license' ) ),
			'release_not_found'       => array( 'error', __( 'The active site activation was not found. It may already have been released.', 'wp-license' ) ),
			'release_failed'          => array( 'error', __( 'The activation could not be released. Refresh and try again.', 'wp-license' ) ),
			'term_extended'           => array( 'success', __( 'The license term was changed.', 'wp-license' ) ),
			'extend_missing_input'    => array( 'error', __( 'A license, a new end date and a reason are required.', 'wp-license' ) ),
			'extend_invalid_date'     => array( 'error', __( 'The new end date is not a valid date in the future.', 'wp-license' ) ),
			'extend_failed'           => array( 'error', __( 'The license term was not changed. Check the license status and try again.', 'wp-license' ) ),
			'extend_unavailable'      => array( 'error', __( 'Changing the term is not available in this installation.', 'wp-license' ) ),
			'license_created'         => array( 'success', __( 'The license was created. The customer finds the key in their account.', 'wp-license' ) ),
			'create_missing_input'    => array( 'error', __( 'Customer, plan, end date and reason are required.', 'wp-license' ) ),
			'create_unknown_customer' => array( 'error', __( 'No user account was found for this user ID or email address.', 'wp-license' ) ),
			'create_unknown_plan'     => array( 'error', __( 'The selected plan is not an active plan in the catalog.', 'wp-license' ) ),
			'create_invalid_date'     => array( 'error', __( 'The end date is not a valid date in the future.', 'wp-license' ) ),
			'create_failed'           => array( 'error', __( 'The license could not be created. Check the license key configuration and the database, then try again.', 'wp-license' ) ),
			'license_not_found'       => array( 'error', __( 'The license was not found.', 'wp-license' ) ),
		);
	}

	/**
	 * Look up one result code.
	 *
	 * @param string $code Result code.
	 * @return array{0:string,1:string}|null
	 */
	public static function message_for( string $code ): ?array {
		$messages = self::messages();
		return isset( $messages[ $code ] ) ? $messages[ $code ] : null;
	}

	/**
	 * Whether a URL points at one of this plugin's admin screens.
	 *
	 * @param string $url       Candidate URL (typically the referer).
	 * @param string $admin_url Absolute URL of wp-admin/admin.php.
	 * @return bool
	 */
	public static function is_plugin_screen_url( string $url, string $admin_url ): bool {
		$candidate = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure helper, PHP 7.4+.
		$admin     = parse_url( $admin_url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		if ( ! is_array( $candidate ) || ! is_array( $admin ) || empty( $candidate['path'] ) || empty( $admin['path'] ) ) {
			return false;
		}
		if ( isset( $candidate['host'] ) && strtolower( (string) $candidate['host'] ) !== strtolower( (string) ( $admin['host'] ?? '' ) ) ) {
			return false;
		}
		if ( $candidate['path'] !== $admin['path'] ) {
			return false;
		}
		$query = array();
		parse_str( (string) ( $candidate['query'] ?? '' ), $query );

		return isset( $query['page'] ) && is_string( $query['page'] ) && 1 === preg_match( '/^wplc-[a-z0-9-]+$/', $query['page'] );
	}

	/**
	 * Redirect to the referring plugin screen (or a fallback) with a result code.
	 *
	 * @param string $code     Allowlisted result code.
	 * @param string $fallback Target when the referer is not a plugin screen.
	 * @return void
	 */
	public static function redirect( string $code, string $fallback = '' ): void {
		wp_safe_redirect( self::target_url( $code, $fallback ) );
		exit;
	}

	/**
	 * Redirect to a fixed plugin screen with a result code.
	 *
	 * @param string $code Allowlisted result code.
	 * @param string $url  Admin URL.
	 * @return void
	 */
	public static function redirect_to( string $code, string $url ): void {
		wp_safe_redirect( add_query_arg( self::QUERY_ARG, sanitize_key( $code ), $url ) );
		exit;
	}

	/**
	 * Build the redirect target for a result code.
	 *
	 * @param string $code     Allowlisted result code.
	 * @param string $fallback Target when the referer is not a plugin screen.
	 * @return string
	 */
	public static function target_url( string $code, string $fallback = '' ): string {
		$default = '' !== $fallback ? $fallback : admin_url( 'admin.php?page=wplc-licenses' );
		$referer = wp_get_referer();
		$target  = is_string( $referer ) && self::is_plugin_screen_url( $referer, admin_url( 'admin.php' ) )
			? remove_query_arg( array( self::QUERY_ARG, '_wpnonce', '_wp_http_referer' ), $referer )
			: $default;

		return add_query_arg( self::QUERY_ARG, sanitize_key( $code ), $target );
	}

	/**
	 * Render the notice for the current request on plugin screens.
	 *
	 * @return void
	 */
	public static function render(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only display of an allowlisted code.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$code = isset( $_GET[ self::QUERY_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::QUERY_ARG ] ) ) : '';
		// phpcs:enable
		if ( '' === $code || 0 !== strpos( $page, 'wplc-' ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$message = self::message_for( $code );
		if ( null === $message ) {
			return;
		}

		$class = 'success' === $message[0] ? 'notice-success' : 'notice-error';
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $message[1] ) . '</p></div>';
	}

	/**
	 * Let WordPress strip the result code from the address bar.
	 *
	 * @param string[] $args Removable query arguments.
	 * @return string[]
	 */
	public static function removable_query_args( $args ) {
		$args   = is_array( $args ) ? $args : array();
		$args[] = self::QUERY_ARG;
		return $args;
	}
}
