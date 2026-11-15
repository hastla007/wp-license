<?php
/**
 * Complete, filterable audit log with CSV export.
 *
 * @package WPLC
 */

namespace WPLC\Admin;

use WPLC\Infrastructure\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce → License audit log. Read only.
 */
final class AuditLogPage {

	/**
	 * Admin page slug.
	 */
	public const PAGE = 'wplc-audit';

	/**
	 * Entries per page.
	 */
	public const PER_PAGE = 100;

	/**
	 * Largest number of rows in one export.
	 */
	public const EXPORT_LIMIT = 50000;

	/**
	 * Register the page and the export handler.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ), 25 );
		add_action( 'admin_post_wplc_admin_export_audit', array( self::class, 'handle_export' ) );
	}

	/**
	 * Add the submenu page.
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		add_submenu_page(
			Capabilities::menu_parent(),
			__( 'License audit log', 'wp-license' ),
			__( 'License audit log', 'wp-license' ),
			Capabilities::cap( 'view_diagnostics' ),
			self::PAGE,
			array( self::class, 'render' )
		);
		LicenseAdmin::connect_page( self::PAGE, __( 'License audit log', 'wp-license' ) );
	}

	/**
	 * URL of the page with filters.
	 *
	 * @param array<string,mixed> $filters Filter values.
	 * @return string
	 */
	public static function url( array $filters = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), array_filter( $filters ) ), admin_url( 'admin.php' ) );
	}

	/**
	 * Filter values of the current request.
	 *
	 * @return array{license:int,object_type:string,object_id:int,audit_action:string,actor:string,from:string,to:string}
	 */
	public static function request_filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters.
		$from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
		$to   = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';

		$filters = array(
			'license'      => isset( $_GET['license'] ) ? absint( $_GET['license'] ) : 0,
			'object_type'  => isset( $_GET['object_type'] ) ? sanitize_key( wp_unslash( $_GET['object_type'] ) ) : '',
			'object_id'    => isset( $_GET['object_id'] ) ? absint( $_GET['object_id'] ) : 0,
			'audit_action' => isset( $_GET['audit_action'] ) ? sanitize_key( wp_unslash( $_GET['audit_action'] ) ) : '',
			'actor'        => isset( $_GET['actor'] ) ? sanitize_text_field( wp_unslash( $_GET['actor'] ) ) : '',
			'from'         => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ? $from : '',
			'to'           => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ? $to : '',
		);
		// phpcs:enable

		return $filters;
	}

	/**
	 * Translate filters into WHERE conditions.
	 *
	 * @param array<string,mixed> $filters Filters from request_filters().
	 * @return array{where:string,params:array<int,mixed>} Conditions without the WHERE keyword.
	 */
	public static function query_parts( array $filters ): array {
		$tables     = Schema::table_names();
		$conditions = array( '1 = 1' );
		$params     = array();

		$license_id = isset( $filters['license'] ) ? (int) $filters['license'] : 0;
		if ( $license_id > 0 ) {
			// Everything that belongs to one license: the license, its sites and its renewals.
			$conditions[] = "( ( object_type = 'license' AND object_id = %d ) OR ( object_type = 'activation' AND object_id IN ( SELECT id FROM {$tables['activations']} WHERE license_id = %d ) ) OR ( object_type = 'renewal' AND object_id IN ( SELECT id FROM {$tables['renewals']} WHERE license_id = %d ) ) )";
			array_push( $params, $license_id, $license_id, $license_id );
		}
		if ( ! empty( $filters['object_type'] ) ) {
			$conditions[] = 'object_type = %s';
			$params[]     = (string) $filters['object_type'];
		}
		if ( ! empty( $filters['object_id'] ) ) {
			$conditions[] = 'object_id = %d';
			$params[]     = (int) $filters['object_id'];
		}
		if ( ! empty( $filters['audit_action'] ) ) {
			$conditions[] = 'action = %s';
			$params[]     = (string) $filters['audit_action'];
		}

		$actor = isset( $filters['actor'] ) ? trim( (string) $filters['actor'] ) : '';
		if ( '' !== $actor ) {
			if ( in_array( strtolower( $actor ), array( 'system', '0' ), true ) ) {
				$conditions[] = 'actor_user_id = 0';
			} elseif ( ctype_digit( ltrim( $actor, '#' ) ) ) {
				$conditions[] = 'actor_user_id = %d';
				$params[]     = (int) ltrim( $actor, '#' );
			} else {
				$user         = get_user_by( 'login', $actor );
				$conditions[] = 'actor_user_id = %d';
				// An unknown login matches nothing instead of everything.
				$params[] = $user ? (int) $user->ID : -1;
			}
		}

		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
		if ( ! empty( $filters['from'] ) ) {
			$from = \DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $filters['from'], $timezone );
			if ( $from ) {
				$conditions[] = 'created_at >= %s';
				$params[]     = $from->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
			}
		}
		if ( ! empty( $filters['to'] ) ) {
			$to = \DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $filters['to'], $timezone );
			if ( $to ) {
				$conditions[] = 'created_at < %s';
				$params[]     = $to->modify( '+1 day' )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
			}
		}

		return array(
			'where'  => implode( ' AND ', $conditions ),
			'params' => $params,
		);
	}

	/**
	 * Load audit entries, newest first.
	 *
	 * @param array<string,mixed> $filters Filters.
	 * @param int                 $limit   Number of rows.
	 * @param int                 $offset  Offset.
	 * @return array<int,array<string,mixed>>
	 */
	public static function entries( array $filters, int $limit, int $offset = 0 ): array {
		global $wpdb;

		$tables = Schema::table_names();
		$query  = self::query_parts( $filters );
		$sql    = "SELECT * FROM {$tables['audit_log']} WHERE {$query['where']} ORDER BY id DESC LIMIT %d OFFSET %d";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Only table names and fixed fragments are interpolated.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $query['params'], array( max( 1, $limit ), max( 0, $offset ) ) ) ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public static function render(): void {
		global $wpdb;

		Capabilities::require_cap( 'view_diagnostics', __( 'You do not have permission to view the audit log.', 'wp-license' ) );

		$tables  = Schema::table_names();
		$filters = self::request_filters();
		$query   = self::query_parts( $filters );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only paging.
		$page    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$sql     = "SELECT COUNT(*) FROM {$tables['audit_log']} WHERE {$query['where']}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Only table names and fixed fragments are interpolated.
		$total   = (int) ( $query['params'] ? $wpdb->get_var( $wpdb->prepare( $sql, $query['params'] ) ) : $wpdb->get_var( $sql ) );
		$entries = self::entries( $filters, self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE );

		echo '<div class="wrap"><h1 class="wp-heading-inline">' . esc_html__( 'License audit log', 'wp-license' ) . '</h1> ';
		$export = wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'wplc_admin_export_audit' ), array_filter( $filters ) ), admin_url( 'admin-post.php' ) ), 'wplc_admin_export_audit' );
		echo '<a href="' . esc_url( $export ) . '" class="page-title-action">' . esc_html__( 'Export as CSV', 'wp-license' ) . '</a>';
		echo '<hr class="wp-header-end">';
		echo '<p>' . esc_html__( 'Every change to licenses, site activations, renewals, releases and settings, newest first. Entries are never changed. License keys, secrets, email addresses and site addresses are not stored here.', 'wp-license' ) . '</p>';

		echo '<form method="get" class="wplc-inline-form">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '">';
		$fields = array(
			'license'      => array( __( 'License ID', 'wp-license' ), 'number', $filters['license'] ? (string) $filters['license'] : '' ),
			'object_type'  => array( __( 'Object type', 'wp-license' ), 'text', $filters['object_type'] ),
			'object_id'    => array( __( 'Object ID', 'wp-license' ), 'number', $filters['object_id'] ? (string) $filters['object_id'] : '' ),
			'audit_action' => array( __( 'Action', 'wp-license' ), 'text', $filters['audit_action'] ),
			'actor'        => array( __( 'By (login, #ID or "system")', 'wp-license' ), 'text', $filters['actor'] ),
			'from'         => array( __( 'From', 'wp-license' ), 'date', $filters['from'] ),
			'to'           => array( __( 'To', 'wp-license' ), 'date', $filters['to'] ),
		);
		foreach ( $fields as $name => $field ) {
			$id = 'wplc-audit-' . str_replace( '_', '-', $name );
			echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $field[0] ) . '</label> ';
			echo '<input type="' . esc_attr( $field[1] ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $field[2] ) . '"' . ( 'number' === $field[1] ? ' min="1" class="small-text"' : '' ) . '> ';
		}
		submit_button( __( 'Filter', 'wp-license' ), '', '', false );
		echo ' <a class="button-link" href="' . esc_url( self::url() ) . '">' . esc_html__( 'Reset', 'wp-license' ) . '</a>';
		echo '</form>';

		/* translators: %d: number of audit entries. */
		echo '<p class="description">' . esc_html( sprintf( _n( '%d entry', '%d entries', $total, 'wp-license' ), $total ) ) . '</p>';

		if ( ! $entries ) {
			echo '<p>' . esc_html__( 'No audit entries match these filters.', 'wp-license' ) . '</p></div>';
			return;
		}

		$actor_ids = array_values( array_unique( array_filter( array_map( 'intval', array_column( $entries, 'actor_user_id' ) ) ) ) );
		if ( $actor_ids && function_exists( 'cache_users' ) ) {
			cache_users( $actor_ids );
		}

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Date', 'wp-license' ), __( 'Action', 'wp-license' ), __( 'Object', 'wp-license' ), __( 'By', 'wp-license' ), __( 'Reason', 'wp-license' ), __( 'Change', 'wp-license' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $entries as $entry ) {
			$actor_id = (int) $entry['actor_user_id'];
			$actor    = $actor_id ? get_userdata( $actor_id ) : false;
			$type     = (string) $entry['object_type'];
			$object   = $type . ' #' . (int) $entry['object_id'];

			echo '<tr><td>' . esc_html( Format::date( $entry['created_at'], true ) ) . '</td>';
			echo '<td><code>' . esc_html( (string) $entry['action'] ) . '</code></td><td>';
			if ( 'license' === $type && (int) $entry['object_id'] > 0 ) {
				echo '<a href="' . esc_url( LicenseAdmin::detail_url( (int) $entry['object_id'] ) ) . '">' . esc_html( $object ) . '</a>';
			} else {
				echo esc_html( $object );
			}
			echo '</td><td>' . esc_html( LicenseDetail::actor_label( $actor_id, $actor ? (string) $actor->user_login : '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) $entry['reason'] ) . '</td>';
			echo '<td>' . esc_html( LicenseDetail::change_summary( $entry['before_json'], $entry['after_json'] ) ) . '</td></tr>';
		}
		echo '</tbody></table>';

		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				(string) paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%', self::url( $filters ) ),
						'format'  => '',
						'current' => $page,
						'total'   => $pages,
					)
				)
			);
			echo '</div></div>';
		}
		echo '</div>';
	}

	/**
	 * Build the CSV for a set of filters.
	 *
	 * @param array<string,mixed> $filters Filters.
	 * @return string
	 */
	public static function export_csv( array $filters ): string {
		$out    = LicenseAdmin::csv_line( array( 'id', 'created_at_utc', 'action', 'object_type', 'object_id', 'actor_user_id', 'actor_login', 'reason', 'before', 'after' ) );
		$offset = 0;
		do {
			$rows = self::entries( $filters, 500, $offset );
			foreach ( $rows as $row ) {
				$actor = (int) $row['actor_user_id'] ? get_userdata( (int) $row['actor_user_id'] ) : false;
				$out  .= LicenseAdmin::csv_line(
					array(
						(int) $row['id'],
						(string) $row['created_at'],
						(string) $row['action'],
						(string) $row['object_type'],
						(int) $row['object_id'],
						(int) $row['actor_user_id'],
						$actor ? (string) $actor->user_login : '',
						(string) $row['reason'],
						(string) $row['before_json'],
						(string) $row['after_json'],
					)
				);
			}
			$batch   = count( $rows );
			$offset += 500;
		} while ( 500 === $batch && $offset < self::EXPORT_LIMIT );

		return $out;
	}

	/**
	 * Stream the filtered audit log as CSV.
	 *
	 * @return void
	 */
	public static function handle_export(): void {
		Capabilities::require_cap( 'view_diagnostics', __( 'You do not have permission to view the audit log.', 'wp-license' ) );
		check_admin_referer( 'wplc_admin_export_audit' );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="license-audit-' . gmdate( 'Y-m-d' ) . '.csv"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo self::export_csv( self::request_filters() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download, cells are neutralized in csv_line().
		exit;
	}
}
