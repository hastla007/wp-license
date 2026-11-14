# Support actions in the admin

What support staff can do in the WordPress admin today. All screens are under the **WooCommerce** menu and require the capability `manage_woocommerce` (shop manager, administrator). Every state-changing form is protected by a nonce. Implemented, not yet verified in a shop.

Source: `src/Admin/*`, `src/License/LicenseLifecycle.php`, `src/License/ActivationManager.php`, `src/Update/ReleaseRegistry.php`.

## Overview

| Action | Where | Capability | Reason required | Audit action |
| --- | --- | --- | --- | --- |
| Search and filter licenses | Licenses | `manage_woocommerce` | – | – (read only) |
| View license details | Licenses → a license | `manage_woocommerce` | – | – (read only) |
| Suspend, revoke, reactivate | License detail | `manage_woocommerce` | yes | `admin_status_change` |
| Change term | License detail | `manage_woocommerce` | yes | `admin_extend` |
| Release a site activation | License detail | `manage_woocommerce` | yes | `admin_site_released` |
| Add license manually | Licenses → Add license manually | `manage_woocommerce` | yes | `license_issued_manually` |
| Book a renewal under review | Renewals to review | `manage_woocommerce` | yes | `renewal_review_booked` (renewal), `renewal_booked_after_review` (license) |
| Mark a renewal under review as not paid | Renewals to review | `manage_woocommerce` | yes | `renewal_result_resolved` |
| Close a review without booking | Renewals to review | `manage_woocommerce` | yes | `renewal_review_closed` |
| Create, import, publish, withdraw, delete releases | License releases | `manage_woocommerce` | only for withdraw | `release_created`, `release_updated`, `release_deleted`, and one entry per status change |
| Read diagnostics | License diagnostics | `manage_woocommerce` | – | – (read only) |
| Change settings | License settings | `manage_woocommerce` | – | `settings_updated` |
| Export / import catalog CSV | License catalog CSV | `manage_woocommerce` (import also `edit_post` on each product) | – | import: `catalog_csv_imported`; export: not audited |

Every action that changes a license, an activation or a release writes an audit entry with the acting user, the reason and the values before and after.

Settings changes and catalog imports are system-level entries (object ID 0), shown on no admin screen; read them from the table `{prefix}wplc_audit_log`:

- `settings_updated` (object type `settings`): one entry per saved change, with the acting user and the changed keys in the reason. Before/after values are stored for `package_hosts` and `reminder_days` only; a changed `sender_note` is listed by name. Secrets are not settings (they live in `wp-config.php` constants), so none can appear here.
- `catalog_csv_imported` (object type `catalog`): one entry per import attempt, also for a rejected file, with the acting user and `outcome` (`completed`, `row_limit_reached`, `upload_failed`, `file_too_large`, `unreadable`, `invalid_header`), the sanitized file name, rows read, rows imported, rows skipped or invalid, and up to 200 product or variation IDs whose mapping was written.

Both follow the normal audit retention (730 days by default).

A rejected action returns to the screen with an explanation; only a missing capability or an invalid nonce ends in an error page.

## License list

WooCommerce → Licenses. Sortable table with filters for status and product. The search field accepts:

- a **complete license key** (pasted, spaces are ignored). Keys are not stored; the search compares the key's hash,
- `#123` for a license ID,
- a number: license ID, customer ID or part of the key prefix,
- an **email address** or part of one (anything containing `@`),
- other text: key prefix, product key, plan key, or part of a customer email.

## License detail

Shows customer, product, plan, status, billing interval, start, paid until, whether the license grants access right now, renewal mode, next automatic payment, live sites used/allowed, source order, technical IDs, then the site activations, renewals, entitlements and the latest 100 audit entries.

The full license key is not shown here. The customer sees it in the account under "Licenses".

### Suspend, revoke, reactivate

