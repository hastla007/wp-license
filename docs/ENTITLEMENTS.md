# Entitlements

Entitlements are the feature rights a license grants. The server stores them per license and sends them, signed, in the `entitlements` object of an activation and of every entitlement check ([API-V1.md](API-V1.md)). A premium add-on asks `LicenseManager::is_entitled( 'voucher.qr_redemption' )` ([CLIENT-INTEGRATION.md](CLIENT-INTEGRATION.md)).

## Core plugins are free and ungated

Decision of 13 Nov 2026: the core plugin of each product stays completely free, with no license check and no locked features, so it can be published on WordPress.org. Consequences:

- Core features have **no** entitlement keys. Nothing in a core plugin asks the license server for anything.
- Entitlement keys exist only for features that ship in the separate premium add-on.
- An expired or missing license can only switch add-on features off. It never changes what the core plugin does, and it never removes or hides data the customer already created (vouchers sold, tickets issued, partners, reports).

## What the server delivers

`entitlements` is a JSON object: key → value. It is **empty whenever the license grants no access** (`access: false`: expired after the grace period, suspended, revoked, pending, released installation).

| Value | Example | How a client reads it |
| --- | --- | --- |
| `true` | `"voucher.qr_redemption": true` | The feature is granted. This is what a ticked feature of a plan becomes. |
| number | `"sites.live_limit": 3` | A limit. `is_entitled()` is true for a number above zero; read the number with `status()->entitlement( $key )`. |
| string | `"support.level": "standard"` | Information. `is_entitled()` is false for a string; read it with `entitlement()`. |
| `false`, `0`, `null`, absent key | | Not granted. |

Two keys are part of every plan and are not feature gates:

- `sites.live_limit` (number): live-site limit of the plan. The limit that is enforced is `site_limit` of the signed data; do not count sites in the add-on.
- `support.level` (string): support level of the plan.

Values that cannot be encoded as JSON (a number that is too large) are refused when a plan is saved and left out of the answer.

A client must **fail closed**: an absent key, an unexpected type or data without `access: true` means "not granted". It must authorize by key, never by the label of a feature or by the name of a plan.

## How long a client may rely on them: the offline window

A client may keep the last signed answer and use it while the server cannot be reached. That snapshot is valid until its signed **`cache_expires_at`**: 14 days after the answer (`server_time`), but never later than `access_until`, the time at which the license stops granting access if nothing changes. This is the only definition of the "maximum offline window"; there is no separate setting on the client.

- The deadline is taken from the signed data of the stored snapshot. It is not restarted by later, failed requests.
- A client must not cut it down to `paid_until` on its own: during the renewal grace period `access_until` is later than `paid_until`, and an honest customer would be locked out.
- After the deadline the client treats the license as without access until it receives a new signed answer.
- Only a signed answer may take rights away before the deadline.

The shop can change the 14 days with the filter `wplc_offline_cache_days`.

## Dates

All date-time values of the API (`paid_until`, `access_until`, `grace_until`, `server_time`, `cache_expires_at`, `deactivated_at`) are **UTC** in the form `YYYY-MM-DD HH:MM:SS`, without a time zone suffix. Parse them as UTC (`strtotime( $value . ' UTC' )`) and convert for display. `paid_until`, `access_until` and `grace_until` can be `null`.

## Product keys (decided 14 Nov 2026, T-158)

The product key on a license product in the shop and the key the premium add-on sends must be identical. Key = add-on folder = text domain; the add-ons live in hastla007/captain-pro. Each key maps to one entitlement prefix (`EntitlementCatalog::PRODUCT_KEYS`; further products via the filter `wplc_known_product_keys`).

| Product | Product key | Entitlement prefix | Premium add-on |
|---|---|---|---|
| Voucher Captain | `voucher-captain-pro` | `voucher.` | `voucher-captain-pro` |
| WP Pass Captain | `pass-captain-pro` | `pass.` | `pass-captain-pro` |
| Affiliate Captain | `affiliate-captain-pro` | `affiliate.` | `affiliate-captain-pro` |

The product editor suggests these keys. The plan key (for example `solo`, `business`, `agency`) is set per product or variation and is independent of the product key.

## Identifier rules

