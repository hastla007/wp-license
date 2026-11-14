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

## Identifier rules

- Prefix keys by product: `affiliate.`, `voucher.` or `pass.`.
- Lowercase snake_case after the prefix. Never reuse a key for different behavior; retire it and add a new one.
- Site counts are license limits, not feature entitlements. A staging site does not use a live slot.
- The keys the shop offers as tick boxes in the product editor come from `WPLC\Catalog\EntitlementCatalog` (filter `wplc_entitlement_catalog`). Further keys and numeric limits can be entered as JSON.

## Keys per product

This is the list of keys known to the server today. Which tier of which product grants which key is configured in the shop per WooCommerce product and is **not final**: it is fixed with the real catalog (TODO T-42).

| Product (add-on) | Keys |
| --- | --- |
| Voucher Captain | `voucher.custom_branding`, `voucher.pdf_templates`, `voucher.automatic_delivery`, `voucher.qr_redemption`, `voucher.partial_balance`, `voucher.staff_roles`, `voucher.scheduled_delivery`, `voucher.bulk_import_export`, `voucher.reports`, `voucher.central_license_overview`, `voucher.white_label`, `voucher.priority_support` |
| WP Pass Captain | `pass.woocommerce_sales`, `pass.membership_passes`, `pass.campaign_codes`, `pass.waitlist`, `pass.csv_export`, `pass.seating`, `pass.service_slots`, `pass.pos`, `pass.offline_checkin`, `pass.team_assignment`, `pass.advanced_reports`, `pass.api_webhooks`, `pass.client_license_management`, `pass.white_label`, `pass.priority_support` |
| WP Affiliate Captain | `affiliate.partner_groups`, `affiliate.product_category_rates`, `affiliate.bonuses`, `affiliate.recurring_commissions`, `affiliate.extended_api`, `affiliate.white_label_portal`, `affiliate.webhooks`, `affiliate.multiple_programs`, `affiliate.priority_support` |

**Open point for WP Affiliate Captain.** The server catalog also still lists `affiliate.partner_portal`, `affiliate.coupon_attribution`, `affiliate.order_refund_handling`, `affiliate.basic_commissions` and `affiliate.manual_payout_csv`. These describe basic functions. Under the decision above they belong to the free core plugin and must not be used as gates by the add-on, unless the product owner decides that they are add-on features. Until that is decided an add-on must not check them.

`*.priority_support` describes a service, not code; an add-on has nothing to gate with it.

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
