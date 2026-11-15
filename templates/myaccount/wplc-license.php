<?php
/**
 * Customer account: one license with its key, term, renewal, actions and sites.
 *
 * Override: yourtheme/wp-license/myaccount/wplc-license.php
 *
 * The action forms are printed through the `wplc_account_license_actions`
 * hook, the payment method of the automatic renewal through
 * `wplc_account_license_renewal`.
 *
 * @package WPLC
 *
 * @var array<string,mixed> $view Display values, see CustomerPortal::license_view(): id, license (database row), title,
 *                                plan, reference, status, status_label, status_help, key, valid_until, sites_text,
 *                                running, renewal_text, renewal_terms, pay_url, can_renew, activations, download_hint,
 *                                install_steps, docs_url.
 */

use WPLC\Account\CustomerPortal;

defined( 'ABSPATH' ) || exit;

$view = isset( $view ) && is_array( $view ) ? $view : array();
if ( empty( $view['id'] ) ) {
	return;
}

$wplc_id      = (int) $view['id'];
$wplc_license = isset( $view['license'] ) && is_array( $view['license'] ) ? $view['license'] : array();
$wplc_key_id  = 'wplc-key-' . $wplc_id;
$wplc_title   = 'wplc-license-title-' . $wplc_id;
$wplc_terms   = isset( $view['renewal_terms'] ) && is_array( $view['renewal_terms'] ) ? $view['renewal_terms'] : array();
$wplc_steps   = isset( $view['install_steps'] ) && is_array( $view['install_steps'] ) ? $view['install_steps'] : array();
$wplc_sites   = isset( $view['activations'] ) && is_array( $view['activations'] ) ? $view['activations'] : array();

/**
 * Fires before one license in the customer account.
 *
 * @param array<string,mixed> $license License row.
 * @param array<string,mixed> $view    Display values.
 */
