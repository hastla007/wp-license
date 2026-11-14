<?php
/**
 * Renewal payment failed / action required (plain text).
 *
 * Override: yourtheme/wp-license/emails/plain/wplc-payment-failed.php
 *
 * @package WPLC
 *
 * @var array<string,string> $view               Display values: product, plan, key_prefix, valid_until, days_left,
 *                                               amount, currency, charge_date, grace_until, account_url, pay_url.
 * @var string               $email_heading      Email heading.
 * @var string               $additional_content Additional content from the email settings.
 * @var string               $sender_note        Sender note from the license settings; empty for none.
 * @var WC_Email             $email              Email object.
 */

defined( 'ABSPATH' ) || exit;

$view = array_merge(
	array_fill_keys( array( 'product', 'plan', 'key_prefix', 'valid_until', 'days_left', 'amount', 'currency', 'charge_date', 'grace_until', 'account_url', 'pay_url' ), '' ),
	isset( $view ) && is_array( $view ) ? $view : array()
);

$paragraphs = array(
	__( 'We could not collect the renewal payment for your license.', 'wp-license' ),
	sprintf(
		/* translators: %s: date. */
		__( 'Your license keeps working until %s. Please complete the payment before that date, otherwise the license expires.', 'wp-license' ),
		$view['grace_until']
	),
	'' !== $view['pay_url']
		? __( 'Your payment provider may ask you to confirm the payment.', 'wp-license' )
		: __( 'Please open your account to check the license and your payment method.', 'wp-license' ),
);
$rows       = array(
	__( 'Product', 'wp-license' ) => $view['product'],
	__( 'Plan', 'wp-license' ) => $view['plan'],
	__( 'License reference', 'wp-license' ) => $view['key_prefix'],
	__( 'Paid through', 'wp-license' ) => $view['valid_until'],
	__( 'Payment possible until', 'wp-license' ) => $view['grace_until'],
);
$button_url   = '' !== $view['pay_url'] ? $view['pay_url'] : $view['account_url'];
$button_label = '' !== $view['pay_url'] ? __( 'Pay now', 'wp-license' ) : __( 'Open your account', 'wp-license' );

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n";
echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

foreach ( $paragraphs as $paragraph ) {
	echo esc_html( wp_strip_all_tags( $paragraph ) ) . "\n\n";
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
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

wc_get_template(
	'emails/plain/wplc-sender-note.php',
	array( 'sender_note' => isset( $sender_note ) ? (string) $sender_note : '' ),
	'wp-license/',
	WPLC_PATH . 'templates/'
);

echo "----------------------------------------\n\n";

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
