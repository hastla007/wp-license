<?php
/**
 * Labels and help texts of the settings.
 *
 * @package WPLC
 */

namespace WPLC\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Translated texts for the settings page, previews and WP-CLI. Kept apart from the
 * definitions so that reading a value never loads translations.
 */
final class Texts {

	/**
	 * Titles of the groups (tabs of the settings page).
	 *
	 * @return array<string,string> Group key => title.
	 */
	public static function groups(): array {
		return array(
			'updates'    => __( 'Updates', 'wp-license' ),
			'renewals'   => __( 'Automatic renewal', 'wp-license' ),
			'emails'     => __( 'Customer emails', 'wp-license' ),
			'rules'      => __( 'Rules of this shop', 'wp-license' ),
			'sites'      => __( 'Customer sites', 'wp-license' ),
			'legal'      => __( 'Legal and privacy', 'wp-license' ),
			'texts'      => __( 'Texts and support', 'wp-license' ),
			'operations' => __( 'Operations', 'wp-license' ),
		);
	}

	/**
	 * The sentence added to the help of every setting with legal weight.
	 *
	 * @return string
	 */
	public static function legal_note(): string {
		return __( 'This value has legal weight. The default is not legal advice; have it checked for your country (legal review).', 'wp-license' );
	}

	/**
	 * Label of a setting.
	 *
	 * @param string $key Setting key.
	 * @return string Empty for an unknown key.
	 */
	public static function label( string $key ): string {
		$all = self::all();

		return isset( $all[ $key ] ) ? $all[ $key ][0] : '';
	}

	/**
	 * Help text of a setting.
	 *
	 * @param string $key Setting key.
	 * @return string Empty for an unknown key.
	 */
	public static function help( string $key ): string {
		$all = self::all();

		return isset( $all[ $key ] ) ? $all[ $key ][1] : '';
	}

