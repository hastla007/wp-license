<?php
/**
 * Administrator license detail screen and manual license form.
 *
 * @package WPLC
 */

namespace WPLC\Admin;

use WPLC\Billing\Money;
use WPLC\Billing\PlanUpgrade;
use WPLC\Billing\RenewalReview;
use WPLC\Infrastructure\Database\Schema;
use WPLC\License\AuditReason;
use WPLC\License\LicenseLifecycle;
use WPLC\License\LicenseStateMachine;
use WPLC\License\PlanChange;

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
			$wpdb->prepare( "SELECT * FROM %i WHERE id = %d LIMIT 1", $tables['licenses'], $license_id ),
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
			$wpdb->prepare( "SELECT * FROM %i WHERE license_id = %d ORDER BY status ASC, activated_at DESC LIMIT 500", $tables['activations'], $license_id ),
			ARRAY_A
		);
		$renewals    = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM %i WHERE license_id = %d ORDER BY period_start DESC, id DESC LIMIT 200", $tables['renewals'], $license_id ),
			ARRAY_A
		);
		$entitlements = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM %i WHERE license_id = %d ORDER BY entitlement_key ASC", $tables['entitlements'], $license_id ),
			ARRAY_A
		);
		$display_name = $wpdb->get_var(
			$wpdb->prepare( "SELECT display_name FROM %i WHERE product_key = %s LIMIT 1", $tables['products'], (string) $license['product_key'] )
		);
		$activations  = is_array( $activations ) ? $activations : array();
		$renewals     = is_array( $renewals ) ? $renewals : array();
		$entitlements = is_array( $entitlements ) ? $entitlements : array();

		echo '<h1 class="wp-heading-inline">';
		/* translators: 1: license key prefix, 2: license ID. */
		echo esc_html( sprintf( __( 'License %1$s (#%2$d)', 'wp-license' ), (string) $license['key_prefix'], $license_id ) );
		echo '</h1> <a href="' . esc_url( LicenseAdmin::list_url() ) . '" class="page-title-action">' . esc_html__( 'All licenses', 'wp-license' ) . '</a>';
		echo '<hr class="wp-header-end">';

		self::render_review_notice( $license_id );
		self::render_upgrade_review_notice( $license_id );
		self::render_summary( $license, is_string( $display_name ) ? $display_name : '', $activations, $entitlements );
		if ( Capabilities::can( 'manage_licenses' ) ) {
			self::render_actions( $license );
			self::render_support_actions( $license );
		}
		self::render_activations( $activations );
		self::render_renewals( $renewals );
		self::render_entitlements( $entitlements );
		self::render_audit_log( $license_id, $activations, $renewals );
		echo '</div>';
	}

	/**
	 * Warn when a renewal of this license waits for an administrator (T-154).
	 *
	 * @param int $license_id License ID.
	 * @return void
	 */
	private static function render_review_notice( int $license_id ): void {
		foreach ( RenewalReview::open_cases_for_license( $license_id ) as $case ) {
			$since    = Format::utc_timestamp( $case['review_at'] ?: $case['updated_at'] );
			$deferred = null !== $since ? $since + LicenseLifecycle::REVIEW_EXPIRY_DEFERRAL_DAYS * DAY_IN_SECONDS : null;
			echo '<div class="notice notice-warning inline wplc-review-notice"><p><strong>' . esc_html__( 'A renewal of this license needs review.', 'wp-license' ) . '</strong> ';
			if ( '' !== (string) $case['review_reason'] ) {
				echo esc_html( AuditReason::label( (string) $case['review_reason'] ) ) . ' ';
			}
			/* translators: 1: date the review started, 2: date until which the expiry of the license is deferred. */
			echo esc_html( sprintf( __( 'Open since %1$s. The license does not expire before the review is resolved, at the latest until %2$s.', 'wp-license' ), Format::date( $case['review_at'] ?: $case['updated_at'], true ), null !== $deferred ? Format::date( gmdate( 'Y-m-d H:i:s', $deferred ), true ) : '—' ) );
			echo ' <a href="' . esc_url( RenewalReviewAdmin::list_url() . '#wplc-review-' . (int) $case['id'] ) . '">' . esc_html__( 'Resolve it in "Renewals to review"', 'wp-license' ) . '</a></p></div>';
		}
	}

	/**
	 * Warn when a paid plan upgrade order of this license could not be applied (T-110).
	 *
	 * @param int $license_id License ID.
	 * @return void
	 */
	private static function render_upgrade_review_notice( int $license_id ): void {
		foreach ( PlanUpgrade::orders_in_review( $license_id ) as $order_id => $reason ) {
			echo '<div class="notice notice-warning inline wplc-upgrade-review-notice"><p><strong>' . esc_html__( 'A paid plan upgrade was not applied.', 'wp-license' ) . '</strong> ';
			echo wp_kses_post( self::order_link( (int) $order_id ) ) . ' ';
			if ( '' !== $reason ) {
				echo esc_html( AuditReason::label( $reason ) ) . ' ';
			}
			echo esc_html__( 'Refund the order in WooCommerce, or change the plan below if the customer keeps the upgrade.', 'wp-license' ) . '</p></div>';
		}
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
			$status .= ' — ' . AuditReason::label( (string) $license['status_reason'] );
		}

		$renewal = Format::renewal_mode_label( $license );
		if ( ! empty( $license['renewal_enabled'] ) ) {
			$amount = Money::to_decimal( (int) $license['renewal_amount_minor'], (int) $license['renewal_currency_decimals'] );
			if ( function_exists( 'wc_price' ) ) {
				// A-131: amount in the shop's number format.
				$renewal .= ' — ' . html_entity_decode(
					wp_strip_all_tags(
						wc_price(
							(float) $amount,
							array(
								'currency' => (string) $license['renewal_currency'],
								'decimals' => (int) $license['renewal_currency_decimals'],
							)
						)
					),
					ENT_QUOTES,
					'UTF-8'
				);
			} else {
				$renewal .= ' — ' . $amount . ' ' . (string) $license['renewal_currency'];
			}
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
			array( __( 'Plan', 'wp-license' ), self::plan_text( $license ) ),
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
			'suspended' => array( __( 'Suspend', 'wp-license' ), __( 'Suspend this license? Its sites lose access to updates and features until it is reactivated.', 'wp-license' ) ),
			'revoked'   => array( __( 'Revoke', 'wp-license' ), __( 'Revoke this license permanently? This cannot be undone.', 'wp-license' ) ),
		);
		$period_ended = ! empty( $license['paid_until'] ) && (string) $license['paid_until'] <= gmdate( 'Y-m-d H:i:s' );
		$any          = false;
		foreach ( $buttons as $target => $button ) {
			if ( ! LicenseStateMachine::allows( $status, $target, 'admin_status_change' ) ) {
				continue;
			}
			if ( 'active' === $target && $period_ended ) {
				// A-51: a suspended license cannot use "Change term"; it is restored with a new end date below.
				if ( 'suspended' !== $status ) {
					echo '<p>' . esc_html__( 'The paid period has ended. Change the term below to reactivate this license.', 'wp-license' ) . '</p>';
				}
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
		if ( ! $any && 'revoked' === $status && ! Capabilities::can( 'restore_licenses' ) ) {
			echo '<p>' . esc_html__( 'A revoked license can only be restored by an administrator. Otherwise add a new license.', 'wp-license' ) . '</p>';
		}
		if ( ( 'suspended' === $status && $period_ended ) || ( 'revoked' === $status && Capabilities::can( 'restore_licenses' ) ) ) {
			self::render_restore_form( $license );
		}

		// The term of a suspended license cannot be changed; reactivate it first.
		if ( in_array( $status, array( 'revoked', 'pending', 'suspended' ), true ) ) {
			return;
		}

		self::render_plan_change( $license );
		self::render_renewal_actions( $license );

		$processing = SupportActions::has_processing_renewal( $license_id );
		$open       = SupportActions::open_renewal_orders( $license_id );

		echo '<h3>' . esc_html__( 'Change term', 'wp-license' ) . '</h3>';
		echo '<form method="post" class="wplc-inline-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="wplc_admin_extend_license">';
		echo '<input type="hidden" name="license_id" value="' . esc_attr( (string) $license_id ) . '">';
		self::nonce_input( 'wplc_admin_extend_license_' . $license_id );
		// B-133: the handler refuses when the term is no longer the one shown here.
		echo '<input type="hidden" name="expected_paid_until" value="' . esc_attr( (string) $license['paid_until'] ) . '">';
		echo '<label for="wplc-extend-date">' . esc_html__( 'New end date', 'wp-license' ) . '</label>';
		echo '<input type="date" id="wplc-extend-date" name="paid_until" required min="' . esc_attr( wp_date( 'Y-m-d' ) ) . '" value="' . esc_attr( Format::utc_to_local_date( $license['paid_until'], wp_timezone() ) ) . '">';
		echo '<label for="wplc-extend-reason">' . esc_html__( 'Reason', 'wp-license' ) . '</label>';
		echo '<input type="text" id="wplc-extend-reason" name="reason" required maxlength="255">';
		echo '<button class="button button-primary" type="submit">' . esc_html__( 'Change term', 'wp-license' ) . '</button></form>';
		echo '<p class="description">';
		/* translators: %s: site timezone, for example Europe/Berlin. */
		echo esc_html( sprintf( __( 'The license runs until the end of that day in the site timezone (%s). No payment is taken. An expired license becomes active again.', 'wp-license' ), wp_timezone_string() ) );
		echo '</p>';
		if ( $processing ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'An automatic payment for this license is being processed right now. The term cannot be changed until it has finished.', 'wp-license' ) . '</p></div>';
		}
		if ( $open ) {
			$links = array();
			foreach ( $open as $row ) {
				$links[] = self::order_link( $row['order_id'] );
			}
			echo '<div class="notice notice-warning inline"><p>';
			echo esc_html__( 'The customer still has an unpaid renewal order for this license. Changing the term does not cancel it: if the customer pays it later, the payment is accepted but may not extend the license. Cancel the order in WooCommerce first, or leave the term unchanged.', 'wp-license' );
			echo ' ' . wp_kses_post( implode( ', ', $links ) );
			echo '</p></div>';
		}
	}

	/**
	 * Automatic renewal on behalf of the customer (A-21) and its amount (A-11).
	 *
	 * @param array<string,mixed> $license License row.
	 * @return void
	 */
	private static function render_renewal_actions( array $license ): void {
		if ( ! in_array( (string) $license['status'], array( 'active', 'past_due' ), true ) || empty( $license['renewal_enabled'] ) ) {
			return;
		}
		$license_id = (int) $license['id'];
		$post_url   = admin_url( 'admin-post.php' );

		echo '<h3>' . esc_html__( 'Automatic renewal', 'wp-license' ) . '</h3>';
		if ( empty( $license['cancel_at_period_end'] ) ) {
			echo '<form method="post" class="wplc-inline-form" action="' . esc_url( $post_url ) . '" data-wplc-confirm="' . esc_attr__( 'Cancel the automatic renewal of this license for the end of the paid period?', 'wp-license' ) . '">';
			echo '<input type="hidden" name="action" value="wplc_admin_cancel_renewal">';
			echo '<input type="hidden" name="license_id" value="' . esc_attr( (string) $license_id ) . '">';
			self::nonce_input( 'wplc_admin_cancel_renewal_' . $license_id );
			echo '<label for="wplc-cancel-renewal-reason">' . esc_html__( 'Reason', 'wp-license' ) . '</label>';
			echo '<input type="text" id="wplc-cancel-renewal-reason" name="reason" required maxlength="255">';
			echo '<button class="button" type="submit">' . esc_html__( 'Cancel at the end of the paid period', 'wp-license' ) . '</button></form>';
			echo '<p class="description">' . esc_html__( 'The same cancellation the customer can make in their account: the license keeps running until the end of the paid period, nothing more is charged, and the customer gets a confirmation email. It can be withdrawn here until the period ends.', 'wp-license' ) . '</p>';
		} else {
			echo '<form method="post" class="wplc-inline-form" action="' . esc_url( $post_url ) . '">';
			echo '<input type="hidden" name="action" value="wplc_admin_withdraw_cancellation">';
			echo '<input type="hidden" name="license_id" value="' . esc_attr( (string) $license_id ) . '">';
			self::nonce_input( 'wplc_admin_withdraw_cancellation_' . $license_id );
			echo '<label for="wplc-withdraw-cancellation-reason">' . esc_html__( 'Reason', 'wp-license' ) . '</label>';
			echo '<input type="text" id="wplc-withdraw-cancellation-reason" name="reason" required maxlength="255">';
			echo '<button class="button" type="submit">' . esc_html__( 'Withdraw cancellation', 'wp-license' ) . '</button></form>';
			echo '<p class="description">' . esc_html__( 'The license is cancelled for the end of the paid period. Withdraw the cancellation only when the customer asked for it: the license is then charged automatically again with the stored payment method.', 'wp-license' ) . '</p>';
		}

		$decimals = (int) $license['renewal_currency_decimals'];
		$current  = (int) $license['renewal_amount_minor'];
		echo '<form method="post" class="wplc-inline-form" action="' . esc_url( $post_url ) . '">';
		echo '<input type="hidden" name="action" value="wplc_admin_renewal_amount">';
		echo '<input type="hidden" name="license_id" value="' . esc_attr( (string) $license_id ) . '">';
		echo '<input type="hidden" name="expected_minor" value="' . esc_attr( (string) $current ) . '">';
		self::nonce_input( 'wplc_admin_renewal_amount_' . $license_id );
		/* translators: %s: currency code, for example EUR. */
		echo '<label for="wplc-renewal-amount">' . esc_html( sprintf( __( 'Renewal amount (%s)', 'wp-license' ), (string) $license['renewal_currency'] ) ) . '</label>';
		echo '<input type="text" inputmode="decimal" id="wplc-renewal-amount" name="amount" required size="10" value="' . esc_attr( Money::to_decimal( $current, $decimals ) ) . '">';
		echo '<label for="wplc-renewal-amount-reason">' . esc_html__( 'Reason', 'wp-license' ) . '</label>';
		echo '<input type="text" id="wplc-renewal-amount-reason" name="reason" required maxlength="255">';
		echo '<button class="button" type="submit">' . esc_html__( 'Change renewal amount', 'wp-license' ) . '</button></form>';
		echo '<p class="description">' . esc_html__( 'The amount of the next automatic renewals, in the same form as the product price in the shop (taxes are added as at purchase). It can only be lowered: the customer agreed to automatic charges of the current amount. Renewal orders that already exist keep their amount; change those on the order screen.', 'wp-license' ) . '</p>';
	}

	/**
	 * Form that brings a suspended or revoked license back with a new end date.
	 *
	 * @param array<string,mixed> $license License row.
	 * @return void
	 */
	private static function render_restore_form( array $license ): void {
		$license_id = (int) $license['id'];
		$status     = (string) $license['status'];
		$default    = ( new \DateTimeImmutable( 'now', wp_timezone() ) )->modify( '+30 days' )->format( 'Y-m-d' );
		$current    = Format::utc_to_local_date( $license['paid_until'], wp_timezone() );

		echo '<h3>' . esc_html__( 'Restore with a new end date', 'wp-license' ) . '</h3>';
		echo '<form method="post" class="wplc-inline-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( 'revoked' === $status ? ' data-wplc-confirm="' . esc_attr__( 'Restore this revoked license? The customer gets access again.', 'wp-license' ) . '"' : '' ) . '>';
		echo '<input type="hidden" name="action" value="wplc_admin_restore_license">';
		echo '<input type="hidden" name="license_id" value="' . esc_attr( (string) $license_id ) . '">';
		echo '<input type="hidden" name="expected_status" value="' . esc_attr( $status ) . '">';
		self::nonce_input( 'wplc_admin_restore_license_' . $license_id );
		echo '<label for="wplc-restore-date">' . esc_html__( 'New end date', 'wp-license' ) . '</label>';
		echo '<input type="date" id="wplc-restore-date" name="paid_until" required min="' . esc_attr( wp_date( 'Y-m-d' ) ) . '" value="' . esc_attr( $current > wp_date( 'Y-m-d' ) ? $current : $default ) . '">';
		echo '<label for="wplc-restore-reason">' . esc_html__( 'Reason', 'wp-license' ) . '</label>';
		echo '<input type="text" id="wplc-restore-reason" name="reason" required maxlength="255">';
		echo '<button class="button" type="submit">' . esc_html__( 'Restore license', 'wp-license' ) . '</button></form>';
		echo '<p class="description">';
		echo esc_html(
			'revoked' === $status
				? __( 'Use this to undo a revocation that happened by mistake, for example an order set to "refunded" by accident. The license becomes active until the end of that day. Automatic renewal stays off. No payment is taken.', 'wp-license' )
				: __( 'The paid period of this suspended license has ended. It becomes active again until the end of that day. No payment is taken.', 'wp-license' )
		);
		echo '</p>';
	}

	/**
	 * Key and owner actions.
	 *
	 * @param array<string,mixed> $license License row.
	 * @return void
	 */
	private static function render_support_actions( array $license ): void {
		$license_id = (int) $license['id'];
		$status     = (string) $license['status'];
		if ( 'pending' === $status ) {
			return;
		}
		$post_url = admin_url( 'admin-post.php' );

		if ( 'revoked' !== $status ) {
			echo '<h3>' . esc_html__( 'License key', 'wp-license' ) . '</h3>';
			echo '<form method="post" class="wplc-inline-form" action="' . esc_url( $post_url ) . '" data-wplc-confirm="' . esc_attr__( 'Issue a new key? The old key stops working immediately.', 'wp-license' ) . '">';
			echo '<input type="hidden" name="action" value="wplc_admin_reissue_key">';
			echo '<input type="hidden" name="license_id" value="' . esc_attr( (string) $license_id ) . '">';
			self::nonce_input( 'wplc_admin_reissue_key_' . $license_id );
			echo '<label class="screen-reader-text" for="wplc-reissue-reason">' . esc_html__( 'Reason for the new key', 'wp-license' ) . '</label>';
			echo '<input type="text" id="wplc-reissue-reason" name="reason" required maxlength="255" placeholder="' . esc_attr__( 'Reason', 'wp-license' ) . '">';
			echo '<label><input type="checkbox" name="release_sites" value="1"> ' . esc_html__( 'Also release all activated sites', 'wp-license' ) . '</label> ';
			echo '<button class="button" type="submit">' . esc_html__( 'Issue new key', 'wp-license' ) . '</button></form>';
			echo '<p class="description">' . esc_html__( 'For a key that was published or passed on. The license keeps its term, its order and its renewal. Sites that are already activated keep working unless you release them; release unknown sites below. The customer finds the new key in their account.', 'wp-license' ) . '</p>';

			if ( ! empty( $license['customer_id'] ) ) {
				echo '<form method="post" class="wplc-inline-form" action="' . esc_url( $post_url ) . '">';
				echo '<input type="hidden" name="action" value="wplc_admin_resend_key">';
				echo '<input type="hidden" name="license_id" value="' . esc_attr( (string) $license_id ) . '">';
				self::nonce_input( 'wplc_admin_resend_key_' . $license_id );
				echo '<label class="screen-reader-text" for="wplc-resend-reason">' . esc_html__( 'Reason for sending the key again', 'wp-license' ) . '</label>';
				echo '<input type="text" id="wplc-resend-reason" name="reason" required maxlength="255" placeholder="' . esc_attr__( 'Reason', 'wp-license' ) . '">';
				echo '<button class="button" type="submit">' . esc_html__( 'Send key email again', 'wp-license' ) . '</button></form>';
			}
		}

		echo '<h3>' . esc_html__( 'Change owner', 'wp-license' ) . '</h3>';
		echo '<form method="post" class="wplc-inline-form" action="' . esc_url( $post_url ) . '" data-wplc-confirm="' . esc_attr__( 'Hand this license over to another customer?', 'wp-license' ) . '">';
		echo '<input type="hidden" name="action" value="wplc_admin_change_owner">';
		echo '<input type="hidden" name="license_id" value="' . esc_attr( (string) $license_id ) . '">';
		echo '<input type="hidden" name="expected_customer" value="' . esc_attr( (string) (int) $license['customer_id'] ) . '">';
		self::nonce_input( 'wplc_admin_change_owner_' . $license_id );
		echo '<label for="wplc-owner-customer">' . esc_html__( 'New customer (user ID or email)', 'wp-license' ) . '</label>';
		echo '<input type="text" id="wplc-owner-customer" name="customer" required>';
		echo '<label for="wplc-owner-reason">' . esc_html__( 'Reason', 'wp-license' ) . '</label>';
		echo '<input type="text" id="wplc-owner-reason" name="reason" required maxlength="255">';
		$can_reissue = 'revoked' !== (string) $license['status'];
		if ( $can_reissue ) {
			// Checked by default: the previous owner knows the current key (P-38).
			echo '<label><input type="checkbox" name="new_key" value="1" checked> ' . esc_html__( 'Issue a new key (the previous key stops working)', 'wp-license' ) . '</label> ';
			echo '<label><input type="checkbox" name="release_sites" value="1"> ' . esc_html__( 'Also release all activated sites', 'wp-license' ) . '</label> ';
		}
		echo '<button class="button" type="submit">' . esc_html__( 'Change owner', 'wp-license' ) . '</button></form>';
		echo '<p class="description">' . esc_html__( 'The new customer sees the license and its key in their account; the previous owner no longer sees it there. Automatic renewal is switched off, because the stored payment method belongs to the previous owner.', 'wp-license' ) . ' ';
		echo esc_html__( 'The previous owner still knows the current key from their emails and could activate further sites with it. Keep "Issue a new key" ticked unless both sides agree otherwise; the new owner then gets the new key. Activated sites stay connected unless you release them.', 'wp-license' ) . '</p>';
	}

	/**
	 * Plan key, with a plan change scheduled for the next renewal.
	 *
	 * @param array<string,mixed> $license License row.
	 * @return string
	 */
	private static function plan_text( array $license ): string {
		$text = (string) $license['plan_key'];
		if ( '' !== (string) ( $license['pending_plan_key'] ?? '' ) ) {
			/* translators: %s: plan key the license changes to. */
			$text .= ' — ' . sprintf( __( 'changes to %s with the next renewal', 'wp-license' ), (string) $license['pending_plan_key'] );
		}

		return $text;
	}

	/**
	 * "Change plan" form (T-110): now or from the next renewal, no payment.
	 *
	 * @param array<string,mixed> $license License row.
	 * @return void
	 */
	private static function render_plan_change( array $license ): void {
		if ( ! in_array( (string) $license['status'], array( 'active', 'past_due' ), true ) ) {
			return;
		}
		$license_id = (int) $license['id'];
		$targets    = PlanChange::targets( $license, false );
		echo '<h3>' . esc_html__( 'Change plan', 'wp-license' ) . '</h3>';
		if ( '' !== (string) ( $license['pending_plan_key'] ?? '' ) ) {
			echo '<form method="post" class="wplc-inline-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="wplc_admin_cancel_plan_change">';
			echo '<input type="hidden" name="license_id" value="' . esc_attr( (string) $license_id ) . '">';
			self::nonce_input( 'wplc_admin_cancel_plan_change_' . $license_id );
			/* translators: %s: plan key. */
			echo '<span>' . esc_html( sprintf( __( 'Scheduled for the next renewal: %s.', 'wp-license' ), (string) $license['pending_plan_key'] ) ) . '</span> ';
			echo '<label class="screen-reader-text" for="wplc-plan-cancel-reason">' . esc_html__( 'Reason', 'wp-license' ) . '</label>';
			echo '<input type="text" id="wplc-plan-cancel-reason" name="reason" required maxlength="255" placeholder="' . esc_attr__( 'Reason', 'wp-license' ) . '">';
			echo '<button class="button" type="submit">' . esc_html__( 'Withdraw plan change', 'wp-license' ) . '</button></form>';
		}
		if ( ! $targets ) {
			echo '<p>' . esc_html__( 'No other plan of this product with the same billing interval is mapped in the catalog.', 'wp-license' ) . '</p>';
			return;
		}
		echo '<form method="post" class="wplc-inline-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="wplc_admin_change_plan">';
		echo '<input type="hidden" name="license_id" value="' . esc_attr( (string) $license_id ) . '">';
		self::nonce_input( 'wplc_admin_change_plan_' . $license_id );
		echo '<label for="wplc-plan-target">' . esc_html__( 'New plan', 'wp-license' ) . '</label>';
		echo '<select id="wplc-plan-target" name="product_id" required>';
		foreach ( $targets as $product_id => $target ) {
			$limit = PlanChange::site_limit_from_plan( $target['plan'] );
			/* translators: 1: plan key, 2: product name, 3: price, 4: currency, 5: live sites. */
			$label = sprintf( __( '%1$s — %2$s (%3$s %4$s, live sites: %5$s)', 'wp-license' ), (string) $target['plan']['plan_key'], wp_strip_all_tags( (string) $target['product']->get_name() ), Money::to_decimal( (int) $target['price']['amount_minor'], (int) $target['price']['decimals'] ), (string) $target['price']['currency'], null === $limit ? '—' : (string) $limit );
			echo '<option value="' . esc_attr( (string) $product_id ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<label for="wplc-plan-when">' . esc_html__( 'When', 'wp-license' ) . '</label>';
		echo '<select id="wplc-plan-when" name="when"><option value="now">' . esc_html__( 'Now', 'wp-license' ) . '</option><option value="renewal">' . esc_html__( 'With the next renewal', 'wp-license' ) . '</option></select>';
		echo '<label for="wplc-plan-reason">' . esc_html__( 'Reason', 'wp-license' ) . '</label>';
		echo '<input type="text" id="wplc-plan-reason" name="reason" required maxlength="255">';
		echo '<button class="button" type="submit">' . esc_html__( 'Change plan', 'wp-license' ) . '</button></form>';
		echo '<p class="description">' . esc_html__( 'No payment is taken and nothing is refunded. The paid period stays. "Now" replaces the entitlements and the live site limit at once; sites above a lower limit stay active, but no new sites can be activated. Future renewals use the product of the new plan; an automatic renewal amount is never raised without the consent of the customer (it stays as it is for a more expensive plan).', 'wp-license' ) . '</p>';
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
		foreach ( array( __( 'Site', 'wp-license' ), __( 'Environment', 'wp-license' ), __( 'Status', 'wp-license' ), __( 'Versions', 'wp-license' ), __( 'Activated', 'wp-license' ), __( 'Last seen', 'wp-license' ), _x( 'Release', 'verb: free the place of a site activation', 'wp-license' ) ) as $heading ) {
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
			if ( 'active' === $activation['status'] && Capabilities::can( 'manage_licenses' ) ) {
				$field_id = 'wplc-release-' . $activation_id;
				/* translators: %s: site URL. */
				$question = sprintf( __( 'Release %s? The site loses its connection until it is activated again.', 'wp-license' ), (string) $activation['site_url'] );
				echo '<form method="post" class="wplc-inline-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-wplc-confirm="' . esc_attr( $question ) . '">';
				echo '<input type="hidden" name="action" value="wplc_admin_release_activation">';
				echo '<input type="hidden" name="activation_id" value="' . esc_attr( (string) $activation_id ) . '">';
				self::nonce_input( 'wplc_admin_release_activation_' . $activation_id );
				/* translators: %s: site URL. */
				echo '<label class="screen-reader-text" for="' . esc_attr( $field_id ) . '">' . esc_html( sprintf( __( 'Reason for releasing %s', 'wp-license' ), (string) $activation['site_url'] ) ) . '</label>';
				echo '<input type="text" id="' . esc_attr( $field_id ) . '" name="reason" required maxlength="255" placeholder="' . esc_attr__( 'Reason', 'wp-license' ) . '">';
				echo '<button class="button" type="submit">' . esc_html_x( 'Release', 'verb: free the place of a site activation', 'wp-license' ) . '</button></form>';
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

		/* translators: %s: maximum number of entries. */
		echo '<p class="description">' . esc_html( sprintf( _n( 'Latest %s entry, newest first.', 'Latest %s entries, newest first.', self::AUDIT_LIMIT, 'wp-license' ), number_format_i18n( self::AUDIT_LIMIT ) ) );
		if ( Capabilities::can( 'view_diagnostics' ) ) {
			echo ' <a href="' . esc_url( AuditLogAdmin::url( array( 'license' => $license_id ) ) ) . '">' . esc_html__( 'Open the complete audit log of this license', 'wp-license' ) . '</a>';
		}
		echo '</p>';
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( Format::date_heading(), __( 'Action', 'wp-license' ), __( 'Object', 'wp-license' ), __( 'By', 'wp-license' ), __( 'Reason', 'wp-license' ), __( 'Change', 'wp-license' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $entries as $entry ) {
			$actor_id = (int) $entry['actor_user_id'];
			$actor    = $actor_id ? get_userdata( $actor_id ) : false;
			$type     = (string) $entry['object_type'];
			$actor_name = self::actor_label( $actor_id, $actor ? (string) $actor->user_login : '' );

			echo '<tr><td>' . esc_html( Format::date( $entry['created_at'], true ) ) . '</td>';
			echo '<td>' . Format::audit_action_html( (string) $entry['action'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Format::audit_action_html().
			echo '<td>' . esc_html( ( isset( $object_labels[ $type ] ) ? $object_labels[ $type ] : $type ) . ' #' . (int) $entry['object_id'] ) . '</td>';
			echo '<td>' . esc_html( $actor_name ) . '</td>';
			echo '<td>' . esc_html( AuditReason::label( (string) $entry['reason'] ) ) . '</td>';
			echo '<td>' . esc_html( self::change_summary( $entry['before_json'], $entry['after_json'] ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Label of the acting user: the login name and ID, which the user cannot change (B-135).
	 *
	 * @param int    $actor_id   User ID, zero for system events.
	 * @param string $user_login Login name, empty when the account no longer exists.
	 * @return string
	 */
	public static function actor_label( int $actor_id, string $user_login ): string {
		if ( $actor_id <= 0 ) {
			return __( 'System', 'wp-license' );
		}
		if ( '' === $user_login ) {
			/* translators: %d: user ID. */
			return sprintf( __( 'Deleted user #%d', 'wp-license' ), $actor_id );
		}

		return $user_login . ' (#' . $actor_id . ')';
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
			$wpdb->prepare(
				'SELECT p.id, p.product_key, p.plan_key, p.billing_interval, p.live_site_limit, pr.display_name FROM %i p LEFT JOIN %i pr ON pr.product_key = p.product_key WHERE p.active = 1 ORDER BY p.product_key ASC, p.live_site_limit ASC, p.plan_key ASC LIMIT 500',
				$tables['plans'],
				$tables['products']
			),
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

		// A-123: entries of a form that was refused come back.
		$kept = LicenseAdmin::take_add_form();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="wplc_admin_add_license">';
		wp_nonce_field( 'wplc_admin_add_license' );
		// A-60: identifies this rendered form, so a double click cannot create two licenses.
		echo '<input type="hidden" name="request_id" value="' . esc_attr( strtolower( wp_generate_password( 24, false ) ) ) . '">';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="wplc-add-customer">' . esc_html__( 'Customer', 'wp-license' ) . '</label></th><td>';
		echo '<input type="text" id="wplc-add-customer" name="customer" class="regular-text" required aria-describedby="wplc-add-customer-help" value="' . esc_attr( null !== $kept ? $kept['customer'] : '' ) . '">';
		echo '<p class="description" id="wplc-add-customer-help">' . esc_html__( 'User ID or email address of an existing user account.', 'wp-license' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="wplc-add-plan">' . esc_html__( 'Plan', 'wp-license' ) . '</label></th><td>';
		echo '<select id="wplc-add-plan" name="plan_id" required><option value="">' . esc_html__( 'Select a plan', 'wp-license' ) . '</option>';
		foreach ( $plans as $plan ) {
			$name = '' !== (string) $plan['display_name'] ? (string) $plan['display_name'] : (string) $plan['product_key'];
			$live = (int) $plan['live_site_limit'];
			$text = sprintf(
				/* translators: 1: product name, 2: plan key, 3: billing interval ("monthly" or "yearly"), 4: number of live sites. */
				_n( '%1$s — %2$s (%3$s, %4$d live site)', '%1$s — %2$s (%3$s, %4$d live sites)', $live, 'wp-license' ),
				$name,
				(string) $plan['plan_key'],
				'month' === $plan['billing_interval'] ? __( 'monthly', 'wp-license' ) : __( 'yearly', 'wp-license' ),
				$live
			);
			echo '<option value="' . esc_attr( (string) (int) $plan['id'] ) . '"' . selected( null !== $kept && $kept['plan_id'] === (int) $plan['id'], true, false ) . '>' . esc_html( $text ) . '</option>';
		}
		echo '</select></td></tr>';

		$default_end = null !== $kept && '' !== $kept['paid_until'] ? $kept['paid_until'] : ( new \DateTimeImmutable( 'now', wp_timezone() ) )->modify( '+1 year' )->format( 'Y-m-d' );
		echo '<tr><th scope="row"><label for="wplc-add-paid-until">' . esc_html__( 'Paid until', 'wp-license' ) . '</label></th><td>';
		echo '<input type="date" id="wplc-add-paid-until" name="paid_until" required min="' . esc_attr( wp_date( 'Y-m-d' ) ) . '" value="' . esc_attr( $default_end ) . '" aria-describedby="wplc-add-paid-until-help">';
		echo '<p class="description" id="wplc-add-paid-until-help">';
		/* translators: %s: site timezone, for example Europe/Berlin. */
		echo esc_html( sprintf( __( 'The license runs until the end of that day in the site timezone (%s).', 'wp-license' ), wp_timezone_string() ) );
		echo '</p></td></tr>';

		echo '<tr><th scope="row"><label for="wplc-add-reason">' . esc_html__( 'Reason', 'wp-license' ) . '</label></th><td>';
		echo '<input type="text" id="wplc-add-reason" name="reason" class="regular-text" required maxlength="255" aria-describedby="wplc-add-reason-help" value="' . esc_attr( null !== $kept ? $kept['reason'] : '' ) . '">';
		echo '<p class="description" id="wplc-add-reason-help">' . esc_html__( 'Stored in the audit log. Do not enter license keys or passwords.', 'wp-license' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Email', 'wp-license' ) . '</th><td>';
		echo '<label><input type="checkbox" name="skip_email" value="1"' . checked( null !== $kept && ! $kept['send_email'], true, false ) . '> ' . esc_html__( 'Do not send the license email with the key', 'wp-license' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Tick this for a migrated customer who already has a key or should not get an email now. The customer finds the key in their account either way.', 'wp-license' ) . '</p></td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Add license', 'wp-license' ) );
		echo '</form></div>';
	}
}
