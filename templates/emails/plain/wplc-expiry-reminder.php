<?php
/**
 * License expiry reminder (no automatic renewal) (plain text).
 *
 * Override: yourtheme/wp-license/emails/plain/wplc-expiry-reminder.php
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

// Days are calendar days in the site timezone, like the date next to them: 0 means "today".
$days_left  = (int) $view['days_left'];
$can_renew  = '' !== $view['can_renew_now'] || '' === $view['renew_from'];
$paragraphs = array(
	$days_left < 1
		/* translators: %s: date. */
		? sprintf( __( 'Your license ends today, %s.', 'wp-license' ), $view['valid_until'] )
		: sprintf(
			/* translators: 1: number of days, 2: date. */
			_n( 'Your license ends in %1$d day, on %2$s.', 'Your license ends in %1$d days, on %2$s.', $days_left, 'wp-license' ),
			$days_left,
			$view['valid_until']
		),
	__( 'It does not renew automatically. Renew it in time to keep receiving updates and to keep your sites activated.', 'wp-license' ),
	$can_renew
		? __( 'You can renew the license in your account under "Licenses".', 'wp-license' )
		/* translators: %s: date. */
		: sprintf( __( 'You can renew the license in your account under "Licenses" from %s.', 'wp-license' ), $view['renew_from'] ),
);
$rows       = array(
	__( 'Product', 'wp-license' ) => $view['product'],
	__( 'Plan', 'wp-license' ) => $view['plan'],
	__( 'License reference', 'wp-license' ) => $view['key_prefix'],
	__( 'Valid until', 'wp-license' ) => $view['valid_until'],
);
$button_url   = $view['account_url'];
// The account only offers "Renew now" inside the renewal window; do not promise a button that is not there yet.
$button_label = $can_renew ? __( 'Renew now', 'wp-license' ) : __( 'View license in your account', 'wp-license' );

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
