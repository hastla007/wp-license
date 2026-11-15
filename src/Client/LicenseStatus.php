<?php
/**
 * License status of one add-on as the LicenseManager sees it.
 *
 * @package WPLC
 */

namespace WPLC\Client;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\\LicenseStatus', false ) ) {

	/**
	 * Read-only result of LicenseManager::status().
	 *
	 * `state` is one of the STATE_* constants. `access` is the only field a
	 * feature gate needs; everything else is for display.
	 * All date-time strings are UTC in the form `Y-m-d H:i:s`.
	 */
	final class LicenseStatus {

		/**
		 * Version of the client library this copy belongs to.
		 */
		const VERSION = '2.0.0';

		const STATE_MISCONFIGURED = 'misconfigured';
		const STATE_UNLICENSED    = 'unlicensed';
		const STATE_ACTIVE        = 'active';
		const STATE_GRACE         = 'grace';
		const STATE_EXPIRED       = 'expired';
		const STATE_SUSPENDED     = 'suspended';
		const STATE_REVOKED       = 'revoked';
		const STATE_INACTIVE      = 'inactive';
		const STATE_DEACTIVATED   = 'deactivated';
		const STATE_SITE_CHANGED  = 'site_changed';
		const STATE_STALE         = 'stale';

		/**
		 * One of the STATE_* constants.
		 *
		 * @var string
		 */
		public $state = self::STATE_UNLICENSED;

		/**
		 * Whether the premium features may run right now.
		 *
		 * @var bool
		 */
		public $access = false;

		/**
		 * `license_status` of the last signed answer (`active`, `past_due`, `expired`, ...), or an empty string.
		 *
		 * @var string
		 */
		public $license_status = '';

		/**
		 * Feature rights of the last signed answer; empty without access.
		 *
		 * @var array<string,mixed>
		 */
		public $entitlements = array();

		/**
		 * End of the paid period (UTC), empty for a license without an end or when unknown.
		 *
		 * @var string
		 */
		public $paid_until = '';

		/**
		 * Time at which access ends if nothing changes (UTC), or an empty string.
		 *
		 * @var string
		 */
		public $access_until = '';

		/**
		 * End of the renewal grace period (UTC), or an empty string.
		 *
		 * @var string
		 */
		public $grace_until = '';

		/**
		 * Signed end of the offline window (UTC), or an empty string.
		 *
		 * @var string
		 */
		public $cache_expires_at = '';

		/**
		 * Environment stored by the server (`production`, `staging`, `development`), or an empty string.
		 *
		 * @var string
		 */
		public $environment = '';

		/**
		 * Live-site limit of the license.
		 *
		 * @var int
		 */
		public $site_limit = 0;

		/**
		 * Live sites in use.
		 *
		 * @var int
		 */
		public $site_count = 0;

		/**
		 * Installation UUID of this site, or an empty string.
		 *
		 * @var string
		 */
		public $installation_id = '';

		/**
		 * Unix time of the last successful check, 0 when there was none.
		 *
		 * @var int
		 */
		public $last_checked = 0;

		/**
		 * Code of the error of the last check, empty when it succeeded.
		 *
		 * @var string
		 */
		public $last_error = '';

		/**
		 * Unix time of that error.
		 *
		 * @var int
		 */
		public $last_error_at = 0;

		/**
		 * Whether the server answered that it does not know this installation (the key must be entered again).
		 *
		 * @var bool
		 */
		public $needs_reactivation = false;

		/**
		 * Whether the license grants a feature.
		 *
		 * True for the value `true` and for a number above zero.
		 *
		 * @param string $key Entitlement key, e.g. `voucher.qr_redemption`.
		 * @return bool
		 */
		public function is_entitled( string $key ): bool {
			if ( ! $this->access || ! array_key_exists( $key, $this->entitlements ) ) {
				return false;
			}
			$value = $this->entitlements[ $key ];

			return true === $value || ( ( is_int( $value ) || is_float( $value ) ) && $value > 0 );
		}

		/**
		 * Raw value of an entitlement (limit), or the fallback without access.
		 *
		 * @param string $key      Entitlement key.
		 * @param mixed  $fallback Value when the right is absent.
		 * @return mixed
		 */
		public function entitlement( string $key, $fallback = null ) {
			return $this->access && array_key_exists( $key, $this->entitlements ) ? $this->entitlements[ $key ] : $fallback;
		}
	}
}
