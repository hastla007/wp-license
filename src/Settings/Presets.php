<?php
/**
 * Country profiles: suggestions for the settings with legal weight.
 *
 * @package WPLC
 */

namespace WPLC\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A country profile is a suggestion, never a silent change (owner decisions S8 and S9 of
 * 23 Nov 2026): the shop sees every value before and after, chooses the rows and confirms.
 *
 * No profile contains legal advice. Every value of a profile counts as unchecked until a
 * legal review of that country has released it (`reviewed`). Where no value has been fixed
 * for a country the profile says "please decide" instead of inventing one: the shop keeps
 * its current value and is told that it has to decide.
 *
 * The same five profile keys are used by all plugins of the suite.
 */
final class Presets {

	/**
	 * Profile keys.
	 */
	public const KEYS = array( 'DE', 'AT', 'CH', 'EU', 'OTHER' );

	/**
	 * Settings a profile speaks about: every setting with legal weight.
	 *
	 * @return string[]
	 */
	public static function keys_with_legal_weight(): array {
		$keys = array();
		foreach ( Registry::definitions() as $key => $definition ) {
			if ( $definition['legal'] ) {
				$keys[] = (string) $key;
			}
		}

		return $keys;
	}

	/**
	 * All profiles.
	 *
	 * `values` holds the suggested value per setting; null means "please decide". `reviewed`
	 * is the date of the legal review of the profile, or '' while it is unchecked.
	 *
	 * DE suggests the built-in defaults, which were written with German law in mind (see
	 * Maintenance\Retention). The other profiles suggest nothing yet.
	 *
	 * @return array<string,array{values:array<string,mixed>,reviewed:string}>
	 */
	public static function all(): array {
		$defaults  = Registry::defaults();
		$keys      = self::keys_with_legal_weight();
		$undecided = array_fill_keys( $keys, null );
		$german    = array_intersect_key( $defaults, $undecided );

		$profiles = array(
			'DE'    => array(
				'values'   => $german,
				'reviewed' => '',
			),
			'AT'    => array(
				'values'   => $undecided,
				'reviewed' => '',
			),
			'CH'    => array(
				'values'   => $undecided,
				'reviewed' => '',
			),
			'EU'    => array(
				'values'   => $undecided,
				'reviewed' => '',
			),
			'OTHER' => array(
				'values'   => $undecided,
				'reviewed' => '',
			),
		);

		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filters the country profiles (suggestions for the settings with legal weight).
			 *
			 * A shop or a law firm can supply checked values for its country. Values are still
			 * only suggested, shown before and after, and pass the check of each setting.
			 *
			 * @since 0.2.0
			 *
			 * @param array<string,array<string,mixed>> $profiles Profile key => `values` (setting key => value, null = please decide) and `reviewed` (date or '').
			 */
			$filtered = (array) apply_filters( 'wplc_settings_country_profiles', $profiles );
			foreach ( self::KEYS as $code ) {
				if ( isset( $filtered[ $code ]['values'] ) && is_array( $filtered[ $code ]['values'] ) ) {
					$profiles[ $code ] = array(
						'values'   => array_intersect_key( $filtered[ $code ]['values'], Registry::definitions() ),
						'reviewed' => isset( $filtered[ $code ]['reviewed'] ) && is_string( $filtered[ $code ]['reviewed'] ) ? $filtered[ $code ]['reviewed'] : '',
					);
				}
			}
		}

		return $profiles;
	}

	/**
	 * Names of the profiles.
	 *
	 * @return array<string,string>
	 */
	public static function labels(): array {
		return array(
			'DE'    => __( 'Germany', 'wp-license' ),
			'AT'    => __( 'Austria', 'wp-license' ),
			'CH'    => __( 'Switzerland', 'wp-license' ),
			'EU'    => __( 'European Union (general)', 'wp-license' ),
			'OTHER' => __( 'Other country', 'wp-license' ),
		);
	}

	/**
	 * The suggestion of a profile compared with the stored settings (rows of Changes::preview()).
	 *
	 * @param string $code       Profile key.
	 * @param bool   $check_caps Whether the capability of the current user counts.
	 * @return array<int,array<string,mixed>> Empty for an unknown profile.
	 */
	public static function suggestion( string $code, bool $check_caps = true ): array {
		$profiles = self::all();
		if ( ! isset( $profiles[ $code ] ) ) {
			return array();
		}

		return Changes::preview( $profiles[ $code ]['values'], $check_caps );
	}
}
