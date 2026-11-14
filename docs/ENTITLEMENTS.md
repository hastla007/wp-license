# Entitlement catalog — version 1 proposal

This catalog gives feature gates stable identifiers shared by the server and product clients. Labels are presentation only; integrations must authorize by identifier. An absent entitlement denies the new premium feature. Existing free/legacy features remain available as documented in the product plan.

## Core plugins are free and ungated

Decision of 13 Nov 2026: the core plugin of each product stays completely free, with no license check and no locked features, so it can be published on WordPress.org. Consequences for this catalog:

- Core features have **no** entitlement keys. Nothing in a core plugin asks the license server for anything.
- Entitlement keys exist only for features that ship in the separate premium add-on plugin.
- An expired or missing license can only switch off add-on features. It never changes what the core plugin does.

## Identifier rules

- Prefix keys by product: `affiliate.`, `voucher.`, or `pass.`.
- Use lowercase snake_case after the prefix; never reuse a key for different behavior.
- Server plans return a boolean or a documented numeric limit. Clients fail closed for new premium features when an entitlement is absent or invalid.
- Plan key/SKU assignment is a separate configuration step; the table below is the proposed entitlement mapping, not live catalog data.
- Site counts are license limits, not feature entitlements. Staging does not consume another live site.

## Proposed plan mapping

| Product / tier | Feature keys or limits |
|---|---|
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

Add-on identifiers must be assigned from the eventual product SKU catalog and must not silently grant a whole higher tier. The precise plan/product IDs and add-on keys require validation against the real WooCommerce catalog before enabling entitlement enforcement.

## Contract examples

Boolean feature:
```json
{ "affiliate.recurring_commissions": true }
```

Numeric quota:
```json
{ "affiliate.program_limit": 3 }
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

