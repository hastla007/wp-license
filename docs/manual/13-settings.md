# 13. Settings

Everything a shop decides for itself (periods, limits, texts, retention) is a setting under **WooCommerce → License settings**. This chapter lists every setting. Secrets are not settings: they stay in `wp-config.php` (chapter 2) and never appear on the screen, in an export or in the change log.

## How settings work

- **One register.** Every setting is described once in the code (`src/Settings/Definitions.php`): key, kind of value, default, limits, group, the capability needed to change it and the filter that belongs to it. The settings page, export and import, reset, WP-CLI and the change log all use this register.
- **Defaults are the behaviour before the setting existed.** Updating the plugin changes nothing until you change a value.
- **Order of precedence:** built-in default, then the stored setting, then the PHP filter named in the table. The filter decides last, so code of the shop wins over the screen. When a filter changes a value, the field says "In effect: …".
- **A value that cannot be accepted is reported, not silently corrected.** The stored value stays and the screen names the field. (The settings that existed before this register keep their earlier behaviour: a number outside its limits is moved to the nearest limit.)
- **Tabs.** "All settings" shows everything on one page; each tab shows one group. Saving a tab changes only the fields of that tab.
- **Who may change what.** The settings need the capability `wplc_manage_settings` (by default everyone who may manage WooCommerce). **Manufacturer rules** act on every customer site (staging and development sites, offline period, downloads, co-users and transfer) and need `wplc_manage_policy` (by default administrators). The trusted package hosts need `wplc_manage_package_hosts` (by default administrators), because update packages for all customer sites are delivered from them. Users without the capability see these values read-only. **Who may do what** below the status table of the settings page lists every capability of the plugin, its default and whether you hold it, and names the settings that need more than `wplc_manage_settings`.
- **Change log.** Every save writes one `settings_updated` entry to the audit log with the old and the new value of each changed setting. Long texts are shortened.
- **Legal weight.** Some values have legal weight (periods, consent wording, retention). Their help text says "legal review". The defaults are not legal advice.
- **Multisite.** Settings are stored per site; there are no network defaults.

## All settings

The tables are generated from the register (`php bin/settings-doc.php --write`).

<!-- settings:start -->

### Updates

| Setting | Key | Value | Default | Filter (wins) | What it does |
| --- | --- | --- | --- | --- | --- |
| Additional trusted package hosts | `package_hosts` | host names, one per line | (empty) | `wplc_update_package_hosts` | One host name per line, for example downloads.example.com. Update packages are only delivered from HTTPS URLs on these hosts. This site is always trusted. Administrators only. |
| Catalog file of release packages | `catalog_auto_import` | on or off | `on` | `wplc_catalog_auto_import` | When a release is created from a ZIP, the file wplc-catalog.json in the plugin folder of the package is checked and imported into the license catalog. The release is created in any case; a file that is invalid or describes another product is not imported and reported. Plans and licenses are never changed. |

### Automatic renewal

