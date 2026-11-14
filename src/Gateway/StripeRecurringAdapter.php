<?php
/**
 * Stripe card-token adapter for the official WooCommerce Stripe gateway.
 *
 * @package WPLC
 */

namespace WPLC\Gateway;

use WPLC\Billing\RecurringConsent;
use WPLC\Billing\RenewalOrder;
use WPLC\Compatibility\Requirements;
use WPLC\Infrastructure\Database\Schema;
use WPLC\License\AuditLog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Charges a customer-owned Stripe payment method through the gateway API.
 *
 * This adapter intentionally supports saved card PaymentMethods only. Other
 * Stripe payment methods may require mandates, delayed settlement, or customer
 * interaction and are rejected until they have a reviewed implementation.
 *
 * The gateway offers no public API for this. The adapter calls its internals
 * (`WC_Stripe_API::request()` and `::retrieve()`, `WC_Stripe_Helper::get_stripe_amount()`,
 * the user option `_stripe_customer_id`, the order metas `_stripe_source_id`,
 * `_stripe_intent_id`, `_stripe_lock_payment`), read against the gateway source of
 * versions 9.6.0 and 11.2.0. Requirements::stripe_adapter_status() switches the
 * adapter off when the gateway is missing, too old or has lost one of them.
 *
 * Nothing in this class has run against a Stripe account yet; see
 * docs/STRIPE-TEST-PLAN.md.
 */
final class StripeRecurringAdapter implements RecurringGatewayAdapter, PaymentReferenceAdapter {

	/** Order meta: the gateway's own five-minute payment lock (`{expiry}` or `{expiry}|{intent}`). */
	public const META_GATEWAY_LOCK = '_stripe_lock_payment';

	/** Order meta: counter that changes the idempotency key after a request Stripe definitely refused. */
	public const META_KEY_SALT = '_wplc_stripe_key_salt';

	/** Order meta: mode (live or test) of the last charge attempt. */
	public const META_MODE = '_wplc_stripe_mode';

	/** Order meta: set once the missing renewal was explained on the order (B-61). */
	public const META_UNAVAILABLE_NOTED = '_wplc_renewal_unavailable_noted';

	/** Seconds a renewal waits while a payment settles asynchronously (`processing`). */
	public const PROCESSING_WAIT_SECONDS = 21600;

	/** Seconds a renewal waits after Stripe's rate limit. */
	public const RATE_LIMIT_WAIT_SECONDS = 1800;

	/** Seconds within which a failed PaymentIntent counts as the answer to the current attempt. */
	public const ATTEMPT_ANSWER_WINDOW = 86400;

	/**
	 * Decline codes after which the card must not be tried again (B-62).
	 *
	 * Card network rules forbid retrying a card that was reported lost or
	 * stolen, flagged as fraud, or whose authorization was revoked.
	 *
	 * @var string[]
	 */
	public const HARD_DECLINE_CODES = array(
		'lost_card',
		'stolen_card',
		'fraudulent',
		'pickup_card',
		'restricted_card',
		'merchant_blacklist',
		'security_violation',
		'revocation_of_authorization',
		'revocation_of_all_authorizations',
		'stop_payment_order',
	);

	/**
	 * Register adapter and payment-token lookup.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_filter( 'wplc_recurring_gateway_adapters', array( self::class, 'register_adapter' ) );
		add_filter( 'wplc_payment_token_id_for_order', array( self::class, 'token_id_for_order' ), 10, 2 );
		// B-58: a recovery payment on the pay page saves its card and becomes the renewal card.
		add_filter( 'wc_stripe_force_save_payment_method', array( self::class, 'force_save_for_renewal_order' ), 20, 2 );
		add_action( 'woocommerce_payment_complete', array( self::class, 'adopt_payment_method_from_order' ), 30, 1 );
		add_action( 'woocommerce_order_status_processing', array( self::class, 'adopt_payment_method_from_order' ), 30, 1 );
		add_action( 'woocommerce_order_status_completed', array( self::class, 'adopt_payment_method_from_order' ), 30, 1 );
		// B-57: before the listeners that switch automatic renewal off for a deleted token.
		add_action( 'woocommerce_payment_token_deleted', array( self::class, 'keep_license_on_token_sync' ), 1, 2 );
		// B-60: gateway 9.6 cancels a renewal order that was refunded in the Stripe dashboard.
		add_action( 'woocommerce_order_status_cancelled', array( self::class, 'on_order_cancelled' ), 5, 1 );
		// B-147: the gateway's webhook must not process the PaymentIntents of this adapter a second time.
		add_filter( 'wc_stripe_allowed_payment_processing_statuses', array( self::class, 'filter_gateway_webhook_statuses' ), 20, 2 );
		RenewalOrderEmails::register_hooks();
	}

	/**
	 * Add this adapter to the shared registry.
	 *
	 * @param array $adapters Existing adapters.
	 * @return array
	 */
	public static function register_adapter( array $adapters ): array {
		$adapters[] = new self();
		return $adapters;
	}

	/**
	 * @inheritDoc
	 */
	public function gateway_id(): string {
		return 'stripe';
	}

	/**
	 * @inheritDoc
	 */
	public function supports_checkout(): bool {
		// A first-time buyer has no saved card yet; the card is saved with the order
		// when consent is given, so checkout support only depends on the gateway.
		$status = Requirements::stripe_adapter_status();
		return $status['ok'];
	}

	/**
	 * @inheritDoc
	 */
	public function supports_saved_token( int $token_id, int $customer_id ): bool {
		if ( ! $token_id || ! $customer_id || ! class_exists( 'WC_Payment_Tokens' ) || ! class_exists( 'WC_Stripe_API' ) ) {
			return false;
		}
		$token = \WC_Payment_Tokens::get( $token_id );
		if ( ! $token || 'stripe' !== $token->get_gateway_id() || (int) $token->get_user_id() !== $customer_id ) {
			return false;
		}
		// The gateway stores Link and other wallets under the same gateway ID; only cards are supported.
		if ( 'CC' !== $token->get_type() ) {
			return false;
		}
		$payment_method = (string) $token->get_token();
		if ( 0 !== strpos( $payment_method, 'pm_' ) ) {
			return false;
		}
		$customer = (string) get_user_option( '_stripe_customer_id', $customer_id );
		return 0 === strpos( $customer, 'cus_' );
	}

	/**
	 * @inheritDoc
	 */
	public function current_mode(): string {
		return self::mode();
	}

	/**
	 * Mode the gateway runs in: `live`, `test`, or empty when the gateway is not loaded.
	 *
	 * @return string
	 */
	public static function mode(): string {
		$mode = '';
		try {
			if ( class_exists( 'WC_Stripe_Mode' ) && is_callable( array( 'WC_Stripe_Mode', 'is_test' ) ) ) {
				$mode = \WC_Stripe_Mode::is_test() ? 'test' : 'live';
			} elseif ( class_exists( 'WC_Stripe_Helper' ) && is_callable( array( 'WC_Stripe_Helper', 'get_stripe_settings' ) ) ) {
				$settings = \WC_Stripe_Helper::get_stripe_settings();
				$mode = is_array( $settings ) && 'yes' === ( $settings['testmode'] ?? 'no' ) ? 'test' : 'live';
			}
		} catch ( \Throwable $exception ) {
			$mode = '';
		}

		/**
		 * Filters the mode the Stripe gateway is considered to run in.
		 *
		 * @param string $mode `live`, `test` or an empty string when unknown.
		 */
		return PaymentReference::normalize_mode( (string) apply_filters( 'wplc_stripe_mode', $mode ) );
	}

