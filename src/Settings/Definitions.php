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
			// Downloads (SET-LIC-19).
			'expired_download'          => array(
				'type'       => 'bool',
				'default'    => 1,
				'group'      => 'updates',
				'capability' => 'manage_policy',
				'filter'     => 'wplc_expired_license_download',
			),
			'download_version_choices'  => array(
				'type'       => 'int',
				'default'    => 10,
				'min'        => 1,
				'max'        => 50,
				'group'      => 'updates',
				'capability' => 'manage_policy',
				'filter'     => 'wplc_customer_download_version_choices',
			),
			'downloads_per_hour'        => array(
				'type'       => 'int',
				'default'    => 10,
				'min'        => 1,
				'max'        => 1000,
				'group'      => 'updates',
				'capability' => 'manage_policy',
				'filter'     => 'wplc_customer_download_hourly_limit',
			),
			'download_reuse_seconds'    => array(
				'type'       => 'int',
				'default'    => 0,
				'min'        => 0,
				'max'        => 600,
				'group'      => 'updates',
				'capability' => 'manage_policy',
				'filter'     => 'wplc_download_reuse_seconds',
			),
			'download_grant_seconds'    => array(
				'type'       => 'int',
				'default'    => 300,
				'min'        => 60,
				'max'        => 900,
				'group'      => 'updates',
				'capability' => 'manage_policy',
				'filter'     => 'wplc_download_grant_seconds',
			),
			// Object storage (SET-LIC-30). The access keys stay constants in wp-config.php; a constant
			// of the same value wins over each field. Not exported: the bucket belongs to this server.
			's3_bucket'                 => array(
				'type'       => 'string',
				'default'    => '',
				'max_length' => 63,
				'pattern'    => '/^[A-Za-z0-9][A-Za-z0-9._-]{1,61}[A-Za-z0-9]$/D',
				'group'      => 'updates',
				'capability' => 'manage_package_hosts',
				'constant'   => 'WPLC_S3_BUCKET',
				'exportable' => false,
			),
			's3_region'                 => array(
				'type'       => 'string',
				'default'    => '',
				'max_length' => 32,
				'pattern'    => '/^[A-Za-z0-9][A-Za-z0-9-]{0,31}$/D',
				'group'      => 'updates',
				'capability' => 'manage_package_hosts',
				'constant'   => 'WPLC_S3_REGION',
				'exportable' => false,
			),
			's3_endpoint'               => array(
				'type'       => 'string',
				'default'    => '',
				'max_length' => 253,
				'pattern'    => '#^https://[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?(:443)?/?$#D',
				'group'      => 'updates',
				'capability' => 'manage_package_hosts',
				'constant'   => 'WPLC_S3_ENDPOINT',
				'exportable' => false,
			),
			's3_path_style'             => array(
				'type'       => 'bool',
				'default'    => 0,
				'group'      => 'updates',
				'capability' => 'manage_package_hosts',
				'constant'   => 'WPLC_S3_PATH_STYLE',
				'exportable' => false,
			),
			// Owner decisions of 23.11.2026 (settings part 6): beta only with the right of the plan (SET-LIC-20, E-05).
			'beta_updates'              => array(
				'type'       => 'bool',
				'default'    => 1,
				'group'      => 'updates',
				'capability' => 'manage_policy',
				'filter'     => 'wplc_license_update_channels',
			),

			// Renewals.
			'combined_renewals'         => array(
				'type'    => 'bool',
				'default' => 0,
				'group'   => 'renewals',
				'filter'  => 'wplc_combined_renewals',
			),
			'combined_renewal_max_lines' => array(
				'type'    => 'int',
				'default' => 20,
				'min'     => 2,
				'max'     => 100,
				'group'   => 'renewals',
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
			'review_deferral_days'      => array(
				'type'    => 'int',
				'unit'    => 'days',
				'default' => 30,
				'min'     => 1,
				'max'     => 365,
				'group'   => 'renewals',
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
			'upgrade_quote_hours'       => array(
				'type'    => 'int',
				'default' => 24,
				'min'     => 1,
				'max'     => 168,
				'group'   => 'rules',
			),
			// Switches for functions that were filters only (SET-LIC-26).
			'show_license_term'         => array(
				'type'    => 'bool',
				'default' => 1,
				'group'   => 'rules',
				'filter'  => 'wplc_show_license_term',
			),
			'renew_unsold_products'     => array(
				'type'    => 'bool',
				'default' => 0,
				'group'   => 'rules',
				'filter'  => 'wplc_manual_renewal_for_unsold_products',
			),
			'renewal_off_on_card_deletion' => array(
				'type'    => 'bool',
				'default' => 1,
				'group'   => 'rules',
				'filter'  => 'wplc_disable_renewal_on_token_deletion',
			),
			'issue_after_completion_gateways' => array(
				'type'      => 'string_list',
				'default'   => array( 'cod' ),
				'pattern'   => '/^[a-z0-9][a-z0-9_-]{0,63}$/',
				'max_items' => 20,
				'group'     => 'rules',
				'filter'    => 'wplc_issue_after_completion_gateways',
			),
			// Owner decisions of 23.11.2026 (settings part 6): refunds, cancellations and disputes (SET-LIC-17, E-07).
			'suspend_on_order_cancelled' => array(
				'type'    => 'bool',
				'default' => 1,
				'group'   => 'rules',
				'filter'  => 'wplc_suspend_license_on_order_status',
				'legal'   => true,
			),
			'suspend_on_order_item_change' => array(
				'type'    => 'bool',
				'default' => 1,
				'group'   => 'rules',
				'filter'  => 'wplc_suspend_license_on_order_item_change',
				'legal'   => true,
			),
			'partial_refund_review'     => array(
				'type'    => 'bool',
				'default' => 1,
				'group'   => 'rules',
				'filter'  => 'wplc_partial_refund_review',
				'legal'   => true,
			),
			'restore_after_won_dispute' => array(
				'type'    => 'bool',
				'default' => 0,
				'group'   => 'rules',
				'filter'  => 'wplc_restore_license_after_won_dispute',
				'legal'   => true,
			),
			// Owner decisions of 23.11.2026 (settings part 6): plan changes stay prorated by day, two switches (E-08).
			'plan_upgrades_enabled'     => array(
				'type'    => 'bool',
				'default' => 1,
				'group'   => 'rules',
				'filter'  => 'wplc_plan_upgrades_enabled',
				'legal'   => true,
			),
			'plan_downgrades_enabled'   => array(
				'type'    => 'bool',
				'default' => 1,
				'group'   => 'rules',
				'filter'  => 'wplc_plan_downgrades_enabled',
				'legal'   => true,
			),
			// Owner decisions of 23.11.2026 (settings part 6): key prefix, only before the first license (E-04).
			'license_key_prefix'        => array(
				'type'       => 'string',
				'default'    => 'WPLC',
				'format'     => 'key_prefix',
				'max_length' => 4,
				'group'      => 'rules',
				'capability' => 'manage_policy',
				'locked'     => array( \WPLC\License\LicenseKey::class, 'prefix_locked' ),
			),

			// Customer sites.
			// P2-M5: at least 2 days, longer than the client's check cadence (daily, catch-up after 36 hours).
			// A stored 1 of an older version is read as 2 (Registry::int() clamps to min).
			'offline_cache_days'        => array(
				'type'       => 'int',
				'unit'       => 'days',
				'default'    => 14,
				'min'        => 2,
				'max'        => 90,
				'group'      => 'sites',
				'capability' => 'manage_policy',
				'filter'     => 'wplc_offline_cache_days',
			),
			'new_activations_per_hour'  => array(
				'type'    => 'int',
				'default' => 10,
				'min'     => 0,
				'max'     => 1000,
				'group'   => 'sites',
				'filter'  => 'wplc_new_activations_per_hour',
			),
			'staging_sites_per_live'    => array(
				'type'       => 'int',
				'default'    => 1,
				'min'        => 0,
				'max'        => 10,
				'group'      => 'sites',
				'capability' => 'manage_policy',
				'filter'     => 'wplc_staging_site_limit',
			),
			'development_sites'         => array(
				'type'       => 'int',
				'default'    => 5,
				'min'        => 0,
				'max'        => 100,
				'group'      => 'sites',
				'capability' => 'manage_policy',
				'filter'     => 'wplc_development_site_limit',
			),
			'staging_labels'            => array(
				'type'       => 'string_list',
				'default'    => self::STAGING_LABELS,
				'pattern'    => '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/',
				'max_items'  => 50,
				'resettable' => true,
				'group'      => 'sites',
				'capability' => 'manage_policy',
				'filter'     => 'wplc_staging_labels',
			),
			'staging_host_suffixes'     => array(
				'type'       => 'string_list',
				'default'    => self::STAGING_HOST_SUFFIXES,
				// At least two labels: a single label (a top-level domain) would make every shop under it a staging copy.
				'pattern'    => '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/',
				'ltrim'      => '.',
				'max_items'  => 100,
				'resettable' => true,
				'group'      => 'sites',
				'capability' => 'manage_policy',
				'filter'     => 'wplc_staging_host_suffixes',
			),
			'local_host_suffixes'       => array(
				'type'       => 'string_list',
				'default'    => self::LOCAL_HOST_SUFFIXES,
				'pattern'    => '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/',
				'ltrim'      => '.',
				'max_items'  => 50,
				'resettable' => true,
				'group'      => 'sites',
				'capability' => 'manage_policy',
				'filter'     => 'wplc_local_host_suffixes',
			),

			// Co-users and transfer (SET-LIC-18).
			'co_user_limit'             => array(
				'type'       => 'int',
				'default'    => 5,
				'min'        => 0,
				'max'        => 100,
				'group'      => 'sharing',
				'capability' => 'manage_policy',
				'filter'     => 'wplc_license_member_limit',
			),
			'invitation_days'           => array(
				'type'       => 'int',
				'unit'       => 'days',
				'default'    => 14,
				'min'        => 1,
				'max'        => 90,
				'group'      => 'sharing',
				'capability' => 'manage_policy',
				'filter'     => 'wplc_license_invitation_days',
			),
			'transfers_enabled'         => array(
				'type'       => 'bool',
				'default'    => 1,
				'group'      => 'sharing',
				'capability' => 'manage_policy',
				'filter'     => 'wplc_license_transfer_enabled',
			),
			'transfer_new_key'          => array(
				'type'       => 'bool',
				'default'    => 0,
				'group'      => 'sharing',
				'capability' => 'manage_policy',
				'filter'     => 'wplc_license_transfer_new_key',
			),

			// Customer account (SET-LIC-25). A changed address rewrites the rules on the next request.
			'account_licenses_slug'     => array(
				'type'       => 'string',
				'default'    => 'wplc-licenses',
				'max_length' => 50,
				'pattern'    => '/^[a-z0-9][a-z0-9-]{0,49}$/D',
				'group'      => 'account',
				'filter'     => 'wplc_account_endpoint_slug',
			),
			'account_licenses_label'    => array(
				'type'       => 'string',
				'default'    => '',
				'max_length' => 60,
				'group'      => 'account',
			),
			'account_licenses_per_page' => array(
				'type'    => 'int',
				'default' => 10,
				'min'     => 1,
				'max'     => 100,
				'group'   => 'account',
			),
			'account_sites_slug'        => array(
				'type'       => 'string',
				'default'    => 'wplc-sites',
				'max_length' => 50,
				'pattern'    => '/^[a-z0-9][a-z0-9-]{0,49}$/D',
				'group'      => 'account',
				'filter'     => 'wplc_sites_endpoint_slug',
			),
			'account_sites_label'       => array(
				'type'       => 'string',
				'default'    => '',
				'max_length' => 60,
				'group'      => 'account',
			),
			'account_sites_per_page'    => array(
				'type'    => 'int',
				'default' => 25,
				'min'     => 1,
				'max'     => 200,
				'group'   => 'account',
			),
			'account_stale_days'        => array(
				'type'    => 'int',
				'unit'    => 'days',
				'default' => 14,
				'min'     => 1,
				'max'     => 365,
				'group'   => 'account',
			),
			'account_expiry_days'       => array(
				'type'    => 'int',
				'unit'    => 'days',
				'default' => 30,
				'min'     => 1,
				'max'     => 365,
				'group'   => 'account',
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
			'public_cancel_hourly_limit' => array(
				'type'    => 'int',
				'default' => 5,
				'min'     => 1,
				'max'     => 100,
				'group'   => 'legal',
				'filter'  => 'wplc_public_cancel_hourly_limit',
				'legal'   => true,
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
			// SET-LIC-46: delivery log of the outgoing webhooks.
			'retention_webhook_deliveries' => array(
				'type'    => 'int',
				'unit'    => 'days',
				'default' => 30,
				'min'     => 0,
				'max'     => 36500,
				'group'   => 'legal',
				'filter'  => 'wplc_retention_days',
				'legal'   => true,
			),

			// Texts and support.
			'support_email'             => array(
				'type'       => 'string',
				'format'     => 'email',
				'default'    => '',
				'max_length' => 200,
				'group'      => 'texts',
				'filter'     => 'wplc_support_email',
			),
			'support_url'               => array(
				'type'       => 'string',
				'format'     => 'url',
				'default'    => '',
				'max_length' => 500,
				'group'      => 'texts',
				'filter'     => 'wplc_support_url',
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
		) + self::alert_thresholds() + array(
			// SET-LIC-28: a reminder, never an automatic rotation; the keys stay in wp-config.php.
			'key_rotation_reminder_months' => array(
				'type'       => 'int',
				'default'    => 0,
				'min'        => 0,
				'max'        => 120,
				'group'      => 'operations',
				'capability' => 'manage_package_hosts',
				'filter'     => 'wplc_key_rotation_reminder_months',
			),
			'debug_log'                 => array(
				'type'       => 'choice',
				'default'    => 'off',
				'choices'    => array( 'off', '24h', '7d' ),
				'group'      => 'operations',
				'filter'     => 'wplc_log_debug',
				// The switch is about this server only; an export must not switch it on elsewhere.
				'exportable' => false,
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

	/**
	 * Subdomain labels that mark a staging copy (SET-LIC-09; the list so far of SiteUrl).
	 */
	public const STAGING_LABELS = array( 'staging', 'stage', 'stg', 'dev', 'test', 'testing', 'preprod', 'preview', 'sandbox', 'uat' );

	/**
	 * Domains of hosting providers' temporary and staging hosts; their subdomains count as staging (SET-LIC-09).
	 */
	public const STAGING_HOST_SUFFIXES = array(
		'wpengine.com',
		'wpenginepowered.com',
		'kinsta.cloud',
		'pantheonsite.io',
		'cloudwaysapps.com',
		'flywheelsites.com',
		'flywheelstaging.com',
		'myftpupload.com',
		'sg-host.com',
		'wpstage.net',
		'wpcomstaging.com',
		'instawp.xyz',
		'tastewp.com',
		'runcloud.link',
		'ngrok.io',
		'ngrok-free.app',
	);

	/**
	 * Endings of names that never resolve on the public internet or belong to local development tools (SET-LIC-09).
	 */
	public const LOCAL_HOST_SUFFIXES = array(
		'localhost',
		'test',
		'local',
		'internal',
		'lan',
		'home.arpa',
		'localdomain',
		'ddev.site',
		'lndo.site',
	);

	/**
	 * Default alert threshold of every figure of the diagnostics page that can raise a health alert.
	 * Today's values of the filter `wplc_health_alert_thresholds`.
	 */
	public const ALERT_THRESHOLDS = array(
		'pending_webhooks'             => 1,
		'manual_review_webhooks'       => 1,
		'overdue_renewals'             => 1,
		'stuck_processing_renewals'    => 1,
		'paid_without_extension'       => 1,
		'failed_scheduled_actions'     => 1,
		'unmapped_license_products'    => 1,
		'review_required_renewals'     => 1,
		'orders_with_issuance_problem' => 1,
		'failed_jobs'                  => 1,
		'release_problems'             => 1,
		'rejected_webhook_minutes'     => 5,
		// SET-LIC-28: 0 while the reminder to rotate keys is off, so no alert until it is switched on.
		'keys_due_for_rotation'        => 1,
	);

	/**
	 * One setting `alert_<figure>` per alert threshold (SET-LIC-22).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function alert_thresholds(): array {
		$out = array();
		foreach ( self::ALERT_THRESHOLDS as $metric => $threshold ) {
			$out[ 'alert_' . $metric ] = array(
				'type'    => 'int',
				'default' => $threshold,
				'min'     => 0,
				'max'     => 100000,
				'group'   => 'operations',
				'filter'  => 'wplc_health_alert_thresholds',
			);
		}

		return $out;
	}
}
