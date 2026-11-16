# Support actions in the admin

What support staff can do in the WordPress admin today. All screens are under the **WooCommerce** menu. Every state-changing form is protected by a nonce. Covered by integration tests; not yet verified in a browser.

Source: `src/Admin/*`, `src/License/LicenseLifecycle.php`, `src/License/ActivationManager.php`, `src/Update/ReleaseRegistry.php`.

## Who may do what

The plugin has its own capabilities (`src/Admin/Capabilities.php`). Nothing is written to the database: a capability that is not stored on a role is derived from the capability in the third column.

| Capability | Allows | Granted by default to users with |
| --- | --- | --- |
| `wplc_view_licenses` | License list, license detail, CSV export of licenses | `manage_woocommerce` (shop manager, administrator) |
| `wplc_manage_licenses` | Suspend, revoke, reactivate, change term, release a site, new key, send key email again, change owner, restore a suspended license, bulk actions, renewals to review | `manage_woocommerce` |
| `wplc_grant_licenses` | Add a license manually, extend several licenses at once, issue missing licenses for an order, book a renewal under review | `manage_woocommerce` |
| `wplc_restore_licenses` | Restore a **revoked** license | `manage_options` (administrator) |
| `wplc_manage_catalog` | License fields of products, catalog CSV | `manage_woocommerce` |
| `wplc_publish_releases` | Publish, withdraw and delete releases | `manage_options` |
| `wplc_view_diagnostics` | License diagnostics, complete audit log and its export | `manage_woocommerce` |
| `wplc_manage_settings` | License settings except the package hosts | `manage_woocommerce` |
| `wplc_manage_package_hosts` | Trusted package hosts in the settings | `manage_options` |
| `wplc_change_prices` | Announce a higher renewal price with notice and withdraw it (license detail and the plan-wide screen) | `manage_options` |

Releases and package hosts decide which code reaches every customer site, restoring a revoked license gives access back, and a price change raises what customers pay; these four therefore need an administrator. Key rotation (`wp wplc rotate-installation-secrets`, `wp wplc signing-key`, `wp wplc rekey-licenses`) needs WP-CLI access to the server and is the administrator's as well.

**Who does support (owner decision of 16 Nov 2026, T-94):** one named person with the WooCommerce role **Shop manager**. With the defaults above that role holds every support capability (view, manage and grant licenses, catalog, diagnostics, settings) but not price changes, releases, package hosts, restoring revoked licenses or key rotation. The administrator holds all capabilities and can do support as well.

