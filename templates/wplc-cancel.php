<?php
/**
 * Public cancellation page, shortcode [wplc_cancel].
 *
 * Three views: the form, the confirmation page with the cancellation button,
 * and the result. The result is worded the same whether or not a license
 * matched, so the page does not reveal who owns a license.
 *
 * Override: yourtheme/wp-license/wplc-cancel.php
 *
 * @package WPLC
 *
 * @var array<string,mixed> $state       view (form|review|done|limited), email, reference, error, expires, signature.
 * @var string              $form_url    URL of the page with the shortcode.
 * @var string              $received_at Formatted date and time the cancellation was received, for the result view.
 * @var string              $support     Support e-mail address, may be empty.
 * @var string              $account_url URL of the license page in the customer account.
 */

defined( 'ABSPATH' ) || exit;

$state       = isset( $state ) && is_array( $state ) ? $state : array();
$state       = array_merge(
	array(
		'view'      => 'form',
		'email'     => '',
		'reference' => '',
		'error'     => '',
		'expires'   => 0,
		'signature' => '',
	),
	$state
);
$form_url    = isset( $form_url ) ? (string) $form_url : '';
$received_at = isset( $received_at ) ? (string) $received_at : '';
$support     = isset( $support ) ? (string) $support : '';
$account_url = isset( $account_url ) ? (string) $account_url : '';
?>
<div class="wplc-cancel wplc-cancel--<?php echo esc_attr( (string) $state['view'] ); ?>">
	<?php if ( 'done' === $state['view'] ) : ?>

		<div class="wplc-cancel-result" role="status">
			<h2><?php esc_html_e( 'We received your cancellation', 'wp-license' ); ?></h2>
			<p>
				<?php
				/* translators: %s: date and time. */
				echo esc_html( sprintf( __( 'Your cancellation was received on %s.', 'wp-license' ), $received_at ) );
				?>
			</p>
			<p><?php esc_html_e( 'If the details match a license with automatic renewal, the renewal is now cancelled: the license stays valid until the end of the paid period and is not charged again. We have sent a confirmation with the end date to the e-mail address of the license.', 'wp-license' ); ?></p>
			<p>
				<?php
				esc_html_e( 'No e-mail after a few minutes? Then the details did not match. Please check the e-mail address and the reference and try again.', 'wp-license' );
				if ( '' !== $support ) {
					/* translators: %s: email address. */
					echo ' ' . esc_html( sprintf( __( 'You can also contact our support: %s', 'wp-license' ), $support ) );
				}
				?>
			</p>
		</div>

	<?php elseif ( 'limited' === $state['view'] ) : ?>

		<p class="wplc-cancel-limited" role="alert"><?php esc_html_e( 'Too many cancellation requests were sent in a short time. Please try again in an hour.', 'wp-license' ); ?></p>

	<?php elseif ( 'review' === $state['view'] ) : ?>

		<h2><?php esc_html_e( 'Please confirm your cancellation', 'wp-license' ); ?></h2>
		<p><?php esc_html_e( 'You are about to cancel the automatic renewal for:', 'wp-license' ); ?></p>
		<dl class="wplc-cancel-details">
			<dt><?php esc_html_e( 'E-mail address', 'wp-license' ); ?></dt>
			<dd><?php echo esc_html( (string) $state['email'] ); ?></dd>
			<dt><?php esc_html_e( 'License reference or order number', 'wp-license' ); ?></dt>
			<dd><?php echo esc_html( (string) $state['reference'] ); ?></dd>
		</dl>
		<p><?php esc_html_e( 'The license stays valid until the end of the paid period and ends then. You will receive a confirmation by e-mail.', 'wp-license' ); ?></p>
		<form method="post" action="<?php echo esc_url( $form_url ); ?>" class="wplc-cancel-form">
			<input type="hidden" name="wplc_cancel_step" value="confirm">
			<input type="hidden" name="wplc_cancel_email" value="<?php echo esc_attr( (string) $state['email'] ); ?>">
			<input type="hidden" name="wplc_cancel_reference" value="<?php echo esc_attr( (string) $state['reference'] ); ?>">
			<input type="hidden" name="wplc_cancel_expires" value="<?php echo esc_attr( (string) (int) $state['expires'] ); ?>">
			<input type="hidden" name="wplc_cancel_signature" value="<?php echo esc_attr( (string) $state['signature'] ); ?>">
			<button type="submit" class="button alt"><?php esc_html_e( 'Cancel now', 'wp-license' ); ?></button>
			<a class="wplc-cancel-back" href="<?php echo esc_url( $form_url ); ?>"><?php esc_html_e( 'Change details', 'wp-license' ); ?></a>
		</form>

	<?php else : ?>

		<h2><?php esc_html_e( 'Cancel automatic renewal', 'wp-license' ); ?></h2>
		<p><?php esc_html_e( 'Here you can cancel the automatic renewal of a license without signing in. You find the license reference (for example WPLC-1A2B3C4D) in every e-mail about your license; the order number is in your order confirmation.', 'wp-license' ); ?></p>
		<?php if ( '' !== (string) $state['error'] ) : ?>
			<p class="wplc-cancel-error woocommerce-error" role="alert"><?php echo esc_html( (string) $state['error'] ); ?></p>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( $form_url ); ?>" class="wplc-cancel-form">
			<input type="hidden" name="wplc_cancel_step" value="review">
			<p class="form-row">
				<label for="wplc-cancel-email"><?php esc_html_e( 'E-mail address of your purchase', 'wp-license' ); ?></label>
				<input type="email" class="input-text" id="wplc-cancel-email" name="wplc_cancel_email" value="<?php echo esc_attr( (string) $state['email'] ); ?>" autocomplete="email" required>
			</p>
			<p class="form-row">
				<label for="wplc-cancel-reference"><?php esc_html_e( 'License reference or order number', 'wp-license' ); ?></label>
				<input type="text" class="input-text" id="wplc-cancel-reference" name="wplc_cancel_reference" value="<?php echo esc_attr( (string) $state['reference'] ); ?>" autocomplete="off" spellcheck="false" required>
			</p>
			<?php // Left empty by people; filled by simple bots. ?>
			<p class="wplc-cancel-website" style="position: absolute; left: -9999px;" aria-hidden="true">
				<label for="wplc-cancel-website"><?php esc_html_e( 'Leave this field empty', 'wp-license' ); ?></label>
				<input type="text" id="wplc-cancel-website" name="wplc_cancel_website" value="" tabindex="-1" autocomplete="off">
			</p>
			<button type="submit" class="button"><?php esc_html_e( 'Continue to cancellation', 'wp-license' ); ?></button>
		</form>
		<?php if ( '' !== $account_url ) : ?>
			<p class="wplc-cancel-account"><?php esc_html_e( 'With a customer account you can also cancel there:', 'wp-license' ); ?> <a href="<?php echo esc_url( $account_url ); ?>"><?php esc_html_e( 'Licenses', 'wp-license' ); ?></a></p>
		<?php endif; ?>

	<?php endif; ?>
</div>
