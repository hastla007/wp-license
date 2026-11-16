<?php
/**
 * Static feature gate for premium code.
 *
 * @package Vendor\Product\Premium
 */

namespace Vendor\Product\Premium\License;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `Entitlements::has( 'voucher.example_feature' )` in every premium entry point (admin screens, REST routes, cron
 * jobs, templates). Fails closed: before the add-on has booted, on any error, without a valid fresh signed cache or
 * for a key of another product the answer is false. Only the premium feature is affected; data it created earlier
 * stays readable (B6).
 */
final class Entitlements {
	/** @var LicenseService|null */
	private static $service = null;

	/**
	 * Set by the plugin bootstrap (and by tests).
	 *
	 * @param LicenseService|null $service Service or null to reset.
	 */
	public static function use_service( ?LicenseService $service ): void {
		self::$service = $service;
	}

	/**
	 * Whether a premium feature is unlocked.
	 *
	 * @param string $key Entitlement key, e.g. "voucher.example_feature".
	 */
	public static function has( string $key ): bool {
		try {
			return null !== self::$service && self::$service->has( $key );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Numeric limit of an entitlement (0 when not granted or not numeric).
	 *
	 * @param string $key Entitlement key, e.g. "voucher.monthly_limit".
	 */
	public static function limit( string $key ): int {
		try {
			$value = null === self::$service ? null : self::$service->value( $key );
		} catch ( \Throwable $e ) {
			return 0;
		}
		return ( is_int( $value ) || is_float( $value ) ) && $value > 0 ? (int) $value : 0;
	}
}
