<?php
/**
 * Three steps from the purchase to the working plugin (plain text).
 *
 * Override: yourtheme/wp-license/emails/plain/wplc-install-steps.php
 *
 * @package WPLC
 *
 * @var string[] $install_steps Plain-text steps.
 * @var string   $docs_url      Documentation link, empty for none.
 */

defined( 'ABSPATH' ) || exit;

$install_steps = isset( $install_steps ) && is_array( $install_steps ) ? $install_steps : array();
if ( ! $install_steps ) {
	return;
}
$docs_url = isset( $docs_url ) ? (string) $docs_url : '';

// Plain text is not HTML: escaping would turn an apostrophe into an entity that the mailer then drops.
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
echo "\n" . wp_strip_all_tags( __( 'How to get started', 'wp-license' ) ) . "\n";
$wplc_step_number = 0;
foreach ( $install_steps as $install_step ) {
	++$wplc_step_number;
	echo (int) $wplc_step_number . '. ' . wp_strip_all_tags( (string) $install_step ) . "\n";
}
if ( '' !== $docs_url ) {
	echo "\n" . wp_strip_all_tags( __( 'Read the documentation', 'wp-license' ) ) . ': ' . esc_url_raw( $docs_url ) . "\n";
}
echo "\n";
// phpcs:enable
