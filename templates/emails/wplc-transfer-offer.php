<?php
/**
 * License transfer offered (B-139) (HTML).
 *
 * Override: yourtheme/wp-license/emails/wplc-transfer-offer.php
 *
 * @package WPLC
 *
 * @var array<string,string> $view               Display values, see wplc-payment-method-missing.php.
 * @var array<string,string> $sharing            accept_url (single-use link), expires (date), inviter (name of the owner).
 * @var string               $email_heading      Email heading.
 * @var string               $additional_content Additional content from the email settings.
 * @var string               $sender_note        Sender note from the license settings (plain text).
 * @var WC_Email             $email              Email object.
 */

defined( 'ABSPATH' ) || exit;

$view    = array_merge(
	array_fill_keys( array( 'product', 'plan', 'key_prefix', 'valid_until', 'support_email' ), '' ),
	isset( $view ) && is_array( $view ) ? $view : array()
);
$sharing = array_merge(
	array_fill_keys( array( 'accept_url', 'expires', 'inviter' ), '' ),
	isset( $sharing ) && is_array( $sharing ) ? $sharing : array()
);

$paragraphs = array(
	'' !== $sharing['inviter']
		? sprintf(
			/* translators: 1: name of the license owner, 2: product name. */
			__( '%1$s wants to transfer their license for %2$s to you. Once you accept, the license belongs to your account: you see its key, sites and downloads, and you decide about renewal and cancellation.', 'wp-license' ),
			$sharing['inviter'],
			$view['product']
		)
		: sprintf(
			/* translators: %s: product name. */
			__( 'A license for %s is being transferred to you. Once you accept, the license belongs to your account: you see its key, sites and downloads, and you decide about renewal and cancellation.', 'wp-license' ),
			$view['product']
		),
	__( 'The license does not renew automatically after the transfer. Renew it yourself in your account before it ends, or switch on automatic renewal there with your own payment method.', 'wp-license' ),
	__( 'To accept, open the link below and sign in with an account that uses this email address. If you do not have an account yet, create one with this email address first. The link works once. Nothing changes until you accept.', 'wp-license' ),
);
$rows         = array(
	__( 'Product', 'wp-license' ) => $view['product'],
	__( 'Plan', 'wp-license' ) => $view['plan'],
	__( 'License reference', 'wp-license' ) => $view['key_prefix'],
	__( 'Valid through', 'wp-license' ) => $view['valid_until'],
	__( 'Offer valid until', 'wp-license' ) => $sharing['expires'],
);
$button_url   = $sharing['accept_url'];
$button_label = __( 'Accept the transfer', 'wp-license' );

do_action( 'woocommerce_email_header', $email_heading, $email );

foreach ( $paragraphs as $paragraph ) {
	echo '<p>' . esc_html( $paragraph ) . '</p>';
}

wc_get_template(
	'emails/wplc-license-details.php',
	array(
		'rows' => $rows,
		'button_url' => $button_url,
		'button_label' => $button_label,
	),
	'wp-license/',
	WPLC_PATH . 'templates/'
);

if ( ! empty( $additional_content ) ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

wc_get_template(
	'emails/wplc-sender-note.php',
	array( 'sender_note' => isset( $sender_note ) ? (string) $sender_note : '' ),
	'wp-license/',
	WPLC_PATH . 'templates/'
);

do_action( 'woocommerce_email_footer', $email );
