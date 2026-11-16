<?php
/**
 * Bulk actions of the administrator license list (T-102).
 *
 * @package WPLC
 */

namespace WPLC\Admin;

use WPLC\Infrastructure\Database\Schema;
use WPLC\License\ActivationManager;
use WPLC\License\AuditLog;
use WPLC\License\LicenseLifecycle;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs one administrator action on several selected licenses.
 *
 * Every license goes through the same lifecycle path as the single action on
 * the detail screen (LicenseLifecycle::admin_set_status() under the license
 * lock, ActivationManager under the activation lock), so the state machine,
 * the per-license audit entries and the status hooks are unchanged. One reason
 * applies to the whole batch. Licenses the action does not apply to are
 * skipped with a reason code and reported per row.
 *
 * A batch is identified by a random token rendered into the form. The handler
 * holds a named lock for the token and stores the result under it, so a
 * double submit (second click, browser resubmit) does not run the batch again
 * but shows the stored result.
 */
final class LicenseBulkActions {

	/**
	 * admin-post action.
	 */
	public const ACTION = 'wplc_admin_license_bulk';

	/**
	 * Query argument carrying the batch token after the redirect.
	 */
	public const QUERY_ARG = 'wplc_bulk';

	/**
	 * Most licenses one batch may contain.
	 */
	public const MAX_LICENSES = 100;

	/**
	 * Seconds a stored batch result is kept (and a token is refused again).
	 */
	public const RESULT_TTL = 3600;

	/**
	 * Transient name prefix of stored results.
	 */
	private const PREFIX = 'wplc_lbulk_';

	/**
	 * Audit action of the per-license entry of a bulk site release.
	 */
	public const AUDIT_SITES_RELEASED = 'admin_sites_released';

	/**
	 * Audit action of the summary entry of one batch (object license #0).
	 */
	public const AUDIT_BATCH = 'admin_bulk_action';

	/**
	 * Register the handler.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
	}

	/**
	 * Bulk actions offered in the list, action key => label.
	 *
	 * Reactivation is offered because it follows the same rules as on the
	 * detail screen (only from past due, suspended or expired, and only while
	 * the paid period has not ended). Extending the term by a number of days
	 * (A-61) uses SupportActions::bulk_extend() and is offered to users who may
	 * grant licenses. Issuing licenses is not available in bulk.
	 *
	 * @return array<string,string>
	 */
	public static function actions(): array {
		$actions = array(
			'suspend'       => __( 'Suspend', 'wp-license' ),
			'reactivate'    => __( 'Reactivate', 'wp-license' ),
			'revoke'        => __( 'Revoke (final)', 'wp-license' ),
			'release_sites' => __( 'Release all site activations', 'wp-license' ),
		);
		if ( Capabilities::can( 'grant_licenses' ) ) {
			$actions['extend'] = __( 'Extend the term by the number of days', 'wp-license' );
		}

		return $actions;
	}

	/**
	 * Target status of the status actions.
	 *
	 * @return array<string,string>
	 */
	private static function target_statuses(): array {
		return array(
			'suspend'    => 'suspended',
			'reactivate' => 'active',
			'revoke'     => 'revoked',
		);
	}

	/**
	 * Translated explanation of a skip reason code.
	 *
	 * @param string $code Reason code.
	 * @return string
	 */
	public static function skip_label( string $code ): string {
		$labels = array(
			'not_found'              => __( 'The license was not found.', 'wp-license' ),
			'same_status'            => __( 'The license already has this status.', 'wp-license' ),
			'transition_not_allowed' => __( 'This change is not allowed from the current status.', 'wp-license' ),
			'paid_period_ended'      => __( 'The paid period has ended. Change the term on the license screen first.', 'wp-license' ),
			'not_applied'            => __( 'Not applied: the license changed in the meantime or is being processed. Try again.', 'wp-license' ),
			'no_active_sites'        => __( 'The license has no active site activations.', 'wp-license' ),
			'release_incomplete'     => __( 'Not every site activation could be released. Try again.', 'wp-license' ),
			'busy'                   => __( 'The license is being processed right now. Try again in a minute.', 'wp-license' ),
		);

		return isset( $labels[ $code ] ) ? $labels[ $code ] : $code;
	}

	/**
	 * A new random batch token for the form.
	 *
	 * @return string
	 */
	public static function new_token(): string {
		return bin2hex( random_bytes( 12 ) );
	}

