<?php
/**
 * Plugin Name: WPLC e2e helpers
 * Description: Test-only helpers for the WP License Captain end-to-end run. NEVER install on a real shop.
 *
 * - routes HTTP requests for https://shop.test to the local `php -S` server,
 * - captures every outgoing mail instead of sending it,
 * - registers a fake recurring gateway (WooCommerce gateway + WPLC adapter).
 *
 * @package WPLC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * 0. Attribute PHP notices, warnings and deprecations to the plugin.
 *    WordPress reports many problems from core files (kses.php, functions.php) although plugin
 *    code caused them. When a frame of the backtrace is inside wp-content/plugins/wp-license,
 *    an extra line "wplc-e2e: plugin-caused ..." is logged; the driver treats it as a failure.
 */
set_error_handler(
	static function ( $errno, $errstr, $errfile = '', $errline = 0 ) {
		if ( ! ( error_reporting() & $errno ) ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions, WordPress.PHP.DiscouragedPHPFunctions
			return false;
		}
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 40 ) as $frame ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			if ( isset( $frame['file'] ) && false !== strpos( $frame['file'], '/plugins/wp-license/' ) ) {
				error_log( 'wplc-e2e: plugin-caused PHP message "' . wp_strip_all_tags( (string) $errstr ) . '" reported in ' . $errfile . ':' . $errline . ', reached from ' . $frame['file'] . ':' . ( $frame['line'] ?? 0 ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
				break;
			}
		}

		return false; // Continue with the normal PHP/WordPress handling.
	}
);

/*
 * 1. Real HTTP without TLS: the site is https://shop.test for WordPress, the transport is
 *    http://127.0.0.1:<port>. The router of the PHP server sets HTTPS=on for the server side.
 */
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		static $busy = false;
		if ( $busy || 0 !== strpos( (string) $url, 'https://shop.test/' ) ) {
			return $pre;
		}
		$port = getenv( 'WPLC_E2E_PORT' ) ? (int) getenv( 'WPLC_E2E_PORT' ) : 8765;
		$busy = true;
		$args['headers']         = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array();
		$args['headers']['Host'] = 'shop.test';
		// Redirects must come back through this filter, so they are followed by the caller.
		$args['redirection'] = 0;
		$args['sslverify']   = false;
		$args['reject_unsafe_urls'] = false;
		$response = wp_remote_request( 'http://127.0.0.1:' . $port . substr( (string) $url, strlen( 'https://shop.test' ) ), $args );
		$busy     = false;

		return $response;
	},
	10,
	3
);

/*
 * 1b. WooCommerce built from its Git tag has no compiled admin JavaScript; its React admin
 *     then throws "Could not find asset registry" on every wp-admin page. Switch that feature
 *     off in this case only (the classic WooCommerce admin and all PHP stay active).
 */
add_filter(
	'woocommerce_admin_disabled',
	static function ( $disabled ) {
		if ( defined( 'WC_ABSPATH' ) && ! file_exists( WC_ABSPATH . 'assets/client/admin/app/index.js' ) ) {
			return true;
		}
		return $disabled;
	}
);

/*
 * 2. Mail capture. Each mail is appended to the option `wplc_e2e_mails`.
 */
add_filter(
	'pre_wp_mail',
	static function ( $short_circuit, $atts ) {
		global $wpdb;
		$wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => 'wplc_e2e_mail_' . str_replace( '.', '', (string) microtime( true ) ) . '_' . wp_rand( 1000, 9999 ),
				'option_value' => wp_json_encode(
					array(
						'to'      => $atts['to'],
						'subject' => $atts['subject'],
						'message' => $atts['message'],
						'headers' => $atts['headers'],
					)
				),
				'autoload'     => 'no',
			)
		);

		return true;
	},
	10,
	2
);

/**
 * Captured mails, oldest first.
 *
 * @return array<int,array<string,mixed>>
 */
