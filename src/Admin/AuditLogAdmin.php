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
 * redaction of AuditLog (applied again at display time), never raw.
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
	 * Register the menu entry and assets.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		// After "Licenses" (10) and "Renewals to review" (11).
		add_action( 'admin_menu', array( self::class, 'register_menu' ), 12 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
	}

	/**
	 * Add the WooCommerce submenu page.
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'License audit log', 'wp-license' ),
			__( 'License audit log', 'wp-license' ),
			'manage_woocommerce',
			self::PAGE,
			array( self::class, 'render' )
		);
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
	 * @return array{object_type:string,object_id:string,action:string,actor:string,from:string,to:string}
	 */
	public static function request_filters(): array {
		$read = static function ( string $key ): string {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filters.
			return isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? trim( sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) ) : '';
		};

		$object_id = $read( 'object_id' );
		$from      = $read( 'from' );
		$to        = $read( 'to' );

		return array(
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
	 * actor actor_history.
	 *
	 * @param array{object_type:string,object_id:string,action:string,actor:string,from:string,to:string} $filters Filters.
	 * @return array{sql:string,params:array<int,mixed>}
	 */
	public static function where( array $filters ): array {
		$conditions = array();
		$params     = array();

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
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to view licenses.', 'wp-license' ), '', array( 'response' => 403 ) );
		}
		if ( ! class_exists( '\\WP_List_Table' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		}

		$filters = self::request_filters();
		$table   = new AuditLogTable();
		$table->prepare_items();

		echo '<div class="wrap wplc-audit-log">';
		echo '<h1>' . esc_html__( 'License audit log', 'wp-license' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Every recorded action on licenses, activations, renewals, releases, settings and keys, newest first. Read-only. Sensitive values are redacted.', 'wp-license' ) . '</p>';

		echo '<form method="get" class="wplc-audit-filters">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '">';
		echo '<p>';
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
		echo '<p class="description">' . esc_html__( 'Object ID 0 shows system-wide entries (settings, catalog import, key rotation, bulk actions).', 'wp-license' ) . '</p>';
		$table->display();
		echo '</form></div>';
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
