<?php
/**
 * Settings → <Product> license.
 *
 * @package Vendor\Product\Premium
 */

namespace Vendor\Product\Premium\Admin;

use Vendor\Product\Premium\License\LicenseService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * License screen: activate, check, deactivate, environment (production / staging with live parent / development).
 *
 * Rendering never calls the license server (the page shows the last verified snapshot); network calls only happen
 * on a submitted form. Every form is nonce-protected and needs manage_options.
 */
final class SettingsPage {
	public const CAPABILITY = 'manage_options';

	/** @var LicenseService */
	private $license;
	/** @var string */
	private $prefix;
	/** @var string */
	private $title;

	/**
	 * @param LicenseService $license License service.
	 * @param string         $prefix  Option/hook prefix.
	 * @param string         $title   Product name, e.g. "Voucher Captain Pro".
	 */
	public function __construct( LicenseService $license, string $prefix, string $title ) {
		$this->license = $license;
		$this->prefix  = $prefix;
		$this->title   = $title;
	}

	/** Register menu and form handler. */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_' . $this->action(), array( $this, 'handle' ) );
	}

	/** Page slug. */
	public function page_slug(): string {
		return str_replace( '_', '-', $this->prefix ) . '-license';
	}

	/** admin-post action and nonce action. */
	public function action(): string {
		return $this->prefix . '_license';
	}

	/** Add the page below Settings. */
	public function add_menu(): void {
		add_options_page(
			/* translators: %s: product name. */
			sprintf( __( '%s license', 'premium-addon-starter' ), $this->title ),
			/* translators: %s: product name. */
			sprintf( __( '%s license', 'premium-addon-starter' ), $this->title ),
			self::CAPABILITY,
			$this->page_slug(),
			array( $this, 'render' )
		);
	}

	/** admin-post handler: capability, nonce, process, redirect back. */
	public function handle(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage the license.', 'premium-addon-starter' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $this->action() );
		$notice = $this->process( wp_unslash( $_POST ) );
		set_transient( $this->notice_key(), $notice, 120 );
		wp_safe_redirect( admin_url( 'options-general.php?page=' . $this->page_slug() ) );
		exit;
	}

	/**
	 * Run one form operation. Caller has checked capability and nonce.
	 *
	 * @param array<string,mixed> $input Unslashed POST data.
	 * @return array{type:string,message:string}
	 */
	public function process( array $input ): array {
		$operation = sanitize_key( (string) ( $input['operation'] ?? '' ) );
		switch ( $operation ) {
			case 'activate':
				$result = $this->license->activate(
					sanitize_text_field( (string) ( $input['license_key'] ?? '' ) ),
					sanitize_key( (string) ( $input['environment'] ?? 'production' ) ),
					sanitize_text_field( (string) ( $input['staging_parent'] ?? '' ) )
				);
				return $this->notice( $result, __( 'The license is active on this site.', 'premium-addon-starter' ) );
			case 'refresh':
				return $this->notice( $this->license->refresh(), __( 'The license status was updated.', 'premium-addon-starter' ) );
			case 'deactivate':
				return $this->notice( $this->license->deactivate(), __( 'The license was removed from this site.', 'premium-addon-starter' ) );
		}
		return array( 'type' => 'error', 'message' => __( 'Unknown action.', 'premium-addon-starter' ) );
	}

	/** Render the page. */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$notice = get_transient( $this->notice_key() );
		delete_transient( $this->notice_key() );
		$state    = $this->license->state();
		$data     = $this->license->snapshot();
		$problem  = $this->license->cache_problem();
		$features = array();
		foreach ( (array) ( $data['entitlements'] ?? array() ) as $key => $value ) {
			if ( $this->license->has( (string) $key ) ) {
				$features[] = true === $value ? (string) $key : $key . ' = ' . wp_json_encode( $value );
			}
		}

		echo '<div class="wrap"><h1>' . esc_html( get_admin_page_title() ) . '</h1>';
		if ( is_array( $notice ) && isset( $notice['type'], $notice['message'] ) ) {
			printf( '<div class="notice notice-%1$s"><p>%2$s</p></div>', esc_attr( 'success' === $notice['type'] ? 'success' : 'error' ), esc_html( (string) $notice['message'] ) );
		}
		if ( ! $this->license->is_configured() ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'This build has no pinned license server key, so it cannot be activated. Premium features are off.', 'premium-addon-starter' ) . '</p></div></div>';
			return;
		}

		if ( $this->license->is_activated() ) {
			$rows = array(
				__( 'Status', 'premium-addon-starter' )          => is_array( $data ) ? (string) ( $data['license_status'] ?? '' ) : $this->problem_text( $problem ),
				__( 'License key', 'premium-addon-starter' )     => '…' . (string) ( $state['key_hint'] ?? '' ),
				__( 'Valid until', 'premium-addon-starter' )     => is_array( $data ) ? (string) ( $data['paid_until'] ?? __( 'no end date', 'premium-addon-starter' ) ) : '',
				__( 'Sites', 'premium-addon-starter' )           => is_array( $data ) ? sprintf( '%d / %d', (int) ( $data['site_count'] ?? 0 ), (int) ( $data['site_limit'] ?? 0 ) ) : '',
				__( 'Plan features', 'premium-addon-starter' )   => implode( ', ', $features ),
				__( 'Environment', 'premium-addon-starter' )     => (string) ( $state['environment'] ?? '' ),
				__( 'Installation ID', 'premium-addon-starter' ) => $this->license->installation_id(),
				__( 'Last check', 'premium-addon-starter' )      => empty( $state['checked_at'] ) ? '' : gmdate( 'Y-m-d H:i', (int) $state['checked_at'] ) . ' UTC',
				__( 'Last error', 'premium-addon-starter' )      => (string) ( $state['last_error'] ?? '' ),
			);
			echo '<table class="form-table" role="presentation"><tbody>';
			foreach ( $rows as $label => $value ) {
				printf( '<tr><th scope="row">%1$s</th><td>%2$s</td></tr>', esc_html( $label ), esc_html( $value ) );
			}
			echo '</tbody></table>';
			echo '<p class="description">' . esc_html__( 'A staging site of this live site needs the installation ID above.', 'premium-addon-starter' ) . '</p>';
			$this->button_form( 'refresh', __( 'Check now', 'premium-addon-starter' ), 'button' );
			$this->button_form( 'deactivate', __( 'Deactivate on this site', 'premium-addon-starter' ), 'button button-secondary' );
			echo '<h2>' . esc_html__( 'Use another license key', 'premium-addon-starter' ) . '</h2>';
		}
		$this->activation_form();
		echo '</div>';
	}

	/** Activation form with environment selector. */
	private function activation_form(): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( $this->action() );
		echo '<input type="hidden" name="action" value="' . esc_attr( $this->action() ) . '">';
		echo '<input type="hidden" name="operation" value="activate">';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="' . esc_attr( $this->prefix ) . '-key">' . esc_html__( 'License key', 'premium-addon-starter' ) . '</label></th>';
		echo '<td><input type="text" class="regular-text code" autocomplete="off" id="' . esc_attr( $this->prefix ) . '-key" name="license_key" value="" required></td></tr>';
		echo '<tr><th scope="row"><label for="' . esc_attr( $this->prefix ) . '-env">' . esc_html__( 'Environment', 'premium-addon-starter' ) . '</label></th><td>';
		echo '<select id="' . esc_attr( $this->prefix ) . '-env" name="environment">';
		$labels = array(
			'production'  => __( 'Production (live site, uses a site slot)', 'premium-addon-starter' ),
			'staging'     => __( 'Staging (linked to a live site, no extra slot)', 'premium-addon-starter' ),
			'development' => __( 'Development (local URL only, no slot)', 'premium-addon-starter' ),
		);
		foreach ( $labels as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select></td></tr>';
		echo '<tr><th scope="row"><label for="' . esc_attr( $this->prefix ) . '-parent">' . esc_html__( 'Live site installation ID (staging only)', 'premium-addon-starter' ) . '</label></th>';
		echo '<td><input type="text" class="regular-text code" id="' . esc_attr( $this->prefix ) . '-parent" name="staging_parent" value=""></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Activate', 'premium-addon-starter' ) );
		echo '</form>';
	}

	/**
	 * Small form with one button.
	 *
	 * @param string $operation Operation.
	 * @param string $label     Button label.
	 * @param string $classes   Button classes.
	 */
	private function button_form( string $operation, string $label, string $classes ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:8px">';
		wp_nonce_field( $this->action() );
		echo '<input type="hidden" name="action" value="' . esc_attr( $this->action() ) . '">';
		echo '<input type="hidden" name="operation" value="' . esc_attr( $operation ) . '">';
		echo '<button type="submit" class="' . esc_attr( $classes ) . '">' . esc_html( $label ) . '</button></form>';
	}

	/**
	 * Notice from a service result.
	 *
	 * @param mixed  $result  Result.
	 * @param string $success Success message.
	 * @return array{type:string,message:string}
	 */
	private function notice( $result, string $success ): array {
		if ( is_wp_error( $result ) ) {
			return array( 'type' => 'error', 'message' => $result->get_error_message() );
		}
		return array( 'type' => 'success', 'message' => $success );
	}

	/**
	 * Explanation when no valid snapshot exists.
	 *
	 * @param string $problem cache_problem() value.
	 */
	private function problem_text( string $problem ): string {
		$texts = array(
			'missing' => __( 'not checked yet – premium features are off', 'premium-addon-starter' ),
			'invalid' => __( 'stored status failed verification – premium features are off', 'premium-addon-starter' ),
			'stale'   => __( 'not confirmed by the license server for 14 days – premium features are off', 'premium-addon-starter' ),
			'site_changed' => __( 'this site runs under another address than the one the license was activated for (a copy or a moved site) – premium features are off; activate the license for this address', 'premium-addon-starter' ),
		);
		return $texts[ $problem ] ?? __( 'unknown – premium features are off', 'premium-addon-starter' );
	}

	/** Per-user transient for the result notice. */
	private function notice_key(): string {
		return $this->prefix . '_license_notice_' . get_current_user_id();
	}
}
