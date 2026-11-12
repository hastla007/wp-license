<?php
/**
 * WooCommerce customer license account page.
 *
 * @package WPLC
 */

namespace WPLC\Account;

use WPLC\Infrastructure\Database\Schema;
use WPLC\License\AuditLog;
use WPLC\License\LicenseKey;
use WPLC\License\LicenseLifecycle;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a self-service license area to WooCommerce My Account.
 */
final class CustomerPortal {

	/**
	 * Register endpoint, menu, renderer and form handlers.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'init', array( self::class, 'register_endpoint' ) );
		add_filter( 'woocommerce_account_menu_items', array( self::class, 'add_menu_item' ) );
		add_action( 'woocommerce_account_wplc-licenses_endpoint', array( self::class, 'render' ) );
		add_action( 'admin_post_wplc_deactivate_site', array( self::class, 'handle_deactivate' ) );
		add_action( 'admin_post_wplc_cancel_license', array( self::class, 'handle_cancel' ) );
	}

	/**
	 * Register the endpoint used by My Account.
	 *
	 * @return void
	 */
	public static function register_endpoint(): void {
		add_rewrite_endpoint( 'wplc-licenses', EP_ROOT | EP_PAGES );
	}

	/**
	 * Add a customer account menu entry.
	 *
	 * @param array<string,string> $items Account menu.
	 * @return array<string,string>
	 */
	public static function add_menu_item( array $items ): array {
		$logout = $items['customer-logout'] ?? null;
		unset( $items['customer-logout'] );
		$items['wplc-licenses'] = __( 'Licenses', 'wp-license' );
		if ( null !== $logout ) {
			$items['customer-logout'] = $logout;
		}

		return $items;
	}

