<?php
/**
 * Customer account: all sites of all licenses on one page.
 *
 * @package WPLC
 */

namespace WPLC\Account;

use WPLC\Admin\Format;
use WPLC\Infrastructure\Database\Schema;
use WPLC\Update\ReleaseRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * "My Account → Sites": one table of every site the customer activated, across
 * all licenses and products, with what needs attention.
 *
 * The license page (CustomerPortal) answers "what does this license cover?";
 * this page answers "what runs where, and where must I act?". It replaces the
 * product entitlements `voucher.central_license_overview` and
 * `pass.client_license_management`: an overview of a customer's sites is a
 * function of the customer account, not of the plugin on each site, and it
 * is available to every customer.
 *
 * Reads only. Deactivating a site uses the existing, audited handler of the
 * license page (admin_post_wplc_deactivate_site).
 */
final class SiteOverview {

	/**
	 * My Account endpoint.
	 */
	public const ENDPOINT = 'wplc-sites';

	/**
	 * Sites per page.
	 */
	public const PER_PAGE = 25;

	/**
	 * Upper bound of activation rows read for one customer.
	 */
	public const MAX_ROWS = 2000;

	/**
	 * A site that has not contacted the server for this many days is flagged.
	 */
	public const STALE_DAYS = 14;

	/**
	 * A license that ends within this many days without renewal is flagged.
	 */
	public const EXPIRY_DAYS = 30;

	/**
	 * Seconds of a day; a constant of its own so the pure helpers run without WordPress.
	 */
	private const DAY = 86400;

	/**
	 * Version of the rewrite rules of this endpoint; a change flushes them once.
	 */
	public const RULES_VERSION = '1';

	/**
	 * Option that stores RULES_VERSION after the flush.
	 */
	public const RULES_OPTION = 'wplc_sites_endpoint_rules';

	/**
	 * Warning codes, in display order.
	 */
	public const WARNINGS = array( 'license_inactive', 'payment_due', 'expires_soon', 'update_available', 'not_seen' );

	/**
	 * Filters of the page.
	 */
	public const FILTERS = array( 'all', 'attention', 'updates' );

	/**
	 * Register endpoint, menu entry, page and CSV export.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'init', array( self::class, 'register_endpoint' ) );
		add_action( 'init', array( self::class, 'maybe_flush_rewrite_rules' ), 99 );
		add_filter( 'woocommerce_account_menu_items', array( self::class, 'add_menu_item' ), 20 );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( self::class, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
		add_action( 'admin_post_wplc_sites_csv', array( self::class, 'handle_csv' ) );
	}

	/**
	 * Register the My Account endpoint.
	 *
	 * @return void
	 */
	public static function register_endpoint(): void {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	/**
	 * Flush the rewrite rules once, so the new endpoint works on sites that
	 * updated the plugin without reactivating it.
	 *
	 * @return void
	 */
	public static function maybe_flush_rewrite_rules(): void {
		if ( get_option( self::RULES_OPTION ) === self::RULES_VERSION ) {
			return;
		}
		// Store first so a failing flush cannot repeat on every request.
		update_option( self::RULES_OPTION, self::RULES_VERSION, true );
		flush_rewrite_rules( false );
	}

	/**
	 * Add "Sites" after "Licenses", for customers who have a license.
	 *
	 * @param mixed $items Account menu.
	 * @return array<string,string>
	 */
	public static function add_menu_item( $items ): array {
		$items = is_array( $items ) ? $items : array();
		if ( ! is_user_logged_in() || ! self::customer_has_license( get_current_user_id() ) ) {
			return $items;
		}
		$label  = __( 'Sites', 'wp-license' );
		$result = array();
		foreach ( $items as $key => $item ) {
			$result[ $key ] = $item;
			if ( 'wplc-licenses' === $key ) {
				$result[ self::ENDPOINT ] = $label;
			}
		}
		if ( ! isset( $result[ self::ENDPOINT ] ) ) {
			$logout = $result['customer-logout'] ?? null;
			unset( $result['customer-logout'] );
			$result[ self::ENDPOINT ] = $label;
			if ( null !== $logout ) {
				$result['customer-logout'] = $logout;
			}
		}

		return $result;
	}

	/**
	 * Load the styles and the confirm script of the license page here as well.
	 *
	 * @return void
	 */
	public static function enqueue_assets(): void {
		if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( self::ENDPOINT ) || ! defined( 'WPLC_FILE' ) ) {
			return;
		}
		$version = defined( 'WPLC_VERSION' ) ? WPLC_VERSION : false;
		wp_enqueue_style( 'wplc-account', plugins_url( 'assets/css/wplc-account.css', WPLC_FILE ), array(), $version );
		wp_enqueue_script( 'wplc-ui', plugins_url( 'assets/js/wplc-ui.js', WPLC_FILE ), array(), $version, true );
	}

