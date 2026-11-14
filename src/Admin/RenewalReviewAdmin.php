<?php
/**
 * Administrator screen "Renewals to review" (T-154, T-137).
 *
 * @package WPLC
 */

namespace WPLC\Admin;

use WPLC\Billing\Money;
use WPLC\Billing\RenewalReview;
use WPLC\License\AuditReason;
use WPLC\License\LicenseLifecycle;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists renewals in status `review_required` and runs the three resolutions.
 *
 * Every action needs `manage_woocommerce`, a per-renewal nonce and a reason;
 * the work is done by RenewalReview under the per-renewal lock.
 */
final class RenewalReviewAdmin {

	/**
	 * Admin page slug.
	 */
	public const PAGE = 'wplc-renewal-review';

	/**
	 * Register menu, assets and the action handler.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		// After LicenseAdmin (priority 10), so the entry sits below "Licenses".
		add_action( 'admin_menu', array( self::class, 'register_menu' ), 11 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
		add_action( 'admin_post_wplc_admin_renewal_review', array( self::class, 'handle_action' ) );
	}

	/**
	 * URL of the review screen.
	 *
	 * @return string
	 */
	public static function list_url(): string {
		return admin_url( 'admin.php?page=' . self::PAGE );
	}

	/**
	 * Menu title with the number of open cases as a badge. Pure.
	 *
	 * @param string $title Translated title.
	 * @param int    $count Open cases.
	 * @return string HTML.
	 */
	public static function menu_title( string $title, int $count ): string {
		$title = esc_html( $title );
		if ( $count <= 0 ) {
			return $title;
		}

		return $title . ' <span class="awaiting-mod count-' . (int) $count . '"><span class="pending-count">' . (int) $count . '</span></span>';
	}

	/**
	 * Add the WooCommerce submenu page.
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		$count = current_user_can( 'manage_woocommerce' ) ? RenewalReview::open_count() : 0;
		add_submenu_page(
			'woocommerce',
			__( 'Renewals to review', 'wp-license' ),
			self::menu_title( __( 'Renewals to review', 'wp-license' ), $count ),
			'manage_woocommerce',
			self::PAGE,
			array( self::class, 'render' )
		);
	}

	/**
	 * Load the license screen styles and the confirmation script.
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
	 * Translated label of a case kind.
	 *
	 * @param string $kind RenewalReview::KIND_* constant.
	 * @return string
	 */
	public static function kind_label( string $kind ): string {
		$labels = array(
			RenewalReview::KIND_RESULT_UNKNOWN  => __( 'Automatic payment: result unknown', 'wp-license' ),
			RenewalReview::KIND_PAID_NOT_BOOKED => __( 'Automatic payment taken, license not extended', 'wp-license' ),
			RenewalReview::KIND_MANUAL_PAYMENT  => __( 'Manual payment not applied', 'wp-license' ),
			RenewalReview::KIND_NO_ORDER        => __( 'No paid or payable order', 'wp-license' ),
		);

		return $labels[ $kind ] ?? $kind;
	}

	/**
	 * Render the review screen.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to view licenses.', 'wp-license' ), '', array( 'response' => 403 ) );
		}

		$cases = RenewalReview::open_cases();
		echo '<div class="wrap wplc-renewal-review">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Renewals to review', 'wp-license' ) . '</h1> ';
		echo '<a href="' . esc_url( LicenseAdmin::list_url() ) . '" class="page-title-action">' . esc_html__( 'All licenses', 'wp-license' ) . '</a>';
		echo '<hr class="wp-header-end">';
		echo '<p>' . esc_html__( 'Renewals listed here may have been paid, but the license was not extended. Check the payment in the payment gateway or the WooCommerce order, then decide. Every action needs a reason and is stored in the audit log.', 'wp-license' ) . '</p>';
		echo '<ul class="ul-disc">';
		echo '<li>' . esc_html__( 'Book renewal: the payment was taken. The license is extended by one period (reactivated if it expired). A suspended or revoked license must be reactivated first.', 'wp-license' ) . '</li>';
		echo '<li>' . esc_html__( 'Mark as not paid (unknown result only): the payment was not taken. The order becomes Failed, the license past due, and automatic retries continue.', 'wp-license' ) . '</li>';
		echo '<li>' . esc_html__( 'Close without booking: you refunded the payment in WooCommerce or decided otherwise. The license is not extended. No refund is made here.', 'wp-license' ) . '</li>';
		echo '</ul>';
		/* translators: %d: number of days. */
		echo '<p class="description">' . esc_html( sprintf( __( 'While a review is open, the license does not expire, for at most %d days after the review started. Access to updates still ends with the grace period.', 'wp-license' ), LicenseLifecycle::REVIEW_EXPIRY_DEFERRAL_DAYS ) ) . '</p>';

