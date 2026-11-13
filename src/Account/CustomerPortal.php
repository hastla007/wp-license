<?php
/**
 * WooCommerce customer license account page.
 *
 * @package WPLC
 */

namespace WPLC\Account;

use WPLC\Admin\Format;
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
	 * Licenses per account page.
	 */
	public const PER_PAGE = 10;

	/**
	 * Option that remembers for which plugin version the rewrite rules were written.
	 */
	public const REWRITE_VERSION_OPTION = 'wplc_rewrite_version';

	/**
	 * Query argument used when a notice cannot be stored in the WooCommerce session.
	 */
	private const NOTICE_ARG = 'wplc_notice';

	/**
	 * Register endpoint, menu, renderer and form handlers.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'init', array( self::class, 'register_endpoint' ) );
		add_action( 'init', array( self::class, 'maybe_flush_rewrite_rules' ), 99 );
		add_filter( 'woocommerce_account_menu_items', array( self::class, 'add_menu_item' ) );
		add_action( 'woocommerce_account_wplc-licenses_endpoint', array( self::class, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
		add_action( 'admin_post_wplc_deactivate_site', array( self::class, 'handle_deactivate' ) );
		add_action( 'admin_post_wplc_cancel_license', array( self::class, 'handle_cancel' ) );
		add_action( 'admin_post_wplc_resume_license', array( self::class, 'handle_resume' ) );
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
	 * Rewrite the rules once after the plugin version changed.
	 *
	 * Activation flushes them, but a plugin update does not run the activation
	 * hook; without this the account endpoint can return 404 after an update.
	 * Runs late on init, after the endpoint has been registered.
	 *
	 * @return void
	 */
	public static function maybe_flush_rewrite_rules(): void {
		if ( ! defined( 'WPLC_VERSION' ) || get_option( self::REWRITE_VERSION_OPTION ) === WPLC_VERSION ) {
			return;
		}
		// Store first so a failing flush cannot repeat on every request.
		update_option( self::REWRITE_VERSION_OPTION, WPLC_VERSION, true );
		flush_rewrite_rules( false );
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
	 * Whether the current request shows the license endpoint.
	 *
	 * @return bool
	 */
	private static function is_license_endpoint(): bool {
		global $wp;

		return function_exists( 'is_account_page' )
			&& is_account_page()
			&& isset( $wp->query_vars )
			&& is_array( $wp->query_vars )
			&& array_key_exists( 'wplc-licenses', $wp->query_vars );
	}

	/**
	 * Load the copy/confirm script and the styles on the license endpoint only.
	 *
	 * @return void
	 */
	public static function enqueue_assets(): void {
		if ( ! self::is_license_endpoint() ) {
			return;
		}
		wp_enqueue_style( 'wplc-account', plugins_url( 'assets/css/wplc-account.css', WPLC_FILE ), array(), WPLC_VERSION );
		wp_enqueue_script( 'wplc-ui', plugins_url( 'assets/js/wplc-ui.js', WPLC_FILE ), array(), WPLC_VERSION, true );
	}

	/**
	 * Number of pages for a result count.
	 *
	 * @param int $total    Number of licenses.
	 * @param int $per_page Licenses per page.
	 * @return int At least 1.
	 */
	public static function page_count( int $total, int $per_page = self::PER_PAGE ): int {
		return max( 1, (int) ceil( max( 0, $total ) / max( 1, $per_page ) ) );
	}

	/**
	 * Group rows by an integer column.
	 *
	 * @param mixed  $rows   Database rows.
	 * @param string $column Grouping column.
	 * @return array<int,array<int,array<string,mixed>>>
	 */
	public static function group_rows( $rows, string $column ): array {
		$grouped = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( is_array( $row ) && isset( $row[ $column ] ) ) {
				$grouped[ (int) $row[ $column ] ][] = $row;
			}
		}
		return $grouped;
	}

	/**
	 * Render the current customer's licenses and site activations.
	 *
	 * @param mixed $endpoint_value Value after the endpoint in the URL: the page number.
	 * @return void
	 */
	public static function render( $endpoint_value = '' ): void {
		if ( ! is_user_logged_in() ) {
			echo esc_html__( 'Please sign in to view your licenses.', 'wp-license' );
			return;
		}

		global $wpdb;
		$tables  = Schema::table_names();
		$user_id = get_current_user_id();

		self::print_fallback_notice();

		$total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$tables['licenses']} WHERE customer_id = %d", $user_id )
		);

		echo '<h2>' . esc_html__( 'Your licenses', 'wp-license' ) . '</h2>';
		if ( ! $total ) {
			echo '<p>' . esc_html__( 'No licenses are associated with this account.', 'wp-license' ) . '</p>';
			return;
		}

		$pages    = self::page_count( $total );
		$page     = min( $pages, max( 1, absint( is_scalar( $endpoint_value ) ? $endpoint_value : 0 ) ) );
		$licenses = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$tables['licenses']} WHERE customer_id = %d ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
				$user_id,
				self::PER_PAGE,
				( $page - 1 ) * self::PER_PAGE
			),
			ARRAY_A
		);
		$licenses = is_array( $licenses ) ? $licenses : array();
		$related  = self::load_related( $licenses );

		foreach ( $licenses as $license ) {
			self::render_license( $license, $related );
		}

		self::render_pagination( $page, $pages );
	}

	/**
	 * Load everything the licenses of one page need in grouped queries.
	 *
	 * @param array<int,array<string,mixed>> $licenses License rows of the page.
	 * @return array{activations:array<int,array<int,array<string,mixed>>>,limits:array<int,int>,products:array<string,string>,renewal_orders:array<int,int>}
	 */
	private static function load_related( array $licenses ): array {
		global $wpdb;

		$related = array(
			'activations'    => array(),
			'limits'         => array(),
			'products'       => array(),
			'renewal_orders' => array(),
		);
		if ( ! $licenses ) {
			return $related;
		}

		$tables       = Schema::table_names();
		$license_ids  = array_map( 'intval', array_column( $licenses, 'id' ) );
		$placeholders = implode( ',', array_fill( 0, count( $license_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders are generated above.
		$related['activations'] = self::group_rows(
			$wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, license_id, site_url, environment, status, activated_at, last_seen_at FROM {$tables['activations']} WHERE status = %s AND license_id IN ({$placeholders}) ORDER BY activated_at DESC",
					array_merge( array( 'active' ), $license_ids )
				),
				ARRAY_A
			),
			'license_id'
		);

		$limits = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT license_id, value_json FROM {$tables['entitlements']} WHERE entitlement_key = %s AND license_id IN ({$placeholders})",
				array_merge( array( 'sites.live_limit' ), $license_ids )
			),
			ARRAY_A
		);
		foreach ( is_array( $limits ) ? $limits : array() as $row ) {
			$limit = json_decode( (string) $row['value_json'], true );
			if ( is_numeric( $limit ) ) {
				$related['limits'][ (int) $row['license_id'] ] = (int) $limit;
			}
		}

		$product_keys = array_values( array_unique( array_map( 'strval', array_column( $licenses, 'product_key' ) ) ) );
		$key_marks    = implode( ',', array_fill( 0, count( $product_keys ), '%s' ) );
		$products     = $wpdb->get_results(
			$wpdb->prepare( "SELECT product_key, display_name FROM {$tables['products']} WHERE product_key IN ({$key_marks})", $product_keys ),
			ARRAY_A
		);
		foreach ( is_array( $products ) ? $products : array() as $row ) {
			$related['products'][ (string) $row['product_key'] ] = (string) $row['display_name'];
		}

		$past_due_ids = array();
		foreach ( $licenses as $license ) {
			if ( 'past_due' === $license['status'] ) {
				$past_due_ids[] = (int) $license['id'];
			}
		}
		if ( $past_due_ids ) {
			$due_marks = implode( ',', array_fill( 0, count( $past_due_ids ), '%d' ) );
			$renewals  = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT license_id, renewal_order_id FROM {$tables['renewals']} WHERE status IN (%s,%s) AND renewal_order_id IS NOT NULL AND license_id IN ({$due_marks}) ORDER BY created_at ASC, id ASC",
					array_merge( array( 'failed', 'manual_action_required' ), $past_due_ids )
				),
				ARRAY_A
			);
			// Ascending order: the newest renewal of each license overwrites older ones.
			foreach ( is_array( $renewals ) ? $renewals : array() as $row ) {
				$related['renewal_orders'][ (int) $row['license_id'] ] = (int) $row['renewal_order_id'];
			}
		}
		// phpcs:enable

		return $related;
	}

	/**
	 * Render one license.
	 *
	 * @param array<string,mixed> $license License row.
	 * @param array<string,mixed> $related Result of load_related().
	 * @return void
	 */
	private static function render_license( array $license, array $related ): void {
		$license_id  = (int) $license['id'];
		$status      = (string) $license['status'];
		$activations = isset( $related['activations'][ $license_id ] ) ? $related['activations'][ $license_id ] : array();
		$product_key = (string) $license['product_key'];
		$name        = isset( $related['products'][ $product_key ] ) && '' !== $related['products'][ $product_key ] ? $related['products'][ $product_key ] : $product_key;
		$running     = in_array( $status, array( 'active', 'past_due' ), true );

		$live_sites = 0;
		foreach ( $activations as $activation ) {
			if ( 'production' === $activation['environment'] ) {
				++$live_sites;
			}
		}
		$sites_text = isset( $related['limits'][ $license_id ] )
			/* translators: 1: number of live sites in use, 2: number of live sites included in the plan. */
			? sprintf( __( '%1$d of %2$d', 'wp-license' ), $live_sites, $related['limits'][ $license_id ] )
			: (string) $live_sites;

		$key    = LicenseKey::for_uuid( (string) $license['license_uuid'], (string) ( $license['license_key_secret_id'] ?? 'legacy' ) );
		$key_id = 'wplc-key-' . $license_id;

		echo '<section class="wplc-license" aria-labelledby="wplc-license-title-' . esc_attr( (string) $license_id ) . '">';
		echo '<h3 id="wplc-license-title-' . esc_attr( (string) $license_id ) . '">' . esc_html( $name . ' — ' . (string) $license['plan_key'] ) . '</h3>';
		echo '<p><strong>' . esc_html__( 'Status:', 'wp-license' ) . '</strong> ' . esc_html( Format::license_status_label( $status ) ) . '</p>';

		if ( '' !== $key ) {
			echo '<p class="wplc-license-key"><label for="' . esc_attr( $key_id ) . '"><strong>' . esc_html__( 'License key:', 'wp-license' ) . '</strong></label> ';
			echo '<input type="text" readonly id="' . esc_attr( $key_id ) . '" class="input-text" value="' . esc_attr( $key ) . '" size="40" data-wplc-select spellcheck="false" autocomplete="off"> ';
			echo '<button type="button" class="button" data-wplc-copy="' . esc_attr( $key_id ) . '" data-wplc-copied="' . esc_attr__( 'Copied', 'wp-license' ) . '">' . esc_html__( 'Copy', 'wp-license' ) . '</button></p>';
		} else {
			echo '<p><strong>' . esc_html__( 'License key:', 'wp-license' ) . '</strong> ' . esc_html__( 'Currently unavailable. Please contact support.', 'wp-license' ) . '</p>';
		}

		echo '<p><strong>' . esc_html__( 'Valid through:', 'wp-license' ) . '</strong> ' . esc_html( Format::date( $license['paid_until'], false, __( 'No expiry', 'wp-license' ) ) ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Live sites:', 'wp-license' ) . '</strong> ' . esc_html( $sites_text ) . '</p>';

		if ( $running ) {
			echo '<p><strong>' . esc_html__( 'Renewal:', 'wp-license' ) . '</strong> ' . esc_html( self::renewal_text( $license ) ) . '</p>';
		}

		if ( 'past_due' === $status && isset( $related['renewal_orders'][ $license_id ] ) ) {
			self::render_payment_link( (int) $related['renewal_orders'][ $license_id ] );
		}

		echo '<div class="wplc-license-actions">';
		$manual_renewal = '\\WPLC\\Billing\\ManualRenewal';
		if ( class_exists( $manual_renewal ) && method_exists( $manual_renewal, 'can_renew' ) && call_user_func( array( $manual_renewal, 'can_renew' ), $license ) ) {
			self::render_renew_form( $license_id );
		}
		$download = '\\WPLC\\Update\\CustomerDownload';
		if ( class_exists( $download ) && method_exists( $download, 'render_button' ) ) {
			call_user_func( array( $download, 'render_button' ), $license );
		}
		if ( $running && ! empty( $license['cancel_at_period_end'] ) ) {
			self::render_resume_form( $license_id );
		} elseif ( $running && ! empty( $license['renewal_enabled'] ) ) {
			self::render_cancel_form( $license_id );
		}
		echo '</div>';

		echo '<h4>' . esc_html__( 'Sites', 'wp-license' ) . '</h4>';
		if ( ! $activations ) {
			echo '<p>' . esc_html__( 'No sites are activated.', 'wp-license' ) . '</p>';
		} else {
			echo '<table class="shop_table shop_table_responsive"><thead><tr>';
			echo '<th scope="col">' . esc_html__( 'Site', 'wp-license' ) . '</th><th scope="col">' . esc_html__( 'Environment', 'wp-license' ) . '</th><th scope="col">' . esc_html__( 'Activated', 'wp-license' ) . '</th><th scope="col">' . esc_html__( 'Actions', 'wp-license' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $activations as $activation ) {
				echo '<tr><td data-title="' . esc_attr__( 'Site', 'wp-license' ) . '">' . esc_html( (string) $activation['site_url'] ) . '</td>';
				echo '<td data-title="' . esc_attr__( 'Environment', 'wp-license' ) . '">' . esc_html( Format::environment_label( (string) $activation['environment'] ) ) . '</td>';
				echo '<td data-title="' . esc_attr__( 'Activated', 'wp-license' ) . '">' . esc_html( Format::date( $activation['activated_at'] ) ) . '</td>';
				echo '<td data-title="' . esc_attr__( 'Actions', 'wp-license' ) . '">';
				self::render_deactivate_form( (int) $activation['id'], (string) $activation['site_url'] );
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</section>';
	}

	/**
	 * Explain how the license continues after the paid period.
	 *
	 * @param array<string,mixed> $license License row.
	 * @return string
	 */
	private static function renewal_text( array $license ): string {
		$mode = Format::renewal_mode( $license );
		if ( 'cancelling' === $mode ) {
			return __( 'Cancelled. The license ends at the end of the paid period.', 'wp-license' );
		}
		if ( 'auto' === $mode ) {
			return __( 'Renews automatically at the end of the paid period.', 'wp-license' );
		}
		return __( 'Does not renew automatically. Renew it yourself before it ends to keep updates and premium features.', 'wp-license' );
	}

	/**
	 * Link to the payment page of a renewal order that needs customer action.
	 *
	 * @param int $order_id Renewal order ID.
	 * @return void
	 */
	private static function render_payment_link( int $order_id ): void {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof \WC_Order || (int) $order->get_customer_id() !== get_current_user_id() || ! $order->needs_payment() ) {
			return;
		}
		echo '<p><a class="button" href="' . esc_url( $order->get_checkout_payment_url() ) . '">' . esc_html__( 'Complete renewal payment', 'wp-license' ) . '</a></p>';
		echo '<p>' . esc_html__( 'Your payment provider may ask you to authenticate the payment before the renewal can be completed.', 'wp-license' ) . '</p>';
	}

	/**
	 * Previous and next page links.
	 *
	 * @param int $page  Current page.
	 * @param int $pages Number of pages.
	 * @return void
	 */
	private static function render_pagination( int $page, int $pages ): void {
		if ( $pages < 2 ) {
			return;
		}
		echo '<nav class="woocommerce-pagination wplc-pagination" aria-label="' . esc_attr__( 'License pages', 'wp-license' ) . '">';
		if ( $page > 1 ) {
			echo '<a class="woocommerce-button woocommerce-button--previous button" href="' . esc_url( self::account_url( $page - 1 ) ) . '">' . esc_html__( 'Previous', 'wp-license' ) . '</a>';
		} else {
			echo '<span></span>';
		}
		/* translators: 1: current page, 2: number of pages. */
		echo '<span>' . esc_html( sprintf( __( 'Page %1$d of %2$d', 'wp-license' ), $page, $pages ) ) . '</span>';
		if ( $page < $pages ) {
			echo '<a class="woocommerce-button woocommerce-button--next button" href="' . esc_url( self::account_url( $page + 1 ) ) . '">' . esc_html__( 'Next', 'wp-license' ) . '</a>';
		} else {
			echo '<span></span>';
		}
		echo '</nav>';
	}

	/**
	 * URL of the license endpoint.
	 *
	 * @param int $page Page number.
	 * @return string
	 */
	private static function account_url( int $page = 1 ): string {
		$base = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/' );
		if ( ! function_exists( 'wc_get_endpoint_url' ) ) {
			return $base;
		}
		return wc_get_endpoint_url( 'wplc-licenses', $page > 1 ? (string) $page : '', $base );
	}

	/**
	 * Allowlisted customer notices.
	 *
	 * @return array<string,array{0:string,1:string}> Code => [ WooCommerce notice type, message ].
	 */
	public static function notice_messages(): array {
		return array(
			'site_deactivated'   => array( 'success', __( 'The site was deactivated. Its place is free again.', 'wp-license' ) ),
			'site_not_found'     => array( 'error', __( 'The site activation was not found for this account.', 'wp-license' ) ),
			'site_not_active'    => array( 'notice', __( 'This site was already deactivated.', 'wp-license' ) ),
			'site_failed'        => array( 'error', __( 'The site could not be deactivated. Please try again.', 'wp-license' ) ),
			'cancel_scheduled'   => array( 'success', __( 'Your cancellation is recorded. The license stays valid until the end of the paid period.', 'wp-license' ) ),
			'cancel_failed'      => array( 'error', __( 'The license could not be cancelled. It may already be cancelled or no longer be active.', 'wp-license' ) ),
			'cancel_withdrawn'   => array( 'success', __( 'Your cancellation was withdrawn. The license continues as before.', 'wp-license' ) ),
			'resume_failed'      => array( 'error', __( 'The cancellation could not be withdrawn. The license may already have ended.', 'wp-license' ) ),
			'license_not_found'  => array( 'error', __( 'The license was not found for this account.', 'wp-license' ) ),
		);
	}

	/**
	 * Store a notice for the next account page view and return there.
	 *
	 * @param string $code Key of notice_messages().
	 * @return void
	 */
	private static function finish( string $code ): void {
		$messages = self::notice_messages();
		$url      = self::account_url();
		if ( isset( $messages[ $code ] ) && ! self::add_session_notice( $messages[ $code ][1], $messages[ $code ][0] ) ) {
			// No WooCommerce session in this request: carry an allowlisted code instead.
			$url = add_query_arg( self::NOTICE_ARG, $code, $url );
		}
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Queue a WooCommerce notice. Form handlers run on admin-post.php, where
	 * WooCommerce does not start the customer session on its own.
	 *
	 * @param string $message Translated message.
	 * @param string $type    success, error or notice.
	 * @return bool False when no session is available.
	 */
	private static function add_session_notice( string $message, string $type ): bool {
		if ( ! function_exists( 'wc_add_notice' ) || ! function_exists( 'WC' ) || ! did_action( 'woocommerce_init' ) ) {
			return false;
		}
		$woocommerce = WC();
		if ( empty( $woocommerce->session ) && is_callable( array( $woocommerce, 'initialize_session' ) ) ) {
			$woocommerce->initialize_session();
		}
		if ( empty( $woocommerce->session ) || ! is_callable( array( $woocommerce->session, 'get' ) ) ) {
			return false;
		}
		wc_add_notice( $message, $type );

		return true;
	}

	/**
	 * Print the notice carried in the URL when the session was unavailable.
	 *
	 * @return void
	 */
	private static function print_fallback_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of an allowlisted code.
		$code     = isset( $_GET[ self::NOTICE_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::NOTICE_ARG ] ) ) : '';
		$messages = self::notice_messages();
		if ( '' === $code || ! isset( $messages[ $code ] ) ) {
			return;
		}
		if ( function_exists( 'wc_print_notice' ) ) {
			wc_print_notice( $messages[ $code ][1], $messages[ $code ][0] );
		} else {
			echo '<p role="alert">' . esc_html( $messages[ $code ][1] ) . '</p>';
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
			self::finish( 'site_not_found' );
		}
		if ( 'active' !== $row['status'] ) {
			self::finish( 'site_not_active' );
		}

		$manager = '\\WPLC\\License\\ActivationManager';
		if ( class_exists( $manager ) && method_exists( $manager, 'deactivate' ) ) {
			// Shared implementation: also deactivates staging children and writes the audit entries.
			$deactivated = (bool) call_user_func( array( $manager, 'deactivate' ), $activation_id, 'Deactivated by license owner', get_current_user_id() );
			self::finish( $deactivated ? 'site_deactivated' : 'site_failed' );
		}

		$now = gmdate( 'Y-m-d H:i:s' );
		$updated = $wpdb->update(
			$tables['activations'],
			array( 'status' => 'deactivated', 'deactivated_at' => $now, 'last_seen_at' => $now ),
			array( 'id' => $activation_id, 'status' => 'active' ),
			array( '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);
		if ( false === $updated ) {
			self::finish( 'site_failed' );
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

		self::finish( 'site_deactivated' );
	}

	/**
	 * Whether a license belongs to the current user.
	 *
	 * @param int $license_id License ID.
	 * @return bool
	 */
	private static function current_user_owns( int $license_id ): bool {
		global $wpdb;

		$tables = Schema::table_names();
		$owner  = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT customer_id FROM {$tables['licenses']} WHERE id = %d LIMIT 1", $license_id )
		);

		return $owner > 0 && get_current_user_id() === $owner;
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

		if ( ! self::current_user_owns( $license_id ) ) {
			self::finish( 'license_not_found' );
		}

		$scheduled = LicenseLifecycle::schedule_cancellation( $license_id, 'Customer requested cancellation', get_current_user_id() );
		self::finish( $scheduled ? 'cancel_scheduled' : 'cancel_failed' );
	}

	/**
	 * Withdraw a cancellation while the license still runs.
	 *
	 * @return void
	 */
	public static function handle_resume(): void {
		$license_id = isset( $_POST['license_id'] ) ? absint( $_POST['license_id'] ) : 0;
		if ( ! is_user_logged_in() || ! $license_id ) {
			wp_die( esc_html__( 'You are not allowed to change this license.', 'wp-license' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'wplc_resume_license_' . $license_id );

		if ( ! self::current_user_owns( $license_id ) ) {
			self::finish( 'license_not_found' );
		}

		$resumed = LicenseLifecycle::resume_renewal( $license_id, get_current_user_id() );
		self::finish( $resumed ? 'cancel_withdrawn' : 'resume_failed' );
	}

	/**
	 * Print a nonce field without an id attribute (several forms share one page).
	 *
	 * @param string $action Nonce action.
	 * @return void
	 */
	private static function nonce_input( string $action ): void {
		echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( $action ) ) . '">';
	}

	/**
	 * Render a POST form to deactivate a site.
	 *
	 * @param int    $activation_id Activation ID.
	 * @param string $site_url Site URL, for the confirmation question.
	 * @return void
	 */
	private static function render_deactivate_form( int $activation_id, string $site_url = '' ): void {
		/* translators: %s: site URL. */
		$question = sprintf( __( 'Deactivate %s? The site loses updates and premium features until it is activated again.', 'wp-license' ), $site_url );
		echo '<form method="post" class="wplc-inline" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-wplc-confirm="' . esc_attr( $question ) . '">';
		echo '<input type="hidden" name="action" value="wplc_deactivate_site">';
		echo '<input type="hidden" name="activation_id" value="' . esc_attr( (string) $activation_id ) . '">';
		self::nonce_input( 'wplc_deactivate_site_' . $activation_id );
		echo '<button type="submit" class="button">' . esc_html__( 'Deactivate', 'wp-license' );
		/* translators: %s: site URL. */
		echo '<span class="screen-reader-text"> ' . esc_html( sprintf( __( 'site %s', 'wp-license' ), $site_url ) ) . '</span></button>';
		echo '</form>';
	}

	/**
	 * Render a POST form to cancel at period end.
	 *
	 * @param int $license_id License ID.
	 * @return void
	 */
	private static function render_cancel_form( int $license_id ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-wplc-confirm="' . esc_attr__( 'Cancel the automatic renewal? The license stays valid until the end of the paid period and ends then.', 'wp-license' ) . '">';
		echo '<input type="hidden" name="action" value="wplc_cancel_license">';
		echo '<input type="hidden" name="license_id" value="' . esc_attr( (string) $license_id ) . '">';
		self::nonce_input( 'wplc_cancel_license_' . $license_id );
		echo '<button type="submit" class="button">' . esc_html__( 'Cancel at period end', 'wp-license' ) . '</button>';
		echo '</form>';
	}

	/**
	 * Render a POST form to withdraw a cancellation.
	 *
	 * @param int $license_id License ID.
	 * @return void
	 */
	private static function render_resume_form( int $license_id ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="wplc_resume_license">';
		echo '<input type="hidden" name="license_id" value="' . esc_attr( (string) $license_id ) . '">';
		self::nonce_input( 'wplc_resume_license_' . $license_id );
		echo '<button type="submit" class="button">' . esc_html__( 'Undo cancellation', 'wp-license' ) . '</button>';
		echo '</form>';
	}

	/**
	 * Render the "Renew now" form handled by the manual renewal package.
	 *
	 * @param int $license_id License ID.
	 * @return void
	 */
	private static function render_renew_form( int $license_id ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="wplc_start_renewal">';
		echo '<input type="hidden" name="license_id" value="' . esc_attr( (string) $license_id ) . '">';
		self::nonce_input( 'wplc_start_renewal_' . $license_id );
		echo '<button type="submit" class="button alt">' . esc_html__( 'Renew now', 'wp-license' ) . '</button>';
		echo '</form>';
	}
}