| Setting | Key | Value | Default | Filter (wins) | What it does |
| --- | --- | --- | --- | --- | --- |
| Combine automatic renewals | `combined_renewals` | on or off | `off` | `wplc_combined_renewals` | Only licenses with the same saved card, currency and billing interval are combined. Each license keeps its own renewal; a declined payment is retried per license. Manual renewals are never combined. Off by default. |
| Combined renewal: most licenses per order | `combined_renewal_max_lines` | whole number, 2 to 100 | `20` | – | Largest number of licenses charged together in one combined renewal order. Further due licenses of the same customer are renewed in the next combined order or on their own. Default: 20. Only used when automatic renewals are combined. |
| Grace period after the paid period | `renewal_grace_days` | whole number, 1 to 90 | `14` | `wplc_renewal_grace_days` | Days after the end of the paid period during which a license with a pending or failed automatic charge keeps its access. Default: 14. Keep it longer than the last retry day below, or the last attempts fall after access has ended. A changed value applies to renewals scheduled afterwards; renewals already scheduled keep their date. |
| Retry days of a failed charge | `renewal_retry_days` | up to 6 whole numbers, each 1 to 60 | `1,3,7` | `wplc_renewal_retry_days` | Days after the start of the new period on which a failed automatic charge is tried again, separated by commas. The number of entries is the number of retries. Default: 1,3,7. Empty = no retry: the first failed charge is final. |
| Manual renewal: days before the end | `manual_renewal_days_before` | whole number, 0 to 365 | `30` | `wplc_manual_renewal_window_days` | From this many days before the end of the paid period a customer can renew by hand in the account, and the emails offer it. Default: 30. 0 = only from the day the period ends. |
| Manual renewal: days after the end | `manual_renewal_days_after` | whole number, 0 to 365 | `30` | `wplc_manual_renewal_window_days` | Until this many days after the end of the paid period an expired license can still be renewed and continues with the same key and the same sites. Default: 30. 0 = an expired license cannot be reactivated by the customer. |
| Open review case defers the expiry for | `review_deferral_days` | whole number, 1 to 365 | `30` | – | Days, counted from the start of the case, during which a renewal that waits for your review (unknown payment result) keeps its license from expiring, because the customer may have paid. Access to updates still ends with the grace period. Afterwards the license expires normally; the case stays open and can still be booked. Default: 30. |

### Customer emails

| Setting | Key | Value | Default | Filter (wins) | What it does |
| --- | --- | --- | --- | --- | --- |
| Reminder lead days | `reminder_days` | up to 6 whole numbers, each 1 to 365 | `30,7` | `wplc_reminder_lead_days` | Days before the end of the paid period on which a reminder is sent, separated by commas. Default: 30,7. |
| Renewal notice lead days | `renewal_notice_days` | whole number, 1 to 60 | `7` | `wplc_renewal_notice_lead_days` | Days before an automatic charge on which the customer is told the date and the amount. Default: 7. A license is only charged after this email was accepted for sending; otherwise the charge is postponed and reported under Diagnostics. |
| Price change notice period | `price_change_notice_days` | whole number, 30 to 365 | `60` | `wplc_price_change_notice_days` | Days between the email that announces a higher renewal price for an existing license and the first renewal at that price. Default: 60, at least 30. Have the period and the wording of the email checked by a lawyer. **Legal review.** |
| Sender note | `sender_note` | text, at most 500 characters | (empty) | – | Optional short text that license emails may show below their content, for example a support contact. |

### Rules of this shop

| Setting | Key | Value | Default | Filter (wins) | What it does |
| --- | --- | --- | --- | --- | --- |
| Largest quantity per order line | `max_quantity_per_line` | whole number, 0 to 100000 | `100` | `wplc_max_license_quantity_per_line` | Largest quantity of one license product a customer can put into the cart and order. Every unit becomes a license of its own with its own key. Default: 100. 0 = no limit. An order you create yourself in the admin or through the REST API is not blocked; a larger quantity there is noted on the order and in the audit log. |
| Suspended licenses count as ended after | `suspended_ends_after_days` | whole number, 0 to 3650 | `0` | `wplc_suspended_license_ends_after_days` | Default: 0 = never; a suspended license then keeps its customer and its data until you revoke it or it is reactivated. With a number of days, a license that stayed suspended without interruption for that long is treated like an expired or revoked one: a personal data erasure request detaches it from the customer and releases its sites, and the retention rules delete its installations and consent records after their periods. This cannot be undone. A license that was reactivated is never affected; a new suspension counts from its own start. Agree the period with your legal counsel. **Legal review.** |
| Upgrade offer valid for | `upgrade_quote_hours` | whole number, 1 to 168 | `24` | – | Hours during which an unpaid upgrade order keeps its prorated price. The price falls every day; after this time the customer clicks "Upgrade" again for a new price. Default: 24. |

### Customer sites

