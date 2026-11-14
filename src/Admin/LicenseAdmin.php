<?php
/**
 * WooCommerce administrator license screens and actions.
 *
 * @package WPLC
 */

namespace WPLC\Admin;

use WPLC\Infrastructure\Database\Schema;
use WPLC\License\AuditLog;
use WPLC\License\LicenseIssuer;
use WPLC\License\LicenseLifecycle;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * License list, detail screen and audited administrator actions.
 *
 * Expected failures redirect back with a notice (see AdminNotices); only a
 * missing capability or an invalid nonce ends in wp_die().
 */
final class LicenseAdmin {

	/**
	 * Admin page slug.
	 */
	public const PAGE = 'wplc-licenses';

	/**
	 * Register admin menu and action handlers.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
		add_action( 'admin_post_wplc_admin_license_status', array( self::class, 'handle_status_change' ) );
		add_action( 'admin_post_wplc_admin_release_activation', array( self::class, 'handle_release_activation' ) );
		add_action( 'admin_post_wplc_admin_extend_license', array( self::class, 'handle_extend' ) );
		add_action( 'admin_post_wplc_admin_add_license', array( self::class, 'handle_add_license' ) );
		add_action( 'admin_post_wplc_admin_license_search', array( self::class, 'handle_search' ) );
		AdminNotices::register_hooks();
		Settings::register_hooks();
		RenewalReviewAdmin::register_hooks();
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
			self::PAGE,
			array( self::class, 'render' )
		);
	}

	/**
	 * Load styles and the confirmation script on the license screens.
	 *
	 * @return void
	 */
	public static function enqueue_assets(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Screen detection only.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::PAGE !== $page ) {
			return;
		}
		wp_enqueue_style( 'wplc-admin', plugins_url( 'assets/css/wplc-admin.css', WPLC_FILE ), array(), WPLC_VERSION );
		wp_enqueue_script( 'wplc-ui', plugins_url( 'assets/js/wplc-ui.js', WPLC_FILE ), array(), WPLC_VERSION, true );
	}

	/**
	 * URL of the license list.
	 *
	 * @return string
	 */
	public static function list_url(): string {
		return admin_url( 'admin.php?page=' . self::PAGE );
	}

	/**
	 * URL of one license detail screen.
	 *
	 * @param int $license_id License ID.
	 * @return string
	 */
	public static function detail_url( int $license_id ): string {
		return add_query_arg(
			array(
				'page'    => self::PAGE,
				'license' => $license_id,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * URL of the "Add license manually" form.
	 *
	 * @return string
	 */
	public static function add_url(): string {
		return add_query_arg(
			array(
				'page' => self::PAGE,
				'view' => 'add',
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Escaped customer cell: email linked to the user profile.
	 *
	 * @param int $customer_id WordPress user ID.
	 * @return string HTML.
	 */
	public static function customer_html( int $customer_id ): string {
		if ( $customer_id <= 0 ) {
			return '—';
		}
		$user = get_userdata( $customer_id );
		if ( ! $user ) {
			/* translators: %d: user ID. */
			return esc_html( sprintf( __( 'Deleted user #%d', 'wp-license' ), $customer_id ) );
		}
		$link = get_edit_user_link( $customer_id );
		$text = esc_html( (string) $user->user_email );

		return $link ? '<a href="' . esc_url( $link ) . '">' . $text . '</a>' : $text;
	}

	/**
	 * Route to the list, the detail screen or the manual form.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to view licenses.', 'wp-license' ), '', array( 'response' => 403 ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only routing.
		$license_id = isset( $_GET['license'] ) ? absint( $_GET['license'] ) : 0;
		$view       = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
		// phpcs:enable

		if ( $license_id ) {
			LicenseDetail::render( $license_id );
			return;
		}
		if ( 'add' === $view ) {
			LicenseDetail::render_add_form();
			return;
		}

		self::render_list();
	}

	/**
	 * Render the license list.
	 *
	 * @return void
	 */
	private static function render_list(): void {
		if ( ! class_exists( '\\WP_List_Table' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		}

		$table = new LicenseListTable();
		$table->prepare_items();
		$filters = LicenseListTable::request_filters();

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Licenses', 'wp-license' ) . '</h1> ';
		echo '<a href="' . esc_url( self::add_url() ) . '" class="page-title-action">' . esc_html__( 'Add license manually', 'wp-license' ) . '</a>';
		$search = $filters['search'];
		if ( null !== $search ) {
			echo '<span class="subtitle">';
			echo esc_html(
				LicenseSearch::TYPE_LICENSE_KEY_HASH === $search['type']
					? __( 'Search results for the pasted license key', 'wp-license' )
					/* translators: %s: search term. */
					: sprintf( __( 'Search results for: %s', 'wp-license' ), $search['label'] )
			);
			echo '</span>';
		}
		echo '<hr class="wp-header-end">';
		if ( $filters['search_expired'] ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'This search has expired. Search again to see the results.', 'wp-license' ) . '</p></div>';
		}

		// The search is POSTed (T-141): the term must not appear in URLs, sort and
		// page links, browser history or access logs. The handler redirects back
		// with an opaque token only.
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wplc-license-search">';
		echo '<input type="hidden" name="action" value="wplc_admin_license_search">';
		wp_nonce_field( 'wplc_admin_license_search' );
		foreach ( array( 'status', 'product' ) as $field ) {
			if ( '' !== $filters[ $field ] ) {
				echo '<input type="hidden" name="' . esc_attr( $field ) . '" value="' . esc_attr( $filters[ $field ] ) . '">';
			}
		}
		self::render_order_fields( $filters );
		$label = __( 'Search licenses', 'wp-license' );
		echo '<p class="search-box">';
		echo '<label class="screen-reader-text" for="wplc-license-search-input">' . esc_html( $label ) . ':</label>';
		// A pasted key is never echoed back; other terms are shown in the page body only.
		$value = null !== $search && LicenseSearch::TYPE_LICENSE_KEY_HASH !== $search['type'] ? $search['label'] : '';
		echo '<input type="search" id="wplc-license-search-input" name="s" value="' . esc_attr( $value ) . '" autocomplete="off">';
		submit_button( $label, '', '', false, array( 'id' => 'search-submit' ) );
		echo '</p>';
		echo '<p class="description">' . esc_html__( 'Search by key prefix, product, plan, customer ID, customer email, #license ID, or paste a complete license key.', 'wp-license' ) . '</p>';
		echo '</form>';

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '">';
		if ( '' !== $filters['search_token'] ) {
			echo '<input type="hidden" name="' . esc_attr( LicenseSearchStore::QUERY_ARG ) . '" value="' . esc_attr( $filters['search_token'] ) . '">';
		}
		self::render_order_fields( $filters );
		$table->display();
		echo '</form></div>';
	}

	/**
	 * Hidden fields keeping a non-default sort order.
	 *
	 * @param array{orderby:string,order:string} $filters Current filters.
	 * @return void
	 */
	private static function render_order_fields( array $filters ): void {
		if ( 'created' !== $filters['orderby'] || 'desc' !== $filters['order'] ) {
			echo '<input type="hidden" name="orderby" value="' . esc_attr( $filters['orderby'] ) . '">';
			echo '<input type="hidden" name="order" value="' . esc_attr( $filters['order'] ) . '">';
		}
	}

	/**
	 * Store a POSTed search term and redirect to the list with an opaque token (T-141).
	 *
	 * @return void
	 */
	public static function handle_search(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to view licenses.', 'wp-license' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'wplc_admin_license_search' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each field is sanitized in search_redirect_url().
		wp_safe_redirect( self::search_redirect_url( get_current_user_id(), wp_unslash( $_POST ) ) );
		exit;
	}

	/**
	 * Build the list URL after a search: filters and sort order plus the token, never the term.
	 *
	 * @param int                 $user_id Current user ID.
	 * @param array<string,mixed> $post    Unslashed POST fields.
	 * @return string
	 */
	public static function search_redirect_url( int $user_id, array $post ): string {
		$args = array( 'page' => self::PAGE );
		foreach ( array( 'status', 'product', 'orderby', 'order' ) as $field ) {
			$value = isset( $post[ $field ] ) && is_string( $post[ $field ] ) ? sanitize_key( $post[ $field ] ) : '';
			if ( '' !== $value ) {
				$args[ $field ] = $value;
			}
		}
		$term  = isset( $post['s'] ) && is_string( $post['s'] ) ? sanitize_text_field( $post['s'] ) : '';
		$token = LicenseSearchStore::save( $user_id, $term );
		if ( '' !== $token ) {
			$args[ LicenseSearchStore::QUERY_ARG ] = $token;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Stop unauthorized requests. Only this and an invalid nonce may end in wp_die().
	 *
	 * @return void
	 */
	private static function require_capability(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to change licenses.', 'wp-license' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Read the mandatory reason of an admin action.
	 *
	 * @return string
	 */
	private static function posted_reason(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Every caller verifies its nonce.
		return isset( $_POST['reason'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) ) : '';
	}

	/**
	 * Handle an administrator status transition.
	 *
	 * @return void
	 */
	public static function handle_status_change(): void {
		self::require_capability();

		$license_id = isset( $_POST['license_id'] ) ? absint( $_POST['license_id'] ) : 0;
		check_admin_referer( 'wplc_admin_license_status_' . $license_id );

		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		$reason = self::posted_reason();
		if ( ! $license_id || '' === $reason ) {
			AdminNotices::redirect( 'status_missing_input' );
		}

		$applied = LicenseLifecycle::admin_set_status( $license_id, $status, $reason, get_current_user_id() );
		AdminNotices::redirect( $applied ? 'status_changed' : 'status_not_applied', self::detail_url( $license_id ) );
	}

	/**
	 * Release one active site activation after an administrator supplies a reason.
	 *
	 * @return void
	 */
	public static function handle_release_activation(): void {
		self::require_capability();

		$activation_id = isset( $_POST['activation_id'] ) ? absint( $_POST['activation_id'] ) : 0;
		check_admin_referer( 'wplc_admin_release_activation_' . $activation_id );

		$reason = self::posted_reason();
		if ( ! $activation_id || '' === $reason ) {
			AdminNotices::redirect( 'release_missing_input' );
		}

		global $wpdb;
		$tables     = Schema::table_names();
		$activation = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$tables['activations']} WHERE id = %d LIMIT 1", $activation_id ),
			ARRAY_A
		);
		if ( ! is_array( $activation ) || 'active' !== $activation['status'] ) {
			AdminNotices::redirect( 'release_not_found' );
		}

		$fallback = self::detail_url( (int) $activation['license_id'] );
		$manager  = '\\WPLC\\License\\ActivationManager';
		if ( class_exists( $manager ) && method_exists( $manager, 'deactivate' ) ) {
			// Shared implementation: also releases staging children and writes the audit entries.
			$released = (bool) call_user_func( array( $manager, 'deactivate' ), $activation_id, $reason, get_current_user_id(), 'admin_site_released' );
			AdminNotices::redirect( $released ? 'activation_released' : 'release_failed', $fallback );
		}

		$now     = gmdate( 'Y-m-d H:i:s' );
		$updated = $wpdb->update(
			$tables['activations'],
			array( 'status' => 'deactivated', 'deactivated_at' => $now, 'last_seen_at' => $now ),
			array( 'id' => $activation_id, 'status' => 'active' ),
			array( '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);
		if ( 1 !== (int) $updated ) {
			AdminNotices::redirect( 'release_failed', $fallback );
		}
		AuditLog::record(
			'admin_site_released',
			'activation',
			$activation_id,
			$reason,
			array( 'status' => 'active', 'site_url' => $activation['site_url'] ),
			array( 'status' => 'deactivated' ),
			get_current_user_id()
		);
		AdminNotices::redirect( 'activation_released', $fallback );
	}

	/**
	 * Change the end of a license term.
	 *
	 * @return void
	 */
	public static function handle_extend(): void {
		self::require_capability();

		$license_id = isset( $_POST['license_id'] ) ? absint( $_POST['license_id'] ) : 0;
		check_admin_referer( 'wplc_admin_extend_license_' . $license_id );

		$date   = isset( $_POST['paid_until'] ) ? sanitize_text_field( wp_unslash( $_POST['paid_until'] ) ) : '';
		$reason = self::posted_reason();
		if ( ! $license_id || '' === $date || '' === $reason ) {
			AdminNotices::redirect( 'extend_missing_input' );
		}

		$fallback   = self::detail_url( $license_id );
		$paid_until = Format::local_date_to_utc_end_of_day( $date, wp_timezone() );
		if ( '' === $paid_until || $paid_until <= gmdate( 'Y-m-d H:i:s' ) ) {
			AdminNotices::redirect( 'extend_invalid_date', $fallback );
		}

		if ( ! method_exists( LicenseLifecycle::class, 'admin_extend' ) ) {
			AdminNotices::redirect( 'extend_unavailable', $fallback );
		}

		$extended = (bool) call_user_func( array( LicenseLifecycle::class, 'admin_extend' ), $license_id, $paid_until, $reason, get_current_user_id() );
		AdminNotices::redirect( $extended ? 'term_extended' : 'extend_failed', $fallback );
	}

	/**
	 * Issue a license without an order.
	 *
	 * @return void
	 */
	public static function handle_add_license(): void {
		self::require_capability();
		check_admin_referer( 'wplc_admin_add_license' );

		$customer = isset( $_POST['customer'] ) ? sanitize_text_field( wp_unslash( $_POST['customer'] ) ) : '';
		$plan_id  = isset( $_POST['plan_id'] ) ? absint( $_POST['plan_id'] ) : 0;
		$date     = isset( $_POST['paid_until'] ) ? sanitize_text_field( wp_unslash( $_POST['paid_until'] ) ) : '';
		$reason   = self::posted_reason();
		$form_url = self::add_url();
		if ( '' === $customer || ! $plan_id || '' === $date || '' === $reason ) {
			AdminNotices::redirect( 'create_missing_input', $form_url );
		}

		$customer_id = self::resolve_customer( $customer );
		if ( ! $customer_id ) {
			AdminNotices::redirect( 'create_unknown_customer', $form_url );
		}

		global $wpdb;
		$tables = Schema::table_names();
		$plan   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$tables['plans']} WHERE id = %d AND active = 1 LIMIT 1", $plan_id ),
			ARRAY_A
		);
		if ( ! is_array( $plan ) ) {
			AdminNotices::redirect( 'create_unknown_plan', $form_url );
		}

		$paid_until = Format::local_date_to_utc_end_of_day( $date, wp_timezone() );
		if ( '' === $paid_until || $paid_until <= gmdate( 'Y-m-d H:i:s' ) ) {
			AdminNotices::redirect( 'create_invalid_date', $form_url );
		}

		$license_id = LicenseIssuer::issue_manual( $customer_id, $plan, $paid_until, get_current_user_id(), $reason );
		if ( ! $license_id ) {
			AdminNotices::redirect( 'create_failed', $form_url );
		}

		AdminNotices::redirect_to( 'license_created', self::detail_url( $license_id ) );
	}

	/**
	 * Resolve a user ID or email address to an existing user.
	 *
	 * @param string $input User ID or email address.
	 * @return int User ID, or zero when no account matches.
	 */
	private static function resolve_customer( string $input ): int {
		$input = trim( $input );
		if ( '' !== $input && ctype_digit( $input ) ) {
			$user = get_userdata( (int) $input );
		} elseif ( is_email( $input ) ) {
			$user = get_user_by( 'email', $input );
		} else {
			$user = false;
		}

		return $user ? (int) $user->ID : 0;
	}
}
