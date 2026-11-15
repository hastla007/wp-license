<?php
/**
 * Table of the audit log screen (T-156).
 *
 * @package WPLC
 */

namespace WPLC\Admin;

use WPLC\Infrastructure\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Paginated, filtered audit entries, newest first.
 *
 * The WordPress base class must be loaded before this file is autoloaded;
 * AuditLogAdmin::render() takes care of that.
 */
final class AuditLogTable extends \WP_List_Table {

	/**
	 * Object type => object ID => license ID, for activation and renewal links.
	 *
	 * @var array<string,array<int,int>>
	 */
	private $license_of = array();

	/**
	 * Configure the table.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'wplc-audit-entry',
				'plural'   => 'wplc-audit-entries',
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
			'created_at' => __( 'Date', 'wp-license' ),
			'action'     => __( 'Action', 'wp-license' ),
			'object'     => __( 'Object', 'wp-license' ),
			'actor'      => __( 'By', 'wp-license' ),
			'reason'     => __( 'Reason', 'wp-license' ),
			'change'     => __( 'Change', 'wp-license' ),
		);
	}

	/**
	 * Query one page of entries plus the license IDs their links need.
	 *
	 * @return void
	 */
	public function prepare_items() {
		global $wpdb;

		$table  = Schema::table_names()['audit_log'];
		$where  = AuditLogAdmin::where( AuditLogAdmin::request_filters() );
		$page   = $this->get_pagenum();
		$offset = ( $page - 1 ) * AuditLogAdmin::PER_PAGE;
		$sql    = "SELECT * FROM {$table}{$where['sql']} ORDER BY id DESC LIMIT %d OFFSET %d";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Only the table name and fixed fragments with placeholders are interpolated.
		$rows        = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $where['params'], array( AuditLogAdmin::PER_PAGE, $offset ) ) ), ARRAY_A );
		$this->items = is_array( $rows ) ? $rows : array();

		$found = count( $this->items );
		if ( $found < AuditLogAdmin::PER_PAGE && ( $found > 0 || 1 === $page ) ) {
			$total = $offset + $found;
		} else {
			$count_sql = "SELECT COUNT(*) FROM {$table}{$where['sql']}";
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Only the table name and fixed fragments with placeholders are interpolated.
			$total = $where['params'] ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $where['params'] ) ) : (int) $wpdb->get_var( $count_sql );
		}

		$this->prime_links();
		$actor_ids = array_values( array_unique( array_filter( array_map( 'intval', array_column( $this->items, 'actor_user_id' ) ) ) ) );
		if ( $actor_ids && function_exists( 'cache_users' ) ) {
			cache_users( $actor_ids );
		}

		$this->_column_headers = array( $this->get_columns(), array(), array(), 'action' );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => AuditLogAdmin::PER_PAGE,
				'total_pages' => (int) ceil( $total / AuditLogAdmin::PER_PAGE ),
			)
		);
	}

	/**
	 * License IDs of the activations and renewals on this page, in one query per type.
	 *
	 * @return void
	 */
	private function prime_links(): void {
		global $wpdb;

		$this->license_of = array();
		$tables           = Schema::table_names();
		foreach ( array(
			'activation' => $tables['activations'],
			'renewal'    => $tables['renewals'],
		) as $type => $table ) {
			$ids = array();
			foreach ( $this->items as $item ) {
				if ( $type === $item['object_type'] && (int) $item['object_id'] > 0 ) {
					$ids[] = (int) $item['object_id'];
				}
			}
			$ids = array_values( array_unique( $ids ) );
			if ( ! $ids ) {
				continue;
			}
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders are generated here.
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT id, license_id FROM {$table} WHERE id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', $ids ),
				ARRAY_A
			);
			// phpcs:enable
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				$this->license_of[ $type ][ (int) $row['id'] ] = (int) $row['license_id'];
			}
		}
	}

	/**
	 * Date and time in the site timezone.
	 *
	 * @param array<string,mixed> $item Audit row.
	 * @return string
	 */
	protected function column_created_at( $item ) {
		return esc_html( Format::date( $item['created_at'], true ) );
	}

	/**
	 * Action code.
	 *
	 * @param array<string,mixed> $item Audit row.
	 * @return string
	 */
	protected function column_action( $item ) {
		return '<code>' . esc_html( (string) $item['action'] ) . '</code>';
	}

	/**
	 * Linked object.
	 *
	 * @param array<string,mixed> $item Audit row.
	 * @return string
	 */
	protected function column_object( $item ) {
		return AuditLogAdmin::object_html( (string) $item['object_type'], (int) $item['object_id'], $this->license_of );
	}

	/**
	 * Acting user, or "System".
	 *
	 * @param array<string,mixed> $item Audit row.
	 * @return string
	 */
	protected function column_actor( $item ) {
		$actor_id = (int) $item['actor_user_id'];
		$actor    = $actor_id > 0 ? get_userdata( $actor_id ) : false;

		// Login and ID, which a user cannot change (B-135), as on the license detail screen.
		return esc_html( LicenseDetail::actor_label( $actor_id, $actor ? (string) $actor->user_login : '' ) );
	}

	/**
	 * Translated, redacted reason.
	 *
	 * @param array<string,mixed> $item Audit row.
	 * @return string
	 */
	protected function column_reason( $item ) {
		$text = AuditLogAdmin::reason_text( (string) $item['reason'] );
		return '' === $text ? '—' : esc_html( $text );
	}

	/**
	 * Change summary plus the full redacted snapshots.
	 *
	 * @param array<string,mixed> $item Audit row.
	 * @return string
	 */
	protected function column_change( $item ) {
		$out    = esc_html( AuditLogAdmin::change_text( $item['before_json'], $item['after_json'] ) );
		$before = AuditLogAdmin::snapshot_text( $item['before_json'] );
		$after  = AuditLogAdmin::snapshot_text( $item['after_json'] );
		if ( '' === $before && '' === $after ) {
			return $out;
		}
		$out .= '<details class="wplc-audit-details"><summary>' . esc_html__( 'Details', 'wp-license' ) . '</summary>';
		if ( '' !== $before ) {
			$out .= '<p><strong>' . esc_html__( 'Before', 'wp-license' ) . '</strong></p><pre>' . esc_html( $before ) . '</pre>';
		}
		if ( '' !== $after ) {
			$out .= '<p><strong>' . esc_html__( 'After', 'wp-license' ) . '</strong></p><pre>' . esc_html( $after ) . '</pre>';
		}

		return $out . '</details>';
	}

	/**
	 * Fallback for unknown columns.
	 *
	 * @param array<string,mixed> $item        Audit row.
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
		esc_html_e( 'No audit entries match these filters.', 'wp-license' );
	}

	/**
	 * Table navigation without bulk actions or their nonce.
	 *
	 * @param string $which top or bottom.
	 * @return void
	 */
	protected function display_tablenav( $which ) {
		echo '<div class="tablenav ' . esc_attr( $which ) . '">';
		$this->pagination( $which );
		echo '<br class="clear"></div>';
	}
}