function wplc_e2e_mails(): array {
	global $wpdb;
	$rows  = $wpdb->get_col( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE 'wplc\\_e2e\\_mail\\_%' ORDER BY option_id ASC" );
	$mails = array();
	foreach ( (array) $rows as $row ) {
		$mail = json_decode( (string) $row, true );
		if ( is_array( $mail ) ) {
			$mails[] = $mail;
		}
	}

	return $mails;
}

/**
 * Forget captured mails.
 *
 * @return void
 */
function wplc_e2e_clear_mails(): void {
	global $wpdb;
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wplc\\_e2e\\_mail\\_%'" );
}

/*
 * 3. Fake recurring gateway `wplc_e2e_fake`.
 *    Option `wplc_e2e_fake_mode`: success | decline | manual | unknown.
 *    Every charge call is appended to the option `wplc_e2e_fake_charges` and stored as a row
 *    `wplc_e2e_charge_*` (safe under concurrency). Option `wplc_e2e_fake_delay_ms` makes the
 *    charge call slow.
 */
add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}

		/**
		 * Minimal tokenizing gateway so WooCommerce treats the ID as a real payment method.
		 */
		class WPLC_E2E_Fake_Gateway extends WC_Payment_Gateway {

			/**
			 * Set up the gateway.
			 */
			public function __construct() {
				$this->id                 = 'wplc_e2e_fake';
				$this->method_title       = 'WPLC e2e fake card';
				$this->method_description = 'Test-only gateway.';
				$this->title              = 'Fake card (e2e)';
				$this->has_fields         = false;
				$this->supports           = array( 'products', 'tokenization' );
				$this->enabled            = 'yes';
			}

			/**
			 * Pay immediately.
			 *
			 * @param int $order_id Order ID.
			 * @return array<string,string>
			 */
			public function process_payment( $order_id ) {
				$order = wc_get_order( $order_id );
				$order->payment_complete( 'e2e-' . $order_id );

				return array(
					'result'   => 'success',
					'redirect' => $this->get_return_url( $order ),
				);
			}
		}

		add_filter(
			'woocommerce_payment_gateways',
			static function ( $gateways ) {
				$gateways[] = 'WPLC_E2E_Fake_Gateway';
				return $gateways;
			}
		);

		if ( ! interface_exists( '\\WPLC\\Gateway\\RecurringGatewayAdapter' ) ) {
			return;
		}

		/**
		 * Adapter whose result is chosen by the option `wplc_e2e_fake_mode`.
		 */
		class WPLC_E2E_Fake_Adapter implements \WPLC\Gateway\RecurringGatewayAdapter {

			/**
			 * Gateway ID.
			 *
			 * @return string
			 */
			public function gateway_id(): string {
				return 'wplc_e2e_fake';
			}

			/**
			 * Always configured.
			 *
			 * @return bool
			 */
			public function supports_checkout(): bool {
				return true;
			}

			/**
			 * The token must be a card of this gateway and belong to the customer.
			 *
			 * @param int $token_id Token ID.
			 * @param int $customer_id Customer ID.
			 * @return bool
			 */
			public function supports_saved_token( int $token_id, int $customer_id ): bool {
				$token = WC_Payment_Tokens::get( $token_id );

				return $token && 'wplc_e2e_fake' === $token->get_gateway_id() && (int) $token->get_user_id() === $customer_id;
			}

			/**
			 * Charge according to the configured mode.
			 *
			 * @param \WC_Order $order Order.
			 * @param int       $token_id Token.
			 * @param string    $idempotency_key Key.
			 * @return array|\WP_Error
			 */
			public function charge( $order, int $token_id, string $idempotency_key ) {
				$mode    = (string) get_option( 'wplc_e2e_fake_mode', 'success' );
				$charges = (array) get_option( 'wplc_e2e_fake_charges', array() );
				$charges[] = array(
					'order_id' => (int) $order->get_id(),
					'total'    => (string) $order->get_total(),
					'token_id' => $token_id,
					'key'      => $idempotency_key,
					'mode'     => $mode,
				);
				update_option( 'wplc_e2e_fake_charges', $charges, false );
				// The option above is a read-modify-write and can lose entries when two processes
				// charge at the same time. Every call is therefore also stored as its own row
				// (wplc_e2e_charge_rows()), which concurrent processes cannot overwrite.
				$GLOBALS['wpdb']->insert(
					$GLOBALS['wpdb']->options,
					array(
						'option_name'  => 'wplc_e2e_charge_' . str_replace( '.', '', (string) microtime( true ) ) . '_' . wp_rand( 100000, 999999 ),
						'option_value' => wp_json_encode( end( $charges ) + array( 'pid' => getmypid() ) ),
						'autoload'     => 'no',
					)
				);
				// A slow gateway widens the window in which other requests can interfere with a charge.
				$delay = (int) get_option( 'wplc_e2e_fake_delay_ms', 0 );
				if ( $delay > 0 ) {
					usleep( min( 5000, $delay ) * 1000 );
				}

				switch ( $mode ) {
					case 'decline':
						return new WP_Error( 'card_declined', 'The card was declined.' );
					case 'manual':
						return new WP_Error( 'renewal_manual_action_required', 'Authentication required.' );
					case 'unknown':
						return new WP_Error( 'renewal_result_unknown', 'Timeout.' );
				}

				return array(
					'success'        => true,
					'transaction_id' => 'e2e_txn_' . count( $charges ),
				);
			}
		}

		add_filter(
			'wplc_recurring_gateway_adapters',
			static function ( $adapters ) {
				$adapters   = is_array( $adapters ) ? $adapters : array();
				$adapters[] = new WPLC_E2E_Fake_Adapter();
				return $adapters;
			}
		);

		// The fake gateway "saved" the customer's default card of this gateway during checkout.
		add_filter(
			'wplc_payment_token_id_for_order',
			static function ( $token_id, $order ) {
				if ( $token_id || ! $order instanceof WC_Order || 'wplc_e2e_fake' !== $order->get_payment_method() ) {
					return $token_id;
				}
				foreach ( WC_Payment_Tokens::get_customer_tokens( (int) $order->get_customer_id(), 'wplc_e2e_fake' ) as $token ) {
					return (int) $token->get_id();
				}
				return $token_id;
			},
			10,
			2
		);
	},
	5
);