	/**
	 * Whether a string has the shape of a batch token.
	 *
	 * @param string $token Candidate.
	 * @return bool
	 */
	public static function is_token( string $token ): bool {
		return 1 === preg_match( '/^[0-9a-f]{24}$/', $token );
	}

	/**
	 * Transient name of one stored result.
	 *
	 * @param int    $user_id User ID.
	 * @param string $token   Batch token.
	 * @return string
	 */
	public static function transient_name( int $user_id, string $token ): string {
		return self::PREFIX . $user_id . '_' . $token;
	}

	/**
	 * Stored result of a batch of the given user.
	 *
	 * @param int    $user_id User ID.
	 * @param string $token   Batch token.
	 * @return array{action:string,done:int[],skipped:array<int,array{id:int,code:string,status:string}>}|null
	 */
	public static function load_result( int $user_id, string $token ): ?array {
		if ( $user_id <= 0 || ! self::is_token( $token ) ) {
			return null;
		}
		$stored = get_transient( self::transient_name( $user_id, $token ) );
		if ( ! is_array( $stored ) || ! isset( $stored['action'], $stored['done'], $stored['skipped'] ) || ! is_array( $stored['done'] ) || ! is_array( $stored['skipped'] ) ) {
			return null;
		}
		$skipped = array();
		foreach ( $stored['skipped'] as $row ) {
			if ( is_array( $row ) && isset( $row['id'], $row['code'] ) ) {
				$skipped[] = array(
					'id'     => (int) $row['id'],
					'code'   => (string) $row['code'],
					'status' => isset( $row['status'] ) ? (string) $row['status'] : '',
				);
			}
		}

		return array(
			'action'  => (string) $stored['action'],
			'done'    => array_map( 'intval', $stored['done'] ),
			'skipped' => $skipped,
		);
	}

	/**
	 * Handle a POSTed batch: capability, nonce, input, then one lifecycle call per license.
	 *
	 * @return void
	 */
	public static function handle(): void {
		Capabilities::require_cap( 'manage_licenses', __( 'You do not have permission to change licenses.', 'wp-license' ) );
		check_admin_referer( self::ACTION );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Every field is sanitized below.
		$post    = wp_unslash( $_POST );
		$return  = self::return_url( $post );
		$action  = isset( $post['bulk_action'] ) && is_string( $post['bulk_action'] ) ? sanitize_key( $post['bulk_action'] ) : '';
		$reason  = isset( $post['bulk_reason'] ) && is_string( $post['bulk_reason'] ) ? trim( sanitize_textarea_field( $post['bulk_reason'] ) ) : '';
		$token   = isset( $post['bulk_token'] ) && is_string( $post['bulk_token'] ) ? sanitize_key( $post['bulk_token'] ) : '';
		$raw_ids = isset( $post['license_ids'] ) && is_array( $post['license_ids'] ) ? $post['license_ids'] : array();
		$ids     = array_values( array_unique( array_filter( array_map( 'absint', $raw_ids ) ) ) );
		$days    = isset( $post['bulk_days'] ) && is_scalar( $post['bulk_days'] ) ? absint( $post['bulk_days'] ) : 0;

		if ( ! isset( self::actions()[ $action ] ) || '' === $reason || ! $ids || ! self::is_token( $token ) || ( 'extend' === $action && ( $days < 1 || $days > 3650 ) ) ) {
			AdminNotices::redirect_to( 'bulk_missing_input', $return );
		}
		if ( count( $ids ) > self::MAX_LICENSES ) {
			AdminNotices::redirect_to( 'bulk_too_many', $return );
		}

		$user_id = get_current_user_id();
		$outcome = self::run_batch( $user_id, $token, $action, $ids, $reason, $days );
		if ( 'busy' === $outcome ) {
			AdminNotices::redirect_to( 'bulk_in_progress', $return );
		}

		$target = add_query_arg( self::QUERY_ARG, $token, $return );
		AdminNotices::redirect_to( 'repeated' === $outcome ? 'bulk_already_done' : 'bulk_done', $target );
	}

