<?php
/**
 * WooCommerce administrator license screens and actions.
 *
 * @package WPLC
 */

namespace WPLC\Admin;

use WPLC\Billing\Money;
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
	 * Most licenses in one CSV export.
	 */
	public const EXPORT_LIMIT = 100000;

	/**
	 * Licenses read per batch of a CSV export.
	 */
	public const EXPORT_BATCH = 500;

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
		add_action( 'admin_post_wplc_admin_cancel_renewal', array( self::class, 'handle_cancel_renewal' ) );
		add_action( 'admin_post_wplc_admin_withdraw_cancellation', array( self::class, 'handle_withdraw_cancellation' ) );
		add_action( 'admin_post_wplc_admin_renewal_amount', array( self::class, 'handle_renewal_amount' ) );
		add_action( 'admin_post_wplc_admin_bulk_extend', array( self::class, 'handle_bulk_extend' ) );
		add_action( 'admin_post_wplc_admin_export_licenses', array( self::class, 'handle_export' ) );
		add_action( 'admin_post_wplc_admin_issue_for_order', array( self::class, 'handle_issue_for_order' ) );
		Capabilities::register_hooks();
		AdminNotices::register_hooks();
		Settings::register_hooks();
		RenewalReviewAdmin::register_hooks();
		LicenseBulkActions::register_hooks();
		AuditLogAdmin::register_hooks();
		SubscriptionsNotice::register_hooks();
		OrderLicensesBox::register_hooks();
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

		echo '<div class="wrap wplc-license-list">';
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
		echo '<p class="description">' . esc_html__( 'Search by key prefix, product, plan, customer ID, order number, customer email, #license ID, site address or installation ID, or paste a complete license key.', 'wp-license' ) . '</p>';
		echo '</form>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only: the result is stored per user.
		$bulk_token = isset( $_GET[ LicenseBulkActions::QUERY_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ LicenseBulkActions::QUERY_ARG ] ) ) : '';
		if ( '' !== $bulk_token ) {
			LicenseBulkActions::render_result( get_current_user_id(), $bulk_token );
		}
		CsvExport::render_truncated_notice( get_current_user_id() );
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
		Capabilities::require_cap( 'view_licenses', __( 'You do not have permission to view licenses.', 'wp-license' ) );
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
			$wpdb->prepare( "SELECT * FROM %i WHERE id = %d LIMIT 1", $tables['activations'], $activation_id ),
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
		$send     = empty( $_POST['skip_email'] );
		// A-123: a refused form comes back with what was entered.
		self::remember_add_form(
			array(
				'customer'   => $customer,
				'plan_id'    => $plan_id,
				'paid_until' => $date,
				'reason'     => $reason,
				'send_email' => $send,
			)
		);
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
			$wpdb->prepare( "SELECT * FROM %i WHERE id = %d AND active = 1 LIMIT 1", $tables['plans'], $plan_id ),
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
				delete_transient( self::add_form_transient() );
				AdminNotices::redirect_to( 'create_duplicate', self::detail_url( $existing ) );
			}
		}

		// A-123: the license email (with the key) is optional; the customer also finds the key in their account.
		$mail_hook = array( 'WPLC\\Notifications\\Notifications', 'on_license_issued' );
		$mail_prio = $send ? false : has_action( 'wplc/license_issued', $mail_hook );
		if ( false !== $mail_prio ) {
			remove_action( 'wplc/license_issued', $mail_hook, (int) $mail_prio );
		}
		try {
			$license_id = LicenseIssuer::issue_manual( $customer_id, $plan, $paid_until, get_current_user_id(), $reason );
			if ( $license_id && '' !== $memo_key ) {
				set_transient( $memo_key, (int) $license_id, DAY_IN_SECONDS );
			}
		} finally {
			if ( false !== $mail_prio ) {
				add_action( 'wplc/license_issued', $mail_hook, (int) $mail_prio, 3 );
			}
			if ( '' !== $lock_name ) {
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
			}
		}
		if ( ! $license_id ) {
			AdminNotices::redirect( 'create_failed', $form_url );
		}

		delete_transient( self::add_form_transient() );
		AdminNotices::redirect_to( $send ? 'license_created' : 'license_created_no_email', self::detail_url( $license_id ) );
	}

	/**
	 * Transient that keeps the entries of a refused "Add license manually" form for its user.
	 *
	 * @return string
	 */
	public static function add_form_transient(): string {
		return 'wplc_add_form_' . get_current_user_id();
	}

	/**
	 * Keep the entries of the "Add license manually" form for ten minutes (A-123).
	 *
	 * @param array<string,mixed> $values Entered values.
	 * @return void
	 */
	private static function remember_add_form( array $values ): void {
		set_transient( self::add_form_transient(), $values, 10 * MINUTE_IN_SECONDS );
	}

	/**
	 * Entries of a refused "Add license manually" form, read once.
	 *
	 * @return array{customer:string,plan_id:int,paid_until:string,reason:string,send_email:bool}|null
	 */
	public static function take_add_form(): ?array {
		$values = get_transient( self::add_form_transient() );
		delete_transient( self::add_form_transient() );
		if ( ! is_array( $values ) ) {
			return null;
		}

		return array(
			'customer'   => isset( $values['customer'] ) ? (string) $values['customer'] : '',
			'plan_id'    => isset( $values['plan_id'] ) ? (int) $values['plan_id'] : 0,
			'paid_until' => isset( $values['paid_until'] ) ? (string) $values['paid_until'] : '',
			'reason'     => isset( $values['reason'] ) ? (string) $values['reason'] : '',
			'send_email' => ! empty( $values['send_email'] ),
		);
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

		$new_key = ! empty( $_POST['new_key'] );
		$release = $new_key && ! empty( $_POST['release_sites'] );
		$result  = SupportActions::change_owner( $license_id, $customer_id, $reason, get_current_user_id(), absint( $_POST['expected_customer'] ), $new_key, $release );
		$codes   = array(
			SupportActions::RESULT_OK         => $new_key ? 'owner_changed_new_key' : 'owner_changed',
			SupportActions::RESULT_KEY_KEPT   => 'owner_changed_key_kept',
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
	 * Cancel the automatic renewal for the end of the paid period on behalf of the customer (A-21).
	 *
	 * @return void
	 */
	public static function handle_cancel_renewal(): void {
		self::require_capability();

		$license_id = isset( $_POST['license_id'] ) ? absint( $_POST['license_id'] ) : 0;
		check_admin_referer( 'wplc_admin_cancel_renewal_' . $license_id );
		$reason   = self::posted_reason();
		$fallback = $license_id ? self::detail_url( $license_id ) : '';
		if ( ! $license_id || '' === $reason ) {
			AdminNotices::redirect( 'status_missing_input', $fallback );
		}

		$result = SupportActions::cancel_renewal( $license_id, $reason, get_current_user_id() );
		$codes  = array(
			SupportActions::RESULT_OK     => 'renewal_cancelled_by_admin',
			SupportActions::RESULT_STALE  => 'renewal_already_cancelled',
			SupportActions::RESULT_FAILED => 'renewal_cancel_failed',
		);
		AdminNotices::redirect( $codes[ $result ] ?? 'renewal_cancel_failed', $fallback );
	}

	/**
	 * Withdraw a cancellation at the end of the paid period (A-21).
	 *
	 * @return void
	 */
	public static function handle_withdraw_cancellation(): void {
		self::require_capability();

		$license_id = isset( $_POST['license_id'] ) ? absint( $_POST['license_id'] ) : 0;
		check_admin_referer( 'wplc_admin_withdraw_cancellation_' . $license_id );
		$reason   = self::posted_reason();
		$fallback = $license_id ? self::detail_url( $license_id ) : '';
		if ( ! $license_id || '' === $reason ) {
			AdminNotices::redirect( 'status_missing_input', $fallback );
		}

		$result = SupportActions::withdraw_cancellation( $license_id, $reason, get_current_user_id() );
		$codes  = array(
			SupportActions::RESULT_OK     => 'cancellation_withdrawn_by_admin',
			SupportActions::RESULT_STALE  => 'cancellation_not_pending',
			SupportActions::RESULT_FAILED => 'cancellation_withdraw_failed',
		);
		AdminNotices::redirect( $codes[ $result ] ?? 'cancellation_withdraw_failed', $fallback );
	}

	/**
	 * Change the amount of the next automatic renewals (A-11).
	 *
	 * @return void
	 */
	public static function handle_renewal_amount(): void {
		self::require_capability();

		$license_id = isset( $_POST['license_id'] ) ? absint( $_POST['license_id'] ) : 0;
		check_admin_referer( 'wplc_admin_renewal_amount_' . $license_id );
		$reason   = self::posted_reason();
		$fallback = $license_id ? self::detail_url( $license_id ) : '';
		$amount   = isset( $_POST['amount'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['amount'] ) ) ) : '';
		global $wpdb;
		$decimals = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT renewal_currency_decimals FROM %i WHERE id = %d', Schema::table_names()['licenses'], $license_id ) );
		// The shop's decimal separator is accepted as well as the point.
		$separator = function_exists( 'wc_get_price_decimal_separator' ) ? (string) wc_get_price_decimal_separator() : '.';
		if ( '' !== $separator && '.' !== $separator ) {
			$amount = str_replace( $separator, '.', $amount );
		}
		$minor = '' !== $amount ? Money::to_minor_units( $amount, $decimals ) : null;
		if ( ! $license_id || '' === $reason || ! isset( $_POST['expected_minor'] ) || ! is_int( $minor ) || $minor <= 0 ) {
			AdminNotices::redirect( 'renewal_amount_missing_input', $fallback );
		}

		$result = SupportActions::change_renewal_amount( $license_id, (int) $minor, absint( $_POST['expected_minor'] ), $reason, get_current_user_id() );
		$codes  = array(
			SupportActions::RESULT_OK               => 'renewal_amount_changed',
			SupportActions::RESULT_STALE            => 'renewal_amount_stale',
			SupportActions::RESULT_PROCESSING       => 'extend_processing',
			SupportActions::RESULT_INCREASE_REFUSED => 'renewal_amount_increase_refused',
			SupportActions::RESULT_FAILED           => 'renewal_amount_failed',
		);
		AdminNotices::redirect( $codes[ $result ] ?? 'renewal_amount_failed', $fallback );
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

		$handle = CsvExport::open_download( 'licenses-' . gmdate( 'Y-m-d' ) . '.csv' );
		$result = self::write_csv( LicenseListTable::request_filters(), $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Output stream of a download.
		if ( $result['truncated'] ) {
			CsvExport::remember_truncated( get_current_user_id(), 'licenses', self::EXPORT_LIMIT );
		}
		exit;
	}

	/**
	 * Build the CSV for a set of list filters as a string (tests, small lists).
	 *
	 * @param array<string,mixed> $filters Filters from LicenseListTable::request_filters().
	 * @param int                 $limit   Row cap.
	 * @return string
	 */
	public static function export_csv( array $filters, int $limit = self::EXPORT_LIMIT ): string {
		$handle = fopen( 'php://temp', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- In-memory stream.
		if ( false === $handle ) {
			return '';
		}
		self::write_csv( $filters, $handle, $limit );
		rewind( $handle );
		$out = (string) stream_get_contents( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- In-memory stream.

		return $out;
	}

	/**
	 * Write the CSV for a set of list filters to a stream, one batch at a time (P-28).
	 *
	 * Only the email address of each customer is read (no user meta), and every
	 * batch is written and flushed before the next is loaded. An export that
	 * reaches the cap ends with a marker line.
	 *
	 * @param array<string,mixed> $filters Filters from LicenseListTable::request_filters().
	 * @param resource            $handle  Output stream.
	 * @param int                 $limit   Row cap.
	 * @return array{rows:int,truncated:bool}
	 */
	public static function write_csv( array $filters, $handle, int $limit = self::EXPORT_LIMIT ): array {
		global $wpdb;

		$tables = Schema::table_names();
		$query  = LicenseListTable::query_parts( $filters );
		$limit  = max( 1, $limit );
		CsvExport::write( $handle, array( 'license_id', 'key_prefix', 'customer_id', 'customer_email', 'product_key', 'plan_key', 'status', 'billing_interval', 'renewal', 'cancel_at_period_end', 'starts_at_utc', 'paid_until_utc', 'next_payment_at_utc', 'source_order_id', 'created_at_utc' ) );
		$last      = 0;
		$total     = 0;
		$truncated = false;
		do {
			// One row more than needed tells a full export from a truncated one.
			$batch_size = min( self::EXPORT_BATCH, $limit - $total + 1 );
			$sql        = "SELECT * FROM {$tables['licenses']} WHERE id > %d" . ( '' !== $query['where'] ? ' AND ' . $query['where'] : '' ) . ' ORDER BY id ASC LIMIT %d';
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Only table names and fixed fragments are interpolated.
			$rows   = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( $last ), $query['params'], array( $batch_size ) ) ), ARRAY_A );
			$rows   = is_array( $rows ) ? $rows : array();
			$batch  = count( $rows );
			$emails = CsvExport::user_field( array_column( $rows, 'customer_id' ), 'user_email' );
			foreach ( $rows as $row ) {
				if ( $total >= $limit ) {
					$truncated = true;
					break;
				}
				$last     = (int) $row['id'];
				$customer = (int) $row['customer_id'];
				CsvExport::write(
					$handle,
					array(
						$last,
						(string) $row['key_prefix'],
						$customer,
						$emails[ $customer ] ?? '',
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
				++$total;
			}
			CsvExport::flush_batch( $handle );
		} while ( ! $truncated && $batch === $batch_size );

		if ( $truncated ) {
			CsvExport::write( $handle, CsvExport::truncated_row( $limit ) );
		}

		return array(
			'rows'      => $total,
			'truncated' => $truncated,
		);
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