	// ------------------------------------------------------------------
	// Page.
	// ------------------------------------------------------------------

	/**
	 * Print the page.
	 *
	 * @param mixed $endpoint_value Value after the endpoint in the URL: the page number.
	 * @return void
	 */
	public static function render( $endpoint_value = '' ): void {
		if ( ! is_user_logged_in() ) {
			echo esc_html__( 'Please sign in to view your sites.', 'wp-license' );
			return;
		}

		$data = self::load( get_current_user_id() );
		$now  = time();

		echo '<h2>' . esc_html__( 'Your sites', 'wp-license' ) . '</h2>';
		if ( ! $data['rows'] ) {
			echo '<p>' . esc_html__( 'No site is activated with your licenses yet. Enter the license key in the plugin on your site to activate it.', 'wp-license' ) . '</p>';
			return;
		}

		$sites    = self::group_by_site( $data['rows'] );
		$warnings = array();
		foreach ( $data['rows'] as $row ) {
			$warnings[ (int) $row['activation_id'] ] = self::warnings( $row, $data['latest'][ (string) $row['product_key'] ] ?? null, $now );
		}
		$summary = self::summary( $sites, $warnings );

		echo '<p class="wplc-sites-summary">' . esc_html(
			sprintf(
				/* translators: 1: number of sites, 2: number of live sites, 3: number of sites that need attention, 4: number of sites with an update. */
				__( 'Sites: %1$d (live: %2$d). Need attention: %3$d. Update available: %4$d.', 'wp-license' ),
				$summary['sites'],
				$summary['live'],
				$summary['attention'],
				$summary['updates']
			)
		) . '</p>';
		if ( count( $data['rows'] ) >= self::MAX_ROWS ) {
			echo '<p role="status">' . esc_html(
				/* translators: %d: maximum number of activations shown. */
				sprintf( __( 'Only the first %d activations are shown. The CSV export contains the same selection.', 'wp-license' ), self::MAX_ROWS )
			) . '</p>';
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filter of the customer's own data.
		$filter = isset( $_GET['wplc_filter'] ) ? sanitize_key( wp_unslash( $_GET['wplc_filter'] ) ) : 'all';
		$search = isset( $_GET['wplc_site'] ) ? sanitize_text_field( wp_unslash( $_GET['wplc_site'] ) ) : '';
		// phpcs:enable
		$filter = in_array( $filter, self::FILTERS, true ) ? $filter : 'all';

		self::render_filter_form( $filter, $search );

		$visible = self::filter_sites( $sites, $warnings, $filter, $search );
		if ( ! $visible ) {
			echo '<p>' . esc_html__( 'No site matches the selection.', 'wp-license' ) . '</p>';
			return;
		}

		$pages = max( 1, (int) ceil( count( $visible ) / self::PER_PAGE ) );
		$page  = min( $pages, max( 1, absint( is_scalar( $endpoint_value ) ? $endpoint_value : 0 ) ) );
		$shown = array_slice( $visible, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE, true );

		echo '<table class="shop_table shop_table_responsive wplc-sites"><thead><tr>';
		foreach ( self::columns() as $column ) {
			echo '<th scope="col">' . esc_html( $column ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $shown as $site ) {
			foreach ( $site['rows'] as $row ) {
				self::render_row( $row, $data, $warnings[ (int) $row['activation_id'] ] ?? array() );
			}
		}
		echo '</tbody></table>';

		self::render_pagination( $page, $pages, $filter, $search );
	}

	/**
	 * Column headings of the table and the CSV export.
	 *
	 * @return string[]
	 */
	private static function columns(): array {
		return array(
			__( 'Site', 'wp-license' ),
			__( 'Product', 'wp-license' ),
			__( 'Environment', 'wp-license' ),
			__( 'Version', 'wp-license' ),
			__( 'Last contact', 'wp-license' ),
			__( 'License', 'wp-license' ),
			__( 'Needs attention', 'wp-license' ),
			__( 'Actions', 'wp-license' ),
		);
	}

	/**
	 * Print one activation.
	 *
	 * @param array<string,mixed> $row      Activation row.
	 * @param array<string,mixed> $data     Result of load(): rows, products, latest.
	 * @param string[]            $warnings Warning codes of the row.
	 * @return void
	 */
	private static function render_row( array $row, array $data, array $warnings ): void {
		$columns  = self::columns();
		$product  = self::product_name( $row, $data['products'] );
		$latest   = $data['latest'][ (string) $row['product_key'] ] ?? '';
		$version  = '' !== (string) $row['product_version'] ? (string) $row['product_version'] : '—';
		$site_url = (string) $row['site_url'];

		echo '<tr>';
		echo '<td data-title="' . esc_attr( $columns[0] ) . '">' . esc_html( $site_url ) . '</td>';
		echo '<td data-title="' . esc_attr( $columns[1] ) . '">' . esc_html( $product . ' — ' . (string) $row['plan_key'] ) . '</td>';
		echo '<td data-title="' . esc_attr( $columns[2] ) . '">' . esc_html( Format::environment_label( (string) $row['environment'] ) ) . '</td>';
		echo '<td data-title="' . esc_attr( $columns[3] ) . '">' . esc_html( $version );
		if ( in_array( 'update_available', $warnings, true ) ) {
			/* translators: %s: version number. */
			echo '<br><small>' . esc_html( sprintf( __( 'Update: %s', 'wp-license' ), $latest ) ) . '</small>';
		}
		echo '</td>';
		echo '<td data-title="' . esc_attr( $columns[4] ) . '">' . esc_html( Format::date( $row['last_seen_at'] ?? null, false, __( 'never', 'wp-license' ) ) ) . '</td>';
		echo '<td data-title="' . esc_attr( $columns[5] ) . '">' . esc_html( Format::license_status_label( (string) $row['license_status'] ) );
		/* translators: %s: date. */
		echo '<br><small>' . esc_html( sprintf( __( 'until %s', 'wp-license' ), Format::date( $row['paid_until'] ?? null, false, __( 'No expiry', 'wp-license' ) ) ) ) . '</small></td>';
		echo '<td data-title="' . esc_attr( $columns[6] ) . '">';
		if ( $warnings ) {
			echo '<ul class="wplc-site-warnings">';
			foreach ( $warnings as $code ) {
				echo '<li>' . esc_html( self::warning_label( $code, $row, $latest ) ) . '</li>';
			}
			echo '</ul>';
		} else {
			echo esc_html__( 'Nothing to do', 'wp-license' );
		}
		echo '</td>';
		echo '<td data-title="' . esc_attr( $columns[7] ) . '">';
		self::render_deactivate_form( (int) $row['activation_id'], $site_url );
		echo '</td></tr>';
	}

	/**
	 * Print the search and filter form (GET, on the same endpoint).
	 *
	 * @param string $filter Selected filter.
	 * @param string $search Search text.
	 * @return void
	 */
	private static function render_filter_form( string $filter, string $search ): void {
		$labels = array(
			'all'       => __( 'All sites', 'wp-license' ),
			'attention' => __( 'Needs attention', 'wp-license' ),
			'updates'   => __( 'Update available', 'wp-license' ),
		);
		echo '<form method="get" class="wplc-sites-filter" action="' . esc_url( self::page_url() ) . '">';
		echo '<label for="wplc-site-search">' . esc_html__( 'Site address contains', 'wp-license' ) . '</label> ';
		echo '<input type="search" id="wplc-site-search" name="wplc_site" value="' . esc_attr( $search ) . '"> ';
		echo '<label for="wplc-site-filter">' . esc_html__( 'Show', 'wp-license' ) . '</label> ';
		echo '<select id="wplc-site-filter" name="wplc_filter">';
		foreach ( $labels as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $filter, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select> <button type="submit" class="button">' . esc_html__( 'Apply', 'wp-license' ) . '</button> ';
		$csv = wp_nonce_url( add_query_arg( 'action', 'wplc_sites_csv', admin_url( 'admin-post.php' ) ), 'wplc_sites_csv' );
		echo '<a class="button" href="' . esc_url( $csv ) . '">' . esc_html__( 'Download as CSV', 'wp-license' ) . '</a>';
		echo '</form>';
	}

	/**
	 * Print the deactivation form; it posts to the handler of the license page.
	 *
	 * @param int    $activation_id Activation ID.
	 * @param string $site_url      Site URL, for the confirmation question.
	 * @return void
	 */
	private static function render_deactivate_form( int $activation_id, string $site_url ): void {
		/* translators: %s: site URL. */
		$question = sprintf( __( 'Deactivate %s? The site loses updates and premium features until it is activated again.', 'wp-license' ), $site_url );
		echo '<form method="post" class="wplc-inline" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-wplc-confirm="' . esc_attr( $question ) . '">';
		echo '<input type="hidden" name="action" value="wplc_deactivate_site">';
		echo '<input type="hidden" name="activation_id" value="' . esc_attr( (string) $activation_id ) . '">';
		echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( 'wplc_deactivate_site_' . $activation_id ) ) . '">';
		echo '<button type="submit" class="button">' . esc_html__( 'Deactivate', 'wp-license' );
		/* translators: %s: site URL. */
		echo '<span class="screen-reader-text"> ' . esc_html( sprintf( __( 'site %s', 'wp-license' ), $site_url ) ) . '</span></button>';
		echo '</form>';
	}

	/**
	 * Print previous/next links that keep the selection.
	 *
	 * @param int    $page   Current page.
	 * @param int    $pages  Number of pages.
	 * @param string $filter Selected filter.
	 * @param string $search Search text.
	 * @return void
	 */
	private static function render_pagination( int $page, int $pages, string $filter, string $search ): void {
		if ( $pages < 2 ) {
			return;
		}
		$args = array_filter(
			array(
				'wplc_filter' => 'all' === $filter ? '' : $filter,
				'wplc_site'   => $search,
			)
		);
		echo '<nav class="woocommerce-pagination" aria-label="' . esc_attr__( 'Sites pages', 'wp-license' ) . '">';
		if ( $page > 1 ) {
			echo '<a class="button" href="' . esc_url( add_query_arg( $args, self::page_url( $page - 1 ) ) ) . '">' . esc_html__( 'Previous', 'wp-license' ) . '</a> ';
		}
		/* translators: 1: current page, 2: number of pages. */
		echo '<span>' . esc_html( sprintf( __( 'Page %1$d of %2$d', 'wp-license' ), $page, $pages ) ) . '</span>';
		if ( $page < $pages ) {
			echo ' <a class="button" href="' . esc_url( add_query_arg( $args, self::page_url( $page + 1 ) ) ) . '">' . esc_html__( 'Next', 'wp-license' ) . '</a>';
		}
		echo '</nav>';
	}

	/**
	 * URL of this endpoint.
	 *
	 * @param int $page Page number.
	 * @return string
	 */
	private static function page_url( int $page = 1 ): string {
		$base = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/' );
		if ( ! function_exists( 'wc_get_endpoint_url' ) ) {
			return $base;
		}

		return wc_get_endpoint_url( self::ENDPOINT, $page > 1 ? (string) $page : '', $base );
	}

	// ------------------------------------------------------------------
	// CSV export.
	// ------------------------------------------------------------------

	/**
	 * Send the customer's sites as CSV (admin-post.php?action=wplc_sites_csv).
	 *
	 * @return void
	 */
	public static function handle_csv(): void {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Please sign in to view your sites.', 'wp-license' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'wplc_sites_csv' );

		$csv = self::csv_for_customer( get_current_user_id(), time() );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="sites-' . gmdate( 'Y-m-d' ) . '.csv"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV document; every cell is neutralised in csv_cell().
		exit;
	}

	/**
	 * The CSV document of one customer.
	 *
	 * @param int $customer_id WordPress user ID.
	 * @param int $now         Current Unix time.
	 * @return string
	 */
	public static function csv_for_customer( int $customer_id, int $now ): string {
		$data    = self::load( $customer_id );
		$columns = self::columns();
		array_pop( $columns ); // No "Actions" in the file.
		$columns[] = __( 'Latest version', 'wp-license' );
		$lines     = array( $columns );
		foreach ( $data['rows'] as $row ) {
			$latest   = $data['latest'][ (string) $row['product_key'] ] ?? '';
			$warnings = self::warnings( $row, '' !== $latest ? $latest : null, $now );
			$labels   = array();
			foreach ( $warnings as $code ) {
				$labels[] = self::warning_label( $code, $row, $latest );
			}
			$lines[] = array(
				(string) $row['site_url'],
				self::product_name( $row, $data['products'] ) . ' — ' . (string) $row['plan_key'],
				Format::environment_label( (string) $row['environment'] ),
				(string) $row['product_version'],
				(string) ( $row['last_seen_at'] ?? '' ),
				Format::license_status_label( (string) $row['license_status'] ) . ' (' . (string) ( $row['paid_until'] ?? '' ) . ')',
				implode( '; ', $labels ),
				$latest,
			);
		}

		return self::csv_document( $lines );
	}

	/**
	 * Build a CSV document (UTF-8 with BOM, comma separated).
	 *
	 * @param array<int,array<int,string>> $lines Lines of cells.
	 * @return string
	 */
	public static function csv_document( array $lines ): string {
		$out = "\xEF\xBB\xBF";
		foreach ( $lines as $line ) {
			$cells = array();
			foreach ( $line as $cell ) {
				$cell    = self::csv_cell( (string) $cell );
				$cells[] = '"' . str_replace( '"', '""', $cell ) . '"';
			}
			$out .= implode( ',', $cells ) . "\r\n";
		}

		return $out;
	}

	/**
	 * Neutralise a cell that a spreadsheet would run as a formula.
	 *
	 * @param string $cell Cell value.
	 * @return string
	 */
	public static function csv_cell( string $cell ): string {
		$cell = str_replace( array( "\r", "\n" ), ' ', $cell );
		if ( '' !== $cell && in_array( $cell[0], array( '=', '+', '-', '@', "\t" ), true ) ) {
			return "'" . $cell;
		}

		return $cell;
	}

	// ------------------------------------------------------------------
	// Data.
	// ------------------------------------------------------------------

	/**
	 * Whether a customer owns at least one license.
	 *
	 * @param int $customer_id WordPress user ID.
	 * @return bool
	 */
	public static function customer_has_license( int $customer_id ): bool {
		static $cache = array();
		if ( $customer_id <= 0 ) {
			return false;
		}
		if ( ! isset( $cache[ $customer_id ] ) ) {
			global $wpdb;
			$tables                 = Schema::table_names();
			$cache[ $customer_id ] = (bool) $wpdb->get_var(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from Schema::table_names().
				$wpdb->prepare( "SELECT 1 FROM {$tables['licenses']} WHERE customer_id = %d LIMIT 1", $customer_id )
			);
		}

		return $cache[ $customer_id ];
	}

	/**
	 * The active activations of a customer's licenses, with product names and latest versions.
	 *
	 * @param int $customer_id WordPress user ID.
	 * @return array{rows:array<int,array<string,mixed>>,products:array<string,string>,latest:array<string,string>}
	 */
	public static function load( int $customer_id ): array {
		$data = array(
			'rows'     => array(),
			'products' => array(),
			'latest'   => array(),
		);
		if ( $customer_id <= 0 ) {
			return $data;
		}

		global $wpdb;
		$tables = Schema::table_names();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names from Schema::table_names(); values are placeholders.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.id AS activation_id, a.license_id, a.site_url, a.environment, a.product_version, a.activated_at, a.last_seen_at,
					l.product_key, l.plan_key, l.status AS license_status, l.paid_until, l.renewal_enabled, l.cancel_at_period_end
				FROM {$tables['activations']} a
				INNER JOIN {$tables['licenses']} l ON l.id = a.license_id
				WHERE l.customer_id = %d AND a.status = %s
				ORDER BY a.site_url ASC, l.product_key ASC, a.id ASC
				LIMIT %d",
				$customer_id,
				'active',
				self::MAX_ROWS
			),
			ARRAY_A
		);
		// phpcs:enable
		$data['rows'] = is_array( $rows ) ? $rows : array();
		if ( ! $data['rows'] ) {
			return $data;
		}

		$keys     = array_values( array_unique( array_map( 'strval', array_column( $data['rows'], 'product_key' ) ) ) );
		$marks    = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$products = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders are generated above.
			$wpdb->prepare( "SELECT product_key, display_name FROM {$tables['products']} WHERE product_key IN ({$marks})", $keys ),
			ARRAY_A
		);
		foreach ( is_array( $products ) ? $products : array() as $row ) {
			$data['products'][ (string) $row['product_key'] ] = (string) $row['display_name'];
		}
		foreach ( $keys as $key ) {
			$release = ReleaseRegistry::latest_published( $key );
			if ( is_array( $release ) && '' !== (string) ( $release['version'] ?? '' ) ) {
				$data['latest'][ $key ] = (string) $release['version'];
			}
		}

