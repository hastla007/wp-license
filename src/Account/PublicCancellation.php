<?php
/**
 * Public cancellation page (no login needed).
 *
 * @package WPLC
 */

namespace WPLC\Account;

use WPLC\Api\V1\Authenticator;
use WPLC\Api\V1\ClientIp;
use WPLC\Compatibility\Requirements;
use WPLC\Infrastructure\Database\Schema;
use WPLC\License\LicenseKey;
use WPLC\License\LicenseLifecycle;
use WPLC\Notifications\Emails\LicenseEmail;
use WPLC\Notifications\Notifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcode [wplc_cancel]: cancel the automatic renewal of a license without
 * signing in.
 *
 * Flow: the customer enters the e-mail address and a license reference (or
 * the order number), checks the details on a confirmation page and presses
 * the cancellation button. A matching license is cancelled at the end of the
 * paid period and the cancellation is confirmed by e-mail with the time it
 * was received and the end date.
 *
 * The page answers identically whether or not the details match a license,
 * so it cannot be used to find out who owns a license. The confirmation
 * e-mail is sent by a background job, so the response time does not tell a
 * match either (P-37).
 *
 * Only the e-mail address of the current owner matches: the account address,
 * the billing address of the source order while that order belongs to the
 * owner, and the license e-mail recipient (P-08).
 *
 * Requests are rate limited with atomic counters in the request_limits table
 * per client address and per reference. An e-mail address alone is never a
 * limit for every reference: the per-address counter only applies to order
 * numbers (which are easy to guess), so flooding a customer's address cannot
 * block the license reference and key printed in their e-mails.
 *
 * Whether this page satisfies a legal cancellation-button requirement in a
 * given country is for the shop owner to check; the wording can be changed
 * through the templates.
 */
final class PublicCancellation {

	/**
	 * Lifetime in seconds of the confirmation page.
	 */
	private const REVIEW_TTL = 3600;

	/**
	 * Cancellation requests per hour and client address, per reference, and per
	 * e-mail address for order-number references.
	 */
	public const DEFAULT_HOURLY_LIMIT = 5;

	/**
	 * Action Scheduler hook that sends the confirmation e-mail (P-37).
	 */
	public const CONFIRMATION_HOOK = 'wplc_public_cancel_confirmation';

	/**
	 * Outcome of the request handled in this page view, for the shortcode.
	 *
	 * @var array<string,mixed>|null
	 */
	private static $state = null;

	/**
	 * Register shortcode and form handler.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_shortcode( 'wplc_cancel', array( self::class, 'shortcode' ) );
		add_action( 'wp_loaded', array( self::class, 'handle_post' ), 20 );
		add_action( self::CONFIRMATION_HOOK, array( self::class, 'send_confirmation' ), 10, 2 );
	}

	/**
	 * Normalize the license reference the customer typed.
	 *
	 * @param string $reference Raw input.
	 * @return string Upper-case reference without surrounding noise.
	 */
	public static function normalize_reference( string $reference ): string {
		$reference = strtoupper( trim( wp_strip_all_tags( $reference ) ) );
		$reference = (string) preg_replace( '/\s+/', '', $reference );

		return ltrim( substr( $reference, 0, 160 ), '#' );
	}

	/**
	 * Signature that ties the confirmation step to the reviewed details.
	 *
	 * @param string $email     E-mail address.
	 * @param string $reference Normalized reference.
	 * @param int    $expires   Unix timestamp until which the confirmation page is valid.
	 * @return string
	 */
	private static function signature( string $email, string $reference, int $expires ): string {
		return hash_hmac( 'sha256', strtolower( $email ) . '|' . $reference . '|' . $expires, wp_salt( 'nonce' ) );
	}

