<?php
/**
 * The definitions of all stored settings.
 *
 * @package WPLC
 */

namespace WPLC\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plain data for Registry: one entry per setting. Add a setting here, its label and help
 * text in Texts, and its chapter in docs/manual/13-settings.md (a test compares the three).
 *
 * Defaults are the behaviour of the version before the setting existed, so an update
 * changes nothing until the shop changes a value.
 */
final class Definitions {

	/**
	 * All definitions in display order. See Registry::definitions() for the fields.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function all(): array {
		return array(
			// Updates.
			'package_hosts'             => array(
				'type'       => 'hosts',
				'default'    => array(),
				'group'      => 'updates',
				'capability' => 'manage_package_hosts',
				'filter'     => 'wplc_update_package_hosts',
			),
			'catalog_auto_import'       => array(
				'type'    => 'bool',
				'default' => 1,
				'group'   => 'updates',
				'filter'  => 'wplc_catalog_auto_import',
			),

			// Renewals.
			'combined_renewals'         => array(
				'type'    => 'bool',
				'default' => 0,
				'group'   => 'renewals',
				'filter'  => 'wplc_combined_renewals',
			),

			// Customer emails.
			'reminder_days'             => array(
				'type'          => 'int_list',
				'default'       => '30,7',
				'min'           => 1,
				'max'           => 365,
				'max_items'     => 6,
				'order'         => 'desc',
				'clamp'         => true,
				'empty_default' => true,
				'group'         => 'emails',
				'filter'        => 'wplc_reminder_lead_days',
			),
			'renewal_notice_days'       => array(
				'type'    => 'int',
				'default' => 7,
				'min'     => 1,
				'max'     => 60,
				'clamp'   => true,
				'group'   => 'emails',
				'filter'  => 'wplc_renewal_notice_lead_days',
			),
			'price_change_notice_days'  => array(
				'type'    => 'int',
				'default' => 60,
				'min'     => 30,
				'max'     => 365,
				'clamp'   => true,
				'group'   => 'emails',
				'filter'  => 'wplc_price_change_notice_days',
				'legal'   => true,
			),
			'sender_note'               => array(
				'type'       => 'text',
				'default'    => '',
				'max_length' => 500,
				'clamp'      => true,
				'group'      => 'emails',
			),

			// Rules of this shop.
			'max_quantity_per_line'     => array(
				'type'    => 'int',
				'default' => 100,
				'min'     => 0,
				'max'     => 100000,
				'clamp'   => true,
				'group'   => 'rules',
				'filter'  => 'wplc_max_license_quantity_per_line',
			),
			'suspended_ends_after_days' => array(
				'type'    => 'int',
				'default' => 0,
				'min'     => 0,
				'max'     => 3650,
				'clamp'   => true,
				'group'   => 'rules',
				'filter'  => 'wplc_suspended_license_ends_after_days',
				'legal'   => true,
			),
		);
	}
}
