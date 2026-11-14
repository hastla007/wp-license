<?php
/**
 * License suspended or revoked (HTML).
 *
 * Override: yourtheme/wp-license/emails/wplc-license-blocked.php
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

$wplc_revoked = 'revoked' === $view['status'];
$paragraphs   = array(
	$wplc_revoked
		? __( 'Your license has been revoked. Updates, downloads and premium features are no longer available for it, and it cannot be activated on sites any more.', 'wp-license' )
		: __( 'Your license has been suspended. Updates, downloads and premium features are not available for it until the suspension is lifted.', 'wp-license' ),
	$wplc_revoked
		? __( 'A license is revoked, for example, when its order was refunded.', 'wp-license' )
		: __( 'A license is suspended, for example, while a payment is disputed.', 'wp-license' ),
	'' !== $view['support_email']
		/* translators: %s: email address. */
		? sprintf( __( 'If you think this is a mistake or have questions, please contact our support: %s', 'wp-license' ), $view['support_email'] )
		: __( 'If you think this is a mistake or have questions, please contact our support.', 'wp-license' ),
);
$rows         = array(
	__( 'Product', 'wp-license' ) => $view['product'],
	__( 'Plan', 'wp-license' ) => $view['plan'],
	__( 'License reference', 'wp-license' ) => $view['key_prefix'],
	__( 'Status', 'wp-license' ) => $wplc_revoked ? __( 'Revoked', 'wp-license' ) : __( 'Suspended', 'wp-license' ),
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
