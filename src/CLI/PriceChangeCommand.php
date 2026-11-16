<?php
/**
 * Announce and withdraw a new renewal price from the command line (INH-05).
 *
 * @package WPLC
 */

namespace WPLC\CLI;

use WPLC\Billing\Money;
use WPLC\Billing\PriceChange;
use WPLC\Notifications\Notifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `wp wplc price-change`: move existing licenses to a higher renewal price with advance notice.
 */
final class PriceChangeCommand {

	/**
	 * Announce a higher renewal price for one license or for every license of a product and plan.
	 *
	 * The customer gets an email with the current price, the new price, the date and how to
	 * cancel. Until the date every renewal is charged at the current price; the new price is
	 * only charged when the email was sent at least the notice period before (setting "Price
	 * change notice period", default 60 days, at least 30). Without --apply nothing is written
	 * and no email is sent.
	 *
	 * ## OPTIONS
	 *
	 * --amount=<amount>
	 * : New renewal amount as a decimal number with a point, in the form of the product price in the shop.
	 *
	 * --effective=<date>
	 * : Day from which the new price applies (YYYY-MM-DD, UTC), at least the notice period ahead.
	 *
	 * --reason=<text>
	 * : Reason for the audit log.
	 *
	 * [--license=<id>]
	 * : One license.
	 *
	 * [--product=<product-key>]
	 * : Every active license of this product key. Needs --currency.
	 *
	 * [--plan=<plan-key>]
	 * : Only licenses of this plan key (with --product).
	 *
	 * [--currency=<code>]
	 * : Currency of the amount (with --product), for example EUR.
	 *
	 * [--apply]
	 * : Write the announcements and send the emails. Without this flag it is a dry run.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wplc price-change announce --product=my-plugin-pro --plan=single --currency=EUR --amount=59.00 --effective=2027-03-01 --reason="Price list 2027"
	 *     wp wplc price-change announce --license=42 --amount=59.00 --effective=2027-03-01 --reason="Price list 2027" --apply
	 *
	 * @param array<int,string>   $args       Unused.
	 * @param array<string,mixed> $assoc_args Options.
	 * @return void
	 */
	public function announce( array $args, array $assoc_args ): void {
		$apply     = ! empty( $assoc_args['apply'] );
		$reason    = trim( (string) ( $assoc_args['reason'] ?? '' ) );
		$date      = (string) ( $assoc_args['effective'] ?? '' );
		$effective = 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $date ) ? strtotime( $date . ' 00:00:00 UTC' ) : false;
		if ( '' === $reason || false === $effective ) {
			\WP_CLI::error( __( 'Give --reason and --effective=YYYY-MM-DD.', 'wp-license' ) );
			return;
		}
		$actor = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

		if ( ! empty( $assoc_args['license'] ) ) {
			$license = Notifications::get_license( (int) $assoc_args['license'] );
			if ( ! $license ) {
				\WP_CLI::error( __( 'License not found.', 'wp-license' ) );
				return;
			}
			$minor  = Money::to_minor_units( (string) ( $assoc_args['amount'] ?? '' ), (int) $license['renewal_currency_decimals'] );
			$result = is_int( $minor ) ? PriceChange::check( $license, $minor, (int) $effective, time(), PriceChange::notice_days() ) : PriceChange::RESULT_FAILED;
			if ( PriceChange::RESULT_OK === $result && $apply ) {
				$result = PriceChange::announce( (int) $license['id'], (int) $minor, (int) $effective, $reason, $actor );
			}
			if ( PriceChange::RESULT_OK !== $result ) {
				/* translators: %s: reason code. */
				\WP_CLI::error( sprintf( __( 'Not announced: %s', 'wp-license' ), $result ) );
				return;
			}
			\WP_CLI::success( $apply ? __( 'Announced; the notice email was handed to the mailer or will be retried by the daily job.', 'wp-license' ) : __( 'Dry run: the announcement is possible. Add --apply to write it and send the email.', 'wp-license' ) );
			return;
		}

		$product  = (string) ( $assoc_args['product'] ?? '' );
		$currency = strtoupper( (string) ( $assoc_args['currency'] ?? '' ) );
		$decimals = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;
		$minor    = Money::to_minor_units( (string) ( $assoc_args['amount'] ?? '' ), $decimals );
		if ( '' === $product || 1 !== preg_match( '/^[A-Z]{3}$/D', $currency ) || ! is_int( $minor ) || $minor <= 0 ) {
			\WP_CLI::error( __( 'Give --license=<id>, or --product=<product-key> with --currency and a valid --amount.', 'wp-license' ) );
			return;
		}
		$after     = 0;
		$announced = 0;
		$skipped   = array();
		do {
			$report     = PriceChange::announce_for_plan( $product, (string) ( $assoc_args['plan'] ?? '' ), $minor, $currency, (int) $effective, $reason, $actor, ! $apply, $after, 200 );
			$announced += $report['announced'];
			foreach ( $report['skipped'] as $code => $count ) {
				$skipped[ $code ] = ( $skipped[ $code ] ?? 0 ) + $count;
			}
			$after = $report['last_id'];
		} while ( $report['more'] );

		foreach ( $skipped as $code => $count ) {
			/* translators: 1: number of licenses, 2: reason code. */
			\WP_CLI::warning( sprintf( __( '%1$d licenses skipped: %2$s', 'wp-license' ), $count, $code ) );
		}
		\WP_CLI::success(
			$apply
				/* translators: %d: number of licenses. */
				? sprintf( __( 'Announced for %d licenses.', 'wp-license' ), $announced )
				/* translators: %d: number of licenses. */
				: sprintf( __( 'Dry run: %d licenses would get the announcement. Add --apply to write it and send the emails.', 'wp-license' ), $announced )
		);
	}

	/**
	 * Withdraw the announcement of one license before it takes effect.
	 *
	 * ## OPTIONS
	 *
	 * --license=<id>
	 * : License ID.
	 *
	 * --reason=<text>
	 * : Reason for the audit log.
	 *
	 * @param array<int,string>   $args       Unused.
	 * @param array<string,mixed> $assoc_args Options.
	 * @return void
	 */
	public function withdraw( array $args, array $assoc_args ): void {
		$result = PriceChange::withdraw( (int) ( $assoc_args['license'] ?? 0 ), (string) ( $assoc_args['reason'] ?? '' ), function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0 );
		if ( PriceChange::RESULT_OK !== $result ) {
			/* translators: %s: reason code. */
			\WP_CLI::error( sprintf( __( 'Not withdrawn: %s', 'wp-license' ), $result ) );
			return;
		}
		\WP_CLI::success( __( 'The announcement was withdrawn. The license keeps its current price.', 'wp-license' ) );
	}
}
