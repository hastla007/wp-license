<?php
/**
 * Administrator screen "License audit log" (T-156).
 *
 * @package WPLC
 */

namespace WPLC\Admin;

use WPLC\Infrastructure\Database\Schema;
use WPLC\License\AuditLog;
use WPLC\License\AuditReason;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only list of every audit entry, including system-wide ones (object ID 0:
 * settings, catalog import, key rotation, signing key, bulk actions) that no
 * other screen shows.
 *
 * Stored reasons and snapshots are shown through AuditReason::label() and the
 * redaction of AuditLog (applied again at display time), never raw. The same
 * applies to the CSV export, which is itself recorded in the audit log.
 *
 * This is the only audit screen (P-27): the former second page `wplc-audit`
 * redirects here, with its filters (including the license filter) carried over.
 */
final class AuditLogAdmin {

	/**
	 * Admin page slug.
	 */
	public const PAGE = 'wplc-audit-log';

	/**
	 * Entries per page.
	 */
	public const PER_PAGE = 50;

	/**
	 * Slug of the removed second audit page; its URLs redirect here.
	 */
	public const LEGACY_PAGE = 'wplc-audit';

	/**
	 * admin-post action of the CSV export.
	 */
	public const EXPORT_ACTION = 'wplc_admin_export_audit';

	/**
	 * Most entries in one CSV export.
	 */
	public const EXPORT_LIMIT = 50000;

	/**
	 * Entries read per batch of a CSV export.
	 */
	public const EXPORT_BATCH = 500;

	/**
	 * Most activation or renewal IDs listed literally in the license filter;
	 * a license with more uses a subquery instead.
	 */
	private const LICENSE_FILTER_MAX_IDS = 2000;

	/**
	 * Register the menu entry and assets.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		// After "Licenses" (10) and "Renewals to review" (11).
		add_action( 'admin_menu', array( self::class, 'register_menu' ), 12 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
		add_action( 'admin_init', array( self::class, 'redirect_legacy_page' ) );
		add_action( 'admin_post_' . self::EXPORT_ACTION, array( self::class, 'handle_export' ) );
	}

	/**
	 * Add the WooCommerce submenu page.
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
	 * Redirect a request for the removed page `wplc-audit` to this screen.
	 *
	 * Runs on admin_init, before WordPress refuses the unknown page slug.
	 *
	 * @return void
	 */
	public static function redirect_legacy_page(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect.
		$target = self::legacy_redirect_url( wp_unslash( $_GET ) );
		if ( null !== $target ) {
			wp_safe_redirect( $target, 301 );
			exit;
		}
	}

	/**
	 * Target of a legacy `wplc-audit` URL, or null for any other request. Pure apart from admin_url().
	 *
	 * @param array<string,mixed> $get Unslashed query arguments.
	 * @return string|null
	 */
	public static function legacy_redirect_url( array $get ): ?string {
		if ( ! isset( $get['page'] ) || self::LEGACY_PAGE !== $get['page'] ) {
			return null;
		}
		$args = array();
		foreach ( array( 'license', 'object_type', 'object_id', 'audit_action', 'actor', 'from', 'to', 'paged' ) as $key ) {
			if ( isset( $get[ $key ] ) && is_string( $get[ $key ] ) && '' !== trim( $get[ $key ] ) ) {
				$args[ $key ] = sanitize_text_field( $get[ $key ] );
			}
		}
		if ( isset( $args['actor'] ) && 1 === preg_match( '/^#\d+$/', $args['actor'] ) ) {
			// The old page accepted "#12" for a user ID.
			$args['actor'] = substr( $args['actor'], 1 );
		}

		return self::url( $args );
	}

