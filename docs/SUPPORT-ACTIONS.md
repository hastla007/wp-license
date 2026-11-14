# Support actions in the admin

What support staff can do in the WordPress admin today. All screens are under the **WooCommerce** menu. Every state-changing form is protected by a nonce. Covered by integration tests; not yet verified in a browser.

Source: `src/Admin/*`, `src/License/LicenseLifecycle.php`, `src/License/ActivationManager.php`, `src/Update/ReleaseRegistry.php`.

## Who may do what

The plugin has its own capabilities (`src/Admin/Capabilities.php`). Nothing is written to the database: a capability that is not stored on a role is derived from the capability in the third column.

| Capability | Allows | Granted by default to users with |
| --- | --- | --- |
| `wplc_view_licenses` | License list, license detail, CSV export of licenses | `manage_woocommerce` (shop manager, administrator) |
| `wplc_manage_licenses` | Suspend, revoke, reactivate, change term, release a site, new key, send key email again, change owner, restore a suspended license | `manage_woocommerce` |
| `wplc_grant_licenses` | Add a license manually, extend several licenses at once, issue missing licenses for an order | `manage_woocommerce` |
| `wplc_restore_licenses` | Restore a **revoked** license | `manage_options` (administrator) |
| `wplc_manage_catalog` | License fields of products, catalog CSV | `manage_woocommerce` |
| `wplc_publish_releases` | Publish, withdraw and delete releases | `manage_options` |
| `wplc_view_diagnostics` | License diagnostics, complete audit log and its export | `manage_woocommerce` |
| `wplc_manage_settings` | License settings except the package hosts | `manage_woocommerce` |
| `wplc_manage_package_hosts` | Trusted package hosts in the settings | `manage_options` |

Releases and package hosts decide which code reaches every customer site, and restoring a revoked license gives access back; these three therefore need an administrator.

**A support role without full shop access:** create a role and give it `read`, `wplc_view_licenses` and `wplc_manage_licenses` (for example with a role editor plugin or `wp role create` / `wp cap add`). Such a user does not see the WooCommerce menu; the license list then appears as its own top-level menu "Licenses". To take one capability away from shop managers, store it as denied on the role: `wp cap add shop_manager wplc_grant_licenses --grant=false`.

**For developers:** the capability of an action can be changed with the filter `wplc_capability`:

```php
add_filter( 'wplc_capability', function ( $capability, $action ) {
	return 'publish_releases' === $action ? 'manage_woocommerce' : $capability;
}, 10, 2 );
```

`$action` is the capability name without `wplc_`.

## Overview

| Action | Where | Capability | Reason required | Audit action |
| --- | --- | --- | --- | --- |
| Search and filter licenses, reports | Licenses | `wplc_view_licenses` | – | – (read only) |
| Export licenses as CSV | Licenses → Export as CSV | `wplc_view_licenses` | – | `licenses_exported` |
| View license details | Licenses → a license | `wplc_view_licenses` | – | – (read only) |
| Suspend, revoke, reactivate | License detail | `wplc_manage_licenses` | yes | `admin_status_change` |
| Change term | License detail | `wplc_manage_licenses` | yes | `admin_extend` |
| Restore with a new end date | License detail (suspended with ended term, or revoked) | `wplc_manage_licenses`; revoked: `wplc_restore_licenses` | yes | `admin_restore` |
| Issue new key | License detail | `wplc_manage_licenses` | yes | `license_key_reissued` |
| Send key email again | License detail | `wplc_manage_licenses` | yes | `license_key_resend_requested` |
| Change owner | License detail | `wplc_manage_licenses` | yes | `license_owner_changed` |
| Release a site activation | License detail | `wplc_manage_licenses` | yes | `admin_site_released` |
| Add license manually | Licenses → Add license manually | `wplc_grant_licenses` | yes | `license_issued_manually` |
| Extend the selected licenses | Licenses, below the list | `wplc_grant_licenses` | yes | `admin_extend` per license |
| Issue missing licenses for an order | Licenses, below the list | `wplc_grant_licenses` | yes | `admin_issue_for_order` |
| Create, import, publish, withdraw, delete releases | License releases | `wplc_publish_releases` | only for withdraw | `release_created`, `release_updated`, `release_deleted`, and one entry per status change |
| Read diagnostics | License diagnostics | `wplc_view_diagnostics` | – | – (read only) |
| Read and export the audit log | License audit log | `wplc_view_diagnostics` | – | – (read only) |
| Change settings | License settings | `wplc_manage_settings`; package hosts: `wplc_manage_package_hosts` | – | `settings_changed` |
| Export / import catalog CSV | License catalog CSV | `wplc_manage_catalog` (import also `edit_post` on each product) | – | not audited |