		if ( ! $cases ) {
			echo '<p><strong>' . esc_html__( 'No renewal needs review.', 'wp-license' ) . '</strong></p></div>';
			return;
		}

		echo '<table class="widefat striped wplc-review-table"><thead><tr>';
		foreach ( array( __( 'License', 'wp-license' ), __( 'Customer', 'wp-license' ), __( 'Order', 'wp-license' ), __( 'Amount', 'wp-license' ), __( 'Case', 'wp-license' ), __( 'Reason', 'wp-license' ), __( 'Since', 'wp-license' ), __( 'Actions', 'wp-license' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $cases as $case ) {
			self::render_row( $case );
		}
		echo '</tbody></table>';
		if ( count( $cases ) >= RenewalReview::LIST_LIMIT ) {
			/* translators: %d: number of rows. */
			echo '<p class="description">' . esc_html( sprintf( __( 'Only the oldest %d cases are shown.', 'wp-license' ), RenewalReview::LIST_LIMIT ) ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * One case.
	 *
	 * @param array<string,mixed> $case Row from RenewalReview::open_cases().
	 * @return void
	 */
	private static function render_row( array $case ): void {
		$renewal_id = (int) $case['id'];
		$license_id = (int) $case['license_id'];
		$license    = null === $case['license_status'] ? null : array(
			'id'          => $license_id,
			'customer_id' => (int) $case['customer_id'],
		);
		$order      = RenewalReview::order_for( $case, $license );
		$kind       = RenewalReview::kind( null !== $order, $order ? \WPLC\Billing\RenewalOrder::is_manual( $order ) : false, $order ? $order->is_paid() : false );

		if ( null === $license ) {
			/* translators: %d: license ID. */
			$license_html = esc_html( sprintf( __( 'License #%d (deleted)', 'wp-license' ), $license_id ) );
		} else {
			$license_html = '<a href="' . esc_url( LicenseAdmin::detail_url( $license_id ) ) . '">' . esc_html( (string) $case['key_prefix'] . ' (#' . $license_id . ')' ) . '</a>'
				. '<br><span class="description">' . esc_html( (string) $case['product_key'] . ' — ' . Format::license_status_label( (string) $case['license_status'] ) )
				/* translators: %s: date. */
				. '<br>' . esc_html( sprintf( __( 'Paid until %s', 'wp-license' ), Format::date( $case['paid_until'] ) ) ) . '</span>';
		}

		if ( $order ) {
			/* translators: %d: order ID. */
			$order_html = '<a href="' . esc_url( $order->get_edit_order_url() ) . '">' . esc_html( sprintf( __( 'Order #%d', 'wp-license' ), (int) $order->get_id() ) ) . '</a>'
				. ( function_exists( 'wc_get_order_status_name' ) ? '<br><span class="description">' . esc_html( wc_get_order_status_name( (string) $order->get_status() ) ) . '</span>' : '' );
			$amount     = (string) $order->get_total() . ' ' . (string) $order->get_currency();
		} else {
			$order_html = $case['renewal_order_id']
				/* translators: %d: order ID. */
				? esc_html( sprintf( __( 'Order #%d (not found)', 'wp-license' ), (int) $case['renewal_order_id'] ) )
				: '—';
			$amount     = null === $license ? '—' : Money::to_decimal( (int) $case['renewal_amount_minor'], (int) $case['renewal_currency_decimals'] ) . ' ' . (string) $case['renewal_currency'];
		}

		echo '<tr id="wplc-review-' . esc_attr( (string) $renewal_id ) . '">';
		echo '<td>' . wp_kses_post( $license_html ) . '</td>';
		echo '<td>' . wp_kses_post( LicenseAdmin::customer_html( (int) $case['customer_id'] ) ) . '</td>';
		echo '<td>' . wp_kses_post( $order_html ) . '</td>';
		echo '<td>' . esc_html( $amount ) . '</td>';
		echo '<td>' . esc_html( self::kind_label( $kind ) ) . ' <span class="description">#' . esc_html( (string) $renewal_id ) . '</span></td>';
		echo '<td>' . esc_html( '' !== (string) $case['review_reason'] ? AuditReason::label( (string) $case['review_reason'] ) : '—' ) . '</td>';
		echo '<td>' . esc_html( Format::date( $case['review_at'] ?: $case['updated_at'], true ) ) . '</td><td>';
		self::render_actions( $renewal_id, $kind );
		echo '</td></tr>';
	}

	/**
	 * Action forms of one case.
	 *
	 * @param int    $renewal_id Renewal ID.
	 * @param string $kind Case kind.
	 * @return void
	 */
	private static function render_actions( int $renewal_id, string $kind ): void {
		$buttons = array(
			RenewalReview::ACTION_BOOK     => array( __( 'Book renewal', 'wp-license' ), __( 'Book this renewal and extend the license? Only do this if the payment was taken.', 'wp-license' ), 'button-primary' ),
			RenewalReview::ACTION_NOT_PAID => array( __( 'Mark as not paid', 'wp-license' ), __( 'Record that the payment was not taken? The order becomes Failed and the license past due.', 'wp-license' ), '' ),
			RenewalReview::ACTION_CLOSE    => array( __( 'Close without booking', 'wp-license' ), __( 'Close this review without extending the license?', 'wp-license' ), '' ),
		);
		foreach ( RenewalReview::actions( $kind ) as $action ) {
			$button  = $buttons[ $action ];
			$field_id = 'wplc-review-' . $renewal_id . '-' . $action;
			echo '<form method="post" class="wplc-inline-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-wplc-confirm="' . esc_attr( $button[1] ) . '">';
			echo '<input type="hidden" name="action" value="wplc_admin_renewal_review">';
			echo '<input type="hidden" name="renewal_id" value="' . esc_attr( (string) $renewal_id ) . '">';
			echo '<input type="hidden" name="review_action" value="' . esc_attr( $action ) . '">';
			echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( self::nonce_action( $renewal_id ) ) ) . '">';
			/* translators: %s: action name such as "Book renewal". */
			echo '<label class="screen-reader-text" for="' . esc_attr( $field_id ) . '">' . esc_html( sprintf( __( 'Reason: %s', 'wp-license' ), $button[0] ) ) . '</label>';
			echo '<input type="text" id="' . esc_attr( $field_id ) . '" name="reason" required maxlength="255" placeholder="' . esc_attr__( 'Reason', 'wp-license' ) . '">';
			echo '<button class="button ' . esc_attr( $button[2] ) . '" type="submit">' . esc_html( $button[0] ) . '</button></form>';
		}
	}

	/**
	 * Nonce action of one case.
	 *
	 * @param int $renewal_id Renewal ID.
	 * @return string
	 */
	public static function nonce_action( int $renewal_id ): string {
		return 'wplc_admin_renewal_review_' . $renewal_id;
	}

	/**
	 * Handle one review action and return to the list with a notice.
	 *
	 * @return void
	 */
	public static function handle_action(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to change licenses.', 'wp-license' ), '', array( 'response' => 403 ) );
		}
		$renewal_id = isset( $_POST['renewal_id'] ) ? absint( $_POST['renewal_id'] ) : 0;
		check_admin_referer( self::nonce_action( $renewal_id ) );

		$action = isset( $_POST['review_action'] ) ? sanitize_key( wp_unslash( $_POST['review_action'] ) ) : '';
		$reason = isset( $_POST['reason'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) ) : '';

		AdminNotices::redirect_to( self::run_action( $renewal_id, $action, $reason, get_current_user_id() ), self::list_url() );
	}

	/**
	 * Dispatch one review action.
	 *
	 * @param int    $renewal_id Renewal ID.
	 * @param string $action RenewalReview::ACTION_* constant.
	 * @param string $reason Administrator's reason.
	 * @param int    $actor_user_id Administrator.
	 * @return string Result code (AdminNotices).
	 */
	public static function run_action( int $renewal_id, string $action, string $reason, int $actor_user_id ): string {
		switch ( $action ) {
			case RenewalReview::ACTION_BOOK:
				return RenewalReview::book( $renewal_id, $reason, $actor_user_id );
			case RenewalReview::ACTION_NOT_PAID:
				return RenewalReview::mark_not_paid( $renewal_id, $reason, $actor_user_id );
			case RenewalReview::ACTION_CLOSE:
				return RenewalReview::close( $renewal_id, $reason, $actor_user_id );
			default:
				return 'review_action_not_allowed';
		}
	}
}
