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
	 * Licenses exported per page.
	 */
	private const EXPORT_LICENSES_PER_PAGE = 20;

	/**
	 * Installations exported together with their license. Further rows follow on later pages.
	 */
	private const EXPORT_ACTIVATIONS_PER_LICENSE = 100;

	/**
	 * Installations per page once the license pages are exhausted.
	 */
	private const EXPORT_OVERFLOW_PER_PAGE = 100;

	/**
	 * Export one page of license and installation information for a WordPress user.
	 *
	 * Pages 1..n walk the customer's licenses in ID order, each with its first
	 * installations. Licenses with more installations than fit are continued on
	 * the pages after n; WordPress merges those rows into the same export item
	 * because they share the item ID. "done" is only reported when both are exhausted.
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

		$tables        = Schema::table_names();
		$user_id       = (int) $user->ID;
		$page          = max( 1, $page );
		$limit         = self::EXPORT_LICENSES_PER_PAGE;
		$license_count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$tables['licenses']} WHERE customer_id = %d", $user_id )
		);
		$license_pages = max( 1, intdiv( $license_count + $limit - 1, $limit ) );
		if ( $page > $license_pages ) {
			return self::export_overflow_activations( $user_id, $page - $license_pages );
		}

		$licenses = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$tables['licenses']} WHERE customer_id = %d ORDER BY id ASC LIMIT %d OFFSET %d",
				$user_id,
				$limit,
				( $page - 1 ) * $limit
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
					"SELECT site_url,environment,status,product_version,wp_version,php_version,activated_at,last_seen_at,deactivated_at FROM {$tables['activations']} WHERE license_id = %d ORDER BY id ASC LIMIT %d",
					(int) $license['id'],
					self::EXPORT_ACTIVATIONS_PER_LICENSE
				),
				ARRAY_A
			);
			foreach ( (array) $activations as $index => $activation ) {
				$license_data = array_merge( $license_data, self::activation_export_fields( $activation, $index + 1 ) );
			}

			$data[] = array(
				'group_id' => 'wp-license-captain',
				'group_label' => __( 'WP License Captain licenses', 'wp-license' ),
				'item_id' => 'license-' . (string) $license['license_uuid'],
				'data' => $license_data,
			);
		}

		if ( $page < $license_pages ) {
			return array( 'data' => $data, 'done' => false );
		}

		$overflow = self::overflow_activations( $user_id, 1, 0 );
		return array(
			'data' => $data,
			'done' => null !== $overflow && ! $overflow,
		);
	}

	/**
	 * Export installations that did not fit next to their license.
	 *
	 * @param int $user_id       Customer user ID.
	 * @param int $overflow_page Page within the overflow phase, starting at 1.
	 * @return array<string,mixed>
	 */
	private static function export_overflow_activations( int $user_id, int $overflow_page ): array {
		$limit  = self::EXPORT_OVERFLOW_PER_PAGE;
		$offset = ( max( 1, $overflow_page ) - 1 ) * $limit;
		$rows   = self::overflow_activations( $user_id, $limit + 1, $offset );
		if ( null === $rows ) {
			// Database error: keep the request retryable instead of reporting a complete export.
			return array( 'data' => array(), 'done' => false );
		}

		$done = count( $rows ) <= $limit;
		$data = array();
		foreach ( array_slice( $rows, 0, $limit ) as $index => $activation ) {
			$data[] = array(
				'group_id' => 'wp-license-captain',
				'group_label' => __( 'WP License Captain licenses', 'wp-license' ),
				'item_id' => 'license-' . (string) $activation['license_uuid'],
				'data' => self::activation_export_fields( $activation, self::EXPORT_ACTIVATIONS_PER_LICENSE + $offset + $index + 1 ),
			);
		}

		return array( 'data' => $data, 'done' => $done );
	}

	/**
	 * Installations beyond the first EXPORT_ACTIVATIONS_PER_LICENSE of each license of a customer.
	 *
	 * @param int $user_id Customer user ID.
	 * @param int $limit   Maximum rows.
	 * @param int $offset  Rows to skip.
	 * @return array<int,array<string,mixed>>|null Null on database error.
	 */
	private static function overflow_activations( int $user_id, int $limit, int $offset ): ?array {
		global $wpdb;

		$tables = Schema::table_names();
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.site_url,a.environment,a.status,a.product_version,a.wp_version,a.php_version,a.activated_at,a.last_seen_at,a.deactivated_at,l.license_uuid
				FROM {$tables['activations']} a
				INNER JOIN {$tables['licenses']} l ON l.id = a.license_id
				WHERE l.customer_id = %d
				AND a.id > ( SELECT x.id FROM {$tables['activations']} x WHERE x.license_id = a.license_id ORDER BY x.id ASC LIMIT 1 OFFSET %d )
				ORDER BY a.id ASC LIMIT %d OFFSET %d",
				$user_id,
				self::EXPORT_ACTIVATIONS_PER_LICENSE - 1,
				$limit,
				$offset
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			return null;
		}
		return $rows;
	}

	/**
	 * Export rows for one installation. Secrets and verifiers are never included.
	 *
	 * @param array<string,mixed> $activation Activation row.
	 * @param int                 $number     Running number used in the labels.
	 * @return array<int,array<string,string>>
	 */
	private static function activation_export_fields( array $activation, int $number ): array {
		$fields = array(
			/* translators: %d: running number of the installation. */
			'site_url' => __( 'Installation %d site URL', 'wp-license' ),
			/* translators: %d: running number of the installation. */
			'environment' => __( 'Installation %d environment', 'wp-license' ),
			/* translators: %d: running number of the installation. */
			'status' => __( 'Installation %d status', 'wp-license' ),
			/* translators: %d: running number of the installation. */
			'product_version' => __( 'Installation %d product version', 'wp-license' ),
			/* translators: %d: running number of the installation. */
			'wp_version' => __( 'Installation %d WordPress version', 'wp-license' ),
			/* translators: %d: running number of the installation. */
			'php_version' => __( 'Installation %d PHP version', 'wp-license' ),
			/* translators: %d: running number of the installation. */
			'activated_at' => __( 'Installation %d activated at', 'wp-license' ),
			/* translators: %d: running number of the installation. */
			'last_seen_at' => __( 'Installation %d last seen at', 'wp-license' ),
			/* translators: %d: running number of the installation. */
			'deactivated_at' => __( 'Installation %d deactivated at', 'wp-license' ),
		);

		$rows = array();
		foreach ( $fields as $column => $label ) {
			$value = isset( $activation[ $column ] ) ? (string) $activation[ $column ] : '';
			if ( '' === $value && ! in_array( $column, array( 'site_url', 'environment', 'status', 'activated_at' ), true ) ) {
				continue;
			}
			$rows[] = array( 'name' => sprintf( $label, $number ), 'value' => $value );
		}

		return $rows;
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
			if ( false === $detached ) {
				$failed = true;
				break;
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
				/* translators: %d: number of licenses. */
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
				/* translators: %d: number of licenses. */
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
