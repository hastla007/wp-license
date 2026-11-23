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

<!-- settings:end -->

## For developers

Read a setting with `\WPLC\Settings\Registry::get( $key )` (or `int()`, `bool()`, `string()`, `list()`), never with `get_option()`. A new setting needs its definition (`src/Settings/Definitions.php`), its label and help text (`src/Settings/Texts.php`) and the class that uses it; the field, the validation, the change log and this chapter follow from the register. The action `wplc_settings_page_tools` fires below the form.
