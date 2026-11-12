<?php
/**
 * WooCommerce administrator license overview.
 *
 * @package WPLC
 */

namespace WPLC\Admin;

use WPLC\Infrastructure\Database\Schema;
use WPLC\License\LicenseLifecycle;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a searchable license list and audited status actions.
 */
final class LicenseAdmin {

	/**
	 * Register admin menu and action handlers.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ) );
		add_action( 'admin_post_wplc_admin_license_status', array( self::class, 'handle_status_change' ) );
	}

	/**
	 * Add a WooCommerce submenu page.
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'WP License Captain', 'wp-license' ),
			__( 'Licenses', 'wp-license' ),
			'manage_woocommerce',
			'wplc-licenses',
			array( self::class, 'render' )
		);
	}

	/**
	 * Render a paginated list of licenses.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to view licenses.', 'wp-license' ), '', array( 'response' => 403 ) );
		}

		global $wpdb;
		$tables = Schema::table_names();
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$per_page = 50;
		$offset = ( $page - 1 ) * $per_page;
		$where = '';
		$params = array();
		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$where = ' WHERE key_prefix LIKE %s OR product_key LIKE %s OR plan_key LIKE %s ';
			$params = array( $like, $like, $like );
		}

		$count_sql = "SELECT COUNT(*) FROM {$tables['licenses']}{$where}";
		$total = $params
			? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) )
			: (int) $wpdb->get_var( $count_sql );
		$list_sql = "SELECT * FROM {$tables['licenses']}{$where} ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$params[] = $per_page;
		$params[] = $offset;
		$licenses = $wpdb->get_results( $wpdb->prepare( $list_sql, $params ), ARRAY_A );

		echo '<div class="wrap"><h1>' . esc_html__( 'WP License Captain — Licenses', 'wp-license' ) . '</h1>';
		echo '<form method="get"><input type="hidden" name="page" value="wplc-licenses">';
		echo '<p class="search-box"><label class="screen-reader-text" for="wplc-search">' . esc_html__( 'Search licenses', 'wp-license' ) . '</label>';
		echo '<input id="wplc-search" type="search" name="s" value="' . esc_attr( $search ) . '">';
		echo '<input type="submit" class="button" value="' . esc_attr__( 'Search', 'wp-license' ) . '"></p></form>';

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'License', 'wp-license' ) . '</th><th>' . esc_html__( 'Customer', 'wp-license' ) . '</th><th>' . esc_html__( 'Product / Plan', 'wp-license' ) . '</th><th>' . esc_html__( 'Status', 'wp-license' ) . '</th><th>' . esc_html__( 'Paid until', 'wp-license' ) . '</th><th>' . esc_html__( 'Sites', 'wp-license' ) . '</th><th>' . esc_html__( 'Actions', 'wp-license' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( ! $licenses ) {
			echo '<tr><td colspan="7">' . esc_html__( 'No licenses found.', 'wp-license' ) . '</td></tr>';
		}
		foreach ( $licenses as $license ) {
			$customer = get_userdata( (int) $license['customer_id'] );
			$site_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$tables['activations']} WHERE license_id = %d AND environment = %s AND status = %s",
					(int) $license['id'],
					'production',
					'active'
				)
			);
			echo '<tr>';
			echo '<td><code>' . esc_html( (string) $license['key_prefix'] ) . '</code> <small>#' . esc_html( (string) $license['id'] ) . '</small></td>';
			echo '<td>' . esc_html( $customer ? $customer->user_email : (string) $license['customer_id'] ) . '</td>';
			echo '<td>' . esc_html( (string) $license['product_key'] . ' / ' . (string) $license['plan_key'] ) . '</td>';
			echo '<td>' . esc_html( (string) $license['status'] ) . ( ! empty( $license['cancel_at_period_end'] ) ? ' · ' . esc_html__( 'cancels at period end', 'wp-license' ) : '' ) . '</td>';
			echo '<td>' . esc_html( $license['paid_until'] ? (string) $license['paid_until'] : '—' ) . '</td>';
			echo '<td>' . esc_html( (string) $site_count ) . '</td>';
			echo '<td>';
			if ( 'active' === $license['status'] ) {
				self::render_action_form( (int) $license['id'], 'suspended', __( 'Suspend', 'wp-license' ) );
				self::render_action_form( (int) $license['id'], 'revoked', __( 'Revoke', 'wp-license' ) );
			} elseif ( 'suspended' === $license['status'] ) {
				self::render_action_form( (int) $license['id'], 'active', __( 'Reactivate', 'wp-license' ) );
			}
			echo '</td></tr>';
		}

		echo '</tbody></table>';
		$pages = (int) ceil( $total / $per_page );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			for ( $i = 1; $i <= $pages; $i++ ) {
				$url = add_query_arg(
					array(
						'page' => 'wplc-licenses',
						'paged' => $i,
						's' => $search,
					),
					admin_url( 'admin.php' )
				);
				echo '<a class="button ' . ( $i === $page ? 'button-primary' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( (string) $i ) . '</a> ';
			}
			echo '</div></div>';
		}
		echo '</div>';
	}

	/**
	 * Handle an administrator status transition.
	 *
	 * @return void
	 */
	public static function handle_status_change(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to change licenses.', 'wp-license' ), '', array( 'response' => 403 ) );
		}

		$license_id = isset( $_POST['license_id'] ) ? absint( $_POST['license_id'] ) : 0;
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		$reason = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';
		check_admin_referer( 'wplc_admin_license_status_' . $license_id );

		LicenseLifecycle::admin_set_status( $license_id, $status, $reason, get_current_user_id() );
		wp_safe_redirect( admin_url( 'admin.php?page=wplc-licenses' ) );
		exit;
	}

	/**
	 * Render a protected status action form.
	 *
	 * @param int $license_id License ID.
	 * @param string $status Target status.
	 * @param string $label Button label.
	 * @return void
	 */
	private static function render_action_form( int $license_id, string $status, string $label ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 4px 4px 0">';
		echo '<input type="hidden" name="action" value="wplc_admin_license_status">';
		echo '<input type="hidden" name="license_id" value="' . esc_attr( (string) $license_id ) . '">';
		echo '<input type="hidden" name="status" value="' . esc_attr( $status ) . '">';
		echo '<input type="text" name="reason" required maxlength="255" placeholder="' . esc_attr__( 'Reason', 'wp-license' ) . '" aria-label="' . esc_attr__( 'Reason for status change', 'wp-license' ) . '" style="width:120px">';
		wp_nonce_field( 'wplc_admin_license_status_' . $license_id );
		echo '<button class="button" type="submit">' . esc_html( $label ) . '</button></form>';
	}
}
