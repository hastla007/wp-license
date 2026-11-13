<?php
/**
 * Converts paid order line items into licenses.
 *
 * @package WPLC
 */

namespace WPLC\License;

use WPLC\Catalog\Catalog;
use WPLC\Infrastructure\Database\Schema;
use WPLC\Gateway\Registry;
use WPLC\Billing\RenewalScheduler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Idempotent license provisioning from WooCommerce orders.
 */
final class LicenseIssuer {

	/**
	 * Register WooCommerce payment and paid-order hooks.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'woocommerce_payment_complete', array( self::class, 'maybe_issue_order' ), 20 );
		add_action( 'woocommerce_order_status_processing', array( self::class, 'maybe_issue_order' ), 20 );
		add_action( 'woocommerce_order_status_completed', array( self::class, 'maybe_issue_order' ), 20 );
	}

	/**
	 * Issue licenses for configured items on a paid order.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return void
	 */
	public static function maybe_issue_order( int $order_id ): void {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->is_paid() || $order->get_meta( '_wplc_renewal_for_license', true ) ) {
			return;
		}

		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			$product_id   = (int) $item->get_product_id();
			$variation_id = (int) $item->get_variation_id();
			$mapping      = Catalog::find_mapping( $product_id, $variation_id );
			if ( ! $mapping && $variation_id ) {
				// A variable product mapped once at parent level covers all its variations.
				$mapping = Catalog::find_mapping( $product_id, 0 );
			}

			if ( ! $mapping ) {
				continue;
			}

			$quantity = max( 0, (int) $item->get_quantity() );
			$issued   = array();

			for ( $unit_index = 0; $unit_index < $quantity; $unit_index++ ) {
				$existing_id = self::find_existing( (int) $item_id, $unit_index );
				if ( $existing_id ) {
					$issued[] = $existing_id;
					continue;
				}

				$license_id = self::create_license( $order, $item, $mapping, $unit_index );
				if ( $license_id ) {
					$issued[] = $license_id;
				}
			}

