<?php
/**
 * Sender note below the content of every license email (HTML).
 *
 * Override: yourtheme/wp-license/emails/wplc-sender-note.php
 *
 * @package WPLC
 *
 * @var string $sender_note Plain text from WooCommerce → License settings; empty for none.
 */

defined( 'ABSPATH' ) || exit;

$sender_note = isset( $sender_note ) && is_scalar( $sender_note ) ? trim( (string) $sender_note ) : '';

if ( '' === $sender_note ) {
	return;
}

// The note is plain text: escape first, then turn line breaks into paragraphs.
echo '<div class="wplc-sender-note">' . wp_kses_post( wpautop( esc_html( $sender_note ) ) ) . '</div>';