	/**
	 * Label and help of every setting.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function all(): array {
		return array(
			'package_hosts'             => array(
				__( 'Additional trusted package hosts', 'wp-license' ),
				__( 'One host name per line, for example downloads.example.com. Update packages are only delivered from HTTPS URLs on these hosts. This site is always trusted.', 'wp-license' ),
			),
			'catalog_auto_import'       => array(
				__( 'Catalog file of release packages', 'wp-license' ),
				__( 'When a release is created from a ZIP, the file wplc-catalog.json in the plugin folder of the package is checked and imported into the license catalog. The release is created in any case; a file that is invalid or describes another product is not imported and reported. Plans and licenses are never changed.', 'wp-license' ),
			),
			'combined_renewals'         => array(
				__( 'Combine automatic renewals', 'wp-license' ),
				__( 'Only licenses with the same saved card, currency and billing interval are combined. Each license keeps its own renewal; a declined payment is retried per license. Manual renewals are never combined. Off by default.', 'wp-license' ),
			),
			'combined_renewal_max_lines' => array(
				__( 'Combined renewal: most licenses per order', 'wp-license' ),
				__( 'Largest number of licenses charged together in one combined renewal order. Further due licenses of the same customer are renewed in the next combined order or on their own. Default: 20. Only used when automatic renewals are combined.', 'wp-license' ),
			),
			'renewal_grace_days'        => array(
				__( 'Grace period after the paid period', 'wp-license' ),
				__( 'Days after the end of the paid period during which a license with a pending or failed automatic charge keeps its access. Default: 14. Keep it longer than the last retry day below, or the last attempts fall after access has ended. A changed value applies to renewals scheduled afterwards; renewals already scheduled keep their date.', 'wp-license' ),
			),
			'renewal_retry_days'        => array(
				__( 'Retry days of a failed charge', 'wp-license' ),
				__( 'Days after the start of the new period on which a failed automatic charge is tried again, separated by commas. The number of entries is the number of retries. Default: 1,3,7. Empty = no retry: the first failed charge is final.', 'wp-license' ),
			),
			'manual_renewal_days_before' => array(
				__( 'Manual renewal: days before the end', 'wp-license' ),
				__( 'From this many days before the end of the paid period a customer can renew by hand in the account, and the emails offer it. Default: 30. 0 = only from the day the period ends.', 'wp-license' ),
			),
			'manual_renewal_days_after' => array(
				__( 'Manual renewal: days after the end', 'wp-license' ),
				__( 'Until this many days after the end of the paid period an expired license can still be renewed and continues with the same key and the same sites. Default: 30. 0 = an expired license cannot be reactivated by the customer.', 'wp-license' ),
			),
			'review_deferral_days'      => array(
				__( 'Open review case defers the expiry for', 'wp-license' ),
				__( 'Days, counted from the start of the case, during which a renewal that waits for your review (unknown payment result) keeps its license from expiring, because the customer may have paid. Access to updates still ends with the grace period. Afterwards the license expires normally; the case stays open and can still be booked. Default: 30.', 'wp-license' ),
			),
			'reminder_days'             => array(
				__( 'Reminder lead days', 'wp-license' ),
				__( 'Days before the end of the paid period on which a reminder is sent, separated by commas. Default: 30,7.', 'wp-license' ),
			),
			'renewal_notice_days'       => array(
				__( 'Renewal notice lead days', 'wp-license' ),
				__( 'Days before an automatic charge on which the customer is told the date and the amount. Default: 7. A license is only charged after this email was accepted for sending; otherwise the charge is postponed and reported under Diagnostics.', 'wp-license' ),
			),
			'price_change_notice_days'  => array(
				__( 'Price change notice period', 'wp-license' ),
				__( 'Days between the email that announces a higher renewal price for an existing license and the first renewal at that price. Default: 60, at least 30. Have the period and the wording of the email checked by a lawyer.', 'wp-license' ),
			),
			'sender_note'               => array(
				__( 'Sender note', 'wp-license' ),
				__( 'Optional short text that license emails may show below their content, for example a support contact.', 'wp-license' ),
			),
			'max_quantity_per_line'     => array(
				__( 'Largest quantity per order line', 'wp-license' ),
				__( 'Largest quantity of one license product a customer can put into the cart and order. Every unit becomes a license of its own with its own key. Default: 100. 0 = no limit. An order you create yourself in the admin or through the REST API is not blocked; a larger quantity there is noted on the order and in the audit log.', 'wp-license' ),
			),
			'suspended_ends_after_days' => array(
				__( 'Suspended licenses count as ended after', 'wp-license' ),
				__( 'Default: 0 = never; a suspended license then keeps its customer and its data until you revoke it or it is reactivated. With a number of days, a license that stayed suspended without interruption for that long is treated like an expired or revoked one: a personal data erasure request detaches it from the customer and releases its sites, and the retention rules delete its installations and consent records after their periods. This cannot be undone. A license that was reactivated is never affected; a new suspension counts from its own start. Agree the period with your legal counsel.', 'wp-license' ),
			),
			'upgrade_quote_hours'       => array(
				__( 'Upgrade offer valid for', 'wp-license' ),
				__( 'Hours during which an unpaid upgrade order keeps its prorated price. The price falls every day; after this time the customer clicks "Upgrade" again for a new price. Default: 24.', 'wp-license' ),
			),
			'offline_cache_days'        => array(
				__( 'Offline period of customer sites', 'wp-license' ),
				__( 'Days a customer site may rely on the last signed answer of this server when it cannot reach it. Default: 14. The period never reaches beyond the end of access of the license: a license whose paid period or grace period ends earlier stops working offline at that end.', 'wp-license' ),
			),
			'new_activations_per_hour'  => array(
				__( 'New sites per license and hour', 'wp-license' ),
				__( 'How many new installations one license may activate within an hour, at least twice its live-site limit, so that an agency can still set up all its sites. Activating a known installation again does not count. Default: 10. 0 = no limit.', 'wp-license' ),
			),
			'withdrawal_consent_mode'   => array(
				__( 'Consent to the immediate start (right of withdrawal)', 'wp-license' ),
				__( 'Whether the checkout asks the customer to agree that the license is provided before the withdrawal period ends. "Automatic" (default) asks unless Germanized or German Market is active, because these plugins ask themselves.', 'wp-license' ),
			),
			'withdrawal_consent_required' => array(
				__( 'The consent is required to buy', 'wp-license' ),
				__( 'On (default): a license can only be bought with the consent. Off: the box is optional and only recorded when ticked; the customer then keeps the right of withdrawal.', 'wp-license' ),
			),
			'withdrawal_consent_text'   => array(
				__( 'Wording of the withdrawal consent', 'wp-license' ),
				__( 'Text next to the checkbox. Empty (default) = the built-in wording in the language of the customer. Your own wording is shown as it is in every language. The text the customer saw is stored with the order as proof.', 'wp-license' ),
			),
			'recurring_consent_text'    => array(
				__( 'Wording of the consent to automatic renewal', 'wp-license' ),
				__( 'Text next to the checkbox for automatic renewal, in the classic and in the block checkout. Empty (default) = the built-in wording in the language of the customer. Your own wording is shown as it is in every language. The text the customer saw is stored as proof of the consent.', 'wp-license' ),
			),
			'retention_audit_log' => array(
				__( 'Keep the audit log for', 'wp-license' ),
				__( 'Days after which entries of the audit log are deleted. Default: 730. 0 = never delete.', 'wp-license' ),
			),
			'retention_event_inbox' => array(
				__( 'Keep processed payment events for', 'wp-license' ),
				__( 'Days after which payment events that were processed completely are deleted. Default: 180. 0 = never delete.', 'wp-license' ),
			),
			'retention_event_inbox_open' => array(
				__( 'Keep unresolved payment events for', 'wp-license' ),
				__( 'Days after which payment events that still need work are deleted. Default: 730. 0 = never delete.', 'wp-license' ),
			),
			'retention_activations' => array(
				__( 'Keep deactivated sites of ended licenses for', 'wp-license' ),
				__( 'Days after which deactivated installations of ended licenses are deleted. Default: 365. 0 = never delete.', 'wp-license' ),
			),
			'retention_inactive_activations' => array(
				__( 'Keep deactivated sites of running licenses for', 'wp-license' ),
				__( 'Days after which deactivated installations of running licenses are deleted. Default: 365. 0 = never delete.', 'wp-license' ),
			),
			'retention_ended_license_activations' => array(
				__( 'Switch off sites of an ended license after', 'wp-license' ),
				__( 'Days after the paid period of an expired license at which its still active installations are deactivated. Default: 30. 0 = never.', 'wp-license' ),
			),
			'retention_reminder_log' => array(
				__( 'Keep the email log for', 'wp-license' ),
				__( 'Days after which entries of the notification log are deleted. Default: 730. 0 = never delete.', 'wp-license' ),
			),
			'retention_anonymize_licenses' => array(
				__( 'Detach ended licenses from the customer after', 'wp-license' ),
				__( 'Days after the end of a license at which the owner, the payment token reference and the installations are removed. Counted from the end of the calendar year in which the license ended. Default: 3650 (ten years, the technical default so far). 0 = never.', 'wp-license' ),
			),
			'retention_consents' => array(
				__( 'Keep proof of renewal consent for', 'wp-license' ),
				__( 'Days after which the proof of a consent to automatic renewal is deleted, once its license has ended. Counted from the end of the calendar year of the consent. Default: 3650 (ten years, the technical default so far). 0 = never delete.', 'wp-license' ),
			),
			'support_email'             => array(
				__( 'Support email address', 'wp-license' ),
				__( 'Address that license emails, the customer account and the cancellation page name for questions. Empty (default) = the sender address of the WooCommerce emails, else the address of the site administrator.', 'wp-license' ),
			),
			'support_url'               => array(
				__( 'Support page', 'wp-license' ),
				__( 'Optional address of your support or contact page (https://…). The customer account and the cancellation page link to it next to the support address. Empty (default) = no link.', 'wp-license' ),
			),
			'health_alert_recipients'   => array(
				__( 'Send health alerts to', 'wp-license' ),
				__( 'Email addresses, separated by commas, that get a message when a figure of the diagnostics page reaches its alert threshold (for example failed background jobs or rejected webhooks). At most one collected email per day. Empty (default) = no email; the alerts are then only available to code (action wplc/health_alert).', 'wp-license' ),
			),
		) + self::alert_threshold_texts() + array(
			'debug_log'                 => array(
				__( 'Verbose log', 'wp-license' ),
				__( 'Writes additional lines of the level debug to the WooCommerce log (source wp-license) to trace a problem. It switches itself off after the chosen time. Off (default). The constant WPLC_DEBUG in wp-config.php switches it on permanently.', 'wp-license' ),
			),
			'uninstall_data'            => array(
				__( 'When the plugin is deleted', 'wp-license' ),
				__( 'Keep (default): licenses, activations, renewals, the audit log and the settings stay in the database when the plugin is deleted, because they are business records. Delete: the tables and options of the plugin are removed for good when it is deleted; uploaded packages and order data are never removed. The constant WPLC_REMOVE_ALL_DATA in wp-config.php also deletes. Deleting cannot be undone; make a backup first.', 'wp-license' ),
			),
		);
	}

	/**
	 * Names of the figures of the diagnostics page that can raise a health alert.
	 *
	 * @return array<string,string> Figure key => name.
	 */
	public static function alert_metrics(): array {
		return array(
			'pending_webhooks'             => __( 'Pending Stripe events', 'wp-license' ),
			'manual_review_webhooks'       => __( 'Stripe events needing review (24 hours)', 'wp-license' ),
			'overdue_renewals'             => __( 'Stuck renewals (job not run, grace period over)', 'wp-license' ),
			'stuck_processing_renewals'    => __( 'Renewals stuck in processing (over 1 hour)', 'wp-license' ),
			'paid_without_extension'       => __( 'Paid renewals without extension', 'wp-license' ),
			'failed_scheduled_actions'     => __( 'Failed scheduled actions (group wplc)', 'wp-license' ),
			'unmapped_license_products'    => __( 'License products without a plan', 'wp-license' ),
			'review_required_renewals'     => __( 'Renewals needing review (unknown payment result, payment not applied)', 'wp-license' ),
			'orders_with_issuance_problem' => __( 'Paid orders without their licenses', 'wp-license' ),
			'failed_jobs'                  => __( 'Failed background jobs (24 hours)', 'wp-license' ),
			'release_problems'             => __( 'Problems with update packages', 'wp-license' ),
			'rejected_webhook_minutes'     => __( 'Minutes with rejected Stripe webhook requests (24 hours)', 'wp-license' ),
		);
	}