Every screen and handler checks these capabilities, never `manage_woocommerce` directly (P-07): a capability stored as denied on a role takes effect everywhere, and a support role without shop access can use everything its capabilities allow. Users without `wplc_manage_licenses` do not see the bulk controls and checkboxes of the license list.

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
| Bulk: suspend, reactivate, revoke, release all site activations | Licenses (list) | `wplc_manage_licenses` | yes, one for the batch | per license as for the single action (`admin_status_change`; `admin_site_released` per activation plus `admin_sites_released` on the license), plus one `admin_bulk_action` summary (object ID 0) |
| Bulk: extend the term by a number of days | Licenses (list) | `wplc_manage_licenses` and `wplc_grant_licenses` | yes, one for the batch | `admin_extend` per license, plus the `admin_bulk_action` summary |
| Suspend, revoke, reactivate | License detail | `wplc_manage_licenses` | yes | `admin_status_change` |
| Change term | License detail | `wplc_manage_licenses` | yes | `admin_extend` |
| Change plan (now / with the next renewal, withdraw) | License detail | `manage_woocommerce` | yes | `admin_plan_change`, `plan_change_scheduled`, `plan_change_cancelled` |
| Cancel automatic renewal at the end of the paid period, withdraw the cancellation | License detail | `wplc_manage_licenses` | yes | `cancellation_scheduled`, `cancellation_withdrawn` |
| Change the renewal amount (lower only) | License detail | `wplc_manage_licenses` | yes | `admin_renewal_amount_changed` |
| Announce a higher renewal price with notice, withdraw the announcement (INH-05) | License detail; WooCommerce → Renewal price change (whole plan); `wp wplc price-change` | `wplc_change_prices` (WP-CLI: server access) | yes | `renewal_price_change_announced`, `renewal_price_change_withdrawn` |
| Restore with a new end date | License detail (suspended with ended term, or revoked) | `wplc_manage_licenses`; revoked: `wplc_restore_licenses` | yes | `admin_restore` |
| Issue new key | License detail | `wplc_manage_licenses` | yes | `license_key_reissued` |
| Send key email again | License detail | `wplc_manage_licenses` | yes | `license_key_resend_requested` |
| Change owner (by default with a new key) | License detail | `wplc_manage_licenses` | yes | `license_owner_changed`, with a new key also `license_key_reissued` |
| Release a site activation | License detail | `wplc_manage_licenses` | yes | `admin_site_released` |
| Add license manually | Licenses → Add license manually | `wplc_grant_licenses` | yes | `license_issued_manually` |
| Issue missing licenses for an order | Licenses, below the list | `wplc_grant_licenses` | yes | `admin_issue_for_order` |
| Book a renewal under review | Renewals to review | `wplc_manage_licenses` and `wplc_grant_licenses` | yes | `renewal_review_booked` (renewal), `renewal_booked_after_review` (license) |
| Mark a renewal under review as not paid | Renewals to review | `wplc_manage_licenses` | yes | `renewal_result_resolved` |
| Close a review without booking | Renewals to review | `wplc_manage_licenses` | yes | `renewal_review_closed` |
| Create, import, publish, withdraw, delete releases | License releases | `wplc_publish_releases` | only for withdraw | `release_created`, `release_updated`, `release_deleted`, and one entry per status change |
| Read diagnostics | License diagnostics | `wplc_view_diagnostics` | – | – (read only) |
| Read and export the audit log | License audit log | `wplc_view_diagnostics` | – | export: `audit_log_exported` (with the filters) |
| Change settings | License settings | `wplc_manage_settings`; package hosts: `wplc_manage_package_hosts` | – | `settings_updated` |
| Export / import catalog CSV | License catalog CSV | `wplc_manage_catalog` (import also `edit_post` on each product) | – | import: `catalog_csv_imported`; export: not audited |

Every action that changes a license, an activation, a release or the settings writes an audit entry with the acting user, the reason and the values before and after. The reason is cut to 255 characters.