	/**
	 * Identifier of this site in PaymentIntent metadata (`wplc_site`, B-29).
	 *
	 * Several sites (a multisite network, a staging copy) can share one Stripe
	 * account. Events of another site are acknowledged and ignored.
	 *
	 * @return string 16 hexadecimal characters.
	 */
	public static function site_id(): string {
		$home = class_exists( '\\WPLC\\Maintenance\\SiteGuard' )
			? \WPLC\Maintenance\SiteGuard::normalize_home( (string) get_option( 'home', '' ) )
			: strtolower( untrailingslashit( (string) get_option( 'home', '' ) ) );
		$site_id = substr( hash( 'sha256', $home . '|' . (int) get_current_blog_id() ), 0, 16 );

		/**
		 * Filters the site identifier sent to Stripe and expected in webhook events.
		 *
		 * @param string $site_id Default: hash of the home URL and the blog ID.
		 */
		$filtered = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) apply_filters( 'wplc_stripe_site_id', $site_id ) );

		return '' !== (string) $filtered ? substr( (string) $filtered, 0, 40 ) : $site_id;
	}

	/**
	 * Stripe customer and payment method of a saved card token.
	 *
	 * @param int $token_id WooCommerce payment token ID.
	 * @param int $customer_id Customer ID.
	 * @return array{customer_ref:string,method_ref:string} Empty strings when the token is not a chargeable card.
	 */
	public function token_reference( int $token_id, int $customer_id ): array {
		$empty = array(
			'customer_ref' => '',
			'method_ref' => '',
		);
		if ( ! $this->supports_saved_token( $token_id, $customer_id ) ) {
			return $empty;
		}
		$token = \WC_Payment_Tokens::get( $token_id );

		return array(
			'customer_ref' => (string) get_user_option( '_stripe_customer_id', $customer_id ),
			'method_ref' => (string) $token->get_token(),
		);
	}

	/**
	 * Find the customer's saved card for a Stripe payment method ID.
	 *
	 * @param int    $customer_id Customer ID.
	 * @param string $method_ref Stripe payment method ID (`pm_…`).
	 * @return int Token ID, zero when the customer has no such card.
	 */
	public function find_token( int $customer_id, string $method_ref ): int {
		if ( ! $customer_id || 0 !== strpos( $method_ref, 'pm_' ) || ! class_exists( 'WC_Payment_Tokens' ) ) {
			return 0;
		}
		// Asking for the customer's tokens makes the gateway synchronise them with Stripe.
		foreach ( \WC_Payment_Tokens::get_customer_tokens( $customer_id, 'stripe' ) as $token ) {
			if ( $method_ref === (string) $token->get_token() && $this->supports_saved_token( (int) $token->get_id(), $customer_id ) ) {
				return (int) $token->get_id();
			}
		}

		return 0;
	}

	/**
	 * Classify a Stripe error object (B-62, A-15, A-16, B-146). Pure.
	 *
	 * @param array<string,string> $error Keys type, code, decline_code, advice_code, outcome_type.
	 * @return array{kind:string,code:string,retryable:bool} kind is one of `authentication`,
	 *         `declined`, `configuration`, `rate_limited`, `unknown`. `code` is the error
	 *         code stored on the renewal.
	 */
	public static function classify_error( array $error ): array {
		$type = sanitize_key( (string) ( $error['type'] ?? '' ) );
		$code = sanitize_key( (string) ( $error['code'] ?? '' ) );
		$decline = sanitize_key( (string) ( $error['decline_code'] ?? '' ) );
		$advice = sanitize_key( (string) ( $error['advice_code'] ?? '' ) );
		$outcome = sanitize_key( (string) ( $error['outcome_type'] ?? '' ) );

		if ( in_array( 'authentication_required', array( $code, $decline ), true ) || 'payment_intent_authentication_failure' === $code ) {
			return array(
				'kind' => 'authentication',
				'code' => 'renewal_manual_action_required',
				'retryable' => false,
			);
		}
		if ( 'idempotency_error' === $type ) {
			// The key was used with other parameters. Repeating can never succeed.
			return array(
				'kind' => 'configuration',
				'code' => 'stripe_idempotency_error',
				'retryable' => false,
			);
		}
		if ( in_array( $code, array( 'idempotency_key_in_use', 'lock_timeout' ), true ) ) {
			// Another request with this key is still running.
			return array(
				'kind' => 'unknown',
				'code' => 'renewal_result_unknown',
				'retryable' => true,
			);
		}
		if ( 'rate_limit' === $code || 'rate_limit_error' === $type ) {
			return array(
				'kind' => 'rate_limited',
				'code' => 'stripe_rate_limited',
				'retryable' => true,
			);
		}
		if ( 'card_error' === $type ) {
			$hard = in_array( $decline, self::HARD_DECLINE_CODES, true ) || 'do_not_try_again' === $advice || 'blocked' === $outcome;
			$detail = '' !== $decline ? $decline : ( '' !== $code ? $code : 'payment_failed' );
			if ( 'blocked' === $outcome && '' === $decline ) {
				$detail = 'blocked';
			}
			return array(
				'kind' => 'declined',
				'code' => substr( 'stripe_' . $detail, 0, 100 ),
				'retryable' => ! $hard,
			);
		}
		if ( in_array( $type, array( 'invalid_request_error', 'authentication_error', 'permission_error' ), true ) ) {
			// Wrong account or mode, deleted customer or payment method, invalid key: the shop has to act.
			return array(
				'kind' => 'configuration',
				'code' => substr( 'stripe_' . ( '' !== $code ? $code : $type ), 0, 100 ),
				'retryable' => false,
			);
		}

		// api_error, api_connection_error and anything unknown leave the outcome open.
		return array(
			'kind' => 'unknown',
			'code' => 'renewal_result_unknown',
			'retryable' => true,
		);
	}

	/**
	 * Key sent to Stripe for an attempt. Pure.
	 *
	 * The attempt key of the renewal, bound to what is charged. A changed
	 * payment method or amount gets its own key instead of Stripe's
	 * `idempotency_error`; the salt changes after a request that Stripe
	 * definitely refused without creating a payment, so the corrected request
	 * is not answered with the stored refusal.
	 *
	 * @param string $attempt_key Key of the attempt: `{renewal key}:{attempt_count}`.
	 * @param string $customer Stripe customer ID.
	 * @param string $payment_method Stripe payment method ID.
	 * @param int    $amount Amount in minor units.
	 * @param string $currency Currency code.
	 * @param int    $salt Refusal counter.
	 * @return string
	 */
	public static function stripe_idempotency_key( string $attempt_key, string $customer, string $payment_method, int $amount, string $currency, int $salt = 0 ): string {
		$bound = substr( hash( 'sha256', implode( '|', array( $customer, $payment_method, $amount, strtolower( $currency ), $salt ) ) ), 0, 16 );

		return substr( $attempt_key, 0, 200 ) . ':' . $bound;
	}

	/**
	 * @inheritDoc
	 */
	public function charge( $order, int $token_id, string $idempotency_key ) {
		if ( ! $order instanceof \WC_Order || ! $order->get_meta( '_wplc_renewal_id', true ) ) {
			return new \WP_Error( 'stripe_renewal_order_invalid', __( 'The renewal order could not be verified.', 'wp-license' ) );
		}
		// B-01: last look directly before money moves; the pause may have been set after the job started.
		if ( class_exists( '\\WPLC\\Maintenance\\SiteGuard' ) && \WPLC\Maintenance\SiteGuard::billing_paused() ) {
			return self::configuration_error( 'renewal_billing_paused', __( 'Billing is paused on this site. The renewal is not charged.', 'wp-license' ) );
		}
		$customer_id = (int) $order->get_customer_id();
		if ( ! $this->supports_saved_token( $token_id, $customer_id ) || 0 >= (float) $order->get_total() ) {
			return self::configuration_error( 'renewal_payment_method_missing', __( 'A saved Stripe card is unavailable for this customer.', 'wp-license' ) );
		}
		$token = \WC_Payment_Tokens::get( $token_id );
		$customer = (string) get_user_option( '_stripe_customer_id', $customer_id );
		$payment_method = (string) $token->get_token();
		try {
			// Throws since gateway 11.2.0 when the amount exceeds what Stripe accepts.
			$amount = \WC_Stripe_Helper::get_stripe_amount( $order->get_total(), $order->get_currency() );
		} catch ( \Throwable $exception ) {
			$amount = 0;
		}
		if ( ! is_int( $amount ) || $amount <= 0 ) {
			return new \WP_Error( 'stripe_amount_invalid', __( 'The renewal amount is not valid for Stripe.', 'wp-license' ) );
		}
		$currency = strtolower( (string) $order->get_currency() );

		// B-59: the customer may be paying this order on the gateway's pay page right now.
		$busy = $this->customer_payment_in_progress( $order );
		if ( $busy ) {
			return $busy;
		}

		// A-114, B-10, B-146: never send a charge before knowing what Stripe already has for this renewal.
		$mode = self::mode();
		$stamped_mode = PaymentReference::normalize_mode( (string) $order->get_meta( self::META_MODE, true ) );
		if ( '' !== $stamped_mode && '' !== $mode && $stamped_mode !== $mode ) {
			return self::configuration_error( 'renewal_gateway_mode_mismatch', __( 'The Stripe mode changed while this renewal was being charged. Check the payment in Stripe before charging again.', 'wp-license' ) );
		}
		$context = $this->renewal_context( $order );
		$known = $this->find_existing_intent( $order, $context, $amount, $currency, $idempotency_key );
		if ( is_wp_error( $known ) ) {
			return $known;
		}
		if ( is_object( $known ) ) {
			$result = $this->result_from_intent( $known, $order, $customer, $payment_method, $idempotency_key, true );
			if ( null !== $result ) {
				return $result;
			}
		}

		$request = array(
			'amount' => $amount,
			'currency' => $currency,
			'customer' => $customer,
			'payment_method' => $payment_method,
			// Without this Stripe enables automatic payment methods and rejects a confirmed
			// intent that has no return_url. The gateway sends the same for its own off-session charges.
			'payment_method_types' => array( 'card' ),
			'off_session' => 'true',
			'confirm' => 'true',
			'description' => self::description( $order ),
			'metadata' => self::metadata( $order, $context, $idempotency_key, $amount ),
		);
		$salt = absint( $order->get_meta( self::META_KEY_SALT, true ) );
		$stripe_key = self::stripe_idempotency_key( $idempotency_key, $customer, $payment_method, $amount, $currency, $salt );
		$idempotency_filter = static function ( $current, $body ) use ( $stripe_key ) {
			return ! empty( $body['metadata']['wplc_renewal_id'] ) ? $stripe_key : $current;
		};

		// Reload: the lock and the gateway's intent may have been written since the order was read.
		$order->read_meta_data( true );
		$busy = $this->customer_payment_in_progress( $order, false );
		if ( $busy ) {
			return $busy;
		}
		$order->update_meta_data( self::META_GATEWAY_LOCK, (string) ( time() + 5 * MINUTE_IN_SECONDS ) );
		if ( '' !== $mode ) {
			$order->update_meta_data( self::META_MODE, $mode );
		}
		$order->save_meta_data();

		add_filter( 'wc_stripe_idempotency_key', $idempotency_filter, 10, 2 );
		// No answer, or an answer that says nothing about the charge: the caller
		// repeats this attempt later instead of counting a failure. Before the
		// repeat, find_existing_intent() looks the payment up.
		$unknown = new \WP_Error( 'renewal_result_unknown', __( 'Stripe did not return a definite result for the renewal payment.', 'wp-license' ) );
		$response = null;
		try {
			$response = \WC_Stripe_API::request( $request, 'payment_intents', 'POST' );
		} catch ( \Throwable $exception ) {
			$response = null;
		} finally {
			remove_filter( 'wc_stripe_idempotency_key', $idempotency_filter, 10 );
			// The lock only covers the request; the customer must be able to pay right after a decline.
			$order->delete_meta_data( self::META_GATEWAY_LOCK );
			$order->save_meta_data();
		}

		if ( is_wp_error( $response ) || ! is_object( $response ) ) {
			return $unknown;
		}
		if ( ! empty( $response->error ) && is_object( $response->error ) ) {
			return $this->result_from_error( $response->error, $order );
		}
		$result = $this->result_from_intent( $response, $order, $customer, $payment_method, $idempotency_key, false );

		return null !== $result ? $result : new \WP_Error( 'stripe_payment_unconfirmed', __( 'Stripe did not confirm the renewal payment as succeeded.', 'wp-license' ) );
	}

	/**
	 * Turn a PaymentIntent into the adapter result.
	 *
	 * @param object    $intent Stripe PaymentIntent.
	 * @param \WC_Order $order Renewal order.
	 * @param string    $customer Stripe customer ID.
	 * @param string    $payment_method Stripe payment method ID.
	 * @param string    $attempt_key Key of the current attempt.
	 * @param bool      $looked_up True when the intent was found before charging, false for the answer to a charge.
	 * @return array|\WP_Error|null Null when a looked-up intent says nothing about the current attempt
	 *                              (an earlier, failed attempt): the charge is sent.
	 */
	private function result_from_intent( $intent, $order, string $customer, string $payment_method, string $attempt_key, bool $looked_up ) {
		$intent_id = sanitize_text_field( (string) ( $intent->id ?? '' ) );
		$status = sanitize_key( (string) ( $intent->status ?? '' ) );

		if ( 'succeeded' === $status ) {
			$this->remember_payment_intent( $order, $intent_id );
			$charge_id = self::charge_id( $intent );
			if ( '' === $charge_id ) {
				return new \WP_Error( 'stripe_transaction_missing', __( 'Stripe did not return a transaction reference.', 'wp-license' ) );
			}
			$intent_method = is_string( $intent->payment_method ?? null ) ? (string) $intent->payment_method : $payment_method;
			$fresh = wc_get_order( (int) $order->get_id() );
			if ( ! $fresh instanceof \WC_Order || ! $fresh->is_paid() ) {
				// An order the customer paid meanwhile keeps the references of that payment (B-184).
				self::store_gateway_meta( $order, $intent_id, $charge_id, $customer, $intent_method );
			}
			if ( $looked_up ) {
				$order->add_order_note(
					sprintf(
						/* translators: %s: Stripe PaymentIntent ID. */
						__( 'WP License Captain: Stripe already holds a successful payment for this renewal (%s). It was booked instead of charging again.', 'wp-license' ),
						$intent_id
					)
				);
			}
			return array(
				'success' => true,
				'transaction_id' => $charge_id,
				'payment_intent_id' => $intent_id,
			);
		}
		if ( 'processing' === $status ) {
			// B-63: the payment is on its way (bank debit and similar). Nobody has to
			// authenticate anything; payment_intent.succeeded books the renewal when it settles.
			$this->remember_payment_intent( $order, $intent_id );
			return new \WP_Error(
				'renewal_payment_processing',
				__( 'Stripe is still processing the renewal payment.', 'wp-license' ),
				array(
					'wait' => self::PROCESSING_WAIT_SECONDS,
					'payment_intent' => $intent_id,
				)
			);
		}
		if ( in_array( $status, array( 'requires_action', 'requires_confirmation', 'requires_capture' ), true ) ) {
			$this->remember_payment_intent( $order, $intent_id );
			return new \WP_Error( 'renewal_manual_action_required', __( 'The customer must authenticate this payment method before renewal can continue.', 'wp-license' ), array( 'payment_intent' => $intent_id ) );
		}
		if ( ! $looked_up ) {
			$this->remember_payment_intent( $order, $intent_id );
			return null;
		}

		// requires_payment_method or canceled: that payment failed. It answers the current attempt
		// only when it was created by it; an earlier attempt was already counted.
		$metadata = is_object( $intent->metadata ?? null ) ? $intent->metadata : new \stdClass();
		$same_attempt = (string) ( $metadata->wplc_attempt ?? '' ) === $attempt_key
			&& absint( $intent->created ?? 0 ) > time() - self::ATTEMPT_ANSWER_WINDOW;
		if ( $same_attempt && is_object( $intent->last_payment_error ?? null ) ) {
			return $this->result_from_error( $intent->last_payment_error, $order, $intent );
		}

		return null;
	}

	/**
	 * Turn a Stripe error object into the adapter result and keep its details on the order.
	 *
	 * @param object      $error Stripe error (`error` of a response or `last_payment_error` of an intent).
	 * @param \WC_Order   $order Renewal order.
	 * @param object|null $intent PaymentIntent the error belongs to, when known.
	 * @return \WP_Error
	 */
	private function result_from_error( $error, $order, $intent = null ): \WP_Error {
		$intent = is_object( $intent ) ? $intent : ( is_object( $error->payment_intent ?? null ) ? $error->payment_intent : null );
		$intent_id = sanitize_text_field( (string) ( $intent->id ?? '' ) );
		$this->remember_payment_intent( $order, $intent_id );

		$details = array(
			'type' => sanitize_key( (string) ( $error->type ?? '' ) ),
			'code' => sanitize_key( (string) ( $error->code ?? '' ) ),
			'decline_code' => sanitize_key( (string) ( $error->decline_code ?? '' ) ),
			'advice_code' => sanitize_key( (string) ( $error->advice_code ?? '' ) ),
			'outcome_type' => self::outcome_type( $error, $intent ),
		);
		$class = self::classify_error( $details );
		$data = array(
			'retryable' => $class['retryable'],
			'decline_code' => $details['decline_code'],
			'advice_code' => $details['advice_code'],
			'outcome_type' => $details['outcome_type'],
			'stripe_code' => $details['code'],
			'payment_intent' => $intent_id,
		);

		switch ( $class['kind'] ) {
			case 'authentication':
				return new \WP_Error( 'renewal_manual_action_required', __( 'The customer must authenticate this payment method before renewal can continue.', 'wp-license' ), $data );
			case 'unknown':
				return new \WP_Error( 'renewal_result_unknown', __( 'Stripe did not return a definite result for the renewal payment.', 'wp-license' ) );
			case 'rate_limited':
				// A-15: Stripe refused to look at the request. Nothing was charged and the customer is not at fault.
				return new \WP_Error( 'renewal_gateway_rate_limited', __( 'Stripe limited the number of requests. The renewal is tried again later.', 'wp-license' ), array( 'wait' => self::RATE_LIMIT_WAIT_SECONDS ) );
			case 'configuration':
				// A-16: Stripe refused the request itself. A corrected request must not get this stored answer.
				$order->update_meta_data( self::META_KEY_SALT, (string) ( absint( $order->get_meta( self::META_KEY_SALT, true ) ) + 1 ) );
				$order->update_meta_data( '_wplc_stripe_error_code', $class['code'] );
				$order->save_meta_data();
				$order->add_order_note(
					sprintf(
						/* translators: %s: Stripe error code. */
						__( 'WP License Captain: Stripe refused the renewal request (%s). This is a configuration problem of the shop, not a declined card. The customer was not charged.', 'wp-license' ),
						$class['code']
					)
				);
				return self::configuration_error( $class['code'], __( 'Stripe refused the renewal request because of the shop configuration.', 'wp-license' ), $data );
		}

		// B-62: keep why the card was declined; hard declines are never tried again.
		$order->update_meta_data( '_wplc_stripe_decline_code', $details['decline_code'] );
		$order->update_meta_data( '_wplc_stripe_advice_code', $details['advice_code'] );
		$order->update_meta_data( '_wplc_stripe_outcome_type', $details['outcome_type'] );
		$order->update_meta_data( '_wplc_stripe_error_code', $details['code'] );
		$order->save_meta_data();
		$order->add_order_note(
			sprintf(
				/* translators: 1: Stripe error code, 2: decline code, 3: advice code, 4: outcome type, 5: "yes" or "no". */
				__( 'WP License Captain: Stripe declined the renewal payment (code %1$s, decline code %2$s, advice %3$s, outcome %4$s). Further automatic attempts: %5$s.', 'wp-license' ),
				'' !== $details['code'] ? $details['code'] : '-',
				'' !== $details['decline_code'] ? $details['decline_code'] : '-',
				'' !== $details['advice_code'] ? $details['advice_code'] : '-',
				'' !== $details['outcome_type'] ? $details['outcome_type'] : '-',
				$class['retryable'] ? __( 'yes', 'wp-license' ) : __( 'no', 'wp-license' )
			)
		);

		return new \WP_Error( $class['code'], __( 'Stripe declined the renewal payment.', 'wp-license' ), $data );
	}

	/**
	 * Build a configuration error: the shop has to act, the customer's payment did not fail.
	 *
	 * @param string              $reason Reason code stored on the renewal.
	 * @param string              $message Message.
	 * @param array<string,mixed> $data Further error data.
	 * @return \WP_Error
	 */
	private static function configuration_error( string $reason, string $message, array $data = array() ): \WP_Error {
		$data['reason'] = sanitize_key( $reason );
		$data['retryable'] = false;

		return new \WP_Error( PaymentReference::CONFIGURATION_ERROR, $message, $data );
	}

	/**
	 * Radar outcome of the charge behind an error, when the answer contains it.
	 *
	 * @param object      $error Stripe error.
	 * @param object|null $intent PaymentIntent.
	 * @return string e.g. `blocked`, `issuer_declined`; empty when not delivered.
	 */
	private static function outcome_type( $error, $intent ): string {
		$candidates = array();
		if ( is_object( $error->charge ?? null ) ) {
			$candidates[] = $error->charge;
		}
		if ( is_object( $intent ) ) {
			if ( is_object( $intent->latest_charge ?? null ) ) {
				$candidates[] = $intent->latest_charge;
			}
			if ( is_object( $intent->charges ?? null ) && is_array( $intent->charges->data ?? null ) && $intent->charges->data ) {
				$candidates[] = end( $intent->charges->data );
			}
		}
		foreach ( $candidates as $charge ) {
			if ( is_object( $charge ) && is_object( $charge->outcome ?? null ) && '' !== (string) ( $charge->outcome->type ?? '' ) ) {
				return sanitize_key( (string) $charge->outcome->type );
			}
		}

		return '';
	}

	/**
	 * Charge ID of a PaymentIntent.
	 *
	 * `latest_charge` since API version 2022-11-15, the `charges` list before.
	 *
	 * @param object $intent PaymentIntent.
	 * @return string `ch_…`/`py_…`, empty when the intent has no charge.
	 */
	public static function charge_id( $intent ): string {
		$charge = $intent->latest_charge ?? '';
		if ( is_object( $charge ) ) {
			$charge = $charge->id ?? '';
		}
		if ( '' === (string) $charge && is_object( $intent->charges ?? null ) && is_array( $intent->charges->data ?? null ) && $intent->charges->data ) {
			$last = end( $intent->charges->data );
			$charge = is_object( $last ) ? ( $last->id ?? '' ) : '';
		}

		return sanitize_text_field( (string) $charge );
	}

	/**
	 * Write the metas the gateway itself writes for a paid order (A-20, B-60).
	 *
	 * The gateway refunds through `_stripe_charge_captured` and the transaction
	 * ID, and resolves `charge.refunded` and dispute webhooks through the
	 * charge and `_stripe_intent_id`. Without them a refund from WooCommerce
	 * fails on gateway 9.6 and a refund in the Stripe dashboard does not reach
	 * the order.
	 *
	 * @param \WC_Order $order Renewal order.
	 * @param string    $intent_id PaymentIntent ID.
	 * @param string    $charge_id Charge ID.
	 * @param string    $customer Stripe customer ID, empty to look it up.
	 * @param string    $payment_method Stripe payment method ID, empty when unknown.
	 * @return void
	 */
	public static function store_gateway_meta( $order, string $intent_id, string $charge_id, string $customer = '', string $payment_method = '' ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		if ( '' === $customer && $order->get_customer_id() ) {
			$customer = (string) get_user_option( '_stripe_customer_id', (int) $order->get_customer_id() );
		}
		if ( 0 === strpos( $intent_id, 'pi_' ) ) {
			$order->update_meta_data( '_stripe_intent_id', $intent_id );
			$order->update_meta_data( RenewalOrder::META_PAYMENT_INTENT, $intent_id );
		}
		if ( '' !== $charge_id ) {
			$order->update_meta_data( '_stripe_charge_captured', 'yes' );
			$order->update_meta_data( '_wplc_stripe_charge_id', $charge_id );
		}
		if ( 0 === strpos( $customer, 'cus_' ) ) {
			$order->update_meta_data( '_stripe_customer_id', $customer );
		}
		if ( 0 === strpos( $payment_method, 'pm_' ) ) {
			$order->update_meta_data( '_stripe_source_id', $payment_method );
			$order->update_meta_data( '_stripe_upe_payment_type', 'card' );
		}
		$order->update_meta_data( '_wplc_charged_by_adapter', 'stripe' );
		$order->save();
	}

	/**
	 * Description of the payment on the customer's statement and receipt (B-149, T-138).
	 *
	 * @param \WC_Order $order Renewal order.
	 * @return string
	 */
	public static function description( $order ): string {
		if ( class_exists( 'WC_Stripe_Helper' ) && is_callable( array( 'WC_Stripe_Helper', 'get_payment_intent_description' ) ) ) {
			try {
				$description = (string) \WC_Stripe_Helper::get_payment_intent_description( $order );
				if ( '' !== $description ) {
					return $description;
				}
			} catch ( \Throwable $exception ) {
				unset( $exception );
			}
		}

		return sprintf(
			/* translators: 1: shop name, 2: order number. */
			__( '%1$s - Order %2$s', 'wp-license' ),
			wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			$order->get_order_number()
		);
	}

	/**
	 * Metadata of a renewal PaymentIntent (B-147, B-29, B-10).
	 *
	 * `order_id`, `order_key`, `site_url`, `signature` and `payment_type` are what
	 * the gateway sends for its own payments, so the gateway's refund and dispute
	 * handling recognises the order. The `wplc_*` keys are ours.
	 *
	 * @param \WC_Order           $order Renewal order.
	 * @param array<string,mixed> $context Result of renewal_context().
	 * @param string              $attempt_key Key of the attempt.
	 * @param int                 $amount Amount in minor units.
	 * @return array<string,string>
	 */
	public static function metadata( $order, array $context, string $attempt_key, int $amount ): array {
		return array(
			'order_id' => (string) $order->get_id(),
			'order_key' => (string) $order->get_order_key(),
			'site_url' => esc_url_raw( (string) get_site_url() ),
			// Same format as WC_Stripe_Payment_Gateway::get_order_signature().
			'signature' => sprintf( '%d:%s', $order->get_id(), md5( implode( '-', array( absint( $order->get_id() ), $order->get_order_key(), (string) $order->get_customer_id(), $amount ) ) ) ),
			'payment_type' => 'recurring',
			'wplc_renewal_id' => (string) $context['renewal_id'],
			'wplc_license_id' => (string) $context['license_id'],
			'wplc_period' => (string) $context['period_start'],
			'wplc_site' => self::site_id(),
			'wplc_attempt' => substr( $attempt_key, 0, 200 ),
		);
	}

	/**
	 * Renewal, license and period of a renewal order.
	 *
	 * @param \WC_Order $order Renewal order.
	 * @return array{renewal_id:int,license_id:int,period_start:string}
	 */
	private function renewal_context( $order ): array {
		global $wpdb;

		$renewal_id = absint( $order->get_meta( '_wplc_renewal_id', true ) );
		$tables = Schema::table_names();
		$period = $renewal_id ? $wpdb->get_var( $wpdb->prepare( 'SELECT period_start FROM %i WHERE id = %d', $tables['renewals'], $renewal_id ) ) : '';

		return array(
			'renewal_id' => $renewal_id,
			'license_id' => absint( $order->get_meta( '_wplc_renewal_for_license', true ) ),
			'period_start' => is_string( $period ) ? $period : '',
		);
	}

	/**
	 * Whether the gateway's pay page is working on this order (B-59).
	 *
	 * @param \WC_Order $order Renewal order.
	 * @param bool      $ask_stripe Whether to look the gateway's PaymentIntent up.
	 * @return \WP_Error|null Error with `wait` when the charge must not be sent now.
	 */
	private function customer_payment_in_progress( $order, bool $ask_stripe = true ) {
		$busy = new \WP_Error(
			'renewal_order_in_use',
			__( 'The customer is paying this renewal order. The automatic charge waits.', 'wp-license' ),
			array( 'wait' => RenewalOrder::CUSTOMER_PAYMENT_QUIET_SECONDS )
		);
		$lock = explode( '|', (string) $order->get_meta( self::META_GATEWAY_LOCK, true ) );
		if ( (int) $lock[0] > 0 && time() <= (int) $lock[0] ) {
			return $busy;
		}
		$gateway_intent = (string) $order->get_meta( '_stripe_intent_id', true );
		if ( 0 !== strpos( $gateway_intent, 'pi_' ) || $gateway_intent === (string) $order->get_meta( RenewalOrder::META_PAYMENT_INTENT, true ) ) {
			return null;
		}
		if ( ! $ask_stripe ) {
			return null;
		}
		$intent = $this->retrieve( 'payment_intents/' . rawurlencode( $gateway_intent ) );
		if ( ! is_object( $intent ) ) {
			// Not knowing whether the customer's payment went through is no reason to charge.
			return $busy;
		}
		if ( ! empty( $intent->error ) ) {
			// An intent of another account or mode cannot be paid here any more.
			return null;
		}

		return in_array( (string) ( $intent->status ?? '' ), array( 'requires_action', 'processing', 'requires_capture', 'succeeded' ), true ) ? $busy : null;
	}

	/**
	 * Look for a PaymentIntent Stripe already holds for this renewal.
	 *
	 * First by the ID stored on the order, then by searching Stripe for the
	 * renewal ID in the metadata. The search also finds a payment this
	 * database knows nothing about, e.g. after an older backup was restored.
	 *
	 * @param \WC_Order           $order Renewal order.
	 * @param array<string,mixed> $context Result of renewal_context().
	 * @param int                 $amount Expected amount in minor units.
	 * @param string              $currency Expected currency, lower case.
	 * @param string              $attempt_key Key of the current attempt.
	 * @return object|\WP_Error|null Intent, null when Stripe has none, WP_Error when that is not known.
	 */
	private function find_existing_intent( $order, array $context, int $amount, string $currency, string $attempt_key ) {
		$unknown = new \WP_Error( 'renewal_result_unknown', __( 'Stripe could not be asked about earlier payments for this renewal.', 'wp-license' ) );
		$candidates = array();

		$stored = (string) $order->get_meta( RenewalOrder::META_PAYMENT_INTENT, true );
		if ( 0 === strpos( $stored, 'pi_' ) ) {
			$intent = $this->retrieve( 'payment_intents/' . rawurlencode( $stored ) );
			if ( ! is_object( $intent ) ) {
				return $unknown;
			}
			if ( empty( $intent->error ) ) {
				$candidates[] = $intent;
			}
		}

		$query = sprintf( "metadata['wplc_renewal_id']:'%d' AND metadata['wplc_license_id']:'%d'", (int) $context['renewal_id'], (int) $context['license_id'] );
		$found = $this->retrieve( 'payment_intents/search?limit=20&query=' . rawurlencode( $query ) );
		if ( ! is_object( $found ) ) {
			return $unknown;
		}
		if ( empty( $found->error ) && is_array( $found->data ?? null ) ) {
			foreach ( $found->data as $intent ) {
				if ( is_object( $intent ) ) {
					$candidates[] = $intent;
				}
			}
		}
		// A search that Stripe refuses (not available for the account) does not block renewals.

		$best = null;
		$rank = array(
			'succeeded' => 4,
			'processing' => 3,
			'requires_action' => 2,
			'requires_confirmation' => 2,
			'requires_capture' => 2,
		);
		foreach ( $candidates as $intent ) {
			if ( ! self::intent_belongs_to( $intent, $order, $context ) ) {
				continue;
			}
			$status = (string) ( $intent->status ?? '' );
			if ( isset( $rank[ $status ] ) && ( absint( $intent->amount ?? 0 ) !== $amount || strtolower( (string) ( $intent->currency ?? '' ) ) !== $currency ) ) {
				// Money moved or is moving for this renewal, but not the amount of this order.
				return new \WP_Error( 'renewal_amount_mismatch', __( 'Stripe holds a payment for this renewal whose amount differs from the order.', 'wp-license' ), array( 'payment_intent' => sanitize_text_field( (string) ( $intent->id ?? '' ) ) ) );
			}
			$score = $rank[ $status ] ?? ( (string) ( $intent->metadata->wplc_attempt ?? '' ) === $attempt_key ? 1 : 0 );
			if ( null === $best || $score > $best[0] ) {
				$best = array( $score, $intent );
			}
		}

		return $best && $best[0] > 0 ? $best[1] : null;
	}

	/**
	 * Whether a PaymentIntent was created for this renewal on this site.
	 *
	 * Renewal and order IDs repeat after a restore and between copies of a
	 * site, so the site and the renewal period are compared as well.
	 *
	 * @param object              $intent PaymentIntent.
	 * @param \WC_Order           $order Renewal order.
	 * @param array<string,mixed> $context Result of renewal_context().
	 * @return bool
	 */
	private static function intent_belongs_to( $intent, $order, array $context ): bool {
		$metadata = is_object( $intent->metadata ?? null ) ? $intent->metadata : null;
		if (
			! $metadata
			|| absint( $metadata->wplc_renewal_id ?? 0 ) !== (int) $context['renewal_id']
			|| absint( $metadata->wplc_license_id ?? 0 ) !== (int) $context['license_id']
		) {
			return false;
		}
		$site = (string) ( $metadata->wplc_site ?? '' );
		if ( '' !== $site && ! hash_equals( self::site_id(), $site ) ) {
			return false;
		}
		$period = (string) ( $metadata->wplc_period ?? '' );
		if ( '' !== $period ) {
			return '' !== (string) $context['period_start'] && $period === (string) $context['period_start'];
		}
		// Intents created before the period was sent: same order, and not older than the order.
		$created = $order->get_date_created();

		return absint( $metadata->order_id ?? 0 ) === (int) $order->get_id()
			&& ( ! $created || absint( $intent->created ?? 0 ) >= $created->getTimestamp() - DAY_IN_SECONDS );
	}

	/**
	 * GET a Stripe API resource through the gateway.
	 *
	 * @param string $api Path below the API root.
	 * @return object|null Decoded answer (may carry `error`), null when there is no usable answer.
	 */
	private function retrieve( string $api ) {
		try {
			// Part of every supported gateway version (Requirements::STRIPE_REQUIRED_SYMBOLS).
			$response = \WC_Stripe_API::retrieve( $api );
		} catch ( \Throwable $exception ) {
			return null;
		}

		return is_object( $response ) && ! is_wp_error( $response ) ? $response : null;
	}

	/**
	 * Store the PaymentIntent of the current attempt on the renewal order.
	 *
	 * Used to resolve disputes to the order and to look the payment up in Stripe.
	 *
	 * @param \WC_Order $order Renewal order.
	 * @param string    $intent_id Stripe PaymentIntent ID.
	 * @return void
	 */
	private function remember_payment_intent( $order, string $intent_id ): void {
		$intent_id = sanitize_text_field( $intent_id );
		if ( 0 !== strpos( $intent_id, 'pi_' ) || $intent_id === (string) $order->get_meta( '_wplc_payment_intent_id', true ) ) {
			return;
		}
		$order->update_meta_data( '_wplc_payment_intent_id', $intent_id );
		$order->save();
	}

	/**
	 * Find the Stripe card token the order was paid with.
	 *
	 * Only the payment method of this order counts. There is no fallback to the
	 * customer's default card (B-61, B-78): the customer agreed to renew with
	 * what they paid with, not with another card on file.
	 *
	 * @param int   $token_id Existing filter value.
	 * @param mixed $order Paid order (WC_Order); a filter argument, so anything else is ignored.
	 * @return int
	 */
	public static function token_id_for_order( int $token_id, $order ): int {
		if ( $token_id || ! $order instanceof \WC_Order || 'stripe' !== $order->get_payment_method() || ! $order->get_customer_id() ) {
			return $token_id;
		}
		if ( ! class_exists( 'WC_Payment_Tokens' ) ) {
			return 0;
		}
		$adapter = new self();
		$customer_id = (int) $order->get_customer_id();
		// The gateway records the PaymentMethod it charged in `_stripe_source_id` and does not
		// attach the saved token to the order. Prefer the card the customer actually paid with.
		$paid_with = (string) $order->get_meta( '_stripe_source_id', true );
		if ( 0 === strpos( $paid_with, 'pm_' ) ) {
			foreach ( \WC_Payment_Tokens::get_customer_tokens( $customer_id, 'stripe' ) as $token ) {
				if ( $paid_with === (string) $token->get_token() && $adapter->supports_saved_token( (int) $token->get_id(), $customer_id ) ) {
					return (int) $token->get_id();
				}
			}
		}
		$tokens = \WC_Payment_Tokens::get_order_tokens( $order->get_id() );
		foreach ( $tokens as $token ) {
			if ( 'stripe' === $token->get_gateway_id() && $adapter->supports_saved_token( (int) $token->get_id(), $customer_id ) ) {
				return (int) $token->get_id();
			}
		}
		self::explain_missing_renewal( $order );

		return 0;
	}

	/**
	 * Tell shop and customer that a purchase with consent will not renew by itself (B-61).
	 *
	 * Happens when the customer paid with Link, Amazon Pay or another Stripe
	 * method that is not a saved card. Written once per order.
	 *
	 * @param \WC_Order $order Paid order.
	 * @return void
	 */
	private static function explain_missing_renewal( $order ): void {
		if ( ! $order->get_meta( RecurringConsent::META_CONSENT_AT, true ) || $order->get_meta( self::META_UNAVAILABLE_NOTED, true ) ) {
			return;
		}
		$order->update_meta_data( self::META_UNAVAILABLE_NOTED, '1' );
		$order->save();
		$type = sanitize_key( (string) $order->get_meta( '_stripe_upe_payment_type', true ) );
		$order->add_order_note(
			sprintf(
				/* translators: %s: Stripe payment method type, e.g. link. */
				__( 'WP License Captain: automatic renewal was requested, but the payment method of this order (%s) is not a saved card. The license will not renew automatically.', 'wp-license' ),
				'' !== $type ? $type : __( 'unknown', 'wp-license' )
			)
		);
		/**
		 * Filters the hint the customer gets when a purchase with consent cannot renew automatically.
		 *
		 * Return an empty string to send no hint.
		 *
		 * @param string    $hint Text of the customer note.
		 * @param \WC_Order $order Order.
		 */
		$hint = (string) apply_filters(
			'wplc_renewal_unavailable_customer_hint',
			__( 'You asked for automatic renewal, but the payment method you used cannot be charged automatically. Your license runs until the end of the paid period. We will remind you before it ends so you can renew it yourself; to renew automatically, add a card in your account.', 'wp-license' ),
			$order
		);
		if ( '' !== $hint ) {
			// A customer note is shown on the order in the account and sent by email.
			$order->add_order_note( $hint, 1 );
		}
		/**
		 * Fires when a purchase with renewal consent has no payment method that can renew.
		 *
		 * @param int    $order_id Order ID.
		 * @param string $payment_type Stripe payment method type, may be empty.
		 */
		do_action( 'wplc/renewal_unavailable_for_order', (int) $order->get_id(), $type );
	}

	/**
	 * Save the card of a recovery payment (B-58).
	 *
	 * A renewal order carries no consent meta, so the gateway would only save
	 * the card when the customer ticks its box. The license already renews
	 * automatically with the customer's consent; the card that pays its
	 * renewal order is the one to renew with.
	 *
	 * @param mixed $force Current decision.
	 * @param mixed $order_id Order ID.
	 * @return mixed
	 */
	public static function force_save_for_renewal_order( $force, $order_id = 0 ) {
		if ( $force || ! $order_id || ! function_exists( 'wc_get_order' ) ) {
			return $force;
		}
		$order = wc_get_order( absint( $order_id ) );
		if ( ! $order instanceof \WC_Order || ! $order->get_customer_id() ) {
			return $force;
		}
		$license = self::renewing_license_of( $order );

		return $license ? true : $force;
	}

	/**
	 * License a renewal order belongs to, when it renews automatically for the order's customer.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string,mixed>|null
	 */
	private static function renewing_license_of( $order ): ?array {
		global $wpdb;

		$license_id = absint( $order->get_meta( RenewalOrder::META_LICENSE, true ) );
		if ( ! $license_id || ! $order->get_meta( RenewalOrder::META_RENEWAL, true ) ) {
			return null;
		}
		$tables = Schema::table_names();
		$license = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', $tables['licenses'], $license_id ), ARRAY_A );
		if (
			! is_array( $license )
			|| empty( $license['renewal_enabled'] )
			|| (int) $license['customer_id'] !== (int) $order->get_customer_id()
			|| ! in_array( (string) $license['status'], array( 'active', 'past_due' ), true )
		) {
			return null;
		}

		return $license;
	}

	/**
	 * Renew with the card the customer paid a renewal order with (B-58, B-20).
	 *
	 * Runs for every paid order, possibly several times; it only acts on a
	 * renewal order the customer paid through the Stripe gateway, and only
	 * when the license renews automatically.
	 *
	 * @param mixed $order_id Order ID.
	 * @return void
	 */
	public static function adopt_payment_method_from_order( $order_id ): void {
		global $wpdb;

		$order = function_exists( 'wc_get_order' ) ? wc_get_order( absint( $order_id ) ) : false;
		if (
			! $order instanceof \WC_Order
			|| 'stripe' !== $order->get_payment_method()
			|| ! $order->is_paid()
			|| 'stripe' === $order->get_meta( '_wplc_charged_by_adapter', true )
			|| ! class_exists( 'WC_Payment_Tokens' )
		) {
			return;
		}
		$license = self::renewing_license_of( $order );
		$paid_with = (string) $order->get_meta( '_stripe_source_id', true );
		if ( ! $license || 0 !== strpos( $paid_with, 'pm_' ) ) {
			return;
		}
		$adapter = new self();
		$customer_id = (int) $license['customer_id'];
		$token_id = $adapter->find_token( $customer_id, $paid_with );
		if ( ! $token_id || ( (int) $license['payment_token_id'] === $token_id && (string) ( $license['gateway_method_ref'] ?? '' ) === $paid_with ) ) {
			return;
		}
		$tables = Schema::table_names();
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET payment_token_id = %d, payment_gateway_id = %s, updated_at = %s WHERE id = %d AND customer_id = %d AND renewal_enabled = 1 AND payment_token_id = %d',
				$tables['licenses'],
				$token_id,
				'stripe',
				gmdate( 'Y-m-d H:i:s' ),
				(int) $license['id'],
				$customer_id,
				(int) $license['payment_token_id']
			)
		);
		if ( 1 !== (int) $updated && $token_id !== (int) $license['payment_token_id'] ) {
			return;
		}
		PaymentReference::store( (int) $license['id'], 'stripe', $token_id, $customer_id );
		AuditLog::record(
			'payment_method_adopted',
			'license',
			(int) $license['id'],
			'renewal_order_paid_by_customer',
			array( 'payment_token_id' => (int) $license['payment_token_id'] ),
			array(
				'payment_token_id' => $token_id,
				'order_id' => (int) $order->get_id(),
			)
		);
		$order->add_order_note( __( 'WP License Captain: future automatic renewals of this license use the card this order was paid with.', 'wp-license' ) );
		/**
		 * Fires after a license was moved to the card a renewal order was paid with.
		 *
		 * @param int $license_id License ID.
		 * @param int $token_id New WooCommerce payment token ID.
		 * @param int $order_id Paid renewal order.
		 */
		do_action( 'wplc/renewal_payment_method_adopted', (int) $license['id'], $token_id, (int) $order->get_id() );
	}

	/**
	 * Keep automatic renewal when the gateway itself removes a token row (B-57).
	 *
	 * The Stripe gateway deletes token rows that Stripe does not list in the
	 * current mode and recreates them later, inside the filter
	 * `woocommerce_get_customer_payment_tokens`. That is not the customer
	 * removing a card. Licenses that know the payment method reference are
	 * detached from the row before the listeners that switch renewal off run;
	 * the token is resolved again by reference at charge time.
	 *
	 * A card deleted by the customer or an administrator is not touched here.
	 *
	 * @param mixed $token_id Deleted token ID.
	 * @param mixed $token Deleted token object.
	 * @return void
	 */
	public static function keep_license_on_token_sync( $token_id = 0, $token = null ): void {
		global $wpdb;

		$token_id = absint( $token_id );
		if (
			! $token_id
			|| ! doing_filter( 'woocommerce_get_customer_payment_tokens' )
			|| ! is_object( $token )
			|| ! is_callable( array( $token, 'get_gateway_id' ) )
			|| 'stripe' !== $token->get_gateway_id()
		) {
			return;
		}
		$tables = Schema::table_names();
		$detached = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET payment_token_id = 0, updated_at = %s WHERE payment_token_id = %d AND renewal_enabled = 1 AND gateway_method_ref IS NOT NULL AND gateway_method_ref <> ''",
				$tables['licenses'],
				gmdate( 'Y-m-d H:i:s' ),
				$token_id
			)
		);
		if ( $detached ) {
			AuditLog::record( 'payment_token_detached', 'payment_token', $token_id, 'gateway_token_sync', null, array( 'licenses' => (int) $detached ) );
		}
	}

	/**
	 * A renewal order paid through this adapter was cancelled (B-60).
	 *
	 * Gateway 9.6 cancels an order instead of refunding it when the payment is
	 * refunded in the Stripe dashboard and the order looks uncaptured to it.
	 * The money is back at the customer, so the renewal has to be treated like
	 * a refunded one. The decision belongs to the license lifecycle; this only
	 * announces it.
	 *
	 * @param mixed $order_id Order ID.
	 * @return void
	 */
	public static function on_order_cancelled( $order_id ): void {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( absint( $order_id ) ) : false;
		if ( ! $order instanceof \WC_Order || 'stripe' !== $order->get_payment_method() ) {
			return;
		}
		$renewal_id = absint( $order->get_meta( RenewalOrder::META_RENEWAL, true ) );
		if ( ! $renewal_id || ! $order->get_date_paid() || 'yes' === $order->get_meta( '_wplc_renewal_voided', true ) ) {
			return;
		}
		$order->update_meta_data( '_wplc_renewal_voided', 'yes' );
		$order->save();
		$license_id = absint( $order->get_meta( RenewalOrder::META_LICENSE, true ) );
		AuditLog::record(
			'renewal_order_voided',
			'renewal',
			$renewal_id,
			'paid_order_cancelled',
			null,
			array(
				'order_id' => (int) $order->get_id(),
				'license_id' => $license_id,
			)
		);
		/**
		 * Fires when a paid renewal order was cancelled, i.e. its payment was taken back.
		 *
		 * To be handled like a full refund of the renewal order.
		 *
		 * @param int $order_id Renewal order ID.
		 * @param int $renewal_id Renewal ID.
		 * @param int $license_id License ID.
		 */
		do_action( 'wplc/renewal_order_voided', (int) $order->get_id(), $renewal_id, $license_id );
	}

	/**
	 * Keep the gateway's webhook from processing this adapter's PaymentIntents (B-147).
	 *
	 * The gateway resolves `payment_intent.*` events to the order through the
	 * metadata and would add "SCA authentication failed", set the status and
	 * mail the shop for every declined renewal attempt. It only acts on orders
	 * in the statuses returned here. Once the customer starts paying the order
	 * on the pay page the gateway stores its own intent on the order and gets
	 * its events again.
	 *
	 * @param mixed $statuses Order statuses the gateway's webhook may process.
	 * @param mixed $order Order.
	 * @return mixed
	 */
	public static function filter_gateway_webhook_statuses( $statuses, $order = null ) {
		// Only inside the gateway's webhook request (`?wc-api=wc_stripe`), never while a customer pays.
		if ( ! doing_action( 'woocommerce_api_wc_stripe' ) || ! $order instanceof \WC_Order || ! $order->get_meta( RenewalOrder::META_RENEWAL, true ) ) {
			return $statuses;
		}
		$ours = (string) $order->get_meta( RenewalOrder::META_PAYMENT_INTENT, true );
		$gateway = (string) $order->get_meta( '_stripe_intent_id', true );
		if ( '' === $ours || ( '' !== $gateway && $gateway !== $ours ) ) {
			return $statuses;
		}

		return array();
	}
}
