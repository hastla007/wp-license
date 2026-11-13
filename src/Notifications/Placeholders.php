<?php
/**
 * Placeholder rendering for email subjects and headings.
 *
 * @package WPLC
 */

namespace WPLC\Notifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Replaces {placeholder} tokens with plain-text values.
 */
final class Placeholders {

	/**
	 * Placeholders offered by every license email.
	 *
	 * @return string[]
	 */
	public static function supported(): array {
		return array( 'product', 'plan', 'key_prefix', 'valid_until', 'days_left', 'amount', 'charge_date', 'grace_until' );
	}

	/**
	 * Build a WooCommerce style placeholder map ("{name}" => value).
	 *
	 * Unknown names are dropped, missing names become empty strings, and values
	 * are reduced to single-line plain text so they are safe in a mail subject.
	 *
	 * @param array<string,mixed> $values Raw values keyed by placeholder name.
	 * @return array<string,string>
	 */
	public static function map( array $values ): array {
		$map = array();
		foreach ( self::supported() as $name ) {
			$value = isset( $values[ $name ] ) && is_scalar( $values[ $name ] ) ? (string) $values[ $name ] : '';
			$map[ '{' . $name . '}' ] = self::plain( $value );
		}
		return $map;
	}

	/**
	 * Render a template string with the given values.
	 *
	 * Replacement is single-pass: a value that itself contains "{plan}" is not
	 * expanded again. Unknown tokens are left untouched.
	 *
	 * @param string              $template Text with {placeholders}.
	 * @param array<string,mixed> $values   Raw values keyed by placeholder name.
	 * @return string
	 */
	public static function render( string $template, array $values ): string {
		return strtr( $template, self::map( $values ) );
	}

	/**
	 * Reduce a value to single-line text without markup.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function plain( string $value ): string {
		$value = html_entity_decode( strip_tags( $value ), ENT_QUOTES, 'UTF-8' );
		$value = str_replace( "\xC2\xA0", ' ', $value );
		$value = (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $value );
		return trim( (string) preg_replace( '/ {2,}/', ' ', $value ) );
	}
}
