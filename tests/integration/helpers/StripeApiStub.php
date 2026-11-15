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

		/** @var callable|null Answers retrieve(); null answers "nothing found". */
		public static $on_retrieve = null;

		public static function reset(): void {
			self::$requests    = array();
			self::$responses   = array();
			self::$retrieved   = array();
			self::$on_retrieve = null;
		}

		/**
		 * Like the gateway: the idempotency key comes from the filter `wc_stripe_idempotency_key`.
		 */
		public static function request( $request, $api = 'charges', $method = 'POST', $with_headers = false ) {
			$key              = apply_filters( 'wc_stripe_idempotency_key', 'generated-by-gateway', $request );
			self::$requests[] = array(
				'request' => $request,
				'api'     => $api,
				'method'  => $method,
				'key'     => $key,
			);
			$next             = array_shift( self::$responses );
			if ( $next instanceof Throwable ) {
				throw $next;
			}

			return is_callable( $next ) ? call_user_func( $next, $request ) : $next;
		}

		public static function retrieve( $api ) {
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
