<?php
/**
 * Terms of the automatic renewal (plain text): date, amount including tax, interval, how to cancel.
 *
 * Override: yourtheme/wp-license/emails/plain/wplc-renewal-terms.php
 *
 * @package WPLC
 *
 * @var array<string,string> $renewal_terms Label => plain-text value.
 * @var string               $cancel_text   How to cancel.
 * @var string               $product       Product name, may be empty.
 * @var string               $key_prefix    License reference, may be empty.
 */

defined( 'ABSPATH' ) || exit;

$renewal_terms = isset( $renewal_terms ) && is_array( $renewal_terms ) ? $renewal_terms : array();
if ( ! $renewal_terms ) {
	return;
}
$cancel_text = isset( $cancel_text ) ? (string) $cancel_text : '';
$product     = isset( $product ) ? (string) $product : '';
$key_prefix  = isset( $key_prefix ) ? (string) $key_prefix : '';
$title       = __( 'Automatic renewal', 'wp-license' );
if ( '' !== $product ) {
	$title .= ': ' . $product . ( '' !== $key_prefix ? ' (' . $key_prefix . ')' : '' );
}

// Plain text is not HTML: escaping would turn an apostrophe into an entity that the mailer then drops.
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
echo "\n" . wp_strip_all_tags( $title ) . "\n";
foreach ( $renewal_terms as $label => $value ) {
	echo wp_strip_all_tags( (string) $label . ': ' . (string) $value ) . "\n";
}
if ( '' !== $cancel_text ) {
	echo "\n" . wp_strip_all_tags( $cancel_text ) . "\n";
}
echo "\n";
// phpcs:enable