| Setting | Key | Value | Default | Filter (wins) | What it does |
| --- | --- | --- | --- | --- | --- |
| Offline period of customer sites | `offline_cache_days` | whole number, 1 to 90 | `14` | `wplc_offline_cache_days` | Days a customer site may rely on the last signed answer of this server when it cannot reach it. Default: 14. The period never reaches beyond the end of access of the license: a license whose paid period or grace period ends earlier stops working offline at that end. Manufacturer rule (`wplc_manage_policy`, by default administrators). |
| New sites per license and hour | `new_activations_per_hour` | whole number, 0 to 1000 | `10` | `wplc_new_activations_per_hour` | How many new installations one license may activate within an hour, at least twice its live-site limit, so that an agency can still set up all its sites. Activating a known installation again does not count. Default: 10. 0 = no limit. |
| Staging sites per live site | `staging_sites_per_live` | whole number, 0 to 10 | `1` | `wplc_staging_site_limit` | How many staging copies each live site of a license may activate free of charge. A staging site must be plausible for a copy of its live site (same domain, a local address or a hosting provider domain below); otherwise it counts as a live site. Default: 1. 0 = staging sites are not included. A plan can set its own number in the product editor. Manufacturer rule (`wplc_manage_policy`, by default administrators). |
| Development sites per license | `development_sites` | whole number, 0 to 100 | `5` | `wplc_development_site_limit` | How many development installations on local addresses a license may activate at the same time without using a live slot: at least this number and at least twice the live-site limit of the license. Default: 5. 0 = development sites are not included. A plan can set its own exact number in the product editor. Manufacturer rule (`wplc_manage_policy`, by default administrators). |
| Staging: name parts | `staging_labels` | entries, one per line, at most 50 | `staging, stage, stg, dev, test, testing, preprod, preview, sandbox, uat` | `wplc_staging_labels` | Parts of an address left of the domain that mark a copy of a site, one per line (for example staging for staging.shop.example). A live site cannot move to such an address with its activation; the copy activates as a staging site of its own. Only the part left of the domain counts: a top-level domain such as .dev never makes a site a staging copy. Default: staging, stage, stg, dev, test, testing, preprod, preview, sandbox, uat. Manufacturer rule (`wplc_manage_policy`, by default administrators). |
| Staging: hosting provider domains | `staging_host_suffixes` | entries, one per line, at most 100 | `wpengine.com, wpenginepowered.com, kinsta.cloud, pantheonsite.io, cloudwaysapps.com, flywheelsites.com, flywheelstaging.…` | `wplc_staging_host_suffixes` | Domains of hosting providers whose subdomains are temporary or staging addresses, one per line, without the leading dot (for example kinsta.cloud for mysite.kinsta.cloud). A subdomain of these domains is accepted as the staging copy of any live site. At least two parts (example.com); a single top-level domain is not accepted. Default: wpengine.com, wpenginepowered.com, kinsta.cloud, pantheonsite.io, cloudwaysapps.com, flywheelsites.com, flywheelstaging.com, myftpupload.com, sg-host.com, wpstage.net, wpcomstaging.com, instawp.xyz, tastewp.com, runcloud.link, ngrok.io, ngrok-free.app. Manufacturer rule (`wplc_manage_policy`, by default administrators). |
| Local addresses: endings | `local_host_suffixes` | entries, one per line, at most 50 | `localhost, test, local, internal, lan, home.arpa, localdomain, ddev.site, lndo.site` | `wplc_local_host_suffixes` | Endings of addresses that only exist on a local computer or network, one per line, without the leading dot (for example test for shop.test). Development sites are only accepted on such addresses, on names without a dot and on private IP addresses; they also count as staging copies. Do not add an ending of the public internet (such as com): every site under it could then activate as a development site without using a live slot. Default: localhost, test, local, internal, lan, home.arpa, localdomain, ddev.site, lndo.site. Manufacturer rule (`wplc_manage_policy`, by default administrators). |

### Legal and privacy

