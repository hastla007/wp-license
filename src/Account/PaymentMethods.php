<?php
/**
 * Self-service for the payment method of the automatic renewal.
 *
 * @package WPLC
 */

namespace WPLC\Account;

use WPLC\Gateway\RecurringGatewayAdapter;
use WPLC\Infrastructure\Database\Schema;
use WPLC\License\AuditLog;
use WPLC\Notifications\Notifications;
use WPLC\Notifications\RenewalTerms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lets the customer pick one of the payment methods saved in WooCommerce for
 * the automatic renewal of a license, switch automatic renewal on later, and
 * reacts when a saved payment method is deleted.
 *
 * Works on WooCommerce payment tokens and the adapter contract only; it knows
 * nothing about a gateway. Saving a new card is done by the gateway under
 * My account > Payment methods.
 */
final class PaymentMethods {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'wplc_account_license_renewal', array( self::class, 'render' ), 10, 2 );
		// The switch-off itself is done by Maintenance\Orphans (one handler, customer-initiated deletions only).
		add_action( 'wplc/renewals_disabled_for_deleted_token', array( self::class, 'notice_after_token_deleted' ), 10, 1 );
	}

	/**
	 * Registered renewal adapters by gateway ID.
	 *
	 * @return array<string,RecurringGatewayAdapter>
	 */
	private static function adapters(): array {
		$adapters = apply_filters( 'wplc_recurring_gateway_adapters', array() );
		$by_id    = array();
		foreach ( is_array( $adapters ) ? $adapters : array() as $adapter ) {
			if ( $adapter instanceof RecurringGatewayAdapter ) {
				$by_id[ $adapter->gateway_id() ] = $adapter;
			}
		}
		return $by_id;
	}

	/**
	 * Saved payment methods of a customer that can pay the renewal of a license.
	 *
	 * A license that renews (or renewed) automatically offers the tokens of its
	 * gateway; a license that never did offers those of every gateway with a
	 * renewal adapter.
	 *
	 * @param array<string,mixed> $license License row.
	 * @param int                 $user_id Customer.
	 * @return array<int,\WC_Payment_Token> Token ID => token.
	 */
	public static function usable_tokens( array $license, int $user_id ): array {
		if ( $user_id <= 0 || ! class_exists( '\\WC_Payment_Tokens' ) ) {
			return array();
		}

		$adapters = self::adapters();
		$gateway  = (string) ( $license['payment_gateway_id'] ?? '' );
		if ( '' !== $gateway ) {
			$adapters = isset( $adapters[ $gateway ] ) ? array( $gateway => $adapters[ $gateway ] ) : array();
		}

		$usable = array();
		foreach ( $adapters as $gateway_id => $adapter ) {
			foreach ( \WC_Payment_Tokens::get_customer_tokens( $user_id, (string) $gateway_id ) as $token ) {
				if ( ! $token instanceof \WC_Payment_Token ) {
					continue;
				}
				$token_id = (int) $token->get_id();
				if ( (int) $token->get_user_id() === $user_id && $adapter->supports_saved_token( $token_id, $user_id ) ) {
					$usable[ $token_id ] = $token;
				}
			}
		}
		return $usable;
	}

	/**
	 * Print the payment method of the automatic renewal and the forms to
	 * change it or to switch automatic renewal on.
	 *
	 * @param mixed $license License row.
	 * @param mixed $view    Display values of the license.
	 * @return void
	 */
	public static function render( $license, $view = array() ): void {
		if ( ! is_array( $license ) || empty( $license['id'] ) || ! is_user_logged_in() ) {
			return;
		}
		$user_id    = get_current_user_id();
		$license_id = (int) $license['id'];
		$status     = (string) ( $license['status'] ?? '' );
		if ( (int) ( $license['customer_id'] ?? 0 ) !== $user_id || ! in_array( $status, array( 'active', 'past_due' ), true ) ) {
			return;
		}

		$tokens  = self::usable_tokens( $license, $user_id );
		$current = (int) ( $license['payment_token_id'] ?? 0 );
		$title   = is_array( $view ) && isset( $view['title'] ) ? (string) $view['title'] : (string) ( $license['product_key'] ?? '' );
		$name    = '' !== (string) ( $license['key_prefix'] ?? '' )
			/* translators: 1: product name, 2: license reference such as WPLC-1A2B3C4D. */
			? sprintf( __( '%1$s, license %2$s', 'wp-license' ), $title, (string) $license['key_prefix'] )
			: $title;

		if ( ! empty( $license['renewal_enabled'] ) ) {
			self::render_change_form( $license_id, $tokens, $current, $name );
			return;
		}

		// Switching on is offered for a running, not cancelled license with a price and a usable payment method.
		if ( 'active' !== $status || ! empty( $license['cancel_at_period_end'] ) || (int) ( $license['renewal_amount_minor'] ?? 0 ) <= 0 || ! $tokens ) {
			return;
		}
		$paid_until = empty( $license['paid_until'] ) ? 0 : (int) strtotime( (string) $license['paid_until'] . ' UTC' );
		if ( $paid_until <= time() ) {
			return;
		}
		self::render_enable_form( $license, $tokens, $name );
	}

	/**
	 * Print the current payment method and, when there are others, a form to change it.
	 *
	 * @param int                          $license_id License ID.
	 * @param array<int,\WC_Payment_Token> $tokens     Usable tokens.
	 * @param int                          $current    Token ID stored on the license.
	 * @param string                       $name       Accessible name of the license.
	 * @return void
	 */
	private static function render_change_form( int $license_id, array $tokens, int $current, string $name ): void {
		echo '<div class="wplc-payment-method">';
		echo '<p><strong>' . esc_html__( 'Payment method:', 'wp-license' ) . '</strong> ';
		if ( isset( $tokens[ $current ] ) ) {
			echo esc_html( self::token_label( $tokens[ $current ] ) );
		} else {
			echo esc_html__( 'The saved payment method is no longer available. Please choose another one, otherwise the renewal payment will fail.', 'wp-license' );
		}
		echo '</p>';

		$others = $tokens;
		unset( $others[ $current ] );
		if ( $others ) {
			$field_id = 'wplc-token-' . $license_id;
			CustomerPortal::open_form( 'wplc_set_payment_method', array( 'license_id' => (string) $license_id ), 'wplc_set_payment_method_' . $license_id, '', 'wplc-payment-method-form' );
			echo '<label for="' . esc_attr( $field_id ) . '">' . esc_html__( 'Use another saved payment method:', 'wp-license' ) . '</label> ';
			self::token_select( $field_id, $others );
			echo ' ';
			CustomerPortal::submit_button( __( 'Change payment method', 'wp-license' ), $name );
			echo '</form>';
		}
		if ( function_exists( 'wc_get_account_endpoint_url' ) ) {
			echo '<p class="wplc-payment-method-hint">' . esc_html__( 'To use a new card, save it first:', 'wp-license' ) . ' <a href="' . esc_url( wc_get_account_endpoint_url( 'payment-methods' ) ) . '">' . esc_html__( 'Payment methods', 'wp-license' ) . '</a></p>';
		}
		echo '</div>';
	}

	/**
	 * Print the form that switches automatic renewal on, with the terms the customer agrees to.
	 *
	 * @param array<string,mixed>          $license License row.
	 * @param array<int,\WC_Payment_Token> $tokens  Usable tokens.
	 * @param string                       $name    Accessible name of the license.
	 * @return void
	 */
	private static function render_enable_form( array $license, array $tokens, string $name ): void {
		$license_id = (int) $license['id'];
		$field_id   = 'wplc-enable-token-' . $license_id;
		$consent_id = 'wplc-enable-consent-' . $license_id;

		echo '<details class="wplc-enable-renewal"><summary>' . esc_html__( 'Switch on automatic renewal', 'wp-license' ) . '</summary>';
		CustomerPortal::open_form( 'wplc_enable_renewal', array( 'license_id' => (string) $license_id ), 'wplc_enable_renewal_' . $license_id, '', 'wplc-enable-renewal-form' );
		echo '<p><label for="' . esc_attr( $field_id ) . '">' . esc_html__( 'Payment method:', 'wp-license' ) . '</label> ';
		self::token_select( $field_id, $tokens );
		echo '</p>';
		// Never preselected: the customer authorizes future charges here.
		echo '<p><input type="checkbox" name="wplc_renewal_consent" value="1" id="' . esc_attr( $consent_id ) . '" required> ';
		echo '<label for="' . esc_attr( $consent_id ) . '">' . esc_html( self::consent_text( $license ) ) . '</label></p>';
		CustomerPortal::submit_button( __( 'Switch on automatic renewal', 'wp-license' ), $name );
		echo '</form></details>';
	}

	/**
	 * Wording of the authorization the customer gives when switching automatic renewal on.
	 *
	 * @param array<string,mixed> $license License row.
	 * @return string
	 */
	public static function consent_text( array $license ): string {
		$amount    = RenewalTerms::amount_text( RenewalTerms::gross( $license ) );
		// The first charge is due when the paid period ends.
		$charge_at = RenewalTerms::charge_timestamp( array( 'paid_until' => $license['paid_until'] ?? '' ) );

		return sprintf(
			/* translators: 1: formatted amount, 2: date, 3: interval such as "every year". */
			__( 'I authorize the renewal of this license with the selected payment method: %1$s on %2$s and then %3$s, until I cancel. I can cancel at any time in my account under "Licenses".', 'wp-license' ),
			$amount,
			$charge_at > 0 ? Notifications::format_date( $charge_at ) : '',
			RenewalTerms::interval_label( (string) ( $license['billing_interval'] ?? 'year' ) )
		);
	}

	/**
	 * Print a select with saved payment methods.
	 *
	 * @param string                       $field_id ID attribute.
	 * @param array<int,\WC_Payment_Token> $tokens   Tokens.
	 * @return void
	 */
	private static function token_select( string $field_id, array $tokens ): void {
		echo '<select name="payment_token_id" id="' . esc_attr( $field_id ) . '">';
		foreach ( $tokens as $token_id => $token ) {
			echo '<option value="' . esc_attr( (string) $token_id ) . '">' . esc_html( self::token_label( $token ) ) . '</option>';
		}
		echo '</select>';
	}

	/**
	 * Display name of a saved payment method, e.g. "Visa ending in 4242 (expires 04/27)".
	 *
	 * @param \WC_Payment_Token $token Token.
	 * @return string
	 */
	public static function token_label( $token ): string {
		$label = is_callable( array( $token, 'get_display_name' ) ) ? wp_strip_all_tags( (string) $token->get_display_name() ) : '';
		if ( '' === trim( $label ) ) {
			/* translators: %d: number of the saved payment method. */
			$label = sprintf( __( 'Saved payment method #%d', 'wp-license' ), (int) $token->get_id() );
		}
		return $label;
	}

	/**
	 * Handle the two payment method forms.
	 *
	 * The caller has verified the login and the nonce of the license.
	 *
	 * @param string $action     wplc_set_payment_method or wplc_enable_renewal.
	 * @param int    $license_id License ID.
	 * @return string Key of CustomerPortal::notice_messages().
	 */
	public static function handle_post( string $action, int $license_id ): string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified by CustomerPortal::handle_front_post().
		$token_id = isset( $_POST['payment_token_id'] ) ? absint( wp_unslash( $_POST['payment_token_id'] ) ) : 0;
		$consent  = ! empty( $_POST['wplc_renewal_consent'] );
		// phpcs:enable
		$user_id = get_current_user_id();

		if ( 'wplc_enable_renewal' === $action ) {
			if ( ! $consent ) {
				return 'renewal_consent_missing';
			}
			return self::enable_renewal( $license_id, $token_id, $user_id ) ? 'renewal_enabled' : 'renewal_enable_failed';
		}

		return self::set_token( $license_id, $token_id, $user_id ) ? 'payment_method_saved' : 'payment_method_failed';
	}

	/**
	 * Fetch a license the user owns.
	 *
	 * @param int $license_id License ID.
	 * @param int $user_id    Customer.
	 * @return array<string,mixed>|null
	 */
	private static function owned_license( int $license_id, int $user_id ): ?array {
		$license = Notifications::get_license( $license_id );

		return $license && $user_id > 0 && (int) $license['customer_id'] === $user_id ? $license : null;
	}

	/**
	 * Store another saved payment method for the automatic renewal of a license.
	 *
	 * @param int $license_id License ID.
	 * @param int $token_id   WooCommerce payment token ID.
	 * @param int $user_id    Acting customer; must own the license and the token.
	 * @return bool
	 */
	public static function set_token( int $license_id, int $token_id, int $user_id ): bool {
		global $wpdb;

		$license = self::owned_license( $license_id, $user_id );
		if ( ! $license || empty( $license['renewal_enabled'] ) || ! in_array( (string) $license['status'], array( 'active', 'past_due' ), true ) ) {
			return false;
		}
		$tokens = self::usable_tokens( $license, $user_id );
		if ( ! isset( $tokens[ $token_id ] ) ) {
			return false;
		}
		$old_token = (int) $license['payment_token_id'];
		if ( $old_token === $token_id ) {
			return true;
		}

		// Compare-and-set on the old token: a parallel change or a lifecycle transition wins.
		$tables  = Schema::table_names();
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET payment_token_id = %d, payment_gateway_id = %s, updated_at = %s WHERE id = %d AND customer_id = %d AND renewal_enabled = 1 AND payment_token_id = %d AND status = %s',
				$tables['licenses'],
				$token_id,
				(string) $tokens[ $token_id ]->get_gateway_id(),
				gmdate( 'Y-m-d H:i:s' ),
				$license_id,
				$user_id,
				$old_token,
				(string) $license['status']
			)
		);
		if ( 1 !== (int) $updated ) {
			return false;
		}

		AuditLog::record(
			'payment_method_changed',
			'license',
			$license_id,
			'Customer chose another saved payment method for the automatic renewal',
			// "payment_method_id": the audit log redacts every field with "token" in its name.
			array( 'payment_method_id' => $old_token ),
			array( 'payment_method_id' => $token_id ),
			$user_id
		);

		return true;
	}

	/**
	 * Switch automatic renewal on for a license that does not renew automatically.
	 *
	 * The caller must have collected the customer's explicit consent to the
	 * terms of consent_text(); the terms are recorded in the audit log.
	 *
	 * @param int $license_id License ID.
	 * @param int $token_id   WooCommerce payment token ID.
	 * @param int $user_id    Acting customer; must own the license and the token.
	 * @return bool
	 */
	public static function enable_renewal( int $license_id, int $token_id, int $user_id ): bool {
		global $wpdb;

		$license = self::owned_license( $license_id, $user_id );
		if (
			! $license
			|| 'active' !== (string) $license['status']
			|| ! empty( $license['renewal_enabled'] )
			|| ! empty( $license['cancel_at_period_end'] )
			|| (int) $license['renewal_amount_minor'] <= 0
			|| empty( $license['paid_until'] )
			|| (string) $license['paid_until'] <= gmdate( 'Y-m-d H:i:s' )
		) {
			return false;
		}
		$tokens = self::usable_tokens( $license, $user_id );
		if ( ! isset( $tokens[ $token_id ] ) ) {
			return false;
		}

		$tables  = Schema::table_names();
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET renewal_enabled = 1, payment_token_id = %d, payment_gateway_id = %s, next_payment_at = paid_until, updated_at = %s WHERE id = %d AND customer_id = %d AND status = %s AND renewal_enabled = 0 AND cancel_at_period_end = 0 AND paid_until = %s',
				$tables['licenses'],
				$token_id,
				(string) $tokens[ $token_id ]->get_gateway_id(),
				gmdate( 'Y-m-d H:i:s' ),
				$license_id,
				$user_id,
				'active',
				(string) $license['paid_until']
			)
		);
		if ( 1 !== (int) $updated ) {
			return false;
		}

		// Evidence of what the customer agreed to: wording (hash), language, amount, interval, first charge.
		$text  = self::consent_text( $license );
		$gross = RenewalTerms::gross( $license );
		AuditLog::record(
			'renewal_enabled',
			'license',
			$license_id,
			'Customer switched on automatic renewal in the account',
			array( 'renewal_enabled' => 0 ),
			array(
				'renewal_enabled'     => 1,
				'payment_method_id'   => $token_id,
				'consent_text_sha256' => hash( 'sha256', $text ),
				'consent_locale'      => function_exists( 'determine_locale' ) ? (string) determine_locale() : '',
				'amount_minor'        => (int) $gross['minor'],
				'currency'            => (string) $gross['currency'],
				'interval'            => (string) $license['billing_interval'],
				'first_charge_at'     => (string) $license['paid_until'],
			),
			$user_id
		);

		$scheduler = '\\WPLC\\Billing\\RenewalScheduler';
		if ( class_exists( $scheduler ) && is_callable( array( $scheduler, 'schedule_next' ) ) ) {
			call_user_func( array( $scheduler, 'schedule_next' ), $license_id );
		}

		return true;
	}

	/**
	 * Tell the customer who deleted a saved payment method what that means for the licenses.
	 *
	 * The licenses stay valid until the end of the paid period; the customer
	 * gets the usual expiry reminders and can renew by hand or switch
	 * automatic renewal on again with another payment method.
	 *
	 * @param mixed $license_ids Licenses whose automatic renewal was switched off.
	 * @return void
	 */
	public static function notice_after_token_deleted( $license_ids = array() ): void {
		global $wpdb;

		$license_ids = array_values( array_filter( array_map( 'absint', (array) $license_ids ) ) );
		$user_id     = get_current_user_id();
		if ( ! $license_ids || $user_id <= 0 ) {
			return;
		}
		$tables  = Schema::table_names();
		$changed = 0;
		foreach ( $license_ids as $license_id ) {
			$changed += (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE id = %d AND customer_id = %d', $tables['licenses'], $license_id, $user_id ) );
		}

		if ( $changed > 0 ) {
			CustomerPortal::add_session_notice(
				sprintf(
					/* translators: %d: number of licenses. */
					_n(
						'The deleted payment method was used for the automatic renewal of %d license. Automatic renewal is now switched off for it: the license ends at the end of the paid period unless you renew it or switch automatic renewal on again under "Licenses".',
						'The deleted payment method was used for the automatic renewal of %d licenses. Automatic renewal is now switched off for them: the licenses end at the end of the paid period unless you renew them or switch automatic renewal on again under "Licenses".',
						$changed,
						'wp-license'
					),
					$changed
				),
				'notice'
			);
		}
	}
}
