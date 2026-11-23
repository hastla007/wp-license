<?php
/**
 * Which update channels a license may use (SET-LIC-20).
 *
 * @package WPLC
 */

namespace WPLC\Update;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Beta versions only for licenses whose plan has the right (owner decision E-05, 23.11.2026).
 *
 * Order of precedence: the setting "Beta versions for licenses" (default allowed, the
 * behaviour so far), then the choice of the plan ("Rules of this plan", empty = the
 * setting), then the filter `wplc_license_update_channels`, which decides last.
 *
 * The stable channel is always part of the list. A client that asks for a channel its
 * license does not have gets the stable channel. The list is sent as `update_channels`
 * in every signed entitlement answer, so the client library (2.4.0 and later) shows the
 * checkbox "Receive beta versions" only to licenses that may use it.
 */
final class UpdateChannels {

	/**
	 * The channels a license may use, stable first.
	 *
	 * @param array<string,mixed> $license License row.
	 * @return string[]
	 */
	public static function for_license( array $license ): array {
		$own      = \WPLC\Catalog\PlanOverrides::for_license( $license, 'beta_allowed' );
		$beta     = null === $own ? \WPLC\Settings\Registry::bool( 'beta_updates' ) : 1 === $own;
		$channels = $beta ? array( ReleaseRegistry::DEFAULT_CHANNEL, ReleaseRegistry::CHANNEL_BETA ) : array( ReleaseRegistry::DEFAULT_CHANNEL );
		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filters the update channels a license may use (SET-LIC-20).
			 *
			 * @since 0.2.0
			 *
			 * @param string[]            $channels Default: `stable`, and `beta` when the plan of the license
			 *                                      (or, without a choice of the plan, the setting "Beta versions
			 *                                      for licenses") allows it.
			 * @param array<string,mixed> $license  License row.
			 */
			$channels = apply_filters( 'wplc_license_update_channels', $channels, $license );
		}

		return self::clean( $channels );
	}

	/**
	 * The channel a request is served from: the requested one when the license may use it, else stable.
	 *
	 * @param string              $requested Requested channel (normalized or not).
	 * @param array<string,mixed> $license   License row.
	 * @return string
	 */
	public static function effective( string $requested, array $license ): string {
		$requested = ReleaseRegistry::normalize_channel( $requested );
		if ( ReleaseRegistry::DEFAULT_CHANNEL === $requested ) {
			return $requested;
		}

		return in_array( $requested, self::for_license( $license ), true ) ? $requested : ReleaseRegistry::DEFAULT_CHANNEL;
	}

	/**
	 * Known channels only, stable always first and present. Pure helper.
	 *
	 * @param mixed $channels Filtered value.
	 * @return string[]
	 */
	public static function clean( $channels ): array {
		$out = array( ReleaseRegistry::DEFAULT_CHANNEL );
		foreach ( is_array( $channels ) ? $channels : array() as $channel ) {
			if ( is_string( $channel ) && ReleaseRegistry::CHANNEL_BETA === strtolower( trim( $channel ) ) ) {
				$out[] = ReleaseRegistry::CHANNEL_BETA;
				break;
			}
		}

		return $out;
	}
}
