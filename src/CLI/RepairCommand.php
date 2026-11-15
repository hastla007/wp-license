<?php
/**
 * Repeat a stuck background step by hand.
 *
 * @package WPLC
 */

namespace WPLC\CLI;

use WPLC\Billing\RenewalProcessor;
use WPLC\Infrastructure\Database\Schema;
use WPLC\License\LicenseIssuer;
use WPLC\License\LicenseLifecycle;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `wp wplc repair`: thin wrappers around the steps the scheduler normally runs (A-74).
 *
 * Every subcommand calls the same public method the scheduled action calls,
 * so all guards (locks, status checks, idempotency keys) apply unchanged.
 */
final class RepairCommand {

	/**
	 * Issue the licenses of a paid order that has none yet.
	 *
	 * ## OPTIONS
	 *
	 * <order-id>
	 * : WooCommerce order ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wplc repair issue-order 1234
	 *
	 * @subcommand issue-order
	 *
	 * @param array<int,string> $args Positional arguments.
	 * @return void
	 */
	public function issue_order( array $args ): void {
		$order_id = absint( $args[0] ?? 0 );
		if ( $order_id < 1 || ! wc_get_order( $order_id ) ) {
			\WP_CLI::error( __( 'Order not found.', 'wp-license' ) );
		}
		$before = count( LicenseLifecycle::license_ids_for_order( $order_id ) );
		LicenseIssuer::maybe_issue_order( $order_id );
		$after = count( LicenseLifecycle::license_ids_for_order( $order_id ) );
		if ( $after === $before ) {
			\WP_CLI::warning(
				sprintf(
					/* translators: %s: number of licenses the order has. */
					__( 'No license was issued; the order has %s. Check the order status and the plan mapping of its products.', 'wp-license' ),
					number_format_i18n( $after )
				)
			);
			return;
		}
		\WP_CLI::success(
			sprintf(
				/* translators: 1: number of issued licenses, 2: number of licenses the order has now. */
				_n( 'Issued %1$s license; the order now has %2$s.', 'Issued %1$s licenses; the order now has %2$s.', $after - $before, 'wp-license' ),
				number_format_i18n( $after - $before ),
				number_format_i18n( $after )
			)
		);
	}

	/**
	 * Run one renewal now.
	 *
	 * This is the step the scheduler runs when a renewal is due: for an
	 * automatic renewal it can charge the customer's stored payment method.
	 *
	 * ## OPTIONS
	 *
	 * <renewal-id>
	 * : ID of the renewal row (see `wp wplc license show`).
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wplc repair renewal 77
	 *
	 * @param array<int,string>   $args       Positional arguments.
	 * @param array<string,mixed> $assoc_args Options.
	 * @return void
	 */
	public function renewal( array $args, array $assoc_args ): void {
		$renewal_id = absint( $args[0] ?? 0 );
		$before = self::renewal_row( $renewal_id );
		if ( null === $before ) {
			\WP_CLI::error( __( 'Renewal not found.', 'wp-license' ) );
		}
		\WP_CLI::confirm(
			sprintf(
				/* translators: 1: renewal ID, 2: license ID, 3: renewal status, 4: attempt number. */
				__( 'Renewal %1$d of license %2$d is "%3$s" (attempt %4$d). Processing it can charge the customer. Continue?', 'wp-license' ),
				$renewal_id,
				(int) $before['license_id'],
				(string) $before['status'],
				(int) $before['attempt_count']
			),
			$assoc_args
		);

		RenewalProcessor::process( $renewal_id );

		$after = self::renewal_row( $renewal_id );
		\WP_CLI::success(
			sprintf(
				/* translators: 1: renewal ID, 2: status before, 3: status after. */
				__( 'Renewal %1$d: %2$s -> %3$s.', 'wp-license' ),
				$renewal_id,
				(string) $before['status'],
				null === $after ? __( 'deleted', 'wp-license' ) : (string) $after['status']
			)
		);
	}

	/**
	 * Process a stored Stripe event again.
	 *
	 * Only events that still wait for reconciliation are processed; an event
	 * that was already applied is left alone.
	 *
	 * ## OPTIONS
	 *
	 * <inbox-id>
	 * : ID of the row in the event inbox.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wplc repair webhook 12
	 *
	 * @param array<int,string> $args Positional arguments.
	 * @return void
	 */
	public function webhook( array $args ): void {
		global $wpdb;

		$inbox_id = absint( $args[0] ?? 0 );
		$tables = Schema::table_names();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names().
		$before = $wpdb->get_var( $wpdb->prepare( "SELECT outcome FROM {$tables['event_inbox']} WHERE id = %d LIMIT 1", $inbox_id ) );
		if ( null === $before ) {
			\WP_CLI::error( __( 'Event not found.', 'wp-license' ) );
		}

		RenewalProcessor::process_stripe_event( $inbox_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names().
		$after = $wpdb->get_var( $wpdb->prepare( "SELECT outcome FROM {$tables['event_inbox']} WHERE id = %d LIMIT 1", $inbox_id ) );
		\WP_CLI::success(
			sprintf(
				/* translators: 1: event inbox ID, 2: outcome before, 3: outcome after. */
				__( 'Event %1$d: %2$s -> %3$s.', 'wp-license' ),
				$inbox_id,
				(string) $before,
				(string) $after
			)
		);
	}

	/**
	 * Read one renewal row.
	 *
	 * @param int $renewal_id Renewal ID.
	 * @return array<string,mixed>|null
	 */
	private static function renewal_row( int $renewal_id ): ?array {
		global $wpdb;

		$tables = Schema::table_names();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema::table_names().
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id,license_id,status,attempt_count FROM {$tables['renewals']} WHERE id = %d LIMIT 1", $renewal_id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}
}
