<?php
/**
 * Announced renewal price withdrawn (INH-05) (HTML).
 *
 * Override: yourtheme/wp-license/emails/wplc-price-change-withdrawn.php
 *
 * @package WPLC
 *
 * @var array<string,string> $view               Display values, see wplc-payment-method-missing.php, and for this email:
 *                                               old_amount, new_amount (both including tax where tax applies),
 *                                               new_amount_from (date), renews_automatically ('1' or '').
 * @var string               $email_heading      Email heading.
 * @var string               $additional_content Additional content from the email settings.
 * @var string               $sender_note        Sender note from the license settings (plain text).
 * @var WC_Email             $email              Email object.
 */

defined( 'ABSPATH' ) || exit;

$view = array_merge(
	array_fill_keys( array( 'product', 'plan', 'key_prefix', 'valid_until', 'days_left', 'amount', 'amount_includes_tax', 'currency', 'interval', 'charge_date', 'grace_until', 'account_url', 'pay_url', 'can_renew_now', 'renew_from', 'cancelled_at', 'status', 'support_email', 'old_amount', 'new_amount', 'new_amount_from', 'renews_automatically' ), '' ),
	isset( $view ) && is_array( $view ) ? $view : array()
);

$paragraphs = array(
	sprintf(
		/* translators: 1: announced price, 2: date, 3: current price. */
		__( 'We told you that the renewal of your license would cost %1$s from %2$s. We have withdrawn this change: your license keeps renewing at %3$s.', 'wp-license' ),
		$view['new_amount'],
		$view['new_amount_from'],
		$view['old_amount']
	),
	__( 'You do not need to do anything.', 'wp-license' ),
);
$rows       = array(
	__( 'Product', 'wp-license' ) => $view['product'],
	__( 'Plan', 'wp-license' ) => $view['plan'],
	__( 'License reference', 'wp-license' ) => $view['key_prefix'],
	__( 'Renewal price', 'wp-license' ) => $view['old_amount'],
);
$button_url   = $view['account_url'];
$button_label = __( 'Open your licenses', 'wp-license' );

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