- Prefix keys by product: `affiliate.`, `voucher.`, or `pass.`.
- Use lowercase snake_case after the prefix; never reuse a key for different behavior.
- Server plans return a boolean or a documented numeric limit. Clients fail closed for new premium features when an entitlement is absent or invalid.
- Plan key/SKU assignment is a separate configuration step; the table below is the proposed entitlement mapping, not live catalog data.
- Site counts are license limits, not feature entitlements. Staging does not consume another live site.
- The keys the shop offers as tick boxes in the product editor come from `WPLC\Catalog\EntitlementCatalog` (filter `wplc_entitlement_catalog`). Further keys and numeric limits can be entered as JSON.
- A license stores a snapshot of its plan's entitlements. A plan change (upgrade, downgrade at the next renewal, administrator change) replaces the whole snapshot with the new plan's entitlements; clients see the change at their next entitlement check, without re-activation. A lower live site limit deactivates no site, it only blocks new activations (see [RENEWALS.md](RENEWALS.md#plan-changes)).

## Changing a plan that licenses already use (A-23)

Saving a plan in the product editor (or the catalog CSV import) changes what **new** licenses get. Existing licenses keep the snapshot from their purchase or last plan change, also when they renew; a customer's rights never change behind their back. To give existing licenses a feature or a higher site limit that their plan gained, run the opt-in sync:

```
wp wplc sync-entitlements --product=voucher-captain-pro --plan=business          # dry run: lists every license that differs
wp wplc sync-entitlements --product=voucher-captain-pro --plan=business --apply  # writes the changes
```

- The plan of a license is the catalog mapping of the product it renews with (its product key, plan key and billing interval must still match); licenses without one are reported as "no current plan" and left alone. Only `active` and `past_due` licenses are changed.
- The whole snapshot is replaced, the same way as for a plan change. A change that takes something away (a removed or switched-off feature, a lower number, a lower support level, any changed text value) is skipped and reported unless `--allow-reductions` is given. Check your terms before taking rights away from paying customers.
- `--license=<id>` syncs one license; `--max-rows` and `--after-id` split large runs. Without `--apply` nothing is written. The command ends with exit code 1 while licenses remain that differ from their plan.
- Each change is audited as `license_entitlements_synced` (before and after values of the changed keys) and fires `wplc/license_entitlements_synced`. Clients see it at their next entitlement check.

## Proposed plan mapping

| Product / tier | Feature keys or limits |
|---|---|
| Affiliate Captain (core, free on WordPress.org) | No entitlements. Which functions stay in the core is not decided yet (T-42); see the note below the table. |
| Affiliate Captain Starter | `affiliate.partner_portal`, `affiliate.coupon_attribution`, `affiliate.order_refund_handling`, `affiliate.basic_commissions`, `affiliate.manual_payout_csv`; live sites: 1 |
| Affiliate Captain Pro | Starter plus `affiliate.partner_groups`, `affiliate.product_category_rates`, `affiliate.recurring_commissions`, `affiliate.extended_api`, `affiliate.automated_payouts`; live sites: 3 |
| Affiliate Captain Agency | Pro plus `affiliate.white_label_portal`, `affiliate.webhooks`, `affiliate.multiple_programs`, `affiliate.priority_support`; live sites: 10 |
| Voucher Captain (core, free on WordPress.org) | No entitlements. Every core feature works without a license, a key or a server call. |
| Voucher Captain Solo | Core plus `voucher.custom_branding`, `voucher.pdf_templates`, `voucher.automatic_delivery`; live sites: 1 |
| Voucher Captain Business | Solo plus `voucher.qr_redemption`, `voucher.partial_balance`, `voucher.staff_roles`, `voucher.scheduled_delivery`, `voucher.bulk_import_export`, `voucher.reports`; live sites: 5 |
| Voucher Captain Agency | Business plus `voucher.white_label`, `voucher.priority_support`; live sites: 25 |
| WP Pass Captain (core, free on WordPress.org) | No entitlements. Every core feature works without a license, a key or a server call. |
| WP Pass Captain Pro | Core plus `pass.woocommerce_sales`, `pass.membership_passes`, `pass.campaign_codes`, `pass.waitlist`, `pass.csv_export`; live sites: 1 |
| WP Pass Captain Business | Pro plus `pass.seating`, `pass.service_slots`, `pass.pos`, `pass.offline_checkin`, `pass.advanced_reports`, `pass.api_webhooks`; live sites: 5 |
| WP Pass Captain Agency | Business plus `pass.white_label`, `pass.priority_support`; live sites: 25 |

**Open for Affiliate Captain (A-90, depends on T-42):** the Starter keys `affiliate.partner_portal`, `affiliate.coupon_attribution`, `affiliate.order_refund_handling`, `affiliate.basic_commissions` and `affiliate.manual_payout_csv` name functions that look like core functions of the free plugin, and they are still offered as tick boxes in the product editor (`EntitlementCatalog`). Under the rule above, a core function is never license-gated. Until the catalog is decided, the core plugin must not check these keys, an add-on checks only keys of functions it ships itself, and no plan should differ from another only by one of them. Once decided, keys that turn out to be core functions are removed from the catalog and listed under [Removed keys](#removed-keys).

Add-on identifiers must be assigned from the eventual product SKU catalog and must not silently grant a whole higher tier. The precise plan/product IDs and add-on keys require validation against the real WooCommerce catalog before enabling entitlement enforcement.

## Contract examples

Signed data of a license with access:

```json
{
  "access": true,
  "license_status": "active",
  "entitlements": {
    "voucher.qr_redemption": true,
    "voucher.reports": true,
    "sites.live_limit": 5,
    "support.level": "standard"
  },
  "server_time": "2026-11-12 00:00:00",
  "cache_expires_at": "2026-11-26 00:00:00"
}
```

The same license after its paid period and grace period:

```json
{
  "access": false,
  "license_status": "expired",
  "entitlements": {},
  "access_until": null
}
```

Clients may cache only a verified signed entitlement response under the separately specified maximum offline window. Entitlements must not disable existing customer data or already purchased vouchers/tickets when a license expires.

## Removed keys

Removed on 14 Nov 2026 by decision of the owner. Licenses that stored one of them keep the value (the product editor keeps unknown keys as JSON); nothing checks them any more.

| Key | Reason | Where the function lives now |
|---|---|---|
| `voucher.central_license_overview` | An overview of a customer's sites is a function of the customer account, not of the plugin on each site. | Customer account → **Sites** (`WPLC\Account\SiteOverview`), for every customer, all products. |
| `pass.client_license_management` | Same as above. | Customer account → **Sites**. |
| `pass.team_assignment` | Ships in the free core: seven staff roles and per-event check-in staff. | Pass Captain (core). |
| `affiliate.bonuses` | Ships in the free core: monthly tier bonuses (marginal or retroactive). | Affiliate Captain (core). |

