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
use WPLC\License\LicenseStateMachine;
use WPLC\Notifications\Notifications;
use WPLC\Notifications\ReminderSchedule;
use WPLC\Notifications\RenewalTerms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a self-service license area to WooCommerce My Account.
 *
 * The page is an ordinary WooCommerce account endpoint (registered through
 * `woocommerce_get_query_vars`), rendered by overridable templates under
 * `templates/myaccount/`. Its forms post to the page they are on, never to
 * wp-admin; the old `admin-post.php` handlers stay for pages that are still
 * in a cache.
 */
final class CustomerPortal {

	/**
	 * Licenses per account page.
	 */
	public const PER_PAGE = 10;

	/**
	 * Key of the account endpoint (query var, menu item and hook names). The
	 * URL slug can differ: see endpoint_slug().
	 */
	public const ENDPOINT = 'wplc-licenses';

	/**
	 * Option that remembers for which plugin version and slug the rewrite rules were written.
	 */
	public const REWRITE_VERSION_OPTION = 'wplc_rewrite_version';

	/**
	 * Raised whenever the way the endpoint is registered changes, to force one flush.
	 */
	private const REWRITE_REVISION = '2';

	/**
	 * Query argument used when a notice cannot be stored in the WooCommerce session.
	 */
	private const NOTICE_ARG = 'wplc_notice';

	/**
	 * Query argument with the page number where the list is shown by the shortcode.
	 */
	private const PAGE_ARG = 'wplc_page';

	/**
	 * Marker field of forms that are handled on the frontend.
	 */
	public const FORM_MARKER = 'wplc_portal';

	/**
	 * URL the forms of the current rendering post to; empty for the account endpoint.
	 *
	 * @var string
	 */
	private static $form_url = '';

	/**
	 * URL a frontend form handler returns to; empty for the account endpoint.
	 *
	 * @var string
	 */
	private static $return_url = '';

	/**
	 * Register endpoint, menu, renderer and form handlers.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_filter( 'woocommerce_get_query_vars', array( self::class, 'add_query_var' ) );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( self::class, 'endpoint_title' ) );
		add_action( 'init', array( self::class, 'maybe_flush_rewrite_rules' ), 99 );
		add_filter( 'woocommerce_account_menu_items', array( self::class, 'add_menu_item' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( self::class, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
		add_action( 'wplc_account_license_actions', array( self::class, 'render_actions' ), 10, 2 );
		add_action( 'woocommerce_thankyou', array( self::class, 'render_thankyou_hint' ), 20 );
		add_shortcode( 'wplc_licenses', array( self::class, 'shortcode' ) );

		// Customer forms post to the frontend page they are shown on.
		add_action( 'wp_loaded', array( self::class, 'handle_front_post' ), 20 );
		// Kept for pages that were cached with the earlier form target.
		add_action( 'admin_post_wplc_deactivate_site', array( self::class, 'handle_deactivate' ) );
		add_action( 'admin_post_wplc_cancel_license', array( self::class, 'handle_cancel' ) );
		add_action( 'admin_post_wplc_resume_license', array( self::class, 'handle_resume' ) );
		foreach ( array( 'wplc_deactivate_site', 'wplc_cancel_license', 'wplc_resume_license' ) as $action ) {
			add_action( 'admin_post_nopriv_' . $action, array( self::class, 'handle_logged_out' ) );
		}

		PaymentMethods::register_hooks();
		PublicCancellation::register_hooks();
	}

	/**
	 * URL slug of the endpoint.
	 *
	 * @return string
	 */
	public static function endpoint_slug(): string {
		/**
		 * Filter the URL slug of the license page in the customer account,
		 * for example to translate it. Rewrite rules are refreshed automatically.
		 *
		 * @param string $slug Slug, default "wplc-licenses".
		 */
		$slug = sanitize_title( (string) apply_filters( 'wplc_account_endpoint_slug', self::ENDPOINT ) );

		return '' !== $slug ? $slug : self::ENDPOINT;
	}

	/**
	 * Announce the endpoint to WooCommerce, which registers the rewrite rule
	 * with the right mask (pages only, or root when the account page is the
	 * front page), maps the slug back to the key and resolves endpoint URLs.
	 *
	 * @param mixed $query_vars Endpoint key => slug.
	 * @return mixed
	 */
	public static function add_query_var( $query_vars ) {
		if ( is_array( $query_vars ) ) {
			$query_vars[ self::ENDPOINT ] = self::endpoint_slug();
		}
		return $query_vars;
	}

	/**
	 * Page title of the endpoint (WooCommerce would show "My account").
	 *
	 * @return string
	 */
	public static function endpoint_title(): string {
		return __( 'Licenses', 'wp-license' );
	}

	/**
	 * Register the rewrite endpoint right now.
	 *
	 * Only needed on plugin activation, which runs after WooCommerce has
	 * registered its endpoints for the request. On every other request
	 * WooCommerce registers the endpoint from add_query_var().
	 *
	 * @return void
	 */
	public static function register_endpoint(): void {
		if ( false === has_filter( 'woocommerce_get_query_vars', array( self::class, 'add_query_var' ) ) ) {
			add_filter( 'woocommerce_get_query_vars', array( self::class, 'add_query_var' ) );
		}
		$mask  = EP_PAGES;
		if ( function_exists( 'WC' ) ) {
			$mask = (int) WC()->query->get_endpoints_mask();
		}
		add_rewrite_endpoint( self::endpoint_slug(), $mask );
	}

