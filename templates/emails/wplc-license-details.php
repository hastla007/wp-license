<?php
/**
 * License details table and call-to-action button (HTML), shared by all license emails.
 *
 * Override: yourtheme/wp-license/emails/wplc-license-details.php
 *
 * @package WPLC
 *
 * @var array<string,string> $rows         Label => plain-text value.
 * @var string               $button_url   Target of the button, empty for none.
 * @var string               $button_label Button text.
 */

defined( 'ABSPATH' ) || exit;

$rows         = isset( $rows ) && is_array( $rows ) ? $rows : array();
$button_url   = isset( $button_url ) ? (string) $button_url : '';
$button_label = isset( $button_label ) ? (string) $button_label : '';
// Follow the reading direction of the email language instead of a fixed "left".
$text_align = is_rtl() ? 'right' : 'left';
?>
<table class="td" cellspacing="0" cellpadding="6" border="1" style="width: 100%; margin-bottom: 24px; table-layout: fixed;">
	<tbody>
		<?php foreach ( $rows as $label => $value ) : ?>
			<?php
			if ( '' === (string) $value ) {
				continue;
			}
			?>
			<tr>
				<th class="td" scope="row" style="text-align: <?php echo esc_attr( $text_align ); ?>; width: 40%;"><?php echo esc_html( (string) $label ); ?></th>
				<?php // A full license key has no spaces: let it wrap instead of widening the table. ?>
				<td class="td" style="text-align: <?php echo esc_attr( $text_align ); ?>; word-break: break-word; overflow-wrap: anywhere;"><?php echo esc_html( (string) $value ); ?></td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>
<?php if ( '' !== $button_url && '' !== $button_label ) : ?>
	<?php // The padding sits on the table cell: Outlook ignores padding on links and would shrink the button. ?>
	<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin: 0 0 24px;">
		<tbody>
			<tr>
				<td bgcolor="#2271b1" style="padding: 10px 18px; border-radius: 4px; background-color: #2271b1;">
					<a class="button" href="<?php echo esc_url( $button_url ); ?>" style="display: inline-block; color: #ffffff; text-decoration: none; font-weight: bold;"><?php echo esc_html( $button_label ); ?></a>
				</td>
			</tr>
		</tbody>
	</table>
<?php endif; ?>
