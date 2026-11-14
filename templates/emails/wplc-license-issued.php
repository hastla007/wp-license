<?php
/**
 * License issued (HTML).
 *
 * Override: yourtheme/wp-license/emails/wplc-license-issued.php
 *
 * @package WPLC
 *
 * @var array<string,string> $view               Display values: product, plan, key_prefix, valid_until, days_left,
 *                                               amount (including tax), amount_includes_tax, currency, interval,
 *                                               charge_date, grace_until, account_url, pay_url, can_renew_now,
 *                                               renew_from, cancelled_at, status, support_email.
 * @var string               $license_key        Full key; empty unless the shop owner enabled it for this email.
 * @var string[]             $install_steps      Steps from the download to the activated license.
 * @var string               $docs_url           Documentation link, empty for none.
 * @var array<string,string> $renewal_terms      Terms of the automatic renewal as label => value; empty without it.
 * @var string               $cancel_text        How to cancel the automatic renewal.
 * @var string               $email_heading      Email heading.
 * @var string               $additional_content Additional content from the email settings.
 * @var WC_Email             $email              Email object.
 */

defined( 'ABSPATH' ) || exit;

$view = array_merge(
	array_fill_keys( array( 'product', 'plan', 'key_prefix', 'valid_until', 'days_left', 'amount', 'amount_includes_tax', 'currency', 'interval', 'charge_date', 'grace_until', 'account_url', 'pay_url', 'can_renew_now', 'renew_from', 'cancelled_at', 'status', 'support_email' ), '' ),
	isset( $view ) && is_array( $view ) ? $view : array()
);

$license_key = isset( $license_key ) ? (string) $license_key : '';
$paragraphs  = array(
	__( 'Thank you for your purchase. Your license has been created and is ready to use.', 'wp-license' ),
	__( 'You can download the plugin in your account under "Licenses" at any time while the license is valid.', 'wp-license' ),
	'' === $license_key
		? __( 'For security reasons this email does not contain the full license key. You can view and copy it in your account.', 'wp-license' )
		: __( 'Keep your license key confidential. You can also find it in your account at any time.', 'wp-license' ),
);
$rows        = array(
	__( 'Product', 'wp-license' ) => $view['product'],
	__( 'Plan', 'wp-license' ) => $view['plan'],
	__( 'License reference', 'wp-license' ) => $view['key_prefix'],
	__( 'License key', 'wp-license' ) => $license_key,
	__( 'Valid until', 'wp-license' ) => $view['valid_until'],
);
$button_url   = $view['account_url'];
$button_label = __( 'View license in your account', 'wp-license' );

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

wc_get_template(
	'emails/wplc-install-steps.php',
	array(
		'install_steps' => isset( $install_steps ) && is_array( $install_steps ) ? $install_steps : array(),
		'docs_url' => isset( $docs_url ) ? (string) $docs_url : '',
	),
	'wp-license/',
	WPLC_PATH . 'templates/'
);

// Date, amount including tax, interval and how to cancel: only while automatic renewal is on.
wc_get_template(
	'emails/wplc-renewal-terms.php',
	array(
		'renewal_terms' => isset( $renewal_terms ) && is_array( $renewal_terms ) ? $renewal_terms : array(),
		'cancel_text' => isset( $cancel_text ) ? (string) $cancel_text : '',
	),
	'wp-license/',
	WPLC_PATH . 'templates/'
);

if ( ! empty( $additional_content ) ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
