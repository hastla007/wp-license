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
 *    Every charge call is appended to the option `wplc_e2e_fake_charges`.
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
