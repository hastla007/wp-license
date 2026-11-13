<?php
/**
 * Administrator license list.
 *
 * @package WPLC
 */

namespace WPLC\Admin;

use WPLC\Infrastructure\Database\Schema;
use WPLC\License\LicenseKey;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sortable, filterable and searchable license table.
 *
 * The WordPress base class must be loaded before this file is autoloaded;
 * LicenseAdmin::render() takes care of that.
 */
final class LicenseListTable extends \WP_List_Table {

	/**
	 * Licenses per page.
	 */
	public const PER_PAGE = 50;

	/**
	 * Product key => display name.
	 *
	 * @var array<string,string>
	 */
	private $products = array();

	/**
	 * License ID => active live sites.
	 *
	 * @var array<int,int>
	 */
	private $site_counts = array();

	/**
	 * License ID => live site limit.
	 *
	 * @var array<int,int>
	 */
	private $site_limits = array();

	/**
	 * Configure the table.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'wplc-license',
				'plural'   => 'wplc-licenses',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Column headings.
	 *
	 * @return array<string,string>
	 */
	public function get_columns() {
		return array(
			'license'    => __( 'License', 'wp-license' ),
			'customer'   => __( 'Customer', 'wp-license' ),
			'product'    => __( 'Product / Plan', 'wp-license' ),
			'status'     => __( 'Status', 'wp-license' ),
			'paid_until' => __( 'Paid until', 'wp-license' ),
			'sites'      => __( 'Live sites', 'wp-license' ),
			'renewal'    => __( 'Renewal', 'wp-license' ),
			'created'    => __( 'Created', 'wp-license' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array<string,array{0:string,1:bool}>
	 */
	protected function get_sortable_columns() {
		return array(
			'status'     => array( 'status', false ),
			'paid_until' => array( 'paid_until', false ),
			'created'    => array( 'created', true ),
		);
	}

	/**
	 * Current filter values from the request.
	 *
	 * @return array{search:string,status:string,product:string,orderby:string,order:string}
	 */
	public static function request_filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$status  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$product = isset( $_GET['product'] ) ? sanitize_key( wp_unslash( $_GET['product'] ) ) : '';
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '';
		$order   = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : '';
		// phpcs:enable

		if ( ! isset( Format::license_status_labels()[ $status ] ) ) {
			$status = '';
		}
		if ( ! in_array( $orderby, array( 'created', 'paid_until', 'status' ), true ) ) {
			$orderby = 'created';
			$order   = '' === $order ? 'desc' : $order;
		}

		return array(
			'search'  => $search,
			'status'  => $status,
			'product' => $product,
			'orderby' => $orderby,
			'order'   => 'asc' === $order ? 'asc' : 'desc',
		);
	}

	/**
	 * Query one page of licenses and everything its rows need, without per-row queries.
	 *
	 * @return void
	 */
	public function prepare_items() {
		global $wpdb;

		$tables  = Schema::table_names();
		$filters = self::request_filters();

		$this->products = array();
		$product_rows   = $wpdb->get_results( "SELECT product_key, display_name FROM {$tables['products']} ORDER BY display_name ASC LIMIT 500", ARRAY_A );
		foreach ( is_array( $product_rows ) ? $product_rows : array() as $row ) {
			$this->products[ (string) $row['product_key'] ] = (string) $row['display_name'];
		}

		$conditions = array();
		$params     = array();
		if ( '' !== $filters['status'] ) {
			$conditions[] = 'status = %s';
			$params[]     = $filters['status'];
		}
		if ( '' !== $filters['product'] ) {
			$conditions[] = 'product_key = %s';
			$params[]     = $filters['product'];
		}
		$search = self::search_condition( $filters['search'] );
		if ( null !== $search ) {
			$conditions[] = $search['sql'];
			$params       = array_merge( $params, $search['params'] );
		}
		$where = $conditions ? ' WHERE ' . implode( ' AND ', $conditions ) : '';

		$count_sql = "SELECT COUNT(*) FROM {$tables['licenses']}{$where}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Only table names and fixed fragments are interpolated.
		$total = $params ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : (int) $wpdb->get_var( $count_sql );

		$order_columns = array(
			'created'    => 'created_at',
			'paid_until' => 'paid_until',
			'status'     => 'status',
		);
		$order_by      = $order_columns[ $filters['orderby'] ];
		$direction     = 'asc' === $filters['order'] ? 'ASC' : 'DESC';
		$page          = $this->get_pagenum();
		$params[]      = self::PER_PAGE;
		$params[]      = ( $page - 1 ) * self::PER_PAGE;
		$list_sql      = "SELECT * FROM {$tables['licenses']}{$where} ORDER BY {$order_by} {$direction}, id {$direction} LIMIT %d OFFSET %d";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Column and direction come from fixed allowlists.
		$items       = $wpdb->get_results( $wpdb->prepare( $list_sql, $params ), ARRAY_A );
		$this->items = is_array( $items ) ? $items : array();

		$this->prime_row_data();

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'license' );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
				'total_pages' => (int) ceil( $total / self::PER_PAGE ),
			)
		);
	}

	/**
	 * Translate a search term into a WHERE fragment.
	 *
	 * @param string $term Raw search term.
	 * @return array{sql:string,params:array<int,mixed>}|null Null when there is nothing to search for.
	 */
	private static function search_condition( string $term ): ?array {
		global $wpdb;

		$search = LicenseSearch::classify( $term );
		switch ( $search['type'] ) {
			case LicenseSearch::TYPE_NONE:
				return null;

			case LicenseSearch::TYPE_LICENSE_KEY:
				// Keys are never stored; a pasted key is compared by its hash.
				return array(
					'sql'    => 'license_key_hash = %s',
					'params' => array( LicenseKey::hash( $search['value'] ) ),
				);

			case LicenseSearch::TYPE_LICENSE_ID:
				return array(
					'sql'    => 'id = %d',
					'params' => array( (int) $search['value'] ),
				);

			case LicenseSearch::TYPE_NUMBER:
				return array(
					'sql'    => '(id = %d OR customer_id = %d OR key_prefix LIKE %s)',
					'params' => array( (int) $search['value'], (int) $search['value'], '%' . $wpdb->esc_like( $search['value'] ) . '%' ),
				);

			case LicenseSearch::TYPE_EMAIL:
				$user_ids = self::user_ids_by_email( $search['value'] );
				if ( ! $user_ids ) {
					return array(
						'sql'    => '1 = 0',
						'params' => array(),
					);
				}
				return array(
					'sql'    => 'customer_id IN (' . implode( ',', array_fill( 0, count( $user_ids ), '%d' ) ) . ')',
					'params' => $user_ids,
				);
		}

		$like     = '%' . $wpdb->esc_like( $search['value'] ) . '%';
		$sql      = 'key_prefix LIKE %s OR product_key LIKE %s OR plan_key LIKE %s';
		$params   = array( $like, $like, $like );
		$user_ids = self::user_ids_by_email( $search['value'] );
		if ( $user_ids ) {
			$sql   .= ' OR customer_id IN (' . implode( ',', array_fill( 0, count( $user_ids ), '%d' ) ) . ')';
			$params = array_merge( $params, $user_ids );
		}

		return array(
			'sql'    => '(' . $sql . ')',
			'params' => $params,
		);
	}

	/**
	 * Find users whose email address contains a fragment.
	 *
	 * @param string $fragment Email address or part of it.
	 * @return int[]
	 */
	private static function user_ids_by_email( string $fragment ): array {
		$fragment = trim( str_replace( '*', '', $fragment ) );
		if ( strlen( $fragment ) < 3 ) {
			return array();
		}
		$ids = get_users(
			array(
				'search'         => '*' . $fragment . '*',
				'search_columns' => array( 'user_email' ),
				'fields'         => 'ID',
				'number'         => 200,
				'orderby'        => 'ID',
			)
		);

		return array_values( array_filter( array_map( 'intval', is_array( $ids ) ? $ids : array() ) ) );
	}

	/**
	 * Load site counts, site limits and users for the current page in grouped queries.
	 *
	 * @return void
	 */
	private function prime_row_data(): void {
		global $wpdb;

		$this->site_counts = array();
		$this->site_limits = array();
		if ( ! $this->items ) {
			return;
		}

		$tables       = Schema::table_names();
		$license_ids  = array_map( 'intval', array_column( $this->items, 'id' ) );
		$placeholders = implode( ',', array_fill( 0, count( $license_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders are generated above.
		$counts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT license_id, COUNT(*) AS used FROM {$tables['activations']} WHERE environment = %s AND status = %s AND license_id IN ({$placeholders}) GROUP BY license_id",
				array_merge( array( 'production', 'active' ), $license_ids )
			),
			ARRAY_A
		);
		$limits = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT license_id, value_json FROM {$tables['entitlements']} WHERE entitlement_key = %s AND license_id IN ({$placeholders})",
				array_merge( array( 'sites.live_limit' ), $license_ids )
			),
			ARRAY_A
		);
		// phpcs:enable
		foreach ( is_array( $counts ) ? $counts : array() as $row ) {
			$this->site_counts[ (int) $row['license_id'] ] = (int) $row['used'];
		}
		foreach ( is_array( $limits ) ? $limits : array() as $row ) {
			$limit = json_decode( (string) $row['value_json'], true );
			if ( is_numeric( $limit ) ) {
				$this->site_limits[ (int) $row['license_id'] ] = (int) $limit;
			}
		}

		$customer_ids = array_values( array_unique( array_filter( array_map( 'intval', array_column( $this->items, 'customer_id' ) ) ) ) );
		if ( $customer_ids && function_exists( 'cache_users' ) ) {
			cache_users( $customer_ids );
		}
	}

	/**
	 * License prefix and ID, linked to the detail screen.
	 *
	 * @param array<string,mixed> $item License row.
	 * @return string
	 */
	protected function column_license( $item ) {
		$url = LicenseAdmin::detail_url( (int) $item['id'] );
		$out = '<strong><a class="row-title" href="' . esc_url( $url ) . '"><code>' . esc_html( (string) $item['key_prefix'] ) . '</code></a></strong> ';
		$out .= '<span class="description">#' . esc_html( (string) (int) $item['id'] ) . '</span>';
		$out .= $this->row_actions(
			array(
				'view' => '<a href="' . esc_url( $url ) . '">' . esc_html__( 'View and manage', 'wp-license' ) . '</a>',
			)
		);
		return $out;
	}

	/**
	 * Customer email linked to the user profile.
	 *
	 * @param array<string,mixed> $item License row.
	 * @return string
	 */
	protected function column_customer( $item ) {
		return LicenseAdmin::customer_html( (int) $item['customer_id'] );
	}

	/**
	 * Product display name with the technical keys.
	 *
	 * @param array<string,mixed> $item License row.
	 * @return string
	 */
	protected function column_product( $item ) {
		$product_key = (string) $item['product_key'];
		$name        = isset( $this->products[ $product_key ] ) && '' !== $this->products[ $product_key ] ? $this->products[ $product_key ] : $product_key;

		return esc_html( $name ) . '<br><code>' . esc_html( $product_key . ' / ' . (string) $item['plan_key'] ) . '</code>';
	}

	/**
	 * Translated status.
	 *
	 * @param array<string,mixed> $item License row.
	 * @return string
	 */
	protected function column_status( $item ) {
		return esc_html( Format::license_status_label( (string) $item['status'] ) );
	}

	/**
	 * Paid-until date in the site timezone.
	 *
	 * @param array<string,mixed> $item License row.
	 * @return string
	 */
	protected function column_paid_until( $item ) {
		return esc_html( Format::date( $item['paid_until'] ) );
	}

	/**
	 * Live sites used and allowed.
	 *
	 * @param array<string,mixed> $item License row.
	 * @return string
	 */
	protected function column_sites( $item ) {
		$license_id = (int) $item['id'];
		$used       = isset( $this->site_counts[ $license_id ] ) ? $this->site_counts[ $license_id ] : 0;
		$limit      = isset( $this->site_limits[ $license_id ] ) ? (string) $this->site_limits[ $license_id ] : '—';

		/* translators: 1: number of active live sites, 2: allowed number of live sites. */
		return esc_html( sprintf( __( '%1$d / %2$s', 'wp-license' ), $used, $limit ) );
	}

	/**
	 * Renewal mode.
	 *
	 * @param array<string,mixed> $item License row.
	 * @return string
	 */
	protected function column_renewal( $item ) {
		return esc_html( Format::renewal_mode_label( $item ) );
	}

	/**
	 * Creation date in the site timezone.
	 *
	 * @param array<string,mixed> $item License row.
	 * @return string
	 */
	protected function column_created( $item ) {
		return esc_html( Format::date( $item['created_at'] ) );
	}

	/**
	 * Fallback for unknown columns.
	 *
	 * @param array<string,mixed> $item License row.
	 * @param string              $column_name Column.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		return '';
	}

	/**
	 * Message for an empty result.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No licenses found.', 'wp-license' );
	}

	/**
	 * Status and product filters.
	 *
	 * @param string $which top or bottom.
	 * @return void
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		$filters = self::request_filters();

		echo '<div class="alignleft actions">';
		echo '<label class="screen-reader-text" for="wplc-filter-status">' . esc_html__( 'Filter by status', 'wp-license' ) . '</label>';
		echo '<select id="wplc-filter-status" name="status"><option value="">' . esc_html__( 'All statuses', 'wp-license' ) . '</option>';
		foreach ( Format::license_status_labels() as $status => $label ) {
			echo '<option value="' . esc_attr( $status ) . '"' . selected( $filters['status'], $status, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';

		echo '<label class="screen-reader-text" for="wplc-filter-product">' . esc_html__( 'Filter by product', 'wp-license' ) . '</label>';
		echo '<select id="wplc-filter-product" name="product"><option value="">' . esc_html__( 'All products', 'wp-license' ) . '</option>';
		foreach ( $this->products as $product_key => $name ) {
			echo '<option value="' . esc_attr( $product_key ) . '"' . selected( $filters['product'], $product_key, false ) . '>' . esc_html( '' !== $name ? $name : $product_key ) . '</option>';
		}
		echo '</select>';

		submit_button( __( 'Filter', 'wp-license' ), '', 'filter_action', false );
		echo '</div>';
	}

	/**
	 * Table navigation without the bulk-action nonce, so filter URLs stay short and shareable.
	 *
	 * @param string $which top or bottom.
	 * @return void
	 */
	protected function display_tablenav( $which ) {
		echo '<div class="tablenav ' . esc_attr( $which ) . '">';
		$this->extra_tablenav( $which );
		$this->pagination( $which );
		echo '<br class="clear"></div>';
	}
}
