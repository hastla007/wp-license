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
use WPLC\License\PlanChange;

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
		add_action( 'admin_post_wplc_admin_change_plan', array( self::class, 'handle_change_plan' ) );
		add_action( 'admin_post_wplc_admin_cancel_plan_change', array( self::class, 'handle_cancel_plan_change' ) );
		add_action( 'admin_post_wplc_admin_add_license', array( self::class, 'handle_add_license' ) );
		add_action( 'admin_post_wplc_admin_license_search', array( self::class, 'handle_search' ) );
		add_action( 'admin_post_wplc_admin_reissue_key', array( self::class, 'handle_reissue_key' ) );
		add_action( 'admin_post_wplc_admin_resend_key', array( self::class, 'handle_resend_key' ) );
		add_action( 'admin_post_wplc_admin_change_owner', array( self::class, 'handle_change_owner' ) );
		add_action( 'admin_post_wplc_admin_restore_license', array( self::class, 'handle_restore' ) );
		add_action( 'admin_post_wplc_admin_bulk_extend', array( self::class, 'handle_bulk_extend' ) );
		add_action( 'admin_post_wplc_admin_export_licenses', array( self::class, 'handle_export' ) );
		add_action( 'admin_post_wplc_admin_issue_for_order', array( self::class, 'handle_issue_for_order' ) );
		Capabilities::register_hooks();
		AuditLogPage::register_hooks();
		AdminNotices::register_hooks();
		Settings::register_hooks();
		RenewalReviewAdmin::register_hooks();
		LicenseBulkActions::register_hooks();
		AuditLogAdmin::register_hooks();
	}

	/**
	 * Add a WooCommerce submenu page.
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		$capability = Capabilities::cap( 'view_licenses' );
		if ( 'woocommerce' === Capabilities::menu_parent() ) {
			add_submenu_page(
				'woocommerce',
				__( 'WP License Captain', 'wp-license' ),
				__( 'Licenses', 'wp-license' ),
				$capability,
				self::PAGE,
				array( self::class, 'render' )
			);
		} else {
			// A support role without access to the WooCommerce menu.
			add_menu_page(
				__( 'WP License Captain', 'wp-license' ),
				__( 'Licenses', 'wp-license' ),
				$capability,
				self::PAGE,
				array( self::class, 'render' ),
				'dashicons-admin-network',
				56
			);
		}
		self::connect_page( self::PAGE, __( 'Licenses', 'wp-license' ) );
	}

	/**
	 * Register a plugin page with WooCommerce Admin, so it gets the WooCommerce
	 * header, breadcrumbs and the activity panel.
	 *
	 * @param string $page  Page slug.
	 * @param string $title Breadcrumb title.
	 * @return void
	 */
	public static function connect_page( string $page, string $title ): void {
		if ( ! function_exists( 'wc_admin_connect_page' ) || 'woocommerce' !== Capabilities::menu_parent() ) {
			return;
		}
		wc_admin_connect_page(
			array(
				'id'        => $page,
				'screen_id' => 'woocommerce_page_' . $page,
				'title'     => $title,
				'path'      => add_query_arg( 'page', $page, 'admin.php' ),
			)
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
		wp_enqueue_style( 'wplc-admin', plugins_url( 'assets/css/wplc-admin.css', WPLC_FILE ), array(), \WPLC\Account\CustomerPortal::asset_version( 'assets/css/wplc-admin.css' ) );
		wp_enqueue_script( 'wplc-ui', plugins_url( 'assets/js/wplc-ui.js', WPLC_FILE ), array(), \WPLC\Account\CustomerPortal::asset_version( 'assets/js/wplc-ui.js' ), true );
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
		Capabilities::require_cap( 'view_licenses', __( 'You do not have permission to view licenses.', 'wp-license' ) );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only routing.
		$license_id = isset( $_GET['license'] ) ? absint( $_GET['license'] ) : 0;
		$view       = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
		// phpcs:enable

		if ( $license_id ) {
			LicenseDetail::render( $license_id );
			return;
		}
		if ( 'add' === $view ) {
			Capabilities::require_cap( 'grant_licenses', __( 'You do not have permission to add licenses.', 'wp-license' ) );
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

		$table      = new LicenseListTable();
		$table->prepare_items();
		$filters = LicenseListTable::request_filters();

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Licenses', 'wp-license' ) . '</h1> ';
		if ( Capabilities::can( 'grant_licenses' ) ) {
			echo '<a href="' . esc_url( self::add_url() ) . '" class="page-title-action">' . esc_html__( 'Add license manually', 'wp-license' ) . '</a> ';
		}
		echo '<a href="' . esc_url( self::export_url( $filters ) ) . '" class="page-title-action">' . esc_html__( 'Export as CSV', 'wp-license' ) . '</a>';
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

		$table->views();

		// The search is POSTed (T-141): the term must not appear in URLs, sort and
		// page links, browser history or access logs. The handler redirects back
		// with an opaque token only.
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wplc-license-search">';
		echo '<input type="hidden" name="action" value="wplc_admin_license_search">';
		wp_nonce_field( 'wplc_admin_license_search' );
		foreach ( LicenseListTable::FILTER_ARGS as $field ) {
			if ( '' !== (string) $filters[ $field ] && '0' !== (string) $filters[ $field ] ) {
				echo '<input type="hidden" name="' . esc_attr( $field ) . '" value="' . esc_attr( (string) $filters[ $field ] ) . '">';
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
		echo '<p class="description">' . esc_html__( 'Search by key prefix, product, plan, customer ID, customer email, #license ID, site address or installation ID, or paste a complete license key.', 'wp-license' ) . '</p>';
		echo '</form>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only: the result is stored per user.
		$bulk_token = isset( $_GET[ LicenseBulkActions::QUERY_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ LicenseBulkActions::QUERY_ARG ] ) ) : '';
		if ( '' !== $bulk_token ) {
			LicenseBulkActions::render_result( get_current_user_id(), $bulk_token );
		}
		// Bulk actions are POSTed through their own form; checkboxes and controls in
		// the table are bound to it with form="wplc-bulk-form" (T-102).
		LicenseBulkActions::render_form( $filters );

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '">';
		if ( '' !== $filters['search_token'] ) {
			echo '<input type="hidden" name="' . esc_attr( LicenseSearchStore::QUERY_ARG ) . '" value="' . esc_attr( $filters['search_token'] ) . '">';
		}
		if ( '' !== $filters['report'] ) {
			echo '<input type="hidden" name="report" value="' . esc_attr( $filters['report'] ) . '">';
		}
		self::render_order_fields( $filters );
		$table->display();
		echo '</form>';
		if ( Capabilities::can( 'grant_licenses' ) ) {
			self::render_issue_for_order_form();
		}
		echo '</div>';
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
		foreach ( array_merge( LicenseListTable::FILTER_ARGS, array( 'orderby', 'order' ) ) as $field ) {
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
	 * Form that issues the missing licenses of a paid order.
	 *
	 * @return void
	 */
	private static function render_issue_for_order_form(): void {
		echo '<h2>' . esc_html__( 'Issue missing licenses for an order', 'wp-license' ) . '</h2>';
		echo '<form method="post" class="wplc-inline-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="wplc_admin_issue_for_order">';
		echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( 'wplc_admin_issue_for_order' ) ) . '">';
		echo '<label for="wplc-issue-order">' . esc_html__( 'Order number', 'wp-license' ) . '</label> ';
		echo '<input type="number" id="wplc-issue-order" name="order_id" min="1" step="1" required class="small-text"> ';
		echo '<label for="wplc-issue-reason">' . esc_html__( 'Reason', 'wp-license' ) . '</label> ';
		echo '<input type="text" id="wplc-issue-reason" name="reason" required maxlength="255"> ';
		echo '<button class="button" type="submit">' . esc_html__( 'Issue licenses', 'wp-license' ) . '</button></form>';
		echo '<p class="description">' . esc_html__( 'For a paid order that has no license, for example after the product was mapped to a plan too late. Licenses that already exist are never issued twice.', 'wp-license' ) . '</p>';
	}

	/**
	 * URL of the CSV export for the current filters.
	 *
	 * @param array<string,mixed>  $filters Filters from LicenseListTable::request_filters().
	 * @return string
	 */
	public static function export_url( array $filters ): string {
		$args = array( 'action' => 'wplc_admin_export_licenses' );
		foreach ( LicenseListTable::FILTER_ARGS as $key ) {
			if ( ! empty( $filters[ $key ] ) ) {
				$args[ $key ] = $filters[ $key ];
			}
		}
		// The search travels as the opaque token of LicenseSearchStore, never as the term (T-141).
		if ( ! empty( $filters['search_token'] ) ) {
			$args[ LicenseSearchStore::QUERY_ARG ] = $filters['search_token'];
		}

		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), 'wplc_admin_export_licenses' );
	}

	/**
	 * Stop unauthorized requests. Only this and an invalid nonce may end in wp_die().
	 *
	 * @param string $slug Action slug, see Capabilities.
	 * @return void
	 */
	private static function require_capability( string $slug = 'manage_licenses' ): void {
		Capabilities::require_cap( $slug, __( 'You do not have permission to change licenses.', 'wp-license' ) );
	}

	/**
	 * Read the mandatory reason of an admin action, limited to the stored length.
	 *
	 * @return string
	 */
	private static function posted_reason(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Every caller verifies its nonce.
		$reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';

		return SupportActions::limit_reason( $reason );
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

		// The end date the administrator saw. A form without it (an old tab) is treated as stale.
		$expected = isset( $_POST['expected_paid_until'] ) ? sanitize_text_field( wp_unslash( $_POST['expected_paid_until'] ) ) : null;
		if ( null === $expected ) {
			AdminNotices::redirect( 'extend_stale', $fallback );
		}

		$result = SupportActions::change_term( $license_id, $paid_until, $expected, $reason, get_current_user_id() );
		$codes  = array(
			SupportActions::RESULT_OK         => 'term_extended',
			SupportActions::RESULT_STALE      => 'extend_stale',
			SupportActions::RESULT_PROCESSING => 'extend_processing',
			SupportActions::RESULT_FAILED     => 'extend_failed',
		);
		AdminNotices::redirect( $codes[ $result ], $fallback );
	}

	/**
	 * Change the plan of a license now or from the next renewal (T-110). No payment is taken.
	 *
	 * @return void
	 */
	public static function handle_change_plan(): void {
		self::require_capability();

		$license_id = isset( $_POST['license_id'] ) ? absint( $_POST['license_id'] ) : 0;
		check_admin_referer( 'wplc_admin_change_plan_' . $license_id );

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$when       = isset( $_POST['when'] ) ? sanitize_key( wp_unslash( $_POST['when'] ) ) : '';
		$reason     = self::posted_reason();
		if ( ! $license_id || ! $product_id || '' === $reason || ! in_array( $when, array( 'now', 'renewal' ), true ) ) {
			AdminNotices::redirect( 'plan_change_missing_input' );
		}
		$result = 'now' === $when
			? PlanChange::admin_change_now( $license_id, $product_id, $reason, get_current_user_id() )
			: PlanChange::schedule_pending( $license_id, $product_id, get_current_user_id(), $reason, true );
		AdminNotices::redirect( $result, self::detail_url( $license_id ) );
	}

	/**
	 * Withdraw a plan change scheduled for the next renewal.
	 *
	 * @return void
	 */
	public static function handle_cancel_plan_change(): void {
		self::require_capability();

		$license_id = isset( $_POST['license_id'] ) ? absint( $_POST['license_id'] ) : 0;
		check_admin_referer( 'wplc_admin_cancel_plan_change_' . $license_id );
		$reason = self::posted_reason();
		if ( ! $license_id || '' === $reason ) {
			AdminNotices::redirect( 'plan_change_missing_input' );
		}
		$withdrawn = PlanChange::cancel_pending( $license_id, get_current_user_id(), $reason );
		AdminNotices::redirect( $withdrawn ? 'plan_change_withdrawn' : 'plan_change_not_withdrawn', self::detail_url( $license_id ) );
	}

	/**
	 * Issue a license without an order.
	 *
	 * @return void
	 */
	public static function handle_add_license(): void {
		self::require_capability( 'grant_licenses' );
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

		// A-60: one rendered form creates one license, also on a double click or a re-sent request.
		$request_id = isset( $_POST['request_id'] ) ? sanitize_key( wp_unslash( $_POST['request_id'] ) ) : '';
		$lock_name  = '';
		$memo_key   = '';
		if ( '' !== $request_id ) {
			$memo_key  = 'wplc_add_req_' . md5( get_current_user_id() . '|' . $request_id );
			$lock_name = 'wplc_add_' . md5( $wpdb->prefix . $memo_key );
			if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, 10 ) ) ) {
				AdminNotices::redirect( 'create_failed', $form_url );
			}
			$existing = (int) get_transient( $memo_key );
			if ( $existing > 0 ) {
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
				AdminNotices::redirect_to( 'create_duplicate', self::detail_url( $existing ) );
			}
		}

		try {
			$license_id = LicenseIssuer::issue_manual( $customer_id, $plan, $paid_until, get_current_user_id(), $reason );
			if ( $license_id && '' !== $memo_key ) {
				set_transient( $memo_key, (int) $license_id, DAY_IN_SECONDS );
			}
		} finally {
			if ( '' !== $lock_name ) {
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
			}
		}
		if ( ! $license_id ) {
			AdminNotices::redirect( 'create_failed', $form_url );
		}

		AdminNotices::redirect_to( 'license_created', self::detail_url( $license_id ) );
	}

	/**
	 * Replace the key of a license.
	 *
	 * @return void
	 */
	public static function handle_reissue_key(): void {
		self::require_capability();

		$license_id = isset( $_POST['license_id'] ) ? absint( $_POST['license_id'] ) : 0;
		check_admin_referer( 'wplc_admin_reissue_key_' . $license_id );

		$reason  = self::posted_reason();
		$release = ! empty( $_POST['release_sites'] );
		$done    = $license_id && '' !== $reason && SupportActions::reissue_key( $license_id, $reason, get_current_user_id(), $release );
		AdminNotices::redirect( $done ? 'key_reissued' : 'key_reissue_failed', $license_id ? self::detail_url( $license_id ) : '' );
	}

	/**
	 * Request the key email again.
	 *
	 * @return void
	 */
	public static function handle_resend_key(): void {
		self::require_capability();

		$license_id = isset( $_POST['license_id'] ) ? absint( $_POST['license_id'] ) : 0;
		check_admin_referer( 'wplc_admin_resend_key_' . $license_id );

		$reason = self::posted_reason();
		$done   = $license_id && '' !== $reason && SupportActions::resend_key( $license_id, $reason, get_current_user_id() );
		AdminNotices::redirect( $done ? 'key_resend_requested' : 'key_resend_failed', $license_id ? self::detail_url( $license_id ) : '' );
	}

	/**
	 * Hand a license over to another customer.
	 *
	 * @return void
	 */
	public static function handle_change_owner(): void {
		self::require_capability();

		$license_id = isset( $_POST['license_id'] ) ? absint( $_POST['license_id'] ) : 0;
		check_admin_referer( 'wplc_admin_change_owner_' . $license_id );

		$customer = isset( $_POST['customer'] ) ? sanitize_text_field( wp_unslash( $_POST['customer'] ) ) : '';
		$reason   = self::posted_reason();
		$fallback = $license_id ? self::detail_url( $license_id ) : '';
		if ( ! $license_id || '' === $customer || '' === $reason || ! isset( $_POST['expected_customer'] ) ) {
			AdminNotices::redirect( 'owner_missing_input', $fallback );
		}
		$customer_id = self::resolve_customer( $customer );
		if ( ! $customer_id ) {
			AdminNotices::redirect( 'owner_unknown_customer', $fallback );
		}

		$result = SupportActions::change_owner( $license_id, $customer_id, $reason, get_current_user_id(), absint( $_POST['expected_customer'] ) );
		$codes  = array(
			SupportActions::RESULT_OK         => 'owner_changed',
			SupportActions::RESULT_STALE      => 'owner_stale',
			SupportActions::RESULT_PROCESSING => 'owner_processing',
			SupportActions::RESULT_FAILED     => 'owner_failed',
		);
		AdminNotices::redirect( $codes[ $result ], $fallback );
	}

	/**
	 * Bring a suspended or revoked license back with a new end date.
	 *
	 * @return void
	 */
	public static function handle_restore(): void {
		self::require_capability();

		$license_id = isset( $_POST['license_id'] ) ? absint( $_POST['license_id'] ) : 0;
		check_admin_referer( 'wplc_admin_restore_license_' . $license_id );

		$date     = isset( $_POST['paid_until'] ) ? sanitize_text_field( wp_unslash( $_POST['paid_until'] ) ) : '';
		$expected = isset( $_POST['expected_status'] ) ? sanitize_key( wp_unslash( $_POST['expected_status'] ) ) : '';
		$reason   = self::posted_reason();
		$fallback = $license_id ? self::detail_url( $license_id ) : '';
		if ( 'suspended' !== $expected && ! Capabilities::can( 'restore_licenses' ) ) {
			// B-47: undoing a revocation is reserved for administrators.
			AdminNotices::redirect( 'restore_forbidden', $fallback );
		}
		$paid_until = '' !== $date ? Format::local_date_to_utc_end_of_day( $date, wp_timezone() ) : '';
		if ( ! $license_id || '' === $reason || '' === $paid_until || $paid_until <= gmdate( 'Y-m-d H:i:s' ) ) {
			AdminNotices::redirect( 'restore_missing_input', $fallback );
		}

		$result = SupportActions::restore( $license_id, $paid_until, $expected, $reason, get_current_user_id() );
		$codes  = array(
			SupportActions::RESULT_OK         => 'license_restored',
			SupportActions::RESULT_STALE      => 'restore_stale',
			SupportActions::RESULT_PROCESSING => 'extend_processing',
			SupportActions::RESULT_FAILED     => 'restore_failed',
		);
		AdminNotices::redirect( $codes[ $result ], $fallback );
	}

	/**
	 * Extend the selected licenses by a number of days.
	 *
	 * @return void
	 */
	public static function handle_bulk_extend(): void {
		self::require_capability( 'grant_licenses' );
		check_admin_referer( 'wplc_admin_bulk_extend' );

		$ids    = isset( $_POST['license_ids'] ) && is_array( $_POST['license_ids'] ) ? array_map( 'absint', wp_unslash( $_POST['license_ids'] ) ) : array();
		$days   = isset( $_POST['days'] ) ? absint( $_POST['days'] ) : 0;
		$reason = self::posted_reason();
		if ( ! $ids || $days < 1 || $days > 3650 || '' === $reason ) {
			AdminNotices::redirect( 'bulk_extend_missing_input' );
		}

		$result = SupportActions::bulk_extend( $ids, $days, $reason, get_current_user_id() );
		AdminNotices::redirect( 'bulk_extended', '', array( $result['extended'], $result['skipped'] ) );
	}

	/**
	 * Issue the missing licenses of a paid order.
	 *
	 * @return void
	 */
	public static function handle_issue_for_order(): void {
		self::require_capability( 'grant_licenses' );
		check_admin_referer( 'wplc_admin_issue_for_order' );

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$created  = SupportActions::issue_for_order( $order_id, self::posted_reason(), get_current_user_id() );
		if ( $created < 0 ) {
			AdminNotices::redirect( 'order_issue_failed' );
		}
		AdminNotices::redirect( 'order_licenses_issued', '', array( $created ) );
	}

	/**
	 * Stream the filtered license list as CSV. Never contains license keys.
	 *
	 * @return void
	 */
	public static function handle_export(): void {
		Capabilities::require_cap( 'view_licenses', __( 'You do not have permission to view licenses.', 'wp-license' ) );
		check_admin_referer( 'wplc_admin_export_licenses' );

		if ( ! class_exists( '\\WP_List_Table' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		}

		AuditLog::record( 'licenses_exported', 'export', 0, '', null, null, get_current_user_id() );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="licenses-' . gmdate( 'Y-m-d' ) . '.csv"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo self::export_csv( LicenseListTable::request_filters() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download, cells are neutralized in csv_line().
		exit;
	}

	/**
	 * Build the CSV for a set of list filters.
	 *
	 * @param array<string,mixed> $filters Filters from LicenseListTable::request_filters().
	 * @return string
	 */
	public static function export_csv( array $filters ): string {
		global $wpdb;

		$tables = Schema::table_names();
		$query  = LicenseListTable::query_parts( $filters );
		$out    = self::csv_line( array( 'license_id', 'key_prefix', 'customer_id', 'customer_email', 'product_key', 'plan_key', 'status', 'billing_interval', 'renewal', 'cancel_at_period_end', 'starts_at_utc', 'paid_until_utc', 'next_payment_at_utc', 'source_order_id', 'created_at_utc' ) );
		$last   = 0;
		$total  = 0;
		do {
			$sql = "SELECT * FROM {$tables['licenses']} WHERE id > %d" . ( '' !== $query['where'] ? ' AND ' . $query['where'] : '' ) . ' ORDER BY id ASC LIMIT 500';
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Only table names and fixed fragments are interpolated.
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( $last ), $query['params'] ) ), ARRAY_A );
			$rows = is_array( $rows ) ? $rows : array();
			$ids  = array_values( array_unique( array_filter( array_map( 'intval', array_column( $rows, 'customer_id' ) ) ) ) );
			if ( $ids && function_exists( 'cache_users' ) ) {
				cache_users( $ids );
			}
			foreach ( $rows as $row ) {
				$last = (int) $row['id'];
				$user = (int) $row['customer_id'] ? get_userdata( (int) $row['customer_id'] ) : false;
				$out .= self::csv_line(
					array(
						$last,
						(string) $row['key_prefix'],
						(int) $row['customer_id'],
						$user ? (string) $user->user_email : '',
						(string) $row['product_key'],
						(string) $row['plan_key'],
						(string) $row['status'],
						(string) $row['billing_interval'],
						Format::renewal_mode( $row ),
						(int) $row['cancel_at_period_end'],
						(string) $row['starts_at'],
						(string) $row['paid_until'],
						(string) $row['next_payment_at'],
						(int) $row['source_order_id'],
						(string) $row['created_at'],
					)
				);
			}
			$batch  = count( $rows );
			$total += $batch;
		} while ( 500 === $batch && $total < 100000 );

		return $out;
	}

	/**
	 * One CSV line; cells that a spreadsheet would run as a formula are neutralized.
	 *
	 * @param array<int,mixed> $cells Cells.
	 * @return string
	 */
	public static function csv_line( array $cells ): string {
		$out = array();
		foreach ( $cells as $cell ) {
			$cell = (string) $cell;
			if ( '' !== $cell && false !== strpos( "=+-@\t\r", $cell[0] ) && ! is_numeric( $cell ) ) {
				$cell = "'" . $cell;
			}
			$out[] = '"' . str_replace( '"', '""', $cell ) . '"';
		}

		return implode( ',', $out ) . "\r\n";
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
