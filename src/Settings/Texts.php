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
			'sharing'    => __( 'Co-users and transfer', 'wp-license' ),
			'account'    => __( 'Customer account', 'wp-license' ),
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
			'expired_download'          => array(
				__( 'Downloads after the paid period', 'wp-license' ),
				__( 'On (default): a customer whose paid period has ended can still download, in the account, the versions that were published while the license was paid. Off: no download at all after the end. Revoked, suspended and unpaid licenses never download.', 'wp-license' ),
			),
			'download_version_choices'  => array(
				__( 'Versions to choose from in the account', 'wp-license' ),
				__( 'How many published versions the customer can choose from when downloading in the account, newest first. Default: 10. 1 = only the latest version.', 'wp-license' ),
			),
			'downloads_per_hour'        => array(
				__( 'Downloads per license and hour', 'wp-license' ),
				__( 'How many downloads from the customer account one license may start within an hour. Default: 10. Updates installed by the customer sites do not count.', 'wp-license' ),
			),
			'download_reuse_seconds'    => array(
				__( 'Reuse of a download link', 'wp-license' ),
				__( 'Seconds during which a used download link of an update still works for a second fetch (some download managers and proxies fetch twice). Default: 0 = strict single use, the safest choice. At most 600.', 'wp-license' ),
			),
			'download_grant_seconds'    => array(
				__( 'Download link valid for', 'wp-license' ),
				__( 'Seconds during which the download link that a customer site receives for an update can be used. Default: 300 (five minutes); 60 to 900. The site asks for a new link right before it downloads, so a short time is enough.', 'wp-license' ),
			),
			's3_bucket'                 => array(
				__( 'Object storage: bucket', 'wp-license' ),
				__( 'Name of the private bucket that update packages are delivered from (S3-compatible storage such as Amazon S3 or Cloudflare R2). Empty (default) = no object storage. The access key ID and the secret stay in wp-config.php (WPLC_S3_ACCESS_KEY_ID, WPLC_S3_SECRET_ACCESS_KEY); without them the bucket is not used. When WPLC_S3_BUCKET is defined in wp-config.php, the constant wins and the field is read-only.', 'wp-license' ),
			),
			's3_region'                 => array(
				__( 'Object storage: region', 'wp-license' ),
				__( 'Region of the bucket, for example eu-central-1; auto for Cloudflare R2. Empty (default) = us-east-1. When WPLC_S3_REGION is defined in wp-config.php, the constant wins and the field is read-only.', 'wp-license' ),
			),
			's3_endpoint'               => array(
				__( 'Object storage: endpoint', 'wp-license' ),
				__( 'HTTPS address of the storage service without a path, for example https://ACCOUNT-ID.r2.cloudflarestorage.com. Empty (default) = Amazon S3 in the region above. When WPLC_S3_ENDPOINT is defined in wp-config.php, the constant wins and the field is read-only.', 'wp-license' ),
			),
			's3_path_style'             => array(
				__( 'Object storage: bucket in the path', 'wp-license' ),
				__( 'On: the bucket is addressed in the path of the address (https://endpoint/bucket/…), which some providers such as MinIO need. Off (default): the bucket is a subdomain of the endpoint; bucket names with dots or capital letters always use the path. When WPLC_S3_PATH_STYLE is defined in wp-config.php, the constant wins and the field is read-only.', 'wp-license' ),
			),
			// Owner decisions of 23.11.2026 (settings part 6).
			'beta_updates'              => array(
				__( 'Beta versions for licenses', 'wp-license' ),
				__( 'On (default, as before): licenses may receive beta versions when the customer site asks for them; with client library 2.4.0 the customer turns them on in the add-on. Off: only stable versions, unless a plan allows beta under "Rules of this plan" in the product editor. A plan can also forbid beta while this is on. A client without the right gets the newest stable version; the signed answer lists the channels of the license (update_channels).', 'wp-license' ),
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
				__( 'Largest quantity of one license product a customer can put into the cart and order. Every unit becomes a license of its own with its own key. Default: 100. 0 = no limit. An order you create yourself in the admin or through the REST API is not blocked; a larger quantity there is noted on the order and in the audit log. A plan can set its own largest quantity in the product editor.', 'wp-license' ),
			),
			'suspended_ends_after_days' => array(
				__( 'Suspended licenses count as ended after', 'wp-license' ),
				__( 'Default: 0 = never; a suspended license then keeps its customer and its data until you revoke it or it is reactivated. With a number of days, a license that stayed suspended without interruption for that long is treated like an expired or revoked one: a personal data erasure request detaches it from the customer and releases its sites, and the retention rules delete its installations and consent records after their periods. This cannot be undone. A license that was reactivated is never affected; a new suspension counts from its own start. Agree the period with your legal counsel.', 'wp-license' ),
			),
			'upgrade_quote_hours'       => array(
				__( 'Upgrade offer valid for', 'wp-license' ),
				__( 'Hours during which an unpaid upgrade order keeps its prorated price. The price falls every day; after this time the customer clicks "Upgrade" again for a new price. Default: 24.', 'wp-license' ),
			),
			'show_license_term'         => array(
				__( 'Show the license term at the price', 'wp-license' ),
				__( 'On (default): the price of a license product shows its term, for example "/ year". Off: the price is shown as WooCommerce prints it.', 'wp-license' ),
			),
			'renew_unsold_products'     => array(
				__( 'Renew licenses of products no longer sold', 'wp-license' ),
				__( 'Off (default): a customer cannot renew a license by hand in the account while its product is not for sale (private, draft or not purchasable). On: such a license can still be renewed at the price of its plan. Automatic renewals are not affected.', 'wp-license' ),
			),
			'renewal_off_on_card_deletion' => array(
				__( 'Deleting the saved card ends automatic renewal', 'wp-license' ),
				__( 'On (default): when the customer deletes the saved payment method in the account, through a personal data erasure or by deleting the account, automatic renewal of the licenses paid with it is switched off. Off: automatic renewal stays on, and the next charge fails unless another component moves the licenses to a new payment method.', 'wp-license' ),
			),
			'issue_after_completion_gateways' => array(
				__( 'Payment methods paid on delivery', 'wp-license' ),
				__( 'IDs of WooCommerce payment methods, one per line, whose orders count as paid only when they are completed, because the order is "processing" before the money arrived. Licenses, manual renewals and plan upgrades paid with them start when you complete the order. Default: cod (cash on delivery).', 'wp-license' ),
			),
			// Owner decisions of 23.11.2026 (settings part 6).
			'suspend_on_order_cancelled' => array(
				__( 'Cancelled or failed order suspends its licenses', 'wp-license' ),
				__( 'On (default): when a paid order is cancelled or fails afterwards (for example a bounced direct debit), the licenses it issued or renewed are suspended until you reactivate them. Off: the licenses stay as they are; check the order yourself. A full refund always revokes the licenses, whatever is set here.', 'wp-license' ),
			),
			'suspend_on_order_item_change' => array(
				__( 'Removed order line suspends its licenses', 'wp-license' ),
				__( 'On (default): when a line of a paid order is removed or its quantity lowered after the licenses were issued, the licenses above the new quantity are suspended until you reactivate them. Off: they stay as they are.', 'wp-license' ),
			),
			'partial_refund_review'     => array(
				__( 'Partial refund by amount: review notice', 'wp-license' ),
				__( 'On (default): a refund of part of the amount without refunding whole licenses changes no license, but asks you to review it: it is written to the order notes and the audit log of each license of the order, and the health alert "license needs review" is sent to the alert recipients. Off: no notice, as before (a renewal or upgrade order still gets its own order note). Refunding licenses by quantity revokes them in both cases.', 'wp-license' ),
			),
			'restore_after_won_dispute' => array(
				__( 'Won payment dispute reactivates the license', 'wp-license' ),
				__( 'Off (default): a license suspended because of a payment dispute stays suspended when the dispute is decided for the shop, until you reactivate it. On: it is reactivated automatically (only when it is still suspended because of that dispute and its paid period has not ended).', 'wp-license' ),
			),
			'plan_upgrades_enabled'     => array(
				__( 'Customers can upgrade their plan', 'wp-license' ),
				__( 'On (default): the customer account offers a more expensive plan of the same product; the customer pays the price difference for the rest of the paid period, prorated by day. Off: the offer is not shown and a request is refused. Administrators can always change the plan.', 'wp-license' ),
			),
			'plan_downgrades_enabled'   => array(
				__( 'Customers can switch to a cheaper plan', 'wp-license' ),
				__( 'On (default): the customer account offers a cheaper plan of the same product, which starts with the next renewal (no credit for the current period). Off: the offer is not shown and a request is refused; a switch already scheduled stays. Administrators can always change the plan.', 'wp-license' ),
			),
			'license_key_prefix'        => array(
				__( 'License key prefix', 'wp-license' ),
				__( 'The first four characters of every license key and license reference, for example ACME for ACME-1A2B3C4D. A letter followed by three letters or digits. Default: WPLC. Can only be changed before the first license is issued, because every key contains it; the length of the keys stays the same.', 'wp-license' ),
			),
			'offline_cache_days'        => array(
				__( 'Offline period of customer sites', 'wp-license' ),
				__( 'Days a customer site may rely on the last signed answer of this server when it cannot reach it. Default: 14. The period never reaches beyond the end of access of the license: a license whose paid period or grace period ends earlier stops working offline at that end. A plan can set its own period in the product editor.', 'wp-license' ),
			),
			'new_activations_per_hour'  => array(
				__( 'New sites per license and hour', 'wp-license' ),
				__( 'How many new installations one license may activate within an hour, at least twice its live-site limit, so that an agency can still set up all its sites. Activating a known installation again does not count. Default: 10. 0 = no limit.', 'wp-license' ),
			),
			'staging_sites_per_live'    => array(
				__( 'Staging sites per live site', 'wp-license' ),
				__( 'How many staging copies each live site of a license may activate free of charge. A staging site must be plausible for a copy of its live site (same domain, a local address or a hosting provider domain below); otherwise it counts as a live site. Default: 1. 0 = staging sites are not included. A plan can set its own number in the product editor.', 'wp-license' ),
			),
			'development_sites'         => array(
				__( 'Development sites per license', 'wp-license' ),
				__( 'How many development installations on local addresses a license may activate at the same time without using a live slot: at least this number and at least twice the live-site limit of the license. Default: 5. 0 = development sites are not included. A plan can set its own exact number in the product editor.', 'wp-license' ),
			),
			'staging_labels'            => array(
				__( 'Staging: name parts', 'wp-license' ),
				sprintf(
					/* translators: %s: list of the built-in name parts. */
					__( 'Parts of an address left of the domain that mark a copy of a site, one per line (for example staging for staging.shop.example). A live site cannot move to such an address with its activation; the copy activates as a staging site of its own. Only the part left of the domain counts: a top-level domain such as .dev never makes a site a staging copy. Default: %s.', 'wp-license' ),
					implode( ', ', Definitions::STAGING_LABELS )
				),
			),
			'staging_host_suffixes'     => array(
				__( 'Staging: hosting provider domains', 'wp-license' ),
				sprintf(
					/* translators: %s: list of the built-in domains. */
					__( 'Domains of hosting providers whose subdomains are temporary or staging addresses, one per line, without the leading dot (for example kinsta.cloud for mysite.kinsta.cloud). A subdomain of these domains is accepted as the staging copy of any live site. At least two parts (example.com); a single top-level domain is not accepted. Default: %s.', 'wp-license' ),
					implode( ', ', Definitions::STAGING_HOST_SUFFIXES )
				),
			),
			'local_host_suffixes'       => array(
				__( 'Local addresses: endings', 'wp-license' ),
				sprintf(
					/* translators: %s: list of the built-in endings. */
					__( 'Endings of addresses that only exist on a local computer or network, one per line, without the leading dot (for example test for shop.test). Development sites are only accepted on such addresses, on names without a dot and on private IP addresses; they also count as staging copies. Do not add an ending of the public internet (such as com): every site under it could then activate as a development site without using a live slot. Default: %s.', 'wp-license' ),
					implode( ', ', Definitions::LOCAL_HOST_SUFFIXES )
				),
			),
			'co_user_limit'             => array(
				__( 'Co-users per license', 'wp-license' ),
				__( 'How many co-users the owner of a license can invite in the customer account (accepted co-users plus open invitations). Co-users see the license with its key, sites and downloads in their own account. Default: 5. 0 = co-users are switched off: nobody can be invited and open invitations can no longer be accepted; co-users who accepted before keep their access until the owner removes them. A plan can set its own number in the product editor.', 'wp-license' ),
			),
			'invitation_days'           => array(
				__( 'Invitations valid for', 'wp-license' ),
				__( 'Days during which the link of an invitation (co-user or transfer) can be accepted. Default: 14. A changed value applies to invitations sent afterwards.', 'wp-license' ),
			),
			'transfers_enabled'         => array(
				__( 'Customers can transfer licenses', 'wp-license' ),
				__( 'On (default): the owner can hand a license over to another customer from the account; the recipient accepts with the link in an email. Off: the transfer form is hidden and open transfers can no longer be accepted; you can still change the owner in the admin.', 'wp-license' ),
			),
			'transfer_new_key'          => array(
				__( 'New license key on transfer', 'wp-license' ),
				__( 'Off (default): the license keeps its key and the activated sites keep working after a transfer accepted in the account. On: the transfer also issues a new key, so the previous owner can no longer use the old one; the new owner enters the new key on the sites.', 'wp-license' ),
			),
			'account_licenses_slug'     => array(
				__( 'Address of the license page', 'wp-license' ),
				__( 'Last part of the address of the license page in the customer account, for example licenses or lizenzen: lowercase letters, digits and hyphens. Default: wplc-licenses. The new address works at once; links in emails sent before lead to the old address and find no page. It must differ from the site page and from the other pages of the account.', 'wp-license' ),
			),
			'account_licenses_label'    => array(
				__( 'Menu item of the license page', 'wp-license' ),
				__( 'Name of the license page in the menu of the customer account and its heading. Empty (default) = "Licenses" in the language of the customer. Your own name is shown as it is in every language.', 'wp-license' ),
			),
			'account_licenses_per_page' => array(
				__( 'Licenses per page', 'wp-license' ),
				__( 'How many licenses the license page of the customer account shows at a time; further licenses are on the next pages. Default: 10.', 'wp-license' ),
			),
			'account_sites_slug'        => array(
				__( 'Address of the site page', 'wp-license' ),
				__( 'Last part of the address of the page "Sites" in the customer account, for example sites or websites: lowercase letters, digits and hyphens. Default: wplc-sites. It must differ from the license page and from the other pages of the account.', 'wp-license' ),
			),
			'account_sites_label'       => array(
				__( 'Menu item of the site page', 'wp-license' ),
				__( 'Name of the page "Sites" in the menu of the customer account. Empty (default) = "Sites" in the language of the customer. Your own name is shown as it is in every language.', 'wp-license' ),
			),
			'account_sites_per_page'    => array(
				__( 'Sites per page', 'wp-license' ),
				__( 'How many sites the page "Sites" of the customer account shows at a time. Default: 25.', 'wp-license' ),
			),
			'account_stale_days'        => array(
				__( 'Site counts as not seen after', 'wp-license' ),
				__( 'Days without contact after which the page "Sites" marks a site with "No contact for more than … days". Default: 14.', 'wp-license' ),
			),
			'account_expiry_days'       => array(
				__( 'License counts as ending soon', 'wp-license' ),
				__( 'Days before the end of the paid period from which the page "Sites" marks a license that does not renew automatically as ending soon. Default: 30.', 'wp-license' ),
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
			'public_cancel_hourly_limit' => array(
				__( 'Cancellation page: requests per hour', 'wp-license' ),
				__( 'How many cancellation requests the public cancellation page (shortcode [wplc_cancel]) accepts per hour, counted per visitor address, per license reference and, for order numbers, per email address. Default: 5. The page itself is shown where you place the shortcode. Whether it meets a legal requirement for a cancellation button in your country is for you to check.', 'wp-license' ),
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
			'retention_webhook_deliveries' => array(
				__( 'Keep the webhook delivery log for', 'wp-license' ),
				__( 'Days after which entries of the delivery log of the outgoing webhooks (event, body with record IDs, status code, error) are deleted. Default: 30. 0 = never delete by age; in any case at most the newest 100 entries per endpoint are kept.', 'wp-license' ),
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
			'key_rotation_reminder_months' => array(
				__( 'Remind to rotate keys after', 'wp-license' ),
				__( 'Months after which the license key secret, the installation secret encryption key and the response signing key should be replaced. A key that is older is shown on the diagnostics page, raises the health figure "Keys due for rotation" and a notice for administrators. Default: 0 = no reminder. The age counts from the day this server first saw the key (keys carry no date of their own; for keys that existed before, from the first day after the update that added this setting). Replacing a key stays a task for wp-config.php and WP-CLI.', 'wp-license' ),
			),
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
			'keys_due_for_rotation'        => __( 'Keys due for rotation', 'wp-license' ),
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
			case 'not_a_key_prefix':
				/* translators: %s: name of a setting. */
				return sprintf( __( '"%s" was not changed: enter four characters, a letter followed by letters or digits, for example ACME.', 'wp-license' ), $label );
			case 'locked':
				/* translators: %s: name of a setting. */
				return sprintf( __( '"%s" was not changed: licenses were already issued with the current value, and their keys would stop working.', 'wp-license' ), $label );
			case 'invalid_entries':
			case 'invalid_hosts':
				/* translators: %s: name of a setting. */
				return sprintf( __( '"%s" was not changed: at least one entry is not valid. Enter one entry per line, lowercase letters, digits, dots and hyphens only.', 'wp-license' ), $label );
		}

		/* translators: %s: name of a setting. */
		return sprintf( __( '"%s" was not changed: the value is not valid.', 'wp-license' ), $label );
	}
}
