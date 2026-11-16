<?php
/**
 * License transferred (B-139) (plain text).
 *
 * Override: yourtheme/wp-license/emails/plain/wplc-license-transferred.php
 *
 * @package WPLC
 *
 * @var array<string,string> $view               Display values, see wplc-payment-method-missing.php.
 * @var array<string,string> $sharing            role ('previous' or 'new'), other_party (name of the other side).
 * @var string               $email_heading      Email heading.
 * @var string               $additional_content Additional content from the email settings.
 * @var string               $sender_note        Sender note from the license settings (plain text).
 * @var WC_Email             $email              Email object.
 */

defined( 'ABSPATH' ) || exit;

$view    = array_merge(
	array_fill_keys( array( 'product', 'plan', 'key_prefix', 'valid_until', 'account_url', 'support_email' ), '' ),
	isset( $view ) && is_array( $view ) ? $view : array()
);
$sharing = array_merge(
	array_fill_keys( array( 'role', 'other_party' ), '' ),
	isset( $sharing ) && is_array( $sharing ) ? $sharing : array()
);

if ( 'previous' === $sharing['role'] ) {
	$paragraphs = array(
		sprintf(
			/* translators: %s: product name. */
			__( 'Your license for %s was transferred: the recipient accepted the transfer. The license no longer appears in your account, and your saved payment method is no longer used for it.', 'wp-license' ),
			$view['product']
		),
		__( 'Sites that are activated with the license keep working. If you did not start this transfer, please contact us.', 'wp-license' ),
	);
	$button_url   = '';
	$button_label = '';
} else {
	$paragraphs = array(
		sprintf(
			/* translators: %s: product name. */
			__( 'The license for %s now belongs to your account. You find its key, sites and downloads under "Licenses".', 'wp-license' ),
			$view['product']
		),
		__( 'The license does not renew automatically. Renew it yourself before it ends, or switch on automatic renewal in your account with your own payment method.', 'wp-license' ),
	);
	$button_url   = $view['account_url'];
	$button_label = __( 'Open your licenses', 'wp-license' );
}
$rows = array(
	__( 'Product', 'wp-license' ) => $view['product'],
	__( 'Plan', 'wp-license' ) => $view['plan'],
	__( 'License reference', 'wp-license' ) => $view['key_prefix'],
	__( 'Valid through', 'wp-license' ) => $view['valid_until'],
	( 'previous' === $sharing['role'] ? __( 'Transferred to', 'wp-license' ) : __( 'Transferred by', 'wp-license' ) ) => $sharing['other_party'],
);

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
