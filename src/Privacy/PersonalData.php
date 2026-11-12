<?php
/**
 * WordPress personal data exporter and eraser.
 *
 * @package WPLC
 */

namespace WPLC\Privacy;

use WPLC\Infrastructure\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exports customer license data and erases eligible inactive installation data.
 */
final class PersonalData {

	/**
	 * License states whose service period has ended and may be detached.
	 *
	 * @var string[]
	 */
	private const TERMINAL_STATES = array( 'expired', 'revoked', 'cancelled' );

	/**
	 * Register WordPress privacy hooks.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'register_eraser' ) );
	}

	/**
	 * Register this plugin's exporter.
	 *
	 * @param array<string,array<string,mixed>> $exporters Existing exporters.
	 * @return array<string,array<string,mixed>>
	 */
	public static function register_exporter( array $exporters ): array {
		$exporters['wp-license-captain'] = array(
			'exporter_friendly_name' => __( 'WP License Captain', 'wp-license' ),
			'callback' => array( self::class, 'export_personal_data' ),
		);
		return $exporters;
	}

	/**
	 * Register this plugin's eraser.
	 *
	 * @param array<string,array<string,mixed>> $erasers Existing erasers.
	 * @return array<string,array<string,mixed>>
	 */
	public static function register_eraser( array $erasers ): array {
		$erasers['wp-license-captain'] = array(
			'eraser_friendly_name' => __( 'WP License Captain', 'wp-license' ),
			'callback' => array( self::class, 'erase_personal_data' ),
		);
		return $erasers;
	}

	/**
	 * Export one page of license and installation information for a WordPress user.
	 *
	 * @param string $email_address User email.
	 * @param int    $page          Page number.
	 * @return array<string,mixed>
	 */
	public static function export_personal_data( string $email_address, int $page = 1 ): array {
		global $wpdb;

		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return array( 'data' => array(), 'done' => true );
		}

