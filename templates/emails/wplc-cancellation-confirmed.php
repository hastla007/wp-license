<?php
/**
 * Cancellation of the automatic renewal confirmed (HTML).
 *
 * Override: yourtheme/wp-license/emails/wplc-cancellation-confirmed.php
 *
 * @package WPLC
 *
 * @var array<string,string> $view               Display values: product, plan, key_prefix, valid_until, days_left,
 *                                               amount (including tax), amount_includes_tax, currency, interval,
 *                                               charge_date, grace_until, account_url, pay_url, can_renew_now,
 *                                               renew_from, cancelled_at, status, support_email.
 * @var string               $email_heading      Email heading.
 * @var string               $additional_content Additional content from the email settings.
 * @var WC_Email             $email              Email object.
 */

defined( 'ABSPATH' ) || exit;

$view = array_merge(
	array_fill_keys( array( 'product', 'plan', 'key_prefix', 'valid_until', 'days_left', 'amount', 'amount_includes_tax', 'currency', 'interval', 'charge_date', 'grace_until', 'account_url', 'pay_url', 'can_renew_now', 'renew_from', 'cancelled_at', 'status', 'support_email' ), '' ),
	isset( $view ) && is_array( $view ) ? $view : array()
);

$paragraphs = array(
	'' !== $view['cancelled_at']
		/* translators: %s: date and time. */
		? sprintf( __( 'We received your cancellation of the automatic renewal on %s and confirm it herewith.', 'wp-license' ), $view['cancelled_at'] )
		: __( 'We received your cancellation of the automatic renewal and confirm it herewith.', 'wp-license' ),
	sprintf(
		/* translators: %s: date. */
		__( 'Your license stays valid until %s and ends then. Your payment method will not be charged again for this license.', 'wp-license' ),
		$view['valid_until']
	),
	__( 'If you change your mind, you can undo the cancellation in your account before the license ends.', 'wp-license' ),
);
$rows       = array(
	__( 'Product', 'wp-license' ) => $view['product'],
	__( 'Plan', 'wp-license' ) => $view['plan'],
	__( 'License reference', 'wp-license' ) => $view['key_prefix'],
	__( 'Cancellation received', 'wp-license' ) => $view['cancelled_at'],
	__( 'License ends on', 'wp-license' ) => $view['valid_until'],
);
$button_url   = $view['account_url'];
$button_label = __( 'View license in your account', 'wp-license' );

do_action( 'woocommerce_email_header', $email_heading, $email );

foreach ( array_filter( $paragraphs, 'strlen' ) as $paragraph ) {
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

do_action( 'woocommerce_email_footer', $email );