	/**
	 * Handle the two form steps once per request, before the page is rendered.
	 *
	 * @return void
	 */
	public static function handle_post(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Public form for signed-out visitors; the confirmation step is bound by an HMAC signature instead.
		if ( 'POST' !== $method || empty( $_POST['wplc_cancel_step'] ) ) {
			return;
		}

		$step      = sanitize_key( wp_unslash( $_POST['wplc_cancel_step'] ) );
		$email     = isset( $_POST['wplc_cancel_email'] ) ? sanitize_email( wp_unslash( $_POST['wplc_cancel_email'] ) ) : '';
		$reference = isset( $_POST['wplc_cancel_reference'] ) ? self::normalize_reference( sanitize_text_field( wp_unslash( $_POST['wplc_cancel_reference'] ) ) ) : '';
		$honeypot  = isset( $_POST['wplc_cancel_website'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['wplc_cancel_website'] ) ) ) : '';
		$expires   = isset( $_POST['wplc_cancel_expires'] ) ? absint( wp_unslash( $_POST['wplc_cancel_expires'] ) ) : 0;
		$signature = isset( $_POST['wplc_cancel_signature'] ) ? sanitize_text_field( wp_unslash( $_POST['wplc_cancel_signature'] ) ) : '';
		// phpcs:enable

