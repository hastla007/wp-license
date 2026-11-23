<?php
/**
 * The value of a setting that is in effect after the filters of the shop.
 *
 * @package WPLC
 */

namespace WPLC\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Asks the class that uses a setting for the value it works with. The settings page and
 * `wp wplc settings list` show it when it differs from the stored value, so that nobody
 * wonders why a field has no effect ("set by code of the shop").
 */
final class Effective {

	/**
	 * Readers of the effective value, by setting key. Settings whose filter needs more than
	 * the value (a product, a license) have no entry.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	private static function readers(): array {
		return array(
			'catalog_auto_import'       => array( '\\WPLC\\Catalog\\ReleaseCatalog', 'enabled' ),
			'combined_renewals'         => array( '\\WPLC\\Billing\\CombinedRenewal', 'enabled' ),
			'renewal_grace_days'        => array( '\\WPLC\\License\\LicenseLifecycle', 'grace_days' ),
			'renewal_retry_days'        => array( '\\WPLC\\Billing\\RenewalProcessor', 'retry_days' ),
			'manual_renewal_days_before' => array( '\\WPLC\\License\\LicenseStateMachine', 'renewal_days_before' ),
			'manual_renewal_days_after' => array( '\\WPLC\\License\\LicenseStateMachine', 'renewal_days_after' ),
			'withdrawal_consent_required' => array( '\\WPLC\\Billing\\WithdrawalConsent', 'required' ),
			'reminder_days'             => array( '\\WPLC\\Notifications\\Notifications', 'lead_days' ),
			'renewal_notice_days'       => array( '\\WPLC\\Notifications\\Notifications', 'notice_days' ),
			'price_change_notice_days'  => array( '\\WPLC\\Billing\\PriceChange', 'notice_days' ),
			'suspended_ends_after_days' => array( '\\WPLC\\License\\EndedLicenses', 'suspended_days' ),
			'account_licenses_slug'     => array( '\\WPLC\\Account\\CustomerPortal', 'endpoint_slug' ),
			'account_sites_slug'        => array( '\\WPLC\\Account\\SiteOverview', 'endpoint_slug' ),
			'key_rotation_reminder_months' => array( '\\WPLC\\Security\\KeyAge', 'reminder_months' ),
		);
	}

	/**
	 * The value in effect, in the stored form of the setting; null when it cannot be told.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function value( string $key ) {
		$readers    = self::readers();
		$definition = Registry::definition( $key );
		if ( null !== $definition && 0 === strpos( $key, 'retention_' ) && class_exists( '\\WPLC\\Maintenance\\Retention' ) ) {
			try {
				$days = \WPLC\Maintenance\Retention::configured_days();
			} catch ( \Throwable $error ) {
				return null;
			}
			$rule = substr( $key, 10 );

			return isset( $days[ $rule ] ) ? (int) $days[ $rule ] : null;
		}
		if ( null === $definition || ! isset( $readers[ $key ] ) || ! is_callable( $readers[ $key ] ) ) {
			return null;
		}
		try {
			$value = call_user_func( $readers[ $key ] );
		} catch ( \Throwable $error ) {
			return null;
		}
		switch ( $definition['type'] ) {
			case 'bool':
				return empty( $value ) ? 0 : 1;
			case 'int':
				return (int) $value;
			case 'int_list':
				return is_array( $value ) ? implode( ',', array_map( 'intval', $value ) ) : (string) $value;
			case 'string_list':
			case 'hosts':
				return is_array( $value ) ? array_values( $value ) : array();
		}

		return is_scalar( $value ) ? (string) $value : null;
	}

	/**
	 * Whether code of the shop changes the stored value.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public static function is_overridden( string $key ): bool {
		$value = self::value( $key );

		return null !== $value && Registry::display( $key, $value ) !== Registry::display( $key, Registry::get( $key ) );
	}
}
