<?php
/**
 * New renewal price announced (INH-05) (HTML).
 *
 * Override: yourtheme/wp-license/emails/wplc-price-change.php
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
		/* translators: 1: date, 2: new price, 3: current price. */
		__( 'From %1$s the renewal of your license costs %2$s instead of %3$s. Renewals before that date are still charged at the current price.', 'wp-license' ),
		$view['new_amount_from'],
		$view['new_amount'],
		$view['old_amount']
	),
	'' !== $view['renews_automatically']
		? __( 'Your license renews automatically. If you do not agree with the new price, you can cancel the automatic renewal at any time in your account under "Licenses"; the license then stays valid until the end of the paid period and nothing more is charged.', 'wp-license' )
		: __( 'Your license does not renew automatically. Nothing is charged unless you renew it yourself in your account under "Licenses".', 'wp-license' ),
	__( 'Everything else about your license stays as it is.', 'wp-license' ),
);
$rows       = array(
	__( 'Product', 'wp-license' ) => $view['product'],
	__( 'Plan', 'wp-license' ) => $view['plan'],
	__( 'License reference', 'wp-license' ) => $view['key_prefix'],
	__( 'Paid through', 'wp-license' ) => $view['valid_until'],
	__( 'Current renewal price', 'wp-license' ) => $view['old_amount'],
	__( 'New renewal price', 'wp-license' ) => $view['new_amount'],
	__( 'New price applies from', 'wp-license' ) => $view['new_amount_from'],
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
