<?php
/**
 * Admin screen of the outgoing webhooks.
 *
 * @package WPLC
 */

namespace WPLC\Webhooks;

use WPLC\Admin\Capabilities;
use WPLC\Admin\Format;
use WPLC\Admin\LicenseAdmin;
use WPLC\Security\SecretCrypto;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce → License webhooks: endpoints (target URL, events, on/off), the secret shown once,
 * a test event, a new secret, the delivery log with "send again", and the notice about endpoints
 * that were switched off after failures. Needs `wplc_manage_policy` (by default administrators):
 * a webhook sends license events of every customer to another system.
 */
final class Admin {

	public const PAGE         = 'wplc-webhooks';
	public const ACTION       = 'wplc_webhook';
	public const NONCE_ACTION = 'wplc_webhook';

	/** Seconds a newly generated secret stays available for the one display. */
	private const SECRET_TTL = 600;

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ), 16 );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
		add_action( 'admin_notices', array( self::class, 'render_disabled_notice' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
	}

	/**
	 * Admin stylesheet on this screen: its tables scroll inside themselves on narrow screens.
	 *
	 * @return void
	 */
	public static function enqueue_assets(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Screen detection only.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::PAGE !== $page ) {
			return;
		}
		wp_enqueue_style( 'wplc-admin', plugins_url( 'assets/css/wplc-admin.css', WPLC_FILE ), array(), \WPLC\Account\CustomerPortal::asset_version( 'assets/css/wplc-admin.css' ) );
	}

	/**
	 * Capability of the screen and its actions.
	 *
	 * @return string
	 */
	public static function capability(): string {
		return Capabilities::cap( 'manage_policy' );
	}

	/**
	 * Add the screen below the license menu.
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		add_submenu_page(
			Capabilities::menu_parent(),
			__( 'License webhooks', 'wp-license' ),
			__( 'License webhooks', 'wp-license' ),
			self::capability(),
			self::PAGE,
			array( self::class, 'render' )
		);
		LicenseAdmin::connect_page( self::PAGE, __( 'License webhooks', 'wp-license' ) );
	}

	/**
	 * URL of the screen.
	 *
	 * @param array<string,scalar> $args Extra query arguments.
	 * @return string
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Notice on every admin screen while an endpoint is off after failures.
	 *
	 * @return void
	 */
	public static function render_disabled_notice(): void {
		// Capability first: other users never read the option.
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}
		$ids = Endpoints::auto_disabled_ids();
		if ( ! $ids ) {
			return;
		}
		$names = array();
		foreach ( $ids as $id ) {
			$endpoint = Endpoints::get( $id );
			if ( null !== $endpoint ) {
				$names[] = '' !== $endpoint['name'] ? $endpoint['name'] : UrlGuard::host( $endpoint['url'] );
			}
		}
		if ( ! $names ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>';
		echo esc_html(
			sprintf(
				/* translators: %s: names of webhook endpoints, separated by commas. */
				_n(
					'A license webhook was switched off because its deliveries failed repeatedly: %s. Check the receiver, then switch it on again.',
					'License webhooks were switched off because their deliveries failed repeatedly: %s. Check the receivers, then switch them on again.',
					count( $names ),
					'wp-license'
				),
				implode( ', ', $names )
			)
		);
		echo ' <a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Open the webhooks', 'wp-license' ) . '</a></p></div>';
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to manage webhooks.', 'wp-license' ), '', array( 'response' => 403 ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- screen state only.
		$edit_id = isset( $_GET['edit'] ) ? absint( wp_unslash( $_GET['edit'] ) ) : 0;
		$log_id  = isset( $_GET['log'] ) ? absint( wp_unslash( $_GET['log'] ) ) : 0;
		// phpcs:enable

		echo '<div class="wrap wplc-webhooks"><h1>' . esc_html__( 'License webhooks', 'wp-license' ) . '</h1>';
		self::render_result();
		echo '<p>' . esc_html__( 'A webhook sends license events of this shop to another system as a signed HTTPS request: a new license, a status change, a new plan, a new or removed site, a reached site limit, a published or withdrawn release. The request carries record IDs and codes only, never a license key, a site address, an email address or payment data. Each request is signed with the secret of the endpoint; failed requests are tried again up to five times.', 'wp-license' ) . '</p>';

		$endpoints = Endpoints::all();
		if ( $endpoints ) {
			self::render_table( $endpoints );
		} else {
			echo '<p><strong>' . esc_html__( 'No webhook endpoint yet. Nothing is sent until one is added.', 'wp-license' ) . '</strong></p>';
		}

		$editing = $edit_id > 0 ? Endpoints::get( $edit_id ) : null;
		self::render_form( $editing );

		$log_endpoint = $log_id > 0 ? Endpoints::get( $log_id ) : null;
		if ( null !== $log_endpoint ) {
			self::render_log( $log_endpoint );
		}
		echo '</div>';
	}

	/**
	 * List of endpoints.
	 *
	 * @param array<int,array<string,mixed>> $endpoints Endpoints.
	 * @return void
	 */
	private static function render_table( array $endpoints ): void {
		echo '<h2>' . esc_html__( 'Endpoints', 'wp-license' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Name', 'wp-license' ), __( 'Target URL', 'wp-license' ), __( 'Events', 'wp-license' ), __( 'Status', 'wp-license' ), __( 'Last delivery', 'wp-license' ), __( 'Actions', 'wp-license' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $endpoints as $endpoint ) {
			$id      = (int) $endpoint['id'];
			$context = self::endpoint_label( $endpoint );
			echo '<tr><td>' . esc_html( '' !== $endpoint['name'] ? (string) $endpoint['name'] : '—' ) . '</td>';
			echo '<td><code>' . esc_html( (string) $endpoint['url'] ) . '</code></td>';
			echo '<td>' . esc_html( implode( ', ', (array) $endpoint['events'] ) ) . '</td><td>';
			if ( $endpoint['active'] ) {
				echo esc_html__( 'On', 'wp-license' );
			} elseif ( Endpoints::REASON_FAILURES === $endpoint['disabled_reason'] ) {
				echo '<strong>' . esc_html__( 'Off after repeated failures', 'wp-license' ) . '</strong>';
			} else {
				echo esc_html__( 'Off', 'wp-license' );
			}
			if ( (int) $endpoint['consecutive_failures'] > 0 ) {
				/* translators: %d: number of failed deliveries in a row. */
				echo '<br>' . esc_html( sprintf( _n( '%d failed delivery in a row', '%d failed deliveries in a row', (int) $endpoint['consecutive_failures'], 'wp-license' ), (int) $endpoint['consecutive_failures'] ) );
			}
			echo '</td><td>';
			/* translators: %s: date and time. */
			echo esc_html( sprintf( __( 'Success: %s', 'wp-license' ), '' !== $endpoint['last_success_at'] ? Format::date( (string) $endpoint['last_success_at'], true ) : '—' ) ) . '<br>';
			/* translators: %s: date and time. */
			echo esc_html( sprintf( __( 'Failure: %s', 'wp-license' ), '' !== $endpoint['last_failure_at'] ? Format::date( (string) $endpoint['last_failure_at'], true ) : '—' ) );
			echo '</td><td>';
			echo '<a class="button button-secondary" href="' . esc_url( self::url( array( 'edit' => $id ) ) ) . '">' . esc_html__( 'Edit', 'wp-license' ) . self::context_suffix( $context ) . '</a> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- context_suffix() returns escaped HTML.
			echo '<a class="button button-secondary" href="' . esc_url( self::url( array( 'log' => $id ) ) ) . '">' . esc_html__( 'Delivery log', 'wp-license' ) . self::context_suffix( $context ) . '</a> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- context_suffix() returns escaped HTML.
			self::action_button( 'test', $id, __( 'Send test event', 'wp-license' ), '', 'endpoint_id', $context );
			self::action_button( $endpoint['active'] ? 'disable' : 'enable', $id, $endpoint['active'] ? __( 'Switch off', 'wp-license' ) : __( 'Switch on', 'wp-license' ), '', 'endpoint_id', $context );
			self::action_button( 'rotate', $id, __( 'Generate new secret', 'wp-license' ), __( 'The old secret stops working at once', 'wp-license' ), 'endpoint_id', $context );
			self::action_button( 'delete', $id, __( 'Delete', 'wp-license' ), __( 'Delete the endpoint and its delivery log', 'wp-license' ), 'endpoint_id', $context );
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * A small form with one button; with a confirmation box when $confirm is given.
	 *
	 * @param string $operation   Operation.
	 * @param int    $id          Endpoint or delivery ID.
	 * @param string $label       Button label.
	 * @param string $confirm     Text of the required confirmation box, '' for none.
	 * @param string $id_field    Name of the ID field.
	 * @param string $context     Row the button belongs to (endpoint name or host), read to screen readers only.
	 * @return void
	 */
	private static function action_button( string $operation, int $id, string $label, string $confirm = '', string $id_field = 'endpoint_id', string $context = '' ): void {
		echo '<form method="post" class="wplc-inline-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		echo '<input type="hidden" name="operation" value="' . esc_attr( $operation ) . '">';
		echo '<input type="hidden" name="' . esc_attr( $id_field ) . '" value="' . esc_attr( (string) $id ) . '">';
		wp_nonce_field( self::NONCE_ACTION );
		if ( '' !== $confirm ) {
			$box = 'wplc-webhook-' . $operation . '-' . $id;
			echo '<label for="' . esc_attr( $box ) . '"><input type="checkbox" id="' . esc_attr( $box ) . '" name="confirm" value="1" required> ' . esc_html( $confirm ) . self::context_suffix( $context ) . '</label> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- context_suffix() returns escaped HTML.
		}
		echo '<button class="button ' . ( 'delete' === $operation ? 'button-link-delete' : 'button-secondary' ) . '" type="submit">' . esc_html( $label ) . self::context_suffix( $context ) . '</button></form> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- context_suffix() returns escaped HTML.
	}

	/**
	 * Name of an endpoint for screen texts: its label, else the host of its URL.
	 *
	 * @param array<string,mixed> $endpoint Endpoint.
	 * @return string
	 */
	private static function endpoint_label( array $endpoint ): string {
		return '' !== (string) $endpoint['name'] ? (string) $endpoint['name'] : UrlGuard::host( (string) $endpoint['url'] );
	}

	/**
	 * Hidden ": <row>" suffix so a button or box says which row it acts on (escaped HTML).
	 *
	 * @param string $context Endpoint name, host or delivery; '' for none.
	 * @return string
	 */
	private static function context_suffix( string $context ): string {
		return '' === $context ? '' : '<span class="screen-reader-text">: ' . esc_html( $context ) . '</span>';
	}

	/**
	 * Form to add or change an endpoint.
	 *
	 * @param array<string,mixed>|null $endpoint Endpoint to change, null to add one.
	 * @return void
	 */
	private static function render_form( ?array $endpoint ): void {
		$editing = null !== $endpoint;
		$values  = array(
			'name'   => $editing ? (string) $endpoint['name'] : '',
			'url'    => $editing ? (string) $endpoint['url'] : '',
			'events' => $editing ? (array) $endpoint['events'] : array(),
			'active' => ! $editing || (bool) $endpoint['active'],
		);
		$error   = '';
		$kept    = get_transient( self::form_key() );
		if ( is_array( $kept ) ) {
			delete_transient( self::form_key() );
			// Only for the same form: the endpoint being changed, or the empty add form.
			if ( (int) ( $kept['endpoint_id'] ?? 0 ) === ( $editing ? (int) $endpoint['id'] : 0 ) ) {
				$values = array(
					'name'   => (string) ( $kept['name'] ?? '' ),
					'url'    => (string) ( $kept['url'] ?? '' ),
					'events' => array_map( 'strval', (array) ( $kept['events'] ?? array() ) ),
					'active' => ! empty( $kept['active'] ),
				);
				$error  = (string) ( $kept['error'] ?? '' );
			}
		}
		$field     = self::error_field( $error );
		$url_error = 'url' === $field ? '<p class="wplc-field-error" id="wplc-webhook-url-error"><strong>' . esc_html( self::message( $error ) ) . '</strong></p>' : '';
		$ev_error  = 'events' === $field ? '<p class="wplc-field-error" id="wplc-webhook-events-error"><strong>' . esc_html( self::message( $error ) ) . '</strong></p>' : '';
		echo '<h2>' . esc_html( $editing ? __( 'Change endpoint', 'wp-license' ) : __( 'Add endpoint', 'wp-license' ) ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		echo '<input type="hidden" name="operation" value="save">';
		if ( $editing ) {
			echo '<input type="hidden" name="endpoint_id" value="' . esc_attr( (string) $endpoint['id'] ) . '">';
		}
		wp_nonce_field( self::NONCE_ACTION );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="wplc-webhook-name">' . esc_html__( 'Name', 'wp-license' ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" id="wplc-webhook-name" name="name" maxlength="190" value="' . esc_attr( $values['name'] ) . '" aria-describedby="wplc-webhook-name-help">';
		echo '<p class="description" id="wplc-webhook-name-help">' . esc_html__( 'Optional label, for example the receiving system.', 'wp-license' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="wplc-webhook-url">' . esc_html__( 'Target URL', 'wp-license' ) . '</label></th><td>';
		echo '<input type="url" class="large-text" id="wplc-webhook-url" name="url" required maxlength="' . esc_attr( (string) UrlGuard::MAX_LENGTH ) . '" placeholder="https://" value="' . esc_attr( $values['url'] ) . '"' . ( '' !== $url_error ? ' aria-invalid="true" aria-describedby="wplc-webhook-url-error wplc-webhook-url-help"' : ' aria-describedby="wplc-webhook-url-help"' ) . '>';
		echo $url_error; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
		echo '<p class="description" id="wplc-webhook-url-help">' . esc_html__( 'Only https:// on port 443. Addresses in a private or local network (localhost, 10.x, 192.168.x, link-local and the like) are refused, also when a host name points there; the address is checked again before every request. Redirects are not followed.', 'wp-license' ) . '</p></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Events', 'wp-license' ) . '</th><td><fieldset' . ( '' !== $ev_error ? ' aria-describedby="wplc-webhook-events-error"' : '' ) . '><legend class="screen-reader-text">' . esc_html__( 'Events', 'wp-license' ) . '</legend>';
		echo $ev_error; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
		$selected = $values['events'];
		foreach ( EventCatalog::names() as $event ) {
			$box = 'wplc-webhook-event-' . str_replace( '.', '-', $event );
			echo '<label for="' . esc_attr( $box ) . '"><input type="checkbox" id="' . esc_attr( $box ) . '" name="events[]" value="' . esc_attr( $event ) . '"' . checked( in_array( $event, $selected, true ), true, false ) . '> <code>' . esc_html( $event ) . '</code> – ' . esc_html( self::event_label( $event ) ) . '</label><br>';
		}
		echo '<p class="description">' . esc_html__( 'Choose at least one event.', 'wp-license' ) . '</p></fieldset></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Active', 'wp-license' ) . '</th><td><label for="wplc-webhook-active"><input type="checkbox" id="wplc-webhook-active" name="active" value="1"' . checked( $values['active'], true, false ) . '> ' . esc_html__( 'Send events to this endpoint', 'wp-license' ) . '</label></td></tr>';
		echo '</tbody></table>';
		if ( ! $editing ) {
			echo '<p>' . esc_html__( 'A secret is generated when the endpoint is saved. It is shown once; copy it into the receiving system to check the signature.', 'wp-license' ) . '</p>';
		}
		echo '<p><button class="button button-primary" type="submit">' . esc_html( $editing ? __( 'Save endpoint', 'wp-license' ) : __( 'Add endpoint', 'wp-license' ) ) . '</button>';
		if ( $editing ) {
			echo ' <a class="button button-secondary" href="' . esc_url( self::url() ) . '">' . esc_html__( 'Cancel', 'wp-license' ) . '</a>';
		}
		echo '</p></form>';
	}

	/**
	 * Form field a save error belongs to: 'url', 'events' or '' (shown in the notice only).
	 *
	 * @param string $error Error code of Endpoints::create() / update().
	 * @return string
	 */
	public static function error_field( string $error ): string {
		if ( 'no_events' === $error ) {
			return 'events';
		}
		$url_errors = array( UrlGuard::INVALID, UrlGuard::NOT_HTTPS, UrlGuard::CREDENTIALS, UrlGuard::PORT, UrlGuard::LOCAL_HOST, UrlGuard::BLOCKED_ADDRESS, UrlGuard::UNRESOLVED, UrlGuard::TRAILING_DOT );

		return in_array( $error, $url_errors, true ) ? 'url' : '';
	}

	/**
	 * Delivery log of one endpoint.
	 *
	 * @param array<string,mixed> $endpoint Endpoint.
	 * @return void
	 */
	private static function render_log( array $endpoint ): void {
		$label = self::endpoint_label( $endpoint );
		/* translators: %s: name of the webhook endpoint. */
		echo '<h2 id="wplc-webhook-log">' . esc_html( sprintf( __( 'Delivery log: %s', 'wp-license' ), $label ) ) . '</h2>';
		/* translators: %d: number of deliveries kept. */
		echo '<p>' . esc_html( sprintf( __( 'The newest %d deliveries are kept; older ones are removed by the retention period "Keep the webhook delivery log for" (License settings → Legal and privacy).', 'wp-license' ), Deliveries::KEEP_PER_ENDPOINT ) ) . '</p>';
		$rows = Deliveries::for_endpoint( (int) $endpoint['id'], 50 );
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No delivery yet.', 'wp-license' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Time', 'wp-license' ), __( 'Event', 'wp-license' ), __( 'Event ID', 'wp-license' ), __( 'Status', 'wp-license' ), __( 'Attempt', 'wp-license' ), __( 'HTTP status', 'wp-license' ), __( 'Duration', 'wp-license' ), __( 'Error', 'wp-license' ), __( 'Actions', 'wp-license' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td>' . esc_html( Format::date( (string) $row['created_at'], true ) ) . '</td>';
			echo '<td><code>' . esc_html( (string) $row['event'] ) . '</code></td>';
			echo '<td><code>' . esc_html( (string) $row['event_id'] ) . '</code></td>';
			echo '<td>' . esc_html( self::status_label( (string) $row['status'] ) );
			if ( Deliveries::RETRYING === $row['status'] && '' !== $row['next_attempt_at'] ) {
				/* translators: %s: date and time. */
				echo '<br>' . esc_html( sprintf( __( 'next attempt %s', 'wp-license' ), Format::date( (string) $row['next_attempt_at'], true ) ) );
			}
			echo '</td><td>' . esc_html( (string) $row['attempts'] ) . '</td>';
			echo '<td>' . esc_html( $row['response_code'] > 0 ? (string) $row['response_code'] : '—' ) . '</td>';
			/* translators: %d: milliseconds. */
			echo '<td>' . esc_html( sprintf( __( '%d ms', 'wp-license' ), (int) $row['duration_ms'] ) ) . '</td>';
			echo '<td>' . esc_html( '' !== $row['error'] ? self::delivery_error( (string) $row['error'] ) : '—' ) . '</td><td>';
			if ( in_array( $row['status'], Deliveries::FINISHED, true ) && EventCatalog::TEST_EVENT !== $row['event'] ) {
				self::action_button( 'redeliver', (int) $row['id'], __( 'Send again', 'wp-license' ), '', 'delivery_id', (string) $row['event'] . ' ' . (string) $row['event_id'] );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Handle every action of the screen (admin-post.php).
	 *
	 * @return void
	 */
	public static function handle(): void {
		Capabilities::require_cap( 'manage_policy', __( 'You do not have permission to manage webhooks.', 'wp-license' ) );
		check_admin_referer( self::NONCE_ACTION );

		$operation   = isset( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
		$endpoint_id = isset( $_POST['endpoint_id'] ) ? absint( wp_unslash( $_POST['endpoint_id'] ) ) : 0;
		$user_id     = (int) get_current_user_id();
		$args        = array();
		$result      = array( 'type' => 'error', 'code' => 'unknown_operation' );

		switch ( $operation ) {
			case 'save':
				$name   = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
				$url    = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
				$events = isset( $_POST['events'] ) && is_array( $_POST['events'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['events'] ) ) : array();
				$active = ! empty( $_POST['active'] );
				$saved  = $endpoint_id > 0
					? Endpoints::update( $endpoint_id, $name, $url, $events, $active, $user_id )
					: Endpoints::create( $name, $url, $events, $active, $user_id );
				$result = $saved['ok'] ? array( 'type' => 'success', 'code' => $endpoint_id > 0 ? 'updated' : 'created' ) : array( 'type' => 'error', 'code' => $saved['error'] );
				if ( '' !== $saved['secret'] ) {
					self::keep_secret( $saved['secret'], (int) $saved['id'] );
				}
				if ( ! $saved['ok'] ) {
					// Keep what was typed for the form after the redirect (this user only, briefly).
					set_transient(
						self::form_key(),
						array(
							'endpoint_id' => $endpoint_id,
							'name'        => $name,
							'url'         => $url,
							'events'      => array_values( array_intersect( EventCatalog::names(), $events ) ),
							'active'      => $active,
							'error'       => (string) $saved['error'],
						),
						5 * MINUTE_IN_SECONDS
					);
					if ( $endpoint_id > 0 ) {
						$args['edit'] = $endpoint_id;
					}
				}
				break;
			case 'enable':
			case 'disable':
				$saved  = Endpoints::set_active( $endpoint_id, 'enable' === $operation, $user_id );
				$result = $saved['ok'] ? array( 'type' => 'success', 'code' => $operation . 'd' ) : array( 'type' => 'error', 'code' => $saved['error'] );
				break;
			case 'rotate':
				if ( empty( $_POST['confirm'] ) ) {
					$result = array( 'type' => 'error', 'code' => 'confirm' );
					break;
				}
				$saved  = Endpoints::rotate_secret( $endpoint_id, $user_id );
				$result = $saved['ok'] ? array( 'type' => 'success', 'code' => 'rotated' ) : array( 'type' => 'error', 'code' => $saved['error'] );
				if ( '' !== $saved['secret'] ) {
					self::keep_secret( $saved['secret'], $endpoint_id );
				}
				break;
			case 'delete':
				if ( empty( $_POST['confirm'] ) ) {
					$result = array( 'type' => 'error', 'code' => 'confirm' );
					break;
				}
				$result = Endpoints::delete( $endpoint_id, $user_id ) ? array( 'type' => 'success', 'code' => 'deleted' ) : array( 'type' => 'error', 'code' => 'not_found' );
				break;
			case 'test':
				$delivery = Dispatcher::send_test( $endpoint_id );
				if ( null === $delivery ) {
					$result = array( 'type' => 'error', 'code' => 'not_found' );
					break;
				}
				$result      = Deliveries::DELIVERED === $delivery['status']
					? array( 'type' => 'success', 'code' => 'test_delivered', 'detail' => (string) $delivery['response_code'] )
					: array( 'type' => 'error', 'code' => 'test_failed', 'detail' => (string) $delivery['error'] );
				$args['log'] = $endpoint_id;
				break;
			case 'redeliver':
				$delivery_id = isset( $_POST['delivery_id'] ) ? absint( wp_unslash( $_POST['delivery_id'] ) ) : 0;
				$delivery    = Deliveries::get( $delivery_id );
				$result      = null !== $delivery && EventCatalog::TEST_EVENT !== $delivery['event'] && Dispatcher::redeliver( $delivery_id )
					? array( 'type' => 'success', 'code' => 'redelivery_queued' )
					: array( 'type' => 'error', 'code' => 'redelivery_refused' );
				if ( null !== $delivery ) {
					$args['log'] = (int) $delivery['endpoint_id'];
				}
				break;
		}

		set_transient( self::result_key(), $result, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	/**
	 * Keep a new secret for the one display after the redirect (encrypted, ten minutes, this user only).
	 *
	 * @param string $secret      Plaintext secret.
	 * @param int    $endpoint_id Endpoint ID.
	 * @return void
	 */
	private static function keep_secret( string $secret, int $endpoint_id ): void {
		$encrypted = SecretCrypto::encrypt( $secret );
		if ( false !== $encrypted ) {
			set_transient(
				self::secret_key(),
				array(
					'endpoint_id' => $endpoint_id,
					'secret'      => $encrypted,
				),
				self::SECRET_TTL
			);
		}
	}

	/**
	 * Show the result of the last action and a new secret, both once.
	 *
	 * @return void
	 */
	private static function render_result(): void {
		$result = get_transient( self::result_key() );
		if ( is_array( $result ) ) {
			delete_transient( self::result_key() );
			$type  = 'success' === ( $result['type'] ?? '' ) ? 'notice-success' : 'notice-error';
			$code  = (string) ( $result['code'] ?? '' );
			$text  = esc_html( self::message( $code, (string) ( $result['detail'] ?? '' ) ) );
			$field = 'success' === ( $result['type'] ?? '' ) ? '' : self::error_field( $code );
			if ( '' !== $field ) {
				// Lead to the field the error belongs to.
				$text = '<a href="' . esc_attr( 'url' === $field ? '#wplc-webhook-url' : '#wplc-webhook-event-' . str_replace( '.', '-', EventCatalog::names()[0] ) ) . '">' . $text . '</a>';
			}
			echo '<div class="notice ' . esc_attr( $type ) . '"><p>' . $text . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		}
		$kept = get_transient( self::secret_key() );
		if ( is_array( $kept ) && isset( $kept['secret'] ) ) {
			delete_transient( self::secret_key() );
			$secret = SecretCrypto::decrypt( (string) $kept['secret'] );
			if ( is_string( $secret ) && '' !== $secret ) {
				echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Secret of the endpoint – shown only now:', 'wp-license' ) . '</strong></p>';
				echo '<p><label for="wplc-webhook-secret" class="screen-reader-text">' . esc_html__( 'Secret', 'wp-license' ) . '</label><input type="text" id="wplc-webhook-secret" class="large-text code" readonly value="' . esc_attr( $secret ) . '" onfocus="this.select()"></p>';
				echo '<p>' . esc_html__( 'Copy it into the receiving system now. It is stored encrypted and cannot be shown again; if it is lost, generate a new one.', 'wp-license' ) . '</p></div>';
			}
		}
	}

	/**
	 * Text of a result code.
	 *
	 * @param string $code   Result or error code.
	 * @param string $detail Status code or error of a test.
	 * @return string
	 */
	public static function message( string $code, string $detail = '' ): string {
		switch ( $code ) {
			case 'created':
				return __( 'The endpoint was added.', 'wp-license' );
			case 'updated':
				return __( 'The endpoint was saved.', 'wp-license' );
			case 'enabled':
				return __( 'The endpoint was switched on. Its failure counter starts again.', 'wp-license' );
			case 'disabled':
				return __( 'The endpoint was switched off. Queued deliveries are not sent.', 'wp-license' );
			case 'rotated':
				return __( 'A new secret was generated. The old one no longer works.', 'wp-license' );
			case 'deleted':
				return __( 'The endpoint and its delivery log were deleted.', 'wp-license' );
			case 'test_delivered':
				/* translators: %s: HTTP status code. */
				return sprintf( __( 'The test event was delivered (HTTP %s).', 'wp-license' ), $detail );
			case 'test_failed':
				/* translators: %s: short error. */
				return sprintf( __( 'The test event could not be delivered: %s', 'wp-license' ), self::delivery_error( $detail ) );
			case 'redelivery_queued':
				return __( 'The delivery was queued again with the same event ID.', 'wp-license' );
			case 'redelivery_refused':
				return __( 'This delivery cannot be sent again now: it is still queued or being sent.', 'wp-license' );
			case 'confirm':
				return __( 'Tick the confirmation box first.', 'wp-license' );
			case 'not_found':
				return __( 'The endpoint was not found.', 'wp-license' );
			case 'no_events':
				return __( 'Choose at least one event.', 'wp-license' );
			case 'too_many':
				/* translators: %d: largest number of endpoints. */
				return sprintf( __( 'At most %d endpoints are possible.', 'wp-license' ), Endpoints::MAX_ENDPOINTS );
			case 'encryption_failed':
				return __( 'The secret could not be encrypted. Check the encryption key in wp-config.php (WPLC_INSTALLATION_ENCRYPTION_KEYS) and that the OpenSSL extension is available.', 'wp-license' );
			case UrlGuard::INVALID:
				return __( 'The target URL is not a valid address.', 'wp-license' );
			case UrlGuard::NOT_HTTPS:
				return __( 'The target URL must start with https://.', 'wp-license' );
			case UrlGuard::CREDENTIALS:
				return __( 'The target URL must not contain a user name or password.', 'wp-license' );
			case UrlGuard::PORT:
				return __( 'The target URL must use the standard port 443.', 'wp-license' );
			case UrlGuard::LOCAL_HOST:
				return __( 'The target URL names a local host. Use the public address of the receiving system.', 'wp-license' );
			case UrlGuard::BLOCKED_ADDRESS:
				return __( 'The target URL points to a private, local or reserved network address. Only public addresses are allowed.', 'wp-license' );
			case UrlGuard::UNRESOLVED:
				return __( 'The host name of the target URL could not be resolved.', 'wp-license' );
			case UrlGuard::TRAILING_DOT:
				return __( 'The host name of the target URL must not end with a dot.', 'wp-license' );
			default:
				return __( 'The action failed. Reload the page and try again.', 'wp-license' );
		}
	}

	/**
	 * Text of an error stored in the delivery log ("code" or "code:detail"); old rows hold a free
	 * text and are shown as they are.
	 *
	 * @param string $stored Stored error.
	 * @return string
	 */
	public static function delivery_error( string $stored ): string {
		$error  = Dispatcher::parse_error( $stored );
		$detail = $error['detail'];
		switch ( $error['code'] ) {
			case Dispatcher::ERROR_ADDRESS_REFUSED:
				/* translators: %s: reason why the target URL was refused. */
				return sprintf( __( 'Not sent; the address was refused before the request: %s', 'wp-license' ), self::message( $detail ) );
			case Dispatcher::ERROR_SECRET:
				return __( 'Not sent; the secret cannot be decrypted. Generate a new secret.', 'wp-license' );
			case Dispatcher::ERROR_TRANSPORT:
				/* translators: %s: technical error of the HTTP request, in English. */
				return sprintf( __( 'The request failed: %s', 'wp-license' ), $detail );
			case Dispatcher::ERROR_HTTP_STATUS:
				$code = (int) $detail;
				return $code >= 300 && $code < 400
					/* translators: %d: HTTP status code. */
					? sprintf( __( 'The receiver answered with HTTP %d; redirects are not followed.', 'wp-license' ), $code )
					/* translators: %d: HTTP status code. */
					: sprintf( __( 'The receiver answered with HTTP %d.', 'wp-license' ), $code );
			case Dispatcher::ERROR_ADDRESS_CHANGED:
				/* translators: %s: IP address. */
				return sprintf( __( 'The connection went to %s instead of the checked address; the delivery does not count as delivered.', 'wp-license' ), $detail );
			case Dispatcher::ERROR_ENDPOINT_GONE:
				return __( 'Not sent; the endpoint was deleted.', 'wp-license' );
			case Dispatcher::ERROR_ENDPOINT_OFF:
				return __( 'Not sent; the endpoint is switched off.', 'wp-license' );
			default:
				return $detail;
		}
	}

	/**
	 * Description of an event for the form.
	 *
	 * @param string $event Event name.
	 * @return string
	 */
	public static function event_label( string $event ): string {
		$labels = array(
			'license.issued'             => __( 'a license was issued (order or by hand)', 'wp-license' ),
			'license.status_changed'     => __( 'the status of a license changed or it was renewed', 'wp-license' ),
			'license.plan_changed'       => __( 'the plan of a license changed', 'wp-license' ),
			'license.site_limit_reached' => __( 'an activation was refused because a site limit was reached', 'wp-license' ),
			'activation.created'         => __( 'a site was activated', 'wp-license' ),
			'activation.removed'         => __( 'a site activation ended', 'wp-license' ),
			'release.published'          => __( 'a release was published', 'wp-license' ),
			'release.withdrawn'          => __( 'a release was withdrawn', 'wp-license' ),
		);

		return $labels[ $event ] ?? $event;
	}

	/**
	 * Label of a delivery status.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function status_label( string $status ): string {
		$labels = array(
			Deliveries::PENDING   => __( 'Queued', 'wp-license' ),
			Deliveries::SENDING   => __( 'Sending', 'wp-license' ),
			Deliveries::RETRYING  => __( 'Failed, will retry', 'wp-license' ),
			Deliveries::DELIVERED => __( 'Delivered', 'wp-license' ),
			Deliveries::FAILED    => __( 'Failed', 'wp-license' ),
			Deliveries::CANCELLED => __( 'Not sent (endpoint off)', 'wp-license' ),
		);

		return $labels[ $status ] ?? $status;
	}

	/**
	 * Transient key of the last result of this user.
	 *
	 * @return string
	 */
	private static function result_key(): string {
		return 'wplc_webhook_result_' . (int) get_current_user_id();
	}

	/**
	 * Transient key of the form input of this user after a failed save.
	 *
	 * @return string
	 */
	private static function form_key(): string {
		return 'wplc_webhook_form_' . (int) get_current_user_id();
	}

	/**
	 * Transient key of the new secret of this user.
	 *
	 * @return string
	 */
	private static function secret_key(): string {
		return 'wplc_webhook_secret_' . (int) get_current_user_id();
	}
}
