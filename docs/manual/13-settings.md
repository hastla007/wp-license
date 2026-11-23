# 13. Settings

Everything a shop decides for itself (periods, limits, texts, retention) is a setting under **WooCommerce → License settings**. This chapter lists every setting. Secrets are not settings: they stay in `wp-config.php` (chapter 2) and never appear on the screen, in an export or in the change log.

## How settings work

- **One register.** Every setting is described once in the code (`src/Settings/Definitions.php`): key, kind of value, default, limits, group, the capability needed to change it and the filter that belongs to it. The settings page, export and import, reset, WP-CLI and the change log all use this register.
- **Defaults are the behaviour before the setting existed.** Updating the plugin changes nothing until you change a value.
- **Order of precedence:** built-in default, then the stored setting, then the PHP filter named in the table. The filter decides last, so code of the shop wins over the screen. When a filter changes a value, the field says "In effect: …".
- **A value that cannot be accepted is reported, not silently corrected.** The stored value stays and the screen names the field. (The settings that existed before this register keep their earlier behaviour: a number outside its limits is moved to the nearest limit.)
- **Tabs.** "All settings" shows everything on one page; each tab shows one group. Saving a tab changes only the fields of that tab.
- **Who may change what.** The settings need the capability `wplc_manage_settings` (by default everyone who may manage WooCommerce). The trusted package hosts need `wplc_manage_package_hosts` (by default administrators), because update packages for all customer sites are delivered from them; other users see the list read-only.
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
| Grace period after the paid period | `renewal_grace_days` | whole number, 1 to 90 | `14` | `wplc_renewal_grace_days` | Days after the end of the paid period during which a license with a pending or failed automatic charge keeps its access. Default: 14. Keep it longer than the last retry day below, or the last attempts fall after access has ended. A changed value applies to renewals scheduled afterwards; renewals already scheduled keep their date. |
| Retry days of a failed charge | `renewal_retry_days` | up to 6 whole numbers, each 1 to 60 | `1,3,7` | `wplc_renewal_retry_days` | Days after the start of the new period on which a failed automatic charge is tried again, separated by commas. The number of entries is the number of retries. Default: 1,3,7. Empty = no retry: the first failed charge is final. |
| Manual renewal: days before the end | `manual_renewal_days_before` | whole number, 0 to 365 | `30` | `wplc_manual_renewal_window_days` | From this many days before the end of the paid period a customer can renew by hand in the account, and the emails offer it. Default: 30. 0 = only from the day the period ends. |
| Manual renewal: days after the end | `manual_renewal_days_after` | whole number, 0 to 365 | `30` | `wplc_manual_renewal_window_days` | Until this many days after the end of the paid period an expired license can still be renewed and continues with the same key and the same sites. Default: 30. 0 = an expired license cannot be reactivated by the customer. |

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

### Operations

| Setting | Key | Value | Default | Filter (wins) | What it does |
| --- | --- | --- | --- | --- | --- |
| Send health alerts to | `health_alert_recipients` | text, at most 500 characters | (empty) | `wplc_health_alert_recipients` | Email addresses, separated by commas, that get a message when a figure of the diagnostics page reaches its alert threshold (for example failed background jobs or rejected webhooks). At most one collected email per day. Empty (default) = no email; the alerts are then only available to code (action wplc/health_alert). |
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

- **Export** downloads a JSON file (`format`, `plugin`, `plugin_version`, `exported_at`, `settings`). It contains every setting of this chapter and no secret.
- **Import** takes such a file (upload or pasted text), checks every value with the rules of the register and shows the differences. Values that are not valid and keys this version does not know are listed and left out. The trusted package hosts change only when you may change them.
- **Reset to defaults** works per group or for all settings, with the same preview. The trusted package hosts are never part of a reset.
- All three write to the audit log (`settings_exported`, `settings_imported`, `settings_reset`), in addition to the entry `settings_updated` with the old and new values.
- The same on the command line: `wp wplc settings` (chapter 10).

## Deleting the plugin

**Operations → When the plugin is deleted** decides what happens to the data: **Keep** (default) leaves tables and options in the database; **Delete** removes them for good and needs an administrator and a ticked confirmation. The constant `WPLC_REMOVE_ALL_DATA` in `wp-config.php` deletes as well. Uploaded packages and order data are never removed. On a network the choice is made per site.

## Health alerts by email

**Operations → Send health alerts to** takes up to five email addresses. When a figure of the diagnostics page reaches its alert threshold (chapter 9), these addresses get one collected email, at most one per day. Without an address nothing is sent; the action `wplc/health_alert` fires as before. Test it with `wp wplc settings test-alert`.

## For developers

Read a setting with `\WPLC\Settings\Registry::get( $key )` (or `int()`, `bool()`, `string()`, `list()`), never with `get_option()`. A new setting needs its definition (`src/Settings/Definitions.php`), its label and help text (`src/Settings/Texts.php`) and the class that uses it; the field, the validation, the change log and this chapter follow from the register. The action `wplc_settings_page_tools` fires below the form.