	/**
	 * Label and help of the alert thresholds (`alert_<figure>`).
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	private static function alert_threshold_texts(): array {
		$out = array();
		foreach ( self::alert_metrics() as $metric => $name ) {
			$out[ 'alert_' . $metric ] = array(
				/* translators: %s: name of a figure of the diagnostics page. */
				sprintf( __( 'Alert from: %s', 'wp-license' ), $name ),
				sprintf(
					/* translators: %s: default threshold. */
					__( 'A health alert is raised when this figure of the diagnostics page reaches this number: the action wplc/health_alert fires and, with recipients above, the collected email is sent. Default: %s. 0 = never alert for this figure.', 'wp-license' ),
					(string) Definitions::ALERT_THRESHOLDS[ $metric ]
				),
			);
		}

		return $out;
	}

	/**
	 * Labels of the choices of a setting of type `choice`.
	 *
	 * @param string $key Setting key.
	 * @return array<string,string> Choice => label.
	 */
	public static function choices( string $key ): array {
		/**
		 * Labels by setting.
		 *
		 * @var array<string,array<string,string>> $all
		 */
		$all = array(
			'withdrawal_consent_mode' => array(
				'auto' => __( 'Automatic: ask, unless Germanized or German Market is active', 'wp-license' ),
				'on'   => __( 'Always ask', 'wp-license' ),
				'off'  => __( 'Never ask', 'wp-license' ),
			),
			'debug_log'               => array(
				'off' => __( 'Off', 'wp-license' ),
				'24h' => __( 'On for 24 hours', 'wp-license' ),
				'7d'  => __( 'On for 7 days', 'wp-license' ),
			),
			'uninstall_data'          => array(
				'keep'   => __( 'Keep all data', 'wp-license' ),
				'delete' => __( 'Delete tables and options', 'wp-license' ),
			),
		);

		return $all[ $key ] ?? array();
	}

	/**
	 * Translated message for a rejected value (Registry::validate() reports the code).
	 *
	 * @param string $key  Setting key.
	 * @param string $code Problem code of Registry::validate().
	 * @return string
	 */
	public static function problem( string $key, string $code ): string {
		$definition = Registry::definition( $key );
		$label      = self::label( $key );
		$min        = (int) ( $definition['min'] ?? 0 );
		$max        = (int) ( $definition['max'] ?? 0 );
		switch ( $code ) {
			case 'not_a_number':
				/* translators: %s: name of a setting. */
				return sprintf( __( '"%s" was not changed: enter a whole number.', 'wp-license' ), $label );
			case 'out_of_range':
				/* translators: 1: name of a setting, 2: smallest value, 3: largest value. */
				return sprintf( __( '"%1$s" was not changed: enter whole numbers from %2$s to %3$s.', 'wp-license' ), $label, number_format_i18n( $min ), number_format_i18n( $max ) );
			case 'too_many':
				/* translators: 1: name of a setting, 2: largest number of entries. */
				return sprintf( __( '"%1$s" was not changed: at most %2$s entries are possible.', 'wp-license' ), $label, number_format_i18n( (int) ( $definition['max_items'] ?? 0 ) ) );
			case 'too_long':
				/* translators: 1: name of a setting, 2: largest number of characters. */
				return sprintf( __( '"%1$s" was not changed: the text is longer than %2$s characters.', 'wp-license' ), $label, number_format_i18n( (int) ( $definition['max_length'] ?? 500 ) ) );
			case 'not_an_email':
				/* translators: %s: name of a setting. */
				return sprintf( __( '"%s" was not changed: enter valid email addresses.', 'wp-license' ), $label );
			case 'not_a_url':
				/* translators: %s: name of a setting. */
				return sprintf( __( '"%s" was not changed: enter an address that starts with https://.', 'wp-license' ), $label );
			case 'invalid_entries':
			case 'invalid_hosts':
				/* translators: %s: name of a setting. */
				return sprintf( __( '"%s" was not changed: at least one entry is not valid. Enter one entry per line, lowercase letters, digits, dots and hyphens only.', 'wp-license' ), $label );
		}

		/* translators: %s: name of a setting. */
		return sprintf( __( '"%s" was not changed: the value is not valid.', 'wp-license' ), $label );
	}
}