Every action that changes a license, an activation, a release or the settings writes an audit entry with the acting user, the reason and the values before and after. The reason is cut to 255 characters.

A rejected action returns to the screen with an explanation; only a missing capability or an invalid nonce ends in an error page.

### Two people on the same license

The forms carry the values they were rendered with. If a colleague changed the license in the meantime, the action is refused with "someone else changed this license" instead of overwriting the colleague's change. This applies to *Change term* (end date), *Change owner* (customer), *Restore* (status) and the package hosts in the settings. Reload the page and decide again.

## License list

WooCommerce → Licenses. Sortable table with filters for status and product. The search field accepts:

- a **complete license key** (pasted; spaces, line breaks, non-breaking spaces and typographic dashes are ignored). Keys are not stored; the search compares the key's hash. The search is sent as a form post and the result page carries the license ID instead, so the key does not appear in the address bar, the browser history or the server's access log,
- `#123` for a license ID,
- a number: license ID, customer ID or part of the key prefix,
- an **email address** or part of one (anything containing `@`),
- a **license UUID** or the **installation ID** a customer site reports,
- other text: key prefix, product key, plan key, part of a customer email, or part of the address of an activated site.

### Reports

Links above the list:

- **Automatic renewals in the next 30 days:** active licenses that will be charged.
- **Ending in the next 30 days without automatic renewal:** candidates for a reminder or a call.
- **Payment overdue:** licenses in `past_due`.
- **Cancelled to the end of the term.**

Each report can be combined with the filters and exported.

### Export as CSV

Exports the current list (search, filters and report applied) with license ID, key prefix, customer ID and email, product, plan, status, renewal mode, dates (UTC) and source order. License keys and UUIDs are never exported. The file contains personal data (email addresses); the export is written to the audit log.

### Extend the selected licenses

Tick licenses in the list, enter the number of days and a reason below the list. The days are added to the current end date, or to today when the term has already ended (an expired license becomes active again). At most 200 licenses per request. Skipped: suspended and revoked licenses, licenses with a payment in progress, and licenses that changed while the request ran. The result message names both counts. No payment is taken. Read the warning about open renewal orders under *Change term*.

### Issue missing licenses for an order

For a paid order that has no license, typically because the product was mapped to a plan after the sale. Enter the order number and a reason. Licenses that already exist are never issued twice; the message says how many were created. An unpaid order and a renewal order get nothing.

## License detail

Shows customer, product, plan, status, billing interval, start, paid until, whether the license grants access right now, renewal mode, next automatic payment, live sites used/allowed, source order, technical IDs, then the site activations, renewals, entitlements and the latest 100 audit entries with a link to the complete audit log of the license.

The column "By" in the audit log shows the login name and user ID, for example `anna (#17)`. Unlike the display name, a user cannot change these. "System" is only shown for entries without a user (background jobs, API, webhooks).

The full license key is not shown here. The customer sees it in the account under "Licenses".

Users with `wplc_view_licenses` but without `wplc_manage_licenses` see this screen without any forms.

### Suspend, revoke, reactivate

- **Suspend** (`active`, `past_due`, `expired` → `suspended`): the license stops granting access until it is reactivated.
- **Revoke** (→ `revoked`): the license stops granting access and automatic renewal is switched off. Only an administrator can undo it (see *Restore*).
- **Reactivate** (`suspended`, `past_due`, `expired` → `active`): only possible while `paid_until` is in the future. If the period has ended, change the term (past due, expired) or restore the license (suspended).

### Change term

Sets a new end date (end of that day in the site timezone, must be in the future). No payment is taken and no order is created. Allowed for `active`, `past_due` and `expired` licenses; an expired license becomes active again. Open automatic renewal attempts for the old period are cancelled; if automatic renewal is on, the next charge is scheduled for the new end date.

Before you change a term:

- **Unpaid renewal order.** If the customer has started a renewal and not paid yet, the screen shows a warning with a link to the order. Changing the term does **not** cancel that order. If the customer pays it afterwards, WooCommerce accepts the payment, but it may not extend the license; the order is then marked for review. Cancel the open order in WooCommerce first, or leave the term as it is and let the customer pay.
- **Payment in progress.** While an automatic charge is being processed, the term cannot be changed (message "an automatic payment for this license is being processed"). Try again a few minutes later. If the message stays for more than an hour, see "Renewals stuck in processing" on the diagnostics page.
- **Shortening** a term is possible and takes access away earlier. The audit log shows the old and the new end date.

### Restore with a new end date

Brings a license back to `active` with a new end date. The form appears in two cases:

