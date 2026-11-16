<?php
/**
 * Admin: announce and withdraw a new renewal price of existing licenses (INH-05).
 *
 * @package WPLC
 */

namespace WPLC\Admin;

use WPLC\Billing\Money;
use WPLC\Billing\PriceChange;
use WPLC\Infrastructure\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The forms on the license page (one license) and the screen for all licenses of a product and
 * plan. Every action needs the capability to manage licenses, a nonce and a reason; announcing
 * for a whole plan also needs a ticked confirmation.
 */
final class PriceChangeAdmin {
	const PAGE = 'wplc-price-change';

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ), 16 );
		add_action( 'admin_post_wplc_admin_price_change_announce', array( self::class, 'handle_announce' ) );
		add_action( 'admin_post_wplc_admin_price_change_withdraw', array( self::class, 'handle_withdraw' ) );
		add_action( 'admin_post_wplc_admin_price_change_plan', array( self::class, 'handle_plan' ) );
	}

	/**
	 * @return string
	 */
	private static function capability(): string {
		return Capabilities::cap( 'manage_licenses' );
	}

	/**
	 * Add the screen for a whole plan below the license menu.
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		add_submenu_page(
			Capabilities::menu_parent(),
			__( 'Renewal price change', 'wp-license' ),
			__( 'Renewal price change', 'wp-license' ),
			self::capability(),
			self::PAGE,
			array( self::class, 'render' )
		);
		LicenseAdmin::connect_page( self::PAGE, __( 'Renewal price change', 'wp-license' ) );
	}

	/**
	 * Explanation shared by both places.
	 *
	 * @return string
	 */
	private static function rules_text(): string {
		return sprintf(
			/* translators: %d: notice period in days. */
			__( 'The customer gets an email with the current price, the new price, the date and how to cancel. Until that date every renewal is charged at the current price. The new price is only charged when the email was sent at least %d days before; otherwise the current price stays and the diagnostics report it. An announcement can be withdrawn until it takes effect. Lowering a price needs no announcement ("Change renewal amount").', 'wp-license' ),
			PriceChange::notice_days()
		);
	}

	/**
	 * The section on the license page.
	 *
	 * @param array<string,mixed> $license License row.
	 * @return void
	 */
	public static function render_license_section( array $license ): void {
		if ( ! current_user_can( self::capability() ) || ! in_array( (string) $license['status'], array( 'active', 'past_due' ), true ) || (int) $license['renewal_amount_minor'] <= 0 ) {
			return;
		}
		$license_id = (int) $license['id'];
		$decimals   = (int) $license['renewal_currency_decimals'];
		$currency   = (string) $license['renewal_currency'];
		$post_url   = admin_url( 'admin-post.php' );
		$pending    = PriceChange::pending( $license );

		echo '<h3>' . esc_html__( 'New renewal price with notice', 'wp-license' ) . '</h3>';
		if ( null !== $pending ) {
			echo '<p>';
			echo esc_html(
				sprintf(
					/* translators: 1: amount, 2: currency, 3: date. */
					__( 'Announced: %1$s %2$s from %3$s.', 'wp-license' ),
					Money::to_decimal( $pending['amount_minor'], $decimals ),
					$currency,
					Format::date( gmdate( 'Y-m-d H:i:s', $pending['effective_at'] ) )
				)
			);
			echo ' ';
			if ( $pending['notified_at'] > 0 ) {
				echo esc_html(
					sprintf(
						/* translators: 1: date and time the notice was sent, 2: date from which the new price is charged. */
						__( 'The customer was notified on %1$s. The new price is charged from %2$s.', 'wp-license' ),
						Format::date( gmdate( 'Y-m-d H:i:s', $pending['notified_at'] ), true ),
						Format::date( gmdate( 'Y-m-d H:i:s', $pending['applies_at'] ) )
					)
				);
			} else {
				echo '<strong>' . esc_html__( 'The notice email has not been sent yet (email switched off, mailer refused it, or billing is paused). It is tried again every day; until it is sent, the current price stays.', 'wp-license' ) . '</strong>';
			}
			echo '</p>';
			echo '<form method="post" class="wplc-inline-form" action="' . esc_url( $post_url ) . '">';
			echo '<input type="hidden" name="action" value="wplc_admin_price_change_withdraw">';
			echo '<input type="hidden" name="license_id" value="' . esc_attr( (string) $license_id ) . '">';
			wp_nonce_field( 'wplc_admin_price_change_withdraw_' . $license_id );
			echo '<label for="wplc-price-change-withdraw-reason">' . esc_html__( 'Reason', 'wp-license' ) . '</label>';
			echo '<input type="text" id="wplc-price-change-withdraw-reason" name="reason" required maxlength="255">';
			echo '<button class="button" type="submit">' . esc_html__( 'Withdraw announcement', 'wp-license' ) . '</button></form>';
			echo '<p class="description">' . esc_html__( 'The license keeps its current price. A customer who was notified gets an email that the change is off.', 'wp-license' ) . '</p>';
			return;
		}

		$earliest = gmdate( 'Y-m-d', PriceChange::earliest_effective_at( time() ) );
		echo '<form method="post" class="wplc-inline-form" action="' . esc_url( $post_url ) . '">';
		echo '<input type="hidden" name="action" value="wplc_admin_price_change_announce">';
		echo '<input type="hidden" name="license_id" value="' . esc_attr( (string) $license_id ) . '">';
		wp_nonce_field( 'wplc_admin_price_change_announce_' . $license_id );
		/* translators: %s: currency code, for example EUR. */
		echo '<label for="wplc-price-change-amount">' . esc_html( sprintf( __( 'New renewal amount (%s)', 'wp-license' ), $currency ) ) . '</label>';
		echo '<input type="text" inputmode="decimal" id="wplc-price-change-amount" name="amount" required size="10">';
		echo '<label for="wplc-price-change-date">' . esc_html__( 'Applies from (UTC)', 'wp-license' ) . '</label>';
		echo '<input type="date" id="wplc-price-change-date" name="effective_date" required min="' . esc_attr( $earliest ) . '" value="' . esc_attr( $earliest ) . '">';
		echo '<label for="wplc-price-change-reason">' . esc_html__( 'Reason', 'wp-license' ) . '</label>';
		echo '<input type="text" id="wplc-price-change-reason" name="reason" required maxlength="255">';
		echo '<button class="button" type="submit">' . esc_html__( 'Announce new price', 'wp-license' ) . '</button></form>';
		echo '<p class="description">' . esc_html( self::rules_text() ) . '</p>';
	}

	/**
	 * The screen for all licenses of a product and plan.
	 *
	 * @return void
	 */
	public static function render(): void {
		Capabilities::require_cap( 'manage_licenses', __( 'You do not have permission to change licenses.', 'wp-license' ) );
		global $wpdb;

		$tables   = Schema::table_names();
		$plans    = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT product_key, plan_key, renewal_currency, COUNT(*) AS licenses, MIN(renewal_amount_minor) AS lowest, MAX(renewal_amount_minor) AS highest, MAX(renewal_currency_decimals) AS decimals, SUM(price_change_amount_minor IS NOT NULL) AS announced FROM %i WHERE status IN (%s, %s) GROUP BY product_key, plan_key, renewal_currency ORDER BY product_key, plan_key', $tables['licenses'], 'active', 'past_due' ), ARRAY_A );
		$earliest = gmdate( 'Y-m-d', PriceChange::earliest_effective_at( time() ) );

		echo '<div class="wrap wplc-price-change"><h1>' . esc_html__( 'Renewal price change', 'wp-license' ) . '</h1>';
		echo '<p>' . esc_html__( 'Announce a new renewal price for every active license of a product and plan whose price is lower today. Licenses that already pay the new price or more, licenses in another currency and licenses with an announcement are skipped.', 'wp-license' ) . ' ' . esc_html( self::rules_text() ) . '</p>';

		if ( ! $plans ) {
			echo '<p>' . esc_html__( 'There are no active licenses.', 'wp-license' ) . '</p></div>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:900px"><thead><tr>';
		foreach ( array( __( 'Product key', 'wp-license' ), __( 'Plan key', 'wp-license' ), __( 'Currency', 'wp-license' ), __( 'Active licenses', 'wp-license' ), __( 'Renewal amounts today', 'wp-license' ), __( 'With an announcement', 'wp-license' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $plans as $plan ) {
			$decimals = (int) $plan['decimals'];
			$low      = Money::to_decimal( (int) $plan['lowest'], $decimals );
			$high     = Money::to_decimal( (int) $plan['highest'], $decimals );
			echo '<tr><td><code>' . esc_html( (string) $plan['product_key'] ) . '</code></td><td><code>' . esc_html( (string) $plan['plan_key'] ) . '</code></td><td>' . esc_html( (string) $plan['renewal_currency'] ) . '</td><td>' . esc_html( number_format_i18n( (int) $plan['licenses'] ) ) . '</td><td>' . esc_html( $low === $high ? $low : $low . ' – ' . $high ) . '</td><td>' . esc_html( number_format_i18n( (int) $plan['announced'] ) ) . '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Announce for a plan', 'wp-license' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="wplc_admin_price_change_plan">';
		wp_nonce_field( 'wplc_admin_price_change_plan' );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="wplc-pc-product">' . esc_html__( 'Product key', 'wp-license' ) . '</label></th><td><input type="text" id="wplc-pc-product" name="product_key" required class="regular-text" pattern="[a-z0-9][a-z0-9\-]{1,99}"></td></tr>';
		echo '<tr><th scope="row"><label for="wplc-pc-plan">' . esc_html__( 'Plan key', 'wp-license' ) . '</label></th><td><input type="text" id="wplc-pc-plan" name="plan_key" required class="regular-text" pattern="[a-z0-9][a-z0-9\-]{0,99}"></td></tr>';
		echo '<tr><th scope="row"><label for="wplc-pc-currency">' . esc_html__( 'Currency', 'wp-license' ) . '</label></th><td><input type="text" id="wplc-pc-currency" name="currency" required size="4" maxlength="3" value="' . esc_attr( (string) $plans[0]['renewal_currency'] ) . '"></td></tr>';
		echo '<tr><th scope="row"><label for="wplc-pc-amount">' . esc_html__( 'New renewal amount', 'wp-license' ) . '</label></th><td><input type="text" inputmode="decimal" id="wplc-pc-amount" name="amount" required size="10"><p class="description">' . esc_html__( 'In the same form as the product price in the shop (taxes are added as at purchase).', 'wp-license' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="wplc-pc-date">' . esc_html__( 'Applies from (UTC)', 'wp-license' ) . '</label></th><td><input type="date" id="wplc-pc-date" name="effective_date" required min="' . esc_attr( $earliest ) . '" value="' . esc_attr( $earliest ) . '"></td></tr>';
		echo '<tr><th scope="row"><label for="wplc-pc-reason">' . esc_html__( 'Reason', 'wp-license' ) . '</label></th><td><input type="text" id="wplc-pc-reason" name="reason" required maxlength="255" class="regular-text"></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Confirmation', 'wp-license' ) . '</th><td><label for="wplc-pc-confirm"><input type="checkbox" id="wplc-pc-confirm" name="confirm_announce" value="1" required> ' . esc_html__( 'Send the announcement email to every customer of these licenses now', 'wp-license' ) . '</label></td></tr>';
		echo '</tbody></table>';
		echo '<p><button class="button button-primary" type="submit">' . esc_html__( 'Announce new price', 'wp-license' ) . '</button></p></form>';
		echo '<p class="description">' . esc_html__( 'Up to 500 licenses are handled per submission. When more remain, submit the form again: licenses that already have the announcement are skipped.', 'wp-license' ) . '</p></div>';
	}

	/**
	 * Amount from the request in minor units.
	 *
	 * @param int $decimals Precision of the currency.
	 * @return int 0 when missing or invalid.
	 */
	private static function posted_amount( int $decimals ): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- every caller verifies its nonce first.
		$amount    = isset( $_POST['amount'] ) ? trim( sanitize_text_field( wp_unslash( (string) $_POST['amount'] ) ) ) : '';
		$separator = function_exists( 'wc_get_price_decimal_separator' ) ? (string) wc_get_price_decimal_separator() : '.';
		if ( '' !== $separator && '.' !== $separator ) {
			$amount = str_replace( $separator, '.', $amount );
		}
		$minor = '' !== $amount ? Money::to_minor_units( $amount, $decimals ) : null;

		return is_int( $minor ) && $minor > 0 ? $minor : 0;
	}

	/**
	 * Start of the posted day in UTC.
	 *
	 * @return int Unix time, 0 when missing or invalid.
	 */
	private static function posted_date(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- every caller verifies its nonce first.
		$date = isset( $_POST['effective_date'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['effective_date'] ) ) : '';
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $date ) ) {
			return 0;
		}
		$time = strtotime( $date . ' 00:00:00 UTC' );

		return false === $time || gmdate( 'Y-m-d', $time ) !== $date ? 0 : (int) $time;
	}

	/**
	 * @return string
	 */
	private static function posted_reason(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- every caller verifies its nonce first.
		$reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['reason'] ) ) : '';

		return SupportActions::limit_reason( $reason );
	}

	/**
	 * Notice code for a result of PriceChange.
	 *
	 * @param string $result RESULT_* constant.
	 * @return string
	 */
	private static function code( string $result ): string {
		$codes = array(
			PriceChange::RESULT_OK                => 'price_change_announced',
			PriceChange::RESULT_NOT_AN_INCREASE   => 'price_change_not_an_increase',
			PriceChange::RESULT_NOTICE_TOO_SHORT  => 'price_change_notice_too_short',
			PriceChange::RESULT_ALREADY_ANNOUNCED => 'price_change_already_announced',
			PriceChange::RESULT_STALE             => 'price_change_stale',
			PriceChange::RESULT_NOTHING_ANNOUNCED => 'price_change_nothing_announced',
		);

		return $codes[ $result ] ?? 'price_change_failed';
	}

	/**
	 * Announce for one license.
	 *
	 * @return void
	 */
	public static function handle_announce(): void {
		Capabilities::require_cap( 'manage_licenses', __( 'You do not have permission to change licenses.', 'wp-license' ) );
		$license_id = isset( $_POST['license_id'] ) ? absint( $_POST['license_id'] ) : 0;
		check_admin_referer( 'wplc_admin_price_change_announce_' . $license_id );
		global $wpdb;

		$fallback = $license_id ? LicenseAdmin::detail_url( $license_id ) : '';
		$decimals = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT renewal_currency_decimals FROM %i WHERE id = %d', Schema::table_names()['licenses'], $license_id ) );
		$amount   = self::posted_amount( $decimals );
		$date     = self::posted_date();
		$reason   = self::posted_reason();
		if ( ! $license_id || ! $amount || ! $date || '' === $reason ) {
			AdminNotices::redirect( 'price_change_missing_input', $fallback );
		}

		AdminNotices::redirect( self::code( PriceChange::announce( $license_id, $amount, $date, $reason, get_current_user_id() ) ), $fallback );
	}

	/**
	 * Withdraw the announcement of one license.
	 *
	 * @return void
	 */
	public static function handle_withdraw(): void {
		Capabilities::require_cap( 'manage_licenses', __( 'You do not have permission to change licenses.', 'wp-license' ) );
		$license_id = isset( $_POST['license_id'] ) ? absint( $_POST['license_id'] ) : 0;
		check_admin_referer( 'wplc_admin_price_change_withdraw_' . $license_id );

		$fallback = $license_id ? LicenseAdmin::detail_url( $license_id ) : '';
		$reason   = self::posted_reason();
		if ( ! $license_id || '' === $reason ) {
			AdminNotices::redirect( 'price_change_missing_input', $fallback );
		}
		$result = PriceChange::withdraw( $license_id, $reason, get_current_user_id() );

		AdminNotices::redirect( PriceChange::RESULT_OK === $result ? 'price_change_withdrawn' : self::code( $result ), $fallback );
	}

	/**
	 * Announce for every license of a product and plan.
	 *
	 * @return void
	 */
	public static function handle_plan(): void {
		Capabilities::require_cap( 'manage_licenses', __( 'You do not have permission to change licenses.', 'wp-license' ) );
		check_admin_referer( 'wplc_admin_price_change_plan' );

		$url      = add_query_arg( array( 'page' => self::PAGE ), admin_url( 'admin.php' ) );
		$product  = isset( $_POST['product_key'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['product_key'] ) ) : '';
		$plan     = isset( $_POST['plan_key'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['plan_key'] ) ) : '';
		$currency = isset( $_POST['currency'] ) ? strtoupper( sanitize_text_field( wp_unslash( (string) $_POST['currency'] ) ) ) : '';
		$decimals = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;
		$amount   = self::posted_amount( $decimals );
		$date     = self::posted_date();
		$reason   = self::posted_reason();
		$confirm  = isset( $_POST['confirm_announce'] ) && '1' === sanitize_text_field( wp_unslash( (string) $_POST['confirm_announce'] ) );
		if ( 1 !== preg_match( '/^[a-z0-9][a-z0-9-]{1,99}$/D', $product ) || 1 !== preg_match( '/^[a-z0-9][a-z0-9-]{0,99}$/D', $plan ) || 1 !== preg_match( '/^[A-Z]{3}$/D', $currency ) || ! $amount || ! $date || '' === $reason ) {
			AdminNotices::redirect( 'price_change_missing_input', $url );
		}
		if ( ! $confirm ) {
			AdminNotices::redirect( 'price_change_not_confirmed', $url );
		}
		if ( $date < time() + PriceChange::notice_days() * 86400 ) {
			AdminNotices::redirect( 'price_change_notice_too_short', $url );
		}

		$report  = PriceChange::announce_for_plan( $product, $plan, $amount, $currency, $date, $reason, get_current_user_id(), false, 0, 500 );
		$skipped = array_sum( $report['skipped'] );
		AdminNotices::redirect( $report['more'] ? 'price_change_plan_more' : 'price_change_plan_done', $url, array( $report['announced'], $skipped ) );
	}
}