	/**
	 * Run a batch once per token.
	 *
	 * @param int    $user_id Acting administrator.
	 * @param string $token   Batch token from the form.
	 * @param string $action  Bulk action key.
	 * @param int[]  $ids     License IDs.
	 * @param string $reason  Reason for every license.
	 * @param int    $days    Days to add for the action `extend`.
	 * @return string done, repeated (token already used: stored result kept) or busy (the same batch is running).
	 */
	public static function run_batch( int $user_id, string $token, string $action, array $ids, string $reason, int $days = 0 ): string {
		global $wpdb;

		$lock   = 'wplc_lbulk_' . substr( hash( 'sha256', Schema::lock_scope() . '|bulk:' . $user_id . ':' . $token ), 0, 48 );
		$locked = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, 0 ) );
		if ( '1' !== (string) $locked ) {
			return 'busy';
		}

		try {
			if ( null !== self::load_result( $user_id, $token ) ) {
				return 'repeated';
			}
			$result = self::apply( $action, $ids, $reason, $user_id, $days );
			set_transient( self::transient_name( $user_id, $token ), $result, self::RESULT_TTL );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}

		return 'done';
	}

	/**
	 * Apply an action to every license and collect the per-license result.
	 *
	 * @param string $action  Bulk action key.
	 * @param int[]  $ids     License IDs.
	 * @param string $reason  Reason for every license.
	 * @param int    $user_id Acting administrator.
	 * @param int    $days    Days to add for the action `extend`.
	 * @return array{action:string,done:int[],skipped:array<int,array{id:int,code:string,status:string}>}
	 */
	public static function apply( string $action, array $ids, string $reason, int $user_id, int $days = 0 ): array {
		$done    = array();
		$skipped = array();
		foreach ( $ids as $license_id ) {
			$license = self::license( (int) $license_id );
			if ( null === $license ) {
				$code = 'not_found';
			} elseif ( 'release_sites' === $action ) {
				$code = self::release_sites( $license, $reason, $user_id );
			} elseif ( 'extend' === $action ) {
				$code = 1 === SupportActions::bulk_extend( array( (int) $license_id ), $days, $reason, $user_id )['extended'] ? '' : 'not_applied';
			} else {
				$code = self::change_status( $license, self::target_statuses()[ $action ], $reason, $user_id );
			}

			if ( '' === $code ) {
				$done[] = (int) $license_id;
			} else {
				$skipped[] = array(
					'id'     => (int) $license_id,
					'code'   => $code,
					'status' => null !== $license ? (string) $license['status'] : '',
				);
			}
		}

		AuditLog::record(
			self::AUDIT_BATCH,
			'license',
			0,
			$reason,
			null,
			array(
				'bulk_action' => $action,
				'done'        => $done,
				'skipped'     => array_map(
					static function ( array $row ): string {
						return $row['id'] . ':' . $row['code'];
					},
					$skipped
				),
			),
			$user_id
		);

		return array(
			'action'  => $action,
			'done'    => $done,
			'skipped' => $skipped,
		);
	}

	/**
	 * Change the status of one license through the lifecycle.
	 *
	 * @param array<string,mixed> $license License row.
	 * @param string              $status  Target status.
	 * @param string              $reason  Reason.
	 * @param int                 $user_id Acting administrator.
	 * @return string '' when applied, otherwise a skip reason code.
	 */
	private static function change_status( array $license, string $status, string $reason, int $user_id ): string {
		$blocker = LicenseLifecycle::admin_status_blocker( $license, $status );
		if ( '' !== $blocker ) {
			return $blocker;
		}

		return LicenseLifecycle::admin_set_status( (int) $license['id'], $status, $reason, $user_id ) ? '' : 'not_applied';
	}

	/**
	 * Release every active site activation of one license.
	 *
	 * Live sites first: releasing a live site also releases its staging sites
	 * (ActivationManager), so those are not counted twice. Every activation
	 * gets its own audit entry; the license gets one summary entry.
	 *
	 * @param array<string,mixed> $license License row.
	 * @param string              $reason  Reason.
	 * @param int                 $user_id Acting administrator.
	 * @return string '' when every active activation was released, otherwise a skip reason code.
	 */
	private static function release_sites( array $license, string $reason, int $user_id ): string {
		global $wpdb;

		$license_id = (int) $license['id'];
		$tables     = Schema::table_names();
		$lock       = ActivationManager::lock_name( $license_id );
		$locked     = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, 5 ) );
		if ( '1' !== (string) $locked ) {
			return 'busy';
		}

		// Site events raised under the activation lock fire after it is released.
		\WPLC\Events\AfterCommit::hold();
		try {
			$ids = array_map(
				'intval',
				(array) $wpdb->get_col(
					$wpdb->prepare(
						"SELECT id FROM {$tables['activations']} WHERE license_id = %d AND status = %s ORDER BY staging_parent_id IS NOT NULL, id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names(), values prepared.
						$license_id,
						'active'
					)
				)
			);
			if ( ! $ids ) {
				return 'no_active_sites';
			}
			foreach ( $ids as $activation_id ) {
				ActivationManager::deactivate_while_locked( $activation_id, $reason, $user_id, 'admin_site_released' );
			}
			$left = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$tables['activations']} WHERE license_id = %d AND status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names(), values prepared.
					$license_id,
					'active'
				)
			);
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
			\WPLC\Events\AfterCommit::release();
		}

		AuditLog::record(
			self::AUDIT_SITES_RELEASED,
			'license',
			$license_id,
			$reason,
			array( 'active_sites' => count( $ids ) ),
			array( 'active_sites' => $left ),
			$user_id
		);

		return 0 === $left ? '' : 'release_incomplete';
	}

	/**
	 * Fresh license row.
	 *
	 * @param int $license_id License ID.
	 * @return array<string,mixed>|null
	 */
	private static function license( int $license_id ): ?array {
		global $wpdb;

		$tables = Schema::table_names();
		$row    = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$tables['licenses']} WHERE id = %d LIMIT 1", $license_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names(), values prepared.
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * The list URL the batch was started from: its filters, sort order, page and search token.
	 *
	 * @param array<string,mixed> $post Unslashed POST fields.
	 * @return string
	 */
	public static function return_url( array $post ): string {
		$args = array( 'page' => LicenseAdmin::PAGE );
		foreach ( array_merge( LicenseListTable::FILTER_ARGS, array( 'orderby', 'order', LicenseSearchStore::QUERY_ARG ) ) as $field ) {
			$value = isset( $post[ 'return_' . $field ] ) && is_string( $post[ 'return_' . $field ] ) ? sanitize_key( $post[ 'return_' . $field ] ) : '';
			if ( '' !== $value ) {
				$args[ $field ] = $value;
			}
		}
		$paged = isset( $post['return_paged'] ) && is_scalar( $post['return_paged'] ) ? absint( $post['return_paged'] ) : 0;
		if ( $paged > 1 ) {
			$args['paged'] = $paged;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Render the form (outside the filter form) and the controls bound to it.
	 *
	 * The checkboxes of the table and the controls carry form="wplc-bulk-form",
	 * so they belong to this POST form although they sit inside the GET filter
	 * form (forms cannot be nested).
	 *
	 * @param array<string,mixed> $filters Current list filters.
	 * @return void
	 */
	public static function render_form( array $filters ): void {
		if ( ! Capabilities::can( 'manage_licenses' ) ) {
			return;
		}
		echo '<form method="post" id="wplc-bulk-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-wplc-confirm="' . esc_attr__( 'Apply the selected action to every selected license?', 'wp-license' ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		echo '<input type="hidden" name="bulk_token" value="' . esc_attr( self::new_token() ) . '">';
		wp_nonce_field( self::ACTION, '_wpnonce', false );
		foreach ( array_merge( LicenseListTable::FILTER_ARGS, array( 'orderby', 'order' ) ) as $field ) {
			if ( isset( $filters[ $field ] ) && '' !== (string) $filters[ $field ] && '0' !== (string) $filters[ $field ] ) {
				echo '<input type="hidden" name="return_' . esc_attr( $field ) . '" value="' . esc_attr( (string) $filters[ $field ] ) . '">';
			}
		}
		if ( ! empty( $filters['search_token'] ) ) {
			echo '<input type="hidden" name="return_' . esc_attr( LicenseSearchStore::QUERY_ARG ) . '" value="' . esc_attr( (string) $filters['search_token'] ) . '">';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page number.
		$paged = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 0;
		if ( $paged > 1 ) {
			echo '<input type="hidden" name="return_paged" value="' . esc_attr( (string) $paged ) . '">';
		}
		echo '</form>';
	}

	/**
	 * Bulk controls for the top table navigation (bound to the bulk form).
	 *
	 * @return void
	 */
	public static function render_controls(): void {
		if ( ! Capabilities::can( 'manage_licenses' ) ) {
			return;
		}
		echo '<div class="alignleft actions bulkactions wplc-bulk-controls">';
		echo '<label class="screen-reader-text" for="wplc-bulk-action">' . esc_html__( 'Bulk action', 'wp-license' ) . '</label>';
		echo '<select id="wplc-bulk-action" name="bulk_action" form="wplc-bulk-form"><option value="">' . esc_html__( 'Bulk actions', 'wp-license' ) . '</option>';
		foreach ( self::actions() as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select> ';
		echo '<label class="screen-reader-text" for="wplc-bulk-reason">' . esc_html__( 'Reason for the bulk action', 'wp-license' ) . '</label>';
		if ( isset( self::actions()['extend'] ) ) {
			echo '<label class="screen-reader-text" for="wplc-bulk-days">' . esc_html__( 'Days to add (for "Extend the term")', 'wp-license' ) . '</label>';
			echo '<input type="number" id="wplc-bulk-days" name="bulk_days" form="wplc-bulk-form" min="1" max="3650" step="1" class="small-text" placeholder="' . esc_attr__( 'Days', 'wp-license' ) . '"> ';
		}
		echo '<input type="text" id="wplc-bulk-reason" name="bulk_reason" form="wplc-bulk-form" maxlength="250" placeholder="' . esc_attr__( 'Reason (required, stored in the audit log)', 'wp-license' ) . '"> ';
		echo '<button type="submit" class="button action" form="wplc-bulk-form" id="wplc-bulk-apply">' . esc_html__( 'Apply', 'wp-license' ) . '</button>';
		echo '</div>';
	}

	/**
	 * Render the stored result of a batch above the list.
	 *
	 * @param int    $user_id Current user.
	 * @param string $token   Batch token from the URL.
	 * @return void
	 */
	public static function render_result( int $user_id, string $token ): void {
		$result = self::load_result( $user_id, $token );
		if ( null === $result ) {
			return;
		}
		$actions = self::actions();
		$label   = isset( $actions[ $result['action'] ] ) ? $actions[ $result['action'] ] : $result['action'];
		$class   = $result['skipped'] ? 'notice-warning' : 'notice-success';

		echo '<div class="notice ' . esc_attr( $class ) . ' inline wplc-bulk-result"><p><strong>' . esc_html( $label ) . ':</strong> ';
		$done_count    = count( $result['done'] );
		$skipped_count = count( $result['skipped'] );
		echo esc_html(
			sprintf(
				/* translators: %s: number of changed licenses. */
				_n( '%s done', '%s done', $done_count, 'wp-license' ),
				number_format_i18n( $done_count )
			) . ', ' . sprintf(
				/* translators: %s: number of skipped licenses. */
				_n( '%s skipped.', '%s skipped.', $skipped_count, 'wp-license' ),
				number_format_i18n( $skipped_count )
			)
		);
		echo '</p>';
		if ( $result['done'] ) {
			echo '<p>' . esc_html__( 'Done:', 'wp-license' ) . ' ';
			$links = array();
			foreach ( $result['done'] as $license_id ) {
				$links[] = '<a href="' . esc_url( LicenseAdmin::detail_url( $license_id ) ) . '">#' . (int) $license_id . '</a>';
			}
			echo implode( ', ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts above.
			echo '</p>';
		}
		if ( $result['skipped'] ) {
			echo '<table class="widefat striped wplc-bulk-skipped"><thead><tr><th scope="col">' . esc_html__( 'License', 'wp-license' ) . '</th><th scope="col">' . esc_html__( 'Status', 'wp-license' ) . '</th><th scope="col">' . esc_html__( 'Skipped because', 'wp-license' ) . '</th></tr></thead><tbody>';
			foreach ( $result['skipped'] as $row ) {
				echo '<tr><td><a href="' . esc_url( LicenseAdmin::detail_url( $row['id'] ) ) . '">#' . (int) $row['id'] . '</a></td>';
				echo '<td>' . esc_html( '' !== $row['status'] ? Format::license_status_label( $row['status'] ) : '—' ) . '</td>';
				echo '<td data-wplc-skip="' . esc_attr( $row['code'] ) . '">' . esc_html( self::skip_label( $row['code'] ) ) . '</td></tr>';
			}
			echo '</tbody></table><p></p>';
		}
		echo '</div>';
	}
}
