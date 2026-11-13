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
| Create, import, publish, withdraw, delete releases | License releases | `manage_woocommerce` | only for withdraw | `release_created`, `release_updated`, `release_deleted`, and one entry per status change |
| Read diagnostics | License diagnostics | `manage_woocommerce` | – | – (read only) |
| Change settings | License settings | `manage_woocommerce` | – | not audited |
| Export / import catalog CSV | License catalog CSV | `manage_woocommerce` (import also `edit_post` on each product) | – | not audited |

Every action that changes a license, an activation or a release writes an audit entry with the acting user, the reason and the values before and after. Settings changes and catalog imports are not written to the audit log.

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
- Refunds and disputes: refunds are made on the WooCommerce order and change the license automatically; a Stripe dispute suspends the license (see [STRIPE-WEBHOOKS.md](STRIPE-WEBHOOKS.md)).
- A screen for Stripe events that need manual review; see [MONITORING.md](MONITORING.md).