		$tables = Schema::table_names();
		$limit  = 100;
		$offset = max( 0, $page - 1 ) * $limit;
		$licenses = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$tables['licenses']} WHERE customer_id = %d ORDER BY id ASC LIMIT %d OFFSET %d",
				(int) $user->ID,
				$limit,
				$offset
			),
			ARRAY_A
		);

		$data = array();
		foreach ( (array) $licenses as $license ) {
			$license_data = array(
				array( 'name' => __( 'License reference', 'wp-license' ), 'value' => (string) $license['key_prefix'] ),
				array( 'name' => __( 'Product', 'wp-license' ), 'value' => (string) $license['product_key'] ),
				array( 'name' => __( 'Plan', 'wp-license' ), 'value' => (string) $license['plan_key'] ),
				array( 'name' => __( 'Status', 'wp-license' ), 'value' => (string) $license['status'] ),
				array( 'name' => __( 'Started', 'wp-license' ), 'value' => (string) $license['starts_at'] ),
				array( 'name' => __( 'Paid through', 'wp-license' ), 'value' => (string) $license['paid_until'] ),
				array( 'name' => __( 'Cancellation at period end', 'wp-license' ), 'value' => ! empty( $license['cancel_at_period_end'] ) ? __( 'Yes', 'wp-license' ) : __( 'No', 'wp-license' ) ),
			);

			if ( ! empty( $license['source_order_id'] ) && function_exists( 'wc_get_order' ) ) {
				$order = wc_get_order( (int) $license['source_order_id'] );
				if ( $order ) {
					$license_data[] = array( 'name' => __( 'WooCommerce order', 'wp-license' ), 'value' => (string) $order->get_order_number() );
				}
			}

			$activations = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT site_url,environment,status,activated_at,last_seen_at,deactivated_at FROM {$tables['activations']} WHERE license_id = %d ORDER BY id ASC",
					(int) $license['id']
				),
				ARRAY_A
			);
			foreach ( (array) $activations as $index => $activation ) {
				$license_data[] = array( 'name' => sprintf( __( 'Installation %d site URL', 'wp-license' ), $index + 1 ), 'value' => (string) $activation['site_url'] );
				$license_data[] = array( 'name' => sprintf( __( 'Installation %d environment', 'wp-license' ), $index + 1 ), 'value' => (string) $activation['environment'] );
				$license_data[] = array( 'name' => sprintf( __( 'Installation %d status', 'wp-license' ), $index + 1 ), 'value' => (string) $activation['status'] );
				$license_data[] = array( 'name' => sprintf( __( 'Installation %d activated at', 'wp-license' ), $index + 1 ), 'value' => (string) $activation['activated_at'] );
			}

			$data[] = array(
				'group_id' => 'wp-license-captain',
				'group_label' => __( 'WP License Captain licenses', 'wp-license' ),
				'item_id' => 'license-' . (string) $license['license_uuid'],
				'data' => $license_data,
			);
		}

		return array(
			'data' => $data,
			'done' => count( (array) $licenses ) < $limit,
		);
	}

	/**
	 * Erase inactive installation details and detach terminal licenses from the account.
	 *
	 * Active or pending licenses are retained to avoid breaking a paid service or
	 * a purchase still being processed. WooCommerce orders remain managed by WC.
	 *
	 * @param string $email_address User email.
	 * @param int    $page          Page number.
	 * @return array<string,mixed>
	 */
	public static function erase_personal_data( string $email_address, int $page = 1 ): array {
		global $wpdb;

		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true );
		}

		$tables = Schema::table_names();
		$limit      = 100;
		$cursor_key = 'wplc_privacy_eraser_cursor_' . (int) $user->ID;
		$cursor     = (int) get_option( $cursor_key, 0 );
		$licenses   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id,status FROM {$tables['licenses']} WHERE customer_id = %d AND id > %d ORDER BY id ASC LIMIT %d",
				(int) $user->ID,
				$cursor,
				$limit
			),
			ARRAY_A
		);

		$removed = 0;
		$retained = 0;
		$failed = false;
		$last_id = $cursor;
		foreach ( (array) $licenses as $license ) {
			$current_id = (int) $license['id'];
			if ( ! in_array( (string) $license['status'], self::TERMINAL_STATES, true ) ) {
				++$retained;
				$last_id = $current_id;
				continue;
			}

			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$tables['activations']} WHERE license_id = %d AND status <> %s",
					(int) $license['id'],
					'active'
				)
			);
			if ( false === $deleted ) {
				$failed = true;
				break;
			}

			$detached = $wpdb->update(
				$tables['licenses'],
				array( 'customer_id' => 0, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ),
				array( 'id' => (int) $license['id'], 'customer_id' => (int) $user->ID ),
				array( '%d', '%s' ),
				array( '%d', '%d' )
			);
			if ( false === $detached || 0 === $detached ) {
				$failed = true;
				continue;
			}
			++$removed;
			$last_id = $current_id;
		}

		$done = count( (array) $licenses ) < $limit && ! $failed;
		if ( $done ) {
			delete_option( $cursor_key );
		} else {
			update_option( $cursor_key, $last_id, false );
		}
		$messages = array();
		if ( $retained > 0 ) {
			$messages[] = sprintf(
				_n(
					'%d active or pending license was retained to preserve service or purchase processing.',
					'%d active or pending licenses were retained to preserve service or purchase processing.',
					$retained,
					'wp-license'
				),
				$retained
			);
		}
		if ( $failed ) {
			$messages[] = __( 'Some records could not be erased. The request can be retried; no completion is reported.', 'wp-license' );
		}
		if ( $removed > 0 ) {
			$messages[] = sprintf(
				_n(
					'%d terminal license was detached from the account; WooCommerce order records were preserved.',
					'%d terminal licenses were detached from the account; WooCommerce order records were preserved.',
					$removed,
					'wp-license'
				),
				$removed
			);
		}

		return array(
			'items_removed' => $removed > 0,
			'items_retained' => $retained > 0,
			'messages' => $messages,
			'done' => $done,
		);
	}
}
