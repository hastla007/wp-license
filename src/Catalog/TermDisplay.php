<?php
/**
 * Shows the license term and the renewal price before the purchase.
 *
 * @package WPLC
 */

namespace WPLC\Catalog;

use WPLC\Billing\Money;
use WPLC\Gateway\Registry;
use WPLC\Infrastructure\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A license is sold for a term (one month or one year). Without this class the
 * price looks like a one-off purchase on the product page, in the cart and in
 * both checkouts. The lines added here are plain text, because the Store API
 * (block cart and checkout) passes item data on as text.
 */
final class TermDisplay {

	/**
	 * Register display hooks. Called from ProductEditor::register_hooks().
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_filter( 'woocommerce_get_price_html', array( self::class, 'price_html' ), 20, 2 );
		add_filter( 'woocommerce_get_item_data', array( self::class, 'cart_item_data' ), 10, 2 );
	}

	/**
	 * Term of a billing interval in words.
	 *
	 * @param string $interval `month` or `year`.
	 * @return string
	 */
	public static function term_label( string $interval ): string {
		return 'month' === $interval ? __( '1 month', 'wp-license' ) : __( '1 year', 'wp-license' );
	}

	/**
	 * Append the term to the price of a license product ("49,00 € for 1 year").
	 *
	 * @param mixed $html Price HTML.
	 * @param mixed $product WC_Product.
	 * @return mixed
	 */
	public static function price_html( $html, $product ) {
		if ( ! is_string( $html ) || '' === $html || ! is_object( $product ) || ! is_callable( array( $product, 'get_id' ) ) ) {
			return $html;
		}
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $html;
		}
		// The autoloaded ID list keeps shop loops free of extra queries for ordinary products.
		if ( ! in_array( (int) $product->get_id(), Catalog::license_product_ids(), true ) ) {
			return $html;
		}

		$mapping = Catalog::mapping_for_product( $product );
		/**
		 * Filters whether the license term is appended to the price of a license product.
		 *
		 * @since 0.2.0
		 *
		 * @param bool                     $show    Default true.
		 * @param \WC_Product              $product Product.
		 * @param array<string,mixed>|null $mapping Plan of the product.
		 */
		if ( ! $mapping || ! apply_filters( 'wplc_show_license_term', true, $product, $mapping ) ) {
			return $html;
		}

		$term = sprintf(
			/* translators: %s: license term, for example "1 year". */
			__( 'for %s', 'wp-license' ),
			self::term_label( (string) $mapping['billing_interval'] )
		);

		return $html . ' <span class="wplc-license-term">' . esc_html( $term ) . '</span>';
	}

	/**
	 * Add term, renewal price and a hint for existing licensees to a cart line.
	 * Shown in the classic cart and checkout and in the block order summary.
	 *
	 * @param mixed $item_data Existing item data.
	 * @param mixed $cart_item Cart item.
	 * @return mixed
	 */
	public static function cart_item_data( $item_data, $cart_item ) {
		if ( ! is_array( $item_data ) || ! is_array( $cart_item ) ) {
			return $item_data;
		}

		$mapping = Catalog::find_mapping( (int) ( $cart_item['product_id'] ?? 0 ), (int) ( $cart_item['variation_id'] ?? 0 ) );
		$product = $cart_item['data'] ?? null;
		if ( ! $mapping || ! is_object( $product ) ) {
			return $item_data;
		}

		$item_data[] = array(
			'key' => __( 'License term', 'wp-license' ),
			'value' => self::term_label( (string) $mapping['billing_interval'] ),
		);

		if ( Registry::any_supports_checkout() ) {
			$item_data[] = array(
				'key' => __( 'Automatic renewal (optional)', 'wp-license' ),
				'value' => self::renewal_price_text( $mapping, $product ),
			);
		}

		if ( self::customer_owns_license( (string) $mapping['product_key'] ) ) {
			$item_data[] = array(
				'key' => __( 'Please note', 'wp-license' ),
				'value' => __( 'You already have a license for this product. This purchase adds another license with a new key. To extend the existing license, use "Renew" in your account instead.', 'wp-license' ),
			);
		}

		return $item_data;
	}

	/**
	 * The renewal charge of a plan as the customer will see it, in plain text:
	 * the regular price with the shop's tax display, for example "59,00 € per year (incl. VAT)".
	 *
	 * @param array<string,mixed> $mapping Plan row from Catalog::find_mapping().
	 * @param mixed               $product WC_Product that is bought, for the tax class.
	 * @return string
	 */
	public static function renewal_price_text( array $mapping, $product ): string {
		$decimals = max( 0, min( 6, (int) ( $mapping['price_decimals'] ?? 2 ) ) );
		$amount   = (float) Money::to_decimal( max( 0, (int) ( $mapping['price_minor'] ?? 0 ) ), $decimals );
		$suffix   = '';

		if ( is_object( $product ) && function_exists( 'wc_tax_enabled' ) && wc_tax_enabled() ) {
			// The shop setting for cart and checkout; it also holds where no cart exists (emails, admin).
			$including = 'incl' === get_option( 'woocommerce_tax_display_cart' );
			$amount    = (float) ( $including
				? wc_get_price_including_tax( $product, array( 'price' => $amount ) )
				: wc_get_price_excluding_tax( $product, array( 'price' => $amount ) ) );
			$suffix = (string) ( $including ? WC()->countries->inc_tax_or_vat() : WC()->countries->ex_tax_or_vat() );
		}

		$price = html_entity_decode(
			wp_strip_all_tags( wc_price( $amount, array( 'currency' => (string) ( $mapping['currency'] ?? '' ) ) ) ),
			ENT_QUOTES,
			'UTF-8'
		);
		$text  = 'month' === (string) ( $mapping['billing_interval'] ?? 'year' )
			/* translators: %s: price, for example "9,90 €". */
			? sprintf( __( '%s per month', 'wp-license' ), $price )
			/* translators: %s: price, for example "59,00 €". */
			: sprintf( __( '%s per year', 'wp-license' ), $price );

		return '' === trim( $suffix ) ? $text : $text . ' ' . trim( $suffix );
	}

	/**
	 * Whether the logged-in customer already has a running license for a product.
	 *
	 * @param string $product_key License product key.
	 * @return bool
	 */
	private static function customer_owns_license( string $product_key ): bool {
		global $wpdb;

		$customer_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( $customer_id <= 0 || '' === $product_key ) {
			return false;
		}

		$tables = Schema::table_names();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name comes from Schema::table_names().
		$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['licenses']} WHERE customer_id = %d AND product_key = %s AND status IN ( 'active', 'past_due' )", $customer_id, $product_key ) );

		return (int) $count > 0;
	}
}
