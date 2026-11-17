<?php
/**
 * Co-user invitation (B-139) (plain text).
 *
 * Override: yourtheme/wp-license/emails/plain/wplc-member-invitation.php
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
			__( '%1$s invites you to use their license for %2$s. As a co-user you see the license key, the activated sites and the downloads in your account, and you can deactivate sites. Payments, renewal and cancellation stay with the owner.', 'wp-license' ),
			$sharing['inviter'],
			$view['product']
		)
		: sprintf(
			/* translators: %s: product name. */
			__( 'You have been invited to use a license for %s. As a co-user you see the license key, the activated sites and the downloads in your account, and you can deactivate sites. Payments, renewal and cancellation stay with the owner.', 'wp-license' ),
			$view['product']
		),
	__( 'To accept, open the link below and sign in with an account that uses this email address. If you do not have an account yet, create one with this email address first. The link works once.', 'wp-license' ),
	__( 'If you do not know the sender, ignore this email; nothing changes.', 'wp-license' ),
);
$rows         = array(
	__( 'Product', 'wp-license' ) => $view['product'],
	__( 'Plan', 'wp-license' ) => $view['plan'],
	__( 'License reference', 'wp-license' ) => $view['key_prefix'],
	__( 'Invitation valid until', 'wp-license' ) => $sharing['expires'],
);
$button_url   = $sharing['accept_url'];
$button_label = __( 'Accept the invitation', 'wp-license' );

// Plain text is not HTML: escaping would turn an apostrophe into an entity that the mailer then drops.
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo wp_strip_all_tags( $email_heading ) . "\n";
echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

foreach ( $paragraphs as $paragraph ) {
	echo wp_strip_all_tags( $paragraph ) . "\n\n";
}

wc_get_template(
	'emails/plain/wplc-license-details.php',
	array(
		'rows' => $rows,
		'button_url' => $button_url,
		'button_label' => $button_label,
	),
	'wp-license/',
	WPLC_PATH . 'templates/'
);

echo "\n";

if ( ! empty( $additional_content ) ) {
	echo wp_strip_all_tags( wptexturize( $additional_content ) ) . "\n\n";
}

wc_get_template(
	'emails/plain/wplc-sender-note.php',
	array( 'sender_note' => isset( $sender_note ) ? (string) $sender_note : '' ),
	'wp-license/',
	WPLC_PATH . 'templates/'
);

echo "----------------------------------------\n\n";

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
// phpcs:enable