- **Suspend** (`active`, `past_due`, `expired` → `suspended`): the license stops granting access until it is reactivated.
- **Revoke** (→ `revoked`): permanent. A revoked license cannot be changed any more; add a new one instead. Automatic renewal is switched off.
- **Reactivate** (`suspended`, `past_due`, `expired` → `active`): only possible while `paid_until` is in the future. If the period has ended, change the term first.

### Change term

Sets a new end date (end of that day in the site timezone, must be in the future). No payment is taken and no order is created. Allowed for `active`, `past_due` and `expired` licenses; an expired license becomes active again. Open renewal attempts for the old period are cancelled; if automatic renewal is on, the next charge is scheduled for the new end date. For a suspended license, reactivate first.

### Release a site activation

Frees one active site. The site can no longer authenticate and loses updates and premium features; a staging site linked to it is released as well. Use this for lost or decommissioned sites when the customer cannot deactivate in the product plugin or in their account. Confirm the customer's identity first.

### Add license manually

For goodwill and migrated customers. Input: user ID or email of an existing account, an active plan from the catalog, end date, reason. No order is created and nothing is charged. The license does not renew automatically; the customer can renew it in the account inside the renewal window (see [RENEWALS.md](RENEWALS.md)). The customer finds the key in the account, and the "license issued" email is sent.

## Renewals to review

WooCommerce → Renewals to review (`src/Admin/RenewalReviewAdmin.php`, logic in `src/Billing/RenewalReview.php`). The menu entry shows the number of open cases as a badge. The license detail screen shows a warning with a link when one of its renewals is under review; the diagnostics page links here when its counter is above zero.

A case is a renewal row in status `review_required`: the money may have been taken, but the license was not extended. Three kinds:

| Kind (column "Case") | How it arises | Typical reasons |
| --- | --- | --- |
| Automatic payment: result unknown | The gateway gave no definite answer for 12 hours, or a check was no longer safe (T-136). The order is not paid. | `result_unknown_timeout`, `renewal_cancelled`, `license_not_renewable`, `license_changed_while_unknown` |
| Automatic payment taken, license not extended | The charge succeeded while the license was suspended, revoked, refunded or expired (T-22), or the order was paid by hand while the license could not be extended. | `license_changed_during_charge` |
| Manual payment not applied | A customer paid a manual renewal that could not be booked: the transfer arrived after the 30-day window, the license was suspended/revoked meanwhile, the period changed, or the order line does not match the price (T-137). | `manual_renewal_not_allowed`, `manual_period_changed`, `manual_price_mismatch`, `manual_license_not_extended` |

A case whose order is missing (or a manual order that is not paid) can only be closed.

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

## Releases

WooCommerce → License releases. Licensed installations receive the highest published version of a product; customers can download it in their account.

- **Create draft:** product, version (`1.2.3` or `1.2.3-beta1`), and either an uploaded ZIP (stored in a private directory, SHA-256 computed automatically, 50 MB by default) or an HTTPS package URL on an allowed host plus its SHA-256. Optional: required PHP/WordPress version, tested-up-to, release notes.
- **Import manifest:** a JSON file created by `tools/create-release-manifest.php`, uploaded (up to 1 MB) or pasted. Creates a draft.
- **Publish:** makes the draft available. Version, package and checksum can no longer be changed.
- **Withdraw** (with reason): installations are offered the next highest published version. A withdrawn release can be published again.
- **Delete:** drafts only.

Details: [UPDATES.md](UPDATES.md), [UPDATE-STORAGE.md](UPDATE-STORAGE.md).

## Not available in the admin

- Showing or re-sending a customer's license key.
- Moving a license to another customer or plan.
- Refunds and disputes: refunds are made on the WooCommerce order and change the license automatically; a Stripe dispute suspends the license (see [STRIPE-WEBHOOKS.md](STRIPE-WEBHOOKS.md)). "Renewals to review" links to the order but never triggers a gateway refund.
- A screen for Stripe events that need manual review; see [MONITORING.md](MONITORING.md).