			if ( $issued ) {
				$item->update_meta_data( '_wplc_license_ids', array_values( array_unique( $issued ) ) );
				$item->save_meta_data();
			}
		}
	}

	/**
	 * Find a previously issued license for an order item unit.
	 *
	 * @param int $order_item_id Order item ID.
	 * @param int $unit_index Quantity unit index.
	 * @return int
	 */
	private static function find_existing( int $order_item_id, int $unit_index ): int {
		global $wpdb;

		$tables = Schema::table_names();
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$tables['licenses']} WHERE source_order_item_id = %d AND unit_index = %d LIMIT 1",
				$order_item_id,
				$unit_index
			)
		);
	}

	/**
	 * Create one license and snapshot its plan entitlements.
	 *
	 * @param \WC_Order $order Paid order.
	 * @param \WC_Order_Item_Product $item Order line item.
	 * @param array<string,mixed> $plan Catalog plan.
	 * @param int $unit_index Quantity unit index.
	 * @return int License ID or zero on failure.
	 */
	private static function create_license( $order, $item, array $plan, int $unit_index ): int {
		global $wpdb;

		$paid_at = $order->get_date_paid();
		$starts_at_timestamp = $paid_at ? $paid_at->getTimestamp() : time();
		$starts_at = gmdate( 'Y-m-d H:i:s', $starts_at_timestamp );
		$paid_until_timestamp = self::period_end( $starts_at_timestamp, (string) $plan['billing_interval'] );
		$paid_until = gmdate( 'Y-m-d H:i:s', $paid_until_timestamp );
		$gateway_id = sanitize_key( (string) $order->get_payment_method() );
		$token_id = absint( apply_filters( 'wplc_payment_token_id_for_order', 0, $order ) );
		$token = class_exists( 'WC_Payment_Tokens' ) ? \WC_Payment_Tokens::get( $token_id ) : false;
		$adapter = Registry::get( $gateway_id );
		$consent = (string) $order->get_meta( '_wplc_recurring_consent_at', true );
		$renewal_enabled = $consent && $token && $adapter && (int) $order->get_customer_id() > 0 && (int) $token->get_user_id() === (int) $order->get_customer_id() && $adapter->supports_saved_token( $token_id, (int) $order->get_customer_id() );
		if ( ! $renewal_enabled ) {
			$token_id = 0;
			$gateway_id = '';
		}

		$stored = self::store_license(
			$plan,
			array(
				'customer_id' => (int) $order->get_customer_id(),
				'renewal_enabled' => $renewal_enabled ? 1 : 0,
				'payment_gateway_id' => $gateway_id,
				'payment_token_id' => $token_id,
				'starts_at' => $starts_at,
				'paid_until' => $paid_until,
				'next_payment_at' => $paid_until,
				'source_order_id' => (int) $order->get_id(),
				'source_order_item_id' => (int) $item->get_id(),
				'unit_index' => $unit_index,
			)
		);
		if ( ! $stored ) {
			return 0;
		}
		$license_id = $stored['id'];
		$uuid       = $stored['uuid'];
		$key        = $stored['key'];

		if ( $renewal_enabled ) {
			RenewalScheduler::schedule_next( $license_id );
		}

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
		 * @param int    $license_id License database ID.
		 * @param string $license_key Newly issued key.
		 * @param int    $order_id WooCommerce order ID.
		 * @since 0.1.0
		 */
		do_action( 'wplc/license_issued', $license_id, $key, (int) $order->get_id() );

		return $license_id;
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
	 *                                     source_order_item_id (null for manual licenses), unit_index.
	 * @return array{id:int,uuid:string,key:string}|null
	 */
	private static function store_license( array $plan, array $context ): ?array {
		global $wpdb;

		$tables = Schema::table_names();
		$uuid   = wp_generate_uuid4();
		$key_id = LicenseKey::current_key_id();
		$key    = LicenseKey::for_uuid( $uuid, $key_id );
		if ( '' === $key_id || '' === $key ) {
			return null;
		}

		$result = $wpdb->insert(
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
				'renewal_amount_minor' => max( 0, (int) $plan['price_minor'] ),
				'renewal_currency_decimals' => max( 0, min( 6, (int) ( $plan['price_decimals'] ?? 2 ) ) ),
				'renewal_currency' => strtoupper( sanitize_text_field( (string) $plan['currency'] ) ),
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
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s' )
		);

		if ( false === $result ) {
			return null;
		}

		$license_id = (int) $wpdb->insert_id;
		$entitlements = json_decode( (string) $plan['entitlements_json'], true );
		if ( is_array( $entitlements ) ) {
			foreach ( $entitlements as $entitlement_key => $value ) {
				if ( ! is_string( $entitlement_key ) || ! preg_match( '/^[a-zA-Z0-9_.-]{1,120}$/', $entitlement_key ) ) {
					continue;
				}

				$value_json = wp_json_encode( $value );
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
			array( 'id' => $license_id, 'status' => 'pending' ),
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
		$reason = trim( $reason );
		$end    = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $paid_until_utc, new \DateTimeZone( 'UTC' ) );
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

		$stored = self::store_license(
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
			)
		);
		if ( ! $stored ) {
			return 0;
		}

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

		return $stored['id'];
	}

	/**
	 * Remove a pending license when its entitlement snapshot cannot be completed.
	 *
	 * @param int $license_id License ID.
	 * @return void
	 */
	private static function discard_pending_license( int $license_id ): void {
		global $wpdb;

		$tables = Schema::table_names();
		$wpdb->delete( $tables['entitlements'], array( 'license_id' => $license_id ), array( '%d' ) );
		$wpdb->delete(
			$tables['licenses'],
			array(
				'id' => $license_id,
				'status' => 'pending',
			),
			array( '%d', '%s' )
		);
	}

	/**
	 * Calculate a month/year period without overflowing end-of-month dates.
	 *
	 * @param int $timestamp UTC timestamp.
	 * @param string $interval month or year.
	 * @return int
	 */
	private static function period_end( int $timestamp, string $interval ): int {
		$date = new \DateTimeImmutable( '@' . $timestamp );
		$date = $date->setTimezone( new \DateTimeZone( 'UTC' ) );
		$day  = (int) $date->format( 'j' );
		$months = 'month' === $interval ? 1 : 12;

		$first_of_month = $date->modify( 'first day of this month' )->modify( '+' . $months . ' months' );
		$target_day = min( $day, (int) $first_of_month->format( 't' ) );

		return $first_of_month
			->setDate(
				(int) $first_of_month->format( 'Y' ),
				(int) $first_of_month->format( 'n' ),
				$target_day
			)
			->setTime(
				(int) $date->format( 'G' ),
				(int) $date->format( 'i' ),
				(int) $date->format( 's' )
			)
			->getTimestamp();
	}
}