- **Suspended, and the paid period has ended.** "Reactivate" is not possible then and "Change term" is not offered for suspended licenses.
- **Revoked** – administrators only. Use it when a license was revoked by mistake, for example because an order was set to "Refunded" accidentally. Setting the order back does not restore the license. Automatic renewal stays off; the customer can renew manually or opt in again with the next purchase.

No payment is taken. The entitlements of the license become active until the new end date.

### Issue new key

For a key that was published or passed on. The license gets a new key; the old key stops working immediately for new activations. Term, order reference, renewal and entitlements stay as they are – do not revoke and re-create the license for this.

- Sites that are already activated keep working, because they no longer use the key. Tick "Also release all activated sites" to disconnect all of them, or release only the sites you do not recognise in the table below.
- The customer finds the new key in the account. The plugin announces the new key internally (`wplc/license_key_reissued`) so that an email can be sent; whether an email goes out depends on the notification settings.
- Not possible for revoked licenses.

### Send key email again

Requests the email with the license key for the customer again (`wplc/license_key_resend_requested`). Not possible when the key can no longer be reproduced with the configured secret (see the diagnostics page); issue a new key instead.

### Change owner

Hands the license over to another existing user account (user ID or email), for example from an agency to its client.

- The new owner sees the license, its key and its sites in the account; the previous owner no longer does.
- **Automatic renewal is switched off** and open automatic attempts are cancelled, because the stored payment method belongs to the previous owner. The new owner renews manually or opts in again when paying.
- Activated sites stay connected. Issue a new key as well if the previous owner must not be able to activate further sites.
- The source order stays with the previous owner's order history.

### Release a site activation

Frees one active site. The site can no longer authenticate and loses updates and premium features; a staging site linked to it is released as well. Use this for lost or decommissioned sites when the customer cannot deactivate in the product plugin or in their account. Confirm the customer's identity first.

### Add license manually

For goodwill and migrated customers. Input: user ID or email of an existing account, an active plan from the catalog, end date, reason. No order is created and nothing is charged. The license does not renew automatically; the customer can renew it in the account inside the renewal window (see [RENEWALS.md](RENEWALS.md)). The customer finds the key in the account, and the "license issued" email is sent.

A form that is submitted twice (double click, reload of the confirmation) creates one license; the second submission leads to the license that already exists.

## Audit log

WooCommerce → License audit log. All entries, newest first, 100 per page. Filters: license ID (includes the sites and renewals of that license), object type and ID, action, acting user (login, `#ID` or `system`), date range in the site timezone. "Export as CSV" exports the filtered entries (at most 50,000 rows).

Entries cannot be edited or deleted here. Old entries are removed by the data retention job (730 days by default, see [MONITORING.md](MONITORING.md)).

## Settings

WooCommerce → License settings. Status rows (schema, Stripe webhook, secrets, how many recent license keys can be verified, why automatic renewal is unavailable, checkout type) and three settings.

- A change is written to the audit log (`settings_changed`) with the old and new values.
- **Trusted package hosts** can only be changed by an administrator; other users see the list read-only. If someone else changed the list after you opened the page, your save keeps their list and tells you so; the other fields of your form are still saved.

## Releases

WooCommerce → License releases. Licensed installations receive the highest published version of a product; customers can download it in their account.

- **Create draft:** product, version (`1.2.3` or `1.2.3-beta1`), and either an uploaded ZIP (stored in a private directory, SHA-256 computed automatically, 50 MB by default) or an HTTPS package URL on an allowed host plus its SHA-256. Optional: required PHP/WordPress version, tested-up-to, release notes.
- **Import manifest:** a JSON file created by `tools/create-release-manifest.php`, uploaded (up to 1 MB) or pasted. Creates a draft.
- **Publish:** makes the draft available. Version, package and checksum can no longer be changed.
- **Withdraw** (with reason): installations are offered the next highest published version. A withdrawn release can be published again.
- **Delete:** drafts only.

Details: [UPDATES.md](UPDATES.md), [UPDATE-STORAGE.md](UPDATE-STORAGE.md).

## Not available in the admin

- Showing a customer's license key. Issue a new key or send the key email again instead.
- Moving a license to another plan (upgrade, downgrade).
- Co-users of a license: a license has exactly one owner.
- Retrying a failed renewal or a Stripe event by hand. The diagnostics page lists what needs attention; see [MONITORING.md](MONITORING.md).
- Refunds and disputes: refunds are made on the WooCommerce order and change the license automatically; a Stripe dispute suspends the license (see [STRIPE-WEBHOOKS.md](STRIPE-WEBHOOKS.md)).