| Setting | Key | Value | Default | Filter (wins) | What it does |
| --- | --- | --- | --- | --- | --- |
| Consent to the immediate start (right of withdrawal) | `withdrawal_consent_mode` | one of `auto`, `on`, `off` | `auto` | `wplc_withdrawal_consent_enabled` | Whether the checkout asks the customer to agree that the license is provided before the withdrawal period ends. "Automatic" (default) asks unless Germanized or German Market is active, because these plugins ask themselves. **Legal review.** |
| The consent is required to buy | `withdrawal_consent_required` | on or off | `on` | `wplc_withdrawal_consent_required` | On (default): a license can only be bought with the consent. Off: the box is optional and only recorded when ticked; the customer then keeps the right of withdrawal. **Legal review.** |
| Wording of the withdrawal consent | `withdrawal_consent_text` | text, at most 1000 characters | (empty) | `wplc_withdrawal_consent_text` | Text next to the checkbox. Empty (default) = the built-in wording in the language of the customer. Your own wording is shown as it is in every language. The text the customer saw is stored with the order as proof. **Legal review.** |
| Wording of the consent to automatic renewal | `recurring_consent_text` | text, at most 1000 characters | (empty) | `wplc_recurring_consent_text` | Text next to the checkbox for automatic renewal, in the classic and in the block checkout. Empty (default) = the built-in wording in the language of the customer. Your own wording is shown as it is in every language. The text the customer saw is stored as proof of the consent. **Legal review.** |
| Keep the audit log for | `retention_audit_log` | whole number, 0 to 36500 | `730` | `wplc_retention_days` | Days after which entries of the audit log are deleted. Default: 730. 0 = never delete. **Legal review.** |
| Keep processed payment events for | `retention_event_inbox` | whole number, 0 to 36500 | `180` | `wplc_retention_days` | Days after which payment events that were processed completely are deleted. Default: 180. 0 = never delete. **Legal review.** |
| Keep unresolved payment events for | `retention_event_inbox_open` | whole number, 0 to 36500 | `730` | `wplc_retention_days` | Days after which payment events that still need work are deleted. Default: 730. 0 = never delete. **Legal review.** |
| Keep deactivated sites of ended licenses for | `retention_activations` | whole number, 0 to 36500 | `365` | `wplc_retention_days` | Days after which deactivated installations of ended licenses are deleted. Default: 365. 0 = never delete. **Legal review.** |
| Keep deactivated sites of running licenses for | `retention_inactive_activations` | whole number, 0 to 36500 | `365` | `wplc_retention_days` | Days after which deactivated installations of running licenses are deleted. Default: 365. 0 = never delete. **Legal review.** |
| Switch off sites of an ended license after | `retention_ended_license_activations` | whole number, 0 to 36500 | `30` | `wplc_retention_days` | Days after the paid period of an expired license at which its still active installations are deactivated. Default: 30. 0 = never. **Legal review.** |
| Keep the email log for | `retention_reminder_log` | whole number, 0 to 36500 | `730` | `wplc_retention_days` | Days after which entries of the notification log are deleted. Default: 730. 0 = never delete. **Legal review.** |
| Detach ended licenses from the customer after | `retention_anonymize_licenses` | whole number, 0 to 36500 | `3650` | `wplc_retention_days` | Days after the end of a license at which the owner, the payment token reference and the installations are removed. Counted from the end of the calendar year in which the license ended. Default: 3650 (ten years, the technical default so far). 0 = never. **Legal review.** |
| Keep proof of renewal consent for | `retention_consents` | whole number, 0 to 36500 | `3650` | `wplc_retention_days` | Days after which the proof of a consent to automatic renewal is deleted, once its license has ended. Counted from the end of the calendar year of the consent. Default: 3650 (ten years, the technical default so far). 0 = never delete. **Legal review.** |

### Texts and support

