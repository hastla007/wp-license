<?php
/**
 * Failure-based rate limiting of anonymous activation attempts (T-142).
 *
 * @package WPLC
 */

namespace WPLC\Api\V1;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Counts failed activation attempts per limiter (client address, key prefix).
 *
 * Only failures count. While a limiter is below its limit nothing else
 * happens, so valid activations are never blocked by other people's typos or
 * guessing on a shared address. Once a limiter has reached its limit within
 * the current minute it is "throttled":
 *
 * - every further request on it is counted in a second bucket that has the
 *   same limit; past that ceiling every request is answered 429 without
 *   looking at the key;
 * - below the ceiling the key is still checked: an unknown key is answered
 *   429 (indistinguishable from the ceiling), a valid key proceeds.
 *
 * Answering valid keys differently is an oracle, so the number of answers it
 * gives is bounded: at most limit failures plus limit throttled requests per
 * limiter and minute, i.e. twice the limit. Before T-142 every request counted
 * and a valid key behind a busy address was locked out together with the
 * guesser; now that only happens when the address keeps sending past the
 * throttled ceiling as well.
 */
final class ActivationRateLimit {

	public const OK          = 'ok';
	public const THROTTLED   = 'throttled';
	public const BLOCKED     = 'blocked';
	public const UNAVAILABLE = 'unavailable';

	/**
	 * Check limiters before the key is looked up.
	 *
	 * @param array<string,int> $limiters Failure limiter hash => failures allowed per minute.
	 * @return string OK, THROTTLED (check the key; reject unknown keys with 429),
	 *                BLOCKED (429 without a key check) or UNAVAILABLE (store failed, 503).
	 */
	public static function check( array $limiters ): string {
		$state = self::OK;
		foreach ( $limiters as $limiter_hash => $limit ) {
			$failures = Authenticator::current_rate_limit( $limiter_hash );
			if ( false === $failures ) {
				return self::UNAVAILABLE;
			}
			if ( $failures < $limit ) {
				continue;
			}

			$throttled = Authenticator::increment_rate_limit( self::throttled_hash( $limiter_hash ) );
			if ( false === $throttled ) {
				return self::UNAVAILABLE;
			}
			if ( $throttled > $limit ) {
				return self::BLOCKED;
			}
			$state = self::THROTTLED;
		}

		return $state;
	}

	/**
	 * Count one failed attempt on every limiter.
	 *
	 * @param array<string,int> $limiters Failure limiter hash => limit.
	 * @return bool False when the store failed.
	 */
	public static function record_failure( array $limiters ): bool {
		foreach ( array_keys( $limiters ) as $limiter_hash ) {
			if ( false === Authenticator::increment_rate_limit( (string) $limiter_hash ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Bucket of requests made while a limiter is throttled.
	 *
	 * @param string $limiter_hash Failure limiter hash.
	 * @return string
	 */
	public static function throttled_hash( string $limiter_hash ): string {
		return hash( 'sha256', 'throttled:' . $limiter_hash );
	}
}
