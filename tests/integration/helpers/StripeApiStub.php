<?php
/**
 * Test double for the two WooCommerce Stripe gateway API methods the renewal adapter calls.
 *
 * Loaded on demand and only when the real gateway plugin is absent. Nothing
 * here talks to Stripe: every answer is chosen by the test.
 *
 * @package WPLC
 */

if ( ! class_exists( 'WC_Stripe_API' ) ) {
	/**
	 * Records requests and answers from a queue.
	 */
	class WC_Stripe_API { // phpcs:ignore Generic.Classes.OpeningBraceSameLine, Universal.Files.SeparateFunctionsFromOO

		/** @var array<int,array<string,mixed>> POST requests, each with request, api, method and key. */
		public static $requests = array();

		/** @var array<int,mixed> Answers to POST requests: object, Throwable or callable. */
		public static $responses = array();

		/** @var string[] Paths asked with retrieve(). */
		public static $retrieved = array();

		/** @var array<int,array<string,string>> Headers of every request and retrieve(), in call order. */
		public static $headers = array();

		/** @var callable|null Answers retrieve(); null answers "nothing found". */
		public static $on_retrieve = null;

		public static function reset(): void {
			self::$requests    = array();
			self::$responses   = array();
			self::$retrieved   = array();
			self::$headers     = array();
			self::$on_retrieve = null;
		}

		/**
		 * Like gateway 11.2.0: its own API version, changeable through `wc_stripe_request_headers`.
		 *
		 * @return array<string,string>
		 */
		public static function get_headers() {
			$headers         = apply_filters( 'wc_stripe_request_headers', array( 'Stripe-Version' => '2026-03-25.dahlia' ) );
			self::$headers[] = $headers;

			return $headers;
		}

		/**
		 * Like the gateway: the idempotency key comes from the filter `wc_stripe_idempotency_key`.
		 */
		public static function request( $request, $api = 'charges', $method = 'POST', $with_headers = false ) {
			$headers          = self::get_headers();
			$key              = apply_filters( 'wc_stripe_idempotency_key', 'generated-by-gateway', $request );
			self::$requests[] = array(
				'request' => $request,
				'api'     => $api,
				'method'  => $method,
				'key'     => $key,
				'headers' => $headers,
			);
			$next             = array_shift( self::$responses );
			if ( $next instanceof Throwable ) {
				throw $next;
			}

			return is_callable( $next ) ? call_user_func( $next, $request ) : $next;
		}

		public static function retrieve( $api ) {
			self::get_headers();
			self::$retrieved[] = (string) $api;
			if ( self::$on_retrieve ) {
				return call_user_func( self::$on_retrieve, (string) $api );
			}
			if ( 0 === strpos( (string) $api, 'payment_intents/search' ) ) {
				return (object) array( 'data' => array() );
			}

			return (object) array( 'error' => (object) array( 'type' => 'invalid_request_error', 'code' => 'resource_missing' ) );
		}
	}
}