/**
 * Charges recorded as separate rows (complete even when processes charged concurrently).
 *
 * @return array<int,array<string,mixed>>
 */
function wplc_e2e_charge_rows(): array {
	global $wpdb;
	$rows = $wpdb->get_col( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE 'wplc\\_e2e\\_charge\\_%' ORDER BY option_id ASC" );

	return array_values( array_filter( array_map( static function ( $row ) {
		return json_decode( (string) $row, true );
	}, (array) $rows ), 'is_array' ) );
}

/*
 * 4. Concurrency helpers (flow 11).
 *
 *    a) Start barrier: a request with the header `X-WPLC-E2E-At: <unix time with fraction>`
 *       sleeps after WordPress has loaded until that moment, so requests sent together by
 *       curl_multi enter the plugin code at the same time. The response says whether it waited
 *       (`X-WPLC-E2E-Waited`).
 *    b) Race operations: `POST /?wplc_e2e_race=1` with the header `X-WPLC-E2E-Race: <secret>`
 *       (option `wplc_e2e_race_secret`, set by the flow) and a JSON body {op, args} runs one
 *       allow-listed plugin/WooCommerce operation inside the PHP server process and answers JSON.
 */
// While flow 11 runs, Action Scheduler must not start its loopback queue runner: that request
// would occupy a server worker and run queued renewals outside the scenario.
add_filter(
	'action_scheduler_allow_async_request_runner',
	static function ( $allow ) {
		return get_option( 'wplc_e2e_no_async_runner' ) ? false : $allow;
	}
);

add_action(
	'wp_loaded',
	static function () {
		$at = isset( $_SERVER['HTTP_X_WPLC_E2E_AT'] ) ? (float) $_SERVER['HTTP_X_WPLC_E2E_AT'] : 0.0;
		if ( $at > 0 ) {
			$wait = $at - microtime( true );
			if ( $wait > 0 && $wait < 15 ) {
				usleep( (int) ( $wait * 1000000 ) );
			}
			if ( ! headers_sent() ) {
				header( 'X-WPLC-E2E-Waited: ' . ( $wait > 0 ? '1' : '0' ) );
			}
		}

		if ( ! isset( $_GET['wplc_e2e_race'] ) ) {
			return;
		}
		$secret = (string) get_option( 'wplc_e2e_race_secret', '' );
		$given  = isset( $_SERVER['HTTP_X_WPLC_E2E_RACE'] ) ? (string) $_SERVER['HTTP_X_WPLC_E2E_RACE'] : '';
		if ( strlen( $secret ) < 32 || ! hash_equals( $secret, $given ) ) {
			status_header( 403 );
			exit;
		}
		$request = json_decode( (string) file_get_contents( 'php://input' ), true );
		$op      = is_array( $request ) ? (string) ( $request['op'] ?? '' ) : '';
		$args    = is_array( $request ) && is_array( $request['args'] ?? null ) ? $request['args'] : array();
		$result  = wplc_e2e_race_op( $op, $args );
		nocache_headers();
		header( 'Content-Type: application/json' );
		echo wp_json_encode( array( 'op' => $op, 'pid' => getmypid(), 'result' => $result ) );
		exit;
	},
	PHP_INT_MAX
);

/**
 * Run one allow-listed operation for the concurrency flow.
 *
 * @param string              $op Operation.
 * @param array<string,mixed> $args Arguments.
 * @return mixed
 */
function wplc_e2e_race_op( string $op, array $args ) {
	$id = (int) ( $args['id'] ?? 0 );
	// Start some operations a little later, e.g. while another request waits for the gateway.
	if ( ! empty( $args['delay_ms'] ) ) {
		usleep( min( 5000, (int) $args['delay_ms'] ) * 1000 );
	}
	switch ( $op ) {
		case 'payment_complete':
			$order = wc_get_order( $id );
			return $order ? $order->payment_complete( 'e2e-race-' . getmypid() ) : null;
		case 'issue_order':
			\WPLC\License\LicenseIssuer::maybe_issue_order( $id );
			return true;
		case 'order_status':
			$order = wc_get_order( $id );
			if ( $order ) {
				$order->update_status( (string) $args['status'], 'e2e race' );
			}
			return (bool) $order;
		case 'process_renewal':
			\WPLC\Billing\RenewalProcessor::process( $id );
			return true;
		case 'run_action':
			ActionScheduler::runner()->process_action( $id, 'e2e-race' );
			return true;
		case 'reconcile_paid_order':
			\WPLC\Billing\RenewalProcessor::reconcile_paid_renewal_order( $id );
			return true;
		case 'admin_set_status':
			return \WPLC\License\LicenseLifecycle::admin_set_status( $id, (string) $args['status'], 'e2e race', 1 );
		case 'admin_extend':
			return \WPLC\License\LicenseLifecycle::admin_extend( $id, (string) $args['paid_until'], 'e2e race', 1 );
		case 'schedule_cancellation':
			return \WPLC\License\LicenseLifecycle::schedule_cancellation( $id, 'e2e race', 0 );
		case 'resume_renewal':
			return \WPLC\License\LicenseLifecycle::resume_renewal( $id, 0 );
		case 'mark_past_due':
			\WPLC\License\LicenseLifecycle::mark_past_due( $id, 'e2e race' );
			return true;
		case 'chargeback':
			\WPLC\License\LicenseLifecycle::suspend_for_chargeback( $id, 'e2e race' );
			return true;
		case 'full_refund':
			\WPLC\License\LicenseLifecycle::handle_full_refund( $id );
			return true;
		case 'expire_due':
			\WPLC\License\LicenseLifecycle::expire_due_licenses();
			return true;
	}

	return 'unknown op';
}
