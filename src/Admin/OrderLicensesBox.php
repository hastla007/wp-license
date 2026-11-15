<?php
/**
 * "Licenses" box on the WooCommerce order screen.
 *
 * @package WPLC
 */

namespace WPLC\Admin;

use WPLC\Infrastructure\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Links an order to its licenses (A-123): the licenses issued for it and the
 * license a renewal order renews. Works on the HPOS and the legacy order screen.
 */
final class OrderLicensesBox {

	/**
	 * Most licenses listed in the box.
	 */
	private const LIMIT = 50;

	/**
	 * Register the meta box.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'add_meta_boxes', array( self::class, 'add_meta_box' ) );
	}

	/**
	 * Add the box to the order screen of the active order storage.
	 *
	 * @return void
	 */
	public static function add_meta_box(): void {
		if ( ! Capabilities::can( 'view_licenses' ) ) {
			return;
		}
		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		add_meta_box( 'wplc-order-licenses', __( 'Licenses', 'wp-license' ), array( self::class, 'render' ), $screen, 'side', 'default' );
	}

	/**
	 * Render the box.
	 *
	 * @param mixed $post_or_order WP_Post (legacy screen) or WC_Order (HPOS screen).
	 * @return void
	 */
	public static function render( $post_or_order ): void {
		$order = $post_or_order instanceof \WC_Order ? $post_or_order : ( function_exists( 'wc_get_order' ) && is_object( $post_or_order ) && isset( $post_or_order->ID ) ? wc_get_order( (int) $post_or_order->ID ) : false );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$licenses = self::licenses_for( $order );
		if ( ! $licenses ) {
			echo '<p>' . esc_html__( 'This order has no license.', 'wp-license' ) . '</p>';
			return;
		}

		echo '<ul>';
		foreach ( $licenses as $license ) {
			$text = (string) $license['key_prefix'] . ' (#' . (int) $license['id'] . ') — ' . Format::license_status_label( (string) $license['status'] );
			$role = 'renewed' === $license['relation'] ? __( 'renewed by this order', 'wp-license' ) : __( 'issued for this order', 'wp-license' );
			echo '<li><a href="' . esc_url( LicenseAdmin::detail_url( (int) $license['id'] ) ) . '">' . esc_html( $text ) . '</a><br><span class="description">' . esc_html( $role ) . '</span></li>';
		}
		echo '</ul>';
	}

	/**
	 * Licenses of an order: issued for it, and the one a renewal order renews.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<int,array<string,mixed>> Rows with id, key_prefix, status and relation (issued|renewed).
	 */
	public static function licenses_for( \WC_Order $order ): array {
		global $wpdb;

		$tables = Schema::table_names();
		$rows   = $wpdb->get_results(
			$wpdb->prepare( 'SELECT id, key_prefix, status FROM %i WHERE source_order_id = %d ORDER BY id ASC LIMIT %d', $tables['licenses'], (int) $order->get_id(), self::LIMIT ),
			ARRAY_A
		);
		$result = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$row['relation'] = 'issued';
			$result[]        = $row;
		}

		$renewed = class_exists( '\\WPLC\\Billing\\RenewalOrder' ) ? absint( $order->get_meta( \WPLC\Billing\RenewalOrder::META_LICENSE, true ) ) : 0;
		if ( $renewed > 0 && ! in_array( $renewed, array_map( 'intval', array_column( $result, 'id' ) ), true ) ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, key_prefix, status FROM %i WHERE id = %d', $tables['licenses'], $renewed ), ARRAY_A );
			if ( is_array( $row ) ) {
				$row['relation'] = 'renewed';
				$result[]        = $row;
			}
		}

		return $result;
	}
}