do_action( 'wplc_before_account_license', $wplc_license, $view );
?>
<section class="wplc-license wplc-license--<?php echo esc_attr( (string) $view['status'] ); ?>" aria-labelledby="<?php echo esc_attr( $wplc_title ); ?>">
	<h3 id="<?php echo esc_attr( $wplc_title ); ?>"><?php echo esc_html( (string) $view['title'] ); ?></h3>

	<p><strong><?php esc_html_e( 'Plan:', 'wp-license' ); ?></strong> <?php echo esc_html( (string) $view['plan'] ); ?></p>
	<p><strong><?php esc_html_e( 'Status:', 'wp-license' ); ?></strong> <?php echo esc_html( (string) $view['status_label'] ); ?></p>

	<?php if ( '' !== (string) $view['status_help'] ) : ?>
		<p class="wplc-license-status-help woocommerce-info" role="note"><?php echo esc_html( (string) $view['status_help'] ); ?></p>
	<?php endif; ?>

	<?php if ( '' !== (string) $view['key'] ) : ?>
		<p class="wplc-license-key">
			<label for="<?php echo esc_attr( $wplc_key_id ); ?>"><strong><?php esc_html_e( 'License key:', 'wp-license' ); ?></strong></label>
			<input type="text" readonly id="<?php echo esc_attr( $wplc_key_id ); ?>" class="input-text" value="<?php echo esc_attr( (string) $view['key'] ); ?>" size="40" data-wplc-select spellcheck="false" autocomplete="off">
			<button type="button" class="button" data-wplc-copy="<?php echo esc_attr( $wplc_key_id ); ?>" data-wplc-copied="<?php esc_attr_e( 'Copied', 'wp-license' ); ?>" data-wplc-copy-failed="<?php esc_attr_e( 'Copying failed. Please select the key and copy it yourself.', 'wp-license' ); ?>"><?php esc_html_e( 'Copy', 'wp-license' ); ?></button>
			<?php // Announces the result of copying to screen readers; filled by wplc-ui.js. ?>
			<span class="wplc-copy-status" role="status" aria-live="polite" data-wplc-copy-status="<?php echo esc_attr( $wplc_key_id ); ?>"></span>
		</p>
	<?php else : ?>
		<p><strong><?php esc_html_e( 'License key:', 'wp-license' ); ?></strong> <?php esc_html_e( 'Currently unavailable. Please contact support.', 'wp-license' ); ?></p>
	<?php endif; ?>

	<p><strong><?php esc_html_e( 'Valid through:', 'wp-license' ); ?></strong> <?php echo esc_html( (string) $view['valid_until'] ); ?></p>
	<p><strong><?php esc_html_e( 'Live sites:', 'wp-license' ); ?></strong> <?php echo esc_html( (string) $view['sites_text'] ); ?></p>

	<?php if ( '' !== (string) $view['renewal_text'] ) : ?>
		<p><strong><?php esc_html_e( 'Renewal:', 'wp-license' ); ?></strong> <?php echo esc_html( (string) $view['renewal_text'] ); ?></p>
	<?php endif; ?>

	<?php if ( $wplc_terms ) : ?>
		<?php // Date and amount (including tax) of the next automatic charge. ?>
		<dl class="wplc-renewal-terms">
			<?php foreach ( $wplc_terms as $wplc_label => $wplc_value ) : ?>
				<dt><?php echo esc_html( (string) $wplc_label ); ?></dt>
				<dd><?php echo esc_html( (string) $wplc_value ); ?></dd>
			<?php endforeach; ?>
		</dl>
	<?php endif; ?>

	<?php
	/**
	 * Fires below the renewal information of one license: payment method of
	 * the automatic renewal and the form to switch automatic renewal on.
	 *
	 * @param array<string,mixed> $license License row.
	 * @param array<string,mixed> $view    Display values.
	 */
	do_action( 'wplc_account_license_renewal', $wplc_license, $view );
	?>

	<?php if ( '' !== (string) $view['pay_url'] ) : ?>
		<p><a class="button" href="<?php echo esc_url( (string) $view['pay_url'] ); ?>"><?php esc_html_e( 'Complete renewal payment', 'wp-license' ); ?></a></p>
		<p><?php esc_html_e( 'Your payment provider may ask you to authenticate the payment before the renewal can be completed.', 'wp-license' ); ?></p>
	<?php endif; ?>

	<div class="wplc-license-actions">
		<?php
		/**
		 * Prints the action forms of one license: renew, download, cancel, undo cancellation.
		 *
		 * @param array<string,mixed> $license License row.
		 * @param array<string,mixed> $view    Display values.
		 */
		do_action( 'wplc_account_license_actions', $wplc_license, $view );
		?>
	</div>

	<?php if ( '' !== (string) $view['download_hint'] ) : ?>
		<p class="wplc-license-download-hint"><?php echo esc_html( (string) $view['download_hint'] ); ?></p>
	<?php endif; ?>

	<?php if ( $wplc_steps ) : ?>
		<details class="wplc-install-steps">
			<summary><?php esc_html_e( 'How to install and activate', 'wp-license' ); ?></summary>
			<ol>
				<?php foreach ( $wplc_steps as $wplc_step ) : ?>
					<li><?php echo esc_html( (string) $wplc_step ); ?></li>
				<?php endforeach; ?>
			</ol>
			<?php if ( '' !== (string) $view['docs_url'] ) : ?>
				<p><a href="<?php echo esc_url( (string) $view['docs_url'] ); ?>"><?php esc_html_e( 'Read the documentation', 'wp-license' ); ?></a></p>
			<?php endif; ?>
		</details>
	<?php endif; ?>

	<h4><?php esc_html_e( 'Sites', 'wp-license' ); ?></h4>
	<?php if ( ! $wplc_sites ) : ?>
		<p><?php esc_html_e( 'No sites are activated.', 'wp-license' ); ?></p>
	<?php else : ?>
		<table class="shop_table shop_table_responsive">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Site', 'wp-license' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Environment', 'wp-license' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Activated', 'wp-license' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'wp-license' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $wplc_sites as $wplc_site ) : ?>
					<tr>
						<td data-title="<?php esc_attr_e( 'Site', 'wp-license' ); ?>"><?php echo esc_html( (string) $wplc_site['site_url'] ); ?></td>
						<td data-title="<?php esc_attr_e( 'Environment', 'wp-license' ); ?>"><?php echo esc_html( (string) $wplc_site['environment'] ); ?></td>
						<td data-title="<?php esc_attr_e( 'Activated', 'wp-license' ); ?>"><?php echo esc_html( (string) $wplc_site['activated'] ); ?></td>
						<td data-title="<?php esc_attr_e( 'Actions', 'wp-license' ); ?>"><?php CustomerPortal::render_deactivate_form( (int) $wplc_site['id'], (string) $wplc_site['site_url'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>
<?php
/**
 * Fires after one license in the customer account.
 *
 * @param array<string,mixed> $license License row.
 * @param array<string,mixed> $view    Display values.
 */
do_action( 'wplc_after_account_license', $wplc_license, $view );