		return $data;
	}

	// ------------------------------------------------------------------
	// Pure helpers (unit tested).
	// ------------------------------------------------------------------

	/**
	 * Group activation rows by site address, keeping the order of the rows.
	 *
	 * @param mixed $rows Activation rows.
	 * @return array<string,array{site_url:string,rows:array<int,array<string,mixed>>}>
	 */
	public static function group_by_site( $rows ): array {
		$sites = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['site_url'] ) || ! is_scalar( $row['site_url'] ) ) {
				continue;
			}
			$url = (string) $row['site_url'];
			if ( ! isset( $sites[ $url ] ) ) {
				$sites[ $url ] = array(
					'site_url' => $url,
					'rows'     => array(),
				);
			}
			$sites[ $url ]['rows'][] = $row;
		}

		return $sites;
	}

	/**
	 * What needs attention for one activation.
	 *
	 * @param array<string,mixed> $row    Activation row joined with its license.
	 * @param string|null         $latest Latest published version of the product, if any.
	 * @param int                 $now    Current Unix time.
	 * @return string[] Codes from WARNINGS, in that order.
	 */
	public static function warnings( array $row, ?string $latest, int $now ): array {
		$found  = array();
		$status = (string) ( $row['license_status'] ?? '' );

		if ( ! in_array( $status, array( 'active', 'past_due' ), true ) ) {
			$found[] = 'license_inactive';
		}
		if ( 'past_due' === $status ) {
			$found[] = 'payment_due';
		}
		$paid_until = Format::utc_timestamp( $row['paid_until'] ?? null );
		$renews     = ! empty( $row['renewal_enabled'] ) && empty( $row['cancel_at_period_end'] );
		if ( 'active' === $status && ! $renews && null !== $paid_until && $paid_until > $now && $paid_until - $now <= self::EXPIRY_DAYS * self::DAY ) {
			$found[] = 'expires_soon';
		}
		$installed = trim( (string) ( $row['product_version'] ?? '' ) );
		if ( null !== $latest && '' !== $latest && '' !== $installed && version_compare( $installed, $latest, '<' ) ) {
			$found[] = 'update_available';
		}
		$seen = Format::utc_timestamp( $row['last_seen_at'] ?? null );
		$seen = null !== $seen ? $seen : Format::utc_timestamp( $row['activated_at'] ?? null );
		if ( null !== $seen && $now - $seen > self::STALE_DAYS * self::DAY ) {
			$found[] = 'not_seen';
		}

		return $found;
	}

	/**
	 * Keep the sites that match the filter and the search text.
	 *
	 * @param array<string,array{site_url:string,rows:array<int,array<string,mixed>>}> $sites    Result of group_by_site().
	 * @param array<int,string[]>                                                      $warnings Warning codes by activation ID.
	 * @param string                                                                   $filter   One of FILTERS.
	 * @param string                                                                   $search   Part of the site address.
	 * @return array<string,array{site_url:string,rows:array<int,array<string,mixed>>}>
	 */
	public static function filter_sites( array $sites, array $warnings, string $filter, string $search ): array {
		$search = strtolower( trim( $search ) );
		$result = array();
		foreach ( $sites as $url => $site ) {
			if ( '' !== $search && false === strpos( strtolower( $url ), $search ) ) {
				continue;
			}
			$codes = array();
			foreach ( $site['rows'] as $row ) {
				$codes = array_merge( $codes, $warnings[ (int) ( $row['activation_id'] ?? 0 ) ] ?? array() );
			}
			if ( 'attention' === $filter && ! $codes ) {
				continue;
			}
			if ( 'updates' === $filter && ! in_array( 'update_available', $codes, true ) ) {
				continue;
			}
			$result[ $url ] = $site;
		}

		return $result;
	}

	/**
	 * Counts for the line above the table.
	 *
	 * @param array<string,array{site_url:string,rows:array<int,array<string,mixed>>}> $sites    Result of group_by_site().
	 * @param array<int,string[]>                                                      $warnings Warning codes by activation ID.
	 * @return array{sites:int,live:int,attention:int,updates:int}
	 */
	public static function summary( array $sites, array $warnings ): array {
		$summary = array(
			'sites'     => count( $sites ),
			'live'      => 0,
			'attention' => 0,
			'updates'   => 0,
		);
		foreach ( $sites as $site ) {
			$live  = false;
			$codes = array();
			foreach ( $site['rows'] as $row ) {
				$live  = $live || 'production' === ( $row['environment'] ?? '' );
				$codes = array_merge( $codes, $warnings[ (int) ( $row['activation_id'] ?? 0 ) ] ?? array() );
			}
			$summary['live']      += $live ? 1 : 0;
			$summary['attention'] += $codes ? 1 : 0;
			$summary['updates']   += in_array( 'update_available', $codes, true ) ? 1 : 0;
		}

		return $summary;
	}

	/**
	 * Text of a warning.
	 *
	 * @param string              $code   Warning code.
	 * @param array<string,mixed> $row    Activation row.
	 * @param string              $latest Latest version of the product.
	 * @return string
	 */
	public static function warning_label( string $code, array $row, string $latest = '' ): string {
		switch ( $code ) {
			case 'license_inactive':
				/* translators: %s: license status, e.g. "Expired". */
				return sprintf( __( 'License is not active (%s): no updates, premium features off.', 'wp-license' ), Format::license_status_label( (string) ( $row['license_status'] ?? '' ) ) );
			case 'payment_due':
				return __( 'Renewal payment is overdue.', 'wp-license' );
			case 'expires_soon':
				/* translators: %s: date. */
				return sprintf( __( 'License ends on %s and does not renew.', 'wp-license' ), Format::date( $row['paid_until'] ?? null ) );
			case 'update_available':
				/* translators: %s: version number. */
				return sprintf( __( 'Version %s is available.', 'wp-license' ), $latest );
			case 'not_seen':
				/* translators: %d: number of days. */
				return sprintf( __( 'No contact for more than %d days.', 'wp-license' ), self::STALE_DAYS );
		}

		return $code;
	}

	/**
	 * Display name of the product of a row.
	 *
	 * @param array<string,mixed>  $row      Activation row.
	 * @param array<string,string> $products Display names by product key.
	 * @return string
	 */
	private static function product_name( array $row, array $products ): string {
		$key = (string) ( $row['product_key'] ?? '' );

		return isset( $products[ $key ] ) && '' !== $products[ $key ] ? $products[ $key ] : $key;
	}
}
