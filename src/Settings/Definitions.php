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
			'renewal_grace_days'        => array(
				'type'    => 'int',
				'unit'    => 'days',
				'default' => 14,
				'min'     => 1,
				'max'     => 90,
				'group'   => 'renewals',
				'filter'  => 'wplc_renewal_grace_days',
			),
			'renewal_retry_days'        => array(
				'type'      => 'int_list',
				'default'   => '1,3,7',
				'min'       => 1,
				'max'       => 60,
				'max_items' => 6,
				'group'     => 'renewals',
				'filter'    => 'wplc_renewal_retry_days',
			),
			'manual_renewal_days_before' => array(
				'type'    => 'int',
				'unit'    => 'days',
				'default' => 30,
				'min'     => 0,
				'max'     => 365,
				'group'   => 'renewals',
				'filter'  => 'wplc_manual_renewal_window_days',
			),
			'manual_renewal_days_after' => array(
				'type'    => 'int',
				'unit'    => 'days',
				'default' => 30,
				'min'     => 0,
				'max'     => 365,
				'group'   => 'renewals',
				'filter'  => 'wplc_manual_renewal_window_days',
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

			// Legal and privacy.
			'withdrawal_consent_mode'   => array(
				'type'    => 'choice',
				'default' => 'auto',
				'choices' => array( 'auto', 'on', 'off' ),
				'group'   => 'legal',
				'filter'  => 'wplc_withdrawal_consent_enabled',
				'legal'   => true,
			),
			'withdrawal_consent_required' => array(
				'type'    => 'bool',
				'default' => 1,
				'group'   => 'legal',
				'filter'  => 'wplc_withdrawal_consent_required',
				'legal'   => true,
			),
			'withdrawal_consent_text'   => array(
				'type'       => 'text',
				'default'    => '',
				'max_length' => 1000,
				'group'      => 'legal',
				'filter'     => 'wplc_withdrawal_consent_text',
				'legal'      => true,
			),
			'recurring_consent_text'    => array(
				'type'       => 'text',
				'default'    => '',
				'max_length' => 1000,
				'group'      => 'legal',
				'filter'     => 'wplc_recurring_consent_text',
				'legal'      => true,
			),
			'retention_audit_log' => array(
				'type'    => 'int',
				'unit'    => 'days',
				'default' => 730,
				'min'     => 0,
				'max'     => 36500,
				'group'   => 'legal',
				'filter'  => 'wplc_retention_days',
				'legal'   => true,
			),
			'retention_event_inbox' => array(
				'type'    => 'int',
				'unit'    => 'days',
				'default' => 180,
				'min'     => 0,
				'max'     => 36500,
				'group'   => 'legal',
				'filter'  => 'wplc_retention_days',
				'legal'   => true,
			),
			'retention_event_inbox_open' => array(
				'type'    => 'int',
				'unit'    => 'days',
				'default' => 730,
				'min'     => 0,
				'max'     => 36500,
				'group'   => 'legal',
				'filter'  => 'wplc_retention_days',
				'legal'   => true,
			),
			'retention_activations' => array(
				'type'    => 'int',
				'unit'    => 'days',
				'default' => 365,
				'min'     => 0,
				'max'     => 36500,
				'group'   => 'legal',
				'filter'  => 'wplc_retention_days',
				'legal'   => true,
			),
			'retention_inactive_activations' => array(
				'type'    => 'int',
				'unit'    => 'days',
				'default' => 365,
				'min'     => 0,
				'max'     => 36500,
				'group'   => 'legal',
				'filter'  => 'wplc_retention_days',
				'legal'   => true,
			),
			'retention_ended_license_activations' => array(
				'type'    => 'int',
				'unit'    => 'days',
				'default' => 30,
				'min'     => 0,
				'max'     => 36500,
				'group'   => 'legal',
				'filter'  => 'wplc_retention_days',
				'legal'   => true,
			),
			'retention_reminder_log' => array(
				'type'    => 'int',
				'unit'    => 'days',
				'default' => 730,
				'min'     => 0,
				'max'     => 36500,
				'group'   => 'legal',
				'filter'  => 'wplc_retention_days',
				'legal'   => true,
			),
			'retention_anonymize_licenses' => array(
				'type'    => 'int',
				'unit'    => 'days',
				'default' => 3650,
				'min'     => 0,
				'max'     => 36500,
				'group'   => 'legal',
				'filter'  => 'wplc_retention_days',
				'legal'   => true,
			),
			'retention_consents' => array(
				'type'    => 'int',
				'unit'    => 'days',
				'default' => 3650,
				'min'     => 0,
				'max'     => 36500,
				'group'   => 'legal',
				'filter'  => 'wplc_retention_days',
				'legal'   => true,
			),

			// Operations.
			'health_alert_recipients'   => array(
				'type'       => 'string',
				'format'     => 'emails',
				'default'    => '',
				'max_length' => 500,
				'max_items'  => 5,
				'group'      => 'operations',
				'filter'     => 'wplc_health_alert_recipients',
			),
			'uninstall_data'            => array(
				'type'       => 'choice',
				'default'    => 'keep',
				'choices'    => array( 'keep', 'delete' ),
				'group'      => 'operations',
				'capability' => 'manage_package_hosts',
			),
		);
	}
}
