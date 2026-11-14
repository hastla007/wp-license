<?php
/**
 * Upcoming automatic renewal (HTML).
 *
 * Override: yourtheme/wp-license/emails/wplc-renewal-notice.php
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
	'' !== $view['amount']
		? sprintf(
			/* translators: 1: date, 2: formatted amount. */
			__( 'Your license renews automatically on %1$s. We will then charge %2$s to your saved payment method.', 'wp-license' ),
			$view['charge_date'],
			$view['amount']
		)
		: sprintf(
			/* translators: %s: date. */
			__( 'Your license renews automatically on %s. We will then charge your saved payment method.', 'wp-license' ),
			$view['charge_date']
		),
	'' !== $view['amount_includes_tax']
		? __( 'The amount includes tax, calculated for your current billing address.', 'wp-license' )
		: '',
	__( 'If you do not want to renew, cancel the automatic renewal in your account before that date. Your license then stays valid until the end of the paid period and is not charged again.', 'wp-license' ),
);
$rows       = array(
	__( 'Product', 'wp-license' ) => $view['product'],
	__( 'Plan', 'wp-license' ) => $view['plan'],
	__( 'License reference', 'wp-license' ) => $view['key_prefix'],
	__( 'Renewal date', 'wp-license' ) => $view['charge_date'],
	__( 'Amount', 'wp-license' ) => $view['amount'],
	__( 'Currency', 'wp-license' ) => '' !== $view['amount'] ? $view['currency'] : '',
	__( 'Interval', 'wp-license' ) => $view['interval'],
);
$button_url   = $view['account_url'];
$button_label = __( 'Manage or cancel renewal', 'wp-license' );

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