	/**
	 * Render the current customer's licenses and site activations.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! is_user_logged_in() ) {
			echo esc_html__( 'Please sign in to view your licenses.', 'wp-license' );
			return;
		}

		global $wpdb;
		$tables = Schema::table_names();
		$licenses = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$tables['licenses']} WHERE customer_id = %d ORDER BY created_at DESC",
				get_current_user_id()
			),
			ARRAY_A
		);

		echo '<h2>' . esc_html__( 'Your licenses', 'wp-license' ) . '</h2>';
		if ( ! $licenses ) {
			echo '<p>' . esc_html__( 'No licenses are associated with this account.', 'wp-license' ) . '</p>';
			return;
		}

		foreach ( $licenses as $license ) {
			$license_id = (int) $license['id'];
			$activations = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$tables['activations']} WHERE license_id = %d ORDER BY activated_at DESC",
					$license_id
				),
				ARRAY_A
			);
			$site_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$tables['activations']} WHERE license_id = %d AND environment = %s AND status = %s",
					$license_id,
					'production',
					'active'
				)
			);

			echo '<section class="woocommerce-MyAccount-content wplc-license">';
			echo '<h3>' . esc_html( (string) $license['product_key'] . ' — ' . (string) $license['plan_key'] ) . '</h3>';
			echo '<p><strong>' . esc_html__( 'Status:', 'wp-license' ) . '</strong> ' . esc_html( (string) $license['status'] ) . '</p>';
			echo '<p><strong>' . esc_html__( 'License key:', 'wp-license' ) . '</strong> <code>' . esc_html( LicenseKey::for_uuid( (string) $license['license_uuid'] ) ) . '</code></p>';
			echo '<p><strong>' . esc_html__( 'Valid through:', 'wp-license' ) . '</strong> ' . esc_html( $license['paid_until'] ? (string) $license['paid_until'] : __( 'No expiry', 'wp-license' ) ) . '</p>';
			echo '<p><strong>' . esc_html__( 'Live sites:', 'wp-license' ) . '</strong> ' . esc_html( (string) $site_count ) . '</p>';

			if ( ! empty( $license['cancel_at_period_end'] ) ) {
				echo '<p>' . esc_html__( 'Cancellation is scheduled for the end of the paid period.', 'wp-license' ) . '</p>';
			} elseif ( in_array( $license['status'], array( 'active', 'past_due' ), true ) ) {
				self::render_cancel_form( $license_id );
			}

			echo '<h4>' . esc_html__( 'Sites', 'wp-license' ) . '</h4>';
			if ( ! $activations ) {
				echo '<p>' . esc_html__( 'No sites are activated.', 'wp-license' ) . '</p>';
			} else {
				echo '<table class="shop_table shop_table_responsive"><thead><tr>';
				echo '<th>' . esc_html__( 'Site', 'wp-license' ) . '</th><th>' . esc_html__( 'Environment', 'wp-license' ) . '</th><th>' . esc_html__( 'Status', 'wp-license' ) . '</th><th>' . esc_html__( 'Actions', 'wp-license' ) . '</th>';
				echo '</tr></thead><tbody>';
				foreach ( $activations as $activation ) {
					echo '<tr><td>' . esc_html( (string) $activation['site_url'] ) . '</td>';
					echo '<td>' . esc_html( (string) $activation['environment'] ) . '</td>';
					echo '<td>' . esc_html( (string) $activation['status'] ) . '</td><td>';
					if ( 'active' === $activation['status'] ) {
						self::render_deactivate_form( (int) $activation['id'] );
					}
					echo '</td></tr>';
				}
				echo '</tbody></table>';
			}
			echo '</section>';
		}
	}

	/**
	 * Handle a customer site deactivation.
	 *
	 * @return void
	 */
	public static function handle_deactivate(): void {
		$activation_id = isset( $_POST['activation_id'] ) ? absint( $_POST['activation_id'] ) : 0;
		if ( ! is_user_logged_in() || ! $activation_id ) {
			wp_die( esc_html__( 'You are not allowed to deactivate this site.', 'wp-license' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'wplc_deactivate_site_' . $activation_id );

		global $wpdb;
		$tables = Schema::table_names();
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT a.* FROM {$tables['activations']} a INNER JOIN {$tables['licenses']} l ON l.id = a.license_id WHERE a.id = %d AND l.customer_id = %d LIMIT 1",
				$activation_id,
				get_current_user_id()
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			wp_die( esc_html__( 'The site activation was not found for this account.', 'wp-license' ), '', array( 'response' => 404 ) );
		}

		if ( 'active' === $row['status'] ) {
			$now = gmdate( 'Y-m-d H:i:s' );
			$updated = $wpdb->update(
				$tables['activations'],
				array( 'status' => 'deactivated', 'deactivated_at' => $now, 'last_seen_at' => $now ),
				array( 'id' => $activation_id, 'status' => 'active' ),
				array( '%s', '%s', '%s' ),
				array( '%d', '%s' )
			);
			if ( false === $updated ) {
				wp_die( esc_html__( 'The site could not be deactivated. Please retry.', 'wp-license' ), '', array( 'response' => 500 ) );
			}
			if ( 1 === $updated ) {
				AuditLog::record(
					'site_deactivated',
					'activation',
					$activation_id,
					'Deactivated by license owner',
					array( 'status' => 'active', 'site_url' => $row['site_url'] ),
					array( 'status' => 'deactivated' ),
					get_current_user_id()
				);
			}
		}

		self::redirect_to_account();
	}

	/**
	 * Handle a customer cancellation request.
	 *
	 * @return void
	 */
	public static function handle_cancel(): void {
		$license_id = isset( $_POST['license_id'] ) ? absint( $_POST['license_id'] ) : 0;
		if ( ! is_user_logged_in() || ! $license_id ) {
			wp_die( esc_html__( 'You are not allowed to cancel this license.', 'wp-license' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'wplc_cancel_license_' . $license_id );

		global $wpdb;
		$tables = Schema::table_names();
		$owner = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT customer_id FROM {$tables['licenses']} WHERE id = %d LIMIT 1", $license_id )
		);
		if ( get_current_user_id() !== $owner ) {
			wp_die( esc_html__( 'The license was not found for this account.', 'wp-license' ), '', array( 'response' => 404 ) );
		}

		if ( ! LicenseLifecycle::schedule_cancellation( $license_id, 'Customer requested cancellation', get_current_user_id() ) ) {
			wp_die( esc_html__( 'The license could not be scheduled for cancellation. Check its status and retry.', 'wp-license' ), '', array( 'response' => 409 ) );
		}
		self::redirect_to_account();
	}

	/**
	 * Render a POST form to deactivate an activation.
	 *
	 * @param int $activation_id Activation ID.
	 * @return void
	 */
	private static function render_deactivate_form( int $activation_id ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="wplc_deactivate_site">';
		echo '<input type="hidden" name="activation_id" value="' . esc_attr( (string) $activation_id ) . '">';
		wp_nonce_field( 'wplc_deactivate_site_' . $activation_id );
		echo '<button type="submit" class="button">' . esc_html__( 'Deactivate', 'wp-license' ) . '</button>';
		echo '</form>';
	}

	/**
	 * Render a POST form to cancel at period end.
	 *
	 * @param int $license_id License ID.
	 * @return void
	 */
	private static function render_cancel_form( int $license_id ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="wplc_cancel_license">';
		echo '<input type="hidden" name="license_id" value="' . esc_attr( (string) $license_id ) . '">';
		wp_nonce_field( 'wplc_cancel_license_' . $license_id );
		echo '<button type="submit" class="button">' . esc_html__( 'Cancel at period end', 'wp-license' ) . '</button>';
		echo '</form>';
	}

	/**
	 * Return to My Account.
	 *
	 * @return void
	 */
	private static function redirect_to_account(): void {
		$url = function_exists( 'wc_get_account_endpoint_url' )
			? wc_get_account_endpoint_url( 'wplc-licenses' )
			: wc_get_page_permalink( 'myaccount' );
		wp_safe_redirect( $url );
		exit;
	}
}
