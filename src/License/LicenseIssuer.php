<?php
/**
 * Converts paid order line items into licenses.
 *
 * @package WPLC
 */

namespace WPLC\License;

use WPLC\Billing\RecurringConsent;
use WPLC\Billing\RenewalScheduler;
use WPLC\Catalog\Catalog;
use WPLC\Compatibility\Requirements;
use WPLC\Gateway\Registry;
use WPLC\Infrastructure\Database\Schema;
use WPLC\Notifications\Notifications;
use WPLC\Notifications\ReminderLog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Idempotent license provisioning from WooCommerce orders.
 *
 * A paid order that cannot get all its licenses never fails silently: the
 * reason is written to the order (note and meta), logged, announced through
 * `wplc/license_issuance_failed`, and the order is retried by the daily run.
 */
final class LicenseIssuer {

	/** Failure reason of the last manual issue (no secrets): one of the FAILED_* constants, '' after a success. */
	public const FAILED_INPUT = 'invalid_input';
	public const FAILED_KEY_ID = 'key_id_without_secret';
	public const FAILED_STORE = 'not_stored';

	/** @var string */
	private static $last_failure = '';

	/**
	 * Why the last call of issue_manual() returned zero, so a caller can say more than "not issued".
	 *
	 * @return string One of the FAILED_* constants, or '' when the last call issued a license.
	 */
	public static function last_failure(): string {
		return self::$last_failure;
	}

	/**
	 * Order meta with the reason why licenses are still missing. Deleted once all are issued.
	 */
	const META_PROBLEM = '_wplc_issuance_problem';

	/**
	 * Action Scheduler hook: finish what the checkout request started for one license (key email).
	 */
	const FOLLOWUP_HOOK = 'wplc_license_issued_followup';

	/**
	 * Action Scheduler hook: try again to issue the licenses of one order.
	 */
	const RETRY_HOOK = 'wplc_retry_license_issuance';

	/**
	 * Seconds after which a `pending` license row counts as left behind by an aborted request.
	 */
	const PENDING_GRACE = 900;

	/**
	 * Visible order item meta (B-42): which licenses the line paid for. Shown on the order,
	 * in emails and on invoices; the label is translated at display time (display_meta_key()).
	 */
	const ITEM_META_LICENSE = 'wplc_license';

	/**
	 * Visible order item meta (B-42): the first service period of the line, `Y-m-d – Y-m-d` (UTC).
	 */
	const ITEM_META_PERIOD = 'wplc_service_period';

	/**
	 * Register WooCommerce payment and paid-order hooks.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'woocommerce_payment_complete', array( self::class, 'maybe_issue_order' ), 20 );
		add_filter( 'woocommerce_order_item_display_meta_key', array( self::class, 'display_meta_key' ), 10, 2 );
		add_action( 'woocommerce_order_status_processing', array( self::class, 'maybe_issue_order' ), 20 );
		add_action( 'woocommerce_order_status_completed', array( self::class, 'maybe_issue_order' ), 20 );
		add_action( self::RETRY_HOOK, array( self::class, 'maybe_issue_order' ) );
		add_action( self::FOLLOWUP_HOOK, array( self::class, 'run_followup' ) );
		// Before the expiry run (priority 10) and the reminders (20).
		add_action( 'wplc_daily_lifecycle', array( self::class, 'recover_unfinished' ), 5 );
	}

	/**
	 * Issue licenses for configured items on a paid order.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return void
	 */
	public static function maybe_issue_order( $order_id ): void {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( absint( $order_id ) );
		// Renewal and plan upgrade orders pay for an existing license; they never issue a new one.
		if ( ! $order instanceof \WC_Order || ! $order->is_paid() || $order->get_meta( '_wplc_renewal_for_license', true ) || $order->get_meta( '_wplc_upgrade_for_license', true ) ) {
			return;
		}
		// A WooCommerce Subscriptions renewal pays the next period of a subscription
		// that already got its license; issuing again would create a second key per period (P-20).
		if ( self::is_subscriptions_renewal( $order ) ) {
			return;
		}

		if ( ! self::money_has_arrived( $order ) ) {
			return;
		}

		/**
		 * Filters whether licenses may be issued for a paid order right now.
		 *
		 * Lets a shop hold back digital content, for example until the customer has
		 * agreed to the immediate start of the service (withdrawal right). Returning
		 * false issues nothing and writes nothing; issuing is tried again on the next
		 * order status hook and by the daily run once the order is flagged.
		 *
		 * @since 0.2.0
		 *
		 * @param bool      $can_issue Default true.
		 * @param \WC_Order $order     Paid order.
		 */
		if ( ! apply_filters( 'wplc_can_issue_for_order', true, $order ) ) {
			return;
		}

		$customer_id = null;
		$problems    = array();

		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			// Catalog::find_mapping() is the one rule for "license product", including the
			// fallback from a variation to a plan stored on its parent product.
			$mapping = Catalog::find_mapping( (int) $item->get_product_id(), (int) $item->get_variation_id() );
			if ( ! $mapping ) {
				continue;
			}

			if ( null === $customer_id ) {
				$customer_id = self::owner_for_order( $order );
			}
			if ( $customer_id <= 0 ) {
				// A license without an owner is invisible: nobody can see the key or manage it.
				$problems['no_customer'] = true;
				continue;
			}

			$quantity = max( 0, (int) $item->get_quantity() );
			$issued   = array();

			for ( $unit_index = 0; $unit_index < $quantity; $unit_index++ ) {
				$existing_id = self::find_existing( (int) $item_id, $unit_index );
				if ( $existing_id > 0 ) {
					$issued[] = $existing_id;
					continue;
				}
				if ( $existing_id < 0 ) {
					$problems['in_progress'] = true;
					continue;
				}

				$license_id = self::create_license( $order, $item, $mapping, $unit_index, $customer_id );
				if ( $license_id ) {
					$issued[] = $license_id;
				} else {
					$problems['issue_failed'] = true;
				}
			}

			if ( $issued ) {
				// Read the complete list back: concurrent hooks for the same order each store
				// every license of the item, so neither overwrites the other with a subset.
				$all_ids = self::issued_for_item( (int) $item_id, $issued );
				$item->update_meta_data( '_wplc_license_ids', $all_ids );
				self::describe_item( $item, $all_ids );
				$item->save_meta_data();
			}
		}

