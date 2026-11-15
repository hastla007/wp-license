<?php
/**
 * Terms of the automatic renewal (HTML): date, amount including tax, interval, how to cancel.
 *
 * Printed in the "license issued" email and below the order table of the
 * customer's order emails while automatic renewal is on.
 *
 * Override: yourtheme/wp-license/emails/wplc-renewal-terms.php
 *
 * @package WPLC
 *
 * @var array<string,string> $renewal_terms Label => plain-text value.
 * @var string               $cancel_text   How to cancel.
 * @var string               $product       Product name, may be empty.
 * @var string               $key_prefix    License reference, may be empty.
 */

defined( 'ABSPATH' ) || exit;

$renewal_terms = isset( $renewal_terms ) && is_array( $renewal_terms ) ? $renewal_terms : array();
if ( ! $renewal_terms ) {
	return;
}
$cancel_text = isset( $cancel_text ) ? (string) $cancel_text : '';
$product     = isset( $product ) ? (string) $product : '';
$key_prefix  = isset( $key_prefix ) ? (string) $key_prefix : '';
$text_align  = is_rtl() ? 'right' : 'left';
$title       = __( 'Automatic renewal', 'wp-license' );
if ( '' !== $product ) {
	$title .= ': ' . $product . ( '' !== $key_prefix ? ' (' . $key_prefix . ')' : '' );
}
?>
<h2><?php echo esc_html( $title ); ?></h2>
<table class="td" cellspacing="0" cellpadding="6" border="1" style="width: 100%; margin-bottom: 12px; table-layout: fixed;">
	<tbody>
		<?php foreach ( $renewal_terms as $label => $value ) : ?>
			<tr>
				<th class="td" scope="row" style="text-align: <?php echo esc_attr( $text_align ); ?>; width: 40%;"><?php echo esc_html( (string) $label ); ?></th>
				<td class="td" style="text-align: <?php echo esc_attr( $text_align ); ?>;"><?php echo esc_html( (string) $value ); ?></td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>
<?php if ( '' !== $cancel_text ) : ?>
	<p style="margin: 0 0 24px;"><?php echo esc_html( $cancel_text ); ?></p>
<?php endif; ?>
