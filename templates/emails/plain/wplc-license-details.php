<?php
/**
 * License details and call-to-action link (plain text), shared by all license emails.
 *
 * Override: yourtheme/wp-license/emails/plain/wplc-license-details.php
 *
 * @package WPLC
 *
 * @var array<string,string> $rows         Label => plain-text value.
 * @var string               $button_url   Target of the link, empty for none.
 * @var string               $button_label Link text.
 */

defined( 'ABSPATH' ) || exit;

$rows         = isset( $rows ) && is_array( $rows ) ? $rows : array();
$button_url   = isset( $button_url ) ? (string) $button_url : '';
$button_label = isset( $button_label ) ? (string) $button_label : '';

foreach ( $rows as $label => $value ) {
	if ( '' === (string) $value ) {
		continue;
	}
	echo esc_html( wp_strip_all_tags( (string) $label . ': ' . (string) $value ) ) . "\n";
}

if ( '' !== $button_url && '' !== $button_label ) {
	echo "\n" . esc_html( wp_strip_all_tags( $button_label ) ) . ': ' . esc_url_raw( $button_url ) . "\n";
}
