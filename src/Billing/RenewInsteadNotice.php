<?php
/**
 * Cart and checkout notice "Renew instead" for customers who already own a license (A-22).
 *
 * @package WPLC
 */

namespace WPLC\Billing;

use WPLC\Account\CustomerPortal;
use WPLC\Admin\Format;
use WPLC\Catalog\Catalog;
use WPLC\Infrastructure\Database\Schema;
use WPLC\License\LicenseLifecycle;
use WPLC\Notifications\ReminderSchedule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A logged-in customer who puts a license product into the cart while owning
 * an active or recently expired license of the same product would get a
 * second license with a new key. The cart and the checkout (classic and
 * block) show a notice with a link to the manual renewal of the existing
 * license instead; the customer can dismiss it per license. Nothing is
 * changed automatically: the purchase stays possible.
 */
final class RenewInsteadNotice {

	/**
	 * User meta with the dismissed licenses (license ID => dismissal time).
	 */
	public const DISMISSED_META = 'wplc_renew_notice_dismissed';

	/**
	 * Query argument of the dismiss link.
	 */
	public const DISMISS_ARG = 'wplc_dismiss_renew_notice';

	/**
	 * Days a dismissal lasts; the notice comes back for a later purchase.
	 */
	public const DISMISS_DAYS = 30;

	/**
	 * Whether the notice was printed in this request (classic and block hooks may both run).
	 *
	 * @var bool
	 */
	private static $printed = false;

	/**
	 * Register display and dismissal hooks.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'woocommerce_before_cart', array( self::class, 'print_notices' ), 5 );
		add_action( 'woocommerce_before_checkout_form', array( self::class, 'print_notices' ), 5 );
		add_filter( 'render_block', array( self::class, 'prepend_to_block' ), 10, 2 );
		add_action( 'wp_loaded', array( self::class, 'handle_dismiss' ), 20 );
	}

	/**
	 * Notices for the current cart, one per product the customer already licenses.
	 *
	 * @return array<int,array{license_id:int,product_key:string,message:string,renew_url:string,account_url:string,dismiss_url:string}>
	 */
	public static function notices_for_cart(): array {
		$customer_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( $customer_id <= 0 || ! function_exists( 'WC' ) || null === WC()->cart ) {
			return array();
		}
		$product_keys = array();
		foreach ( WC()->cart->get_cart() as $item ) {
			$mapping = Catalog::find_mapping( (int) ( $item['product_id'] ?? 0 ), (int) ( $item['variation_id'] ?? 0 ) );
			if ( $mapping && ! empty( $mapping['product_key'] ) ) {
				$product_keys[ (string) $mapping['product_key'] ] = true;
			}
		}

		return self::notices_for( $customer_id, array_keys( $product_keys ), time() );
	}

	/**
	 * Notices for a customer and the license products in the cart.
	 *
	 * @param int      $customer_id  Customer.
	 * @param string[] $product_keys License product keys in the cart.
	 * @param int      $now          Unix timestamp.
	 * @return array<int,array{license_id:int,product_key:string,message:string,renew_url:string,account_url:string,dismiss_url:string}>
	 */
	public static function notices_for( int $customer_id, array $product_keys, int $now ): array {
		global $wpdb;

		if ( $customer_id <= 0 || ! $product_keys ) {
			return array();
		}
		$tables       = Schema::table_names();
		$placeholders = implode( ',', array_fill( 0, count( $product_keys ), '%s' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Placeholders are generated above; the values are passed as one array.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE customer_id = %d AND status IN ( 'active', 'past_due', 'expired' ) AND product_key IN ({$placeholders}) ORDER BY paid_until DESC, id DESC LIMIT 100",
				array_merge( array( $tables['licenses'], $customer_id ), array_values( $product_keys ) )
			),
			ARRAY_A
		);
		// phpcs:enable
		$dismissed = self::dismissed( $customer_id, $now );
		$best      = array();
		foreach ( is_array( $rows ) ? $rows : array() as $license ) {
			if ( ! self::is_relevant( $license, $now ) ) {
				continue;
			}
			$key = (string) $license['product_key'];
			// Prefer a license that can be renewed now; otherwise the one that runs longest (rows are sorted).
			if ( ! isset( $best[ $key ] ) || ( ! $best[ $key ]['renewable'] && self::renewable( $license ) ) ) {
				$best[ $key ] = array(
					'license'   => $license,
					'renewable' => self::renewable( $license ),
				);
			}
		}

		$notices = array();
		foreach ( $best as $product_key => $choice ) {
			$license    = $choice['license'];
			$license_id = (int) $license['id'];
			if ( isset( $dismissed[ $license_id ] ) ) {
				continue;
			}
			$notice = array(
				'license_id'  => $license_id,
				'product_key' => (string) $product_key,
				'message'     => self::message( $license, $choice['renewable'] ),
				'renew_url'   => $choice['renewable'] ? self::renew_url( $license_id ) : '',
				'account_url' => CustomerPortal::account_url(),
				'dismiss_url' => self::dismiss_url( $license_id ),
			);

			/**
			 * Filter the "Renew instead" notice for one license in the cart (A-22).
			 *
			 * Return false to hide it. The keys message, renew_url (empty when the
			 * license cannot be renewed now), account_url and dismiss_url may be changed.
			 *
			 * @param array<string,mixed>|false $notice  Notice.
			 * @param array<string,mixed>       $license License row.
			 */
			$filtered = apply_filters( 'wplc_renew_instead_notice', $notice, $license );
			if ( is_array( $filtered ) && '' !== (string) ( $filtered['message'] ?? '' ) ) {
				$notices[] = array_merge( $notice, $filtered );
			}
		}

		return $notices;
	}

