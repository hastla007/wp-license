<?php
/**
 * Upcoming automatic renewal (plain text).
 *
 * Override: yourtheme/wp-license/emails/plain/wplc-renewal-notice.php
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
	__( 'Taxes may be added according to your billing address.', 'wp-license' ),
	__( 'If you do not want to renew, cancel the automatic renewal in your account before that date. Your license then stays valid until the end of the paid period and is not charged again.', 'wp-license' ),
);
$rows       = array(
	__( 'Product', 'wp-license' ) => $view['product'],
	__( 'Plan', 'wp-license' ) => $view['plan'],
	__( 'License reference', 'wp-license' ) => $view['key_prefix'],
	__( 'Renewal date', 'wp-license' ) => $view['charge_date'],
	__( 'Amount', 'wp-license' ) => $view['amount'],
	__( 'Currency', 'wp-license' ) => '' !== $view['amount'] ? $view['currency'] : '',
);
$button_url   = $view['account_url'];
$button_label = __( 'Manage or cancel renewal', 'wp-license' );

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
