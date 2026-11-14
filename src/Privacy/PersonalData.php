<?php
/**
 * WordPress personal data exporter and eraser.
 *
 * @package WPLC
 */

namespace WPLC\Privacy;

use WPLC\Billing\Money;
use WPLC\Infrastructure\Database\Schema;
use WPLC\License\ActivationManager;
use WPLC\License\AuditLog;
use WPLC\Maintenance\RenewalSwitch;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exports the license data of a customer and erases what is no longer needed.
 */
final class PersonalData {

	/**
	 * License states whose service period has ended and may be detached.
	 *
	 * @var string[]
	 */
	private const TERMINAL_STATES = array( 'expired', 'revoked', 'cancelled' );

	/**
	 * Licenses exported per page.
	 */
	private const EXPORT_LICENSES_PER_PAGE = 20;

	/**
	 * Installations exported together with their license. Further rows follow on later pages.
	 */
	private const EXPORT_ACTIVATIONS_PER_LICENSE = 100;

	/**
	 * Rows per page of every phase after the licenses.
	 */
	private const EXPORT_ROWS_PER_PAGE = 100;

	/**
	 * Licenses erased per call.
	 */
	private const ERASE_LICENSES_PER_PAGE = 50;

	/**
	 * Export phases after the licenses, in page order.
	 *
	 * @var string[]
	 */
	private const EXPORT_PHASES = array( 'activations', 'renewals', 'consents', 'history', 'emails', 'downloads', 'streams' );