	/**
	 * Whether a license is "active or recently expired". Pure.
	 *
	 * Active means it grants access now; recently expired means expired but
	 * still inside the manual renewal window.
	 *
	 * @param array<string,mixed> $license License row.
	 * @param int                 $now     Unix timestamp.
	 * @return bool
	 */
	public static function is_relevant( array $license, int $now ): bool {
		if ( LicenseLifecycle::has_access( $license, $now ) ) {
			return true;
		}

		return 'expired' === (string) ( $license['status'] ?? '' ) && ManualRenewal::in_window( $license, $now );
	}

	/**
	 * Whether the account offers "Renew now" for the license (same rule as the account page).
	 *
	 * @param array<string,mixed> $license License row.
	 * @return bool
	 */
	private static function renewable( array $license ): bool {
		if ( 'active' === (string) $license['status'] && ReminderSchedule::renews_automatically( $license ) ) {
			return false;
		}

		return ManualRenewal::can_renew( $license );
	}

	/**
	 * Text of the notice.
	 *
	 * @param array<string,mixed> $license   License row.
	 * @param bool                $renewable Whether a renewal link is offered.
	 * @return string Plain text.
	 */
	private static function message( array $license, bool $renewable ): string {
		$reference = (string) ( $license['key_prefix'] ?? '' );
		$until     = Format::date( $license['paid_until'] ?? '', false, __( 'No expiry', 'wp-license' ) );
		if ( 'expired' === (string) $license['status'] ) {
			/* translators: 1: license reference such as WPLC-1A2B3C4D, 2: date. */
			$first = sprintf( __( 'Your license %1$s for this product expired on %2$s.', 'wp-license' ), $reference, $until );
		} else {
			/* translators: 1: license reference such as WPLC-1A2B3C4D, 2: date. */
			$first = sprintf( __( 'You already have the license %1$s for this product, valid through %2$s.', 'wp-license' ), $reference, $until );
		}
		$second = __( 'Buying it again adds a second license with a new key.', 'wp-license' );
		if ( $renewable ) {
			return $first . ' ' . $second . ' ' . __( 'To keep your key and your sites, renew the existing license instead.', 'wp-license' );
		}
		if ( ReminderSchedule::renews_automatically( $license ) ) {
			return $first . ' ' . $second . ' ' . __( 'The existing license renews automatically.', 'wp-license' );
		}

		return $first . ' ' . $second;
	}