		self::record_outcome( $order, array_keys( $problems ) );
	}

	/**
	 * B-42: write license reference and service period as visible item meta, so the
	 * order, its emails and invoices say what the line paid for (renewal orders get the
	 * same from RenewalOrder). Does not save the item.
	 *
	 * The license list follows the issued units; the period is written once and keeps
	 * the first term even after later renewals moved the end date of the license.
	 *
	 * @param \WC_Order_Item $item Order item.
	 * @param int[]          $license_ids Licenses issued for the item.
	 * @return void
	 */
	public static function describe_item( $item, array $license_ids ): void {
		global $wpdb;

		$license_ids = array_values( array_filter( array_map( 'intval', $license_ids ) ) );
		if ( ! $license_ids ) {
			return;
		}
		$tables       = Schema::table_names();
		$placeholders = implode( ',', array_fill( 0, count( $license_ids ), '%d' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- table name from Schema::table_names() and a generated list of %d placeholders.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, key_prefix, starts_at, paid_until FROM {$tables['licenses']} WHERE id IN ({$placeholders}) ORDER BY unit_index ASC, id ASC",
				$license_ids
			),
			ARRAY_A
		);
		// phpcs:enable
		if ( ! is_array( $rows ) || ! $rows ) {
			return;
		}

		$labels = array();
		$starts = array();
		$ends   = array();
		foreach ( $rows as $row ) {
			$labels[] = trim( sprintf( '#%d %s', (int) $row['id'], (string) $row['key_prefix'] ) );
			if ( ! empty( $row['starts_at'] ) ) {
				$starts[] = substr( (string) $row['starts_at'], 0, 10 );
			}
			if ( ! empty( $row['paid_until'] ) ) {
				$ends[] = substr( (string) $row['paid_until'], 0, 10 );
			}
		}
		$item->update_meta_data( self::ITEM_META_LICENSE, implode( ', ', $labels ) );
		if ( $starts && $ends && '' === (string) $item->get_meta( self::ITEM_META_PERIOD, true ) ) {
			$item->update_meta_data( self::ITEM_META_PERIOD, min( $starts ) . ' – ' . max( $ends ) );
		}
	}

	/**
	 * Translate the keys of the visible item meta when they are displayed.
	 *
	 * @param mixed $display_key Key as WooCommerce would show it.
	 * @param mixed $meta        Meta object with a `key` property.
	 * @return mixed
	 */
	public static function display_meta_key( $display_key, $meta = null ) {
		$key = is_object( $meta ) && isset( $meta->key ) ? (string) $meta->key : '';
		if ( self::ITEM_META_LICENSE === $key ) {
			return __( 'License', 'wp-license' );
		}
		if ( self::ITEM_META_PERIOD === $key ) {
			return __( 'Service period (UTC)', 'wp-license' );
		}

		return $display_key;
	}

	/**
	 * Whether the order status means the money is there.
	 *
	 * "Processing" counts as paid in WooCommerce, but with cash on delivery the
	 * order is processing before anything was paid. For those payment methods the
	 * license is issued when the order is completed.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	private static function money_has_arrived( $order ): bool {
		/**
		 * Filters the payment methods whose orders only get their licenses once the order is completed.
		 *
		 * @since 0.2.0
		 *
		 * @param string[]  $gateway_ids Default: cash on delivery.
		 * @param \WC_Order $order       Order.
		 */
		$deferred = (array) apply_filters( 'wplc_issue_after_completion_gateways', array( 'cod' ), $order );

		return ! ( $order->has_status( 'processing' ) && in_array( (string) $order->get_payment_method(), $deferred, true ) );
	}

	/**
	 * The user who will own the licenses of an order.
	 *
	 * Normally the customer of the order. An order without a customer (a guest
	 * who got through, an order created by hand) is assigned to the account with
	 * the billing email address, if there is one.
	 *
	 * @param \WC_Order $order Order.
	 * @return int User ID, or zero when nobody can own the license.
	 */
	private static function owner_for_order( $order ): int {
		$customer_id = (int) $order->get_customer_id();
		if ( $customer_id > 0 ) {
			return get_userdata( $customer_id ) ? $customer_id : 0;
		}

		$email = (string) $order->get_billing_email();
		$user  = '' !== $email && is_email( $email ) ? get_user_by( 'email', $email ) : false;

		/**
		 * Filters the owner of the licenses of an order without a customer account.
		 *
		 * @since 0.2.0
		 *
		 * @param int       $user_id Account with the billing email address, or zero.
		 * @param \WC_Order $order   Order.
		 */
		return max( 0, (int) apply_filters( 'wplc_license_owner_for_guest_order', $user ? (int) $user->ID : 0, $order ) );
	}

	/**
	 * Write the result of an issuing attempt to the order.
	 *
	 * @param \WC_Order $order Order.
	 * @param string[]  $problems Problem codes: no_customer, issue_failed, in_progress.
	 * @return void
	 */
	private static function record_outcome( $order, array $problems ): void {
		$previous = (string) $order->get_meta( self::META_PROBLEM, true );

		if ( ! $problems ) {
			if ( '' !== $previous ) {
				$order->delete_meta_data( self::META_PROBLEM );
				$order->save_meta_data();
				$order->add_order_note( __( 'WP License Captain: all licenses of this order are issued now.', 'wp-license' ) );
			}
			return;
		}

		sort( $problems );
		$code = implode( ',', $problems );

		if ( in_array( 'in_progress', $problems, true ) ) {
			// Another request is issuing right now, or died while doing so. Look again when its row is stale.
			Requirements::schedule_single_action( time() + self::PENDING_GRACE + 60, self::RETRY_HOOK, array( (int) $order->get_id() ) );
		}

		if ( $code === $previous ) {
			return;
		}

		$order->update_meta_data( self::META_PROBLEM, $code );
		$order->save_meta_data();

		$messages = array();
		if ( in_array( 'no_customer', $problems, true ) ) {
			$messages[] = __( 'this order has no customer account and no account uses the billing email address. Assign a customer to the order; the license is then issued by the daily run or when the order status is saved again', 'wp-license' );
		}
		if ( in_array( 'issue_failed', $problems, true ) ) {
			$messages[] = __( 'a license could not be created (check the license key configuration under WooCommerce → Licenses → Diagnostics and the PHP error log). Issuing is retried daily', 'wp-license' );
		}
		if ( $messages ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: reason. */
					__( 'WP License Captain: this paid order has NOT received all its licenses: %s.', 'wp-license' ),
					implode( '; ', $messages )
				)
			);
		}

		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning(
				'Paid order ' . (int) $order->get_id() . ' has not received all its licenses: ' . $code,
				array( 'source' => 'wp-license' )
			);
		}

		/**
		 * Fires when a paid order could not get all its licenses.
		 *
		 * @since 0.2.0
		 *
		 * @param int      $order_id Order ID.
		 * @param string[] $problems Problem codes: no_customer, issue_failed, in_progress.
		 */
		do_action( 'wplc/license_issuance_failed', (int) $order->get_id(), $problems );
	}

	/**
	 * Every license issued for an order item, in unit order.
	 *
	 * @param int   $order_item_id Order item ID.
	 * @param int[] $fallback IDs known to this request, used when the read fails.
	 * @return int[]
	 */
	private static function issued_for_item( int $order_item_id, array $fallback ): array {
		global $wpdb;

		$tables = Schema::table_names();
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$tables['licenses']} WHERE source_order_item_id = %d ORDER BY unit_index ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names(), values prepared.
				$order_item_id
			)
		);
		$ids = array_map( 'intval', is_array( $ids ) ? $ids : array() );

		return $ids ? $ids : array_values( array_unique( $fallback ) );
	}

	/**
	 * Find a previously issued license for an order item unit.
	 *
	 * A `pending` row is a license whose creation has not finished. While it is
	 * fresh another request is still working on it. Once it is older than
	 * PENDING_GRACE the request has died: the row is removed so the unit can be
	 * issued again instead of being blocked forever.
	 *
	 * @param int $order_item_id Order item ID.
	 * @param int $unit_index Quantity unit index.
	 * @return int License ID; 0 when the unit has no license; -1 while another request is issuing it.
	 */
	private static function find_existing( int $order_item_id, int $unit_index ): int {
		global $wpdb;

		$tables = Schema::table_names();
		$row    = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name comes from Schema::table_names().
				"SELECT id, status, created_at FROM {$tables['licenses']} WHERE source_order_item_id = %d AND unit_index = %d LIMIT 1",
				$order_item_id,
				$unit_index
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return 0;
		}
		if ( 'pending' !== (string) $row['status'] ) {
			return (int) $row['id'];
		}

		$created = strtotime( (string) $row['created_at'] . ' UTC' );
		if ( false !== $created && $created > time() - self::PENDING_GRACE ) {
			return -1;
		}

		return self::discard_pending_license( (int) $row['id'] ) ? 0 : -1;
	}

	/**
	 * Create one license and snapshot its plan entitlements.
	 *
	 * @param \WC_Order              $order Paid order.
	 * @param \WC_Order_Item_Product $item Order line item.
	 * @param array<string,mixed>    $plan Catalog plan.
	 * @param int                    $unit_index Quantity unit index.
	 * @param int                    $customer_id Owner of the license.
	 * @return int License ID or zero on failure.
	 */
	private static function create_license( $order, $item, array $plan, int $unit_index, int $customer_id ): int {
		$paid_at              = $order->get_date_paid();
		$starts_at_timestamp  = $paid_at ? $paid_at->getTimestamp() : time();
		$starts_at            = gmdate( 'Y-m-d H:i:s', $starts_at_timestamp );
		$paid_until_timestamp = RenewalScheduler::period_end( $starts_at_timestamp, (string) $plan['billing_interval'] );
		$paid_until           = gmdate( 'Y-m-d H:i:s', $paid_until_timestamp );
		$gateway_id           = sanitize_key( (string) $order->get_payment_method() );
		$token_id             = absint( apply_filters( 'wplc_payment_token_id_for_order', 0, $order ) );
		$token                = class_exists( 'WC_Payment_Tokens' ) ? \WC_Payment_Tokens::get( $token_id ) : false;
		$adapter              = Registry::get( $gateway_id );
		$consent              = (string) $order->get_meta( RecurringConsent::META_CONSENT_AT, true );
		// Automatic renewal needs the customer of the order itself: the saved payment method belongs to that account.
		$renewal_enabled = $consent && $token && $adapter && (int) $order->get_customer_id() > 0 && (int) $order->get_customer_id() === $customer_id && (int) $token->get_user_id() === $customer_id && $adapter->supports_saved_token( $token_id, $customer_id );
		if ( ! $renewal_enabled ) {
			$token_id   = 0;
			$gateway_id = '';
		}

		// T-132: the license renews at the regular price of the product or
		// variation that was bought. A plan mapped on a variable parent holds
		// the lowest variation price, which is wrong for every other variation.
		$purchased = $item->get_product();
		$renewal_product_id = $purchased ? (int) $purchased->get_id() : 0;
		$renewal_price = $purchased ? Catalog::product_regular_price( $purchased ) : null;

		$stored = self::store_license(
			$plan,
			array(
				'customer_id' => $customer_id,
				'renewal_enabled' => $renewal_enabled ? 1 : 0,
				'payment_gateway_id' => $gateway_id,
				'payment_token_id' => $token_id,
				'starts_at' => $starts_at,
				'paid_until' => $paid_until,
				'next_payment_at' => $paid_until,
				'source_order_id' => (int) $order->get_id(),
				'source_order_item_id' => (int) $item->get_id(),
				'unit_index' => $unit_index,
				'renewal_product_id' => $renewal_product_id,
				'renewal_price' => $renewal_price,
			)
		);
		if ( ! $stored ) {
			// A concurrent paid-order hook may have issued this unit a moment earlier
			// (UNIQUE order_unit): that license counts, and that request announces it.
			return self::find_existing( (int) $item->get_id(), $unit_index );
		}
		$license_id = $stored['id'];
		$uuid       = $stored['uuid'];
		$key        = $stored['key'];

		if ( $renewal_enabled ) {
			// B-57: the gateway's own customer and payment method references and the mode of the purchase.
			\WPLC\Gateway\PaymentReference::store( $license_id, $gateway_id, $token_id, $customer_id );
			self::store_consent_evidence( $license_id, $order, $plan, $customer_id, $consent );
			RenewalScheduler::schedule_next( $license_id );
		}

		// Queued before anything else can fail: if this request dies now, the job still sends the key email.
		Requirements::enqueue_async_action( self::FOLLOWUP_HOOK, array( $license_id ) );

		$order->add_order_note(
			sprintf(
				/* translators: 1: license prefix, 2: order item ID. */
				__( 'WP License Captain issued license %1$s for order item #%2$d.', 'wp-license' ),
				LicenseKey::prefix( $uuid ),
				(int) $item->get_id()
			)
		);

		/**
		 * Fires after a license is issued. The key is derived and never persisted in plaintext.
		 *
		 * Listeners that do not need the key should prefer `wplc/license_issued_followup`,
		 * which also runs when the issuing request was interrupted.
		 *
		 * @param int    $license_id License database ID.
		 * @param string $license_key Newly issued key.
		 * @param int    $order_id WooCommerce order ID.
		 * @since 0.1.0
		 */
		do_action( 'wplc/license_issued', $license_id, $key, (int) $order->get_id() );

		return $license_id;
	}

	/**
	 * Keep evidence of the automatic renewal consent for one license: when, in
	 * which checkout and language, which wording, which amount and interval.
	 *
	 * @param int                 $license_id License ID.
	 * @param \WC_Order           $order Order with the consent.
	 * @param array<string,mixed> $plan Catalog plan.
	 * @param int                 $customer_id Customer.
	 * @param string              $consent_at UTC time of the consent.
	 * @return void
	 */
	private static function store_consent_evidence( int $license_id, $order, array $plan, int $customer_id, string $consent_at ): void {
		global $wpdb;

		$evidence = $order->get_meta( RecurringConsent::META_EVIDENCE, true );
		$evidence = is_array( $evidence ) ? $evidence : array();
		$text     = isset( $evidence['text'] ) && is_string( $evidence['text'] ) ? $evidence['text'] : '';
		$when     = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $consent_at, new \DateTimeZone( 'UTC' ) );
		$tables   = Schema::table_names();

		$wpdb->insert(
			$tables['consents'],
			array(
				'license_id' => $license_id,
				'order_id' => (int) $order->get_id(),
				'customer_id' => $customer_id,
				'consent_at' => $when ? $when->format( 'Y-m-d H:i:s' ) : gmdate( 'Y-m-d H:i:s' ),
				'source' => substr( sanitize_key( (string) ( $evidence['source'] ?? '' ) ), 0, 20 ),
				'locale' => substr( sanitize_text_field( (string) ( $evidence['locale'] ?? '' ) ), 0, 20 ),
				'text_hash' => hash( 'sha256', $text ),
				'consent_text' => $text,
				'amount_minor' => max( 0, (int) $plan['price_minor'] ),
				'currency_decimals' => max( 0, min( 6, (int) ( $plan['price_decimals'] ?? 2 ) ) ),
				'currency' => strtoupper( sanitize_text_field( (string) $plan['currency'] ) ),
				'billing_interval' => (string) $plan['billing_interval'],
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Whether an order is a renewal or resubscribe order of WooCommerce Subscriptions.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool False when WooCommerce Subscriptions is not active.
	 */
	public static function is_subscriptions_renewal( \WC_Order $order ): bool {
		foreach ( array( 'wcs_order_contains_renewal', 'wcs_order_contains_resubscribe' ) as $function ) {
			if ( function_exists( $function ) && $function( $order ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A new license UUID (RFC 4122 version 4) from the CSPRNG (P-63).
	 *
	 * The UUID is part of the license key and its first 32 bits are the public
	 * key prefix, so it must not come from mt_rand() as wp_generate_uuid4() does.
	 *
	 * @return string Lowercase UUID, e.g. 0f8b7a3c-1d2e-4f5a-9b6c-7d8e9f0a1b2c.
	 */
	public static function new_uuid(): string {
		$bytes    = random_bytes( 16 );
		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );

		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $bytes ), 4 ) );
	}

	/**
	 * Generate a key, store one license row and snapshot the plan entitlements.
	 *
	 * Shared by order-based and manual issuing. The license only becomes
	 * active after every entitlement has been stored.
	 *
	 * @param array<string,mixed> $plan Catalog plan.
	 * @param array<string,mixed> $context customer_id, renewal_enabled, payment_gateway_id, payment_token_id,
	 *                                     starts_at, paid_until, next_payment_at, source_order_id,
	 *                                     source_order_item_id (null for manual licenses), unit_index,
	 *                                     renewal_product_id and renewal_price (optional; the plan price
	 *                                     is used when no price of the purchased product is given).
	 * @return array{id:int,uuid:string,key:string}|null
	 */
	private static function store_license( array $plan, array $context ): ?array {
		global $wpdb;

		$price = self::renewal_price( $plan, $context['renewal_price'] ?? null );

		$tables = Schema::table_names();
		$uuid   = self::new_uuid();
		$key_id = LicenseKey::current_key_id();
		$key    = LicenseKey::for_uuid( $uuid, $key_id );
		if ( '' === $key_id || '' === $key ) {
			// WPLC_LICENSE_KEY_ID names no entry of WPLC_LICENSE_KEY_SECRETS (typo during a key rotation): no
			// license can be issued until wp-config.php is fixed. Say so instead of failing silently.
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error(
					sprintf( 'License not issued: the current license key ID "%s" (WPLC_LICENSE_KEY_ID) has no secret in WPLC_LICENSE_KEY_SECRETS.', $key_id ),
					array( 'source' => 'wp-license' )
				);
			}
			self::$last_failure = self::FAILED_KEY_ID;
			return null;
		}

		// Order units are unique (source_order_item_id, unit_index); a concurrent hook that lost
		// the race gets 0 here without a database error in the log.
		$result = Schema::insert_unique(
			$tables['licenses'],
			array(
				'license_uuid' => $uuid,
				'license_key_hash' => LicenseKey::hash( $key ),
				'license_key_secret_id' => $key_id,
				'key_prefix' => LicenseKey::prefix( $uuid ),
				'customer_id' => (int) $context['customer_id'],
				'product_key' => (string) $plan['product_key'],
				'plan_key' => (string) $plan['plan_key'],
				'parent_license_id' => null,
				'status' => 'pending',
				'status_reason' => '',
				'renewal_enabled' => (int) $context['renewal_enabled'],
				'payment_gateway_id' => (string) $context['payment_gateway_id'],
				'payment_token_id' => (int) $context['payment_token_id'],
				'renewal_amount_minor' => $price['amount_minor'],
				'renewal_currency_decimals' => $price['decimals'],
				'renewal_currency' => $price['currency'],
				'renewal_product_id' => max( 0, (int) ( $context['renewal_product_id'] ?? 0 ) ),
				// B-76: how the stored amount is to be read stays fixed, whatever the shop setting becomes later.
				'renewal_amount_includes_tax' => function_exists( 'wc_prices_include_tax' ) && wc_prices_include_tax() ? 1 : 0,
				'cancel_at_period_end' => 0,
				'revoked_at' => null,
				'billing_interval' => (string) $plan['billing_interval'],
				'starts_at' => (string) $context['starts_at'],
				'paid_until' => (string) $context['paid_until'],
				'next_payment_at' => $context['next_payment_at'],
				'source_order_id' => $context['source_order_id'],
				'source_order_item_id' => $context['source_order_item_id'],
				'unit_index' => (int) $context['unit_index'],
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s' )
		);

		if ( ! $result ) {
			return null;
		}

		$license_id = $result;
		$entitlements = json_decode( (string) $plan['entitlements_json'], true );
		if ( is_array( $entitlements ) ) {
			foreach ( $entitlements as $entitlement_key => $value ) {
				if ( ! is_string( $entitlement_key ) || ! preg_match( '/^[a-zA-Z0-9_.-]{1,120}$/', $entitlement_key ) ) {
					continue;
				}

				$value_json        = wp_json_encode( $value );
				$entitlement_saved = $wpdb->insert(
					$tables['entitlements'],
					array(
						'license_id' => $license_id,
						'entitlement_key' => $entitlement_key,
						'value_json' => false === $value_json ? 'null' : $value_json,
						'source_order_item_id' => $context['source_order_item_id'],
						'starts_at' => (string) $context['starts_at'],
						'ends_at' => (string) $context['paid_until'],
						'status' => 'active',
					),
					array( '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
				);
				if ( false === $entitlement_saved ) {
					self::discard_pending_license( $license_id );
					return null;
				}
			}
		}

		$activated = $wpdb->update(
			$tables['licenses'],
			array( 'status' => 'active' ),
			array(
				'id' => $license_id,
				'status' => 'pending',
			),
			array( '%s' ),
			array( '%d', '%s' )
		);
		if ( 1 !== $activated ) {
			self::discard_pending_license( $license_id );
			return null;
		}

		return array(
			'id' => $license_id,
			'uuid' => $uuid,
			'key' => $key,
		);
	}

	/**
	 * Renewal price stored on a new license.
	 *
	 * The regular price of the purchased product wins; the plan price is the
	 * fallback (manual licenses, products without a usable price).
	 *
	 * @param array<string,mixed>                                         $plan Catalog plan.
	 * @param array{amount_minor:int,decimals:int,currency:string}|null $product_price Regular price of the purchased product.
	 * @return array{amount_minor:int,decimals:int,currency:string}
	 */
	public static function renewal_price( array $plan, ?array $product_price ): array {
		if ( is_array( $product_price ) && (int) $product_price['amount_minor'] > 0 ) {
			return array(
				'amount_minor' => (int) $product_price['amount_minor'],
				'decimals' => max( 0, min( 6, (int) $product_price['decimals'] ) ),
				'currency' => strtoupper( (string) $product_price['currency'] ),
			);
		}

		return array(
			'amount_minor' => max( 0, (int) ( $plan['price_minor'] ?? 0 ) ),
			'decimals' => max( 0, min( 6, (int) ( $plan['price_decimals'] ?? 2 ) ) ),
			'currency' => strtoupper( sanitize_text_field( (string) ( $plan['currency'] ?? '' ) ) ),
		);
	}

	/**
	 * Issue a license without an order (goodwill, migration). Audited.
	 *
	 * The license does not renew automatically; the customer can renew it manually.
	 *
	 * @param int                 $customer_id WordPress user ID of the owner.
	 * @param array<string,mixed> $plan Active catalog plan row.
	 * @param string              $paid_until_utc End of the term, 'Y-m-d H:i:s' in UTC, in the future.
	 * @param int                 $actor_user_id Administrator who issues the license.
	 * @param string              $reason Mandatory reason.
	 * @return int License ID or zero on failure.
	 */
	public static function issue_manual( int $customer_id, array $plan, string $paid_until_utc, int $actor_user_id, string $reason ): int {
		self::$last_failure = self::FAILED_INPUT;
		$reason             = trim( $reason );
		$end                = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $paid_until_utc, new \DateTimeZone( 'UTC' ) );
		if (
			$customer_id <= 0
			|| '' === $reason
			|| ! $end instanceof \DateTimeImmutable
			|| $end->format( 'Y-m-d H:i:s' ) !== $paid_until_utc
			|| $end->getTimestamp() <= time()
			|| empty( $plan['product_key'] )
			|| empty( $plan['plan_key'] )
			|| ! in_array( (string) ( $plan['billing_interval'] ?? '' ), array( 'month', 'year' ), true )
			|| ! get_userdata( $customer_id )
		) {
			return 0;
		}

		// From here a failure is a failed insert, unless store_license() names the key ID instead.
		self::$last_failure = self::FAILED_STORE;
		$stored             = self::store_license(
			$plan,
			array(
				'customer_id' => $customer_id,
				'renewal_enabled' => 0,
				'payment_gateway_id' => '',
				'payment_token_id' => 0,
				'starts_at' => gmdate( 'Y-m-d H:i:s' ),
				'paid_until' => $paid_until_utc,
				'next_payment_at' => null,
				'source_order_id' => null,
				'source_order_item_id' => null,
				'unit_index' => 0,
				'renewal_product_id' => (int) ( $plan['woocommerce_variation_id'] ?? 0 ) ? (int) $plan['woocommerce_variation_id'] : (int) ( $plan['woocommerce_product_id'] ?? 0 ),
			)
		);
		if ( ! $stored ) {
			return 0;
		}
		self::$last_failure = '';

		AuditLog::record(
			'license_issued_manually',
			'license',
			$stored['id'],
			$reason,
			null,
			array(
				'status' => 'active',
				'product_key' => (string) $plan['product_key'],
				'plan_key' => (string) $plan['plan_key'],
				'paid_until' => $paid_until_utc,
				'customer_id' => $customer_id,
			),
			$actor_user_id
		);

		/** This action is documented in LicenseIssuer::create_license(); the order ID is zero for manual licenses. */
		do_action( 'wplc/license_issued', $stored['id'], $stored['key'], 0 );
		// A license issued by hand has no follow-up job: its contract event is raised here (order ID zero).
		\WPLC\Events\LicenseEvents::on_license_issued( $stored['id'], 0 );

		return $stored['id'];
	}

	/**
	 * Finish the work of the issuing request for one license.
	 *
	 * Runs as an Action Scheduler job right after the checkout request, and from
	 * the daily run for licenses without a key email. The email is protected
	 * against duplicates by the notification log, so this only sends what the
	 * checkout request did not.
	 *
	 * @param mixed $license_id License ID.
	 * @return void
	 */
	public static function run_followup( $license_id ): void {
		global $wpdb;

		$license_id = absint( $license_id );
		$tables     = Schema::table_names();
		$license    = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name comes from Schema::table_names().
			$wpdb->prepare( "SELECT id, license_uuid, license_key_secret_id, license_key_hash, status, source_order_id FROM {$tables['licenses']} WHERE id = %d", $license_id ),
			ARRAY_A
		);
		if ( ! is_array( $license ) || 'active' !== (string) $license['status'] ) {
			return;
		}

		$order_id = (int) $license['source_order_id'];
		// Only a key that reproduces the stored hash is mailed (P-62): a wrong key ID or a
		// renamed key-ring entry would otherwise send the customer an invalid key.
		$key = LicenseKey::verified_for_license( (string) $license['license_uuid'], (string) $license['license_key_secret_id'], (string) $license['license_key_hash'] );
		if ( '' !== $key ) {
			// Does nothing when the email for this license is already on record.
			Notifications::on_license_issued( $license_id, $key, $order_id );
		} elseif ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning(
				sprintf( 'License #%d: the key email was not sent because the configured secret no longer reproduces the stored key (see wp wplc recover-license-key-ids).', $license_id ),
				array( 'source' => 'wp-license' )
			);
		}

		// The follow-up runs in its own job, outside every lock and transaction; AfterCommit
		// keeps that promise should a caller ever run it inside one (WFC-LIC-07).
		\WPLC\Events\AfterCommit::run(
			static function () use ( $license_id, $order_id ): void {
				/**
				 * Fires once the follow-up work for an issued license is done. No key is passed.
				 *
				 * Unlike `wplc/license_issued` this also runs when the issuing request was
				 * interrupted, and it can run more than once for a license: listeners must be idempotent.
				 * Fires after the license is committed and no license lock is held.
				 *
				 * @since 0.2.0
				 *
				 * @param int $license_id License ID.
				 * @param int $order_id   Source order ID.
				 */
				do_action( 'wplc/license_issued_followup', $license_id, $order_id );
			}
		);
	}

	/**
	 * Daily safety net for everything an interrupted request left behind.
	 *
	 * 1. `pending` license rows of aborted requests are removed and their orders issued again.
	 * 2. Paid orders that are flagged as not fully issued are tried again.
	 * 3. Licenses of the last three days whose key email was never sent get their follow-up.
	 *
	 * @return void
	 */
	public static function recover_unfinished(): void {
		global $wpdb;

		$tables    = Schema::table_names();
		$order_ids = array();

		$stale = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name comes from Schema::table_names().
				"SELECT id, source_order_id FROM {$tables['licenses']} WHERE status = 'pending' AND created_at < %s ORDER BY id ASC LIMIT 200",
				gmdate( 'Y-m-d H:i:s', time() - self::PENDING_GRACE )
			),
			ARRAY_A
		);
		foreach ( (array) $stale as $row ) {
			if ( self::discard_pending_license( (int) $row['id'] ) && (int) $row['source_order_id'] > 0 ) {
				$order_ids[ (int) $row['source_order_id'] ] = true;
			}
		}

		if ( function_exists( 'wc_get_orders' ) ) {
			$query = array(
				'limit' => 50,
				'return' => 'ids',
				'orderby' => 'date',
				'order' => 'DESC',
			);
			$util  = '\\Automattic\\WooCommerce\\Utilities\\OrderUtil';
			if ( class_exists( $util ) && call_user_func( array( $util, 'custom_orders_table_usage_is_enabled' ) ) ) {
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- daily, limited, only flagged orders carry the key.
				$query['meta_query'] = array(
					array(
						'key' => self::META_PROBLEM,
						'compare' => 'EXISTS',
					),
				);
			} else {
				// The legacy order storage does not accept meta_query; these two arguments reach WP_Query.
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- daily, limited, only flagged orders carry the key.
				$query['meta_key']     = self::META_PROBLEM;
				$query['meta_compare'] = 'EXISTS';
			}
			$flagged = wc_get_orders( $query );
			foreach ( (array) $flagged as $order_id ) {
				$order_ids[ (int) $order_id ] = true;
			}
		}

		foreach ( array_keys( $order_ids ) as $order_id ) {
			try {
				self::maybe_issue_order( (int) $order_id );
			} catch ( \Throwable $error ) {
				if ( function_exists( 'wc_get_logger' ) ) {
					wc_get_logger()->error( 'Retrying license issuance for order ' . (int) $order_id . ' failed: ' . $error->getMessage(), array( 'source' => 'wp-license' ) );
				}
			}
		}

		// Licenses of the last three days whose key email was never claimed in the notification log.
		// The log is joined directly: its duplicate check is keyed by the paid-until date, which a
		// renewal or an admin change moves, so calling the notifier again could send a second email.
		$log_table = ReminderLog::table_name();
		$recent    = array();
		if ( preg_match( '/^[A-Za-z0-9_]+$/', $log_table ) ) {
			$suppressed = $wpdb->suppress_errors( true ); // The log table creates itself on first use and may not exist yet.
			$recent     = $wpdb->get_col(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from Schema::table_names() and ReminderLog::table_name().
					"SELECT l.id FROM {$tables['licenses']} l LEFT JOIN {$log_table} r ON r.license_id = l.id AND r.reminder_type = 'issued' WHERE l.status = 'active' AND l.source_order_id IS NOT NULL AND l.customer_id > 0 AND l.created_at >= %s AND r.id IS NULL ORDER BY l.id ASC LIMIT 200",
					gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS )
				)
			);
			$wpdb->suppress_errors( $suppressed );
		}
		foreach ( (array) $recent as $license_id ) {
			try {
				self::run_followup( (int) $license_id );
			} catch ( \Throwable $error ) {
				continue;
			}
		}
	}

	/**
	 * Remove a pending license whose creation did not finish.
	 *
	 * The license row goes first and only while it is still pending, so a license
	 * that another request activated in the meantime keeps its entitlements.
	 *
	 * @param int $license_id License ID.
	 * @return bool True when the pending row was removed.
	 */
	private static function discard_pending_license( int $license_id ): bool {
		global $wpdb;

		$tables  = Schema::table_names();
		$deleted = $wpdb->delete(
			$tables['licenses'],
			array(
				'id' => $license_id,
				'status' => 'pending',
			),
			array( '%d', '%s' )
		);
		if ( 1 !== $deleted ) {
			return false;
		}
		$wpdb->delete( $tables['entitlements'], array( 'license_id' => $license_id ), array( '%d' ) );

		return true;
	}
}
