<?php
/**
 * Language a license was bought in.
 *
 * @package WPLC
 */

namespace WPLC\Notifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Remembers the locale of a purchase on the order and uses it for later emails.
 *
 * No extra column is needed: the license points to its source order, and the
 * order carries the locale as meta (written through the WooCommerce CRUD API).
 */
final class PurchaseLocale {

	/**
	 * Order meta key holding the WordPress locale of the checkout, e.g. "de_DE".
	 */
	public const META = '_wplc_locale';

	/**
	 * Register the checkout hooks.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		// Classic checkout: the order is saved right after this hook.
		add_action( 'woocommerce_checkout_create_order', array( self::class, 'remember' ), 20, 1 );
		// Block checkout (Store API).
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( self::class, 'remember_and_save' ), 20, 1 );
	}

	/**
	 * Write the current locale to an order that is about to be saved.
	 *
	 * @param mixed $order Order being created.
	 * @return void
	 */
	public static function remember( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$locale = self::sanitize( function_exists( 'determine_locale' ) ? (string) determine_locale() : (string) get_locale() );
		if ( '' !== $locale ) {
			$order->update_meta_data( self::META, $locale );
		}
	}

	/**
	 * Write the current locale to an existing draft order and save the meta.
	 *
	 * @param mixed $order Draft order of the block checkout.
	 * @return void
	 */
	public static function remember_and_save( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		self::remember( $order );
		$order->save_meta_data();
	}

	/**
	 * Locale the emails of a license are written in.
	 *
	 * Order of preference: the locale stored at checkout, the language a
	 * multilingual plugin stored on the order, the customer's profile language.
	 *
	 * @param array<string,mixed> $license License row.
	 * @return string WordPress locale, or an empty string to keep the site language.
	 */
	public static function for_license( array $license ): string {
		$locale = '';
		$order  = ! empty( $license['source_order_id'] ) && function_exists( 'wc_get_order' ) ? wc_get_order( (int) $license['source_order_id'] ) : false;
		if ( $order instanceof \WC_Order ) {
			$locale = self::sanitize( (string) $order->get_meta( self::META, true ) );
			if ( '' === $locale ) {
				// WPML and Polylang for WooCommerce store a language code or locale here.
				$locale = self::installed( self::sanitize( (string) $order->get_meta( 'wpml_language', true ) ) );
			}
		}
		if ( '' === $locale && ! empty( $license['customer_id'] ) && function_exists( 'get_user_locale' ) ) {
			$locale = self::sanitize( (string) get_user_locale( (int) $license['customer_id'] ) );
		}

		/**
		 * Filter the locale used for the emails of one license.
		 *
		 * @param string              $locale  WordPress locale, may be empty.
		 * @param array<string,mixed> $license License row.
		 */
		return self::sanitize( (string) apply_filters( 'wplc_license_email_locale', $locale, $license ) );
	}

	/**
	 * Reduce a value to a well-formed locale string.
	 *
	 * @param string $locale Raw value.
	 * @return string Empty when the value is not a locale.
	 */
	public static function sanitize( string $locale ): string {
		$locale = trim( $locale );
		return preg_match( '/^[a-z]{2,3}(_[A-Za-z0-9]{2,8}){0,2}$/', $locale ) ? $locale : '';
	}

	/**
	 * Map a language code such as "de" to an installed locale such as "de_DE".
	 *
	 * @param string $code Language code or locale.
	 * @return string Installed locale, or an empty string.
	 */
	private static function installed( string $code ): string {
		if ( '' === $code || ! function_exists( 'get_available_languages' ) ) {
			return '';
		}
		$available = array_merge( array( 'en_US' ), (array) get_available_languages() );
		if ( in_array( $code, $available, true ) ) {
			return $code;
		}
		foreach ( $available as $candidate ) {
			if ( 0 === strpos( (string) $candidate, $code . '_' ) ) {
				return (string) $candidate;
			}
		}
		return '';
	}
}
