<?php
/**
 * Keeps WooCommerce from mailing the customer twice about an automatic renewal.
 *
 * @package WPLC
 */

namespace WPLC\Gateway;

use WPLC\Billing\RenewalOrder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Switches off WooCommerce's customer status mails for automatic renewal orders (B-149).
 *
 * The plugin sends its own mails for a renewed license and for a failed
 * renewal payment. WooCommerce would add "order failed", "processing order"
 * and "order completed" for the renewal order on top, and Stripe its receipt.
 * Mails to the shop (new order, failed order, cancelled order) stay as they are,
 * and so do the mails for a renewal order the customer placed and paid by hand.
 */
final class RenewalOrderEmails {

	/**
	 * WooCommerce customer emails replaced by the plugin's own mails.
	 *
	 * @var string[]
	 */
	public const CUSTOMER_EMAIL_IDS = array(
		'customer_failed_order',
		'customer_processing_order',
		'customer_completed_order',
	);

	/**
	 * Register the filters.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		foreach ( self::CUSTOMER_EMAIL_IDS as $email_id ) {
			add_filter( 'woocommerce_email_enabled_' . $email_id, array( self::class, 'filter_enabled' ), 20, 3 );
		}
	}

	/**
	 * Disable a customer status mail for an automatic renewal order.
	 *
	 * @param mixed $enabled Whether the email is enabled.
	 * @param mixed $order Object the email is about.
	 * @param mixed $email Email instance.
	 * @return mixed
	 */
	public static function filter_enabled( $enabled, $order = null, $email = null ) {
		if ( ! $enabled || ! $order instanceof \WC_Order || ! self::is_automatic_renewal_order( $order ) ) {
			return $enabled;
		}
		$email_id = is_object( $email ) && isset( $email->id ) ? (string) $email->id : '';

		/**
		 * Filters whether a WooCommerce customer mail is suppressed for an automatic renewal order.
		 *
		 * @param bool      $suppress Default true.
		 * @param string    $email_id WooCommerce email ID, e.g. `customer_completed_order`.
		 * @param \WC_Order $order Renewal order.
		 */
		return apply_filters( 'wplc_suppress_renewal_order_email', true, $email_id, $order ) ? false : $enabled;
	}

	/**
	 * Whether an order is a renewal order created for an automatic charge.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	public static function is_automatic_renewal_order( $order ): bool {
		return (bool) $order->get_meta( RenewalOrder::META_RENEWAL, true ) && ! RenewalOrder::is_manual( $order );
	}
}
