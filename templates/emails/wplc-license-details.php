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
?>
<table class="td" cellspacing="0" cellpadding="6" border="1" style="width: 100%; margin-bottom: 24px;">
	<tbody>
		<?php foreach ( $rows as $label => $value ) : ?>
			<?php
			if ( '' === (string) $value ) {
				continue;
			}
			?>
			<tr>
				<th class="td" scope="row" style="text-align: left; width: 40%;"><?php echo esc_html( (string) $label ); ?></th>
				<td class="td" style="text-align: left;"><?php echo esc_html( (string) $value ); ?></td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>
<?php if ( '' !== $button_url && '' !== $button_label ) : ?>
	<p style="margin: 0 0 24px;">
		<a class="button" href="<?php echo esc_url( $button_url ); ?>" style="display: inline-block; padding: 10px 18px; border-radius: 4px; background-color: #2271b1; color: #ffffff; text-decoration: none; font-weight: bold;"><?php echo esc_html( $button_label ); ?></a>
	</p>
<?php endif; ?>
