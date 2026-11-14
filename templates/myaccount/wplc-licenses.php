<?php
/**
 * Customer account: list of the customer's licenses.
 *
 * Override: yourtheme/wp-license/myaccount/wplc-licenses.php
 *
 * @package WPLC
 *
 * @var array<int,array<string,mixed>> $licenses Display values per license, see CustomerPortal::license_view().
 * @var int                            $total    Number of licenses of the customer.
 * @var int                            $page     Current page.
 * @var int                            $pages    Number of pages.
 * @var string                         $prev_url URL of the previous page, empty on the first page.
 * @var string                         $next_url URL of the next page, empty on the last page.
 */

defined( 'ABSPATH' ) || exit;

$licenses = isset( $licenses ) && is_array( $licenses ) ? $licenses : array();
$page     = isset( $page ) ? (int) $page : 1;
$pages    = isset( $pages ) ? (int) $pages : 1;
$prev_url = isset( $prev_url ) ? (string) $prev_url : '';
$next_url = isset( $next_url ) ? (string) $next_url : '';

/**
 * Fires before the license list in the customer account.
 *
 * @param array<int,array<string,mixed>> $licenses Display values per license.
 */
do_action( 'wplc_before_account_licenses', $licenses );
?>
<div class="wplc-licenses">
	<h2><?php esc_html_e( 'Your licenses', 'wp-license' ); ?></h2>

	<?php if ( ! $licenses ) : ?>
		<p><?php esc_html_e( 'No licenses are associated with this account.', 'wp-license' ); ?></p>
	<?php endif; ?>

	<?php
	foreach ( $licenses as $wplc_license_view ) {
		wc_get_template(
			'myaccount/wplc-license.php',
			array( 'view' => $wplc_license_view ),
			'wp-license/',
			WPLC_PATH . 'templates/'
		);
	}
	?>

	<?php if ( $pages > 1 ) : ?>
		<nav class="woocommerce-pagination wplc-pagination" aria-label="<?php esc_attr_e( 'License pages', 'wp-license' ); ?>">
			<?php if ( '' !== $prev_url ) : ?>
				<a class="woocommerce-button woocommerce-button--previous button" href="<?php echo esc_url( $prev_url ); ?>"><?php esc_html_e( 'Previous', 'wp-license' ); ?></a>
			<?php else : ?>
				<span></span>
			<?php endif; ?>
			<span>
				<?php
				/* translators: 1: current page, 2: number of pages. */
				echo esc_html( sprintf( __( 'Page %1$d of %2$d', 'wp-license' ), $page, $pages ) );
				?>
			</span>
			<?php if ( '' !== $next_url ) : ?>
				<a class="woocommerce-button woocommerce-button--next button" href="<?php echo esc_url( $next_url ); ?>"><?php esc_html_e( 'Next', 'wp-license' ); ?></a>
			<?php else : ?>
				<span></span>
			<?php endif; ?>
		</nav>
	<?php endif; ?>
</div>
<?php
/**
 * Fires after the license list in the customer account.
 *
 * @param array<int,array<string,mixed>> $licenses Display values per license.
 */
do_action( 'wplc_after_account_licenses', $licenses );
