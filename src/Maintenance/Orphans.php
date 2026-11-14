<?php
/**
 * Keeps license data consistent when the records it points to are deleted.
 *
 * @package WPLC
 */

namespace WPLC\Maintenance;

use WPLC\Infrastructure\Database\Schema;
use WPLC\License\AuditLog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reacts to a deleted customer account, a deleted payment method and a deleted order.
 *
 * None of these revokes a license: the customer paid for the period. What
 * stops is everything that needs the deleted record: automatic renewal
 * without a customer or a payment method, and the link to the account.
 */
final class Orphans {

	/**
	 * Orders already handled in this request (trash and delete hooks overlap).
	 *
	 * @var array<int,bool>
	 */
	private static $seen_orders = array();

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'deleted_user', array( self::class, 'on_user_deleted' ), 10, 1 );
		add_action( 'woocommerce_payment_token_deleted', array( self::class, 'on_payment_token_deleted' ), 10, 1 );
		add_action( 'woocommerce_before_trash_order', array( self::class, 'on_order_removed' ), 10, 1 );
		add_action( 'woocommerce_before_delete_order', array( self::class, 'on_order_removed' ), 10, 1 );
		add_action( 'wp_trash_post', array( self::class, 'on_post_removed' ), 10, 1 );
		add_action( 'before_delete_post', array( self::class, 'on_post_removed' ), 10, 1 );
	}

	/**
	 * A customer account was deleted: detach its licenses, stop automatic renewal.
	 *
	 * The licenses are not revoked. Without the account nobody can be charged
	 * or notified, so automatic renewal is switched off; deactivated
	 * installations (site addresses) of the account are deleted and the user
	 * ID is removed from the audit log.
	 *
	 * @param mixed $user_id Deleted user ID.
	 * @return void
	 */
	public static function on_user_deleted( $user_id ): void {
		global $wpdb;

		$user_id = (int) $user_id;
		if ( $user_id < 1 ) {
			return;
		}
		$tables = Schema::table_names();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- only table names are interpolated.
		for ( $round = 0; $round < 50; ++$round ) {
			$license_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$tables['licenses']} WHERE customer_id = %d ORDER BY id ASC LIMIT 200", $user_id ) );
			if ( ! $license_ids ) {
				break;
			}
			$progress = 0;
			foreach ( $license_ids as $license_id ) {
				$license_id = (int) $license_id;
				RenewalSwitch::off( $license_id, 'Customer account deleted' );
				$detached = $wpdb->update(
					$tables['licenses'],
					array(
						'customer_id' => 0,
						'updated_at'  => gmdate( 'Y-m-d H:i:s' ),
					),
					array(
						'id'          => $license_id,
						'customer_id' => $user_id,
					),
					array( '%d', '%s' ),
					array( '%d', '%d' )
				);
				if ( ! $detached ) {
					continue;
				}
				++$progress;
				$wpdb->query( $wpdb->prepare( "DELETE n FROM {$tables['request_nonces']} n INNER JOIN {$tables['activations']} a ON a.id = n.activation_id WHERE a.license_id = %d AND a.status <> %s", $license_id, 'active' ) );
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$tables['activations']} WHERE license_id = %d AND status <> %s", $license_id, 'active' ) );
				AuditLog::record( 'license_owner_deleted', 'license', $license_id, 'Customer account deleted; the license was detached from the account and is not revoked.' );
			}
			if ( $progress < 1 ) {
				break;
			}
		}

		$wpdb->query( $wpdb->prepare( "UPDATE {$tables['audit_log']} SET actor_user_id = 0 WHERE actor_user_id = %d", $user_id ) );
		// phpcs:enable
	}

	/**
	 * A stored payment method was deleted: licenses that renew with it stop renewing automatically.
	 *
	 * @param mixed $token_id WooCommerce payment token ID.
	 * @return void
	 */
	public static function on_payment_token_deleted( $token_id ): void {
		global $wpdb;

		$token_id = (int) $token_id;
		/**
		 * Filter whether deleting a payment method switches automatic renewal off.
		 *
		 * Return false when another component moves the licenses to a different payment method.
		 *
		 * @param bool $disable  Default true.
		 * @param int  $token_id Deleted payment token ID.
		 */
		if ( $token_id < 1 || ! apply_filters( 'wplc_disable_renewal_on_token_deletion', true, $token_id ) ) {
			return;
		}
		$tables = Schema::table_names();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- only the table name is interpolated.
		$license_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$tables['licenses']} WHERE payment_token_id = %d ORDER BY id ASC LIMIT 1000", $token_id ) );
		foreach ( (array) $license_ids as $license_id ) {
			RenewalSwitch::off( (int) $license_id, 'Stored payment method deleted', get_current_user_id() );
		}
	}

	/**
	 * Trash and delete of posts: only orders in the legacy order storage are relevant.
	 *
	 * @param mixed $post_id Post ID.
	 * @return void
	 */
	public static function on_post_removed( $post_id ): void {
		if ( 'shop_order' === get_post_type( (int) $post_id ) ) {
			self::on_order_removed( $post_id );
		}
	}

	/**
	 * An order was trashed or deleted: note it on the licenses it paid for.
	 *
	 * The licenses stay as they are; whether a deleted order should end its
	 * licenses is a business decision. The audit entry keeps the license
	 * explainable after the order is gone.
	 *
	 * @param mixed $order_id Order ID.
	 * @return void
	 */
	public static function on_order_removed( $order_id ): void {
		global $wpdb;

		$order_id = (int) $order_id;
		if ( $order_id < 1 || isset( self::$seen_orders[ $order_id ] ) ) {
			return;
		}
		self::$seen_orders[ $order_id ] = true;

		$tables = Schema::table_names();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- only the table name is interpolated.
		$license_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$tables['licenses']} WHERE source_order_id = %d ORDER BY id ASC LIMIT 500", $order_id ) );
		foreach ( (array) $license_ids as $license_id ) {
			AuditLog::record(
				'source_order_removed',
				'license',
				(int) $license_id,
				'The order this license was bought with was moved to the trash or deleted. The license was not changed.',
				null,
				array( 'order_id' => $order_id ),
				get_current_user_id()
			);
		}
	}
}
