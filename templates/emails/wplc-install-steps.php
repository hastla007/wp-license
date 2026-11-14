<?php
/**
 * Three steps from the purchase to the working plugin (HTML).
 *
 * Override: yourtheme/wp-license/emails/wplc-install-steps.php
 *
 * @package WPLC
 *
 * @var string[] $install_steps Plain-text steps.
 * @var string   $docs_url      Documentation link, empty for none.
 */

defined( 'ABSPATH' ) || exit;

$install_steps = isset( $install_steps ) && is_array( $install_steps ) ? $install_steps : array();
if ( ! $install_steps ) {
	return;
}
$docs_url = isset( $docs_url ) ? (string) $docs_url : '';
?>
<h2><?php esc_html_e( 'How to get started', 'wp-license' ); ?></h2>
<ol style="margin: 0 0 24px; padding-inline-start: 24px;">
	<?php foreach ( $install_steps as $install_step ) : ?>
		<li style="margin: 0 0 6px;"><?php echo esc_html( (string) $install_step ); ?></li>
	<?php endforeach; ?>
</ol>
<?php if ( '' !== $docs_url ) : ?>
	<p style="margin: 0 0 24px;"><a href="<?php echo esc_url( $docs_url ); ?>"><?php esc_html_e( 'Read the documentation', 'wp-license' ); ?></a></p>
<?php endif; ?>