Settings changes, catalog imports, key rotations and bulk-action summaries are system-level entries (object ID 0). The license detail screen does not show them; the [audit log screen](#audit-log) does (filter "Object ID" = 0):

- `settings_updated` (object type `settings`): one entry per saved change, with the acting user and the changed keys in the reason. Before/after values are stored for `package_hosts`, `reminder_days` and `renewal_notice_days` only; a changed `sender_note` is listed by name. Secrets are not settings (they live in `wp-config.php` constants), so none can appear here.
- `catalog_csv_imported` (object type `catalog`): one entry per import, with the acting user and `outcome` (`completed`, `row_limit_reached`, `time_budget_reached`), the file name, rows read, rows imported, rows skipped or invalid, the line where a time budget stopped the run, and up to 200 product or variation IDs whose mapping was written.

Both follow the normal audit retention (730 days by default).

A rejected action returns to the screen with an explanation; only a missing capability or an invalid nonce ends in an error page.

### Two people on the same license

The forms carry the values they were rendered with. If a colleague changed the license in the meantime, the action is refused with "someone else changed this license" instead of overwriting the colleague's change. This applies to *Change term* (end date), *Change owner* (customer), *Restore* (status) and the package hosts in the settings. Reload the page and decide again.

## License list

WooCommerce → Licenses. Sortable table with these filters (they can be combined, and they are kept when searching, sorting, paging and after a bulk action):

- **status** and **product**,
- **renewal**: licenses with a renewal in `review_required` ("needs review"), `reconciling` ("result being checked"), `failed` or `manual_action_required` ("needs customer action"), or **past due with an open renewal** (license `past_due` and a renewal that is scheduled, processing, failed, needs action, reconciling or in review),
- **renewal mode**: automatic, manual, cancels at period end (same definition as the "Renewal" column),
- **expiry**: expires within 30 days (`active` or `past_due`, paid until in the next 30 days) or paid period ended (paid until in the past, any status),
- **source order ID**: the licenses issued for that order (renewal orders are not source orders).

The search field accepts:

- a **complete license key** (pasted; spaces, line breaks, non-breaking spaces and typographic dashes are ignored). Keys are not stored; the search compares the key's hash. The search is sent as a form post and the result page carries the license ID instead, so the key does not appear in the address bar, the browser history or the server's access log,
- `#123` for a license ID,
- a number: license ID, customer ID, order ID (the licenses issued for that order, or the license a renewal order renews) or part of the key prefix. Order numbers changed by a sequential-numbering plugin are not resolved; use the order ID,
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

The file is streamed in batches of 500 licenses; only the email address of each customer is read (no user meta), so large shops do not run out of memory (P-28). An export holds at most 100,000 licenses. A larger list ends with a last line `# Export truncated after 100,000 rows …`, and the next license screen shows a notice: narrow the filters and export the rest separately.

### Extend the selected licenses

Tick licenses in the list, enter the number of days and a reason below the list. The days are added to the current end date, or to today when the term has already ended (an expired license becomes active again). At most 200 licenses per request. Skipped: suspended and revoked licenses, licenses with a payment in progress, and licenses that changed while the request ran. The result message names both counts. No payment is taken. Read the warning about open renewal orders under *Change term*.

### Issue missing licenses for an order

For a paid order that has no license, typically because the product was mapped to a plan after the sale. Enter the order number and a reason. Licenses that already exist are never issued twice; the message says how many were created. An unpaid order and a renewal order get nothing.

### Bulk actions

Select licenses with the row checkboxes (at most 100 per batch), choose an action, enter **one reason** for the whole batch and click "Apply" (the browser asks for confirmation). Available actions:

- **Suspend**, **Reactivate**, **Revoke (final)**: exactly the rules of the single action on the detail screen (see below). Reactivation is only applied while the paid period has not ended; changing the term is not available in bulk.
- **Release all site activations**: releases every active site of the license (live sites first; their staging sites go with them). The license status does not change.

Every license runs through the same lifecycle code as the single action, under its own lock, and gets its own audit entries with the batch reason and the acting user. A license the action does not apply to is skipped. After the batch the list shows "n done, m skipped" with a link to every changed license and a table of the skipped ones with the reason:

| Skip reason | Meaning |
| --- | --- |
| The license already has this status. | e.g. suspending a suspended license |
| This change is not allowed from the current status. | e.g. anything on a revoked license, suspending a pending one |
| The paid period has ended. | Reactivate only after changing the term |
| The license has no active site activations. | Release: nothing to release |
| Not every site activation could be released. | Release: an activation changed during the batch; try again |
| Not applied / being processed | The license changed or was locked by another request between check and change; try again |
| The license was not found. | Deleted meanwhile |

A batch runs once. Submitting the same form again (double click, browser reload or back button) does not repeat it: the list shows the stored result with the notice "already processed". The result is kept for one hour and only for the administrator who ran it. Each batch also writes one summary entry `admin_bulk_action` (object type `license`, object ID 0) with the action and the changed and skipped license IDs.

## Order screen

The WooCommerce order screen (HPOS and legacy) has a box "Licenses" for users with `wplc_view_licenses` (A-123): the licenses issued for the order and, on a renewal order, the license it renews, each linked to its detail screen.

## License detail

Shows customer, product, plan, status, billing interval, start, paid until, whether the license grants access right now, renewal mode, next automatic payment, live sites used/allowed, source order, technical IDs, then the site activations, renewals, entitlements and the latest 100 audit entries with a link to the complete audit log of the license.

The column "By" in the audit log shows the login name and user ID, for example `anna (#17)`. Unlike the display name, a user cannot change these. "System" is only shown for entries without a user (background jobs, API, webhooks).

The full license key is not shown here. The customer sees it in the account under "Licenses".

Users with `wplc_view_licenses` but without `wplc_manage_licenses` see this screen without any forms.

### Suspend, revoke, reactivate

- **Suspend** (`active`, `past_due`, `expired` → `suspended`, after a confirmation): the license stops granting access until it is reactivated.
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

- **Issue a new key** is ticked by default (P-38): the previous owner knows the current key from the account and the license emails and could otherwise keep activating sites on the new owner's quota. With it, the license gets a new key right after the hand-over, exactly as with *Issue new key* below (the old key stops working, `wplc/license_key_reissued` is fired for the new owner); tick "Also release all activated sites" to disconnect the existing sites as well. A revoked license cannot get a new key: the owner is changed and the notice says that the old key still works. Untick it only when both sides agree.
- The new owner sees the license and its sites in the account; the previous owner no longer sees it there.
- **Automatic renewal is switched off** and open automatic attempts are cancelled, because the stored payment method belongs to the previous owner. The new owner renews manually or opts in again when paying.
- Activated sites stay connected unless "Also release all activated sites" is ticked.
- The public cancellation page accepts only the new owner's addresses: the billing address of the source order stops counting once the order belongs to someone else.
- The source order stays with the previous owner's order history.

### Change plan

Moves the license to another plan of the same product and billing interval (T-110; details in [RENEWALS.md](RENEWALS.md#plan-changes)). The list shows every active catalog mapping with another plan key and a price in the license currency, with plan key, product, price and live sites. Allowed for `active` and `past_due` licenses. No order is created, nothing is charged or refunded, and the paid period stays.

- **Now:** the plan key, the entitlements and the live site limit change at once; future renewals use the product of the new plan. For a license with automatic renewal, the automatic amount is lowered for a cheaper plan but never raised for a more expensive one (the customer did not agree to a higher off-session amount); manual renewals use the current price of the new product. A scheduled plan change is dropped. Audit `admin_plan_change` with plan, renewal product, renewal amount and site limit before and after.
- **With the next renewal:** stored as pending plan, exactly like a downgrade chosen by the customer; the renewal is billed with the new plan's product (an automatic charge never above the stored amount) and switches the plan when it is booked. Audit `plan_change_scheduled`. The summary shows "changes to … with the next renewal".
- **Withdraw plan change** (with reason): removes a pending change. Audit `plan_change_cancelled`.

A lower site limit deactivates no site: sites above the limit keep working, new activations are refused until enough sites are released (use "Release a site activation" below if the customer asks).

A paid customer upgrade that could not be applied (the license changed between order and payment) shows a warning "A paid plan upgrade was not applied" with the order link and the reason. Refund that order in WooCommerce, or use "Change plan → Now" if the customer keeps the upgrade (then consider the automatic amount: it is not raised by this action).

A paid upgrade order that is cancelled or fails afterwards (bounced SEPA debit) puts the license back on its previous plan while the upgraded period is still running. If that is no longer possible (a renewal was booked or the plan changed since), the plan stays, the order note says so and the license is reported for review (`license_needs_review`): decide whether to change the plan back with "Change plan → Now".

### Automatic renewal

Shown for `active` and `past_due` licenses with automatic renewal (A-21, A-11).

- **Cancel at the end of the paid period** (with reason, after a confirmation): the same cancellation the customer can make in the account or on the public cancellation page. The license keeps running until `paid_until`, nothing more is charged, and the customer gets the cancellation confirmation email. Audit `cancellation_scheduled` with the acting user and the reason.
- **Withdraw cancellation** (with reason): only while the paid period runs. The license is charged automatically again with the stored payment method; withdraw only when the customer asked for it. Audit `cancellation_withdrawn`. A license whose automatic renewal was switched off (for example after an owner change) cannot be switched on here: that needs the customer's consent to off-session charges, which they give in their account.
- **Change renewal amount** (with reason; also offered for licenses without automatic renewal): the amount of the next renewals, automatic and manual (price guarantee, INH-01), in the same form as the product price (taxes are added as at purchase); the currency stays. It can only be **lowered**: the customer agreed to the current amount. A shop that collected the customer's consent to a higher amount elsewhere can allow increases with the filter `wplc_admin_can_raise_renewal_amount` (arguments: `false`, license row, new amount in minor units, acting user). Refused while an automatic charge is in flight and when the amount changed after the page was opened. Renewal orders that already exist keep their amount; change those on the order screen. Audit `admin_renewal_amount_changed` with the amount before and after.

### Release a site activation

Frees one active site. You are asked to confirm before the site is released. The site can no longer authenticate and loses updates and premium features; a staging site linked to it is released as well. Use this for lost or decommissioned sites when the customer cannot deactivate in the product plugin or in their account. Confirm the customer's identity first.

### Add license manually

For goodwill and migrated customers. Input: user ID or email of an existing account, an active plan from the catalog, end date, reason. No order is created and nothing is charged. The license does not renew automatically; the customer can renew it in the account inside the renewal window (see [RENEWALS.md](RENEWALS.md)). The customer finds the key in the account, and the "license issued" email is sent unless "Do not send the license email with the key" is ticked (for a migrated customer who already has a key; the email can be sent later with *Send key email again*).

When the form is refused (unknown customer, invalid date, missing field), it comes back with the entered values for ten minutes (A-123); they are kept per user in a transient and removed when the form is shown again or the license is created.

A form that is submitted twice (double click, reload of the confirmation) creates one license; the second submission leads to the license that already exists.

## Renewals to review

WooCommerce → Renewals to review (`src/Admin/RenewalReviewAdmin.php`, logic in `src/Billing/RenewalReview.php`). The menu entry shows the number of open cases as a badge. The license detail screen shows a warning with a link when one of its renewals is under review; the diagnostics page links here when its counter is above zero.

A case is a renewal row in status `review_required`: the money may have been taken, but the license was not extended. Three kinds:

| Kind (column "Case") | How it arises | Typical reasons |
| --- | --- | --- |
| Automatic payment: result unknown | The gateway gave no definite answer for 12 hours, or a check was no longer safe (T-136). The order is not paid. | `result_unknown_timeout`, `renewal_cancelled`, `license_not_renewable`, `license_changed_while_unknown` |
| Automatic payment taken, license not extended | The charge succeeded while the license was suspended, revoked, refunded or expired, or its term was moved (T-22, B-50), or the order was paid by hand while the license could not be extended. The charge succeeded while the customer cancelled or automatic renewal was switched off (P-02). An administrator set a never-charged order to paid without a transaction ID (P-60). | `license_changed_during_charge`, `renewal_withdrawn_during_charge`, `marked_paid_without_payment` |
| Manual payment not applied | A customer paid a manual renewal that could not be booked: the transfer arrived after the 30-day window, the license was suspended/revoked meanwhile, the period changed, or the order line does not match the price (T-137). | `manual_renewal_not_allowed`, `manual_period_changed`, `manual_price_mismatch`, `manual_license_not_extended` |

Every new case also fires `wplc/renewal_needs_review` (passed on to `wplc/health_alert`). Rows that an earlier development version stored as `payment_review` are moved to `review_required` by schema step 21.

A case whose order is missing (or a manual order that is not paid) can only be closed. So can a case whose order was refunded or cancelled (kind "Order refunded or cancelled", P-05); a full refund or cancellation of the order closes its open case by itself.

Stripe payments that arrived but could not be booked (reason `unbooked_stripe_payment`, P-19) are listed as well; they cannot be marked as not paid. Cases with the reasons `renewal_withdrawn_during_charge`, `marked_paid_without_payment` and `unbooked_stripe_payment` are only resolved here, never by a paid-order hook or a Stripe event.

Each row shows the license (key prefix, product, status, paid until), the customer, a link to the WooCommerce order with its status, the amount, the translated reason and when the case was opened. Oldest first, up to 200 rows.

### Actions

Each action needs a reason, runs under the per-renewal lock (and then the license lock), claims the renewal with a conditional update and writes an audit entry whose reason is a code plus the administrator's text (shown translated). A second submission, e.g. a double click, finds the case resolved and reports "no longer needs review" without changing anything.

- **Book renewal** — the payment was taken. The renewal becomes `paid` and the license is extended:
  - automatic payment whose period still starts at the license's paid-until date, and whose period has not ended yet: to the end of that period, as if the charge had succeeded (no gap; the customer had access during the grace period);
  - everything else (late manual payment, changed period, period already over): one period from max(paid until, now), like a manual renewal made now; never back-dated.

  `active` and `past_due` licenses are extended, an `expired` license is reactivated (the same transitions as "Change term"). A `suspended` or `revoked` license is refused: reactivate it first, or close the case and refund. An unpaid order is marked paid (`payment_complete()`, or set to Processing if it no longer needs payment); the paid-order hooks that follow find the renewal booked. The next automatic period is scheduled if automatic renewal is on. The customer gets the "renewed" email.
- **Mark as not paid** (unknown result only) — the payment was not taken. The order is set to Failed and the attempt is recorded as a definite decline, exactly as when the order is set to Failed by hand: the license becomes `past_due` (with the "payment failed" email) and the retries continue with a new idempotency key.
- **Close without booking** — the payment was refunded in WooCommerce, or the shop decided otherwise. The renewal becomes `closed`, the license is not changed, and the order gets a note and the meta `_wplc_review_closed` (`paid` or `unpaid`, its state at that moment). Nothing is refunded here: make the refund on the linked WooCommerce order (a full refund of a renewal order that was never booked does not change the license). An order that was unpaid when the case was closed and is paid later goes back to review (`paid_after_review_closed`) instead of being booked silently. A closed period is not charged automatically again; a customer can renew it manually only after its old order was refunded or cancelled.

### Expiry while a case is open

While a renewal of a license is in review, the daily job and the grace-end job do not set the license to `expired`, for at most 30 days after the case was opened (`LicenseLifecycle::REVIEW_EXPIRY_DEFERRAL_DAYS`). Access follows the normal rules: it still ends 14 days after paid until for automatic renewals. After 30 days the license expires normally; the case stays open and booking it reactivates the license.

## Audit log

WooCommerce → License audit log (page `wplc-audit-log`, capability `wplc_view_diagnostics`). Read-only list of all audit entries, newest first: licenses, activations, renewals, releases, settings, catalog imports, key rotations (object type `system`) and bulk-action summaries. It is the only audit screen: the former second page `wplc-audit` was removed (P-27), and its old URLs (for example bookmarks of "Open the complete audit log of this license") redirect here with their filters.

- **Filters:** license ID (the license with its site activations and renewals; the link on the license detail screen sets it), object type, object ID (0 = system-wide entries), action (exact code, e.g. `settings_updated`), actor (user ID, email, login, or `system`), source (see below; "Unknown" lists entries written before schema 23), date range (days in the site timezone, both inclusive). The date range is mapped to an ID range and the license filter reads the IDs of the activations and renewals first, so no filter scans the table.
- **Columns:** date (in the site timezone, named in the column heading), action (a readable name with the code below it; A-122), object (linked: a license to its detail screen; an activation or renewal to the detail screen of its license; releases, settings and catalog entries to their screens), acting user as login and user ID, for example `anna (#17)` ("System" for jobs and webhooks), source, reason (system reason codes translated, as on the license detail screen), change summary ("field: old → new") with the full before/after snapshots under "Details".
- **Source (A-76, schema 23):** where an entry came from, detected by `AuditLog::record()`: `cli` (WP-CLI), `cron` (WP-Cron or an Action Scheduler action, also when Action Scheduler runs inside another request), `webhook` (the Stripe webhook route), `rest` (any other REST request, e.g. the license API), `admin` (a logged-in user with `wplc_view_licenses` in wp-admin), `customer` (any other logged-in user, also a customer's `admin-post.php` action from the account page), `system` otherwise (shown as "Internal"). The `wplc_audit_source` filter can replace it (a key of at most 20 characters). Entries written before schema 23 have no source ("Unknown"). The license detail screen shows the same column.
- **Redaction:** reasons and snapshots are redacted when written (license keys, emails, tokens, secrets). The screen applies the same redaction again before showing a row, so older rows or rows written by add-ons cannot show such values either. Site addresses of activations are kept (A-77) until a privacy erasure removes them.
- **Export as CSV:** the filtered list with ID, time (UTC), action, object, actor ID and login, source, reason and the before/after snapshots, redacted exactly like the screen. It is streamed in batches (newest first, keyset paging) and holds at most 50,000 entries; a larger selection ends with a `# Export truncated …` line and the next visit of the screen shows a notice. Every export is recorded as `audit_log_exported` (object type `export`, ID 0) with the acting user and the filters.

Entries cannot be edited or deleted here. Old entries are removed by the data retention job (730 days by default, see [MONITORING.md](MONITORING.md)).

## Settings

WooCommerce → License settings (also linked as "Getting started" in the plugin list). At the top, a "Getting started" checklist leads a new vendor from an empty installation to the first sale (A-121, PROD-09). The state of every step is read from the site on each visit; nothing is stored. Each step has a one-line hint and a link to the screen where it is done; the summary says how many of the seven steps are done, and the checklist folds away once all are done.

| Step | Done when | Link |
| --- | --- | --- |
| Define the secrets in `wp-config.php` | `WPLC_LICENSE_KEY_SECRETS` and `WPLC_INSTALLATION_ENCRYPTION_KEYS` (or their single-key forms) are set, so neither falls back to the WordPress salts | Diagnostics |
| Pin the public key in your add-on | `WPLC_RESPONSE_SIGNING_KEY` is set in `wp-config.php` and usable (`wp wplc signing-key --generate`). A key stored in the database does not count: a restored or copied database could change the public key the add-on pins. Reading the state never creates a stored key | Diagnostics (shows the public key) |
| Import your catalog file | At least one catalog file was imported by upload, WP-CLI or a release package; the copies shipped in `catalogs/` do not count ([CATALOG-FILE.md](CATALOG-FILE.md)) | Catalog files |
| Turn a product into a license product | At least one active plan exists | Products |
| Publish the first release | At least one release is published (a draft does not count) | License releases |
| Make a test purchase | At least one license exists | Licenses |
| Connect the Stripe webhook | `WPLC_STRIPE_WEBHOOK_SECRET` is set ([STRIPE-WEBHOOKS.md](STRIPE-WEBHOOKS.md)) | – |

The logic is `Settings::setup_state()` (reads the site) and `Settings::setup_steps_for()` (pure; unit tests in `tests/Admin/SettingsTest.php`, integration test `AdminSupportWave2IntegrationTest::test_a121_settings_page_lists_the_setup_steps_in_order`). The e2e flow `01-vendor-onboarding` walks through all steps with an invented vendor on an empty installation.

Below the checklist, status rows (schema, Stripe webhook, secrets, how many recent license keys can be verified, why automatic renewal is unavailable, checkout type) and the settings.

- A change is written to the audit log (`settings_updated`, see above).
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
- Co-users of a license: a license has exactly one owner.
- Charging or refunding a plan change from the admin: the customer upgrades in the account (prorated order); refunds are made on that WooCommerce order.
- Retrying a failed renewal or a Stripe event by hand. The diagnostics page lists what needs attention; see [MONITORING.md](MONITORING.md).
- Refunds and disputes: refunds are made on the WooCommerce order and change the license automatically; a Stripe dispute suspends the license (see [STRIPE-WEBHOOKS.md](STRIPE-WEBHOOKS.md)). "Renewals to review" links to the order but never triggers a gateway refund.
