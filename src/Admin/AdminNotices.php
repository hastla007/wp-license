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
			// Renewal review (T-154, T-137).
			'review_booked'             => array( 'success', __( 'The renewal was booked and the license extended.', 'wp-license' ) ),
			'review_marked_not_paid'    => array( 'success', __( 'The renewal was recorded as not paid. The license is past due and automatic retries continue.', 'wp-license' ) ),
			'review_closed'             => array( 'success', __( 'The review was closed without extending the license. Make any refund in the WooCommerce order.', 'wp-license' ) ),
			'review_missing_reason'     => array( 'error', __( 'A reason is required for this action.', 'wp-license' ) ),
			'review_not_open'           => array( 'error', __( 'This renewal no longer needs review. It may already have been resolved.', 'wp-license' ) ),
			'review_busy'               => array( 'error', __( 'This renewal is being processed right now. Try again in a minute.', 'wp-license' ) ),
			'review_action_not_allowed' => array( 'error', __( 'This action is not available for this renewal.', 'wp-license' ) ),
			'review_license_status'     => array( 'error', __( 'The license is suspended or revoked and cannot be extended. Reactivate it first, or close the review and refund the payment.', 'wp-license' ) ),
			'review_book_failed'        => array( 'error', __( 'The renewal could not be booked. Check the license status and try again.', 'wp-license' ) ),
			// License list bulk actions (T-102).
			'bulk_done'                 => array( 'success', __( 'The bulk action was processed. The result for every license is shown below.', 'wp-license' ) ),
			'bulk_already_done'         => array( 'error', __( 'This bulk action was already processed and was not run again. Its result is shown below. Reload the list to start a new one.', 'wp-license' ) ),
			'bulk_in_progress'          => array( 'error', __( 'This bulk action is still being processed. Reload the list in a minute to see the result.', 'wp-license' ) ),
			'bulk_missing_input'        => array( 'error', __( 'Select at least one license, an action and enter a reason.', 'wp-license' ) ),
			'bulk_too_many'             => array( 'error', __( 'Too many licenses were selected. Select at most 100 licenses per bulk action.', 'wp-license' ) ),
			// Plan change (T-110).
			'plan_changed'              => array( 'success', __( 'The plan was changed. Future renewals use the new plan.', 'wp-license' ) ),
			'plan_change_scheduled'     => array( 'success', __( 'The plan change is scheduled for the next renewal.', 'wp-license' ) ),
			'plan_change_withdrawn'     => array( 'success', __( 'The scheduled plan change was withdrawn.', 'wp-license' ) ),
			'plan_change_missing_input' => array( 'error', __( 'A license, a plan and a reason are required.', 'wp-license' ) ),
			'plan_change_unavailable'   => array( 'error', __( 'The plan was not changed. The license must be active or past due, and the plan must be an active catalog plan of the same product and billing interval with a price in the license currency.', 'wp-license' ) ),
			'plan_change_busy'          => array( 'error', __( 'The license or its renewal is being processed right now. Try again in a minute.', 'wp-license' ) ),
			'plan_change_not_withdrawn' => array( 'error', __( 'There is no scheduled plan change to withdraw.', 'wp-license' ) ),
			'extend_stale'            => array( 'error', __( 'The term was not changed: someone else changed this license after you opened the page. Check the current end date below and try again.', 'wp-license' ) ),
			'extend_processing'       => array( 'error', __( 'The term was not changed: an automatic payment for this license is being processed right now. Try again in a few minutes.', 'wp-license' ) ),
			'create_duplicate'        => array( 'success', __( 'This form was already submitted. The license below was created by the first submission; no second license was issued.', 'wp-license' ) ),
			'key_reissued'            => array( 'success', __( 'A new license key was issued. The old key no longer works. The customer finds the new key in their account.', 'wp-license' ) ),
			'key_reissue_failed'      => array( 'error', __( 'The license key was not replaced. A reason is required, and the key of a revoked license cannot be replaced.', 'wp-license' ) ),
			'key_resend_requested'    => array( 'success', __( 'The email with the license key was requested for the customer.', 'wp-license' ) ),
			'key_resend_failed'       => array( 'error', __( 'The key email was not requested. A reason is required, the license needs a customer, and the key must be verifiable with the configured secret.', 'wp-license' ) ),
			'owner_changed'           => array( 'success', __( 'The license now belongs to the new customer. Automatic renewal was switched off because the stored payment method belongs to the previous owner.', 'wp-license' ) ),
			'owner_missing_input'     => array( 'error', __( 'A license, the new customer and a reason are required.', 'wp-license' ) ),
			'owner_unknown_customer'  => array( 'error', __( 'No user account was found for this user ID or email address.', 'wp-license' ) ),
			'owner_stale'             => array( 'error', __( 'The owner was not changed: the license was changed after you opened the page. Check it and try again.', 'wp-license' ) ),
			'owner_processing'        => array( 'error', __( 'The owner was not changed: an automatic payment for this license is being processed right now. Try again in a few minutes.', 'wp-license' ) ),
			'owner_failed'            => array( 'error', __( 'The owner was not changed. The license may already belong to this customer.', 'wp-license' ) ),
			'license_restored'        => array( 'success', __( 'The license is active again with the new end date.', 'wp-license' ) ),
			'restore_missing_input'   => array( 'error', __( 'A license, a new end date in the future and a reason are required.', 'wp-license' ) ),
			'restore_stale'           => array( 'error', __( 'The license was not restored: its status changed after you opened the page.', 'wp-license' ) ),
			'restore_failed'          => array( 'error', __( 'The license was not restored. Check the license status and the end date.', 'wp-license' ) ),
			'restore_forbidden'       => array( 'error', __( 'Restoring a revoked license needs an administrator.', 'wp-license' ) ),
			/* translators: 1: number of extended licenses, 2: number of skipped licenses. */
			'bulk_extended'           => array( 'success', __( 'Terms extended: %1$d. Skipped: %2$d (suspended, revoked, changed in the meantime or with a payment in progress).', 'wp-license' ) ),
			'bulk_extend_missing_input' => array( 'error', __( 'Select at least one license and enter the number of days (1 to 3650) and a reason.', 'wp-license' ) ),
			/* translators: 1: number of created licenses. */
			'order_licenses_issued'   => array( 'success', __( 'Licenses created for the order: %1$d. If this is 0, the order already has all its licenses or contains no license product.', 'wp-license' ) ),
			'order_issue_failed'      => array( 'error', __( 'No licenses were issued. Enter the number of a paid order and a reason.', 'wp-license' ) ),
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
	 * @param int[]  $numbers  Optional numbers for messages with placeholders.
	 * @return void
	 */
	public static function redirect( string $code, string $fallback = '', array $numbers = array() ): void {
		wp_safe_redirect( self::with_numbers( self::target_url( $code, $fallback ), $numbers ) );
		exit;
	}

	/**
	 * Append up to two non-negative integers that a message shows as %1$d and %2$d.
	 *
	 * @param string $url     Target URL.
	 * @param int[]  $numbers Numbers.
	 * @return string
	 */
	public static function with_numbers( string $url, array $numbers ): string {
		$numbers = array_values( $numbers );
		$args    = array();
		foreach ( array( 'wplc_n', 'wplc_m' ) as $index => $name ) {
			if ( isset( $numbers[ $index ] ) ) {
				$args[ $name ] = max( 0, (int) $numbers[ $index ] );
			}
		}

		return $args ? add_query_arg( $args, $url ) : $url;
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
			? remove_query_arg( array( self::QUERY_ARG, 'wplc_n', 'wplc_m', '_wpnonce', '_wp_http_referer' ), $referer )
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
		$first  = isset( $_GET['wplc_n'] ) ? absint( $_GET['wplc_n'] ) : 0;
		$second = isset( $_GET['wplc_m'] ) ? absint( $_GET['wplc_m'] ) : 0;
		// phpcs:enable
		if ( '' === $code || 0 !== strpos( $page, 'wplc-' ) ) {
			return;
		}
		if ( ! Capabilities::can( 'view_licenses' ) && ! Capabilities::can( 'view_diagnostics' ) && ! Capabilities::can( 'manage_settings' ) ) {
			return;
		}

		$message = self::message_for( $code );
		if ( null === $message ) {
			return;
		}

		$class = 'success' === $message[0] ? 'notice-success' : 'notice-error';
		$text  = false !== strpos( $message[1], '%1$d' ) ? sprintf( $message[1], $first, $second ) : $message[1];
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
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
		$args[] = 'wplc_n';
		$args[] = 'wplc_m';
		return $args;
	}
}