	/**
	 * Rewrite the rules once after the plugin version or the slug changed.
	 *
	 * Activation flushes them, but a plugin update does not run the activation
	 * hook; without this the account endpoint can return 404 after an update.
	 * Runs late on init, after WooCommerce has registered the endpoint.
	 *
	 * @return void
	 */
	public static function maybe_flush_rewrite_rules(): void {
		if ( ! defined( 'WPLC_VERSION' ) ) {
			return;
		}
		$signature = WPLC_VERSION . ':' . self::REWRITE_REVISION . ':' . self::endpoint_slug();
		if ( get_option( self::REWRITE_VERSION_OPTION ) === $signature ) {
			return;
		}
		// Store first so a failing flush cannot repeat on every request.
		update_option( self::REWRITE_VERSION_OPTION, $signature, true );
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
		$items[ self::ENDPOINT ] = __( 'Licenses', 'wp-license' );
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
			&& ( array_key_exists( self::ENDPOINT, $wp->query_vars ) || array_key_exists( self::endpoint_slug(), $wp->query_vars ) );
	}

	/**
	 * Load styles and script in the page head on the license endpoint.
	 *
	 * Pages that show the list in another way (shortcode, page builder) get
	 * them from render() instead.
	 *
	 * @return void
	 */
	public static function enqueue_assets(): void {
		if ( self::is_license_endpoint() ) {
			self::enqueue_now();
		}
	}

	/**
	 * Enqueue the copy/confirm script and the styles.
	 *
	 * Safe to call while the page is being rendered: WordPress prints late
	 * styles and scripts in the footer.
	 *
	 * @return void
	 */
	public static function enqueue_now(): void {
		if ( ! function_exists( 'wp_enqueue_style' ) || ! defined( 'WPLC_FILE' ) ) {
			return;
		}
		wp_enqueue_style( 'wplc-account', plugins_url( 'assets/css/wplc-account.css', WPLC_FILE ), array(), self::asset_version( 'assets/css/wplc-account.css' ) );
		wp_enqueue_script( 'wplc-ui', plugins_url( 'assets/js/wplc-ui.js', WPLC_FILE ), array(), self::asset_version( 'assets/js/wplc-ui.js' ), true );
	}

	/**
	 * Cache-busting version of an asset: plugin version plus the file's change time.
	 *
	 * @param string $relative Path relative to the plugin directory.
	 * @return string
	 */
	public static function asset_version( string $relative ): string {
		$version = defined( 'WPLC_VERSION' ) ? (string) WPLC_VERSION : '0';
		$file    = defined( 'WPLC_PATH' ) ? WPLC_PATH . ltrim( $relative, '/' ) : '';
		$changed = '' !== $file && is_readable( $file ) ? (int) filemtime( $file ) : 0;

		return $changed > 0 ? $version . '.' . $changed : $version;
	}

