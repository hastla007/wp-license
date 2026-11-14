<?php
/**
 * Renewal payment failed / action required (HTML).
 *
 * Override: yourtheme/wp-license/emails/wplc-payment-failed.php
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
