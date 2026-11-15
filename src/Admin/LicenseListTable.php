<?php
/**
 * Administrator license list.
 *
 * @package WPLC
 */

namespace WPLC\Admin;

use WPLC\Infrastructure\Database\Schema;

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
	 * GET arguments of the list filters (kept across search, sort and bulk actions).
	 */
	public const FILTER_ARGS = array( 'status', 'product', 'order_id', 'renewal', 'mode', 'expiry', 'report' );

	/**
	 * Renewal statuses that count as an open renewal (not paid, closed or refunded).
	 */
	private const OPEN_RENEWAL_STATUSES = array( 'scheduled', 'processing', 'failed', 'manual_action_required', 'reconciling', 'review_required' );

	/**
	 * Days of the "expires soon" filter.
	 */
	public const EXPIRY_WINDOW_DAYS = 30;

	/**
	 * Search: most license IDs a key-prefix fragment is resolved to before
	 * the list falls back to filtering with LIKE.
	 */
	private const MAX_RESOLVED_IDS = 1000;

	/**
	 * Search: most product/plan pairs a term is resolved to before the list
	 * falls back to filtering with LIKE.
	 */
	private const MAX_RESOLVED_PAIRS = 200;

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
		$columns = array(
			'cb'         => '<input type="checkbox" form="wplc-bulk-form">',
			'license'    => __( 'License', 'wp-license' ),
			'customer'   => __( 'Customer', 'wp-license' ),
			'product'    => __( 'Product / Plan', 'wp-license' ),
			'status'     => __( 'Status', 'wp-license' ),
			'paid_until' => __( 'Paid until', 'wp-license' ),
			'sites'      => __( 'Live sites', 'wp-license' ),
			'renewal'    => __( 'Renewal', 'wp-license' ),
			'created'    => __( 'Created', 'wp-license' ),
		);
		if ( ! Capabilities::can( 'manage_licenses' ) ) {
			// Read-only support: no bulk form to bind the checkboxes to (P-07).
			unset( $columns['cb'] );
		}

		return $columns;
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
	 * The search term itself is never read from the URL (T-141): the URL only
	 * carries an opaque token that LicenseSearchStore resolves for the current
	 * user. A legacy "s" query argument is ignored.
	 *
	 * @return array{search:array{type:string,value:string,label:string}|null,search_token:string,search_expired:bool,status:string,product:string,order_id:int,renewal:string,mode:string,expiry:string,report:string,orderby:string,order:string}
	 */
	public static function request_filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
		$token   = isset( $_GET[ LicenseSearchStore::QUERY_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ LicenseSearchStore::QUERY_ARG ] ) ) : '';
		$status  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$product = isset( $_GET['product'] ) ? sanitize_key( wp_unslash( $_GET['product'] ) ) : '';
		$report  = isset( $_GET['report'] ) ? sanitize_key( wp_unslash( $_GET['report'] ) ) : '';
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '';
		$order   = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : '';
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$renewal  = isset( $_GET['renewal'] ) ? sanitize_key( wp_unslash( $_GET['renewal'] ) ) : '';
		$mode     = isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : '';
		$expiry   = isset( $_GET['expiry'] ) ? sanitize_key( wp_unslash( $_GET['expiry'] ) ) : '';
		// phpcs:enable

		$search = '' !== $token ? LicenseSearchStore::load( get_current_user_id(), $token ) : null;

		if ( ! isset( Format::license_status_labels()[ $status ] ) ) {
			$status = '';
		}
		if ( ! isset( self::renewal_filter_labels()[ $renewal ] ) ) {
			$renewal = '';
		}
		if ( ! isset( self::mode_filter_labels()[ $mode ] ) ) {
			$mode = '';
		}
		if ( ! isset( self::expiry_filter_labels()[ $expiry ] ) ) {
			$expiry = '';
		}
		if ( ! isset( self::reports()[ $report ] ) ) {
			$report = '';
		}
		if ( ! in_array( $orderby, array( 'created', 'paid_until', 'status' ), true ) ) {
			$orderby = 'created';
			$order   = '' === $order ? 'desc' : $order;
		}

		return array(
			'search'         => $search,
			'search_token'   => null !== $search ? $token : '',
			'search_expired' => '' !== $token && null === $search,
			'status'         => $status,
			'product'        => $product,
			'order_id'       => $order_id,
			'renewal'        => $renewal,
			'mode'           => $mode,
			'expiry'         => $expiry,
			'report'         => $report,
			'orderby'        => $orderby,
			'order'          => 'asc' === $order ? 'asc' : 'desc',
		);
	}

	/**
	 * Reports offered above the list (A-61).
	 *
	 * @return array<string,string> Report key => label.
	 */
	public static function reports(): array {
		return array(
			'upcoming_renewals' => __( 'Automatic renewals in the next 30 days', 'wp-license' ),
			'ending_soon'       => __( 'Ending in the next 30 days without automatic renewal', 'wp-license' ),
			'payment_failures'  => __( 'Payment overdue', 'wp-license' ),
			'cancellations'     => __( 'Cancelled to the end of the term', 'wp-license' ),
		);
	}

	/**
	 * Translate list filters into the WHERE conditions of the license query.
	 *
	 * @param array<string,mixed> $filters Filters from request_filters().
	 * @return array{where:string,params:array<int,mixed>} Conditions without the WHERE keyword ('' for none).
	 */
	public static function query_parts( array $filters ): array {
		$conditions = array();
		$params     = array();
		if ( ! empty( $filters['status'] ) ) {
			$conditions[] = 'status = %s';
			$params[]     = $filters['status'];
		}
		if ( ! empty( $filters['product'] ) ) {
			$conditions[] = 'product_key = %s';
			$params[]     = $filters['product'];
		}

		$now  = gmdate( 'Y-m-d H:i:s' );
		$soon = gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS );
		switch ( isset( $filters['report'] ) ? $filters['report'] : '' ) {
			case 'upcoming_renewals':
				$conditions[] = "status = 'active' AND renewal_enabled = 1 AND cancel_at_period_end = 0 AND next_payment_at IS NOT NULL AND next_payment_at <= %s";
				$params[]     = $soon;
				break;
			case 'ending_soon':
				$conditions[] = "status = 'active' AND ( renewal_enabled = 0 OR cancel_at_period_end = 1 ) AND paid_until > %s AND paid_until <= %s";
				$params[]     = $now;
				$params[]     = $soon;
				break;
			case 'payment_failures':
				$conditions[] = "status = 'past_due'";
				break;
			case 'cancellations':
				$conditions[] = "cancel_at_period_end = 1 AND status IN ('active','past_due')";
				break;
		}

		foreach ( self::filter_conditions( $filters ) as $condition ) {
			$conditions[] = $condition['sql'];
			$params       = array_merge( $params, $condition['params'] );
		}

		// Criteria from LicenseSearchStore, or a raw term from callers outside the list screen.
		$criteria = isset( $filters['search'] ) ? $filters['search'] : null;
		if ( is_string( $criteria ) ) {
			$criteria = LicenseSearch::criteria( $criteria );
		}
		$search = self::search_condition( is_array( $criteria ) ? $criteria : null );
		if ( null !== $search ) {
			$conditions[] = $search['sql'];
			$params       = array_merge( $params, $search['params'] );
		}

		return array(
			'where'  => $conditions ? '(' . implode( ') AND (', $conditions ) . ')' : '',
			'params' => $params,
		);
	}

	/**
	 * Report links above the table.
	 *
	 * @return array<string,string>
	 */
	protected function get_views() {
		$current = self::request_filters()['report'];
		$base    = LicenseAdmin::list_url();
		$views   = array(
			'all' => '<a href="' . esc_url( $base ) . '"' . ( '' === $current ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html__( 'All licenses', 'wp-license' ) . '</a>',
		);
		foreach ( self::reports() as $key => $label ) {
			$views[ $key ] = '<a href="' . esc_url( add_query_arg( 'report', $key, $base ) ) . '"' . ( $key === $current ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}

		return $views;
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

		$query  = self::query_parts( $filters );
		$params = $query['params'];
		$where  = '' !== $query['where'] ? ' WHERE ' . $query['where'] : '';

		$order_columns = array(
			'created'    => 'created_at',
			'paid_until' => 'paid_until',
			'status'     => 'status',
		);
		$order_by    = $order_columns[ $filters['orderby'] ];
		$direction   = 'asc' === $filters['order'] ? 'ASC' : 'DESC';
		$page        = $this->get_pagenum();
		$offset      = ( $page - 1 ) * self::PER_PAGE;
		$list_params = array_merge( $params, array( self::PER_PAGE, $offset ) );
		// Deferred join (T-89): the IDs of the page are sorted and skipped in an index,
		// full rows are read for these IDs only. Deep pages stay cheap.
		$ids_sql = "SELECT id FROM {$tables['licenses']}{$where} ORDER BY {$order_by} {$direction}, id {$direction} LIMIT %d OFFSET %d";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Column and direction come from fixed allowlists.
		$ids         = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $ids_sql, $list_params ) ) );
		$this->items = self::rows_in_order( $ids );

		// A page that is not full is the end of the result: no COUNT(*) needed.
		$found = count( $ids );
		if ( $found < self::PER_PAGE && ( $found > 0 || 1 === $page ) ) {
			$total = $offset + $found;
		} else {
			$count_sql = "SELECT COUNT(*) FROM {$tables['licenses']}{$where}";
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Only table names and fixed fragments are interpolated.
			$total = $params ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : (int) $wpdb->get_var( $count_sql );
		}

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
	 * Renewal filter values => labels.
	 *
	 * @return array<string,string>
	 */
	public static function renewal_filter_labels(): array {
		return array(
			'review_required'        => __( 'Renewal needs review', 'wp-license' ),
			'reconciling'            => __( 'Renewal result being checked', 'wp-license' ),
			'failed'                 => __( 'Renewal payment failed', 'wp-license' ),
			'manual_action_required' => __( 'Renewal needs customer action', 'wp-license' ),
			'past_due_open'          => __( 'Past due with open renewal', 'wp-license' ),
		);
	}

	/**
	 * Renewal mode filter values => labels.
	 *
	 * @return array<string,string>
	 */
	public static function mode_filter_labels(): array {
		return array(
			'auto'       => __( 'Automatic renewal', 'wp-license' ),
			'manual'     => __( 'Manual renewal', 'wp-license' ),
			'cancelling' => __( 'Cancels at period end', 'wp-license' ),
		);
	}

	/**
	 * Expiry filter values => labels.
	 *
	 * @return array<string,string>
	 */
	public static function expiry_filter_labels(): array {
		return array(
			/* translators: %s: number of days. */
			'soon'    => sprintf( _n( 'Expires within %s day', 'Expires within %s days', self::EXPIRY_WINDOW_DAYS, 'wp-license' ), number_format_i18n( self::EXPIRY_WINDOW_DAYS ) ),
			'expired' => __( 'Paid period ended', 'wp-license' ),
		);
	}

	/**
	 * WHERE fragments of the source order, renewal, renewal mode and expiry filters (T-102).
	 *
	 * Each fragment is served by an existing index (see docs/PERFORMANCE.md):
	 * the order through order_unit after resolving the order's items, renewal
	 * states through renewals.due_renewals, the expiry window through
	 * status_paid_until / paid_until. The renewal mode has no selective index;
	 * it filters the rows read in sort order.
	 *
	 * @param array<string,mixed> $filters Filters from request_filters().
	 * @param int|null            $now     Unix time (tests).
	 * @return array<int,array{sql:string,params:array<int,mixed>}>
	 */
	public static function filter_conditions( array $filters, ?int $now = null ): array {
		global $wpdb;

		$now        = null === $now ? time() : $now;
		$tables     = Schema::table_names();
		$conditions = array();

		$order_id = isset( $filters['order_id'] ) ? (int) $filters['order_id'] : 0;
		if ( $order_id > 0 ) {
			// Licenses store their order item; the order_unit index finds them by item.
			// Without items (order deleted) the plain column comparison still answers.
			$item_ids = array_map(
				'intval',
				(array) $wpdb->get_col(
					$wpdb->prepare( "SELECT order_item_id FROM {$wpdb->prefix}woocommerce_order_items WHERE order_id = %d LIMIT 500", $order_id )
				)
			);
			if ( $item_ids ) {
				$conditions[] = array(
					'sql'    => '(source_order_item_id IN (' . implode( ',', array_fill( 0, count( $item_ids ), '%d' ) ) . ') AND source_order_id = %d)',
					'params' => array_merge( $item_ids, array( $order_id ) ),
				);
			} else {
				$conditions[] = array(
					'sql'    => 'source_order_id = %d',
					'params' => array( $order_id ),
				);
			}
		}

		$renewal = isset( $filters['renewal'] ) ? (string) $filters['renewal'] : '';
		if ( 'past_due_open' === $renewal ) {
			$conditions[] = array(
				'sql'    => "(status = %s AND id IN (SELECT license_id FROM {$tables['renewals']} WHERE status IN (" . implode( ',', array_fill( 0, count( self::OPEN_RENEWAL_STATUSES ), '%s' ) ) . ')))',
				'params' => array_merge( array( 'past_due' ), self::OPEN_RENEWAL_STATUSES ),
			);
		} elseif ( '' !== $renewal && isset( self::renewal_filter_labels()[ $renewal ] ) ) {
			$conditions[] = array(
				'sql'    => "id IN (SELECT license_id FROM {$tables['renewals']} WHERE status = %s)",
				'params' => array( $renewal ),
			);
		}

		$mode = isset( $filters['mode'] ) ? (string) $filters['mode'] : '';
		if ( 'auto' === $mode ) {
			$conditions[] = array(
				'sql'    => 'renewal_enabled = 1 AND cancel_at_period_end = 0',
				'params' => array(),
			);
		} elseif ( 'manual' === $mode ) {
			$conditions[] = array(
				'sql'    => 'renewal_enabled = 0 AND cancel_at_period_end = 0',
				'params' => array(),
			);
		} elseif ( 'cancelling' === $mode ) {
			$conditions[] = array(
				'sql'    => 'cancel_at_period_end = 1',
				'params' => array(),
			);
		}

		$expiry = isset( $filters['expiry'] ) ? (string) $filters['expiry'] : '';
		$utc    = gmdate( 'Y-m-d H:i:s', $now );
		if ( 'soon' === $expiry ) {
			// Only licenses that can still expire: active or past due.
			$conditions[] = array(
				'sql'    => "status IN ('active','past_due') AND paid_until > %s AND paid_until <= %s",
				'params' => array( $utc, gmdate( 'Y-m-d H:i:s', $now + self::EXPIRY_WINDOW_DAYS * DAY_IN_SECONDS ) ),
			);
		} elseif ( 'expired' === $expiry ) {
			$conditions[] = array(
				'sql'    => 'paid_until <= %s',
				'params' => array( $utc ),
			);
		}

		return $conditions;
	}

	/**
	 * Full license rows for the given IDs, in the order of the IDs.
	 *
	 * @param int[] $ids License IDs.
	 * @return array<int,array<string,mixed>>
	 */
	private static function rows_in_order( array $ids ): array {
		global $wpdb;

		if ( ! $ids ) {
			return array();
		}
		$tables = Schema::table_names();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders are generated here.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$tables['licenses']} WHERE id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', $ids ),
			ARRAY_A
		);
		// phpcs:enable
		$by_id = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$by_id[ (int) $row['id'] ] = $row;
		}
		$ordered = array();
		foreach ( $ids as $id ) {
			if ( isset( $by_id[ $id ] ) ) {
				$ordered[] = $by_id[ $id ];
			}
		}

		return $ordered;
	}

	/**
	 * Translate search criteria into a WHERE fragment.
	 *
	 * @param array{type:string,value:string,label:string}|null $search Criteria from LicenseSearch::criteria().
	 * @return array{sql:string,params:array<int,mixed>}|null Null when there is nothing to search for.
	 */
	private static function search_condition( ?array $search ): ?array {
		global $wpdb;

		$tables = Schema::table_names();
		if ( null === $search ) {
			return null;
		}
		switch ( $search['type'] ) {
			case LicenseSearch::TYPE_NONE:
				return null;

			case LicenseSearch::TYPE_LICENSE_KEY_HASH:
				// Keys are never stored; a pasted key is compared by its hash.
				return array(
					'sql'    => 'license_key_hash = %s',
					'params' => array( $search['value'] ),
				);

			case LicenseSearch::TYPE_LICENSE_ID:
				return array(
					'sql'    => 'id = %d',
					'params' => array( (int) $search['value'] ),
				);

			case LicenseSearch::TYPE_NUMBER:
				$sql    = 'id = %d OR customer_id = %d';
				$params = array( (int) $search['value'], (int) $search['value'] );
				$prefix = self::key_prefix_condition( $search['value'] );
				if ( null !== $prefix ) {
					$sql   .= ' OR ' . $prefix['sql'];
					$params = array_merge( $params, $prefix['params'] );
				}
				return array(
					'sql'    => '(' . $sql . ')',
					'params' => $params,
				);

			case LicenseSearch::TYPE_UUID:
				// License UUID or the installation ID a customer site reports.
				return array(
					'sql'    => "(license_uuid = %s OR id IN (SELECT license_id FROM {$tables['activations']} WHERE installation_uuid = %s))",
					'params' => array( $search['value'], $search['value'] ),
				);

			case LicenseSearch::TYPE_EMAIL:
				$fragment = self::email_fragment( $search['value'] );
				if ( '' === $fragment ) {
					return array(
						'sql'    => '1 = 0',
						'params' => array(),
					);
				}
				// B-119: a subquery instead of the first 200 matching user IDs.
				return array(
					'sql'    => "customer_id IN (SELECT ID FROM {$wpdb->users} WHERE user_email LIKE %s)",
					'params' => array( '%' . $wpdb->esc_like( $fragment ) . '%' ),
				);
		}

		// Key prefix, product key, plan key or customer email contain the term. Each
		// part is resolved to an indexable condition first (T-89), so the license
		// table is not scanned with leading-wildcard LIKEs across columns.
		$parts  = array();
		$params = array();
		foreach ( array( self::key_prefix_condition( $search['value'] ), self::product_plan_condition( $search['value'] ) ) as $part ) {
			if ( null !== $part ) {
				$parts[] = $part['sql'];
				$params  = array_merge( $params, $part['params'] );
			}
		}
		if ( '' !== self::email_fragment( $search['value'] ) ) {
			$like = '%' . $wpdb->esc_like( $search['value'] ) . '%';
			// B-119: a subquery instead of the first 200 matching user IDs.
			$parts[]  = "customer_id IN (SELECT ID FROM {$wpdb->users} WHERE user_email LIKE %s)";
			$params[] = $like;
			// A-76: the address of an activated site.
			$parts[]  = "id IN (SELECT license_id FROM {$tables['activations']} WHERE site_url LIKE %s)";
			$params[] = $like;
		}
		if ( ! $parts ) {
			return array(
				'sql'    => '1 = 0',
				'params' => array(),
			);
		}

		return array(
			'sql'    => '(' . implode( ' OR ', $parts ) . ')',
			'params' => $params,
		);
	}

	/**
	 * Condition for "key_prefix contains the fragment", or null when no license can match.
	 *
	 * Same result as `key_prefix LIKE '%fragment%'`, which reads every row:
	 * - Every prefix is "WPLC-" and 8 hex digits (LicenseKey::prefix()). A
	 *   plain ASCII fragment with any other character cannot match.
	 * - A fragment starting with "WPLC-" can only match at the start, so an
	 *   anchored LIKE uses the key_prefix index as a range.
	 * - Otherwise the matching IDs are collected from the key_prefix index
	 *   alone (index-only scan). Too many matches keep the plain LIKE.
	 *
	 * @param string $fragment Search term.
	 * @return array{sql:string,params:array<int,mixed>}|null
	 */
	private static function key_prefix_condition( string $fragment ): ?array {
		global $wpdb;

		$ascii = 1 !== preg_match( '/[^\x20-\x7e]/', $fragment );
		if ( '' === $fragment || strlen( $fragment ) > 32 || ( $ascii && 1 === preg_match( '/[^0-9A-FLPW\-]/i', $fragment ) ) ) {
			return null;
		}
		if ( $ascii && 1 === preg_match( '/^WPLC-[0-9A-F]{0,8}$/i', $fragment ) ) {
			return array(
				'sql'    => 'key_prefix LIKE %s',
				'params' => array( $wpdb->esc_like( $fragment ) . '%' ),
			);
		}

		$like   = '%' . $wpdb->esc_like( $fragment ) . '%';
		$tables = Schema::table_names();
		$ids    = $wpdb->get_col(
			$wpdb->prepare( "SELECT id FROM {$tables['licenses']} WHERE key_prefix LIKE %s LIMIT %d", $like, self::MAX_RESOLVED_IDS + 1 ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names(), values prepared.
		);
		$ids    = array_map( 'intval', (array) $ids );
		if ( ! $ids ) {
			return null;
		}
		if ( count( $ids ) > self::MAX_RESOLVED_IDS ) {
			return array(
				'sql'    => 'key_prefix LIKE %s',
				'params' => array( $like ),
			);
		}

		return array(
			'sql'    => 'id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')',
			'params' => $ids,
		);
	}

	/**
	 * Condition for "product key or plan key contains the term", or null when none does.
	 *
	 * Same result as `product_key LIKE '%term%' OR plan_key LIKE '%term%'`:
	 * the distinct product/plan pairs of all licenses (few, read from the
	 * product_plan index) are matched first, then licenses are selected by pair.
	 *
	 * @param string $term Search term.
	 * @return array{sql:string,params:array<int,mixed>}|null
	 */
	private static function product_plan_condition( string $term ): ?array {
		global $wpdb;

		$like   = '%' . $wpdb->esc_like( $term ) . '%';
		$tables = Schema::table_names();
		$pairs  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.product_key, p.plan_key FROM ( SELECT product_key, plan_key FROM {$tables['licenses']} GROUP BY product_key, plan_key ) p WHERE p.product_key LIKE %s OR p.plan_key LIKE %s LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names(), values prepared.
				$like,
				$like,
				self::MAX_RESOLVED_PAIRS + 1
			),
			ARRAY_A
		);
		$pairs  = is_array( $pairs ) ? $pairs : array();
		if ( ! $pairs ) {
			return null;
		}
		if ( count( $pairs ) > self::MAX_RESOLVED_PAIRS ) {
			return array(
				'sql'    => '(product_key LIKE %s OR plan_key LIKE %s)',
				'params' => array( $like, $like ),
			);
		}
		$params = array();
		foreach ( $pairs as $pair ) {
			$params[] = (string) $pair['product_key'];
			$params[] = (string) $pair['plan_key'];
		}

		return array(
			'sql'    => '(product_key, plan_key) IN (' . implode( ',', array_fill( 0, count( $pairs ), '(%s,%s)' ) ) . ')',
			'params' => $params,
		);
	}

	/**
	 * Fragment that is long enough for a substring search.
	 *
	 * @param string $fragment Email address, site address or part of one.
	 * @return string Empty when the fragment is too short.
	 */
	private static function email_fragment( string $fragment ): string {
		$fragment = trim( str_replace( '*', '', $fragment ) );

		return strlen( $fragment ) < 3 ? '' : $fragment;
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
	 * Selection checkbox, bound to the bulk action form.
	 *
	 * @param array<string,mixed> $item License row.
	 * @return string
	 */
	protected function column_cb( $item ) {
		$id = (int) $item['id'];
		/* translators: %d: license ID. */
		$label = sprintf( __( 'Select license #%d', 'wp-license' ), $id );

		return '<label class="screen-reader-text" for="wplc-cb-' . $id . '">' . esc_html( $label ) . '</label>'
			. '<input type="checkbox" id="wplc-cb-' . $id . '" name="license_ids[]" value="' . $id . '" form="wplc-bulk-form">';
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
	 * Status, product, renewal, renewal mode, expiry and source order filters.
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

		$selects = array(
			'renewal' => array( __( 'Filter by renewal', 'wp-license' ), __( 'All renewal states', 'wp-license' ), self::renewal_filter_labels() ),
			'mode'    => array( __( 'Filter by renewal mode', 'wp-license' ), __( 'All renewal modes', 'wp-license' ), self::mode_filter_labels() ),
			'expiry'  => array( __( 'Filter by expiry', 'wp-license' ), __( 'Any expiry', 'wp-license' ), self::expiry_filter_labels() ),
		);
		foreach ( $selects as $name => $select ) {
			echo '<label class="screen-reader-text" for="wplc-filter-' . esc_attr( $name ) . '">' . esc_html( $select[0] ) . '</label>';
			echo '<select id="wplc-filter-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '"><option value="">' . esc_html( $select[1] ) . '</option>';
			foreach ( $select[2] as $value => $label ) {
				echo '<option value="' . esc_attr( $value ) . '"' . selected( $filters[ $name ], $value, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select>';
		}

		echo '<label class="screen-reader-text" for="wplc-filter-order">' . esc_html__( 'Filter by source order ID', 'wp-license' ) . '</label>';
		echo '<input type="number" min="1" step="1" class="small-text" id="wplc-filter-order" name="order_id" placeholder="' . esc_attr__( 'Order ID', 'wp-license' ) . '" value="' . esc_attr( $filters['order_id'] > 0 ? (string) $filters['order_id'] : '' ) . '">';

		submit_button( __( 'Filter', 'wp-license' ), '', 'filter_action', false );
		echo '</div>';
	}

	/**
	 * Table navigation without the core bulk-action nonce, so filter URLs stay short and shareable.
	 *
	 * The bulk controls belong to the separate POST form (LicenseBulkActions).
	 *
	 * @param string $which top or bottom.
	 * @return void
	 */
	protected function display_tablenav( $which ) {
		echo '<div class="tablenav ' . esc_attr( $which ) . '">';
		if ( 'top' === $which && $this->has_items() ) {
			LicenseBulkActions::render_controls();
		}
		$this->extra_tablenav( $which );
		$this->pagination( $which );
		echo '<br class="clear"></div>';
	}
}