	/**
	 * Tell page caches and the browser not to store the license page.
	 *
	 * @return void
	 */
	public static function send_no_cache(): void {
		if ( class_exists( '\\WC_Cache_Helper' ) ) {
			\WC_Cache_Helper::set_nocache_constants();
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Convention of page cache plugins.
		}
		if ( ! headers_sent() ) {
			nocache_headers();
		}
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
	 * Render the current customer's licenses on the account endpoint.
	 *
	 * @param mixed $endpoint_value Value after the endpoint in the URL: the page number.
	 * @return void
	 */
	public static function render( $endpoint_value = '' ): void {
		self::$form_url = '';
		self::output( absint( is_scalar( $endpoint_value ) ? $endpoint_value : 0 ), false );
	}

	/**
	 * Shortcode [wplc_licenses]: the same list on any page, for account pages
	 * built with a page builder.
	 *
	 * @return string
	 */
	public static function shortcode(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page number.
		$page = isset( $_GET[ self::PAGE_ARG ] ) ? absint( wp_unslash( $_GET[ self::PAGE_ARG ] ) ) : 0;
		$url  = function_exists( 'get_permalink' ) ? get_permalink() : '';

		self::$form_url = is_string( $url ) && '' !== $url ? ( $page > 1 ? add_query_arg( self::PAGE_ARG, $page, $url ) : $url ) : '';
		ob_start();
		try {
			self::output( $page, true );
		} finally {
			$html           = (string) ob_get_clean();
			self::$form_url = '';
		}

		return $html;
	}

	/**
	 * Load the licenses of one page and hand them to the template.
	 *
	 * @param int  $page       Requested page number.
	 * @param bool $standalone True outside the account endpoint: notices are printed here.
	 * @return void
	 */
	private static function output( int $page, bool $standalone ): void {
		global $wpdb;

		// The page shows a license key and one-time form tokens: never from a cache.
		self::send_no_cache();
		self::enqueue_now();

		if ( ! is_user_logged_in() ) {
			echo '<p class="wplc-login-required">' . esc_html__( 'Please sign in to view your licenses.', 'wp-license' );
			if ( $standalone && function_exists( 'wc_get_page_permalink' ) ) {
				echo ' <a href="' . esc_url( wc_get_page_permalink( 'myaccount' ) ) . '">' . esc_html__( 'Sign in', 'wp-license' ) . '</a>';
			}
			echo '</p>';
			return;
		}

		$tables  = Schema::table_names();
		$user_id = get_current_user_id();

		if ( $standalone && function_exists( 'wc_print_notices' ) ) {
			wc_print_notices();
		}
		self::print_fallback_notice();

		$total = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE customer_id = %d', $tables['licenses'], $user_id )
		);

		$pages    = self::page_count( $total );
		$page     = min( $pages, max( 1, $page ) );
		$licenses = array();
		if ( $total ) {
			$licenses = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE customer_id = %d ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d',
					$tables['licenses'],
					$user_id,
					self::PER_PAGE,
					( $page - 1 ) * self::PER_PAGE
				),
				ARRAY_A
			);
			$licenses = is_array( $licenses ) ? $licenses : array();
		}

		$related = self::load_related( $licenses );
		$views   = array();
		foreach ( $licenses as $license ) {
			$views[] = self::license_view( $license, $related );
		}

		wc_get_template(
			'myaccount/wplc-licenses.php',
			array(
				'licenses' => $views,
				'total'    => $total,
				'page'     => $page,
				'pages'    => $pages,
				'prev_url' => $page > 1 ? self::page_url( $page - 1, $standalone ) : '',
				'next_url' => $page < $pages ? self::page_url( $page + 1, $standalone ) : '',
			),
			'wp-license/',
			WPLC_PATH . 'templates/'
		);
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
					"SELECT id, license_id, site_url, environment, status, activated_at, last_seen_at FROM {$tables['activations']} WHERE status = %s AND license_id IN ({$placeholders}) ORDER BY activated_at DESC, id DESC",
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
	 * Build the display values of one license for the template.
	 *
	 * @param array<string,mixed> $license License row.
	 * @param array<string,mixed> $related Result of load_related().
	 * @return array<string,mixed>
	 */
	public static function license_view( array $license, array $related ): array {
		$license_id  = (int) $license['id'];
		$status      = (string) $license['status'];
		$product_key = (string) $license['product_key'];
		$name        = isset( $related['products'][ $product_key ] ) && '' !== $related['products'][ $product_key ] ? (string) $related['products'][ $product_key ] : $product_key;
		$running     = in_array( $status, array( 'active', 'past_due' ), true );
		$has_access  = LicenseLifecycle::has_access( $license );

		$activations = array();
		$live_sites  = 0;
		foreach ( isset( $related['activations'][ $license_id ] ) ? $related['activations'][ $license_id ] : array() as $activation ) {
			if ( 'production' === $activation['environment'] ) {
				++$live_sites;
			}
			$activations[] = array(
				'id'          => (int) $activation['id'],
				'site_url'    => (string) $activation['site_url'],
				'environment' => Format::environment_label( (string) $activation['environment'] ),
				'activated'   => Format::date( $activation['activated_at'] ),
			);
		}
		$sites_text = isset( $related['limits'][ $license_id ] )
			/* translators: 1: number of live sites in use, 2: number of live sites included in the plan. */
			? sprintf( __( '%1$d of %2$d', 'wp-license' ), $live_sites, $related['limits'][ $license_id ] )
			: (string) $live_sites;

		// Only a key that reproduces the stored verifier is shown. After a changed salt or a wrong
		// secret the derived value would be a different, invalid key.
		$key_id = (string) ( $license['license_key_secret_id'] ?? 'legacy' );
		$key    = LicenseKey::matches_hash( (string) $license['license_uuid'], $key_id, (string) ( $license['license_key_hash'] ?? '' ) )
			? LicenseKey::for_uuid( (string) $license['license_uuid'], $key_id )
			: '';

		$auto        = $running && ReminderSchedule::renews_automatically( $license );
		$can_renew   = self::can_renew( $license );
		$pay_url     = 'past_due' === $status && isset( $related['renewal_orders'][ $license_id ] ) ? self::payment_url( (int) $related['renewal_orders'][ $license_id ] ) : '';
		$has_release = self::has_release( $product_key );

		$view = array(
			'id'            => $license_id,
			'license'       => $license,
			'title'         => $name,
			'plan'          => Notifications::plan_label( (string) $license['plan_key'], $license ),
			'reference'     => (string) ( $license['key_prefix'] ?? '' ),
			'status'        => $status,
			'status_label'  => Format::license_status_label( $status ),
			'status_help'   => self::status_help( $license, $can_renew, '' !== $pay_url ),
			'key'           => $key,
			'valid_until'   => Format::date( $license['paid_until'], false, __( 'No expiry', 'wp-license' ) ),
			'sites_text'    => $sites_text,
			'running'       => $running,
			'renewal_text'  => $running ? self::renewal_text( $license ) : '',
			'renewal_terms' => $auto ? RenewalTerms::rows( $license ) : array(),
			'pay_url'       => $pay_url,
			'can_renew'     => $can_renew,
			'activations'   => $activations,
			// The download button prints its own hint when no release is published.
			'download_hint' => '',
			'install_steps' => $has_access ? Notifications::install_steps( $license ) : array(),
			'docs_url'      => $has_access ? Notifications::docs_url( $product_key ) : '',
		);

		/**
		 * Filter the display values of one license in the customer account.
		 *
		 * @param array<string,mixed> $view    Display values handed to templates/myaccount/wplc-license.php.
		 * @param array<string,mixed> $license License row.
		 */
		$filtered = (array) apply_filters( 'wplc_account_license_view', $view, $license );

		// Keys a filter removed fall back to the built-in values; the template relies on all of them.
		return array_merge( $view, $filtered );
	}

	/**
	 * Whether the customer may start a manual renewal now.
	 *
	 * @param array<string,mixed> $license License row.
	 * @return bool
	 */
	private static function can_renew( array $license ): bool {
		return \WPLC\Billing\ManualRenewal::can_renew( $license );
	}

	/**
	 * Whether a published release exists for a product.
	 *
	 * @param string $product_key Product key.
	 * @return bool
	 */
	private static function has_release( string $product_key ): bool {
		static $cache = array();

		if ( ! isset( $cache[ $product_key ] ) ) {
			$cache[ $product_key ] = null !== \WPLC\Update\ReleaseRegistry::latest_published( $product_key );
		}
		return $cache[ $product_key ];
	}

	/**
	 * Explain a status in which the license does not simply work, and what the customer can do.
	 *
	 * @param array<string,mixed> $license      License row.
	 * @param bool                $can_renew    Whether "Renew now" is offered.
	 * @param bool                $has_pay_link Whether a link to the open renewal payment is shown.
	 * @return string Empty for a license that needs no explanation.
	 */
	public static function status_help( array $license, bool $can_renew, bool $has_pay_link ): string {
		$status     = (string) ( $license['status'] ?? '' );
		$paid_until = Format::utc_timestamp( $license['paid_until'] ?? '' );
		$support    = Notifications::support_email();
		$contact    = '' !== $support
			/* translators: %s: email address. */
			? ' ' . sprintf( __( 'Please contact our support: %s', 'wp-license' ), $support )
			: ' ' . __( 'Please contact our support.', 'wp-license' );

		switch ( $status ) {
			case 'past_due':
				$text = null !== $paid_until
					? sprintf(
						/* translators: %s: date. */
						__( 'We could not collect the renewal payment. The license keeps working until %s; without a payment it expires then.', 'wp-license' ),
						Notifications::format_date( $paid_until + LicenseLifecycle::GRACE_DAYS * 86400 )
					)
					: __( 'We could not collect the renewal payment.', 'wp-license' );
				if ( $has_pay_link ) {
					return $text . ' ' . __( 'Use "Complete renewal payment" to pay now.', 'wp-license' );
				}
				return $text . ' ' . ( $can_renew ? __( 'Use "Renew now" to pay with any payment method.', 'wp-license' ) : __( 'Please check your payment method.', 'wp-license' ) );

			case 'expired':
				if ( $can_renew && null !== $paid_until ) {
					return sprintf(
						/* translators: %s: date. */
						__( 'This license has expired: updates, downloads and new site activations are not available. You can still renew it until %s with "Renew now"; it then continues with the same key and the same sites.', 'wp-license' ),
						Notifications::format_date( $paid_until + LicenseStateMachine::MANUAL_RENEWAL_WINDOW_DAYS * 86400 )
					);
				}
				return __( 'This license has expired and can no longer be renewed. Updates, downloads and site activations are not available. To use the product again, please buy a new license.', 'wp-license' );

			case 'suspended':
				return __( 'This license is suspended. Updates, downloads and premium features are not available until the suspension is lifted.', 'wp-license' ) . $contact;

			case 'revoked':
				return __( 'This license was revoked, for example because its order was refunded. It can no longer be used.', 'wp-license' ) . $contact;
		}

		return '';
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
	 * Payment page of a renewal order that needs customer action.
	 *
	 * @param int $order_id Renewal order ID.
	 * @return string URL, or an empty string when the order is not payable by this customer.
	 */
	private static function payment_url( int $order_id ): string {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof \WC_Order || (int) $order->get_customer_id() !== get_current_user_id() || ! $order->needs_payment() ) {
			return '';
		}
		return (string) $order->get_checkout_payment_url();
	}

	/**
	 * Print the action buttons of one license (hooked to `wplc_account_license_actions`).
	 *
	 * @param mixed $license License row.
	 * @param mixed $view    Result of license_view().
	 * @return void
	 */
	public static function render_actions( $license, $view = array() ): void {
		if ( ! is_array( $license ) || empty( $license['id'] ) ) {
			return;
		}
		$view       = is_array( $view ) ? $view : array();
		$license_id = (int) $license['id'];
		$status     = (string) $license['status'];
		$running    = in_array( $status, array( 'active', 'past_due' ), true );
		$name       = self::accessible_name( $license, (string) ( $view['title'] ?? '' ) );
		$auto       = ReminderSchedule::renews_automatically( $license );

		// With automatic renewal the saved payment method is charged on the renewal date; an extra
		// manual payment before that would only collide with it. Offered again once that charge failed.
		$can_renew = isset( $view['can_renew'] ) ? (bool) $view['can_renew'] : self::can_renew( $license );
		if ( $can_renew && ! ( 'active' === $status && $auto ) ) {
			self::render_renew_form( $license_id, $name );
		}

		self::render_download_form( $license );

		if ( $running && ! empty( $license['cancel_at_period_end'] ) ) {
			self::render_resume_form( $license, $name );
		} elseif ( $running && ! empty( $license['renewal_enabled'] ) ) {
			self::render_cancel_form( $license, $name );
		}
	}

	/**
	 * Name of a license for assistive technology: several licenses have the same buttons.
	 *
	 * @param array<string,mixed> $license License row.
	 * @param string              $title   Product name.
	 * @return string
	 */
	private static function accessible_name( array $license, string $title ): string {
		$reference = (string) ( $license['key_prefix'] ?? '' );
		if ( '' === $title ) {
			$title = (string) ( $license['product_key'] ?? '' );
		}
		/* translators: 1: product name, 2: license reference such as WPLC-1A2B3C4D. */
		return '' !== $reference ? sprintf( __( '%1$s, license %2$s', 'wp-license' ), $title, $reference ) : $title;
	}

	/**
	 * URL of the license endpoint.
	 *
	 * @param int $page Page number.
	 * @return string
	 */
	public static function account_url( int $page = 1 ): string {
		$base = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/' );
		if ( ! function_exists( 'wc_get_endpoint_url' ) ) {
			return $base;
		}
		return wc_get_endpoint_url( self::ENDPOINT, $page > 1 ? (string) $page : '', $base );
	}

	/**
	 * URL of another page of the list.
	 *
	 * @param int  $page       Page number.
	 * @param bool $standalone Whether the list is shown by the shortcode.
	 * @return string
	 */
	private static function page_url( int $page, bool $standalone ): string {
		if ( ! $standalone ) {
			return self::account_url( $page );
		}
		$url = function_exists( 'get_permalink' ) ? get_permalink() : '';
		$url = is_string( $url ) && '' !== $url ? $url : self::account_url();

		return $page > 1 ? add_query_arg( self::PAGE_ARG, $page, $url ) : remove_query_arg( self::PAGE_ARG, $url );
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
			// The form was open for too long, or the customer signed out in another tab.
			'session_expired'    => array( 'error', __( 'Your session has expired, so nothing was changed. Please try again.', 'wp-license' ) ),
			'login_required'     => array( 'notice', __( 'Please sign in to manage your licenses.', 'wp-license' ) ),
			'action_unavailable' => array( 'error', __( 'This action is not available at the moment.', 'wp-license' ) ),
			// Payment method of the automatic renewal.
			'payment_method_saved'    => array( 'success', __( 'The payment method for the automatic renewal was changed.', 'wp-license' ) ),
			'payment_method_failed'   => array( 'error', __( 'The payment method could not be used for the automatic renewal. Please choose another one.', 'wp-license' ) ),
			'renewal_enabled'         => array( 'success', __( 'Automatic renewal is now switched on for this license.', 'wp-license' ) ),
			'renewal_enable_failed'   => array( 'error', __( 'Automatic renewal could not be switched on. Please check the payment method and try again.', 'wp-license' ) ),
			'renewal_consent_missing' => array( 'error', __( 'Please confirm the renewal terms to switch on automatic renewal.', 'wp-license' ) ),
			// Manual renewal (same texts as the errors of ManualRenewal::start()).
			'manual_renewal_unavailable' => array( 'error', __( 'This license cannot be renewed at the moment.', 'wp-license' ) ),
			'manual_renewal_busy'        => array( 'notice', __( 'A renewal for this license is being processed. Please try again in a few minutes.', 'wp-license' ) ),
			'manual_renewal_paid'        => array( 'notice', __( 'Your payment for this renewal has been received and is being processed.', 'wp-license' ) ),
			'manual_renewal_pending'     => array( 'notice', __( 'A renewal order for this license is already waiting for your payment to arrive.', 'wp-license' ) ),
		);
	}

	/**
	 * Send the customer back to the licenses page with one of the notices above.
	 *
	 * For handlers outside this class. Works on the frontend and on
	 * admin-post.php, where WooCommerce has no session.
	 *
	 * @param string $code Key of notice_messages().
	 * @return void
	 */
	public static function redirect_with_notice( string $code ): void {
		self::finish( $code );
	}

	/**
	 * Store a notice for the next page view and return to the page the form was on.
	 *
	 * @param string $code Key of notice_messages().
	 * @return void
	 */
	private static function finish( string $code ): void {
		$messages = self::notice_messages();
		$url      = '' !== self::$return_url ? self::$return_url : self::account_url();
		if ( isset( $messages[ $code ] ) && ! self::add_session_notice( $messages[ $code ][1], $messages[ $code ][0] ) ) {
			// No WooCommerce session in this request: carry an allowlisted code instead.
			$url = add_query_arg( self::NOTICE_ARG, $code, $url );
		}
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Queue a WooCommerce notice. On admin-post.php WooCommerce does not start
	 * the customer session on its own.
	 *
	 * @param string $message Translated message.
	 * @param string $type    success, error or notice.
	 * @return bool False when no session is available.
	 */
	public static function add_session_notice( string $message, string $type ): bool {
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
	 * Handle a customer form that was posted to a frontend page.
	 *
	 * An expired session or form token never ends on a wp-admin error page:
	 * the customer returns to the page with a notice.
	 *
	 * @return void
	 */
	public static function handle_front_post(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The nonce is verified per action below.
		if ( 'POST' !== $method || empty( $_POST[ self::FORM_MARKER ] ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- The nonce is verified per action below.
		$action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';
		$return = isset( $_POST['wplc_return'] ) ? esc_url_raw( wp_unslash( $_POST['wplc_return'] ) ) : '';
		$object = 'wplc_deactivate_site' === $action
			? ( isset( $_POST['activation_id'] ) ? absint( wp_unslash( $_POST['activation_id'] ) ) : 0 )
			: ( isset( $_POST['license_id'] ) ? absint( wp_unslash( $_POST['license_id'] ) ) : 0 );
		$nonce  = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		// phpcs:enable

		$nonce_actions = array(
			'wplc_deactivate_site'    => 'wplc_deactivate_site_',
			'wplc_cancel_license'     => 'wplc_cancel_license_',
			'wplc_resume_license'     => 'wplc_resume_license_',
			'wplc_start_renewal'      => 'wplc_start_renewal_',
			'wplc_download_package'   => 'wplc_download_package_',
			'wplc_set_payment_method' => 'wplc_set_payment_method_',
			'wplc_enable_renewal'     => 'wplc_enable_renewal_',
		);
		if ( ! isset( $nonce_actions[ $action ] ) ) {
			return;
		}

		self::send_no_cache();
		self::$return_url = '' !== $return ? (string) wp_validate_redirect( $return, '' ) : '';

		if ( ! is_user_logged_in() ) {
			// The account page shows the login form.
			self::$return_url = '';
			self::finish( 'login_required' );
		}
		if ( ! $object || ! wp_verify_nonce( $nonce, $nonce_actions[ $action ] . $object ) ) {
			self::finish( 'session_expired' );
		}

		switch ( $action ) {
			case 'wplc_deactivate_site':
				self::finish( self::deactivate_site( $object ) );
				break;

			case 'wplc_cancel_license':
				self::finish( self::cancel_license( $object ) );
				break;

			case 'wplc_resume_license':
				self::finish( self::resume_license( $object ) );
				break;

			case 'wplc_set_payment_method':
			case 'wplc_enable_renewal':
				self::finish( PaymentMethods::handle_post( $action, $object ) );
				break;

			case 'wplc_start_renewal':
				// Handler of the billing package; it verifies the same nonce and redirects itself.
				self::delegate( '\\WPLC\\Billing\\ManualRenewal', 'handle_start' );
				break;

			case 'wplc_download_package':
				// Handler of the update package; it verifies the same nonce and sends the file.
				self::delegate( '\\WPLC\\Update\\CustomerDownload', 'handle' );
				break;
		}
	}

	/**
	 * Run a form handler of another package, which ends the request itself.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @param string $method     Public static handler.
	 * @return void
	 */
	private static function delegate( string $class_name, string $method ): void {
		if ( class_exists( $class_name ) && is_callable( array( $class_name, $method ) ) ) {
			call_user_func( array( $class_name, $method ) );
		}
		self::finish( 'action_unavailable' );
	}

	/**
	 * Deactivate a site of the current customer.
	 *
	 * @param int $activation_id Activation ID.
	 * @return string Key of notice_messages().
	 */
	private static function deactivate_site( int $activation_id ): string {
		global $wpdb;

		$tables = Schema::table_names();
		$row    = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT a.* FROM %i a INNER JOIN %i l ON l.id = a.license_id WHERE a.id = %d AND l.customer_id = %d LIMIT 1',
				$tables['activations'],
				$tables['licenses'],
				$activation_id,
				get_current_user_id()
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return 'site_not_found';
		}
		if ( 'active' !== $row['status'] ) {
			return 'site_not_active';
		}

		$manager = '\\WPLC\\License\\ActivationManager';
		if ( class_exists( $manager ) && method_exists( $manager, 'deactivate' ) ) {
			// Shared implementation: also deactivates staging children and writes the audit entries.
			$deactivated = (bool) call_user_func( array( $manager, 'deactivate' ), $activation_id, 'Deactivated by license owner', get_current_user_id() );
			return $deactivated ? 'site_deactivated' : 'site_failed';
		}

		$now     = gmdate( 'Y-m-d H:i:s' );
		$updated = $wpdb->update(
			$tables['activations'],
			array(
				'status'         => 'deactivated',
				'deactivated_at' => $now,
				'last_seen_at'   => $now,
			),
			array(
				'id'     => $activation_id,
				'status' => 'active',
			),
			array( '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);
		if ( false === $updated ) {
			return 'site_failed';
		}
		if ( 1 === $updated ) {
			AuditLog::record(
				'site_deactivated',
				'activation',
				$activation_id,
				'Deactivated by license owner',
				array(
					'status'   => 'active',
					'site_url' => $row['site_url'],
				),
				array( 'status' => 'deactivated' ),
				get_current_user_id()
			);
		}

		return 'site_deactivated';
	}

	/**
	 * Cancel the automatic renewal of a license of the current customer and
	 * confirm it by email.
	 *
	 * @param int $license_id License ID.
	 * @return string Key of notice_messages().
	 */
	private static function cancel_license( int $license_id ): string {
		if ( ! self::current_user_owns( $license_id ) ) {
			return 'license_not_found';
		}
		if ( ! LicenseLifecycle::schedule_cancellation( $license_id, 'Customer requested cancellation', get_current_user_id() ) ) {
			return 'cancel_failed';
		}
		// LicenseLifecycle fired `wplc/cancellation_scheduled`; the notification package confirms by email.

		return 'cancel_scheduled';
	}

	/**
	 * Withdraw the cancellation of a license of the current customer.
	 *
	 * @param int $license_id License ID.
	 * @return string Key of notice_messages().
	 */
	private static function resume_license( int $license_id ): string {
		if ( ! self::current_user_owns( $license_id ) ) {
			return 'license_not_found';
		}
		return LicenseLifecycle::resume_renewal( $license_id, get_current_user_id() ) ? 'cancel_withdrawn' : 'resume_failed';
	}

	/**
	 * Legacy admin-post handler: deactivate a site.
	 *
	 * @return void
	 */
	public static function handle_deactivate(): void {
		$activation_id = isset( $_POST['activation_id'] ) ? absint( $_POST['activation_id'] ) : 0;
		if ( ! is_user_logged_in() || ! $activation_id ) {
			wp_die( esc_html__( 'You are not allowed to deactivate this site.', 'wp-license' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'wplc_deactivate_site_' . $activation_id );

		self::finish( self::deactivate_site( $activation_id ) );
	}

	/**
	 * Legacy admin-post handler: cancel at period end.
	 *
	 * @return void
	 */
	public static function handle_cancel(): void {
		$license_id = isset( $_POST['license_id'] ) ? absint( $_POST['license_id'] ) : 0;
		if ( ! is_user_logged_in() || ! $license_id ) {
			wp_die( esc_html__( 'You are not allowed to cancel this license.', 'wp-license' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'wplc_cancel_license_' . $license_id );

		self::finish( self::cancel_license( $license_id ) );
	}

	/**
	 * Legacy admin-post handler: withdraw a cancellation.
	 *
	 * @return void
	 */
	public static function handle_resume(): void {
		$license_id = isset( $_POST['license_id'] ) ? absint( $_POST['license_id'] ) : 0;
		if ( ! is_user_logged_in() || ! $license_id ) {
			wp_die( esc_html__( 'You are not allowed to change this license.', 'wp-license' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'wplc_resume_license_' . $license_id );

		self::finish( self::resume_license( $license_id ) );
	}

	/**
	 * Legacy admin-post handler for visitors whose session has ended: back to
	 * the account page (login form) instead of an empty wp-admin response.
	 *
	 * @return void
	 */
	public static function handle_logged_out(): void {
		self::finish( 'login_required' );
	}

	/**
	 * Whether a license belongs to the current user.
	 *
	 * @param int $license_id License ID.
	 * @return bool
	 */
	public static function current_user_owns( int $license_id ): bool {
		global $wpdb;

		$tables = Schema::table_names();
		$owner  = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT customer_id FROM %i WHERE id = %d LIMIT 1', $tables['licenses'], $license_id )
		);

		return $owner > 0 && get_current_user_id() === $owner;
	}

	/**
	 * Open a POST form that is handled by handle_front_post().
	 *
	 * The field names are those of the earlier admin-post forms, so the same
	 * request body works against both targets.
	 *
	 * @param string               $action       Form action, e.g. wplc_cancel_license.
	 * @param array<string,string> $fields       Hidden fields.
	 * @param string               $nonce_action Nonce action.
	 * @param string               $confirm      Confirmation question, empty for none.
	 * @param string               $css_class    CSS class of the form.
	 * @return void
	 */
	public static function open_form( string $action, array $fields, string $nonce_action, string $confirm = '', string $css_class = '' ): void {
		$url = self::form_url();
		echo '<form method="post" action="' . esc_url( $url ) . '"';
		if ( '' !== $css_class ) {
			echo ' class="' . esc_attr( $css_class ) . '"';
		}
		if ( '' !== $confirm ) {
			echo ' data-wplc-confirm="' . esc_attr( $confirm ) . '"';
		}
		echo '>';
		echo '<input type="hidden" name="' . esc_attr( self::FORM_MARKER ) . '" value="1">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		foreach ( $fields as $name => $value ) {
			echo '<input type="hidden" name="' . esc_attr( (string) $name ) . '" value="' . esc_attr( (string) $value ) . '">';
		}
		echo '<input type="hidden" name="wplc_return" value="' . esc_attr( $url ) . '">';
		// No id attribute: several forms share one page.
		echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( $nonce_action ) ) . '">';
	}

	/**
	 * URL the forms of the current rendering post to.
	 *
	 * @return string
	 */
	public static function form_url(): string {
		return '' !== self::$form_url ? self::$form_url : self::account_url();
	}

	/**
	 * Print a POST form to deactivate a site (used by the license template).
	 *
	 * @param int    $activation_id Activation ID.
	 * @param string $site_url      Site URL, for the confirmation question.
	 * @return void
	 */
	public static function render_deactivate_form( int $activation_id, string $site_url = '' ): void {
		/* translators: %s: site URL. */
		$question = sprintf( __( 'Deactivate %s? The site loses updates and premium features until it is activated again.', 'wp-license' ), $site_url );
		self::open_form( 'wplc_deactivate_site', array( 'activation_id' => (string) $activation_id ), 'wplc_deactivate_site_' . $activation_id, $question, 'wplc-inline' );
		echo '<button type="submit" class="button">' . esc_html__( 'Deactivate', 'wp-license' );
		/* translators: %s: site URL. */
		echo '<span class="screen-reader-text"> ' . esc_html( sprintf( __( 'site %s', 'wp-license' ), $site_url ) ) . '</span></button>';
		echo '</form>';
	}

	/**
	 * Print a submit button whose accessible name carries the license it acts on.
	 *
	 * @param string $label     Visible label.
	 * @param string $name      Accessible name of the license.
	 * @param string $css_class CSS classes.
	 * @return void
	 */
	public static function submit_button( string $label, string $name, string $css_class = 'button' ): void {
		// The accessible name starts with the visible label (WCAG 2.5.3).
		echo '<button type="submit" class="' . esc_attr( $css_class ) . '" aria-label="' . esc_attr( $label . ': ' . $name ) . '">' . esc_html( $label ) . '</button>';
	}

	/**
	 * Print a POST form to cancel at period end.
	 *
	 * @param array<string,mixed> $license License row.
	 * @param string              $name    Accessible name of the license.
	 * @return void
	 */
	private static function render_cancel_form( array $license, string $name ): void {
		$license_id = (int) $license['id'];
		$question   = sprintf(
			/* translators: %s: date. */
			__( 'Cancel the automatic renewal? The license stays valid until %s and ends then. You will receive a confirmation by email.', 'wp-license' ),
			Format::date( $license['paid_until'] ?? '', false, __( 'the end of the paid period', 'wp-license' ) )
		);
		self::open_form( 'wplc_cancel_license', array( 'license_id' => (string) $license_id ), 'wplc_cancel_license_' . $license_id, $question );
		self::submit_button( __( 'Cancel at period end', 'wp-license' ), $name );
		echo '</form>';
	}

	/**
	 * Print a POST form to withdraw a cancellation.
	 *
	 * Withdrawing re-activates the payment authorization, so the question names
	 * what will be charged and when.
	 *
	 * @param array<string,mixed> $license License row.
	 * @param string              $name    Accessible name of the license.
	 * @return void
	 */
	private static function render_resume_form( array $license, string $name ): void {
		$license_id = (int) $license['id'];
		$question   = __( 'Undo the cancellation? The license then continues after the paid period.', 'wp-license' );
		if ( ! empty( $license['renewal_enabled'] ) ) {
			$amount    = RenewalTerms::amount_text( RenewalTerms::gross( $license ) );
			$charge_at = RenewalTerms::charge_timestamp( $license );
			$date      = $charge_at > 0 ? Notifications::format_date( $charge_at ) : '';
			if ( '' !== $amount && '' !== $date ) {
				$question = sprintf(
					/* translators: 1: formatted amount, 2: date, 3: interval such as "every year". */
					__( 'Undo the cancellation? The license then renews automatically again: %1$s will be charged to your saved payment method on %2$s and then %3$s, until you cancel.', 'wp-license' ),
					$amount,
					$date,
					RenewalTerms::interval_label( (string) ( $license['billing_interval'] ?? 'year' ) )
				);
			} else {
				$question = __( 'Undo the cancellation? The license then renews automatically again and your saved payment method is charged on the renewal date.', 'wp-license' );
			}
		}
		self::open_form( 'wplc_resume_license', array( 'license_id' => (string) $license_id ), 'wplc_resume_license_' . $license_id, $question );
		self::submit_button( __( 'Undo cancellation', 'wp-license' ), $name );
		echo '</form>';
	}

	/**
	 * Print the "Renew now" form handled by the manual renewal package.
	 *
	 * @param int    $license_id License ID.
	 * @param string $name       Accessible name of the license.
	 * @return void
	 */
	private static function render_renew_form( int $license_id, string $name ): void {
		self::open_form( 'wplc_start_renewal', array( 'license_id' => (string) $license_id ), 'wplc_start_renewal_' . $license_id );
		self::submit_button( __( 'Renew now', 'wp-license' ), $name, 'button alt' );
		echo '</form>';
	}

	/**
	 * Print the download form of the update package.
	 *
	 * CustomerDownload::render_button() posts to the page that is being
	 * rendered (form_url()) and handles the request on the front end itself
	 * (B-17), so the markup is printed as it is.
	 *
	 * @param array<string,mixed> $license License row.
	 * @return void
	 */
	private static function render_download_form( array $license ): void {
		\WPLC\Update\CustomerDownload::render_button( $license );
	}

	/**
	 * Tell the customer on the order-received page where the license is.
	 *
	 * The page can be opened with the order key alone, so it never shows a key.
	 *
	 * @param mixed $order_id Order ID.
	 * @return void
	 */
	public static function render_thankyou_hint( $order_id = 0 ): void {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( absint( $order_id ) ) : false;
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$licensed = (bool) LicenseLifecycle::license_ids_for_order( (int) $order->get_id() );
		$pending  = ! $licensed && \WPLC\Billing\RecurringConsent::order_contains_license( $order );
		if ( ! $licensed && ! $pending ) {
			return;
		}

		$text = $licensed
			? __( 'Your license is ready. You will find the license key, the download and the installation steps in your account under "Licenses".', 'wp-license' )
			: __( 'Your license is created as soon as your payment has been confirmed. You will then receive an email, and the license appears in your account under "Licenses".', 'wp-license' );

		echo '<section class="woocommerce-wplc-license-hint">';
		echo '<h2>' . esc_html__( 'Your license', 'wp-license' ) . '</h2>';
		echo '<p>' . esc_html( $text ) . '</p>';
		echo '<p><a class="button" href="' . esc_url( self::account_url() ) . '">' . esc_html__( 'Go to your licenses', 'wp-license' ) . '</a></p>';
		echo '</section>';
	}
}