	/**
	 * Load the admin styles on this screen.
	 *
	 * @return void
	 */
	public static function enqueue_assets(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Screen detection only.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::PAGE === $page ) {
			wp_enqueue_style( 'wplc-admin', plugins_url( 'assets/css/wplc-admin.css', WPLC_FILE ), array(), WPLC_VERSION );
		}
	}

	/**
	 * URL of the screen, optionally filtered.
	 *
	 * @param array<string,scalar> $args Filter arguments.
	 * @return string
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Object types with labels (unknown stored types are shown as stored).
	 *
	 * @return array<string,string>
	 */
	public static function object_type_labels(): array {
		return array(
			'license'    => __( 'License', 'wp-license' ),
			'activation' => __( 'Activation', 'wp-license' ),
			'renewal'    => __( 'Renewal', 'wp-license' ),
			'release'    => __( 'Release', 'wp-license' ),
			'settings'   => __( 'Settings', 'wp-license' ),
			'catalog'    => __( 'Catalog', 'wp-license' ),
			'system'     => __( 'System', 'wp-license' ),
		);
	}

	/**
	 * Filters from the request.
	 *
	 * @return array{license:string,object_type:string,object_id:string,action:string,actor:string,from:string,to:string}
	 */
	public static function request_filters(): array {
		$read = static function ( string $key ): string {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filters.
			return isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? trim( sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) ) : '';
		};

		$license   = $read( 'license' );
		$object_id = $read( 'object_id' );
		$from      = $read( 'from' );
		$to        = $read( 'to' );

		return array(
			'license'     => '' !== $license && ctype_digit( $license ) && (int) $license > 0 ? (string) (int) $license : '',
			'object_type' => sanitize_key( $read( 'object_type' ) ),
			'object_id'   => '' !== $object_id && ctype_digit( $object_id ) ? (string) (int) $object_id : '',
			'action'      => sanitize_key( $read( 'audit_action' ) ),
			'actor'       => $read( 'actor' ),
			'from'        => 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ? $from : '',
			'to'          => 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ? $to : '',
		);
	}

	/**
	 * Translate filters into a WHERE clause.
	 *
	 * The list is ordered by ID (primary key). IDs grow with created_at (the
	 * retention job relies on the same property), so a date range becomes an ID
	 * range found by a binary search over the primary key: no index on
	 * created_at is needed. object_type/object_id use object_history, the
	 * actor actor_history. The license filter (the license, its activations and
	 * its renewals) reads the activation and renewal IDs first, so the audit
	 * query is a few object_history ranges instead of a subquery per row.
	 *
	 * @param array{license?:string,object_type:string,object_id:string,action:string,actor:string,from:string,to:string} $filters Filters.
	 * @return array{sql:string,params:array<int,mixed>}
	 */
	public static function where( array $filters ): array {
		$conditions = array();
		$params     = array();

		$license_id = isset( $filters['license'] ) ? (int) $filters['license'] : 0;
		if ( $license_id > 0 ) {
			$license_where = self::license_condition( $license_id );
			$conditions[]  = $license_where['sql'];
			$params        = array_merge( $params, $license_where['params'] );
		}

		if ( '' !== $filters['object_type'] ) {
			$conditions[] = 'object_type = %s';
			$params[]     = $filters['object_type'];
		}
		if ( '' !== $filters['object_id'] ) {
			if ( '' === $filters['object_type'] ) {
				// object_history starts with object_type: list the stored types (read
				// with a loose index scan) so an object ID alone, such as 0 for the
				// system-wide entries, is a few index ranges instead of a table scan.
				$types = self::stored_object_types();
				if ( $types ) {
					$conditions[] = 'object_type IN (' . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ')';
					$params       = array_merge( $params, $types );
				}
			}
			$conditions[] = 'object_id = %d';
			$params[]     = (int) $filters['object_id'];
		}
		if ( '' !== $filters['action'] ) {
			$conditions[] = 'action = %s';
			$params[]     = $filters['action'];
		}
		if ( '' !== $filters['actor'] ) {
			$actor = self::resolve_actor( $filters['actor'] );
			if ( null === $actor ) {
				$conditions[] = '1 = 0';
			} else {
				$conditions[] = 'actor_user_id = %d';
				$params[]     = $actor;
			}
		}
		$timezone = wp_timezone();
		if ( '' !== $filters['from'] ) {
			$start = Format::local_date_to_utc_start_of_day( $filters['from'], $timezone );
			if ( '' !== $start ) {
				$conditions[] = 'id >= %d';
				$params[]     = self::first_id_at_or_after( $start );
			}
		}
		if ( '' !== $filters['to'] ) {
			$end = Format::local_date_to_utc_end_of_day( $filters['to'], $timezone );
			if ( '' !== $end ) {
				$conditions[] = 'id < %d';
				$params[]     = self::first_id_at_or_after( gmdate( 'Y-m-d H:i:s', (int) strtotime( $end . ' UTC' ) + 1 ) );
			}
		}

		return array(
			'sql'    => $conditions ? ' WHERE ' . implode( ' AND ', $conditions ) : '',
			'params' => $params,
		);
	}

	/**
	 * Condition matching every entry of one license: the license itself, its
	 * site activations and its renewals.
	 *
	 * @param int $license_id License ID.
	 * @return array{sql:string,params:array<int,mixed>}
	 */
	public static function license_condition( int $license_id ): array {
		global $wpdb;

		$tables = Schema::table_names();
		$parts  = array( "( object_type = 'license' AND object_id = %d )" );
		$params = array( $license_id );
		foreach ( array(
			'activation' => $tables['activations'],
			'renewal'    => $tables['renewals'],
		) as $type => $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names().
			$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE license_id = %d ORDER BY id ASC LIMIT %d", $license_id, self::LICENSE_FILTER_MAX_IDS + 1 ) ) );
			if ( ! $ids ) {
				continue;
			}
			if ( count( $ids ) > self::LICENSE_FILTER_MAX_IDS ) {
				$parts[] = "( object_type = '{$type}' AND object_id IN ( SELECT id FROM {$table} WHERE license_id = %d ) )";
				$params[] = $license_id;
				continue;
			}
			$parts[] = "( object_type = '{$type}' AND object_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') )';
			$params  = array_merge( $params, $ids );
		}

		return array(
			'sql'    => '( ' . implode( ' OR ', $parts ) . ' )',
			'params' => $params,
		);
	}

	/**
	 * Distinct object types in the audit log (loose index scan of object_history).
	 *
	 * @return string[]
	 */
	public static function stored_object_types(): array {
		global $wpdb;

		$table = Schema::table_names()['audit_log'];
		$types = $wpdb->get_col( "SELECT DISTINCT object_type FROM {$table} LIMIT 200" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names().

		return array_values( array_map( 'strval', (array) $types ) );
	}

	/**
	 * Actor filter input to a user ID: "system" or 0, a user ID, or an email address.
	 *
	 * @param string $input Filter input.
	 * @return int|null Null when no user matches.
	 */
	public static function resolve_actor( string $input ): ?int {
		$input = trim( $input );
		if ( 'system' === strtolower( $input ) || '0' === $input ) {
			return 0;
		}
		if ( ctype_digit( $input ) ) {
			return (int) $input;
		}
		if ( is_email( $input ) ) {
			$user = get_user_by( 'email', $input );
			return $user ? (int) $user->ID : null;
		}
		$user = get_user_by( 'login', $input );

		return $user ? (int) $user->ID : null;
	}

	/**
	 * Smallest audit ID whose created_at is at or after a UTC time (MAX(id)+1 when none).
	 *
	 * Binary search over the primary key; each step reads one row.
	 *
	 * @param string $utc 'Y-m-d H:i:s' UTC.
	 * @return int
	 */
	public static function first_id_at_or_after( string $utc ): int {
		global $wpdb;

		$table = Schema::table_names()['audit_log'];
		$low   = (int) $wpdb->get_var( "SELECT MIN(id) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names().
		$high  = (int) $wpdb->get_var( "SELECT MAX(id) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names().
		$best  = $high + 1;
		$steps = 0;
		// Invariant: every existing ID below $low is older than $utc; $best is the
		// smallest ID found so far that is not.
		while ( $low <= $high && $steps < 128 ) {
			++$steps;
			$mid = intdiv( $low + $high, 2 );
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, created_at FROM {$table} WHERE id >= %d AND id <= %d ORDER BY id ASC LIMIT 1", $mid, $high ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names().
			if ( ! is_array( $row ) ) {
				$high = $mid - 1;
			} elseif ( (string) $row['created_at'] >= $utc ) {
				$best = (int) $row['id'];
				$high = $mid - 1;
			} else {
				$low = (int) $row['id'] + 1;
			}
		}

		return max( 1, $best );
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public static function render(): void {
		Capabilities::require_cap( 'view_diagnostics', __( 'You do not have permission to view the audit log.', 'wp-license' ) );
		if ( ! class_exists( '\\WP_List_Table' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		}

		$filters = self::request_filters();
		$table   = new AuditLogTable();
		$table->prepare_items();

		echo '<div class="wrap wplc-audit-log">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'License audit log', 'wp-license' ) . '</h1> ';
		echo '<a href="' . esc_url( self::export_url( $filters ) ) . '" class="page-title-action">' . esc_html__( 'Export as CSV', 'wp-license' ) . '</a>';
		echo '<hr class="wp-header-end">';
		CsvExport::render_truncated_notice( get_current_user_id() );
		echo '<p class="description">' . esc_html__( 'Every recorded action on licenses, activations, renewals, releases, settings and keys, newest first. Read-only. Sensitive values are redacted.', 'wp-license' ) . '</p>';

		echo '<form method="get" class="wplc-audit-filters">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '">';
		echo '<p>';
		echo '<label for="wplc-audit-license">' . esc_html__( 'License ID', 'wp-license' ) . '</label> ';
		echo '<input type="number" min="1" class="small-text" id="wplc-audit-license" name="license" value="' . esc_attr( $filters['license'] ) . '"> ';
		echo '<label for="wplc-audit-type">' . esc_html__( 'Object type', 'wp-license' ) . '</label> ';
		echo '<select id="wplc-audit-type" name="object_type"><option value="">' . esc_html__( 'All types', 'wp-license' ) . '</option>';
		$types = self::object_type_labels();
		if ( '' !== $filters['object_type'] && ! isset( $types[ $filters['object_type'] ] ) ) {
			$types[ $filters['object_type'] ] = $filters['object_type'];
		}
		foreach ( $types as $type => $label ) {
			echo '<option value="' . esc_attr( $type ) . '"' . selected( $filters['object_type'], $type, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select> ';
		echo '<label for="wplc-audit-object">' . esc_html__( 'Object ID', 'wp-license' ) . '</label> ';
		echo '<input type="number" min="0" class="small-text" id="wplc-audit-object" name="object_id" value="' . esc_attr( $filters['object_id'] ) . '"> ';
		echo '<label for="wplc-audit-action">' . esc_html__( 'Action', 'wp-license' ) . '</label> ';
		echo '<input type="text" id="wplc-audit-action" name="audit_action" value="' . esc_attr( $filters['action'] ) . '" placeholder="settings_updated"> ';
		echo '<label for="wplc-audit-actor">' . esc_html__( 'By', 'wp-license' ) . '</label> ';
		echo '<input type="text" id="wplc-audit-actor" name="actor" value="' . esc_attr( $filters['actor'] ) . '" placeholder="' . esc_attr__( 'User ID, email or "system"', 'wp-license' ) . '"> ';
		echo '<label for="wplc-audit-from">' . esc_html__( 'From', 'wp-license' ) . '</label> ';
		echo '<input type="date" id="wplc-audit-from" name="from" value="' . esc_attr( $filters['from'] ) . '"> ';
		echo '<label for="wplc-audit-to">' . esc_html__( 'To', 'wp-license' ) . '</label> ';
		echo '<input type="date" id="wplc-audit-to" name="to" value="' . esc_attr( $filters['to'] ) . '"> ';
		submit_button( __( 'Filter', 'wp-license' ), '', 'filter_action', false );
		echo ' <a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Reset', 'wp-license' ) . '</a>';
		echo '</p>';
		echo '<p class="description">' . esc_html__( 'Object ID 0 shows system-wide entries (settings, catalog import, key rotation, bulk actions). License ID shows the license with its site activations and renewals.', 'wp-license' ) . '</p>';
		$table->display();
		echo '</form></div>';
	}

	/**
	 * Nonce-protected URL of the CSV export for a set of filters.
	 *
	 * @param array<string,string> $filters Filters from request_filters().
	 * @return string
	 */
	public static function export_url( array $filters ): string {
		$args = array( 'action' => self::EXPORT_ACTION );
		foreach ( $filters as $key => $value ) {
			if ( '' !== $value ) {
				$args[ 'action' === $key ? 'audit_action' : $key ] = $value;
			}
		}

		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), self::EXPORT_ACTION );
	}

	/**
	 * Stream the filtered audit log as CSV and record the export.
	 *
	 * @return void
	 */
	public static function handle_export(): void {
		Capabilities::require_cap( 'view_diagnostics', __( 'You do not have permission to view the audit log.', 'wp-license' ) );
		check_admin_referer( self::EXPORT_ACTION );

		$filters = self::request_filters();
		AuditLog::record( 'audit_log_exported', 'export', 0, '', null, array_filter( $filters, static function ( $value ): bool { return '' !== (string) $value; } ), get_current_user_id() );

		$handle = CsvExport::open_download( 'license-audit-' . gmdate( 'Y-m-d' ) . '.csv' );
		$result = self::write_csv( $filters, $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Output stream of a download.
		if ( $result['truncated'] ) {
			CsvExport::remember_truncated( get_current_user_id(), 'audit', self::EXPORT_LIMIT );
		}
		exit;
	}

	/**
	 * Build the CSV for a set of filters as a string (tests, small logs).
	 *
	 * @param array{license?:string,object_type:string,object_id:string,action:string,actor:string,from:string,to:string} $filters Filters.
	 * @param int                                                                                                           $limit   Row cap.
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
	 * Write the filtered audit log to a stream, newest first, one keyset batch at a time.
	 *
	 * Reasons and snapshots are redacted as on the screen.
	 *
	 * @param array{license?:string,object_type:string,object_id:string,action:string,actor:string,from:string,to:string} $filters Filters.
	 * @param resource                                                                                                      $handle  Output stream.
	 * @param int                                                                                                           $limit   Row cap.
	 * @return array{rows:int,truncated:bool}
	 */
	public static function write_csv( array $filters, $handle, int $limit = self::EXPORT_LIMIT ): array {
		global $wpdb;

		$table     = Schema::table_names()['audit_log'];
		$where     = self::where( $filters );
		$limit     = max( 1, $limit );
		$last      = PHP_INT_MAX;
		$total     = 0;
		$truncated = false;
		$redact    = static function ( $json ): string {
			if ( ! is_string( $json ) || '' === $json ) {
				return '';
			}
			$encoded = wp_json_encode( AuditLog::redact_for_display( json_decode( $json, true ) ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			return false === $encoded ? '' : $encoded;
		};
		CsvExport::write( $handle, array( 'id', 'created_at_utc', 'action', 'object_type', 'object_id', 'actor_user_id', 'actor_login', 'reason', 'before', 'after' ) );
		do {
			$batch_size = min( self::EXPORT_BATCH, $limit - $total + 1 );
			$sql        = "SELECT * FROM {$table}" . ( '' !== $where['sql'] ? $where['sql'] . ' AND' : ' WHERE' ) . ' id < %d ORDER BY id DESC LIMIT %d';
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Only the table name and fixed fragments with placeholders are interpolated.
			$rows   = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $where['params'], array( $last, $batch_size ) ) ), ARRAY_A );
			$rows   = is_array( $rows ) ? $rows : array();
			$batch  = count( $rows );
			$logins = CsvExport::user_field( array_column( $rows, 'actor_user_id' ), 'user_login' );
			foreach ( $rows as $row ) {
				if ( $total >= $limit ) {
					$truncated = true;
					break;
				}
				$last  = (int) $row['id'];
				$actor = (int) $row['actor_user_id'];
				CsvExport::write(
					$handle,
					array(
						$last,
						(string) $row['created_at'],
						(string) $row['action'],
						(string) $row['object_type'],
						(int) $row['object_id'],
						$actor,
						$logins[ $actor ] ?? '',
						AuditLog::redact_text_for_display( (string) $row['reason'] ),
						$redact( $row['before_json'] ),
						$redact( $row['after_json'] ),
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
	 * Escaped link to the object of an audit entry.
	 *
	 * @param string                                 $type       Object type.
	 * @param int                                    $object_id  Object ID.
	 * @param array<string,array<int,int>>           $license_of Object type => object ID => license ID (activations, renewals).
	 * @return string HTML.
	 */
	public static function object_html( string $type, int $object_id, array $license_of ): string {
		$labels = self::object_type_labels();
		$label  = isset( $labels[ $type ] ) ? $labels[ $type ] : $type;
		$text   = esc_html( $object_id > 0 ? $label . ' #' . $object_id : $label );

		$url = '';
		if ( 'license' === $type && $object_id > 0 ) {
			$url = LicenseAdmin::detail_url( $object_id );
		} elseif ( isset( $license_of[ $type ][ $object_id ] ) ) {
			$url = LicenseAdmin::detail_url( $license_of[ $type ][ $object_id ] );
		} elseif ( 'release' === $type ) {
			$url = admin_url( 'admin.php?page=' . ReleaseAdmin::PAGE );
		} elseif ( 'settings' === $type ) {
			$url = admin_url( 'admin.php?page=' . Settings::PAGE );
		} elseif ( 'catalog' === $type ) {
			$url = admin_url( 'admin.php?page=wplc-catalog-csv' );
		}
		if ( '' === $url ) {
			return $text;
		}
		$out = '<a href="' . esc_url( $url ) . '">' . $text . '</a>';
		if ( isset( $license_of[ $type ][ $object_id ] ) ) {
			/* translators: %d: license ID. */
			$out .= '<br><span class="description">' . esc_html( sprintf( __( 'License #%d', 'wp-license' ), $license_of[ $type ][ $object_id ] ) ) . '</span>';
		}

		return $out;
	}

	/**
	 * Translated, redacted reason of an entry.
	 *
	 * @param string $stored Stored reason.
	 * @return string Plain text.
	 */
	public static function reason_text( string $stored ): string {
		return AuditLog::redact_text_for_display( AuditReason::label( $stored ) );
	}

	/**
	 * Decoded, redacted snapshot of an entry, pretty-printed; '' when none.
	 *
	 * @param mixed $json Stored JSON or null.
	 * @return string Plain text.
	 */
	public static function snapshot_text( $json ): string {
		if ( ! is_string( $json ) || '' === $json ) {
			return '';
		}
		$decoded = json_decode( $json, true );
		if ( null === $decoded && 'null' !== trim( $json ) ) {
			return __( '(unreadable)', 'wp-license' );
		}
		$pretty = wp_json_encode( AuditLog::redact_for_display( $decoded ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return false === $pretty ? '' : $pretty;
	}

	/**
	 * Redacted "field: old → new" summary.
	 *
	 * @param mixed $before Stored JSON or null.
	 * @param mixed $after  Stored JSON or null.
	 * @return string Plain text.
	 */
	public static function change_text( $before, $after ): string {
		$redact = static function ( $json ) {
			if ( ! is_string( $json ) ) {
				return null;
			}
			$encoded = wp_json_encode( AuditLog::redact_for_display( json_decode( $json, true ) ) );
			return false === $encoded ? null : $encoded;
		};

		return LicenseDetail::change_summary( $redact( $before ), $redact( $after ) );
	}
}
