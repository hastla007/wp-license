<?php
/**
 * Administrator license detail screen and manual license form.
 *
 * @package WPLC
 */

namespace WPLC\Admin;

use WPLC\Billing\Money;
use WPLC\Infrastructure\Database\Schema;
use WPLC\License\LicenseLifecycle;
use WPLC\License\LicenseStateMachine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders one license with its activations, renewals, entitlements and audit trail.
 *
 * Capability checks happen in LicenseAdmin::render(); the action handlers live in LicenseAdmin.
 */
final class LicenseDetail {

	/**
	 * Number of audit entries shown.
	 */
	public const AUDIT_LIMIT = 100;

	/**
	 * Render the detail screen.
	 *
	 * @param int $license_id License ID.
	 * @return void
	 */
	public static function render( int $license_id ): void {
		global $wpdb;

		$tables  = Schema::table_names();
		$license = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$tables['licenses']} WHERE id = %d LIMIT 1", $license_id ),
			ARRAY_A
		);

		echo '<div class="wrap wplc-license-detail">';
		if ( ! is_array( $license ) ) {
			echo '<h1>' . esc_html__( 'License', 'wp-license' ) . '</h1>';
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'The license was not found.', 'wp-license' ) . '</p></div>';
			echo '<p><a href="' . esc_url( LicenseAdmin::list_url() ) . '">' . esc_html__( 'Back to all licenses', 'wp-license' ) . '</a></p></div>';
			return;
		}

		$activations = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$tables['activations']} WHERE license_id = %d ORDER BY status ASC, activated_at DESC LIMIT 500", $license_id ),
			ARRAY_A
		);
		$renewals    = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$tables['renewals']} WHERE license_id = %d ORDER BY period_start DESC, id DESC LIMIT 200", $license_id ),
			ARRAY_A
		);
		$entitlements = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$tables['entitlements']} WHERE license_id = %d ORDER BY entitlement_key ASC", $license_id ),
			ARRAY_A
		);
		$display_name = $wpdb->get_var(
			$wpdb->prepare( "SELECT display_name FROM {$tables['products']} WHERE product_key = %s LIMIT 1", (string) $license['product_key'] )
		);
		$activations  = is_array( $activations ) ? $activations : array();
		$renewals     = is_array( $renewals ) ? $renewals : array();
		$entitlements = is_array( $entitlements ) ? $entitlements : array();

		echo '<h1 class="wp-heading-inline">';
		/* translators: 1: license key prefix, 2: license ID. */
		echo esc_html( sprintf( __( 'License %1$s (#%2$d)', 'wp-license' ), (string) $license['key_prefix'], $license_id ) );
		echo '</h1> <a href="' . esc_url( LicenseAdmin::list_url() ) . '" class="page-title-action">' . esc_html__( 'All licenses', 'wp-license' ) . '</a>';
		echo '<hr class="wp-header-end">';

		self::render_summary( $license, is_string( $display_name ) ? $display_name : '', $activations, $entitlements );
		self::render_actions( $license );
		self::render_activations( $activations );
		self::render_renewals( $renewals );
		self::render_entitlements( $entitlements );
		self::render_audit_log( $license_id, $activations, $renewals );
		echo '</div>';
	}

	/**
	 * License fields.
	 *
	 * @param array<string,mixed>            $license      License row.
	 * @param string                         $display_name Product display name.
	 * @param array<int,array<string,mixed>> $activations  Activation rows.
	 * @param array<int,array<string,mixed>> $entitlements Entitlement rows.
	 * @return void
	 */
	private static function render_summary( array $license, string $display_name, array $activations, array $entitlements ): void {
		$live_sites = 0;
		foreach ( $activations as $activation ) {
			if ( 'active' === $activation['status'] && 'production' === $activation['environment'] ) {
				++$live_sites;
			}
		}
		$limit = '—';
		foreach ( $entitlements as $entitlement ) {
			if ( 'sites.live_limit' === $entitlement['entitlement_key'] ) {
				$decoded = json_decode( (string) $entitlement['value_json'], true );
				$limit   = is_numeric( $decoded ) ? (string) (int) $decoded : $limit;
			}
		}

		$status = Format::license_status_label( (string) $license['status'] );
		if ( '' !== (string) $license['status_reason'] ) {
			$status .= ' — ' . (string) $license['status_reason'];
		}

		$renewal = Format::renewal_mode_label( $license );
		if ( ! empty( $license['renewal_enabled'] ) ) {
			$amount   = Money::to_decimal( (int) $license['renewal_amount_minor'], (int) $license['renewal_currency_decimals'] );
			$renewal .= ' — ' . $amount . ' ' . (string) $license['renewal_currency'];
			if ( '' !== (string) $license['payment_gateway_id'] ) {
				$renewal .= ' (' . (string) $license['payment_gateway_id'] . ')';
			}
		}

		$order_html = '—';
		$order_id   = (int) $license['source_order_id'];
		if ( $order_id ) {
			$order_html = self::order_link( $order_id );
		} elseif ( empty( $license['source_order_item_id'] ) ) {
			$order_html = esc_html__( 'None (added manually)', 'wp-license' );
		}

		$rows = array(
			array( __( 'Customer', 'wp-license' ), LicenseAdmin::customer_html( (int) $license['customer_id'] ), true ),
			array( __( 'Product', 'wp-license' ), '' !== $display_name ? $display_name . ' (' . (string) $license['product_key'] . ')' : (string) $license['product_key'] ),
			array( __( 'Plan', 'wp-license' ), (string) $license['plan_key'] ),
			array( __( 'Status', 'wp-license' ), $status ),
			array( __( 'Billing interval', 'wp-license' ), 'month' === $license['billing_interval'] ? __( 'Monthly', 'wp-license' ) : __( 'Yearly', 'wp-license' ) ),
			array( __( 'Started', 'wp-license' ), Format::date( $license['starts_at'], true ) ),
			array( __( 'Paid until', 'wp-license' ), Format::date( $license['paid_until'], true ) ),
			array( __( 'Access right now', 'wp-license' ), LicenseLifecycle::has_access( $license ) ? __( 'Yes', 'wp-license' ) : __( 'No', 'wp-license' ) ),
			array( __( 'Renewal', 'wp-license' ), $renewal ),
			array( __( 'Next automatic payment', 'wp-license' ), ! empty( $license['renewal_enabled'] ) && empty( $license['cancel_at_period_end'] ) ? Format::date( $license['next_payment_at'], true ) : '—' ),
			/* translators: 1: active live sites, 2: allowed live sites. */
			array( __( 'Live sites', 'wp-license' ), sprintf( __( '%1$d / %2$s', 'wp-license' ), $live_sites, $limit ) ),
			array( __( 'Source order', 'wp-license' ), $order_html, true ),
			array( __( 'License UUID', 'wp-license' ), (string) $license['license_uuid'] ),
			array( __( 'Key secret ID', 'wp-license' ), (string) $license['license_key_secret_id'] ),
			array( __( 'Parent license', 'wp-license' ), $license['parent_license_id'] ? '#' . (int) $license['parent_license_id'] : '—' ),
			array( __( 'Revoked', 'wp-license' ), Format::date( $license['revoked_at'], true ) ),
			array( __( 'Created', 'wp-license' ), Format::date( $license['created_at'], true ) ),
			array( __( 'Last changed', 'wp-license' ), Format::date( $license['updated_at'], true ) ),
		);

		echo '<table class="widefat striped" role="presentation"><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><th scope="row">' . esc_html( $row[0] ) . '</th><td>';
			// Cells flagged as HTML are built from escaped parts above.
			echo ! empty( $row[2] ) ? wp_kses_post( $row[1] ) : esc_html( $row[1] );
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'The full license key is not shown here. The customer finds it in their account under "Licenses".', 'wp-license' ) . '</p>';
	}

	/**
	 * Status and term actions. Every action requires a reason.
	 *
	 * @param array<string,mixed> $license License row.
	 * @return void
	 */
	private static function render_actions( array $license ): void {
		$license_id = (int) $license['id'];
		$status     = (string) $license['status'];

		echo '<h2>' . esc_html__( 'Actions', 'wp-license' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Every action needs a reason. It is stored in the audit log.', 'wp-license' ) . '</p>';

		$buttons = array(
			'active'    => array( __( 'Reactivate', 'wp-license' ), '' ),
			'suspended' => array( __( 'Suspend', 'wp-license' ), '' ),
			'revoked'   => array( __( 'Revoke', 'wp-license' ), __( 'Revoke this license permanently? This cannot be undone.', 'wp-license' ) ),
		);
		$period_ended = ! empty( $license['paid_until'] ) && (string) $license['paid_until'] <= gmdate( 'Y-m-d H:i:s' );
		$any          = false;
		foreach ( $buttons as $target => $button ) {
			if ( ! LicenseStateMachine::allows( $status, $target, 'admin_status_change' ) ) {
				continue;
			}
			if ( 'active' === $target && $period_ended ) {
				echo '<p>' . esc_html__( 'The paid period has ended. Change the term below to reactivate this license.', 'wp-license' ) . '</p>';
				continue;
			}
			$any     = true;
			$form_id = 'wplc-status-' . $target;
			echo '<form method="post" class="wplc-inline-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( '' !== $button[1] ? ' data-wplc-confirm="' . esc_attr( $button[1] ) . '"' : '' ) . '>';
			echo '<input type="hidden" name="action" value="wplc_admin_license_status">';
			echo '<input type="hidden" name="license_id" value="' . esc_attr( (string) $license_id ) . '">';
			echo '<input type="hidden" name="status" value="' . esc_attr( $target ) . '">';
			self::nonce_input( 'wplc_admin_license_status_' . $license_id );
			/* translators: %s: action name such as "Suspend". */
			echo '<label class="screen-reader-text" for="' . esc_attr( $form_id ) . '">' . esc_html( sprintf( __( 'Reason: %s', 'wp-license' ), $button[0] ) ) . '</label>';
			echo '<input type="text" id="' . esc_attr( $form_id ) . '" name="reason" required maxlength="255" placeholder="' . esc_attr__( 'Reason', 'wp-license' ) . '">';
			echo '<button class="button" type="submit">' . esc_html( $button[0] ) . '</button></form>';
		}
		if ( ! $any && 'revoked' === $status ) {
			echo '<p>' . esc_html__( 'A revoked license cannot be changed. Add a new license instead.', 'wp-license' ) . '</p>';
		}

		if ( 'revoked' === $status || 'pending' === $status ) {
			return;
		}

		echo '<h3>' . esc_html__( 'Change term', 'wp-license' ) . '</h3>';
		echo '<form method="post" class="wplc-inline-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="wplc_admin_extend_license">';
		echo '<input type="hidden" name="license_id" value="' . esc_attr( (string) $license_id ) . '">';
		self::nonce_input( 'wplc_admin_extend_license_' . $license_id );
		echo '<label for="wplc-extend-date">' . esc_html__( 'New end date', 'wp-license' ) . '</label>';
		echo '<input type="date" id="wplc-extend-date" name="paid_until" required min="' . esc_attr( wp_date( 'Y-m-d' ) ) . '" value="' . esc_attr( Format::utc_to_local_date( $license['paid_until'], wp_timezone() ) ) . '">';
		echo '<label for="wplc-extend-reason">' . esc_html__( 'Reason', 'wp-license' ) . '</label>';
		echo '<input type="text" id="wplc-extend-reason" name="reason" required maxlength="255">';
		echo '<button class="button button-primary" type="submit">' . esc_html__( 'Change term', 'wp-license' ) . '</button></form>';
		echo '<p class="description">';
		/* translators: %s: site timezone, for example Europe/Berlin. */
		echo esc_html( sprintf( __( 'The license runs until the end of that day in the site timezone (%s). No payment is taken. An expired license becomes active again.', 'wp-license' ), wp_timezone_string() ) );
		echo '</p>';
	}

	/**
	 * Site activations with a release action.
	 *
	 * @param array<int,array<string,mixed>> $activations Activation rows.
	 * @return void
	 */
	private static function render_activations( array $activations ): void {
		echo '<h2>' . esc_html__( 'Site activations', 'wp-license' ) . '</h2>';
		if ( ! $activations ) {
			echo '<p>' . esc_html__( 'No site has been activated with this license.', 'wp-license' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Site', 'wp-license' ), __( 'Environment', 'wp-license' ), __( 'Status', 'wp-license' ), __( 'Versions', 'wp-license' ), __( 'Activated', 'wp-license' ), __( 'Last seen', 'wp-license' ), __( 'Release', 'wp-license' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $activations as $activation ) {
			$activation_id = (int) $activation['id'];
			$environment   = Format::environment_label( (string) $activation['environment'] );
			if ( ! empty( $activation['staging_parent_id'] ) ) {
				/* translators: 1: environment label, 2: activation ID of the live site. */
				$environment = sprintf( __( '%1$s (of #%2$d)', 'wp-license' ), $environment, (int) $activation['staging_parent_id'] );
			}
			/* translators: 1: product version, 2: WordPress version, 3: PHP version. */
			$versions = sprintf( __( 'Plugin %1$s, WP %2$s, PHP %3$s', 'wp-license' ), (string) $activation['product_version'] ?: '—', (string) $activation['wp_version'] ?: '—', (string) $activation['php_version'] ?: '—' );

			echo '<tr><td>' . esc_html( (string) $activation['site_url'] ) . ' <span class="description">#' . esc_html( (string) $activation_id ) . '</span></td>';
			echo '<td>' . esc_html( $environment ) . '</td>';
			echo '<td>' . esc_html( Format::activation_status_label( (string) $activation['status'] ) ) . '</td>';
			echo '<td>' . esc_html( $versions ) . '</td>';
			echo '<td>' . esc_html( Format::date( $activation['activated_at'], true ) ) . '</td>';
			echo '<td>' . esc_html( Format::date( 'active' === $activation['status'] ? $activation['last_seen_at'] : $activation['deactivated_at'], true ) ) . '</td><td>';
			if ( 'active' === $activation['status'] ) {
				$field_id = 'wplc-release-' . $activation_id;
				echo '<form method="post" class="wplc-inline-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				echo '<input type="hidden" name="action" value="wplc_admin_release_activation">';
				echo '<input type="hidden" name="activation_id" value="' . esc_attr( (string) $activation_id ) . '">';
				self::nonce_input( 'wplc_admin_release_activation_' . $activation_id );
				/* translators: %s: site URL. */
				echo '<label class="screen-reader-text" for="' . esc_attr( $field_id ) . '">' . esc_html( sprintf( __( 'Reason for releasing %s', 'wp-license' ), (string) $activation['site_url'] ) ) . '</label>';
				echo '<input type="text" id="' . esc_attr( $field_id ) . '" name="reason" required maxlength="255" placeholder="' . esc_attr__( 'Reason', 'wp-license' ) . '">';
				echo '<button class="button" type="submit">' . esc_html__( 'Release', 'wp-license' ) . '</button></form>';
			} else {
				echo '—';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Renewal attempts with their orders.
	 *
	 * @param array<int,array<string,mixed>> $renewals Renewal rows.
	 * @return void
	 */
	private static function render_renewals( array $renewals ): void {
		echo '<h2>' . esc_html__( 'Renewals', 'wp-license' ) . '</h2>';
		if ( ! $renewals ) {
			echo '<p>' . esc_html__( 'No renewal has been scheduled or paid for this license.', 'wp-license' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Period', 'wp-license' ), __( 'Status', 'wp-license' ), __( 'Order', 'wp-license' ), __( 'Attempts', 'wp-license' ), __( 'Next attempt', 'wp-license' ), __( 'Grace ends', 'wp-license' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $renewals as $renewal ) {
			echo '<tr><td>' . esc_html( Format::date( $renewal['period_start'] ) . ' – ' . Format::date( $renewal['period_end'] ) ) . ' <span class="description">#' . esc_html( (string) (int) $renewal['id'] ) . '</span></td>';
			echo '<td>' . esc_html( Format::renewal_status_label( (string) $renewal['status'] ) ) . '</td>';
			echo '<td>' . ( $renewal['renewal_order_id'] ? wp_kses_post( self::order_link( (int) $renewal['renewal_order_id'] ) ) : '—' ) . '</td>';
			echo '<td>' . esc_html( (string) (int) $renewal['attempt_count'] ) . '</td>';
			echo '<td>' . esc_html( Format::date( $renewal['retry_at'], true ) ) . '</td>';
			echo '<td>' . esc_html( Format::date( $renewal['grace_ends_at'] ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Entitlement snapshot of the license.
	 *
	 * @param array<int,array<string,mixed>> $entitlements Entitlement rows.
	 * @return void
	 */
	private static function render_entitlements( array $entitlements ): void {
		echo '<h2>' . esc_html__( 'Entitlements', 'wp-license' ) . '</h2>';
		if ( ! $entitlements ) {
			echo '<p>' . esc_html__( 'This license has no entitlements.', 'wp-license' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Key', 'wp-license' ), __( 'Value', 'wp-license' ), __( 'Status', 'wp-license' ), __( 'Valid from', 'wp-license' ), __( 'Valid until', 'wp-license' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $entitlements as $entitlement ) {
			echo '<tr><td><code>' . esc_html( (string) $entitlement['entitlement_key'] ) . '</code></td>';
			echo '<td><code>' . esc_html( (string) $entitlement['value_json'] ) . '</code></td>';
			echo '<td>' . esc_html( Format::license_status_label( (string) $entitlement['status'] ) ) . '</td>';
			echo '<td>' . esc_html( Format::date( $entitlement['starts_at'] ) ) . '</td>';
			echo '<td>' . esc_html( Format::date( $entitlement['ends_at'] ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Latest audit entries of the license, its activations and its renewals.
	 *
	 * @param int                            $license_id  License ID.
	 * @param array<int,array<string,mixed>> $activations Activation rows.
	 * @param array<int,array<string,mixed>> $renewals    Renewal rows.
	 * @return void
	 */
	private static function render_audit_log( int $license_id, array $activations, array $renewals ): void {
		global $wpdb;

		$tables     = Schema::table_names();
		$conditions = array( '(object_type = %s AND object_id = %d)' );
		$params     = array( 'license', $license_id );
		foreach ( array(
			'activation' => $activations,
			'renewal'    => $renewals,
		) as $object_type => $rows ) {
			$ids = array_map( 'intval', array_column( $rows, 'id' ) );
			if ( $ids ) {
				$conditions[] = '(object_type = %s AND object_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . '))';
				$params       = array_merge( $params, array( $object_type ), $ids );
			}
		}
		$params[] = self::AUDIT_LIMIT;
		$sql      = "SELECT * FROM {$tables['audit_log']} WHERE " . implode( ' OR ', $conditions ) . ' ORDER BY created_at DESC, id DESC LIMIT %d';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Only table name and generated placeholders are interpolated.
		$entries = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		$entries = is_array( $entries ) ? $entries : array();

		echo '<h2>' . esc_html__( 'Audit log', 'wp-license' ) . '</h2>';
		if ( ! $entries ) {
			echo '<p>' . esc_html__( 'No audit entries exist for this license.', 'wp-license' ) . '</p>';
			return;
		}

		$actor_ids = array_values( array_unique( array_filter( array_map( 'intval', array_column( $entries, 'actor_user_id' ) ) ) ) );
		if ( $actor_ids && function_exists( 'cache_users' ) ) {
			cache_users( $actor_ids );
		}
		$object_labels = array(
			'license'    => __( 'License', 'wp-license' ),
			'activation' => __( 'Activation', 'wp-license' ),
			'renewal'    => __( 'Renewal', 'wp-license' ),
		);

		/* translators: %d: maximum number of entries. */
		echo '<p class="description">' . esc_html( sprintf( __( 'Latest %d entries, newest first.', 'wp-license' ), self::AUDIT_LIMIT ) ) . '</p>';
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Date', 'wp-license' ), __( 'Action', 'wp-license' ), __( 'Object', 'wp-license' ), __( 'By', 'wp-license' ), __( 'Reason', 'wp-license' ), __( 'Change', 'wp-license' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $entries as $entry ) {
			$actor_id = (int) $entry['actor_user_id'];
			$actor    = $actor_id ? get_userdata( $actor_id ) : false;
			$type     = (string) $entry['object_type'];
			if ( $actor ) {
				$actor_name = (string) $actor->display_name;
			} elseif ( $actor_id ) {
				/* translators: %d: user ID. */
				$actor_name = sprintf( __( 'Deleted user #%d', 'wp-license' ), $actor_id );
			} else {
				$actor_name = __( 'System', 'wp-license' );
			}

			echo '<tr><td>' . esc_html( Format::date( $entry['created_at'], true ) ) . '</td>';
			echo '<td><code>' . esc_html( (string) $entry['action'] ) . '</code></td>';
			echo '<td>' . esc_html( ( isset( $object_labels[ $type ] ) ? $object_labels[ $type ] : $type ) . ' #' . (int) $entry['object_id'] ) . '</td>';
			echo '<td>' . esc_html( $actor_name ) . '</td>';
			echo '<td>' . esc_html( (string) $entry['reason'] ) . '</td>';
			echo '<td>' . esc_html( self::change_summary( $entry['before_json'], $entry['after_json'] ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Print nonce and referer fields without id attributes (several forms share one page).
	 *
	 * @param string $action Nonce action.
	 * @return void
	 */
	private static function nonce_input( string $action ): void {
		echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( $action ) ) . '">';
		// The referer brings the administrator back to this screen with the result notice.
		echo '<input type="hidden" name="_wp_http_referer" value="' . esc_attr( wp_unslash( isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '' ) ) . '">'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Escaped; validated before use in AdminNotices.
	}

	/**
	 * Summarize the before/after snapshots of an audit entry as "field: old → new".
	 *
	 * @param mixed $before_json Stored JSON or null.
	 * @param mixed $after_json  Stored JSON or null.
	 * @return string
	 */
	public static function change_summary( $before_json, $after_json ): string {
		$before = is_string( $before_json ) ? json_decode( $before_json, true ) : null;
		$after  = is_string( $after_json ) ? json_decode( $after_json, true ) : null;
		$before = is_array( $before ) ? $before : array();
		$after  = is_array( $after ) ? $after : array();

		$parts = array();
		foreach ( array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ) as $field ) {
			$old = array_key_exists( $field, $before ) ? self::scalar_text( $before[ $field ] ) : null;
			$new = array_key_exists( $field, $after ) ? self::scalar_text( $after[ $field ] ) : null;
			if ( $old === $new ) {
				continue;
			}
			if ( null === $old ) {
				$parts[] = $field . ': ' . $new;
			} elseif ( null === $new ) {
				$parts[] = $field . ': ' . $old;
			} else {
				$parts[] = $field . ': ' . $old . ' → ' . $new;
			}
		}

		return $parts ? implode( '; ', $parts ) : '—';
	}

	/**
	 * Text for one snapshot value.
	 *
	 * @param mixed $value Decoded JSON value.
	 * @return string
	 */
	private static function scalar_text( $value ): string {
		if ( null === $value ) {
			return '∅';
		}
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}
		$json = json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure helper.
		return false === $json ? '' : $json;
	}

	/**
	 * Escaped link to a WooCommerce order (HPOS-safe).
	 *
	 * @param int $order_id Order ID.
	 * @return string HTML.
	 */
	private static function order_link( int $order_id ): string {
		/* translators: %d: order ID. */
		$label = esc_html( sprintf( __( 'Order #%d', 'wp-license' ), $order_id ) );
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		if ( ! $order || ! is_callable( array( $order, 'get_edit_order_url' ) ) ) {
			return $label . ' ' . esc_html__( '(not found)', 'wp-license' );
		}

		$status = is_callable( array( $order, 'get_status' ) ) && function_exists( 'wc_get_order_status_name' )
			? ' — ' . esc_html( wc_get_order_status_name( (string) $order->get_status() ) )
			: '';

		return '<a href="' . esc_url( $order->get_edit_order_url() ) . '">' . $label . '</a>' . $status;
	}

	/**
	 * Render the "Add license manually" form.
	 *
	 * @return void
	 */
	public static function render_add_form(): void {
		global $wpdb;

		$tables = Schema::table_names();
		$plans  = $wpdb->get_results(
			"SELECT p.id, p.product_key, p.plan_key, p.billing_interval, p.live_site_limit, pr.display_name FROM {$tables['plans']} p LEFT JOIN {$tables['products']} pr ON pr.product_key = p.product_key WHERE p.active = 1 ORDER BY p.product_key ASC, p.live_site_limit ASC, p.plan_key ASC LIMIT 500",
			ARRAY_A
		);
		$plans  = is_array( $plans ) ? $plans : array();

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Add license manually', 'wp-license' ) . '</h1> ';
		echo '<a href="' . esc_url( LicenseAdmin::list_url() ) . '" class="page-title-action">' . esc_html__( 'All licenses', 'wp-license' ) . '</a>';
		echo '<hr class="wp-header-end">';
		echo '<p>' . esc_html__( 'Use this for goodwill or migrated customers. No order is created and nothing is charged. The license does not renew automatically; the customer can renew it in their account.', 'wp-license' ) . '</p>';

		if ( ! $plans ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'There is no active plan in the catalog yet. Mark a WooCommerce product as a license product first.', 'wp-license' ) . '</p></div></div>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="wplc_admin_add_license">';
		wp_nonce_field( 'wplc_admin_add_license' );
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="wplc-add-customer">' . esc_html__( 'Customer', 'wp-license' ) . '</label></th><td>';
		echo '<input type="text" id="wplc-add-customer" name="customer" class="regular-text" required aria-describedby="wplc-add-customer-help">';
		echo '<p class="description" id="wplc-add-customer-help">' . esc_html__( 'User ID or email address of an existing user account.', 'wp-license' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="wplc-add-plan">' . esc_html__( 'Plan', 'wp-license' ) . '</label></th><td>';
		echo '<select id="wplc-add-plan" name="plan_id" required><option value="">' . esc_html__( 'Select a plan', 'wp-license' ) . '</option>';
		foreach ( $plans as $plan ) {
			$name = '' !== (string) $plan['display_name'] ? (string) $plan['display_name'] : (string) $plan['product_key'];
			$text = sprintf(
				/* translators: 1: product name, 2: plan key, 3: billing interval, 4: number of live sites. */
				__( '%1$s — %2$s (%3$s, %4$d live sites)', 'wp-license' ),
				$name,
				(string) $plan['plan_key'],
				'month' === $plan['billing_interval'] ? __( 'monthly', 'wp-license' ) : __( 'yearly', 'wp-license' ),
				(int) $plan['live_site_limit']
			);
			echo '<option value="' . esc_attr( (string) (int) $plan['id'] ) . '">' . esc_html( $text ) . '</option>';
		}
		echo '</select></td></tr>';

		$default_end = ( new \DateTimeImmutable( 'now', wp_timezone() ) )->modify( '+1 year' )->format( 'Y-m-d' );
		echo '<tr><th scope="row"><label for="wplc-add-paid-until">' . esc_html__( 'Paid until', 'wp-license' ) . '</label></th><td>';
		echo '<input type="date" id="wplc-add-paid-until" name="paid_until" required min="' . esc_attr( wp_date( 'Y-m-d' ) ) . '" value="' . esc_attr( $default_end ) . '" aria-describedby="wplc-add-paid-until-help">';
		echo '<p class="description" id="wplc-add-paid-until-help">';
		/* translators: %s: site timezone, for example Europe/Berlin. */
		echo esc_html( sprintf( __( 'The license runs until the end of that day in the site timezone (%s).', 'wp-license' ), wp_timezone_string() ) );
		echo '</p></td></tr>';

		echo '<tr><th scope="row"><label for="wplc-add-reason">' . esc_html__( 'Reason', 'wp-license' ) . '</label></th><td>';
		echo '<input type="text" id="wplc-add-reason" name="reason" class="regular-text" required maxlength="255" aria-describedby="wplc-add-reason-help">';
		echo '<p class="description" id="wplc-add-reason-help">' . esc_html__( 'Stored in the audit log. Do not enter license keys or passwords.', 'wp-license' ) . '</p></td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Add license', 'wp-license' ) );
		echo '</form></div>';
	}
}
