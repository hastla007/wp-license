<?php
/**
 * Saved card no longer available (B-192) (plain text).
 *
 * Override: yourtheme/wp-license/emails/plain/wplc-payment-method-missing.php
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
	__( 'The card you saved for the automatic renewal of your license is no longer available, so we could not charge the renewal.', 'wp-license' ),
	__( 'We do not charge any other card of yours in its place.', 'wp-license' ),
	sprintf(
		/* translators: %s: date. */
		__( 'Your license keeps working until %s. To keep it, add a card in your account and switch automatic renewal on again under "Licenses", or renew the license manually there.', 'wp-license' ),
		$view['grace_until']
	),
);
$rows       = array(
	__( 'Product', 'wp-license' ) => $view['product'],
	__( 'Plan', 'wp-license' ) => $view['plan'],
	__( 'License reference', 'wp-license' ) => $view['key_prefix'],
	__( 'Paid through', 'wp-license' ) => $view['valid_until'],
	__( 'Renewal possible until', 'wp-license' ) => $view['grace_until'],
);
$button_url   = $view['account_url'];
$button_label = __( 'Open your licenses', 'wp-license' );

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

echo "----------------------------------------\n\n";

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
// phpcs:enable
