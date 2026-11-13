<?php
/**
 * License expiry reminder (no automatic renewal) (HTML).
 *
 * Override: yourtheme/wp-license/emails/wplc-expiry-reminder.php
 *
 * @package WPLC
 *
 * @var array<string,string> $view               Display values: product, plan, key_prefix, valid_until, days_left,
 *                                               amount, currency, charge_date, grace_until, account_url, pay_url.
 * @var string               $email_heading      Email heading.
 * @var string               $additional_content Additional content from the email settings.
 * @var WC_Email             $email              Email object.
 */

defined( 'ABSPATH' ) || exit;

$view = array_merge(
	array_fill_keys( array( 'product', 'plan', 'key_prefix', 'valid_until', 'days_left', 'amount', 'currency', 'charge_date', 'grace_until', 'account_url', 'pay_url' ), '' ),
	isset( $view ) && is_array( $view ) ? $view : array()
);

$days_left  = (int) $view['days_left'];
$paragraphs = array(
	sprintf(
		/* translators: 1: number of days, 2: date. */
		_n( 'Your license ends in %1$d day, on %2$s.', 'Your license ends in %1$d days, on %2$s.', max( 1, $days_left ), 'wp-license' ),
		max( 1, $days_left ),
		$view['valid_until']
	),
	__( 'It does not renew automatically. Renew it in time to keep receiving updates and to keep your sites activated.', 'wp-license' ),
);
$rows       = array(
	__( 'Product', 'wp-license' ) => $view['product'],
	__( 'Plan', 'wp-license' ) => $view['plan'],
	__( 'License reference', 'wp-license' ) => $view['key_prefix'],
	__( 'Valid until', 'wp-license' ) => $view['valid_until'],
);
$button_url   = $view['account_url'];
$button_label = __( 'Renew now', 'wp-license' );

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

do_action( 'woocommerce_email_footer', $email );