		CustomerPortal::send_no_cache();
		self::$state = self::process( $step, $email, $reference, $honeypot, $expires, $signature );
	}

	/**
	 * Decide what the page shows for a submitted step.
	 *
	 * @param string $step      "review" (first form) or "confirm" (confirmation page).
	 * @param string $email     Sanitized e-mail address.
	 * @param string $reference Normalized reference.
	 * @param string $honeypot  Value of the hidden field of the first form that people leave empty.
	 * @param int    $expires   Expiry of the confirmation page.
	 * @param string $signature Signature of the confirmation page.
	 * @return array<string,mixed> Keys: view (form|review|done|limited), email, reference, error, expires, signature, received_at.
	 */
	public static function process( string $step, string $email, string $reference, string $honeypot = '', int $expires = 0, string $signature = '' ): array {
		$state = array(
			'view'        => 'form',
			'email'       => $email,
			'reference'   => $reference,
			'error'       => '',
			'expires'     => 0,
			'signature'   => '',
			'received_at' => 0,
		);

		if ( ! is_email( $email ) || '' === $reference ) {
			$state['error'] = __( 'Please enter the e-mail address of your purchase and the license reference or order number.', 'wp-license' );
			return $state;
		}

		if ( 'confirm' !== $step ) {
			// Nothing is looked up yet: the confirmation page only repeats what was entered.
			$state['view']      = 'review';
			$state['expires']   = time() + self::REVIEW_TTL;
			// A filled trap field marks a bot: it gets a confirmation page that can never be confirmed.
			$state['signature'] = '' === $honeypot ? self::signature( $email, $reference, $state['expires'] ) : hash( 'sha256', wp_generate_password( 32, false ) );
			return $state;
		}

		if ( $expires < time() || ! hash_equals( self::signature( $email, $reference, $expires ), $signature ) ) {
			$state['error'] = __( 'The confirmation page has expired. Please check your details and try again.', 'wp-license' );
			return $state;
		}

		if ( self::limited( $email, $reference ) ) {
			$state['view'] = 'limited';
			return $state;
		}

		$state['view']        = 'done';
		$state['received_at'] = time();
		self::cancel_matching( $email, $reference, $state['received_at'] );

		return $state;
	}

	/**
	 * Whether a reference can only be an order number.
	 *
	 * @param string $reference Normalized reference.
	 * @return bool
	 */
	public static function is_order_number( string $reference ): bool {
		return 1 === preg_match( '/^[0-9]{1,18}$/', $reference );
	}

	/**
	 * Count this request and tell whether it is over a limit.
	 *
	 * Counters (atomic, hourly, keyed hashes only): the client address, the
	 * reference, and for order-number references the e-mail address. A database
	 * failure counts as over the limit.
	 *
	 * @param string $email     E-mail address.
	 * @param string $reference Normalized reference.
	 * @return bool
	 */
	private static function limited( string $email, string $reference ): bool {
		/**
		 * Filter how many public cancellation requests are accepted per hour,
		 * counted per client address, per reference and (for order numbers) per
		 * e-mail address.
		 *
		 * @param int $limit Requests per hour. Default: the setting "Cancellation page: requests per hour" (5).
		 */
		$limit   = max( 1, (int) apply_filters( 'wplc_public_cancel_hourly_limit', \WPLC\Settings\Registry::int( 'public_cancel_hourly_limit' ) ) );
		$ip      = class_exists( ClientIp::class ) ? ClientIp::current() : '';
		// "WPLC-1A2B3C4D" and "1A2B3C4D" are the same reference.
		$buckets = array( 'public-cancel-ref' => (string) preg_replace( '/^' . LicenseKey::key_prefix() . '-(?=[0-9A-F]{8}$)/', '', $reference ) );
		if ( '' !== $ip ) {
			$buckets['public-cancel-ip'] = $ip;
		}
		if ( self::is_order_number( $reference ) ) {
			$buckets['public-cancel-order-email'] = strtolower( $email );
		}

		$hour = gmdate( 'Y-m-d H:00:00' );
		$over = false;
		foreach ( $buckets as $scope => $value ) {
			$hits = Authenticator::increment_bucket( Authenticator::limiter_hash( $scope, $value ), $hour );
			if ( false === $hits || $hits > $limit ) {
				$over = true;
			}
		}

		return $over;
	}

	/**
	 * Cancel every automatically renewing license that matches the details and
	 * queue the confirmation e-mail.
	 *
	 * The e-mail is sent by a background job (CONFIRMATION_HOOK) instead of the
	 * synchronous listener of `wplc/cancellation_scheduled`, so a match does not
	 * make the response slower than a miss.
	 *
	 * @param string $email       E-mail address the customer entered.
	 * @param string $reference   Normalized reference.
	 * @param int    $received_at Unix timestamp of the request.
	 * @return int Number of cancelled licenses.
	 */
	public static function cancel_matching( string $email, string $reference, int $received_at ): int {
		$cancelled = 0;
		foreach ( self::find_licenses( $email, $reference ) as $license ) {
			$license_id = (int) $license['id'];
			if ( ! self::schedule_without_synchronous_email( $license_id, $received_at ) ) {
				continue;
			}
			self::queue_confirmation( $license_id, $received_at );
			++$cancelled;
		}

		return $cancelled;
	}

	/**
	 * Schedule the cancellation while the synchronous confirmation listener is detached.
	 *
	 * @param int $license_id  License ID.
	 * @param int $received_at Unix timestamp of the request.
	 * @return bool
	 */
	private static function schedule_without_synchronous_email( int $license_id, int $received_at ): bool {
		$listener = array( Notifications::class, 'on_cancellation_scheduled' );
		$priority = has_action( 'wplc/cancellation_scheduled', $listener );
		$async    = function_exists( 'as_schedule_single_action' );
		if ( $async && false !== $priority ) {
			remove_action( 'wplc/cancellation_scheduled', $listener, (int) $priority );
		}
		try {
			// Fires `wplc/cancellation_scheduled` with the time of receipt for other listeners.
			return LicenseLifecycle::schedule_cancellation( $license_id, 'Cancelled through the public cancellation page', 0, $received_at );
		} finally {
			if ( $async && false !== $priority ) {
				add_action( 'wplc/cancellation_scheduled', $listener, (int) $priority, 2 );
			}
		}
	}

	/**
	 * Queue the confirmation e-mail of one cancellation.
	 *
	 * @param int $license_id  License ID.
	 * @param int $received_at Unix timestamp of the request.
	 * @return void
	 */
	private static function queue_confirmation( int $license_id, int $received_at ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			// Without Action Scheduler the synchronous listener already sent it.
			return;
		}
		// Zero means the same confirmation is already queued.
		Requirements::schedule_single_action( time(), self::CONFIRMATION_HOOK, array( $license_id, $received_at ) );
	}

	/**
	 * Background job: send the confirmation e-mail (retried by Notifications on failure).
	 *
	 * @param mixed $license_id  License ID.
	 * @param mixed $received_at Unix timestamp of the request.
	 * @return void
	 */
	public static function send_confirmation( $license_id = 0, $received_at = 0 ): void {
		Notifications::on_cancellation_scheduled( absint( $license_id ), absint( $received_at ) );
	}

	/**
	 * Licenses with automatic renewal that match an e-mail address and a reference.
	 *
	 * The reference is the license reference shown in the account and in every
	 * license e-mail (WPLC-1A2B3C4D, with or without the prefix), the full
	 * license key, or the number of the order the license was bought with.
	 *
	 * @param string $email     E-mail address.
	 * @param string $reference Normalized reference.
	 * @return array<int,array<string,mixed>> License rows.
	 */
	public static function find_licenses( string $email, string $reference ): array {
		global $wpdb;

		if ( ! is_email( $email ) || '' === $reference ) {
			return array();
		}

		$tables     = Schema::table_names();
		$conditions = array();
		$values     = array( $tables['licenses'] );
		// E-04: the key prefix of the shop (WPLC by default).
		$prefix = LicenseKey::key_prefix();
		if ( preg_match( '/^(?:' . $prefix . '-)?([0-9A-F]{8})$/', $reference, $match ) ) {
			$conditions[] = 'key_prefix = %s';
			$values[]     = $prefix . '-' . $match[1];
		}
		if ( preg_match( '/^' . $prefix . '-[0-9A-F-]{36}-[0-9A-F]{64}$/', $reference ) ) {
			$conditions[] = 'license_key_hash = %s';
			$values[]     = LicenseKey::hash( $reference );
		}
		if ( preg_match( '/^[0-9]{1,18}$/', $reference ) ) {
			/**
			 * Filter the order ID for an order number typed on the public cancellation
			 * page, for shops whose order numbers differ from the order IDs.
			 *
			 * @param int    $order_id     Order ID (the typed number).
			 * @param string $order_number Typed order number.
			 */
			$order_id = absint( apply_filters( 'wplc_public_cancel_order_id', (int) $reference, $reference ) );
			if ( $order_id > 0 ) {
				$conditions[] = 'source_order_id = %d';
				$values[]     = $order_id;
			}
		}
		if ( ! $conditions ) {
			return array();
		}

		$values = array_merge( $values, array( 'active', 'past_due' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Only fixed condition strings with placeholders are interpolated.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE ( ' . implode( ' OR ', $conditions ) . ' ) AND renewal_enabled = 1 AND cancel_at_period_end = 0 AND status IN (%s,%s) ORDER BY id ASC LIMIT 50',
				$values
			),
			ARRAY_A
		);
		// phpcs:enable

		$matches = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( self::email_belongs_to( $email, $row ) ) {
				$matches[] = $row;
			}
		}
		return $matches;
	}

	/**
	 * Whether an e-mail address belongs to the current owner of a license.
	 *
	 * The billing address of the source order only counts while the order
	 * belongs to the current owner: after an owner change the previous owner's
	 * order still points at the license, but must not cancel it (P-08).
	 *
	 * @param string              $email   E-mail address.
	 * @param array<string,mixed> $license License row.
	 * @return bool
	 */
	public static function email_belongs_to( string $email, array $license ): bool {
		$email     = strtolower( $email );
		$addresses = array();
		$owner     = (int) ( $license['customer_id'] ?? 0 );

		$user = $owner > 0 ? get_userdata( $owner ) : false;
		if ( $user ) {
			$addresses[] = strtolower( (string) $user->user_email );
		}
		$order = ! empty( $license['source_order_id'] ) && function_exists( 'wc_get_order' ) ? wc_get_order( (int) $license['source_order_id'] ) : false;
		if ( $order instanceof \WC_Order && (int) $order->get_customer_id() === $owner ) {
			$addresses[] = strtolower( (string) $order->get_billing_email() );
		}
		if ( class_exists( LicenseEmail::class ) ) {
			$addresses[] = strtolower( LicenseEmail::recipient_for( $license ) );
		}

		return '' !== $email && in_array( $email, $addresses, true );
	}

	/**
	 * Render the cancellation page.
	 *
	 * @return string
	 */
	public static function shortcode(): string {
		$state = is_array( self::$state ) ? self::$state : array(
			'view'        => 'form',
			'email'       => '',
			'reference'   => '',
			'error'       => '',
			'expires'     => 0,
			'signature'   => '',
			'received_at' => 0,
		);
		if ( 'form' !== $state['view'] || '' !== $state['error'] ) {
			// Responses to a submitted form must never be served to someone else from a cache.
			CustomerPortal::send_no_cache();
		}
		CustomerPortal::enqueue_now();

		$url = function_exists( 'get_permalink' ) ? get_permalink() : '';
		ob_start();
		wc_get_template(
			'wplc-cancel.php',
			array(
				'state'       => $state,
				'form_url'    => is_string( $url ) ? $url : '',
				'received_at' => $state['received_at'] > 0 ? Notifications::format_date( (int) $state['received_at'], true ) : '',
				'support'     => Notifications::support_email(),
				'support_url' => Notifications::support_url(),
				'account_url' => CustomerPortal::account_url(),
			),
			'wp-license/',
			WPLC_PATH . 'templates/'
		);

		return (string) ob_get_clean();
	}
}