	/**
	 * Nonce-protected link that starts the manual renewal of a license.
	 *
	 * @param int $license_id License ID.
	 * @return string
	 */
	public static function renew_url( int $license_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'     => 'wplc_start_renewal',
					'license_id' => $license_id,
				),
				admin_url( 'admin-post.php' )
			),
			'wplc_start_renewal_' . $license_id
		);
	}

	/**
	 * Nonce-protected link that hides the notice of a license.
	 *
	 * @param int $license_id License ID.
	 * @return string
	 */
	public static function dismiss_url( int $license_id ): string {
		return wp_nonce_url( add_query_arg( self::DISMISS_ARG, $license_id ), self::DISMISS_ARG . '_' . $license_id );
	}

	/**
	 * Licenses whose notice the customer dismissed recently.
	 *
	 * @param int $customer_id Customer.
	 * @param int $now         Unix timestamp.
	 * @return array<int,int> License ID => dismissal time.
	 */
	public static function dismissed( int $customer_id, int $now ): array {
		$stored = get_user_meta( $customer_id, self::DISMISSED_META, true );
		$kept   = array();
		foreach ( is_array( $stored ) ? $stored : array() as $license_id => $time ) {
			if ( (int) $time > $now - self::DISMISS_DAYS * 86400 ) {
				$kept[ (int) $license_id ] = (int) $time;
			}
		}

		return $kept;
	}

	/**
	 * Hide the notice of one license for the current customer.
	 *
	 * @param int $customer_id Customer.
	 * @param int $license_id  License ID.
	 * @return void
	 */
	public static function dismiss( int $customer_id, int $license_id ): void {
		$now  = time();
		$kept = self::dismissed( $customer_id, $now );
		$kept[ $license_id ] = $now;
		update_user_meta( $customer_id, self::DISMISSED_META, array_slice( $kept, -50, null, true ) );
	}

	/**
	 * Handle a click on "Dismiss" and return to the page without the arguments.
	 *
	 * @return void
	 */
	public static function handle_dismiss(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The nonce is verified below.
		if ( ! isset( $_GET[ self::DISMISS_ARG ] ) || ! is_user_logged_in() ) {
			return;
		}
		$license_id = absint( wp_unslash( $_GET[ self::DISMISS_ARG ] ) );
		$nonce      = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		// phpcs:enable
		if ( $license_id > 0 && wp_verify_nonce( $nonce, self::DISMISS_ARG . '_' . $license_id ) && CustomerPortal::current_user_owns( $license_id ) ) {
			self::dismiss( get_current_user_id(), $license_id );
		}
		wp_safe_redirect( remove_query_arg( array( self::DISMISS_ARG, '_wpnonce' ) ) );
		exit;
	}

	/**
	 * HTML of the notices for the current cart; empty when there is none.
	 *
	 * @return string
	 */
	public static function notices_html(): string {
		$html = '';
		foreach ( self::notices_for_cart() as $notice ) {
			$links = array();
			if ( '' !== (string) $notice['renew_url'] ) {
				$links[] = '<a class="button wplc-renew-instead" href="' . esc_url( (string) $notice['renew_url'] ) . '">' . esc_html__( 'Renew instead', 'wp-license' ) . '</a>';
			} elseif ( '' !== (string) $notice['account_url'] ) {
				$links[] = '<a href="' . esc_url( (string) $notice['account_url'] ) . '">' . esc_html__( 'View your licenses', 'wp-license' ) . '</a>';
			}
			$links[] = '<a class="wplc-renew-notice-dismiss" href="' . esc_url( (string) $notice['dismiss_url'] ) . '">' . esc_html__( 'Dismiss', 'wp-license' ) . '</a>';
			$html   .= '<div class="woocommerce-info wplc-renew-notice" role="status" data-wplc-license="' . esc_attr( (string) $notice['license_id'] ) . '">'
				. esc_html( (string) $notice['message'] ) . ' ' . implode( ' ', $links ) . '</div>';
		}

		return $html;
	}

	/**
	 * Print the notices above the classic cart and checkout.
	 *
	 * @return void
	 */
	public static function print_notices(): void {
		if ( self::$printed ) {
			return;
		}
		$html = self::notices_html();
		if ( '' === $html ) {
			return;
		}
		self::$printed = true;
		echo wp_kses_post( $html );
	}

	/**
	 * Put the notices above the block cart and checkout.
	 *
	 * @param mixed $content Block HTML.
	 * @param mixed $block   Parsed block.
	 * @return mixed
	 */
	public static function prepend_to_block( $content, $block ) {
		if ( self::$printed || ! is_string( $content ) || ! is_array( $block ) || ! in_array( (string) ( $block['blockName'] ?? '' ), array( 'woocommerce/cart', 'woocommerce/checkout' ), true ) ) {
			return $content;
		}
		$html = self::notices_html();
		if ( '' === $html ) {
			return $content;
		}
		self::$printed = true;

		return wp_kses_post( $html ) . $content;
	}

	/**
	 * Allow the next rendering to print again (tests).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$printed = false;
	}
}