| Setting | Key | Value | Default | Filter (wins) | What it does |
| --- | --- | --- | --- | --- | --- |
| Support email address | `support_email` | text, at most 200 characters | (empty) | `wplc_support_email` | Address that license emails, the customer account and the cancellation page name for questions. Empty (default) = the sender address of the WooCommerce emails, else the address of the site administrator. |
| Support page | `support_url` | text, at most 500 characters | (empty) | `wplc_support_url` | Optional address of your support or contact page (https://…). The customer account and the cancellation page link to it next to the support address. Empty (default) = no link. |

### Operations

| Setting | Key | Value | Default | Filter (wins) | What it does |
| --- | --- | --- | --- | --- | --- |
| Send health alerts to | `health_alert_recipients` | text, at most 500 characters | (empty) | `wplc_health_alert_recipients` | Email addresses, separated by commas, that get a message when a figure of the diagnostics page reaches its alert threshold (for example failed background jobs or rejected webhooks). At most one collected email per day. Empty (default) = no email; the alerts are then only available to code (action wplc/health_alert). |
| Alert from: Pending Stripe events | `alert_pending_webhooks` | whole number, 0 to 100000 | `1` | `wplc_health_alert_thresholds` | A health alert is raised when this figure of the diagnostics page reaches this number: the action wplc/health_alert fires and, with recipients above, the collected email is sent. Default: 1. 0 = never alert for this figure. |
| Alert from: Stripe events needing review (24 hours) | `alert_manual_review_webhooks` | whole number, 0 to 100000 | `1` | `wplc_health_alert_thresholds` | A health alert is raised when this figure of the diagnostics page reaches this number: the action wplc/health_alert fires and, with recipients above, the collected email is sent. Default: 1. 0 = never alert for this figure. |
| Alert from: Stuck renewals (job not run, grace period over) | `alert_overdue_renewals` | whole number, 0 to 100000 | `1` | `wplc_health_alert_thresholds` | A health alert is raised when this figure of the diagnostics page reaches this number: the action wplc/health_alert fires and, with recipients above, the collected email is sent. Default: 1. 0 = never alert for this figure. |
| Alert from: Renewals stuck in processing (over 1 hour) | `alert_stuck_processing_renewals` | whole number, 0 to 100000 | `1` | `wplc_health_alert_thresholds` | A health alert is raised when this figure of the diagnostics page reaches this number: the action wplc/health_alert fires and, with recipients above, the collected email is sent. Default: 1. 0 = never alert for this figure. |
| Alert from: Paid renewals without extension | `alert_paid_without_extension` | whole number, 0 to 100000 | `1` | `wplc_health_alert_thresholds` | A health alert is raised when this figure of the diagnostics page reaches this number: the action wplc/health_alert fires and, with recipients above, the collected email is sent. Default: 1. 0 = never alert for this figure. |
| Alert from: Failed scheduled actions (group wplc) | `alert_failed_scheduled_actions` | whole number, 0 to 100000 | `1` | `wplc_health_alert_thresholds` | A health alert is raised when this figure of the diagnostics page reaches this number: the action wplc/health_alert fires and, with recipients above, the collected email is sent. Default: 1. 0 = never alert for this figure. |
| Alert from: License products without a plan | `alert_unmapped_license_products` | whole number, 0 to 100000 | `1` | `wplc_health_alert_thresholds` | A health alert is raised when this figure of the diagnostics page reaches this number: the action wplc/health_alert fires and, with recipients above, the collected email is sent. Default: 1. 0 = never alert for this figure. |
| Alert from: Renewals needing review (unknown payment result, payment not applied) | `alert_review_required_renewals` | whole number, 0 to 100000 | `1` | `wplc_health_alert_thresholds` | A health alert is raised when this figure of the diagnostics page reaches this number: the action wplc/health_alert fires and, with recipients above, the collected email is sent. Default: 1. 0 = never alert for this figure. |
| Alert from: Paid orders without their licenses | `alert_orders_with_issuance_problem` | whole number, 0 to 100000 | `1` | `wplc_health_alert_thresholds` | A health alert is raised when this figure of the diagnostics page reaches this number: the action wplc/health_alert fires and, with recipients above, the collected email is sent. Default: 1. 0 = never alert for this figure. |
| Alert from: Failed background jobs (24 hours) | `alert_failed_jobs` | whole number, 0 to 100000 | `1` | `wplc_health_alert_thresholds` | A health alert is raised when this figure of the diagnostics page reaches this number: the action wplc/health_alert fires and, with recipients above, the collected email is sent. Default: 1. 0 = never alert for this figure. |
| Alert from: Problems with update packages | `alert_release_problems` | whole number, 0 to 100000 | `1` | `wplc_health_alert_thresholds` | A health alert is raised when this figure of the diagnostics page reaches this number: the action wplc/health_alert fires and, with recipients above, the collected email is sent. Default: 1. 0 = never alert for this figure. |
| Alert from: Minutes with rejected Stripe webhook requests (24 hours) | `alert_rejected_webhook_minutes` | whole number, 0 to 100000 | `5` | `wplc_health_alert_thresholds` | A health alert is raised when this figure of the diagnostics page reaches this number: the action wplc/health_alert fires and, with recipients above, the collected email is sent. Default: 5. 0 = never alert for this figure. |
| Verbose log | `debug_log` | one of `off`, `24h`, `7d` | `off` | `wplc_log_debug` | Writes additional lines of the level debug to the WooCommerce log (source wp-license) to trace a problem. It switches itself off after the chosen time. Off (default). The constant WPLC_DEBUG in wp-config.php switches it on permanently. |
| When the plugin is deleted | `uninstall_data` | one of `keep`, `delete` | `keep` | – | Keep (default): licenses, activations, renewals, the audit log and the settings stay in the database when the plugin is deleted, because they are business records. Delete: the tables and options of the plugin are removed for good when it is deleted; uploaded packages and order data are never removed. The constant WPLC_REMOVE_ALL_DATA in wp-config.php also deletes. Deleting cannot be undone; make a backup first. Administrators only. |

<!-- settings:end -->

## Country profiles

Below the form, **Country profile** offers Germany, Austria, Switzerland, European Union (general) and Other country. A profile is a **suggestion** for the settings with legal weight:

- It shows every value now and afterwards. Nothing is ticked in advance, and nothing changes until you tick rows, tick the confirmation and apply.
- **No profile has been through a legal review.** Every value is marked "unchecked". The profile "Germany" suggests the built-in defaults, which were written with German rules in mind; the other profiles say "please decide" for every value, because nothing has been fixed for these countries. A profile never invents a value.
- Retention stays technically as it is; a profile only suggests.
- A law firm or the shop itself can supply checked values with the filter `wplc_settings_country_profiles` (per profile: `values` and the date `reviewed`). They are still only suggested.
- Applying a profile writes the audit entry `settings_preset_applied`.

## Export, import and reset

- **Export** downloads a JSON file (`format`, `plugin`, `plugin_version`, `exported_at`, `settings`). It contains every setting of this chapter except the verbose log, and no secret.
- **Import** takes such a file (upload or pasted text), checks every value with the rules of the register and shows the differences. Values that are not valid and keys this version does not know are listed and left out. The trusted package hosts change only when you may change them.
- **Reset to defaults** works per group or for all settings, with the same preview. The trusted package hosts are never part of a reset.
- All three write to the audit log (`settings_exported`, `settings_imported`, `settings_reset`), in addition to the entry `settings_updated` with the old and new values.
- The same on the command line: `wp wplc settings` (chapter 10).

## Deleting the plugin

**Operations → When the plugin is deleted** decides what happens to the data: **Keep** (default) leaves tables and options in the database; **Delete** removes them for good and needs an administrator and a ticked confirmation. The constant `WPLC_REMOVE_ALL_DATA` in `wp-config.php` deletes as well. Uploaded packages and order data are never removed. On a network the choice is made per site.

## Health alerts by email

**Operations → Send health alerts to** takes up to five email addresses. When a figure of the diagnostics page reaches its alert threshold (chapter 9), these addresses get one collected email, at most one per day. Without an address nothing is sent; the action `wplc/health_alert` fires as before.

- **Thresholds.** Each figure has its own setting **Alert from: …** (default 1; rejected webhook minutes: 5). 0 never alerts for that figure. The filter `wplc_health_alert_thresholds` still decides last.
- **Test.** **Send test message** below the form (or `wp wplc settings test-alert`) sends a test to the recipients, also when an alert email already left that day.
- **History.** **Show all changes of the settings in the audit log** below the form opens the audit log filtered by the object type "Settings".

## Customer sites

- **Offline period of customer sites** (1 to 90 days, default 14, manufacturer rule): how long a customer site may rely on the last signed answer of this server when it cannot reach it. The period never reaches beyond the end of access of the license. The filter `wplc_offline_cache_days` decides last.
- **Staging sites per live site** (0 to 10, default 1, manufacturer rule): staging copies each live site may activate free of charge; 0 = none. A refused staging site gets `409 staging_limit` with the number in the message and in `data.staging_limit`. The filter `wplc_staging_site_limit` decides last.
- **Development sites per license** (0 to 100, default 5, manufacturer rule): development installations on local addresses, at least this number and at least twice the live-site limit; 0 = none. The filter `wplc_development_site_limit` decides last.
- **Staging: name parts**, **Staging: hosting provider domains**, **Local addresses: endings** (manufacturer rules): the lists that decide which address is a copy of a site and where development sites may run, one entry per line. Only valid entries are accepted (a name part has no dot; a hosting provider domain has at least two parts, so a top-level domain alone is refused). **Reset this list to the default when saving** below each list brings back the built-in list. The filters `wplc_staging_labels`, `wplc_staging_host_suffixes` and `wplc_local_host_suffixes` decide last over the lists; `wplc_is_staging_host`, `wplc_registrable_domain` and `wplc_is_local_development_url` still decide last over the result.
- **New sites per license and hour** (default 10, 0 = no limit): at least twice the live-site limit of the license, so an agency can still set up all its sites. The technical limits of the license API (requests per installation, failed sign-ins and activations, time window of signed requests) are fixed and listed on the diagnostics page.

## Who may do what

The plugin brings its own capabilities (chapter 2, "Who may do what"). The overview on the settings page shows them with their defaults and says which ones you hold. Settings that need more than `wplc_manage_settings`:

- `wplc_manage_policy` (by default administrators): the manufacturer rules, marked "Manufacturer rule" in the tables above. They change how the add-ons behave on every customer site. A shop manager sees them read-only. To let another role change them, store the capability on that role, for example `wp cap add shop_manager wplc_manage_policy`.
- `wplc_manage_package_hosts` (by default administrators): the trusted package hosts and what happens to the data when the plugin is deleted.

## Support contact

**Texts and support → Support email address** is the address that license emails, the customer account and the cancellation page name. Empty (default) keeps the behaviour so far: the sender address of the WooCommerce emails, else the administrator. **Support page** adds a link to your support or contact page in the customer account and on the cancellation page. The filters `wplc_support_email` and `wplc_support_url` decide last.

## Verbose log

**Operations → Verbose log** writes additional debug lines to the WooCommerce log (source `wp-license`) for 24 hours or 7 days and then switches itself off; an hourly job sets the field back to "Off" and writes it to the audit log. To switch it on again after it ran out, choose a time again. The constant `WPLC_DEBUG` in `wp-config.php` switches it on permanently, the filter `wplc_log_debug` decides last. The value is not part of an export.

## For developers

Read a setting with `\WPLC\Settings\Registry::get( $key )` (or `int()`, `bool()`, `string()`, `list()`), never with `get_option()`. A new setting needs its definition (`src/Settings/Definitions.php`), its label and help text (`src/Settings/Texts.php`) and the class that uses it; the field, the validation, the change log and this chapter follow from the register. The action `wplc_settings_page_tools` fires below the form.