	/**
	 * Register WordPress privacy hooks.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'register_eraser' ) );
		add_action( 'admin_init', array( self::class, 'add_privacy_policy_content' ) );
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
	 * Suggest a section for the shop's privacy policy (Settings → Privacy → Policy guide).
	 *
	 * @return void
	 */
	public static function add_privacy_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content( __( 'WP License Captain', 'wp-license' ), wp_kses_post( wpautop( self::privacy_policy_text(), false ) ) );
	}

	/**
	 * Suggested privacy policy text. A starting point, not legal advice.
	 *
	 * @return string
	 */
	public static function privacy_policy_text(): string {
		$paragraphs = array(
			__( 'Suggested text (adapt it to your shop and have it checked): When you buy a software license in this shop, we store the license with your customer account: product and plan, status, start and end of the paid period, the order it belongs to and a reference to the license key (the key itself is stored in a form that cannot be read back).', 'wp-license' ),
			__( 'When you activate the license on a website, the licensed plugin sends the address of that website, an installation identifier, the plugin version and the WordPress and PHP version to our license server. We use this to check the number of websites your license covers, to deliver updates that fit your system and to help you in support cases. Each later license check and update check sends the same technical data; the IP address of the website is processed briefly to limit abuse and is not stored with the license.', 'wp-license' ),
			__( 'If you agreed to automatic renewal at checkout, we store that agreement with its date, the renewal amount, the billing interval, the next payment date and a reference to the payment method saved with our payment provider. We never store card numbers. You can switch automatic renewal off in your account at any time; the license then runs until the end of the paid period.', 'wp-license' ),
			__( 'We keep a history of changes to your license (for example renewals, cancellations, activations and deactivations of websites), a record of which license emails were sent and when, and a record of update downloads. This history is deleted automatically after the retention period; websites you deactivated are deleted after the retention period as well.', 'wp-license' ),
			__( 'You can request an export or the erasure of this data. Licenses whose paid period is still running are kept so that your websites keep working; their automatic renewal is switched off when you request erasure. Orders and invoices are kept as long as the law requires.', 'wp-license' ),
		);

		return implode( "\n\n", $paragraphs );
	}

	/**
	 * Export one page of the license data of a WordPress user.
	 *
	 * Pages 1..n walk the customer's licenses in ID order, each with its
	 * renewal settings and its first installations. The following pages
	 * export, in this order: further installations, renewals, the license
	 * history (audit entries about the customer's licenses and installations
	 * and entries the customer triggered), the email log, download grants and
	 * package downloads. "done" is reported on the last page only. A database
	 * error returns a WP_Error, which WordPress shows as a failed, retryable request.
	 *
	 * @param mixed $email_address User email.
	 * @param mixed $page          Page number.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function export_personal_data( $email_address, $page = 1 ) {
		global $wpdb;

		$user = is_string( $email_address ) && '' !== $email_address ? get_user_by( 'email', $email_address ) : false;
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$user_id = (int) $user->ID;
		$page    = max( 1, (int) $page );
		$tables  = Schema::table_names();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- only the table name is interpolated.
		$license_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['licenses']} WHERE customer_id = %d", $user_id ) );
		$license_pages = max( 1, self::pages( $license_count, self::EXPORT_LICENSES_PER_PAGE ) );

		// Pages of every phase, so that a page number maps to exactly one phase and offset.
		$phase_pages = array();
		foreach ( self::EXPORT_PHASES as $phase ) {
			$count = self::phase_rows( $phase, $user_id, 0, 0, true );
			if ( null === $count ) {
				return self::database_error();
			}
			$phase_pages[ $phase ] = self::pages( (int) $count, self::EXPORT_ROWS_PER_PAGE );
		}
		$total_pages = $license_pages + array_sum( $phase_pages );

		if ( $page <= $license_pages ) {
			$data = self::export_licenses( $user_id, $page );
			if ( null === $data ) {
				return self::database_error();
			}
			return array(
				'data' => $data,
				'done' => $page >= $total_pages,
			);
		}

		$offset_page = $page - $license_pages;
		foreach ( $phase_pages as $phase => $pages ) {
			if ( $offset_page > $pages ) {
				$offset_page -= $pages;
				continue;
			}
			$offset = ( $offset_page - 1 ) * self::EXPORT_ROWS_PER_PAGE;
			$rows   = self::phase_rows( $phase, $user_id, self::EXPORT_ROWS_PER_PAGE, $offset, false );
			if ( null === $rows ) {
				return self::database_error();
			}
			return array(
				'data' => self::phase_items( $phase, (array) $rows, $offset ),
				'done' => $page >= $total_pages,
			);
		}

		return array(
			'data' => array(),
			'done' => true,
		);
	}

	/**
	 * Number of pages for a row count.
	 *
	 * Pure.
	 *
	 * @param int $rows     Row count.
	 * @param int $per_page Rows per page.
	 * @return int
	 */
	public static function pages( int $rows, int $per_page ): int {
		return $rows < 1 ? 0 : intdiv( $rows + $per_page - 1, $per_page );
	}

	/**
	 * One page of licenses with renewal settings and their first installations.
	 *
	 * @param int $user_id Customer user ID.
	 * @param int $page    Page within the license phase.
	 * @return array<int,array<string,mixed>>|null Null on database error.
	 */
	private static function export_licenses( int $user_id, int $page ): ?array {
		global $wpdb;

		$tables = Schema::table_names();
		$limit  = self::EXPORT_LICENSES_PER_PAGE;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- only table names are interpolated.
		$licenses = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$tables['licenses']} WHERE customer_id = %d ORDER BY id ASC LIMIT %d OFFSET %d",
				$user_id,
				$limit,
				( $page - 1 ) * $limit
			),
			ARRAY_A
		);
		if ( ! is_array( $licenses ) || '' !== (string) $wpdb->last_error ) {
			return null;
		}

		$yes  = __( 'Yes', 'wp-license' );
		$no   = __( 'No', 'wp-license' );
		$data = array();
		foreach ( $licenses as $license ) {
			$license_data = array(
				array( 'name' => __( 'License reference', 'wp-license' ), 'value' => (string) $license['key_prefix'] ),
				array( 'name' => __( 'Product', 'wp-license' ), 'value' => (string) $license['product_key'] ),
				array( 'name' => __( 'Plan', 'wp-license' ), 'value' => (string) $license['plan_key'] ),
				array( 'name' => __( 'Status', 'wp-license' ), 'value' => (string) $license['status'] ),
				array( 'name' => __( 'Started', 'wp-license' ), 'value' => (string) $license['starts_at'] ),
				array( 'name' => __( 'Paid through', 'wp-license' ), 'value' => (string) $license['paid_until'] ),
				array( 'name' => __( 'Cancellation at period end', 'wp-license' ), 'value' => ! empty( $license['cancel_at_period_end'] ) ? $yes : $no ),
				array( 'name' => __( 'Automatic renewal', 'wp-license' ), 'value' => ! empty( $license['renewal_enabled'] ) ? $yes : $no ),
				array( 'name' => __( 'Billing interval', 'wp-license' ), 'value' => (string) $license['billing_interval'] ),
			);
			if ( (int) $license['renewal_amount_minor'] > 0 ) {
				$license_data[] = array(
					'name'  => __( 'Renewal amount', 'wp-license' ),
					'value' => Money::to_decimal( (int) $license['renewal_amount_minor'], (int) $license['renewal_currency_decimals'] ) . ' ' . (string) $license['renewal_currency'],
				);
			}
			if ( ! empty( $license['next_payment_at'] ) ) {
				$license_data[] = array( 'name' => __( 'Next payment', 'wp-license' ), 'value' => (string) $license['next_payment_at'] );
			}
			// Only the fact that a payment method is referenced is exported, never a token value.
			$license_data[] = array( 'name' => __( 'Payment method on file for renewal', 'wp-license' ), 'value' => (int) $license['payment_token_id'] > 0 ? $yes : $no );
			if ( '' !== (string) $license['payment_gateway_id'] ) {
				$license_data[] = array( 'name' => __( 'Payment provider', 'wp-license' ), 'value' => (string) $license['payment_gateway_id'] );
			}

			if ( ! empty( $license['source_order_id'] ) && function_exists( 'wc_get_order' ) ) {
				$order = wc_get_order( (int) $license['source_order_id'] );
				if ( $order instanceof \WC_Order ) {
					$license_data[] = array( 'name' => __( 'WooCommerce order', 'wp-license' ), 'value' => (string) $order->get_order_number() );
					$consent_at     = (string) $order->get_meta( '_wplc_recurring_consent_at', true );
					if ( '' !== $consent_at ) {
						$license_data[] = array( 'name' => __( 'Consent to automatic renewal given at (UTC)', 'wp-license' ), 'value' => $consent_at );
					}
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
		// phpcs:enable

		return $data;
	}

	/**
	 * Rows (or the row count) of one export phase.
	 *
	 * Every query is limited to licenses whose owner is the given user, so no
	 * other customer's data can appear.
	 *
	 * @param string $phase   One of EXPORT_PHASES.
	 * @param int    $user_id Customer user ID.
	 * @param int    $limit   Maximum rows.
	 * @param int    $offset  Rows to skip.
	 * @param bool   $count   Return the number of rows instead of the rows.
	 * @return array<int,array<string,mixed>>|int|null Null on database error.
	 */
	private static function phase_rows( string $phase, int $user_id, int $limit, int $offset, bool $count ) {
		global $wpdb;

		$tables = Schema::table_names();
		$owned  = "SELECT ol.id FROM {$tables['licenses']} ol WHERE ol.customer_id = %d";
		$args   = array( $user_id );

		switch ( $phase ) {
			case 'activations':
				// Installations beyond the first EXPORT_ACTIVATIONS_PER_LICENSE of each license.
				$select = 'a.site_url,a.environment,a.status,a.product_version,a.wp_version,a.php_version,a.activated_at,a.last_seen_at,a.deactivated_at,l.license_uuid';
				$from   = "FROM {$tables['activations']} a INNER JOIN {$tables['licenses']} l ON l.id = a.license_id WHERE l.customer_id = %d AND a.id > ( SELECT x.id FROM {$tables['activations']} x WHERE x.license_id = a.license_id ORDER BY x.id ASC LIMIT 1 OFFSET %d )";
				$args[] = self::EXPORT_ACTIVATIONS_PER_LICENSE - 1;
				$order  = 'a.id';
				break;

			case 'renewals':
				$select = 'r.id,r.period_start,r.period_end,r.status,r.attempt_count,r.renewal_order_id,r.created_at,l.key_prefix';
				$from   = "FROM {$tables['renewals']} r INNER JOIN {$tables['licenses']} l ON l.id = r.license_id WHERE l.customer_id = %d";
				$order  = 'r.id';
				break;

			case 'consents':
				// Evidence of the consent to automatic renewal (B-37): what the customer agreed to, when and how.
				$select = 'c.id,c.order_id,c.consent_at,c.source,c.locale,c.consent_text,c.amount_minor,c.currency_decimals,c.currency,c.billing_interval,l.key_prefix';
				$from   = "FROM {$tables['consents']} c LEFT JOIN {$tables['licenses']} l ON l.id = c.license_id WHERE ( c.customer_id = %d OR l.customer_id = %d )";
				$args   = array( $user_id, $user_id );
				$order  = 'c.id';
				break;

			case 'history':
				$select = 'h.id,h.action,h.object_type,h.reason,h.created_at';
				$from   = "FROM {$tables['audit_log']} h WHERE ( h.actor_user_id = %d OR ( h.object_type = 'license' AND h.object_id IN ( {$owned} ) ) OR ( h.object_type = 'activation' AND h.object_id IN ( SELECT oa.id FROM {$tables['activations']} oa INNER JOIN {$tables['licenses']} ol2 ON ol2.id = oa.license_id WHERE ol2.customer_id = %d ) ) )";
				$args   = array( $user_id, $user_id, $user_id );
				$order  = 'h.id';
				break;

			case 'emails':
				$select = 'm.id,m.reminder_type,m.period_end,m.sent_at,l.key_prefix';
				$from   = "FROM {$tables['reminder_log']} m INNER JOIN {$tables['licenses']} l ON l.id = m.license_id WHERE l.customer_id = %d";
				$order  = 'm.id';
				break;

			case 'downloads':
				$select = 'd.id,d.created_at,d.expires_at,d.consumed_at,l.key_prefix';
				$from   = "FROM {$tables['download_tokens']} d INNER JOIN {$tables['licenses']} l ON l.id = d.license_id WHERE l.customer_id = %d";
				$order  = 'd.id';
				break;

			case 'streams':
				$select = 's.id,s.source,s.created_at,s.consumed_at,l.key_prefix,rel.product_key,rel.version';
				$from   = "FROM {$tables['package_streams']} s INNER JOIN {$tables['licenses']} l ON l.id = s.license_id LEFT JOIN {$tables['releases']} rel ON rel.id = s.release_id WHERE l.customer_id = %d";
				$order  = 's.id';
				break;

			default:
				return $count ? 0 : array();
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- fixed fragments and table names; all values are placeholders.
		if ( $count ) {
			$result = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$from}", $args ) );
			if ( null === $result || '' !== (string) $wpdb->last_error ) {
				return null;
			}
			return (int) $result;
		}

		$args[] = $limit;
		$args[] = $offset;
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT {$select} {$from} ORDER BY {$order} ASC LIMIT %d OFFSET %d", $args ), ARRAY_A );
		// phpcs:enable
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			return null;
		}

		return $rows;
	}

	/**
	 * Turn the rows of one phase into export items.
	 *
	 * @param string                         $phase  One of EXPORT_PHASES.
	 * @param array<int,array<string,mixed>> $rows   Rows from phase_rows().
	 * @param int                            $offset Offset of the first row within the phase.
	 * @return array<int,array<string,mixed>>
	 */
	private static function phase_items( string $phase, array $rows, int $offset ): array {
		$items = array();
		foreach ( $rows as $index => $row ) {
			switch ( $phase ) {
				case 'activations':
					$items[] = array(
						'group_id' => 'wp-license-captain',
						'group_label' => __( 'WP License Captain licenses', 'wp-license' ),
						'item_id' => 'license-' . (string) $row['license_uuid'],
						'data' => self::activation_export_fields( $row, self::EXPORT_ACTIVATIONS_PER_LICENSE + $offset + $index + 1 ),
					);
					break;

				case 'renewals':
					$data = array(
						array( 'name' => __( 'License reference', 'wp-license' ), 'value' => (string) $row['key_prefix'] ),
						array( 'name' => __( 'Period start', 'wp-license' ), 'value' => (string) $row['period_start'] ),
						array( 'name' => __( 'Period end', 'wp-license' ), 'value' => (string) $row['period_end'] ),
						array( 'name' => __( 'Status', 'wp-license' ), 'value' => (string) $row['status'] ),
						array( 'name' => __( 'Payment attempts', 'wp-license' ), 'value' => (string) (int) $row['attempt_count'] ),
						array( 'name' => __( 'Created', 'wp-license' ), 'value' => (string) $row['created_at'] ),
					);
					$order = ! empty( $row['renewal_order_id'] ) && function_exists( 'wc_get_order' ) ? wc_get_order( (int) $row['renewal_order_id'] ) : false;
					if ( $order instanceof \WC_Order ) {
						$data[] = array( 'name' => __( 'WooCommerce order', 'wp-license' ), 'value' => (string) $order->get_order_number() );
						$data[] = array( 'name' => __( 'Amount', 'wp-license' ), 'value' => (string) $order->get_total() . ' ' . (string) $order->get_currency() );
					}
					$items[] = array(
						'group_id' => 'wp-license-captain-renewals',
						'group_label' => __( 'WP License Captain renewals', 'wp-license' ),
						'item_id' => 'renewal-' . (int) $row['id'],
						'data' => $data,
					);
					break;

				case 'history':
					$items[] = array(
						'group_id' => 'wp-license-captain-history',
						'group_label' => __( 'WP License Captain license history', 'wp-license' ),
						'item_id' => 'history-' . (int) $row['id'],
						'data' => array(
							array( 'name' => __( 'Date (UTC)', 'wp-license' ), 'value' => (string) $row['created_at'] ),
							array( 'name' => __( 'Event', 'wp-license' ), 'value' => (string) $row['action'] ),
							array( 'name' => __( 'Concerns', 'wp-license' ), 'value' => (string) $row['object_type'] ),
							array( 'name' => __( 'Note', 'wp-license' ), 'value' => (string) $row['reason'] ),
						),
					);
					break;

				case 'consents':
					$items[] = array(
						'group_id' => 'wp-license-captain-consents',
						'group_label' => __( 'WP License Captain consents to automatic renewal', 'wp-license' ),
						'item_id' => 'consent-' . (int) $row['id'],
						'data' => array(
							array( 'name' => __( 'License reference', 'wp-license' ), 'value' => (string) $row['key_prefix'] ),
							array( 'name' => __( 'Order', 'wp-license' ), 'value' => (int) $row['order_id'] > 0 ? (string) (int) $row['order_id'] : '' ),
							array( 'name' => __( 'Consent given at (UTC)', 'wp-license' ), 'value' => (string) $row['consent_at'] ),
							array( 'name' => __( 'Given through', 'wp-license' ), 'value' => (string) $row['source'] ),
							array( 'name' => __( 'Language', 'wp-license' ), 'value' => (string) $row['locale'] ),
							array( 'name' => __( 'Text you agreed to', 'wp-license' ), 'value' => (string) $row['consent_text'] ),
							array(
								'name'  => __( 'Renewal amount', 'wp-license' ),
								'value' => \WPLC\Billing\Money::to_decimal( (int) $row['amount_minor'], max( 0, min( 6, (int) $row['currency_decimals'] ) ) ) . ' ' . (string) $row['currency'],
							),
							array( 'name' => __( 'Billing interval', 'wp-license' ), 'value' => (string) $row['billing_interval'] ),
						),
					);
					break;

				case 'emails':
					$items[] = array(
						'group_id' => 'wp-license-captain-emails',
						'group_label' => __( 'WP License Captain email log', 'wp-license' ),
						'item_id' => 'email-' . (int) $row['id'],
						'data' => array(
							array( 'name' => __( 'License reference', 'wp-license' ), 'value' => (string) $row['key_prefix'] ),
							array( 'name' => __( 'Email type', 'wp-license' ), 'value' => (string) $row['reminder_type'] ),
							array( 'name' => __( 'For the period ending', 'wp-license' ), 'value' => (string) $row['period_end'] ),
							array( 'name' => __( 'Sent at (UTC)', 'wp-license' ), 'value' => (string) $row['sent_at'] ),
						),
					);
					break;

				case 'downloads':
				case 'streams':
					$data = array(
						array( 'name' => __( 'License reference', 'wp-license' ), 'value' => (string) $row['key_prefix'] ),
						array( 'name' => __( 'Download link created at (UTC)', 'wp-license' ), 'value' => (string) $row['created_at'] ),
					);
					if ( ! empty( $row['consumed_at'] ) ) {
						$data[] = array( 'name' => __( 'Downloaded at (UTC)', 'wp-license' ), 'value' => (string) $row['consumed_at'] );
					}
					if ( 'streams' === $phase ) {
						$data[] = array( 'name' => __( 'Requested through', 'wp-license' ), 'value' => (string) $row['source'] );
						if ( ! empty( $row['version'] ) ) {
							$data[] = array( 'name' => __( 'Package', 'wp-license' ), 'value' => (string) $row['product_key'] . ' ' . (string) $row['version'] );
						}
					}
					$items[] = array(
						'group_id' => 'wp-license-captain-downloads',
						'group_label' => __( 'WP License Captain downloads', 'wp-license' ),
						'item_id' => ( 'streams' === $phase ? 'package-' : 'download-' ) . (int) $row['id'],
						'data' => $data,
					);
					break;
			}
		}

		return $items;
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
	 * Error returned when the export could not read the database.
	 *
	 * @return \WP_Error
	 */
	private static function database_error(): \WP_Error {
		return new \WP_Error( 'wplc_privacy_database_error', __( 'The license data could not be read. Please try again.', 'wp-license' ) );
	}

	/**
	 * Erase what is no longer needed and stop automatic renewal.
	 *
	 * - Ended licenses (expired, revoked, cancelled) are detached from the
	 *   account. Their installations (also still active ones), email log rows
	 *   and download records are deleted.
	 * - Licenses in force are kept, otherwise a paid service would stop and the
	 *   customer's sites would break. Their automatic renewal is switched off
	 *   (payment token reference cleared, open renewals cancelled) and their
	 *   deactivated installations are deleted.
	 * - The user ID is removed from audit entries the person triggered.
	 *
	 * The work does not depend on the page number: every call handles the next
	 * batch of records that still match, so a repeated or skipped page cannot
	 * skip records. A record that cannot be changed is reported as retained and
	 * does not keep the request running forever.
	 *
	 * @param mixed $email_address User email.
	 * @param mixed $page          Page number (WordPress calls 1, 2, ... until "done"); not used, see above.
	 * @return array<string,mixed>
	 */
	public static function erase_personal_data( $email_address, $page = 1 ): array {
		global $wpdb;

		$result = array(
			'items_removed' => false,
			'items_retained' => false,
			'messages' => array(),
			'done' => true,
		);
		$user   = is_string( $email_address ) && '' !== $email_address ? get_user_by( 'email', $email_address ) : false;
		if ( ! $user ) {
			return $result;
		}

		$user_id = (int) $user->ID;
		$tables  = Schema::table_names();
		$limit   = self::ERASE_LICENSES_PER_PAGE;
		$actor   = get_current_user_id();
		$removed = 0;
		$failed  = 0;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- only table names are interpolated.

		// 1. Ended licenses: detach and delete what belongs to them.
		$ended = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$tables['licenses']} WHERE customer_id = %d AND status IN (%s,%s,%s) ORDER BY id ASC LIMIT %d",
				$user_id,
				self::TERMINAL_STATES[0],
				self::TERMINAL_STATES[1],
				self::TERMINAL_STATES[2],
				$limit
			)
		);
		foreach ( (array) $ended as $license_id ) {
			if ( self::erase_ended_license( (int) $license_id, $user_id, $actor ) ) {
				++$removed;
			} else {
				++$failed;
			}
		}
		$more_ended = count( (array) $ended ) >= $limit;

		// 2. Licenses in force: stop automatic renewal, delete deactivated installations.
		$renewals_off = 0;
		$in_force     = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$tables['licenses']} WHERE customer_id = %d AND status NOT IN (%s,%s,%s) AND ( renewal_enabled = 1 OR payment_token_id > 0 ) ORDER BY id ASC LIMIT %d",
				$user_id,
				self::TERMINAL_STATES[0],
				self::TERMINAL_STATES[1],
				self::TERMINAL_STATES[2],
				$limit
			)
		);
		foreach ( (array) $in_force as $license_id ) {
			if ( RenewalSwitch::off( (int) $license_id, 'Privacy erasure request: automatic renewal switched off.', $actor ) ) {
				++$renewals_off;
			} else {
				++$failed;
			}
		}
		$more_in_force = count( (array) $in_force ) >= $limit;

		$installations = $wpdb->query(
			$wpdb->prepare(
				"DELETE a FROM {$tables['activations']} a INNER JOIN {$tables['licenses']} l ON l.id = a.license_id LEFT JOIN {$tables['activations']} c ON c.staging_parent_id = a.id AND c.status = %s WHERE l.customer_id = %d AND a.status <> %s AND c.id IS NULL",
				'active',
				$user_id,
				'active'
			)
		);
		if ( false === $installations ) {
			++$failed;
		}

		// 3. The person as actor in the audit history.
		$actor_rows = $wpdb->query( $wpdb->prepare( "UPDATE {$tables['audit_log']} SET actor_user_id = 0 WHERE actor_user_id = %d LIMIT 5000", $user_id ) );
		if ( false === $actor_rows ) {
			++$failed;
		}
		$more_actor_rows = (int) $actor_rows >= 5000;

		$retained = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$tables['licenses']} WHERE customer_id = %d AND status NOT IN (%s,%s,%s)",
				$user_id,
				self::TERMINAL_STATES[0],
				self::TERMINAL_STATES[1],
				self::TERMINAL_STATES[2]
			)
		);
		// phpcs:enable

		$progress = $removed + $renewals_off + (int) $installations + (int) $actor_rows;
		// Another call is only useful while this one made progress; a permanent error must not loop forever.
		$result['done']           = ! ( ( $more_ended || $more_in_force || $more_actor_rows ) && $progress > 0 );
		$result['items_removed']  = $removed > 0 || (int) $installations > 0 || (int) $actor_rows > 0;
		$result['items_retained'] = $retained > 0 || $failed > 0;

		if ( $removed > 0 ) {
			$result['messages'][] = sprintf(
				/* translators: %d: number of licenses. */
				_n(
					'%d ended license was detached from the account; its installations, email log and download records were deleted. WooCommerce order records were preserved.',
					'%d ended licenses were detached from the account; their installations, email log and download records were deleted. WooCommerce order records were preserved.',
					$removed,
					'wp-license'
				),
				$removed
			);
		}
		if ( $renewals_off > 0 ) {
			$result['messages'][] = sprintf(
				/* translators: %d: number of licenses. */
				_n(
					'Automatic renewal was switched off for %d license; the stored payment method reference was removed and open renewals were cancelled.',
					'Automatic renewal was switched off for %d licenses; the stored payment method references were removed and open renewals were cancelled.',
					$renewals_off,
					'wp-license'
				),
				$renewals_off
			);
		}
		if ( $retained > 0 && $result['done'] ) {
			$result['messages'][] = sprintf(
				/* translators: %d: number of licenses. */
				_n(
					'%d license that is still in force was retained to preserve the paid service, together with its active installations. It ends with its paid period and can be erased afterwards.',
					'%d licenses that are still in force were retained to preserve the paid service, together with their active installations. They end with their paid period and can be erased afterwards.',
					$retained,
					'wp-license'
				),
				$retained
			);
		}
		if ( $failed > 0 ) {
			$result['messages'][] = __( 'Some license records could not be erased because of a database error. They were retained; run the erasure request again.', 'wp-license' );
		}

		return $result;
	}

	/**
	 * Detach one ended license from its owner and delete its personal records.
	 *
	 * @param int $license_id License ID.
	 * @param int $user_id    Owner.
	 * @param int $actor      Acting user.
	 * @return bool False when the license could not be processed completely.
	 */
	private static function erase_ended_license( int $license_id, int $user_id, int $actor ): bool {
		global $wpdb;

		$tables = Schema::table_names();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- only table names are interpolated.

		// Still active installations of an ended license are released first (audit entry, staging children).
		$active = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$tables['activations']} WHERE license_id = %d AND status = %s ORDER BY staging_parent_id IS NOT NULL, id ASC LIMIT 500", $license_id, 'active' ) );
		foreach ( (array) $active as $activation_id ) {
			ActivationManager::deactivate( (int) $activation_id, 'Privacy erasure request', $actor, 'site_deactivated_license_ended' );
		}

		// Compare-and-set first: a license that was reactivated in the meantime keeps everything.
		$detached = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$tables['licenses']} SET customer_id = 0, payment_token_id = 0, renewal_enabled = 0, next_payment_at = NULL, updated_at = %s WHERE id = %d AND customer_id = %d AND status IN (%s,%s,%s)",
				gmdate( 'Y-m-d H:i:s' ),
				$license_id,
				$user_id,
				self::TERMINAL_STATES[0],
				self::TERMINAL_STATES[1],
				self::TERMINAL_STATES[2]
			)
		);
		if ( ! $detached ) {
			return false;
		}

		$complete = true;
		$queries  = array(
			$wpdb->prepare( "DELETE n FROM {$tables['request_nonces']} n INNER JOIN {$tables['activations']} a ON a.id = n.activation_id WHERE a.license_id = %d", $license_id ),
			$wpdb->prepare( "DELETE FROM {$tables['activations']} WHERE license_id = %d", $license_id ),
			$wpdb->prepare( "DELETE FROM {$tables['reminder_log']} WHERE license_id = %d", $license_id ),
			// The consent evidence stays as proof for the charges that were made, without the person.
			$wpdb->prepare( "UPDATE {$tables['consents']} SET customer_id = 0 WHERE license_id = %d", $license_id ),
			$wpdb->prepare( "DELETE FROM {$tables['download_tokens']} WHERE license_id = %d", $license_id ),
			$wpdb->prepare( "DELETE FROM {$tables['package_streams']} WHERE license_id = %d", $license_id ),
		);
		// phpcs:enable
		foreach ( $queries as $query ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
			if ( false === $wpdb->query( $query ) ) {
				// What is left is removed by the retention job (installations of ended licenses).
				$complete = false;
			}
		}

		AuditLog::record( 'license_erased', 'license', $license_id, 'Privacy erasure request: license detached from the account, installations, email log and download records deleted, consent evidence detached from the account.', null, null, $actor );

		return $complete;
	}
}
